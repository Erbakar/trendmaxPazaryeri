<?php
/**
 * OzyConnect Panel — Order Model
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 */
declare(strict_types=1);

namespace App\Models;

final class Order extends BaseModel
{
    protected string $table = 'orders';

    /**
     * Pazaryeri sipariş ID'si ile bul
     */
    /**
     * API sipariş listesi — mağazaya kapalı, sıralama beyaz listeli.
     *
     * ApiOrderController daha önce protected rawQuery()'yi çağırıyordu (ölümcül hata)
     * ve sorgularında store_id yoktu. İkisi de burada çözülüyor.
     *
     * @param array{status?: ?string, marketplace_id?: ?int, date_from?: ?string, date_to?: ?string, search?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function apiList(array $filters, int $page, int $perPage, string $sortKey = 'created_desc'): array
    {
        $siralama = [
            'created_desc' => 'o.created_at DESC',
            'created_asc'  => 'o.created_at ASC',
            'ordered_desc' => 'o.ordered_at DESC',
            'ordered_asc'  => 'o.ordered_at ASC',
            'total_desc'   => 'o.total_amount DESC',
            'total_asc'    => 'o.total_amount ASC',
        ];
        $orderBy = $siralama[$sortKey] ?? $siralama['created_desc'];

        $where  = ['o.store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        if (!empty($filters['status'])) {
            $where[] = 'o.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['marketplace_id'])) {
            $where[] = 'o.marketplace_id = :mp_id';
            $params['mp_id'] = (int) $filters['marketplace_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'o.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'o.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = '(o.marketplace_order_id LIKE :search OR o.customer_name LIKE :search2)';
            $params['search']  = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $page     = max(1, $page);
        $perPage  = max(1, min(200, $perPage));
        $offset   = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne("SELECT COUNT(*) AS cnt FROM orders o WHERE {$whereSql}", $params);

        $rows = $this->rawQuery(
            "SELECT o.*, m.display_name AS marketplace_name, m.slug AS marketplace_slug
               FROM orders o
               LEFT JOIN marketplaces m ON m.id = o.marketplace_id
              WHERE {$whereSql}
              ORDER BY {$orderBy}
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => (int) ($countRow['cnt'] ?? 0)];
    }

    /**
     * API sipariş detayı — mağazaya kapalı.
     *
     * @return array<string, mixed>|false
     */
    public function apiFind(int $orderId): array|false
    {
        return $this->rawQueryOne(
            "SELECT o.*, m.display_name AS marketplace_name, m.slug AS marketplace_slug
               FROM orders o
               LEFT JOIN marketplaces m ON m.id = o.marketplace_id
              WHERE o.id = :id AND o.store_id = :store_id
              LIMIT 1",
            ['id' => $orderId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * API sipariş kalemleri — mağazaya kapalı.
     *
     * @return array<int, array<string, mixed>>
     */
    public function apiItems(int $orderId): array
    {
        return $this->rawQuery(
            "SELECT * FROM order_items WHERE order_id = :oid AND store_id = :store_id ORDER BY id ASC",
            ['oid' => $orderId, 'store_id' => $this->tenantId()]
        );
    }

    public function findByMarketplaceOrderId(int $marketplaceId, string $orderId): array|false
    {
        return $this->findBy([
            'marketplace_id'       => $marketplaceId,
            'marketplace_order_id' => $orderId,
        ]);
    }

    /**
     * Pazaryeri sipariş ID listesine göre siparişleri getir
     *
     * @param array<int, string> $orderIds
     * @return array<int, array<string, mixed>>
     */
    public function findByMarketplaceOrderIds(int $marketplaceId, array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_unique(array_map('strval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['marketplace_id' => $marketplaceId, 'store_id' => $this->tenantId()];

        foreach ($orderIds as $index => $orderId) {
            $paramKey = 'order_id_' . $index;
            $placeholders[] = ':' . $paramKey;
            $params[$paramKey] = $orderId;
        }

        return $this->rawQuery(
            'SELECT id, marketplace_order_id, raw_data_json FROM orders
             WHERE marketplace_id = :marketplace_id
               AND store_id = :store_id
               AND marketplace_order_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    /**
     * Belirli bir tarihten sonra oluşan yeni sipariş sayısı
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
     * Filtreli sipariş listesi
     */
    public function getFiltered(array $filters = [], int $page = 1, int $perPage = 25, string $orderBy = 'o.ordered_at', string $orderDir = 'DESC'): array
    {
        $where  = ['1=1'];
        $params = [];

        // Tenant izolasyonu — birincil sipariş tablosu store_id ile sınırlandırılır.
        $where[]              = "o.store_id = :store_id";
        $params['store_id']   = $this->tenantId();

        // Faz 2 — Store Entitlement: yalnız mağazanın AKTİF satın aldığı pazaryerleri.
        // (Pasif/süresi-dolmuş pazaryeri siparişleri liste + sayımdan düşer.)
        $where[]                  = "o.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent']   = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[]        = "o.marketplace_id = :mid";
            $params['mid']  = $filters['marketplace_id'];
        }
        if (!empty($filters['status'])) {
            $where[]           = "o.status = :status";
            $params['status']  = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[]              = "o.ordered_at >= :date_from";
            $params['date_from']  = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]            = "o.ordered_at <= :date_to";
            $params['date_to']  = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[]           = "(o.marketplace_order_id LIKE :s1 OR o.customer_name LIKE :s2 OR o.customer_phone LIKE :s3)";
            $params['s1']      = '%' . $filters['search'] . '%';
            $params['s2']      = '%' . $filters['search'] . '%';
            $params['s3']      = '%' . $filters['search'] . '%';
        }
        if (isset($filters['has_invoice']) && $filters['has_invoice'] !== '') {
            $where[] = $filters['has_invoice'] === '1'
                ? "o.invoice_id IS NOT NULL"
                : "o.invoice_id IS NULL";
        }
        if (!empty($filters['amount_min'])) {
            $where[]               = "o.total_amount >= :amount_min";
            $params['amount_min']  = $filters['amount_min'];
        }
        if (!empty($filters['amount_max'])) {
            $where[]               = "o.total_amount <= :amount_max";
            $params['amount_max']  = $filters['amount_max'];
        }

        // Arşiv filtresi: varsayılan olarak arşivlenmişleri gizle
        if (!empty($filters['show_archived'])) {
            $where[] = "o.is_archived = 1";
        } else {
            $where[] = "o.is_archived = 0";
        }

        $whereStr = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        // Sort validation
        $allowedSort = ['o.ordered_at','o.marketplace_order_id','o.total_amount','o.status','o.cargo_company','o.customer_name','o.created_at'];
        if (!in_array($orderBy, $allowedSort, true)) $orderBy = 'o.ordered_at';
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
        $orderSql = "{$orderBy} {$orderDir}, o.id {$orderDir}";

        $countRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM orders o WHERE {$whereStr}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        // SELECT'te ek tenant tablolarına (subquery + JOIN'ler) ayrı store_id
        // placeholder'ları gerekir (EMULATE_PREPARES=false: aynı :store_id tek
        // statement'ta birden çok kullanılamaz). Hepsi aynı tenantId()'e bağlanır.
        $dataParams = $params + [
            'store_id_oi'  => $this->tenantId(),
            'store_id_c'   => $this->tenantId(),
            'store_id_inv' => $this->tenantId(),
            'store_id_p'   => $this->tenantId(),
            'store_id_fioi'=> $this->tenantId(),
        ];

        $data = $this->rawQuery(
            "SELECT o.*,
                    m.display_name as marketplace_name,
                    m.slug         as marketplace_slug,
                    c.id           as customer_db_id,
                    c.total_orders as customer_total_orders,
                    c.total_spent  as customer_total_spent,
                    inv.series     as invoice_series,
                    inv.sequence_no as invoice_seq,
                    inv.status     as invoice_status,
                    COALESCE(NULLIF(fi_oi.name, ''), NULLIF(fi.item_names, ''), '') as item_names,
                    fi_oi.sku       as first_item_sku,
                    COALESCE(fi.item_count, 0) as item_count,
                    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(fi_p.images_json, '$[0].url')), ''), '') as first_product_image
             FROM orders o
             JOIN  marketplaces m ON o.marketplace_id = m.id
             LEFT JOIN customers c ON o.customer_id   = c.id AND c.store_id = :store_id_c
             LEFT JOIN invoices inv ON o.invoice_id    = inv.id AND inv.store_id = :store_id_inv
             LEFT JOIN (
                 SELECT oi2.order_id,
                        MIN(oi2.id) as first_item_id,
                        COUNT(*) as item_count,
                        GROUP_CONCAT(NULLIF(oi2.name, '') ORDER BY oi2.id SEPARATOR ', ') as item_names
                 FROM order_items oi2
                 WHERE oi2.store_id = :store_id_oi
                 GROUP BY oi2.order_id
             ) fi ON fi.order_id = o.id
             LEFT JOIN order_items fi_oi ON fi_oi.id = fi.first_item_id AND fi_oi.store_id = :store_id_fioi
             LEFT JOIN products fi_p ON fi_oi.product_id = fi_p.id AND fi_p.store_id = :store_id_p
             WHERE {$whereStr}
             ORDER BY {$orderSql}
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
     * Durum bazlı özet sayılar (tab bar için)
     */
    public function getStatusCounts(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        // Tenant izolasyonu
        $where[]            = "store_id = :store_id";
        $params['store_id'] = $this->tenantId();

        // Store Entitlement — sayımlar yalnız AKTİF satın alınan pazaryerlerini içerir (getFiltered ile tutarlı)
        $where[]                = "marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[]       = "marketplace_id = :mid";
            $params['mid'] = $filters['marketplace_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]              = "ordered_at >= :date_from";
            $params['date_from']  = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]            = "ordered_at <= :date_to";
            $params['date_to']  = $filters['date_to'] . ' 23:59:59';
        }
        // Arşivlenmişleri sayılarda gösterme (açıkça belirtilmedikçe)
        if (!empty($filters['show_archived'])) {
            $where[] = "is_archived = 1";
        } else {
            $where[] = "is_archived = 0";
        }

        $whereStr = implode(' AND ', $where);
        $rows = $this->rawQuery(
            "SELECT status, COUNT(*) as cnt FROM orders WHERE {$whereStr} GROUP BY status",
            $params
        );

        $counts = ['all' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
            $counts['all']         += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Toplam sipariş sayısı (filtresiz — DataTables recordsTotal)
     */
    public function getTotalCount(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM orders
             WHERE is_archived = 0 AND store_id = :store_id
               AND marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))",
            ['store_id' => $this->tenantId(), 'store_id_ent' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Sipariş detay (join'li)
     */
    public function getDetail(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT o.*,
                    m.display_name as marketplace_name,
                    m.slug         as marketplace_slug,
                    c.id           as customer_db_id,
                    c.total_orders as customer_total_orders,
                    c.total_spent  as customer_total_spent,
                    c.first_order_at as customer_first_order_at
             FROM orders o
             JOIN  marketplaces m ON o.marketplace_id = m.id
             LEFT JOIN customers c ON o.customer_id   = c.id AND c.store_id = :store_id_c
             WHERE o.id = :id AND o.store_id = :store_id
               AND o.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))",
            ['id' => $id, 'store_id' => $this->tenantId(), 'store_id_c' => $this->tenantId(), 'store_id_ent' => $this->tenantId()]
        );
    }

    /** Dashboard: Bugünkü istatistikler */
    public function getTodayStats(): array
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue
             FROM orders WHERE DATE(ordered_at) = CURDATE() AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return ['count' => (int)($row['cnt'] ?? 0), 'revenue' => (float)($row['revenue'] ?? 0)];
    }

    /** Dashboard: Bu haftaki istatistikler */
    public function getWeekStats(): array
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue
             FROM orders WHERE ordered_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return ['count' => (int)($row['cnt'] ?? 0), 'revenue' => (float)($row['revenue'] ?? 0)];
    }

    /** Dashboard: Son 7 gün günlük grafik */
    public function getDailyChart(int $days = 7): array
    {
        return $this->rawQuery(
            "SELECT DATE(ordered_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue
             FROM orders WHERE ordered_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) AND store_id = :store_id
             GROUP BY DATE(ordered_at) ORDER BY date ASC",
            ['days' => $days, 'store_id' => $this->tenantId()]
        );
    }

    /** Dashboard: Pazaryeri bazlı istatistikler */
    public function getMarketplaceStats(): array
    {
        return $this->rawQuery(
            "SELECT m.slug, m.display_name,
                    COUNT(o.id) as order_count,
                    COALESCE(SUM(o.total_amount),0) as revenue
             FROM marketplaces m
             LEFT JOIN orders o ON o.marketplace_id = m.id
                 AND o.store_id = :store_id
                 AND o.ordered_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             WHERE m.is_active = 1
             GROUP BY m.id ORDER BY order_count DESC",
            ['store_id' => $this->tenantId()]
        );
    }

    /**
     * Finans (birleşik) özeti — SİPARİŞLERDEN hesaplanır (finans API'si olmayan pazaryerleri dahil).
     * brüt = tüm sipariş tutarı, iade = iptal/iade tutarı, satış = brüt - iade.
     *
     * @param array<string,mixed> $filters date_from, date_to (Y-m-d)
     * @return array{order_count:int, gross:float, sales:float, returns_amount:float, return_count:int}
     */
    public function getFinanceSummary(array $filters = []): array
    {
        $where  = ['o.store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];
        if (!empty($filters['date_from'])) { $where[] = 'o.ordered_at >= :df'; $params['df'] = $filters['date_from'] . ' 00:00:00'; }
        if (!empty($filters['date_to']))   { $where[] = 'o.ordered_at <= :dt'; $params['dt'] = $filters['date_to'] . ' 23:59:59'; }
        $whereStr = implode(' AND ', $where);
        $row = $this->rawQueryOne(
            "SELECT
                COUNT(*) AS order_count,
                COALESCE(SUM(o.total_amount),0) AS gross,
                COALESCE(SUM(CASE WHEN o.status IN ('iade','iptal') THEN o.total_amount ELSE 0 END),0) AS returns_amount,
                COALESCE(SUM(CASE WHEN o.status NOT IN ('iade','iptal') THEN o.total_amount ELSE 0 END),0) AS sales,
                COALESCE(SUM(CASE WHEN o.status = 'iade' THEN 1 ELSE 0 END),0) AS return_count
             FROM orders o WHERE {$whereStr}",
            $params
        ) ?: [];
        return [
            'order_count'    => (int) ($row['order_count'] ?? 0),
            'gross'          => (float) ($row['gross'] ?? 0),
            'sales'          => (float) ($row['sales'] ?? 0),
            'returns_amount' => (float) ($row['returns_amount'] ?? 0),
            'return_count'   => (int) ($row['return_count'] ?? 0),
        ];
    }

    /** Dashboard: Son N sipariş */
    public function getRecent(int $limit = 10): array
    {
        return $this->rawQuery(
            "SELECT o.*, m.display_name as marketplace_name, m.slug as marketplace_slug
             FROM orders o
             JOIN marketplaces m ON o.marketplace_id = m.id
             WHERE o.store_id = :store_id
             ORDER BY o.ordered_at DESC LIMIT :limit",
            ['limit' => $limit, 'store_id' => $this->tenantId()]
        );
    }

    /** Dashboard: Faturasız sipariş sayısı */
    public function getPendingInvoiceCount(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM orders
             WHERE invoice_id IS NULL AND status NOT IN ('iptal','iade') AND store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Pazaryeri bazlı sipariş sayıları (tab badge için)
     */
    public function getMarketplaceOrderCounts(): array
    {
        $rows = $this->rawQuery(
            "SELECT marketplace_id, COUNT(*) as cnt FROM orders
             WHERE is_archived = 0 AND store_id = :store_id
               AND marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
             GROUP BY marketplace_id",
            ['store_id' => $this->tenantId(), 'store_id_ent' => $this->tenantId()]
        );
        $counts = ['all' => 0];
        foreach ($rows as $row) {
            $counts[$row['marketplace_id']] = (int) $row['cnt'];
            $counts['all'] += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Pazaryeri bazlı 'yeni' (işlem bekleyen) sipariş sayıları — pazaryeri sekmesindeki "N yeni" rozeti.
     *
     * @return array<int, int> marketplace_id => adet
     */
    public function getMarketplaceNewCounts(): array
    {
        $rows = $this->rawQuery(
            "SELECT marketplace_id, COUNT(*) AS cnt FROM orders
             WHERE store_id = :store_id AND status = 'yeni' AND is_archived = 0 AND marketplace_id IS NOT NULL
             GROUP BY marketplace_id",
            ['store_id' => $this->tenantId()]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['marketplace_id']] = (int) $row['cnt'];
        }
        return $counts;
    }
}
