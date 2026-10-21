<?php
/**
 * OzyConnect Panel — AdminUpdateController (Platform admin: changelog yönetimi)
 *
 * Admin güncelleme yayınlar/düzenler/taslağa alır/siler. AdminAuthMiddleware arkasında.
 * Merchant tarafı (salt-okunur liste) UpdateController'dadır.
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
use App\Models\AppUpdate;

final class AdminUpdateController
{
    private const TYPES = ['feature', 'fix', 'improvement', 'announcement'];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly AppUpdate $updates;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->updates      = $container->get(AppUpdate::class);
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
        $editId = (int) $request->query('edit', '0');
        $editItem = $editId > 0 ? $this->updates->adminFind($editId) : false;
        $this->render('admin.update_index', [
            'pageTitle' => 'Güncellemeler',
            'updates'   => $this->updates->adminAll(),
            'editItem'  => $editItem ?: null,
        ]);
    }

    public function store(Request $request): void
    {
        $title = trim((string) $request->post('title', ''));
        $body  = trim((string) $request->post('body', ''));
        $type  = (string) $request->post('type', 'announcement');
        $version = trim((string) $request->post('version', ''));
        // İşaretsiz checkbox POST'a hiç eklenmez → varsayılan YOK (null → false = taslak).
        $publish = $request->post('is_published') === '1';
        if (!in_array($type, self::TYPES, true)) {
            $type = 'announcement';
        }
        if ($title === '') {
            $this->session->flash('error', 'Başlık zorunludur');
            $this->response->redirectTo('/admin/updates');
        }
        $this->updates->create([
            'version'      => $version !== '' ? mb_substr($version, 0, 40) : null,
            'title'        => mb_substr($title, 0, 200),
            'body'         => $body !== '' ? $body : null,
            'type'         => $type,
            'is_published' => $publish ? 1 : 0,
            'published_at' => $publish ? date('Y-m-d H:i:s') : null,
            'created_by'   => (int) $this->adminSession->adminId(),
        ]);
        $this->session->flash('success', 'Güncelleme oluşturuldu');
        $this->response->redirectTo('/admin/updates');
    }

    public function update(Request $request, string $id): void
    {
        $item = $this->updates->adminFind((int) $id);
        if ($item === false) {
            $this->session->flash('error', 'Kayıt bulunamadı');
            $this->response->redirectTo('/admin/updates');
        }
        $title = trim((string) $request->post('title', ''));
        $type  = (string) $request->post('type', 'announcement');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'announcement';
        }
        if ($title === '') {
            $this->session->flash('error', 'Başlık zorunludur');
            $this->response->redirectTo('/admin/updates?edit=' . $id);
        }
        $version = trim((string) $request->post('version', ''));
        $body    = trim((string) $request->post('body', ''));
        $this->updates->update((int) $id, [
            'version' => $version !== '' ? mb_substr($version, 0, 40) : null,
            'title'   => mb_substr($title, 0, 200),
            'body'    => $body !== '' ? $body : null,
            'type'    => $type,
        ]);
        $this->session->flash('success', 'Güncelleme düzenlendi');
        $this->response->redirectTo('/admin/updates');
    }

    public function togglePublish(Request $request, string $id): void
    {
        $this->updates->togglePublish((int) $id);
        $this->session->flash('success', 'Yayın durumu değişti');
        $this->response->redirectTo('/admin/updates');
    }

    public function delete(Request $request, string $id): void
    {
        $this->updates->delete((int) $id);
        $this->session->flash('success', 'Güncelleme silindi');
        $this->response->redirectTo('/admin/updates');
    }
}
