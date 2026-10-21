<?php
/**
 * OzyConnect Panel — Session
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

namespace App\Core;

final class Session
{
    /**
     * Session değeri oku
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Session değeri yaz
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Session değeri sil
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Anahtar var mı kontrol et
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Flash mesaj yaz (bir sonraki istekte okunup silinir)
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Flash mesaj oku ve sil
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Kullanıcı giriş yapmış mı
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
    }

    /**
     * Giriş yapan kullanıcı ID'si
     */
    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Giriş yapan kullanıcı rolü
     */
    public function userRole(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    /**
     * Giriş yapan kullanıcının aktif mağazası (D1: 1 kullanıcı = 1 mağaza).
     */
    public function storeId(): ?int
    {
        return isset($_SESSION['store_id']) ? (int) $_SESSION['store_id'] : null;
    }

    /**
     * Aktif mağazayı session'a yaz (ileride çok-mağaza için seçici de buraya yazar).
     */
    public function setStoreId(int $storeId): void
    {
        $_SESSION['store_id'] = $storeId;
    }

    public function userRoleId(): ?int
    {
        return isset($_SESSION['user_role_id']) ? (int) $_SESSION['user_role_id'] : null;
    }

    public function userRoleName(): ?string
    {
        return $_SESSION['user_role_name'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function userPermissions(): array
    {
        $permissions = $_SESSION['user_permissions'] ?? [];
        return is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];
    }

    /**
     * Admin mi (mağaza/tenant admini — platform Super Admin DEĞİL)
     */
    public function isAdmin(): bool
    {
        return $this->userRole() === 'admin';
    }

    // NOT: Faz 3'te interim `isSuperAdmin()` KALDIRILDI. Platform admin yetkisi artık
    // AdminSession (admin_users) üzerinden; merchant Session admin yetkisi taşımaz.

    /**
     * Login bilgilerini session'a yaz + session ID regenerate
     *
     * @param array<string, mixed> $user
     */
    public function login(array $user): void
    {
        // Session fixation koruması: ÖNCE regenerate, SONRA veri yaz (yalnız aktif session'da)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        
        $permissions = $user['permissions'] ?? json_decode((string) ($user['permissions_json'] ?? '[]'), true);
        if (!is_array($permissions)) {
            $permissions = [];
        }

        $_SESSION['user_id']          = (int) $user['id'];
        $_SESSION['user_name']        = (string) ($user['name'] ?? '');
        $_SESSION['user_email']       = (string) ($user['email'] ?? '');
        $_SESSION['user_role']        = (string) ($user['role_slug'] ?? $user['role'] ?? '');
        $_SESSION['user_role_id']     = isset($user['role_id']) ? (int) $user['role_id'] : null;
        $_SESSION['user_role_name']   = (string) ($user['role_name'] ?? $user['role'] ?? '');
        $_SESSION['user_permissions'] = array_values(array_map('strval', $permissions));
        $_SESSION['login_at']         = time();
        $_SESSION['user_ip']          = $_SERVER['REMOTE_ADDR'] ?? '';
        // D1: 1 kullanıcı = 1 mağaza. Aktif mağaza login'de sabitlenir.
        if (isset($user['store_id'])) {
            $_SESSION['store_id'] = (int) $user['store_id'];
        }
        // (Faz 3: is_super_admin KALDIRILDI — admin yetkisi AdminSession/admin_users'ta.)
    }

    /**
     * Çıkış yap
     */
    public function logout(): void
    {
        // Faz 3 — AYRIM: yalnız MERCHANT anahtarlarını temizle; admin namespace (varsa) KORUNUR.
        foreach (['user_id','user_name','user_email','user_role','user_role_id','user_role_name',
                  'user_permissions','login_at','user_ip','store_id'] as $k) {
            unset($_SESSION[$k]);
        }

        // Admin oturumu yoksa tam temizlik (eski davranış); varsa admin korunsun diye yalnız regenerate.
        if (empty($_SESSION['admin'])) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Session IP doğrulaması — session hijacking koruması
     * Login IP'si ile mevcut IP farklıysa session geçersiz sayılır.
     */
    public function validateSession(): bool
    {
        if (!$this->isAuthenticated()) {
            return true; // Giriş yapmamış, kontrol gereksiz
        }

        $loginIp = $_SESSION['user_ip'] ?? '';
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';

        // IP kaydı yoksa (eski session), şimdi kaydet ve geç
        if ($loginIp === '') {
            $_SESSION['user_ip'] = $currentIp;
            return true;
        }

        if ($loginIp !== $currentIp) {
            // IP değişti — session hijacking olabilir
            $this->logout();
            return false;
        }

        return true;
    }

    /**
     * CSRF token oluştur/getir
     */
    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * CSRF token doğrula
     */
    public function verifyCsrfToken(string $token): bool
    {
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        if ($sessionToken === '' || $token === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}
