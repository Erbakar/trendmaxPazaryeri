<?php
/**
 * OzyConnect Panel — API Base Controller
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

namespace App\Controllers\Api;

use App\Core\Container;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Models\ApiLog;
use App\Models\ApiToken;

abstract class ApiBaseController
{
    protected readonly Response $response;
    protected readonly Logger $logger;
    protected readonly Container $container;
    protected readonly ApiLog $apiLog;
    protected readonly ApiToken $apiTokenModel;
    private float $startTime;

    public function __construct(Container $container)
    {
        $this->container     = $container;
        $this->response      = $container->get(Response::class);
        $this->logger        = $container->get(Logger::class);
        $this->apiLog        = $container->get(ApiLog::class);
        $this->apiTokenModel = $container->get(ApiToken::class);
        $this->startTime     = microtime(true);

        // CORS headers
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-Key, X-API-Secret, Content-Type, Accept');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Aktif API token
     *
     * @return array<string, mixed>
     */
    protected function apiToken(): array
    {
        return $GLOBALS['_api_token'] ?? [];
    }

    /**
     * İzin kontrolü — yoksa 403
     */
    protected function requirePermission(string $permission): void
    {
        $token = $this->apiToken();
        if (!$this->apiTokenModel->hasPermission($token, $permission)) {
            $this->apiError('Bu işlem için yetkiniz yok: ' . $permission, 'FORBIDDEN', 403);
        }
    }

    /**
     * Faz 2 — API store-entitlement guard (modül). Token'ın store_id'sinden tenant bağlamı
     * kurulur (R3 ön ödemesi) ve mağaza bu modülü almamışsa 403. ("Menüde yok ≠ güvenlik" —
     * API katmanında da bağımsız zorlanır.)
     */
    protected function requireModuleAccess(string $module): void
    {
        $storeId = (int) ($this->apiToken()['store_id'] ?? 0);
        if ($storeId <= 0) {
            $this->apiError('API token mağaza bağlamı taşımıyor', 'FORBIDDEN', 403);
        }
        $this->container->get(\App\Core\TenantContext::class)->setStoreId($storeId);
        if (!$this->container->get(\App\Services\EntitlementService::class)->canAccessModule($module)) {
            $this->apiError('Mağazanız bu modülü kullanma yetkisine sahip değil: ' . $module, 'FORBIDDEN', 403);
        }
    }

    /**
     * Başarılı API yanıtı
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function apiSuccess(array $data = [], string $message = 'Başarılı', array $meta = []): never
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        $this->logAndRespond(200, $payload);
    }

    /**
     * Sayfalı API yanıtı
     *
     * @param array<int, array<string, mixed>> $data
     */
    protected function apiPaginated(array $data, int $page, int $perPage, int $total): never
    {
        $totalPages = (int) ceil($total / max(1, $perPage));

        $payload = [
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
                'has_next'    => $page < $totalPages,
                'has_prev'    => $page > 1,
            ],
        ];

        $this->logAndRespond(200, $payload);
    }

    /**
     * Hata API yanıtı
     */
    protected function apiError(string $message, string $code = 'ERROR', int $status = 400): never
    {
        $payload = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        $this->logAndRespond($status, $payload);
    }

    /**
     * 404
     */
    protected function apiNotFound(string $message = 'Kayıt bulunamadı'): never
    {
        $this->apiError($message, 'NOT_FOUND', 404);
    }

    /**
     * Validation hatası
     *
     * @param array<string, string> $errors
     */
    protected function apiValidationError(array $errors): never
    {
        $payload = [
            'success' => false,
            'error'   => [
                'code'    => 'VALIDATION_ERROR',
                'message' => 'Doğrulama hatası',
                'details' => $errors,
            ],
        ];

        $this->logAndRespond(422, $payload);
    }

    /**
     * Request'ten JSON body parse et
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(Request $request): array
    {
        $body = $request->json();
        return is_array($body) ? $body : [];
    }

    /**
     * Sayfa ve limit parametrelerini al
     *
     * @return array{page: int, per_page: int}
     */
    protected function paginationParams(Request $request): array
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = min(100, max(1, $request->integer('per_page', 25)));

        return ['page' => $page, 'per_page' => $perPage];
    }

    /**
     * Yanıtı logla ve gönder
     *
     * @param array<string, mixed> $payload
     */
    private function logAndRespond(int $status, array $payload): never
    {
        $elapsed = (int) ((microtime(true) - $this->startTime) * 1000);
        $token = $this->apiToken();

        try {
            $this->apiLog->logRequest(
                !empty($token['id']) ? (int) $token['id'] : null,
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $_SERVER['REQUEST_URI'] ?? '',
                $status,
                $elapsed,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                null,
                mb_substr(json_encode($payload['error'] ?? $payload['message'] ?? '', JSON_UNESCAPED_UNICODE), 0, 500)
            );
        } catch (\Throwable) {
            // Log hatası API yanıtını engellemez
        }

        $this->response->json($payload, $status);
    }
}
