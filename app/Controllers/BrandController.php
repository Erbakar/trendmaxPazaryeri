<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Brand;
use App\Models\Marketplace;
use App\Models\BrandMarketplaceMapping;
use App\Models\MarketplaceBrand;
use App\Helpers\StringHelper;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;

final class BrandController extends BaseController
{
    private readonly Brand $brandModel;
    private readonly Marketplace $marketplaceModel;
    private readonly BrandMarketplaceMapping $mappingModel;
    private readonly MarketplaceBrand $mpBrandModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->brandModel       = $container->get(Brand::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->mappingModel     = $container->get(BrandMarketplaceMapping::class);
        $this->mpBrandModel     = $container->get(MarketplaceBrand::class);
    }

    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getAll();

        $mpStats = [];
        foreach ($marketplaces as $mp) {
            $mpStats[$mp['slug']] = $this->mpBrandModel->countByMarketplace((int)$mp['id']);
        }

        // Özet kart sayıları (brands.html mockup)
        $totalBrands  = $this->brandModel->count();
        $mappedBrands = $this->mappingModel->countMappedBrands();

        $this->view('brands.index', [
            'pageTitle'       => 'Markalar',
            'marketplaces'    => $marketplaces,
            'mpStats'         => $mpStats,
            'totalBrands'     => $totalBrands,
            'brandedProducts' => $this->brandModel->getTotalProductCount(),
            'mappedBrands'    => $mappedBrands,
            'unmatchedBrands' => max(0, $totalBrands - $mappedBrands),
            'inactiveBrands'  => $this->brandModel->count(['is_active' => 0]),
        ]);
    }

    /**
     * AJAX — Markalar listesi (server-side DataTables)
     */
    public function ajaxList(Request $request): void
    {
        $draw   = (int)$request->query('draw', 1);
        $start  = (int)$request->query('start', 0);
        $length = (int)$request->query('length', 25);
        $searchArr = $request->query('search');
        $search = is_array($searchArr) ? ($searchArr['value'] ?? '') : '';

        $totalRecords = $this->brandModel->count();

        $whereParts = [];
        $params = [];
        if ($search !== '') {
            $whereParts[] = "b.name LIKE :s";
            $params['s'] = "%{$search}%";
        }
        $where = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

        $orderArr = $request->query('order');
        $orderCol = is_array($orderArr) ? (int)($orderArr[0]['column'] ?? 1) : 1;
        $orderDir = is_array($orderArr) ? (strtoupper($orderArr[0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC') : 'ASC';
        $orderMap = [0 => 'b.id', 1 => 'b.name', 2 => 'product_count', 3 => 'mapping_count', 4 => 'b.is_active', 5 => 'b.created_at'];
        $orderBy = ($orderMap[$orderCol] ?? 'b.name') . ' ' . $orderDir;

        $brands = $this->brandModel->getBrandsWithMappings($where, $params, $orderBy, $length, $start);
        $filteredRecords = $this->brandModel->countBrandsFiltered($where, $params);

        // Batch load all mappings in one query instead of N+1
        $brandIds = array_column($brands, 'id');
        $allMappings = [];
        if (!empty($brandIds)) {
            $allMappings = $this->mappingModel->getMappingsForBrands($brandIds);
        }
        foreach ($brands as &$brand) {
            $brand['mappings'] = $allMappings[(int)$brand['id']] ?? [];
        }

        $this->json([
            'draw'            => $draw,
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data'            => $brands,
        ]);
    }

    /**
     * Pazaryerinden markaları çek → Cache'e kaydet (AJAX)
     */
    public function syncFromMarketplace(Request $request): void
    {
        $slug = $request->post('marketplace', '');
        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        $mp = $this->marketplaceModel->findBySlug($slug);
        if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
        $mpId = (int)$mp['id'];

        try {
            if ($slug === 'trendyol') {
                $trendyol = $this->container->get(TrendyolService::class);
                $rawBrands = $trendyol->fetchBrands();

                // Toplu upsert için normalize et
                $rows = [];
                $skipped = 0;
                foreach ($rawBrands as $tBrand) {
                    $name = trim($tBrand['name'] ?? '');
                    $mpBrandId = (string)($tBrand['id'] ?? '');
                    if ($name === '' || $mpBrandId === '') { $skipped++; continue; }
                    $rows[] = ['mp_brand_id' => $mpBrandId, 'name' => $name];
                }

                $saved = $this->mpBrandModel->bulkUpsert($mpId, $rows);

                $this->success([
                    'message' => "Trendyol: {$saved} marka cache'e kaydedildi, {$skipped} atlandı (toplam " . count($rawBrands) . ")",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($rawBrands),
                ]);
            } elseif ($slug === 'n11') {
                $n11 = $this->container->get(N11Service::class);
                $rawBrands = $n11->fetchBrands();

                $saved = 0;
                $skipped = 0;

                foreach ($rawBrands as $nBrand) {
                    $name = trim($nBrand['name'] ?? '');
                    $mpBrandId = (string)($nBrand['id'] ?? '');
                    if ($name === '' || $mpBrandId === '') { $skipped++; continue; }

                    $this->mpBrandModel->upsert($mpId, $mpBrandId, $name);
                    $saved++;
                }

                // N11 ürünlerindeki marketplace_brand_name alanını da güncelle
                $this->updateN11ProductBrands($mpId);

                $this->success([
                    'message' => "N11: {$saved} marka cache'e kaydedildi, {$skipped} atlandı (toplam " . count($rawBrands) . ")",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($rawBrands),
                ]);
            } elseif ($slug === 'pazarama') {
                $pazarama = $this->container->get(\App\Services\Marketplace\PazaramaService::class);
                $rawBrands = $pazarama->fetchBrands();

                $saved = 0;
                $skipped = 0;

                foreach ($rawBrands as $pBrand) {
                    $name = trim($pBrand['name'] ?? $pBrand['brandName'] ?? '');
                    $mpBrandId = (string)($pBrand['id'] ?? $pBrand['brandId'] ?? '');
                    if ($name === '' || $mpBrandId === '') { $skipped++; continue; }

                    $this->mpBrandModel->upsert($mpId, $mpBrandId, $name);
                    $saved++;
                }

                // Cache'e kaydettikten sonra otomatik eşleştirme yap
                $matched = 0;
                $allBrands = $this->brandModel->all(['is_active' => 1], 'name ASC');
                foreach ($allBrands as $brand) {
                    $existing = $this->mappingModel->findMapping((int)$brand['id'], $mpId);
                    if ($existing !== false) { continue; }

                    $match = $this->mpBrandModel->findExactMatch($brand['name'], $mpId);
                    if ($match !== false) {
                        $this->mappingModel->saveMapping(
                            (int)$brand['id'], $mpId,
                            $match['mp_brand_id'], $match['name']
                        );
                        $matched++;
                    }
                }

                $this->success([
                    'message' => "Pazarama: {$saved} marka çekildi, {$matched} otomatik eşleştirildi",
                    'saved'   => $saved,
                    'matched' => $matched,
                    'skipped' => $skipped,
                    'total'   => count($rawBrands),
                ]);
            } elseif ($slug === 'oziygo') {
                $oziygo = $this->container->get(\App\Services\Marketplace\OziygoService::class);
                $rawBrands = $oziygo->fetchBrands();

                $saved = 0;
                $skipped = 0;
                foreach ($rawBrands as $brand) {
                    $name = trim($brand['name'] ?? '');
                    $mpBrandId = (string)($brand['id'] ?? '');
                    if ($name === '' || $mpBrandId === '') { $skipped++; continue; }
                    $this->mpBrandModel->upsert($mpId, $mpBrandId, $name);
                    $saved++;
                }

                $this->success([
                    'message' => "OziyGO: {$saved} marka cache'e kaydedildi, {$skipped} atlandi",
                    'saved'   => $saved,
                    'skipped' => $skipped,
                    'total'   => count($rawBrands),
                ]);
            } elseif ($slug === 'ciceksepeti') {
                // ÇiçekSepeti API'de marka listesi yok — ürünlerden çıkar
                $db = $this->container->get(\App\Core\Database::class)->getConnection();
                $stmt = $db->prepare("
                    SELECT marketplace_brand_id, marketplace_brand_name, COUNT(*) as cnt
                    FROM marketplace_products
                    WHERE marketplace_id = :mpId
                      AND store_id = :storeId
                      AND marketplace_brand_id IS NOT NULL
                      AND marketplace_brand_id != ''
                    GROUP BY marketplace_brand_id, marketplace_brand_name
                ");
                // store_id şart: filtresiz sorgu diğer bayilerin markalarını da getiriyordu
                $stmt->execute([':mpId' => $mpId, ':storeId' => $this->container->get(\App\Core\TenantContext::class)->currentStoreId()]);
                $productBrands = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $saved = 0;
                foreach ($productBrands as $pb) {
                    $brandId   = (string) ($pb['marketplace_brand_id'] ?? '');
                    $brandName = trim((string) ($pb['marketplace_brand_name'] ?? ''));
                    if ($brandId === '' || $brandName === '') continue;
                    $this->mpBrandModel->upsert($mpId, $brandId, $brandName);
                    $saved++;
                }

                if ($saved > 0) {
                    $this->success([
                        'message' => "ÇiçekSepeti: Ürünlerden {$saved} marka çıkarıldı.",
                        'saved'   => $saved,
                        'skipped' => 0,
                        'total'   => $saved,
                    ]);
                } else {
                    $this->success([
                        'message' => 'ÇiçekSepeti API\'de marka endpoint\'i yok. Önce ürünleri import edin.',
                        'saved'   => 0,
                        'skipped' => 0,
                        'total'   => 0,
                    ]);
                }
            } else {
                // GENEL DAL: servisin kendi fetchBrands() metodu kullanılır.
                // Hepsiburada/Amazon/İdefix/PttAVM bu metoda zaten sahipti;
                // yalnız burada çağrılmadığı için "desteklenmiyor" deniyordu.
                $this->syncBrandsGeneric($slug, $mpId);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Marka sync hatası: {$e->getMessage()}", 'BrandSync');
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    /**
     * Otomatik eşleştirme — detaylı sonuç
     */
    public function autoMatch(Request $request): void
    {
        $slug = $request->post('marketplace', '');

        try {
            if ($slug !== '') {
                $mp = $this->marketplaceModel->findBySlug($slug);
                if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
                if (!$this->authorization()->canAccessMarketplace((int) $mp['id'])) {
                    $this->error("'{$slug}' pazaryeri paketinizde bulunmuyor", 403);
                }
                $marketplaces = [$mp];
            } else {
                $marketplaces = $this->accessibleActiveMarketplaces();
            }

            $allBrands = $this->brandModel->all(['is_active' => 1], 'name ASC');
            $mpResults = []; // her mp için detaylı sonuç

            foreach ($marketplaces as $mp) {
                $mpId = (int)$mp['id'];
                $mpSlug = $mp['slug'] ?? '';
                $mpResult = [
                    'slug'       => $mpSlug,
                    'name'       => $mp['display_name'],
                    'mp_id'      => $mpId,
                    'matched'    => 0,
                    'already'    => 0,
                    'not_found'  => 0,
                    'not_found_names' => [],
                    'not_found_items' => [],
                ];

                $cacheCount = $this->mpBrandModel->countByMarketplace($mpId);
                if ($cacheCount === 0) {
                    // Cache boşsa live API dene
                    foreach ($allBrands as $brand) {
                        $existing = $this->mappingModel->findMapping((int)$brand['id'], $mpId);
                        if ($existing !== false) { $mpResult['already']++; continue; }

                        $liveMatch = $this->searchLiveAPI($mpSlug, $brand['name']);
                        if ($liveMatch !== null) {
                            $this->mpBrandModel->upsert($mpId, (string)$liveMatch['id'], $liveMatch['name']);
                            $this->mappingModel->saveMapping(
                                (int)$brand['id'], $mpId,
                                (string)$liveMatch['id'], $liveMatch['name']
                            );
                            $mpResult['matched']++;
                        } else {
                            $mpResult['not_found']++;
                            if (count($mpResult['not_found_names']) < 10) {
                                $mpResult['not_found_names'][] = $brand['name'];
                            }
                            $mpResult['not_found_items'][] = ['id' => (int)$brand['id'], 'name' => $brand['name']];
                        }
                    }
                } else {
                    foreach ($allBrands as $brand) {
                        $existing = $this->mappingModel->findMapping((int)$brand['id'], $mpId);
                        if ($existing !== false) { $mpResult['already']++; continue; }

                        $match = $this->mpBrandModel->findExactMatch($brand['name'], $mpId);
                        if ($match !== false) {
                            $this->mappingModel->saveMapping(
                                (int)$brand['id'], $mpId,
                                $match['mp_brand_id'], $match['name']
                            );
                            $mpResult['matched']++;
                        } else {
                            $mpResult['not_found']++;
                            if (count($mpResult['not_found_names']) < 10) {
                                $mpResult['not_found_names'][] = $brand['name'];
                            }
                            $mpResult['not_found_items'][] = ['id' => (int)$brand['id'], 'name' => $brand['name']];
                        }
                    }
                }

                $mpResults[] = $mpResult;
            }

            $totalMatched = array_sum(array_column($mpResults, 'matched'));
            $totalNotFound = array_sum(array_column($mpResults, 'not_found'));

            $this->success([
                'message'    => "{$totalMatched} eşleştirildi, {$totalNotFound} bulunamadı",
                'matched'    => $totalMatched,
                'not_found'  => $totalNotFound,
                'mp_results' => $mpResults,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Marka otomatik eşleştirme hatası: {$e->getMessage()}", 'BrandAutoMatch');
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    /**
     * Tüm sistem markalarını sil
     */
    public function deleteAllBrands(Request $request): void
    {
        try {
            // Ürünlü markaları atla
            $brands = $this->brandModel->all([], 'name ASC');
            $deleted = 0;
            $skipped = 0;

            foreach ($brands as $brand) {
                $productCount = $this->brandModel->getProductCount((int)$brand['id']);
                if ($productCount > 0) { $skipped++; continue; }
                $this->brandModel->delete((int)$brand['id']);
                $deleted++;
            }

            $this->success([
                'message' => "{$deleted} marka silindi" . ($skipped > 0 ? " ({$skipped} ürünlü marka atlanıldi)" : ''),
                'deleted' => $deleted,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            $this->error('Hata: ' . $e->getMessage());
        }
    }

    /**
     * Pazaryeri marka cache'ini temizle
     */
    public function clearCache(Request $request): void
    {
        $slug = $request->post('marketplace', '');
        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        try {
            $mp = $this->marketplaceModel->findBySlug($slug);
            if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }

            $mpId = (int)$mp['id'];

            // Cache markalarını sil
            $this->mpBrandModel->deleteByMarketplace($mpId);

            // Mapping'leri de temizle
            $this->mappingModel->deleteByMarketplace($mpId);

            $this->success([
                'message' => "{$mp['display_name']} marka cache'i temizlendi",
            ]);
        } catch (\Throwable $e) {
            $this->error('Hata: ' . $e->getMessage());
        }
    }
    /**
     * Yeni marka oluştur — sonra tüm pazaryerlerinde otomatik eşleştir
     * Eşleşme bulamazsa, bulamadığı pazaryerlerini frontend'e bildir
     */
    public function store(Request $request): void
    {
        $name = trim($request->post('name', ''));
        if ($name === '') { $this->error('Marka adı zorunludur'); }

        $existing = $this->brandModel->findByName($name);
        if ($existing !== false) { $this->error("'{$name}' markası zaten mevcut"); }

        $id = $this->brandModel->create([
            'name'      => $name,
            'slug'      => StringHelper::slugify($name),
            'is_active' => 1,
        ]);

        // Tüm aktif pazaryerlerinde otomatik eşleştirme
        $matchResult = $this->autoMatchBrandDetailed($id, $name);

        $matchMsg = '';
        if ($matchResult['matched'] > 0) {
            $matchMsg = " ({$matchResult['matched']} pazaryeriyle eşleştirildi)";
        }

        $this->success([
            'id'                     => $id,
            'brand_name'             => $name,
            'message'                => "'{$name}' markası oluşturuldu{$matchMsg}",
            'matched_marketplaces'   => $matchResult['matched_list'],
            'unmatched_marketplaces' => $matchResult['unmatched_list'],
        ]);
    }

    /**
     * Tek bir markayı tüm aktif pazaryerlerinde otomatik eşleştir
     * Cache varsa cache'den, yoksa canlı API'den arar
     */
    private function autoMatchBrandDetailed(int $brandId, string $name): array
    {
        $matched = 0;
        $matchedList = [];
        $unmatchedList = [];
        // Yalnız mağazanın yetkili olduğu (paketindeki) etkin pazaryerleri — yetkisiz pazaryerine canlı arama yapılmaz
        $marketplaces = $this->accessibleActiveMarketplaces();

        foreach ($marketplaces as $mp) {
            $mpId = (int)$mp['id'];
            $slug = $mp['slug'] ?? '';

            // Zaten eşleştirilmiş mi?
            $existing = $this->mappingModel->findMapping($brandId, $mpId);
            if ($existing !== false) {
                $matchedList[] = ['id' => $mpId, 'slug' => $slug, 'name' => $mp['display_name']];
                $matched++;
                continue;
            }

            // 1) Cache'de ara
            $cacheCount = $this->mpBrandModel->countByMarketplace($mpId);
            if ($cacheCount > 0) {
                $match = $this->mpBrandModel->findExactMatch($name, $mpId);
                if ($match !== false) {
                    $this->mappingModel->saveMapping(
                        $brandId, $mpId,
                        $match['mp_brand_id'], $match['name']
                    );
                    $matchedList[] = ['id' => $mpId, 'slug' => $slug, 'name' => $mp['display_name']];
                    $matched++;
                    continue;
                }
            }

            // 2) Cache'de bulunamadı veya cache boş → Canlı API'den ara
            $liveMatch = $this->searchLiveAPI($slug, $name);
            if ($liveMatch !== null) {
                // Cache'e de kaydet
                $this->mpBrandModel->upsert($mpId, (string)$liveMatch['id'], $liveMatch['name']);

                $this->mappingModel->saveMapping(
                    $brandId, $mpId,
                    (string)$liveMatch['id'], $liveMatch['name']
                );
                $matchedList[] = ['id' => $mpId, 'slug' => $slug, 'name' => $mp['display_name']];
                $matched++;
            } else {
                $unmatchedList[] = [
                    'id'     => $mpId,
                    'slug'   => $slug,
                    'name'   => $mp['display_name'],
                    'reason' => 'not_found',
                ];
            }
        }

        return [
            'matched'        => $matched,
            'matched_list'   => $matchedList,
            'unmatched_list' => $unmatchedList,
        ];
    }

    /**
     * Pazaryeri servisinin fetchBrands() çıktısını katalog tablosuna yazar.
     *
     * Servisin metodu yoksa anlamlı hata verilir; "desteklenmiyor" mesajı
     * artık YALNIZ gerçekten desteklemeyenler için çıkar.
     */
    private function syncBrandsGeneric(string $slug, int $mpId): void
    {
        $servis = $this->resolveMarketplaceService($slug);

        if ($servis === null || !method_exists($servis, 'fetchBrands')) {
            $this->error("'{$slug}' pazaryeri marka listesi sunmuyor");
        }

        $servis->loadCredentials();
        $hamMarkalar = $servis->fetchBrands();

        $kaydedilen = 0;
        $atlanan    = 0;

        foreach ($hamMarkalar as $marka) {
            $ad = trim((string) (is_array($marka) ? ($marka['name'] ?? '') : $marka));
            if ($ad === '') {
                $atlanan++;
                continue;
            }

            // Bazı pazaryerlerinde marka ID'si yoktur (ör. Hepsiburada markayı
            // ürün niteliği olarak tutar); o zaman ADIN kendisi kimlik olur.
            // Kimlik 'id' ya da 'mp_brand_id' anahtarında gelir (WooCommerce/OpenCart/Shopify fetchBrands
            // 'mp_brand_id' döndürür — InitialImportService::importBrands ile aynı okuma).
            $id = is_array($marka) ? trim((string) ($marka['id'] ?? $marka['mp_brand_id'] ?? '')) : '';
            if ($id === '') {
                $id = $ad;
            }

            try {
                $this->mpBrandModel->upsert($mpId, $id, $ad);
                $kaydedilen++;
            } catch (\Throwable) {
                $atlanan++;
            }
        }

        $this->success([
            'message' => "{$kaydedilen} marka güncellendi.",
            'saved'   => $kaydedilen,
            'skipped' => $atlanan,
            'total'   => count($hamMarkalar),
        ]);
    }

    /**
     * Slug'dan pazaryeri servisini çöz.
     */
    private function resolveMarketplaceService(string $slug): ?object
    {
        $harita = [
            'trendyol'    => \App\Services\Marketplace\TrendyolService::class,
            'hepsiburada' => \App\Services\Marketplace\HepsiburadaService::class,
            'n11'         => \App\Services\Marketplace\N11Service::class,
            'amazon'      => \App\Services\Marketplace\AmazonService::class,
            'pazarama'    => \App\Services\Marketplace\PazaramaService::class,
            'ciceksepeti' => \App\Services\Marketplace\CiceksepetiService::class,
            'idefix'      => \App\Services\Marketplace\IdefixService::class,
            'pttavm'      => \App\Services\Marketplace\PttavmService::class,
            'oziygo'      => \App\Services\Marketplace\OziygoService::class,
            'shopify'     => \App\Services\Marketplace\ShopifyService::class,
            'woocommerce' => \App\Services\Marketplace\WooCommerceService::class,
            'opencart'    => \App\Services\Marketplace\OpenCartService::class,
        ];

        if (!isset($harita[$slug])) {
            return null;
        }

        return $this->container->get($harita[$slug]);
    }

    /**
     * Canlı API'de marka ara (pazaryerine göre)
     */
    private function searchLiveAPI(string $slug, string $name): ?array
    {
        try {
            if ($slug === 'trendyol') {
                $trendyol = $this->container->get(TrendyolService::class);
                return $trendyol->searchBrandByName($name);
            }
            if ($slug === 'n11') {
                // N11'de ayrı brand API yok, cache'den exact match dene
                $mp = $this->marketplaceModel->findBySlug('n11');
                if ($mp !== false) {
                    $match = $this->mpBrandModel->findExactMatch($name, (int)$mp['id']);
                    if ($match !== false) {
                        return ['id' => $match['mp_brand_id'], 'name' => $match['name']];
                    }
                }
                return null;
            }
            if ($slug === 'pazarama') {
                $pazarama = $this->container->get(\App\Services\Marketplace\PazaramaService::class);
                $pazarama->loadCredentials();
                $brands = $pazarama->fetchBrands($name);
                foreach ($brands as $brand) {
                    $brandName = $brand['name'] ?? '';
                    if (mb_strtolower($brandName) === mb_strtolower($name)) {
                        return ['id' => (string)($brand['id'] ?? ''), 'name' => $brandName];
                    }
                }
                return null;
            }

            // Pazaryerine ozel dal yoksa SESSIZCE TRENDYOL sonucuna dusmek
            // yanlis eslestirmeye yol aciyordu. Secili pazaryerinin KENDI
            // katalogunda ara.
            return $this->yerelKatalogdaAra($slug, $name);
        } catch (\Throwable $e) {
            $this->logger->error("Live API marka arama hatası ({$slug}): {$e->getMessage()}", 'BrandAutoMatch');
        }
        return null;
    }

    /**
     * Secili pazaryerinin yerel marka katalogunda ara.
     *
     * @return array{id: string, name: string}|null
     */
    private function yerelKatalogdaAra(string $slug, string $name): ?array
    {
        $mp = $this->marketplaceModel->findBySlug($slug);
        if ($mp === false) {
            return null;
        }

        $tam = $this->mpBrandModel->findExactMatch($name, (int) $mp['id']);
        if ($tam !== false) {
            return ['id' => (string) $tam['mp_brand_id'], 'name' => (string) $tam['name']];
        }

        return null;
    }

    /**
     * Pazaryeri marka cache'inde arama (AJAX) — Manuel eşleştirme için
     */
    public function searchCache(Request $request): void
    {
        $q = trim((string)$request->query('q', ''));
        $mpId = (int)$request->query('marketplace_id', 0);

        if ($q === '' || strlen($q) < 2) {
            $this->json(['success' => true, 'data' => []]);
        }

        $results = $this->mpBrandModel->searchByName($q, $mpId > 0 ? $mpId : null);

        $finder = $this->container->get(\App\Services\Mapping\MappingCandidateFinder::class);

        foreach ($results as $i => $row) {
            $results[$i]['match_score'] = $finder->scoreBrand($q, (string) ($row['name'] ?? ''));
        }

        usort($results, static fn(array $a, array $b): int => $b['match_score'] <=> $a['match_score']);

        $this->json(['success' => true, 'data' => array_slice($results, 0, 50)]);
    }

    /**
     * Manuel eşleştirme kaydet (AJAX)
     */
    public function manualMap(Request $request): void
    {
        $brandId = (int)$request->post('brand_id', 0);
        $marketplaceId = (int)$request->post('marketplace_id', 0);
        $mpBrandId = trim($request->post('mp_brand_id', ''));
        $mpBrandName = trim($request->post('mp_brand_name', ''));

        if ($brandId === 0) { $this->error('Marka belirtilmedi'); }
        if ($marketplaceId === 0) { $this->error('Pazaryeri belirtilmedi'); }
        if ($mpBrandId === '') { $this->error('Pazaryeri markası seçilmedi'); }

        $brand = $this->brandModel->find($brandId);
        if ($brand === false) { $this->error('Marka bulunamadı', 404); }

        $this->mappingModel->saveMapping($brandId, $marketplaceId, $mpBrandId, $mpBrandName);

        $mp = $this->marketplaceModel->find($marketplaceId);
        $mpName = $mp !== false ? $mp['display_name'] : 'Pazaryeri';

        $this->success([
            'message'  => "'{$brand['name']}' → {$mpName}: '{$mpBrandName}' olarak eşleştirildi",
            'brand_id' => $brandId,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $brand = $this->brandModel->find((int)$id);
        if ($brand === false) { $this->error('Marka bulunamadı', 404); }

        $name = trim($request->post('name', ''));
        if ($name === '') { $this->error('Marka adı zorunludur'); }

        $existing = $this->brandModel->findByName($name);
        if ($existing !== false && (int)$existing['id'] !== (int)$id) {
            $this->error("'{$name}' markası zaten mevcut");
        }

        $this->brandModel->update((int)$id, [
            'name'      => $name,
            'slug'      => StringHelper::slugify($name),
            'is_active' => (int)$request->post('is_active', 1),
        ]);

        $this->success(['message' => "'{$name}' markası güncellendi"]);
    }

    public function delete(Request $request, string $id): void
    {
        $brand = $this->brandModel->find((int)$id);
        if ($brand === false) { $this->error('Marka bulunamadı', 404); }

        $productCount = $this->brandModel->getProductCount((int)$id);
        if ($productCount > 0) {
            $this->error("Bu markada {$productCount} ürün var. Önce ürünlerin markasını değiştirin.");
        }

        $this->brandModel->delete((int)$id);
        $this->success(['message' => "'{$brand['name']}' markası silindi"]);
    }

    /**
     * Toplu silme (AJAX)
     */
    public function bulkDelete(Request $request): void
    {
        $idsRaw = $request->post('ids', '');
        if ($idsRaw === '') { $this->error('Silinecek marka seçilmedi'); }

        $ids = array_map('intval', explode(',', $idsRaw));
        $deleted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($ids as $id) {
            $brand = $this->brandModel->find($id);
            if ($brand === false) { $skipped++; continue; }

            $productCount = $this->brandModel->getProductCount($id);
            if ($productCount > 0) {
                $errors[] = "{$brand['name']} ({$productCount} ürün)";
                $skipped++;
                continue;
            }

            $this->brandModel->delete($id);
            $deleted++;
        }

        $msg = "{$deleted} marka silindi";
        if ($skipped > 0) $msg .= ", {$skipped} atlandı";
        if (!empty($errors)) $msg .= ". Ürünlü markalar: " . implode(', ', array_slice($errors, 0, 3));

        $this->success(['message' => $msg, 'deleted' => $deleted, 'skipped' => $skipped]);
    }

    /**
     * N11 ürünlerindeki boş marketplace_brand_name'leri N11 API'den çekip güncelle
     */
    private function updateN11ProductBrands(int $mpId): void
    {
        $n11 = $this->container->get(N11Service::class);
        $db  = $this->container->get(\App\Core\Database::class);
        $pdo = $db->getConnection();

        $page = 0;
        $updated = 0;

        while (true) {
            $response = $n11->fetchProducts($page, 250);
            $items = $response['content'] ?? [];
            if (empty($items)) break;

            foreach ($items as $product) {
                $brandName = '';
                foreach ($product['attributes'] ?? [] as $attr) {
                    if (mb_strtolower($attr['attributeName'] ?? '') === 'marka') {
                        $brandName = trim($attr['attributeValue'] ?? '');
                        break;
                    }
                }
                if ($brandName === '') continue;

                $mpProductId = (string)($product['n11ProductId'] ?? '');
                $stockCode   = $product['stockCode'] ?? '';
                if ($mpProductId === '' && $stockCode === '') continue;

                // marketplace_products tablosundaki kaydı bul ve güncelle
                if ($mpProductId !== '') {
                    $stmt = $pdo->prepare(
                        "UPDATE marketplace_products SET marketplace_brand_name = ?
                         WHERE marketplace_id = ? AND marketplace_product_id = ?
                         AND (marketplace_brand_name IS NULL OR marketplace_brand_name = '')"
                    );
                    $stmt->execute([$brandName, $mpId, $mpProductId]);
                    $updated += $stmt->rowCount();
                } elseif ($stockCode !== '') {
                    $stmt = $pdo->prepare(
                        "UPDATE marketplace_products SET marketplace_brand_name = ?
                         WHERE marketplace_id = ? AND marketplace_sku = ?
                         AND (marketplace_brand_name IS NULL OR marketplace_brand_name = '')"
                    );
                    $stmt->execute([$brandName, $mpId, $stockCode]);
                    $updated += $stmt->rowCount();
                }
            }

            if (($response['last'] ?? false) === true || count($items) < 250) break;
            $page++;
        }

        if ($updated > 0) {
            $this->logger->info("N11: {$updated} ürünün marka bilgisi güncellendi", 'BrandSync');
        }
    }

    /**
     * Overlay: Pazaryeri markalarını listele (paginated, with mapping status)
     */
    public function mpBrandList(Request $request): void
    {
        $slug   = $request->query('marketplace', '');
        $search = trim((string)$request->query('search', ''));
        $page   = max(1, (int)$request->query('page', 1));
        $perPage = 50;

        if ($slug === '') { $this->error('Pazaryeri belirtilmedi'); }

        $mp = $this->marketplaceModel->findBySlug($slug);
        if ($mp === false) { $this->error("'{$slug}' pazaryeri bulunamadı"); }
        $mpId = (int)$mp['id'];

        $db = $this->container->get(\App\Core\Database::class)->getConnection();

        // Mağaza kapsamı: WooCommerce/Shopify/OpenCart markaları yalnız bu mağazanın; eşleşme ve sistem
        // markası da yalnız aktif mağazanın kayıtlarından okunur (başka mağazanın eşleşmesi görünmez).
        $storeId = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();
        [$scope, $scopeParams] = $this->mpBrandModel->scopeFilter('mb');

        // Count
        $cntSql = "SELECT COUNT(*) as cnt FROM marketplace_brands mb WHERE mb.marketplace_id = :mid AND {$scope}";
        $cntParams = ['mid' => $mpId] + $scopeParams;
        if ($search !== '') {
            $cntSql .= " AND mb.name LIKE :q";
            $cntParams['q'] = '%' . $search . '%';
        }
        $total = (int)($db->prepare($cntSql) ? (function() use ($db, $cntSql, $cntParams) {
            $s = $db->prepare($cntSql); $s->execute($cntParams); return $s->fetchColumn();
        })() : 0);

        // Items
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT mb.id, mb.mp_brand_id, mb.name,
                       bmm.id as mapping_id, bmm.brand_id as system_brand_id,
                       b.name as system_brand_name
                FROM marketplace_brands mb
                LEFT JOIN brand_marketplace_mapping bmm
                    ON mb.marketplace_id = bmm.marketplace_id AND mb.mp_brand_id = bmm.marketplace_brand_id
                   AND bmm.store_id = :sid_bmm
                LEFT JOIN brands b ON bmm.brand_id = b.id AND b.store_id = :sid_b
                WHERE mb.marketplace_id = :mid AND {$scope}";
        $params = ['mid' => $mpId, 'sid_bmm' => $storeId, 'sid_b' => $storeId] + $scopeParams;
        if ($search !== '') {
            $sql .= " AND mb.name LIKE :q";
            $params['q'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY mb.name ASC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Pazaryeri markasını sisteme ekle (overlay'den)
     */
    public function addToSystem(Request $request): void
    {
        $mpBrandId = trim($request->post('mp_brand_id', ''));
        $mpId      = (int)$request->post('marketplace_id', 0);

        if ($mpBrandId === '' || $mpId === 0) {
            $this->error('Eksik parametreler');
        }

        $mpBrand = $this->mpBrandModel->findByMpBrandId($mpId, $mpBrandId);
        if ($mpBrand === false) { $this->error('Pazaryeri markası bulunamadı'); }

        $brandName = trim($mpBrand['name']);

        // Zaten eşleşmiş mi?
        $existingMapping = $this->mappingModel->findByMarketplaceBrand($mpId, $mpBrandId);
        if ($existingMapping !== false) {
            $this->success(['message' => 'Bu marka zaten eşleştirilmiş', 'already_mapped' => true]);
        }

        // Sistem markasında aynı isim var mı?
        $existingBrand = $this->brandModel->findByName($brandName);
        if ($existingBrand !== false) {
            $sysBrandId = (int)$existingBrand['id'];
        } else {
            $sysBrandId = $this->brandModel->create([
                'name'      => $brandName,
                'slug'      => StringHelper::slugify($brandName),
                'is_active' => 1,
            ]);
        }

        $this->mappingModel->saveMapping($sysBrandId, $mpId, $mpBrandId, $brandName);

        $this->success([
            'message'  => "'{$brandName}' sisteme eklendi ve eşleştirildi",
            'brand_id' => $sysBrandId,
            'is_new'   => ($existingBrand === false),
        ]);
    }

    /**
     * Toplu: MP markalarını sisteme ekle (overlay'den)
     */
    public function bulkAddToSystem(Request $request): void
    {
        $items = $request->post('items', '');
        $mpId  = (int)$request->post('marketplace_id', 0);

        if ($mpId === 0) { $this->error('Pazaryeri belirtilmedi'); }

        $itemIds = json_decode($items, true);
        if (!is_array($itemIds) || empty($itemIds)) {
            $this->error('Marka seçilmedi');
        }

        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($itemIds as $mpBrandId) {
            $mpBrandId = (string)$mpBrandId;
            $mpBrand = $this->mpBrandModel->findByMpBrandId($mpId, $mpBrandId);
            if ($mpBrand === false) { $errors[] = "MP #{$mpBrandId} bulunamadı"; continue; }

            $existingMapping = $this->mappingModel->findByMarketplaceBrand($mpId, $mpBrandId);
            if ($existingMapping !== false) { $skipped++; continue; }

            $brandName = trim($mpBrand['name']);
            $existingBrand = $this->brandModel->findByName($brandName);

            if ($existingBrand !== false) {
                $sysBrandId = (int)$existingBrand['id'];
            } else {
                $sysBrandId = $this->brandModel->create([
                    'name'      => $brandName,
                    'slug'      => StringHelper::slugify($brandName),
                    'is_active' => 1,
                ]);
            }

            $this->mappingModel->saveMapping($sysBrandId, $mpId, $mpBrandId, $brandName);
            $added++;
        }

        $this->success([
            'message' => "{$added} marka eklendi, {$skipped} zaten eşleşik" . (!empty($errors) ? ', ' . count($errors) . ' hata' : ''),
            'added'   => $added,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    /**
     * Etkin VE mağazanın paketinde (entitlement) olan pazaryerleri. getActive() yapılandırması olmayan
     * global aktif pazaryerlerini de döndürür; yetkisiz pazaryerine canlı API araması yapılmasın.
     *
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

    private function authorization(): \App\Services\AuthorizationService
    {
        return $this->container->get(\App\Services\AuthorizationService::class);
    }
}

