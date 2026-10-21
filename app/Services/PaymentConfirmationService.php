<?php
/**
 * OzyConnect Panel — PaymentConfirmationService (Faz 9)
 *
 * Bir ödemeyi onaylamanın TEK yolu (havale-admin-onay + PayTR-callback ortak). Transaction'lı.
 * Faturanın kind'ine göre dispatch eder:
 *   - subscription → SubscriptionService::activateFromPayment (normal aktivasyon/yenileme)
 *   - upgrade      → SubscriptionService::changePackage(meta.package_id) + activate
 *   - addon        → StoreAddon::activate(meta.module_key) + SubscriptionService::resyncEntitlements
 * İdempotent: zaten 'confirmed' ödeme tekrar işlenmez (callback yeniden gelebilir).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ModuleCatalog;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\StoreAddon;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Queue\QueueService;

final class PaymentConfirmationService
{
    public function __construct(
        private readonly Database $db,
        private readonly Payment $payments,
        private readonly SubscriptionInvoice $invoices,
        private readonly Subscription $subscriptions,
        private readonly SubscriptionService $subService,
        private readonly StoreAddon $addons,
        private readonly AddonService $addonService,
        private readonly Notification $notifications,
        private readonly NotificationService $notifier,
        private readonly QueueService $queue,
        private readonly AiCreditService $credits,
    ) {}

    /**
     * @return array{ok:bool, message:string, already?:bool}
     */
    public function confirm(int $paymentId, ?int $adminId = null, string $source = 'admin'): array
    {
        $pay = $this->payments->adminFind($paymentId);
        if ($pay === false) {
            return ['ok' => false, 'message' => 'Ödeme bulunamadı.'];
        }
        $status = (string) ($pay['status'] ?? '');
        if ($status === 'confirmed') {
            return ['ok' => true, 'message' => 'Ödeme zaten onaylı.', 'already' => true]; // idempotent
        }
        if ($status !== 'pending') {
            return ['ok' => false, 'message' => 'Bu ödeme işlenemez (durum: ' . $status . ').'];
        }

        $storeId   = (int) $pay['store_id'];
        $invoiceId = (int) $pay['subscription_invoice_id'];
        $invoice   = $this->invoices->adminFind($invoiceId);
        if ($invoice === false) {
            return ['ok' => false, 'message' => 'Fatura bulunamadı.'];
        }
        // Tenant izolasyonu: fatura ödemenin mağazasına ait olmalı (cross-store onayı engelle).
        if ((int) ($invoice['store_id'] ?? 0) !== $storeId) {
            return ['ok' => false, 'message' => 'Fatura bu ödemeye ait değil.'];
        }
        $kind      = (string) ($invoice['kind'] ?? 'subscription');
        $meta      = $this->decodeMeta($invoice['meta_json'] ?? null);
        $amount    = (float) ($pay['amount'] ?? 0);

        // Kredi faturası: krediler işlenmeden ÖNCE doğrula (sessiz "ödendi ama kredi yok" durumunu engelle).
        if ($kind === 'credit') {
            $cAmt = (int) ($meta['credit_amount'] ?? 0);
            if ($cAmt <= 0 || $cAmt > 1_000_000) {
                return ['ok' => false, 'message' => 'Kredi paketi geçersiz (kredi miktarı tanımsız).'];
            }
        }

        // ── Atomik: ödeme + fatura + abonelik durumu ──
        $processed = $this->db->transaction(function () use ($paymentId, $invoiceId, $adminId, $storeId, $kind, $meta, $amount) {
            // Eşzamanlı çift onayı engelle: ödeme satırını kilitle + durumu yeniden doğrula.
            $cur = $this->db->fetchOne("SELECT status FROM payments WHERE id = :id FOR UPDATE", ['id' => $paymentId]);
            if ((string) ($cur['status'] ?? '') !== 'pending') {
                return false; // başka bir istek bu ödemeyi zaten işledi
            }
            $this->payments->setStatus($paymentId, 'confirmed', $adminId);
            $this->invoices->markPaid($invoiceId);

            if ($kind === 'credit') {
                // AI kredi paketi: bakiyeye ekle (faturaya bağlı, idempotent ref).
                $credits = (int) ($meta['credit_amount'] ?? 0);
                if ($credits > 0) {
                    $this->credits->add($credits, 'purchase', ['type' => 'subscription_invoice', 'id' => $invoiceId], $storeId, $adminId, 'Kredi paketi satın alımı');
                }
            } elseif ($kind === 'upgrade' && !empty($meta['package_id'])) {
                $this->subService->changePackage($storeId, (int) $meta['package_id'], $adminId);
                $this->subService->activateFromPayment($storeId, $adminId);
                $this->grantMonthlyCredits($storeId, $invoiceId, $adminId);
            } elseif ($kind === 'addon' && !empty($meta['marketplace_id'])) {
                // Pazaryeri add-on: entitlement DOĞRUDAN verilir (resolver/applyPackage pazaryeri add-on'unu kapsamaz).
                $this->addonService->addMarketplace($storeId, (int) $meta['marketplace_id'], false);
            } elseif ($kind === 'addon' && !empty($meta['module_key'])) {
                // Modül add-on: grant + store_addons (faturasız) — admin akışıyla aynı yol.
                $this->addonService->addModule($storeId, (string) $meta['module_key'], false);
            } else {
                $this->subService->activateFromPayment($storeId, $adminId);
                $this->grantMonthlyCredits($storeId, $invoiceId, $adminId);
            }
            return true;
        });

        if ($processed === false) {
            return ['ok' => true, 'message' => 'Ödeme zaten onaylı.', 'already' => true]; // eşzamanlı istek işledi
        }

        // ── Commit sonrası best-effort bildirim + e-posta ──
        $this->notifyAfter($storeId, $kind, $amount, $meta);

        // ── e-Arşiv faturası: ASYNC kuyruğa al (worker keser; PayTR callback / admin onayını BLOKLAMAZ) ──
        if ($invoiceId > 0) {
            try {
                $this->queue->enqueue($storeId, 'saas_invoice_earsiv', null, ['invoice_id' => $invoiceId]);
            } catch (\Throwable) {
                // sessiz — kuyruğa alınamazsa fatura mağaza detayından elle kesilebilir
            }
        }

        return ['ok' => true, 'message' => $this->successMessage($kind)];
    }

    private function successMessage(string $kind): string
    {
        return match ($kind) {
            'upgrade' => 'Ödeme onaylandı, paket yükseltmesi uygulandı.',
            'addon'   => 'Ödeme onaylandı, modül eklendi.',
            'credit'  => 'Ödeme onaylandı, kredi paketi hesaba eklendi.',
            default   => 'Ödeme onaylandı, abonelik aktifleştirildi.',
        };
    }

    /**
     * Abonelik aktivasyonu/yenilemesinde paketin aylık AI kredisini tahsis et.
     * İdempotent: aynı abonelik faturası için ikinci kez tahsis yapılmaz.
     */
    private function grantMonthlyCredits(int $storeId, int $invoiceId, ?int $adminId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $exists = $this->db->fetchOne(
            "SELECT 1 FROM store_credit_transactions
             WHERE store_id = :s AND type = 'grant' AND ref_type = 'subscription_invoice' AND ref_id = :i LIMIT 1",
            ['s' => $storeId, 'i' => $invoiceId]
        );
        if ($exists !== false) {
            return; // bu fatura için zaten tahsis edilmiş
        }
        // Aktif paket (mağaza başına tek abonelik; upgrade'de changePackage SONRASI okunur → YENİ paket kredisi verilir).
        $sub = $this->db->fetchOne("SELECT package_id FROM subscriptions WHERE store_id = :s ORDER BY id DESC LIMIT 1", ['s' => $storeId]);
        $pkgId = (int) ($sub['package_id'] ?? 0);
        if ($pkgId <= 0) {
            return;
        }
        $pkg = $this->db->fetchOne("SELECT monthly_credits FROM packages WHERE id = :id", ['id' => $pkgId]);
        $credits = (int) ($pkg['monthly_credits'] ?? 0);
        if ($credits <= 0) {
            return;
        }
        $this->credits->add($credits, 'grant', ['type' => 'subscription_invoice', 'id' => $invoiceId], $storeId, $adminId, 'Paket aylık AI kredisi');
    }

    private function notifyAfter(int $storeId, string $kind, float $amount, array $meta): void
    {
        try {
            $title = 'Ödemeniz onaylandı';
            $body  = match ($kind) {
                'upgrade' => 'Paket yükseltmeniz uygulandı.',
                'addon'   => 'Satın aldığınız modül hesabınıza eklendi.',
                'credit'  => 'Satın aldığınız ' . number_format((float) ($meta['credit_amount'] ?? 0), 0, ',', '.') . ' AI kredisi hesabınıza eklendi.',
                default   => 'Aboneliğiniz aktifleştirildi. Teşekkürler.',
            };
            $this->notifications->push($storeId, 'payment', $title, $body, '/subscription');
            $owner = $this->db->fetchOne("SELECT email, name FROM users WHERE store_id = :s AND role = 'admin' ORDER BY id LIMIT 1", ['s' => $storeId]);
            if ($owner !== false && !empty($owner['email'])) {
                $this->notifier->sendTemplate((string) $owner['email'], 'payment_confirmed', [
                    'name'   => (string) ($owner['name'] ?? ''),
                    'amount' => '₺' . number_format($amount, 2, ',', '.'),
                ]);
            }
        } catch (\Throwable) {
            // sessiz — bildirim/e-posta tahsilatı bozmaz
        }
    }

    /** @return array<string,mixed> */
    private function decodeMeta(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }
}
