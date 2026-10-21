<?php
/**
 * OzyConnect — SubscriptionInvoiceService (SaaS abonelik faturası → Platform e-Arşiv)
 *
 * Platform, mağazaya (alıcı) kestiği abonelik faturasını kendi GİB e-Arşiv hesabıyla keser.
 * Satıcı = giriş yapılan GİB hesabı (platform); alıcı = mağaza (stores fatura bilgileri).
 * KDV ayarlanabilir (Setting: invoice_vat_rate + invoice_vat_mode = included|excluded|exempt).
 *
 * HER ŞEY HATA-TOLERANSLI: e-Arşiv başarısız olursa istisna FIRLATMAZ (ödeme/abonelik akışı bozulmaz);
 * subscription_invoices.earsiv_status = failed/missing_info/no_creds işaretler → sonra elle yeniden denenir.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Setting;
use App\Services\Invoice\GibEarsivProvider;

final class SubscriptionInvoiceService
{
    public function __construct(
        private readonly Database $db,
        private readonly Setting $settings,
        private readonly GibEarsivProvider $gib,
        private readonly Logger $logger,
    ) {}

    /** Platform GİB kimlik bilgilerini provider'a yükle (per-store değil). false = eksik. */
    private function applyPlatformCreds(): bool
    {
        $u = (string) $this->settings->get('platform_gib_username', '');
        $p = (string) $this->settings->get('platform_gib_password', '');
        if ($u === '' || $p === '') {
            return false;
        }
        $test = (string) $this->settings->get('platform_gib_test_mode', '1') === '1';
        $this->gib->useExplicitCredentials($u, $p, $test);
        return true;
    }

    /**
     * Bir abonelik faturasına e-Arşiv kes. İdempotent (zaten kesildiyse force=false → atla).
     * @return array{ok:bool, status:string, message:string}
     */
    public function issue(int $invoiceId, bool $force = false): array
    {
        $inv = $this->db->fetchOne("SELECT * FROM subscription_invoices WHERE id = :id", ['id' => $invoiceId]);
        if ($inv === false) {
            return ['ok' => false, 'status' => 'not_found', 'message' => 'Fatura bulunamadı.'];
        }
        if (!$force && !empty($inv['earsiv_uuid']) && ($inv['earsiv_status'] ?? '') === 'issued') {
            return ['ok' => true, 'status' => 'issued', 'message' => 'Fatura zaten kesilmiş.'];
        }

        $store = $this->db->fetchOne("SELECT * FROM stores WHERE id = :id", ['id' => (int) $inv['store_id']]);
        if ($store === false) {
            return $this->mark($invoiceId, 'failed', 'Mağaza bulunamadı.');
        }
        $taxId = preg_replace('/\D/', '', (string) ($store['tax_id'] ?? ''));
        if ($taxId === '' || ($store['legal_name'] ?? '') === '') {
            return $this->mark($invoiceId, 'missing_info', 'Mağaza fatura bilgileri eksik (VKN/ünvan). Mağaza detayından doldurun.');
        }
        if (!$this->applyPlatformCreds()) {
            return $this->mark($invoiceId, 'no_creds', 'Platform GİB bilgileri girilmemiş (Ayarlar › E-posta & e-Arşiv).');
        }

        // ── KDV (ayarlanabilir) ──
        $rate = (int) $this->settings->get('invoice_vat_rate', '20');
        $mode = (string) $this->settings->get('invoice_vat_mode', 'included'); // included|excluded|exempt
        $amount = (float) ($inv['amount'] ?? 0);
        if ($mode === 'exempt') {
            $rate = 0;
            $kdvDahilTotal = $amount;
        } elseif ($mode === 'excluded') {
            $kdvDahilTotal = round($amount * (1 + $rate / 100), 2); // amount NET → üstüne KDV
        } else { // included
            $kdvDahilTotal = $amount; // amount KDV DAHİL → geri ayrıştırılır
        }

        $owner = $this->db->fetchOne(
            "SELECT email FROM users WHERE store_id = :s AND role = 'admin' ORDER BY id LIMIT 1",
            ['s' => (int) $inv['store_id']]
        );
        $buyerName = (string) ($store['legal_name'] ?: $store['name']);
        $addrJson = json_encode([
            'address'  => $store['billing_address'] ?? '',
            'district' => $store['billing_district'] ?? '',
            'city'     => $store['billing_city'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        $order = [
            'customer_name'         => $buyerName,
            'customer_id_no'        => $taxId,
            'customer_tax_office'   => (string) ($store['tax_office'] ?? ''),
            'customer_email'        => (string) ($owner['email'] ?? ''),
            'billing_address_json'  => $addrJson,
            'shipping_address_json' => $addrJson,
            'raw_data_json'         => '{}',
            'marketplace_order_id'  => (string) ($inv['number'] ?? ''),
            'ordered_at'            => (string) ($inv['created_at'] ?? 'now'),
        ];
        $items = [[
            'name'       => 'Entegratör Yazılımı Abonelik' . (!empty($inv['number']) ? ' — ' . $inv['number'] : ''),
            'qty'        => 1,
            'unit_price' => $kdvDahilTotal,
            'discount'   => 0,
            'tax_rate'   => $rate,
            'line_total' => $kdvDahilTotal,
        ]];

        try {
            $payload = $this->gib->buildFromOrder($order, $items, [], 'SATIS');
            // VKN (10 hane) → tüzel kişi: ünvan kullan, ad/soyad boşalt.
            if (strlen($taxId) === 10) {
                $payload['aliciUnvan']  = $buyerName;
                $payload['aliciAdi']    = '';
                $payload['aliciSoyadi'] = '';
            }
            $res = $this->gib->send($payload);
        } catch (\Throwable $e) {
            $this->logger->error('SaaS e-Arşiv kesim hatası: ' . $e->getMessage(), 'SaaSInvoice', ['invoice' => $invoiceId]);
            return $this->mark($invoiceId, 'failed', 'GİB hatası: ' . $e->getMessage());
        }

        if (empty($res['success'])) {
            return $this->mark($invoiceId, 'failed', 'GİB: ' . ($res['message'] ?? 'bilinmeyen hata'));
        }

        $uuid = (string) ($res['uuid'] ?? '');
        $this->db->query(
            "UPDATE subscription_invoices SET earsiv_uuid = :u, earsiv_status = 'issued', earsiv_at = NOW() WHERE id = :id",
            ['u' => $uuid, 'id' => $invoiceId]
        );
        $this->logger->info("SaaS e-Arşiv kesildi: fatura #{$invoiceId} → {$uuid}", 'SaaSInvoice');
        return ['ok' => true, 'status' => 'issued', 'message' => 'e-Arşiv faturası kesildi (' . $uuid . ').'];
    }

    /** Kesilmiş e-Arşiv faturasını iptal et. */
    public function cancel(int $invoiceId, string $reason = 'İptal'): array
    {
        $inv = $this->db->fetchOne("SELECT * FROM subscription_invoices WHERE id = :id", ['id' => $invoiceId]);
        if ($inv === false) {
            return ['ok' => false, 'status' => 'not_found', 'message' => 'Fatura bulunamadı.'];
        }
        if (empty($inv['earsiv_uuid']) || ($inv['earsiv_status'] ?? '') !== 'issued') {
            return ['ok' => false, 'status' => 'not_issued', 'message' => 'Kesilmiş bir e-Arşiv faturası yok.'];
        }
        if (!$this->applyPlatformCreds()) {
            return ['ok' => false, 'status' => 'no_creds', 'message' => 'Platform GİB bilgileri girilmemiş.'];
        }
        try {
            $ok = $this->gib->cancel((string) $inv['earsiv_uuid'], $reason);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'İptal hatası: ' . $e->getMessage()];
        }
        if (!$ok) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'GİB iptal başarısız: ' . $this->gib->getLastError()];
        }
        $this->db->query(
            "UPDATE subscription_invoices SET earsiv_status = 'cancelled' WHERE id = :id",
            ['id' => $invoiceId]
        );
        $this->logger->info("SaaS e-Arşiv iptal: fatura #{$invoiceId}", 'SaaSInvoice');
        return ['ok' => true, 'status' => 'cancelled', 'message' => 'e-Arşiv faturası iptal edildi.'];
    }

    private function mark(int $invoiceId, string $status, string $message): array
    {
        try {
            $this->db->query(
                "UPDATE subscription_invoices SET earsiv_status = :st WHERE id = :id",
                ['st' => $status, 'id' => $invoiceId]
            );
        } catch (\Throwable) { /* yut */ }
        $this->logger->warning("SaaS e-Arşiv atlandı/başarısız (#{$invoiceId}): {$message}", 'SaaSInvoice');
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
