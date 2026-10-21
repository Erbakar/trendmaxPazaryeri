<?php
/** OzyConnect Panel — Payment (Faz 8: SaaS ödeme/dekont — store-bağlı, tenantScoped=false). */
declare(strict_types=1);

namespace App\Models;

final class Payment extends BaseModel
{
    protected string $table = 'payments';
    protected bool $tenantScoped = false;

    public function forStore(int $storeId, int $limit = 100): array
    {
        $lim = max(1, min(500, $limit));
        return $this->rawQuery(
            "SELECT p.*, i.number AS invoice_number FROM payments p
             LEFT JOIN subscription_invoices i ON i.id = p.subscription_invoice_id
             WHERE p.store_id = :s ORDER BY p.id DESC LIMIT {$lim}",
            ['s' => $storeId]
        );
    }

    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT p.*, i.number AS invoice_number, i.subscription_id, st.name AS store_name
             FROM payments p
             LEFT JOIN subscription_invoices i ON i.id = p.subscription_invoice_id
             LEFT JOIN stores st ON st.id = p.store_id WHERE p.id = :id",
            ['id' => $id]
        );
    }

    public function adminList(?string $status = null, int $limit = 300): array
    {
        $lim = max(1, min(1000, $limit));
        $where = '';
        $params = [];
        if ($status !== null && $status !== '') { $where = 'WHERE p.status = :st'; $params['st'] = $status; }
        return $this->rawQuery(
            "SELECT p.*, i.number AS invoice_number, st.name AS store_name
             FROM payments p
             LEFT JOIN subscription_invoices i ON i.id = p.subscription_invoice_id
             LEFT JOIN stores st ON st.id = p.store_id
             {$where} ORDER BY p.id DESC LIMIT {$lim}",
            $params
        );
    }

    public function pendingCount(): int
    {
        return (int) ($this->rawQueryOne("SELECT COUNT(*) c FROM payments WHERE status='pending'")['c'] ?? 0);
    }

    /** Bu ay onaylanan tahsilat toplamı. */
    public function confirmedThisMonth(): float
    {
        $r = $this->rawQueryOne(
            "SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='confirmed' AND confirmed_at >= :d",
            ['d' => date('Y-m-01 00:00:00')]
        );
        return (float) ($r['t'] ?? 0);
    }

    /** Geçen ay onaylanan tahsilat toplamı (trend/delta kıyası için). */
    public function confirmedLastMonth(): float
    {
        $r = $this->rawQueryOne(
            "SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status='confirmed' AND confirmed_at >= :a AND confirmed_at < :b",
            ['a' => date('Y-m-01 00:00:00', strtotime('first day of last month')), 'b' => date('Y-m-01 00:00:00')]
        );
        return (float) ($r['t'] ?? 0);
    }

    /**
     * Son N ayın onaylanan tahsilat serisi (sıfır-dolgulu, en eskiden yeniye).
     * @return array<int,array{ym:string,label:string,total:float}>
     */
    public function confirmedMonthlySeries(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $mon = ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $base = strtotime(date('Y-m-01'));
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-{$i} month", $base);
            $ym = date('Y-m', $ts);
            $buckets[$ym] = ['ym' => $ym, 'label' => $mon[(int) date('n', $ts)], 'total' => 0.0];
        }
        $start = date('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' month', $base));
        $rows = $this->rawQuery(
            "SELECT DATE_FORMAT(confirmed_at,'%Y-%m') ym, COALESCE(SUM(amount),0) t
             FROM payments WHERE status='confirmed' AND confirmed_at >= :d GROUP BY ym",
            ['d' => $start]
        );
        foreach ($rows as $r) {
            $ym = (string) ($r['ym'] ?? '');
            if (isset($buckets[$ym])) { $buckets[$ym]['total'] = (float) $r['t']; }
        }
        return array_values($buckets);
    }

    public function setStatus(int $id, string $status, ?int $adminId): void
    {
        $this->db->query(
            "UPDATE payments SET status=:st, confirmed_by=:by, confirmed_at=NOW() WHERE id=:id",
            ['st' => $status, 'by' => $adminId, 'id' => $id]
        );
    }

    /** Faz 9 — PayTR callback: merchant_oid ile ödeme bul. */
    public function findByMerchantOid(string $oid): array|false
    {
        return $this->rawQueryOne(
            "SELECT p.*, i.subscription_id FROM payments p
             LEFT JOIN subscription_invoices i ON i.id = p.subscription_invoice_id
             WHERE p.merchant_oid = :o LIMIT 1",
            ['o' => $oid]
        );
    }

    public function setProviderRef(int $id, string $ref): void
    {
        $this->db->query("UPDATE payments SET provider_ref=:r WHERE id=:id", ['r' => mb_substr($ref, 0, 120), 'id' => $id]);
    }
}
