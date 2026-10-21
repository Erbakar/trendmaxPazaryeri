<?php
/**
 * OzyConnect Panel — CSRF Middleware
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
use App\Core\Session;

final class CsrfMiddleware
{
    public function __construct(
        private readonly Session $session,
        private readonly Response $response,
    ) {}

    public function handle(Request $request): void
    {
        // Sadece POST/PUT/DELETE isteklerinde kontrol et
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return;
        }

        $token = $request->post('_csrf_token')
            ?? $request->server('HTTP_X_CSRF_TOKEN');

        if ($token === null || !$this->session->verifyCsrfToken($token)) {
            if ($request->isAjax()) {
                $this->response->error('CSRF token geçersiz. Sayfayı yenileyip tekrar deneyin.', 403);
            }

            $this->session->flash('error', 'Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.');
            $this->response->redirectTo('/');
        }
    }
}
