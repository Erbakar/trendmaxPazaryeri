<?php
/**
 * OzyConnect Panel — AdminInvoiceController (Faturalar & Ödemeler)
 *
 * Global abonelik faturası (subscription_invoices) listesi — tüm mağazalar tek yerde.
 * e-Arşiv kesim/iptal işlemleri SubscriptionInvoiceService ile (mağaza detayındaki ile AYNI servis).
 * AdminAuthMiddleware (Pattern A).
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
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionInvoiceService;

final class AdminInvoiceController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly SubscriptionInvoice $invoices;
    private readonly SubscriptionInvoiceService $invoiceService;

    public function __construct(Container $container)
    {
        $this->response       = $container->get(Response::class);
        $this->session        = $container->get(Session::class);
        $this->adminSession   = $container->get(AdminSession::class);
        $this->invoices       = $container->get(SubscriptionInvoice::class);
        $this->invoiceService = $container->get(SubscriptionInvoiceService::class);
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
        $filters = [
            'status'   => (string) $request->query('status', ''),
            'earsiv'   => (string) $request->query('earsiv', ''),
            'store_id' => (int) $request->query('store_id', 0),
            'q'        => (string) $request->query('q', ''),
        ];
        $this->render('admin.invoices_index', [
            'pageTitle' => 'Faturalar',
            'invoices'  => $this->invoices->adminListAll([]), // tümü; filtre client-side (DataTables)
            'stats'     => $this->invoices->adminStats(),
            'filters'   => $filters,
        ]);
    }

    public function issueEarsiv(Request $request, string $id): void
    {
        $force = (string) $request->input('force', '') === '1';
        $res = $this->invoiceService->issue((int) $id, $force);
        $this->session->flash($res['ok'] ? 'success' : 'error', $res['message']);
        $this->response->redirectTo('/admin/invoices');
    }

    public function cancelEarsiv(Request $request, string $id): void
    {
        $reason = trim((string) $request->input('reason', '')) ?: 'İptal';
        $res = $this->invoiceService->cancel((int) $id, $reason);
        $this->session->flash($res['ok'] ? 'success' : 'error', $res['message']);
        $this->response->redirectTo('/admin/invoices');
    }
}
