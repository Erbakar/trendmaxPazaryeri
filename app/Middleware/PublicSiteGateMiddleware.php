<?php
/**
 * OzyConnect — Kurumsal site geçici kapısı
 *
 * public_site_enabled=false iken kurumsal sayfalar /login'e yönlendirilir.
 * Giriş, kayıt (magaza-olustur) ve panel rotaları etkilenmez.
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

final class PublicSiteGateMiddleware
{
    public function __construct(
        private readonly Container $container,
        private readonly Response $response,
    ) {}

    public function handle(Request $request): void
    {
        if ($this->container->config('public_site_enabled', false) === true) {
            return;
        }

        $this->response->redirectTo('/login');
    }
}
