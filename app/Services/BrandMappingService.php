<?php
/**
 * OzyConnect Panel — BrandMappingService
 * İç marka ↔ pazaryeri markası eşleştirme servisi
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

use App\Core\Logger;
use App\Helpers\StringHelper;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\BrandMarketplaceMapping;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\OziygoService;

final class BrandMappingService
{
    public function __construct(
        private readonly BrandMarketplaceMapping $mappingModel,
        private readonly Brand $brandModel,
        private readonly BrandAlias $brandAliasModel,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly PazaramaService $pazaramaService,
        private readonly OziygoService $oziygoService,
        private readonly Logger $logger,
    ) {}

    /**
     * Pazaryeri marka adından iç marka ID'sini çöz
     * Önce mapping tablosunu kontrol eder, yoksa Brand.findOrCreate ile oluşturur
     *
     * @return int Brand ID (her zaman döner)
     */
    public function resolveBrand(string $marketplaceBrandName, int $marketplaceId): int
    {
        if ($marketplaceBrandName === '') {
            return $this->brandModel->findOrCreate('Belirtilmemiş');
        }

        // Önce iç markayı bul veya oluştur
        $brandId = $this->brandModel->findOrCreate($marketplaceBrandName);

        // Mapping tablosunda kayıt var mı kontrol et, yoksa oluştur
        $mapping = $this->mappingModel->findMapping($brandId, $marketplaceId);
        if ($mapping === false) {
            // Otomatik mapping oluştur (marka adı aynı olduğu için)
            $this->mappingModel->saveMapping(
                $brandId,
                $marketplaceId,
                $marketplaceBrandName, // marketplace_brand_id olarak marka adını kullan
                $marketplaceBrandName
            );
        }

        return $brandId;
    }

    /**
     * Trendyol marka arama (AJAX autocomplete için)
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function searchMarketplaceBrands(int $marketplaceId, string $query, string $slug = 'trendyol'): array
    {
        try {
            $brands = match ($slug) {
                'trendyol'    => $this->trendyolService->fetchBrands($query),
                'n11'         => $this->n11Service->fetchBrands($query),
                'hepsiburada' => $this->hepsiburadaService->fetchBrands($query),
                'pazarama'    => $this->pazaramaService->fetchBrands($query),
                'oziygo'      => $this->oziygoService->fetchBrands($query),
                default       => [],
            };

            $result = [];
            foreach ($brands as $brand) {
                $result[] = [
                    'id'   => (int) ($brand['id'] ?? 0),
                    'name' => $brand['name'] ?? '',
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Marka arama hatası: " . $e->getMessage(), 'BrandMapping');
            return [];
        }
    }

    /**
     * Eşleştirilmemiş markalara sahip ürün sayısını getir
     */
    public function getUnmappedProductCount(int $marketplaceId): int
    {
        return $this->mappingModel->getUnmappedBrandCount($marketplaceId);
    }

    /**
     * Tüm iç markaları eşleştirme durumuyla getir
     */
    /**
     * Bu pazaryerinde HENUZ eslesmemis markalar (toplu atama icin).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnmappedBrands(int $marketplaceId): array
    {
        return array_values(array_filter(
            $this->mappingModel->getBrandsWithStatus($marketplaceId),
            static fn(array $b): bool => empty($b['mapping_id'])
        ));
    }

    public function getBrandsWithMappingStatus(int $marketplaceId): array
    {
        return $this->mappingModel->getBrandsWithStatus($marketplaceId);
    }

    // ═══════════════════════════════════════════════════════
    // ENRICHMENT MATCHING (Phase 7 — Post-Import Enrichment)
    // ═══════════════════════════════════════════════════════

    /**
     * Gelişmiş marka eşleştirme — kademeli çözümleme
     *
     * Sıra:
     *   1. brand_marketplace_mapping tablosu
     *   2. brands tablosunda exact match (case-insensitive)
     *   3. Normalize edilmiş isim ile brands tablosu
     *   4. brand_aliases tablosunda alias eşleştirme
     *   5. Fuzzy match (similar_text >= %85)
     *
     * @return array{brand_id: int|null, method: string, confidence: int}
     */
    public function resolveWithFuzzy(string $marketplaceBrandName, int $marketplaceId): array
    {
        if ($marketplaceBrandName === '') {
            return ['brand_id' => null, 'method' => 'none', 'confidence' => 0];
        }

        // 1. Mapping tablosu
        $allMappings = $this->mappingModel->getAllByMarketplace($marketplaceId);
        foreach ($allMappings as $m) {
            if (mb_strtolower($m['marketplace_brand_name'] ?? '') === mb_strtolower($marketplaceBrandName)) {
                return ['brand_id' => (int) $m['brand_id'], 'method' => 'mapping', 'confidence' => 100];
            }
        }

        // 2. Exact match (case-insensitive) — Brand::findByName zaten case-insensitive
        $exactMatch = $this->brandModel->findByName($marketplaceBrandName);
        if ($exactMatch !== false) {
            return ['brand_id' => (int) $exactMatch['id'], 'method' => 'exact_name', 'confidence' => 100];
        }

        // 3. Normalize edilmiş isim
        $normalized = StringHelper::normalize($marketplaceBrandName);
        $normalizedMatch = $this->findBrandByNormalizedName($normalized);
        if ($normalizedMatch !== null) {
            return ['brand_id' => $normalizedMatch, 'method' => 'normalized_name', 'confidence' => 95];
        }

        // 4. Alias eşleştirme
        $aliasMatch = $this->matchByAlias($marketplaceBrandName);
        if ($aliasMatch !== null) {
            return ['brand_id' => $aliasMatch, 'method' => 'alias', 'confidence' => 90];
        }

        // 5. Fuzzy match
        $fuzzyMatch = $this->fuzzyMatchBrand($marketplaceBrandName);
        if ($fuzzyMatch !== null) {
            return ['brand_id' => $fuzzyMatch['id'], 'method' => 'fuzzy', 'confidence' => $fuzzyMatch['score']];
        }

        return ['brand_id' => null, 'method' => 'none', 'confidence' => 0];
    }

    /**
     * Alias tablosundan marka eşleştir
     *
     * @return int|null Brand ID veya null
     */
    public function matchByAlias(string $brandName): ?int
    {
        $normalized = StringHelper::normalize($brandName);
        if ($normalized === '') {
            return null;
        }

        $alias = $this->brandAliasModel->findByNormalizedName($normalized);
        if ($alias !== false) {
            return (int) $alias['brand_id'];
        }

        return null;
    }

    /**
     * Fuzzy marka eşleştirme (similar_text >= %85)
     *
     * @return array{id: int, name: string, score: int}|null
     */
    public function fuzzyMatchBrand(string $brandName): ?array
    {
        $normalized = StringHelper::normalize($brandName);
        if ($normalized === '') {
            return null;
        }

        $brands = $this->brandModel->all(['is_active' => 1], 'name ASC');
        $bestMatch = null;
        $bestScore = 0;

        foreach ($brands as $brand) {
            $brandNormalized = StringHelper::normalize($brand['name'] ?? '');
            if ($brandNormalized === '') {
                continue;
            }

            similar_text($normalized, $brandNormalized, $percent);
            $score = (int) round($percent);

            if ($score >= 85 && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'id'    => (int) $brand['id'],
                    'name'  => $brand['name'],
                    'score' => $score,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Eşleştirilmemiş pazaryeri markalarını getir (enrichment dashboard için)
     *
     * @return array<int, array{brand_name: string, product_count: int}>
     */
    public function getUnmappedMarketplaceBrands(int $marketplaceId): array
    {
        return $this->mappingModel->getUnmappedMarketplaceBrands($marketplaceId);
    }

    /**
     * Normalize edilmiş isimle iç marka bul
     */
    private function findBrandByNormalizedName(string $normalizedName): ?int
    {
        if ($normalizedName === '') {
            return null;
        }

        $brands = $this->brandModel->all(['is_active' => 1]);

        foreach ($brands as $brand) {
            $brandNormalized = StringHelper::normalize($brand['name'] ?? '');
            if ($brandNormalized === $normalizedName) {
                return (int) $brand['id'];
            }
        }

        return null;
    }
}
