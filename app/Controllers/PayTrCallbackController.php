<?php
/**
 * OzyConnect Panel — PayTrCallbackController (Faz 9: PayTR bildirim/callback — PUBLIC, no-auth)
 *
 * PayTR sunucu→sunucu POST. HMAC doğrula → merchant_oid'den ödeme bul → status='success' ise
 * PaymentConfirmationService ile onayla (kind dispatch). Yanıt gövdesi MUTLAKA "OK" olmalı (yoksa PayTR tekrar dener).
 * ROUTE_PERMISSION/MODULE_MAP DIŞINDA → otomatik public. CSRF YOK (sunucu-sunucu).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\PaymentConfirmationService;
use App\Services\PayTrService;

final class PayTrCallbackController
{
    private readonly PayTrService $paytr;
    private readonly Payment $payments;
    private readonly PaymentConfirmationService $confirmer;
    private readonly Notification $notifications;

    public function __construct(Container $container)
    {
        $this->paytr         = $container->get(PayTrService::class);
        $this->payments      = $container->get(Payment::class);
        $this->confirmer     = $container->get(PaymentConfirmationService::class);
        $this->notifications = $container->get(Notification::class);
    }

    public function handle(Request $request): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        $post = $_POST;

        // 1) HMAC doğrula — başarısızsa "OK" DEME (PayTR sahteyi reddetsin).
        if (!$this->paytr->verifyCallback($post)) {
            echo 'PAYTR notification failed: bad hash';
            return;
        }

        $oid = (string) ($post['merchant_oid'] ?? '');
        $pay = $this->payments->findByMerchantOid($oid);
        if ($pay === false) {
            echo 'OK'; // bilinmeyen oid — ack (tekrar denemesin)
            return;
        }

        $curStatus = (string) ($pay['status'] ?? '');
        if ($curStatus === 'confirmed' || $curStatus === 'rejected') {
            echo 'OK'; // idempotent — zaten işlenmiş
            return;
        }

        try {
            if ((string) ($post['status'] ?? '') === 'success') {
                if (!empty($post['payment_id'])) {
                    $this->payments->setProviderRef((int) $pay['id'], (string) $post['payment_id']);
                }
                $res = $this->confirmer->confirm((int) $pay['id'], null, 'paytr');
                if (!$res['ok']) {
                    echo 'PAYTR confirm error'; // OK deme → PayTR tekrar dener
                    return;
                }
            } else {
                $this->payments->setStatus((int) $pay['id'], 'rejected', null);
                $this->notifications->push((int) $pay['store_id'], 'payment', 'Kart ödemesi başarısız', 'Ödeme tamamlanamadı: ' . (string) ($post['failed_reason_msg'] ?? 'reddedildi') . '. Tekrar deneyebilirsiniz.', '/subscription');
            }
        } catch (\Throwable) {
            echo 'PAYTR processing error'; // OK deme → tekrar dener
            return;
        }

        echo 'OK';
    }
}
