<?php
/**
 * OzyConnect Panel — ReturnRequest Model
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

final class ReturnRequest extends BaseModel
{
    protected string $table = 'returns';

    /**
     * Pazaryeri iade ID'si ile bul
     *
     * @return array<string, mixed>|false
     */
    public function findByMarketplaceReturnId(int $marketplaceId, string $returnId): array|false
    {
        return $this->findBy([
            'marketplace_id'        => $marketplaceId,
            'marketplace_return_id' => $returnId,
        ]);
    }

    /**
     * Belirli bir tarihten sonra oluşan yeni iade sayısı
     */
    public function getNewCountSince(string $since): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM `{$this->table}` WHERE created_at > :since AND store_id = :store_id",
            ['since' => $since, 'store_id' => $this->tenantId()]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Filtreli iade listesi
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public function getFiltered(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];

        // Tenant izolasyonu: birincil tenant tablosu (returns r) store_id ile filtrelenir.
        $where[] = "r.store_id = :store_id";
        $params['store_id'] = $this->tenantId();

        // Faz 2 — Store Entitlement: yalnız mağazanın aktif satın aldığı pazaryeri iadeleri.
        $where[] = "r.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = "r.marketplace_id = :mid";
            $params['mid'] = $filters['marketplace_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "r.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "r.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "r.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM `returns` r WHERE {$whereStr}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $data = $this->rawQuery(
            "SELECT r.*, o.marketplace_order_id, o.customer_name,
                    m.display_name as marketplace_name, m.slug as marketplace_slug
             FROM `returns` r
             JOIN orders o ON r.order_id = o.id AND o.store_id = r.store_id
             JOIN marketplaces m ON r.marketplace_id = m.id
             WHERE {$whereStr}
             ORDER BY COALESCE(r.request_date, r.created_at) DESC
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
     * Bekleyen iade sayısı
     */
    public function getPendingCount(): int
    {
        return $this->count(['status' => 'requested']);
    }

    /**
     * API verisinden upsert (varsa güncelle, yoksa oluştur)
     *
     * @param array<string, mixed> $data Normalize edilmiş iade verisi
     * @return array{action: string, id: int} 'inserted' veya 'updated'
     */
    public function upsertFromApi(int $marketplaceId, string $marketplaceReturnId, array $data): array
    {
        $existing = $this->findByMarketplaceReturnId($marketplaceId, $marketplaceReturnId);

        $row = [
            'marketplace_id'        => $marketplaceId,
            'marketplace_return_id' => $marketplaceReturnId,
            'marketplace_order_id'  => $data['marketplace_order_id'] ?? null,
            'order_id'              => $data['order_id'] ?? null,
            'customer_name'         => $data['customer_name'] ?? null,
            'customer_email'        => $data['customer_email'] ?? null,
            'customer_phone'        => $data['customer_phone'] ?? null,
            'product_name'          => $data['product_name'] ?? null,
            'product_sku'           => $data['product_sku'] ?? null,
            'quantity'              => $data['quantity'] ?? 1,
            'status'                => $data['status'] ?? 'requested',
            'status_raw'            => $data['status_raw'] ?? null,
            'reason'                => $data['reason'] ?? null,
            'reason_code'           => $data['reason_code'] ?? null,
            'items_json'            => is_string($data['items_json'] ?? null)
                ? $data['items_json']
                : json_encode($data['items'] ?? [], JSON_UNESCAPED_UNICODE),
            'refund_amount'         => $data['refund_amount'] ?? 0,
            'cargo_company'         => $data['cargo_company'] ?? null,
            'cargo_tracking_no'     => $data['cargo_tracking_no'] ?? null,
            'image_url'             => $data['image_url'] ?? null,
            'action_required'       => (int) ($data['action_required'] ?? 0),
            'request_date'          => $data['request_date'] ?? null,
            'approved_date'         => $data['approved_date'] ?? null,
            'denied_date'           => $data['denied_date'] ?? null,
            'cancelled_date'        => $data['cancelled_date'] ?? null,
            'raw_data_json'         => is_string($data['raw_data_json'] ?? null)
                ? $data['raw_data_json']
                : json_encode($data['raw_data'] ?? null, JSON_UNESCAPED_UNICODE),
            'synced_at'             => date('Y-m-d H:i:s'),
        ];

        if ($existing !== false) {
            $this->update((int) $existing['id'], $row);
            return ['action' => 'updated', 'id' => (int) $existing['id']];
        }

        $id = $this->create($row);
        return ['action' => 'inserted', 'id' => $id];
    }

    /**
     * DB'den filtreli iade listesi (JOIN'siz — tüm veri returns tablosunda)
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public function getFilteredFromDb(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];

        // Tenant izolasyonu: birincil tenant tablosu (returns r) store_id ile filtrelenir.
        $where[] = "r.store_id = :store_id";
        $params['store_id'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = "r.marketplace_id = :mid";
            $params['mid'] = $filters['marketplace_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "r.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "r.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "r.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = "(r.marketplace_return_id LIKE :search
                OR r.marketplace_order_id LIKE :search2
                OR r.customer_name LIKE :search3
                OR r.product_name LIKE :search4)";
            $params['search'] = $search;
            $params['search2'] = $search;
            $params['search3'] = $search;
            $params['search4'] = $search;
        }

        $whereStr = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM `returns` r WHERE {$whereStr}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $data = $this->rawQuery(
            "SELECT r.id, r.order_id, r.marketplace_return_id, r.marketplace_order_id,
                    r.customer_name, r.customer_email, r.customer_phone,
                    r.product_name, r.product_sku, r.quantity,
                    r.marketplace_id, r.status, r.status_raw, r.reason, r.reason_code,
                    r.items_json, r.refund_amount, r.cargo_company, r.cargo_tracking_no,
                    r.image_url, r.action_required, r.request_date, r.approved_date,
                    r.denied_date, r.cancelled_date, r.cancel_invoice_id,
                    r.marketplace_decision, r.resolved_at,
                    r.created_at, r.updated_at, r.synced_at,
                    m.display_name as marketplace_name, m.slug as marketplace_slug
             FROM `returns` r
             JOIN marketplaces m ON r.marketplace_id = m.id
             WHERE {$whereStr}
             ORDER BY COALESCE(r.request_date, r.created_at) DESC
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
     * Pazaryeri bazında iade sayıları
     *
     * @return array<string|int, int> marketplace_id => count
     */
    public function getCountsByMarketplace(): array
    {
        // Tenant izolasyonu (r.store_id) + Faz 2 Store Entitlement.
        $rows = $this->rawQuery(
            "SELECT r.marketplace_id, COUNT(*) as cnt
             FROM `returns` r
             WHERE r.store_id = :store_id
               AND r.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
             GROUP BY r.marketplace_id",
            ['store_id' => $this->tenantId(), 'store_id_ent' => $this->tenantId()]
        );

        $counts = ['all' => 0];
        foreach ($rows as $row) {
            $mid = (string) $row['marketplace_id'];
            $cnt = (int) $row['cnt'];
            $counts[$mid] = $cnt;
            $counts['all'] += $cnt;
        }

        return $counts;
    }

    /**
     * Belirli bir pazaryeri için status bazlı iade sayıları
     *
     * @return array<string, int> status => count  (+  'all' => toplam)
     */
    public function getStatusCountsByMarketplace(int $marketplaceId): array
    {
        // Tenant izolasyonu (r.store_id) + Faz 2 Store Entitlement.
        $rows = $this->rawQuery(
            "SELECT r.status, COUNT(*) as cnt
             FROM `returns` r
             WHERE r.store_id = :store_id
               AND r.marketplace_id = :mid
               AND r.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
             GROUP BY r.status",
            ['store_id' => $this->tenantId(), 'mid' => $marketplaceId, 'store_id_ent' => $this->tenantId()]
        );

        $counts = ['all' => 0];
        foreach ($rows as $row) {
            $s = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            $counts[$s] = $cnt;
            $counts['all'] += $cnt;
        }

        return $counts;
    }

    /**
     * Son sync zamanı
     */
    public function getLastSyncTime(int $marketplaceId): ?string
    {
        // Tenant izolasyonu: başka mağazanın sync zamanı sızmamalı.
        $row = $this->rawQueryOne(
            "SELECT MAX(synced_at) as last_sync FROM `returns` WHERE store_id = :store_id AND marketplace_id = :mid",
            ['store_id' => $this->tenantId(), 'mid' => $marketplaceId]
        );
        return $row['last_sync'] ?? null;
    }
}
