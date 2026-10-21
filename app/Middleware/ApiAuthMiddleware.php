<?php
/**
 * OzyConnect Panel — API Auth Middleware
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
use App\Core\TenantContext;
use App\Models\ApiToken;

final class ApiAuthMiddleware
{
    public function __construct(
        private readonly ApiToken $tokenModel,
        private readonly Response $response,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request): void
    {
        $apiKey = $request->server('HTTP_X_API_KEY') ?? '';
        $apiSecret = $request->server('HTTP_X_API_SECRET') ?? '';

        if ($apiKey === '' || $apiSecret === '') {
            $this->response->json([
                'success' => false,
                'error'   => [
                    'code'    => 'MISSING_CREDENTIALS',
                    'message' => 'X-API-Key ve X-API-Secret header\'ları gereklidir.',
                ],
            ], 401);
        }

        $token = $this->tokenModel->verifyCredentials($apiKey, $apiSecret);

        if ($token === false) {
            $this->response->json([
                'success' => false,
                'error'   => [
                    'code'    => 'INVALID_CREDENTIALS',
                    'message' => 'Geçersiz API anahtarı veya süresi dolmuş.',
                ],
            ], 401);
        }

        // IP whitelist kontrolü
        $ip = $request->ip();
        if (!$this->tokenModel->isIpAllowed($token, $ip)) {
            $this->response->json([
                'success' => false,
                'error'   => [
                    'code'    => 'IP_NOT_ALLOWED',
                    'message' => 'Bu IP adresi için erişim izni yok.',
                ],
            ], 403);
        }

        // Son kullanım zamanını güncelle
        $this->tokenModel->updateLastUsed((int) $token['id']);

        // Faz 4 (R3) — Token → TenantContext. Token'ın store_id'si yoksa FAIL-CLOSED.
        $storeId = (int) ($token['store_id'] ?? 0);
        if ($storeId <= 0) {
            $this->response->json([
                'success' => false,
                'error'   => ['code' => 'NO_STORE_CONTEXT', 'message' => 'API token bir mağazaya bağlı değil.'],
            ], 403);
        }
        $this->tenant->setStoreId($storeId); // bundan sonra tüm tenant-scoped sorgular bu mağazaya kapanır

        // Token bilgisini request'e ekle (controller'larda kullanmak için)
        $GLOBALS['_api_token'] = $token;
    }
}
