<?php
/**
 * OzyConnect Panel — SystemAuditLog (Faz 7, salt-okuma)
 *
 * system_audit_log GLOBAL tablosu (store_id YOK → tenantScoped=false). Yazımlar ilgili
 * servislerde (EntitlementService/AdminAuthService/BackupService) ham SQL ile yapılır;
 * bu model ops/audit dashboard için OKUMA yardımcıları sağlar.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class SystemAuditLog extends BaseModel
{
    protected string $table = 'system_audit_log';
    protected bool $tenantScoped = false;

    /**
     * Son audit kayıtları (filtreli). $filters: actor_type, result, action_prefix.
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 50, array $filters = []): array
    {
        $where = []; $params = [];
        if (!empty($filters['actor_type'])) { $where[] = 'actor_type = :at'; $params['at'] = $filters['actor_type']; }
        if (!empty($filters['result']))     { $where[] = 'result = :res';    $params['res'] = $filters['result']; }
        if (!empty($filters['action_prefix'])) { $where[] = 'action LIKE :ap'; $params['ap'] = $filters['action_prefix'] . '%'; }
        $sql = "SELECT * FROM system_audit_log";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) max(1, min(500, $limit));
        return $this->db->fetchAll($sql, $params);
    }

    /** Admin işlemleri (actor_type='admin'). */
    public function adminActions(int $limit = 50): array
    {
        return $this->recent($limit, ['actor_type' => 'admin']);
    }

    /** Entitlement değişiklikleri (grant/revoke). */
    public function entitlementChanges(int $limit = 50): array
    {
        return $this->recent($limit, ['action_prefix' => 'entitlement.']);
    }

    /** Kritik olaylar: reddedilen/başarısız sonuçlar. */
    public function criticalEvents(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM system_audit_log
              WHERE result IN ('denied','failed')
              ORDER BY created_at DESC, id DESC
              LIMIT " . (int) max(1, min(500, $limit))
        );
    }

    /**
     * Son N saatte sonuç (success/denied/failed) sayıları.
     * @return array<string,int>
     */
    public function countsByResult(int $windowHours = 24): array
    {
        $rows = $this->db->fetchAll(
            "SELECT result, COUNT(*) AS n FROM system_audit_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)
              GROUP BY result",
            ['h' => $windowHours]
        );
        $out = ['success' => 0, 'denied' => 0, 'failed' => 0];
        foreach ($rows as $r) { $out[$r['result']] = (int) $r['n']; }
        return $out;
    }
}
