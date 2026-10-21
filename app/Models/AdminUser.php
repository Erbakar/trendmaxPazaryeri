<?php
/** OzyConnect — AdminUser (platform operatörü; merchant users'tan ayrı). @package OzyConnect */
declare(strict_types=1);

namespace App\Models;

final class AdminUser extends BaseModel
{
    protected string $table = 'admin_users';
    protected bool $tenantScoped = false; // platform kimliği — store_id YOK

    /** Tüm admin kullanıcıları rol adıyla (liste ekranı). @return array<int,array<string,mixed>> */
    public function allWithRole(): array
    {
        return $this->db->fetchAll(
            "SELECT au.id, au.name, au.email, au.is_active, au.totp_enabled, au.last_login_at, au.created_at,
                    au.admin_role_id, ar.name AS role_name, ar.is_system AS role_is_system
             FROM admin_users au
             LEFT JOIN admin_roles ar ON ar.id = au.admin_role_id
             ORDER BY au.id ASC"
        );
    }

    /** E-posta zaten kullanımda mı (opsiyonel hariç id)? */
    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $row = $this->db->fetchOne(
            "SELECT id FROM admin_users WHERE email = :e" . ($exceptId ? " AND id <> :x" : "") . " LIMIT 1",
            $exceptId ? ['e' => $email, 'x' => $exceptId] : ['e' => $email]
        );
        return $row !== false;
    }

    /** E-posta ile bul (GLOBAL; admin kimliği tenant-öncesi). */
    public function findByEmail(string $email): array|false
    {
        return $this->db->fetchOne(
            "SELECT * FROM admin_users WHERE email = :e AND is_active = 1 LIMIT 1",
            ['e' => $email]
        );
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function updatePasswordHash(int $adminId, string $newPassword): bool
    {
        return $this->update($adminId, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);
    }

    public function touchLastLogin(int $adminId): void
    {
        $this->update($adminId, ['last_login_at' => date('Y-m-d H:i:s')]);
    }
}
