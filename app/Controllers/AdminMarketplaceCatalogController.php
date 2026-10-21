<?php
/**
 * OzyConnect Panel — AdminMarketplaceCatalogController (Faz 11: satılabilir pazaryeri kataloğu + fiyatlar)
 *
 * GLOBAL marketplaces: her pazaryeri için add-on aylık fiyatı (price_monthly) ve satılabilirlik (is_sellable).
 * Modül kataloğunun pazaryeri karşılığı. Bu fiyatlar mağaza abonelik add-on akışında kullanılır.
 * AdminAuthMiddleware + CsrfMiddleware (POST) route'ta uygulanır (Pattern A: BaseController yok).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Marketplace;

final class AdminMarketplaceCatalogController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Marketplace $marketplaces;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->marketplaces = $container->get(Marketplace::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    public function index(Request $request): void
    {
        $this->render('admin.marketplace_catalog', [
            'pageTitle'    => 'Satılabilir Pazaryerleri',
            'marketplaces' => $this->marketplaces->allCatalog(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $price    = (float) $request->post('price_monthly', 0);
        $sellable = $request->post('is_sellable') === '1';

        $this->marketplaces->updateCatalog((int) $id, $price, $sellable);

        $this->session->flash('success', 'Pazaryeri kaydedildi.');
        $this->response->redirectTo('/admin/marketplace-catalog');
    }
}
