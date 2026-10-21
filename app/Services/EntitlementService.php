<?php
/**
 * OzyConnect Panel — EntitlementService
 *
 * Faz 2 — MAĞAZA-BAZLI entitlement çekirdeği. Erişim = Tenant ∩ Store Entitlement ∩ RBAC.
 *
 * OKUMA (canAccess.. ve allowed.. metodlari): AKTİF mağaza (TenantContext.currentStoreId()) için — merchant
 * kendi yetkilerini görür. YAZMA (grant/revoke): admin AÇIKÇA bir store_id belirtir (mağazalar
 * arası yönetim). Her yetki değişikliği system_audit_log'a yazılır.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\AdminSession;
use App\Core\Database;
use App\Core\TenantContext;

final class EntitlementService
{
    /**
     * Satın alınabilir (entitlement-gated) FEATURE modülleri.
     * Bu listede OLMAYAN modüller (dashboard, settings, users, roles, logs, backups, marketplaces
     * yönetimi) her zaman açıktır (yalnız RBAC ile korunur — mağaza "satın almaz").
     */
    public const ENTITLEMENT_MODULES = [
        'orders', 'returns', 'questions', 'products', 'categories', 'brands',
        'customers', 'invoices', 'finance', 'shipping', 'feeds', 'import', 'sync',
        // AI Suite modülleri (satın alınır; kullanım ayrıca kredi harcar)
        'ai_content', 'ai_bulk', 'ai_vision', 'ai_optimize', 'ai_competitor', 'ai_advisor', 'ai_trends',
    ];

    /** @var array<int, int[]> store_id => marketplace_ids (per-request cache) */
    private array $mpCache = [];
    /** @var array<int, string[]> store_id => module_keys (per-request cache) */
    private array $modCache = [];

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly AdminSession $adminSession,
    ) {}

    // ─── OKUMA (aktif mağaza = TenantContext) ──────────────────────────────

    /** Aktif mağazanın erişebileceği (active + geçerli) pazaryeri id'leri. */
    public function allowedMarketplaceIds(): array
    {
        $sid = $this->tenant->currentStoreId();
        if (isset($this->mpCache[$sid])) {
            return $this->mpCache[$sid];
        }
        $rows = $this->db->fetchAll(
            "SELECT marketplace_id FROM store_marketplaces
             WHERE store_id = :sid AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())",
            ['sid' => $sid]
        );
        return $this->mpCache[$sid] = array_map(static fn($r) => (int) $r['marketplace_id'], $rows);
    }

    public function canAccessMarketplace(int $marketplaceId): bool
    {
        return in_array($marketplaceId, $this->allowedMarketplaceIds(), true);
    }

    public function isEntitlementModule(string $moduleKey): bool
    {
        return in_array($moduleKey, self::ENTITLEMENT_MODULES, true);
    }

    /** Aktif mağazanın aldığı (active + geçerli) modül anahtarları. */
    public function activeModuleKeys(): array
    {
        $sid = $this->tenant->currentStoreId();
        if (isset($this->modCache[$sid])) {
            return $this->modCache[$sid];
        }
        $rows = $this->db->fetchAll(
            "SELECT module_key FROM store_modules
             WHERE store_id = :sid AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())",
            ['sid' => $sid]
        );
        return $this->modCache[$sid] = array_map(static fn($r) => (string) $r['module_key'], $rows);
    }

    /**
     * Modül erişimi: entitlement-gated DEĞİLSE daima true (always-on); gated ise mağaza almış mı.
     */
    public function canAccessModule(string $moduleKey): bool
    {
        if (!$this->isEntitlementModule($moduleKey)) {
            return true;
        }
        return in_array($moduleKey, $this->activeModuleKeys(), true);
    }

    /** Menü/UI için: aktif mağaza yetkileri. */
    public function getStoreEntitlements(): array
    {
        return [
            'marketplace_ids' => $this->allowedMarketplaceIds(),
            'modules'         => $this->activeModuleKeys(),
        ];
    }

    // ─── YAZMA (admin; açık store_id) — her değişiklik audit'lenir ─────────

    public function grantMarketplace(int $storeId, int $marketplaceId, ?string $expiresAt = null, ?string $reason = null, ?string $notes = null): void
    {
        $old = $this->currentStatus('store_marketplaces', 'marketplace_id', $storeId, (string) $marketplaceId);
        $this->db->query(
            "INSERT INTO store_marketplaces (store_id, marketplace_id, status, granted_by, granted_at, expires_at, reason, notes)
             VALUES (:sid, :mp, 'active', :by, NOW(), :exp, :reason, :notes)
             ON DUPLICATE KEY UPDATE status='active', granted_by=VALUES(granted_by), granted_at=NOW(),
                 expires_at=VALUES(expires_at), reason=VALUES(reason), notes=VALUES(notes), revoked_by=NULL, revoked_at=NULL",
            ['sid' => $storeId, 'mp' => $marketplaceId, 'by' => $this->adminSession->adminId(), 'exp' => $expiresAt, 'reason' => $reason, 'notes' => $notes]
        );
        $this->audit('entitlement.marketplace.grant', "store#{$storeId}/mp#{$marketplaceId}", $old, 'active', $expiresAt, $reason);
        $this->clearCache();
    }

    public function revokeMarketplace(int $storeId, int $marketplaceId, ?string $reason = null): void
    {
        $old = $this->currentStatus('store_marketplaces', 'marketplace_id', $storeId, (string) $marketplaceId);
        $this->db->query(
            "UPDATE store_marketplaces SET status='passive', revoked_by=:by, revoked_at=NOW(), reason=COALESCE(:reason, reason)
             WHERE store_id = :sid AND marketplace_id = :mp",
            ['sid' => $storeId, 'mp' => $marketplaceId, 'by' => $this->adminSession->adminId(), 'reason' => $reason]
        );
        $this->audit('entitlement.marketplace.revoke', "store#{$storeId}/mp#{$marketplaceId}", $old, 'passive', null, $reason);
        $this->clearCache();
    }

    public function grantModule(int $storeId, string $moduleKey, ?string $expiresAt = null, ?string $reason = null, ?string $notes = null): void
    {
        $old = $this->currentStatus('store_modules', 'module_key', $storeId, $moduleKey);
        $this->db->query(
            "INSERT INTO store_modules (store_id, module_key, status, granted_by, granted_at, expires_at, reason, notes)
             VALUES (:sid, :mk, 'active', :by, NOW(), :exp, :reason, :notes)
             ON DUPLICATE KEY UPDATE status='active', granted_by=VALUES(granted_by), granted_at=NOW(),
                 expires_at=VALUES(expires_at), reason=VALUES(reason), notes=VALUES(notes), revoked_by=NULL, revoked_at=NULL",
            ['sid' => $storeId, 'mk' => $moduleKey, 'by' => $this->adminSession->adminId(), 'exp' => $expiresAt, 'reason' => $reason, 'notes' => $notes]
        );
        $this->audit('entitlement.module.grant', "store#{$storeId}/{$moduleKey}", $old, 'active', $expiresAt, $reason);
        $this->clearCache();
    }

    public function revokeModule(int $storeId, string $moduleKey, ?string $reason = null): void
    {
        $old = $this->currentStatus('store_modules', 'module_key', $storeId, $moduleKey);
        $this->db->query(
            "UPDATE store_modules SET status='passive', revoked_by=:by, revoked_at=NOW(), reason=COALESCE(:reason, reason)
             WHERE store_id = :sid AND module_key = :mk",
            ['sid' => $storeId, 'mk' => $moduleKey, 'by' => $this->adminSession->adminId(), 'reason' => $reason]
        );
        $this->audit('entitlement.module.revoke', "store#{$storeId}/{$moduleKey}", $old, 'passive', null, $reason);
        $this->clearCache();
    }

    // ─── iç yardımcılar ────────────────────────────────────────────────────

    private function currentStatus(string $table, string $col, int $storeId, string $val): ?string
    {
        // $table/$col sabit çağrı yerlerinden gelir (kullanıcı girdisi değil).
        $row = $this->db->fetchOne(
            "SELECT status FROM `{$table}` WHERE store_id = :sid AND `{$col}` = :val LIMIT 1",
            ['sid' => $storeId, 'val' => $val]
        );
        return $row === false ? null : (string) $row['status'];
    }

    private function audit(string $action, string $target, ?string $oldStatus, string $newStatus, ?string $expiresAt, ?string $reason = null): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_audit_log (user_id, admin_user_id, actor_type, actor_email, action, target, result, detail_json, ip_address, created_at)
                 VALUES (NULL, ?, 'admin', ?, ?, ?, 'success', ?, ?, NOW())",
                [
                    $this->adminSession->adminId(),
                    $this->adminSession->adminEmail(),
                    $action,
                    $target,
                    json_encode(['old_status' => $oldStatus, 'new_status' => $newStatus, 'expires_at' => $expiresAt, 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null),
                ]
            );
        } catch (\Throwable) {
            // audit yazılamazsa akışı bozma
        }
    }

    private function clearCache(): void
    {
        $this->mpCache = [];
        $this->modCache = [];
    }
}
