<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Question;
use App\Models\ReturnRequest;

final class NotificationController extends BaseController
{
    private readonly Order $orderModel;
    private readonly ReturnRequest $returnModel;
    private readonly Question $questionModel;
    private readonly Notification $notifications;
    private readonly TenantContext $tenant;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->orderModel    = $container->get(Order::class);
        $this->returnModel   = $container->get(ReturnRequest::class);
        $this->questionModel = $container->get(Question::class);
        $this->notifications = $container->get(Notification::class);
        $this->tenant        = $container->get(TenantContext::class);
    }

    /** Faz 8 — Bildirim Merkezi (kalıcı sistem bildirimleri: abonelik/ödeme/destek). */
    public function center(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $this->view('notifications.center', [
            'pageTitle'     => 'Bildirimler',
            'notifications' => $this->notifications->forStore($storeId, 50),
            'unread'        => $this->notifications->unreadCount($storeId),
        ]);
    }

    /** Tümünü okundu işaretle. */
    public function read(Request $request): void
    {
        $this->notifications->markAllRead($this->tenant->currentStoreId());
        $this->redirectWithSuccess('/notifications', 'Bildirimler okundu olarak işaretlendi.');
    }

    public function check(Request $request): void
    {
        $since = $request->query('since', '');

        if ($since === '' || !strtotime($since)) {
            $since = date('Y-m-d H:i:s', strtotime('-30 seconds'));
        }

        $newOrders    = $this->orderModel->getNewCountSince($since);
        $newReturns   = $this->returnModel->getNewCountSince($since);
        $newQuestions = $this->questionModel->getNewCountSince($since);

        $now = date('Y-m-d H:i:s');

        $this->success([
            'orders'    => $newOrders,
            'returns'   => $newReturns,
            'questions' => $newQuestions,
            'timestamp' => $now,
        ]);
    }
}
