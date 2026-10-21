<?php
/**
 * OzyConnect Panel — AdminModuleCatalogController (Faz 9: satılabilir modül kataloğu + fiyatlar)
 *
 * GLOBAL module_catalog: her modül için fiyat (price_monthly), satılabilirlik (is_sellable),
 * görünen ad (label) güncellenir. Bu fiyatlar mağazaların "Modül Ekle" ekranında add-on olarak görünür.
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
use App\Models\ModuleCatalog;

final class AdminModuleCatalogController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly ModuleCatalog $moduleCatalog;

    public function __construct(Container $container)
    {
        $this->response      = $container->get(Response::class);
        $this->session       = $container->get(Session::class);
        $this->adminSession  = $container->get(AdminSession::class);
        $this->moduleCatalog = $container->get(ModuleCatalog::class);
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
        $this->render('admin.module_catalog', [
            'pageTitle' => 'Satılabilir Modüller',
            'modules'   => $this->moduleCatalog->allAdmin(),
        ]);
    }

    public function update(Request $request, string $key): void
    {
        $price    = (float) $request->post('price_monthly', 0);
        $sellable = $request->post('is_sellable') === '1';
        $label    = trim((string) $request->post('label', ''));

        $this->moduleCatalog->updateOne($key, $price, $sellable, $label !== '' ? $label : null);

        $this->session->flash('success', 'Modül kaydedildi.');
        $this->response->redirectTo('/admin/module-catalog');
    }
}
