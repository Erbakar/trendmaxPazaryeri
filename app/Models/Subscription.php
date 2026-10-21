<?php
/**
 * OzyConnect Panel — Subscription (Faz 8: abonelik — store-bağlı, BaseModel-tenant-scope DEĞİL)
 *
 * Admin cross-store yönetir; merchant kendi aboneliğini forStore() ile açık store_id ile okur.
 * Durum geçişleri YALNIZ SubscriptionService üzerinden (state machine + history).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class Subscription extends BaseModel
{
    protected string $table = 'subscriptions';
    protected bool $tenantScoped = false;

    public function forStore(int $storeId): array|false
    {
        return $this->rawQueryOne(
            "SELECT s.*, p.name AS package_name, p.slug AS package_slug, p.price_monthly, p.price_yearly
             FROM subscriptions s LEFT JOIN packages p ON p.id = s.package_id
             WHERE s.store_id = :s",
            ['s' => $storeId]
        );
    }

    public function adminList(?string $status = null, int $limit = 300): array
    {
        $lim = max(1, min(1000, $limit));
        $where = '';
        $params = [];
        if ($status !== null && $status !== '') {
            $where = 'WHERE s.status = :st';
            $params['st'] = $status;
        }
        return $this->rawQuery(
            "SELECT s.*, p.name AS package_name, st.name AS store_name
             FROM subscriptions s
             LEFT JOIN packages p ON p.id = s.package_id
             LEFT JOIN stores st ON st.id = s.store_id
             {$where} ORDER BY s.id DESC LIMIT {$lim}",
            $params
        );
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        $rows = $this->rawQuery("SELECT status, COUNT(*) c FROM subscriptions GROUP BY status");
        $out = ['trial' => 0, 'active' => 0, 'passive' => 0, 'suspended' => 0, 'expired' => 0, 'cancelled' => 0, 'all' => 0];
        foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; $out['all'] += (int) $r['c']; }
        return $out;
    }

    /** Trial dönüşüm metrikleri (store#1 internal hariç). */
    public function conversionMetrics(): array
    {
        $row = $this->rawQueryOne(
            "SELECT
               SUM(status='trial') AS trial,
               SUM(status='active' AND package_id <> 1) AS active_paid,
               SUM(status IN ('expired','cancelled')) AS ended,
               COUNT(*) AS total
             FROM subscriptions WHERE package_id <> 1 OR status <> 'active'"
        ) ?: [];
        $trial = (int) ($row['trial'] ?? 0);
        $paid = (int) ($row['active_paid'] ?? 0);
        $ended = (int) ($row['ended'] ?? 0);
        $denom = $paid + $ended + $trial;
        return [
            'trial'      => $trial,
            'active_paid'=> $paid,
            'ended'      => $ended,
            'rate'       => $denom > 0 ? round($paid / $denom * 100, 1) : 0.0,
        ];
    }
}
