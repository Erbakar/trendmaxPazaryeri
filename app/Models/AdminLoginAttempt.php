<?php
/** OzyConnect — AdminLoginAttempt (admin giriş/IP/cihaz/başarısız-giriş logu). @package OzyConnect */
declare(strict_types=1);

namespace App\Models;

final class AdminLoginAttempt extends BaseModel
{
    protected string $table = 'admin_login_attempts';
    protected bool $tenantScoped = false;

    /** Son başarısız giriş denemeleri (Faz 7 audit dashboard). */
    public function recentFailures(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM admin_login_attempts WHERE success = 0
              ORDER BY created_at DESC, id DESC LIMIT " . (int) max(1, min(500, $limit))
        );
    }

    /** Son N saatteki başarılı/başarısız giriş sayıları + en çok denenen IP'ler. */
    public function stats(int $windowHours = 24): array
    {
        $row = $this->db->fetchOne(
            "SELECT SUM(success=1) AS ok, SUM(success=0) AS fail, COUNT(*) AS total
               FROM admin_login_attempts WHERE created_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)",
            ['h' => $windowHours]
        );
        $topIps = $this->db->fetchAll(
            "SELECT ip_address, COUNT(*) AS n FROM admin_login_attempts
              WHERE success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)
              GROUP BY ip_address ORDER BY n DESC LIMIT 5",
            ['h' => $windowHours]
        );
        return [
            'success' => (int) ($row['ok'] ?? 0),
            'failed'  => (int) ($row['fail'] ?? 0),
            'total'   => (int) ($row['total'] ?? 0),
            'top_failing_ips' => $topIps,
        ];
    }
}
