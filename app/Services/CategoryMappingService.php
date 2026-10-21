<?php
/**
 * OzyConnect Panel — CategoryMappingService
 * İç kategori ↔ pazaryeri kategorisi eşleştirme servisi
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
use App\Models\Category;
use App\Models\CategoryMarketplaceMapping;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\OziygoService;

final class CategoryMappingService
{
    public function __construct(
        private readonly CategoryMarketplaceMapping $mappingModel,
        private readonly Category $categoryModel,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly PazaramaService $pazaramaService,
        private readonly OziygoService $oziygoService,
        private readonly Logger $logger,
    ) {}

    /**
     * Pazaryeri kategori ID'sinden iç kategori ID'sini çöz
     *
     * @return int|null İç kategori ID veya null (eşleşme yoksa)
     */
    public function resolveCategory(string $marketplaceCategoryId, int $marketplaceId): ?int
    {
        $mapping = $this->mappingModel->findByMarketplaceCategory($marketplaceId, $marketplaceCategoryId);

        if ($mapping === false) {
            return null;
        }

        return (int) $mapping['category_id'];
    }

    /**
     * Trendyol kategori ağacını çek (AJAX arama için)
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchMarketplaceCategories(int $marketplaceId, string $query = '', string $slug = 'trendyol'): array
    {
        try {
            $categories = match ($slug) {
                'trendyol'    => $this->trendyolService->fetchCategories(),
                'n11'         => $this->n11Service->fetchCategories(),
                'hepsiburada' => $this->hepsiburadaService->fetchCategories(),
                'pazarama'    => $this->pazaramaService->fetchCategories(),
                'oziygo'      => $this->oziygoService->fetchCategories(),
                default       => [],
            };
            return $this->flattenCategories($categories, $query);
        } catch (\Throwable $e) {
            $this->logger->error("Kategori arama hatası: " . $e->getMessage(), 'CategoryMapping');
            return [];
        }
    }

    /**
     * Eşleştirilmemiş kategorilere sahip ürün sayısını getir
     */
    public function getUnmappedProductCount(int $marketplaceId): int
    {
        return $this->mappingModel->getUnmappedCategoryCount($marketplaceId);
    }

    /**
     * Tüm iç kategorileri eşleştirme durumuyla getir
     */
    public function getCategoriesWithMappingStatus(int $marketplaceId): array
    {
        return $this->mappingModel->getCategoriesWithStatus($marketplaceId);
    }

    // ═══════════════════════════════════════════════════════
    // ENRICHMENT MATCHING (Phase 7 — Post-Import Enrichment)
    // ═══════════════════════════════════════════════════════

    /**
     * Gelişmiş kategori eşleştirme — kademeli çözümleme
     *
     * Sıra:
     *   1. marketplace_category_id ile mapping tablosu
     *   2. Normalize edilmiş isim ile iç kategoriler
     *   3. Fuzzy match (similar_text >= %85)
     *
     * @return array{category_id: int|null, method: string, confidence: int}
     */
    public function resolveWithFuzzy(string $marketplaceCategoryId, ?string $marketplaceCategoryName, int $marketplaceId): array
    {
        // 1. Mapping tablosu — marketplace_category_id ile
        if ($marketplaceCategoryId !== '') {
            $mapping = $this->mappingModel->findByMarketplaceCategory($marketplaceId, $marketplaceCategoryId);
            if ($mapping !== false) {
                return ['category_id' => (int) $mapping['category_id'], 'method' => 'mapping_id', 'confidence' => 100];
            }
        }

        // 2. Normalize edilmiş isim ile iç kategoriler
        if ($marketplaceCategoryName !== null && $marketplaceCategoryName !== '') {
            $normalized = StringHelper::normalize($marketplaceCategoryName);
            $match = $this->findCategoryByNormalizedName($normalized);
            if ($match !== null) {
                return ['category_id' => $match, 'method' => 'normalized_name', 'confidence' => 95];
            }

            // 3. Fuzzy match
            $fuzzyMatch = $this->fuzzyMatchCategory($marketplaceCategoryName);
            if ($fuzzyMatch !== null) {
                return ['category_id' => $fuzzyMatch['id'], 'method' => 'fuzzy', 'confidence' => $fuzzyMatch['score']];
            }
        }

        return ['category_id' => null, 'method' => 'none', 'confidence' => 0];
    }

    /**
     * Fuzzy kategori eşleştirme (similar_text >= %85)
     *
     * @return array{id: int, name: string, score: int}|null
     */
    public function fuzzyMatchCategory(string $categoryName): ?array
    {
        $normalized = StringHelper::normalize($categoryName);
        if ($normalized === '') {
            return null;
        }

        $categories = $this->categoryModel->all(['is_active' => 1], 'name ASC');
        $bestMatch = null;
        $bestScore = 0;

        foreach ($categories as $cat) {
            $catNormalized = StringHelper::normalize($cat['name'] ?? '');
            if ($catNormalized === '') {
                continue;
            }

            similar_text($normalized, $catNormalized, $percent);
            $score = (int) round($percent);

            if ($score >= 85 && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'id'    => (int) $cat['id'],
                    'name'  => $cat['name'],
                    'score' => $score,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Eşleştirilmemiş pazaryeri kategorilerini getir (enrichment dashboard için)
     *
     * @return array<int, array{marketplace_category_name: string, product_count: int}>
     */
    public function getUnmappedMarketplaceCategories(int $marketplaceId): array
    {
        return $this->mappingModel->getUnmappedMarketplaceCategories($marketplaceId);
    }

    /**
     * Normalize edilmiş isimle iç kategori bul
     */
    private function findCategoryByNormalizedName(string $normalizedName): ?int
    {
        if ($normalizedName === '') {
            return null;
        }

        $categories = $this->categoryModel->all(['is_active' => 1]);

        foreach ($categories as $cat) {
            $catNormalized = StringHelper::normalize($cat['name'] ?? '');
            if ($catNormalized === $normalizedName) {
                return (int) $cat['id'];
            }
        }

        return null;
    }

    /**
     * Kategori ağacını düzleştir ve opsiyonel arama filtresi uygula
     *
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    private function flattenCategories(array $categories, string $query = '', string $parentPath = ''): array
    {
        $result = [];
        $queryLower = mb_strtolower($query);

        foreach ($categories as $cat) {
            $name = $cat['name'] ?? '';
            $path = $parentPath !== '' ? "{$parentPath} > {$name}" : $name;
            $hasChildren = !empty($cat['subCategories']);

            $match = $query === '' || str_contains(mb_strtolower($name), $queryLower)
                                   || str_contains(mb_strtolower($path), $queryLower);

            if ($match && !$hasChildren) {
                $result[] = [
                    'id'   => $cat['id'] ?? 0,
                    'name' => $name,
                    'path' => $path,
                ];
            }

            // Alt kategorileri de tara
            if ($hasChildren) {
                $subResults = $this->flattenCategories($cat['subCategories'], $query, $path);
                $result = array_merge($result, $subResults);
            }
        }

        return $result;
    }
}
