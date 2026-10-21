<?php
/**
 * OzyConnect Panel — Product Model (PIM Ana Tablosu)
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

namespace App\Models;

final class Product extends BaseModel
{
    protected string $table = 'products';

    /**
     * API ürün listesi sayımı.
     *
     * ApiProductController protected rawQueryOne()'ı çağırıyordu (ölümcül hata).
     * WHERE parçası çağıranda kurulur ama mağaza filtresi ZORUNLUDUR — burada da
     * doğrulanır, aksi halde istisna atılır.
     *
     * @param array<string, mixed> $binds
     */
    public function apiCount(string $whereSql, array $binds): int
    {
        $this->assertStoreFiltered($whereSql);

        $row = $this->rawQueryOne("SELECT COUNT(*) AS cnt FROM products p WHERE {$whereSql}", $binds);

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * API ürün listesi. $orderBy çağıranda BEYAZ LİSTEDEN geçirilmiş olmalıdır.
     *
     * @param array<string, mixed> $binds
     * @return array<int, array<string, mixed>>
     */
    public function apiRows(string $whereSql, array $binds, string $orderBy, int $limit, int $offset): array
    {
        $this->assertStoreFiltered($whereSql);

        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return $this->rawQuery(
            "SELECT p.* FROM products p WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}",
            $binds
        );
    }

    /**
     * Mağaza filtresi olmayan bir WHERE parçası kabul edilmez (kiracı sızıntısı koruması).
     */
    private function assertStoreFiltered(string $whereSql): void
    {
        if (!str_contains($whereSql, 'store_id')) {
            throw new \RuntimeException('Ürün sorgusu mağaza filtresi olmadan çalıştırılamaz.');
        }
    }

    /**
     * SKU ile bul
     *
     * @return array<string, mixed>|false
     */
    public function findBySku(string $sku): array|false
    {
        return $this->findBy(['sku' => $sku]);
    }

    /**
     * Pazaryeri ilanını elle bağlamak için ürün arama (ad / SKU / barkod).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchForLinking(string $query, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $like  = '%' . $query . '%';

        return $this->rawQuery(
            "SELECT p.id, p.name, p.sku, p.barcode, p.base_price, p.stock_qty, p.main_image
             FROM products p
             WHERE p.store_id = :store_id
               AND p.is_archived = 0
               AND (p.name LIKE :q1 OR p.sku LIKE :q2 OR p.barcode LIKE :q3)
             ORDER BY (p.sku = :exact1) DESC, (p.barcode = :exact2) DESC, p.name ASC
             LIMIT {$limit}",
            [
                'store_id' => $this->tenantId(),
                'q1'       => $like,
                'q2'       => $like,
                'q3'       => $like,
                'exact1'   => $query,
                'exact2'   => $query,
            ]
        );
    }

    /**
     * Barkod ile bul
     *
     * @return array<string, mixed>|false
     */
    public function findByBarcode(string $barcode): array|false
    {
        return $this->findBy(['barcode' => $barcode]);
    }

    /**
     * AI Toplu Doldurma listesi — id, ad, sku + içerik/AI durumu.
     * İçeriği eksik (açıklama/seo boş, AI hiç üretilmemiş) ürünler üste gelir.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForBulk(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $sql = "SELECT `id`, `name`, `sku`,
                       CASE WHEN (`description` IS NULL OR `description` = '') THEN 1 ELSE 0 END AS no_desc,
                       CASE WHEN (`seo_title` IS NULL OR `seo_title` = '') THEN 1 ELSE 0 END AS no_seo,
                       `ai_generated_at`
                FROM `products`
                WHERE `store_id` = :sid
                ORDER BY (`ai_generated_at` IS NULL) DESC,
                         (CASE WHEN (`description` IS NULL OR `description` = '') THEN 1 ELSE 0 END) DESC,
                         `id` DESC
                LIMIT {$limit}";
        return $this->rawQuery($sql, ['sid' => $this->tenantId()]);
    }

    /**
     * SKU veya barkod listesiyle toplu resim haritası oluştur
     *
     * @param array<int, string> $skus
     * @param array<int, string> $barcodes
     * @return array<string, string> Anahtar: normalize edilmiş SKU/barkod, Değer: resim URL
     */
    public function getImageMapBySkuOrBarcode(array $skus, array $barcodes): array
    {
        $skus = array_values(array_filter(array_unique(array_map('trim', $skus)), fn(string $v) => $v !== ''));
        $barcodes = array_values(array_filter(array_unique(array_map('trim', $barcodes)), fn(string $v) => $v !== ''));

        if ($skus === [] && $barcodes === []) {
            return [];
        }

        $conditions = [];
        $params = [];

        if ($skus !== []) {
            $ph = [];
            foreach ($skus as $i => $v) {
                $key = 'sku_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $v;
            }
            $conditions[] = 'sku IN (' . implode(',', $ph) . ')';
        }

        if ($barcodes !== []) {
            $ph = [];
            foreach ($barcodes as $i => $v) {
                $key = 'bc_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $v;
            }
            $conditions[] = 'barcode IN (' . implode(',', $ph) . ')';
        }

        $params['store_id'] = $this->tenantId();

        $rows = $this->rawQuery(
            'SELECT sku, barcode, main_image, images_json
             FROM products
             WHERE (' . implode(' OR ', $conditions) . ')
               AND store_id = :store_id',
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $imageUrl = trim((string) ($row['main_image'] ?? ''));

            if ($imageUrl === '') {
                $images = json_decode((string) ($row['images_json'] ?? '[]'), true);
                if (is_array($images) && $images !== []) {
                    $first = $images[0] ?? null;
                    if (is_string($first)) {
                        $imageUrl = trim($first);
                    } elseif (is_array($first)) {
                        $imageUrl = trim((string) ($first['url'] ?? $first['src'] ?? ''));
                    }
                }
            }

            if ($imageUrl === '') {
                continue;
            }

            $sku = mb_strtolower(trim((string) ($row['sku'] ?? '')), 'UTF-8');
            $barcode = mb_strtolower(trim((string) ($row['barcode'] ?? '')), 'UTF-8');

            if ($sku !== '') {
                $map[$sku] = $imageUrl;
            }
            if ($barcode !== '') {
                $map[$barcode] = $imageUrl;
            }
        }

        return $map;
    }

    /**
     * Stok durumuna göre ürünleri say
     *
     * @return array{in_stock: int, low_stock: int, out_of_stock: int, total: int}
     */
    public function getStockSummary(): array
    {
        $row = $this->rawQueryOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN stock_qty > min_stock_alert THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN stock_qty > 0 AND stock_qty <= min_stock_alert THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) as out_of_stock
             FROM products WHERE is_active = 1 AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'in_stock'     => (int) ($row['in_stock'] ?? 0),
            'low_stock'    => (int) ($row['low_stock'] ?? 0),
            'out_of_stock' => (int) ($row['out_of_stock'] ?? 0),
        ];
    }

    /**
     * Zenginleştirme istatistikleri (pazaryeri bazlı)
     *
     * @return array{total: int, complete: int, partial: int, pending: int, missing_category: int, missing_brand: int, missing_price: int, missing_images: int, missing_stock: int}
     */
    public function getEnrichmentStats(int $marketplaceId): array
    {
        $row = $this->rawQueryOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN p.enrichment_status = 'complete' THEN 1 ELSE 0 END) as complete_count,
                SUM(CASE WHEN p.enrichment_status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN p.enrichment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(p.needs_category_mapping) as missing_category,
                SUM(p.needs_brand_mapping) as missing_brand,
                SUM(p.missing_price) as missing_price,
                SUM(p.missing_images) as missing_images,
                SUM(p.missing_stock) as missing_stock
             FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id AND mp.store_id = :store_id2
             WHERE mp.marketplace_id = :mid AND p.is_active = 1 AND p.store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );

        return [
            'total'            => (int) ($row['total'] ?? 0),
            'complete'         => (int) ($row['complete_count'] ?? 0),
            'partial'          => (int) ($row['partial_count'] ?? 0),
            'pending'          => (int) ($row['pending_count'] ?? 0),
            'missing_category' => (int) ($row['missing_category'] ?? 0),
            'missing_brand'    => (int) ($row['missing_brand'] ?? 0),
            'missing_price'    => (int) ($row['missing_price'] ?? 0),
            'missing_images'   => (int) ($row['missing_images'] ?? 0),
            'missing_stock'    => (int) ($row['missing_stock'] ?? 0),
        ];
    }

    /**
     * Pazaryerine bağlı aktif ürünleri getir (enrichment için)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByMarketplace(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT p.* FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id AND mp.store_id = :store_id2
             WHERE mp.marketplace_id = :mid AND p.is_active = 1 AND p.store_id = :store_id
             ORDER BY p.id ASC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * ID listesine göre aktif ürünleri getir
     *
     * @param array<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_map('intval', $ids));
        return $this->rawQuery(
            "SELECT * FROM products WHERE id IN ({$placeholders}) AND is_active = 1 AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
    }

    /**
     * Varyantlardan fiyat bul (enrichment price fallback)
     */
    public function getVariantPrice(int $parentId): ?float
    {
        $row = $this->rawQueryOne(
            "SELECT base_price FROM products WHERE parent_id = :pid AND base_price > 0 AND store_id = :store_id LIMIT 1",
            ['pid' => $parentId, 'store_id' => $this->tenantId()]
        );
        return $row !== false ? (float) $row['base_price'] : null;
    }

    /**
     * Enrichment filtreli ürün listesi (dashboard için)
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getIncompleteForEnrichment(int $marketplaceId, array $filters = []): array
    {
        $where = ['mp.marketplace_id = :mid', 'p.is_active = 1', 'p.store_id = :store_id', 'mp.store_id = :store_id2'];
        $params = ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()];

        if (!empty($filters['missing_category'])) {
            $where[] = 'p.needs_category_mapping = 1';
        }
        if (!empty($filters['missing_brand'])) {
            $where[] = 'p.needs_brand_mapping = 1';
        }
        if (!empty($filters['missing_price'])) {
            $where[] = 'p.missing_price = 1';
        }
        if (!empty($filters['missing_images'])) {
            $where[] = 'p.missing_images = 1';
        }
        if (!empty($filters['missing_stock'])) {
            $where[] = 'p.missing_stock = 1';
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.enrichment_status = :status';
            $params['status'] = $filters['status'];
        }

        $whereStr = implode(' AND ', $where);
        $params['store_id3'] = $this->tenantId();
        $params['store_id4'] = $this->tenantId();

        return $this->rawQuery(
            "SELECT p.*, c.name as category_name, b.name as brand_name
             FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id3
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id4
             WHERE {$whereStr}
             ORDER BY p.enrichment_status ASC, p.updated_at DESC
             LIMIT 500",
            $params
        );
    }

    /**
     * Kategori eşleştirmesi uygulanacak ürün ID'lerini getir
     *
     * @return array<int, array{id: int}>
     */
    public function getProductIdsForCategoryMapping(int $marketplaceId, string $marketplaceCategoryId): array
    {
        return $this->rawQuery(
            "SELECT p.id FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id AND mp.store_id = :store_id2
             WHERE mp.marketplace_id = :mid
               AND mp.marketplace_category_id = :mcid
               AND p.store_id = :store_id
               AND (p.category_id IS NULL OR p.needs_category_mapping = 1)",
            ['mid' => $marketplaceId, 'mcid' => $marketplaceCategoryId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Marka eşleştirmesi uygulanacak ürün ID'lerini getir
     *
     * @return array<int, array{id: int}>
     */
    public function getProductIdsForBrandMapping(int $marketplaceId, string $marketplaceBrandId): array
    {
        return $this->rawQuery(
            "SELECT p.id FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id AND mp.store_id = :store_id2
             WHERE mp.marketplace_id = :mid
               AND mp.marketplace_brand_id = :mbid
               AND p.store_id = :store_id
               AND (p.brand_id IS NULL OR p.needs_brand_mapping = 1)",
            ['mid' => $marketplaceId, 'mbid' => $marketplaceBrandId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Kritik stok altındaki ürünleri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLowStock(int $limit = 50): array
    {
        return $this->rawQuery(
            "SELECT p.*, c.name as category_name, b.name as brand_name
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id2
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id3
             WHERE p.is_active = 1 AND p.stock_qty <= p.min_stock_alert AND p.store_id = :store_id
             ORDER BY p.stock_qty ASC
             LIMIT :limit",
            ['limit' => $limit, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId(), 'store_id3' => $this->tenantId()]
        );
    }

    /**
     * Ürün listesi (filtreli + sayfalı + join'li)
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public function getFiltered(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1', 'p.store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        if (!empty($filters['search'])) {
            $where[] = "(p.name LIKE :search OR p.sku LIKE :search2 OR p.barcode LIKE :search3)";
            $params['search']  = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        if (!empty($filters['brand_id'])) {
            $where[] = "p.brand_id = :brand_id";
            $params['brand_id'] = $filters['brand_id'];
        }

        if (isset($filters['is_active'])) {
            $where[] = "p.is_active = :is_active";
            $params['is_active'] = $filters['is_active'];
        }

        if (isset($filters['stock_status'])) {
            $where[] = match ($filters['stock_status']) {
                'in_stock'     => "p.stock_qty > p.min_stock_alert",
                'low_stock'    => "p.stock_qty > 0 AND p.stock_qty <= p.min_stock_alert",
                'out_of_stock' => "p.stock_qty <= 0",
                default        => "1=1",
            };
        }

        if (isset($filters['has_variants'])) {
            $where[] = "p.has_variants = :has_variants";
            $params['has_variants'] = $filters['has_variants'];
        }

        // Sadece ana ürünleri göster (varyant değil)
        if (empty($filters['show_variants'])) {
            $where[] = "p.parent_id IS NULL";
        }

        $whereStr = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products p WHERE {$whereStr}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $params['store_id2'] = $this->tenantId();
        $params['store_id3'] = $this->tenantId();
        $params['store_id4'] = $this->tenantId();

        $data = $this->rawQuery(
            "SELECT p.*,
                    c.name as category_name,
                    b.name as brand_name,
                    (SELECT COUNT(*) FROM products v WHERE v.parent_id = p.id AND v.store_id = :store_id2) as variant_count
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id3
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id4
             WHERE {$whereStr}
             ORDER BY p.updated_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data'       => $data,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Ürün detay (join'li)
     *
     * @return array<string, mixed>|false
     */
    public function getDetail(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT p.*,
                    c.name as category_name, c.full_path as category_path,
                    b.name as brand_name
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id2
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id3
             WHERE p.id = :id AND p.store_id = :store_id",
            ['id' => $id, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId(), 'store_id3' => $this->tenantId()]
        );
    }

    /**
     * Varyantları getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVariants(int $parentId): array
    {
        return $this->all(['parent_id' => $parentId], 'id ASC');
    }

    /**
     * Parent + tüm varyantları tek seferde çek (VariantPublishService için)
     *
     * @return array{parent: array<string, mixed>|false, variants: array<int, array<string, mixed>>}
     */
    public function getVariantGroup(int $parentId): array
    {
        $parent = $this->getDetail($parentId);

        $variants = $this->rawQuery(
            "SELECT p.*, c.name as category_name, b.name as brand_name
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id2
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id3
             WHERE p.parent_id = :pid AND p.is_active = 1 AND p.store_id = :store_id
             ORDER BY p.id ASC",
            ['pid' => $parentId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId(), 'store_id3' => $this->tenantId()]
        );

        return [
            'parent'   => $parent,
            'variants' => $variants,
        ];
    }

    /**
     * Varyant attribute'larını çek (Renk: Kırmızı, Beden: L gibi)
     *
     * @return array<int, array{attribute_name: string, attribute_value: string}>
     */
    public function getVariantAttributeValues(int $productId): array
    {
        return $this->rawQuery(
            "SELECT pa.name as attribute_name, pa.slug as attribute_slug,
                    COALESCE(av.value, pav.custom_value) as attribute_value,
                    pav.attribute_id, pav.attribute_value_id
             FROM product_attribute_values pav
             JOIN product_attributes pa ON pav.attribute_id = pa.id
             LEFT JOIN attribute_values av ON pav.attribute_value_id = av.id
             WHERE pav.product_id = :pid AND pav.store_id = :store_id
             ORDER BY pa.sort_order ASC",
            ['pid' => $productId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Varyant grubunun özet bilgileri (toplam stok, fiyat aralığı, varyant sayısı)
     *
     * @return array{variant_count: int, total_stock: int, min_price: float, max_price: float}
     */
    public function getVariantSummary(int $parentId): array
    {
        $row = $this->rawQueryOne(
            "SELECT
                COUNT(*) as variant_count,
                COALESCE(SUM(stock_qty), 0) as total_stock,
                COALESCE(MIN(base_price), 0) as min_price,
                COALESCE(MAX(base_price), 0) as max_price
             FROM products
             WHERE parent_id = :pid AND is_active = 1 AND store_id = :store_id",
            ['pid' => $parentId, 'store_id' => $this->tenantId()]
        );

        return [
            'variant_count' => (int) ($row['variant_count'] ?? 0),
            'total_stock'   => (int) ($row['total_stock'] ?? 0),
            'min_price'     => (float) ($row['min_price'] ?? 0),
            'max_price'     => (float) ($row['max_price'] ?? 0),
        ];
    }

    /**
     * Pazaryerinden içe aktarılan varyant gruplarının kaynakları. Bu gruplarda ana ürün de pazaryerinde
     * satılan bir varyanttır (ProductImportService::groupVariantsFromImport).
     */
    public const IMPORTED_VARIANT_GROUP_SOURCES = [
        'trendyol', 'hepsiburada', 'n11', 'amazon', 'pazarama', 'ciceksepeti',
        'idefix', 'pttavm', 'shopify', 'woocommerce', 'opencart',
    ];

    /**
     * İçe aktarılmış varyant grubu mu: hiçbir üyede varyant özniteliği (variant_attributes) yok ve ana ürünün
     * kaynağı bir pazaryeri. Böyle bir grupta ana ürün kendi ilanına bağlı, satılan bir varyanttır; stoğu
     * KENDİ stoğudur (grup toplamı değil). Varyant formu, XML ya da OziyGO seçenekleri öznitelik taşır.
     *
     * @param array<string, mixed> $parent
     * @param array<int, array<string, mixed>> $variants
     */
    public static function isImportedVariantGroup(array $parent, array $variants): bool
    {
        foreach (array_merge([$parent], $variants) as $member) {
            $decoded = json_decode((string) ($member['variant_attributes'] ?? ''), true);
            if (is_array($decoded) && $decoded !== []) {
                return false;
            }
        }

        $source = strtolower(trim((string) ($parent['source'] ?? '')));

        return in_array($source, self::IMPORTED_VARIANT_GROUP_SOURCES, true);
    }

    /**
     * Ana ürünün stoğu kendisine mi ait? (İçe aktarılmış varyant grubu → evet; varyantların toplamı
     * bu ürüne YAZILMAMALI, yazılırsa toplam onun pazaryeri ilanına stok olarak gider.)
     */
    public function parentKeepsOwnStock(int $parentId): bool
    {
        if ($parentId <= 0) {
            return false;
        }

        $parent = $this->rawQueryOne(
            "SELECT id, source, variant_attributes FROM products WHERE id = :pid AND store_id = :store_id",
            ['pid' => $parentId, 'store_id' => $this->tenantId()]
        );
        if ($parent === false) {
            return false;
        }

        $variants = $this->rawQuery(
            "SELECT id, variant_attributes FROM products
             WHERE parent_id = :pid AND is_active = 1 AND store_id = :store_id",
            ['pid' => $parentId, 'store_id' => $this->tenantId()]
        );

        return $variants !== [] && self::isImportedVariantGroup($parent, $variants);
    }

    /**
     * Stok güncelle (atomik — SELECT FOR UPDATE ile)
     */
    public function updateStockAtomic(int $productId, int $newQty): bool
    {
        $stmt = $this->db->query(
            "UPDATE products SET stock_qty = :qty, updated_at = NOW()
             WHERE id = :id AND store_id = :store_id",
            ['qty' => $newQty, 'id' => $productId, 'store_id' => $this->tenantId()]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * FOR UPDATE ile stok oku (transaction içinde kullan)
     *
     * @return array<string, mixed>|false
     */
    public function findForUpdate(int $productId): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM products WHERE id = :id AND store_id = :store_id FOR UPDATE",
            ['id' => $productId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Ürünün tüm pazaryerlerindeki durumunu getir
     *
     * @return array{product: array<string, mixed>|false, marketplaces: array<int, array<string, mixed>>, active_count: int}
     */
    public function getWithMarketplaceStatus(int $productId): array
    {
        $product = $this->getDetail($productId);

        $marketplaces = $this->rawQuery(
            "SELECT mp.*, m.slug, m.display_name, m.is_active as marketplace_active
             FROM marketplace_products mp
             JOIN marketplaces m ON mp.marketplace_id = m.id
             WHERE mp.product_id = :pid AND mp.store_id = :store_id
             ORDER BY m.display_name ASC",
            ['pid' => $productId, 'store_id' => $this->tenantId()]
        );

        $activeCount = 0;
        foreach ($marketplaces as $mp) {
            if ((int) $mp['is_active'] === 1 && (int) $mp['is_listed'] === 1) {
                $activeCount++;
            }
        }

        return [
            'product'      => $product,
            'marketplaces' => $marketplaces,
            'active_count' => $activeCount,
        ];
    }

    /**
     * Ürün listesinde her ürünün aktif pazaryeri sayısını ekle
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    public function appendMarketplaceCounts(array $products): array
    {
        if (empty($products)) {
            return $products;
        }

        $ids = array_column($products, 'id');
        $placeholders = implode(',', array_map('intval', $ids));

        $counts = $this->rawQuery(
            "SELECT mp.product_id,
                    COUNT(*) as total_marketplaces,
                    SUM(CASE WHEN mp.is_active = 1 AND mp.is_listed = 1 THEN 1 ELSE 0 END) as active_marketplaces
             FROM marketplace_products mp
             WHERE mp.product_id IN ({$placeholders})
               AND mp.store_id = :store_id
             GROUP BY mp.product_id",
            ['store_id' => $this->tenantId()]
        );

        $countMap = [];
        foreach ($counts as $row) {
            $countMap[(int) $row['product_id']] = [
                'total_marketplaces'  => (int) $row['total_marketplaces'],
                'active_marketplaces' => (int) $row['active_marketplaces'],
            ];
        }

        foreach ($products as &$product) {
            $pid = (int) $product['id'];
            $product['total_marketplaces']  = $countMap[$pid]['total_marketplaces'] ?? 0;
            $product['active_marketplaces'] = $countMap[$pid]['active_marketplaces'] ?? 0;
        }
        unset($product);

        return $products;
    }

    /**
     * Feed için ürünleri getir (filtreli)
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getForFeed(array $filters = []): array
    {
        $where = ['p.is_active = 1', 'p.store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        if (!empty($filters['min_stock'])) {
            $where[] = "p.stock_qty >= :min_stock";
            $params['min_stock'] = $filters['min_stock'];
        }

        if (!empty($filters['category_ids'])) {
            $ids = implode(',', array_map('intval', $filters['category_ids']));
            $where[] = "p.category_id IN ({$ids})";
        }

        if (!empty($filters['brand_ids'])) {
            $ids = implode(',', array_map('intval', $filters['brand_ids']));
            $where[] = "p.brand_id IN ({$ids})";
        }

        if (empty($filters['include_inactive'])) {
            $where[] = "p.is_active = 1";
        }

        $whereStr = implode(' AND ', $where);

        $parentWhere = empty($filters['include_variants'])
            ? "AND p.parent_id IS NULL"
            : "";

        $params['store_id2'] = $this->tenantId();
        $params['store_id3'] = $this->tenantId();

        return $this->rawQuery(
            "SELECT p.*, c.name as category_name, c.full_path as category_path,
                    b.name as brand_name
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id2
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id3
             WHERE {$whereStr} {$parentWhere}
             ORDER BY p.id ASC",
            $params
        );
    }

    /**
     * DataTables server-side processing için ürün listesi
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, filtered: int}
     */
    /**
     * Ürün listesi filtrelerini tek yerde WHERE'e çevir.
     *
     * getForDataTables() içinden çıkarıldı; artık aynı filtreler hem liste, hem
     * "filtreye uyan tüm ürünleri seç", hem de toplu gönderim hazırlık raporu
     * tarafından kullanılıyor (tek doğruluk kaynağı).
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    /**
     * Verilen ürünlerin XML profil kimlikleri (tekilleştirilmiş).
     *
     * Profili olmayan ürün 0 olarak döner; böylece çağıran taraf "hepsi aynı
     * profilden mi" sorusunu tek bakışta yanıtlayabilir.
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public function xmlProfilleriOfIds(array $ids): array
    {
        $idler = array_values(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0));
        if ($idler === []) {
            return [];
        }

        $yerTutucu = [];
        $params    = ['store_id' => $this->tenantId()];

        foreach (array_slice($idler, 0, 20000) as $i => $id) {
            $anahtar = 'xp_' . $i;
            $yerTutucu[] = ':' . $anahtar;
            $params[$anahtar] = $id;
        }

        $satirlar = $this->rawQuery(
            "SELECT DISTINCT COALESCE(xml_profile_id, 0) AS pid
             FROM products
             WHERE store_id = :store_id AND id IN (" . implode(',', $yerTutucu) . ")",
            $params
        );

        return array_map(static fn(array $r): int => (int) $r['pid'], $satirlar);
    }

    private function buildListWhere(array $filters): array
    {
        // Tenant izolasyonu: ürünler birincil tenant tablosudur (p.store_id ZORUNLU).
        $where = ['p.parent_id IS NULL', 'p.store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        // Default: arşivlenmişleri gösterme (show_archived=1 yoksa)
        if (!empty($filters['show_archived'])) {
            $where[] = "p.is_archived = 1";
        } else {
            $where[] = "p.is_archived = 0";
        }

        if (!empty($filters['search'])) {
            $where[] = "(p.name LIKE :search OR p.sku LIKE :search2 OR p.barcode LIKE :search3)";
            $params['search']  = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['category_id'])) {
            // 'yok' = kategorisi hiç atanmamış ürünler. XML'den alan eşlemesi
            // yapılmadan gelen ürünlerde category_id NULL kalıyor; bu ürünler
            // gönderimde "kategori eşleştirmesi yok" diye engelleniyor ama
            // listede süzülemedikleri için kullanıcı onları bulamıyordu.
            if ((string) $filters['category_id'] === 'yok') {
                $where[] = "p.category_id IS NULL";
            } else {
                $where[] = "p.category_id = :category_id";
                $params['category_id'] = $filters['category_id'];
            }
        }

        if (!empty($filters['brand_id'])) {
            $where[] = "p.brand_id = :brand_id";
            $params['brand_id'] = $filters['brand_id'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "p.is_active = :is_active";
            $params['is_active'] = (int) $filters['is_active'];
        }

        // Kaynak filtresi — "XML ile gelen ürünler" için (toplu gönderimde kullanılır)
        if (!empty($filters['source'])) {
            $where[] = "p.source = :source";
            $params['source'] = (string) $filters['source'];
        }

        // Hangi XML profilinden geldiği — her tedarikçiyi ayrı yönetebilmek için
        if (!empty($filters['xml_profile_id'])) {
            $where[] = "p.xml_profile_id = :xml_profile_id";
            $params['xml_profile_id'] = (int) $filters['xml_profile_id'];
        }

        // Belirli ID listesi (toplu gönderimde kullanıcı tek tek seçtiyse)
        if (!empty($filters['__ids']) && is_array($filters['__ids'])) {
            $idler = array_values(array_filter(array_map('intval', $filters['__ids']), static fn(int $v): bool => $v > 0));
            if ($idler !== []) {
                $yerTutucu = [];
                foreach (array_slice($idler, 0, 20000) as $i => $id) {
                    $anahtar = 'pid_' . $i;
                    $yerTutucu[] = ':' . $anahtar;
                    $params[$anahtar] = $id;
                }
                $where[] = 'p.id IN (' . implode(',', $yerTutucu) . ')';
            }
        }

        if (!empty($filters['stock_status'])) {
            $where[] = match ($filters['stock_status']) {
                'in_stock'  => "p.stock_qty > p.min_stock_alert",
                'low'       => "p.stock_qty > 0 AND p.stock_qty <= p.min_stock_alert",
                'out'       => "p.stock_qty <= 0",
                default     => "1=1",
            };
        }

        if (!empty($filters['marketplace_id'])) {
            // Faz 2 — Store Entitlement: yalnız mağazanın AKTİF satın aldığı pazaryeri eşlemesi sayılır.
            $where[] = "EXISTS (
                SELECT 1 FROM marketplace_products mp2
                JOIN store_marketplaces sm2 ON sm2.marketplace_id = mp2.marketplace_id
                WHERE mp2.product_id = p.id
                  AND mp2.store_id = :mp_store_id
                  AND mp2.marketplace_id = :mp_filter_id
                  AND sm2.store_id = :store_id_ent
                  AND sm2.status = 'active' AND (sm2.expires_at IS NULL OR sm2.expires_at > NOW())
            )";
            $params['mp_store_id']  = $this->tenantId();
            $params['mp_filter_id'] = (int) $filters['marketplace_id'];
            $params['store_id_ent'] = $this->tenantId();
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Filtreye uyan ürün ID'leri — "filtreye uyan tümünü seç" için.
     *
     * ID listesi tarayıcıya taşınmaz; toplu gönderim sunucuda bu listeyle kurulur.
     *
     * @param array<string, mixed> $filters
     * @return array<int, int>
     */
    /**
     * Filtreye uyan ürün sayısı (üst sınır UYGULANMADAN).
     *
     * getIdsForFilters() sert bir üst sınırla kırpar; toplu işlemin kullanıcıya
     * "kaç ürün etkilenecek" derken GERÇEK sayıyı söyleyebilmesi için ayrı sayım.
     *
     * @param array<string, mixed> $filters
     */
    public function countForFilters(array $filters): int
    {
        [$whereStr, $params] = $this->buildListWhere($filters);

        $row = $this->rawQueryOne(
            "SELECT COUNT(*) AS cnt FROM products p WHERE {$whereStr}",
            $params
        );

        return (int) ($row['cnt'] ?? 0);
    }

    public function getIdsForFilters(array $filters, int $cap = 5000): array
    {
        [$whereStr, $params] = $this->buildListWhere($filters);
        $cap = max(1, min(20000, $cap));

        $rows = $this->rawQuery(
            "SELECT p.id FROM products p WHERE {$whereStr} ORDER BY p.id ASC LIMIT {$cap}",
            $params
        );

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Toplu gönderim hazırlık raporu — TEK sorguda engel sayımı.
     *
     * Ürün başına validateForPublish() çağırmak 5.000 üründe ~25.000 sorgu demek;
     * bu metot aynı bilgiyi tek sorguda üretir.
     *
     * @param array<string, mixed> $filters
     * @return array{total: int, ready: int, no_barcode: int, no_image: int, no_price: int, no_category_mapping: int, no_brand_mapping: int}
     */
    public function getPublishReadiness(array $filters, int $marketplaceId): array
    {
        [$whereStr, $params] = $this->buildListWhere($filters);
        $params['mp_ready_id']  = $marketplaceId;
        $params['mp_ready_id2'] = $marketplaceId;
        $params['store_cat']    = $this->tenantId();
        $params['store_brand']  = $this->tenantId();

        $row = $this->rawQueryOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN p.barcode IS NULL OR p.barcode = '' THEN 1 ELSE 0 END) AS no_barcode,
                SUM(CASE WHEN (p.main_image IS NULL OR p.main_image = '')
                          AND (p.images_json IS NULL OR p.images_json = '' OR p.images_json = '[]') THEN 1 ELSE 0 END) AS no_image,
                SUM(CASE WHEN p.base_price <= 0 THEN 1 ELSE 0 END) AS no_price,
                SUM(CASE WHEN p.category_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM category_marketplace_mapping cm
                         WHERE cm.category_id = p.category_id
                           AND cm.marketplace_id = :mp_ready_id
                           AND cm.store_id = :store_cat
                    ) THEN 1 ELSE 0 END) AS no_category_mapping,
                SUM(CASE WHEN p.brand_id IS NULL OR NOT EXISTS (
                        SELECT 1 FROM brand_marketplace_mapping bm
                         WHERE bm.brand_id = p.brand_id
                           AND bm.marketplace_id = :mp_ready_id2
                           AND bm.store_id = :store_brand
                    ) THEN 1 ELSE 0 END) AS no_brand_mapping
             FROM products p
             WHERE {$whereStr}",
            $params
        );

        $sonuc = [
            'total'               => (int) ($row['total'] ?? 0),
            'no_barcode'          => (int) ($row['no_barcode'] ?? 0),
            'no_image'            => (int) ($row['no_image'] ?? 0),
            'no_price'            => (int) ($row['no_price'] ?? 0),
            'no_category_mapping' => (int) ($row['no_category_mapping'] ?? 0),
            'no_brand_mapping'    => (int) ($row['no_brand_mapping'] ?? 0),
        ];

        // "Hazır" = hiçbir engeli olmayan ürün sayısı (engeller çakışabildiği için
        // ayrı bir sorguyla hesaplanır).
        // DİKKAT: PDO::ATTR_EMULATE_PREPARES=false olduğundan sorguda GEÇMEYEN
        // parametreyi göndermek HY093 hatası verir → ayrı parametre dizisi kurulur.
        $hazirParams = $params;
        unset($hazirParams['mp_ready_id2'], $hazirParams['store_brand']);

        // "Hazır" sayısı yalnız fiyat + kategoriye bakıyordu; BARKOD yoksa ürün
        // gönderimde reddediliyor ama önizlemede hazır görünüyordu. 10.000 ürünlük
        // bir katalogda "6.398 hazır" deyip fiilen 21 ürünün geçmesinin sebebi buydu.
        // Barkod gönderimde zorunlu olduğu için artık hazırlık şartına dahil.
        $hazir = $this->rawQueryOne(
            "SELECT COUNT(*) AS cnt FROM products p
              WHERE {$whereStr}
                AND p.base_price > 0
                AND p.barcode IS NOT NULL AND p.barcode <> ''
                AND p.category_id IS NOT NULL
                AND EXISTS (SELECT 1 FROM category_marketplace_mapping cm
                             WHERE cm.category_id = p.category_id
                               AND cm.marketplace_id = :mp_ready_id
                               AND cm.store_id = :store_cat)",
            $hazirParams
        );
        $sonuc['ready'] = (int) ($hazir['cnt'] ?? 0);

        return $sonuc;
    }

    public function getForDataTables(
        array $filters,
        int $start,
        int $length,
        string $orderBy = 'p.updated_at',
        string $orderDir = 'DESC'
    ): array {
        [$whereStr, $params] = $this->buildListWhere($filters);

        // Total count (kullanıcı filtreleri hariç ama TENANT-scoped)
        $totalRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products p WHERE p.parent_id IS NULL AND p.store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        $totalAll = (int) ($totalRow['cnt'] ?? 0);

        // Filtered count
        $filteredRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products p WHERE {$whereStr}",
            $params
        );
        $filteredTotal = (int) ($filteredRow['cnt'] ?? 0);

        // Data with ordering
        $allowedCols = ['p.name', 'p.sku', 'p.base_price', 'p.stock_qty', 'p.updated_at', 'p.is_active', 'c.name', 'b.name'];
        if (!in_array($orderBy, $allowedCols)) {
            $orderBy = 'p.updated_at';
        }
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        // JOIN'ler de tenant-scoped; varyant sayımı aynı mağaza ile sınırlı.
        $dataParams = $params;
        $dataParams['store_id_c'] = $this->tenantId();
        $dataParams['store_id_b'] = $this->tenantId();
        $dataParams['store_id_v'] = $this->tenantId();

        $data = $this->rawQuery(
            "SELECT p.*,
                    c.name as category_name,
                    b.name as brand_name,
                    (SELECT COUNT(*) FROM products v WHERE v.parent_id = p.id AND v.store_id = :store_id_v) as variant_count
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id AND c.store_id = :store_id_c
             LEFT JOIN brands b ON p.brand_id = b.id AND b.store_id = :store_id_b
             WHERE {$whereStr}
             ORDER BY {$orderBy} {$orderDir}
             LIMIT {$length} OFFSET {$start}",
            $dataParams
        );

        return [
            'data'     => $data,
            'total'    => $totalAll,
            'filtered' => $filteredTotal,
        ];
    }

    /**
     * Pazaryeri bazlı ürün sayıları (tab badge için)
     *
     * @return array<int, int>
     */
    public function getMarketplaceProductCounts(): array
    {
        // Tenant izolasyonu (mp.store_id + p.store_id) + Faz 2 Store Entitlement.
        $rows = $this->rawQuery(
            "SELECT mp.marketplace_id, COUNT(DISTINCT mp.product_id) as cnt
             FROM marketplace_products mp
             JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id_p
             WHERE p.parent_id IS NULL
               AND mp.store_id = :store_id
               AND mp.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
             GROUP BY mp.marketplace_id",
            [
                'store_id'     => $this->tenantId(),
                'store_id_p'   => $this->tenantId(),
                'store_id_ent' => $this->tenantId(),
            ]
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[(int) $r['marketplace_id']] = (int) $r['cnt'];
        }
        return $counts;
    }

    /**
     * Inline stok veya fiyat güncelleme
     */
    public function inlineUpdate(int $productId, array $data): bool
    {
        $allowed = ['base_price', 'list_price', 'stock_qty'];
        $updateData = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }
        if (empty($updateData)) return false;

        $updateData['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($productId, $updateData);
    }
}
