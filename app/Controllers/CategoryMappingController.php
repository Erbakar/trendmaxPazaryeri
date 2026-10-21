<?php
/**
 * OzyConnect Panel — CategoryMappingController
 * Kategori ↔ pazaryeri kategorisi eşleştirme yönetimi
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryMarketplaceMapping;
use App\Models\Marketplace;
use App\Models\MarketplaceBrand;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Services\AuthorizationService;
use App\Services\CategoryMappingService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\PazaramaService;
use App\Services\ProductPublishService;

final class CategoryMappingController extends BaseController
{
    private const HB_SLUG = 'hepsiburada';
    private const HB_BRAND_KEY = 'Marka';
    private const HB_PRODUCT_LIMIT = 200;
    private const HB_SUGGESTION_LIMIT = 200;
    private const HB_VALUE_MAX = 255;
    private const HB_BRAND_MAX = 100;
    private const ATTRIBUTE_ID_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';
    private const PRODUCT_KEY_PATTERN = '/^id:(\d{1,10})$/';

    private readonly CategoryMappingService $categoryMappingService;
    private readonly CategoryMarketplaceMapping $mappingModel;
    private readonly Marketplace $marketplaceModel;
    private readonly TrendyolService $trendyolService;
    private readonly N11Service $n11Service;
    private readonly PazaramaService $pazaramaService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->categoryMappingService = $container->get(CategoryMappingService::class);
        $this->mappingModel           = $container->get(CategoryMarketplaceMapping::class);
        $this->marketplaceModel       = $container->get(Marketplace::class);
        $this->trendyolService        = $container->get(TrendyolService::class);
        $this->n11Service             = $container->get(N11Service::class);
        $this->pazaramaService        = $container->get(PazaramaService::class);
    }

    /**
     * Eşleştirme listesi (yalnız mağazanın erişebildiği aktif pazaryerleri)
     */
    public function index(Request $request): void
    {
        $marketplaces = $this->accessibleMarketplaces();
        $selected = $this->pickMarketplace($marketplaces, $request->integer('marketplace_id', 0));
        $marketplaceId = $selected !== null ? (int) $selected['id'] : 0;

        $categories = [];
        $unmappedCount = 0;
        $unsellable = [];

        if ($marketplaceId > 0) {
            $categories = $this->categoryMappingService->getCategoriesWithMappingStatus($marketplaceId);
            $unmappedCount = $this->categoryMappingService->getUnmappedProductCount($marketplaceId);
            $unsellable = $this->unsellableMappings($marketplaceId);
        }

        // Güven skoru DB'de tutulmaz; satır çizilirken hesaplanır. Böylece şema
        // değişmez ve uyarı elle yapılmış eşleştirmelerde de çalışır.
        $finder = $this->container->get(\App\Services\Mapping\MappingCandidateFinder::class);

        foreach ($categories as $i => $c) {
            $categories[$i]['confidence'] = empty($c['mapping_id'])
                ? null
                : $finder->confidenceFor(
                    (string) ($c['name'] ?? ''),
                    (string) ($c['full_path'] ?? ''),
                    (string) ($c['marketplace_category_name'] ?? '')
                );

            $problem = !empty($c['mapping_id']) ? ($unsellable[(int) $c['id']] ?? null) : null;
            $categories[$i]['unsellable_reason'] = $problem !== null ? (string) ($problem['reason'] ?? '') : '';
            $categories[$i]['unsellable_path']   = $problem !== null ? (string) ($problem['path'] ?? '') : '';
        }

        // Katalog neredeyse boşsa hiçbir skorlama iyileştirmesi yardımcı olmaz
        // (ör. ÇiçekSepeti'nin kategori API'si yok, katalog ürünlerden toplanıyor).
        $catalogSize = $marketplaceId > 0
            ? $this->container->get(MarketplaceCategory::class)->countByMarketplace($marketplaceId)
            : 0;

        $this->view('categories.mapping', [
            'pageTitle'       => 'Kategori Eşleştirme',
            'marketplaces'    => $marketplaces,
            'marketplaceId'   => $marketplaceId,
            'marketplaceSlug' => $selected !== null ? (string) ($selected['slug'] ?? '') : '',
            'marketplaceName' => $selected !== null ? (string) ($selected['display_name'] ?? $selected['slug'] ?? '') : '',
            'categories'      => $categories,
            'unmappedCount'   => $unmappedCount,
            'unsellableCount' => count($unsellable),
            'catalogSize'     => $catalogSize,
            'confidenceSafe'  => \App\Services\Mapping\MappingCandidateFinder::CONFIDENCE_SAFE,
        ]);
    }

    /**
     * Eşleştirme kaydet
     */
    public function store(Request $request): void
    {
        $categoryId = $request->integer('category_id');
        $marketplaceId = $request->integer('marketplace_id');
        $mpCategoryId = trim((string) $request->post('marketplace_category_id', ''));
        $mpCategoryName = trim((string) $request->post('marketplace_category_name', ''));
        $mpCategoryPath = $request->post('marketplace_category_path');
        $mpCategoryPath = is_string($mpCategoryPath) ? trim($mpCategoryPath) : null;

        if ($categoryId === 0 || $marketplaceId === 0 || $mpCategoryId === '') {
            $this->failStore($request, '/categories/mapping', 'Eksik alanlar');
        }

        $backUrl = '/categories/mapping?marketplace_id=' . $marketplaceId;
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false || !$this->authorization()->canAccessMarketplace($marketplaceId)) {
            $this->failStore($request, '/categories/mapping', 'Bu pazaryeri paketinizde bulunmuyor.');
        }

        try {
            $this->mappingModel->saveMapping($categoryId, $marketplaceId, $mpCategoryId, $mpCategoryName, $mpCategoryPath);
        } catch (\Throwable $e) {
            $this->failStore($request, $backUrl, 'Eşleştirme kaydedilemedi: ' . $this->publicError($e, 'Kategori eşleştirmesi'));
        }

        // Kategori özelliklerini de kaydet. HB satırında mevcut kategori varsayılanları ve
        // ürün bazlı değerler KORUNUR; gelen veri onların üzerine birleştirilir.
        $attributeJson = $request->post('attribute_mapping_json');
        if (is_string($attributeJson) && trim($attributeJson) !== '') {
            $mapping = $this->mappingModel->findMapping($categoryId, $marketplaceId);
            if ($mapping !== false) {
                try {
                    $this->mappingModel->update((int) $mapping['id'], [
                        'attribute_mapping_json' => $this->mergeAttributeMappingJson(
                            $mapping,
                            (string) ($marketplace['slug'] ?? ''),
                            $attributeJson
                        ),
                    ]);
                } catch (\Throwable $e) {
                    $this->failStore($request, $backUrl, 'Kategori eşleştirildi ancak özellikler kaydedilemedi: ' . $this->publicError($e, 'Kategori özellikleri'));
                }
            }
        }

        if ($request->isAjax()) {
            $this->success([], 'Eşleştirme kaydedildi');
        }

        $this->redirectWithSuccess($backUrl, 'Kategori eşleştirmesi kaydedildi');
    }

    /**
     * Eşleştirme sil
     */
    public function delete(Request $request, string $id): void
    {
        $mappingId = (int) $id;
        $mapping = $mappingId > 0 ? $this->mappingModel->find($mappingId) : false;
        if ($mapping === false) {
            if ($request->isAjax()) {
                $this->error('Eşleştirme bulunamadı', 404);
            }
            $this->redirectWithError('/categories/mapping', 'Eşleştirme bulunamadı');
        }

        $marketplaceId = (int) $mapping['marketplace_id'];
        $this->mappingModel->delete($mappingId);

        if ($request->isAjax()) {
            $this->success([], 'Eşleştirme silindi');
        }

        $this->redirectWithSuccess('/categories/mapping?marketplace_id=' . $marketplaceId, 'Eşleştirme silindi');
    }

    /**
     * AJAX: Pazaryeri kategori arama (seçili pazaryerinin slug'ı ile)
     */
    public function searchTrendyol(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $query = trim((string) $request->query('q', ''));

        $marketplace = $marketplaceId > 0 ? $this->marketplaceModel->find($marketplaceId) : false;
        if ($marketplace === false) {
            $this->error('Pazaryeri bulunamadı', 404);
        }
        if (!$this->authorization()->canAccessMarketplace($marketplaceId)) {
            $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
        }

        // Hepsiburada: her aramada tüm listeyi canlı API'den çekmek yerine yerel kategori önbelleği;
        // yalnız satışa uygun yapraklar (ara / satışa kapalı kategori önerilmez).
        if (($marketplace['slug'] ?? '') === 'hepsiburada') {
            $rows = mb_strlen($query) < 2
                ? []
                : $this->container->get(MarketplaceCategory::class)->searchSellable($query, $marketplaceId, 50);
            $this->success(['categories' => array_map(static fn(array $row): array => [
                'id'   => (string) $row['mp_category_id'],
                'name' => (string) $row['name'],
                'path' => (string) (($row['full_path'] ?? '') !== '' ? $row['full_path'] : $row['name']),
            ], $rows)]);
        }

        $categories = $this->categoryMappingService->searchMarketplaceCategories(
            $marketplaceId,
            $query,
            (string) ($marketplace['slug'] ?? '')
        );

        $this->success(['categories' => $categories]);
    }

    /**
     * AJAX: Kategori özellikleri (marketplace-aware)
     */
    public function attributes(Request $request, string $categoryId): void
    {
        try {
            $marketplaceId = $request->integer('marketplace_id', 0);
            $slug = '';

            if ($marketplaceId > 0) {
                if (!$this->authorization()->canAccessMarketplace($marketplaceId)) {
                    $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
                }
                $mp = $this->marketplaceModel->find($marketplaceId);
                $slug = $mp !== false ? ($mp['slug'] ?? '') : '';
            }

            $attributes = match ($slug) {
                'n11' => $this->n11Service->fetchCategoryAttributes($categoryId),
                'pazarama' => (function () use ($categoryId) {
                    $this->pazaramaService->loadCredentials();
                    return $this->pazaramaService->fetchCategoryAttributes($categoryId);
                })(),
                'hepsiburada' => $this->hepsiburada()->getRequiredCategoryAttributes($categoryId),
                'ciceksepeti' => [],
                default => (function () use ($categoryId) {
                    $this->trendyolService->loadCredentials();
                    return $this->trendyolService->fetchCategoryAttributes($categoryId);
                })(),
            };

            $this->success(['attributes' => $attributes]);
        } catch (\Throwable $e) {
            $this->error('Özellikler alınamadı: ' . $e->getMessage());
        }
    }

    /**
     * AJAX GET /categories/mapping/{id}/hb-attributes
     * HB eşleştirmesinin zorunlu özellikleri + geçerli değerleri, kategori varsayılanları ve
     * kategorideki ürünler için çözülen değer/kaynak. HB'ye yalnız (önbellekli) GET.
     */
    public function hbAttributes(Request $request, string $id): void
    {
        $mapping = $this->resolveHbMapping($id, 'categories.view');
        $refresh = (string) $request->query('refresh', '') === '1';
        $hbCategoryId = trim((string) $mapping['marketplace_category_id']);

        $attributes = [];
        $error = '';
        try {
            $attributes = $this->normalizeHbAttributes(
                $this->hepsiburada()->getRequiredCategoryAttributes($hbCategoryId, $refresh)
            );
        } catch (\Throwable $e) {
            $error = 'Hepsiburada özellikleri alınamadı: ' . $e->getMessage();
            $this->logger->warning("HB kategori özellikleri alınamadı ({$hbCategoryId}): " . $e->getMessage(), 'CategoryMapping');
        }

        [$products, $productTotal] = $error === '' ? $this->resolveHbProducts($mapping) : [[], 0];

        $this->success([
            'mapping'       => $this->hbMappingSummary($mapping),
            'attributes'    => $attributes,
            'defaults'      => (object) $this->mappingModel->attributeDefaults($mapping),
            'products'      => $products,
            'product_total' => $productTotal,
            'product_limit' => self::HB_PRODUCT_LIMIT,
            'brands'        => $this->hbBrandSuggestions((int) $mapping['marketplace_id']),
            'error'         => $error,
        ]);
    }

    /**
     * AJAX POST /categories/mapping/{id}/hb-attribute
     * Kategori varsayılanı (product_key boş) ya da ürün bazlı değer (product_key = id:<ürün>).
     * Enum değerleri yalnız HB'nin geçerli listesinden kabul edilir; boş değer kaydı kaldırır.
     */
    public function hbAttributeSave(Request $request, string $id): void
    {
        $mapping = $this->resolveHbMapping($id, 'categories.edit');
        $mappingId = (int) $mapping['id'];

        $attributeId = trim((string) $request->post('attribute_id', ''));
        $rawValue = $request->post('value', '');
        $productKey = trim((string) $request->post('product_key', ''));

        if (!preg_match(self::ATTRIBUTE_ID_PATTERN, $attributeId)) {
            $this->error('Geçersiz özellik.', 422);
        }
        if (!is_string($rawValue) || !mb_check_encoding($rawValue, 'UTF-8')) {
            $this->error('Değer geçersiz karakter içeriyor (UTF-8 bekleniyor).', 422);
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $rawValue));

        if ($productKey !== '') {
            $this->assertProductInMapping($productKey, $mapping);
        }

        $value = $attributeId === self::HB_BRAND_KEY
            ? $this->validatedBrandValue($value, $productKey)
            : $this->validatedAttributeValue($mapping, $attributeId, $value);

        try {
            $saved = $productKey !== ''
                ? $this->mappingModel->saveProductAttributeValue($mappingId, $productKey, $attributeId, $value)
                : $this->mappingModel->saveAttributeDefault($mappingId, $attributeId, $value);
        } catch (\Throwable $e) {
            $this->logger->error("HB özellik kaydı başarısız (eşleşme {$mappingId}): " . $e->getMessage(), 'CategoryMapping');
            $this->error('Kaydedilemedi: ' . $this->publicError($e), 500);
        }

        if ($saved === false) {
            $this->error('Kategori eşleştirmesi bulunamadı.', 404);
        }

        $message = match (true) {
            $value !== ''      => 'Kaydedildi.',
            $productKey !== '' => 'Kaldırıldı — kategori değerine dönüldü.',
            default            => 'Kaldırıldı — otomatik değere dönüldü.',
        };

        $this->success([
            'attribute_id' => $attributeId,
            'product_key'  => $productKey,
            'value'        => $value,
        ], $message);
    }

    // ─── HB kategori özellikleri: yardımcılar ─────────────────────────

    /**
     * Eşleşmeyi tenant kapsamında bulur; HB, modül, izin ve pazaryeri yetkisini doğrular.
     *
     * @return array<string, mixed>
     */
    private function resolveHbMapping(string $id, string $permission): array
    {
        $authorization = $this->authorization();
        if (!$authorization->canAccessModule('categories')) {
            $this->error('Mağazanız bu modülü kullanma yetkisine sahip değil.', 403);
        }
        if (!$authorization->hasPermission($permission)) {
            $this->error('Bu işlem için yetkiniz bulunmuyor.', 403);
        }

        $mappingId = (int) $id;
        $mapping = $mappingId > 0 ? $this->mappingModel->find($mappingId) : false;
        if ($mapping === false) {
            $this->error('Kategori eşleştirmesi bulunamadı.', 404);
        }

        $marketplaceId = (int) $mapping['marketplace_id'];
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false || ($marketplace['slug'] ?? '') !== self::HB_SLUG) {
            $this->error('Kategori özellikleri yalnız Hepsiburada eşleştirmeleri için kullanılabilir.', 422);
        }
        if (!$authorization->canAccessMarketplace($marketplaceId)) {
            $this->error('Bu pazaryeri paketinizde bulunmuyor.', 403);
        }
        if (trim((string) ($mapping['marketplace_category_id'] ?? '')) === '') {
            $this->error('Bu eşleştirmede Hepsiburada kategorisi seçilmemiş.', 422);
        }

        $this->assertHbAttributeSupport();

        return $mapping;
    }

    /**
     * Sözleşme metotları (servis/model güncellemesi) yoksa anlaşılır hata döner.
     */
    private function assertHbAttributeSupport(): void
    {
        $required = [
            HepsiburadaService::class         => ['getRequiredCategoryAttributes', 'canonicalAttributeValue'],
            ProductPublishService::class      => ['resolveHepsiburadaAttributes'],
            CategoryMarketplaceMapping::class => ['attributeDefaults', 'attributeProductKey', 'saveAttributeDefault', 'saveProductAttributeValue'],
        ];

        foreach ($required as $class => $methods) {
            foreach ($methods as $method) {
                if (!method_exists($class, $method)) {
                    $this->error('Hepsiburada kategori özellikleri bu kurulumda henüz etkin değil (güncelleme eksik).', 501);
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $required
     * @return array<int, array{id: string, name: string, type: string, group: string, multi_value: bool, values: array<int, string>}>
     */
    private function normalizeHbAttributes(array $required): array
    {
        $attributes = [];
        foreach ($required as $attribute) {
            $attributeId = (string) ($attribute['id'] ?? '');
            if ($attributeId === '') {
                continue;
            }
            $values = array_values(array_filter(
                array_map('strval', (array) ($attribute['values'] ?? [])),
                static fn(string $v): bool => $v !== ''
            ));
            $attributes[] = [
                'id'          => $attributeId,
                'name'        => (string) ($attribute['name'] ?? $attributeId),
                'type'        => strtolower((string) ($attribute['type'] ?? 'string')),
                'group'       => (string) ($attribute['group'] ?? ''),
                'multi_value' => (bool) ($attribute['multiValue'] ?? false),
                'values'      => $values,
            ];
        }

        return $attributes;
    }

    /**
     * Kategorideki mağaza ürünleri (en fazla HB_PRODUCT_LIMIT) için çözülen HB özellikleri.
     *
     * @param array<string, mixed> $mapping
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function resolveHbProducts(array $mapping): array
    {
        $productModel = $this->container->get(Product::class);
        $conditions = ['category_id' => (int) $mapping['category_id'], 'is_archived' => 0];
        $rows = $productModel->all($conditions, 'name ASC', self::HB_PRODUCT_LIMIT);
        $total = count($rows) < self::HB_PRODUCT_LIMIT ? count($rows) : $productModel->count($conditions);

        $publishService = $this->container->get(ProductPublishService::class);
        $products = [];

        foreach ($rows as $product) {
            try {
                $resolved = $publishService->resolveHepsiburadaAttributes($product, $mapping);
            } catch (\Throwable $e) {
                $resolved = [
                    'details' => [],
                    'brand'   => ['value' => '', 'source' => ''],
                    'errors'  => ['Özellikler çözülemedi: ' . $e->getMessage()],
                ];
            }

            $values = [];
            foreach ((array) ($resolved['details'] ?? []) as $detail) {
                $values[(string) ($detail['id'] ?? '')] = [
                    'value'  => (string) ($detail['value'] ?? ''),
                    'source' => (string) ($detail['source'] ?? ''),
                    'valid'  => (bool) ($detail['valid'] ?? false),
                ];
            }

            $products[] = [
                'id'     => (int) $product['id'],
                'key'    => (string) $this->mappingModel->attributeProductKey($product),
                'name'   => trim(strip_tags((string) ($product['name'] ?? ''))),
                'sku'    => (string) ($product['sku'] ?? ''),
                'values' => (object) $values,
                'brand'  => [
                    'value'  => (string) ($resolved['brand']['value'] ?? ''),
                    'source' => (string) ($resolved['brand']['source'] ?? ''),
                ],
                'errors' => array_values(array_map('strval', (array) ($resolved['errors'] ?? []))),
            ];
        }

        return [$products, $total];
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function hbMappingSummary(array $mapping): array
    {
        $category = $this->container->get(Category::class)->find((int) $mapping['category_id']);
        $hbCategoryId = trim((string) $mapping['marketplace_category_id']);
        $status = $this->mappingModel->getMarketplaceCategoryLeafStatus((int) $mapping['marketplace_id'], $hbCategoryId);

        return [
            'id'               => (int) $mapping['id'],
            'category_id'      => (int) $mapping['category_id'],
            'category_name'    => $category !== false ? (string) ($category['name'] ?? '') : '',
            'category_path'    => $category !== false ? (string) ($category['full_path'] ?? '') : '',
            'hb_category_id'   => $hbCategoryId,
            'hb_category_name' => (string) ($mapping['marketplace_category_name'] ?? ''),
            'hb_category_path' => (string) ($mapping['marketplace_category_path'] ?? ''),
            'sellable'         => $status === null ? null : (bool) ($status['sellable'] ?? $status['is_leaf'] ?? true),
        ];
    }

    /**
     * Marka alanı için öneri listesi: HB marka önbelleği + mağazanın kendi markaları.
     *
     * @return array<int, string>
     */
    private function hbBrandSuggestions(int $marketplaceId): array
    {
        $names = [];
        try {
            foreach ($this->container->get(MarketplaceBrand::class)->all(['marketplace_id' => $marketplaceId], 'name ASC', self::HB_SUGGESTION_LIMIT) as $row) {
                $names[] = trim((string) ($row['name'] ?? ''));
            }
            foreach ($this->container->get(Brand::class)->all([], 'name ASC', self::HB_SUGGESTION_LIMIT) as $row) {
                $names[] = trim((string) ($row['name'] ?? ''));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('HB marka önerileri okunamadı: ' . $e->getMessage(), 'CategoryMapping');
        }

        $names = array_values(array_unique(array_filter($names, static fn(string $n): bool => $n !== '')));
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function assertProductInMapping(string $productKey, array $mapping): void
    {
        if (!preg_match(self::PRODUCT_KEY_PATTERN, $productKey, $match)) {
            $this->error('Geçersiz ürün.', 422);
        }

        $product = $this->container->get(Product::class)->find((int) $match[1]);
        if ($product === false || (int) ($product['category_id'] ?? 0) !== (int) $mapping['category_id']) {
            $this->error('Ürün bu kategoride bulunamadı.', 404);
        }
    }

    private function validatedBrandValue(string $value, string $productKey): string
    {
        if ($productKey !== '') {
            $this->error('Marka ürün bazında değil, kategori varsayılanı olarak girilir.', 422);
        }
        if (mb_strlen($value) > self::HB_BRAND_MAX) {
            $this->error('Marka en fazla ' . self::HB_BRAND_MAX . ' karakter olabilir.', 422);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function validatedAttributeValue(array $mapping, string $attributeId, string $value): string
    {
        $hepsiburada = $this->hepsiburada();
        try {
            $required = $hepsiburada->getRequiredCategoryAttributes(trim((string) $mapping['marketplace_category_id']));
        } catch (\Throwable $e) {
            $this->error('Hepsiburada özellikleri alınamadı: ' . $e->getMessage(), 502);
        }

        $target = null;
        foreach ($required as $attribute) {
            if ((string) ($attribute['id'] ?? '') === $attributeId) {
                $target = $attribute;
                break;
            }
        }
        if ($target === null) {
            $this->error('Bu Hepsiburada kategorisinde böyle bir zorunlu özellik yok.', 422);
        }

        if ($value !== '' && strtolower((string) ($target['type'] ?? '')) === 'enum') {
            $canonical = $hepsiburada->canonicalAttributeValue($value, array_map('strval', (array) ($target['values'] ?? [])));
            if ($canonical === null) {
                $this->error(sprintf(
                    "'%s' Hepsiburada'nın %s listesinde yok. Listeden bir değer seçin.",
                    mb_substr($value, 0, 60),
                    (string) ($target['name'] ?? $attributeId)
                ), 422);
            }
            $value = $canonical;
        }

        if (mb_strlen($value) > self::HB_VALUE_MAX) {
            $this->error('Değer en fazla ' . self::HB_VALUE_MAX . ' karakter olabilir.', 422);
        }

        return $value;
    }

    // ─── Genel yardımcılar ────────────────────────────────────────────

    /**
     * attribute_mapping_json birleştirme. HB satırında (SPEC kural c) mevcut defaults/products
     * korunur ve gelen değerler üzerine yazılır; liste biçimli veri `legacy_list` altında tutulur.
     * HB dışı satırlarda gelen liste doğrulanıp olduğu gibi saklanır.
     *
     * @param array<string, mixed> $mapping
     */
    private function mergeAttributeMappingJson(array $mapping, string $slug, string $postedJson): string
    {
        $posted = json_decode($postedJson, true);
        if (!is_array($posted)) {
            throw new \InvalidArgumentException('Özellik eşleştirme verisi geçerli JSON değil.');
        }

        if ($slug !== self::HB_SLUG) {
            return json_encode($posted, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $merged = ['defaults' => [], 'products' => []];
        $current = json_decode((string) ($mapping['attribute_mapping_json'] ?? ''), true);
        if (is_array($current)) {
            $merged = $this->mergeHbAttributeDocument($merged, $current);
        }
        $merged = $this->mergeHbAttributeDocument($merged, $posted);
        $merged['updated_at'] = date('Y-m-d H:i:s');

        return json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeHbAttributeDocument(array $base, array $incoming): array
    {
        if (array_is_list($incoming)) {
            if ($incoming !== []) {
                $base['legacy_list'] = $incoming;
            }
            return $base;
        }

        $base['defaults'] = $this->attributeValueMap($incoming['defaults'] ?? []) + $base['defaults'];

        foreach ((array) ($incoming['products'] ?? []) as $productKey => $values) {
            if (!is_string($productKey) || !preg_match(self::PRODUCT_KEY_PATTERN, $productKey)) {
                continue;
            }
            $merged = $this->attributeValueMap($values) + ($base['products'][$productKey] ?? []);
            if ($merged !== []) {
                $base['products'][$productKey] = $merged;
            }
        }

        if (isset($incoming['legacy_list']) && is_array($incoming['legacy_list'])) {
            $base['legacy_list'] = $incoming['legacy_list'];
        }

        return $base;
    }

    /**
     * Yalnız geçerli özellik kimlikli, boş olmayan skaler değerler.
     *
     * @return array<string, string>
     */
    private function attributeValueMap(mixed $values): array
    {
        $map = [];
        foreach (is_array($values) ? $values : [] as $attributeId => $value) {
            $attributeId = (string) $attributeId;
            if (!preg_match(self::ATTRIBUTE_ID_PATTERN, $attributeId) || !is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $map[$attributeId] = mb_substr($value, 0, self::HB_VALUE_MAX);
            }
        }

        return $map;
    }

    /**
     * Satışa uygun OLMAYAN kategoriye bağlı eşleşmeler (önbellekte durum bilgisi yoksa boş).
     *
     * @return array<int, array<string, mixed>> category_id => {reason, mp_category_id, path}
     */
    private function unsellableMappings(int $marketplaceId): array
    {
        $model = $this->container->get(MarketplaceCategory::class);
        if (!method_exists($model, 'findUnsellableMappings')) {
            return [];
        }

        try {
            return $model->findUnsellableMappings(
                $marketplaceId,
                $this->container->get(TenantContext::class)->currentStoreId()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Satışa kapalı eşleşmeler okunamadı: ' . $e->getMessage(), 'CategoryMapping');
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accessibleMarketplaces(): array
    {
        $authorization = $this->authorization();

        return array_values(array_filter(
            $this->marketplaceModel->getActive(),
            static fn(array $mp): bool => $authorization->canAccessMarketplace((int) $mp['id'])
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $marketplaces
     * @return array<string, mixed>|null
     */
    private function pickMarketplace(array $marketplaces, int $requestedId): ?array
    {
        foreach ($marketplaces as $marketplace) {
            if ((int) $marketplace['id'] === $requestedId) {
                return $marketplace;
            }
        }

        return $marketplaces[0] ?? null;
    }

    /**
     * Kullanıcıya gösterilecek hata metni: veritabanı hatasının iç ayrıntısı (SQLSTATE, tablo/kolon)
     * gösterilmez, günlüğe yazılır; diğer hatalar kısaltılarak gösterilir.
     */
    private function publicError(\Throwable $e, string $context = ''): string
    {
        if ($e instanceof \PDOException) {
            if ($context !== '') {
                $this->logger->error($context . ' veritabanı hatası: ' . $e->getMessage(), 'CategoryMapping');
            }
            return 'veritabanı hatası; ayrıntı sistem günlüğüne yazıldı.';
        }
        return mb_substr($e->getMessage(), 0, 300);
    }

    private function failStore(Request $request, string $redirectPath, string $message): never
    {
        if ($request->isAjax()) {
            $this->error($message);
        }
        $this->redirectWithError($redirectPath, $message);
    }

    private function authorization(): AuthorizationService
    {
        return $this->container->get(AuthorizationService::class);
    }

    private function hepsiburada(): HepsiburadaService
    {
        return $this->container->get(HepsiburadaService::class);
    }
}
