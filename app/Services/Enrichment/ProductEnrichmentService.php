<?php
/**
 * OzyConnect Panel — ProductEnrichmentService
 * İçe aktarma sonrası ürün zenginleştirme pipeline servisi.
 *
 * Pipeline sırası: Kategori → Marka → Görsel → Fiyat → Stok → Durum
 * Her adım bağımsız — bir adımın hatası diğerlerini engellemez.
 * İdempotent — aynı ürün tekrar işlendiğinde veri bozulmaz.
 * Üzerine yazma koruması — geçerli veri asla null/boş ile ezilmez.
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

namespace App\Services\Enrichment;

use App\Core\Logger;
use App\Models\BrandAlias;
use App\Models\EnrichmentLog;
use App\Models\MarketplaceProduct;
use App\Models\Product;
use App\Services\BrandMappingService;
use App\Services\CategoryMappingService;

final class ProductEnrichmentService
{
    public function __construct(
        private readonly Product $productModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly CategoryMappingService $categoryMappingService,
        private readonly BrandMappingService $brandMappingService,
        private readonly EnrichmentLog $enrichmentLogModel,
        private readonly BrandAlias $brandAliasModel,
        private readonly Logger $logger,
    ) {}

    /**
     * Toplu zenginleştirme
     *
     * @param array<int> $productIds Boş ise marketplace'deki tüm ürünler işlenir
     */
    public function enrichImportedProducts(int $marketplaceId, array $productIds = []): EnrichmentResult
    {
        $result = new EnrichmentResult();

        if (empty($productIds)) {
            $products = $this->productModel->getByMarketplace($marketplaceId);
        } else {
            $products = $this->productModel->getByIds($productIds);
        }

        foreach ($products as $product) {
            try {
                $singleResult = $this->enrichSingleProduct((int) $product['id'], $marketplaceId);
                $result->merge($singleResult);
            } catch (\Throwable $e) {
                $result->addError("Ürün #{$product['id']}: " . $e->getMessage());
                $this->logger->error("Enrichment hatası (ürün #{$product['id']}): " . $e->getMessage(), 'Enrichment');
            }
        }

        return $result;
    }

    /**
     * Tek ürün zenginleştirme — tüm pipeline adımlarını çalıştırır
     */
    public function enrichSingleProduct(int $productId, int $marketplaceId): EnrichmentResult
    {
        $result = new EnrichmentResult();
        $result->totalProcessed = 1;

        $product = $this->productModel->find($productId);
        if ($product === false) {
            $result->addError("Ürün bulunamadı: #{$productId}");
            return $result;
        }

        // Marketplace ürün verisini al (raw data için)
        $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);

        $updateData = [];

        // ── Pipeline adımları ──
        $updateData = $this->stepCategory($product, $marketplaceId, $result, $updateData);
        $updateData = $this->stepBrand($product, $marketplaceId, $result, $updateData);
        $updateData = $this->stepImages($product, $mpProduct, $marketplaceId, $result, $updateData);
        $updateData = $this->stepPrice($product, $mpProduct, $marketplaceId, $result, $updateData);
        $updateData = $this->stepStock($product, $mpProduct, $marketplaceId, $result, $updateData);

        // ── Enrichment status hesapla ──
        // Güncel flag'leri hesapla (updateData'daki değerler + mevcut product değerleri)
        $mergedProduct = array_merge($product, $updateData);
        $updateData['enrichment_status'] = $this->computeEnrichmentStatus($mergedProduct);

        // ── DB güncelle ──
        if (!empty($updateData)) {
            $this->productModel->update($productId, $updateData);
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════
    // PIPELINE ADIMLARI
    // ═══════════════════════════════════════════════════════

    /**
     * Adım 1: Kategori eşleştirme
     * Üzerine yazma koruması: mevcut geçerli category_id korunur
     */
    private function stepCategory(array $product, int $marketplaceId, EnrichmentResult $result, array $updateData): array
    {
        $productId = (int) $product['id'];
        $currentCategoryId = $product['category_id'] ?? null;

        // Mevcut geçerli category_id varsa → skip
        if ($currentCategoryId !== null) {
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'category', 'category_id', 'skipped', (string) $currentCategoryId, null, 'Mevcut geçerli kategori korundu');
            $result->categoryMatched++;
            $updateData['needs_category_mapping'] = 0;
            return $updateData;
        }

        try {
            // Marketplace category bilgisini al
            $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
            $mpCategoryId = $mpProduct['marketplace_category_id'] ?? '';
            $mpCategoryName = null;

            // marketplace_products'tan kategori adını çıkar (varsa)
            $mpCategoryName = $mpProduct['marketplace_category_name'] ?? null;

            $match = $this->categoryMappingService->resolveWithFuzzy($mpCategoryId, $mpCategoryName, $marketplaceId);

            if ($match['category_id'] !== null) {
                $updateData['category_id'] = $match['category_id'];
                $updateData['needs_category_mapping'] = 0;
                $result->categoryMatched++;

                // Güvenli eşleşme ise mapping kaydet (fuzzy hariç)
                if ($match['method'] !== 'fuzzy' && $mpCategoryId !== '') {
                    $this->categoryMappingService->resolveCategory($mpCategoryId, $marketplaceId);
                }

                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'category', 'category_id', 'success', null, (string) $match['category_id'], "Eşleşme: {$match['method']} (%{$match['confidence']})");
            } else {
                $updateData['needs_category_mapping'] = 1;
                $result->categoryUnmatched++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'category', 'category_id', 'failed', null, null, 'Kategori eşleşmesi bulunamadı');
            }
        } catch (\Throwable $e) {
            $updateData['needs_category_mapping'] = 1;
            $result->categoryUnmatched++;
            $result->addWarning("Ürün #{$productId}: Kategori eşleştirme hatası: " . $e->getMessage());
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'category', 'category_id', 'failed', null, null, $e->getMessage());
        }

        return $updateData;
    }

    /**
     * Adım 2: Marka eşleştirme
     * Üzerine yazma koruması: mevcut geçerli brand_id korunur
     */
    private function stepBrand(array $product, int $marketplaceId, EnrichmentResult $result, array $updateData): array
    {
        $productId = (int) $product['id'];
        $currentBrandId = $product['brand_id'] ?? null;

        // Mevcut geçerli brand_id varsa → skip
        if ($currentBrandId !== null) {
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'brand', 'brand_id', 'skipped', (string) $currentBrandId, null, 'Mevcut geçerli marka korundu');
            $result->brandMatched++;
            $updateData['needs_brand_mapping'] = 0;
            return $updateData;
        }

        try {
            // Marketplace'den marka adını al — source verisinden
            $brandName = '';
            $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);

            // Trendyol import sırasında brand_name source_id veya raw data'da olabilir
            // Şimdilik marketplace_brand_name veya product name'den çıkarmayı dene
            if ($mpProduct !== false) {
                $brandName = $mpProduct['marketplace_brand_id'] ?? ''; // import sırasında brand name buraya yazılmış olabilir
            }

            if ($brandName === '') {
                $updateData['needs_brand_mapping'] = 1;
                $result->brandUnmatched++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'brand', 'brand_id', 'failed', null, null, 'Marka adı bulunamadı');
                return $updateData;
            }

            $match = $this->brandMappingService->resolveWithFuzzy($brandName, $marketplaceId);

            if ($match['brand_id'] !== null) {
                $updateData['brand_id'] = $match['brand_id'];
                $updateData['needs_brand_mapping'] = 0;
                $result->brandMatched++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'brand', 'brand_id', 'success', null, (string) $match['brand_id'], "Eşleşme: {$match['method']} (%{$match['confidence']})");
            } else {
                $updateData['needs_brand_mapping'] = 1;
                $result->brandUnmatched++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'brand', 'brand_id', 'failed', null, null, "Marka eşleşmesi bulunamadı: {$brandName}");
            }
        } catch (\Throwable $e) {
            $updateData['needs_brand_mapping'] = 1;
            $result->brandUnmatched++;
            $result->addWarning("Ürün #{$productId}: Marka eşleştirme hatası: " . $e->getMessage());
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'brand', 'brand_id', 'failed', null, null, $e->getMessage());
        }

        return $updateData;
    }

    /**
     * Adım 3: Görsel zenginleştirme
     * Üzerine yazma koruması: mevcut geçerli main_image/images_json korunur
     */
    private function stepImages(array $product, array|false $mpProduct, int $marketplaceId, EnrichmentResult $result, array $updateData): array
    {
        $productId = (int) $product['id'];
        $currentMainImage = $product['main_image'] ?? null;
        $currentImagesJson = $product['images_json'] ?? null;

        // Her iki alan da dolu → skip
        if (!empty($currentMainImage) && !empty($currentImagesJson)) {
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'image', 'main_image', 'skipped', null, null, 'Mevcut görseller korundu');
            $result->imageFilled++;
            $updateData['missing_images'] = 0;
            return $updateData;
        }

        try {
            // images_json'dan görselleri çıkar
            $images = [];
            if (!empty($currentImagesJson)) {
                $decoded = json_decode($currentImagesJson, true);
                if (is_array($decoded)) {
                    $images = $decoded;
                }
            }

            // Marketplace verisinden görselleri al (yoksa mevcut images_json'dan)
            if (empty($images) && $mpProduct !== false) {
                // marketplace_products'ta raw data yoksa product'ın images_json'ını kullan
                // Trendyol import sırasında images_json zaten doldurulmuş olmalı
            }

            // main_image boşsa ve images varsa → ilk görseli ata
            if (empty($currentMainImage) && !empty($images)) {
                $firstUrl = $images[0]['url'] ?? ($images[0]['src'] ?? '');
                if ($firstUrl !== '') {
                    $updateData['main_image'] = $firstUrl;
                    $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'image', 'main_image', 'success', null, $firstUrl, 'İlk görsel main_image olarak atandı');
                }
            }

            // Sonuç kontrolü
            $hasMainImage = !empty($updateData['main_image'] ?? $currentMainImage);
            $hasImagesJson = !empty($currentImagesJson);

            if ($hasMainImage || $hasImagesJson) {
                $result->imageFilled++;
                $updateData['missing_images'] = 0;
            } else {
                $result->imageMissing++;
                $updateData['missing_images'] = 1;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'image', 'main_image', 'failed', null, null, 'Görsel bulunamadı');
            }
        } catch (\Throwable $e) {
            $result->imageMissing++;
            $updateData['missing_images'] = 1;
            $result->addWarning("Ürün #{$productId}: Görsel zenginleştirme hatası: " . $e->getMessage());
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'image', 'main_image', 'failed', null, null, $e->getMessage());
        }

        return $updateData;
    }

    /**
     * Adım 4: Fiyat normalizasyonu
     * Üzerine yazma koruması: mevcut geçerli base_price (> 0) korunur
     */
    private function stepPrice(array $product, array|false $mpProduct, int $marketplaceId, EnrichmentResult $result, array $updateData): array
    {
        $productId = (int) $product['id'];
        $currentPrice = (float) ($product['base_price'] ?? 0);

        // Mevcut geçerli fiyat varsa → skip
        if ($currentPrice > 0) {
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'price', 'base_price', 'skipped', (string) $currentPrice, null, 'Mevcut geçerli fiyat korundu');
            $result->priceNormalized++;
            $updateData['missing_price'] = 0;
            return $updateData;
        }

        try {
            $newPrice = null;

            // 1. Varyantlardan fiyat dene
            $variantPrice = $this->productModel->getVariantPrice($productId);
            if ($variantPrice !== null) {
                $newPrice = $variantPrice;
            }

            // 2. Marketplace raw data'dan fiyat dene
            if ($newPrice === null && $mpProduct !== false) {
                $lastPrice = (float) ($mpProduct['last_price_sent'] ?? 0);
                if ($lastPrice > 0) {
                    $newPrice = $lastPrice;
                }
            }

            if ($newPrice !== null && $newPrice > 0) {
                $updateData['base_price'] = $newPrice;
                $updateData['missing_price'] = 0;
                $result->priceNormalized++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'price', 'base_price', 'success', '0', (string) $newPrice, 'Fiyat normalize edildi');
            } else {
                $updateData['missing_price'] = 1;
                $result->priceIncomplete++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'price', 'base_price', 'failed', '0', null, 'Fiyat kaynağı bulunamadı');
            }
        } catch (\Throwable $e) {
            $updateData['missing_price'] = 1;
            $result->priceIncomplete++;
            $result->addWarning("Ürün #{$productId}: Fiyat normalizasyon hatası: " . $e->getMessage());
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'price', 'base_price', 'failed', null, null, $e->getMessage());
        }

        return $updateData;
    }

    /**
     * Adım 5: Stok normalizasyonu
     * Üzerine yazma koruması: mevcut geçerli stock_qty (> 0) korunur
     */
    private function stepStock(array $product, array|false $mpProduct, int $marketplaceId, EnrichmentResult $result, array $updateData): array
    {
        $productId = (int) $product['id'];
        $currentStock = (int) ($product['stock_qty'] ?? 0);

        // Mevcut geçerli stok varsa → skip
        if ($currentStock > 0) {
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'stock', 'stock_qty', 'skipped', (string) $currentStock, null, 'Mevcut geçerli stok korundu');
            $result->stockNormalized++;
            $updateData['missing_stock'] = 0;
            return $updateData;
        }

        try {
            $newStock = null;

            // Marketplace raw data'dan stok dene
            if ($mpProduct !== false) {
                $lastStock = $mpProduct['last_stock_sent'] ?? null;
                if ($lastStock !== null && (int) $lastStock > 0) {
                    $newStock = (int) $lastStock;
                }
            }

            if ($newStock !== null && $newStock > 0) {
                $updateData['stock_qty'] = $newStock;
                $updateData['missing_stock'] = 0;
                $result->stockNormalized++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'stock', 'stock_qty', 'success', '0', (string) $newStock, 'Stok normalize edildi');
            } else {
                $updateData['missing_stock'] = 1;
                $result->stockIncomplete++;
                $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'stock', 'stock_qty', 'failed', '0', null, 'Stok kaynağı bulunamadı');
            }
        } catch (\Throwable $e) {
            $updateData['missing_stock'] = 1;
            $result->stockIncomplete++;
            $result->addWarning("Ürün #{$productId}: Stok normalizasyon hatası: " . $e->getMessage());
            $this->enrichmentLogModel->logStep($productId, $marketplaceId, 'stock', 'stock_qty', 'failed', null, null, $e->getMessage());
        }

        return $updateData;
    }

    // ═══════════════════════════════════════════════════════
    // YARDIMCI METOTLAR
    // ═══════════════════════════════════════════════════════

    /**
     * Enrichment status hesapla
     * pending → henüz işlenmemiş
     * partial → bazı alanlar eksik
     * complete → tüm alanlar tamam
     */
    private function computeEnrichmentStatus(array $product): string
    {
        $hasIssue = (int) ($product['needs_category_mapping'] ?? 0) === 1
                 || (int) ($product['needs_brand_mapping'] ?? 0) === 1
                 || (int) ($product['missing_price'] ?? 0) === 1
                 || (int) ($product['missing_images'] ?? 0) === 1
                 || (int) ($product['missing_stock'] ?? 0) === 1;

        return $hasIssue ? 'partial' : 'complete';
    }

    /**
     * Eşleştirilmemiş kategorileri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnmappedCategories(int $marketplaceId): array
    {
        return $this->categoryMappingService->getUnmappedMarketplaceCategories($marketplaceId);
    }

    /**
     * Eşleştirilmemiş markaları getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnmappedBrands(int $marketplaceId): array
    {
        return $this->brandMappingService->getUnmappedMarketplaceBrands($marketplaceId);
    }

    /**
     * Eksik verili ürünleri getir (filtreli)
     *
     * @param array<string, mixed> $filters Desteklenen: missing_category, missing_brand, missing_price, missing_images, missing_stock, status
     * @return array<int, array<string, mixed>>
     */
    public function getIncompleteProducts(int $marketplaceId, array $filters = []): array
    {
        return $this->productModel->getIncompleteForEnrichment($marketplaceId, $filters);
    }

    /**
     * Kategori eşleştirmesini ilgili ürünlere toplu uygula
     *
     * @return int Güncellenen ürün sayısı
     */
    public function applyCategoryMapping(int $categoryId, int $marketplaceId, string $marketplaceCategoryId): int
    {
        $affected = $this->productModel->getProductIdsForCategoryMapping($marketplaceId, $marketplaceCategoryId);

        $count = 0;
        foreach ($affected as $row) {
            $this->productModel->update((int) $row['id'], [
                'category_id' => $categoryId,
                'needs_category_mapping' => 0,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Marka eşleştirmesini ilgili ürünlere toplu uygula
     *
     * @return int Güncellenen ürün sayısı
     */
    public function applyBrandMapping(int $brandId, int $marketplaceId, string $marketplaceBrandName): int
    {
        $affected = $this->productModel->getProductIdsForBrandMapping($marketplaceId, $marketplaceBrandName);

        $count = 0;
        foreach ($affected as $row) {
            $this->productModel->update((int) $row['id'], [
                'brand_id' => $brandId,
                'needs_brand_mapping' => 0,
            ]);
            $count++;
        }

        return $count;
    }
}
