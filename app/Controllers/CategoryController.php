<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Category;
use App\Models\Marketplace;
use App\Models\CategoryMarketplaceMapping;
use App\Models\MarketplaceCategory;
use App\Helpers\StringHelper;
use App\Services\AuthorizationService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\PazaramaService;

final class CategoryController extends BaseController
{
    /** Tam listenin en az bu oranı geldiyse listede olmayan kategoriler satışa kapalı işaretlenir. */
    private const FULL_LIST_RATIO = 0.9;
    private const UNSELLABLE_SEARCH_LIMIT = 5;

    private readonly Category $categoryModel;
    private readonly Marketplace $marketplaceModel;
    private readonly CategoryMarketplaceMapping $mappingModel;
    private readonly MarketplaceCategory $mpCatModel;

    /** @var array<int, array<int, array<string, mixed>>> marketplace_id => category_id => sorun */
    private array $unsellableCache = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->categoryModel    = $container->get(Category::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->mappingModel     = $container->get(CategoryMarketplaceMapping::class);
        $this->mpCatModel       = $container->get(MarketplaceCategory::class);
    }

    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getAll();
        $flatTree     = $this->categoryModel->getFlatTree();

        $totalCategories = $this->categoryModel->count();
        $mpStats = [];
        foreach ($marketplaces as $mp) {
            $mpId = (int)$mp['id'];
            $mpStats[$mp['slug']] = $this->mpCatModel->countByMarketplace($mpId);
        }
        $totalMapped = $this->mappingModel->countMappedCategories();

        $this->view('categories.index', [
            'pageTitle'         => 'Kategoriler',
            'marketplaces'      => $marketplaces,
            'flatTree'          => $flatTree,
            'totalCategories'   => $totalCategories,
            'totalMapped'       => $totalMapped,
            'totalUnmapped'     => $totalCategories - $totalMapped,
            'mpStats'           => $mpStats,
            'unsellableSummary' => $this->unsellableSummary($marketplaces),
            'hbCacheStatus'     => $this->hepsiburadaCacheStatus($marketplaces, $mpStats),
        ]);
    }

    public function ajaxList(Request $request): void
    {
        $parentId = $request->query('parent_id', '');
        $search   = trim((string)$request->query('search', ''));

        if ($search !== '') {
            $categories = $this->categoryModel->searchCategories($search);
            $this->json([
                'success' => true,
                'data'    => $this->enrichCategories($categories),
                'mode'    => 'search',
            ]);
        }

        if ($parentId === '' || $parentId === null) {
            $categories = $this->categoryModel->getRootCategories();
        } else {
            $categories = $this->categoryModel->getChildrenWithCounts((int)$parentId);
        }

        $this->json([
            'success' => true,
            'data'    => $this->enrichCategories($categories),
            'mode'    => 'tree',
        ]);
    }

    private function enrichCategories(array $categories): array
    {
        foreach ($categories as &$cat) {
            $mappings = $this->mappingModel->getMappingsForCategory((int)$cat['id']);
            foreach ($mappings as $i => $mapping) {
                $problem = $this->unsellableFor((int) ($mapping['marketplace_id'] ?? 0))[(int) $cat['id']] ?? null;
                $mappings[$i]['unsellable_reason'] = $problem !== null ? (string) ($problem['reason'] ?? '') : '';
            }
            $cat['mappings'] = $mappings;
            $cat['mapping_count'] = count($mappings);
        }
        return $categories;
    }

    public function syncFromMarketplace(Request $request): void
    {
        $slug = (string) $request->post('marketplace', '');
        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        $mp = $this->marketplaceModel->findBySlug($slug);
        if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
        $mpId = (int)$mp['id'];
        if (!$this->authorization()->canAccessMarketplace($mpId)) {
            $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
        }

        try {
            if ($slug === 'hepsiburada' && $this->supportsBulkCategorySync()) {
                $result = $this->syncHepsiburadaCategories($mpId);
                $this->success($result + ['message' => $this->hepsiburadaSyncMessage($result)]);
            } elseif ($slug === 'trendyol') {
                $trendyol = $this->container->get(TrendyolService::class);
                $rawCategories = $trendyol->fetchCategories();
                $flat = [];
                $trendyol->flattenCategoryTree($rawCategories, $flat);

                $saved = 0;
                $skipped = 0;

                foreach ($flat as $tCat) {
                    $name = trim($tCat['name'] ?? '');
                    $mpCatId = (string)($tCat['id'] ?? '');
                    if ($name === '' || $mpCatId === '') { $skipped++; continue; }

                    $path = $this->buildCategoryPath($flat, $tCat);
                    $parentMpId = $tCat['parent_id'] !== null ? (string)$tCat['parent_id'] : null;

                    $hasChildren = false;
                    foreach ($flat as $check) {
                        if ($check['parent_id'] !== null && (int)$check['parent_id'] === (int)$tCat['id']) {
                            $hasChildren = true;
                            break;
                        }
                    }

                    $this->mpCatModel->upsert($mpId, $mpCatId, $name, $parentMpId, $path, !$hasChildren);
                    $saved++;
                }

                $this->success([
                    'message' => "Trendyol: {$saved} kategori cache'e kaydedildi, {$skipped} atlandı (toplam " . count($flat) . ")",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($flat),
                ]);
            } elseif ($slug === 'n11') {
                $n11 = $this->container->get(N11Service::class);
                $flat = $n11->fetchCategories();

                $saved = 0;
                $skipped = 0;

                foreach ($flat as $nCat) {
                    $name = trim($nCat['name'] ?? '');
                    $mpCatId = (string)($nCat['id'] ?? '');
                    if ($name === '' || $mpCatId === '') { $skipped++; continue; }

                    $path = $this->buildCategoryPath($flat, $nCat);
                    $parentMpId = ($nCat['parent_id'] ?? null) !== null && (int)$nCat['parent_id'] !== 0
                        ? (string)$nCat['parent_id']
                        : null;

                    $hasChildren = !empty($nCat['has_children']) || $this->hasChildrenInFlat($flat, $nCat);

                    $this->mpCatModel->upsert($mpId, $mpCatId, $name, $parentMpId, $path, !$hasChildren);
                    $saved++;
                }

                $this->success([
                    'message' => "N11: {$saved} kategori cache'e kaydedildi, {$skipped} atlandı (toplam " . count($flat) . ")",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($flat),
                ]);
            } elseif ($slug === 'pazarama') {
                $pazarama = $this->container->get(PazaramaService::class);
                $rawCategories = $pazarama->fetchCategories();

                $flat = [];
                $this->flattenPazaramaCategoryTree($rawCategories, $flat);

                $saved = 0;
                $skipped = 0;

                foreach ($flat as $pCat) {
                    $name = trim($pCat['name'] ?? '');
                    $mpCatId = (string)($pCat['id'] ?? '');
                    if ($name === '' || $mpCatId === '') { $skipped++; continue; }

                    $path = $this->buildCategoryPath($flat, $pCat);
                    $parentMpId = ($pCat['parent_id'] ?? null) !== null && (int)$pCat['parent_id'] !== 0
                        ? (string)$pCat['parent_id']
                        : null;

                    $hasChildren = $this->hasChildrenInFlat($flat, $pCat);

                    $this->mpCatModel->upsert($mpId, $mpCatId, $name, $parentMpId, $path, !$hasChildren);
                    $saved++;
                }

                $this->success([
                    'message' => "Pazarama: {$saved} kategori cache'e kaydedildi, {$skipped} atlandı (toplam " . count($flat) . ")",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($flat),
                ]);
            } elseif ($slug === 'oziygo') {
                $oziygo = $this->container->get(\App\Services\Marketplace\OziygoService::class);
                $flat = $oziygo->fetchCategories();

                $saved = 0;
                $skipped = 0;
                foreach ($flat as $cat) {
                    $name = trim($cat['name'] ?? '');
                    $mpCatId = (string)($cat['id'] ?? '');
                    if ($name === '' || $mpCatId === '') { $skipped++; continue; }

                    $parentMpId = !empty($cat['parentId']) ? (string)$cat['parentId'] : null;
                    $path = !empty($cat['paths']) && is_array($cat['paths']) ? implode(' > ', $cat['paths']) : $name;
                    $this->mpCatModel->upsert($mpId, $mpCatId, $name, $parentMpId, $path, true);
                    $saved++;
                }

                $this->success([
                    'message' => "OziyGO: {$saved} kategori cache'e kaydedildi, {$skipped} atlandi",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($flat),
                ]);
            } elseif ($slug === 'ciceksepeti') {
                $ciceksepeti = $this->container->get(\App\Services\Marketplace\CiceksepetiService::class);
                $flat = $ciceksepeti->fetchCategories();

                // /Category endpoint erişilemezse (404), flat boş gelir.
                // Mevcut ürünlerden kategorileri çıkar.
                if (empty($flat)) {
                    // DB'deki ürünlerden kategorileri extract et
                    $db = $this->container->get(\App\Core\Database::class)->getConnection();
                    $stmt = $db->prepare("
                        SELECT marketplace_category_id, marketplace_category_name, COUNT(*) as cnt
                        FROM marketplace_products
                        WHERE marketplace_id = :mpId
                          AND store_id = :storeId
                          AND marketplace_category_id IS NOT NULL
                          AND marketplace_category_id != ''
                        GROUP BY marketplace_category_id, marketplace_category_name
                    ");
                    // store_id şart: filtresiz sorgu diğer bayilerin kategorilerini getiriyordu
                    $stmt->execute([':mpId' => $mpId, ':storeId' => $this->container->get(\App\Core\TenantContext::class)->currentStoreId()]);
                    $productCats = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    $saved = 0;
                    foreach ($productCats as $pc) {
                        $catId   = (string) ($pc['marketplace_category_id'] ?? '');
                        $catName = trim((string) ($pc['marketplace_category_name'] ?? ''));
                        if ($catId === '' || $catName === '') continue;

                        $this->mpCatModel->upsert($mpId, $catId, $catName, null, $catName, true);
                        $saved++;
                    }

                    if ($saved > 0) {
                        $this->success([
                            'message' => "ÇiçekSepeti /Category API erişilemedi. Mevcut ürünlerden {$saved} kategori çıkarıldı.",
                            'saved'   => $saved,
                            'skipped' => 0,
                            'total'   => $saved,
                        ]);
                    } else {
                        $this->success([
                            'message' => "ÇiçekSepeti /Category API erişilemedi. Önce ürünleri import edin — kategoriler otomatik toplanacak.",
                            'saved'   => 0,
                            'skipped' => 0,
                            'total'   => 0,
                        ]);
                    }
                    return;
                }

                $saved = 0;
                $skipped = 0;

                foreach ($flat as $csCat) {
                    $name = trim($csCat['name'] ?? '');
                    $mpCatId = (string)($csCat['id'] ?? '');
                    if ($name === '' || $mpCatId === '') { $skipped++; continue; }

                    $parentMpId = ($csCat['parent_id'] ?? null) !== null && (string)$csCat['parent_id'] !== '0' && (string)$csCat['parent_id'] !== ''
                        ? (string)$csCat['parent_id']
                        : null;

                    $path = $this->buildCategoryPath($flat, $csCat);
                    $hasChildren = $this->hasChildrenInFlat($flat, $csCat);

                    $this->mpCatModel->upsert($mpId, $mpCatId, $name, $parentMpId, $path, !$hasChildren);
                    $saved++;
                }

                $this->success([
                    'message' => "ÇiçekSepeti: {$saved} kategori cache'e kaydedildi, {$skipped} atlandı (toplam " . count($flat) . ")",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($flat),
                ]);
            } else {
                // Genel dal — Amazon, Hepsiburada, İdefix, PttAVM ve sonradan eklenecek
                // pazaryerleri. Öncesinde bu dal hata veriyordu; katalog boş kaldığı için
                // bu pazaryerlerinde kategori eşleştirmesi HİÇ yapılamıyor, dolayısıyla
                // ürün gönderimi de engelleniyordu.
                [$saved, $skipped, $total] = $this->syncCategoriesGeneric($slug, $mpId);

                $this->success([
                    'message' => sprintf(
                        '%s: %d kategori kaydedildi, %d atlandı (toplam %d)',
                        $mp['display_name'] ?? $slug,
                        $saved,
                        $skipped,
                        $total
                    ),
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => $total,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Kategori sync hatası: {$e->getMessage()}", 'CategorySync');
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    public function autoMatch(Request $request): void
    {
        $slug = $request->post('marketplace', '');

        try {
            if ($slug !== '') {
                $mp = $this->marketplaceModel->findBySlug($slug);
                if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
                if (!$this->authorization()->canAccessMarketplace((int) $mp['id'])) {
                    $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
                }
                $marketplaces = [$mp];
            } else {
                $marketplaces = $this->accessibleActiveMarketplaces();
            }

            $allCategories = $this->categoryModel->all(['is_active' => 1], 'name ASC');
            $mpResults = [];

            foreach ($marketplaces as $mp) {
                $mpResults[] = $this->autoMatchMarketplace($mp, $allCategories);
            }

            $totalMatched = array_sum(array_column($mpResults, 'matched'));
            $totalNotFound = array_sum(array_column($mpResults, 'not_found'));
            $totalFixed = array_sum(array_column($mpResults, 'fixed'));

            $message = "{$totalMatched} eşleştirildi, {$totalNotFound} bulunamadı";
            if ($totalFixed > 0) {
                $message .= " ({$totalFixed} satışa kapalı eşleşme düzeltildi)";
            }

            $this->success([
                'message'    => $message,
                'matched'    => $totalMatched,
                'not_found'  => $totalNotFound,
                'fixed'      => $totalFixed,
                'mp_results' => $mpResults,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Kategori otomatik eşleştirme hatası: {$e->getMessage()}", 'CategoryAutoMatch');
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    public function deleteAllCategories(Request $request): void
    {
        try {
            $cats = $this->categoryModel->all([], 'name ASC');
            $deleted = 0;
            $skipped = 0;

            foreach ($cats as $cat) {
                $children = $this->categoryModel->getChildren((int)$cat['id']);
                if (!empty($children)) { $skipped++; continue; }
                $productCount = $this->categoryModel->getProductCount((int)$cat['id']);
                if ($productCount > 0) { $skipped++; continue; }
                $this->categoryModel->delete((int)$cat['id']);
                $deleted++;
            }

            $this->success([
                'message' => "{$deleted} kategori silindi" . ($skipped > 0 ? " ({$skipped} atlandı)" : ''),
                'deleted' => $deleted,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    public function clearCache(Request $request): void
    {
        $slug = $request->post('marketplace', '');
        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        try {
            $mp = $this->marketplaceModel->findBySlug($slug);
            if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }

            $mpId = (int)$mp['id'];
            $this->mpCatModel->deleteByMarketplace($mpId);
            $this->mappingModel->deleteByMarketplace($mpId);

            $this->success(['message' => "{$mp['display_name']} kategori cache'i temizlendi"]);
        } catch (\Throwable $e) {
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    public function searchCache(Request $request): void
    {
        $q = trim((string)$request->query('q', ''));
        $mpId = (int)$request->query('marketplace_id', 0);

        if ($q === '' || strlen($q) < 2) {
            $this->json(['success' => true, 'data' => []]);
        }

        // Yalnız satışa uygun yaprak kategoriler seçilebilir; satışa kapalı olanlar ayrı listelenir.
        // Tek pazaryerinde kelimeler ad VE tam yol içinde aranır (kapalı listesiyle aynı kural).
        $candidates = $mpId > 0 && method_exists($this->mpCatModel, 'searchSellable')
            ? $this->mpCatModel->searchSellable($q, $mpId, 50)
            : $this->mpCatModel->searchByName($q, $mpId > 0 ? $mpId : null);
        $results = array_values(array_filter(
            $candidates,
            fn(array $row): bool => $this->isSellableRow($row)
        ));

        // En yakın aday üstte olsun — eşleştirme skorlayıcısıyla sırala
        $finder = $this->container->get(\App\Services\Mapping\MappingCandidateFinder::class);

        foreach ($results as $i => $row) {
            $results[$i]['match_score'] = $finder->confidenceFor(
                $q,
                '',
                (string) ($row['name'] ?? '')
            );
        }

        usort($results, static fn(array $a, array $b): int => $b['match_score'] <=> $a['match_score']);

        $this->json([
            'success' => true,
            'data'    => array_slice($results, 0, 50),
            'closed'  => $mpId > 0 ? $this->searchUnsellable($q, $mpId) : [],
        ]);
    }

    public function manualMap(Request $request): void
    {
        $catId = (int)$request->post('category_id', 0);
        $mpId = (int)$request->post('marketplace_id', 0);
        $mpCatId = trim((string) $request->post('mp_category_id', ''));
        $mpCatName = trim((string) $request->post('mp_category_name', ''));

        if ($catId === 0 || $mpId === 0 || $mpCatId === '') {
            $this->error('Eksik parametre');
        }
        if (!$this->authorization()->canAccessMarketplace($mpId)) {
            $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
        }

        // Önbellekteki durum: ara ya da satışa kapalı kategori reddedilir (önbellekte yoksa izin verilir).
        $status = $this->mappingModel->getMarketplaceCategoryLeafStatus($mpId, $mpCatId);
        $mpCatPath = '';
        if ($status !== null) {
            $label = $status['full_path'] !== '' ? $status['full_path'] : ($status['name'] !== '' ? $status['name'] : $mpCatId);
            if (!$status['is_leaf']) {
                $this->error('Ara kategori seçilemez, en alt kategoriyi seçin: ' . $label);
            }
            if (!($status['sellable'] ?? true)) {
                $this->error('Bu kategori pazaryerinde satışa kapalı, seçilemez: ' . $label);
            }
            $mpCatPath = $status['full_path'];
            if ($mpCatName === '') {
                $mpCatName = $status['name'];
            }
        }

        try {
            $this->mappingModel->saveMapping($catId, $mpId, $mpCatId, $mpCatName, $mpCatPath);
            $this->success(['message' => 'Eşleştirme kaydedildi']);
        } catch (\Throwable $e) {
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    public function store(Request $request): void
    {
        $name      = trim($request->post('name', ''));
        $parentId  = $request->post('parent_id', '');
        $sortOrder = (int)$request->post('sort_order', 0);
        $isActive  = (int)$request->post('is_active', 1);

        if ($name === '') { $this->error('Kategori adı zorunludur'); }

        $parentIdVal = ($parentId !== '' && $parentId !== '0') ? (int)$parentId : null;

        $id = $this->categoryModel->create([
            'name'       => $name,
            'slug'       => StringHelper::slugify($name),
            'parent_id'  => $parentIdVal,
            'sort_order' => $sortOrder,
            'is_active'  => $isActive,
        ]);

        $this->categoryModel->updateFullPath($id);
        $matchResult = $this->autoMatchCategoryDetailed($id, $name);

        $matchMsg = '';
        if ($matchResult['matched'] > 0) {
            $matchMsg = " ({$matchResult['matched']} pazaryeriyle eşleştirildi)";
        }

        $this->success([
            'id'                     => $id,
            'category_name'          => $name,
            'message'                => "'{$name}' kategorisi oluşturuldu{$matchMsg}",
            'matched_marketplaces'   => $matchResult['matched_list'],
            'unmatched_marketplaces' => $matchResult['unmatched_list'],
        ]);
    }

    /**
     * Tek bir kategoriyi tüm aktif pazaryerlerinde otomatik eşleştir (detaylı sonuç)
     */
    private function autoMatchCategoryDetailed(int $categoryId, string $name): array
    {
        $matched = 0;
        $matchedList = [];
        $unmatchedList = [];
        $marketplaces = $this->accessibleActiveMarketplaces();

        foreach ($marketplaces as $mp) {
            $mpId = (int)$mp['id'];
            $slug = $mp['slug'] ?? '';

            $existing = $this->mappingModel->findMapping($categoryId, $mpId);
            if ($existing !== false) {
                $matchedList[] = ['id' => $mpId, 'slug' => $slug, 'name' => $mp['display_name']];
                $matched++;
                continue;
            }

            $cacheCount = $this->mpCatModel->countByMarketplace($mpId);
            if ($cacheCount > 0) {
                $match = $this->findSellableExactMatch($name, $mpId);
                if ($match !== false) {
                    $this->mappingModel->saveMapping(
                        $categoryId, $mpId,
                        $match['mp_category_id'], $match['name'], $match['full_path']
                    );
                    $matchedList[] = ['id' => $mpId, 'slug' => $slug, 'name' => $mp['display_name']];
                    $matched++;
                    continue;
                }
            }

            // Cache'de bulunamadı veya cache boş
            $unmatchedList[] = [
                'id'     => $mpId,
                'slug'   => $slug,
                'name'   => $mp['display_name'],
                'reason' => $cacheCount > 0 ? 'not_found' : 'no_cache',
            ];
        }

        return [
            'matched'        => $matched,
            'matched_list'   => $matchedList,
            'unmatched_list' => $unmatchedList,
        ];
    }

    private function hasChildrenInFlat(array $flat, array $cat): bool
    {
        $catId = (int)($cat['id'] ?? 0);
        foreach ($flat as $check) {
            if (($check['parent_id'] ?? null) !== null && (int)$check['parent_id'] === $catId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pazarama kategori ağacını düzleştir (recursive)
     * Pazarama formatı: [{id, name, subCategories: [...]}]
     */
    private function flattenPazaramaCategoryTree(array $categories, array &$flat, ?int $parentId = null): void
    {
        foreach ($categories as $cat) {
            $flat[] = [
                'id'        => (int)($cat['id'] ?? $cat['categoryId'] ?? 0),
                'name'      => $cat['name'] ?? $cat['categoryName'] ?? '',
                'parent_id' => $parentId,
            ];

            $children = $cat['subCategories'] ?? $cat['children'] ?? [];
            if (!empty($children)) {
                $this->flattenPazaramaCategoryTree($children, $flat, (int)($cat['id'] ?? $cat['categoryId'] ?? 0));
            }
        }
    }

    /**
     * Pazaryerine özel dalı olmayan servisler için genel kategori senkronu.
     *
     * Servislerin fetchCategories() çıktısı düz dizidir ve anahtarlar biraz farklıdır
     * (parent_id / parentId, leaf, paths). Burada tek biçime indirgenir.
     *
     * @return array{0: int, 1: int, 2: int} [kaydedilen, atlanan, toplam]
     */
    private function syncCategoriesGeneric(string $slug, int $mpId): array
    {
        $servisler = [
            'amazon'      => \App\Services\Marketplace\AmazonService::class,
            'hepsiburada' => \App\Services\Marketplace\HepsiburadaService::class,
            'idefix'      => \App\Services\Marketplace\IdefixService::class,
            'pttavm'      => \App\Services\Marketplace\PttavmService::class,
            // WooCommerce: fetchCategories düz liste (id, parent_id '0'=kök, leaf, paths) döndürür
            'woocommerce' => \App\Services\Marketplace\WooCommerceService::class,
        ];

        $sinif = $servisler[$slug] ?? null;
        if ($sinif === null) {
            $this->error("'{$slug}' pazaryeri için kategori çekme desteklenmiyor");
        }

        /** @var object $servis */
        $servis = $this->container->get($sinif);
        if (method_exists($servis, 'loadCredentials')) {
            $servis->loadCredentials();
        }

        $flat = $servis->fetchCategories();
        if (!is_array($flat) || $flat === []) {
            $this->error('Pazaryerinden kategori listesi alınamadı (boş yanıt). API bilgilerini kontrol edin.');
        }

        // Üst kategorisi olanları indeksle → yaprak tespiti O(n)
        $cocukluOlanlar = [];
        foreach ($flat as $cat) {
            $ust = $this->normalizeParentId($cat);
            if ($ust !== null) {
                $cocukluOlanlar[$ust] = true;
            }
        }

        // Ad haritası → yol kurma (id'ler METİN olarak karşılaştırılır; Amazon'da
        // kategori kimliği "SHOES" gibi metindir, int'e çevirmek hepsini 0 yapar)
        $adHaritasi = [];
        foreach ($flat as $cat) {
            $id = trim((string) ($cat['id'] ?? ''));
            if ($id !== '') {
                $adHaritasi[$id] = [
                    'name'   => trim((string) ($cat['name'] ?? '')),
                    'parent' => $this->normalizeParentId($cat),
                ];
            }
        }

        $saved = 0;
        $skipped = 0;

        foreach ($flat as $cat) {
            $mpCatId = trim((string) ($cat['id'] ?? ''));
            $name    = trim((string) ($cat['name'] ?? ''));

            if ($mpCatId === '' || $name === '') {
                $skipped++;
                continue;
            }

            $parentMpId = $this->normalizeParentId($cat);

            // Yaprak mı: servis açıkça bildirdiyse ona uy, yoksa "çocuğu var mı" ile hesapla
            $isLeaf = array_key_exists('leaf', $cat)
                ? (bool) $cat['leaf']
                : !isset($cocukluOlanlar[$mpCatId]);

            $this->mpCatModel->upsert($mpId, $mpCatId, $name, $parentMpId, $this->buildPathFromMap($adHaritasi, $mpCatId, $cat), $isLeaf);
            $saved++;
        }

        return [$saved, $skipped, count($flat)];
    }

    /**
     * parent_id / parentId anahtarlarını tek biçime indirger; kök için null döner.
     *
     * @param array<string, mixed> $cat
     */
    private function normalizeParentId(array $cat): ?string
    {
        $ham = $cat['parent_id'] ?? $cat['parentId'] ?? null;
        if ($ham === null) {
            return null;
        }

        $ust = trim((string) $ham);

        return ($ust === '' || $ust === '0') ? null : $ust;
    }

    /**
     * Kategori yolunu üst zincirinden kurar. Servis "paths" verdiyse onu kullanır.
     *
     * @param array<string, array{name: string, parent: ?string}> $harita
     * @param array<string, mixed> $cat
     */
    private function buildPathFromMap(array $harita, string $id, array $cat): string
    {
        if (!empty($cat['paths']) && is_array($cat['paths'])) {
            return implode(' > ', array_map('strval', $cat['paths']));
        }

        $parcalar = [];
        $mevcut = $id;
        $derinlik = 0;

        while ($mevcut !== null && isset($harita[$mevcut]) && $derinlik++ < 10) {
            array_unshift($parcalar, $harita[$mevcut]['name']);
            $mevcut = $harita[$mevcut]['parent'];
        }

        return implode(' > ', $parcalar);
    }

    private function buildCategoryPath(array $flat, array $cat): string
    {
        $parts = [$cat['name']];
        $parentId = $cat['parent_id'] ?? null;
        $maxDepth = 10;

        while ($parentId !== null && $maxDepth-- > 0) {
            foreach ($flat as $p) {
                if ((int)($p['id'] ?? 0) === (int)$parentId) {
                    array_unshift($parts, $p['name']);
                    $parentId = $p['parent_id'] ?? null;
                    break;
                }
            }
        }

        return implode(' > ', $parts);
    }

    public function update(Request $request, string $id): void
    {
        $cat = $this->categoryModel->find((int)$id);
        if ($cat === false) { $this->error('Kategori bulunamadı', 404); }

        $name = trim($request->post('name', ''));
        if ($name === '') { $this->error('Kategori adı zorunludur'); }

        $parentId = $request->post('parent_id', '');
        $parentIdVal = ($parentId !== '' && $parentId !== '0') ? (int)$parentId : null;

        if ($parentIdVal === (int)$id) { $this->error('Kategori kendi altına taşınamaz'); }

        $this->categoryModel->update((int)$id, [
            'name'       => $name,
            'slug'       => StringHelper::slugify($name),
            'parent_id'  => $parentIdVal,
            'sort_order' => (int)$request->post('sort_order', $cat['sort_order'] ?? 0),
            'is_active'  => (int)$request->post('is_active', 1),
        ]);

        $this->categoryModel->updateFullPath((int)$id);
        $this->success(['message' => "'{$name}' kategorisi güncellendi"]);
    }

    public function delete(Request $request, string $id): void
    {
        $cat = $this->categoryModel->find((int)$id);
        if ($cat === false) { $this->error('Kategori bulunamadı', 404); }

        $children = $this->categoryModel->getChildren((int)$id);
        if (!empty($children)) {
            $this->error('Bu kategorinin alt kategorileri var. Önce onları silin veya taşıyın.');
        }

        $productCount = $this->categoryModel->getProductCount((int)$id);
        if ($productCount > 0) {
            $this->error("Bu kategoride {$productCount} ürün var. Önce ürünleri başka kategoriye taşıyın.");
        }

        $this->categoryModel->delete((int)$id);
        $this->success(['message' => "'{$cat['name']}' kategorisi silindi"]);
    }

    /**
     * Pazaryeri sekmesi: Belirli pazaryerindeki cache'lenmiş kategorileri listele (paginated, searchable)
     */
    public function mpCategoryList(Request $request): void
    {
        $slug = $request->query('marketplace', '');
        $search = trim((string)$request->query('search', ''));
        $page = max(1, (int)$request->query('page', 1));
        $perPage = 50;
        $leafOnly = $request->query('leaf_only', '0') === '1';

        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        $mp = $this->marketplaceModel->findBySlug($slug);
        if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
        $mpId = (int)$mp['id'];

        $result = $this->mpCatModel->paginatedWithMappingStatus($mpId, $search, $leafOnly, $page, $perPage);

        $this->json([
            'success' => true,
            'data' => $result['items'],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => (int)ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * Pazaryeri sekmesi: Ağaç yapısında listele (parent_mp_id bazlı)
     */
    public function mpCategoryTree(Request $request): void
    {
        $slug = $request->query('marketplace', '');
        $parentMpId = $request->query('parent_mp_id', null);
        $search = trim((string)$request->query('search', ''));
        $page = max(1, (int)$request->query('page', 1));

        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        $mp = $this->marketplaceModel->findBySlug($slug);
        if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
        $mpId = (int)$mp['id'];

        if ($parentMpId === '' || $parentMpId === 'null') { $parentMpId = null; }

        $result = $this->mpCatModel->treeList($mpId, $parentMpId, $search, $page);

        $this->json([
            'success'    => true,
            'data'       => $result['items'],
            'pagination' => [
                'page'        => $result['page'],
                'per_page'    => $result['per_page'],
                'total'       => $result['total'],
                'total_pages' => $result['total_pages'],
            ],
        ]);
    }

    /**
     * Pazaryeri kategorisini sisteme ekle + otomatik eşleştirme
     */
    public function addToSystem(Request $request): void
    {
        $mpCatId   = $request->post('mp_category_id', '');
        $mpId      = (int)$request->post('marketplace_id', 0);
        $catName   = trim($request->post('name', ''));

        if ($mpCatId === '' || $mpId === 0) {
            $this->error('Eksik parametreler');
        }
        if (!$this->authorization()->canAccessMarketplace($mpId)) {
            $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
        }

        // MP kategori bilgisini al
        $mpCat = $this->mpCatModel->findByMpCatId($mpId, $mpCatId);
        if ($mpCat === false) { $this->error('Pazaryeri kategorisi bulunamadı'); }
        if (!$this->isSellableRow($mpCat)) {
            $this->error((int) ($mpCat['is_leaf'] ?? 0) !== 1
                ? 'Ara kategori seçilemez. Lütfen en alt/final pazaryeri kategorisini seçin.'
                : 'Bu kategori pazaryerinde satışa kapalı, sisteme eklenemez.');
        }

        if ($catName === '') { $catName = $mpCat['name']; }

        // Zaten eşleşmiş mi kontrol et
        $existingMapping = $this->mappingModel->findByMarketplaceCategory($mpId, $mpCatId);
        if ($existingMapping !== false) {
            $this->success([
                'message' => "Bu kategori zaten eşleştirilmiş",
                'already_mapped' => true,
            ]);
        }

        // Sistem kategorisinde aynı isim var mı?
        $existingCat = $this->categoryModel->findBy(['name' => $catName]);

        if ($existingCat !== false) {
            // Mevcut sistem kategorisiyle eşleştir
            $sysCatId = (int)$existingCat['id'];
        } else {
            // Yeni sistem kategorisi oluştur
            $sysCatId = $this->categoryModel->create([
                'name'       => $catName,
                'slug'       => StringHelper::slugify($catName),
                'parent_id'  => null,
                'sort_order' => 0,
                'is_active'  => 1,
            ]);
            $this->categoryModel->updateFullPath($sysCatId);
        }

        // Eşleştirme kaydet
        $this->mappingModel->saveMapping(
            $sysCatId, $mpId, $mpCatId, $mpCat['name'], $mpCat['full_path'] ?? null
        );

        $this->success([
            'message'       => "'{$catName}' sisteme eklendi ve eşleştirildi",
            'category_id'   => $sysCatId,
            'is_new'        => ($existingCat === false),
        ]);
    }

    /**
     * Toplu: Birden fazla MP kategorisini sisteme ekle
     */
    public function bulkAddToSystem(Request $request): void
    {
        $items = $request->post('items', '');
        $mpId  = (int)$request->post('marketplace_id', 0);

        if ($mpId === 0) { $this->error('Pazaryeri belirtilmedi'); }
        if (!$this->authorization()->canAccessMarketplace($mpId)) {
            $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
        }

        // items: JSON array of mp_category_id's
        $itemIds = json_decode($items, true);
        if (!is_array($itemIds) || empty($itemIds)) {
            $this->error('Kategori seçilmedi');
        }

        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($itemIds as $mpCatId) {
            $mpCatId = (string)$mpCatId;
            $mpCat = $this->mpCatModel->findByMpCatId($mpId, $mpCatId);
            if ($mpCat === false) { $errors[] = "MP #{$mpCatId} bulunamadı"; continue; }
            if (!$this->isSellableRow($mpCat)) {
                $errors[] = (int) ($mpCat['is_leaf'] ?? 0) !== 1
                    ? "MP #{$mpCatId} ara kategori olduğu için atlandı"
                    : "MP #{$mpCatId} satışa kapalı olduğu için atlandı";
                continue;
            }

            $existingMapping = $this->mappingModel->findByMarketplaceCategory($mpId, $mpCatId);
            if ($existingMapping !== false) { $skipped++; continue; }

            $catName = $mpCat['name'];
            $existingCat = $this->categoryModel->findBy(['name' => $catName]);

            if ($existingCat !== false) {
                $sysCatId = (int)$existingCat['id'];
            } else {
                $sysCatId = $this->categoryModel->create([
                    'name'       => $catName,
                    'slug'       => StringHelper::slugify($catName),
                    'parent_id'  => null,
                    'sort_order' => 0,
                    'is_active'  => 1,
                ]);
                $this->categoryModel->updateFullPath($sysCatId);
            }

            $this->mappingModel->saveMapping(
                $sysCatId, $mpId, $mpCatId, $mpCat['name'], $mpCat['full_path'] ?? null
            );
            $added++;
        }

        $this->success([
            'message' => "{$added} kategori eklendi, {$skipped} zaten eşleşik" . (!empty($errors) ? ', ' . count($errors) . ' hata' : ''),
            'added'   => $added,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // OTOMATİK EŞLEŞTİRME
    // ═══════════════════════════════════════════════════════

    /**
     * Tek pazaryerinde ad eşleşmesiyle otomatik eşleştirme. Yalnız satışa uygun yaprak
     * kategoriler aday olur; satışa kapalı kategoriye bağlı mevcut eşleşmeler yeniden
     * eşleştirme adayıdır (bulunamazsa eski eşleşme olduğu gibi kalır).
     *
     * @param array<string, mixed> $mp
     * @param array<int, array<string, mixed>> $categories
     * @return array<string, mixed>
     */
    private function autoMatchMarketplace(array $mp, array $categories): array
    {
        $mpId = (int) $mp['id'];
        $result = [
            'slug'            => $mp['slug'] ?? '',
            'name'            => $mp['display_name'] ?? ($mp['slug'] ?? ''),
            'mp_id'           => $mpId,
            'matched'         => 0,
            'fixed'           => 0,
            'already'         => 0,
            'not_found'       => 0,
            'not_found_names' => [],
            'not_found_items' => [],
        ];

        $hasCache = $this->mpCatModel->countByMarketplace($mpId) > 0;
        $unsellable = $this->unsellableFor($mpId);

        foreach ($categories as $cat) {
            $catId = (int) $cat['id'];
            $existing = $this->mappingModel->findMapping($catId, $mpId);
            $needsFix = $existing !== false && isset($unsellable[$catId]);
            if ($existing !== false && !$needsFix) {
                $result['already']++;
                continue;
            }

            $match = $hasCache ? $this->findSellableExactMatch((string) $cat['name'], $mpId) : false;
            if ($match !== false) {
                try {
                    $this->mappingModel->saveMapping(
                        $catId, $mpId,
                        (string) $match['mp_category_id'], (string) $match['name'], $match['full_path'] ?? null
                    );
                    $result[$needsFix ? 'fixed' : 'matched']++;
                    continue;
                } catch (\Throwable $e) {
                    $this->logger->warning("Otomatik eşleştirme kaydedilemedi (kategori {$catId}, mp {$mpId}): " . $e->getMessage(), 'CategoryAutoMatch');
                }
            }

            if ($needsFix) {
                $result['already']++;
                continue;
            }

            $result['not_found']++;
            if (count($result['not_found_names']) < 10) {
                $result['not_found_names'][] = $cat['name'];
            }
            $result['not_found_items'][] = ['id' => $catId, 'name' => $cat['name']];
        }

        unset($this->unsellableCache[$mpId]);

        return $result;
    }

    // ═══════════════════════════════════════════════════════
    // HEPSİBURADA KATEGORİ SENKRONU (satış durumu ile)
    // ═══════════════════════════════════════════════════════

    /**
     * HB kategorilerini status/available bilgisiyle toplu kaydeder. Tam liste geldiyse
     * (önceki önbelleğin en az %90'ı) listede olmayan eski kategoriler satışa kapalı işaretlenir.
     *
     * @return array{saved: int, skipped: int, total: int, closed: int, has_availability: bool, partial: bool}
     */
    private function syncHepsiburadaCategories(int $mpId): array
    {
        @set_time_limit(0);

        $service = $this->container->get(HepsiburadaService::class);
        $service->loadCredentials();
        $flat = $service->fetchCategories();
        if (!is_array($flat) || $flat === []) {
            $this->error('Hepsiburada kategori listesi alınamadı (boş yanıt). API bilgilerini kontrol edin.');
        }

        // Çok sayfalı çekimde MySQL bağlantısı zaman aşımına düşmüş olabilir.
        $this->db()->reconnect();

        $withChildren = [];
        $pathMap = [];
        foreach ($flat as $cat) {
            $parent = $this->normalizeParentId($cat);
            if ($parent !== null) {
                $withChildren[$parent] = true;
            }
            $id = trim((string) ($cat['id'] ?? ''));
            if ($id !== '') {
                $pathMap[$id] = ['name' => trim((string) ($cat['name'] ?? '')), 'parent' => $parent];
            }
        }

        $rows = [];
        $skipped = 0;
        $hasAvailability = false;
        foreach ($flat as $cat) {
            $id = trim((string) ($cat['id'] ?? ''));
            $name = trim((string) ($cat['name'] ?? ''));
            if ($id === '' || $name === '') {
                $skipped++;
                continue;
            }

            $status = $this->normalizeCategoryStatus($cat['status'] ?? null);
            $available = $this->normalizeAvailableFlag($cat['available'] ?? null);
            if ($status !== null || $available !== null) {
                $hasAvailability = true;
            }

            $rows[] = [
                'mp_category_id' => $id,
                'name'           => $name,
                'parent_mp_id'   => $this->normalizeParentId($cat),
                'full_path'      => $this->buildPathFromMap($pathMap, $id, $cat),
                'is_leaf'        => array_key_exists('leaf', $cat) ? (bool) $cat['leaf'] : !isset($withChildren[$id]),
                'is_active'      => $status === null ? null : $status === 'ACTIVE',
                'is_available'   => $available,
            ];
        }

        $columnsReady = $this->availabilityColumnsReady();
        // Oran yalnız AÇIK satırlara göre: önceden kapatılmış eski satırlar tam listeyi "eksik" göstermesin.
        $previousCount = $this->mpCatModel->countOpenByMarketplace($mpId);
        $syncStartedAt = $this->syncClockStart();
        $saved = $this->mpCatModel->bulkUpsert($mpId, $rows);

        $fullList = $saved >= (int) floor($previousCount * self::FULL_LIST_RATIO)
            // Sayfalama bütçesi (sayfa/süre) dolup liste yarım kaldıysa listede olmayanlar kapatılmaz.
            && (!method_exists($service, 'lastCategoryFetchComplete') || $service->lastCategoryFetchComplete());
        $closed = 0;
        if ($columnsReady && $hasAvailability && $fullList) {
            $closed = $this->mpCatModel->markUnavailableSyncedBefore($mpId, $syncStartedAt);
            if ($closed > 0) {
                $this->logger->info("Kategori senkronu: listede olmayan {$closed} kategori satışa kapalı işaretlendi (mp={$mpId})", 'CategorySync');
            }
        }

        unset($this->unsellableCache[$mpId]);

        return [
            'saved'            => $saved,
            'skipped'          => $skipped,
            'total'            => count($flat),
            'closed'           => $closed,
            'has_availability' => $hasAvailability,
            'columns_ready'    => $columnsReady,
            'partial'          => $columnsReady && $hasAvailability && !$fullList,
        ];
    }

    /**
     * @param array{saved: int, skipped: int, total: int, closed: int, has_availability: bool, columns_ready: bool, partial: bool} $result
     */
    private function hepsiburadaSyncMessage(array $result): string
    {
        $message = sprintf(
            'Hepsiburada: %d kategori kaydedildi, %d atlandı (toplam %d)',
            $result['saved'],
            $result['skipped'],
            $result['total']
        );

        if ($result['closed'] > 0) {
            $message .= sprintf('; listede olmayan %d kategori satışa kapalı işaretlendi', $result['closed']);
        }
        if (!$result['columns_ready']) {
            $message .= '. Satış durumu kaydedilmedi: veritabanı güncellemesi (052) henüz uygulanmamış';
        } elseif (!$result['has_availability']) {
            $message .= '. Hepsiburada satış durumu bilgisi göndermedi';
        } elseif ($result['partial']) {
            $message .= '. Liste eksik geldiği için eski kategoriler kapatılmadı';
        }

        return $message . '.';
    }

    /**
     * Toplu kategori yazımı (bulkUpsert) ve satışa kapalı işaretleme yardımcıları mevcut mu.
     */
    private function supportsBulkCategorySync(): bool
    {
        return method_exists($this->mpCatModel, 'bulkUpsert')
            && method_exists($this->mpCatModel, 'markUnavailableSyncedBefore');
    }

    /**
     * Satış durumu kolonları (migration phase12/052) şemada var mı.
     */
    private function availabilityColumnsReady(): bool
    {
        return method_exists($this->mpCatModel, 'hasAvailabilityColumns') && $this->mpCatModel->hasAvailabilityColumns();
    }

    /**
     * Senkron başlangıç zamanı: PHP ve veritabanı saatlerinden erken olanı. synced_at hangi saatle
     * yazılırsa yazılsın bu turda kaydedilen satırlar asla "eski" sayılmaz.
     */
    private function syncClockStart(): string
    {
        $phpNow = date('Y-m-d H:i:s');
        try {
            $row = $this->db()->fetchOne('SELECT NOW() AS db_now');
            $dbNow = $row !== false ? (string) ($row['db_now'] ?? '') : '';
        } catch (\Throwable) {
            $dbNow = '';
        }

        return ($dbNow !== '' && $dbNow < $phpNow) ? $dbNow : $phpNow;
    }

    private function normalizeCategoryStatus(mixed $status): ?string
    {
        if (!is_string($status) || trim($status) === '') {
            return null;
        }

        return strtoupper(trim($status));
    }

    private function normalizeAvailableFlag(mixed $available): ?bool
    {
        if (is_bool($available)) {
            return $available;
        }
        if (in_array($available, [1, '1', 'true', 'TRUE'], true)) {
            return true;
        }
        if (in_array($available, [0, '0', 'false', 'FALSE'], true)) {
            return false;
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════
    // SATIŞA UYGUNLUK (is_leaf + is_active + is_available)
    // ═══════════════════════════════════════════════════════

    /**
     * Önbellek satırı satışa uygun yaprak mı? Durum bilgisi NULL ise uygun sayılır.
     *
     * @param array<string, mixed> $row
     */
    private function isSellableRow(array $row): bool
    {
        if ((int) ($row['is_leaf'] ?? 0) !== 1) {
            return false;
        }

        foreach (['is_active', 'is_available'] as $flag) {
            if (isset($row[$flag]) && (int) $row[$flag] === 0) {
                return false;
            }
        }

        return true;
    }

    private function sellableCondition(string $alias): string
    {
        if (method_exists($this->mpCatModel, 'sellableCondition')) {
            return $this->mpCatModel->sellableCondition($alias);
        }

        return ($alias !== '' ? $alias . '.' : '') . 'is_leaf = 1';
    }

    /**
     * Ad eşleşmesi (büyük/küçük harf duyarsız) — yalnız satışa uygun yaprak, en kısa yol önce.
     *
     * @return array<string, mixed>|false
     */
    private function findSellableExactMatch(string $name, int $mpId): array|false
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        [$scope, $scopeParams] = $this->mpCatModel->scopeFilter('mc');

        return $this->db()->fetchOne(
            "SELECT mc.* FROM marketplace_categories mc
             WHERE mc.marketplace_id = :mid AND {$scope} AND LOWER(mc.name) = LOWER(:name) AND " . $this->sellableCondition('mc') . "
             ORDER BY CHAR_LENGTH(mc.full_path) ASC
             LIMIT 1",
            ['mid' => $mpId, 'name' => $name] + $scopeParams
        );
    }

    /**
     * Aramaya uyan ama seçilemeyen (satışa kapalı / pasif / ara) kategoriler.
     *
     * @return array<int, array{mp_category_id: string, name: string, full_path: string, reason: string}>
     */
    private function searchUnsellable(string $query, int $mpId): array
    {
        if (!method_exists($this->mpCatModel, 'hasAvailabilityData') || !$this->mpCatModel->hasAvailabilityData($mpId)) {
            return [];
        }

        [$scope, $scopeParams] = $this->mpCatModel->scopeFilter('mc');
        $conditions = [];
        $params = ['mid' => $mpId] + $scopeParams;
        foreach ($this->searchWords($query) as $i => $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $conditions[] = "(mc.name LIKE :n{$i} OR mc.full_path LIKE :p{$i})";
            $params["n{$i}"] = $like;
            $params["p{$i}"] = $like;
        }
        if ($conditions === []) {
            return [];
        }

        try {
            $rows = $this->db()->fetchAll(
                "SELECT mc.mp_category_id, mc.name, mc.full_path, mc.is_leaf, mc.is_active, mc.is_available
                 FROM marketplace_categories mc
                 WHERE mc.marketplace_id = :mid AND {$scope} AND " . implode(' AND ', $conditions) . "
                   AND NOT (" . $this->sellableCondition('mc') . ")
                 ORDER BY CHAR_LENGTH(mc.full_path) ASC
                 LIMIT " . self::UNSELLABLE_SEARCH_LIMIT,
                $params
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Satışa kapalı kategori araması başarısız: ' . $e->getMessage(), 'CategorySearch');
            return [];
        }

        return array_map(static fn(array $row): array => [
            'mp_category_id' => (string) $row['mp_category_id'],
            'name'           => (string) $row['name'],
            'full_path'      => (string) ($row['full_path'] ?? ''),
            'reason'         => match (true) {
                (int) ($row['is_leaf'] ?? 0) !== 1                          => 'Ara kategori',
                $row['is_active'] !== null && (int) $row['is_active'] === 0 => 'Pasif — satışa kapalı',
                default                                                     => 'Satışa kapalı',
            },
        ], $rows);
    }

    /**
     * Arama cümlesinden anlamlı kelimeler (en fazla 6); kelime yoksa cümlenin tamamı.
     *
     * @return array<int, string>
     */
    private function searchWords(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_unique(array_filter($parts, static fn(string $w): bool => mb_strlen($w) > 2)));

        if ($words === []) {
            return trim($query) === '' ? [] : [trim($query)];
        }

        return array_slice($words, 0, 6);
    }

    /**
     * Satışa uygun OLMAYAN kategoriye bağlı eşleşmeler (istek içinde önbellekli).
     *
     * @return array<int, array<string, mixed>> category_id => {reason, mp_category_id, path}
     */
    private function unsellableFor(int $mpId): array
    {
        if ($mpId <= 0) {
            return [];
        }
        if (isset($this->unsellableCache[$mpId])) {
            return $this->unsellableCache[$mpId];
        }

        $result = [];
        if (method_exists($this->mpCatModel, 'findUnsellableMappings')) {
            try {
                $result = $this->mpCatModel->findUnsellableMappings(
                    $mpId,
                    $this->container->get(TenantContext::class)->currentStoreId()
                );
            } catch (\Throwable $e) {
                $this->logger->warning("Satışa kapalı eşleşmeler okunamadı (mp={$mpId}): " . $e->getMessage(), 'CategoryMapping');
            }
        }

        return $this->unsellableCache[$mpId] = $result;
    }

    /**
     * Sayfa üstü uyarısı için pazaryeri başına geçersiz eşleşme sayısı.
     *
     * @param array<int, array<string, mixed>> $marketplaces
     * @return array<int, array{id: int, slug: string, name: string, count: int}>
     */
    private function unsellableSummary(array $marketplaces): array
    {
        $authorization = $this->authorization();
        $summary = [];
        foreach ($marketplaces as $mp) {
            $mpId = (int) $mp['id'];
            if (!$authorization->canAccessMarketplace($mpId)) {
                continue;
            }
            $count = count($this->unsellableFor($mpId));
            if ($count > 0) {
                $summary[] = [
                    'id'    => $mpId,
                    'slug'  => (string) ($mp['slug'] ?? ''),
                    'name'  => (string) ($mp['display_name'] ?? $mp['slug'] ?? ''),
                    'count' => $count,
                ];
            }
        }

        return $summary;
    }

    /**
     * HB kategori önbelleği satış durumu bilgisi taşıyor mu? Taşımıyorsa yenileme önerilir.
     *
     * @param array<int, array<string, mixed>> $marketplaces
     * @param array<string, int> $mpStats
     * @return array{id: int, name: string, cache_count: int, stale: bool}|null
     */
    private function hepsiburadaCacheStatus(array $marketplaces, array $mpStats): ?array
    {
        foreach ($marketplaces as $mp) {
            if (($mp['slug'] ?? '') !== 'hepsiburada') {
                continue;
            }
            $mpId = (int) $mp['id'];
            if (!$this->authorization()->canAccessMarketplace($mpId)) {
                return null;
            }

            $cacheCount = (int) ($mpStats['hepsiburada'] ?? 0);
            $stale = false;
            if ($cacheCount > 0 && $this->supportsBulkCategorySync() && $this->availabilityColumnsReady()
                && method_exists($this->mpCatModel, 'hasAvailabilityData')) {
                try {
                    $stale = !$this->mpCatModel->hasAvailabilityData($mpId);
                } catch (\Throwable) {
                    $stale = false;
                }
            }

            return [
                'id'          => $mpId,
                'name'        => (string) ($mp['display_name'] ?? 'Hepsiburada'),
                'cache_count' => $cacheCount,
                'stale'       => $stale,
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accessibleActiveMarketplaces(): array
    {
        $authorization = $this->authorization();

        return array_values(array_filter(
            $this->marketplaceModel->getActive(),
            static fn(array $mp): bool => $authorization->canAccessMarketplace((int) $mp['id'])
        ));
    }

    private function authorization(): AuthorizationService
    {
        return $this->container->get(AuthorizationService::class);
    }

    private function db(): Database
    {
        return $this->container->get(Database::class);
    }
}
