<?php
/**
 * OzyConnect Panel — PasswordReset Model
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

final class PasswordReset extends BaseModel
{
    protected string $table = 'password_resets';
    protected bool $tenantScoped = false; // GLOBAL: e-posta ile sifirlama (tenant-oncesi)

    /**
     * Yeni şifre sıfırlama token'ı oluştur
     *
     * @return array{token: string, expires_at: string}
     */
    /**
     * Şifre belirleme bağlantısının ömrü (dakika).
     *
     * Ayar okunamazsa 24 saat kullanılır; bağlantının erken ölmesi
     * kullanıcıyı hatalı-şifre hatasına düşürdüğü için varsayılan geniş
     * tutulur. Alt sınır 15 dakika, üst sınır 7 gündür.
     */
    private function tokenLifetimeMinutes(): int
    {
        $dakika = 1440;

        try {
            $deger = $this->db->fetchOne(
                "SELECT `value` FROM `settings` WHERE `key` = :k LIMIT 1",
                ['k' => 'password_reset_lifetime_minutes']
            );

            if ($deger !== false && is_numeric($deger['value'] ?? null)) {
                $dakika = (int) $deger['value'];
            }
        } catch (\Throwable) {
            // Ayar tablosu yoksa varsayılanla devam
        }

        return max(15, min(10080, $dakika));
    }

    public function createToken(string $email): array
    {
        // Eski token'ları temizle
        $this->deleteExpiredTokens();
        $this->deleteTokensByEmail($email);

        // Güvenli token oluştur
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        // Bağlantının ömrü ayardan gelir. 15 dakika sabitken firma e-postayı
        // açana kadar bağlantı ölüyor, şifre belirleme sessizce başarısız
        // oluyor ve giriş "e-posta veya şifre hatalı" veriyordu.
        $expiresAt = date('Y-m-d H:i:s', time() + $this->tokenLifetimeMinutes() * 60);

        $this->create([
            'email'      => $email,
            'token_hash' => $tokenHash,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token'      => $rawToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Token doğrula — geçerli ve süresi dolmamış mı
     *
     * @return array<string, mixed>|false
     */
    public function verifyToken(string $token): array|false
    {
        $tokenHash = hash('sha256', $token);

        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table}`
             WHERE `token_hash` = :token_hash
               AND `expires_at` > NOW()
             LIMIT 1",
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * E-posta'ya ait tüm token'ları sil
     */
    public function deleteTokensByEmail(string $email): void
    {
        $this->db->query(
            "DELETE FROM `{$this->table}` WHERE `email` = :email",
            ['email' => $email]
        );
    }

    /**
     * Token hash ile sil
     */
    public function deleteByTokenHash(string $tokenHash): void
    {
        $this->db->query(
            "DELETE FROM `{$this->table}` WHERE `token_hash` = :token_hash",
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * Süresi dolmuş tüm token'ları temizle
     */
    public function deleteExpiredTokens(): void
    {
        $this->db->query(
            "DELETE FROM `{$this->table}` WHERE `expires_at` < NOW()"
        );
    }

    /**
     * Belirli e-posta için son X dakikada kaç token oluşturulmuş (rate limiting)
     */
    public function recentAttemptCount(string $email, int $minutes = 15): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM `{$this->table}`
             WHERE `email` = :email
               AND `created_at` > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
            ['email' => $email, 'minutes' => $minutes]
        );

        return (int) ($row['cnt'] ?? 0);
    }
}
