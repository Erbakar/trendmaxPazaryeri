<?php
/**
 * OzyConnect Panel — SupportController (Merchant destek talepleri)
 *
 * Mağaza kendi destek taleplerini açar, görür ve yanıtlar. Tüm erişim tenant-scoped
 * (SupportTicket/SupportTicketMessage BaseModel store_id'yi zorlar — başka mağazanın
 * talebi find()'da bulunamaz → 404). Admin tarafı AdminSupportController'dadır.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;

final class SupportController extends BaseController
{
    private readonly SupportTicket $tickets;
    private readonly SupportTicketMessage $messages;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->tickets  = $container->get(SupportTicket::class);
        $this->messages = $container->get(SupportTicketMessage::class);
    }

    public function index(Request $request): void
    {
        $status = (string) $request->query('status', '');
        $this->view('support.index', [
            'pageTitle' => 'Destek',
            'tickets'   => $this->tickets->forStore($status !== '' ? $status : null),
            'counts'    => $this->tickets->statusCountsForStore(),
            'current'   => $status,
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $ticket = $this->tickets->find((int) $id);
        if ($ticket === false) {
            $this->response->notFound('Destek talebi bulunamadı');
        }
        $this->view('support.show', [
            'pageTitle' => 'Destek Talebi #' . $ticket['id'],
            'ticket'    => $ticket,
            'messages'  => $this->messages->forTicket((int) $id),
        ]);
    }

    public function store(Request $request): void
    {
        $subject  = trim((string) $request->post('subject', ''));
        $body     = trim((string) $request->post('body', ''));
        $priority = (string) $request->post('priority', 'normal');
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            $priority = 'normal';
        }
        if ($subject === '' || $body === '') {
            $this->redirectWithError('/support', 'Konu ve mesaj alanları zorunludur.');
        }

        $ticketId = $this->tickets->create([
            'user_id'       => $this->session->userId(),
            'subject'       => mb_substr($subject, 0, 200),
            'status'        => 'open',
            'priority'      => $priority,
            'last_reply_at' => date('Y-m-d H:i:s'),
            'last_reply_by' => 'merchant',
        ]);
        $this->messages->create([
            'ticket_id'   => $ticketId,
            'sender_type' => 'merchant',
            'sender_id'   => $this->session->userId(),
            'sender_name' => $this->session->get('user_name'),
            'body'        => $body,
        ]);

        $this->redirectWithSuccess('/support/' . $ticketId, 'Destek talebiniz oluşturuldu.');
    }

    public function reply(Request $request, string $id): void
    {
        $ticket = $this->tickets->find((int) $id);
        if ($ticket === false) {
            $this->response->notFound('Destek talebi bulunamadı');
        }
        if ($ticket['status'] === 'closed') {
            $this->redirectWithError('/support/' . $id, 'Bu talep kapatılmış, yanıtlanamaz.');
        }
        $body = trim((string) $request->post('body', ''));
        if ($body === '') {
            $this->redirectWithError('/support/' . $id, 'Mesaj boş olamaz.');
        }

        $this->messages->create([
            'ticket_id'   => (int) $id,
            'sender_type' => 'merchant',
            'sender_id'   => $this->session->userId(),
            'sender_name' => $this->session->get('user_name'),
            'body'        => $body,
        ]);
        $this->tickets->markReply((int) $id, 'merchant', 'open');

        $this->redirectWithSuccess('/support/' . $id, 'Mesajınız gönderildi.');
    }

    public function close(Request $request, string $id): void
    {
        $ticket = $this->tickets->find((int) $id);
        if ($ticket === false) {
            $this->response->notFound('Destek talebi bulunamadı');
        }
        $this->tickets->update((int) $id, ['status' => 'closed']);
        $this->redirectWithSuccess('/support/' . $id, 'Talep kapatıldı.');
    }
}
