<?php
/**
 * OzyConnect Panel — User Model
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User extends BaseModel
{
    protected string $table = 'users';

    /**
     * E-posta ile rol bilgileriyle birlikte kullanıcı bul
     *
     * @return array<string, mixed>|false
     */
    public function findAuthUserByEmail(string $email): array|false
    {
        // KASITLI GLOBAL (tenant-oncesi kimlik cozumu): login store_id kurulmadan once
        // calisir; users.email GLOBAL UNIQUE (D1). store_id ile FILTRELENMEZ.
        return $this->db->fetchOne(
            "SELECT u.*,
                    r.name AS role_name,
                    r.slug AS role_slug,
                    r.permissions_json
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1",
            ['email' => $email]
        );
    }

    /**
     * ID ile rol bilgileriyle birlikte kullanıcı bul
     *
     * @return array<string, mixed>|false
     */
    public function findAuthUserById(int $userId): array|false
    {
        // KASITLI GLOBAL (oturum kimligi yenileme): auth-internal, kullanici-girdisi degil.
        return $this->db->fetchOne(
            "SELECT u.*,
                    r.name AS role_name,
                    r.slug AS role_slug,
                    r.permissions_json
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1",
            ['id' => $userId]
        );
    }

    /**
     * E-posta ile kullanıcı bul
     *
     * @return array<string, mixed>|false
     */
    public function findByEmail(string $email): array|false
    {
        return $this->findBy(['email' => $email]);
    }

    /**
     * E-posta ile kullanıcı bul — GLOBAL (tenant filtresiz)
     *
     * KASITLI GLOBAL (tenant-oncesi sifre sifirlama): /password/forgot ve
     * /password/reset istekleri OTURUMSUZ gelir, TenantContext kurulmamistir.
     * users.email TUM magazalarda benzersizdir (D1, UNIQUE KEY) -> store_id
     * ile filtrelenMEZ. Desen: findAuthUserByEmail / emailExists ile ayni.
     *
     * @return array<string, mixed>|false
     */
    public function findByEmailGlobal(string $email): array|false
    {
        return $this->db->fetchOne(
            "SELECT * FROM `users` WHERE `email` = :email LIMIT 1",
            ['email' => $email]
        );
    }

    /**
     * Şifre güncelle — GLOBAL (tenant filtresiz)
     *
     * KASITLI GLOBAL (tenant-oncesi sifre sifirlama): kimlik dogrulamasi
     * PasswordResetService'teki token kontrolunden gelir; id, ayni istekte
     * findByEmailGlobal ile bulunan kullanicidir. BaseModel::update
     * kullanilamaz cunku tenant-scoped modelde store_id kosulu ekler ve
     * oturumsuz istekte TenantContextException firlatir.
     */
    public function updatePasswordGlobal(int $userId, string $newPassword): bool
    {
        $stmt = $this->db->query(
            "UPDATE `users`
                SET `password_hash` = :password_hash
              WHERE `id` = :id
              LIMIT 1",
            [
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                'id'            => $userId,
            ]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Kullanıcı listesini rol bilgileriyle getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function allWithRoles(): array
    {
        // TENANT-SCOPED: yalnizca aktif magazanin kullanicilari listelenir (cross-tenant sizinti kapatildi).
        return $this->db->fetchAll(
            "SELECT u.*, r.name AS role_name, r.slug AS role_slug
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.store_id = :store_id
             ORDER BY u.is_active DESC, u.name ASC",
            ['store_id' => $this->tenantId()]
        );
    }

    /**
     * Tek kullanıcıyı rol bilgileriyle getir
     *
     * @return array<string, mixed>|false
     */
    public function findWithRole(int $userId): array|false
    {
        // TENANT-SCOPED: baska magazanin kullanicisi id ile cekilemez (cross-tenant red).
        return $this->db->fetchOne(
            "SELECT u.*, r.name AS role_name, r.slug AS role_slug
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.store_id = :store_id
             LIMIT 1",
            ['id' => $userId, 'store_id' => $this->tenantId()]
        );
    }

    public function emailExists(string $email, ?int $ignoreId = null): bool
    {
        // KASITLI GLOBAL: e-posta TUM magazalarda benzersiz (D1) -> store_id ile filtrelenMEZ.
        $row = $this->db->fetchOne(
            "SELECT id
             FROM users
             WHERE email = :email
               AND (:ignore_id IS NULL OR id <> :ignore_id)
             LIMIT 1",
            [
                'email' => $email,
                'ignore_id' => $ignoreId,
            ]
        );

        return $row !== false;
    }

    /**
     * Şifre doğrula
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Yeni kullanıcı oluştur
     *
     * @param array<string, mixed> $data
     */
    public function createUser(array $data): int
    {
        $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        unset($data['password']);

        return $this->create($data);
    }

    /**
     * Şifre güncelle
     */
    public function updatePassword(int $userId, string $newPassword): bool
    {
        return $this->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);
    }

    /**
     * Son giriş zamanını güncelle
     */
    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Aktif kullanıcıları getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        return $this->all(['is_active' => 1], 'name ASC');
    }
}
