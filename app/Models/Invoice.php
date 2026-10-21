<?php
/**
 * OzyConnect Panel — Invoice Model
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

final class Invoice extends BaseModel
{
    protected string $table = 'invoices';

    /**
     * Sipariş ID'si ile fatura getir
     *
     * @return array<string, mixed>|false
     */
    public function findByOrderId(int $orderId): array|false
    {
        return $this->findBy(['order_id' => $orderId]);
    }

    /**
     * UUID ile fatura getir — TENANT KAPSAMI DIŞINDA.
     *
     * Yalnızca herkese açık fatura belgesi servisi (pazaryerlerinin faturayı
     * indirebilmesi için) kullanır. UUID GİB tarafından üretilen tahmin
     * edilemez bir GUID'dir ve mağaza bağlamı sonuçtaki store_id'den kurulur.
     *
     * @return array<string, mixed>|false
     */
    public function findByUuidPublic(string $uuid): array|false
    {
        $uuid = trim($uuid);
        if ($uuid === '' || !preg_match('/^[A-Za-z0-9\-]{8,64}$/', $uuid)) {
            return false;
        }

        return $this->rawQueryOne(
            "SELECT * FROM invoices WHERE invoice_uuid = :uuid LIMIT 1",
            ['uuid' => $uuid]
        );
    }

    /**
     * Sonraki fatura sıra numarasını getir
     */
    public function getNextSequenceNo(string $series): int
    {
        $row = $this->rawQueryOne(
            "SELECT MAX(sequence_no) as max_seq FROM invoices WHERE series = :series AND store_id = :store_id FOR UPDATE",
            ['series' => $series, 'store_id' => $this->tenantId()]
        );

        return ((int) ($row['max_seq'] ?? 0)) + 1;
    }

    /**
     * Filtreli fatura listesi
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public function getFiltered(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];

        // Tenant izolasyonu: invoices (i) ve orders (o) tenant tablolari
        $where[] = "i.store_id = :store_id";
        $params['store_id'] = $this->tenantId();
        $where[] = "o.store_id = :store_id2";
        $params['store_id2'] = $this->tenantId();

        if (!empty($filters['type'])) {
            $where[] = "i.type = :type";
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['invoice_flow'])) {
            $where[] = "i.invoice_flow = :invoice_flow";
            $params['invoice_flow'] = $filters['invoice_flow'];
        }

        if (!empty($filters['status'])) {
            $where[] = "i.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "i.issued_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "i.issued_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['marketplace_id'])) {
            $where[] = "o.marketplace_id = :marketplace_id";
            $params['marketplace_id'] = (int) $filters['marketplace_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(
                CONCAT(i.series, i.sequence_no) LIKE :search
                OR i.customer_name LIKE :search
                OR i.customer_tax_no LIKE :search
                OR o.marketplace_order_id LIKE :search
                OR CAST(i.id AS CHAR) LIKE :search
            )";
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $whereStr = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM invoices i JOIN orders o ON i.order_id = o.id WHERE {$whereStr}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $dataParams = $params;
        $dataParams['store_id3'] = $this->tenantId();
        $data = $this->rawQuery(
            "SELECT i.*, o.marketplace_order_id, o.customer_id as order_customer_id, m.display_name as marketplace_name, m.slug as marketplace_slug,
                    ri.series as related_invoice_series, ri.sequence_no as related_invoice_seq
             FROM invoices i
             JOIN orders o ON i.order_id = o.id
             JOIN marketplaces m ON o.marketplace_id = m.id
             LEFT JOIN invoices ri ON i.related_invoice_id = ri.id AND ri.store_id = :store_id3
             WHERE {$whereStr}
             ORDER BY i.issued_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $dataParams
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
     * Bekleyen fatura sayısı
     */
    public function getPendingCount(): int
    {
        return $this->count(['status' => 'draft']);
    }

    /**
     * Pazaryeri bazlı fatura sayıları
     *
     * @return array<int, int> [marketplace_id => count]
     */
    public function getCountsByMarketplace(): array
    {
        $rows = $this->rawQuery(
            "SELECT o.marketplace_id, COUNT(*) as cnt
             FROM invoices i
             JOIN orders o ON i.order_id = o.id
             WHERE i.store_id = :store_id AND o.store_id = :store_id2
             GROUP BY o.marketplace_id",
            ['store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['marketplace_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * Aylık fatura özeti
     *
     * @return array<int, array{month: string, count: int, total: float}>
     */
    public function getMonthlySummary(int $year): array
    {
        return $this->rawQuery(
            "SELECT DATE_FORMAT(issued_at, '%Y-%m') as month,
                    COUNT(*) as count,
                    SUM(total) as total
             FROM invoices
             WHERE YEAR(issued_at) = :year AND status != 'cancelled' AND store_id = :store_id
             GROUP BY month
             ORDER BY month ASC",
            ['year' => $year, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * AJAX DataTables uyumlu filtreli fatura listesi
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, filtered: int}
     */
    public function getFilteredAjax(array $filters, int $start = 0, int $length = 25, string $orderBy = 'i.issued_at', string $orderDir = 'DESC'): array
    {
        $where = ['1=1'];
        $params = [];

        // Tenant izolasyonu: invoices (i) ve orders (o) tenant tablolari
        $where[] = "i.store_id = :store_id";
        $params['store_id'] = $this->tenantId();
        $where[] = "o.store_id = :store_id2";
        $params['store_id2'] = $this->tenantId();

        if (!empty($filters['status'])) {
            $where[] = "i.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['invoice_flow'])) {
            $where[] = "i.invoice_flow = :invoice_flow";
            $params['invoice_flow'] = $filters['invoice_flow'];
        }

        if (!empty($filters['marketplace_id'])) {
            $where[] = "o.marketplace_id = :marketplace_id";
            $params['marketplace_id'] = (int) $filters['marketplace_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "i.issued_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "i.issued_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where[] = "(
                CONCAT(i.series, i.sequence_no) LIKE :search
                OR i.customer_name LIKE :search
                OR i.customer_tax_no LIKE :search
                OR o.marketplace_order_id LIKE :search
                OR i.invoice_number LIKE :search
            )";
            $params['search'] = '%' . trim((string) $filters['search']) . '%';
        }

        $whereStr = implode(' AND ', $where);

        // Total unfiltered (tenant-scoped)
        $totalRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM invoices i JOIN orders o ON i.order_id = o.id WHERE i.store_id = :store_id AND o.store_id = :store_id2",
            ['store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
        $total = (int) ($totalRow['cnt'] ?? 0);

        // Total filtered
        $filteredRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM invoices i JOIN orders o ON i.order_id = o.id WHERE {$whereStr}",
            $params
        );
        $filtered = (int) ($filteredRow['cnt'] ?? 0);

        $dataParams = $params;
        $dataParams['store_id3'] = $this->tenantId();
        $data = $this->rawQuery(
            "SELECT i.*, o.marketplace_order_id, o.marketplace_id, o.customer_id as order_customer_id,
                    o.customer_name as order_customer_name,
                    m.display_name as marketplace_name, m.slug as marketplace_slug,
                    ri.series as related_invoice_series, ri.sequence_no as related_invoice_seq
             FROM invoices i
             JOIN orders o ON i.order_id = o.id
             JOIN marketplaces m ON o.marketplace_id = m.id
             LEFT JOIN invoices ri ON i.related_invoice_id = ri.id AND ri.store_id = :store_id3
             WHERE {$whereStr}
             ORDER BY {$orderBy} {$orderDir}
             LIMIT {$length} OFFSET {$start}",
            $dataParams
        );

        return ['data' => $data, 'total' => $total, 'filtered' => $filtered];
    }

    /**
     * Durum bazlı fatura sayıları
     *
     * @return array<string, int>
     */
    public function getStatusCounts(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        // Tenant izolasyonu: invoices (i) ve orders (o) tenant tablolari
        $where[] = "i.store_id = :store_id";
        $params['store_id'] = $this->tenantId();
        $where[] = "o.store_id = :store_id2";
        $params['store_id2'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = "o.marketplace_id = :marketplace_id";
            $params['marketplace_id'] = (int) $filters['marketplace_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "i.issued_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "i.issued_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = implode(' AND ', $where);

        $rows = $this->rawQuery(
            "SELECT i.status, COUNT(*) as cnt
             FROM invoices i
             JOIN orders o ON i.order_id = o.id
             WHERE {$whereStr}
             GROUP BY i.status",
            $params
        );

        $result = ['all' => 0];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
            $result['all'] += (int) $row['cnt'];
        }
        return $result;
    }
}
