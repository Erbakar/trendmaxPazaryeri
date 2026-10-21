<?php
/**
 * OzyConnect Panel — MarketplaceProduct Model
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

final class MarketplaceProduct extends BaseModel
{
    protected string $table = 'marketplace_products';

    /**
     * marketplace_product_id + marketplace_id ile bul
     */
    public function findByMarketplaceProductId(int $marketplaceId, string $mpProductId): array|false
    {
        return $this->findBy([
            'marketplace_id'         => $marketplaceId,
            'marketplace_product_id' => $mpProductId,
        ]);
    }

    /**
     * Ürün + pazaryeri bazlı bul
     *
     * @return array<string, mixed>|false
     */
    public function findByProductAndMarketplace(int $productId, int $marketplaceId): array|false
    {
        return $this->findBy([
            'product_id'     => $productId,
            'marketplace_id' => $marketplaceId,
        ]);
    }

    /**
     * Pazaryeri SKU ile bul
     *
     * @return array<string, mixed>|false
     */
    public function findByMarketplaceSku(int $marketplaceId, string $sku): array|false
    {
        return $this->findBy([
            'marketplace_id'  => $marketplaceId,
            'marketplace_sku' => $sku,
        ]);
    }

    /**
     * Belirli pazaryerideki aktif ürünleri getir (stok sync için)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForMarketplace(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT mp.*, p.stock_qty, p.base_price, p.list_price, p.cost_price,
                    p.barcode, p.name as product_name, p.sku as internal_sku
             FROM marketplace_products mp
             JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id2
             WHERE mp.marketplace_id = :mid AND mp.is_active = 1 AND p.is_active = 1
               AND mp.is_listed = 1
               AND mp.store_id = :store_id
             ORDER BY p.id ASC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Stoğu değişen ürünleri getir (son sync'ten farklı)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStockChanged(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT mp.*, p.stock_qty, p.base_price, p.barcode,
                    p.name as product_name, p.sku as internal_sku,
                    (p.stock_qty - mp.stock_offset) as calculated_stock
             FROM marketplace_products mp
             JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id2
             WHERE mp.marketplace_id = :mid
               AND mp.is_active = 1 AND p.is_active = 1
               AND mp.is_listed = 1
               AND (mp.last_stock_sent IS NULL OR mp.last_stock_sent != (p.stock_qty - mp.stock_offset))
               AND mp.store_id = :store_id
             ORDER BY p.id ASC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Pazaryeri onayı bekleyen kayıtlar ("Batch kontrol bekliyor: <batchId>").
     *
     * Gönderim anında pazaryeri sonucu birkaç saniyede gelmediği için ürünler bu
     * durumda asılı kalıyordu ve sonucu soran hiçbir iş yoktu; cron/sync_publish_batches.php
     * bu listeyi tarayıp gerçek sonucu (onaylandı / reddedildi + sebep) yazar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingBatches(int $marketplaceId = 0, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $ek    = $marketplaceId > 0 ? ' AND mp.marketplace_id = :mid' : '';
        $par   = ['store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()];
        if ($marketplaceId > 0) {
            $par['mid'] = $marketplaceId;
        }

        return $this->rawQuery(
            "SELECT mp.id, mp.product_id, mp.marketplace_id, mp.sync_error,
                    p.barcode, p.sku, p.name AS product_name
             FROM marketplace_products mp
             JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id2
             WHERE mp.store_id = :store_id
               AND mp.sync_error LIKE 'Batch kontrol bekliyor: %'
               {$ek}
             ORDER BY mp.marketplace_id ASC, mp.id ASC
             LIMIT {$limit}",
            $par
        );
    }

    /**
     * Son gönderilen stok/fiyat güncelle
     */
    public function updateLastSent(int $id, ?int $stock = null, ?float $price = null): bool
    {
        $data = ['last_synced_at' => date('Y-m-d H:i:s'), 'sync_error' => null];

        if ($stock !== null) {
            $data['last_stock_sent'] = $stock;
        }
        if ($price !== null) {
            $data['last_price_sent'] = $price;
        }

        return $this->update($id, $data);
    }

    /**
     * Sync hatası kaydet
     */
    public function setSyncError(int $id, string $error): bool
    {
        return $this->update($id, [
            'sync_error'    => $error,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Bir ürünün tüm pazaryeri eşleştirmelerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByProduct(int $productId): array
    {
        return $this->rawQuery(
            "SELECT mp.*, m.slug, m.display_name, m.is_active as marketplace_active
             FROM marketplace_products mp
             JOIN marketplaces m ON mp.marketplace_id = m.id
             WHERE mp.product_id = :pid
               AND mp.store_id = :store_id
             ORDER BY m.display_name ASC",
            ['pid' => $productId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Marketplace kategori/marka ID'leri ile birlikte ürün eşleştirmesini getir
     *
     * @return array<string, mixed>|false
     */
    public function getWithMappingDetails(int $productId, int $marketplaceId): array|false
    {
        return $this->rawQueryOne(
            "SELECT mp.*,
                    p.name as product_name, p.sku as internal_sku, p.barcode,
                    p.base_price, p.list_price, p.stock_qty, p.main_image,
                    m.slug as marketplace_slug, m.display_name as marketplace_name
             FROM marketplace_products mp
             JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id2
             JOIN marketplaces m ON mp.marketplace_id = m.id
             WHERE mp.product_id = :pid AND mp.marketplace_id = :mid
               AND mp.store_id = :store_id",
            ['pid' => $productId, 'mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Upsert: product_id + marketplace_id composite key ile kaydet veya güncelle
     *
     * @param array<string, mixed> $data
     * @return int Oluşturulan veya güncellenen kaydın ID'si
     */
    public function upsertByProductAndMarketplace(int $productId, int $marketplaceId, array $data): int
    {
        $existing = $this->findByProductAndMarketplace($productId, $marketplaceId);

        if ($existing !== false) {
            $this->update((int) $existing['id'], $data);
            return (int) $existing['id'];
        }

        $data['product_id'] = $productId;
        $data['marketplace_id'] = $marketplaceId;
        return $this->create($data);
    }

    /**
     * Bir pazaryerindeki ilan satırları (aktif + pasif) — anahtar kümesi sayfalaması (mp.id artan).
     * mp.* alanları (product_name, main_image, marketplace_barcode …) olduğu gibi gelir; bağlı sistem
     * ürününün alanları ayrı takma adlarla eklenir (eşleşmemiş satırda NULL) — mp değerlerini ezmez.
     * Tamamını dolaşmak için chunkForMarketplace() kullanın.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllForMarketplace(int $marketplaceId, int $limit = 500, int $afterId = 0): array
    {
        $limit = max(1, min(2000, $limit));

        return $this->rawQuery(
            "SELECT mp.*, p.name AS system_name, p.sku AS internal_sku, p.barcode AS system_barcode,
                    p.base_price AS system_price, p.stock_qty AS system_stock,
                    p.main_image AS system_image, p.images_json AS system_images_json
             FROM marketplace_products mp
             LEFT JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id2
             WHERE mp.marketplace_id = :mid
               AND mp.store_id = :store_id
               AND mp.id > :after_id
             ORDER BY mp.id ASC
             LIMIT {$limit}",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId(), 'after_id' => $afterId]
        );
    }

    /**
     * Bir pazaryerindeki TÜM ilan satırlarını parça parça dolaşır (bellek sabit, satır sınırı yok).
     *
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function chunkForMarketplace(int $marketplaceId, int $chunkSize = 500): \Generator
    {
        $chunkSize = max(1, min(2000, $chunkSize));
        $afterId = 0;

        do {
            $rows = $this->getAllForMarketplace($marketplaceId, $chunkSize, $afterId);
            if ($rows === []) {
                return;
            }
            yield $rows;
            $afterId = (int) $rows[array_key_last($rows)]['id'];
        } while (count($rows) === $chunkSize);
    }

    /**
     * Belirli ürün ID'leri için marketplace detaylarını getir
     *
     * @param array<int> $productIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getMarketplaceDetailsForProducts(array $productIds): array
    {
        if (empty($productIds)) return [];

        $placeholders = implode(',', array_map('intval', $productIds));
        $rows = $this->rawQuery(
            "SELECT mp.product_id, mp.marketplace_id, mp.marketplace_product_id,
                    mp.marketplace_sku, mp.marketplace_barcode, mp.is_active, mp.is_listed,
                    mp.stock_offset,
                    mp.last_stock_sent, mp.last_price_sent, mp.last_synced_at, mp.sync_error,
                    m.slug, m.display_name
             FROM marketplace_products mp
             JOIN marketplaces m ON mp.marketplace_id = m.id
             WHERE mp.product_id IN ({$placeholders})
               AND mp.store_id = :store_id
             ORDER BY m.display_name ASC",
            ['store_id' => $this->tenantId()]
        );

        $result = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($result[$pid])) $result[$pid] = [];
            $result[$pid][] = $r;
        }
        return $result;
    }

    /**
     * Belirli product_id + marketplace_id kombinasyonunu güncelle
     *
     * @param int $productId
     * @param int $marketplaceId
     * @param array<string, mixed> $data
     * @return bool
     */
    public function updateByProductAndMarketplace(int $productId, int $marketplaceId, array $data): bool
    {
        $sets = [];
        $params = ['pid' => $productId, 'mid' => $marketplaceId, 'store_id' => $this->tenantId()];

        foreach ($data as $col => $val) {
            $key = 'v_' . $col;
            $sets[] = "{$col} = :{$key}";
            $params[$key] = $val;
        }

        if (empty($sets)) return false;

        $setStr = implode(', ', $sets);
        $this->rawQuery(
            "UPDATE marketplace_products SET {$setStr}, updated_at = NOW()
             WHERE product_id = :pid AND marketplace_id = :mid
               AND store_id = :store_id",
            $params
        );

        return true;
    }

    /**
     * Eşleşmiş ürün sayısı (en az 1 pazaryerinde)
     */
    public function countMatchedProducts(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(DISTINCT product_id) as cnt FROM marketplace_products WHERE store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Sync sorunu olan ürün sayısı
     */
    public function countSyncIssues(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(DISTINCT product_id) as cnt FROM marketplace_products WHERE sync_error IS NOT NULL AND sync_error != '' AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Pazaryeri bazında yayında olan ürün sayıları
     * @return array<string, array{total: int, live: int, name: string}>
     */
    public function getLiveCountsByMarketplace(): array
    {
        $rows = $this->rawQuery(
            "SELECT mp.marketplace_id, m.slug, m.display_name,
                    COUNT(*) as total,
                    SUM(CASE WHEN mp.is_active = 1 AND mp.is_listed = 1 THEN 1 ELSE 0 END) as live
             FROM marketplace_products mp
             JOIN marketplaces m ON mp.marketplace_id = m.id
             WHERE mp.store_id = :store_id
             GROUP BY mp.marketplace_id, m.slug, m.display_name",
            ['store_id' => $this->tenantId()]
        );
        $result = [];
        foreach ($rows as $r) {
            $result[$r['slug']] = [
                'total' => (int)$r['total'],
                'live'  => (int)$r['live'],
                'name'  => $r['display_name'],
            ];
        }
        return $result;
    }

    /**
     * Belirli pazaryerideki ürün sayısı
     */
    public function countByMarketplace(int $marketplaceId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM marketplace_products WHERE marketplace_id = :mid AND store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Pazaryeri ürünleri — sayfalı listeleme (mp tab için)
     * @return array{items: array, total: int}
     */
    public function getPagedByMarketplace(int $marketplaceId, int $page = 1, int $perPage = 50, string $search = ''): array
    {
        $where = ['mp.marketplace_id = :mid', 'mp.store_id = :store_id'];
        $params = ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id_p' => $this->tenantId()];

        // Faz 2 — Store Entitlement: pazaryeri satın alınmamış/pasifse ürün eşlemesi gösterilmez.
        $where[] = "mp.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent'] = $this->tenantId();

        if ($search !== '') {
            $where[] = "(mp.marketplace_sku LIKE :q OR COALESCE(p.name, mp.product_name) LIKE :q2 OR p.barcode LIKE :q3 OR p.sku LIKE :q4 OR mp.marketplace_barcode LIKE :q5)";
            $params['q']  = '%' . $search . '%';
            $params['q2'] = '%' . $search . '%';
            $params['q3'] = '%' . $search . '%';
            $params['q4'] = '%' . $search . '%';
            $params['q5'] = '%' . $search . '%';
        }

        $whereStr = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM marketplace_products mp
             LEFT JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id_p
             WHERE {$whereStr}",
            $params
        );

        $items = $this->rawQuery(
            "SELECT mp.*, COALESCE(p.name, mp.product_name) as product_name, p.sku as internal_sku,
                    p.barcode, p.base_price as system_price, p.stock_qty as system_stock,
                    COALESCE(p.main_image, mp.main_image) as main_image, p.is_active as product_active,
                    m.slug as marketplace_slug, m.display_name as marketplace_name
             FROM marketplace_products mp
             LEFT JOIN products p ON mp.product_id = p.id AND p.store_id = :store_id_p
             JOIN marketplaces m ON mp.marketplace_id = m.id
             WHERE {$whereStr}
             ORDER BY mp.updated_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items' => $items,
            'total' => (int)($countRow['cnt'] ?? 0),
        ];
    }
}

