<?php
/**
 * OzyConnect Panel — VariantPublishService
 * Varyantlı ürünleri pazaryerlerine gruplayarak gönderen servis.
 * Mevcut ProductPublishService'den BAĞIMSIZ — yayın akışını çağırmaz, bozmaz. Yalnız Hepsiburada için
 * marka/özellik çözümü ProductPublishService::resolveHepsiburadaAttributes'ten alınır (tek kaynak).
 * WooCommerce: ProductPublishService::publishProducts varyant grubu algılayınca publishWooCommerceGroup'u
 * çağırır (değişken ürün + varyasyonlar); öğe alanları ProductPublishService::wooCommerceItem'dan gelir.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Models\BrandMarketplaceMapping;
use App\Models\CategoryMarketplaceMapping;
use App\Models\Marketplace;
use App\Models\MarketplaceProduct;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\SyncLog;
use App\Services\Marketplace\HepsiburadaListingMatchService;
use App\Services\Marketplace\HepsiburadaService;
use RuntimeException;

final class VariantPublishService
{
    /** HepsiburadaService::createProducts dosya boyu (trackingIds[] bu boyuttaki parçalara karşılık gelir). */
    private const HB_IMPORT_CHUNK = 100;

    private const SLUG_MAP = [
        'trendyol'    => \App\Services\Marketplace\TrendyolService::class,
        'n11'         => \App\Services\Marketplace\N11Service::class,
        'hepsiburada' => \App\Services\Marketplace\HepsiburadaService::class,
        'pazarama'    => \App\Services\Marketplace\PazaramaService::class,
        'ciceksepeti' => \App\Services\Marketplace\CiceksepetiService::class,
        'pttavm'      => \App\Services\Marketplace\PttavmService::class,
        'idefix'      => \App\Services\Marketplace\IdefixService::class,
        'amazon'      => \App\Services\Marketplace\AmazonService::class,
        'woocommerce' => \App\Services\Marketplace\WooCommerceService::class,
    ];

    private const WOO_SLUG = 'woocommerce';

    /** Varyant öznitelik bilgisi olmayan gruplarda tek varyasyon ekseninin adı */
    private const WOO_FALLBACK_ATTRIBUTE = 'Seçenek';

    /** variant_attributes içindeki öznitelik OLMAYAN anahtarlar */
    private const VARIANT_META_KEYS = ['label', 'options', 'oziygo_product_id', 'oziygo_value_ids'];

    /** İçe aktarılmış grupta (ana ürün de varyasyon) WooCommerce değişken ürününün SKU eki */
    private const WOO_IMPORTED_PARENT_SUFFIX = '-GRUP';

    public function __construct(
        private readonly Container $container,
        private readonly Database $db,
        private readonly Product $productModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly CategoryMarketplaceMapping $catMappingModel,
        private readonly BrandMarketplaceMapping $brandMappingModel,
        private readonly ProductAttributeValue $pAttrValueModel,
        private readonly PriceRuleEngine $priceEngine,
        private readonly SyncLog $syncLogModel,
        private readonly Marketplace $marketplaceModel,
        private readonly Logger $logger,
    ) {}

    /**
     * Ana ürün ID → tüm varyantları çek → pazaryerine gönder
     *
     * @return array{success: bool, sent: int, failed: int, errors: array}
     */
    public function publishVariantGroup(int $parentProductId, int $marketplaceId): array
    {
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false) {
            return $this->fail('Pazaryeri bulunamadı');
        }

        $slug = $marketplace['slug'] ?? '';
        if ($slug === self::WOO_SLUG) {
            $wooResult = $this->publishWooCommerceGroup($parentProductId, $marketplaceId);

            return [
                'success' => $wooResult['success'],
                'sent'    => $wooResult['sent'],
                'failed'  => $wooResult['failed'],
                'errors'  => $wooResult['errors'],
            ];
        }

        $group = $this->productModel->getVariantGroup($parentProductId);
        $parent = $group['parent'];
        $variants = $group['variants'];

        if ($parent === false) {
            return $this->fail('Ana ürün bulunamadı');
        }

        if ((int) ($parent['has_variants'] ?? 0) !== 1) {
            return $this->fail('Bu ürünün varyantı yok (has_variants=0)');
        }

        if (empty($variants)) {
            return $this->fail('Varyant ürünler bulunamadı');
        }

        // Kategori + marka eşleştirmesi (parent'tan alınır)
        $catMapping = $this->catMappingModel->findMapping((int) ($parent['category_id'] ?? 0), $marketplaceId);
        $brandMapping = $this->brandMappingModel->findMapping((int) ($parent['brand_id'] ?? 0), $marketplaceId);
        if ($catMapping === false) {
            return $this->fail('Kategori pazaryeri eslestirmesi yapilmamis');
        }
        $leafStatus = $this->catMappingModel->getMarketplaceCategoryLeafStatus(
            $marketplaceId,
            (string) ($catMapping['marketplace_category_id'] ?? '')
        );
        // Ara kategori ya da (önbellekte durum bilgisi varsa) satışa kapalı/pasif yaprak → gönderilmez
        $isClosedLeaf = $leafStatus !== null && isset($leafStatus['sellable']) && !$leafStatus['sellable'];
        if ($leafStatus !== null && (!$leafStatus['is_leaf'] || $isClosedLeaf)) {
            $categoryLabel = $leafStatus['full_path'] !== ''
                ? $leafStatus['full_path']
                : ($leafStatus['name'] !== '' ? $leafStatus['name'] : (string) ($catMapping['marketplace_category_id'] ?? ''));
            return $this->fail(!$leafStatus['is_leaf']
                ? 'Kategori pazaryeri eslestirmesi ara kategoriye bagli. En alt/final kategori secin: ' . $categoryLabel
                : 'Eşlenen pazaryeri kategorisi satışa kapalı: ' . $categoryLabel . '. Kategori eşleştirmesini değiştirin.');
        }
        if ($brandMapping === false) {
            return $this->fail('Marka pazaryeri eslestirmesi yapilmamis');
        }

        // Her varyant için fiyat hesapla
        $variantItems = [];
        foreach ($variants as $variant) {
            $price = $this->priceEngine->calculatePrice($variant, $marketplaceId);
            $attrs = $this->pAttrValueModel->getByProduct((int) $variant['id']);

            $variantItems[] = [
                'product'    => $variant,
                'price'      => $price,
                'attributes' => $attrs,
            ];
        }

        // Pazaryerine özel payload oluştur
        $payload = $this->buildVariantGroupPayload(
            $parent, $variantItems, $slug, $catMapping, $brandMapping
        );

        if (empty($payload)) {
            return $this->fail("Pazaryeri '{$slug}' için varyant payload oluşturulamadı");
        }

        // Hepsiburada: marka + kategoriye özel zorunlu özellikler (geçerli değer listesinden) ve SKU kuralı
        // (normalize merchantSku) ProductPublishService ile aynı kaynaktan; eksikte hiçbir şey gönderilmez.
        if ($slug === 'hepsiburada') {
            $hbErrors = $this->enrichHepsiburadaItems($payload, $parent, $variantItems, $catMapping, $brandMapping);
            if ($hbErrors !== []) {
                return ['success' => false, 'sent' => 0, 'failed' => count($variants), 'errors' => $hbErrors];
            }
        }

        // API çağrısı
        $service = $this->resolveService($slug);
        if ($service === null) {
            return $this->fail("Pazaryeri servisi bulunamadı: {$slug}");
        }

        if (!method_exists($service, 'createVariantProducts')) {
            return $this->fail("Pazaryeri '{$slug}' henüz createVariantProducts desteklemiyor");
        }

        try {
            $apiResult = $service->createVariantProducts($payload);
        } catch (\Throwable $e) {
            $this->logger->error("Varyant publish hatası ({$slug}): " . $e->getMessage(), 'VariantPublish');
            return $this->fail('API hatası: ' . $e->getMessage());
        }

        // DB kayıtları güncelle
        $sent = 0;
        $failed = 0;
        if ($apiResult['success'] ?? false) {
            foreach (array_values($variants) as $index => $variant) {
                try {
                    $this->mpProductModel->upsertByProductAndMarketplace((int) $variant['id'], $marketplaceId, $slug === 'hepsiburada'
                        ? $this->hepsiburadaPendingState($payload['items'][$index] ?? [], $apiResult, (int) $index, $catMapping, $brandMapping)
                        : [
                            'is_listed'               => 1,
                            'sync_error'              => null,
                            'last_synced_at'          => date('Y-m-d H:i:s'),
                            'marketplace_category_id' => $catMapping['marketplace_category_id'] ?? null,
                            'marketplace_brand_id'    => $brandMapping['marketplace_brand_id'] ?? null,
                        ]);
                    $sent++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logger->error("Varyant DB hatası (#{$variant['id']}): " . $e->getMessage(), 'VariantPublish');
                }
            }
        } else {
            $failed = count($variants);
        }

        // Sync log
        $this->syncLogModel->create([
            'marketplace_id'    => $marketplaceId,
            'sync_type'         => 'variant_push',
            'status'            => $failed === 0 ? 'success' : ($sent > 0 ? 'partial' : 'failed'),
            'records_fetched'   => count($variants),
            'records_processed' => $sent,
            'records_failed'    => $failed,
            'error_details'     => !empty($apiResult['errors']) ? json_encode($apiResult['errors'], JSON_UNESCAPED_UNICODE) : null,
            'started_at'        => date('Y-m-d H:i:s'),
            'finished_at'       => date('Y-m-d H:i:s'),
            'triggered_by'      => 'manual',
        ]);

        $this->logger->info(
            "Varyant publish tamamlandı ({$slug}): parent=#{$parentProductId}, sent={$sent}, failed={$failed}",
            'VariantPublish'
        );

        return [
            'success' => $sent > 0,
            'sent'    => $sent,
            'failed'  => $failed,
            'errors'  => $apiResult['errors'] ?? [],
        ];
    }

    /**
     * Pazaryerine özel varyant payload oluştur
     *
     * @param array<string, mixed> $parent
     * @param array<int, array{product: array, price: array, attributes: array}> $variantItems
     * @return array<string, mixed>
     */
    private function buildVariantGroupPayload(
        array $parent,
        array $variantItems,
        string $slug,
        array|false $catMapping,
        array|false $brandMapping,
    ): array {
        $groupId = $parent['sku']; // Parent SKU = productMainId / groupCode

        $items = [];
        foreach ($variantItems as $vi) {
            $product = $vi['product'];
            $price = $vi['price'];
            $rawAttrs = $vi['attributes'];

            // Görseller (child'dan, yoksa parent'tan)
            $images = $this->extractImages($product, $parent);

            // Attribute'lar
            $attributes = [];
            foreach ($rawAttrs as $pa) {
                if (!empty($pa['custom_value'])) {
                    $attributes[] = [
                        'attributeId'          => (int) $pa['attribute_id'],
                        'customAttributeValue' => $pa['custom_value'],
                    ];
                } elseif (!empty($pa['attribute_value_id'])) {
                    $attributes[] = [
                        'attributeId'      => (int) $pa['attribute_id'],
                        'attributeValueId' => (int) $pa['attribute_value_id'],
                    ];
                }
            }

            $items[] = [
                'barcode'           => $product['barcode'] ?? '',
                'title'             => $product['name'] ?? $parent['name'],
                'productMainId'     => $groupId,
                'groupCode'         => $groupId,
                'stockCode'         => $product['sku'] ?? $product['barcode'] ?? '',
                'brandId'           => (int) ($brandMapping['marketplace_brand_id'] ?? 0),
                'categoryId'        => (int) ($catMapping['marketplace_category_id'] ?? 0),
                'quantity'          => max(0, (int) ($product['stock_qty'] ?? 0)),
                'description'       => $product['description'] ?? $parent['description'] ?? $parent['name'],
                'currencyType'      => 'TRY',
                'listPrice'         => $price['list_price'] ?? 0,
                'salePrice'         => $price['sale_price'] ?? 0,
                'vatRate'           => (int) ($product['tax_rate'] ?? $parent['tax_rate'] ?? 20),
                'dimensionalWeight' => (float) ($product['desi'] ?? $parent['desi'] ?? 1),
                'images'            => $images,
                'attributes'        => $attributes,
                'preparingDay'      => 3,
                'shipmentTemplate'  => '1',
            ];
        }

        return [
            'slug'        => $slug,
            'groupId'     => $groupId,
            'parentSku'   => $parent['sku'],
            'parentName'  => $parent['name'],
            'categoryId'  => (int) ($catMapping['marketplace_category_id'] ?? 0),
            'brandId'     => (int) ($brandMapping['marketplace_brand_id'] ?? 0),
            'items'       => $items,
        ];
    }

    /**
     * HB öğelerini resmi import şemasının istediği alanlarla tamamlar (yerinde). Hata listesi döner.
     *
     * @param array<string, mixed> $payload buildVariantGroupPayload çıktısı (items[] variantItems ile aynı sırada)
     * @param array<string, mixed> $parent
     * @param array<int, array{product: array, price: array, attributes: array}> $variantItems
     * @return array<int, array{product_id: int, error: string}>
     */
    private function enrichHepsiburadaItems(array &$payload, array $parent, array $variantItems, array|false $catMapping, array|false $brandMapping): array
    {
        $publishService = $this->container->get(ProductPublishService::class);
        $groupId = HepsiburadaService::normalizeMerchantSku((string) ($parent['sku'] ?? ''));
        $errors = [];

        foreach ($variantItems as $index => $vi) {
            $product = $vi['product'];
            $label = (string) (($product['sku'] ?? '') !== '' ? $product['sku'] : '#' . ($product['id'] ?? '?'));
            $merchantSku = HepsiburadaService::normalizeMerchantSku((string) ($product['sku'] ?? ''));
            if ($merchantSku === '') {
                $errors[] = ['product_id' => (int) ($product['id'] ?? 0), 'error' => "{$label}: varyantın stok kodu (SKU) yok"];
                continue;
            }

            $resolution = $publishService->resolveHepsiburadaAttributes($product, $catMapping, $brandMapping);
            if ($resolution['errors'] !== []) {
                $errors[] = ['product_id' => (int) ($product['id'] ?? 0), 'error' => $label . ': ' . implode('; ', $resolution['errors'])];
                continue;
            }

            $payload['items'][$index]['merchantSku']    = $merchantSku;
            $payload['items'][$index]['stockCode']      = $merchantSku;
            $payload['items'][$index]['variantGroupId'] = $groupId !== '' ? $groupId : $merchantSku;
            $payload['items'][$index]['brand']          = $resolution['brand']['value'];
            $payload['items'][$index]['hbAttributes']   = $resolution['values'];
            $payload['items'][$index]['desi']           = (float) ($product['desi'] ?? $parent['desi'] ?? 0);
        }

        return $errors;
    }

    /**
     * HB içe aktarımı asenkrondur: kayıt "onay bekliyor" (Batch kontrol bekliyor: <trackingId>) yazılır;
     * sonuç Gönderim Sonucu / İlan Eşleştirme ile tamamlanır (ProductPublishService ile aynı durum).
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $apiResult createProducts dönüşü (trackingIds 100'lük dosya başına)
     * @return array<string, mixed>
     */
    private function hepsiburadaPendingState(array $item, array $apiResult, int $index, array|false $catMapping, array|false $brandMapping): array
    {
        $trackingIds = array_values((array) ($apiResult['trackingIds'] ?? []));
        $trackingId = (string) ($trackingIds[intdiv($index, self::HB_IMPORT_CHUNK)] ?? $apiResult['trackingId'] ?? '');

        return [
            'is_listed'               => 0,
            'sync_error'              => $trackingId !== '' ? HepsiburadaListingMatchService::PENDING_BATCH_PREFIX . $trackingId : null,
            'last_synced_at'          => date('Y-m-d H:i:s'),
            'marketplace_sku'         => (string) ($item['merchantSku'] ?? ''),
            'marketplace_barcode'     => (string) ($item['barcode'] ?? ''),
            'marketplace_category_id' => $catMapping['marketplace_category_id'] ?? null,
            'marketplace_brand_id'    => $brandMapping['marketplace_brand_id'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // WOOCOMMERCE — değişken ürün (variable) + varyasyonlar
    // ═══════════════════════════════════════════════════════

    /**
     * Varyant grubunu WooCommerce'e değişken ürün olarak gönderir: ana ürün type=variable + varyasyon
     * öznitelikleri (variation: true), varyantlar varyasyon (WooCommerceService::createVariantProducts).
     * Aynı SKU'lar yeniden gönderilirse WooCommerce'teki ürün güncellenir (mükerrer açılmaz).
     * Yanıt senkrondur; sonuç hemen yerel kayda işlenir.
     *
     * @return array{success: bool, sent: int, failed: int, skipped: int, errors: array<int, array{product_id: int, error: string}>}
     */
    public function publishWooCommerceGroup(int $parentProductId, int $marketplaceId, bool $writeSyncLog = true): array
    {
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false || ($marketplace['slug'] ?? '') !== self::WOO_SLUG) {
            return $this->wooFail($parentProductId, 'WooCommerce pazaryeri bulunamadı');
        }

        $publishService = $this->container->get(ProductPublishService::class);
        $group = $publishService->wooCommerceVariantGroup($parentProductId);
        if ($group === null) {
            return $this->wooFail($parentProductId, 'Varyant grubu bulunamadı (ana ürün ya da aktif varyant yok)');
        }

        $parent = $group['parent'];
        $parentIsVariant = $group['parent_is_variant'];
        $members = $parentIsVariant ? array_merge([$parent], $group['variants']) : $group['variants'];

        $validation = $publishService->validateWooCommerceGroup($parentProductId, $marketplace);
        if (!$validation['valid']) {
            $error = 'Validasyon hatası: ' . implode(', ', $validation['errors']);
            $publishService->recordWooCommerceFailure($parentProductId, $marketplaceId, $error);

            return [
                'success' => false,
                'sent'    => 0,
                'failed'  => 0,
                'skipped' => count($members),
                'errors'  => [['product_id' => $parentProductId, 'error' => $error]],
            ];
        }

        $service = $this->resolveService(self::WOO_SLUG);
        if ($service === null || !method_exists($service, 'createVariantProducts')) {
            return $this->wooFail($parentProductId, 'WooCommerce servisi varyantlı ürün göndermeyi desteklemiyor');
        }

        [$payload, $ownImages] = $this->buildWooCommercePayload($publishService, $parent, $members, $parentIsVariant, $marketplaceId);
        $api = $publishService->callWooCommerce(static fn(): mixed => $service->createVariantProducts($payload));

        $index = $publishService->wooCommerceResultIndex($api);
        $apiError = $publishService->wooCommerceApiError($api);
        $sent = 0;
        $failed = 0;
        $errors = [];
        $wooParentId = '';

        foreach ($members as $i => $member) {
            $memberId = (int) ($member['id'] ?? 0);
            $item = $payload['items'][$i];
            $result = $publishService->wooCommerceResultFor($index, (string) ($item['sku'] ?? ''));

            if ($result !== null && $publishService->wooCommerceResultSucceeded($result)) {
                try {
                    $overrides = $ownImages[$i] !== '' ? ['main_image' => $ownImages[$i]] : [];
                    $this->db->transaction(function () use ($memberId, $marketplaceId, $publishService, $item, $result, $overrides): void {
                        $this->mpProductModel->upsertByProductAndMarketplace(
                            $memberId,
                            $marketplaceId,
                            $publishService->wooCommercePublishState($item, $result, $overrides)
                        );
                    });
                    $sent++;
                    if ($wooParentId === '') {
                        $wooParentId = trim((string) ($result['parent_id'] ?? ''));
                    }
                    $warning = $publishService->wooCommerceResultWarning($result);
                    if ($warning !== '') {
                        $errors[] = ['product_id' => $memberId, 'error' => 'Uyarı (' . $this->wooLabel($member) . '): ' . $warning];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'product_id' => $memberId,
                        'error'      => 'API başarılı ama DB kayıt hatası: ' . $e->getMessage(),
                    ];
                    $this->logger->error("WooCommerce varyant DB hatası (#{$memberId}): " . $e->getMessage(), 'VariantPublish');
                }
                continue;
            }

            $error = $publishService->wooCommerceItemError($result, $apiError);
            $failed++;
            $errors[] = ['product_id' => $memberId, 'error' => 'Varyant ' . $this->wooLabel($member) . ': ' . $error];
            $publishService->recordWooCommerceFailure($memberId, $marketplaceId, $error);
        }

        // Ana kart (değişken ürün) — içe aktarılmış grupta ana ürün zaten varyasyon olarak işlendi
        if (!$parentIsVariant) {
            // Ana ürün sonucu: results[] içinde ya da ayrı 'parent' satırında (WooCommerceService)
            $parentResult = $publishService->wooCommerceResultFor($index, (string) $payload['parentSku'])
                ?? (is_array($api['parent'] ?? null) ? $api['parent'] : null);
            $parentOk = $parentResult !== null && $publishService->wooCommerceResultSucceeded($parentResult);
            $parentWooId = $parentOk ? trim((string) ($parentResult['marketplace_product_id'] ?? '')) : '';
            if ($parentWooId === '' || $parentWooId === '0') {
                $parentWooId = $wooParentId;
            }

            if ($sent > 0 && $parentWooId !== '' && $parentWooId !== '0') {
                try {
                    $this->mpProductModel->upsertByProductAndMarketplace(
                        $parentProductId,
                        $marketplaceId,
                        $publishService->wooCommercePublishState($payload['parent'], ['marketplace_product_id' => $parentWooId])
                    );
                } catch (\Throwable $e) {
                    $errors[] = ['product_id' => $parentProductId, 'error' => 'Ana ürün yerel kaydı güncellenemedi: ' . $e->getMessage()];
                    $this->logger->error("WooCommerce ana ürün DB hatası (#{$parentProductId}): " . $e->getMessage(), 'VariantPublish');
                }
            } else {
                $parentError = $publishService->wooCommerceItemError($parentResult, $apiError);
                $publishService->recordWooCommerceFailure($parentProductId, $marketplaceId, $parentError);
                if ($parentResult !== null && !$parentOk) {
                    $errors[] = ['product_id' => $parentProductId, 'error' => 'Ana ürün: ' . $parentError];
                }
            }
        }

        if ($writeSyncLog) {
            $this->syncLogModel->create([
                'marketplace_id'    => $marketplaceId,
                'sync_type'         => 'variant_push',
                'status'            => $failed === 0 ? 'success' : ($sent > 0 ? 'partial' : 'failed'),
                'records_fetched'   => count($members),
                'records_processed' => $sent,
                'records_failed'    => $failed,
                'error_details'     => $errors !== [] ? json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE) : null,
                'started_at'        => date('Y-m-d H:i:s'),
                'finished_at'       => date('Y-m-d H:i:s'),
                'triggered_by'      => 'manual',
            ]);
        }

        $this->logger->info(
            "WooCommerce varyant gönderimi: parent=#{$parentProductId}, gönderilen={$sent}, hatalı={$failed}",
            'VariantPublish'
        );

        return ['success' => $sent > 0, 'sent' => $sent, 'failed' => $failed, 'skipped' => 0, 'errors' => $errors];
    }

    /**
     * createVariantProducts girdisi — diğer servislerin varyant grubu biçimi (slug, groupId, parentSku,
     * parentName, categoryId, brandId, items[]) + değişken ürün alanları grup düzeyinde (description,
     * short_description, images[{src}]) + WooCommerce alanları:
     *   parent     → değişken ürün (type=variable, attributes[{name, options[], visible, variation:true}],
     *                fiyat/stok/GTIN YOK; açıklama, kategori, galeri, marka ana üründen)
     *   attributes → parent.attributes ile aynı
     *   items[]    → varyasyonlar: sku, regular_price, sale_price, manage_stock, stock_quantity,
     *                global_unique_id (geçerliyse), weight, attributes[{name, option}], image{src} (yalnız
     *                ana ürününkinden farklıysa) + genel anahtarlar (barcode=geçerli GTIN ya da boş, salePrice,
     *                listPrice, quantity …; ProductPublishService::wooCommerceItem)
     * İkinci dönüş: varyant sırasıyla varyantın kendi ilk görseli (yerel kayıt için).
     *
     * @param array<string, mixed> $parent
     * @param array<int, array<string, mixed>> $members
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function buildWooCommercePayload(
        ProductPublishService $publishService,
        array $parent,
        array $members,
        bool $parentIsVariant,
        int $marketplaceId,
    ): array {
        $axes = $this->wooVariationAxes($members, $parent);

        $attributes = [];
        foreach ($axes['attributes'] as $attribute) {
            $attributes[] = [
                'name'      => $attribute['name'],
                'options'   => $attribute['options'],
                'visible'   => true,
                'variation' => true,
            ];
        }

        $parentItem = $publishService->wooCommerceItem($parent, $marketplaceId);
        $parentSku = (string) ($parentItem['sku'] ?? '');
        if ($parentIsVariant && $parentSku !== '') {
            // Ana ürün kendisi bir varyasyon: değişken ürüne ayrı, kalıcı (tekrar gönderimde aynı) SKU
            $parentSku .= self::WOO_IMPORTED_PARENT_SUFFIX;
        }
        // Değişken ürünün fiyatı/stoğu varyasyonlardan gelir; GTIN mağaza genelinde tekil olmalı → varyasyonlarda
        unset($parentItem['regular_price'], $parentItem['sale_price'], $parentItem['stock_quantity'], $parentItem['global_unique_id']);
        $parentItem = array_merge($parentItem, [
            'type'         => 'variable',
            'sku'          => $parentSku,
            'barcode'      => $parentSku,
            'stockCode'    => $parentSku,
            'manage_stock' => false,
            'attributes'   => $attributes,
        ]);
        $parentImage = (string) ($parentItem['images'][0]['src'] ?? '');
        $parentDescription = trim((string) ($parent['description'] ?? ''));

        $items = [];
        $ownImages = [];
        foreach (array_values($members) as $i => $member) {
            $item = $publishService->wooCommerceItem($member, $marketplaceId);

            // Varyasyon tek görsel alır; ana ürününkiyle aynıysa gönderilmez (WooCommerce her seferinde kopya indirir)
            $ownImage = (string) ($item['images'][0]['src'] ?? '');
            $ownImages[$i] = $ownImage;
            if ($ownImage !== '' && $ownImage !== $parentImage) {
                $item['image'] = ['src' => $ownImage];
                $item['images'] = [['src' => $ownImage]];
            } else {
                $item['images'] = [];
            }

            // Varyasyon açıklaması yalnız ana ürününkinden farklıysa
            if (trim((string) ($item['description'] ?? '')) === $parentDescription) {
                unset($item['description']);
            }
            // Kategori, marka ve kısa açıklama varyasyonda yok (değişken üründe tutulur)
            unset($item['categories'], $item['brands'], $item['short_description']);

            $item['attributes'] = [];
            foreach ($axes['combos'][$i] ?? [] as $name => $option) {
                $item['attributes'][] = ['name' => (string) $name, 'option' => (string) $option];
            }
            $items[] = $item;
        }

        // Değişken ürünün açıklama/kategori/marka/galeri alanları grup düzeyinde de verilir
        // (WooCommerceService ana ürün gövdesini grup anahtarlarından kurar).
        $groupPayload = [
            'slug'       => self::WOO_SLUG,
            'groupId'    => $parentSku,
            'parentSku'  => $parentSku,
            'parentName' => (string) ($parent['name'] ?? ''),
            'categoryId' => (string) ($parentItem['categoryId'] ?? ''),
            'brandId'    => (string) ($parentItem['brandId'] ?? ''),
            'images'     => $parentItem['images'] ?? [],
            'parent'     => $parentItem,
            'attributes' => $attributes,
            'items'      => $items,
        ];
        foreach (['description', 'short_description'] as $field) {
            if (isset($parentItem[$field])) {
                $groupPayload[$field] = $parentItem[$field];
            }
        }

        return [$groupPayload, $ownImages];
    }

    /**
     * Varyasyon eksenleri. Kaynak sırası: variant_attributes (ad → değer; oziygo 'options') →
     * ürün özellikleri (product_attribute_values; yalnız varyantlar arasında değişenler) →
     * tek eksen 'Seçenek' (variant_attributes.label → ad farkı → SKU).
     * Her varyantın eksen kümesi aynı ve birleşimi tekil olmalı; değilse bir sonraki kaynağa geçilir.
     *
     * @param array<int, array<string, mixed>> $members
     * @param array<string, mixed> $parent
     * @return array{attributes: array<int, array{name: string, options: array<int, string>}>, combos: array<int, array<string, string>>}
     */
    private function wooVariationAxes(array $members, array $parent): array
    {
        $members = array_values($members);

        $combos = array_map(fn(array $member): array => $this->variantAttributePairs($member), $members);
        if (!$this->usableCombos($combos)) {
            $combos = $this->differingProductAttributes($members);
        }
        if (!$this->usableCombos($combos)) {
            $combos = array_map(
                static fn(string $label): array => [self::WOO_FALLBACK_ATTRIBUTE => $label],
                $this->fallbackVariantLabels($members, $parent)
            );
        }

        $options = [];
        foreach ($combos as $combo) {
            foreach ($combo as $name => $value) {
                $options[(string) $name][(string) $value] = true;
            }
        }

        $attributes = [];
        foreach ($options as $name => $values) {
            $attributes[] = ['name' => (string) $name, 'options' => array_map('strval', array_keys($values))];
        }

        return ['attributes' => $attributes, 'combos' => $combos];
    }

    /**
     * variant_attributes JSON → [ad => değer]. Biçimler: {"Renk":"Siyah","Beden":"M"} (XML), {"label":…,
     * "options":{"Renk":"Siyah"}} (oziygo), [{"name":…,"value":…}]; {"label":…} tek başına öznitelik değildir.
     *
     * @param array<string, mixed> $member
     * @return array<string, string>
     */
    private function variantAttributePairs(array $member): array
    {
        $decoded = json_decode((string) ($member['variant_attributes'] ?? ''), true);
        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        $nested = is_array($decoded['options'] ?? null) && $decoded['options'] !== [];
        $source = $nested ? $decoded['options'] : $decoded;
        $pairs = [];
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                $name = $value['name'] ?? $value['group_name'] ?? $value['attribute_name'] ?? null;
                $option = $value['value'] ?? $value['value_name'] ?? $value['option'] ?? $value['attribute_value'] ?? null;
                if (is_scalar($name) && is_scalar($option)) {
                    $this->addAxisPair($pairs, (string) $name, (string) $option);
                }
                continue;
            }
            if (!is_string($key) || !is_scalar($value) || (!$nested && in_array($key, self::VARIANT_META_KEYS, true))) {
                continue;
            }
            $this->addAxisPair($pairs, $key, (string) $value);
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     */
    private function addAxisPair(array &$pairs, string $name, string $value): void
    {
        $name = trim($name);
        // WooCommerce yerel özniteliklerinde '|' değer ayırıcıdır
        $value = trim(str_replace('|', '/', $value));
        if ($name !== '' && $value !== '') {
            $pairs[mb_substr($name, 0, 100)] = mb_substr($value, 0, 200);
        }
    }

    /**
     * Ürün özelliklerinden (product_attribute_values) varyantlar arasında DEĞİŞEN ve her varyantta bulunan özellikler.
     *
     * @param array<int, array<string, mixed>> $members
     * @return array<int, array<string, string>>
     */
    private function differingProductAttributes(array $members): array
    {
        $perMember = [];
        foreach ($members as $i => $member) {
            $pairs = [];
            try {
                $rows = $this->productModel->getVariantAttributeValues((int) ($member['id'] ?? 0));
            } catch (\Throwable $e) {
                $this->logger->warning('Varyant özellikleri okunamadı: ' . $e->getMessage(), 'VariantPublish');
                return [];
            }
            foreach ($rows as $row) {
                $this->addAxisPair($pairs, (string) ($row['attribute_name'] ?? ''), (string) ($row['attribute_value'] ?? ''));
            }
            $perMember[$i] = $pairs;
        }

        $common = null;
        foreach ($perMember as $pairs) {
            $common = $common === null ? array_keys($pairs) : array_values(array_intersect($common, array_keys($pairs)));
        }

        $axes = array_values(array_filter(
            $common ?? [],
            static fn(string $name): bool => count(array_unique(array_column($perMember, $name))) > 1
        ));
        if ($axes === []) {
            return [];
        }

        return array_map(
            static fn(array $pairs): array => array_intersect_key($pairs, array_flip($axes)),
            $perMember
        );
    }

    /**
     * Tek eksen değerleri: variant_attributes.label → adın ana ürün adından (ya da ortak önekten) sonraki
     * kısmı → SKU. Değerler tekil değilse hepsi için SKU kullanılır.
     *
     * @param array<int, array<string, mixed>> $members
     * @param array<string, mixed> $parent
     * @return array<int, string>
     */
    private function fallbackVariantLabels(array $members, array $parent): array
    {
        $labels = [];
        foreach ($members as $i => $member) {
            $decoded = json_decode((string) ($member['variant_attributes'] ?? ''), true);
            $labels[$i] = is_array($decoded) && is_scalar($decoded['label'] ?? null)
                ? trim(str_replace('|', '/', (string) $decoded['label']))
                : '';
        }
        if ($this->uniqueNonEmpty($labels)) {
            return $labels;
        }

        $names = array_map(static fn(array $m): string => trim((string) ($m['name'] ?? '')), $members);
        foreach ([$this->stripPrefix($names, trim((string) ($parent['name'] ?? ''))), $this->stripPrefix($names, $this->commonWordPrefix($names))] as $candidate) {
            if ($this->uniqueNonEmpty($candidate)) {
                return $candidate;
            }
        }

        return array_map(fn(array $m): string => $this->wooLabel($m), $members);
    }

    /**
     * Adlardan ortak öneki at (öneki taşımayan ad → boş); ayırıcı karakterler kırpılır.
     *
     * @param array<int, string> $names
     * @return array<int, string>
     */
    private function stripPrefix(array $names, string $prefix): array
    {
        $out = [];
        foreach ($names as $i => $name) {
            if ($prefix === '' || mb_stripos($name, $prefix) !== 0) {
                $out[$i] = '';
                continue;
            }
            $rest = trim(mb_substr($name, mb_strlen($prefix)), " \t-–—/|,:");
            $out[$i] = str_replace('|', '/', $rest);
        }

        return $out;
    }

    /**
     * @param array<int, string> $names
     */
    private function commonWordPrefix(array $names): string
    {
        $split = array_map(static fn(string $n): array => preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [], $names);
        $first = $split[array_key_first($split)] ?? [];
        $prefix = [];
        foreach ($first as $index => $word) {
            foreach ($split as $words) {
                if (!isset($words[$index]) || mb_strtolower($words[$index]) !== mb_strtolower($word)) {
                    break 2;
                }
            }
            $prefix[] = $word;
        }

        return implode(' ', $prefix);
    }

    /**
     * @param array<int, string> $values
     */
    private function uniqueNonEmpty(array $values): bool
    {
        if ($values === [] || in_array('', $values, true)) {
            return false;
        }

        return count(array_unique(array_map('mb_strtolower', $values))) === count($values);
    }

    /**
     * Her varyantın eksen kümesi aynı, boş değil ve birleşimler tekil mi?
     *
     * @param array<int, array<string, string>> $combos
     */
    private function usableCombos(array $combos): bool
    {
        if ($combos === []) {
            return false;
        }

        $names = null;
        $seen = [];
        foreach ($combos as $combo) {
            if ($combo === []) {
                return false;
            }
            $keys = array_keys($combo);
            sort($keys);
            if ($names !== null && $keys !== $names) {
                return false;
            }
            $names = $keys;

            ksort($combo);
            $signature = mb_strtolower(json_encode($combo, JSON_UNESCAPED_UNICODE) ?: '');
            if (isset($seen[$signature])) {
                return false;
            }
            $seen[$signature] = true;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function wooLabel(array $product): string
    {
        $sku = trim((string) ($product['sku'] ?? ''));

        return $sku !== '' ? $sku : '#' . (int) ($product['id'] ?? 0);
    }

    /**
     * @return array{success: false, sent: 0, failed: 0, skipped: 1, errors: array<int, array{product_id: int, error: string}>}
     */
    private function wooFail(int $parentProductId, string $message): array
    {
        return [
            'success' => false,
            'sent'    => 0,
            'failed'  => 0,
            'skipped' => 1,
            'errors'  => [['product_id' => $parentProductId, 'error' => $message]],
        ];
    }

    /**
     * Ürün görsellerini çıkar (child → parent fallback)
     *
     * @return array<int, array{url: string}>
     */
    private function extractImages(array $product, array $parent): array
    {
        $images = [];
        $json = $product['images_json'] ?? $parent['images_json'] ?? '[]';
        $parsed = json_decode($json, true);

        if (is_array($parsed)) {
            foreach ($parsed as $img) {
                $url = is_array($img) ? ($img['url'] ?? ($img['src'] ?? '')) : (string) $img;
                $url = $this->publicImageUrl((string) $url);
                if ($url !== '') {
                    $images[] = ['url' => $url];
                }
            }
        }

        if (empty($images)) {
            $mainImage = $this->publicImageUrl((string) ($product['main_image'] ?? $parent['main_image'] ?? ''));
            if ($mainImage !== '') {
                $images[] = ['url' => $mainImage];
            }
        }

        return $images;
    }

    /**
     * Görsel adresini pazaryerinin çekebileceği mutlak URL'ye çevirir (göreli / localhost adresler
     * canlı köke — public/index.php app_asset_url; ProductPublishService ile aynı kural).
     */
    private function publicImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return function_exists('app_asset_url') ? app_asset_url($url) : $url;
    }

    /**
     * Marketplace servisini çözümle
     */
    private function resolveService(string $slug): ?object
    {
        $class = self::SLUG_MAP[$slug] ?? null;
        if ($class === null) {
            return null;
        }
        return $this->container->get($class);
    }

    /**
     * Hata döndürme helper
     * @return array{success: false, sent: 0, failed: 0, errors: array}
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'sent' => 0, 'failed' => 0, 'errors' => [['error' => $message]]];
    }
}
