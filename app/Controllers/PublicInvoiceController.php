<?php
/**
 * OzyConnect Panel — PublicInvoiceController (Pazaryeri fatura linki)
 *
 * Pazaryerleri (Hepsiburada, Trendyol, Pazarama…) fatura bağlantısını KENDİ
 * sunucularından indirir. Panel oturumu isteyen /invoices/{id}/html adresi
 * onlara giriş sayfası döndürüyor, Hepsiburada da bunu
 * "Url is not correct format. Url must be pdf or html link" + boş ContentType
 * hatasıyla reddediyordu.
 *
 * Bu uç nokta:
 *  - oturum/CSRF istemez (adres GİB UUID'i ile korunur — tahmin edilemez),
 *  - ".html" ile biter (pazaryerleri uzantı kontrolü yapar),
 *  - text/html Content-Type ile inline döner,
 *  - arama motorlarına kapalıdır.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Invoice;
use App\Services\Invoice\InvoiceService;

final class PublicInvoiceController extends BaseController
{
    private readonly Invoice $invoiceModel;
    private readonly InvoiceService $invoiceService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->invoiceModel   = $container->get(Invoice::class);
        $this->invoiceService = $container->get(InvoiceService::class);
    }

    /**
     * GET /fatura/{uuid}.html
     */
    public function document(Request $request, string $uuid): void
    {
        $uuid = (string) preg_replace('/\.(html?|pdf)$/i', '', trim($uuid));

        $invoice = $this->invoiceModel->findByUuidPublic($uuid);
        if ($invoice === false) {
            $this->plain(404, 'Fatura bulunamadi');
        }

        // Tenant bağlamı faturanın mağazasından kurulur (GİB'den çekmek gerekirse).
        $storeId = (int) ($invoice['store_id'] ?? 0);
        if ($storeId > 0) {
            try {
                $tenant = $this->container->get(TenantContext::class);
                if (!$tenant->hasStore()) {
                    $tenant->setStoreId($storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Public fatura tenant bağlamı kurulamadı: ' . $e->getMessage(), 'Invoice');
            }
        }

        $dir  = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices';
        $path = $dir . '/' . $uuid . '.html';
        $html = is_file($path) ? (string) file_get_contents($path) : '';

        if ($html === '') {
            try {
                $result = $this->invoiceService->getInvoiceHtmlResult((int) $invoice['id']);
                $html = (string) ($result['html'] ?? '');
            } catch (\Throwable $e) {
                $this->logger->error('Public fatura belgesi alınamadı: ' . $e->getMessage(), 'Invoice');
            }
        }

        if ($html === '') {
            $this->plain(404, 'Fatura belgesi hazir degil');
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $uuid . '.html"');
        header('Content-Length: ' . strlen($html));
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, max-age=300');
        echo $html;
        exit;
    }

    private function plain(int $code, string $message): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
