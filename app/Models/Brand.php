<?php
/**
 * OzyConnect Panel — Brand Model
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

use App\Helpers\StringHelper;

final class Brand extends BaseModel
{
    protected string $table = 'brands';

    /**
     * İsimle bul (case-insensitive)
     *
     * @return array<string, mixed>|false
     */
    public function findByName(string $name): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM brands WHERE LOWER(name) = LOWER(:name) AND store_id = :store_id LIMIT 1",
            ['name' => $name, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Marka oluştur veya mevcut olanı getir
     */
    public function findOrCreate(string $name): int
    {
        $existing = $this->findByName($name);
        if ($existing !== false) {
            return (int) $existing['id'];
        }

        return $this->create([
            'name' => $name,
            'slug' => StringHelper::slugify($name),
        ]);
    }

    /**
     * API marka listesi — mağazaya kapalı, sayfalı.
     *
     * ApiBrandController protected rawQuery()'yi çağırıyordu (ölümcül hata) ve
     * sorgusunda store_id yoktu.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function apiList(string $search, ?int $isActive, int $page, int $perPage): array
    {
        $where  = ['store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        if ($search !== '') {
            $where[] = 'name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        if ($isActive !== null) {
            $where[] = 'is_active = :active';
            $params['active'] = $isActive;
        }

        $whereSql = implode(' AND ', $where);
        $page     = max(1, $page);
        $perPage  = max(1, min(200, $perPage));
        $offset   = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne("SELECT COUNT(*) AS cnt FROM brands WHERE {$whereSql}", $params);

        $rows = $this->rawQuery(
            "SELECT * FROM brands WHERE {$whereSql} ORDER BY name ASC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => (int) ($countRow['cnt'] ?? 0)];
    }

    /**
     * Aktif markaları getir (dropdown için)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        return $this->all(['is_active' => 1], 'name ASC');
    }

    /**
     * Arama (autocomplete için)
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->rawQuery(
            "SELECT id, name FROM brands
             WHERE name LIKE :q AND is_active = 1 AND store_id = :store_id
             ORDER BY name ASC
             LIMIT :limit",
            ['q' => '%' . $query . '%', 'limit' => $limit, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Markadaki ürün sayısını getir
     */
    public function getProductCount(int $brandId): int
    {
        $result = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products WHERE brand_id = :bid AND store_id = :store_id",
            ['bid' => $brandId, 'store_id' => $this->tenantId()]
        );
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * DataTables server-side: markalar + ürün sayısı + mapping sayısı
     */
    public function getBrandsWithMappings(string $where, array $params, string $orderBy, int $limit, int $offset): array
    {
        // Tenant izolasyonu: birincil tablo b ve her alt-sorgu store_id ile filtrelenir.
        // Dinamik $where ('WHERE ...' veya '') birincil store_id koşulundan sonra AND ile birleşir.
        $tenantWhere = $where !== '' ? preg_replace('/^\s*WHERE\s+/i', 'AND ', $where) : '';
        $params['store_id']  = $this->tenantId();
        $params['store_id2'] = $this->tenantId();
        $params['store_id3'] = $this->tenantId();

        return $this->rawQuery(
            "SELECT b.*,
                    IFNULL(pc.product_count, 0) as product_count,
                    IFNULL(mc.mapping_count, 0) as mapping_count
             FROM brands b
             LEFT JOIN (SELECT brand_id, COUNT(*) as product_count FROM products WHERE store_id = :store_id2 GROUP BY brand_id) pc ON pc.brand_id = b.id
             LEFT JOIN (SELECT brand_id, COUNT(*) as mapping_count FROM brand_marketplace_mapping WHERE store_id = :store_id3 GROUP BY brand_id) mc ON mc.brand_id = b.id
             WHERE b.store_id = :store_id {$tenantWhere}
             ORDER BY {$orderBy}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /**
     * Filtrelenmiş sayı
     */
    public function countBrandsFiltered(string $where, array $params): int
    {
        // Tenant izolasyonu: birincil tablo b store_id ile filtrelenir; dinamik $where AND ile birleşir.
        $tenantWhere = $where !== '' ? preg_replace('/^\s*WHERE\s+/i', 'AND ', $where) : '';
        $params['store_id'] = $this->tenantId();

        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM brands b WHERE b.store_id = :store_id {$tenantWhere}",
            $params
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Markaların toplam ürün sayısı
     */
    public function getTotalProductCount(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products WHERE brand_id IS NOT NULL AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }
    /**
     * Pazaryeri marka ID'si ile sistem markasını bul (brand_marketplace_mapping)
     * @return array<string, mixed>|false
     */
    public function findByMarketplaceBrandId(int $marketplaceId, string $mpBrandId): array|false
    {
        if ($mpBrandId === '') return false;
        return $this->rawQueryOne(
            "SELECT b.* FROM brands b
             JOIN brand_marketplace_mapping bmm ON bmm.brand_id = b.id
             WHERE bmm.marketplace_id = :mid AND bmm.marketplace_brand_id = :mbid
               AND b.store_id = :store_id AND bmm.store_id = :store_id2
             LIMIT 1",
            ['mid' => $marketplaceId, 'mbid' => $mpBrandId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Pazaryeri marka adı ile sistem markasını bul veya oluştur
     */
    public function findOrCreateByMarketplaceBrand(int $marketplaceId, string $mpBrandId, string $brandName): int
    {
        // Önce mapping'den bak
        $mapped = $this->findByMarketplaceBrandId($marketplaceId, $mpBrandId);
        if ($mapped) return (int)$mapped['id'];

        // İsimle ara
        if ($brandName !== '') {
            $byName = $this->findByName($brandName);
            if ($byName) {
                $brandId = (int)$byName['id'];
            } else {
                // Yeni marka oluştur
                $brandId = $this->findOrCreate($brandName);
            }

            // Mapping'i kaydet
            if ($mpBrandId !== '') {
                $this->rawQuery(
                    "INSERT IGNORE INTO brand_marketplace_mapping (brand_id, marketplace_id, marketplace_brand_id, marketplace_brand_name, store_id)
                     VALUES (:bid, :mid, :mbid, :mbn, :store_id)",
                    ['bid' => $brandId, 'mid' => $marketplaceId, 'mbid' => $mpBrandId, 'mbn' => $brandName, 'store_id' => $this->tenantId()]
                );
            }

            return $brandId;
        }

        return 0;
    }
}

