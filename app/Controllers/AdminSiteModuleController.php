<?php
/**
 * OzyConnect Panel — AdminSiteModuleController (Faz 9: kurumsal site "Modüller" kartları)
 *
 * GLOBAL site_modules CRUD: icon[lucide adı]/color[#hex]/title/description/sort_order/is_active.
 * Görsel YOK — lucide ikon adıyla render edilir. Kurumsal site activeOrdered() ile çeker.
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
use App\Models\SiteModule;

final class AdminSiteModuleController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly SiteModule $moduleModel;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->moduleModel  = $container->get(SiteModule::class);
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
        $this->render('admin.site_modules_index', [
            'pageTitle' => 'Site Modülleri',
            'modules'   => $this->moduleModel->allAdmin(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.site_modules_form', [
            'pageTitle' => 'Yeni Modül',
            'module'    => null,
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validated($request);
        if ($data === null) {
            $this->response->redirectTo('/admin/site-modules/create');
            return;
        }
        $this->moduleModel->createOne($data);
        $this->session->flash('success', 'Modül oluşturuldu.');
        $this->response->redirectTo('/admin/site-modules');
    }

    public function edit(Request $request, string $id): void
    {
        $module = $this->moduleModel->findOne((int) $id);
        if ($module === false) {
            $this->session->flash('error', 'Modül bulunamadı.');
            $this->response->redirectTo('/admin/site-modules');
            return;
        }
        $this->render('admin.site_modules_form', [
            'pageTitle' => 'Modül Düzenle',
            'module'    => $module,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $mid = (int) $id;
        if ($this->moduleModel->findOne($mid) === false) {
            $this->session->flash('error', 'Modül bulunamadı.');
            $this->response->redirectTo('/admin/site-modules');
            return;
        }
        $data = $this->validated($request);
        if ($data === null) {
            $this->response->redirectTo('/admin/site-modules/' . $mid . '/edit');
            return;
        }
        $this->moduleModel->updateOne($mid, $data);
        $this->session->flash('success', 'Modül güncellendi.');
        $this->response->redirectTo('/admin/site-modules');
    }

    public function delete(Request $request, string $id): void
    {
        $mid = (int) $id;
        if ($this->moduleModel->findOne($mid) === false) {
            $this->session->flash('error', 'Modül bulunamadı.');
            $this->response->redirectTo('/admin/site-modules');
            return;
        }
        $this->moduleModel->softDelete($mid);
        $this->session->flash('success', 'Modül silindi.');
        $this->response->redirectTo('/admin/site-modules');
    }

    /**
     * Form girdilerini doğrula + normalize et. Hata varsa flash bırakıp null döner.
     *
     * @return array<string,mixed>|null
     */
    private function validated(Request $request): ?array
    {
        $title = trim((string) $request->post('title', ''));
        if ($title === '') {
            $this->session->flash('error', 'Başlık zorunludur.');
            return null;
        }

        // Lucide ikon adı: harf/rakam/tire; boşsa varsayılan kullan.
        $icon = strtolower(trim((string) $request->post('icon', '')));
        $icon = (string) preg_replace('/[^a-z0-9\-]/', '', $icon);
        if ($icon === '') {
            $icon = 'box';
        }

        // Renk: #rrggbb'e normalize; geçersizse marka mavisi.
        $color = trim((string) $request->post('color', ''));
        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) {
            $color = '#' . strtolower(ltrim($color, '#'));
        } else {
            $color = '#2563eb';
        }

        $description = trim((string) $request->post('description', ''));
        $description = $description === '' ? null : mb_substr($description, 0, 1000);

        return [
            'icon'        => mb_substr($icon, 0, 64),
            'color'       => $color,
            'title'       => mb_substr($title, 0, 191),
            'description' => $description,
            'sort_order'  => max(0, (int) $request->post('sort_order', 0)),
            'is_active'   => $request->post('is_active') !== null ? 1 : 0,
        ];
    }
}
