<?php
/**
 * OzyConnect Panel — FinancialTransaction Model
 */
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

class FinancialTransaction extends BaseModel
{
    protected string $table = 'financial_transactions';

    /**
     * Get transactions with filters
     */
    public function getFiltered(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['ft.store_id = :store_id'];
        $params = [':store_id' => $this->tenantId()];

        // Faz 2 — Store Entitlement: yalnız mağazanın aktif satın aldığı pazaryeri finans kayıtları.
        $where[] = "ft.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params[':store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = 'ft.marketplace_id = :mp';
            $params[':mp'] = $filters['marketplace_id'];
        }
        if (!empty($filters['transaction_type'])) {
            $where[] = 'ft.transaction_type = :type';
            $params[':type'] = $filters['transaction_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'ft.transaction_date >= :from';
            $params[':from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'ft.transaction_date <= :to';
            $params[':to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT ft.*, m.display_name AS marketplace_name
                FROM {$this->table} ft
                LEFT JOIN marketplaces m ON m.id = ft.marketplace_id
                {$whereClause}
                ORDER BY ft.transaction_date DESC
                LIMIT {$limit} OFFSET {$offset}";

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count with filters
     */
    public function countFiltered(array $filters = []): int
    {
        $where = ['store_id = :store_id'];
        $params = [':store_id' => $this->tenantId()];

        // Faz 2 — Store Entitlement: yalnız mağazanın aktif satın aldığı pazaryeri finans kayıtları.
        $where[] = "marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params[':store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = 'marketplace_id = :mp';
            $params[':mp'] = $filters['marketplace_id'];
        }
        if (!empty($filters['transaction_type'])) {
            $where[] = 'transaction_type = :type';
            $params[':type'] = $filters['transaction_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'transaction_date >= :from';
            $params[':from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'transaction_date <= :to';
            $params[':to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) FROM {$this->table} {$whereClause}";
        return (int) $this->db->query($sql, $params)->fetchColumn();
    }

    /**
     * Summary stats
     */
    public function getSummary(array $filters = []): array
    {
        $where = ['store_id = :store_id'];
        $params = [':store_id' => $this->tenantId()];

        // Faz 2 — Store Entitlement: yalnız mağazanın aktif satın aldığı pazaryeri finans kayıtları.
        $where[] = "marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params[':store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = 'marketplace_id = :mp';
            $params[':mp'] = $filters['marketplace_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'transaction_date >= :from';
            $params[':from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'transaction_date <= :to';
            $params[':to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN transaction_type = 'sale' THEN gross_amount ELSE 0 END), 0) AS total_sales,
                    COALESCE(SUM(CASE WHEN transaction_type = 'refund' THEN ABS(gross_amount) ELSE 0 END), 0) AS total_refunds,
                    COALESCE(SUM(CASE WHEN transaction_type = 'commission' THEN ABS(gross_amount) ELSE 0 END), 0) AS total_commission,
                    COALESCE(SUM(CASE WHEN transaction_type = 'stoppage' THEN ABS(gross_amount) ELSE 0 END), 0) AS total_stoppage,
                    COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN net_amount ELSE 0 END), 0) AS total_payments,
                    COALESCE(SUM(net_amount), 0) AS total_net,
                    COALESCE(SUM(gross_amount), 0) AS total_gross,
                    COUNT(*) AS total_count
                FROM {$this->table} {$whereClause}";

        return $this->db->query($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Marketplace breakdown
     */
    public function getMarketplaceBreakdown(array $filters = []): array
    {
        $where = ['ft.store_id = :store_id'];
        $params = [':store_id' => $this->tenantId()];

        // Faz 2 — Store Entitlement: yalnız mağazanın aktif satın aldığı pazaryeri finans kayıtları.
        $where[] = "ft.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params[':store_id_ent'] = $this->tenantId();

        if (!empty($filters['date_from'])) {
            $where[] = 'ft.transaction_date >= :from';
            $params[':from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'ft.transaction_date <= :to';
            $params[':to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT m.display_name AS marketplace_name, ft.marketplace_id,
                    SUM(CASE WHEN ft.transaction_type='sale' THEN ft.gross_amount ELSE 0 END) AS sales,
                    SUM(CASE WHEN ft.transaction_type='refund' THEN ABS(ft.gross_amount) ELSE 0 END) AS refunds,
                    SUM(CASE WHEN ft.transaction_type='commission' THEN ABS(ft.gross_amount) ELSE 0 END) AS commission,
                    SUM(CASE WHEN ft.transaction_type='stoppage' THEN ABS(ft.gross_amount) ELSE 0 END) AS stoppage,
                    SUM(ft.net_amount) AS net,
                    SUM(ft.gross_amount) AS gross,
                    COUNT(*) AS tx_count
                FROM {$this->table} ft
                LEFT JOIN marketplaces m ON m.id = ft.marketplace_id
                {$whereClause}
                GROUP BY ft.marketplace_id, m.display_name
                ORDER BY net DESC";

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Check if external_id already exists
     */
    public function existsByExternalId(int $marketplaceId, string $externalId): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE store_id = :store_id AND marketplace_id = :mp AND external_id = :eid LIMIT 1";
        return (bool) $this->db->query($sql, [':store_id' => $this->tenantId(), ':mp' => $marketplaceId, ':eid' => $externalId])->fetchColumn();
    }
}
