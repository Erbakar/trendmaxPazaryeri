<?php
/**
 * OzyConnect Panel — InvoiceService
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Invoice;

use App\Core\Database;
use App\Core\Logger;
use App\Helpers\CurrencyHelper;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreSetting;
use RuntimeException;

final class InvoiceService
{
    public function __construct(
        private readonly Database $db,
        private readonly Invoice $invoiceModel,
        private readonly Order $orderModel,
        private readonly OrderItem $orderItemModel,
        private readonly StoreSetting $storeSetting,
        private readonly UblTrBuilder $ublBuilder,
        private readonly GibEarsivProvider $gibProvider,
        private readonly Logger $logger,
    ) {}

    /**
     * Sipariş için e-Arşiv fatura oluştur ve GİB'e gönder
     */
    public function createForOrder(int $orderId, string $type = 'e-arsiv'): int
    {
        $order = $this->orderModel->findOrFail($orderId);

        // Duplicate guard — mevcut faturanın durumuna göre davran:
        // sent → engelle, draft → imza tekrarı dene, cancelled/error → yeniden kesime izin ver
        if (!empty($order['invoice_id'])) {
            $existing = $this->invoiceModel->find((int) $order['invoice_id']);
            if ($existing !== false) {
                $existingStatus = (string) ($existing['status'] ?? '');
                if ($existingStatus === 'draft') {
                    return $this->retrySignDraft($existing);
                }
                if (!in_array($existingStatus, ['cancelled', 'error'], true)) {
                    throw new RuntimeException("Sipariş #{$orderId} için zaten fatura mevcut");
                }
            }
        }

        $items = $this->orderItemModel->getByOrderId($orderId);
        if (empty($items)) {
            throw new RuntimeException("Sipariş #{$orderId} için kalem bulunamadı");
        }

        $seller = $this->getSellerInfo();
        $series = (string) $this->storeSetting->getValue('invoice_series', 'OMR');

        // GİB'e gönder — DB transaction DIŞINDA (uzun cURL zinciri kilit tutmasın)
        $gibData   = $this->gibProvider->buildFromOrder($order, $items, $seller);
        $gibResult = $this->gibProvider->send($gibData);

        // Taslak dahi oluşmadıysa kayıt açma — GİB'in gerçek mesajını yukarı taşı
        if (empty($gibResult['success'])) {
            throw new RuntimeException((string) ($gibResult['message'] ?? 'GİB fatura gönderimi başarısız'));
        }

        $gibUuid = (string) ($gibResult['uuid'] ?? $this->generateUuid());
        $signed  = !empty($gibResult['signed']);
        // Taslak oluştu ama imzalanamadıysa 'draft' — sonradan imza tekrarı yapılabilir
        $status  = $signed ? 'sent' : 'draft';

        return $this->db->transaction(function () use (
            $orderId, $type, $order, $series, $gibUuid, $gibResult, $status, $signed
        ): int {
            // Sıra numarası FOR UPDATE kilidi için transaction içinde alınır
            $sequenceNo = $this->invoiceModel->getNextSequenceNo($series);

            // Fatura tutarları
            $totalAmount = (float) $order['total_amount'];
            $defaultTaxRate = (float) $this->storeSetting->getValue('default_tax_rate', '20');
            $netTotal = round($totalAmount / (1 + $defaultTaxRate / 100), 2);
            $taxTotal = round($totalAmount - $netTotal, 2);

            // DB'ye kaydet
            $invoiceId = $this->invoiceModel->create([
                'order_id'       => $orderId,
                'type'           => $type,
                'invoice_uuid'   => $gibUuid,
                'series'         => $series,
                'sequence_no'    => $sequenceNo,
                'status'         => $status,
                'provider'       => 'gib_earsiv',
                'customer_name'  => $order['customer_name'] ?? '',
                'customer_tax_no'=> $order['customer_id_no'] ?? '',
                'subtotal'       => $netTotal,
                'tax_total'      => $taxTotal,
                'total'          => $totalAmount,
                'issued_at'      => date('Y-m-d H:i:s'),
                'provider_response_json' => json_encode($gibResult, JSON_UNESCAPED_UNICODE),
            ]);

            // Siparişe fatura bağla
            $this->orderModel->update($orderId, ['invoice_id' => $invoiceId]);

            $this->logger->info(
                "e-Arşiv fatura: #{$series}{$sequenceNo} → Sipariş #{$order['marketplace_order_id']} → GİB: " .
                ($signed ? 'İmzalandı' : 'Taslak (imzasız)'),
                'InvoiceService'
            );

            return $invoiceId;
        });
    }

    /**
     * Taslak (imzasız) durumdaki mevcut faturayı GİB'de imzalamayı dener.
     * Başarılıysa status='sent' güncellenir; değilse gerçek mesajla exception fırlatılır.
     *
     * @param array<string, mixed> $invoice
     */
    private function retrySignDraft(array $invoice): int
    {
        $uuid = (string) ($invoice['invoice_uuid'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('Taslak faturanın UUID bilgisi yok — imza tekrarı yapılamadı');
        }

        $res = $this->gibProvider->signDraft($uuid);
        if (!empty($res['success']) && !empty($res['signed'])) {
            $this->invoiceModel->update((int) $invoice['id'], ['status' => 'sent']);
            $this->logger->info("Taslak fatura imzalandı (retry): #{$invoice['id']} → {$uuid}", 'InvoiceService');
            return (int) $invoice['id'];
        }

        throw new RuntimeException((string) ($res['message'] ?? 'Taslak imzalanamadı'));
    }

    /**
     * GİB'den fatura HTML'ini al ve kaydet
     */
    public function saveInvoiceHtml(string $uuid): ?string
    {
        $html = $this->gibProvider->getInvoiceHtml($uuid);
        if (!$html) return null;

        $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $path = $dir . '/' . $uuid . '.html';
        file_put_contents($path, $this->sanitizeInvoiceHtml($html), LOCK_EX);
        return $path;
    }

    /**
     * GİB belgesi statik HTML'dir; script/olay yakalayıcı içermez. Yine de
     * panel origin'inde ham basıldığı için gelen içerikten script blokları,
     * on* olay öznitelikleri ve javascript: URI'leri ayıklanır (savunma katmanı).
     */
    private function sanitizeInvoiceHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', '$1=$2#$2', $html) ?? $html;
        return $html;
    }

    /**
     * Fatura HTML sonucunu ve varsa hata detayini getir.
     *
     * @return array{html: ?string, error: ?string}
     */
    public function getInvoiceHtmlResult(int $invoiceId): array
    {
        $invoice = $this->invoiceModel->find($invoiceId);
        if ($invoice === false) {
            return ['html' => null, 'error' => 'Fatura bulunamadi.'];
        }

        $uuid = (string) ($invoice['invoice_uuid'] ?? '');
        if ($uuid === '') {
            return ['html' => null, 'error' => 'Fatura UUID bilgisi bulunamadi.'];
        }

        $dir  = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices';
        $path = $dir . '/' . $uuid . '.html';

        if (file_exists($path)) {
            return ['html' => file_get_contents($path) ?: null, 'error' => null];
        }

        $html = $this->gibProvider->getInvoiceHtml($uuid);
        if ($html) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $html = $this->sanitizeInvoiceHtml($html);
            file_put_contents($path, $html, LOCK_EX);
            return ['html' => $html, 'error' => null];
        }

        $error = trim($this->gibProvider->getLastError());
        if ($error === '') {
            $error = 'GIB portali fatura HTML belgesini gondermedi.';
        }

        return ['html' => null, 'error' => $error];
    }

    /**
     * Fatura HTML'ini getir (görüntüleme için)
     */
    public function getInvoiceHtml(int $invoiceId): ?string
    {
        return $this->getInvoiceHtmlResult($invoiceId)['html'];
    }

    /**
     * GİB bağlantı testi
     */
    public function testGibConnection(): array
    {
        return $this->gibProvider->testConnection();
    }

    /**
     * Toplu fatura oluştur
     *
     * @param array<int, int> $orderIds
     * @return array{success: int, failed: int, errors: array}
     */
    public function createBulk(array $orderIds, string $type = 'e-arsiv'): array
    {
        $result = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($orderIds as $orderId) {
            try {
                $this->createForOrder($orderId, $type);
                $result['success']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * İade faturası oluştur ve GİB'e gönder
     *
     * @param int $orderId Orijinal sipariş ID
     * @param int $returnRequestId İade talebi ID
     * @param float|null $refundAmount Kısmi iade tutarı (null = tam iade)
     */
    public function createReturnInvoice(int $orderId, int $returnRequestId, ?float $refundAmount = null): int
    {
        $order = $this->orderModel->findOrFail($orderId);
        $items = $this->orderItemModel->getByOrderId($orderId);
        if (empty($items)) {
            throw new RuntimeException("Sipariş #{$orderId} için kalem bulunamadı");
        }

        // Orijinal satış faturasını bul
        $originalInvoice = null;
        if (!empty($order['invoice_id'])) {
            $originalInvoice = $this->invoiceModel->find((int) $order['invoice_id']);
        }

        // Kısmi iade ise tutarları oranla
        if ($refundAmount !== null && $refundAmount > 0) {
            $orderTotal = (float) $order['total_amount'];
            if ($orderTotal > 0 && $refundAmount < $orderTotal) {
                $ratio = $refundAmount / $orderTotal;
                foreach ($items as &$item) {
                    $item['unit_price'] = round((float) ($item['unit_price'] ?? 0) * $ratio, 2);
                    $item['line_total'] = round((float) ($item['line_total'] ?? 0) * $ratio, 2);
                    $item['discount']   = round((float) ($item['discount'] ?? 0) * $ratio, 2);
                }
                unset($item);
            }
        }

        $seller = $this->getSellerInfo();
        $series = (string) $this->storeSetting->getValue('invoice_series', 'OMR');

        // İade faturası GİB'de orijinal SATIŞ faturasının belge no + tarihini ister (iadeTable)
        $iadeRef = null;
        if ($originalInvoice !== null && $originalInvoice !== false) {
            $origResponse = json_decode((string) ($originalInvoice['provider_response_json'] ?? '{}'), true) ?: [];
            $origBelgeNo  = (string) ($origResponse['belgeNo'] ?? '');
            if ($origBelgeNo !== '') {
                $iadeRef = [
                    'faturaNo'         => $origBelgeNo,
                    'duzenlenmeTarihi' => date('d/m/Y', strtotime((string) ($originalInvoice['issued_at'] ?? 'now'))),
                ];
            }
        }

        // GİB'e gönder — faturaTipi = IADE — DB transaction DIŞINDA (uzun cURL zinciri kilit tutmasın)
        $gibData   = $this->gibProvider->buildFromOrder($order, $items, $seller, 'IADE', $iadeRef);
        $gibResult = $this->gibProvider->send($gibData);

        // Taslak dahi oluşmadıysa kayıt açma — GİB'in gerçek mesajını yukarı taşı
        if (empty($gibResult['success'])) {
            throw new RuntimeException((string) ($gibResult['message'] ?? 'GİB iade faturası gönderimi başarısız'));
        }

        $gibUuid = (string) ($gibResult['uuid'] ?? $this->generateUuid());
        $signed  = !empty($gibResult['signed']);
        $status  = $signed ? 'sent' : 'draft';

        return $this->db->transaction(function () use (
            $orderId, $order, $series, $gibUuid, $gibResult, $status, $signed,
            $originalInvoice, $returnRequestId, $refundAmount
        ): int {
            // Sıra numarası FOR UPDATE kilidi için transaction içinde alınır
            $sequenceNo = $this->invoiceModel->getNextSequenceNo($series);

            // Fatura tutarları
            $totalAmount = $refundAmount ?? (float) $order['total_amount'];
            $defaultTaxRate = (float) $this->storeSetting->getValue('default_tax_rate', '20');
            $netTotal = round($totalAmount / (1 + $defaultTaxRate / 100), 2);
            $taxTotal = round($totalAmount - $netTotal, 2);

            // DB'ye kaydet
            $invoiceId = $this->invoiceModel->create([
                'order_id'               => $orderId,
                'type'                   => 'e-arsiv',
                'invoice_flow'           => 'return',
                'invoice_uuid'           => $gibUuid,
                'series'                 => $series,
                'sequence_no'            => $sequenceNo,
                'status'                 => $status,
                'provider'               => 'gib_earsiv',
                'customer_name'          => $order['customer_name'] ?? '',
                'customer_tax_no'        => $order['customer_id_no'] ?? '',
                'subtotal'               => $netTotal,
                'tax_total'              => $taxTotal,
                'total'                  => $totalAmount,
                'issued_at'              => date('Y-m-d H:i:s'),
                'related_invoice_id'     => $originalInvoice ? (int) $originalInvoice['id'] : null,
                'return_request_id'      => $returnRequestId,
                'provider_response_json' => json_encode($gibResult, JSON_UNESCAPED_UNICODE),
            ]);

            $this->logger->info(
                "İade faturası: #{$series}{$sequenceNo} → Sipariş #{$order['marketplace_order_id']} → GİB: " .
                ($signed ? 'İmzalandı' : 'Taslak (imzasız)'),
                'InvoiceService'
            );

            return $invoiceId;
        });
    }

    /**
     * Fatura XML'ini indir
     */
    public function getXmlPath(int $invoiceId): ?string
    {
        $invoice = $this->invoiceModel->find($invoiceId);
        if ($invoice === false || empty($invoice['xml_path'])) {
            return null;
        }

        $path = BASE_PATH . '/storage/invoices/' . $invoice['xml_path'];
        return file_exists($path) ? $path : null;
    }

    /**
     * Fatura iptal (GİB + lokal)
     */
    public function cancel(int $invoiceId, string $reason = ''): bool
    {
        $invoice = $this->invoiceModel->findOrFail($invoiceId);

        if ($invoice['status'] === 'cancelled') {
            throw new RuntimeException('Fatura zaten iptal edilmiş');
        }

        // GİB'den de sil
        $uuid = $invoice['invoice_uuid'] ?? '';
        if (!empty($uuid)) {
            try {
                $this->gibProvider->cancel($uuid, $reason ?: 'Entegratör Yazılımı iptal');
                $this->logger->info("Fatura GİB'den iptal edildi: {$uuid}", 'InvoiceService');
            } catch (\Throwable $e) {
                $this->logger->warning("GİB iptal hatası (devam ediliyor): " . $e->getMessage(), 'InvoiceService');
            }
        }

        $updated = $this->invoiceModel->update($invoiceId, [
            'status'        => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_at'  => date('Y-m-d H:i:s'),
        ]);

        // Siparişteki fatura bağını kopar (yeniden kesilebilsin) — yalnızca sipariş
        // bu faturayı gösteriyorsa (iade faturası iptali satış faturası bağını bozmasın)
        if ($updated && !empty($invoice['order_id'])) {
            $order = $this->orderModel->find((int) $invoice['order_id']);
            if ($order !== false && (int) ($order['invoice_id'] ?? 0) === $invoiceId) {
                $this->orderModel->update((int) $invoice['order_id'], ['invoice_id' => null]);
            }
        }

        return $updated;
    }

    /**
     * Satıcı bilgilerini settings'ten al
     *
     * @return array<string, mixed>
     */
    private function getSellerInfo(): array
    {
        $keys = [
            'seller_company_name', 'seller_tax_no', 'seller_tax_office',
            'seller_mersis_no', 'seller_address', 'seller_city',
            'seller_district', 'seller_phone', 'seller_email',
        ];

        $info = [];
        foreach ($keys as $key) {
            $info[$key] = (string) $this->storeSetting->getValue($key, '');
        }

        return $info;
    }

    /**
     * UUID v4 oluştur
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
