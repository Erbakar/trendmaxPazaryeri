<?php
/**
 * OzyConnect Panel — AdminSession (merchant Session'dan TAMAMEN AYRI namespace)
 *
 * Admin oturumu $_SESSION['admin'] altında; merchant oturumu ($_SESSION['user_id'])
 * ile karışmaz. isAuthenticated() YALNIZ 2FA tamamlanmışsa true (2FA atlanamaz).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Core;

final class AdminSession
{
    /** Parola doğrulandı; 2FA BEKLENİYOR (henüz yetkili değil). */
    public function startPending(array $admin): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // fixation koruması
        }
        $_SESSION['admin'] = [
            'id'       => (int) $admin['id'],
            'email'    => (string) ($admin['email'] ?? ''),
            'name'     => (string) ($admin['name'] ?? ''),
            '2fa'      => false,
            'login_at' => time(),
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
    }

    /** 2FA tamamlandı → tam yetkili admin oturumu. */
    public function complete2fa(): void
    {
        if (isset($_SESSION['admin'])) {
            $_SESSION['admin']['2fa'] = true;
        }
    }

    /** Parola geçti ama 2FA bekliyor mu? */
    public function isPending2fa(): bool
    {
        return isset($_SESSION['admin']['id']) && ($_SESSION['admin']['2fa'] ?? false) !== true;
    }

    /** TAM yetkili admin mi (2FA dahil)? Merchant session burada DAİMA false. */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['admin']['id'])
            && (int) $_SESSION['admin']['id'] > 0
            && ($_SESSION['admin']['2fa'] ?? false) === true;
    }

    public function adminId(): ?int
    {
        return isset($_SESSION['admin']['id']) ? (int) $_SESSION['admin']['id'] : null;
    }

    public function adminEmail(): ?string
    {
        return $_SESSION['admin']['email'] ?? null;
    }

    /** Admin çıkışı — YALNIZ admin namespace temizlenir (merchant oturumu etkilenmez). */
    public function logout(): void
    {
        unset($_SESSION['admin']);
    }
}
