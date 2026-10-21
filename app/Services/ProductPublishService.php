<?php
/**
 * OzyConnect Panel — ProductPublishService (Marketplace-Agnostic)
 * Ürünleri pazaryerlerine gönderme (listeleme) servisi.
 * Doğrudan TrendyolService'e bağımlı DEĞİL — Container üzerinden dinamik çözümleme.
 *
 * İŞ KURALLARI:
 * - Batch size limiti: 50 ürün (otomatik bölünür)
 * - Retry: AbstractMarketplace TEK SAHİBİ — ProductPublishService retry YAPMAZ
 *   (AbstractMarketplace.httpRequest() zaten 429/5xx için exponential backoff retry uygular)
 * - Transaction: marketplace_products update DB transaction içinde
 * - API başarılı + DB başarısız senaryosu: hata loglanır, partial success olarak raporlanır,
 *   retry güvenli (upsert idempotent + Trendyol barcode bazlı create-or-update)
 * - broadcastStock izole: hatası publish sonucunu etkilemez
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
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
use RuntimeException;

final class ProductPublishService
{
    private const BATCH_SIZE = 50;

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
        private readonly StockManager $stockManager,
        private readonly Logger $logger,
    ) {}

    /**
     * Pazaryeri onayı bekleyen gönderimlerin SONUCUNU sorar ve kaydeder.
     *
     * Gönderim anında sonuç yalnızca ~4,5 saniye bekleniyor; pazaryeri (özellikle
     * Trendyol) toplu isteği dakikalarca işlediği için ürünler "Batch kontrol
     * bekliyor" durumunda asılı kalıyor ve reddetme sebebi HİÇ öğrenilmiyordu.
     * Bu metot cron/sync_publish_batches.php tarafından periyodik çağrılır:
     * onaylananı yayında işaretler, reddedileni sebebiyle birlikte yazar.
     *
     * @return array{checked:int, approved:int, rejected:int, pending:int, errors:array<int,string>}
     */
    public function resolvePendingBatches(int $marketplaceId = 0, int $limit = 500): array
    {
        $sonuc = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'errors' => []];

        $bekleyen = $this->mpProductModel->getPendingBatches($marketplaceId, $limit);
        if ($bekleyen === []) {
            return $sonuc;
        }

        $servisler     = [];   // mpId  => servis|null
        $sluglar       = [];   // mpId  => slug
        $batchOnbellek = [];   // mpId:batchId => durum haritası

        foreach ($bekleyen as $satir) {
            $mpId    = (int) $satir['marketplace_id'];
            $batchId = trim(substr((string) ($satir['sync_error'] ?? ''), strlen('Batch kontrol bekliyor: ')));
            if ($batchId === '') {
                continue;
            }

            if (!array_key_exists($mpId, $servisler)) {
                $servisler[$mpId] = null;
                $mp = $this->marketplaceModel->find($mpId);
                if ($mp !== false) {
                    $sluglar[$mpId] = (string) $mp['slug'];
                    try {
                        $servis = $this->resolveMarketplaceService($sluglar[$mpId]);
                        if (method_exists($servis, 'getBatchStatus')) {
                            $servisler[$mpId] = $servis;
                        }
                    } catch (\Throwable $e) {
                        $sonuc['errors'][] = "#{$mpId}: " . $e->getMessage();
                    }
                }
            }

            $servis = $servisler[$mpId];
            if ($servis === null) {
                continue;
            }

            $sonuc['checked']++;
            $anahtar = $mpId . ':' . $batchId;

            try {
                $batchOnbellek[$anahtar] ??= $this->checkBatchStatus($servis, $batchId);
            } catch (\Throwable $e) {
                $batchOnbellek[$anahtar] = [];
                $sonuc['errors'][] = "batch {$batchId}: " . $e->getMessage();
            }

            $durum = $this->batchStatusForProduct(
                ['batch_status_by_barcode' => $batchOnbellek[$anahtar]],
                ['barcode' => (string) ($satir['barcode'] ?? ''), 'sku' => (string) ($satir['sku'] ?? '')]
            );

            if ($durum === null) {
                $sonuc['pending']++;
                continue;
            }

            $d = (string) ($durum['status'] ?? '');
            if ($d === 'SUCCESS') {
                $this->mpProductModel->update((int) $satir['id'], $this->batchSuccessState(
                    $sluglar[$mpId] ?? '',
                    ['barcode' => (string) ($satir['barcode'] ?? ''), 'sku' => (string) ($satir['sku'] ?? '')],
                    $durum
                ));
                $sonuc['approved']++;
            } elseif ($d === 'FAILED') {
                $sebep = trim((string) ($durum['message'] ?? ''));
                $this->mpProductModel->update((int) $satir['id'], [
                    'is_listed'  => 0,
                    'sync_error' => 'Pazaryeri reddetti: ' . ($sebep !== '' ? $sebep : 'sebep bildirilmedi'),
                ]);
                $sonuc['rejected']++;
            } else {
                $sonuc['pending']++;
            }
        }

        return $sonuc;
    }

    /**
     * Tek ürünü pazaryerine gönder
     *
     * @return array{success: bool, error: ?string}
     */
    public function publishProduct(int $productId, int $marketplaceId): array
    {
        $result = $this->publishProducts([$productId], $marketplaceId);

        if ($result['success'] > 0) {
            return ['success' => true, 'error' => null];
        }

        $error = !empty($result['errors']) ? $result['errors'][0]['error'] ?? 'Bilinmeyen hata' : 'Bilinmeyen hata';
        return ['success' => false, 'error' => $error];
    }

    /**
     * Toplu ürün gönderimi (batch size max 50, otomatik bölünür)
     * marketplace_products update + sync_log write aynı transaction içinde
     * API hataları (429, 5xx) için exponential backoff retry (max 3)
     *
     * @param array<int> $productIds
     * @return array{success: int, failed: int, skipped: int, errors: array}
     */
    public function publishProducts(array $productIds, int $marketplaceId): array
    {
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false) {
            return ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [['error' => 'Pazaryeri bulunamadı']]];
        }

        $marketplaceService = $this->resolveMarketplaceService($marketplace['slug']);

        $totalSuccess = 0;
        $totalFailed = 0;
        $totalSkipped = 0;
        $totalProcessing = 0;
        $allErrors = [];
        $allBatchIds = [];

        // WooCommerce: varyant grubu (ana ürün + varyantlar) değişken ürün olarak ayrı gönderilir
        $wooVariantParentIds = [];
        if ($marketplace['slug'] === self::WOO_SLUG) {
            [$productIds, $wooVariantParentIds] = $this->splitWooCommerceVariantGroups($productIds);
        }

        // Batch'lere böl
        $batches = array_chunk($productIds, self::BATCH_SIZE);

        foreach ($batches as $batchIds) {
            $batchResult = $this->publishBatch($batchIds, $marketplaceId, $marketplace, $marketplaceService);
            $totalSuccess += $batchResult['success'];
            $totalFailed += $batchResult['failed'];
            $totalSkipped += $batchResult['skipped'];
            $totalProcessing += (int) ($batchResult['processing'] ?? 0);
            $allErrors = array_merge($allErrors, $batchResult['errors']);
            $allBatchIds = array_merge($allBatchIds, $batchResult['batch_ids'] ?? []);
        }

        foreach ($wooVariantParentIds as $parentId) {
            try {
                $groupResult = $this->container->get(VariantPublishService::class)
                    ->publishWooCommerceGroup($parentId, $marketplaceId, false);
            } catch (\Throwable $e) {
                $this->logger->error("WooCommerce varyant grubu gönderilemedi (#{$parentId}): " . $e->getMessage(), 'ProductPublish');
                $groupResult = [
                    'sent' => 0, 'failed' => 1, 'skipped' => 0,
                    'errors' => [['product_id' => $parentId, 'error' => 'Varyant grubu gönderilemedi: ' . $e->getMessage()]],
                ];
            }
            $totalSuccess += $groupResult['sent'];
            $totalFailed += $groupResult['failed'];
            $totalSkipped += $groupResult['skipped'];
            $allErrors = array_merge($allErrors, $groupResult['errors']);
        }

        // Sync log — transaction dışında (tüm batch'ler bittikten sonra)
        $this->logSyncResult($marketplaceId, $totalSuccess, $totalFailed, $totalSkipped, $allErrors);

        return [
            'success' => $totalSuccess,
            'failed'  => $totalFailed,
            'skipped' => $totalSkipped,
            // Pazaryeri onayı bekleyenler (başarılı sayılmaz) — BulkPublishService bu sayacı okur
            'processing' => $totalProcessing,
            'errors'  => $allErrors,
            'batch_ids' => array_values(array_unique(array_filter($allBatchIds))),
        ];
    }

    /**
     * Ürünün publish için hazır olup olmadığını kontrol et
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateForPublish(int $productId, int $marketplaceId): array
    {
        $errors = [];

        $product = $this->productModel->find($productId);
        if ($product === false) {
            return ['valid' => false, 'errors' => ['Ürün bulunamadı']];
        }

        $marketplace = $this->marketplaceModel->find($marketplaceId);
        $marketplaceSlug = (string) ($marketplace['slug'] ?? '');

        // WooCommerce kendi kurallarıyla doğrulanır (barkod/kategori/marka isteğe bağlı, SKU zorunlu)
        if ($marketplaceSlug === self::WOO_SLUG && is_array($marketplace)) {
            return $this->validateForWooCommerce($product, $marketplace);
        }

        // a) En az bir görsel
        if (!$this->productHasImage($product)) {
            $errors[] = 'Ürün görseli eksik (main_image boş)';
        }

        $isHepsiburada = $marketplaceSlug === 'hepsiburada';

        // HB mükerrer koruması: ürünün Hepsiburada'da mevcut/eşleşmiş ilanı varsa yeni ürün açılmaz
        if ($isHepsiburada) {
            $existingListing = $this->findHepsiburadaExistingListing($product, $marketplaceId);
            if ($existingListing !== null) {
                $errors[] = "Bu ürün Hepsiburada'da zaten satışta (merchantSku " . $existingListing['merchant_sku']
                    . ") — yeni ürün oluşturulmaz; İlan Eşleştirme'yi kullanın.";
            }
        }

        if ($this->requiresPublicImages($marketplaceSlug)) {
            $privateImages = $this->findPrivateImageUrls($product);
            if (!empty($privateImages)) {
                $errors[] = 'Pazaryeri görselleri dışarıdan erişilebilir URL olmalı. localhost/özel ağ görseli kullanılamaz; '
                    . '.env APP_URL canlı alan adı olmalı (ör. https://alanadiniz.com): ' . $privateImages[0];
            }
        }

        // b) Geçerli fiyat
        if ((float) ($product['base_price'] ?? 0) <= 0) {
            $errors[] = 'Geçersiz fiyat (base_price > 0 olmalı)';
        }

        // c) Geçerli stok
        if ((int) ($product['stock_qty'] ?? -1) < 0) {
            $errors[] = 'Geçersiz stok (stock_qty >= 0 olmalı)';
        }

        // d) Barkod — HB gerçek barkod (EAN/GTIN) ister; stok kodunun kopyası yer tutucudur
        if ($isHepsiburada && $this->isPlaceholderBarcode($product)) {
            $barcodeText = trim((string) ($product['barcode'] ?? ''));
            $errors[] = $barcodeText === ''
                ? 'Barkod eksik — Hepsiburada gerçek barkod (EAN/GTIN) ister; ürünün Barkod alanını doldurun'
                : "Barkod yer tutucu ('{$barcodeText}') — Hepsiburada gerçek barkod (EAN/GTIN) ister; ürünün Barkod alanını doldurun";
        } elseif (empty($product['barcode'])) {
            $errors[] = 'Barkod eksik';
        }

        // e) Kategori eşleştirmesi
        $categoryId = $product['category_id'] ?? null;
        if ($categoryId === null) {
            $errors[] = 'Kategori atanmamış';
        } else {
            $catMapping = $this->catMappingModel->findMapping((int) $categoryId, $marketplaceId);
            if ($catMapping === false) {
                $errors[] = 'Kategori pazaryeri eşleştirmesi yapılmamış';
            } else {
                $leafStatus = $this->catMappingModel->getMarketplaceCategoryLeafStatus(
                    $marketplaceId,
                    (string) ($catMapping['marketplace_category_id'] ?? '')
                );
                // Satışa uygunluk: yaprak + (biliniyorsa) aktif ve satışa açık. Durum bilgisi
                // olmayan eski önbellekte 'sellable' yoktur — yaprak kontrolüne düşülür.
                $isSellable = $leafStatus !== null
                    ? (bool) ($leafStatus['sellable'] ?? $leafStatus['is_leaf'])
                    : true;
                if ($leafStatus !== null && !$isSellable) {
                    $categoryLabel = $leafStatus['full_path'] !== ''
                        ? $leafStatus['full_path']
                        : ($leafStatus['name'] !== '' ? $leafStatus['name'] : (string) ($catMapping['marketplace_category_id'] ?? ''));
                    $marketplaceName = (string) ($marketplace['display_name'] ?? $marketplace['name'] ?? $marketplaceSlug);
                    $errors[] = 'Eşlenen ' . $marketplaceName . ' kategorisi satışa kapalı: ' . $categoryLabel
                        . (!$leafStatus['is_leaf'] ? ' (ara kategori — en alt/final kategori seçilmeli)' : '')
                        . '. Kategori eşleştirmesini değiştirin.';
                }

                // f) Zorunlu kategori özellikleri
                // HB: özellikler HB'nin kendi listesinden (ürün değeri → kategori varsayılanı → otomatik).
                // HB dışı: attribute_mapping_json LİSTE biçimi [{id,name,required}]; nesne gelirse yok sayılır.
                $attrMapping = $isHepsiburada ? null : json_decode((string) ($catMapping['attribute_mapping_json'] ?? '[]'), true);
                if ($isHepsiburada) {
                    $hbResolution = $this->resolveHepsiburadaAttributes($product, $catMapping);
                    $errors = array_merge($errors, $hbResolution['errors']);
                } elseif (is_array($attrMapping) && array_is_list($attrMapping)) {
                    $requiredAttrs = array_filter($attrMapping, fn($a) => is_array($a) && !empty($a['required']));
                    if (!empty($requiredAttrs)) {
                        $productAttrs = $this->pAttrValueModel->getByProduct($productId);
                        $filledAttrIds = array_column($productAttrs, 'attribute_id');

                        foreach ($requiredAttrs as $reqAttr) {
                            $attrId = $reqAttr['id'] ?? 0;
                            if ($attrId > 0 && !in_array($attrId, $filledAttrIds)) {
                                $errors[] = "Zorunlu özellik eksik: " . ($reqAttr['name'] ?? "ID:{$attrId}");
                            }
                        }
                    }
                }
            }
        }

        // g) Marka eşleştirmesi — yalnız payload'unda pazaryeri marka ID'si kullanan
        // pazaryerleri için zorunlu. N11/ÇiçekSepeti gibi pazaryerlerinde gerçek marka
        // API'si yok; oralarda marka eşleştirmesi publish'i bloklamasın.
        if ($this->requiresBrandMapping($marketplaceSlug)) {
            $brandId = $product['brand_id'] ?? null;
            if ($brandId === null) {
                $errors[] = 'Marka atanmamış';
            } else {
                $brandMapping = $this->brandMappingModel->findMapping((int) $brandId, $marketplaceId);
                if ($brandMapping === false) {
                    $errors[] = 'Marka pazaryeri eşleştirmesi yapılmamış';
                } elseif ((int) ($brandMapping['marketplace_brand_id'] ?? 0) <= 0) {
                    // Eşleştirmede pazaryeri marka KİMLİĞİ yerine marka ADI kalmışsa
                    // payload'a brandId:0 gidiyor ve pazaryeri kalemi reddediyordu.
                    // Önceden yalnız log'a uyarı yazılıp gönderim yine yapılıyordu.
                    $errors[] = 'Marka eşleştirmesi geçersiz (pazaryeri marka kimliği yok) — markayı yeniden eşleştirin';
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Ürün payload'unda pazaryeri marka ID'si zorunlu olan pazaryerleri
     */
    /**
     * Katalog kaydı fiyatı belirlemeyen, fiyatın AYRI listeleme ucundan
     * gönderilmesi gereken pazaryerleri.
     */
    private const LISTING_PRICE_REQUIRED = ['hepsiburada'];

    private function requiresBrandMapping(string $marketplaceSlug): bool
    {
        return in_array($marketplaceSlug, ['trendyol', 'pazarama', 'oziygo', 'idefix'], true);
    }

    // ═══════════════════════════════════════════════════════
    // HEPSİBURADA — kategori özellikleri, marka, barkod, mükerrer koruması
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün oluşturma (import) isteği fiyat ve stoğu da taşıyan pazaryerleri: ürün henüz
     * eşleşmediği için listeleme uçları (pushListingPriceStock) bu yolda ÇAĞRILMAZ.
     */
    private const IMPORT_CARRIES_PRICE_STOCK = ['hepsiburada'];

    /** Hepsiburada tek yüklemede en fazla bu kadar ürün kabul eder (createProducts aynı boyutta böler) */
    private const HB_IMPORT_CHUNK = 100;

    /** HB özellik değeri kaynaklarının okunur adları */
    private const HB_SOURCE_LABELS = [
        'urun'       => 'ürün değeri',
        'varsayilan' => 'kategori varsayılanı',
        'otomatik'   => 'otomatik türetme',
        'eslesme'    => 'marka eşleştirmesi',
        ''           => 'değer yok',
    ];

    /** hb_listing_cache tablosu bu kurulumda var mı (null: henüz denenmedi) */
    private ?bool $hbListingCacheAvailable = null;

    /**
     * HB zorunlu kategori özelliklerini ve markayı çöz.
     * Değer kaynağı sırası: ürün bazlı değer → kategori varsayılanı (attribute_mapping_json) → otomatik türetme.
     * Enum değeri HB'nin geçerli listesinde değilse gönderilmez; açık hata döner.
     *
     * @param array<string, mixed> $product products satırı (en az id, store_id, name, sku, brand_id)
     * @param array<string, mixed>|false|null $catMapping category_marketplace_mapping satırı (HB)
     * @param array<string, mixed>|false|null $brandMapping null: ürün markasından bulunur
     * @return array{values: array<string, string>, details: array<int, array<string, mixed>>, brand: array{value: string, source: string}, errors: array<int, string>}
     */
    public function resolveHepsiburadaAttributes(array $product, array|false|null $catMapping, array|false|null $brandMapping = null): array
    {
        $catMapping = is_array($catMapping) ? $catMapping : false;
        $result = ['values' => [], 'details' => [], 'brand' => ['value' => '', 'source' => ''], 'errors' => []];

        $result['brand'] = $this->resolveHepsiburadaBrand($product, $catMapping, $brandMapping);
        if ($result['brand']['value'] === '') {
            $result['errors'][] = "Marka eksik — Hepsiburada 'Marka' zorunlu: ürüne marka atayın ya da Kategori Eşleştirme > Kategori Özellikleri'nden varsayılan marka girin";
        }

        $hbCategoryId = $catMapping !== false ? trim((string) ($catMapping['marketplace_category_id'] ?? '')) : '';
        if ($hbCategoryId === '') {
            $result['errors'][] = 'Hepsiburada kategori eşleştirmesi yok';
            return $result;
        }

        $hepsiburada = $this->hepsiburadaService();
        try {
            $required = $hepsiburada->getRequiredCategoryAttributes($hbCategoryId);
        } catch (\Throwable $e) {
            $result['errors'][] = "Hepsiburada kategori özellikleri alınamadı ({$hbCategoryId}): " . $e->getMessage();
            return $result;
        }

        $productValues = CategoryMarketplaceMapping::productAttributeValues(
            $catMapping,
            CategoryMarketplaceMapping::attributeProductKey($product)
        );
        $defaults = CategoryMarketplaceMapping::attributeDefaults($catMapping);
        $info = $this->hepsiburadaProductInfo($product);

        foreach ($required as $attr) {
            $attrId = (string) ($attr['id'] ?? '');
            if ($attrId === '') {
                continue;
            }
            $attrName = (string) ($attr['name'] ?? $attrId);
            $attrType = (string) ($attr['type'] ?? 'string');

            if (isset($productValues[$attrId])) {
                [$value, $source] = [(string) $productValues[$attrId], 'urun'];
            } elseif (isset($defaults[$attrId])) {
                [$value, $source] = [(string) $defaults[$attrId], 'varsayilan'];
            } else {
                $value = (string) ($hepsiburada->suggestAttributeValue($attr, $info) ?? '');
                $source = $value !== '' ? 'otomatik' : '';
            }

            $detail = [
                'id' => $attrId, 'name' => $attrName, 'group' => (string) ($attr['group'] ?? ''), 'type' => $attrType,
                'value' => $value, 'source' => $source, 'valid' => false,
            ];

            if ($value === '') {
                $result['errors'][] = "Hepsiburada zorunlu özelliği çözülemedi: {$attrName} — Kategori Eşleştirme > Kategori Özellikleri'nden değer seçin";
            } elseif ($attrType === 'enum') {
                $canonical = \App\Services\Marketplace\HepsiburadaService::canonicalAttributeValue($value, (array) ($attr['values'] ?? []));
                if ($canonical === null) {
                    $result['errors'][] = "Hepsiburada '{$attrName}' değeri geçersiz: '{$value}' (" . self::HB_SOURCE_LABELS[$source]
                        . ") — HB'nin geçerli değer listesinde yok; Kategori Özellikleri'nden listeden seçin";
                } else {
                    $detail['value'] = $canonical;
                    $detail['valid'] = true;
                    $result['values'][$attrId] = $canonical;
                }
            } else {
                $detail['valid'] = true;
                $result['values'][$attrId] = $value;
            }

            $result['details'][] = $detail;
        }

        return $result;
    }

    /**
     * Ürünün Hepsiburada'da mevcut/eşleşmiş ilanı (mükerrer koruması) — yalnız aktif mağazanın kayıtları.
     * 1) marketplace_products: aynı ürünün HB kaydı ya da aynı barkod/merchantSku'lu içe aktarılmış HB ilanı;
     *    'yayında/eşleşti' = is_listed=1 veya pazaryeri ürün kimliği gerçek bir hbSku (HBC…).
     * 2) hb_listing_cache (HB katalog/ilan önbelleği): aynı barkod ya da merchantSku (tablo yoksa atlanır).
     *
     * @param array<string, mixed> $product
     * @return array{merchant_sku: string, hb_sku: string, kaynak: string}|null
     */
    public function findHepsiburadaExistingListing(array $product, int $marketplaceId): ?array
    {
        $storeId = $this->storeIdFor($product);
        if ($storeId <= 0 || $marketplaceId <= 0) {
            return null;
        }

        $productId = (int) ($product['id'] ?? 0);
        $barcode = $this->isPlaceholderBarcode($product) ? '' : trim((string) ($product['barcode'] ?? ''));
        $sku = \App\Services\Marketplace\HepsiburadaService::normalizeMerchantSku((string) ($product['sku'] ?? ''));

        $row = $this->db->fetchOne(
            "SELECT marketplace_sku, marketplace_product_id
               FROM marketplace_products
              WHERE store_id = ? AND marketplace_id = ?
                AND (is_listed = 1
                     OR (marketplace_product_id REGEXP '^HBC[A-Z0-9]{6,}$'
                         AND marketplace_product_id <> COALESCE(marketplace_sku, '')))
                AND (product_id = ?
                     OR (? <> '' AND marketplace_barcode = ?)
                     OR (? <> '' AND UPPER(marketplace_sku) = ?))
              ORDER BY (product_id = ?) DESC, is_listed DESC, id DESC
              LIMIT 1",
            [$storeId, $marketplaceId, $productId, $barcode, $barcode, $sku, $sku, $productId]
        );
        if ($row !== false) {
            $merchantSku = trim((string) ($row['marketplace_sku'] ?? ''));
            $hbSku = trim((string) ($row['marketplace_product_id'] ?? ''));

            return [
                'merchant_sku' => $merchantSku !== '' ? $merchantSku : $hbSku,
                'hb_sku'       => $hbSku,
                'kaynak'       => 'marketplace_products',
            ];
        }

        return $this->hepsiburadaListingCacheHit($storeId, $barcode, $sku);
    }

    /**
     * HB barkodu yer tutucu mu: boş, 'BP-<id>' ya da stok kodunun kopyası olup sayısal GTIN (8-14 hane) olmayan değer.
     *
     * @param array<string, mixed> $product
     */
    private function isPlaceholderBarcode(array $product): bool
    {
        $barcode = trim((string) ($product['barcode'] ?? ''));
        if ($barcode === '' || preg_match('/^BP-\d+$/i', $barcode) === 1) {
            return true;
        }

        $sku = trim((string) ($product['sku'] ?? ''));
        if ($sku === '' || strcasecmp($barcode, $sku) !== 0) {
            return false;
        }

        return preg_match('/^\d{8,14}$/', $barcode) !== 1;
    }

    /**
     * Marka: HB marka eşleştirmesi → ürünün marka adı → kategori varsayılanı ('Marka')
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed>|false $catMapping
     * @param array<string, mixed>|false|null $brandMapping
     * @return array{value: string, source: string}
     */
    private function resolveHepsiburadaBrand(array $product, array|false $catMapping, array|false|null $brandMapping): array
    {
        $brandId = (int) ($product['brand_id'] ?? 0);
        $marketplaceId = $catMapping !== false ? (int) ($catMapping['marketplace_id'] ?? 0) : 0;
        if ($brandMapping === null && $brandId > 0 && $marketplaceId > 0) {
            $brandMapping = $this->brandMappingModel->findMapping($brandId, $marketplaceId);
        }

        $mapped = is_array($brandMapping) ? trim((string) ($brandMapping['marketplace_brand_name'] ?? '')) : '';
        if ($mapped !== '') {
            return ['value' => $mapped, 'source' => 'eslesme'];
        }

        $storeId = $this->storeIdFor($product);
        if ($brandId > 0 && $storeId > 0) {
            $row = $this->db->fetchOne(
                'SELECT name FROM brands WHERE id = ? AND store_id = ? LIMIT 1',
                [$brandId, $storeId]
            );
            $name = $row !== false ? trim((string) ($row['name'] ?? '')) : '';
            if ($name !== '') {
                return ['value' => $name, 'source' => 'urun'];
            }
        }

        $default = trim((string) (CategoryMarketplaceMapping::attributeDefaults($catMapping)['Marka'] ?? ''));

        return $default !== '' ? ['value' => $default, 'source' => 'varsayilan'] : ['value' => '', 'source' => ''];
    }

    /**
     * Otomatik türetme, desi ve açıklama için ürün bilgisi (products.weight kg / desi / description).
     * Çağıran ürün satırını eksik alanlarla verdiyse (ör. panel listesi) eksikler products'tan okunur.
     *
     * @param array<string, mixed> $product
     * @return array{name: string, weight: ?float, desi: ?float, description_html: string}
     */
    private function hepsiburadaProductInfo(array $product): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $storeId = $this->storeIdFor($product);
        $missing = !array_key_exists('weight', $product)
            || !array_key_exists('desi', $product)
            || !array_key_exists('description', $product);

        if ($missing && $productId > 0 && $storeId > 0) {
            try {
                $row = $this->db->fetchOne(
                    'SELECT weight, desi, description FROM products WHERE id = ? AND store_id = ? LIMIT 1',
                    [$productId, $storeId]
                );
                if ($row !== false) {
                    $product += $row;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('HB ürün bilgisi okunamadı (#' . $productId . '): ' . $e->getMessage(), 'ProductPublish');
            }
        }

        $weight = (float) ($product['weight'] ?? 0);
        $desi = (float) ($product['desi'] ?? 0);

        return [
            'name'             => trim((string) ($product['name'] ?? '')),
            'weight'           => $weight > 0 ? $weight : null,
            'desi'             => $desi > 0 ? $desi : null,
            'description_html' => trim((string) ($product['description'] ?? '')),
        ];
    }

    /**
     * HB ilan önbelleğinde (hb_listing_cache — HB katalog + ilan listesinin mağaza bazlı yerel kopyası)
     * aynı barkod ya da merchantSku. Tablo bu kurulumda yoksa (migration uygulanmamış) null.
     *
     * @return array{merchant_sku: string, hb_sku: string, kaynak: string}|null
     */
    private function hepsiburadaListingCacheHit(int $storeId, string $barcode, string $merchantSku): ?array
    {
        if ($this->hbListingCacheAvailable === false || ($barcode === '' && $merchantSku === '')) {
            return null;
        }

        try {
            $row = $this->db->fetchOne(
                "SELECT merchant_sku, hb_sku
                   FROM hb_listing_cache
                  WHERE store_id = ?
                    AND ((? <> '' AND barcode = ?) OR (? <> '' AND merchant_sku = ?))
                  ORDER BY is_salable DESC, has_listing DESC
                  LIMIT 1",
                [$storeId, $barcode, $barcode, $merchantSku, $merchantSku]
            );
            $this->hbListingCacheAvailable = true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02' || (int) ($e->errorInfo[1] ?? 0) === 1146) {
                $this->hbListingCacheAvailable = false;
            } else {
                $this->logger->warning('HB ilan önbelleği okunamadı: ' . $e->getMessage(), 'ProductPublish');
            }
            return null;
        }

        if ($row === false) {
            return null;
        }

        $hbSku = trim((string) ($row['hb_sku'] ?? ''));
        $cachedSku = trim((string) ($row['merchant_sku'] ?? ''));

        return [
            'merchant_sku' => $cachedSku !== '' ? $cachedSku : $hbSku,
            'hb_sku'       => $hbSku,
            'kaynak'       => 'hb_listing_cache',
        ];
    }

    /**
     * Ürünün mağazası: ürün satırındaki store_id, yoksa aktif tenant (yoksa 0 — sorgular atlanır).
     *
     * @param array<string, mixed> $product
     */
    private function storeIdFor(array $product): int
    {
        $storeId = (int) ($product['store_id'] ?? 0);
        if ($storeId > 0) {
            return $storeId;
        }

        try {
            $tenant = $this->container->get(\App\Core\TenantContext::class);
            return $tenant->hasStore() ? $tenant->currentStoreId() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function hepsiburadaService(): \App\Services\Marketplace\HepsiburadaService
    {
        return $this->container->get(\App\Services\Marketplace\HepsiburadaService::class);
    }

    private function importCarriesPriceStock(string $slug): bool
    {
        return in_array($slug, self::IMPORT_CARRIES_PRICE_STOCK, true);
    }

    /**
     * Tek doğrulama hatası HB mükerrer koruması mı ve ürünün kendi kaydı zaten yayında mı?
     *
     * @param array<int, string> $validationErrors
     */
    private function isOwnListingDuplicateOnly(array $validationErrors, int $productId, int $marketplaceId): bool
    {
        if (count($validationErrors) !== 1 || !str_contains((string) reset($validationErrors), 'zaten satışta')) {
            return false;
        }

        $row = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);

        return $row !== false && (int) ($row['is_listed'] ?? 0) === 1;
    }

    // ═══════════════════════════════════════════════════════
    // WOOCOMMERCE — doğrulama, ürün verisi, senkron sonuç işleme
    // ═══════════════════════════════════════════════════════

    private const WOO_SLUG = 'woocommerce';

    /**
     * WooCommerce kuralları: SKU, fiyat > 0 ve stok ≥ 0 zorunlu; barkod, kategori ve marka eşleştirmesi
     * isteğe bağlı; görsel zorunlu değil ama mağaza herkese açık adresteyse görseller de herkese açık olmalı.
     * Varyant grubunun bir üyesi doğrulanırken bütün grup (ana ürün + varyantlar) doğrulanır.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $marketplace
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateForWooCommerce(array $product, array $marketplace): array
    {
        $parentId = $this->wooCommerceVariantParentId($product);
        if ($parentId > 0) {
            return $this->validateWooCommerceGroup($parentId, $marketplace);
        }

        [$errors, $publicStore] = $this->wooStoreCheck($marketplace);
        $errors = array_merge($errors, $this->wooProductErrors($product, (int) ($marketplace['id'] ?? 0), $publicStore, false));

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Varyant grubunun tamamı: ana ürün (değişken ürün ise fiyat/stok aranmaz), her varyant ve grup içi SKU tekilliği.
     *
     * @param array<string, mixed> $marketplace
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateWooCommerceGroup(int $parentId, array $marketplace): array
    {
        [$errors, $publicStore] = $this->wooStoreCheck($marketplace);
        $marketplaceId = (int) ($marketplace['id'] ?? 0);

        $group = $this->wooCommerceVariantGroup($parentId);
        if ($group === null) {
            $errors[] = 'Varyant grubu bulunamadı (ana ürün ya da aktif varyant yok)';

            return ['valid' => false, 'errors' => $errors];
        }

        $parentIsVariant = $group['parent_is_variant'];
        foreach ($this->wooProductErrors($group['parent'], $marketplaceId, $publicStore, !$parentIsVariant) as $error) {
            $errors[] = 'Ana ürün: ' . $error;
        }
        foreach ($group['variants'] as $variant) {
            foreach ($this->wooProductErrors($variant, $marketplaceId, $publicStore, false) as $error) {
                $errors[] = 'Varyant ' . $this->wooProductLabel($variant) . ': ' . $error;
            }
        }

        // WooCommerce SKU'yu mağaza genelinde tekil tutar (büyük/küçük harf ayırmadan)
        $seen = [];
        $members = array_merge([$group['parent']], $group['variants']);
        foreach ($members as $member) {
            $sku = $this->wooCommerceSku($member, $marketplaceId);
            if ($sku === '') {
                continue;
            }
            $key = mb_strtolower($sku);
            if (isset($seen[$key])) {
                $errors[] = "Aynı SKU grupta birden fazla üründe kullanılıyor: {$sku} — her varyantın SKU'su farklı olmalı";
            }
            $seen[$key] = true;
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Mağaza adresi kontrolü: [hatalar, mağaza herkese açık mı].
     *
     * @param array<string, mixed> $marketplace
     * @return array{0: array<int, string>, 1: bool}
     */
    private function wooStoreCheck(array $marketplace): array
    {
        $storeUrl = $this->wooStoreUrl($marketplace);
        if ($storeUrl === '') {
            return [['WooCommerce mağaza adresi girilmemiş — Pazaryerleri › WooCommerce ayarlarından mağaza adresini girin'], false];
        }

        return [[], $this->isPublicHttpUrl($storeUrl)];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, string>
     */
    private function wooProductErrors(array $product, int $marketplaceId, bool $publicStore, bool $variableParent): array
    {
        $errors = [];
        if ($this->wooCommerceSku($product, $marketplaceId) === '') {
            $errors[] = 'Stok kodu (SKU) eksik — WooCommerce ürünü SKU ile tanır';
        }

        // Değişken ürünün (ana kart) fiyatı/stoğu WooCommerce'te varyasyonlardan gelir
        if (!$variableParent) {
            if ((float) ($product['base_price'] ?? 0) <= 0) {
                $errors[] = 'Geçersiz fiyat (base_price > 0 olmalı)';
            }
            if ((int) ($product['stock_qty'] ?? -1) < 0) {
                $errors[] = 'Geçersiz stok (stock_qty >= 0 olmalı)';
            }
        }

        if ($publicStore) {
            $privateImages = $this->findPrivateImageUrls($product);
            if ($privateImages !== []) {
                $errors[] = 'WooCommerce mağazanız herkese açık adreste; görseller de dışarıdan erişilebilir olmalı '
                    . '(localhost/özel ağ görseli indirilemez). .env APP_URL canlı alan adı olmalı: ' . $privateImages[0];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function wooProductLabel(array $product): string
    {
        $sku = trim((string) ($product['sku'] ?? ''));

        return $sku !== '' ? $sku : '#' . (int) ($product['id'] ?? 0);
    }

    /**
     * Mağaza adresi (extra_config.store_url; şemasız girildiyse https varsayılır, WooCommerceService ile aynı).
     *
     * @param array<string, mixed> $marketplace
     */
    private function wooStoreUrl(array $marketplace): string
    {
        $config = json_decode((string) ($marketplace['extra_config_json'] ?? '{}'), true);
        $url = is_array($config) ? trim((string) ($config['store_url'] ?? '')) : '';
        if ($url === '') {
            return '';
        }

        return preg_match('#^https?://#i', $url) === 1 ? rtrim($url, '/') : 'https://' . rtrim($url, '/');
    }

    /**
     * WooCommerce SKU'su: ürün SKU'su olduğu gibi (normalize edilmez). Ürün WooCommerce ilanına zaten
     * bağlıysa (içe aktarma / önceki gönderim) kayıtlı WooCommerce SKU'su korunur — mükerrer ürün açılmaz.
     *
     * @param array<string, mixed> $product
     */
    public function wooCommerceSku(array $product, int $marketplaceId): string
    {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId > 0 && $marketplaceId > 0) {
            $row = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
            if ($row !== false) {
                $linkedSku = trim((string) ($row['marketplace_sku'] ?? ''));
                if ($linkedSku !== '' && trim((string) ($row['marketplace_product_id'] ?? '')) !== '') {
                    return $linkedSku;
                }
            }
        }

        return trim((string) ($product['sku'] ?? ''));
    }

    /**
     * Ürün bir varyant grubundaysa (ana ürün ya da varyant) grubun ana ürün kimliği; değilse 0.
     * Grup = ana ürün + en az bir aktif varyant (products.parent_id).
     *
     * @param array<string, mixed> $product
     */
    public function wooCommerceVariantParentId(array $product): int
    {
        $parentId = (int) ($product['parent_id'] ?? 0);
        if ($parentId <= 0 && (int) ($product['has_variants'] ?? 0) === 1) {
            $parentId = (int) ($product['id'] ?? 0);
        }
        if ($parentId <= 0) {
            return 0;
        }

        return $this->wooCommerceVariantGroup($parentId) !== null ? $parentId : 0;
    }

    /**
     * Varyant grubu. parent_is_variant: grup bir pazaryerinden içe aktarılmış ve ana ürün de satılan
     * bir varyanttır (varyant öznitelik bilgisi yok) — WooCommerce'e o da varyasyon olarak gider.
     *
     * @return array{parent: array<string, mixed>, variants: array<int, array<string, mixed>>, parent_is_variant: bool}|null
     */
    public function wooCommerceVariantGroup(int $parentId): ?array
    {
        if ($parentId <= 0) {
            return null;
        }

        $group = $this->productModel->getVariantGroup($parentId);
        $parent = $group['parent'] ?? false;
        $variants = array_values(array_filter((array) ($group['variants'] ?? []), 'is_array'));
        if (!is_array($parent) || $variants === []) {
            return null;
        }

        return [
            'parent'            => $parent,
            'variants'          => $variants,
            'parent_is_variant' => $this->isImportedVariantGroup($parent, $variants),
        ];
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<int, array<string, mixed>> $variants
     */
    private function isImportedVariantGroup(array $parent, array $variants): bool
    {
        // Tek ölçüt: stok yönetimi (StockManager, ürün ekranı) aynı kuralla ana ürünün kendi stoğunu korur.
        return Product::isImportedVariantGroup($parent, $variants);
    }

    /**
     * Ürün kimliklerini WooCommerce için böl: varyant grubu üyeleri ana ürün kimliğinde toplanır
     * (grup bir kez gönderilir), diğerleri basit ürün olarak kalır.
     *
     * @param array<int, int|string> $productIds
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function splitWooCommerceVariantGroups(array $productIds): array
    {
        $simpleIds = [];
        $parentIds = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $product = $this->productModel->find($productId);
            $parentId = $product !== false ? $this->wooCommerceVariantParentId($product) : 0;
            if ($parentId > 0) {
                $parentIds[$parentId] = $parentId;
            } else {
                $simpleIds[] = $productId;
            }
        }

        return [$simpleIds, array_values($parentIds)];
    }

    /**
     * Ürün → WooCommerce öğesi (fiyat kuralı ve eşleştirmeler bu pazaryerine göre çözülür).
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function wooCommerceItem(array $product, int $marketplaceId): array
    {
        $price = $this->priceEngine->calculatePrice($product, $marketplaceId);
        $catMapping = (int) ($product['category_id'] ?? 0) > 0
            ? $this->catMappingModel->findMapping((int) $product['category_id'], $marketplaceId)
            : false;
        $brandMapping = (int) ($product['brand_id'] ?? 0) > 0
            ? $this->brandMappingModel->findMapping((int) $product['brand_id'], $marketplaceId)
            : false;
        $brandMapping = $this->resolveBrandMappingId($brandMapping, $marketplaceId, self::WOO_SLUG);

        return $this->buildWooCommerceItem($product, $price, $catMapping, $brandMapping, $marketplaceId);
    }

    /**
     * WooCommerce REST v3 alanları (sku, name, description, short_description, regular_price/sale_price
     * — ondalık '.' metin, manage_stock + stock_quantity, categories[{id}], images[{src}], weight, dimensions,
     * global_unique_id, brands[{id}]) + yayın akışının genel anahtarları. Boş açıklama/kategori/ağırlık
     * gönderilmez (güncellemede WooCommerce'teki mevcut değer silinmesin).
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $price PriceRuleEngine::calculatePrice çıktısı (pazaryeri fiyatı)
     * @param array<string, mixed>|false $catMapping
     * @param array<string, mixed>|false $brandMapping
     * @return array<string, mixed>
     */
    private function buildWooCommerceItem(array $product, array $price, array|false $catMapping, array|false $brandMapping, int $marketplaceId): array
    {
        $sku = $this->wooCommerceSku($product, $marketplaceId);
        $sale = round((float) ($price['sale_price'] ?? $product['base_price'] ?? 0), 2);
        $list = round((float) ($price['list_price'] ?? $sale), 2);
        $discounted = $sale > 0 && $list > $sale;
        $quantity = max(0, (int) ($product['stock_qty'] ?? 0));
        $name = (string) ($product['name'] ?? '');

        $barcode = trim((string) ($product['barcode'] ?? ''));
        $gtin = preg_match('/^\d{8,14}$/', $barcode) === 1 ? $barcode : '';

        $images = [];
        foreach ($this->buildImageList($product) as $image) {
            $images[] = ['src' => (string) $image['url']];
        }

        $categoryId = is_array($catMapping) ? trim((string) ($catMapping['marketplace_category_id'] ?? '')) : '';
        $brandId = is_array($brandMapping) ? trim((string) ($brandMapping['marketplace_brand_id'] ?? '')) : '';

        $item = [
            'sku'            => $sku,
            'name'           => $name,
            'regular_price'  => self::wooDecimal($discounted ? $list : $sale),
            // İndirim yoksa boş metin: WooCommerce'te kalmış eski indirimi temizler
            'sale_price'     => $discounted ? self::wooDecimal($sale) : '',
            'manage_stock'   => true,
            'stock_quantity' => $quantity,
            'images'         => $images,
            // Genel anahtarlar. WooCommerceService barkodu yalnız GTIN için okur (ürünü SKU ile
            // bulur) → yalnız geçerli GTIN verilir; yer tutucu/stok kodu kopyası gönderilmez.
            'barcode'          => $gtin,
            'stockCode'        => $sku,
            'title'            => $name,
            'salePrice'        => $sale,
            'listPrice'        => $list,
            'quantity'         => $quantity,
            'gtin'             => $gtin,
            'brand'            => $this->wooCommerceBrandName($product, $brandMapping),
            'brandId'          => $brandId,
            'categoryId'       => $categoryId,
            'categoryName'     => is_array($catMapping) ? (string) ($catMapping['marketplace_category_name'] ?? '') : '',
            'local_product_id' => (int) ($product['id'] ?? 0),
        ];

        $description = trim((string) ($product['description'] ?? ''));
        if ($description !== '') {
            $item['description'] = $description;
        }
        $shortDescription = trim((string) ($product['short_description'] ?? ''));
        if ($shortDescription !== '') {
            $item['short_description'] = $shortDescription;
        }
        if ($gtin !== '') {
            $item['global_unique_id'] = $gtin;
        }
        if (ctype_digit($categoryId) && (int) $categoryId > 0) {
            $item['categories'] = [['id' => (int) $categoryId]];
        }
        if (ctype_digit($brandId) && (int) $brandId > 0) {
            $item['brands'] = [['id' => (int) $brandId]];
        }

        $weight = (float) ($product['weight'] ?? 0);
        if ($weight > 0) {
            $item['weight'] = rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.');
        }
        $dimensions = $this->wooDimensions($product);
        if ($dimensions !== null) {
            $item['dimensions'] = $dimensions;
        }

        return $item;
    }

    /** WooCommerce fiyat metni (ondalık ayırıcı '.') */
    private static function wooDecimal(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * Marka adı: WooCommerce marka eşleştirmesi → ürünün marka adı.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed>|false $brandMapping
     */
    private function wooCommerceBrandName(array $product, array|false $brandMapping): string
    {
        $mapped = is_array($brandMapping) ? trim((string) ($brandMapping['marketplace_brand_name'] ?? '')) : '';
        if ($mapped !== '') {
            return $mapped;
        }

        $joined = trim((string) ($product['brand_name'] ?? ''));
        if ($joined !== '') {
            return $joined;
        }

        $brandId = (int) ($product['brand_id'] ?? 0);
        $storeId = $this->storeIdFor($product);
        if ($brandId <= 0 || $storeId <= 0) {
            return '';
        }

        $row = $this->db->fetchOne('SELECT name FROM brands WHERE id = ? AND store_id = ? LIMIT 1', [$brandId, $storeId]);

        return $row !== false ? trim((string) ($row['name'] ?? '')) : '';
    }

    /**
     * products.dimensions_json → WooCommerce dimensions (yalnız en az bir ölçü varsa).
     *
     * @param array<string, mixed> $product
     * @return array{length: string, width: string, height: string}|null
     */
    private function wooDimensions(array $product): ?array
    {
        $decoded = json_decode((string) ($product['dimensions_json'] ?? ''), true);
        if (!is_array($decoded)) {
            return null;
        }

        $aliases = [
            'length' => ['length', 'uzunluk', 'boy'],
            'width'  => ['width', 'genislik', 'en'],
            'height' => ['height', 'yukseklik'],
        ];
        $dimensions = [];
        foreach ($aliases as $key => $names) {
            $value = 0.0;
            foreach ($names as $alias) {
                if (isset($decoded[$alias]) && is_numeric($decoded[$alias]) && (float) $decoded[$alias] > 0) {
                    $value = (float) $decoded[$alias];
                    break;
                }
            }
            $dimensions[$key] = $value > 0 ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : '';
        }

        return array_filter($dimensions, static fn(string $v): bool => $v !== '') === [] ? null : $dimensions;
    }

    /**
     * Basit ürünleri WooCommerce'e gönder ve senkron sonucu yerel kayda işle
     * (WooCommerceService::createProducts → {success, results[{sku, status, marketplace_product_id, error}], errors[]}).
     *
     * @param array<int, array<string, mixed>> $validProductMap productId => products satırı
     * @param array<int, array<string, mixed>> $publishMeta productId => {item, ...}
     * @param array<int, array<string, mixed>> $errors önceki (doğrulama) hataları
     * @return array{success: int, failed: int, skipped: int, processing: int, errors: array, batch_ids: array}
     */
    private function publishWooCommerceItems(
        object $marketplaceService,
        int $marketplaceId,
        array $validProductMap,
        array $publishMeta,
        int $skipped,
        array $errors
    ): array {
        $items = [];
        foreach (array_keys($validProductMap) as $productId) {
            $items[] = $publishMeta[$productId]['item'];
        }

        $api = method_exists($marketplaceService, 'createProducts')
            ? $this->callWooCommerce(static fn(): mixed => $marketplaceService->createProducts($items))
            : ['success' => false, 'results' => [], 'errors' => [['error' => 'WooCommerce servisi ürün göndermeyi desteklemiyor']]];

        $index = $this->wooCommerceResultIndex($api);
        $apiError = $this->wooCommerceApiError($api);
        $success = 0;
        $failed = 0;

        foreach ($validProductMap as $productId => $product) {
            $item = $publishMeta[$productId]['item'];
            $result = $this->wooCommerceResultFor($index, (string) ($item['sku'] ?? ''));

            if ($result !== null && $this->wooCommerceResultSucceeded($result)) {
                try {
                    $this->db->transaction(function () use ($productId, $marketplaceId, $item, $result): void {
                        $this->mpProductModel->upsertByProductAndMarketplace(
                            $productId,
                            $marketplaceId,
                            $this->wooCommercePublishState($item, $result)
                        );
                    });
                    $success++;
                    $warning = $this->wooCommerceResultWarning($result);
                    if ($warning !== '') {
                        $errors[] = ['product_id' => $productId, 'error' => 'Uyarı (' . ($item['sku'] ?? '') . '): ' . $warning];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'product_id' => $productId,
                        'error'      => 'API başarılı ama DB kayıt hatası: ' . $e->getMessage()
                            . " — Ürün WooCommerce'e gönderildi, lokal durum güncellenemedi. Tekrar gönderim güvenlidir (SKU ile güncellenir).",
                    ];
                    $this->logger->error("WooCommerce publish DB tutarsızlığı (ürün #{$productId}): " . $e->getMessage(), 'ProductPublish');
                }
                continue;
            }

            $error = $this->wooCommerceItemError($result, $apiError);
            $failed++;
            $errors[] = ['product_id' => $productId, 'error' => $error];
            $this->recordWooCommerceFailure($productId, $marketplaceId, $error);
        }

        return [
            'success'    => $success,
            'failed'     => $failed,
            'skipped'    => $skipped,
            'processing' => 0,
            'errors'     => $errors,
            'batch_ids'  => [],
        ];
    }

    /**
     * WooCommerceService çağrısı — istisna ya da geçersiz yanıt hata sonucuna çevrilir.
     *
     * @return array<string, mixed>
     */
    public function callWooCommerce(callable $call): array
    {
        try {
            $result = $call();
        } catch (\Throwable $e) {
            $this->logger->error('WooCommerce gönderim hatası: ' . $e->getMessage(), 'ProductPublish');

            return ['success' => false, 'results' => [], 'errors' => [['error' => $e->getMessage()]]];
        }

        return is_array($result)
            ? $result
            : ['success' => false, 'results' => [], 'errors' => [['error' => 'WooCommerce servisinden geçersiz yanıt']]];
    }

    /**
     * results[] → SKU anahtarlı dizin (birebir + büyük/küçük harf duyarsız). Aynı SKU'da başarılı satır önceliklidir.
     *
     * @param array<string, mixed> $api
     * @return array<string, array<string, mixed>>
     */
    public function wooCommerceResultIndex(array $api): array
    {
        $index = [];
        foreach ((array) ($api['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            foreach ([$sku, 'ci:' . mb_strtolower($sku)] as $key) {
                if (!isset($index[$key]) || !$this->wooCommerceResultSucceeded($index[$key])) {
                    $index[$key] = $row;
                }
            }
        }

        return $index;
    }

    /**
     * @param array<string, array<string, mixed>> $index
     * @return array<string, mixed>|null
     */
    public function wooCommerceResultFor(array $index, string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        return $index[$sku] ?? $index['ci:' . mb_strtolower($sku)] ?? null;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function wooCommerceResultSucceeded(array $result): bool
    {
        return strtoupper(trim((string) ($result['status'] ?? ''))) === 'SUCCESS';
    }

    /**
     * Başarılı satırdaki uyarı (ör. GTIN başka üründe kayıtlı → GTIN'siz gönderildi); yoksa boş.
     *
     * @param array<string, mixed> $result
     */
    public function wooCommerceResultWarning(array $result): string
    {
        $warning = $result['warning'] ?? null;

        return is_scalar($warning) ? trim((string) $warning) : '';
    }

    /**
     * Yanıttaki ilk genel hata metni (errors[] metin ya da {error|message}).
     *
     * @param array<string, mixed> $api
     */
    public function wooCommerceApiError(array $api): string
    {
        foreach ((array) ($api['errors'] ?? []) as $error) {
            $text = is_array($error)
                ? (string) ($error['error'] ?? $error['message'] ?? '')
                : (is_scalar($error) ? (string) $error : '');
            if (trim($text) !== '') {
                return trim($text);
            }
        }

        return '';
    }

    /**
     * Ürün satırının hata metni: satır hatası → genel hata → sonuç yok.
     *
     * @param array<string, mixed>|null $result
     */
    public function wooCommerceItemError(?array $result, string $apiError): string
    {
        $error = $result !== null ? trim((string) ($result['error'] ?? '')) : '';
        if ($error !== '') {
            return $error;
        }
        if ($apiError !== '') {
            return $apiError;
        }

        return $result !== null
            ? 'WooCommerce ürünü kabul etmedi (ayrıntı bildirilmedi)'
            : 'WooCommerce bu SKU için sonuç döndürmedi';
    }

    /**
     * Başarılı gönderimin yerel kaydı: listelendi, WooCommerce ürün/varyasyon kimliği, SKU (marketplace_barcode
     * da SKU — içe aktarmadaki kural; stok/fiyat senkronu bu alanı anahtar alır), son gönderilen fiyat/stok.
     * Boş kategori/marka/görsel mevcut değeri ezmez.
     *
     * @param array<string, mixed> $item buildWooCommerceItem çıktısı
     * @param array<string, mixed> $result createProducts/createVariantProducts sonuç satırı
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function wooCommercePublishState(array $item, array $result, array $overrides = []): array
    {
        $sku = (string) ($item['sku'] ?? '');
        $state = [
            'is_listed'       => 1,
            'sync_error'      => null,
            'last_synced_at'  => date('Y-m-d H:i:s'),
            'marketplace_sku' => $sku,
            'marketplace_barcode' => $sku,
            'product_name'    => (string) ($item['name'] ?? ''),
            'last_price_sent' => (float) ($item['salePrice'] ?? 0),
            'last_stock_sent' => (int) ($item['quantity'] ?? 0),
        ];

        $wooId = trim((string) ($result['marketplace_product_id'] ?? ''));
        if ($wooId !== '' && $wooId !== '0') {
            $state['marketplace_product_id'] = $wooId;
        }

        $optional = [
            'marketplace_category_id'   => (string) ($item['categoryId'] ?? ''),
            'marketplace_category_name' => (string) ($item['categoryName'] ?? ''),
            'marketplace_brand_id'      => (string) ($item['brandId'] ?? ''),
            'marketplace_brand_name'    => (string) ($item['brand'] ?? ''),
            'main_image'                => $this->wooFirstImage($item),
        ];
        foreach ($optional as $column => $value) {
            if (trim($value) !== '') {
                $state[$column] = $value;
            }
        }

        return array_merge($state, $overrides);
    }

    /**
     * Başarısız gönderim: hata rozeti yazılır; mevcut listelenme durumu ve kimlikler korunur.
     */
    public function recordWooCommerceFailure(int $productId, int $marketplaceId, string $error): void
    {
        try {
            $this->mpProductModel->upsertByProductAndMarketplace($productId, $marketplaceId, [
                'sync_error'     => mb_substr($error, 0, 1000),
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning("WooCommerce hata rozeti yazılamadı (ürün #{$productId}): " . $e->getMessage(), 'ProductPublish');
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function wooFirstImage(array $item): string
    {
        $images = is_array($item['images'] ?? null) ? $item['images'] : [];
        $first = reset($images);
        if (is_array($first)) {
            return (string) ($first['src'] ?? $first['url'] ?? '');
        }

        $single = is_array($item['image'] ?? null) ? $item['image'] : [];

        return (string) ($single['src'] ?? '');
    }

    /**
     * Marka eşleştirmesi pazaryeri marka ID'si yerine marka ADI içeriyorsa
     * (import sırasında ada göre oluşan eski eşleştirmeler) marketplace_brands
     * katalogundan gerçek ID'ye çevir. Trendyol SAYISAL marka ID'si zorunlar;
     * metin gönderilirse (int) cast 0 olur ve batch her seferinde reddedilir.
     *
     * @param array<string, mixed>|false $brandMapping
     * @return array<string, mixed>|false
     */
    private function resolveBrandMappingId(array|false $brandMapping, int $marketplaceId, string $slug): array|false
    {
        if ($brandMapping === false) {
            return false;
        }

        $value = trim((string) ($brandMapping['marketplace_brand_id'] ?? ''));
        if ($value === '') {
            return $brandMapping;
        }

        // WooCommerce: marka kimliği sayısal terim ID'sidir (brands[{id}]). Eski marka senkronu marka ADINI
        // kimlik olarak kaydetmiş olabilir → aynı adlı sayısal katalog kaydına çevir (yoksa marka gönderilmez).
        if ($slug === self::WOO_SLUG && !ctype_digit($value)) {
            try {
                $mpBrandModel = $this->container->get(\App\Models\MarketplaceBrand::class);
                foreach ([$value, trim((string) ($brandMapping['marketplace_brand_name'] ?? ''))] as $name) {
                    $numericId = $name !== '' ? $mpBrandModel->findNumericIdByName($name, $marketplaceId) : null;
                    if ($numericId !== null) {
                        $brandMapping['marketplace_brand_id'] = $numericId;
                        return $brandMapping;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('WooCommerce marka ID çözümleme hatası: ' . $e->getMessage(), 'ProductPublish');
            }

            $this->logger->warning(
                "WooCommerce marka eşleştirmesi sayısal değil ve katalogda bulunamadı: '{$value}' — Markalar › WooCommerce "
                . 'markalarını yeniden çekip eşleştirin; marka gönderilmeyecek',
                'ProductPublish'
            );

            return $brandMapping;
        }

        // Trendyol: sayısal marka ID zorunlu — metinse katalogdan adıyla çöz
        if ($slug === 'trendyol' && !ctype_digit($value)) {
            try {
                $mpBrandModel = $this->container->get(\App\Models\MarketplaceBrand::class);
                $found = $mpBrandModel->findExactMatch($value, $marketplaceId);
                if ($found !== false && ctype_digit((string) $found['mp_brand_id'])) {
                    $brandMapping['marketplace_brand_id'] = (string) $found['mp_brand_id'];
                    return $brandMapping;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Marka ID çözümleme hatası: ' . $e->getMessage(), 'ProductPublish');
            }

            $this->logger->warning(
                "Trendyol marka eşleştirmesi sayısal değil ve katalogda bulunamadı: '{$value}' — batch reddedilebilir",
                'ProductPublish'
            );
        }

        // Pazarama: marka ID System.Guid zorunlu — GUID değilse (md5/ad) gerçek
        // katalogdan adıyla arayıp GUID'e çevir
        $isGuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
        if ($slug === 'pazarama' && !$isGuid) {
            try {
                $pazarama = $this->container->get(\App\Services\Marketplace\PazaramaService::class);
                // Eşleştirme değeri çoğu kurulumda marka ADI veya md5'idir;
                // md5 ise sistem marka adına düş
                $searchName = str_contains($value, ' ') || !ctype_xdigit($value)
                    ? $value
                    : (string) ($brandMapping['brand_name'] ?? $value);
                $guid = $pazarama->findBrandGuidByName($searchName);
                if ($guid !== null) {
                    $brandMapping['marketplace_brand_id'] = $guid;
                    return $brandMapping;
                }
                $this->logger->warning(
                    "Pazarama marka GUID'i bulunamadı: '{$searchName}' — Pazarama'da marka onayı gerekebilir",
                    'ProductPublish'
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Pazarama marka GUID çözümleme hatası: ' . $e->getMessage(), 'ProductPublish');
            }
        }

        return $brandMapping;
    }

    /**
     * Tek batch'i işle
     *
     * @param array<int> $productIds
     * @param array<string, mixed> $marketplace
     * @param object $marketplaceService
     * @return array{success: int, failed: int, skipped: int, errors: array}
     */
    private function publishBatch(array $productIds, int $marketplaceId, array $marketplace, object $marketplaceService): array
    {
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $processing = 0;   // pazaryeri onayı bekleyen — başarılı SAYILMAZ
        $listelemeKuyrugu = []; // productId => item (fiyat/stok TOPLU gönderilir)
        $errors = [];
        $slug = (string) ($marketplace['slug'] ?? '');
        $validItems = [];
        $validProductMap = []; // productId => product data
        $publishMeta = []; // productId => item + mapping data

        // 1. Validasyon — API çağrısından ÖNCE
        foreach ($productIds as $productId) {
            $validation = $this->validateForPublish($productId, $marketplaceId);

            if (!$validation['valid']) {
                $skipped++;
                $validationError = 'Validasyon hatası: ' . implode(', ', $validation['errors']);
                $errors[] = [
                    'product_id' => $productId,
                    'error'      => $validationError,
                ];

                // Hata ürün-pazaryeri kaydına da yazılsın — liste rozetinde görünsün.
                // İstisna: ürünün KENDİ ilanı zaten yayında ve tek engel mükerrer koruması ise
                // sağlıklı ilan hata rozetiyle işaretlenmez (yeniden oluşturma reddi bilgisi yanıtta).
                try {
                    if (!$this->isOwnListingDuplicateOnly($validation['errors'], $productId, $marketplaceId)) {
                        $this->mpProductModel->upsertByProductAndMarketplace($productId, $marketplaceId, [
                            'sync_error'     => mb_substr($validationError, 0, 500),
                            'last_synced_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                } catch (\Throwable $badgeEx) {
                    $this->logger->warning('Validasyon rozeti yazılamadı: ' . $badgeEx->getMessage(), 'ProductPublish');
                }
                continue;
            }

            $product = $this->productModel->find($productId);
            if ($product === false) {
                $skipped++;
                continue;
            }

            $price = $this->priceEngine->calculatePrice($product, $marketplaceId);
            $catMapping = $this->catMappingModel->findMapping((int) $product['category_id'], $marketplaceId);
            $brandMapping = $this->brandMappingModel->findMapping((int) $product['brand_id'], $marketplaceId);
            $brandMapping = $this->resolveBrandMappingId($brandMapping, $marketplaceId, (string) $marketplace['slug']);

            // Pazaryerine özel ürün formatı
            $item = $this->buildMarketplaceItem($product, $price, $catMapping, $brandMapping, $marketplace['slug'], $marketplace);

            $validItems[] = $item;
            $validProductMap[$productId] = $product;
            $publishMeta[$productId] = [
                'item' => $item,
                'price' => $price,
                'category_mapping' => $catMapping,
                'brand_mapping' => $brandMapping,
                'slug' => $slug,
            ];
        }

        if (empty($validItems)) {
            return ['success' => 0, 'failed' => $failed, 'skipped' => $skipped, 'errors' => $errors, 'batch_ids' => []];
        }

        // WooCommerce senkron yanıt verir (takip kimliği yok): sonuç hemen işlenir
        if ($slug === self::WOO_SLUG) {
            return $this->publishWooCommerceItems($marketplaceService, $marketplaceId, $validProductMap, $publishMeta, $skipped, $errors);
        }

        // 1.5 BEKLEYEN BATCH ÇÖZÜMÜ — daha önce gönderilmiş ve sonucu bekleyen
        //     ürün için YENİ create atma. (Trendyol art arda create isteklerini
        //     satıcı bazında "recurring" hatasıyla engeller; kullanıcı tekrar
        //     Yayınla'ya bastığında önce önceki batch'in sonucuna bakılır.)
        if (method_exists($marketplaceService, 'getBatchStatus')) {
            // Aynı batch'i ürün başına sormak 50 üründe 50 isteğe ve her birinde
            // 4 denemeye kadar beklemeye yol açıyordu — batch başına BİR kez sor.
            $batchOnbellek = [];

            foreach (array_keys($validProductMap) as $pendingProductId) {
                $mpRow = $this->mpProductModel->findByProductAndMarketplace($pendingProductId, $marketplaceId);
                $syncError = $mpRow !== false ? (string) ($mpRow['sync_error'] ?? '') : '';
                if (!str_starts_with($syncError, 'Batch kontrol bekliyor: ')) {
                    continue;
                }

                $pendingBatchId = trim(substr($syncError, strlen('Batch kontrol bekliyor: ')));
                if ($pendingBatchId === '') {
                    continue;
                }

                $batchOnbellek[$pendingBatchId] ??= $this->checkBatchStatus($marketplaceService, $pendingBatchId);
                $statuses = $batchOnbellek[$pendingBatchId];

                // Anahtar barkod DEĞİL satıcı stok kodu olabilir (Hepsiburada
                // merchantSku döndürür); ikisi de denenmezse ürün sonsuza dek
                // "Batch kontrol bekliyor" durumunda kalırdı.
                $batchStatus = $this->batchStatusForProduct(
                    ['batch_status_by_barcode' => $statuses],
                    $validProductMap[$pendingProductId]
                );

                if ($batchStatus !== null && ($batchStatus['status'] ?? '') === 'SUCCESS') {
                    // Önceki gönderim başarıyla tamamlanmış — listelendi işaretle, create'e gerek yok
                    $this->mpProductModel->update((int) $mpRow['id'], $this->batchSuccessState(
                        $slug,
                        $validProductMap[$pendingProductId],
                        $batchStatus
                    ));
                    $success++;
                    unset($validProductMap[$pendingProductId], $publishMeta[$pendingProductId]);
                } elseif ($batchStatus !== null && ($batchStatus['status'] ?? '') === 'FAILED') {
                    // Önceki batch reddedilmiş — yeniden gönderime (create) devam et,
                    // eski hata mesajı yeni denemenin sonucuna göre güncellenecek
                    continue;
                } else {
                    // Hâlâ işleniyor — bu turda tekrar gönderme, kullanıcıyı bilgilendir
                    $skipped++;
                    $errors[] = [
                        'product_id' => $pendingProductId,
                        'error'      => "Önceki gönderim (batch {$pendingBatchId}) pazaryerinde hâlâ işleniyor — "
                            . 'tekrar göndermeye gerek yok, sonuç birkaç dakika içinde otomatik netleşir.',
                    ];
                    unset($validProductMap[$pendingProductId], $publishMeta[$pendingProductId]);
                }
            }

            // Kalan ürünlerle gönderi listesini yeniden kur
            $validItems = array_values(array_map(
                fn(int $pid) => $publishMeta[$pid]['item'],
                array_keys($validProductMap)
            ));

            if (empty($validItems)) {
                return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'processing' => $processing, 'errors' => $errors, 'batch_ids' => []];
            }
        }

        // 2. API çağrısı — tek çağrı, retry AbstractMarketplace'in sorumluluğunda
        $apiResult = $this->callCreateProducts($marketplaceService, $validItems);

        // Ürün → gönderim kimliği (çok parçalı yüklemede her parçanın kendi trackingId'si vardır).
        // validItems, validProductMap ile AYNI sırada kurulur; sıra numarası parçayı belirler.
        $productBatchIds = [];
        foreach (array_keys($validProductMap) as $itemIndex => $mappedProductId) {
            $productBatchIds[$mappedProductId] = $this->batchIdForItem($apiResult, $itemIndex);
        }

        // 3. Sonuçları kaydet — her ürün kendi transaction'ında
        //    API başarılı + DB başarısız senaryosu: hata loglanır, partial success olarak raporlanır
        //    Retry güvenli: upsert idempotent + Trendyol barcode bazlı create-or-update
        if ($apiResult['success']) {
            foreach ($validProductMap as $productId => $product) {
                try {
                    $meta = $publishMeta[$productId] ?? [];
                    $batchStatus = $this->batchStatusForProduct($apiResult, $product);
                    if ($batchStatus !== null && ($batchStatus['status'] ?? '') === 'FAILED') {
                        $failed++;
                        $errorText = (string) ($batchStatus['message'] ?? 'Batch reddedildi');
                        $errors[] = ['product_id' => $productId, 'error' => $errorText];
                        $meta['batch_failure'] = $errorText;
                        $this->db->transaction(function () use ($productId, $marketplaceId, $product, $meta): void {
                            $this->mpProductModel->upsertByProductAndMarketplace(
                                $productId,
                                $marketplaceId,
                                $this->buildMarketplaceProductState($product, $meta)
                            );
                        });
                        continue;
                    }

                    $batchRequestId = $productBatchIds[$productId] ?? '';
                    $onaylandi = ($batchStatus['status'] ?? '') === 'SUCCESS';

                    if ($batchRequestId !== '' && !$onaylandi) {
                        $meta['pending_batch_id'] = $batchRequestId;
                    }
                    if ($onaylandi) {
                        $meta['hb_sku'] = (string) ($batchStatus['hb_sku'] ?? '');
                    }

                    $this->db->transaction(function () use ($productId, $marketplaceId, $product, $meta): void {
                        $this->mpProductModel->upsertByProductAndMarketplace(
                            $productId,
                            $marketplaceId,
                            $this->buildMarketplaceProductState($product, $meta)
                        );
                    });

                    // Katalog kaydı fiyatı BELİRLEMEZ (ör. Hepsiburada eski şeması: katalog
                    // ve listeleme ayrı). Fiyat/stok ayrıca gönderilir — listeleme uçları
                    // TOPLU çalışır. Import isteği fiyat/stoğu zaten taşıyorsa (HB resmi
                    // şeması) ATLANIR: ürün henüz eşleşmediği için listeleme yoktur.
                    if (($meta['item'] ?? []) !== [] && !$this->importCarriesPriceStock($slug)) {
                        $listelemeKuyrugu[$productId] = $meta['item'];
                    }

                    // Pazaryeri henüz onaylamadıysa BAŞARILI SAYILMAZ; sonuç
                    // netleşene kadar "işleniyor" olarak raporlanır.
                    if ($onaylandi) {
                        $success++;
                    } else {
                        $processing++;
                    }
                } catch (\Throwable $e) {
                    // API başarılı ama DB başarısız — ürün pazaryerinde listelendi ama lokal kayıt güncellenemedi
                    // Bu partial success olarak raporlanır
                    // Retry güvenli: upsertByProductAndMarketplace idempotent (composite unique key)
                    // Trendyol tarafında duplicate yok: aynı barcode ile tekrar gönderim güncelleme olarak işlenir
                    $failed++;
                    $dbError = 'API başarılı ama DB kayıt hatası: ' . $e->getMessage()
                             . ' — Ürün pazaryerinde listelendi, lokal durum güncellenemedi. Tekrar gönderim güvenlidir.';
                    $errors[] = ['product_id' => $productId, 'error' => $dbError];

                    $this->logger->error(
                        "Publish DB tutarsızlığı (ürün #{$productId}): API başarılı, DB başarısız: " . $e->getMessage(),
                        'ProductPublish'
                    );

                    // sync_error olarak da kaydetmeyi dene (bu da başarısız olabilir ama en azından dene)
                    try {
                        $mpExisting = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
                        if ($mpExisting !== false) {
                            $this->mpProductModel->setSyncError((int) $mpExisting['id'], 'DB güncelleme hatası — pazaryerinde listelendi');
                        }
                    } catch (\Throwable $innerEx) {
                        // DB tamamen erişilemez — sadece logla
                        $this->logger->error("sync_error yazılamadı: " . $innerEx->getMessage(), 'ProductPublish');
                    }
                }
            }
        } else {
            // API başarısız — ürünleri hatalı işaretle. Çok parçalı yüklemede pazaryerinin
            // KABUL ETTİĞİ parçalardaki ürünler gönderilmiştir: onlar sonucu beklenen
            // (Batch kontrol bekliyor) olarak kaydedilir ki tekrar Yayınla'da mükerrer açılmasın.
            $apiError = !empty($apiResult['errors']) ? $apiResult['errors'][0]['error'] ?? 'API hatası' : 'API hatası';
            foreach ($validProductMap as $productId => $product) {
                $acceptedBatchId = $productBatchIds[$productId] ?? '';
                if ($acceptedBatchId !== '') {
                    $meta = ($publishMeta[$productId] ?? []) + ['pending_batch_id' => $acceptedBatchId];
                    try {
                        $this->db->transaction(function () use ($productId, $marketplaceId, $product, $meta): void {
                            $this->mpProductModel->upsertByProductAndMarketplace(
                                $productId,
                                $marketplaceId,
                                $this->buildMarketplaceProductState($product, $meta)
                            );
                        });
                        $processing++;
                        continue;
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            "Kabul edilen parça kaydı yazılamadı (ürün #{$productId}, batch {$acceptedBatchId}): " . $e->getMessage(),
                            'ProductPublish'
                        );
                    }
                }

                $failed++;
                $errors[] = ['product_id' => $productId, 'error' => $apiError];

                // sync_error kaydet
                $mpExisting = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
                if ($mpExisting !== false) {
                    $this->mpProductModel->setSyncError((int) $mpExisting['id'], $apiError);
                }
            }
        }

        // Fiyat/stok TEK seferde gönderilir (listeleme uçları toplu çalışır)
        $listelemeHatasi = $this->pushListingPriceStock(
            $marketplaceService,
            $slug,
            array_values($listelemeKuyrugu)
        );

        if ($listelemeHatasi !== null) {
            // Ürünler katalogda oluştu ama fiyat gönderilemedi — pazaryeri
            // listelemeyi askıya alır. Bu SESSİZ geçilmemeli.
            $errors[] = ['product_id' => 0, 'error' => $listelemeHatasi];
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'processing' => $processing,
            'errors' => $errors,
            'batch_ids' => array_values(array_unique(array_filter(array_map(
                'strval',
                (array) ($apiResult['batchRequestIds'] ?? [$apiResult['batchRequestId'] ?? ''])
            )))),
        ];
    }

    /**
     * API çağrısı — tek çağrı, retry AbstractMarketplace'in sorumluluğunda
     *
     * RETRY SAHİPLİĞİ: AbstractMarketplace.httpRequest() zaten 429/5xx için
     * exponential backoff retry uygular (maxRetries=3, retryBaseDelayMs=1000).
     * ProductPublishService ek retry YAPMAZ — duplicate retry loop önlenir.
     *
     * TrendyolService.createProducts() kendi try/catch'i ile sarılıdır ve
     * AbstractMarketplace'in fırlattığı exception'ları yakalar. Bu yüzden
     * burada sadece result kontrolü yapılır.
     *
     * @return array{success: bool, errors: array, batchRequestId?: ?string, batch_status_by_barcode?: array<string, array<string, string>>}
     */
    /**
     * Katalog kaydından SONRA fiyat ve stok listeleme uçlarına gönderilir.
     *
     * Hepsiburada'da katalog ve listeleme AYRI sistemdir:
     *   /products/import            → ürünü oluşturur (fiyat BELİRLEMEZ)
     *   /listings/.../price-uploads → fiyatı belirler
     *   /listings/.../stock-uploads → stoğu belirler
     * Bu adım atlanınca ürün 0 fiyatla açılır ve pazaryeri listelemeyi askıya
     * alır — müşteride tam olarak bu yaşandı.
     *
     * @param array<int, array<string, mixed>> $items
     * @return string|null Hata metni; sorun yoksa null
     */
    private function pushListingPriceStock(object $marketplaceService, string $slug, array $items): ?string
    {
        if (!in_array($slug, self::LISTING_PRICE_REQUIRED, true) || $items === []) {
            return null;
        }

        // Fiyatı olmayan kalem gönderilmez — 0 fiyat listelemeyi askıya alır
        $gonderilecek = array_values(array_filter(
            $items,
            static fn(array $i): bool => (float) ($i['salePrice'] ?? $i['price'] ?? 0) > 0
        ));

        if ($gonderilecek === []) {
            return 'Fiyat 0 — pazaryeri listelemeyi askıya alır, gönderim yapılmadı';
        }

        try {
            $fiyatSonuc = $marketplaceService->updatePrice($gonderilecek);
            if (($fiyatSonuc['failed'] ?? 0) > 0) {
                $ilk = $fiyatSonuc['errors'][0]['error'] ?? 'bilinmeyen hata';
                return 'Fiyat gönderilemedi: ' . $ilk;
            }

            $marketplaceService->updateStock($gonderilecek);
        } catch (\Throwable $e) {
            return 'Fiyat/stok gönderimi başarısız: ' . $e->getMessage();
        }

        return null;
    }

    private function callCreateProducts(object $marketplaceService, array $items): array
    {
        if (!method_exists($marketplaceService, 'createProducts')) {
            return ['success' => false, 'errors' => [['error' => 'Pazaryeri servisi createProducts desteklemiyor']]];
        }

        try {
            $result = $marketplaceService->createProducts($items);

            // Hepsiburada her 100'lük dosya için ayrı trackingId döner (trackingIds[]);
            // diğer servisler tek kimlik (batchRequestId/batchId/taskId/trackingId).
            $trackingIds = array_values(array_filter(
                array_map(static fn($id): string => trim((string) $id), (array) ($result['trackingIds'] ?? [])),
                static fn(string $id): bool => $id !== ''
            ));

            if ($result['success'] ?? false) {
                $batchId = $result['batchRequestId']
                    ?? $result['batchId']
                    ?? $result['taskId']
                    ?? $result['trackingId']
                    ?? null;
                $batchId = $batchId !== null && trim((string) $batchId) !== '' ? trim((string) $batchId) : null;
                if ($trackingIds === [] && $batchId !== null) {
                    $trackingIds = [$batchId];
                }

                $statuses = [];
                foreach ($trackingIds as $trackingId) {
                    $statuses = array_merge($statuses, $this->checkBatchStatus($marketplaceService, $trackingId));
                }

                return [
                    'success' => true,
                    'errors' => [],
                    'batchRequestId' => $trackingIds[0] ?? null,
                    'batchRequestIds' => $trackingIds,
                    'item_batch_ids' => $this->mapItemsToBatchIds(count($items), $trackingIds),
                    'batch_status_by_barcode' => $statuses,
                ];
            }

            $apiErrors = $result['errors'] ?? [['error' => 'Bilinmeyen API hatası']];

            $this->logger->warning(
                'Publish API başarısız',
                'ProductPublish',
                ['errors' => $apiErrors, 'accepted_tracking_ids' => $trackingIds]
            );

            // Çok parçalı yüklemede ilk parçalar kabul edilmiş olabilir — yalnız o ürünler eşlenir
            return [
                'success' => false,
                'errors' => $apiErrors,
                'batchRequestId' => null,
                'batchRequestIds' => $trackingIds,
                'item_batch_ids' => $this->mapItemsToBatchIds(
                    min(count($items), count($trackingIds) * self::HB_IMPORT_CHUNK),
                    $trackingIds
                ),
            ];

        } catch (\Throwable $e) {
            $this->logger->error(
                'Publish exception: ' . $e->getMessage(),
                'ProductPublish'
            );

            return ['success' => false, 'errors' => [['error' => $e->getMessage()]], 'batchRequestId' => null];
        }
    }

    /**
     * Gönderilen kalemleri sıralarına göre gönderim kimliğine eşle. Çok parçalı yüklemede
     * (Hepsiburada: 100'lük dosyalar) i. kalem intdiv(i, 100). parçadadır; tek kimlikte hepsi aynıdır.
     *
     * @param array<int, string> $batchIds
     * @return array<int, string>
     */
    private function mapItemsToBatchIds(int $itemCount, array $batchIds): array
    {
        if ($batchIds === [] || $itemCount <= 0) {
            return [];
        }

        $lastIndex = count($batchIds) - 1;
        $map = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $map[$i] = $batchIds[min(intdiv($i, self::HB_IMPORT_CHUNK), $lastIndex)];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $apiResult
     */
    private function batchIdForItem(array $apiResult, int $itemIndex): string
    {
        $map = is_array($apiResult['item_batch_ids'] ?? null) ? $apiResult['item_batch_ids'] : [];

        return trim((string) ($map[$itemIndex] ?? ''));
    }

    /**
     * Marketplace servisini dinamik olarak çöz (Container üzerinden)
     */
    private function resolveMarketplaceService(string $slug): object
    {
        $serviceMap = [
            'trendyol' => \App\Services\Marketplace\TrendyolService::class,
            'n11'      => \App\Services\Marketplace\N11Service::class,
            'pazarama' => \App\Services\Marketplace\PazaramaService::class,
            'pttavm'   => \App\Services\Marketplace\PttavmService::class,
            'ciceksepeti' => \App\Services\Marketplace\CiceksepetiService::class,
            'hepsiburada' => \App\Services\Marketplace\HepsiburadaService::class,
            'idefix'      => \App\Services\Marketplace\IdefixService::class,
            'oziygo'      => \App\Services\Marketplace\OziygoService::class,
            // Amazon bu haritada YOKTU: AmazonService::createProducts() yazılmış olmasına
            // rağmen yayınlama akışı "Desteklenmeyen pazaryeri: amazon" ile duruyordu.
            'amazon'      => \App\Services\Marketplace\AmazonService::class,
            'woocommerce' => \App\Services\Marketplace\WooCommerceService::class,
        ];

        $serviceClass = $serviceMap[$slug] ?? null;

        if ($serviceClass === null) {
            throw new RuntimeException("Desteklenmeyen pazaryeri: {$slug}");
        }

        return $this->container->get($serviceClass);
    }

    /**
     * Pazaryerine gönderilecek ürün verisini oluştur
     *
     * @return array<string, mixed>
     */
    private function buildMarketplaceItem(
        array $product,
        array $price,
        array|false $catMapping,
        array|false $brandMapping,
        string $slug,
        array $marketplace = []
    ): array {
        $extraConfig = json_decode((string) ($marketplace['extra_config_json'] ?? '{}'), true) ?: [];

        // Açıklama boş string olabilir — "?? " yalnız NULL'u yakalar; pazaryerleri
        // (ör. Trendyol "Açıklama alanı boş olamaz") boş açıklamayı reddeder.
        $description = trim((string) ($product['description'] ?? ''));
        if ($description === '') {
            $description = (string) $product['name'];
        }

        // Trendyol formatı
        if ($slug === 'trendyol') {
            $images = $this->buildImageList($product);

            $attributes = [];
            $productAttrs = $this->pAttrValueModel->getByProduct((int) $product['id']);
            foreach ($productAttrs as $pa) {
                if (!empty($pa['custom_value'])) {
                    $attributes[] = [
                        'attributeId' => (int) $pa['attribute_id'],
                        'customAttributeValue' => $pa['custom_value'],
                    ];
                } elseif (!empty($pa['attribute_value_id'])) {
                    $attributes[] = [
                        'attributeId' => (int) $pa['attribute_id'],
                        'attributeValueId' => (int) $pa['attribute_value_id'],
                    ];
                }
            }

            // Trendyol ürün adını 100 karakterle sınırlar. XML/tedarikçi feed'lerinde
            // adlar 120-200 karakteri sık geçer; ham gönderilince kalem bazında
            // reddediliyor ve sebebi kullanıcıya hiç ulaşmıyordu.
            $baslik = trim((string) $product['name']);
            if (mb_strlen($baslik, 'UTF-8') > 100) {
                $baslik = rtrim(mb_substr($baslik, 0, 100, 'UTF-8'));
            }

            return [
                'barcode'    => $product['barcode'],
                'title'      => $baslik,
                'productMainId' => $product['sku'],
                'brandId'    => (int) ($brandMapping['marketplace_brand_id'] ?? 0),
                'categoryId' => (int) ($catMapping['marketplace_category_id'] ?? 0),
                'quantity'   => max(0, (int) $product['stock_qty']),
                'stockCode'  => $product['sku'],
                'dimensionalWeight' => (float) ($product['desi'] ?? 1),
                'description' => $description,
                'currencyType' => 'TRY',
                'listPrice'  => $price['list_price'],
                'salePrice'  => $price['sale_price'],
                'vatRate'    => (int) ($product['tax_rate'] ?? 20),
                // Trendyol ürün açmada zorunlu: satıcının anlaşmalı kargo firması.
                // Pazaryeri ayarlarındaki cargo_company_id kullanılır; girilmemişse
                // 17 = Trendyol Express (çoğu satıcıda varsayılan anlaşma).
                'cargoCompanyId' => (int) ($extraConfig['cargo_company_id'] ?? 17),
                'images'     => $images,
                'attributes' => $attributes,
            ];
        }

        // Hepsiburada formatı — HepsiburadaService::createProducts bunu resmi import şemasına
        // ({categoryId, merchant, attributes{merchantSku, Barcode, UrunAdi, …, <özellikId>}}) çevirir
        if ($slug === 'hepsiburada') {
            $images = $this->buildImageList($product);
            $hbResolution = $this->resolveHepsiburadaAttributes($product, $catMapping, $brandMapping);
            $info = $this->hepsiburadaProductInfo($product);
            // SKU kuralı TEK: import'taki merchantSku = marketplace_products.marketplace_sku
            $merchantSku = \App\Services\Marketplace\HepsiburadaService::normalizeMerchantSku((string) ($product['sku'] ?? ''));

            return [
                'categoryId'     => (int) ($catMapping['marketplace_category_id'] ?? 0),
                'merchantSku'    => $merchantSku,
                'stockCode'      => $merchantSku,
                'variantGroupId' => $merchantSku,
                // HepsiburadaSku HB'NİN kendi kodudur; uydurulmaz. Listeleme anahtarı
                // satıcı stok kodudur — aynı normalize değer.
                'sku'            => $merchantSku,
                'barcode'        => trim((string) ($product['barcode'] ?? '')),
                'productName'    => $product['name'],
                'title'          => $product['name'],
                // HepsiburadaService::cleanDescriptionHtml HB kurallarına göre temizler
                'description'    => $info['description_html'] !== '' ? $info['description_html'] : $description,
                'brand'          => $hbResolution['brand']['value'],
                'price'          => $price['sale_price'],
                'salePrice'      => $price['sale_price'],
                'listPrice'      => $price['list_price'],
                'quantity'       => max(0, (int) $product['stock_qty']),
                'vatRate'        => (float) ($product['tax_rate'] ?? 20),
                'desi'           => $info['desi'] ?? 0,
                'images'         => $images,
                // Kategoriye özel HB özellikleri (HB'nin geçerli değer listesinden)
                'hbAttributes'   => $hbResolution['values'],
                // Sistemdeki Trendyol-ID'li özellikler HB'ye gönderilmez
                'attributes'     => [],
            ];
        }

        // ÇiçekSepeti formatı (POST /Products şeması)
        if ($slug === 'ciceksepeti') {
            $images = $this->buildImageList($product);

            // NOT: Sistemdeki ürün özellikleri Trendyol ID'leriyle tutulur;
            // ÇiçekSepeti kendi attribute ID/valueId'lerini bekler. Yanlış ID
            // göndermek batch'i reddettirir — bu yüzden özellik gönderilmez.
            // ÇS'de zorunlu (varyant) özellikli kategoriler için özellikler
            // ÇiçekSepeti panelinden tamamlanmalıdır.
            return [
                'productName'         => $product['name'],
                'mainProductCode'     => $product['sku'],
                'stockCode'           => $product['sku'],
                'barcode'             => $product['barcode'] ?? '',
                'categoryId'          => (int) ($catMapping['marketplace_category_id'] ?? 0),
                'description'         => $description,
                'stockQuantity'       => max(0, (int) $product['stock_qty']),
                'salesPrice'          => $price['sale_price'],
                'listPrice'           => $price['list_price'],
                // deliveryType 2 = kargo; deliveryMessageType 5 = "yarın kargoda"
                // (kargolu kategorilerde ÇS yalnız kargo gönderim türünü kabul eder)
                'deliveryType'        => (int) ($extraConfig['delivery_type'] ?? 2),
                'deliveryMessageType' => (int) ($extraConfig['delivery_message_type'] ?? 5),
                'images'              => $images,
                'attributes'          => [],
            ];
        }

        // N11 formatı
        if ($slug === 'n11') {
            $images = $this->buildImageList($product, true);

            $attributes = [];
            $productAttrs = $this->pAttrValueModel->getByProduct((int) $product['id']);
            foreach ($productAttrs as $pa) {
                if (!empty($pa['custom_value'])) {
                    $attributes[] = [
                        'attributeId' => (int) $pa['attribute_id'],
                        'customAttributeValue' => $pa['custom_value'],
                    ];
                } elseif (!empty($pa['attribute_value_id'])) {
                    $attributes[] = [
                        'attributeId' => (int) $pa['attribute_id'],
                        'attributeValueId' => (int) $pa['attribute_value_id'],
                    ];
                }
            }

            return [
                'barcode'         => $product['barcode'] ?? '',
                'title'           => $product['name'],
                'productMainId'   => $product['sku'],
                'categoryId'      => (int) ($catMapping['marketplace_category_id'] ?? 0),
                'quantity'        => max(0, (int) $product['stock_qty']),
                'stockCode'       => $product['sku'],
                'description'     => $description,
                'currencyType'    => 'TL',
                'listPrice'       => $price['list_price'],
                'salePrice'       => $price['sale_price'],
                'vatRate'         => (int) ($product['tax_rate'] ?? 20),
                'images'          => $images,
                'attributes'      => $attributes,
                'preparingDay'    => 3,
                'shipmentTemplate' => '1',
            ];
        }

        // Pazarama formatı
        if ($slug === 'pazarama') {
            $images = $this->buildImageList($product, true);

            // Pazarama attributeId/attributeValueId olarak kendi GUID'lerini bekler.
            // Sistemdeki özellik ID'leri Trendyol kaynaklı sayısal ID'lerdir — bunları
            // göndermek MER_30 (System.Guid çevrilemedi) hatası üretir. Bu yüzden
            // yalnızca GUID formatındaki özellikler gönderilir.
            $attributes = [];
            $productAttrs = $this->pAttrValueModel->getByProduct((int) $product['id']);
            foreach ($productAttrs as $pa) {
                $attrId = (string) ($pa['attribute_id'] ?? '');
                $valueId = (string) ($pa['attribute_value_id'] ?? '');
                $guidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                if (preg_match($guidRe, $attrId) && preg_match($guidRe, $valueId)) {
                    $attributes[] = [
                        'attributeId'      => $attrId,
                        'attributeValueId' => $valueId,
                    ];
                }
            }

            return [
                'barcode'           => $product['barcode'] ?? '',
                'title'             => $product['name'],
                'productMainId'     => $product['sku'],
                // Pazarama marka/kategori ID'leri GUID'dir — int cast edilirse 0 olur
                'brandId'           => (string) ($brandMapping['marketplace_brand_id'] ?? ''),
                'categoryId'        => (string) ($catMapping['marketplace_category_id'] ?? ''),
                'quantity'          => max(0, (int) $product['stock_qty']),
                'stockCode'         => $product['sku'],
                'description'       => $description,
                'currencyType'      => 'TL',
                'listPrice'         => $price['list_price'],
                'salePrice'         => $price['sale_price'],
                'vatRate'           => (int) ($product['tax_rate'] ?? 20),
                'dimensionalWeight' => (float) ($product['desi'] ?? 1),
                'images'            => $images,
                'attributes'        => $attributes,
            ];
        }

        if ($slug === 'oziygo') {
            $images = $this->buildImageList($product, true);

            return [
                'sku'               => $product['sku'],
                'barcode'           => $product['barcode'],
                'name'              => $product['name'],
                'title'             => $product['name'],
                'description'       => $description,
                'short_description' => $product['short_description'] ?? '',
                'categoryId'        => (string) ($catMapping['marketplace_category_id'] ?? ''),
                'brandId'           => (string) ($brandMapping['marketplace_brand_id'] ?? ''),
                'quantity'          => max(0, (int) $product['stock_qty']),
                'stock'             => max(0, (int) $product['stock_qty']),
                'listPrice'         => $price['list_price'],
                'salePrice'         => $price['sale_price'],
                'price'             => $price['sale_price'],
                'vatRate'           => (int) ($product['tax_rate'] ?? 20),
                'images'            => $images,
            ];
        }

        // Amazon formatı (SP-API Listings Items — PUT /listings/2021-08-01/items/...)
        // ASIN biliniyorsa mevcut ürüne teklif, bilinmiyorsa barkodla eşleştirme denenir.
        if ($slug === 'amazon') {
            $images = $this->buildImageList($product);

            $eslesenTip = trim((string) ($catMapping['marketplace_category_id'] ?? ''));
            $amazonProductType = $eslesenTip !== ''
                ? $eslesenTip
                : (string) ($extraConfig['product_type'] ?? 'PRODUCT');

            // ASIN daha önce eşleşmişse marketplace_products'ta durur; yoksa barkodla denenir
            $asin = '';
            $mpId = (int) ($marketplace['id'] ?? 0);
            if ($mpId > 0) {
                $mevcut = $this->mpProductModel->findByProductAndMarketplace((int) $product['id'], $mpId);
                if ($mevcut !== false) {
                    $asin = (string) ($mevcut['marketplace_product_id'] ?? '');
                }
            }

            return [
                'sku'         => (string) $product['sku'],
                'title'       => (string) $product['name'],
                'barcode'     => (string) ($product['barcode'] ?? ''),
                'asin'        => $asin,
                'brand'       => (string) ($brandMapping['marketplace_brand_name'] ?? ''),
                'price'       => $price['sale_price'],
                'quantity'    => max(0, (int) $product['stock_qty']),
                'currency'    => 'TRY',
                // Amazon'da "kategori" = product type. validateForPublish() kategori
                // eşleştirmesini zorunlu tutuyordu ama burada kullanılmıyordu; artık
                // eşleştirilen değer gönderilir, yoksa mağaza ayarına düşülür.
                'productType' => $amazonProductType,
                'images'      => $images,
            ];
        }

        // PttAVM formatı (POST /api/v1/products/upsert)
        // KDV yalnız 0/1/10/20 olabilir; KDV dahil fiyat gönderildiği için oran > 0 olmalı.
        if ($slug === 'pttavm') {
            $images = $this->buildImageList($product);
            $kdv = (int) ($product['tax_rate'] ?? 20);
            if (!in_array($kdv, [0, 1, 10, 20], true)) {
                $kdv = 20;
            }
            if ($kdv === 0) {
                $kdv = 20; // KDV dahil fiyat gönderiliyor → oran 0 olamaz (PttAVM kuralı)
            }

            return [
                'categoryId'       => (int) ($catMapping['marketplace_category_id'] ?? 0),
                'barcode'          => (string) ($product['barcode'] ?? ''),
                'name'             => mb_substr((string) $product['name'], 0, 200),
                'productCode'      => (string) $product['sku'],
                'brand'            => (string) ($brandMapping['marketplace_brand_name'] ?? ''),
                'priceWithVat'     => $price['sale_price'],
                'vatRate'          => $kdv,
                'quantity'         => max(0, (int) $product['stock_qty']),
                'desi'             => (float) ($product['desi'] ?? 0),
                'shortDescription' => mb_substr(strip_tags((string) ($product['short_description'] ?? $description)), 0, 500),
                'longDescription'  => $description,
                'images'           => $images,
                'active'           => true,
                'cargoProfileId'   => (int) ($extraConfig['cargo_profile_id'] ?? 0),
            ];
        }

        // İdefix formatı (hızlı ürün ekleme — POST /pim/catalog/{vendorId}/fast-listing)
        // Alanlar dokümandaki adlarla birebir: barcode, title, vendorStockCode,
        // price, comparePrice, inventoryQuantity
        if ($slug === 'idefix') {
            return [
                'barcode'           => (string) ($product['barcode'] ?? ''),
                'title'             => (string) $product['name'],
                'vendorStockCode'   => (string) $product['sku'],
                'price'             => $price['sale_price'],
                'comparePrice'      => $price['list_price'] > 0 ? $price['list_price'] : $price['sale_price'],
                'inventoryQuantity' => max(0, (int) $product['stock_qty']),
            ];
        }

        // WooCommerce formatı (REST v3 alan adları + yayın akışının genel anahtarları)
        if ($slug === self::WOO_SLUG) {
            return $this->buildWooCommerceItem($product, $price, $catMapping, $brandMapping, (int) ($marketplace['id'] ?? 0));
        }

        // Gelecek pazaryerleri için generic format
        return [
            'sku'       => $product['sku'],
            'barcode'   => $product['barcode'],
            'name'      => $product['name'],
            'price'     => $price['sale_price'],
            'stock'     => (int) $product['stock_qty'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildMarketplaceProductState(array $product, array $meta): array
    {
        $item = $meta['item'] ?? [];
        $price = $meta['price'] ?? [];
        $catMapping = is_array($meta['category_mapping'] ?? null) ? $meta['category_mapping'] : [];
        $brandMapping = is_array($meta['brand_mapping'] ?? null) ? $meta['brand_mapping'] : [];
        $firstImage = $this->firstImageUrl($item);
        $pendingBatchId = trim((string) ($meta['pending_batch_id'] ?? ''));
        $batchFailure = trim((string) ($meta['batch_failure'] ?? ''));
        $hbSku = trim((string) ($meta['hb_sku'] ?? ''));

        return [
            'is_listed'                 => ($pendingBatchId === '' && $batchFailure === '') ? 1 : 0,
            'sync_error'                => $batchFailure !== ''
                ? 'Batch reddedildi: ' . $batchFailure
                : ($pendingBatchId === '' ? null : 'Batch kontrol bekliyor: ' . $pendingBatchId),
            'last_synced_at'            => date('Y-m-d H:i:s'),
            'marketplace_sku'           => (string) ($item['stockCode'] ?? $item['sku'] ?? $product['sku'] ?? ''),
            // Hepsiburada: ürün kimliği HB'nin kendi kodudur (HBC…) — onayda geldiyse yazılır,
            // bilinmiyorsa BOŞ kalır (stok kodu HepsiburadaSku diye uydurulmaz; listeleme
            // anahtarı marketplace_sku'dur). Diğer pazaryerleri: eski davranış.
            'marketplace_product_id'    => $this->isHepsiburadaSku($hbSku)
                ? $hbSku
                : (($meta['slug'] ?? '') === 'hepsiburada'
                    ? null
                    : (string) ($item['productMainId'] ?? $item['id'] ?? $product['sku'] ?? '')),
            'marketplace_barcode'       => (string) ($item['barcode'] ?? $product['barcode'] ?? ''),
            'marketplace_category_id'   => (string) ($catMapping['marketplace_category_id'] ?? $item['categoryId'] ?? ''),
            'marketplace_category_name' => (string) ($catMapping['marketplace_category_name'] ?? ''),
            'marketplace_brand_id'      => (string) ($brandMapping['marketplace_brand_id'] ?? $item['brandId'] ?? ''),
            'marketplace_brand_name'    => (string) ($brandMapping['marketplace_brand_name'] ?? ''),
            'product_name'              => (string) ($item['title'] ?? $product['name'] ?? ''),
            'main_image'                => $firstImage,
            'last_price_sent'           => (float) ($item['salePrice'] ?? $price['sale_price'] ?? $product['base_price'] ?? 0),
            'last_stock_sent'           => (int) ($item['quantity'] ?? $product['stock_qty'] ?? 0),
        ];
    }

    /**
     * Önceki gönderimi pazaryeri onayladı: yerel kayıt "listelendi" olur. HB'de satıcı stok
     * kodu import'taki normalize değerdir (listeleme anahtarı); onayda HBC… kodu geldiyse o da yazılır.
     *
     * @param array<string, mixed> $product
     * @param array<string, string> $batchStatus
     * @return array<string, mixed>
     */
    private function batchSuccessState(string $slug, array $product, array $batchStatus): array
    {
        $state = [
            'is_listed'      => 1,
            'sync_error'     => null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];

        if ($slug === 'hepsiburada') {
            $merchantSku = \App\Services\Marketplace\HepsiburadaService::normalizeMerchantSku((string) ($product['sku'] ?? ''));
            if ($merchantSku !== '') {
                $state['marketplace_sku'] = $merchantSku;
            }

            $hbSku = trim((string) ($batchStatus['hb_sku'] ?? ''));
            if ($this->isHepsiburadaSku($hbSku)) {
                $state['marketplace_product_id'] = $hbSku;
            }
        }

        return $state;
    }

    private function isHepsiburadaSku(string $value): bool
    {
        return $value !== '' && preg_match('/^HBC[A-Z0-9]{6,}$/i', $value) === 1;
    }

    /**
     * Gönderim sonucu (checkBatchStatus haritası) içinde ürünün satırı.
     * Pazaryerine göre anahtar barkod ya da satıcı stok kodudur (Trendyol barkod,
     * Hepsiburada barkod + merchantSku); ikisi de denenir, stok kodu normalize karşılaştırılır.
     *
     * @param array<string, mixed> $apiResult
     * @param array<string, mixed> $product
     * @return array<string, string>|null
     */
    private function batchStatusForProduct(array $apiResult, array $product): ?array
    {
        $statuses = $apiResult['batch_status_by_barcode'] ?? [];
        if (!is_array($statuses) || $statuses === []) {
            return null;
        }

        $keys = [];
        $barcode = trim((string) ($product['barcode'] ?? ''));
        if ($barcode !== '') {
            $keys[] = 'barcode:' . $barcode;
        }
        $keys = array_merge($keys, $this->batchSkuKeys((string) ($product['sku'] ?? '')));

        foreach ($keys as $key) {
            if (isset($statuses[$key]) && is_array($statuses[$key])) {
                return $statuses[$key];
            }
        }

        return null;
    }

    /**
     * Stok kodu eşleme anahtarları: olduğu gibi (büyük harf) ve HB merchantSku kuralıyla normalize.
     *
     * @return array<int, string>
     */
    private function batchSkuKeys(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [];
        }

        $keys = ['sku:' . strtoupper($sku)];
        if (method_exists(\App\Services\Marketplace\HepsiburadaService::class, 'normalizeMerchantSku')) {
            $normalized = \App\Services\Marketplace\HepsiburadaService::normalizeMerchantSku($sku);
            if ($normalized !== '') {
                $keys[] = 'sku:' . $normalized;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Gönderim sonucunu sorgula (4 deneme × 1,5 sn) ve ürün satırlarını eşleme anahtarlarıyla döndür:
     * 'barcode:<barkod>' ve 'sku:<STOK KODU>' → {status: SUCCESS|FAILED|PROCESSING|…, message, hb_sku}.
     * getBatchStatus sözleşmesi: {status: PROCESSING|COMPLETED, items[{barcode, merchantSku, status, failureReasons[]}]}.
     *
     * @return array<string, array<string, string>>
     */
    private function checkBatchStatus(object $marketplaceService, string $batchId): array
    {
        if ($batchId === '' || !method_exists($marketplaceService, 'getBatchStatus')) {
            return [];
        }

        $status = [];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) {
                usleep(1500000);
            }

            try {
                $status = $marketplaceService->getBatchStatus($batchId);
            } catch (\Throwable $e) {
                return [];
            }

            // Trendyol üst seviye status döner (PROCESSING → COMPLETED);
            // ÇiçekSepeti gibi servislerde üst status yoktur — items geldiyse tamam say
            $topStatus = strtoupper((string) ($status['status'] ?? ''));
            if ($topStatus === 'COMPLETED' || ($topStatus === '' && !empty($status['items']))) {
                break;
            }
        }

        $byKey = [];
        foreach ((array) ($status['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Trendyol: items[].requestItem.barcode — ÇiçekSepeti: items[].data.barcode
            // Hepsiburada: items[].barcode + items[].merchantSku
            $requestItem = is_array($item['requestItem'] ?? null) ? $item['requestItem'] : [];
            $requestProduct = is_array($requestItem['product'] ?? null) ? $requestItem['product'] : [];
            $dataItem = is_array($item['data'] ?? null) ? $item['data'] : [];
            $barcode = trim((string) (
                $requestItem['barcode']
                ?? $requestProduct['barcode']
                ?? $dataItem['barcode']
                ?? $item['barcode']
                ?? ''
            ));
            $skuKeys = $this->batchSkuKeys((string) ($item['merchantSku'] ?? $item['sku'] ?? ''));

            if ($barcode === '' && $skuKeys === []) {
                continue;
            }

            $itemStatus = strtoupper((string) ($item['status'] ?? ''));
            $reasons = $item['failureReasons'] ?? [];
            if (is_array($reasons)) {
                $message = implode(', ', array_map(
                    static fn($r) => is_array($r)
                        ? (string) ($r['message'] ?? json_encode($r, JSON_UNESCAPED_UNICODE))
                        : (string) $r,
                    $reasons
                ));
            } else {
                $message = (string) $reasons;
            }
            if ($message === '') {
                $message = $itemStatus === 'SUCCESS' ? 'Batch basarili' : 'Batch kontrol sonucu bekleniyor';
            }

            $entry = [
                'status'  => $itemStatus,
                'message' => $message,
                'hb_sku'  => trim((string) ($item['hbSku'] ?? $item['hepsiburadaSku'] ?? '')),
            ];

            if ($barcode !== '') {
                $byKey['barcode:' . $barcode] = $entry;
            }
            foreach ($skuKeys as $skuKey) {
                $byKey[$skuKey] = $entry;
            }
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function productHasImage(array $product): bool
    {
        return !empty($this->buildImageList($product));
    }

    private function requiresPublicImages(string $marketplaceSlug): bool
    {
        return in_array($marketplaceSlug, [
            'trendyol',
            'n11',
            'hepsiburada',
            'pazarama',
            'pttavm',
            'ciceksepeti',
            'idefix',
            'amazon',
        ], true);
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, string>
     */
    private function findPrivateImageUrls(array $product): array
    {
        $private = [];
        foreach ($this->buildImageList($product) as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url !== '' && !$this->isPublicHttpUrl($url)) {
                $private[] = $url;
            }
        }

        return $private;
    }

    private function isPublicHttpUrl(string $url): bool
    {
        // public/index.php: görsel adres kuralıyla (app_asset_url) aynı "herkese açık" tanımı
        if (function_exists('app_url_is_public')) {
            return app_url_is_public($url);
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, array<string, mixed>>
     */
    private function buildImageList(array $product, bool $withOrder = false): array
    {
        $urls = [];
        $imagesJson = json_decode($product['images_json'] ?? '[]', true);
        if (is_array($imagesJson)) {
            foreach ($imagesJson as $img) {
                $url = is_array($img) ? ($img['url'] ?? ($img['src'] ?? '')) : (string) $img;
                $url = $this->normalizeImageUrl((string) $url);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $mainImage = $this->normalizeImageUrl((string) ($product['main_image'] ?? ''));
        if ($mainImage !== '') {
            array_unshift($urls, $mainImage);
        }

        $urls = array_values(array_unique(array_filter($urls)));
        $images = [];
        foreach ($urls as $i => $url) {
            $entry = ['url' => $url];
            if ($withOrder) {
                $entry['order'] = $i;
            }
            $images[] = $entry;
        }

        return $images;
    }

    private function normalizeImageUrl(string $url): string
    {
        // public/index.php: göreli ve localhost/özel ağ görsellerini canlı kökle kurar (CLI'da APP_URL)
        if (function_exists('app_asset_url')) {
            return app_asset_url($url);
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (preg_match('~^https?://~i', $url) === 1) {
            return $url;
        }

        $baseUrl = function_exists('app_base_url')
            ? app_base_url()
            : rtrim((string) env('APP_URL', 'http://localhost'), '/');

        $path = ltrim(str_replace('\\', '/', $url), '/');
        if (str_starts_with($path, 'public/')) {
            return rtrim($baseUrl, '/') . '/' . $path;
        }
        if (str_starts_with($path, 'uploads/')) {
            return rtrim($baseUrl, '/') . '/public/' . $path;
        }

        return rtrim($baseUrl, '/') . '/' . $path;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function firstImageUrl(array $item): string
    {
        $images = $item['images'] ?? [];
        if (!is_array($images) || empty($images)) {
            return '';
        }

        $first = reset($images);
        return is_array($first) ? (string) ($first['url'] ?? '') : '';
    }

    /**
     * Sync log kaydı oluştur
     */
    private function logSyncResult(int $marketplaceId, int $success, int $failed, int $skipped, array $errors): void
    {
        $total = $success + $failed + $skipped;
        $status = $failed === 0 ? 'success' : ($success > 0 ? 'partial' : 'failed');

        $errorDetails = !empty($errors) ? json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE) : null;

        $this->syncLogModel->create([
            'marketplace_id'    => $marketplaceId,
            'sync_type'         => 'products_push',
            'status'            => $status,
            'records_fetched'   => $total,
            'records_processed' => $success,
            'records_failed'    => $failed + $skipped,
            'error_details'     => $errorDetails,
            'started_at'        => date('Y-m-d H:i:s'),
            'finished_at'       => date('Y-m-d H:i:s'),
            'triggered_by'      => 'manual',
        ]);
    }
}
