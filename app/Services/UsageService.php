<?php
/**
 * OzyConnect Panel — UsageService (Faz 8: kullanım/limit — gösterim; MVP soft)
 *
 * Aktif aboneliğin paketindeki limitlere karşı mağazanın kullanımını döndürür. NULL limit = sınırsız.
 * MVP'de yalnız GÖSTERİM/uyarı (UsageGuard ileride sert engelleme için flag'lenir).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\Package;
use App\Models\Subscription;

final class UsageService
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Subscription $subscriptions,
        private readonly Package $packages,
    ) {}

    /**
     * Aktif mağazanın kullanım/limit tablosu.
     * @return array<string,array{label:string,used:int,limit:?int,pct:int,unlimited:bool}>
     */
    public function forCurrentStore(): array
    {
        $storeId = $this->tenant->currentStoreId();
        $limits = $this->activeLimits($storeId);

        $defs = [
            'products' => ['Ürün',       'products',   null],
            'orders'   => ['Sipariş/ay', 'orders',     'month'],
            'users'    => ['Kullanıcı',  'users',      null],
            'api'      => ['API Token',  'api_tokens', null],
        ];
        $limitKey = ['products' => 'max_products', 'orders' => 'max_orders_month', 'users' => 'max_users', 'api' => 'max_api_tokens'];

        $out = [];
        foreach ($defs as $key => [$label, $table, $window]) {
            $used  = $this->countTable($table, $storeId, $window);
            $limit = $limits[$limitKey[$key]] ?? null;
            $limit = ($limit === null || $limit === '') ? null : (int) $limit;
            $unlimited = $limit === null;
            $pct = ($unlimited || $limit <= 0) ? 0 : (int) min(100, round($used / $limit * 100));
            $out[$key] = ['label' => $label, 'used' => $used, 'limit' => $limit, 'pct' => $pct, 'unlimited' => $unlimited];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function activeLimits(int $storeId): array
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return [];
        }
        $lim = $this->packages->limits((int) $sub['package_id']);
        return $lim === false ? [] : $lim;
    }

    private function countTable(string $table, int $storeId, ?string $window): int
    {
        // $table sabit (kullanıcı girdisi değil). store_id ile açık filtre.
        $sql = "SELECT COUNT(*) c FROM `{$table}` WHERE store_id = :s";
        $params = ['s' => $storeId];
        if ($window === 'month') {
            $sql .= " AND created_at >= :m";
            $params['m'] = date('Y-m-01 00:00:00');
        }
        try {
            return (int) ($this->db->fetchOne($sql, $params)['c'] ?? 0);
        } catch (\Throwable) {
            return 0; // tablo/kolon farkı olsa bile gösterimi bozma
        }
    }
}
