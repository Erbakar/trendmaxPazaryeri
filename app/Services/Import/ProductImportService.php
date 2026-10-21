<?php
/**
 * OzyConnect Panel — ProductImportService (Pazaryerinden Import)
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

namespace App\Services\Import;

use App\Core\Logger;
use App\Enums\ImportMode;
use App\Helpers\StringHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Marketplace;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImportJob;
use App\Services\BrandMappingService;
use App\Services\CategoryMappingService;
use App\Services\Enrichment\EnrichmentDispatcher;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\PttavmService;
use App\Services\Marketplace\IdefixService;
use App\Services\Marketplace\OziygoService;
use App\Services\Marketplace\ShopifyService;
use App\Services\Marketplace\WooCommerceService;
use App\Services\Marketplace\OpenCartService;

final class ProductImportService
{
    /** İçe aktarılan ilanı yayınlı (is_listed=1) sayan kendi-mağaza platformları */
    private const LISTED_ON_IMPORT_SLUGS = ['woocommerce', 'shopify', 'opencart'];

    /** @var array<int> İşlenen ürün ID'leri (enrichment dispatch için) */
    private array $processedProductIds = [];

    /** @var string Aktif import slug'ı */
    private string $currentSlug = 'trendyol';

    public function __construct(
        private readonly Product $productModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly Brand $brandModel,
        private readonly Category $categoryModel,
        private readonly ProductImportJob $importJobModel,
        private readonly ProductAttributeValue $productAttrValueModel,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly PazaramaService $pazaramaService,
        private readonly CiceksepetiService $ciceksepetiService,
        private readonly PttavmService $pttavmService,
        private readonly IdefixService $idefixService,
        private readonly OziygoService $oziygoService,
        private readonly ShopifyService $shopifyService,
        private readonly WooCommerceService $wooCommerceService,
        private readonly OpenCartService $openCartService,
        private readonly CategoryMappingService $categoryMappingService,
        private readonly BrandMappingService $brandMappingService,
        private readonly EnrichmentDispatcher $enrichmentDispatcher,
        private readonly Marketplace $marketplaceModel,
        private readonly MarketplaceCategory $mpCategoryModel,
        private readonly \App\Models\MarketplaceBrand $mpBrandModel,
        private readonly Logger $logger,
    ) {}

    /**
     * Marketplace API credentials yükle (AJAX modunda kullanım)
     */
    public function loadMarketplaceCredentials(string $slug): void
    {
        match ($slug) {
            'trendyol'    => $this->trendyolService->loadCredentials(),
            'n11'         => $this->n11Service->loadCredentials(),
            'hepsiburada' => $this->hepsiburadaService->loadCredentials(),
            'pazarama'    => $this->pazaramaService->loadCredentials(),
            'ciceksepeti' => $this->ciceksepetiService->loadCredentials(),
            'pttavm'      => $this->pttavmService->loadCredentials(),
            'idefix'      => $this->idefixService->loadCredentials(),
            'oziygo'      => $this->oziygoService->loadCredentials(),
            'shopify'     => $this->shopifyService->loadCredentials(),
            'woocommerce' => $this->wooCommerceService->loadCredentials(),
            'opencart'    => $this->openCartService->loadCredentials(),
            default       => null,
        };
    }

    /**
     * Tek sayfa import et — AJAX progress bar için
     *
     * @param string $mode 'full' = eşleşmeyenler yeni ürün olarak oluşturulur, 'match_only' = sadece eşleştir
     * @return array{page: int, totalPages: int, totalElements: int, created: int, updated: int, failed: int}
     */
    public function importSinglePage(int $marketplaceId, string $slug, int $page, int $size = 100, string $mode = 'full'): array
    {
        $this->currentSlug = $slug;
        $importMode = ImportMode::tryFrom($mode) ?? ImportMode::FULL;

        try {
            $response = match ($slug) {
                'trendyol'    => $this->trendyolService->importProducts($page, $size),
                'n11'         => $this->n11Service->importProducts($page, $size),
                'hepsiburada' => $this->hepsiburadaService->importProducts($page, $size),
                'pazarama'    => $this->pazaramaService->importProducts($page, $size),
                'ciceksepeti' => $this->ciceksepetiService->importProducts($page, $size),
                'pttavm'      => $this->pttavmService->importProducts($page, $size),
                'idefix'      => $this->idefixService->importProducts($page, $size),
                'oziygo'      => $this->oziygoService->importProducts($page, $size),
                'shopify'     => $this->shopifyService->importProducts($page, $size),
                'woocommerce' => $this->wooCommerceService->importProducts($page, $size),
                'opencart'    => $this->openCartService->importProducts($page, $size),
                default       => ['products' => [], 'totalPages' => 0, 'totalElements' => 0],
            };
        } catch (\RuntimeException $e) {
            // ÇiçekSepeti gibi pazaryerlerinde ürün listeleme kotası çok sıkıdır
            // (aynı endpoint 10 dakikada 1). En az bir sayfa alındıysa bunu hata
            // değil "kısmi tamamlandı" olarak raporla — kalan sayfalar bir sonraki
            // senkronda içeri alınır.
            if ($page > 0 && str_contains($e->getMessage(), 'rate limit')) {
                $this->logger->warning(
                    "Ürün importu sayfa {$page} pazaryeri kotasına takıldı ({$slug}) — kalan sayfalar sonraki senkronda alınacak",
                    'ProductImport'
                );

                return [
                    'page'          => $page,
                    'totalPages'    => $page, // done=true olarak işaretlenir
                    'totalElements' => 0,
                    'pageSize'      => 0,
                    'created'       => 0,
                    'updated'       => 0,
                    'failed'        => 0,
                    'errors'        => [],
                    'rateLimited'   => true,
                    'notice'        => 'Pazaryeri API kotası nedeniyle kalan sayfalar şimdi alınamadı; '
                        . 'sonraki otomatik senkronda devam edilecek.',
                    'slug'          => $slug,
                ];
            }

            throw $e;
        }

        $totalPages = $response['totalPages'];
        $totalElements = $response['totalElements'];
        $products = $response['products'];

        $created = 0;
        $updated = 0;
        $failed = 0;

        $result = new ImportResult();

        foreach ($products as $productData) {
            try {
                $this->processImportProduct($marketplaceId, $productData, $result, $importMode);
            } catch (\Throwable $e) {
                $result->addError(
                    $productData['marketplace_sku'] ?? $productData['barcode'] ?? 'unknown',
                    $e->getMessage()
                );
                $this->logger->error("Import hatası: " . $e->getMessage(), 'ProductImport');
            }
        }

        // CicekSepeti: Ürünlerden benzersiz kategori ve markaları cache'e kaydet
        // (/Category endpoint erişilemez, marka listesi API'si hiç yok — tek kaynak ürünler)
        if ($slug === 'ciceksepeti') {
            $this->extractAndCacheCategoriesFromProducts($marketplaceId, $products);
            $this->extractAndCacheBrandsFromProducts($marketplaceId, $products);
        }

        // Varyant gruplaması — pazaryerindeki group ID'ye göre parent-child ilişkisi kur
        $this->groupVariantsFromImport($products);

        // Enrichment dispatch — import sonrası otomatik zenginleştirme
        $this->dispatchEnrichment($marketplaceId);

        $pageResult = [
            'page'          => $page,
            'totalPages'    => $totalPages,
            'totalElements' => $totalElements,
            'pageSize'      => count($products),
            'created'       => $result->created,
            'updated'       => $result->updated,
            'failed'        => $result->failed,
            'errors'        => array_slice($result->errors, 0, 5),
            'slug'          => $slug,
        ];

        // İmleç tabanlı sayfalama (Shopify since_id): bir sonraki sayfa numarası
        // servisten gelir; sayfa sayacını +1 artırmak imleci bozar.
        if (array_key_exists('nextPage', $response)) {
            $pageResult['nextPage'] = $response['nextPage'];
        }
        if (array_key_exists('done', $response)) {
            $pageResult['done'] = (bool) $response['done'];
        }

        return $pageResult;
    }

    /**
     * Bir sonraki sayfa/imleç değeri (Shopify since_id gibi imleçli servisler dahil).
     *
     * @param array<string, mixed> $response
     * @return int|null null = son sayfa
     */
    private function resolveNextPage(array $response, int $currentPage): ?int
    {
        if (array_key_exists('done', $response)) {
            if (!empty($response['done'])) {
                return null;
            }
            $next = $response['nextPage'] ?? null;
            return $next !== null ? (int) $next : $currentPage + 1;
        }

        if (array_key_exists('nextPage', $response)) {
            return $response['nextPage'] !== null ? (int) $response['nextPage'] : null;
        }

        if (count($response['products'] ?? []) === 0) {
            return null;
        }

        $totalPages = max(1, (int) ($response['totalPages'] ?? 1));
        return ($currentPage + 1) < $totalPages ? $currentPage + 1 : null;
    }

    /**
     * Pazaryerinden ürünleri çek ve PIM'e aktar (tam senkron — eski yöntem)
     *
     * @return ImportResult
     */
    public function importFromMarketplace(int $marketplaceId, string $slug, string $mode = 'full', ?int $userId = null): ImportResult
    {
        $this->currentSlug = $slug;
        $importMode = ImportMode::tryFrom($mode) ?? ImportMode::FULL;
        $jobId = $this->importJobModel->startJob($marketplaceId, $mode, $userId);
        $result = new ImportResult();

        try {
            $page = 0;
            $size = 100;
            $guard = 0;

            do {
                $response = match ($slug) {
                    'trendyol'    => $this->trendyolService->importProducts($page, $size),
                    'n11'         => $this->n11Service->importProducts($page, $size),
                    'hepsiburada' => $this->hepsiburadaService->importProducts($page, $size),
                    'pazarama'    => $this->pazaramaService->importProducts($page, $size),
                    'ciceksepeti' => $this->ciceksepetiService->importProducts($page, $size),
                    'pttavm'      => $this->pttavmService->importProducts($page, $size),
                    'idefix'      => $this->idefixService->importProducts($page, $size),
                    'oziygo'      => $this->oziygoService->importProducts($page, $size),
                    'shopify'     => $this->shopifyService->importProducts($page, $size),
                    'woocommerce' => $this->wooCommerceService->importProducts($page, $size),
                    'opencart'    => $this->openCartService->importProducts($page, $size),
                    default       => ['products' => [], 'totalPages' => 0, 'totalElements' => 0],
                };

                $products = $response['products'];

                foreach ($products as $productData) {
                    try {
                        $this->processImportProduct($marketplaceId, $productData, $result, $importMode);
                    } catch (\Throwable $e) {
                        $result->addError(
                            $productData['marketplace_sku'] ?? $productData['barcode'] ?? 'unknown',
                            $e->getMessage()
                        );
                        $this->logger->error("Import hatası: " . $e->getMessage(), 'ProductImport');
                    }
                }

                $result->total += count($products);

                // Varyant gruplaması — sihirbaz (importSinglePage) ile aynı yapı kurulsun
                $this->groupVariantsFromImport($products);

                // İlerleme güncelle
                $this->importJobModel->updateProgress($jobId, $result->total, $result->created + $result->updated, $result->failed);

                $nextPage = $this->resolveNextPage($response, $page);
                if ($nextPage === null || ++$guard > 500) {
                    break;
                }
                $page = $nextPage;
            } while (true);

            $status = $result->failed > 0 ? 'completed_with_errors' : 'completed';
            $this->importJobModel->finishJob($jobId, $status, $result->total, $result->created + $result->updated, $result->failed, $result->errors);

            // Enrichment dispatch — import sonrası otomatik zenginleştirme
            $this->dispatchEnrichment($marketplaceId);

        } catch (\Throwable $e) {
            $this->importJobModel->finishJob($jobId, 'failed', $result->total, $result->created + $result->updated, $result->failed, [['error' => $e->getMessage()]]);
            throw $e;
        }

        return $result;
    }

    /**
     * Zenginleştirme tetikle — import sonrası çağrılır
     * EnrichmentDispatcher soyutlaması sayesinde sync/async swap edilebilir
     * Hata durumunda import sonucunu etkilemez
     */
    private function dispatchEnrichment(int $marketplaceId): void
    {
        $productIds = array_unique($this->processedProductIds);
        $this->processedProductIds = []; // Reset for next call

        if (empty($productIds)) {
            return;
        }

        $this->enrichmentDispatcher->dispatch($marketplaceId, $productIds);
    }

    /**
     * CicekSepeti ürünlerinden benzersiz kategorileri çıkar ve marketplace_categories tablosuna kaydet
     *
     * /Category endpoint erişilemediğinde tek kategori kaynağı budur.
     *
     * @param array<int, array<string, mixed>> $products normalize edilmiş ürün listesi
     */
    private function extractAndCacheCategoriesFromProducts(int $marketplaceId, array $products): void
    {
        $saved = 0;

        foreach ($products as $p) {
            $catId   = (string) ($p['category_id'] ?? '');
            $catName = trim((string) ($p['category_name'] ?? ''));
            if ($catId === '' || $catName === '') {
                continue;
            }

            try {
                $this->mpCategoryModel->upsert($marketplaceId, $catId, $catName, null, $catName, true);
                $saved++;
            } catch (\Throwable $e) {
                // Duplicate / constraint hataları sessizce atla
            }
        }

        if ($saved > 0) {
            $this->logger->debug("CicekSepeti: Ürünlerden {$saved} kategori cache'e kaydedildi", 'ProductImport');
        }
    }

    /**
     * ÇiçekSepeti ürünlerinden benzersiz markaları çıkar ve marketplace_brands
     * tablosuna kaydet. ÇS'nin marka listesi API'si yoktur — marka eşleştirme
     * ekranının çalışabilmesi için tek kaynak, satıcının kendi ürünlerindeki
     * "Marka" özelliğidir.
     *
     * @param array<int, array<string, mixed>> $products normalize edilmiş ürün listesi
     */
    private function extractAndCacheBrandsFromProducts(int $marketplaceId, array $products): void
    {
        $unique = [];
        foreach ($products as $p) {
            $brandName = trim((string) ($p['brand_name'] ?? ''));
            if ($brandName === '') {
                continue;
            }
            // ÇS gerçek marka ID'si sunmadığından adın kendisi ID olarak kullanılır
            $unique[mb_strtolower($brandName)] = $brandName;
        }

        if ($unique === []) {
            return;
        }

        $rows = [];
        foreach ($unique as $name) {
            $rows[] = ['mp_brand_id' => $name, 'name' => $name];
        }

        try {
            $saved = $this->mpBrandModel->bulkUpsert($marketplaceId, $rows);
            if ($saved > 0) {
                $this->logger->debug("CicekSepeti: Ürünlerden {$saved} marka cache'e kaydedildi", 'ProductImport');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('CicekSepeti marka cache hatası: ' . $e->getMessage(), 'ProductImport');
        }
    }

    /**
     * Tek ürünü işle
     *
     * Upsert anahtarları:
     *   1. barcode (birincil — barcode varsa HER ZAMAN primary match)
     *   2. sku (ikincil — sadece barcode yoksa, marketplace_products.marketplace_sku üzerinden)
     *   3. İkisi de eşleşmezse:
     *      - FULL/SELECTIVE mod → yeni master product oluştur
     *      - MATCH_ONLY/DELTA mod → skip + hata raporla
     *
     * @param array<string, mixed> $data Normalize edilmiş ürün verisi
     */
    private function processImportProduct(int $marketplaceId, array $data, ImportResult $result, ImportMode $mode): void
    {
        $barcode = trim($data['barcode'] ?? '');
        $mpSku = trim($data['marketplace_sku'] ?? '');

        // ── 1. Ürün eşleştirme: barcode → sku → mode-dependent ──
        $existingProduct = null;
        $isNewProduct = false;

        if ($barcode !== '') {
            // Barkod bu hesapta tek güvenilir, listing-bazlı benzersiz anahtar.
            // Barkodlu ürün bulunamazsa GERÇEKTEN yenidir — stockCode/marketplace_sku'ya
            // düşme; o anahtar benzersiz değil ve iki farklı ürünü tek kayda birleştirir.
            $found = $this->productModel->findByBarcode($barcode);
            if ($found !== false) {
                $existingProduct = $found;
            }
        } elseif ($mpSku !== '') {
            // Yalnızca barkodsuz ürün: korumalı son-çare marketplace_sku eşleşmesi.
            $mpExisting = $this->mpProductModel->findByMarketplaceSku($marketplaceId, $mpSku);
            if ($mpExisting !== false) {
                $found = $this->productModel->find((int) $mpExisting['product_id']);
                if ($found !== false) {
                    $existingProduct = $found;
                }
            }
        }

        // Barkod/marketplace_sku eşleşmedi ama aynı stok kodlu master ürün olabilir
        // (ör. başka pazaryerinden aynı stockCode ile import edilmiş, barkodları farklı).
        // uq_products_store_sku (store_id, sku) harf-duyarsız (utf8mb4_unicode_ci)
        // olduğundan 'çç01' INSERT'ü mevcut 'ÇÇ01' ile 1062 çakışması üretir —
        // patlamak yerine products.sku üzerinden eşleştirip mevcut ürüne bağla.
        if ($existingProduct === null && $mpSku !== '') {
            $foundBySku = $this->productModel->findBySku($mpSku);
            if ($foundBySku !== false) {
                $existingProduct = $foundBySku;
            }
        }

        // İkisi de eşleşmezse → moda göre davran
        if ($existingProduct === null) {
            if (!$mode->allowsProductCreation()) {
                // MATCH_ONLY / DELTA → skip
                $identifier = $barcode !== '' ? $barcode : ($mpSku !== '' ? $mpSku : 'unknown');
                $result->addError($identifier, 'Eşleştirilemedi: barcode ve SKU ile mevcut ürün bulunamadı (mod: ' . $mode->value . ')');
                $result->skipped++;
                return;
            }

            // FULL / SELECTIVE → yeni master product oluştur
            $sku = $mpSku !== '' ? $mpSku : StringHelper::randomToken(8);

            $brandId = null;
            $brandName = $data['brand_name'] ?? '';
            if ($brandName !== '') {
                $brandId = $this->brandMappingService->resolveBrand($brandName, $marketplaceId);
                $result->brandMatched++;
            } else {
                $result->brandUnmatched++;
            }

            $categoryId = null;
            $mpCategoryId = $this->extractCategoryId($data);
            if ($mpCategoryId !== null) {
                $categoryId = $this->categoryMappingService->resolveCategory($mpCategoryId, $marketplaceId);
                if ($categoryId !== null) {
                    $result->categoryMatched++;
                } else {
                    $result->categoryUnmatched++;
                }
            }

            $images = $this->httpsGorseller($data['images'] ?? []);
            $mainImage = !empty($images) ? ($images[0]['url'] ?? null) : null;
            $imagesJson = !empty($images) ? json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

            // Fiyat farkı ters uygula (pazaryeri fiyatından sistem fiyatına)
            $mpPrice = (float)($data['price'] ?? 0);
            $mpListPrice = (float)($data['list_price'] ?? 0);
            $systemPrice = $this->reversePriceAdjustment($mpPrice, $marketplaceId);
            $systemListPrice = $this->reversePriceAdjustment($mpListPrice, $marketplaceId);
            $hasReliableStock = $this->hasReliableStock($data);
            $importStock = $this->normalizeReliableStock($data);

            try {
                $productId = $this->productModel->create([
                    'sku'         => $sku,
                    'barcode'     => $barcode !== '' ? $barcode : null,
                    'name'        => $data['name'] ?? 'İsimsiz Ürün',
                    'base_price'  => $systemPrice,
                    'list_price'  => $systemListPrice,
                    'stock_qty'   => $hasReliableStock ? $importStock : 0,
                    'brand_id'    => $brandId,
                    'category_id' => $categoryId,
                    'main_image'  => $mainImage,
                    'images_json' => $imagesJson,
                    'source'      => $this->currentSlug,
                    'source_id'   => $data['marketplace_product_id'] ?? null,
                    'is_active'   => 1,
                ]);
            } catch (\PDOException $e) {
                // 1062: (store_id, sku) benzersiz index çakışması — yarış durumu veya
                // collation eşitliği. Mevcut kaydı bulup güncelleme yoluna geç.
                if ((string) $e->getCode() === '23000' && $sku !== '') {
                    $collided = $this->productModel->findBySku($sku);
                    if ($collided !== false) {
                        $this->updateExistingImportProduct($marketplaceId, $data, $result, (array) $collided, $barcode, $mpSku);
                        return;
                    }
                }
                throw $e;
            }

            $result->created++;
            $isNewProduct = true;
            $this->processedProductIds[] = $productId;

            // Marketplace eşleştirmesi + özellikler
            $mpPayload = $this->buildMarketplaceProductPayload($data, $mainImage);
            if (!$hasReliableStock) {
                $mpPayload['last_stock_sent'] = 0;
            }

            $this->mpProductModel->upsertByProductAndMarketplace($productId, $marketplaceId, [
                ...$mpPayload,
                'marketplace_sku'        => $mpSku,
                'marketplace_barcode'    => $barcode,
                'marketplace_product_id' => $data['marketplace_product_id'] ?? null,
            ]);

            $this->importProductAttributes($productId, $data['attributes'] ?? []);
            $this->syncOziygoVariants(
                $marketplaceId,
                $productId,
                $data,
                $mainImage,
                $brandId !== null ? (int) $brandId : null,
                $categoryId !== null ? (int) $categoryId : null,
                (float) $systemPrice,
                (float) $systemListPrice
            );
            return;
        }

        // ── Mevcut ürün bulundu — güncelle ──
        $this->updateExistingImportProduct($marketplaceId, $data, $result, (array) $existingProduct, $barcode, $mpSku);
    }

    /**
     * Eşleşen mevcut master ürünü import verisiyle güncelle + pazaryeri eşleştirmesini upsert et
     *
     * @param array<string, mixed> $data Normalize edilmiş ürün verisi
     * @param array<string, mixed> $existingProduct products tablosundaki mevcut satır
     */
    private function updateExistingImportProduct(
        int $marketplaceId,
        array $data,
        ImportResult $result,
        array $existingProduct,
        string $barcode,
        string $mpSku
    ): void {
        $productId = (int) $existingProduct['id'];

        // ── 2. Kategori eşleştirme ──
        $categoryId = $existingProduct['category_id'];
        $mpCategoryId = $this->extractCategoryId($data);

        if ($mpCategoryId !== null) {
            $resolvedCategoryId = $this->categoryMappingService->resolveCategory($mpCategoryId, $marketplaceId);
            if ($resolvedCategoryId !== null) {
                $categoryId = $resolvedCategoryId;
                $result->categoryMatched++;
            } else {
                $result->categoryUnmatched++;
            }
        }

        // ── 3. Marka eşleştirme ──
        $brandId = $existingProduct['brand_id'];
        $brandName = $data['brand_name'] ?? '';

        if ($brandName !== '') {
            $brandId = $this->brandMappingService->resolveBrand($brandName, $marketplaceId);
            $result->brandMatched++;
        } else {
            $result->brandUnmatched++;
        }

        // ── 4. Görsel işleme ──
        $mainImage = $existingProduct['main_image'] ?? null;
        $imagesJson = $existingProduct['images_json'] ?? null;

        $images = $this->httpsGorseller($data['images'] ?? []);
        if (!empty($images)) {
            $mainImage = $images[0]['url'] ?? $mainImage;
            $imagesJson = json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // ── 5. Mevcut ürünü güncelle ──
        // Fiyat farkı ters uygula (pazaryeri fiyatından sistem fiyatına)
        $mpPrice = $data['price'] ?? null;
        $mpListPrice = $data['list_price'] ?? null;
        $systemPrice = $mpPrice !== null
            ? $this->reversePriceAdjustment((float)$mpPrice, $marketplaceId)
            : $existingProduct['base_price'];
        $systemListPrice = $mpListPrice !== null
            ? $this->reversePriceAdjustment((float)$mpListPrice, $marketplaceId)
            : $existingProduct['list_price'];
        $hasReliableStock = $this->hasReliableStock($data);
        $importStock = $this->normalizeReliableStock($data);

        $updateData = [
            'base_price'  => $systemPrice,
            'list_price'  => $systemListPrice,
            'stock_qty'   => $this->shouldUpdateMasterStockFromImport() && $hasReliableStock
                ? $importStock
                : $existingProduct['stock_qty'],
            'brand_id'    => $brandId,
            'category_id' => $categoryId,
            'main_image'  => $mainImage,
            'images_json' => $imagesJson,
            'source'      => $this->currentSlug,
            'source_id'   => $data['marketplace_product_id'] ?? $existingProduct['source_id'] ?? null,
        ];

        $this->productModel->update($productId, $updateData);
        $result->updated++;
        $this->processedProductIds[] = $productId;

        // ── 6. Marketplace ürün eşleştirmesi (upsert: product_id + marketplace_id) ──
        $this->mpProductModel->upsertByProductAndMarketplace($productId, $marketplaceId, [
            ...$this->buildMarketplaceProductPayload($data, $mainImage),
            'marketplace_sku'        => $mpSku,
            'marketplace_barcode'    => $barcode,
            'marketplace_product_id' => $data['marketplace_product_id'] ?? null,
        ]);

        // ── 7. Özellik verilerini kaydet ──
        $this->importProductAttributes($productId, $data['attributes'] ?? []);
        $this->syncOziygoVariants(
            $marketplaceId,
            $productId,
            $data,
            $mainImage,
            $brandId !== null ? (int) $brandId : null,
            $categoryId !== null ? (int) $categoryId : null,
            (float) $systemPrice,
            (float) $systemListPrice
        );
    }

    /**
     * İlk import akışında marketplace_products satırını liste ekranı için zenginleştir.
     *
     * Manuel sync ekranı zaten bu alanları dolduruyor; initial import da aynı meta
     * bilgileri yazmalı ki fiyat / stok / resim / isim alanları boş kalmasın.
     *
     * @return array<string, mixed>
     */
    /**
     * OziyGO stores options in anaozellik/anaozellikalt, not as child products.
     * Convert those option rows into OzyConnect child variants under the parent.
     *
     * @param array<string, mixed> $data
     */
    private function syncOziygoVariants(
        int $marketplaceId,
        int $parentId,
        array $data,
        ?string $mainImage,
        ?int $brandId,
        ?int $categoryId,
        float $parentPrice,
        float $parentListPrice
    ): void {
        if ($this->currentSlug !== 'oziygo') {
            return;
        }

        $groups = is_array($data['variants'] ?? null) ? $data['variants'] : [];
        $sourceProductId = (string) ($data['marketplace_product_id'] ?? '');
        if ($sourceProductId === '') {
            return;
        }

        $combinations = $this->buildOziygoVariantCombinations($groups);
        if ($combinations === []) {
            $this->deleteStaleOziygoVariants($parentId, $sourceProductId, []);
            $this->productModel->update($parentId, ['has_variants' => 0]);
            return;
        }

        $parent = $this->productModel->find($parentId);
        if ($parent === false) {
            return;
        }

        $parentSku = trim((string) ($parent['sku'] ?? $data['marketplace_sku'] ?? ''));
        $parentName = trim((string) ($parent['name'] ?? $data['name'] ?? 'Urun'));
        $imagesJson = $parent['images_json'] ?? null;
        $processedIds = [];

        foreach ($combinations as $combo) {
            $valueIds = [];
            $labels = [];
            $attributes = [];
            $stockValues = [];
            $priceAdd = 0.0;

            foreach ($combo as $entry) {
                $groupName = trim((string) ($entry['group_name'] ?? 'Secenek'));
                $valueName = trim((string) ($entry['value_name'] ?? ''));
                $valueId = trim((string) ($entry['value_id'] ?? ''));

                if ($valueId !== '') {
                    $valueIds[] = $valueId;
                }
                if ($valueName !== '') {
                    $labels[] = $valueName;
                    $attributes[$groupName] = $valueName;
                }

                if ($this->hasReliableStock($entry)) {
                    $stockValues[] = $this->normalizeReliableStock($entry);
                }
                $priceAdd += $this->reversePriceAdjustment((float) ($entry['price'] ?? 0), $marketplaceId);
            }

            if ($valueIds === []) {
                continue;
            }

            $variantKey = implode('-', $valueIds);
            $variantSourceId = $sourceProductId . ':' . $variantKey;
            $variantCode = ($parentSku !== '' ? $parentSku : 'ozygo-' . $sourceProductId) . '-V' . $variantKey;
            $label = implode(' / ', $labels);
            $variantName = $parentName . ($label !== '' ? ' - ' . $label : '');
            $variantPrice = round($parentPrice + $priceAdd, 2);
            $variantListPrice = round($parentListPrice + $priceAdd, 2);

            $existingChild = $this->productModel->findBy([
                'source' => 'oziygo',
                'source_id' => $variantSourceId,
            ]);
            if ($existingChild === false) {
                $existingChild = $this->productModel->findBySku($variantCode);
            }

            if ($stockValues !== []) {
                $variantStock = min($stockValues);
            } elseif ($this->hasReliableStock($data)) {
                $variantStock = $this->normalizeReliableStock($data);
            } elseif ($existingChild !== false) {
                $variantStock = (int) ($existingChild['stock_qty'] ?? 0);
            } else {
                $variantStock = 0;
            }

            $childData = [
                'name' => $variantName,
                'sku' => $variantCode,
                'barcode' => $variantCode,
                'base_price' => $variantPrice,
                'list_price' => $variantListPrice,
                'stock_qty' => $variantStock,
                'parent_id' => $parentId,
                'has_variants' => 0,
                'is_active' => (int) ($parent['is_active'] ?? 1),
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'tax_rate' => (float) ($parent['tax_rate'] ?? 20),
                'description' => $parent['description'] ?? '',
                'images_json' => $imagesJson,
                'main_image' => $mainImage ?? ($parent['main_image'] ?? ''),
                'source' => 'oziygo',
                'source_id' => $variantSourceId,
                'variant_attributes' => json_encode([
                    'label' => $label,
                    'options' => $attributes,
                    'oziygo_product_id' => $sourceProductId,
                    'oziygo_value_ids' => $valueIds,
                ], JSON_UNESCAPED_UNICODE),
            ];

            if ($existingChild !== false) {
                $childId = (int) $existingChild['id'];
                $this->productModel->update($childId, $childData);
            } else {
                $childId = $this->productModel->create($childData);
            }

            $processedIds[] = $childId;
            $this->processedProductIds[] = $childId;

            $variantPayload = $data;
            $variantPayload['name'] = $variantName;
            $variantPayload['price'] = $variantPrice;
            $variantPayload['list_price'] = $variantListPrice;
            $variantPayload['stock'] = $variantStock;

            $this->mpProductModel->upsertByProductAndMarketplace($childId, $marketplaceId, [
                ...$this->buildMarketplaceProductPayload($variantPayload, $mainImage),
                'marketplace_sku' => $variantCode,
                'marketplace_barcode' => $variantCode,
                'marketplace_product_id' => $variantSourceId,
                'is_active' => 0,
            ]);
        }

        $this->deleteStaleOziygoVariants($parentId, $sourceProductId, $processedIds);

        if ($processedIds !== []) {
            $summary = $this->productModel->getVariantSummary($parentId);
            $this->productModel->update($parentId, [
                'has_variants' => 1,
                'stock_qty' => $summary['total_stock'],
            ]);
        }
    }

    /**
     * @param array<int, mixed> $groups
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildOziygoVariantCombinations(array $groups): array
    {
        $normalizedGroups = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupName = trim((string) ($group['name'] ?? 'Secenek'));
            $values = [];
            foreach (($group['values'] ?? []) as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $valueId = trim((string) ($value['id'] ?? ''));
                $valueName = trim((string) ($value['name'] ?? ''));
                if ($valueId === '' || $valueName === '') {
                    continue;
                }

                $option = [
                    'group_name' => $groupName,
                    'value_id' => $valueId,
                    'value_name' => $valueName,
                    'price' => (float) ($value['price'] ?? 0),
                ];

                if ($this->hasReliableStock($value)) {
                    $option['stock'] = $this->normalizeReliableStock($value);
                }

                $values[] = $option;
            }

            if ($values !== []) {
                $normalizedGroups[] = $values;
            }
        }

        if ($normalizedGroups === []) {
            return [];
        }

        $combinations = [[]];
        foreach ($normalizedGroups as $values) {
            $next = [];
            foreach ($combinations as $combo) {
                foreach ($values as $value) {
                    $next[] = [...$combo, $value];
                    if (count($next) >= 250) {
                        break 2;
                    }
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param array<int, int> $keepIds
     */
    private function deleteStaleOziygoVariants(int $parentId, string $sourceProductId, array $keepIds): void
    {
        $children = $this->productModel->getVariants($parentId);
        foreach ($children as $child) {
            $childId = (int) ($child['id'] ?? 0);
            $source = (string) ($child['source'] ?? '');
            $sourceId = (string) ($child['source_id'] ?? '');

            if ($source !== 'oziygo' || !str_starts_with($sourceId, $sourceProductId . ':')) {
                continue;
            }
            if (in_array($childId, $keepIds, true)) {
                continue;
            }

            $this->productModel->delete($childId);
        }
    }

    private function buildMarketplaceProductPayload(array $data, ?string $fallbackImage = null): array
    {
        $firstImage = $fallbackImage;

        if (($firstImage === null || $firstImage === '') && !empty($data['images']) && is_array($data['images'])) {
            $firstImage = (string) ($data['images'][0]['url'] ?? '');
        }

        $brandName = trim((string) ($data['brand_name'] ?? ''));
        $brandId = trim((string) ($data['brand_id'] ?? ''));
        if ($brandId === '' && $brandName !== '') {
            $brandId = $brandName;
        }

        $payload = [
            'last_price_sent'           => array_key_exists('price', $data) ? (float) ($data['price'] ?? 0) : null,
            'is_active'                 => (int) (($data['is_active'] ?? true) ? 1 : 0),
            'sync_error'                => null,
        ];

        // Kendi mağaza platformundan (WooCommerce/Shopify/OpenCart) gelen ürün orada zaten satışta: ilan
        // stok/fiyat senkronu için yayınlı sayılır (elle eşleştirme ve "Pazaryeri ürünlerini çek" ile aynı
        // kural). Aksi halde StockSync/PriceSync (is_listed=1 arar) içe aktarılan ürünleri hiç göndermez.
        if (in_array($this->currentSlug, self::LISTED_ON_IMPORT_SLUGS, true)
            && trim((string) ($data['marketplace_product_id'] ?? '')) !== ''
        ) {
            $payload['is_listed'] = 1;
        }

        if ($this->hasReliableStock($data)) {
            $payload['last_stock_sent'] = $this->normalizeReliableStock($data);
        }

        if (($data['category_id'] ?? '') !== '') {
            $payload['marketplace_category_id'] = (string) $data['category_id'];
        }
        if (($data['category_name'] ?? '') !== '') {
            $payload['marketplace_category_name'] = (string) $data['category_name'];
        }
        if ($brandId !== '') {
            $payload['marketplace_brand_id'] = $brandId;
        }
        if ($brandName !== '') {
            $payload['marketplace_brand_name'] = $brandName;
        }
        if (($data['name'] ?? '') !== '') {
            $payload['product_name'] = (string) $data['name'];
        }
        if (($firstImage ?? '') !== '') {
            $payload['main_image'] = $firstImage;
        }

        return $payload;
    }

    private function hasReliableStock(array $data): bool
    {
        return array_key_exists('stock', $data)
            && $data['stock'] !== null
            && $data['stock'] !== '';
    }

    private function normalizeReliableStock(array $data): int
    {
        return max(0, (int) ($data['stock'] ?? 0));
    }

    private function shouldUpdateMasterStockFromImport(): bool
    {
        return $this->currentSlug === 'oziygo';
    }

    /**
     * Trendyol ürün verisinden kategori ID'sini çıkar
     */
    /**
     * Görsel adreslerini https'e yükselt.
     *
     * Panelin güvenlik başlığı (CSP) `img-src 'self' data: https:` gönderir ve
     * panel HTTPS üzerinden yayınlanır; http:// adresli görseller tarayıcıda
     * ENGELLENİR. Tek kaynak XmlFileImportService::normalizeImageUrl().
     *
     * @param array<int, mixed> $images
     * @return array<int, mixed>
     */
    private function httpsGorseller(array $images): array
    {
        foreach ($images as $i => $img) {
            if (is_array($img) && isset($img['url'])) {
                $images[$i]['url'] = \App\Services\Import\XmlFileImportService::normalizeImageUrl((string) $img['url']);
            } elseif (is_string($img)) {
                $images[$i] = \App\Services\Import\XmlFileImportService::normalizeImageUrl($img);
            }
        }

        return $images;
    }

    private function extractCategoryId(array $data): ?string
    {
        // raw veride categoryId varsa kullan (Trendyol + N11 uyumlu)
        $raw = $data['raw'] ?? [];
        $categoryId = $raw['categoryId'] ?? $raw['ppiCategoryId'] ?? null;

        // Normalize edilmiş veride category_id varsa onu da dene
        if ($categoryId === null && !empty($data['category_id'])) {
            $categoryId = $data['category_id'];
        }

        if ($categoryId !== null) {
            return (string) $categoryId;
        }

        return null;
    }

    /**
     * Ürün özelliklerini import et
     *
     * @param array<int, array{name: string, value: string}> $attributes
     */
    private function importProductAttributes(int $productId, array $attributes): void
    {
        if (empty($attributes)) {
            return;
        }

        foreach ($attributes as $attr) {
            $name = $attr['name'] ?? '';
            $value = $attr['value'] ?? '';

            if ($name === '' || $value === '') {
                continue;
            }

            // product_attributes tablosunda bu özellik var mı?
            $attributeId = $this->productAttrValueModel->findAttributeIdByName($name);

            if ($attributeId === null) {
                // Özellik tanımı yoksa atla (yeni özellik oluşturmak bu scope dışında)
                continue;
            }

            // custom_value olarak kaydet
            $this->productAttrValueModel->setProductAttribute(
                $productId,
                $attributeId,
                null,
                $value
            );
        }
    }

    /**
     * Pazaryeri fiyatından sistem fiyatına dönüştür (ters fiyat farkı)
     * Örn: Trendyol -10% ayarlandıysa, 900₺ → 1000₺ olarak kaydedilir
     */
    private function reversePriceAdjustment(float $marketplacePrice, int $marketplaceId): float
    {
        if ($marketplacePrice <= 0) {
            return $marketplacePrice;
        }

        $mp = $this->marketplaceModel->find($marketplaceId);
        if ($mp === false) {
            return $marketplacePrice;
        }

        $extraConfig = json_decode($mp['extra_config_json'] ?? '{}', true) ?: [];
        $pct = (float)($extraConfig['price_adjustment_percent'] ?? 0);

        if ($pct === 0.0) {
            return $marketplacePrice;
        }

        // Ters uygula: system_price = marketplace_price / (1 + pct/100)
        $factor = 1 + ($pct / 100);
        if ($factor <= 0) {
            return $marketplacePrice;
        }

        return round($marketplacePrice / $factor, 2);
    }

    /**
     * İmport edilen ürünleri marketplace group ID'sine göre parent-child ilişkisine bağla.
     *
     * Trendyol: productMainId aynı olan ürünler aynı grup (varyant) olur; WooCommerce: aynı değişken ürünün
     * varyasyonları. Grup içinde en yüksek stoklu ürün parent olarak seçilir, diğerleri child olarak bağlanır.
     *
     * Parent de pazaryerinde SATILAN bir varyanttır (kendi ilanı = marketplace_products satırı): stoğu KENDİ
     * stoğudur, grup toplamı yazılmaz (yazılırsa stok senkronu toplamı o ilana gönderir). Ölçüt
     * Product::isImportedVariantGroup; StockManager ve ürün ekranı bu grupta parent'a toplam yazmaz.
     * Grup daha önce kurulduysa sonradan eklenen varyant (ör. WooCommerce'te yeni varyasyon) mevcut parent'a bağlanır.
     *
     * @param array<int, array<string, mixed>> $products Normalize edilmiş ürün listesi
     */
    private function groupVariantsFromImport(array $products): void
    {
        // Grup ID'ye göre ürünleri topla
        $groups = [];
        foreach ($products as $p) {
            $groupId = $p['group_id'] ?? null;
            if ($groupId === null || $groupId === '') {
                continue;
            }

            $barcode = trim($p['barcode'] ?? '');
            if ($barcode === '') {
                continue;
            }

            $product = $this->productModel->findBy(['barcode' => $barcode]);
            if ($product === false) {
                continue;
            }

            $groups[$groupId][(int) $product['id']] = $product;
        }

        // Her grup için parent-child ilişkisi kur
        foreach ($groups as $groupId => $groupProducts) {
            $groupProducts = array_values($groupProducts);
            if (count($groupProducts) < 2) {
                continue; // Tek ürünlü grup — varyant değil
            }

            // Zaten gruplanmışsa: parent bu grubun üyesiyse yalnız bağlanmamış üyeleri ona ekle;
            // üyeler başka bir gruba bağlıysa (ör. elle kurulmuş grup) dokunma.
            $alreadyGrouped = false;
            foreach ($groupProducts as $gp) {
                if (!empty($gp['parent_id']) || !empty($gp['has_variants'])) {
                    $alreadyGrouped = true;
                    break;
                }
            }
            if ($alreadyGrouped) {
                $existingParentId = $this->existingGroupParentId($groupProducts);
                if ($existingParentId === null) {
                    continue;
                }
                $attached = 0;
                foreach ($groupProducts as $gp) {
                    $memberId = (int) $gp['id'];
                    if ($memberId === $existingParentId || !empty($gp['parent_id']) || !empty($gp['has_variants'])) {
                        continue;
                    }
                    $this->productModel->update($memberId, ['parent_id' => $existingParentId]);
                    $attached++;
                }
                if ($attached > 0) {
                    $this->logger->info(
                        "Varyant gruplama: #{$existingParentId} parent'ına {$attached} yeni varyant bağlandı (group: {$groupId})",
                        'ProductImport'
                    );
                }
                continue;
            }

            // Parent seç: en yüksek stoklu ürün (stoğu kendi stoğu olarak kalır)
            usort($groupProducts, fn($a, $b) => (int)($b['stock_qty'] ?? 0) <=> (int)($a['stock_qty'] ?? 0));
            $parent = $groupProducts[0];
            $parentId = (int) $parent['id'];

            $this->productModel->update($parentId, [
                'has_variants' => 1,
            ]);

            // Child'ları bağla
            for ($i = 1; $i < count($groupProducts); $i++) {
                $childId = (int) $groupProducts[$i]['id'];
                $this->productModel->update($childId, [
                    'parent_id' => $parentId,
                ]);
            }

            $this->logger->info(
                "Varyant gruplama: #{$parentId} parent, " . (count($groupProducts) - 1) . " child (group: {$groupId})",
                'ProductImport'
            );
        }
    }

    /**
     * Grubun mevcut parent'ı — yalnız grubun KENDİ üyesi olan varyantlı (has_variants, kendisi alt ürün
     * olmayan) ürün ve diğer bağlı üyeler de ona bağlıysa. Yoksa null.
     *
     * @param array<int, array<string, mixed>> $groupProducts
     */
    private function existingGroupParentId(array $groupProducts): ?int
    {
        $parentId = null;
        foreach ($groupProducts as $gp) {
            if (!empty($gp['has_variants']) && empty($gp['parent_id'])) {
                $parentId = (int) $gp['id'];
                break;
            }
        }
        if ($parentId === null) {
            return null;
        }

        foreach ($groupProducts as $gp) {
            if (!empty($gp['parent_id']) && (int) $gp['parent_id'] !== $parentId) {
                return null;
            }
        }

        return $parentId;
    }
}
