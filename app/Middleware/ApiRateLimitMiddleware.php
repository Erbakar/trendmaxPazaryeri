<?php
/**
 * OzyConnect Panel — API Rate Limit Middleware
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

use App\Core\Request;
use App\Core\Response;
use App\Models\ApiLog;

final class ApiRateLimitMiddleware
{
    public function __construct(
        private readonly ApiLog $logModel,
        private readonly Response $response,
    ) {}

    public function handle(Request $request): void
    {
        $token = $GLOBALS['_api_token'] ?? null;
        if ($token === null) {
            return; // ApiAuthMiddleware çalışmadıysa geç
        }

        $rateLimit = (int) ($token['rate_limit'] ?? 60);
        $tokenId = (int) $token['id'];

        $recentCount = $this->logModel->getRecentRequestCount($tokenId, 1);

        if ($recentCount >= $rateLimit) {
            header("X-RateLimit-Limit: {$rateLimit}");
            header('X-RateLimit-Remaining: 0');
            header('Retry-After: 60');

            $this->response->json([
                'success' => false,
                'error'   => [
                    'code'    => 'RATE_LIMIT_EXCEEDED',
                    'message' => "Rate limit aşıldı. Dakikada maksimum {$rateLimit} istek yapabilirsiniz.",
                ],
            ], 429);
        }

        // Rate limit header'ları
        $remaining = max(0, $rateLimit - $recentCount - 1);
        header("X-RateLimit-Limit: {$rateLimit}");
        header("X-RateLimit-Remaining: {$remaining}");
    }
}
