<?php
/**
 * OzyConnect Panel — SubscriptionService (Faz 8: abonelik durum makinesi — TEK geçiş noktası)
 *
 * Her geçiş: subscriptions satırını güncelle → subscription_history'ye yaz → PackageEntitlementSync ile
 * entitlement uygula/iptal et (EntitlementService'e DOKUNMADAN) → gerekirse stores.status.
 * Bildirim/e-posta YOK (çağıran controller/cron üretir) — servis saf durum + entitlement.
 *
 * GENİŞLETİLEBİLİRLİK: fiyat hesabı invoice katmanında ayrı; kupon/add-on ileride Resolver+invoice'a
 * eklenir, bu durum makinesi değişmez.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\AdminSession;
use App\Core\Database;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;

final class SubscriptionService
{
    public function __construct(
        private readonly Database $db,
        private readonly Subscription $subscriptions,
        private readonly SubscriptionHistory $history,
        private readonly PackageEntitlementSync $sync,
        private readonly AdminSession $adminSession,
    ) {}

    private function actor(?int $changedBy): ?int
    {
        if ($changedBy !== null) {
            return $changedBy;
        }
        $id = (int) $this->adminSession->adminId();
        return $id > 0 ? $id : null;
    }

    private function periodEnd(string $cycle): string
    {
        return date('Y-m-d H:i:s', strtotime($cycle === 'yearly' ? '+1 year' : '+1 month'));
    }

    /** Yeni mağaza için trial başlat. Mağazada abonelik varsa mevcut id döner (idempotent). */
    public function startTrial(int $storeId, int $packageId, string $cycle, int $trialDays, ?int $changedBy = null): int
    {
        $existing = $this->subscriptions->forStore($storeId);
        if ($existing !== false) {
            return (int) $existing['id'];
        }
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        // trial_days=0 pakette expires_at=ŞİMDİ yazılırsa entitlement anında
        // süresi dolmuş doğar ve pazaryerleri mağazada hiç görünmez —
        // 0 günlük denemede dönem sonu normal fatura dönemine göre belirlenir.
        $trialEnds = $trialDays > 0
            ? date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days'))
            : $this->periodEnd($cycle);
        $subId = $this->subscriptions->create([
            'store_id'             => $storeId,
            'package_id'           => $packageId,
            'status'               => 'trial',
            'billing_cycle'        => $cycle,
            'trial_ends_at'        => $trialEnds,
            'current_period_start' => date('Y-m-d H:i:s'),
            'current_period_end'   => $trialEnds,
            'started_at'           => date('Y-m-d H:i:s'),
            'created_by'           => $this->actor($changedBy),
        ]);
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => $subId, 'new_package_id' => $packageId,
            'old_status' => null, 'new_status' => 'trial', 'action' => 'provision',
            'changed_by' => $this->actor($changedBy), 'reason' => 'provision:trial',
        ]);
        $this->sync->applyPackage($storeId, $packageId, $trialEnds, 'provision:trial');
        return $subId;
    }

    /** Ödeme onaylandı → aktifleştir + dönem başlat + entitlement expiry tazele. */
    public function activateFromPayment(int $storeId, ?int $changedBy = null): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return;
        }
        $cycle = (string) ($sub['billing_cycle'] ?? 'monthly');
        $end = $this->periodEnd($cycle);
        $old = (string) $sub['status'];
        $this->subscriptions->update((int) $sub['id'], [
            'status' => 'active', 'current_period_start' => date('Y-m-d H:i:s'), 'current_period_end' => $end,
        ]);
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'new_package_id' => (int) $sub['package_id'],
            'old_status' => $old, 'new_status' => 'active', 'action' => 'renew',
            'changed_by' => $this->actor($changedBy), 'reason' => 'payment:active',
        ]);
        $this->sync->applyPackage($storeId, (int) $sub['package_id'], $end, 'payment:active');
        $this->setStoreStatus($storeId, 'active');
    }

    /** Süre doldu (cron) → expired + entitlement revoke. */
    public function expire(int $storeId, ?int $changedBy = null, string $reason = 'period_expired'): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false || in_array($sub['status'], ['expired', 'cancelled'], true)) {
            return;
        }
        $this->subscriptions->update((int) $sub['id'], ['status' => 'expired']);
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'new_package_id' => (int) $sub['package_id'],
            'old_status' => (string) $sub['status'], 'new_status' => 'expired', 'action' => 'expire',
            'changed_by' => $this->actor($changedBy), 'reason' => $reason,
        ]);
        $this->sync->revokeAllOfPackage($storeId, (int) $sub['package_id'], $reason);
    }

    public function suspend(int $storeId, ?int $changedBy = null, string $reason = 'admin_suspend'): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return;
        }
        $this->subscriptions->update((int) $sub['id'], ['status' => 'suspended']);
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'new_package_id' => (int) $sub['package_id'],
            'old_status' => (string) $sub['status'], 'new_status' => 'suspended', 'action' => 'suspend',
            'changed_by' => $this->actor($changedBy), 'reason' => $reason,
        ]);
        $this->sync->revokeAllOfPackage($storeId, (int) $sub['package_id'], $reason);
        $this->setStoreStatus($storeId, 'suspended');
    }

    public function cancel(int $storeId, ?int $changedBy = null, string $reason = 'cancelled'): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return;
        }
        $this->subscriptions->update((int) $sub['id'], ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'new_package_id' => (int) $sub['package_id'],
            'old_status' => (string) $sub['status'], 'new_status' => 'cancelled', 'action' => 'cancel',
            'changed_by' => $this->actor($changedBy), 'reason' => $reason,
        ]);
        $this->sync->revokeAllOfPackage($storeId, (int) $sub['package_id'], $reason);
    }

    public function reactivate(int $storeId, ?int $changedBy = null): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return;
        }
        $end = $this->periodEnd((string) ($sub['billing_cycle'] ?? 'monthly'));
        $this->subscriptions->update((int) $sub['id'], [
            'status' => 'active', 'current_period_start' => date('Y-m-d H:i:s'), 'current_period_end' => $end, 'cancelled_at' => null,
        ]);
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'new_package_id' => (int) $sub['package_id'],
            'old_status' => (string) $sub['status'], 'new_status' => 'active', 'action' => 'reactivate',
            'changed_by' => $this->actor($changedBy), 'reason' => 'reactivate',
        ]);
        $this->sync->applyPackage($storeId, (int) $sub['package_id'], $end, 'reactivate');
        $this->setStoreStatus($storeId, 'active');
    }

    /** Paket değiştir (upgrade/downgrade). expiry mevcut dönem sonuna göre korunur. */
    public function changePackage(int $storeId, int $newPackageId, ?int $changedBy = null): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false || (int) $sub['package_id'] === $newPackageId) {
            return;
        }
        $oldPkg = (int) $sub['package_id'];
        $oldPrice = (float) ($sub['price_monthly'] ?? 0);
        $end = (string) ($sub['current_period_end'] ?: $this->periodEnd((string) ($sub['billing_cycle'] ?? 'monthly')));
        $this->subscriptions->update((int) $sub['id'], ['package_id' => $newPackageId]);
        $newSub = $this->subscriptions->forStore($storeId);
        $action = ((float) ($newSub['price_monthly'] ?? 0) >= $oldPrice) ? 'upgrade' : 'downgrade';
        $this->history->log([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'old_package_id' => $oldPkg, 'new_package_id' => $newPackageId,
            'old_status' => (string) $sub['status'], 'new_status' => (string) $sub['status'], 'action' => $action,
            'changed_by' => $this->actor($changedBy), 'reason' => 'package_change',
        ]);
        // Yeni paketi uygula (eksikleri grant, fazlaları revoke). Süresi dolmuş abonelikte expiry geçmişte kalır.
        $this->sync->applyPackage($storeId, $newPackageId, $end, $action);
    }

    /** Pakete yeniden eşitle (drift düzeltme — admin "re-sync" butonu). */
    public function resyncEntitlements(int $storeId): void
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return;
        }
        $active = in_array($sub['status'], ['active', 'trial'], true);
        $end = (string) ($sub['current_period_end'] ?: '');
        if ($active) {
            $this->sync->applyPackage($storeId, (int) $sub['package_id'], $end !== '' ? $end : null, 're-sync');
        } else {
            $this->sync->revokeAllOfPackage($storeId, (int) $sub['package_id'], 're-sync:inactive');
        }
    }

    /**
     * Cron: bir mağazanın aboneliğini yaşam döngüsüne göre ilerlet.
     * trial bitti → expired; active dönem bitti → expired. Idempotent (expired/cancelled/suspended → skip).
     * @return string yapılan aksiyon ('expired_trial'|'expired_period'|'none'|'skip')
     */
    public function runLifecycle(int $storeId): string
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            return 'none';
        }
        $status = (string) $sub['status'];
        if (in_array($status, ['expired', 'cancelled', 'suspended'], true)) {
            return 'skip';
        }
        $now = date('Y-m-d H:i:s');
        if ($status === 'trial' && !empty($sub['trial_ends_at']) && (string) $sub['trial_ends_at'] < $now) {
            $this->expire($storeId, null, 'trial_expired');
            return 'expired_trial';
        }
        if ($status === 'active' && !empty($sub['current_period_end']) && (string) $sub['current_period_end'] < $now) {
            $this->expire($storeId, null, 'period_expired');
            return 'expired_period';
        }
        return 'none';
    }

    private function setStoreStatus(int $storeId, string $status): void
    {
        try {
            $this->db->query("UPDATE stores SET status = :st WHERE id = :id", ['st' => $status, 'id' => $storeId]);
        } catch (\Throwable) {
            // stores.status enum dışı bir değer gelmez (active/suspended); yine de akışı bozma
        }
    }
}
