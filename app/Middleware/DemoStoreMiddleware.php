<?php
/**
 * OzyConnect Panel — Demo Mağaza Middleware
 *
 * Vitrin/demo mağazalarda GET (görüntüleme) serbest; POST/PUT/PATCH/DELETE engellenir.
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;
use App\Services\DemoStoreService;

final class DemoStoreMiddleware
{
    /** Demo modda bile erişilebilen yazma yolları (yok — çıkış GET). */
    private const WRITE_ALLOWLIST = [];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DemoStoreService $demoStore,
        private readonly Response $response,
        private readonly Session $session,
    ) {}

    public function handle(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        if ($storeId <= 0 || !$this->demoStore->isDemoStore($storeId)) {
            return;
        }

        $method = $request->getMethod();
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return;
        }

        $path = $request->getPath();
        foreach (self::WRITE_ALLOWLIST as $allow) {
            if ($path === $allow || str_starts_with($path, $allow . '/')) {
                return;
            }
        }

        $message = 'Bu vitrin hesabında değişiklik yapılamaz. Satış öncesi demo amaçlıdır; yalnızca görüntüleme yapabilirsiniz.';

        if ($request->isAjax() || str_starts_with($path, '/api/')) {
            $this->response->error($message, 403);
        }

        $this->session->flash('warning', $message);
        $referer = $request->server('HTTP_REFERER') ?? '';
        if ($referer !== '' && !str_contains($referer, '/login')) {
            $this->response->redirect($referer);
        }
        $this->response->redirectTo('/dashboard');
    }
}
