<?php
/**
 * OzyConnect Panel — API Product Controller
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

namespace App\Controllers\Api;

use App\Core\Container;
use App\Core\Request;
use App\Models\Product;

final class ApiProductController extends ApiBaseController
{
    private readonly Product $productModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->productModel = $container->get(Product::class);
    }

    /**
     * GET /api/v1/products — Ürün listesi
     */
    public function list(Request $request): void
    {
        $this->requirePermission('products:read');

        $params = $this->paginationParams($request);
        $conditions = [];

        // Filtreler
        $search = trim($request->query('search', ''));
        $categoryId = $request->query('category_id');
        $brandId = $request->query('brand_id');
        $isActive = $request->query('is_active');
        $stockStatus = $request->query('stock_status');

        if ($categoryId !== null) {
            $conditions['category_id'] = (int) $categoryId;
        }
        if ($brandId !== null) {
            $conditions['brand_id'] = (int) $brandId;
        }
        if ($isActive !== null) {
            $conditions['is_active'] = (int) $isActive;
        }

        // Search + stock filtering needs raw query
        if ($search !== '' || $stockStatus !== null) {
            $this->listWithSearch($request, $params, $search, $categoryId, $brandId, $isActive, $stockStatus);
            return;
        }

        $result = $this->productModel->paginate(
            $params['page'],
            $params['per_page'],
            $conditions,
            $request->query('sort', 'id DESC')
        );

        $this->apiPaginated(
            array_map([$this, 'formatProduct'], $result['data']),
            $result['page'],
            $result['perPage'],
            $result['total']
        );
    }

    /**
     * GET /api/v1/products/{id} — Ürün detayı
     */
    public function show(Request $request, string $id): void
    {
        $this->requirePermission('products:read');

        $product = $this->productModel->find((int) $id);
        if ($product === false) {
            $this->apiNotFound('Ürün bulunamadı');
        }

        $this->apiSuccess($this->formatProduct($product));
    }

    /**
     * GET /api/v1/products/sku/{sku} — SKU ile ürün getir
     */
    public function showBySku(Request $request, string $sku): void
    {
        $this->requirePermission('products:read');

        $product = $this->productModel->findBySku($sku);
        if ($product === false) {
            $this->apiNotFound("SKU '{$sku}' bulunamadı");
        }

        $this->apiSuccess($this->formatProduct($product));
    }

    /**
     * POST /api/v1/products — Yeni ürün oluştur
     */
    public function create(Request $request): void
    {
        $this->requirePermission('products:write');

        $body = $this->jsonBody($request);
        $errors = $this->validateProduct($body);

        if (!empty($errors)) {
            $this->apiValidationError($errors);
        }

        // SKU benzersizlik kontrolü
        $existing = $this->productModel->findBySku($body['sku']);
        if ($existing !== false) {
            $this->apiError("SKU '{$body['sku']}' zaten mevcut", 'DUPLICATE_SKU', 409);
        }

        $data = [
            'sku'           => $body['sku'],
            'name'          => $body['name'],
            'barcode'       => $body['barcode'] ?? null,
            'category_id'   => isset($body['category_id']) ? (int) $body['category_id'] : null,
            'brand_id'      => isset($body['brand_id']) ? (int) $body['brand_id'] : null,
            // products tablosunda 'price' kolonu YOK; satis fiyati base_price.
            'base_price'    => (float) ($body['price'] ?? 0),
            'stock_qty'     => (int) ($body['stock_qty'] ?? 0),
            'weight'        => (float) ($body['weight'] ?? 0),
            'description'   => $body['description'] ?? null,
            'is_active'     => (int) ($body['is_active'] ?? 1),
        ];

        $id = $this->productModel->create($data);
        $product = $this->productModel->find($id);

        $this->apiSuccess($this->formatProduct($product), 'Ürün oluşturuldu');
    }

    /**
     * PUT /api/v1/products/{id} — Ürün güncelle
     */
    public function update(Request $request, string $id): void
    {
        $this->requirePermission('products:write');

        $product = $this->productModel->find((int) $id);
        if ($product === false) {
            $this->apiNotFound('Ürün bulunamadı');
        }

        $body = $this->jsonBody($request);
        $data = [];

        // Sadece gönderilen alanları güncelle
        // API alan adi 'price'; veritabani sutunu base_price (asagida eslenir).
        $allowedFields = ['name', 'barcode', 'category_id', 'brand_id', 'price', 'stock_qty', 'weight', 'description', 'is_active'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }

        // API alan adi 'price' → veritabani sutunu 'base_price'
        if (array_key_exists('price', $data)) {
            $data['base_price'] = (float) $data['price'];
            unset($data['price']);
        }

        if (empty($data)) {
            $this->apiError('Güncellenecek alan belirtilmedi', 'NO_DATA', 400);
        }

        $this->productModel->update((int) $id, $data);
        $updated = $this->productModel->find((int) $id);

        $this->apiSuccess($this->formatProduct($updated), 'Ürün güncellendi');
    }

    /**
     * PATCH /api/v1/products/{id}/stock — Stok güncelle
     */
    public function updateStock(Request $request, string $id): void
    {
        $this->requirePermission('stock:write');

        $product = $this->productModel->find((int) $id);
        if ($product === false) {
            $this->apiNotFound('Ürün bulunamadı');
        }

        $body = $this->jsonBody($request);
        if (!isset($body['stock_qty'])) {
            $this->apiValidationError(['stock_qty' => 'Stok miktarı zorunlu']);
        }

        $this->productModel->update((int) $id, [
            'stock_qty' => (int) $body['stock_qty'],
        ]);

        $this->apiSuccess([
            'id'        => (int) $id,
            'stock_qty' => (int) $body['stock_qty'],
        ], 'Stok güncellendi');
    }

    /**
     * PATCH /api/v1/products/{id}/price — Fiyat güncelle
     */
    public function updatePrice(Request $request, string $id): void
    {
        $this->requirePermission('products:write');

        $product = $this->productModel->find((int) $id);
        if ($product === false) {
            $this->apiNotFound('Ürün bulunamadı');
        }

        $body = $this->jsonBody($request);
        if (!isset($body['price'])) {
            $this->apiValidationError(['price' => 'Fiyat zorunlu']);
        }

        $this->productModel->update((int) $id, [
            'base_price' => (float) $body['price'],
        ]);

        $this->apiSuccess([
            'id'    => (int) $id,
            'price' => (float) $body['price'],
        ], 'Fiyat güncellendi');
    }

    // ─── Private Helpers ─────────────────────────────

    private function listWithSearch(Request $request, array $params, string $search, ?string $catId, ?string $brandId, ?string $isActive, ?string $stockStatus): void
    {
        // Mağaza filtresi ŞART: '1=1' ile başlayan sorgu API üzerinden TÜM bayilerin
        // ürünlerini döndürüyordu.
        $where = ['p.store_id = :__store_id'];
        $binds = ['__store_id' => $this->container->get(\App\Core\TenantContext::class)->currentStoreId()];

        if ($search !== '') {
            $where[] = "(p.name LIKE :search OR p.sku LIKE :search2 OR p.barcode LIKE :search3)";
            $binds['search'] = "%{$search}%";
            $binds['search2'] = "%{$search}%";
            $binds['search3'] = "%{$search}%";
        }
        if ($catId !== null) {
            $where[] = "p.category_id = :cat";
            $binds['cat'] = (int) $catId;
        }
        if ($brandId !== null) {
            $where[] = "p.brand_id = :brand";
            $binds['brand'] = (int) $brandId;
        }
        if ($isActive !== null) {
            $where[] = "p.is_active = :active";
            $binds['active'] = (int) $isActive;
        }
        if ($stockStatus === 'low') {
            $where[] = "p.stock_qty > 0 AND p.stock_qty <= 5";
        } elseif ($stockStatus === 'out') {
            $where[] = "p.stock_qty = 0";
        }

        $whereStr = implode(' AND ', $where);
        $offset = ($params['page'] - 1) * $params['per_page'];

        $total = $this->productModel->apiCount($whereStr, $binds);

        // SQL Injection koruması: sort parametresini whitelist ile kontrol et
        $sortParam = $request->query('sort', 'p.id DESC');
        $allowedSorts = [
            'p.id ASC' => 'p.id ASC',
            'p.id DESC' => 'p.id DESC',
            'p.name ASC' => 'p.name ASC',
            'p.name DESC' => 'p.name DESC',
            'p.sku ASC' => 'p.sku ASC',
            'p.sku DESC' => 'p.sku DESC',
            'p.price ASC' => 'p.price ASC',
            'p.price DESC' => 'p.price DESC',
            'p.stock_qty ASC' => 'p.stock_qty ASC',
            'p.stock_qty DESC' => 'p.stock_qty DESC',
            'p.created_at ASC' => 'p.created_at ASC',
            'p.created_at DESC' => 'p.created_at DESC',
            'p.updated_at ASC' => 'p.updated_at ASC',
            'p.updated_at DESC' => 'p.updated_at DESC',
        ];
        $sort = $allowedSorts[$sortParam] ?? 'p.id DESC';

        $rows = $this->productModel->apiRows($whereStr, $binds, $sort, (int) $params['per_page'], (int) $offset);

        $this->apiPaginated(
            array_map([$this, 'formatProduct'], $rows),
            $params['page'],
            $params['per_page'],
            $total
        );
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function formatProduct(array $p): array
    {
        return [
            'id'          => (int) $p['id'],
            'sku'         => $p['sku'] ?? '',
            'barcode'     => $p['barcode'] ?? null,
            'name'        => $p['name'] ?? '',
            'category_id' => isset($p['category_id']) ? (int) $p['category_id'] : null,
            'brand_id'    => isset($p['brand_id']) ? (int) $p['brand_id'] : null,
            'price'       => (float) ($p['price'] ?? 0),
            'stock_qty'   => (int) ($p['stock_qty'] ?? 0),
            'weight'      => (float) ($p['weight'] ?? 0),
            'is_active'   => (bool) ($p['is_active'] ?? true),
            'image_url'   => $p['image_url'] ?? null,
            'created_at'  => $p['created_at'] ?? null,
            'updated_at'  => $p['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function validateProduct(array $body): array
    {
        $errors = [];
        if (empty($body['sku'])) {
            $errors['sku'] = 'SKU zorunlu';
        }
        if (empty($body['name'])) {
            $errors['name'] = 'Ürün adı zorunlu';
        }
        return $errors;
    }
}
