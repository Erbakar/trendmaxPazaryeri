<?php
/**
 * OzyConnect Panel — InvoiceController
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Invoice;
use App\Models\Marketplace;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Services\Invoice\InvoiceService;

final class InvoiceController extends BaseController
{
    /** Fatura & GİB ayarı olarak store_settings'e yazılabilecek anahtarlar (per-store). */
    private const INVOICE_KEYS = [
        'gib_test_mode', 'gib_username', 'gib_password', 'invoice_series', 'default_tax_rate',
        'invoice_auto_create', 'auto_return_invoice', 'seller_company_name', 'seller_tax_no',
        'seller_tax_office', 'seller_mersis_no', 'seller_address', 'seller_city', 'seller_district',
        'seller_phone', 'seller_email',
    ];

    private readonly Invoice $invoiceModel;
    private readonly InvoiceService $invoiceService;
    private readonly Setting $settingModel;
    private readonly StoreSetting $storeSetting;
    private readonly Marketplace $marketplaceModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->invoiceModel      = $container->get(Invoice::class);
        $this->invoiceService    = $container->get(InvoiceService::class);
        $this->settingModel      = $container->get(Setting::class);
        $this->storeSetting      = $container->get(StoreSetting::class);
        $this->marketplaceModel  = $container->get(Marketplace::class);
    }

    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getAll();
        $mpInvoiceCounts = $this->invoiceModel->getCountsByMarketplace();
        $statusCounts = $this->invoiceModel->getStatusCounts();

        $totalInvoices = 0;
        foreach ($mpInvoiceCounts as $cnt) {
            $totalInvoices += $cnt;
        }

        $this->view('invoices.index', [
            'pageTitle'       => 'Faturalar',
            'marketplaces'    => $marketplaces,
            'mpInvoiceCounts' => $mpInvoiceCounts,
            'statusCounts'    => $statusCounts,
            'totalInvoices'   => $totalInvoices,
        ]);
    }

    /**
     * AJAX DataTables endpoint
     */
    public function ajaxList(Request $request): void
    {
        $draw   = (int) ($_GET['draw'] ?? 1);
        $start  = (int) ($_GET['start'] ?? 0);
        $length = max(10, min(100, (int) ($_GET['length'] ?? 25)));

        $searchValue = trim((string) ($_GET['search']['value'] ?? ''));

        $dtCol = (int) ($_GET['order'][0]['column'] ?? 0);
        $dtDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc'));
        $colMap = [
            0 => 'i.issued_at', 1 => 'i.issued_at', 2 => 'i.customer_name',
            3 => 'o.marketplace_order_id', 4 => 'i.total', 5 => 'i.status',
            6 => 'i.issued_at', 7 => 'i.issued_at',
        ];
        $orderBy  = $colMap[$dtCol] ?? 'i.issued_at';
        $orderDir = $dtDir === 'asc' ? 'ASC' : 'DESC';

        $filters = array_filter([
            'marketplace_id' => $_GET['marketplace_id'] ?? '',
            'status'         => $_GET['status'] ?? '',
            'invoice_flow'   => $_GET['invoice_flow'] ?? '',
            'date_from'      => $_GET['date_from'] ?? '',
            'date_to'        => $_GET['date_to'] ?? '',
            'search'         => $searchValue,
        ], fn($v) => $v !== '' && $v !== null);

        $result = $this->invoiceModel->getFilteredAjax($filters, $start, $length, $orderBy, $orderDir);

        $statusCounts = $this->invoiceModel->getStatusCounts(array_filter([
            'marketplace_id' => $_GET['marketplace_id'] ?? '',
            'date_from'      => $_GET['date_from'] ?? '',
            'date_to'        => $_GET['date_to'] ?? '',
        ], fn($v) => $v !== '' && $v !== null));

        $mpInvoiceCounts = $this->invoiceModel->getCountsByMarketplace();

        $rows = [];
        foreach ($result['data'] as $inv) {
            $rows[] = [
                'id'                     => (int) $inv['id'],
                'order_id'               => (int) ($inv['order_id'] ?? 0),
                'series'                 => $inv['series'] ?? '',
                'sequence_no'            => $inv['sequence_no'] ?? '',
                'invoice_number'         => $inv['invoice_number'] ?? '',
                'invoice_flow'           => $inv['invoice_flow'] ?? 'sale',
                'status'                 => $inv['status'] ?? 'draft',
                'customer_name'          => $inv['customer_name'] ?? '',
                'customer_tax_no'        => $inv['customer_tax_no'] ?? '',
                'marketplace_order_id'   => $inv['marketplace_order_id'] ?? '',
                'marketplace_name'       => $inv['marketplace_name'] ?? '',
                'marketplace_slug'       => $inv['marketplace_slug'] ?? '',
                'marketplace_id'         => (int) ($inv['marketplace_id'] ?? 0),
                'order_customer_id'      => $inv['order_customer_id'] ?? null,
                'subtotal'               => (float) ($inv['subtotal'] ?? 0),
                'tax_total'              => (float) ($inv['tax_total'] ?? 0),
                'total'                  => (float) ($inv['total'] ?? 0),
                'issued_at'              => $inv['issued_at'] ?? null,
                'cancelled_at'           => $inv['cancelled_at'] ?? null,
                'related_invoice_series' => $inv['related_invoice_series'] ?? null,
                'related_invoice_seq'    => $inv['related_invoice_seq'] ?? null,
                'related_invoice_id'     => $inv['related_invoice_id'] ?? null,
            ];
        }

        $this->json([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => $rows,
            'statusCounts'    => $statusCounts,
            'mpInvoiceCounts' => $mpInvoiceCounts,
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $invoice = $this->invoiceModel->find((int) $id);
        if ($invoice === false) {
            $this->response->notFound('Fatura bulunamadı');
        }

        // Fatura HTML'ini al
        $invoiceHtml = $this->invoiceService->getInvoiceHtml((int) $id);

        $this->view('invoices.show', [
            'pageTitle'   => 'Fatura #' . ($invoice['series'] ?? '') . ($invoice['sequence_no'] ?? ''),
            'invoice'     => $invoice,
            'invoiceHtml' => $invoiceHtml,
        ]);
    }

    /**
     * Fatura HTML olarak görüntüle (iframe/popup için)
     */
    public function viewHtml(Request $request, string $id): void
    {
        $invoice = $this->invoiceModel->find((int) $id);
        if ($invoice === false) {
            $this->response->notFound('Fatura bulunamadı');
        }

        $uuid = (string) ($invoice['invoice_uuid'] ?? '');
        $result = $this->invoiceService->getInvoiceHtmlResult((int) $id);
        $html = $result['html'];
        $error = trim((string) ($result['error'] ?? ''));

        if (!$html) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fatura görüntülenemedi</title></head><body style="margin:0;background:#f8fafc">';
            echo '<div style="max-width:860px;margin:40px auto;padding:0 20px;font-family:\'Segoe UI\',sans-serif;color:#334155">';
            echo '<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 18px 45px rgba(15,23,42,.08);overflow:hidden">';
            echo '<div style="padding:28px 32px;border-bottom:1px solid #e2e8f0">';
            echo '<div style="font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8">E-ARŞİV FATURA</div>';
            echo '<h1 style="margin:8px 0 0;font-size:28px;line-height:1.2;color:#0f172a">Görüntüleme şu anda tamamlanamadı</h1>';
            echo '</div>';
            echo '<div style="padding:28px 32px">';
            echo '<div style="padding:16px 18px;background:#fff7ed;border:1px solid #fdba74;border-radius:14px;color:#9a3412">';
            echo '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px">GİB YANITI</div>';
            echo '<div style="font-size:16px;line-height:1.6">' . htmlspecialchars($error !== '' ? $error : 'Fatura HTML belgesi GİB tarafından dönmedi.') . '</div>';
            echo '</div>';
            echo '<div style="margin-top:18px;display:grid;gap:12px">';
            echo '<div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px">';
            echo '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:6px">FATURA UUID</div>';
            echo '<div style="font-family:Consolas,\'Courier New\',monospace;font-size:14px;color:#0f172a;word-break:break-all">' . htmlspecialchars($uuid !== '' ? $uuid : '-') . '</div>';
            echo '</div>';
            echo '<div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;font-size:14px;line-height:1.7;color:#475569">';
            echo 'Fatura kesme işlemi ayrıdır; bu ekran yalnızca resmî HTML belgesini getirmeye çalışır. GİB bu belgeyi vermediği için görüntü açılamadı.';
            echo '</div>';
            echo '</div>';
            echo '<div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap">';
            echo '<button onclick="location.reload()" style="padding:12px 20px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer">Tekrar dene</button>';
            echo '<button onclick="window.close()" style="padding:12px 20px;background:#fff;color:#334155;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer">Pencereyi kapat</button>';
            echo '</div>';
            echo '</div>';
            echo '</body></html>';
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        // Wrap invoice HTML with a toolbar for print/download
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>e-Arşiv Fatura</title>';
        echo '<style>';
        echo '.inv-toolbar{position:fixed;top:0;left:0;right:0;z-index:9999;display:flex;align-items:center;justify-content:space-between;padding:8px 20px;background:#1e293b;box-shadow:0 2px 8px rgba(0,0,0,.2);font-family:"Segoe UI",sans-serif}';
        echo '.inv-toolbar-left{display:flex;align-items:center;gap:12px;color:#e2e8f0;font-size:13px;font-weight:600}';
        echo '.inv-toolbar-right{display:flex;gap:8px}';
        echo '.inv-btn{padding:7px 16px;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;font-family:inherit}';
        echo '.inv-btn-primary{background:#3b82f6;color:#fff}.inv-btn-primary:hover{background:#2563eb}';
        echo '.inv-btn-outline{background:transparent;border:1px solid #475569;color:#e2e8f0}.inv-btn-outline:hover{background:#334155}';
        echo 'body{padding-top:48px}';
        echo '@media print{.inv-toolbar{display:none!important}body{padding-top:0!important}}';
        echo '</style></head><body>';
        echo '<div class="inv-toolbar">';
        echo '<div class="inv-toolbar-left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> e-Arşiv Fatura</div>';
        echo '<div class="inv-toolbar-right">';
        echo '<button class="inv-btn inv-btn-primary" onclick="window.print()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> İndir / Yazdır</button>';
        echo '<button class="inv-btn inv-btn-outline" onclick="window.close()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Kapat</button>';
        echo '</div></div>';
        echo $html;
        echo '</body></html>';
        exit;
    }

    public function downloadHtml(Request $request, string $id): void
    {
        $invoice = $this->invoiceModel->find((int) $id);
        if ($invoice === false) {
            $this->response->notFound('Fatura bulunamadı');
        }

        $uuid = $invoice['invoice_uuid'] ?? '';
        $dir  = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices';
        $htmlPath = $dir . '/' . $uuid . '.html';

        // HTML dosyası yoksa GİB'den çek
        if (!file_exists($htmlPath) && !empty($uuid)) {
            $this->invoiceService->saveInvoiceHtml($uuid);
        }

        // HTML dosyası var mı?
        if (file_exists($htmlPath)) {
            $filename = ($invoice['series'] ?? 'FTR') . ($invoice['sequence_no'] ?? '') . '.html';
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($htmlPath));
            readfile($htmlPath);
            exit;
        }

        $this->response->notFound('Fatura dosyası bulunamadı');
    }

    public function cancel(Request $request, string $id): void
    {
        try {
            $reason = $request->post('reason', '');
            $this->invoiceService->cancel((int) $id, $reason);
            $this->redirectWithSuccess('/invoices', 'Fatura iptal edildi');
        } catch (\Throwable $e) {
            $this->redirectWithError('/invoices', 'İptal hatası: ' . $e->getMessage());
        }
    }

    /**
     * SMS onay (imza) sürecini başlat: GİB'de kayıtlı numaraya kod gönderilir.
     * Oturum anahtarı + operasyon ID doğrulama isteğine PHP session ile taşınır.
     */
    public function signSmsStart(Request $request, string $id): void
    {
        try {
            $invoice = $this->invoiceModel->findOrFail((int) $id);
            if (($invoice['status'] ?? '') !== 'draft') {
                $this->json(['success' => false, 'message' => 'Yalnızca taslak (imzasız) durumdaki faturalar onaylanabilir']);
                return;
            }

            $gib = $this->container->get(\App\Services\Invoice\GibEarsivProvider::class);
            $res = $gib->startSmsSign();
            if (empty($res['success'])) {
                $this->json(['success' => false, 'message' => (string) ($res['message'] ?? 'SMS onayı başlatılamadı')]);
                return;
            }

            $this->session->set('gib_sms_sign', [
                'oid'        => (string) $res['oid'],
                'token'      => (string) $res['token'],
                'invoice_id' => (int) $id,
                'expires'    => time() + 300,
            ]);

            $this->json(['success' => true, 'message' => (string) $res['message']]);
        } catch (\Throwable $e) {
            $this->container->get(\App\Core\Logger::class)->error('SMS onay başlatma hatası: ' . $e->getMessage(), 'InvoiceController');
            $this->json(['success' => false, 'message' => 'SMS onayı başlatılamadı — sistem kayıtlarını kontrol edin']);
        }
    }

    /**
     * SMS kodunu doğrula ve taslağı imzala; başarılıysa fatura 'sent' olur.
     */
    public function signSmsVerify(Request $request, string $id): void
    {
        try {
            $code = trim((string) $request->post('code', ''));
            if ($code === '' || !preg_match('/^\d{4,8}$/', $code)) {
                $this->json(['success' => false, 'message' => 'Geçerli bir SMS kodu girin']);
                return;
            }

            $state = $this->session->get('gib_sms_sign');
            if (!is_array($state)
                || (int) ($state['invoice_id'] ?? 0) !== (int) $id
                || time() > (int) ($state['expires'] ?? 0)
            ) {
                $this->json(['success' => false, 'message' => 'SMS onay oturumu bulunamadı veya süresi doldu — "SMS ile Onayla" ile yeniden başlatın']);
                return;
            }

            $invoice = $this->invoiceModel->findOrFail((int) $id);
            $uuid = (string) ($invoice['invoice_uuid'] ?? '');
            if ($uuid === '') {
                $this->json(['success' => false, 'message' => 'Faturanın GİB UUID bilgisi yok']);
                return;
            }

            $gib = $this->container->get(\App\Services\Invoice\GibEarsivProvider::class);
            $res = $gib->verifySmsSign((string) $state['oid'], $code, $uuid, (string) $state['token']);

            if (!empty($res['success'])) {
                $this->session->remove('gib_sms_sign');
                $this->invoiceModel->update((int) $id, ['status' => 'sent']);

                // İmzasızken önbelleğe alınmış taslak HTML'i kaldır — Görüntüle/İndir
                // bir sonraki istekte GİB'den İMZALI belgeyi çeksin
                $htmlPath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices/' . $uuid . '.html';
                if (is_file($htmlPath)) {
                    @unlink($htmlPath);
                }
            }

            $this->json($res);
        } catch (\Throwable $e) {
            $this->container->get(\App\Core\Logger::class)->error('SMS doğrulama hatası: ' . $e->getMessage(), 'InvoiceController');
            $this->json(['success' => false, 'message' => 'SMS doğrulama sırasında hata oluştu — sistem kayıtlarını kontrol edin']);
        }
    }

    /**
     * Fatura ayarları sayfası
     */
    public function settings(Request $request): void
    {
        // Per-store: fatura/GİB ayarları artık mağazaya özel (store_settings)
        $this->view('invoices.settings', [
            'pageTitle' => 'Fatura Ayarları',
            'values'    => $this->storeSetting->allForStore(),
        ]);
    }

    /**
     * Fatura ayarlarını kaydet (per-store, store_settings)
     */
    public function updateSettings(Request $request): void
    {
        $settings = $request->post('settings', []);
        if (!is_array($settings)) {
            $this->redirectWithError('/invoices/settings', 'Geçersiz veri');
            return;
        }

        // Yalnızca bilinen fatura anahtarları (store_settings'e rastgele anahtar yazılmasın)
        $pairs = [];
        foreach (self::INVOICE_KEYS as $k) {
            if (array_key_exists($k, $settings)) {
                $pairs[$k] = mb_substr(trim((string) $settings[$k]), 0, 1000);
            }
        }
        if ($pairs !== []) {
            $this->storeSetting->setMany($pairs);
        }

        $this->redirectWithSuccess('/invoices/settings', 'Fatura ayarları güncellendi');
    }

    /**
     * GİB bağlantı testi
     */
    public function testGibConnection(Request $request): void
    {
        try {
            $result = $this->invoiceService->testGibConnection();
            $this->json($result);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
