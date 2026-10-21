<?php
/**
 * OzyConnect Panel — AdminSupportController (Platform admin: tüm destek talepleri)
 *
 * Admin tüm mağazaların taleplerini görür, yanıtlar, durum değiştirir. AdminAuthMiddleware
 * arkasında (merchant erişemez). Cross-store ham model metodları kullanılır (admin* önekli).
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
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;

final class AdminSupportController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly SupportTicket $tickets;
    private readonly SupportTicketMessage $messages;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->tickets      = $container->get(SupportTicket::class);
        $this->messages     = $container->get(SupportTicketMessage::class);
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
        $status = (string) $request->query('status', '');
        $q      = trim((string) $request->query('q', ''));
        $this->render('admin.support_index', [
            'pageTitle' => 'Destek Talepleri',
            'tickets'   => $this->tickets->adminList(null, ''), // tümü; filtre+arama client-side (DataTables)
            'counts'    => $this->tickets->adminStatusCounts(),
            'current'   => $status,
            'q'         => $q,
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $ticket = $this->tickets->adminFind((int) $id);
        if ($ticket === false) {
            $this->session->flash('error', 'Talep bulunamadı');
            $this->response->redirectTo('/admin/support');
        }
        $this->render('admin.support_show', [
            'pageTitle' => 'Talep #' . $ticket['id'],
            'ticket'    => $ticket,
            'messages'  => $this->messages->adminForTicket((int) $id),
        ]);
    }

    public function reply(Request $request, string $id): void
    {
        $ticket = $this->tickets->adminFind((int) $id);
        if ($ticket === false) {
            $this->session->flash('error', 'Talep bulunamadı');
            $this->response->redirectTo('/admin/support');
        }
        $body = trim((string) $request->post('body', ''));
        if ($body === '') {
            $this->session->flash('error', 'Mesaj boş olamaz');
            $this->response->redirectTo('/admin/support/' . $id);
        }
        $this->messages->adminCreate(
            (int) $id,
            (int) $ticket['store_id'],
            (int) $this->adminSession->adminId(),
            (string) $this->adminSession->adminEmail(),
            $body
        );
        $this->tickets->adminMarkReply((int) $id);
        $this->session->flash('success', 'Yanıt gönderildi');
        $this->response->redirectTo('/admin/support/' . $id);
    }

    public function status(Request $request, string $id): void
    {
        $ticket = $this->tickets->adminFind((int) $id);
        if ($ticket === false) {
            $this->session->flash('error', 'Talep bulunamadı');
            $this->response->redirectTo('/admin/support');
        }
        $status = (string) $request->post('status', '');
        if (!in_array($status, ['open', 'answered', 'closed'], true)) {
            $this->session->flash('error', 'Geçersiz durum');
            $this->response->redirectTo('/admin/support/' . $id);
        }
        $this->tickets->adminSetStatus((int) $id, $status);
        $this->session->flash('success', 'Durum güncellendi');
        $this->response->redirectTo('/admin/support/' . $id);
    }
}
