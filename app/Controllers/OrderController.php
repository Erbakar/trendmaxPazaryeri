<?php
/**
 * OzyConnect Panel — OrderController
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
use App\Models\Customer;
use App\Models\Marketplace;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Invoice\InvoiceService;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\PttavmService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Shipping\CargoLabelService;
use App\Services\StockManager;
use App\Services\Sync\OrderSyncService;

final class OrderController extends BaseController
{
    private readonly Order $orderModel;
    private readonly OrderItem $orderItemModel;
    private readonly Marketplace $marketplaceModel;
    private readonly Customer $customerModel;
    private readonly OrderSyncService $orderSync;
    private readonly InvoiceService $invoiceService;
    private readonly TrendyolService $trendyolService;
    private readonly N11Service $n11Service;
    private readonly PazaramaService $pazaramaService;
    private readonly CiceksepetiService $ciceksepetiService;
    private readonly PttavmService $pttavmService;
    private readonly HepsiburadaService $hepsiburadaService;
    private readonly CargoLabelService $cargoLabelService;
    private readonly StockManager $stockManager;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->orderModel        = $container->get(Order::class);
        $this->orderItemModel    = $container->get(OrderItem::class);
        $this->marketplaceModel  = $container->get(Marketplace::class);
        $this->customerModel     = $container->get(Customer::class);
        $this->orderSync         = $container->get(OrderSyncService::class);
        $this->invoiceService    = $container->get(InvoiceService::class);
        $this->trendyolService   = $container->get(TrendyolService::class);
        $this->n11Service        = $container->get(N11Service::class);
        $this->pazaramaService   = $container->get(PazaramaService::class);
        $this->ciceksepetiService = $container->get(CiceksepetiService::class);
        $this->pttavmService     = $container->get(PttavmService::class);
        $this->hepsiburadaService = $container->get(HepsiburadaService::class);
        $this->cargoLabelService = $container->get(CargoLabelService::class);
        $this->stockManager      = $container->get(StockManager::class);
    }

    public function index(Request $request): void
    {
        $filters = [
            'marketplace_id' => $request->query('marketplace_id'),
            'status'         => $request->query('status'),
            'date_from'      => $request->query('date_from'),
            'date_to'        => $request->query('date_to'),
            'search'         => $request->query('search'),
            'has_invoice'    => $request->query('has_invoice'),
            'amount_min'     => $request->query('amount_min'),
            'amount_max'     => $request->query('amount_max'),
        ];

        $statusCounts = $this->orderModel->getStatusCounts($filters);
        $marketplaces = $this->marketplaceModel->getAll();

        // Sekme görünürlüğü: bağlantısı girilmiş ya da siparişi olan pazaryerleri gösterilir.
        $mpConnected = [];
        foreach ($marketplaces as $mp) {
            $mpConnected[(int) $mp['id']] = $this->marketplaceModel->isConnectedForStore((int) $mp['id']);
        }

        $this->view('orders.index', [
            'pageTitle'      => 'Siparişler',
            'filters'        => $filters,
            'marketplaces'   => $marketplaces,
            'statusCounts'   => $statusCounts,
            'mpOrderCounts'  => $this->orderModel->getMarketplaceOrderCounts(),
            'mpNewCounts'    => $this->orderModel->getMarketplaceNewCounts(),
            'mpConnected'    => $mpConnected,
        ]);
    }

    /**
     * DataTables server-side AJAX endpoint
     */
    public function ajaxList(Request $request): void
    {
        $draw   = (int) ($_GET['draw'] ?? 1);
        $start  = (int) ($_GET['start'] ?? 0);
        $length = max(10, min(100, (int) ($_GET['length'] ?? 25)));

        $searchValue = trim((string) ($_GET['search']['value'] ?? ''));

        $dtCol = (int) ($_GET['order'][0]['column'] ?? 1);
        $dtDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc'));
        $colMap = [
            0 => 'o.ordered_at', 1 => 'o.ordered_at', 2 => 'o.ordered_at',
            3 => 'o.ordered_at', 4 => 'o.total_amount', 5 => 'o.cargo_company',
            6 => 'o.status', 7 => 'o.ordered_at', 8 => 'o.ordered_at',
        ];
        $orderBy  = $colMap[$dtCol] ?? 'o.ordered_at';
        $orderDir = $dtDir === 'asc' ? 'ASC' : 'DESC';

        $filters = array_filter([
            'marketplace_id' => $_GET['marketplace_id'] ?? '',
            'status'         => $_GET['status'] ?? '',
            'date_from'      => $_GET['date_from'] ?? '',
            'date_to'        => $_GET['date_to'] ?? '',
            'search'         => $searchValue,
            'show_archived'  => $_GET['show_archived'] ?? '',
        ], fn($v) => $v !== '' && $v !== null);

        $page   = (int) floor($start / max(1, $length)) + 1;
        $result = $this->orderModel->getFiltered($filters, $page, $length, $orderBy, $orderDir);
        $totalAll = $this->orderModel->getTotalCount();

        $statusCounts = $this->orderModel->getStatusCounts(array_filter([
            'marketplace_id' => $_GET['marketplace_id'] ?? '',
            'date_from'      => $_GET['date_from'] ?? '',
            'date_to'        => $_GET['date_to'] ?? '',
            'show_archived'  => $_GET['show_archived'] ?? '',
        ], fn($v) => $v !== '' && $v !== null));

        $rows = [];
        foreach ($result['data'] as $o) {
            $raw   = json_decode($o['raw_data_json'] ?? '{}', true) ?: [];
            // Trendyol/N11: lines[], Pazarama: items[], PttAVM: siparisUrunler[]
            $lines = $raw['lines'] ?? $raw['items'] ?? $raw['siparisUrunler'] ?? [];
            $first = $lines[0] ?? [];
            // Pazarama: product.name, product.imageURL, product.stockCode
            $firstProduct = $first['product'] ?? [];
            $imgs  = $first['images'] ?? [];

            // ÇiçekSepeti: raw data düz yapıda, lines/items yok
            $slug = $o['marketplace_slug'] ?? '';
            if ($slug === 'ciceksepeti') {
                $productName  = $raw['name'] ?? $raw['productName'] ?? ($o['item_names'] ?? '—');
                $productSku   = $raw['barcode'] ?? $raw['productCode'] ?? '';
                $productImage = '';
                $extraItems   = 0;
                $cargoCompany = $o['cargo_company'] ?? $raw['cargoCompany'] ?? '';
                $cargoTrackNo = (string) ($o['cargo_tracking_no'] ?? $raw['cargoNumber'] ?? '');
                $cargoLink    = $raw['shipmentTrackingUrl'] ?? '';
            } elseif ($slug === 'pttavm') {
                $pttItems     = $raw['siparisUrunler'] ?? [];
                $pttFirst     = $pttItems[0] ?? [];
                $productName  = $pttFirst['urun'] ?? $raw['urunAdi'] ?? ($o['item_names'] ?? '—');
                $productSku   = $pttFirst['urunBarkod'] ?? $raw['urunKodu'] ?? '';
                $productImage = $o['first_product_image'] ?? '';
                $extraItems   = max(0, count($pttItems) - 1);
                $cargoCompany = $o['cargo_company'] ?: (!empty($raw['kargoBarkod']) ? 'PTT Kargo' : '');
                $cargoTrackNo = (string) ($o['cargo_tracking_no'] ?? $raw['kargoBarkod'] ?? '');
                $cargoLink    = '';
            } else {
                $productName  = $first['productName']
                    ?? $first['product_name']
                    ?? $first['name']
                    ?? $first['urun_adi']
                    ?? $first['urunadi']
                    ?? $firstProduct['name']
                    ?? ($o['item_names'] ?? '—');
                $productSku   = $first['merchantSku']
                    ?? $first['sku']
                    ?? $firstProduct['stockCode']
                    ?? $first['stockCode']
                    ?? $first['product_code']
                    ?? $first['productCode']
                    ?? $first['urunkodu']
                    ?? $first['urun_kodu']
                    ?? ($o['first_item_sku'] ?? '');
                $productImage = ($imgs[0] ?? [])['url']
                    ?? $first['productImageUrl']
                    ?? $first['image']
                    ?? $first['main_image']
                    ?? $firstProduct['imageURL']
                    ?? $o['first_product_image']
                    ?? '';
                $extraItems   = max(0, (count($lines) > 0 ? count($lines) : (int) ($o['item_count'] ?? 0)) - 1);
                $cargoCompany = $o['cargo_company'] ?? $raw['cargoProviderName'] ?? '';
                $cargoTrackNo = (string) ($o['cargo_tracking_no'] ?? $raw['cargoTrackingNumber'] ?? '');
                $cargoLink    = $raw['cargoTrackingLink'] ?? '';
            }

            $rows[] = [
                'id'                   => (int) $o['id'],
                'marketplace_order_id' => $o['marketplace_order_id'] ?? '',
                'ordered_at'           => $o['ordered_at'] ?? $o['created_at'] ?? null,
                'customer_name'        => $o['customer_name'] ?? '',
                'product_name'         => $productName,
                'product_sku'          => $productSku,
                'product_image'        => $productImage,
                'extra_items'          => $extraItems,
                'total_amount'         => (float) ($o['total_amount'] ?? 0),
                'cargo_company'        => $cargoCompany,
                'cargo_tracking_no'    => $cargoTrackNo,
                'cargo_tracking_link'  => $cargoLink,
                'status'               => $o['status'] ?? '',
                'invoice_id'           => $o['invoice_id'] ? (int) $o['invoice_id'] : null,
                'invoice_sent_to_mp'   => (int) ($o['invoice_sent_to_marketplace'] ?? 0),
                'is_archived'          => (int) ($o['is_archived'] ?? 0),
                'marketplace_slug'     => $o['marketplace_slug'] ?? '',
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $totalAll,
            'recordsFiltered' => $result['total'],
            'data'            => $rows,
            'statusCounts'    => $statusCounts,
            'mpOrderCounts'   => $this->orderModel->getMarketplaceOrderCounts(),
            'mpNewCounts'     => (object) $this->orderModel->getMarketplaceNewCounts(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function show(Request $request, string $id): void
    {
        $order = $this->orderModel->getDetail((int) $id);
        if ($order === false) {
            $this->response->notFound('Sipariş bulunamadı');
        }

        $items = $this->orderItemModel->getByOrderId((int) $id);

        // Müşteri geçmiş siparişleri
        $customerOrders = [];
        if (!empty($order['customer_id'])) {
            $customerOrders = $this->customerModel->getOrders((int) $order['customer_id']);
        }

        $this->view('orders.show', [
            'pageTitle'      => "Sipariş #{$order['marketplace_order_id']}",
            'order'          => $order,
            'items'          => $items,
            'customerOrders' => $customerOrders,
            'shippingConfig' => $this->getShippingConfig((int) ($order['marketplace_id'] ?? 0), $order['marketplace_slug'] ?? ''),
        ]);
    }

    /**
     * GET /orders/{id}/quick — Hızlı-bakış drawer için sipariş detayı (JSON):
     * tüm kalemler + döküm + müşteri. show() ile AYNI tenant+entitlement-scoped modelleri kullanır.
     */
    public function quickDetail(Request $request, string $id): void
    {
        $order = $this->orderModel->getDetail((int) $id);
        if ($order === false) {
            $this->json(['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
        }
        $items = $this->orderItemModel->getByOrderId((int) $id);
        $this->json(['success' => true, 'order' => $order, 'items' => $items]);
    }

    /**
     * GET /orders/export — Mevcut filtrelerle siparişleri CSV indir (tenant+entitlement-scoped).
     */
    public function export(Request $request): void
    {
        $filters = array_filter([
            'marketplace_id' => trim((string) ($_GET['marketplace_id'] ?? '')),
            'status'         => trim((string) ($_GET['status'] ?? '')),
            'date_from'      => trim((string) ($_GET['date_from'] ?? '')),
            'date_to'        => trim((string) ($_GET['date_to'] ?? '')),
            'search'         => trim((string) ($_GET['search'] ?? '')),
            'show_archived'  => trim((string) ($_GET['show_archived'] ?? '')),
        ], static fn($v) => $v !== '' && $v !== null);

        $result = $this->orderModel->getFiltered($filters, 1, 10000);
        $rows   = $result['data'] ?? [];

        $labels = [
            'beklemede'=>'Beklemede','yeni'=>'Yeni','hazirlaniyor'=>'Hazırlanıyor','faturalandi'=>'Faturalandı',
            'kargoda'=>'Kargoda','teslim_noktasinda'=>'Teslim Noktasında','teslim_edildi'=>'Teslim Edildi',
            'teslim_edilemedi'=>'Teslim Edilemedi','iptal'=>'İptal','iade'=>'İade',
        ];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="siparisler-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel TR uyumu)
        fputcsv($out, ['Sipariş No', 'Pazaryeri', 'Müşteri', 'Adet', 'Tutar', 'Durum', 'Tarih'], ';');
        foreach ($rows as $o) {
            $qty = (int) ($o['item_count'] ?? 0);
            if ($qty <= 0) { $qty = (int) ($o['extra_items'] ?? 0) + 1; }
            $st = (string) ($o['status'] ?? '');
            fputcsv($out, [
                (string) ($o['marketplace_order_id'] ?? ''),
                (string) ($o['marketplace_name'] ?? $o['marketplace_slug'] ?? ''),
                (string) ($o['customer_name'] ?? ''),
                $qty,
                number_format((float) ($o['total_amount'] ?? 0), 2, ',', '.'),
                $labels[$st] ?? $st,
                (string) ($o['ordered_at'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    public function sync(Request $request): void
    {
        try {
            $forceResync = $request->post('force_resync', '0') === '1';

            if ($forceResync) {
                $results = $this->orderSync->forceResyncAll('manual');
            } else {
                $results = $this->orderSync->syncAll('manual');
            }

            if ($request->isAjax()) {
                $errors = $results['errors'] ?? [];
                $msg = "Senkronizasyon: {$results['fetched']} çekildi, {$results['processed']} yeni, {$results['skipped']} güncellendi";
                if (!empty($errors)) {
                    $failedMps = array_map(fn($e) => $e['marketplace'] ?? 'bilinmeyen', $errors);
                    $msg .= ' (' . count($errors) . ' pazaryeri hatalı: ' . implode(', ', $failedMps) . ')';
                }
                $this->success($results, $msg);
            }
            $msg = "Senkronizasyon: {$results['fetched']} çekildi, {$results['processed']} yeni, {$results['skipped']} güncellendi";
            $this->redirectWithSuccess('/orders', $msg);
        } catch (\Throwable $e) {
            $this->logger->error('Sipariş sync hatası: ' . $e->getMessage(), 'OrderController');
            if ($request->isAjax()) {
                $this->error('Senkronizasyon hatası: ' . $e->getMessage());
            }
            $this->redirectWithError('/orders', 'Senkronizasyon hatası: ' . $e->getMessage());
        }
    }

    /**
     * SSE Stream — Canlı senkronizasyon ilerleme akışı
     */
    public function syncStream(Request $request): void
    {
        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Output buffering kapat
        while (ob_get_level()) { ob_end_clean(); }
        set_time_limit(0);

        $sendEvent = function (string $event, array $data) {
            echo "event: {$event}\n";
            echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        try {
            $forceResync = $_POST['force_resync'] ?? '0';

            if ($forceResync === '1') {
                $sendEvent('info', ['message' => 'Tüm siparişler temizleniyor...']);
                $results = $this->orderSync->forceResyncAll('manual');
                $sendEvent('complete', [
                    'success' => true,
                    'results' => $results,
                    'message' => "Sıfırlama tamamlandı: {$results['fetched']} çekildi, {$results['processed']} yeni",
                ]);
            } else {
                $results = $this->orderSync->syncAll('manual', function (string $event, string $slug, string $displayName, array $result = []) use ($sendEvent) {
                    $sendEvent($event, [
                        'slug'         => $slug,
                        'display_name' => $displayName,
                        'result'       => $result,
                    ]);
                });

                $sendEvent('complete', [
                    'success' => true,
                    'results' => $results,
                    'message' => "Senkronizasyon: {$results['fetched']} çekildi, {$results['processed']} yeni, {$results['skipped']} güncellendi",
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('SSE sync hatası: ' . $e->getMessage(), 'OrderController');
            $sendEvent('complete', [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
            ]);
        }

        exit;
    }

    public function createInvoice(Request $request, string $id): void
    {
        try {
            $type = $request->post('type', 'e-arsiv');
            $invoiceId = $this->invoiceService->createForOrder((int) $id, $type);

            // Taslak oluştu ama imzalanamadıysa kullanıcıyı bilgilendir (sahte "başarılı" yok)
            $invoice = $this->container->get(\App\Models\Invoice::class)->find((int) $invoiceId);
            if (is_array($invoice) && ($invoice['status'] ?? '') === 'draft') {
                $this->redirectWithSuccess(
                    "/orders/{$id}",
                    'Fatura taslağı GİB\'de oluşturuldu ancak henüz imzalanmadı. '
                    . 'Faturalar sayfasından fatura detayına girip "SMS ile Onayla" ile imzalayabilirsiniz.'
                );
            }

            $this->redirectWithSuccess("/orders/{$id}", 'Fatura başarıyla oluşturuldu');
        } catch (\Throwable $e) {
            $this->redirectWithError("/orders/{$id}", 'Fatura hatası: ' . $e->getMessage());
        }
    }

    public function bulkInvoice(Request $request): void
    {
        $ids = $request->post('order_ids', []);
        if (empty($ids) || !is_array($ids)) {
            $this->redirectWithError('/orders', 'Sipariş seçilmedi');
        }

        $result = $this->invoiceService->createBulk(array_map('intval', $ids));
        $this->redirectWithSuccess('/orders', "{$result['success']} fatura oluşturuldu, {$result['failed']} hatalı");
    }

    /**
     * Kargo etiketi indir (PDF)
     */
    public function cargoLabel(Request $request, string $id): void
    {
        $order = $this->orderModel->getDetail((int) $id);
        if ($order === false) {
            $this->response->notFound('Sipariş bulunamadı');
        }

        $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
        $trackingNo = $order['cargo_tracking_no'] ?? $rawData['cargoTrackingNumber'] ?? '';
        $filename = "label_{$order['marketplace_order_id']}.pdf";
        $slug = $order['marketplace_slug'] ?? '';

        // Daha önce oluşturulmuş mu?
        if ($this->cargoLabelService->labelExists($filename)) {
            $this->sendFile($this->cargoLabelService->getLabelPath($filename), $filename);
            return;
        }

        $zplContent = null;

        // N11, Pazarama veya ÇiçekSepeti siparişleri: custom etiket oluştur
        if ($slug === 'n11' || $slug === 'pazarama' || $slug === 'ciceksepeti') {
            $zplContent = $this->cargoLabelService->generateCustomLabel($order);
        } elseif ($slug === 'hepsiburada') {
            // Hepsiburada: Paket numarasından etiket almayı dene
            $packageNumber = (string) ($rawData['packageNumber'] ?? $rawData['id'] ?? '');
            if (!empty($packageNumber)) {
                try {
                    $this->hepsiburadaService->loadCredentials();
                    $labelResult = $this->hepsiburadaService->getPackageLabels($packageNumber, 'pdf');
                    if (!empty($labelResult['success']) && !empty($labelResult['data'])) {
                        // PDF binary olarak geliyorsa direkt dosya olarak kaydet
                        $labelDir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/labels/';
                        if (!is_dir($labelDir)) {
                            mkdir($labelDir, 0755, true);
                        }
                        $labelPath = $labelDir . $filename;
                        file_put_contents($labelPath, $labelResult['data']);
                        $this->sendFile($labelPath, $filename);
                        return;
                    }
                } catch (\Throwable $e) {
                    // API'den alınamadı — fallback custom etiket
                }
            }
            $zplContent = $this->cargoLabelService->generateCustomLabel($order);
        } elseif ($slug === 'pttavm') {
            // PttAVM: Kargo barkod etiketini PttAVM API'den al
            $kargoBarkod = $rawData['kargoBarkod'] ?? $order['cargo_tracking_no'] ?? '';
            $mpOrderId = $order['marketplace_order_id'] ?? '';
            if (!empty($kargoBarkod) && !empty($mpOrderId)) {
                $htmlLabel = $this->pttavmService->getBarcodeLabel($kargoBarkod, $mpOrderId);
                if (!empty($htmlLabel)) {
                    // HTML etiketi doğrudan PDF olarak kaydet
                    $labelPath = $this->cargoLabelService->htmlToPdf($htmlLabel, $filename);
                    if ($labelPath !== null) {
                        $this->sendFile($labelPath, $filename);
                        return;
                    }
                }
            }
            // Fallback: custom etiket oluştur
            $zplContent = $this->cargoLabelService->generateCustomLabel($order);
        } else {
            // Trendyol: Common Label dene
            $shippingConfig = $this->getShippingConfig((int) ($order['marketplace_id'] ?? 0), $slug);
            if (!$this->canUseCommonLabel($shippingConfig, (string) ($order['cargo_company'] ?? $rawData['cargoProviderName'] ?? ''))) {
                $trackingNo = '';
            }

            // 1. Trendyol API'den etiket almayı dene (TY Pays model)
            if (!empty($trackingNo)) {
                $this->trendyolService->createCommonLabel((string) $trackingNo);
                usleep(500000);
                $labelResult = $this->trendyolService->getCommonLabel((string) $trackingNo);
                if ($labelResult['success'] && !empty($labelResult['labels'])) {
                    $zplContent = $labelResult['labels'][0]['label'] ?? null;
                }
            }

            // 2. API'den alınamadıysa kendi etiketimizi oluştur
            if (empty($zplContent)) {
                $zplContent = $this->cargoLabelService->generateCustomLabel($order);
            }
        }

        // ZPL → PDF dönüştür
        $pdfPath = $this->cargoLabelService->zplToPdf($zplContent, $filename);

        if ($pdfPath === null) {
            $this->redirectWithError("/orders/{$id}", 'Kargo etiketi oluşturulamadı');
            return;
        }

        $this->sendFile($pdfPath, $filename);
    }

    /**
     * Kargo etiketi önizleme (PNG — AJAX)
     */
    public function cargoLabelPreview(Request $request, string $id): void
    {
        $order = $this->orderModel->getDetail((int) $id);
        if ($order === false) {
            $this->error('Sipariş bulunamadı', 404);
        }

        $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
        $trackingNo = $order['cargo_tracking_no'] ?? $rawData['cargoTrackingNumber'] ?? '';
        $slug = $order['marketplace_slug'] ?? '';

        $zplContent = null;

        if ($slug === 'n11' || $slug === 'ciceksepeti') {
            // N11 / ÇiçekSepeti: her zaman custom etiket
            $zplContent = $this->cargoLabelService->generateCustomLabel($order);
        } else {
            // Trendyol'dan dene
            $shippingConfig = $this->getShippingConfig((int) ($order['marketplace_id'] ?? 0), $slug);
            if (!$this->canUseCommonLabel($shippingConfig, (string) ($order['cargo_company'] ?? $rawData['cargoProviderName'] ?? ''))) {
                $trackingNo = '';
            }

            if (!empty($trackingNo)) {
                $labelResult = $this->trendyolService->getCommonLabel((string) $trackingNo);
                if ($labelResult['success'] && !empty($labelResult['labels'])) {
                    $zplContent = $labelResult['labels'][0]['label'] ?? null;
                }
            }

            // Yoksa kendi etiketimizi oluştur
            if (empty($zplContent)) {
                $zplContent = $this->cargoLabelService->generateCustomLabel($order);
            }
        }

        $pngBase64 = $this->cargoLabelService->zplToPng($zplContent);

        $this->json([
            'success' => $pngBase64 !== null,
            'image'   => $pngBase64,
            'source'  => !empty($trackingNo) ? 'trendyol' : 'custom',
        ]);
    }

    /**
     * Faturayı Trendyol'a gönder (invoice link veya file)
     */
    /**
     * Sipariş arşivle / arşivden çıkar (AJAX)
     */
    public function archive(Request $request, string $id): void
    {
        $order = $this->orderModel->find((int) $id);
        if ($order === false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı']);
            exit;
        }

        $newState = (int) $order['is_archived'] === 1 ? 0 : 1;
        $this->orderModel->update((int) $id, ['is_archived' => $newState]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'archived' => $newState,
            'message' => $newState ? 'Sipariş arşivlendi' : 'Sipariş arşivden çıkarıldı',
        ]);
        exit;
    }

    /**
     * Pazaryerinin indirebileceği herkese açık fatura adresi.
     *
     * Pazaryerleri linki KENDİ sunucularından çeker; bu yüzden adres
     *  - oturum istemeyen bir uç noktaya bakmalı,
     *  - ".html" uzantısıyla bitmeli (HB "Url must be pdf or html link" der),
     *  - text/html Content-Type döndürmelidir.
     *
     * @param array<string, mixed> $invoice
     * @return string boş string = public adres üretilemedi (localhost vb.)
     */
    private function publicInvoiceUrl(array|false $invoice): string
    {
        if ($invoice === false) {
            return '';
        }

        $uuid = trim((string) ($invoice['invoice_uuid'] ?? ''));
        if ($uuid === '') {
            return '';
        }

        $appUrl = rtrim((string) $this->container->config('app.url', ''), '/');
        if ($appUrl === ''
            || str_contains($appUrl, 'localhost')
            || str_contains($appUrl, '127.0.0.1')
            || !str_starts_with($appUrl, 'http')
        ) {
            return '';
        }

        // Belge yoksa GİB'den çekip diske yaz — pazaryeri linke bastığında hazır olsun.
        $path = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices/' . $uuid . '.html';
        if (!file_exists($path)) {
            try {
                $this->invoiceService->saveInvoiceHtml($uuid);
            } catch (\Throwable $e) {
                $this->logger->warning('Fatura HTML GİB\'den alınamadı: ' . $e->getMessage(), 'Invoice');
            }
        }

        return $appUrl . '/fatura/' . rawurlencode($uuid) . '.html';
    }

    public function sendInvoiceToMarketplace(Request $request, string $id): void
    {
        $order = $this->orderModel->getDetail((int) $id);
        if ($order === false || empty($order['invoice_id'])) {
            $this->redirectWithError("/orders/{$id}", 'Sipariş veya fatura bulunamadı');
        }

        $slug = $order['marketplace_slug'] ?? '';

        // N11'de fatura gönderme API'si henüz yok
        if ($slug === 'n11') {
            $this->redirectWithError("/orders/{$id}", 'N11 REST API\'de fatura gönderme henüz desteklenmiyor');
            return;
        }

        // Pazarama fatura gönder (invoiceLink endpoint)
        if ($slug === 'pazarama') {
            $this->sendInvoiceToPazarama($order, $id);
            return;
        }

        // ÇiçekSepeti fatura gönder (SendInvoiceMail endpoint)
        if ($slug === 'ciceksepeti') {
            $this->sendInvoiceToCiceksepeti($order, $id);
            return;
        }

        // Hepsiburada fatura gönder (package invoice link)
        if ($slug === 'hepsiburada') {
            $this->sendInvoiceToHepsiburada($order, $id);
            return;
        }

        $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
        $shipmentPackageId = (int) ($rawData['shipmentPackageId'] ?? $rawData['id'] ?? 0);

        if ($shipmentPackageId === 0) {
            $this->redirectWithError("/orders/{$id}", 'Paket ID bulunamadı');
        }

        // Fatura HTML dosyasını Trendyol'a gönder
        $invoice = $this->container->get(\App\Models\Invoice::class)->find((int) $order['invoice_id']);
        $uuid = $invoice['invoice_uuid'] ?? '';
        $htmlPath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices/' . $uuid . '.html';

        // HTML dosyası yoksa GİB'den çekmeyi dene
        if (!file_exists($htmlPath) && !empty($uuid)) {
            $this->invoiceService->saveInvoiceHtml($uuid);
        }

        if (file_exists($htmlPath)) {
            // Dosya olarak gönder (link localhost'ta çalışmaz, Trendyol erişemez)
            $result = $this->trendyolService->sendInvoiceFile($shipmentPackageId, $htmlPath);

            // Dosya başarısız olursa link ile dene (public URL varsa)
            if (!$result['success']) {
                $invoiceUrl = $this->publicInvoiceUrl($invoice);
                if ($invoiceUrl !== '') {
                    $result = $this->trendyolService->sendInvoiceLink($shipmentPackageId, $invoiceUrl);
                }
            }
        } else {
            $result = ['success' => false, 'message' => 'Fatura HTML dosyası bulunamadı ve GİB\'den çekilemedi'];
        }

        if ($result['success']) {
            // Fatura gönderildi olarak işaretle
            $this->orderModel->update((int) $id, ['invoice_sent_to_marketplace' => 1]);
            $this->redirectWithSuccess("/orders/{$id}", 'Fatura Trendyol\'a gönderildi');
        } else {
            $this->redirectWithError("/orders/{$id}", 'Trendyol gönderim hatası: ' . ($result['message'] ?? ''));
        }
    }

    /**
     * Siparişi kargola — Trendyol: Picking → Invoiced → Shipped + Etiket | N11: Picking (onaylama)
     */
    public function shipOrder(Request $request, string $id): void
    {
        try {
            $order = $this->orderModel->getDetail((int) $id);
            if ($order === false) {
                $this->error('Sipariş bulunamadı', 404);
                return;
            }

            $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
            $pkgId = (string) ($rawData['shipmentPackageId'] ?? $rawData['id'] ?? '');
            $slug  = $order['marketplace_slug'] ?? '';

            if (empty($pkgId)) {
                $this->error('Shipment Package ID bulunamadı');
                return;
            }

            // N11 sipariş onaylama
            if ($slug === 'n11') {
                $this->shipOrderN11($order, $rawData, $request);
                return;
            }

            // Pazarama sipariş kargolama
            if ($slug === 'pazarama') {
                $this->shipOrderPazarama($order, $rawData, $request);
                return;
            }

            // ÇiçekSepeti sipariş kargolama
            if ($slug === 'ciceksepeti') {
                $this->shipOrderCiceksepeti($order, $rawData, $request);
                return;
            }

            // PttAVM sipariş kargolama — kargo barkod oluştur
            if ($slug === 'pttavm') {
                $this->shipOrderPttavm($order, $rawData, $request);
                return;
            }

            // Hepsiburada sipariş kargolama
            if ($slug === 'hepsiburada') {
                $this->shipOrderHepsiburada($order, $rawData, $request, $id);
                return;
            }

            // WooCommerce: kargo bilgisi + "Tamamlandı" (aşağıdaki Trendyol akışına DÜŞMEMELİ)
            if ($slug === 'woocommerce') {
                $this->shipOrderWooCommerce($order, $request);
                return;
            }

            $trackingNo   = $request->post('tracking_no', '');
            $cargoCompany = $request->post('cargo_company', '');
            $shippingConfig = $this->getShippingConfig((int) ($order['marketplace_id'] ?? 0), $slug);

            // Trendyol: eğer kargo seçilmemişse API'den gelen kargo firmasını kullan
            if ($slug === 'trendyol' && empty($cargoCompany)) {
                $cargoCompany = $this->normalizeCargoCompany(
                    (string) ($rawData['cargoProviderName'] ?? $order['cargo_company'] ?? '')
                );
            }
            // Trendyol: eğer takip no girilmemişse API'den gelen varsa kullan
            if ($slug === 'trendyol' && empty($trackingNo)) {
                $trackingNo = (string) ($order['cargo_tracking_no'] ?? $rawData['cargoTrackingNumber'] ?? '');
            }

            $steps = [];

            // 1) Picking
            try {
                $this->trendyolService->updateOrderStatus($pkgId, 'Picking');
                $steps[] = ['step' => 'Picking', 'ok' => true];
            } catch (\Throwable $e) {
                // Zaten Picking'den geçmiş olabilir — devam
                $steps[] = ['step' => 'Picking', 'ok' => false, 'msg' => $e->getMessage()];
            }

            // 2) Invoiced
            try {
                $this->trendyolService->updateOrderStatus($pkgId, 'Invoiced');
                $steps[] = ['step' => 'Invoiced', 'ok' => true];
            } catch (\Throwable $e) {
                $steps[] = ['step' => 'Invoiced', 'ok' => false, 'msg' => $e->getMessage()];
            }

            // 3) Shipped — tracking no ile
            if (!empty($trackingNo) && !empty($cargoCompany)) {
                try {
                    $this->trendyolService->updateCargoInfo($pkgId, $trackingNo, $cargoCompany);
                    $steps[] = ['step' => 'Shipped', 'ok' => true];

                    // DB güncelle
                    $this->orderModel->update((int) $id, [
                        'status'            => 'kargoda',
                        'cargo_tracking_no' => $trackingNo,
                        'cargo_company'     => $cargoCompany,
                        'cargo_sent_at'     => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {
                    $steps[] = ['step' => 'Shipped', 'ok' => false, 'msg' => $e->getMessage()];
                }
            }

            // 4) Common Label (TY Ödemeli ise)
            $labelAvailable = false;
            $labelSource = 'none';
            $actualTrackingNo = $trackingNo ?: ($order['cargo_tracking_no'] ?? $rawData['cargoTrackingNumber'] ?? '');

            if (!empty($actualTrackingNo) && $this->canUseCommonLabel($shippingConfig, $cargoCompany ?: (string) ($order['cargo_company'] ?? $rawData['cargoProviderName'] ?? ''))) {
                try {
                    $this->trendyolService->createCommonLabel($actualTrackingNo);
                    usleep(800000); // 0.8s bekle
                    $labelResult = $this->trendyolService->getCommonLabel($actualTrackingNo);

                    if ($labelResult['success'] && !empty($labelResult['labels'])) {
                        $labelAvailable = true;
                        $labelSource = 'trendyol';
                    }
                } catch (\Throwable $e) {
                    // TY etiket alınamadı — kendiniz basabilirsiniz
                }
            }

            $this->success([
                'steps'          => $steps,
                'labelAvailable' => $labelAvailable,
                'labelSource'    => $labelSource,
                'trackingNo'     => $actualTrackingNo,
                'autoDownloadLabel' => $labelAvailable && !empty($shippingConfig['auto_label']),
            ], 'Sipariş kargolama işlemi tamamlandı');

        } catch (\Throwable $e) {
            $this->error('Kargolama hatası: ' . $e->getMessage());
        }
    }

    /**
     * Dosya gönder (PDF indirme)
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function loadReturnPageData(array $filters, int $page): array
    {
        $pageSize = 50;
        $trendyolMarketplace = $this->marketplaceModel->findBySlug('trendyol');
        $selectedMarketplaceId = (int) ($filters['marketplace_id'] ?? 0);
        $trendyolMarketplaceId = (int) ($trendyolMarketplace['id'] ?? 0);

        $returns = [
            'data'       => [],
            'total'      => 0,
            'page'       => $page,
            'perPage'    => $pageSize,
            'totalPages' => 1,
        ];
        $claimIssueReasons = [];
        $loadError = null;

        if ($selectedMarketplaceId === 0 || $selectedMarketplaceId === $trendyolMarketplaceId) {
            try {
                $response = $this->trendyolService->fetchReturnsResponse([
                    'page'      => $page - 1,
                    'size'      => $pageSize,
                    'status'    => $filters['status'] ?? '',
                    'date_from' => $filters['date_from'] ?? '',
                    'date_to'   => $filters['date_to'] ?? '',
                ]);

                $returns = [
                    'data'       => $this->hydrateTrendyolReturns($response['content'] ?? [], $trendyolMarketplaceId),
                    'total'      => (int) ($response['totalElements'] ?? 0),
                    'page'       => max(1, (int) (($response['page'] ?? 0) + 1)),
                    'perPage'    => (int) ($response['size'] ?? $pageSize),
                    'totalPages' => max(1, (int) ($response['totalPages'] ?? 1)),
                ];
                $claimIssueReasons = $this->trendyolService->fetchClaimIssueReasons();
            } catch (\Throwable $e) {
                $loadError = $e->getMessage();
                $this->logger->error('Trendyol iade listesi alinamadi: ' . $e->getMessage(), 'OrderController');
            }
        }

        return [
            'returns'           => $returns,
            'claimIssueReasons' => $claimIssueReasons,
            'loadError'         => $loadError,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $claims
     * @return array<int, array<string, mixed>>
     */
    private function hydrateTrendyolReturns(array $claims, int $marketplaceId): array
    {
        $rows = [];

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }

            $claimLineItemIds = [];
            $items = [];
            $statuses = [];
            $primaryReason = '';
            $primaryReasonCode = '';

            foreach ($claim['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $orderLine = is_array($item['orderLine'] ?? null) ? $item['orderLine'] : [];
                foreach ($item['claimItems'] ?? [] as $claimItem) {
                    if (!is_array($claimItem)) {
                        continue;
                    }

                    $claimLineItemId = (string) ($claimItem['id'] ?? '');
                    if ($claimLineItemId !== '') {
                        $claimLineItemIds[] = $claimLineItemId;
                    }

                    $reason = $claimItem['trendyolClaimItemReason']['name']
                        ?? $claimItem['customerClaimItemReason']['name']
                        ?? '';
                    $reasonCode = $claimItem['trendyolClaimItemReason']['code']
                        ?? $claimItem['customerClaimItemReason']['code']
                        ?? '';
                    $status = (string) ($claimItem['claimItemStatus']['name'] ?? '');

                    if ($primaryReason === '' && $reason !== '') {
                        $primaryReason = (string) $reason;
                        $primaryReasonCode = (string) $reasonCode;
                    }
                    if ($status !== '') {
                        $statuses[] = $status;
                    }

                    $items[] = [
                        'claim_line_item_id' => $claimLineItemId,
                        'product_name'       => (string) ($orderLine['productName'] ?? ''),
                        'merchant_sku'       => (string) ($orderLine['merchantSku'] ?? ''),
                        'barcode'            => (string) ($orderLine['barcode'] ?? ''),
                        'reason'             => (string) $reason,
                        'reason_code'        => (string) $reasonCode,
                        'status'             => $status,
                    ];
                }
            }

            $orderNumber = (string) ($claim['orderNumber'] ?? '');
            $localOrder = $orderNumber !== '' && $marketplaceId > 0
                ? $this->orderModel->findByMarketplaceOrderId($marketplaceId, $orderNumber)
                : false;

            $claimDate = $this->formatClaimTimestamp($claim['claimDate'] ?? null);
            $customerName = trim((string) ($claim['customerFirstName'] ?? '') . ' ' . (string) ($claim['customerLastName'] ?? ''));
            $statusRaw = $statuses[0] ?? 'Created';

            $rows[] = [
                'claim_id'               => (string) ($claim['claimId'] ?? $claim['id'] ?? ''),
                'marketplace_return_id'  => (string) ($claim['claimId'] ?? $claim['id'] ?? ''),
                'marketplace_name'       => 'Trendyol',
                'marketplace_slug'       => 'trendyol',
                'marketplace_order_id'   => $orderNumber,
                'order_id'               => $localOrder['id'] ?? null,
                'customer_name'          => $customerName,
                'reason'                 => $primaryReason,
                'reason_code'            => $primaryReasonCode,
                'status'                 => $this->mapClaimStatus($statusRaw),
                'status_raw'             => $statusRaw,
                'claim_item_ids'         => array_values(array_unique(array_filter($claimLineItemIds))),
                'items'                  => $items,
                'item_count'             => count($items),
                'cargo_company'          => (string) ($claim['cargoProviderName'] ?? ''),
                'cargo_tracking_no'      => (string) ($claim['cargoTrackingNumber'] ?? ''),
                'cargo_tracking_link'    => (string) ($claim['cargoTrackingLink'] ?? ''),
                'created_at'             => $claimDate,
                'claim_date'             => $claimDate,
                'raw_data'               => $claim,
            ];
        }

        return $rows;
    }

    private function mapClaimStatus(string $status): string
    {
        return match ($status) {
            'Created'           => 'created',
            'WaitingInAction'   => 'waiting_in_action',
            'WaitingFraudCheck' => 'waiting_fraud_check',
            'Accepted'          => 'accepted',
            'Rejected'          => 'rejected',
            'Cancelled'         => 'cancelled',
            default             => strtolower($status),
        };
    }

    private function formatClaimTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', intdiv((int) $value, 1000));
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @return array{ty_cargo_payment: bool, default_cargo: string, auto_label: bool, marketplace_slug: string}
     */
    private function getShippingConfig(int $marketplaceId, string $slug = ''): array
    {
        $marketplace = $marketplaceId > 0 ? $this->marketplaceModel->find($marketplaceId) : false;
        $extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];

        if ($slug === 'n11') {
            return [
                'ty_cargo_payment' => false,
                'default_cargo'    => '',
                'auto_label'       => true,
                'marketplace_slug' => 'n11',
            ];
        }

        if ($slug === 'pazarama') {
            return [
                'ty_cargo_payment' => false,
                'default_cargo'    => '',
                'auto_label'       => false,
                'marketplace_slug' => 'pazarama',
            ];
        }

        if ($slug === 'ciceksepeti') {
            return [
                'ty_cargo_payment' => false,
                'default_cargo'    => '',
                'auto_label'       => true,
                'marketplace_slug' => 'ciceksepeti',
            ];
        }

        return [
            'ty_cargo_payment' => !empty($extraConfig['ty_cargo_payment']),
            'default_cargo'    => (string) ($extraConfig['ty_default_cargo'] ?? $extraConfig['ty_cargo_provider'] ?? ''),
            'auto_label'       => !empty($extraConfig['ty_auto_label']),
            'marketplace_slug' => $slug ?: ($marketplace['slug'] ?? ''),
        ];
    }

    /**
     * @param array{ty_cargo_payment: bool, default_cargo: string, auto_label: bool} $shippingConfig
     */
    private function canUseCommonLabel(array $shippingConfig, string $cargoCompany): bool
    {
        if (!$shippingConfig['ty_cargo_payment']) {
            return false;
        }

        return in_array($this->normalizeCargoCompany($cargoCompany), ['TEXKARGO', 'ARASKARGO'], true);
    }

    private function normalizeCargoCompany(string $cargoCompany): string
    {
        $normalized = strtoupper(trim($cargoCompany));
        $normalized = str_replace([' ', '-', '_'], '', $normalized);

        return match (true) {
            str_contains($normalized, 'TEX')  => 'TEXKARGO',
            str_contains($normalized, 'ARAS') => 'ARASKARGO',
            default                           => $normalized,
        };
    }

    /**
     * N11 sipariş onaylama (Picking) + kargo etiket
     */
    private function shipOrderN11(array $order, array $rawData, Request $request): void
    {
        $lines = $rawData['lines'] ?? [];
        $steps = [];

        // 1) Tüm line'ları Picking'e al
        $lineIds = [];
        foreach ($lines as $line) {
            if (!empty($line['orderLineId'])) {
                $lineIds[] = (int) $line['orderLineId'];
            }
        }

        if (empty($lineIds)) {
            $this->error('Sipariş kalem ID\'leri bulunamadı');
            return;
        }

        try {
            // N11 updateOrderStatus: tüm lineId'leri virgülle birleştirip gönder
            $lineIdStr = implode(',', $lineIds);
            $result = $this->n11Service->updateOrderStatus($lineIdStr, 'Picking');

            $steps[] = ['step' => 'Picking', 'ok' => $result];

            // DB güncelle
            if ($result) {
                $this->orderModel->update((int) $order['id'], ['status' => 'hazirlaniyor']);
            }
        } catch (\Throwable $e) {
            // Zaten onaylanmış olabilir
            $steps[] = ['step' => 'Picking', 'ok' => false, 'msg' => $e->getMessage()];
        }

        // N11'de kargo bilgisi zaten sipariş verisinde var
        $cargoCompany = $rawData['cargoProviderName'] ?? $order['cargo_company'] ?? '';
        $trackingNo = (string) ($rawData['cargoTrackingNumber'] ?? $order['cargo_tracking_no'] ?? '');

        if (!empty($trackingNo) && !empty($cargoCompany)) {
            $this->orderModel->update((int) $order['id'], [
                'cargo_company'     => $cargoCompany,
                'cargo_tracking_no' => $trackingNo,
            ]);
            $steps[] = ['step' => 'CargoInfo', 'ok' => true];
        } else {
            $steps[] = ['step' => 'CargoInfo', 'ok' => true, 'msg' => 'N11 kargo bilgisi bekleniyor'];
        }

        // Etiket: custom etiket oluştur (N11'de API etiketi yok)
        $labelAvailable = !empty($trackingNo);

        $this->success([
            'steps'             => $steps,
            'labelAvailable'    => $labelAvailable,
            'labelSource'       => 'custom',
            'trackingNo'        => $trackingNo,
            'autoDownloadLabel' => $labelAvailable,
        ], 'N11 sipariş onaylama tamamlandı');
    }

    /**
     * Pazarama sipariş kargolama
     */
    private function shipOrderPazarama(array $order, array $rawData, Request $request): void
    {
        $steps = [];
        $trackingNo   = $request->post('tracking_no', '');
        $cargoCompany = $request->post('cargo_company', '');
        $orderNumber  = $rawData['orderNumber'] ?? $order['marketplace_order_id'] ?? '';

        if (empty($orderNumber)) {
            $this->error('Pazarama sipariş numarası bulunamadı');
            return;
        }

        try {
            $this->pazaramaService->loadCredentials();

            // Kargoya verildi (status=5) — kargo bilgileriyle birlikte
            $extra = [
                'order_number'    => $orderNumber,
                'tracking_number' => $trackingNo,
                'cargo_company_id' => $cargoCompany,
                'delivery_type'   => 1,
            ];

            // Birden fazla item varsa toplu güncelleme, tekil ise item bazlı
            $orderItems = $rawData['orderItems'] ?? $rawData['items'] ?? [];
            if (!empty($orderItems)) {
                foreach ($orderItems as $item) {
                    $extra['order_item_id'] = $item['orderItemId'] ?? $item['id'] ?? '';
                    $this->pazaramaService->updateOrderStatus($orderNumber, '5', $extra);
                }
                $steps[] = ['step' => 'Shipped', 'ok' => true];
            } else {
                // Toplu güncelleme
                $this->pazaramaService->updateOrderStatus($orderNumber, '5', $extra);
                $steps[] = ['step' => 'Shipped', 'ok' => true];
            }

            // DB güncelle
            $updateData = ['status' => 'kargoda'];
            if (!empty($trackingNo)) {
                $updateData['cargo_tracking_no'] = $trackingNo;
                $updateData['cargo_sent_at'] = date('Y-m-d H:i:s');
            }
            if (!empty($cargoCompany)) {
                $updateData['cargo_company'] = $cargoCompany;
            }
            $this->orderModel->update((int) $order['id'], $updateData);

        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Shipped', 'ok' => false, 'msg' => $e->getMessage()];
        }

        $this->success([
            'steps'             => $steps,
            'labelAvailable'    => false,
            'labelSource'       => 'none',
            'trackingNo'        => $trackingNo,
            'autoDownloadLabel' => false,
        ], 'Pazarama sipariş kargolama tamamlandı');
    }

    /**
     * Pazarama fatura gönder (invoiceLink + file fallback)
     */
    private function sendInvoiceToPazarama(array $order, string $id): void
    {
        try {
            $this->pazaramaService->loadCredentials();

            $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
            $orderNumber = (int) ($rawData['orderNumber'] ?? $order['marketplace_order_id'] ?? 0);

            if ($orderNumber === 0) {
                $this->redirectWithError("/orders/{$id}", 'Pazarama sipariş numarası bulunamadı');
                return;
            }

            $invoice = $this->container->get(\App\Models\Invoice::class)->find((int) $order['invoice_id']);

            // Fatura URL'i (auth'suz, .html ile biten public adres)
            $invoiceUrl = $this->publicInvoiceUrl($invoice);

            // Önce fatura dosyası dene (PDF varsa)
            $pdfPath = $invoice['pdf_path'] ?? '';
            if ($pdfPath !== '' && file_exists($pdfPath)) {
                try {
                    $orderItems = json_decode($order['raw_data_json'] ?? '{}', true)['orderItems'] ?? [];
                    $this->pazaramaService->sendInvoiceFile($orderNumber, $pdfPath, $orderItems);
                    $this->orderModel->update((int) $id, ['invoice_sent_to_marketplace' => 1]);
                    $this->redirectWithSuccess("/orders/{$id}", 'Fatura dosyası Pazarama\'ya gönderildi');
                    return;
                } catch (\Throwable $e) {
                    // Dosya gönderimi başarısız — link ile devam et
                    $this->logger->warning(
                        "Pazarama fatura dosyası gönderilemedi, link ile deniyor: " . $e->getMessage(),
                        'OrderController'
                    );
                }
            }

            // Link ile gönder
            if ($invoiceUrl === '') {
                $this->redirectWithError(
                    "/orders/{$id}",
                    'Pazarama fatura linki için genel erişime açık site adresi (Ayarlar > Site URL) gereklidir.'
                );
                return;
            }

            $this->pazaramaService->sendInvoiceLink($orderNumber, $invoiceUrl);

            $this->orderModel->update((int) $id, ['invoice_sent_to_marketplace' => 1]);
            $this->redirectWithSuccess("/orders/{$id}", 'Fatura Pazarama\'ya gönderildi');

        } catch (\Throwable $e) {
            $this->redirectWithError("/orders/{$id}", 'Pazarama fatura hatası: ' . $e->getMessage());
        }
    }

    /**
     * WooCommerce sipariş kargolama. WooCommerce çekirdeğinde "kargoda" durumu yoktur:
     *  1) takip no / kargo firması girildiyse sipariş meta'sına (_tracking_number, _tracking_provider)
     *     ve müşteriye açık nota yazılır,
     *  2) sipariş WooCommerce'te "Tamamlandı" yapılır (gönderildi; WooCommerce müşteriye e-posta atar).
     * Durum yazılmazsa WooCommerce "İşleniyor"da kalır ve sonraki senkron/webhook yerel durumu geri alırdı.
     *
     * @param array<string, mixed> $order
     */
    private function shipOrderWooCommerce(array $order, Request $request): void
    {
        $wooOrderId   = trim((string) ($order['marketplace_order_id'] ?? ''));
        $trackingNo   = trim((string) $request->post('tracking_no', ''));
        $cargoCompany = trim((string) $request->post('cargo_company', ''));

        if ($wooOrderId === '' || preg_match('/\d/', $wooOrderId) !== 1) {
            $this->error('WooCommerce sipariş numarası bulunamadı');
        }

        $woo = $this->container->get(\App\Services\Marketplace\WooCommerceService::class);
        $steps = [];

        try {
            if ($trackingNo !== '' || $cargoCompany !== '') {
                $cargoOk = $woo->updateCargoInfo($wooOrderId, $trackingNo, $cargoCompany);
                $steps[] = ['step' => 'Kargo bilgisi', 'ok' => $cargoOk];
                if (!$cargoOk) {
                    $this->error('Kargo bilgisi WooCommerce siparişine yazılamadı. Bağlantıyı (Pazaryerleri › WooCommerce › Test Et) kontrol edip tekrar deneyin.');
                }
            }

            $statusOk = $woo->updateOrderStatus($wooOrderId, 'completed');
            $steps[] = ['step' => 'Tamamlandı', 'ok' => $statusOk];
            if (!$statusOk) {
                $this->error('WooCommerce sipariş durumu "Tamamlandı" yapılamadı. Bağlantıyı kontrol edip tekrar deneyin.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('WooCommerce kargolama hatası (#' . (int) $order['id'] . '): ' . $e->getMessage(), 'Orders');
            $this->error('WooCommerce kargolama hatası: ' . $e->getMessage());
        }

        $updateData = ['status' => 'kargoda', 'cargo_sent_at' => date('Y-m-d H:i:s')];
        if ($trackingNo !== '') {
            $updateData['cargo_tracking_no'] = $trackingNo;
        }
        if ($cargoCompany !== '') {
            $updateData['cargo_company'] = $cargoCompany;
        }
        $this->orderModel->update((int) $order['id'], $updateData);

        $this->success([
            'steps'             => $steps,
            'labelAvailable'    => $trackingNo !== '',
            'labelSource'       => 'custom',
            'trackingNo'        => $trackingNo,
            'autoDownloadLabel' => false,
        ], 'WooCommerce siparişi kargoya verildi (WooCommerce\'te "Tamamlandı")');
    }

    /**
     * ÇiçekSepeti sipariş kargolama
     */
    private function shipOrderCiceksepeti(array $order, array $rawData, Request $request): void
    {
        $steps = [];
        $trackingNo   = $request->post('tracking_no', '');
        $cargoCompany = $request->post('cargo_company', '');
        $orderId      = $order['marketplace_order_id'] ?? '';

        if (empty($orderId)) {
            $this->error('ÇiçekSepeti sipariş numarası bulunamadı');
            return;
        }

        try {
            $this->ciceksepetiService->loadCredentials();

            // Kargo bilgilerini gönder
            $result = $this->ciceksepetiService->updateCargoInfo($orderId, $trackingNo, $cargoCompany);
            $steps[] = ['step' => 'Shipped', 'ok' => $result];

            if ($result) {
                // DB güncelle
                $updateData = ['status' => 'kargoda'];
                if (!empty($trackingNo)) {
                    $updateData['cargo_tracking_no'] = $trackingNo;
                    $updateData['cargo_sent_at']     = date('Y-m-d H:i:s');
                }
                if (!empty($cargoCompany)) {
                    $updateData['cargo_company'] = $cargoCompany;
                }
                $this->orderModel->update((int) $order['id'], $updateData);
            }
        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Shipped', 'ok' => false, 'msg' => $e->getMessage()];
        }

        $this->success([
            'steps'             => $steps,
            'labelAvailable'    => !empty($trackingNo),
            'labelSource'       => 'custom',
            'trackingNo'        => $trackingNo,
            'autoDownloadLabel' => !empty($trackingNo),
        ], 'ÇiçekSepeti sipariş kargolama tamamlandı');
    }

    /**
     * PttAVM sipariş kargolama — Kargo barkod oluştur ve etiket al
     */
    private function shipOrderPttavm(array $order, array $rawData, Request $request): void
    {
        $steps = [];
        $mpOrderId   = $order['marketplace_order_id'] ?? '';
        $warehouseId = (int) $request->post('warehouse_id', '0');

        if (empty($mpOrderId)) {
            $this->error('PttAVM sipariş numarası bulunamadı');
            return;
        }

        try {
            $this->pttavmService->loadCredentials();

            // Depo ID'si gelmemişse ilk depoyu kullan
            if ($warehouseId === 0) {
                $warehouses = $this->pttavmService->getWarehouses();
                if (!empty($warehouses)) {
                    $warehouseId = (int) ($warehouses[0]['id'] ?? 0);
                }
            }

            if ($warehouseId === 0) {
                $this->error('PttAVM depo bilgisi bulunamadı. Lütfen PttAVM panelinden depo ayarlarınızı kontrol edin.');
                return;
            }

            // 1) Kargo barkod oluştur
            $barcodeResult = $this->pttavmService->createCargoBarcode([
                ['order_id' => $mpOrderId, 'warehouse_id' => $warehouseId],
            ]);
            $trackingId = $barcodeResult['tracking_id'] ?? '';
            $steps[] = ['step' => 'CreateBarcode', 'ok' => !empty($trackingId), 'tracking_id' => $trackingId];

            // 2) Barkod durumunu kontrol et
            $barcode = '';
            if (!empty($trackingId)) {
                // Biraz bekle ve kontrol et
                usleep(2000000); // 2 saniye
                $statusResult = $this->pttavmService->checkBarcodeStatus($trackingId);
                $status = $statusResult['status'] ?? '';

                if ($status === 'completed' && !empty($statusResult['data'])) {
                    $firstOrder = $statusResult['data'][0] ?? [];
                    $barcodes = $firstOrder['barcodes'] ?? [];
                    $barcode = $barcodes[0] ?? '';
                }
                $steps[] = ['step' => 'CheckBarcode', 'ok' => !empty($barcode), 'barcode' => $barcode];
            }

            // 3) DB güncelle
            if (!empty($barcode)) {
                $this->orderModel->update((int) $order['id'], [
                    'status'            => 'kargoda',
                    'cargo_tracking_no' => $barcode,
                    'cargo_company'     => 'PTT Kargo',
                    'cargo_sent_at'     => date('Y-m-d H:i:s'),
                ]);
            }

            $this->success([
                'steps'             => $steps,
                'labelAvailable'    => !empty($barcode),
                'labelSource'       => 'pttavm',
                'trackingNo'        => $barcode,
                'autoDownloadLabel' => !empty($barcode),
            ], 'PttAVM sipariş kargolama tamamlandı');

        } catch (\Throwable $e) {
            $steps[] = ['step' => 'Error', 'ok' => false, 'msg' => $e->getMessage()];
            $this->error('PttAVM kargolama hatası: ' . $e->getMessage());
        }
    }

    /**
     * Hepsiburada sipariş kargolama
     *
     * HB akışı: Paket oluştur → Kargoya ver (intransit)
     * HB'de Picking/Invoiced gibi ara adımlar yok — direkt paketleme ve kargoya verme.
     */
    private function shipOrderHepsiburada(array $order, array $rawData, Request $request, string $id): void
    {
        $steps = [];
        $trackingNo   = $request->post('tracking_no', '');
        $cargoCompany = $request->post('cargo_company', '');

        // HB raw data'daki paket/satır bilgileri
        $lineItemId = (string) ($rawData['id'] ?? $rawData['lineItemId'] ?? '');
        $packageNumber = (string) ($rawData['packageNumber'] ?? '');

        if (empty($lineItemId) && empty($packageNumber)) {
            $this->error('Hepsiburada sipariş satır veya paket bilgisi bulunamadı');
            return;
        }

        // Kargo bilgisi yoksa API'den gelen bilgiyi kullan
        if (empty($cargoCompany)) {
            $cargoCompany = (string) ($rawData['cargoCompany'] ?? $order['cargo_company'] ?? '');
        }
        if (empty($trackingNo)) {
            $trackingNo = (string) ($rawData['trackingInfoCode'] ?? $order['cargo_tracking_no'] ?? '');
        }

        try {
            $this->hepsiburadaService->loadCredentials();

            // 1) Paket yoksa oluştur
            if (empty($packageNumber) && !empty($lineItemId)) {
                try {
                    $packageResult = $this->hepsiburadaService->createPackage([$lineItemId]);
                    $packageNumber = (string) ($packageResult['packageNumber'] ?? $packageResult['id'] ?? '');
                    $steps[] = ['step' => 'CreatePackage', 'ok' => !empty($packageNumber)];
                } catch (\Throwable $e) {
                    $steps[] = ['step' => 'CreatePackage', 'ok' => false, 'msg' => $e->getMessage()];
                }
            }

            // 2) Kargoya ver (intransit)
            if (!empty($packageNumber)) {
                try {
                    $extra = [];
                    if (!empty($trackingNo)) {
                        $extra['trackingNumber'] = $trackingNo;
                    }
                    if (!empty($cargoCompany)) {
                        $extra['cargoCompany'] = $cargoCompany;
                    }

                    $this->hepsiburadaService->updateOrderStatus($packageNumber, 'intransit', $extra);
                    $steps[] = ['step' => 'InTransit', 'ok' => true];

                    // DB güncelle
                    $this->orderModel->update((int) $id, [
                        'status'            => 'kargoda',
                        'cargo_tracking_no' => $trackingNo,
                        'cargo_company'     => $cargoCompany,
                        'cargo_sent_at'     => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {
                    $steps[] = ['step' => 'InTransit', 'ok' => false, 'msg' => $e->getMessage()];
                }
            }

            $this->success([
                'steps'          => $steps,
                'labelAvailable' => false,
                'labelSource'    => 'none',
                'trackingNo'     => $trackingNo,
                'autoDownloadLabel' => false,
            ], 'Hepsiburada sipariş kargolama tamamlandı');

        } catch (\Throwable $e) {
            $this->error('Hepsiburada kargolama hatası: ' . $e->getMessage());
        }
    }

    /**
     * Hepsiburada fatura gönder (sendInvoice endpoint)
     */
    private function sendInvoiceToHepsiburada(array $order, string $id): void
    {
        try {
            $this->hepsiburadaService->loadCredentials();

            $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];

            // HB paket numarası siparişin geldiği uca göre farklı anahtarlarda olabilir.
            $packageNumber = '';
            foreach (['packageNumber', 'PackageNumber', 'packageNo', 'id', 'orderNumber'] as $key) {
                $candidate = trim((string) ($rawData[$key] ?? ''));
                if ($candidate !== '') {
                    $packageNumber = $candidate;
                    break;
                }
            }
            if ($packageNumber === '') {
                $packageNumber = trim((string) ($order['marketplace_order_id'] ?? ''));
            }

            if ($packageNumber === '') {
                $this->redirectWithError("/orders/{$id}", 'Hepsiburada paket numarası bulunamadı');
                return;
            }

            // Fatura dosya URL'ini oluştur (auth'suz, .html ile biten public adres)
            $invoice    = $this->container->get(\App\Models\Invoice::class)->find((int) $order['invoice_id']);
            $invoiceUrl = $this->publicInvoiceUrl($invoice);

            if ($invoiceUrl === '') {
                $this->redirectWithError(
                    "/orders/{$id}",
                    'Hepsiburada fatura göndermek için genel erişime açık site adresi (Ayarlar > Site URL) gereklidir; '
                    . 'localhost adresleri Hepsiburada tarafından okunamaz.'
                );
                return;
            }

            $result = $this->hepsiburadaService->sendInvoice($packageNumber, $invoiceUrl);

            if ($result) {
                $this->orderModel->update((int) $id, ['invoice_sent_to_marketplace' => 1]);
                $this->redirectWithSuccess("/orders/{$id}", 'Fatura Hepsiburada\'ya gönderildi');
            } else {
                $this->redirectWithError("/orders/{$id}", 'Hepsiburada fatura gönderim hatası');
            }
        } catch (\Throwable $e) {
            $this->redirectWithError("/orders/{$id}", 'Hepsiburada fatura hatası: ' . $e->getMessage());
        }
    }

    /**
     * ÇiçekSepeti fatura gönder (SendInvoiceMail endpoint)
     */
    private function sendInvoiceToCiceksepeti(array $order, string $id): void
    {
        try {
            $this->ciceksepetiService->loadCredentials();

            $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];

            // Sipariş kalemlerinden orderItemId'leri topla
            $orderItems = $rawData['products'] ?? $rawData['orderItems'] ?? $rawData['items'] ?? [];
            if (empty($orderItems)) {
                $this->redirectWithError("/orders/{$id}", 'ÇiçekSepeti sipariş kalemleri bulunamadı');
                return;
            }

            $invoice = $this->container->get(\App\Models\Invoice::class)->find((int) $order['invoice_id']);
            $uuid = $invoice['invoice_uuid'] ?? '';
            $pdfPath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/storage/invoices/' . $uuid . '.pdf';

            // PDF yoksa HTML'den oluşturmayı dene
            $pdfBase64 = null;
            $pdfUrl    = null;

            if (file_exists($pdfPath)) {
                $pdfBase64 = base64_encode(file_get_contents($pdfPath));
            } else {
                // Public URL'den gönder
                $appUrl = rtrim((string) $this->container->config('app.url', ''), '/');
                if (!empty($appUrl) && !str_contains($appUrl, 'localhost') && !str_contains($appUrl, '127.0.0.1')) {
                    $pdfUrl = $appUrl . "/invoices/{$order['invoice_id']}/pdf";
                }
            }

            $sentCount = 0;
            foreach ($orderItems as $item) {
                $orderItemId = (string) ($item['orderItemId'] ?? $item['id'] ?? '');
                if ($orderItemId === '') continue;

                $result = $this->ciceksepetiService->sendInvoice($orderItemId, $pdfUrl, $pdfBase64);
                if (!empty($result) && ($result['isSuccess'] ?? $result['success'] ?? false)) {
                    $sentCount++;
                }
            }

            if ($sentCount > 0) {
                $this->orderModel->update((int) $id, ['invoice_sent_to_marketplace' => 1]);
                $this->redirectWithSuccess("/orders/{$id}", "Fatura ÇiçekSepeti'ne gönderildi ({$sentCount} kalem)");
            } else {
                $this->redirectWithError("/orders/{$id}", 'ÇiçekSepeti fatura gönderimi başarısız oldu');
            }
        } catch (\Throwable $e) {
            $this->redirectWithError("/orders/{$id}", 'ÇiçekSepeti fatura hatası: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTrendyolCommonLabel(string $trackingNo, bool $createFirst): array
    {
        if ($createFirst) {
            $this->trendyolService->createCommonLabel($trackingNo);
            usleep(500000);
        }

        return $this->trendyolService->getCommonLabel($trackingNo);
    }

    private function sendFile(string $filePath, string $filename): never
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache');
        readfile($filePath);
        exit;
    }

    // ═══ PAKET BÖLME (N11 & Pazarama) ═══

    /**
     * Paket bölme
     * POST /orders/{id}/split-package
     * N11: { "splitGroups": [[orderLineId1], [orderLineId2, orderLineId3]] }
     * Pazarama: { "items": [{"orderItemId": "xxx", "quantity": 1}] }
     */
    public function splitPackage(Request $request, string $id): void
    {
        $order = $this->orderModel->find((int) $id);
        if (!$order) {
            $this->error('Sipariş bulunamadı', 404);
            return;
        }

        $marketplace = $this->marketplaceModel->find((int) $order['marketplace_id']);
        $slug = $marketplace['slug'] ?? '';

        if ($slug === 'pazarama') {
            $items = $request->post('items');
            if (!is_array($items) || empty($items)) {
                $this->error('items alanı gereklidir');
                return;
            }

            $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
            $orderNumber = (int) ($rawData['orderNumber'] ?? $order['marketplace_order_id'] ?? 0);

            if ($orderNumber === 0) {
                $this->error('Pazarama sipariş numarası bulunamadı');
                return;
            }

            try {
                $this->pazaramaService->loadCredentials();
                $result = $this->pazaramaService->splitOrderForRefund($orderNumber, $items);

                $this->logger->info("Pazarama sipariş bölme başarılı: order={$id}", 'Orders');
                $this->success($result, 'Sipariş başarıyla bölündü');
            } catch (\Throwable $e) {
                $this->error('Sipariş bölme hatası: ' . $e->getMessage());
            }
            return;
        }

        if ($slug !== 'n11') {
            $this->error('Paket bölme sadece N11 ve Pazarama siparişleri için desteklenir');
            return;
        }

        $splitGroups = $request->post('splitGroups');
        if (!is_array($splitGroups) || empty($splitGroups)) {
            $this->error('splitGroups alanı gereklidir');
            return;
        }

        $result = $this->n11Service->splitPackages($splitGroups);

        if ($result['success']) {
            $this->logger->info("N11 paket bölme başarılı: order={$id}", 'Orders');
            $this->success(null, 'Paket başarıyla bölündü');
        } else {
            $this->error('Paket bölme hatası: ' . ($result['error'] ?? ''));
        }
    }

    // ═══ KISMI İPTAL (N11 & Pazarama) ═══

    /**
     * Miktar bazlı paket bölme + kısmi iptal
     * POST /orders/{id}/partial-cancel
     */
    /**
     * Siparişi iptal et — destekleyen pazaryerlerine iptal bildirimi gönderir,
     * yerel durumu 'iptal' yapar ve rezerve stoğu iade eder.
     *
     * Pazaryeri iptal yetenekleri:
     *  - Trendyol    : UnSupplied bildirimi (satıcı tedarik edememe)
     *  - Hepsiburada : cancelbymerchant (kalem bazında, yalnız Open statüde)
     *  - N11         : splitPackageByQuantity cancelledItems
     *  - Pazarama    : splitOrderForRefund
     *  - ÇiçekSepeti / PttAVM : API satıcı iptalini desteklemiyor → pazaryeri paneline yönlendirilir
     */
    public function cancelOrder(Request $request, string $id): void
    {
        $order = $this->orderModel->find((int) $id);
        if (!$order) {
            $this->error('Sipariş bulunamadı', 404);
            return;
        }

        $status = (string) ($order['status'] ?? '');
        if (in_array($status, ['iptal', 'iade'], true)) {
            $this->error('Sipariş zaten ' . $status . ' durumunda');
            return;
        }
        if (in_array($status, ['kargoda', 'teslim_noktasinda', 'teslim_edildi'], true)) {
            $this->error('Kargoya verilmiş veya teslim edilmiş sipariş iptal edilemez. İade akışını kullanın.');
            return;
        }

        $marketplaceId = (int) ($order['marketplace_id'] ?? 0);
        $marketplace = $marketplaceId > 0 ? $this->marketplaceModel->find($marketplaceId) : false;
        $slug = $marketplace !== false ? (string) ($marketplace['slug'] ?? '') : '';

        $items = $this->orderItemModel->getByOrderId((int) $id);
        $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
        $warning = '';

        try {
            if ($slug === 'trendyol') {
                // Trendyol satıcı iptali = UnSupplied bildirimi (paket + kalem bazında)
                $packageId = (string) ($rawData['id'] ?? $order['marketplace_order_id'] ?? '');
                $lines = [];
                foreach ($items as $item) {
                    $lineId = (int) ($item['marketplace_item_id'] ?? 0);
                    if ($lineId > 0) {
                        $lines[] = ['lineId' => $lineId, 'quantity' => (int) $item['qty']];
                    }
                }
                if ($packageId === '' || $lines === []) {
                    throw new \RuntimeException('Trendyol paket/kalem bilgisi eksik (raw_data)');
                }
                // reasonId 500 = tedarik edilemedi (satıcı iptali standart sebebi)
                $this->trendyolService->updateOrderStatus($packageId, 'UnSupplied', [
                    'lines'  => $lines,
                    'params' => ['reasonId' => (int) $request->post('reason_id', '500')],
                ]);
            } elseif ($slug === 'n11') {
                $cancelledItems = [];
                foreach ($items as $item) {
                    $lineId = (int) ($item['marketplace_item_id'] ?? 0);
                    if ($lineId > 0) {
                        $cancelledItems[] = [
                            'orderLineId'    => $lineId,
                            'quantity'       => (int) $item['qty'],
                            'cancelReasonId' => (int) $request->post('reason_id', '65'), // 65 = Diğer
                        ];
                    }
                }
                if ($cancelledItems === []) {
                    throw new \RuntimeException('N11 sipariş kalemi (orderLineId) bilgisi bulunamadı');
                }
                $result = $this->n11Service->splitPackagesByQuantity([], $cancelledItems);
                if (!($result['success'] ?? false)) {
                    throw new \RuntimeException('N11 iptal bildirimi başarısız: ' . ($result['error'] ?? 'bilinmeyen hata'));
                }
            } elseif ($slug === 'hepsiburada') {
                $cancelledAny = false;
                foreach ($items as $item) {
                    $lineId = trim((string) ($item['marketplace_item_id'] ?? ''));
                    if ($lineId === '') {
                        continue;
                    }
                    $this->hepsiburadaService->cancelOrderLine($lineId);
                    $cancelledAny = true;
                }
                if (!$cancelledAny) {
                    throw new \RuntimeException('Hepsiburada kalem (lineItemId) bilgisi bulunamadı');
                }
            } elseif ($slug === 'pazarama') {
                $orderNumber = (int) ($rawData['orderNumber'] ?? $order['marketplace_order_id'] ?? 0);
                if ($orderNumber === 0) {
                    throw new \RuntimeException('Pazarama sipariş numarası bulunamadı');
                }
                $refundItems = [];
                foreach ($items as $item) {
                    $itemId = trim((string) ($item['marketplace_item_id'] ?? ''));
                    if ($itemId !== '') {
                        $refundItems[] = ['orderItemId' => $itemId, 'quantity' => (int) $item['qty']];
                    }
                }
                if ($refundItems === []) {
                    throw new \RuntimeException('Pazarama sipariş kalemi bilgisi bulunamadı');
                }
                $this->pazaramaService->loadCredentials();
                $this->pazaramaService->splitOrderForRefund($orderNumber, $refundItems);
            } elseif ($slug === 'woocommerce') {
                // WooCommerce'te "İptal edildi" (WooCommerce kendi stoğunu geri ekler; panel stoğu aşağıda)
                $wooOrderId = trim((string) ($order['marketplace_order_id'] ?? ''));
                if ($wooOrderId === '') {
                    throw new \RuntimeException('WooCommerce sipariş numarası bulunamadı');
                }
                if (!$this->container->get(\App\Services\Marketplace\WooCommerceService::class)->updateOrderStatus($wooOrderId, 'cancelled')) {
                    throw new \RuntimeException('WooCommerce sipariş durumu "İptal edildi" yapılamadı — bağlantıyı kontrol edin');
                }
            } elseif ($slug === 'ciceksepeti' || $slug === 'pttavm') {
                $mpName = (string) ($marketplace['display_name'] ?? $slug);
                $this->error("{$mpName} API'si satıcı tarafından sipariş iptalini desteklemiyor. "
                    . "İptali {$mpName} satıcı panelinden yapın; sipariş senkronunda durum otomatik güncellenecektir.");
                return;
            } elseif ($slug !== '') {
                // Entegrasyonu olmayan pazaryeri — yalnız yerel iptal, kullanıcıyı uyar
                $warning = ' (Bu pazaryerine API üzerinden iptal bildirimi gönderilemiyor — pazaryeri panelinden de iptal edin)';
            }
            // $slug === '' → yerel/manuel sipariş: yalnız yerel iptal yeterli
        } catch (\Throwable $e) {
            $this->logger->error("Sipariş iptali başarısız (#{$id}, {$slug}): " . $e->getMessage(), 'Orders');
            $this->error('Pazaryeri iptal bildirimi başarısız: ' . $e->getMessage());
            return;
        }

        // Yerel durum + stok iadesi
        $this->orderModel->update((int) $id, ['status' => 'iptal']);
        try {
            $this->stockManager->cancelOrderReservation((int) $id);
        } catch (\Throwable $e) {
            $this->logger->warning("İptal stok iadesi yapılamadı (#{$id}): " . $e->getMessage(), 'Orders');
        }

        $this->logger->info("Sipariş iptal edildi: #{$id}" . ($slug !== '' ? " ({$slug})" : ''), 'Orders');
        $this->success([], 'Sipariş iptal edildi' . $warning);
    }

    public function partialCancel(Request $request, string $id): void
    {
        $order = $this->orderModel->find((int) $id);
        if (!$order) {
            $this->error('Sipariş bulunamadı', 404);
            return;
        }

        $marketplace = $this->marketplaceModel->find((int) $order['marketplace_id']);
        $slug = $marketplace['slug'] ?? '';

        if ($slug === 'pazarama') {
            $items = $request->post('items') ?? [];

            if (empty($items)) {
                $this->error('İptal edilecek ürün bilgisi gereklidir');
                return;
            }

            $rawData = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
            $orderNumber = (int) ($rawData['orderNumber'] ?? $order['marketplace_order_id'] ?? 0);

            if ($orderNumber === 0) {
                $this->error('Pazarama sipariş numarası bulunamadı');
                return;
            }

            try {
                $this->pazaramaService->loadCredentials();
                $result = $this->pazaramaService->splitOrderForRefund($orderNumber, $items);

                $this->logger->info("Pazarama kısmi iptal başarılı: order={$id}", 'Orders');
                $this->success($result, 'Kısmi iptal başarıyla tamamlandı');
            } catch (\Throwable $e) {
                $this->error('Kısmi iptal hatası: ' . $e->getMessage());
            }
            return;
        }

        if ($slug !== 'n11') {
            $this->error('Kısmi iptal sadece N11 ve Pazarama siparişleri için desteklenir');
            return;
        }

        $splitPackages = $request->post('splitPackages') ?? [];
        $cancelledItems = $request->post('cancelledItems') ?? [];

        if (empty($splitPackages) && empty($cancelledItems)) {
            $this->error('splitPackages veya cancelledItems gereklidir');
            return;
        }

        $result = $this->n11Service->splitPackagesByQuantity($splitPackages, $cancelledItems);

        if ($result['success']) {
            $this->logger->info("N11 kısmi iptal başarılı: order={$id}", 'Orders');
            $this->success(null, 'İşlem başarıyla tamamlandı');
        } else {
            $this->error('İşlem hatası: ' . ($result['error'] ?? ''));
        }
    }
}
