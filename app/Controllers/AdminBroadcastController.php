<?php
/**
 * OzyConnect Panel — AdminBroadcastController (İletişim: mağazalara anlık in-app bildirim)
 *
 * Seçili (veya tüm) mağazaların paneline anlık bildirim gönderir (Notification::push → bildirim zili).
 * Pattern A: BaseController yok, AdminSession self-guard. AdminAuthMiddleware route'ta.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Notification;

final class AdminBroadcastController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Database $db;
    private readonly Notification $notifications;

    public function __construct(Container $container)
    {
        $this->response      = $container->get(Response::class);
        $this->session       = $container->get(Session::class);
        $this->adminSession  = $container->get(AdminSession::class);
        $this->db            = $container->get(Database::class);
        $this->notifications = $container->get(Notification::class);
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
        $this->render('admin.broadcast', [
            'pageTitle' => 'Bildirim Gönder',
            'stores'    => $this->db->fetchAll("SELECT id, name FROM stores WHERE status <> 'archived' ORDER BY name ASC"),
            'recent'    => $this->db->fetchAll(
                "SELECT n.id, n.title, n.type, n.created_at, s.name AS store_name
                 FROM notifications n LEFT JOIN stores s ON s.id = n.store_id
                 ORDER BY n.id DESC LIMIT 25"
            ),
        ]);
    }

    public function send(Request $request): void
    {
        $ids = array_map('intval', (array) $request->post('store_ids', []));
        $ids = array_values(array_filter($ids, static fn($i) => $i > 0));
        $title = trim((string) $request->post('title', ''));
        $body  = trim((string) $request->post('body', ''));
        $link  = trim((string) $request->post('link', ''));

        if ($title === '') {
            $this->session->flash('error', 'Bildirim başlığı zorunludur.');
            $this->response->redirectTo('/admin/broadcast');
            return;
        }
        if ($ids === []) {
            $this->session->flash('error', 'En az bir mağaza seçin.');
            $this->response->redirectTo('/admin/broadcast');
            return;
        }
        $valid = array_map('intval', array_column(
            $this->db->fetchAll("SELECT id FROM stores WHERE status <> 'archived'"), 'id'
        ));
        $sent = 0;
        foreach ($ids as $sid) {
            if (!in_array($sid, $valid, true)) { continue; }
            try { $this->notifications->push($sid, 'system', $title, $body, $link); $sent++; }
            catch (\Throwable) { /* tek mağaza hatası akışı bozmaz */ }
        }
        $this->session->flash($sent > 0 ? 'success' : 'error', $sent . ' mağazaya anlık bildirim gönderildi.');
        $this->response->redirectTo('/admin/broadcast');
    }
}
