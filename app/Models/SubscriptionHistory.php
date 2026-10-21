<?php
/** OzyConnect Panel — SubscriptionHistory (Faz 8: paket geçmişi — store-bağlı, tenantScoped=false). */
declare(strict_types=1);

namespace App\Models;

final class SubscriptionHistory extends BaseModel
{
    protected string $table = 'subscription_history';
    protected bool $tenantScoped = false;

    /** @param array<string,mixed> $d */
    public function log(array $d): int
    {
        return $this->create([
            'store_id'        => (int) $d['store_id'],
            'subscription_id' => (int) $d['subscription_id'],
            'old_package_id'  => isset($d['old_package_id']) ? (int) $d['old_package_id'] : null,
            'new_package_id'  => isset($d['new_package_id']) ? (int) $d['new_package_id'] : null,
            'old_status'      => $d['old_status'] ?? null,
            'new_status'      => $d['new_status'] ?? null,
            'action'          => $d['action'],
            'changed_by'      => isset($d['changed_by']) ? (int) $d['changed_by'] : null,
            'reason'          => $d['reason'] ?? null,
        ]);
    }

    public function forStore(int $storeId, int $limit = 100): array
    {
        $lim = max(1, min(500, $limit));
        return $this->rawQuery(
            "SELECT h.*, op.name AS old_package, np.name AS new_package
             FROM subscription_history h
             LEFT JOIN packages op ON op.id = h.old_package_id
             LEFT JOIN packages np ON np.id = h.new_package_id
             WHERE h.store_id = :s ORDER BY h.id DESC LIMIT {$lim}",
            ['s' => $storeId]
        );
    }
}
