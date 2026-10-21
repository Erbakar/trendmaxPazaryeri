<?php
/**
 * OzyConnect Panel — AdminAuthMiddleware (Faz 3)
 *
 * Korumalı /admin/* rotaları: TAM yetkili admin (AdminSession.isAuthenticated = 2FA dahil)
 * değilse giriş/2FA'ya yönlendirir. Merchant oturumu burada yetki VERMEZ.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Core\AdminSession;
use App\Core\Request;
use App\Core\Response;

final class AdminAuthMiddleware
{
    public function __construct(
        private readonly AdminSession $adminSession,
        private readonly Response $response,
    ) {}

    public function handle(Request $request): void
    {
        if ($this->adminSession->isAuthenticated()) {
            return;
        }
        if ($request->isAjax()) {
            $this->response->error('Admin oturumu gerekli', 401);
        }
        // Parola geçti ama 2FA bekliyorsa 2FA'ya; yoksa girişe.
        $this->response->redirectTo($this->adminSession->isPending2fa() ? '/admin/2fa' : '/admin/login');
    }
}
