<?php
/**
 * OzyConnect Panel — AdminPaymentController (Faz 8: SaaS ödeme onayları/tahsilat)
 *
 * Havale/dekont ödemelerini onayla/reddet. Onay → invoice paid + SubscriptionService.activateFromPayment
 * + mağazaya in-app bildirim + payment_confirmed maili. Dekont (storage/receipts, web-kök dışı) yalnız admin indirir.
 * AdminAuthMiddleware (Pattern A).
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
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use App\Services\NotificationService;
use App\Services\PaymentConfirmationService;
use App\Services\SubscriptionService;

final class AdminPaymentController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Database $db;
    private readonly Payment $payments;
    private readonly SubscriptionInvoice $invoices;
    private readonly SubscriptionService $subscriptions;
    private readonly Notification $notifications;
    private readonly NotificationService $notifier;
    private readonly PaymentConfirmationService $confirmer;

    public function __construct(Container $container)
    {
        $this->response      = $container->get(Response::class);
        $this->session       = $container->get(Session::class);
        $this->adminSession  = $container->get(AdminSession::class);
        $this->db            = $container->get(Database::class);
        $this->payments      = $container->get(Payment::class);
        $this->invoices      = $container->get(SubscriptionInvoice::class);
        $this->subscriptions = $container->get(SubscriptionService::class);
        $this->notifications = $container->get(Notification::class);
        $this->notifier      = $container->get(NotificationService::class);
        $this->confirmer     = $container->get(PaymentConfirmationService::class);
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
        $this->render('admin.payments_index', [
            'pageTitle'    => 'Ödemeler',
            'payments'     => $this->payments->adminList(null), // tümü; filtre client-side (DataTables)
            'current'      => $status,
            'pendingCount' => $this->payments->pendingCount(),
            'monthTotal'   => $this->payments->confirmedThisMonth(),
        ]);
    }

    public function confirm(Request $request, string $id): void
    {
        // Tek onay yolu: havale + PayTR-callback ortak PaymentConfirmationService (transaction + kind dispatch).
        $adminId = (int) $this->adminSession->adminId() ?: null;
        $res = $this->confirmer->confirm((int) $id, $adminId, 'admin');
        $this->session->flash($res['ok'] ? 'success' : 'error', $res['message']);
        $this->response->redirectTo('/admin/payments');
    }

    public function reject(Request $request, string $id): void
    {
        $pay = $this->payments->adminFind((int) $id);
        if ($pay === false || ($pay['status'] ?? '') !== 'pending') {
            $this->session->flash('error', 'Ödeme bulunamadı veya zaten işlenmiş.');
            $this->response->redirectTo('/admin/payments');
            return;
        }
        $this->payments->setStatus((int) $id, 'rejected', (int) $this->adminSession->adminId() ?: null);
        $this->notifications->push((int) $pay['store_id'], 'payment', 'Ödemeniz reddedildi', 'Lütfen dekontu kontrol edip tekrar deneyin.', '/subscription');
        $this->session->flash('success', 'Ödeme reddedildi.');
        $this->response->redirectTo('/admin/payments');
    }

    /** Dekont indir (web-kök dışı storage/receipts). */
    public function receipt(Request $request, string $id): void
    {
        $pay = $this->payments->adminFind((int) $id);
        if ($pay === false || empty($pay['receipt_path'])) {
            $this->response->notFound('Dekont bulunamadı');
            return;
        }
        $rel = (string) $pay['receipt_path'];
        // Güvenlik: yalnız storage/receipts altında, traversal yok.
        if (str_contains($rel, '..')) {
            $this->response->notFound('Geçersiz yol');
            return;
        }
        $full = BASE_PATH . '/storage/' . ltrim($rel, '/');
        $real = realpath($full);
        $baseDir = realpath(BASE_PATH . '/storage/receipts');
        if ($real === false || $baseDir === false || !str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR)) {
            $this->response->notFound('Dekont bulunamadı');
            return;
        }
        $this->response->download($real, basename($real));
    }

    /** Mağaza sahibine (ilk admin kullanıcı) şablonlu e-posta (best-effort). */
    private function emailOwner(int $storeId, string $tplKey, array $vars): void
    {
        try {
            $owner = $this->db->fetchOne("SELECT email, name FROM users WHERE store_id = :s AND role = 'admin' ORDER BY id LIMIT 1", ['s' => $storeId]);
            if ($owner !== false && !empty($owner['email'])) {
                $this->notifier->sendTemplate((string) $owner['email'], $tplKey, ['name' => (string) ($owner['name'] ?? '')] + $vars);
            }
        } catch (\Throwable) {
            // sessiz
        }
    }
}
