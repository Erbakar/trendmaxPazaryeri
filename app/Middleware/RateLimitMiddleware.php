<?php
/**
 * OzyConnect Panel — Rate Limit Middleware
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

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class RateLimitMiddleware
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly Database $db,
        private readonly Response $response,
    ) {}

    public function handle(Request $request): void
    {
        $ip = $request->ip();
        $cutoff = date('Y-m-d H:i:s', time() - (self::LOCKOUT_MINUTES * 60));

        // Son X dakikadaki başarısız deneme sayısı
        $stmt = $this->db->query(
            'SELECT COUNT(*) as cnt FROM login_attempts
             WHERE ip_address = :ip AND attempted_at > :cutoff AND is_success = 0',
            ['ip' => $ip, 'cutoff' => $cutoff]
        );

        $row = $stmt->fetch();
        $attempts = (int) ($row['cnt'] ?? 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            if ($request->isAjax()) {
                $this->response->error(
                    'Çok fazla başarısız giriş denemesi. Lütfen ' . self::LOCKOUT_MINUTES . ' dakika bekleyin.',
                    429
                );
            }

            http_response_code(429);
            echo '<h1>Çok Fazla Deneme</h1>';
            echo '<p>IP adresiniz geçici olarak engellenmiştir. Lütfen ' . self::LOCKOUT_MINUTES . ' dakika sonra tekrar deneyin.</p>';
            exit;
        }
    }

    /**
     * Giriş denemesini kaydet
     */
    public function recordAttempt(string $ip, string $email, bool $success): void
    {
        $this->db->query(
            'INSERT INTO login_attempts (ip_address, email, is_success, attempted_at) VALUES (:ip, :email, :success, NOW())',
            ['ip' => $ip, 'email' => $email, 'success' => $success ? 1 : 0]
        );
    }

    /**
     * Eski kayıtları temizle (cron'dan çağrılır)
     */
    public function cleanOldAttempts(): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - 86400); // 24 saat öncesi
        $stmt = $this->db->query(
            'DELETE FROM login_attempts WHERE attempted_at < :cutoff',
            ['cutoff' => $cutoff]
        );
        return $stmt->rowCount();
    }
}
