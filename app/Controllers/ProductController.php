<?php
/**
 * OzyConnect Panel — ProductController
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    2.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Helpers\ValidationHelper;
use App\Models\Brand;
use App\Models\BrandMarketplaceMapping;
use App\Models\Category;
use App\Models\CategoryMarketplaceMapping;
use App\Models\Marketplace;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\PriceRule;
use App\Models\StockMovement;
use App\Enums\PriceRuleType;
use App\Services\PriceRuleEngine;
use App\Services\ProductPublishService;
use App\Services\StockManager;

final class ProductController extends BaseController
{
    /** Import döngüsü için üst sınır (sonsuz döngü koruması) */
    private const MAX_IMPORT_PAGES = 500;

    private readonly Product $productModel;
    private readonly Category $categoryModel;
    private readonly Brand $brandModel;
    private readonly MarketplaceProduct $mpProductModel;
    private readonly StockMovement $stockMovementModel;
    private readonly ProductAttributeValue $pAttrValueModel;
    private readonly ProductAttribute $pAttrModel;
    private readonly StockManager $stockManager;
    private readonly PriceRule $priceRuleModel;
    private readonly PriceRuleEngine $priceRuleEngine;
    private readonly ProductPublishService $publishService;
    private readonly Marketplace $marketplaceModel;
    private readonly CategoryMarketplaceMapping $catMappingModel;
    private readonly BrandMarketplaceMapping $brandMappingModel;
    private readonly MarketplaceCategory $mpCatModel;
    private readonly \App\Services\ListingStatusService $listingStatusService;
    private readonly \App\Services\Product\BulkProductActionService $bulkJobService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->productModel       = $container->get(Product::class);
        $this->categoryModel      = $container->get(Category::class);
        $this->brandModel         = $container->get(Brand::class);
        $this->mpProductModel     = $container->get(MarketplaceProduct::class);
        $this->stockMovementModel = $container->get(StockMovement::class);
        $this->pAttrValueModel    = $container->get(ProductAttributeValue::class);
        $this->pAttrModel         = $container->get(ProductAttribute::class);
        $this->stockManager       = $container->get(StockManager::class);
        $this->priceRuleModel     = $container->get(PriceRule::class);
        $this->priceRuleEngine    = $container->get(PriceRuleEngine::class);
        $this->publishService     = $container->get(ProductPublishService::class);
        $this->marketplaceModel   = $container->get(Marketplace::class);
        $this->catMappingModel    = $container->get(CategoryMarketplaceMapping::class);
        $this->brandMappingModel  = $container->get(BrandMarketplaceMapping::class);
        $this->mpCatModel         = $container->get(MarketplaceCategory::class);
        $this->listingStatusService = $container->get(\App\Services\ListingStatusService::class);
        $this->bulkJobService = $container->get(\App\Services\Product\BulkProductActionService::class);
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN LİSTESİ (DataTables + Marketplace Tabs)
    // ═══════════════════════════════════════════════════════

    public function index(Request $request): void
    {
        $filters = [
            'search'         => $request->query('search'),
            'category_id'    => $request->query('category_id'),
            'brand_id'       => $request->query('brand_id'),
            'is_active'      => $request->query('is_active'),
            'stock_status'   => $request->query('stock_status'),
            'marketplace_id' => $request->query('marketplace_id'),
            'xml_profile_id' => $request->query('xml_profile_id'),
        ];

        $categories = $this->categoryModel->getFlatTree();
        $brands = $this->brandModel->getActive();
        $marketplaces = $this->marketplaceModel->getAll();
        $mpProductCounts = $this->productModel->getMarketplaceProductCounts();
        $totalProducts = $this->productModel->countForFilters([]);
        // Üst özet kartları (salt okunur sayımlar; liste süzgeciyle aynı WHERE kuralları)
        $ozet = [
            'aktif'   => $this->productModel->countForFilters(['is_active' => '1']),
            'dusuk'   => $this->productModel->countForFilters(['stock_status' => 'low']),
            'tukendi' => $this->productModel->countForFilters(['stock_status' => 'out']),
        ];

        $this->view('products.index', [
            'trendyolCatalogStatus' => $this->container->get(\App\Services\MarketplaceCatalogStatus::class)->read(),
            'ozet'            => $ozet,
            'pageTitle'       => 'Ürünler',
            'filters'         => $filters,
            'categories'      => $categories,
            'brands'          => $brands,
            'marketplaces'    => $marketplaces,
            'mpProductCounts' => $mpProductCounts,
            'totalProducts'   => $totalProducts,
            // XML kaynağı süzgeci — yalnız içe aktarım profilleri listelenir
            'xmlProfiles'     => $this->container->get(\App\Models\XmlProfile::class)
                ->all(['direction' => 'import'], 'name ASC'),
        ]);
    }

    public function catalogStatus(Request $request): void
    {
        $mp = $this->marketplaceModel->findBySlug('trendyol');
        $auth = $this->container->get(\App\Services\AuthorizationService::class);
        if (!$mp || !$auth->canAccessMarketplaceData('products.view', (int)$mp['id'])) {
            $this->response->forbidden('Bu pazaryerine erişim yetkiniz yok');
        }
        $this->response->success($this->container->get(\App\Services\MarketplaceCatalogStatus::class)->refresh());
    }

    /**
     * DataTables server-side AJAX endpoint
     */
    public function ajaxList(Request $request): void
    {
        $draw   = (int) ($_GET['draw'] ?? 1);
        $start  = (int) ($_GET['start'] ?? 0);
        $length = max(10, min(100, (int) ($_GET['length'] ?? 25)));

        $searchValue = trim((string) ($_GET['search']['value'] ?? ''));

        $dtCol = (int) ($_GET['order'][0]['column'] ?? 0);
        $dtDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc'));
        // Kolon sırası views/products/index.php tablosuyla BİREBİR aynı olmalı:
        // 0 seçim · 1 ürün · 2 kategori · 3 marka · 4 fiyat · 5 stok · 6 pazaryeri · 7 durum · 8 işlemler
        $colMap = [
            0 => 'p.updated_at', 1 => 'p.name', 2 => 'c.name', 3 => 'b.name',
            4 => 'p.base_price', 5 => 'p.stock_qty', 6 => 'p.updated_at',
            7 => 'p.is_active', 8 => 'p.updated_at',
        ];
        $orderBy  = $colMap[$dtCol] ?? 'p.updated_at';
        $orderDir = $dtDir === 'asc' ? 'ASC' : 'DESC';

        $filters = array_filter([
            'search'         => $searchValue,
            'category_id'    => $_GET['category_id'] ?? '',
            'brand_id'       => $_GET['brand_id'] ?? '',
            'is_active'      => $_GET['is_active'] ?? '',
            'stock_status'   => $_GET['stock_status'] ?? '',
            'marketplace_id' => $_GET['marketplace_id'] ?? '',
            'show_archived'  => $_GET['show_archived'] ?? '',
            // "XML ile gelen ürünler" filtresi — toplu pazaryerine gönderim için
            'source'         => $_GET['source'] ?? '',
            'xml_profile_id' => $_GET['xml_profile_id'] ?? '',
        ], fn($v) => $v !== '' && $v !== null);

        $result = $this->productModel->getForDataTables($filters, $start, $length, $orderBy, $orderDir);

        // Append marketplace counts
        if (!empty($result['data'])) {
            $result['data'] = $this->productModel->appendMarketplaceCounts($result['data']);
        }

        // Get marketplace product mapping info per product
        $productIds = array_column($result['data'], 'id');
        $mpDetails = $this->mpProductModel->getMarketplaceDetailsForProducts(array_map('intval', $productIds));

        $rows = [];
        foreach ($result['data'] as $p) {
            $mainImage = $p['main_image'] ?? '';
            if (empty($mainImage) && !empty($p['images_json'])) {
                $imgs = json_decode($p['images_json'], true);
                $mainImage = is_array($imgs) && !empty($imgs) ? ($imgs[0]['url'] ?? ($imgs[0] ?? '')) : '';
            }

            $rows[] = [
                'id'                  => (int) $p['id'],
                'sku'                 => $p['sku'] ?? '',
                'barcode'             => $p['barcode'] ?? '',
                'name'                => $p['name'] ?? '',
                'category_name'       => $p['category_name'] ?? '',
                'brand_name'          => $p['brand_name'] ?? '',
                'base_price'          => (float) ($p['base_price'] ?? 0),
                'list_price'          => (float) ($p['list_price'] ?? 0),
                'stock_qty'           => (int) ($p['stock_qty'] ?? 0),
                'min_stock_alert'     => (int) ($p['min_stock_alert'] ?? 5),
                'is_active'           => (int) ($p['is_active'] ?? 0),
                'main_image'          => $mainImage,
                'total_marketplaces'  => (int) ($p['total_marketplaces'] ?? 0),
                'active_marketplaces' => (int) ($p['active_marketplaces'] ?? 0),
                'updated_at'          => $p['updated_at'] ?? '',
                'marketplaces'        => $mpDetails[(int) $p['id']] ?? [],
                'variant_count'       => (int) ($p['variant_count'] ?? 0),
                'has_variants'        => (int) ($p['has_variants'] ?? 0),
            ];
        }

        $mpProductCounts = $this->productModel->getMarketplaceProductCounts();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => $rows,
            'mpProductCounts' => $mpProductCounts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Inline fiyat/stok güncelleme + Trendyol API senkronizasyonu (AJAX)
     */
    public function inlineUpdate(Request $request): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $productId = (int) ($body['product_id'] ?? 0);
        $field = $body['field'] ?? '';
        $value = $body['value'] ?? null;

        if ($productId === 0 || !in_array($field, ['base_price', 'list_price', 'stock_qty'])) {
            $this->error('Geçersiz parametre');
        }

        $product = $this->productModel->find($productId);
        if ($product === false) {
            $this->error('Ürün bulunamadı');
        }

        // Yerel veritabanını güncelle
        $updateData = [];
        if ($field === 'stock_qty') {
            $updateData['stock_qty'] = max(0, (int) $value);

            // Stok hareketi kaydet
            $diff = (int)$value - (int)$product['stock_qty'];
            if ($diff !== 0) {
                $this->stockMovementModel->record(
                    $productId,
                    $diff > 0 ? 'adjustment_in' : 'adjustment_out',
                    'inline_edit',
                    $diff,
                    (int)$product['stock_qty'],
                    (int)$value,
                    null,
                    'Inline güncelleme (ürün listesi)'
                );
            }
        } else {
            $updateData[$field] = (float) $value;
        }

        try {
            $this->productModel->update($productId, $updateData);

            // Child ürünse parent stokunu güncelle (içe aktarılmış grupta ana ürünün stoğu kendisinindir)
            if ($field === 'stock_qty' && !empty($product['parent_id'])) {
                $parentId = (int) $product['parent_id'];
                if (!$this->productModel->parentKeepsOwnStock($parentId)) {
                    $summary = $this->productModel->getVariantSummary($parentId);
                    $this->productModel->update($parentId, ['stock_qty' => $summary['total_stock']]);
                }
            }

            // Bağlı pazaryerlerinde senkronize et
            $syncResults = $this->syncToMarketplaces($productId, $field, $value, $product);

            $this->logger->info(
                "Inline güncelleme: product={$productId}, {$field}={$value}, sync=" . count($syncResults) . " mp",
                'ProductController'
            );

            $this->success([
                'product_id' => $productId,
                'field'      => $field,
                'value'      => $updateData[$field] ?? $value,
                'sync'       => $syncResults,
            ], $field === 'stock_qty' ? 'Stok güncellendi' : 'Fiyat güncellendi');
        } catch (\Throwable $e) {
            $this->error('Güncelleme hatası: ' . $e->getMessage());
        }
    }

    /**
     * Tek ürünü belirli pazaryerlerine senkronize et (AJAX)
     */
    public function syncProduct(Request $request, string $id): void
    {
        $productId = (int) $id;
        $product   = $this->productModel->find($productId);
        if ($product === false) {
            $this->error('Ürün bulunamadı');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $targetMarketplaceIds = $this->extractTargetMarketplaceIds($body);
        $syncType = (string) ($body['sync_type'] ?? 'all');
        if (!in_array($syncType, ['all', 'products', 'stock', 'price'], true)) {
            $syncType = 'all';
        }

        $publishResults = [];
        $publishedMarketplaceIds = [];
        if ($syncType === 'all' || $syncType === 'products') {
            $publishResults = $this->publishUnlistedMarketplaces($productId, $product, $targetMarketplaceIds);
            foreach ($publishResults as $result) {
                if (in_array(($result['status'] ?? ''), ['success', 'wait'], true)) {
                    $publishedMarketplaceIds[] = (int) ($result['marketplace_id'] ?? 0);
                }
            }
        }

        $stockResults = [];
        if ($syncType === 'all' || $syncType === 'stock') {
            $stockResults = $this->syncToMarketplaces(
                $productId,
                'stock_qty',
                $product['stock_qty'],
                $product,
                $targetMarketplaceIds,
                $publishedMarketplaceIds
            );
        }

        $priceResults = [];
        if ($syncType === 'all' || $syncType === 'price') {
            $priceResults = $this->syncToMarketplaces(
                $productId,
                'base_price',
                $product['base_price'],
                $product,
                $targetMarketplaceIds,
                $publishedMarketplaceIds
            );
        }

        $allResults = array_merge($publishResults, $stockResults, $priceResults);

        $this->logger->info(
            "Ürün sync: product={$productId}, results=" . count($allResults),
            'ProductController'
        );

        $this->success([
            'product_id' => $productId,
            'results'    => $allResults,
        ], count($allResults) . ' pazaryeri işlemi tamamlandı');
    }

    public function show(Request $request, string $id): void
    {
        $product = $this->productModel->getDetail((int) $id);
        if ($product === false) {
            $this->response->notFound('Ürün bulunamadı');
        }

        $variants   = $this->productModel->getVariants((int) $id);
        $mpProducts = $this->mpProductModel->getByProduct((int) $id);
        $attributes = $this->pAttrValueModel->getByProduct((int) $id);
        $movements  = $this->stockMovementModel->getByProduct((int) $id, 20);

        // Pazaryeri durumu özeti
        $marketplaceStatus = $this->productModel->getWithMarketplaceStatus((int) $id);

        $this->view('products.show', [
            'pageTitle'         => $product['name'],
            'product'           => $product,
            'variants'          => $variants,
            'mpProducts'        => $mpProducts,
            'attributes'        => $attributes,
            'movements'         => $movements,
            'marketplaceStatus' => $marketplaceStatus,
            'xmlProfit' => !empty($product['xml_profile_id']) ? $this->container->get(\App\Services\Import\XmlProfileSettings::class)->forProfile((int)$product['xml_profile_id']) : null,
        ]);
    }

    public function create(Request $request): void
    {
        $categories = $this->categoryModel->getFlatTree();
        $brands = $this->brandModel->getActive();

        // Get all attributes for the form
        $attributes = $this->pAttrModel->all([], 'sort_order ASC, name ASC');

        // Load attribute values for select-type attributes
        foreach ($attributes as &$attr) {
            if (in_array($attr['input_type'], ['select', 'multi_select'])) {
                $attr['values'] = $this->pAttrModel->getWithValues((int) $attr['id']);
            } else {
                $attr['values'] = [];
            }
        }
        unset($attr);

        // AJAX: return form data for overlay popup
        if ($request->isAjax()) {
            $this->success([
                'product'           => null,
                'categories'        => $categories,
                'brands'            => $brands,
                'attributes'        => $attributes,
                'productAttributes' => [],
            ], 'Form verisi yüklendi');
        }

        $this->view('products.form', [
            'pageTitle'          => 'Yeni Ürün',
            'product'            => null,
            'categories'         => $categories,
            'brands'             => $brands,
            'attributes'         => $attributes,
            'productAttributes'  => [],
        ]);
    }

    public function store(Request $request): void
    {
        $isAjax = $request->isAjax();

        $validator = new ValidationHelper();
        $isValid = $validator->validate($request->all(), [
            'sku'         => 'required|max:100',
            'name'        => 'required|max:500',
            'base_price'  => 'required|numeric',
            'category_id' => 'required',
        ]);

        if (!$isValid) {
            if ($isAjax) {
                $this->error($validator->firstError() ?? 'Doğrulama hatası');
            }
            $this->redirectWithError('/products/create', $validator->firstError() ?? 'Doğrulama hatası');
        }

        // Parse images JSON
        $imagesJson = $request->post('images_json', '[]');
        $mainImage = $request->post('main_image', '');

        $data = [
            'sku'               => $request->post('sku'),
            'barcode'           => $request->post('barcode', ''),
            'name'              => $request->post('name'),
            'description'       => $request->post('description', ''),
            'short_description' => $request->post('short_description', ''),
            'category_id'       => $request->post('category_id') ?: null,
            'brand_id'          => $request->post('brand_id') ?: null,
            'base_price'        => (float) $request->post('base_price', 0),
            'list_price'        => (float) $request->post('list_price', 0),
            'cost_price'        => (float) $request->post('cost_price', 0),
            'stock_qty'         => (int) $request->post('stock_qty', 0),
            'tax_rate'          => (float) $request->post('tax_rate', 20),
            'weight'            => $request->post('weight') !== '' ? (float) $request->post('weight') : null,
            'desi'              => $request->post('desi') !== '' ? (float) $request->post('desi') : null,
            'unit'              => $request->post('unit', 'adet'),
            'min_stock_alert'   => (int) $request->post('min_stock_alert', 5),
            'is_active'         => $request->boolean('is_active', true) ? 1 : 0,
            'images_json'       => $imagesJson,
            'main_image'        => $mainImage,
            'seo_title'         => $request->post('seo_title', ''),
            'seo_description'   => $request->post('seo_description', ''),
            'technical_specs'   => $request->post('technical_specs', ''),
        ];

        try {
            $productId = $this->productModel->create($data);

            // Save attributes
            $this->saveProductAttributes($productId, $request);

            // Save variants (child products)
            $this->saveVariants($productId, $request);

            if ($isAjax) {
                $this->success(['id' => $productId], 'Ürün başarıyla oluşturuldu');
            }
            $this->redirectWithSuccess("/products/{$productId}", 'Ürün başarıyla oluşturuldu');
        } catch (\Throwable $e) {
            if ($isAjax) {
                $this->error('Hata: ' . $e->getMessage());
            }
            $this->redirectWithError('/products/create', 'Hata: ' . $e->getMessage());
        }
    }

    public function edit(Request $request, string $id): void
    {
        $product = $this->productModel->find((int) $id);
        if ($product === false) {
            if ($request->isAjax()) {
                $this->error('Ürün bulunamadı', 404);
            }
            $this->response->notFound('Ürün bulunamadı');
        }

        $categories = $this->categoryModel->getFlatTree();
        $brands = $this->brandModel->getActive();

        // Get all attributes
        $attributes = $this->pAttrModel->all([], 'sort_order ASC, name ASC');
        foreach ($attributes as &$attr) {
            if (in_array($attr['input_type'], ['select', 'multi_select'])) {
                $attr['values'] = $this->pAttrModel->getWithValues((int) $attr['id']);
            } else {
                $attr['values'] = [];
            }
        }
        unset($attr);

        // Get current product attribute values
        $productAttributes = $this->pAttrValueModel->getByProduct((int) $id);

        // Get current variants (child products)
        $variants = $this->productModel->getVariants((int) $id);

        // AJAX: return JSON for overlay popup
        if ($request->isAjax()) {
            $this->success([
                'product'           => $product,
                'categories'        => $categories,
                'brands'            => $brands,
                'attributes'        => $attributes,
                'productAttributes' => $productAttributes,
                'variants'          => $variants,
            ], 'Ürün verisi yüklendi');
        }

        $this->view('products.form', [
            'pageTitle'          => 'Ürün Düzenle: ' . $product['name'],
            'product'            => $product,
            'categories'         => $categories,
            'brands'             => $brands,
            'attributes'         => $attributes,
            'productAttributes'  => $productAttributes,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $isAjax = $request->isAjax();

        $validator = new ValidationHelper();
        $isValid = $validator->validate($request->all(), [
            'name'        => 'required|max:500',
            'base_price'  => 'required|numeric',
            'category_id' => 'required',
        ]);

        if (!$isValid) {
            if ($isAjax) {
                $this->error($validator->firstError() ?? 'Doğrulama hatası');
            }
            $this->redirectWithError("/products/{$id}/edit", $validator->firstError() ?? 'Doğrulama hatası');
        }

        // Parse images
        $imagesJson = $request->post('images_json', '');
        $mainImage = $request->post('main_image', '');

        $data = [
            'barcode'           => $request->post('barcode', ''),
            'name'              => $request->post('name'),
            'description'       => $request->post('description', ''),
            'short_description' => $request->post('short_description', ''),
            'category_id'       => $request->post('category_id') ?: null,
            'brand_id'          => $request->post('brand_id') ?: null,
            'base_price'        => (float) $request->post('base_price', 0),
            'list_price'        => (float) $request->post('list_price', 0),
            'cost_price'        => (float) $request->post('cost_price', 0),
            'tax_rate'          => (float) $request->post('tax_rate', 20),
            'weight'            => $request->post('weight') !== '' ? (float) $request->post('weight') : null,
            'desi'              => $request->post('desi') !== '' ? (float) $request->post('desi') : null,
            'unit'              => $request->post('unit', 'adet'),
            'min_stock_alert'   => (int) $request->post('min_stock_alert', 5),
            'is_active'         => $request->boolean('is_active', true) ? 1 : 0,
            'seo_title'         => $request->post('seo_title', ''),
            'seo_description'   => $request->post('seo_description', ''),
            'technical_specs'   => $request->post('technical_specs', ''),
        ];

        // Only update images if provided
        if ($imagesJson !== '') {
            $data['images_json'] = $imagesJson;
        }
        if ($mainImage !== '') {
            $data['main_image'] = $mainImage;
        }

        try {
            // Fiyat değişimini tespit için eski değerler (update ÖNCESİ)
            $oldProduct = $this->productModel->find((int) $id);

            $this->productModel->update((int) $id, $data);

            // Save attributes
            $this->saveProductAttributes((int) $id, $request);

            // Save variants (child products)
            $this->saveVariants((int) $id, $request);

            // Fiyat değiştiyse bağlı pazaryerlerine ANINDA gönder (inline düzenleme
            // ile aynı akış — updatePrice salePrice+listPrice birlikte iletir)
            $syncMsg = '';
            if ($oldProduct !== false
                && ((float) $oldProduct['base_price'] !== $data['base_price']
                    || (float) $oldProduct['list_price'] !== $data['list_price'])
            ) {
                try {
                    $fresh = $this->productModel->find((int) $id) ?: $oldProduct;
                    $syncResults = $this->syncToMarketplaces((int) $id, 'base_price', $data['base_price'], $fresh);
                    $okCount = count(array_filter($syncResults, static fn(array $r): bool => ($r['status'] ?? '') === 'success'));
                    if ($syncResults !== []) {
                        $syncMsg = " — {$okCount}/" . count($syncResults) . ' pazaryerine yeni fiyat gönderildi';
                    }
                } catch (\Throwable $e) {
                    $this->logger->error("Ürün güncelleme fiyat senkron hatası (#{$id}): " . $e->getMessage(), 'ProductController');
                    $syncMsg = ' — pazaryeri fiyat gönderimi başarısız (log\'a bakın)';
                }
            }

            // Aktiflik değiştiyse pazaryeri ilanlarına da yansıt
            // (panelde pasife alınan ürün pazaryerinde satışta kalmasın).
            if ($oldProduct !== false && (int) $oldProduct['is_active'] !== (int) $data['is_active']) {
                $listing = ['ok' => 0, 'fail' => 0, 'first_error' => ''];
                $this->collectListingStatus(
                    $this->propagateListingStatus((int) $id, $data['is_active'] === 1),
                    $listing
                );
                $syncMsg .= $this->listingStatusSummary($listing, $data['is_active'] === 1);
            }

            if ($isAjax) {
                $this->success(['id' => (int) $id], 'Ürün güncellendi' . $syncMsg);
            }
            $this->redirectWithSuccess("/products/{$id}", 'Ürün güncellendi' . $syncMsg);
        } catch (\Throwable $e) {
            if ($isAjax) {
                $this->error('Hata: ' . $e->getMessage());
            }
            $this->redirectWithError("/products/{$id}/edit", 'Hata: ' . $e->getMessage());
        }
    }

    public function adjustStock(Request $request, string $id): void
    {
        $qty  = $request->integer('qty', 0);
        $note = $request->post('note', 'Manuel stok ayarlama');

        if ($qty === 0) {
            $this->redirectWithError("/products/{$id}", 'Miktar sıfır olamaz');
        }

        try {
            $this->stockManager->manualAdjustment((int) $id, $qty, $note, $this->session->userId() ?? 0);
            $this->redirectWithSuccess("/products/{$id}", 'Stok güncellendi');
        } catch (\Throwable $e) {
            $this->redirectWithError("/products/{$id}", 'Stok hatası: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════
    // TOPLU İŞLEMLER (Bulk Actions)
    // ═══════════════════════════════════════════════════════

    /**
     * Toplu işlem: activate, deactivate, delete, sync
     */
    /**
     * Toplu işlem — 1. adım: kapsamı çöz, iş anahtarı üret.
     *
     * Eski akış gizli formla YALNIZCA sayfadaki seçimi gönderiyordu; "filtreye uyan
     * tümünü seç" bandı yalnız pazaryerine gönderime bağlıydı. Bu uç bandı tüm
     * toplu işlemlere açar ve on binlerce üründe zaman aşımına girmemek için
     * işi parçalara böler.
     */
    public function bulkJobPrepare(Request $request): void
    {
        try {
            $this->success($this->bulkJobService->prepare(
                (string) $request->post('action', ''),
                $this->idListesi((string) $request->post('product_ids', '')),
                $this->toplulukFiltreleri($request),
                (string) $request->post('all_matching', '') === '1'
            ));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Toplu işlem — 2. adım: bir parçayı uygula (istemci döngüde çağırır).
     */
    public function bulkJobChunk(Request $request): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $this->success($this->bulkJobService->runChunk((string) $request->post('token', '')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    public function bulkJobCancel(Request $request): void
    {
        $this->bulkJobService->cancel((string) $request->post('token', ''));
        $this->success(['cancelled' => true], 'İşlem durduruldu');
    }

    /**
     * Toplu işlemde kullanılacak filtreler — beyaz listeden geçirilir.
     *
     * @return array<string, mixed>
     */
    private function toplulukFiltreleri(Request $request): array
    {
        $ham = json_decode((string) $request->post('filters', '{}'), true);
        if (!is_array($ham)) {
            return [];
        }

        $izinli = [
            'search', 'category_id', 'brand_id', 'is_active',
            'stock_status', 'marketplace_id', 'show_archived',
            'source', 'xml_profile_id',
        ];

        return array_filter(
            array_intersect_key($ham, array_flip($izinli)),
            static fn($v): bool => $v !== '' && $v !== null
        );
    }

    /**
     * Virgüllü kimlik listesini tam sayı dizisine çevir.
     *
     * @return array<int, int>
     */
    private function idListesi(string $ham): array
    {
        return array_values(array_filter(
            array_map('intval', explode(',', $ham)),
            static fn(int $v): bool => $v > 0
        ));
    }

    public function bulkAction(Request $request): void
    {
        $action = $request->post('action', '');
        $idsRaw = $request->post('product_ids', '');
        $ids = array_filter(array_map('intval', explode(',', $idsRaw)));

        if (empty($ids)) {
            $this->redirectWithError('/products', 'Hiçbir ürün seçilmedi');
        }

        $count = count($ids);

        try {
            switch ($action) {
                case 'activate':
                    $listing = ['ok' => 0, 'fail' => 0, 'first_error' => ''];
                    foreach ($ids as $pid) {
                        $this->productModel->update($pid, ['is_active' => 1]);
                        $this->collectListingStatus($this->propagateListingStatus($pid, true), $listing);
                    }
                    $this->redirectWithSuccess(
                        '/products',
                        "{$count} ürün aktif edildi" . $this->listingStatusSummary($listing, true)
                    );
                    break;

                case 'deactivate':
                    $listing = ['ok' => 0, 'fail' => 0, 'first_error' => ''];
                    foreach ($ids as $pid) {
                        $this->productModel->update($pid, ['is_active' => 0]);
                        $this->collectListingStatus($this->propagateListingStatus($pid, false), $listing);
                    }
                    $this->redirectWithSuccess(
                        '/products',
                        "{$count} ürün pasif edildi" . $this->listingStatusSummary($listing, false)
                    );
                    break;

                case 'delete':
                    $deleted = 0;
                    foreach ($ids as $pid) {
                        try {
                            // Parent siliniyorsa child'ları da sil (cascade)
                            $prod = $this->productModel->find($pid);
                            if ($prod !== false && !empty($prod['has_variants'])) {
                                $children = $this->productModel->getVariants($pid);
                                foreach ($children as $child) {
                                    $this->productModel->delete((int) $child['id']);
                                }
                            }
                            $this->productModel->delete($pid);
                            $deleted++;
                        } catch (\Throwable $e) {
                            $this->logger->error("Ürün silinemedi #{$pid}: " . $e->getMessage());
                        }
                    }
                    $this->redirectWithSuccess('/products', "{$deleted}/{$count} ürün silindi");
                    break;

                case 'update_stock':
                    $newStock = max(0, (int) $request->post('bulk_value', 0));
                    $affectedParents = [];
                    foreach ($ids as $pid) {
                        $this->productModel->inlineUpdate($pid, ['stock_qty' => $newStock]);
                        // Parent propagasyonu için parent_id'leri topla
                        $p = $this->productModel->find($pid);
                        if ($p !== false && !empty($p['parent_id'])) {
                            $affectedParents[(int) $p['parent_id']] = true;
                        }
                    }
                    // Etkilenen parent'ların stoklarını güncelle (içe aktarılmış grupta ana ürünün stoğu kendisinindir)
                    foreach (array_keys($affectedParents) as $parentId) {
                        if ($this->productModel->parentKeepsOwnStock($parentId)) {
                            continue;
                        }
                        $summary = $this->productModel->getVariantSummary($parentId);
                        $this->productModel->update($parentId, ['stock_qty' => $summary['total_stock']]);
                    }
                    $this->redirectWithSuccess('/products', "{$count} ürünün stoğu {$newStock} olarak güncellendi");
                    break;

                case 'update_price':
                    $newPrice = (float) $request->post('bulk_value', 0);
                    foreach ($ids as $pid) {
                        $this->productModel->inlineUpdate($pid, ['base_price' => $newPrice]);
                    }
                    $this->redirectWithSuccess('/products', "{$count} ürünün fiyatı güncellendi");
                    break;

                case 'archive':
                    foreach ($ids as $pid) {
                        $this->productModel->update($pid, ['is_archived' => 1, 'archived_at' => date('Y-m-d H:i:s')]);
                    }
                    $this->redirectWithSuccess('/products', "{$count} ürün arşive taşındı");
                    break;

                case 'unarchive':
                    foreach ($ids as $pid) {
                        $this->productModel->update($pid, ['is_archived' => 0, 'archived_at' => null]);
                    }
                    $this->redirectWithSuccess('/products', "{$count} ürün arşivden çıkarıldı");
                    break;

                default:
                    $this->redirectWithError('/products', 'Geçersiz işlem');
            }
        } catch (\Throwable $e) {
            $this->redirectWithError('/products', 'Toplu işlem hatası: ' . $e->getMessage());
        }
    }

    /**
     * Ürünü seçili pazaryerlerinde satışa aç / satıştan kaldır (AJAX).
     *
     * Gövde: {"active": true|false, "marketplace_ids": [1,2]}
     */
    public function listingStatus(Request $request, string $id): void
    {
        $productId = (int) $id;
        $product   = $this->productModel->find($productId);
        if ($product === false) {
            $this->error('Ürün bulunamadı');
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!array_key_exists('active', $body)) {
            $this->error('Durum bilgisi (active) gönderilmedi');
        }

        $active  = filter_var($body['active'], FILTER_VALIDATE_BOOLEAN);
        $targets = $this->extractTargetMarketplaceIds($body);

        $results = $this->listingStatusService->apply($productId, $active, $targets);

        if ($results === []) {
            $this->error('Bu ürün hiçbir pazaryerinde yayınlı görünmüyor');
        }

        $ok     = count(array_filter($results, fn(array $r): bool => $r['status'] === 'success'));
        $failed = count($results) - $ok;

        $this->success(
            ['product_id' => $productId, 'active' => $active, 'results' => $results],
            $failed === 0
                ? ($active ? "{$ok} pazaryerinde satışa açıldı" : "{$ok} pazaryerinde satışa kapatıldı")
                : "{$ok} başarılı, {$failed} hatalı"
        );
    }

    /**
     * Ürün durumunu bağlı pazaryerlerine ilet (hata olursa akışı kesmez).
     *
     * @return array<int, array<string, mixed>>
     */
    private function propagateListingStatus(int $productId, bool $active): array
    {
        try {
            return $this->listingStatusService->apply($productId, $active);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Pazaryeri satış durumu iletilemedi (ürün #{$productId}): " . $e->getMessage(),
                'ProductController'
            );

            return [[
                'marketplace_id' => 0,
                'slug'           => '',
                'display_name'   => '',
                'status'         => 'error',
                'message'        => $e->getMessage(),
            ]];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @param array{ok: int, fail: int, first_error: string} $acc
     */
    private function collectListingStatus(array $results, array &$acc): void
    {
        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'success') {
                $acc['ok']++;
                continue;
            }

            $acc['fail']++;
            if ($acc['first_error'] === '') {
                $acc['first_error'] = trim(
                    (string) ($result['display_name'] ?? '') . ': ' . (string) ($result['message'] ?? ''),
                    ': '
                );
            }
        }
    }

    /**
     * @param array{ok: int, fail: int, first_error: string} $acc
     */
    private function listingStatusSummary(array $acc, bool $active): string
    {
        if ($acc['ok'] === 0 && $acc['fail'] === 0) {
            return '';
        }

        $verb = $active ? 'satışa açıldı' : 'satışa kapatıldı';
        $text = " — {$acc['ok']} pazaryeri ilanı {$verb}";

        if ($acc['fail'] > 0) {
            $text .= ", {$acc['fail']} hatalı ({$acc['first_error']})";
        }

        return $text;
    }

    // ═══════════════════════════════════════════════════════
    // MANUEL SENKRONİZASYON
    // ═══════════════════════════════════════════════════════

    /**
     * Manuel senkronizasyon tetikle (AJAX)
     */
    public function manualSync(Request $request): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $marketplaceId = (int) ($body['marketplace_id'] ?? 0);
        $syncType = $body['sync_type'] ?? 'products';

        if ($marketplaceId === 0) {
            if ($syncType !== 'products') {
                $this->error('Pazaryeri belirtilmedi');
            }

            $summary = ['imported' => 0, 'updated' => 0, 'marketplaces' => [], 'errors' => []];
            foreach ($this->marketplaceModel->getActive() as $mp) {
                try {
                    $mpResult = $this->syncMarketplaceProductsIntoCache((int) $mp['id'], $mp);
                    $summary['imported'] += $mpResult['imported'];
                    $summary['updated'] += $mpResult['updated'];
                    $summary['marketplaces'][$mp['slug'] ?? (string) $mp['id']] = $mpResult;
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'marketplace' => $mp['slug'] ?? (string) $mp['id'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            if (!empty($summary['errors']) && $summary['imported'] + $summary['updated'] === 0) {
                $this->error('Senkronizasyon hatası: ' . ($summary['errors'][0]['error'] ?? 'Pazaryeri ürünleri çekilemedi'));
            }

            $this->success(
                $summary,
                "{$summary['imported']} yeni ürün eklendi, {$summary['updated']} güncellendi"
            );
            return;
        }

        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false) {
            $this->error('Pazaryeri bulunamadı');
        }

        try {
            $service = $this->getMarketplaceService($marketplace['slug'] ?? '');
            if (!$service) {
                $this->error('Pazaryeri servisi bulunamadı: ' . ($marketplace['slug'] ?? ''));
            }

            if ($syncType === 'products') {
                $imported = 0;
                $updated = 0;
                $page = 0;
                $guard = 0;

                while (true) {
                    $result = $service->importProducts($page, 100);

                    foreach ($result['products'] ?? [] as $prod) {
                        $barcode = $prod['barcode'] ?? '';
                        $mpSku = $prod['marketplace_sku'] ?? '';
                        $mpProdId = $prod['marketplace_product_id'] ?? '';

                        // marketplace_products tablosunda var mı?
                        $existing = $this->mpProductModel->findByMarketplaceProductId(
                            $marketplaceId, $mpProdId
                        );

                        // İlk resmi al
                        $firstImage = '';
                        if (!empty($prod['images']) && is_array($prod['images'])) {
                            $firstImage = $prod['images'][0]['url'] ?? '';
                        }

                        $mpData = [
                            'marketplace_id'          => $marketplaceId,
                            'marketplace_sku'         => $mpSku,
                            'marketplace_product_id'  => $mpProdId,
                            'marketplace_barcode'     => $barcode,
                            'marketplace_category_id' => $prod['category_id'] ?? '',
                            'marketplace_brand_id'    => $prod['brand_id'] ?? '',
                            'product_name'            => $prod['name'] ?? '',
                            'main_image'              => $firstImage,
                            'marketplace_category_name' => $prod['category_name'] ?? '',
                            'marketplace_brand_name'    => $prod['brand_name'] ?? '',
                            'last_price_sent'         => $prod['price'] ?? 0,
                            'last_stock_sent'         => $prod['stock'] ?? 0,
                            'is_active'               => 1,
                            'is_listed'               => 1,
                        ];

                        if ($existing) {
                            $this->mpProductModel->update((int)$existing['id'], $mpData);

                            // Mevcut eşleşmiş sistem ürününde resim yoksa güncelle
                            if ($firstImage && !empty($existing['product_id'])) {
                                $sysProduct = $this->productModel->find((int)$existing['product_id']);
                                if ($sysProduct && empty($sysProduct['main_image'])) {
                                    $this->productModel->update((int)$sysProduct['id'], ['main_image' => $firstImage]);
                                }
                            }

                            // Pazaryeri ürününde resim yoksa eşleşen sistem ürününün resmini kullan
                            if (!$firstImage && !empty($existing['product_id'])) {
                                $sysProduct = $sysProduct ?? $this->productModel->find((int)$existing['product_id']);
                                if ($sysProduct && !empty($sysProduct['main_image'])) {
                                    $this->mpProductModel->update((int)$existing['id'], ['main_image' => $sysProduct['main_image']]);
                                }
                            }

                            $updated++;
                        } else {
                            // Barkod/SKU ile otomatik eşleştirme
                            $systemProduct = null;
                            if ($barcode) $systemProduct = $this->productModel->findByBarcode($barcode);
                            if (!$systemProduct && $mpSku) $systemProduct = $this->productModel->findBySku($mpSku);

                            $mpData['product_id'] = $systemProduct ? (int)$systemProduct['id'] : null;

                            // Sistem ürününde resim yoksa pazaryeri resmini ata
                            if ($systemProduct && empty($systemProduct['main_image']) && $firstImage) {
                                $this->productModel->update((int)$systemProduct['id'], ['main_image' => $firstImage]);
                            }

                            // Pazaryeri ürününde resim yoksa eşleşen sistem ürününün resmini kullan
                            if (!$firstImage && $systemProduct && !empty($systemProduct['main_image'])) {
                                $mpData['main_image'] = $systemProduct['main_image'];
                            }

                            $this->mpProductModel->create($mpData);
                            $imported++;
                        }
                    }

                    $next = $this->nextImportPage($result, $page);
                    if ($next === null || ++$guard > self::MAX_IMPORT_PAGES) {
                        break;
                    }
                    $page = $next;
                }

                $this->logger->info(
                    "Manuel sync tamamlandı: mp={$marketplaceId}, imported={$imported}, updated={$updated}",
                    'Sync'
                );

                $this->success(
                    ['imported' => $imported, 'updated' => $updated],
                    "{$imported} yeni ürün eklendi, {$updated} güncellendi"
                );
            } else {
                $this->success(['sync_type' => $syncType], 'Senkronizasyon başlatıldı');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Manuel sync hatası: ' . $e->getMessage(), 'Sync');
            $this->error('Senkronizasyon hatası: ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════
    // ÖZELLIK KAYDETME YARDIMCISI
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün özelliklerini kaydet (form submit sonrası)
     */
    private function syncMarketplaceProductsIntoCache(int $marketplaceId, array $marketplace): array
    {
        $service = $this->getMarketplaceService($marketplace['slug'] ?? '');
        if (!$service || !method_exists($service, 'importProducts')) {
            throw new \RuntimeException('Pazaryeri servisi bulunamadı: ' . ($marketplace['slug'] ?? ''));
        }

        $imported = 0;
        $updated = 0;
        $page = 0;
        $guard = 0;

        while (true) {
            $result = $service->importProducts($page, 100);

            foreach ($result['products'] ?? [] as $prod) {
                $barcode = $prod['barcode'] ?? '';
                $mpSku = $prod['marketplace_sku'] ?? '';
                $mpProdId = $prod['marketplace_product_id'] ?? '';

                if ($mpProdId === '' && $mpSku === '' && $barcode === '') {
                    continue;
                }

                $existing = $mpProdId !== ''
                    ? $this->mpProductModel->findByMarketplaceProductId($marketplaceId, $mpProdId)
                    : false;
                if ($existing === false && $mpSku !== '') {
                    $existing = $this->mpProductModel->findByMarketplaceSku($marketplaceId, $mpSku);
                }

                $firstImage = '';
                if (!empty($prod['images']) && is_array($prod['images'])) {
                    $firstImage = $prod['images'][0]['url'] ?? '';
                }

                $mpData = [
                    'marketplace_id'            => $marketplaceId,
                    'marketplace_sku'           => $mpSku,
                    'marketplace_product_id'    => $mpProdId,
                    'marketplace_barcode'       => $barcode,
                    'marketplace_category_id'   => $prod['category_id'] ?? '',
                    'marketplace_brand_id'      => $prod['brand_id'] ?? '',
                    'product_name'              => $prod['name'] ?? '',
                    'main_image'                => $firstImage,
                    'marketplace_category_name' => $prod['category_name'] ?? '',
                    'marketplace_brand_name'    => $prod['brand_name'] ?? '',
                    'last_price_sent'           => $prod['price'] ?? 0,
                    'is_active'                 => 1,
                    'is_listed'                 => 1,
                ];

                if (array_key_exists('stock', $prod)) {
                    $mpData['last_stock_sent'] = max(0, (int) $prod['stock']);
                }

                if ($existing) {
                    $this->mpProductModel->update((int) $existing['id'], $mpData);
                    $updated++;
                    continue;
                }

                $systemProduct = null;
                if ($barcode) {
                    $systemProduct = $this->productModel->findByBarcode($barcode);
                }
                if (!$systemProduct && $mpSku) {
                    $systemProduct = $this->productModel->findBySku($mpSku);
                }

                $mpData['product_id'] = $systemProduct ? (int) $systemProduct['id'] : null;

                if ($systemProduct && empty($systemProduct['main_image']) && $firstImage) {
                    $this->productModel->update((int) $systemProduct['id'], ['main_image' => $firstImage]);
                }

                if (!$firstImage && $systemProduct && !empty($systemProduct['main_image'])) {
                    $mpData['main_image'] = $systemProduct['main_image'];
                }

                $this->mpProductModel->create($mpData);
                $imported++;
            }

            $next = $this->nextImportPage($result, $page);
            if ($next === null || ++$guard > self::MAX_IMPORT_PAGES) {
                break;
            }
            $page = $next;
        }

        return ['imported' => $imported, 'updated' => $updated];
    }

    /**
     * Bir sonraki import sayfası/imleci.
     *
     * Shopify gibi platformlar sayfa numarası değil İMLEÇ (since_id) kullanır;
     * eskiden sayfa sayacı +1 artırıldığı için imleç bozuluyor ve ilk sayfadan
     * sonrası hiç çekilemiyordu (mağazada binlerce ürün varken ~150 ürün gelmesi).
     *
     * @param array<string, mixed> $result
     * @return int|null null = son sayfa
     */
    private function nextImportPage(array $result, int $currentPage): ?int
    {
        if (array_key_exists('done', $result)) {
            if (!empty($result['done'])) {
                return null;
            }
            $next = $result['nextPage'] ?? null;
            return $next !== null ? (int) $next : $currentPage + 1;
        }

        if (array_key_exists('nextPage', $result)) {
            return $result['nextPage'] !== null ? (int) $result['nextPage'] : null;
        }

        $totalPages = max(1, (int) ($result['totalPages'] ?? 1));
        if (count($result['products'] ?? []) === 0) {
            return null;
        }

        return ($currentPage + 1) < $totalPages ? $currentPage + 1 : null;
    }

    private function saveProductAttributes(int $productId, Request $request): void
    {
        // Standard attributes
        $attrs = $request->post('attrs');
        if (is_array($attrs)) {
            foreach ($attrs as $attrId => $value) {
                $attrId = (int) $attrId;
                if ($attrId <= 0) continue;

                if (is_numeric($value) && $value > 0) {
                    // It might be an attribute_value_id (from select)
                    $this->pAttrValueModel->setProductAttribute($productId, $attrId, (int) $value, null);
                } elseif (!empty($value)) {
                    // Custom text value
                    $this->pAttrValueModel->setProductAttribute($productId, $attrId, null, (string) $value);
                }
            }
        }

        // Custom attributes — create attribute if not exists
        $customAttrs = $request->post('custom_attrs');
        if (is_array($customAttrs)) {
            foreach ($customAttrs as $custom) {
                $name = trim($custom['name'] ?? '');
                $value = trim($custom['value'] ?? '');
                if (empty($name) || empty($value)) continue;

                // Find or create attribute
                $existingAttrId = $this->pAttrValueModel->findAttributeIdByName($name);
                if ($existingAttrId === null) {
                    // Create new attribute
                    $existingAttrId = $this->pAttrModel->create([
                        'name'       => $name,
                        'slug'       => mb_strtolower(str_replace(' ', '-', $name)),
                        'input_type' => 'text',
                    ]);
                }

                $this->pAttrValueModel->setProductAttribute($productId, $existingAttrId, null, $value);
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // VARYANT KAYDETME (Child Products)
    // ═══════════════════════════════════════════════════════

    /**
     * Formdan gelen variants[] dizisini parent-child yapısına kaydet.
     * Her varyant satırı ayrı bir child ürün olarak oluşturulur.
     * Mevcut child'lar güncellenir, formdaki listede olmayanlar silinir.
     */
    private function saveVariants(int $parentId, Request $request): void
    {
        $variants = $request->post('variants');
        if (!is_array($variants)) {
            $variants = [];
        }

        // Filter out empty rows (name, sku, barcode all blank)
        $variants = array_filter($variants, function ($v) {
            return !empty(trim($v['name'] ?? '')) || !empty(trim($v['sku'] ?? '')) || !empty(trim($v['barcode'] ?? ''));
        });

        $parent = $this->productModel->find($parentId);
        if ($parent === false) {
            return;
        }

        // Get existing child products
        $existingChildren = $this->productModel->getVariants($parentId);
        $existingByBarcode = [];
        $existingBySku = [];
        foreach ($existingChildren as $child) {
            if (!empty($child['barcode'])) {
                $existingByBarcode[$child['barcode']] = $child;
            }
            if (!empty($child['sku'])) {
                $existingBySku[$child['sku']] = $child;
            }
        }

        $processedIds = [];

        foreach ($variants as $v) {
            $vName    = trim($v['name'] ?? '');
            $vSku     = trim($v['sku'] ?? '');
            $vBarcode = trim($v['barcode'] ?? '');
            $vPrice   = (float) ($v['price'] ?? 0);
            $vStock   = max(0, (int) ($v['stock'] ?? 0)); // Negatif stok koruması

            // Try to match existing child by barcode or SKU
            $existingChild = null;
            if ($vBarcode !== '' && isset($existingByBarcode[$vBarcode])) {
                $existingChild = $existingByBarcode[$vBarcode];
            } elseif ($vSku !== '' && isset($existingBySku[$vSku])) {
                $existingChild = $existingBySku[$vSku];
            }

            $childData = [
                'name'              => $vName ?: ($parent['name'] . ' - ' . ($vSku ?: $vBarcode)),
                'sku'               => $vSku ?: ($parent['sku'] . '-V' . (count($processedIds) + 1)),
                'barcode'           => $vBarcode,
                'base_price'        => $vPrice > 0 ? $vPrice : (float) ($parent['base_price'] ?? 0),
                'list_price'        => $vPrice > 0 ? $vPrice : (float) ($parent['list_price'] ?? 0),
                'stock_qty'         => $vStock,
                'parent_id'         => $parentId,
                'has_variants'      => 0,
                'is_active'         => (int) ($parent['is_active'] ?? 1),
                'category_id'       => $parent['category_id'] ?? null,
                'brand_id'          => $parent['brand_id'] ?? null,
                'tax_rate'          => (float) ($parent['tax_rate'] ?? 20),
                'description'       => $parent['description'] ?? '',
                'images_json'       => $parent['images_json'] ?? '[]',
                'main_image'        => $parent['main_image'] ?? '',
                'variant_attributes' => json_encode(['label' => $vName], JSON_UNESCAPED_UNICODE),
            ];

            if ($existingChild) {
                // Update existing child
                $this->productModel->update((int) $existingChild['id'], $childData);
                $processedIds[] = (int) $existingChild['id'];
            } else {
                // Create new child
                $newId = $this->productModel->create($childData);
                $processedIds[] = $newId;
            }
        }

        // Delete children that are no longer in the form
        foreach ($existingChildren as $child) {
            if (!in_array((int) $child['id'], $processedIds, true)) {
                $this->productModel->delete((int) $child['id']);
            }
        }

        // Update parent has_variants flag
        $hasVariants = count($processedIds) > 0 ? 1 : 0;
        $parentUpdate = ['has_variants' => $hasVariants];

        // Aggregate stock from children to parent (single query, no N+1).
        // İçe aktarılmış grupta ana ürün de satılan bir varyanttır: stoğu kendisinindir.
        if ($hasVariants && !$this->productModel->parentKeepsOwnStock($parentId)) {
            $summary = $this->productModel->getVariantSummary($parentId);
            $parentUpdate['stock_qty'] = $summary['total_stock'];
        }

        $this->productModel->update($parentId, $parentUpdate);
    }

    // ═══════════════════════════════════════════════════════
    // TOPLU STOK İŞLEMLERİ (Phase 4)
    // ═══════════════════════════════════════════════════════

    /**
     * Toplu stok güncelleme (JSON body)
     */
    public function bulkStock(Request $request): void
    {
        $itemsRaw = $request->post('items', '');
        $note = $request->post('note', 'Toplu stok güncelleme');

        // JSON string veya array olabilir
        $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

        if (!is_array($items) || empty($items)) {
            $this->error('Geçerli ürün listesi gerekli (items: [{product_id, qty}])');
        }

        try {
            $result = $this->stockManager->bulkAdjustment($items, $note, $this->session->userId() ?? 0);

            if ($request->isAjax()) {
                $this->success($result, "Toplu stok: {$result['success']} başarılı, {$result['failed']} hatalı");
            }

            $msg = "Toplu stok güncelleme: {$result['success']} başarılı";
            if ($result['failed'] > 0) {
                $msg .= ", {$result['failed']} hatalı";
            }
            $this->redirectWithSuccess('/products', $msg);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Toplu stok hatası: ' . $e->getMessage());
            }
            $this->redirectWithError('/products', 'Toplu stok hatası: ' . $e->getMessage());
        }
    }

    /**
     * CSV'den toplu stok güncelleme
     */
    public function bulkStockCsv(Request $request): void
    {
        $file = $request->file('csv_file');
        if ($file === null || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithError('/products', 'CSV dosyası yüklenemedi');
        }

        $delimiter = $request->post('delimiter', ';');

        try {
            $result = $this->stockManager->bulkAdjustmentFromCsv($file['tmp_name'], $delimiter, $this->session->userId() ?? 0);

            $msg = "CSV stok güncelleme: {$result['success']} başarılı";
            if ($result['failed'] > 0) {
                $msg .= ", {$result['failed']} hatalı";
            }
            $this->redirectWithSuccess('/products', $msg);
        } catch (\Throwable $e) {
            $this->redirectWithError('/products', 'CSV stok hatası: ' . $e->getMessage());
        }
    }

    /**
     * Stok hareketleri listesi (filtreli)
     */
    public function stockMovements(Request $request): void
    {
        $filters = [
            'product_id'    => $request->query('product_id'),
            'movement_type' => $request->query('movement_type'),
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
        ];

        $page = $request->integer('page', 1);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['product_id'])) {
            $where[] = "sm.product_id = :product_id";
            $params['product_id'] = $filters['product_id'];
        }

        if (!empty($filters['movement_type'])) {
            $where[] = "sm.movement_type = :movement_type";
            $params['movement_type'] = $filters['movement_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "sm.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "sm.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = implode(' AND ', $where);

        $countRow = $this->stockMovementModel->countFiltered($whereStr, $params);
        $total = $countRow;

        $movements = $this->stockMovementModel->getFiltered($whereStr, $params, $perPage, $offset);

        $this->view('products.stock_movements', [
            'pageTitle'  => 'Stok Hareketleri',
            'movements'  => $movements,
            'filters'    => $filters,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // FİYAT KURALLARI YÖNETİMİ (Phase 3)
    // ═══════════════════════════════════════════════════════

    /**
     * Fiyat kuralları listesi
     */
    public function priceRules(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id', 0);
        $marketplaces = $this->marketplaceModel->getAll();

        if ($marketplaceId === 0 && !empty($marketplaces)) {
            $marketplaceId = (int) $marketplaces[0]['id'];
        }

        $rules = [];
        if ($marketplaceId > 0) {
            $rules = $this->priceRuleModel->getByMarketplace($marketplaceId);
        }

        $categories = $this->categoryModel->getFlatTree();

        $this->view('products.price_rules', [
            'pageTitle'     => 'Fiyat Kuralları',
            'marketplaces'  => $marketplaces,
            'marketplaceId' => $marketplaceId,
            'rules'         => $rules,
            'categories'    => $categories,
        ]);
    }

    /**
     * Yeni fiyat kuralı oluştur
     */
    public function storePriceRule(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');

        $data = [
            'name'           => $request->post('name', ''),
            'marketplace_id' => $marketplaceId,
            'rule_type'      => $request->post('rule_type', 'percent'),
            'direction'      => $request->post('direction', 'increase'),
            'value'          => (float) $request->post('value', 0),
            'min_price'      => $request->post('min_price') !== '' ? (float) $request->post('min_price') : null,
            'max_price'      => $request->post('max_price') !== '' ? (float) $request->post('max_price') : null,
            'apply_to'       => $request->post('apply_to', 'all'),
            'reference_id'   => $request->post('reference_id') ?: null,
            'priority'       => $request->integer('priority', 10),
            'is_active'      => $request->boolean('is_active', true) ? 1 : 0,
        ];

        if ($data['name'] === '' || $marketplaceId === 0) {
            $this->redirectWithError('/price-rules?marketplace_id=' . $marketplaceId, 'Kural adı ve pazaryeri zorunludur');
        }

        try {
            $this->priceRuleModel->create($data);
            $this->redirectWithSuccess('/price-rules?marketplace_id=' . $marketplaceId, 'Fiyat kuralı oluşturuldu');
        } catch (\Throwable $e) {
            $this->redirectWithError('/price-rules?marketplace_id=' . $marketplaceId, 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * Fiyat kuralı güncelle
     */
    public function updatePriceRule(Request $request, string $id): void
    {
        $rule = $this->priceRuleModel->find((int) $id);
        if ($rule === false) {
            $this->redirectWithError('/price-rules', 'Kural bulunamadı');
        }

        $marketplaceId = (int) $rule['marketplace_id'];

        $data = [
            'name'         => $request->post('name', $rule['name']),
            'rule_type'    => $request->post('rule_type', $rule['rule_type']),
            'direction'    => $request->post('direction', $rule['direction']),
            'value'        => (float) $request->post('value', $rule['value']),
            'min_price'    => $request->post('min_price') !== '' ? (float) $request->post('min_price') : null,
            'max_price'    => $request->post('max_price') !== '' ? (float) $request->post('max_price') : null,
            'apply_to'     => $request->post('apply_to', $rule['apply_to']),
            'reference_id' => $request->post('reference_id') ?: null,
            'priority'     => $request->integer('priority', (int) $rule['priority']),
            'is_active'    => $request->boolean('is_active', true) ? 1 : 0,
        ];

        $this->priceRuleModel->update((int) $id, $data);
        $this->redirectWithSuccess('/price-rules?marketplace_id=' . $marketplaceId, 'Fiyat kuralı güncellendi');
    }

    /**
     * Fiyat kuralı sil
     */
    public function deletePriceRule(Request $request, string $id): void
    {
        $rule = $this->priceRuleModel->find((int) $id);
        if ($rule === false) {
            $this->redirectWithError('/price-rules', 'Kural bulunamadı');
        }

        $marketplaceId = (int) $rule['marketplace_id'];
        $this->priceRuleModel->delete((int) $id);

        if ($request->isAjax()) {
            $this->success([], 'Kural silindi');
        }

        $this->redirectWithSuccess('/price-rules?marketplace_id=' . $marketplaceId, 'Fiyat kuralı silindi');
    }

    /**
     * AJAX: Fiyat kuralı önizleme
     */
    public function previewPriceRule(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $productIdsRaw = $request->post('product_ids', '');

        $productIds = array_filter(array_map('intval', explode(',', $productIdsRaw)));

        if (empty($productIds) || $marketplaceId === 0) {
            $this->error('Ürün ve pazaryeri seçimi zorunludur');
        }

        $results = $this->priceRuleEngine->previewPrices($productIds, $marketplaceId);
        $this->success(['prices' => $results]);
    }


    // ═══════════════════════════════════════════════════════
    // PAZARYERI STOK/FİYAT GÖNDERME
    // ═══════════════════════════════════════════════════════

    /**
     * Ürünün bağlı olduğu pazaryerlerine stok veya fiyat gönder
     */
    private function syncToMarketplaces(
        int $productId,
        string $field,
        mixed $value,
        array $product,
        ?array $onlyMarketplaceIds = null,
        array $excludeMarketplaceIds = []
    ): array
    {
        $mpDetails = $this->mpProductModel->getMarketplaceDetailsForProducts([$productId]);
        $mpList    = $mpDetails[$productId] ?? [];

        if (empty($mpList)) {
            return [];
        }

        $results = [];

        foreach ($mpList as $mp) {
            $slug        = $mp['slug'] ?? '';
            $displayName = $mp['display_name'] ?? $slug;
            $mpId        = (int)$mp['marketplace_id'];
            if ($onlyMarketplaceIds !== null && !in_array($mpId, $onlyMarketplaceIds, true)) {
                continue;
            }
            if (in_array($mpId, $excludeMarketplaceIds, true)) {
                continue;
            }
            if ((int) ($mp['is_active'] ?? 1) !== 1) {
                continue;
            }

            try {
                if (!$this->marketplaceProductCanReceiveStockPrice($mp)) {
                    $results[] = [
                        'marketplace_id' => $mpId,
                        'slug'           => $slug,
                        'display_name'   => $displayName,
                        'status'         => 'error',
                        'message'        => 'Ürün bu pazaryerinde henüz yayınlı değil. Önce ürün kartını yayınlayın.',
                    ];
                    continue;
                }

                $service = $this->getMarketplaceService($slug);
                if (!$service) {
                    $results[] = [
                        'marketplace_id' => $mpId,
                        'slug'           => $slug,
                        'display_name'   => $displayName,
                        'status'         => 'error',
                        'message'        => 'Servis bulunamadı',
                    ];
                    continue;
                }

                // Trendyol formatında stok/fiyat item'ı oluştur
                $mpProductId = (string) (($mp['marketplace_product_id'] ?? '') ?: ($product['source_id'] ?? ''));
                $barcode = (string) (($mp['marketplace_barcode'] ?? '') ?: ($product['barcode'] ?? ''));
                $sku = (string) (($mp['marketplace_sku'] ?? '') ?: ($product['sku'] ?? ''));

                // Pazaryeri fiyat farkı yüzdesini al
                $mpRecord = $this->marketplaceModel->find($mpId);
                $mpExtraConfig = json_decode($mpRecord['extra_config_json'] ?? '{}', true) ?: [];
                $priceAdjPct = (float)($mpExtraConfig['price_adjustment_percent'] ?? 0);

                if ($field === 'stock_qty') {
                    $items = [[
                        'id'       => $mpProductId,
                        'marketplace_product_id' => $mpProductId,
                        'hbSku'    => $mpProductId,
                        'sku'      => $sku,
                        'barcode'  => $barcode,
                        'quantity' => (int)$value,
                    ]];
                    $apiResult = $service->updateStock($items);
                } else {
                    // Fiyat farkı uygula
                    $basePrice = (float)$value;
                    $adjustedPrice = $priceAdjPct !== 0.0
                        ? round($basePrice * (1 + $priceAdjPct / 100), 2)
                        : $basePrice;
                    
                    $listPrice = (float)($product['list_price'] ?: $value);
                    $adjustedListPrice = $priceAdjPct !== 0.0
                        ? round($listPrice * (1 + $priceAdjPct / 100), 2)
                        : $listPrice;

                    $items = [[
                        'id'        => $mpProductId,
                        'marketplace_product_id' => $mpProductId,
                        'hbSku'     => $mpProductId,
                        'sku'       => $sku,
                        'barcode'   => $barcode,
                        'salePrice' => $adjustedPrice,
                        'listPrice' => $adjustedListPrice,
                    ]];
                    $apiResult = $service->updatePrice($items);
                }

                $success = ($apiResult['success'] ?? 0) > 0 && ($apiResult['failed'] ?? 0) === 0;

                // marketplace_products tablosunu güncelle.
                // last_stock_sent / last_price_sent SADECE gönderim başarılıysa
                // damgalanır; başarısız gönderimi damgalamak bir sonraki senkronda
                // "değişiklik yok" sayılmasına ve düzeltmenin hiç gitmemesine yol açar.
                $updateFields = [
                    'last_synced_at' => date('Y-m-d H:i:s'),
                    'sync_error'     => $success ? null : mb_substr($this->formatApiErrors($apiResult), 0, 500),
                ];
                if ($success) {
                    if ($field === 'stock_qty') {
                        $updateFields['last_stock_sent'] = (int) $value;
                    } else {
                        $updateFields['last_price_sent'] = $adjustedPrice ?? (float) $value;
                    }
                }

                // mpProduct update
                $this->mpProductModel->updateByProductAndMarketplace($productId, $mpId, $updateFields);

                // Mesaj oluştur
                if ($field === 'stock_qty') {
                    $msg = "Stok: {$value}";
                } else {
                    $msg = $priceAdjPct !== 0.0
                        ? "Fiyat: {$value}₺ → {$adjustedPrice}₺ (%{$priceAdjPct})"
                        : "Fiyat: {$value}₺";
                }

                // Pazaryeri işlemi asenkronsa (n11 task / HB upload) bunu gizleme.
                if ($success && !empty($apiResult['pending'])) {
                    $msg .= ' — ' . (string) ($apiResult['notice'] ?? 'Pazaryerinde işleniyor');
                }

                $results[] = [
                    'marketplace_id' => $mpId,
                    'slug'           => $slug,
                    'display_name'   => $displayName,
                    'status'         => $success ? 'success' : 'error',
                    'message'        => $success ? $msg : ('Hata: ' . $this->formatApiErrors($apiResult)),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'marketplace_id' => $mpId,
                    'slug'           => $slug,
                    'display_name'   => $displayName,
                    'status'         => 'error',
                    'message'        => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int>|null
     */
    private function extractTargetMarketplaceIds(array $body): ?array
    {
        $ids = [];

        if (!empty($body['marketplace_ids']) && is_array($body['marketplace_ids'])) {
            $ids = array_map('intval', $body['marketplace_ids']);
        } elseif (!empty($body['marketplace_id'])) {
            $ids = [(int) $body['marketplace_id']];
        }

        $ids = array_values(array_unique(array_filter($ids, fn(int $id): bool => $id > 0)));
        return empty($ids) ? null : $ids;
    }

    /**
     * Ürün pazaryerinde yoksa önce tam ürün kartını gönderir.
     *
     * @param array<string, mixed> $product
     * @param array<int>|null $onlyMarketplaceIds
     * @return array<int, array<string, mixed>>
     */
    private function publishUnlistedMarketplaces(int $productId, array $product, ?array $onlyMarketplaceIds = null): array
    {
        $mpDetails = $this->mpProductModel->getMarketplaceDetailsForProducts([$productId]);
        $mpList = $mpDetails[$productId] ?? [];
        $results = [];

        foreach ($mpList as $mp) {
            $mpId = (int) ($mp['marketplace_id'] ?? 0);
            if ($onlyMarketplaceIds !== null && !in_array($mpId, $onlyMarketplaceIds, true)) {
                continue;
            }
            if ((int) ($mp['is_active'] ?? 1) !== 1) {
                continue;
            }
            if ($this->marketplaceProductCanReceiveStockPrice($mp)) {
                continue;
            }

            $slug = (string) ($mp['slug'] ?? '');
            $displayName = (string) ($mp['display_name'] ?? $slug);

            try {
                $publish = $this->publishService->publishProducts([$productId], $mpId);
                // Hepsiburada içe aktarımı ilk kontrolde çoğu kez 'processing' döner: hata değil, onay bekliyor.
                $processing = (int) ($publish['processing'] ?? 0);
                $ok = ((int) ($publish['success'] ?? 0) + $processing) > 0 && (int) ($publish['failed'] ?? 0) === 0;
                $batchIds = $publish['batch_ids'] ?? [];
                $batchText = !empty($batchIds) ? ' Batch: ' . implode(', ', $batchIds) : '';
                $waiting = !empty($batchIds) || $processing > 0;
                $status = $ok ? ($waiting ? 'wait' : 'success') : 'error';

                $results[] = [
                    'marketplace_id' => $mpId,
                    'slug'           => $slug,
                    'display_name'   => $displayName,
                    'status'         => $status,
                    'message'        => $ok
                        ? ($waiting ? 'Ürün kartı kuyruğa alındı; pazaryeri onayı bekleniyor.' . $batchText : 'Ürün kartı gönderildi.')
                        : $this->formatPublishError($publish),
                    'action'         => 'publish',
                    'batch_ids'      => $batchIds,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'marketplace_id' => $mpId,
                    'slug'           => $slug,
                    'display_name'   => $displayName,
                    'status'         => 'error',
                    'message'        => $e->getMessage(),
                    'action'         => 'publish',
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $mp
     */
    private function marketplaceProductCanReceiveStockPrice(array $mp): bool
    {
        return (int) ($mp['is_listed'] ?? 0) === 1;
    }

    /**
     * Pazaryeri API hatalarını okunabilir tek satıra çevir.
     *
     * @param array<string, mixed> $apiResult
     */
    private function formatApiErrors(array $apiResult): string
    {
        $errors = $apiResult['errors'] ?? [];
        if (empty($errors) || !is_array($errors)) {
            return 'Pazaryeri güncellemeyi kabul etmedi (ayrıntı bildirilmedi)';
        }

        $messages = [];
        foreach ($errors as $error) {
            if (is_string($error)) {
                $messages[] = $error;
            } elseif (is_array($error)) {
                $messages[] = (string) ($error['error'] ?? json_encode($error, JSON_UNESCAPED_UNICODE));
            }
        }

        $messages = array_values(array_filter(array_unique($messages)));
        if ($messages === []) {
            return 'Pazaryeri güncellemeyi kabul etmedi (ayrıntı bildirilmedi)';
        }

        return implode(' | ', array_slice($messages, 0, 3));
    }

    /**
     * @param array<string, mixed> $publish
     */
    private function formatPublishError(array $publish): string
    {
        $errors = $publish['errors'] ?? [];
        if (!empty($errors) && is_array($errors)) {
            $first = reset($errors);
            if (is_array($first)) {
                return (string) ($first['error'] ?? json_encode($first, JSON_UNESCAPED_UNICODE));
            }
            return (string) $first;
        }

        return 'Ürün yayınlama başarısız';
    }

    /**
     * Marketplace slug'ına göre servis instance'ı döndür
     */
    private function getMarketplaceService(string $slug): ?object
    {
        $serviceMap = [
            'trendyol'    => \App\Services\Marketplace\TrendyolService::class,
            'n11'         => \App\Services\Marketplace\N11Service::class,
            'hepsiburada' => \App\Services\Marketplace\HepsiburadaService::class,
            'pazarama'    => \App\Services\Marketplace\PazaramaService::class,
            'pttavm'      => \App\Services\Marketplace\PttavmService::class,
            'ciceksepeti' => \App\Services\Marketplace\CiceksepetiService::class,
            'idefix'      => \App\Services\Marketplace\IdefixService::class,
            'amazon'      => \App\Services\Marketplace\AmazonService::class,
            'oziygo'      => \App\Services\Marketplace\OziygoService::class,
            'shopify'     => \App\Services\Marketplace\ShopifyService::class,
            'woocommerce' => \App\Services\Marketplace\WooCommerceService::class,
            'opencart'    => \App\Services\Marketplace\OpenCartService::class,
        ];

        $serviceClass = $serviceMap[$slug] ?? null;
        if (!$serviceClass) {
            return null;
        }

        try {
            return $this->container->get($serviceClass);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════
    // PAZARYERI ÜRÜN LİSTESİ (MP Tabları için AJAX)
    // ═══════════════════════════════════════════════════════

    /**
     * Pazaryeri tab'ındaki ürün listesini döndürür
     */
    public function mpProductList(Request $request): void
    {
        $mpId   = (int)($request->query('marketplace_id') ?? 0);
        $page   = max(1, (int)($request->query('page') ?? 1));
        $search = trim((string)($request->query('search') ?? ''));

        if ($mpId <= 0) {
            $this->json(['success' => false, 'message' => 'Pazaryeri belirtilmedi']);
            return;
        }

        $result = $this->mpProductModel->getPagedByMarketplace($mpId, $page, 50, $search);

        $this->json([
            'success' => true,
            'data'    => $result['items'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => (int)ceil($result['total'] / 50),
        ]);
    }

    /**
     * Sistem ürünlerinde arama (pazaryeri ilanını elle bağlamak için).
     *
     * GET /products/search?q=...
     */
    public function searchProducts(Request $request): void
    {
        $query = trim((string) ($request->query('q') ?? ''));
        if (mb_strlen($query) < 2) {
            $this->json(['success' => true, 'data' => []]);
            return;
        }

        $rows = $this->productModel->searchForLinking($query, 20);

        $this->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Pazaryerindeki mevcut ilanı sistem ürününe ELLE bağla.
     *
     * Barkod/SKU tutmadığında otomatik eşleştirme çalışmaz; bu bağlama olmadan
     * stok-fiyat senkronu ilanı bulamaz (HB'de Merchant SKU ↔ HBC eşlemesi).
     *
     * POST /products/mp-products/link  {mp_product_id, product_id}
     */
    public function linkMpProduct(Request $request): void
    {
        $body        = json_decode(file_get_contents('php://input'), true) ?: [];
        $mpProductId = (int) ($body['mp_product_id'] ?? $request->post('mp_product_id', 0));
        $productId   = (int) ($body['product_id'] ?? $request->post('product_id', 0));

        if ($mpProductId <= 0) {
            $this->error('Pazaryeri ürünü seçilmedi');
        }

        $mpProduct = $this->mpProductModel->find($mpProductId);
        if ($mpProduct === false) {
            $this->error('Pazaryeri ürünü bulunamadı');
        }

        // product_id = 0 → bağlantıyı kaldır
        if ($productId <= 0) {
            $this->mpProductModel->update($mpProductId, [
                'product_id' => null,
                'is_listed'  => 0,
                'sync_error' => null,
            ]);

            $this->success(['mp_product_id' => $mpProductId], 'Eşleşme kaldırıldı');
        }

        $product = $this->productModel->find($productId);
        if ($product === false) {
            $this->error('Sistem ürünü bulunamadı');
        }

        $this->mpProductModel->update($mpProductId, [
            'product_id' => $productId,
            // İlan pazaryerinde zaten var: stok/fiyat senkronu için yayınlı sayılır.
            'is_listed'  => 1,
            'is_active'  => 1,
            'sync_error' => null,
        ]);

        $this->logger->info(
            "Pazaryeri ilanı elle eşleştirildi: mp_product #{$mpProductId} → ürün #{$productId}",
            'ProductController'
        );

        $this->success(
            ['mp_product_id' => $mpProductId, 'product_id' => $productId],
            'İlan "' . ($product['name'] ?? '') . '" ürünüyle eşleştirildi'
        );
    }

    /**
     * Barkod/SKU bazlı otomatik ürün eşleştirme
     */
    public function autoMatchProducts(Request $request): void
    {
        $mpId = (int)($request->post('marketplace_id') ?? 0);
        $entitlement = $this->container->get(\App\Services\EntitlementService::class);

        // Belirli pazaryeri seçilmişse yalnız o (mağaza yetkisi şart), yoksa mağazanın yetkili olduğu tümü
        if ($mpId > 0) {
            if (!$entitlement->canAccessMarketplace($mpId)) {
                $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor; önce eklenti olarak eklenmeli.'], 403);
            }
            $marketplaceIds = [$mpId];
        } else {
            $marketplaceIds = $entitlement->allowedMarketplaceIds();
        }

        @set_time_limit(300);
        $totals = ['matched' => 0, 'not_found' => 0, 'already' => 0, 'conflict' => 0];

        // Tüm ilanlar parça parça dolaşılır (eski 500 satırlık kesme yok)
        foreach ($marketplaceIds as $marketplaceId) {
            foreach ($this->mpProductModel->chunkForMarketplace((int) $marketplaceId) as $chunk) {
                [$m, $n, $a, $c] = $this->matchProductsBatch($chunk);
                $totals['matched']   += $m;
                $totals['not_found'] += $n;
                $totals['already']   += $a;
                $totals['conflict']  += $c;
            }
        }

        $message = "{$totals['matched']} ürün eşleştirildi, {$totals['already']} zaten eşleşik, {$totals['not_found']} bulunamadı";
        if ($totals['conflict'] > 0) {
            $message .= ", {$totals['conflict']} ürün bu pazaryerinde başka bir ilana zaten bağlı";
        }

        $this->json([
            'success' => true,
            'data'    => ['message' => $message] + $totals,
        ]);
    }

    /**
     * Barkod/SKU ile batch eşleştirme helper.
     * 1. Barkod: ilanın KENDİ barkodu (mp.marketplace_barcode) — yalnız mağazada tek üründe kullanılıyorsa
     *    (tekrarlanan barkod kimlik değildir).
     * 2. SKU: ilanın satıcı SKU'su (mp.marketplace_sku) = ürün SKU'su.
     * Ürünün bu pazaryerinde zaten başka bir ilan satırı varsa (UNIQUE store + ürün + pazaryeri) bağlanmaz → çakışma.
     *
     * @param array<int, array<string, mixed>> $mpProducts MarketplaceProduct::getAllForMarketplace satırları
     * @return array{0: int, 1: int, 2: int, 3: int} [matched, notFound, already, conflict]
     */
    private function matchProductsBatch(array $mpProducts): array
    {
        $matched = 0; $notFound = 0; $already = 0; $conflict = 0;

        foreach ($mpProducts as $mpProd) {
            // Zaten eşleşmişse atla
            if (!empty($mpProd['product_id'])) {
                $already++;
                continue;
            }

            $product = false;

            $barcode = trim((string) ($mpProd['marketplace_barcode'] ?? ''));
            if ($barcode !== '' && $this->productModel->count(['barcode' => $barcode]) === 1) {
                $product = $this->productModel->findByBarcode($barcode);
            }

            if ($product === false) {
                $sku = trim((string) ($mpProd['marketplace_sku'] ?? ''));
                if ($sku !== '') {
                    $product = $this->productModel->findBySku($sku);
                }
            }

            if ($product === false) {
                $notFound++;
                continue;
            }

            if ($this->mpProductModel->findByProductAndMarketplace((int) $product['id'], (int) $mpProd['marketplace_id']) !== false) {
                $conflict++;
                continue;
            }

            $this->mpProductModel->update((int) $mpProd['id'], [
                'product_id' => (int) $product['id'],
            ]);
            $matched++;
        }

        return [$matched, $notFound, $already, $conflict];
    }

    /**
     * Marketplace servisini slug ile çöz
     */
    private function resolveMarketplaceService(string $slug): ?object
    {
        // Tek kaynak: getMarketplaceService() — pazaryeri eklendiğinde iki ayrı
        // haritayı güncelleme zorunluluğu (ve unutulan pazaryerleri) ortadan kalkar.
        return $this->getMarketplaceService($slug);
    }

    /**
     * Pazaryeri ürününü sisteme ekle (yeni ürün oluştur)
     */
    public function addMpProductToSystem(Request $request): void
    {
        $mpProductId = (int)($request->post('mp_product_id') ?? 0);

        if ($mpProductId <= 0) {
            $this->json(['success' => false, 'message' => 'Geçersiz parametre']);
            return;
        }

        $mpProd = $this->mpProductModel->find($mpProductId);
        if ($mpProd === false) {
            $this->json(['success' => false, 'message' => 'Pazaryeri ürünü bulunamadı']);
            return;
        }

        // Zaten eşleşmiş mi?
        if (!empty($mpProd['product_id'])) {
            $this->json(['success' => false, 'message' => 'Bu ürün zaten sisteme eşleşmiş']);
            return;
        }

        $marketplaceId = (int)($mpProd['marketplace_id'] ?? 0);

        // ═══ KATEGORİ ÇÖZÜMLEME ═══
        $systemCategoryId = null;
        $mpCatId = $mpProd['marketplace_category_id'] ?? '';
        if ($mpCatId !== '') {
            // category_marketplace_mapping üzerinden eşleşme ara
            $catMapping = $this->catMappingModel->findByMarketplaceCategory($marketplaceId, $mpCatId);
            if ($catMapping) {
                $systemCategoryId = (int)$catMapping['category_id'];
            }
        }
        
        // Eğer ID ile eşleşme bulunamadıysa, ad ile ara
        if (!$systemCategoryId) {
            $catName = trim($mpProd['marketplace_category_name'] ?? '');
            if ($catName !== '') {
                // Mağazaya kapalı arama (eski kod protected rawQueryOne çağırıyordu ve
                // store_id filtresi yoktu → başka bayinin kategorisiyle eşleşebiliyordu)
                $eslesenler = $this->categoryModel->searchCategories($catName);
                if ($eslesenler !== []) {
                    $systemCategoryId = (int) $eslesenler[0]['id'];
                }
            }
        }

        // ═══ MARKA ÇÖZÜMLEME ═══
        $systemBrandId = null;
        $mpBrandId = $mpProd['marketplace_brand_id'] ?? '';

        if ($mpBrandId !== '') {
            // brand_marketplace_mapping tablosundan bak
            $existingBrand = $this->brandModel->findByMarketplaceBrandId($marketplaceId, $mpBrandId);
            if ($existingBrand) {
                $systemBrandId = (int)$existingBrand['id'];
            }
        }
        
        // Eğer ID ile eşleşme bulunamadıysa, ad ile ara
        if (!$systemBrandId) {
            $brandName = trim($mpProd['marketplace_brand_name'] ?? '');
            if ($brandName !== '' && $brandName !== 'Diğer') {
                $brand = $this->brandModel->findByName($brandName);
                if ($brand) {
                    $systemBrandId = (int)$brand['id'];
                } else {
                    // Otomat olarak marka oluştur
                    $systemBrandId = $this->brandModel->create([
                        'name' => $brandName,
                        'is_active' => 1,
                    ]);
                }
            }
        }

        // ═══ ÜRÜN OLUŞTUR ═══
        $productData = [
            'name'        => $mpProd['product_name'] ?? ($mpProd['marketplace_sku'] ?? 'İsimsiz Ürün'),
            'sku'         => $mpProd['marketplace_sku'] ?? ('MP-' . $mpProd['id']),
            'barcode'     => $mpProd['marketplace_barcode'] ?? null,
            'base_price'  => (float)($mpProd['last_price_sent'] ?? 0),
            'stock_qty'   => (int)($mpProd['last_stock_sent'] ?? 0),
            'is_active'   => 1,
        ];

        if ($systemCategoryId) {
            $productData['category_id'] = $systemCategoryId;
        }
        if ($systemBrandId) {
            $productData['brand_id'] = $systemBrandId;
        }

        $productId = $this->productModel->create($productData);

        // Eşleştir
        $this->mpProductModel->update($mpProductId, [
            'product_id' => $productId,
        ]);

        $catName = $systemCategoryId ? ($this->categoryModel->find($systemCategoryId)['name'] ?? '?') : 'Eşleşmedi';
        $brandInfo = $systemBrandId ? 'Eşleşti' : 'Eşleşmedi';

        $this->json([
            'success' => true,
            'data'    => [
                'message'    => "Ürün sisteme eklendi (Kategori: {$catName}, Marka: {$brandInfo})",
                'product_id' => $productId,
                'category'   => $catName,
                'brand_matched' => (bool)$systemBrandId,
            ],
        ]);
    }

    /**
     * Ürün görseli yükle (AJAX)
     */
    public function uploadImage(Request $request): void
    {
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['success' => false, 'message' => 'Dosya yüklenemedi']);
            return;
        }

        $file = $_FILES['image'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if ($file['size'] > $maxSize) {
            $this->json(['success' => false, 'message' => 'Dosya 5MB\'dan büyük']);
            return;
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowedTypes)) {
            $this->json(['success' => false, 'message' => 'Sadece JPG, PNG, WEBP, GIF yüklenebilir']);
            return;
        }

        $ext = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };

        $uploadDir = BASE_PATH . '/public/uploads/products/' . date('Y/m');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = uniqid('img_', true) . '.' . $ext;
        $targetPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $this->json(['success' => false, 'message' => 'Dosya kaydedilemedi']);
            return;
        }

        $relativePath = 'uploads/products/' . date('Y/m') . '/' . $filename;
        $url = function_exists('public_url') ? public_url($relativePath) : '/public/' . $relativePath;

        $this->json([
            'success' => true,
            'data'    => ['url' => $url, 'filename' => $filename],
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // PAZARYERI AYARLARI (Per-product marketplace settings)
    // ═══════════════════════════════════════════════════════

    /**
     * Ürünün pazaryeri ayarlarını getir (AJAX)
     * GET /products/{id}/marketplace-settings
     */
    public function getMarketplaceSettings(Request $request, string $id): void
    {
        $productId = (int) $id;
        $product = $this->productModel->find($productId);
        if ($product === false) {
            $this->error('Ürün bulunamadı', 404);
        }

        // All active marketplaces
        $marketplaces = $this->marketplaceModel->getActive();

        // Existing marketplace_products for this product
        $mpProducts = $this->mpProductModel->getByProduct($productId);
        $mpMap = [];
        foreach ($mpProducts as $mp) {
            $mpMap[(int) $mp['marketplace_id']] = $mp;
        }

        // Build per-marketplace data
        $result = [];
        foreach ($marketplaces as $mp) {
            $mpId = (int) $mp['id'];
            $existing = $mpMap[$mpId] ?? null;

            // Category mapping status
            $catMapping = null;
            if (!empty($product['category_id'])) {
                $catMapping = $this->catMappingModel->findMapping((int) $product['category_id'], $mpId);
            }

            // Brand mapping status
            $brandMapping = null;
            if (!empty($product['brand_id'])) {
                $brandMapping = $this->brandMappingModel->findMapping((int) $product['brand_id'], $mpId);
            }

            // Calculate price with current rules
            $calculatedPrice = null;
            if ((float) ($product['base_price'] ?? 0) > 0) {
                $priceData = $this->priceRuleEngine->calculatePrice($product, $mpId);
                $calculatedPrice = $priceData;
            }

            // Computed stock = stock_qty - stock_offset
            $stockOffset = (int) ($existing['stock_offset'] ?? 0);
            $computedStock = max(0, (int) ($product['stock_qty'] ?? 0) - $stockOffset);

            $result[] = [
                'marketplace_id'     => $mpId,
                'marketplace_slug'   => $mp['slug'] ?? '',
                'marketplace_name'   => $mp['display_name'] ?? $mp['name'] ?? '',
                'marketplace_color'  => $mp['color'] ?? '#6366f1',
                'marketplace_icon'   => $mp['icon'] ?? '🏪',
                'is_enabled'         => $existing ? (int) ($existing['is_active'] ?? 0) : 0,
                'is_listed'          => $existing && $this->marketplaceProductCanReceiveStockPrice($existing) ? 1 : 0,
                'mp_sku'             => $existing['marketplace_sku'] ?? '',
                'mp_product_id'      => $existing['marketplace_product_id'] ?? '',
                'price_rule_type'    => $existing['price_rule_type'] ?? 'none',
                'price_rule_value'   => (float) ($existing['price_rule_value'] ?? 0),
                'stock_offset'       => $stockOffset,
                'computed_stock'     => $computedStock,
                'calculated_price'   => $calculatedPrice,
                'last_price_sent'    => $existing['last_price_sent'] ?? null,
                'last_stock_sent'    => $existing['last_stock_sent'] ?? null,
                'last_synced_at'     => $existing['last_synced_at'] ?? null,
                'sync_error'         => $existing['sync_error'] ?? null,
                'category_mapped'    => $catMapping !== false && $catMapping !== null,
                'category_mp_name'   => $catMapping['marketplace_category_name'] ?? null,
                'category_mp_path'   => $catMapping['marketplace_category_path'] ?? null,
                'brand_mapped'       => $brandMapping !== false && $brandMapping !== null,
                'brand_mp_name'      => $brandMapping['marketplace_brand_name'] ?? null,
                'has_category'       => !empty($product['category_id']),
                'has_brand'          => !empty($product['brand_id']),
            ];
        }

        $this->success([
            'product_id'   => $productId,
            'base_price'   => (float) ($product['base_price'] ?? 0),
            'list_price'   => (float) ($product['list_price'] ?? 0),
            'stock_qty'    => (int) ($product['stock_qty'] ?? 0),
            'marketplaces' => $result,
        ]);
    }

    /**
     * Ürünün pazaryeri ayarlarını kaydet (AJAX)
     * POST /products/{id}/marketplace-settings
     */
    public function saveMarketplaceSettings(Request $request, string $id): void
    {
        $productId = (int) $id;
        $product = $this->productModel->find($productId);
        if ($product === false) {
            $this->error('Ürün bulunamadı', 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $marketplaces = $body['marketplaces'] ?? [];

        if (!is_array($marketplaces)) {
            $this->error('Geçersiz veri formatı');
        }

        $updated = 0;
        $errors = [];

        foreach ($marketplaces as $mpData) {
            $mpId = (int) ($mpData['marketplace_id'] ?? 0);
            if ($mpId <= 0) continue;

            // Validate marketplace exists
            $mp = $this->marketplaceModel->find($mpId);
            if ($mp === false) continue;

            $enabled = !empty($mpData['enabled']);
            $priceRuleType = $mpData['price_rule_type'] ?? 'none';
            $priceRuleValue = (float) ($mpData['price_rule_value'] ?? 0);
            $stockOffset = max(0, (int) ($mpData['stock_offset'] ?? 0));

            // Validate price_rule_type
            $validTypes = ['none', 'percent_increase', 'percent_decrease', 'fixed_increase', 'fixed_decrease'];
            if (!in_array($priceRuleType, $validTypes, true)) {
                $priceRuleType = 'none';
            }

            try {
                $existing = $this->mpProductModel->findByProductAndMarketplace($productId, $mpId);

                if ($enabled) {
                    $data = [
                        'price_rule_type'  => $priceRuleType,
                        'price_rule_value' => $priceRuleValue,
                        'stock_offset'     => $stockOffset,
                        'is_active'        => 1,
                    ];
                    $this->mpProductModel->upsertByProductAndMarketplace($productId, $mpId, $data);
                    $updated++;
                } else {
                    // Disable: set is_active=0 if exists
                    if ($existing !== false) {
                        $this->mpProductModel->update((int) $existing['id'], ['is_active' => 0]);
                        $updated++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ($mp['display_name'] ?? "MP#{$mpId}") . ': ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $this->success([
                'updated' => $updated,
                'errors'  => $errors,
            ], "{$updated} pazaryeri güncellendi, " . count($errors) . " hata");
        }

        $this->success([
            'updated' => $updated,
        ], "{$updated} pazaryeri ayarı güncellendi");
    }
}
