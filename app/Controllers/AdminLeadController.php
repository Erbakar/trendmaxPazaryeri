<?php
/**
 * OzyConnect Panel — AdminLeadController (Faz 10: Gelen Kutusu — Leads)
 *
 * Demo/iletişim/başvuru formlarından gelen kayıtlar. Liste (type/status filtre + statusCounts),
 * durum değiştirme (new/contacted/closed) ve gerçek silme (leads geçici). Global `leads` tablosu
 * (tenant-scoped DEĞİL). AdminAuthMiddleware arkasında (Pattern A: BaseController yok, AdminSession self-guard).
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
use App\Models\Lead;

final class AdminLeadController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Lead $leads;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->leads        = $container->get(Lead::class);
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
        $type   = trim((string) $request->query('type', ''));
        $status = trim((string) $request->query('status', ''));
        if (!in_array($type, ['demo', 'contact', 'store_signup'], true)) {
            $type = '';
        }
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            $status = '';
        }
        $this->render('admin.leads_index', [
            'pageTitle'    => 'Gelen Kutusu',
            'leads'        => $this->leads->adminList(null, null), // tümü; filtre client-side (DataTables)
            'currentType'  => $type,
            'currentStatus' => $status,
            'counts'       => $this->leads->statusCounts(),
        ]);
    }

    public function status(Request $request, string $id): void
    {
        $status = trim((string) $request->post('status', ''));
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            $this->session->flash('error', 'Geçersiz durum.');
            $this->response->redirectTo('/admin/leads');
            return;
        }
        $this->leads->setStatus((int) $id, $status);
        $this->session->flash('success', 'Durum güncellendi.');
        $this->response->redirectTo('/admin/leads');
    }

    public function delete(Request $request, string $id): void
    {
        $this->leads->deleteOne((int) $id);
        $this->session->flash('success', 'Kayıt silindi.');
        $this->response->redirectTo('/admin/leads');
    }
}
