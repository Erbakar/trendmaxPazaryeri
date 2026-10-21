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

    /**
     * Yeni şifre sıfırlama token'ı oluştur
     *
     * @return array{token: string, expires_at: string}
     */
    public function createToken(string $email): array
    {
        // Eski token'ları temizle
        $this->deleteExpiredTokens();
        $this->deleteTokensByEmail($email);

        // Güvenli token oluştur
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

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
