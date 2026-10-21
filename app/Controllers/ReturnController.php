<?php
/**
 * OzyConnect Panel - ReturnController
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tum Haklari Saklidir.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Marketplace;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\IdefixService;
use App\Services\Sync\ReturnSyncService;
use App\Services\Invoice\InvoiceService;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\StockManager;

final class ReturnController extends BaseController
{
    private const LIST_CACHE_TTL = 300;
    private const REASON_CACHE_TTL = 21600;
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE = 100;

    private readonly Marketplace $marketplaceModel;
    private readonly Order $orderModel;
    private readonly Product $productModel;
    private readonly ReturnRequest $returnModel;
    private readonly TrendyolService $trendyolService;
    private readonly N11Service $n11Service;
    private readonly PazaramaService $pazaramaService;
    private readonly HepsiburadaService $hepsiburadaService;
    private readonly CiceksepetiService $ciceksepetiService;
    private readonly IdefixService $idefixService;
    private readonly ReturnSyncService $returnSyncService;
    private readonly InvoiceService $invoiceService;
    private readonly Invoice $invoiceModel;
    private readonly Setting $settingModel;
    private readonly StockManager $stockManager;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->orderModel = $container->get(Order::class);
        $this->productModel = $container->get(Product::class);
        $this->returnModel = $container->get(ReturnRequest::class);
        $this->trendyolService = $container->get(TrendyolService::class);
        $this->n11Service = $container->get(N11Service::class);
        $this->pazaramaService = $container->get(PazaramaService::class);
        $this->hepsiburadaService = $container->get(HepsiburadaService::class);
        $this->ciceksepetiService = $container->get(CiceksepetiService::class);
        $this->idefixService = $container->get(IdefixService::class);
        $this->returnSyncService = $container->get(ReturnSyncService::class);
        $this->invoiceService = $container->get(InvoiceService::class);
        $this->invoiceModel = $container->get(Invoice::class);
        $this->settingModel = $container->get(Setting::class);
        $this->stockManager = $container->get(StockManager::class);
    }

    public function index(Request $request): void
    {
        $filters = [
            'marketplace_id' => $request->query('marketplace_id'),
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
        ];

        $initialPayload = [
            'total' => 0,
            'rows' => [],
            'marketplaceCounts' => ['all' => 0],
            'supported' => true,
            'emptyMessage' => 'İade talebi bulunamadı.',
            'warning' => null,
        ];

        try {
            $initialPayload = $this->loadReturnPage($filters, 1, self::DEFAULT_PAGE_SIZE);
        } catch (\Throwable $e) {
            $this->logger->error('İlk iade verisi yüklenemedi: ' . $e->getMessage(), 'ReturnController');
        }

        $this->view('returns.index', [
            'pageTitle' => 'İadeler',
            'filters' => $filters,
            'marketplaces' => $this->marketplaceModel->getAll(),
            'supportedReturnMarketplaceSlugs' => ['trendyol', 'n11', 'pazarama', 'ciceksepeti', 'hepsiburada', 'idefix'],
            'noReturnSupportSlugs' => ['pttavm'],
            'initialPayload' => $initialPayload,
            'pageSize' => self::DEFAULT_PAGE_SIZE,
        ]);
    }

    public function ajaxList(Request $request): void
    {
        $draw = (int) ($_GET['draw'] ?? 1);
        $start = max(0, (int) ($_GET['start'] ?? 0));
        $length = max(10, min(self::MAX_PAGE_SIZE, (int) ($_GET['length'] ?? self::DEFAULT_PAGE_SIZE)));
        $page = (int) floor($start / max(1, $length)) + 1;

        $searchValue = is_array($_GET['search'] ?? null)
            ? trim((string) ($_GET['search']['value'] ?? ''))
            : trim((string) ($_GET['search'] ?? ''));

        $filters = [
            'marketplace_id' => trim((string) ($_GET['marketplace_id'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
            'search' => $searchValue,
        ];

        try {
            $result = $this->loadReturnPage($filters, $page, $length);

            // Per-marketplace status counts (overlay header stats için)
            $statusCounts = ['all' => 0];
            $mpId = trim($filters['marketplace_id'] ?? '');
            if ($mpId !== '') {
                $statusCounts = $this->returnModel->getStatusCountsByMarketplace((int) $mpId);
            }

            $this->json([
                'draw' => $draw,
                'recordsTotal' => $result['total'],
                'recordsFiltered' => $result['total'],
                'data' => $result['rows'],
                'mpReturnCounts' => $result['marketplaceCounts'],
                'statusCounts' => $statusCounts,
                'meta' => [
                    'supported' => $result['supported'],
                    'emptyMessage' => $result['emptyMessage'],
                    'warning' => $result['warning'],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('İade listesi yüklenemedi: ' . $e->getMessage(), 'ReturnController');

            $this->json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'mpReturnCounts' => ['all' => 0],
                'meta' => [
                    'supported' => true,
                    'emptyMessage' => 'İade listesi şu anda alınamadı.',
                    'warning' => 'İade listesi şu anda alınamadı. Lütfen tekrar deneyin.',
                ],
            ]);
        }
    }

    public function issueReasons(Request $request): void
    {
        $marketplace = trim((string) ($_GET['marketplace'] ?? ''));

        try {
            if ($marketplace === 'n11') {
                $reasons = $this->fetchCachedN11DenyReasons();
            } elseif ($marketplace === 'pazarama') {
                // Pazarama RefundRejectType: 1=Ürün Hasarlı, 2=Yanlış Ürün, 3=Eksik Ürün, 4=Diğer
                $reasons = [
                    ['id' => 1, 'name' => 'Ürün Hasarlı'],
                    ['id' => 2, 'name' => 'Yanlış Ürün'],
                    ['id' => 3, 'name' => 'Eksik Ürün'],
                    ['id' => 4, 'name' => 'Diğer'],
                ];
            } else {
                $reasons = $this->fetchCachedClaimIssueReasons();
            }

            $this->json([
                'success' => true,
                'reasons' => $reasons,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('İade ret sebepleri alınamadı: ' . $e->getMessage(), 'ReturnController');
            $this->json([
                'success' => false,
                'reasons' => [],
                'message' => 'Ret sebepleri alınamadı.',
            ], 500);
        }
    }

    /**
     * N11 erteleme sebeplerini getir
     */
    public function pendingReasons(Request $request): void
    {
        try {
            $reasons = $this->fetchCachedN11PendingReasons();
            $this->json([
                'success' => true,
                'reasons' => $reasons,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('N11 erteleme sebepleri alınamadı: ' . $e->getMessage(), 'ReturnController');
            $this->json([
                'success' => false,
                'reasons' => [],
                'message' => 'Erteleme sebepleri alınamadı.',
            ], 500);
        }
    }

    public function approve(Request $request, string $claimId): void
    {
        try {
            $marketplace = trim((string) $request->post('marketplace', ''));

            match ($marketplace) {
                'n11' => $this->n11Service->approveReturn($claimId) ?: throw new \RuntimeException('N11 iade onay başarısız oldu.'),
                'pazarama' => $this->pazaramaService->approveReturn($claimId) ?: throw new \RuntimeException('Pazarama iade onay başarısız oldu.'),
                'hepsiburada' => $this->hepsiburadaService->approveReturn($claimId) ?: throw new \RuntimeException('Hepsiburada iade onay başarısız oldu.'),
                'ciceksepeti' => $this->ciceksepetiService->approveReturn($claimId) ?: throw new \RuntimeException('Çiçeksepeti iade onay başarısız oldu.'),
                'idefix' => $this->idefixService->approveReturn($claimId) ?: throw new \RuntimeException('İdefix iade onay başarısız oldu.'),
                'pttavm' => throw new \RuntimeException('PttAVM iade işlemi pazaryeri panelinden yapılmalıdır.'),
                default => $this->trendyolService->approveClaimLineItems($claimId, $this->extractClaimLineItemIds($request)),
            };

            if ($request->isAjax()) {
                // Stok iade — stoğu geri ekle + tüm pazaryerlerine broadcast
                $this->restoreReturnStock($claimId);

                $invoiceMsg = $this->handleReturnInvoice($claimId);
                $msg = 'İade onaylandı';
                if ($invoiceMsg) {
                    $msg .= '. ' . $invoiceMsg;
                }
                $this->success([], $msg);
                return;
            }

            $this->restoreReturnStock($claimId);

            $invoiceMsg = $this->handleReturnInvoice($claimId);
            $msg = 'İade onaylandı';
            if ($invoiceMsg) {
                $msg .= '. ' . $invoiceMsg;
            }
            $this->redirectWithSuccess('/returns', $msg);
        } catch (\Throwable $e) {
            $this->logger->error('İade onay hatası: ' . $e->getMessage(), 'ReturnController', ['claimId' => $claimId]);

            if ($request->isAjax()) {
                $this->error('İade onaylanamadı: ' . $e->getMessage());
                return;
            }

            $this->redirectWithError('/returns', 'İade onaylanamadı: ' . $e->getMessage());
        }
    }

    public function reject(Request $request, string $claimId): void
    {
        try {
            $marketplace = trim((string) $request->post('marketplace', ''));

            if ($marketplace === 'pazarama') {
                $rejectType = $request->integer('claim_issue_reason_id');
                $result = $this->pazaramaService->rejectReturn($claimId, (string) $rejectType);
                if (!$result) {
                    throw new \RuntimeException('Pazarama iade ret başarısız oldu.');
                }
            } elseif ($marketplace === 'n11') {
                $denyReasonId = $request->integer('claim_issue_reason_id');
                if ($denyReasonId <= 0) {
                    throw new \RuntimeException('Ret sebebi seçilmedi.');
                }

                $denyReasonNote = trim((string) $request->post('description', ''));
                if ($denyReasonNote === '') {
                    $denyReasonNote = 'İade talebi reddedildi.';
                }

                $reasonPayload = [
                    'denyReasonId'   => $denyReasonId,
                    'denyReasonNote' => $denyReasonNote,
                ];

                $returnShipmentType = trim((string) $request->post('return_shipment_type', ''));
                if ($returnShipmentType !== '') {
                    $reasonPayload['returnShipmentType'] = $returnShipmentType;
                }
                $trackingNumber = trim((string) $request->post('tracking_number', ''));
                if ($trackingNumber !== '') {
                    $reasonPayload['trackingNumber'] = $trackingNumber;
                }
                $deliveryReceiptNumber = trim((string) $request->post('delivery_receipt_number', ''));
                if ($deliveryReceiptNumber !== '') {
                    $reasonPayload['deliveryReceiptNumber'] = $deliveryReceiptNumber;
                }

                $result = $this->n11Service->rejectReturn($claimId, json_encode($reasonPayload, JSON_UNESCAPED_UNICODE));
                if (!$result) {
                    throw new \RuntimeException('N11 iade ret başarısız oldu.');
                }
            } elseif ($marketplace === 'hepsiburada') {
                $description = trim((string) $request->post('description', ''));
                $result = $this->hepsiburadaService->rejectReturn($claimId, $description);
                if (!$result) {
                    throw new \RuntimeException('Hepsiburada iade ret başarısız oldu.');
                }
            } elseif ($marketplace === 'ciceksepeti') {
                $description = trim((string) $request->post('description', ''));
                $result = $this->ciceksepetiService->rejectReturn($claimId, $description);
                if (!$result) {
                    throw new \RuntimeException('Çiçeksepeti iade ret başarısız oldu.');
                }
            } elseif ($marketplace === 'idefix') {
                $description = trim((string) $request->post('description', ''));
                $result = $this->idefixService->rejectReturn($claimId, $description);
                if (!$result) {
                    throw new \RuntimeException('İdefix iade ret başarısız oldu.');
                }
            } elseif ($marketplace === 'pttavm') {
                throw new \RuntimeException('PttAVM iade işlemi pazaryeri panelinden yapılmalıdır.');
            } else {
                $claimIssueReasonId = $request->integer('claim_issue_reason_id');
                if ($claimIssueReasonId <= 0) {
                    throw new \RuntimeException('Ret sebebi seçilmedi.');
                }

                $description = trim((string) $request->post('description', ''));
                $claimLineItemIds = $this->extractClaimLineItemIds($request);
                $attachmentPath = $this->resolveAttachmentPath($request->file('attachment'));

                $this->trendyolService->createClaimIssue(
                    $claimId,
                    $claimIssueReasonId,
                    $claimLineItemIds,
                    $description,
                    $attachmentPath
                );
            }

            if ($request->isAjax()) {
                $this->success([], 'İade reddedildi');
                return;
            }

            $this->redirectWithSuccess('/returns', 'İade reddedildi');
        } catch (\Throwable $e) {
            $this->logger->error('İade ret hatası: ' . $e->getMessage(), 'ReturnController', ['claimId' => $claimId]);

            if ($request->isAjax()) {
                $this->error('İade reddedilemedi: ' . $e->getMessage());
                return;
            }

            $this->redirectWithError('/returns', 'İade reddedilemedi: ' . $e->getMessage());
        }
    }

    /**
     * N11 iade erteleme
     */
    public function pend(Request $request, string $claimId): void
    {
        try {
            $pendingReasonId = $request->integer('pending_reason_id');
            if ($pendingReasonId <= 0) {
                throw new \RuntimeException('Erteleme sebebi seçilmedi.');
            }

            $pendingDayCount = $request->integer('pending_day_count');
            if ($pendingDayCount <= 0 || $pendingDayCount > 30) {
                throw new \RuntimeException('Erteleme gün sayısı 1-30 arasında olmalıdır.');
            }

            $pendingReasonNote = trim((string) $request->post('pending_reason_note', ''));
            if ($pendingReasonNote === '') {
                $pendingReasonNote = 'İade talebi ertelendi.';
            }

            $result = $this->n11Service->pendReturn($claimId, $pendingReasonId, $pendingDayCount, $pendingReasonNote);
            if (!$result) {
                throw new \RuntimeException('N11 iade erteleme başarısız oldu.');
            }

            if ($request->isAjax()) {
                $this->success([], 'İade ertelendi');
                return;
            }

            $this->redirectWithSuccess('/returns', 'İade ertelendi');
        } catch (\Throwable $e) {
            $this->logger->error('İade erteleme hatası: ' . $e->getMessage(), 'ReturnController', ['claimId' => $claimId]);

            if ($request->isAjax()) {
                $this->error('İade ertelenemedi: ' . $e->getMessage());
                return;
            }

            $this->redirectWithError('/returns', 'İade ertelenemedi: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $filters
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     total: int,
     *     marketplaceCounts: array<string|int, int>,
     *     supported: bool,
     *     emptyMessage: string,
     *     warning: ?string
     * }
     */
    private function loadReturnPage(array $filters, int $page, int $pageSize): array
    {
        $marketplaces = $this->marketplaceModel->getAll();
        $marketplaceCounts = $this->returnModel->getCountsByMarketplace();

        // Marketplace ID haritası (counts için tüm id'lerin var olduğundan emin ol)
        foreach ($marketplaces as $marketplace) {
            $mid = (string) ($marketplace['id'] ?? 0);
            if (!isset($marketplaceCounts[$mid])) {
                $marketplaceCounts[$mid] = 0;
            }
        }

        $dbResult = $this->returnModel->getFilteredFromDb($filters, $page, $pageSize);

        // image_url boş olan satırlar için ürün resmini products tablosundan çek
        $skusToLookup = [];
        foreach ($dbResult['data'] as $row) {
            if (empty($row['image_url']) && !empty($row['product_sku'])) {
                $skusToLookup[] = trim((string) $row['product_sku']);
            }
        }
        $imageMap = $skusToLookup !== [] ? $this->productModel->getImageMapBySkuOrBarcode($skusToLookup, $skusToLookup) : [];

        // DB satırlarını frontend'in beklediği formata dönüştür
        $rows = [];
        foreach ($dbResult['data'] as $row) {
            if (empty($row['image_url']) && !empty($row['product_sku'])) {
                $key = mb_strtolower(trim((string) $row['product_sku']), 'UTF-8');
                $row['image_url'] = $imageMap[$key] ?? '';
            }
            $rows[] = $this->formatReturnRow($row);
        }

        $syncStatus = $this->returnSyncService->getSyncStatus();
        $warning = null;

        if ($dbResult['total'] === 0 && $syncStatus['last_sync'] === null) {
            $warning = 'Henüz iade senkronizasyonu yapılmamış. "Yenile" butonuyla iadeleri çekebilirsiniz.';
        }

        return [
            'rows'              => $rows,
            'total'             => $dbResult['total'],
            'marketplaceCounts' => $marketplaceCounts,
            'supported'         => true,
            'emptyMessage'      => 'İade talebi bulunamadı.',
            'warning'           => $warning,
            'lastSync'          => $syncStatus['last_sync'],
            'syncStatus'        => $syncStatus['status'],
        ];
    }

    /**
     * DB satırını frontend formatına dönüştür
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatReturnRow(array $row): array
    {
        $items = json_decode((string) ($row['items_json'] ?? '[]'), true);
        if (!is_array($items)) {
            $items = [];
        }

        // items boşsa ama üst seviyede product bilgisi varsa, sentetik item oluştur
        if (empty($items) && !empty($row['product_name'])) {
            $items = [[
                'product_name'       => $row['product_name'],
                'merchant_sku'       => $row['product_sku'] ?? '',
                'quantity'           => $row['quantity'] ?? 1,
                'claim_line_item_id' => '',
            ]];
        }

        $slug = $row['marketplace_slug'] ?? '';
        $statusRaw = $row['status_raw'] ?? $row['status'] ?? '';

        return [
            'claim_id'              => (string) ($row['marketplace_return_id'] ?? ''),
            'marketplace_return_id' => (string) ($row['marketplace_return_id'] ?? ''),
            'marketplace_id'        => (int) ($row['marketplace_id'] ?? 0),
            'marketplace_name'      => (string) ($row['marketplace_name'] ?? ''),
            'marketplace_slug'      => $slug,
            'marketplace_order_id'  => (string) ($row['marketplace_order_id'] ?? ''),
            'order_id'              => $row['order_id'] ?? null,
            'customer_name'         => (string) ($row['customer_name'] ?? ''),
            'reason'                => (string) ($row['reason'] ?? ''),
            'reason_code'           => (string) ($row['reason_code'] ?? ''),
            'status'                => (string) ($row['status'] ?? 'requested'),
            'status_raw'            => $statusRaw,
            'status_label'          => $this->statusLabel($statusRaw !== '' ? $statusRaw : ($row['status'] ?? '')),
            'items'                 => $items,
            'item_count'            => count($items),
            'image_url'             => (string) ($row['image_url'] ?? ''),
            'cargo_company'         => (string) ($row['cargo_company'] ?? ''),
            'cargo_tracking_no'     => (string) ($row['cargo_tracking_no'] ?? ''),
            'created_at'            => $row['created_at'] ?? null,
            'claim_date'            => $row['request_date'] ?? $row['created_at'] ?? null,
            'action_required'       => (bool) ($row['action_required'] ?? false),
            'claim_item_ids'        => array_column($items, 'claim_line_item_id'),
            'customer_images'       => [],
            'refund_amount'         => (float) ($row['refund_amount'] ?? 0),
            'synced_at'             => $row['synced_at'] ?? null,
        ];
    }

    /**
     * Manuel sync tetikle (AJAX)
     */
    public function sync(Request $request): void
    {
        try {
            $results = $this->returnSyncService->syncAll('manual');

            $this->json([
                'success' => true,
                'message' => sprintf(
                    'Senkronizasyon tamamlandı. %d iade çekildi, %d işlendi.',
                    $results['fetched'],
                    $results['processed']
                ),
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Manuel iade sync hatası: ' . $e->getMessage(), 'ReturnController');
            $this->json([
                'success' => false,
                'message' => 'Senkronizasyon başarısız: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync durumu (AJAX)
     */
    public function syncStatus(Request $request): void
    {
        $status = $this->returnSyncService->getSyncStatus();
        $this->json([
            'success'   => true,
            'last_sync' => $status['last_sync'],
            'status'    => $status['status'],
        ]);
    }

    /**
     * SSE Stream — Canlı iade senkronizasyon ilerleme akışı
     */
    public function syncStream(Request $request): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level()) { ob_end_clean(); }
        set_time_limit(0);

        $sendEvent = function (string $event, array $data) {
            echo "event: {$event}\n";
            echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        try {
            $results = $this->returnSyncService->syncAll('manual', false, function (string $event, string $slug, string $displayName, array $result = []) use ($sendEvent) {
                $sendEvent($event, [
                    'slug'         => $slug,
                    'display_name' => $displayName,
                    'result'       => $result,
                ]);
            });

            $sendEvent('complete', [
                'success' => true,
                'results' => $results,
                'message' => sprintf(
                    'Senkronizasyon: %d iade çekildi, %d işlendi.',
                    $results['fetched'],
                    $results['processed']
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SSE iade sync hatası: ' . $e->getMessage(), 'ReturnController');
            $sendEvent('complete', [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
            ]);
        }

        exit;
    }

    // ══════════════════════════════════════════════════
    // N11 İADE İŞLEMLERİ
    // ══════════════════════════════════════════════════

    /**
     * N11 iade taleplerini yükle (SOAP ReturnService)
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadN11Returns(array $filters, int $marketplaceId, int $page, int $pageSize, ?string &$warning): array
    {
        $n11Filters = [];

        // Statü dönüşümü (frontend → N11 SOAP)
        $statusFilter = $filters['status'] ?? '';
        $n11StatusMap = [
            'Created'         => 'REQUESTED',
            'WaitingInAction' => 'REQUESTED',
            'Accepted'        => 'APPROVED',
            'Rejected'        => 'DENIED',
        ];
        $n11Filters['status'] = $n11StatusMap[$statusFilter] ?? 'ALL';

        // Tarih filtreleri (dd/mm/yyyy formatına çevir)
        if (!empty($filters['date_from'])) {
            $ts = strtotime($filters['date_from']);
            if ($ts !== false) {
                $n11Filters['startDate'] = date('d/m/Y', $ts);
            }
        }
        if (!empty($filters['date_to'])) {
            $ts = strtotime($filters['date_to']);
            if ($ts !== false) {
                $n11Filters['endDate'] = date('d/m/Y', $ts);
            }
        }

        // Arama
        $search = ltrim(trim((string) ($filters['search'] ?? '')), '#');
        if ($search !== '') {
            if (ctype_digit($search)) {
                $n11Filters['searchInfoType'] = 'ORDERID';
                $n11Filters['searchQuery'] = $search;
            }
        }

        // Sayfalama (N11 SOAP sayfası 0 bazlı)
        $n11Filters['page'] = max(0, $page - 1);

        // Önce cache'e bak
        $cacheKey = 'n11_returns_' . md5((string) json_encode($n11Filters, JSON_UNESCAPED_UNICODE));
        $cached = $this->readCache($cacheKey, self::LIST_CACHE_TTL);
        if ($cached !== null && is_array($cached['data'] ?? null)) {
            $n11Result = $cached['data'];
        } else {
            try {
                $n11Result = $this->n11Service->fetchReturns($n11Filters);
                $this->writeCache($cacheKey, $n11Result);
            } catch (\Throwable $e) {
                // Stale cache dene
                $stale = $this->readCache($cacheKey, 31536000, true);
                if ($stale !== null && is_array($stale['data'] ?? null)) {
                    $n11Result = $stale['data'];
                    $warning = 'N11 canlı bağlantı kurulamadı. Son alınan veriler gösteriliyor.';
                } else {
                    throw $e;
                }
            }
        }

        $returns = $n11Result['returns'] ?? [];

        // marketplace_id'yi set et ve resim çözümlemesi yap
        $orderNumbers = [];
        foreach ($returns as &$row) {
            $row['marketplace_id'] = $marketplaceId;
            $orderNumber = $row['marketplace_order_id'] ?? '';
            if ($orderNumber !== '') {
                $orderNumbers[] = $orderNumber;
            }
        }
        unset($row);

        // Yerel sipariş eşlemesi
        if ($orderNumbers !== []) {
            try {
                $localOrders = $this->orderModel->findByMarketplaceOrderIds($marketplaceId, $orderNumbers);
                $localOrderMap = [];
                foreach ($localOrders as $lo) {
                    $loId = (string) ($lo['marketplace_order_id'] ?? '');
                    if ($loId !== '') {
                        $localOrderMap[$loId] = $lo;
                    }
                }

                foreach ($returns as &$row) {
                    $orderId = $row['marketplace_order_id'] ?? '';
                    if (isset($localOrderMap[$orderId])) {
                        $row['order_id'] = $localOrderMap[$orderId]['id'] ?? null;
                    }
                }
                unset($row);
            } catch (\Throwable $e) {
                $this->logger->error('N11 iade sipariş eşleme hatası: ' . $e->getMessage(), 'ReturnController');
            }
        }

        return $returns;
    }

    // ══════════════════════════════════════════════════
    // TRENDYOL İADE İŞLEMLERİ (mevcut mantık)
    // ══════════════════════════════════════════════════

    /**
     * Trendyol iade taleplerini yükle
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadTrendyolReturns(array $filters, int $marketplaceId, int $page, int $pageSize, ?string &$warning): array
    {
        $apiFilters = [
            'status' => $filters['status'] ?? '',
            'date_from' => $filters['date_from'] ?? '',
            'date_to' => $filters['date_to'] ?? '',
        ];

        $search = ltrim(trim((string) ($filters['search'] ?? '')), '#');
        if ($search !== '') {
            if (ctype_digit($search)) {
                $apiFilters['orderNumber'] = $search;
            } elseif (preg_match('/^[a-f0-9-]{8,}$/i', $search) === 1) {
                $apiFilters['claimIds'] = $search;
            }
        }

        $metaResponse = $this->fetchCachedTrendyolClaims(array_merge($apiFilters, [
            'page' => 0,
            'size' => 1,
        ]), $warning);

        $total = (int) ($metaResponse['totalElements'] ?? 0);

        if ($total <= 0) {
            return [];
        }

        $descOffset = max(0, ($page - 1) * $pageSize);
        if ($descOffset >= $total) {
            return [];
        }

        $descEndExclusive = min($total, $descOffset + $pageSize);
        $ascStart = max(0, $total - $descEndExclusive);
        $ascEndExclusive = $total - $descOffset;
        $apiPageFrom = intdiv($ascStart, $pageSize);
        $apiPageTo = intdiv(max(0, $ascEndExclusive - 1), $pageSize);

        $claims = [];
        for ($apiPage = $apiPageFrom; $apiPage <= $apiPageTo; $apiPage++) {
            $pageResponse = $this->fetchCachedTrendyolClaims(array_merge($apiFilters, [
                'page' => $apiPage,
                'size' => $pageSize,
            ]), $warning);

            $pageClaims = is_array($pageResponse['content'] ?? null)
                ? array_values(array_filter($pageResponse['content'], 'is_array'))
                : [];

            if ($pageClaims !== []) {
                usort($pageClaims, static fn(array $a, array $b): int => (int) ($a['claimDate'] ?? 0) <=> (int) ($b['claimDate'] ?? 0));
                array_push($claims, ...$pageClaims);
            }
        }

        $pageBaseIndex = $apiPageFrom * $pageSize;
        $sliceStart = max(0, $ascStart - $pageBaseIndex);
        $sliceLength = max(0, $ascEndExclusive - $ascStart);
        $claims = array_reverse(array_slice($claims, $sliceStart, $sliceLength));

        // Claim'lerden SKU ve barkod bilgilerini topla
        $allSkus = [];
        $allBarcodes = [];
        $orderNumbersNeedingLookup = [];

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }

            foreach ($claim['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $orderLine = is_array($item['orderLine'] ?? null) ? $item['orderLine'] : [];
                $sku = trim((string) ($orderLine['merchantSku'] ?? ''));
                $barcode = trim((string) ($orderLine['barcode'] ?? ''));
                if ($sku !== '') {
                    $allSkus[] = $sku;
                }
                if ($barcode !== '') {
                    $allBarcodes[] = $barcode;
                }
            }

            $orderNumber = trim((string) ($claim['orderNumber'] ?? ''));
            if ($orderNumber !== '' && $this->claimNeedsLocalImageLookup($claim)) {
                $orderNumbersNeedingLookup[] = $orderNumber;
            }
        }

        $productImageMap = [];
        try {
            $productImageMap = $this->productModel->getImageMapBySkuOrBarcode($allSkus, $allBarcodes);
        } catch (\Throwable $e) {
            $this->logger->error('Ürün resim haritası alınamadı: ' . $e->getMessage(), 'ReturnController');
        }

        $localOrderMap = [];
        if ($orderNumbersNeedingLookup !== []) {
            $localOrders = $this->orderModel->findByMarketplaceOrderIds($marketplaceId, $orderNumbersNeedingLookup);
            foreach ($localOrders as $localOrder) {
                $marketplaceOrderId = (string) ($localOrder['marketplace_order_id'] ?? '');
                if ($marketplaceOrderId === '') {
                    continue;
                }
                $localOrder['image_map'] = $this->buildLocalOrderImageMap($localOrder);
                $localOrderMap[$marketplaceOrderId] = $localOrder;
            }
        }

        return $this->hydrateTrendyolReturns($claims, $marketplaceId, $localOrderMap, $productImageMap);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function fetchCachedTrendyolClaims(array $filters, ?string &$warning = null): array
    {
        $cacheKey = 'trendyol_claims_' . md5((string) json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = $this->readCache($cacheKey, self::LIST_CACHE_TTL);
        if ($cached !== null && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        try {
            $response = $this->trendyolService->fetchReturnsResponse($filters);
            $this->writeCache($cacheKey, $response);

            return $response;
        } catch (\Throwable $e) {
            $stale = $this->readCache($cacheKey, 31536000, true);
            if ($stale !== null && is_array($stale['data'] ?? null)) {
                $warning = 'Canlı bağlantı kurulamadı. Son alınan iade verileri gösteriliyor.';
                return $stale['data'];
            }

            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCachedClaimIssueReasons(): array
    {
        $cacheKey = 'trendyol_claim_issue_reasons';
        $cached = $this->readCache($cacheKey, self::REASON_CACHE_TTL);
        if ($cached !== null && is_array($cached['data'] ?? null)) {
            return $this->normalizeReasonRows($cached['data']);
        }

        try {
            $reasons = $this->trendyolService->fetchClaimIssueReasons();
            $this->writeCache($cacheKey, $reasons);

            return $this->normalizeReasonRows($reasons);
        } catch (\Throwable $e) {
            $stale = $this->readCache($cacheKey, 31536000, true);
            if ($stale !== null && is_array($stale['data'] ?? null)) {
                return $this->normalizeReasonRows($stale['data']);
            }

            throw $e;
        }
    }

    /**
     * @param array<int, mixed> $reasons
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReasonRows(array $reasons): array
    {
        return array_values(array_filter(
            $reasons,
            static fn($reason): bool => is_array($reason) && (int) ($reason['id'] ?? 0) > 0
        ));
    }

    /**
     * N11 ret sebeplerini cache'li getir
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function fetchCachedN11DenyReasons(): array
    {
        $cacheKey = 'n11_return_deny_reasons';
        $cached = $this->readCache($cacheKey, self::REASON_CACHE_TTL);
        if ($cached !== null && is_array($cached['data'] ?? null)) {
            return $this->normalizeN11ReasonRows($cached['data']);
        }

        try {
            $reasons = $this->n11Service->fetchReturnDenyReasons();
            $this->writeCache($cacheKey, $reasons);
            return $this->normalizeN11ReasonRows($reasons);
        } catch (\Throwable $e) {
            $stale = $this->readCache($cacheKey, 31536000, true);
            if ($stale !== null && is_array($stale['data'] ?? null)) {
                return $this->normalizeN11ReasonRows($stale['data']);
            }
            throw $e;
        }
    }

    /**
     * N11 erteleme sebeplerini cache'li getir
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function fetchCachedN11PendingReasons(): array
    {
        $cacheKey = 'n11_return_pending_reasons';
        $cached = $this->readCache($cacheKey, self::REASON_CACHE_TTL);
        if ($cached !== null && is_array($cached['data'] ?? null)) {
            return $this->normalizeN11ReasonRows($cached['data']);
        }

        try {
            $reasons = $this->n11Service->fetchReturnPendingReasons();
            $this->writeCache($cacheKey, $reasons);
            return $this->normalizeN11ReasonRows($reasons);
        } catch (\Throwable $e) {
            $stale = $this->readCache($cacheKey, 31536000, true);
            if ($stale !== null && is_array($stale['data'] ?? null)) {
                return $this->normalizeN11ReasonRows($stale['data']);
            }
            throw $e;
        }
    }

    /**
     * N11 sebep listesi normalize: {id, value} → {id, name}
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function normalizeN11ReasonRows(array $reasons): array
    {
        $rows = [];
        foreach ($reasons as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rows[] = [
                'id'   => $id,
                'name' => (string) ($r['value'] ?? $r['name'] ?? "Sebep #{$id}"),
            ];
        }
        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $claims
     * @param array<string, array<string, mixed>> $localOrders
     * @param array<string, string> $productImageMap
     * @return array<int, array<string, mixed>>
     */
    private function hydrateTrendyolReturns(array $claims, int $marketplaceId, array $localOrders, array $productImageMap = []): array
    {
        $rows = [];

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }

            $orderNumber = (string) ($claim['orderNumber'] ?? '');
            $localOrder = $localOrders[$orderNumber] ?? null;
            $imageMap = is_array($localOrder['image_map'] ?? null) ? $localOrder['image_map'] : [];
            $claimLineItemIds = [];
            $items = [];
            $statuses = [];
            $primaryReason = '';
            $primaryReasonCode = '';
            $firstImageUrl = '';

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
                    $imageUrl = $this->resolveClaimImage($claimItem, $orderLine, $imageMap, $productImageMap);

                    // Müşteri iade resimleri
                    $customerImages = [];
                    foreach ($claimItem['customerImages'] ?? $claimItem['images'] ?? [] as $ci) {
                        if (is_string($ci) && trim($ci) !== '') {
                            $customerImages[] = trim($ci);
                        } elseif (is_array($ci)) {
                            $url = trim((string) ($ci['url'] ?? $ci['imageUrl'] ?? ''));
                            if ($url !== '') {
                                $customerImages[] = $url;
                            }
                        }
                        if (count($customerImages) >= 3) {
                            break;
                        }
                    }

                    if ($primaryReason === '' && $reason !== '') {
                        $primaryReason = (string) $reason;
                        $primaryReasonCode = (string) $reasonCode;
                    }
                    if ($status !== '') {
                        $statuses[] = $status;
                    }
                    if ($firstImageUrl === '' && $imageUrl !== '') {
                        $firstImageUrl = $imageUrl;
                    }

                    $items[] = [
                        'claim_line_item_id' => $claimLineItemId,
                        'product_name' => (string) ($orderLine['productName'] ?? ''),
                        'merchant_sku' => (string) ($orderLine['merchantSku'] ?? ''),
                        'barcode' => (string) ($orderLine['barcode'] ?? ''),
                        'reason' => (string) $reason,
                        'reason_code' => (string) $reasonCode,
                        'status' => $status,
                        'status_label' => $this->statusLabel($status),
                        'customer_note' => (string) ($claimItem['customerNote'] ?? ''),
                        'image_url' => $imageUrl,
                        'customer_images' => $customerImages,
                    ];
                }
            }

            $claimDate = $this->formatTimestampMs($claim['claimDate'] ?? null);
            $customerName = trim((string) ($claim['customerFirstName'] ?? '') . ' ' . (string) ($claim['customerLastName'] ?? ''));
            $statusRaw = $statuses[0] ?? 'Created';

            $rows[] = [
                'claim_id' => (string) ($claim['claimId'] ?? $claim['id'] ?? ''),
                'marketplace_return_id' => (string) ($claim['claimId'] ?? $claim['id'] ?? ''),
                'marketplace_id' => $marketplaceId,
                'marketplace_name' => 'Trendyol',
                'marketplace_slug' => 'trendyol',
                'marketplace_order_id' => $orderNumber,
                'order_id' => $localOrder['id'] ?? null,
                'customer_name' => $customerName,
                'reason' => $primaryReason,
                'reason_code' => $primaryReasonCode,
                'status' => $this->mapClaimStatus($statusRaw),
                'status_raw' => $statusRaw,
                'status_label' => $this->statusLabel($statusRaw),
                'claim_item_ids' => array_values(array_unique(array_filter($claimLineItemIds))),
                'items' => $items,
                'item_count' => count($items),
                'image_url' => $firstImageUrl,
                'customer_images' => $items !== []
                    ? array_values(array_unique(array_slice(array_merge(
                        ...array_map(fn($it) => $it['customer_images'] ?? [], $items)
                    ), 0, 3)))
                    : [],
                'cargo_company' => (string) ($claim['cargoProviderName'] ?? ''),
                'cargo_tracking_no' => (string) ($claim['cargoTrackingNumber'] ?? ''),
                'cargo_tracking_link' => (string) ($claim['cargoTrackingLink'] ?? ''),
                'created_at' => $claimDate,
                'created_at_ts' => (int) ($claim['claimDate'] ?? 0),
                'claim_date' => $claimDate,
                'action_required' => $statusRaw === 'WaitingInAction',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $localOrder
     * @return array<string, string>
     */
    private function buildLocalOrderImageMap(array $localOrder): array
    {
        $raw = json_decode((string) ($localOrder['raw_data_json'] ?? '{}'), true);
        if (!is_array($raw)) {
            return [];
        }

        $imageMap = [];
        foreach ($raw['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $imageUrl = $this->extractFirstImageUrl($line['images'] ?? null)
                ?? $this->extractFirstImageUrl($line['imageUrl'] ?? null)
                ?? $this->extractFirstImageUrl($line['productImage'] ?? null);

            if ($imageUrl === null || $imageUrl === '') {
                continue;
            }

            foreach ([
                (string) ($line['merchantSku'] ?? ''),
                (string) ($line['barcode'] ?? ''),
                (string) ($line['productName'] ?? ''),
            ] as $key) {
                $normalizedKey = $this->normalizeLookupKey($key);
                if ($normalizedKey !== '') {
                    $imageMap[$normalizedKey] = $imageUrl;
                }
            }
        }

        return $imageMap;
    }

    /**
     * @param array<string, mixed> $claimItem
     * @param array<string, mixed> $orderLine
     * @param array<string, string> $imageMap
     * @param array<string, string> $productImageMap
     */
    private function resolveClaimImage(array $claimItem, array $orderLine, array $imageMap, array $productImageMap = []): string
    {
        foreach ([
            $claimItem['imageUrl'] ?? null,
            $claimItem['productImage'] ?? null,
            $claimItem['images'] ?? null,
            $orderLine['imageUrl'] ?? null,
            $orderLine['productImage'] ?? null,
            $orderLine['images'] ?? null,
        ] as $candidate) {
            $imageUrl = $this->extractFirstImageUrl($candidate);
            if ($imageUrl !== null && $imageUrl !== '') {
                return $imageUrl;
            }
        }

        // Ürün tablosundan SKU/barkod eşleşmesiyle resim bul
        foreach ([
            (string) ($orderLine['merchantSku'] ?? ''),
            (string) ($orderLine['barcode'] ?? ''),
        ] as $key) {
            $normalizedKey = mb_strtolower(trim($key), 'UTF-8');
            if ($normalizedKey !== '' && isset($productImageMap[$normalizedKey])) {
                return $productImageMap[$normalizedKey];
            }
        }

        // Son çare: sipariş raw_data_json image haritası
        foreach ([
            (string) ($orderLine['merchantSku'] ?? ''),
            (string) ($orderLine['barcode'] ?? ''),
            (string) ($orderLine['productName'] ?? ''),
        ] as $key) {
            $normalizedKey = $this->normalizeLookupKey($key);
            if ($normalizedKey !== '' && isset($imageMap[$normalizedKey])) {
                return $imageMap[$normalizedKey];
            }
        }

        return '';
    }

    /**
     * Claim satirlarinda gorsel yoksa yerel siparis verisine bak.
     *
     * @param array<string, mixed> $claim
     */
    private function claimNeedsLocalImageLookup(array $claim): bool
    {
        foreach ($claim['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $orderLine = is_array($item['orderLine'] ?? null) ? $item['orderLine'] : [];
            foreach ($item['claimItems'] ?? [] as $claimItem) {
                if (!is_array($claimItem)) {
                    continue;
                }

                $hasImage = false;
                foreach ([
                    $claimItem['imageUrl'] ?? null,
                    $claimItem['productImage'] ?? null,
                    $claimItem['images'] ?? null,
                    $claimItem['customerImages'] ?? null,
                    $orderLine['imageUrl'] ?? null,
                    $orderLine['productImage'] ?? null,
                    $orderLine['images'] ?? null,
                ] as $candidate) {
                    $imageUrl = $this->extractFirstImageUrl($candidate);
                    if ($imageUrl !== null && $imageUrl !== '') {
                        $hasImage = true;
                        break;
                    }
                }

                if (!$hasImage) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractFirstImageUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['url', 'imageUrl', 'src', 'link'] as $key) {
            $candidate = $value[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($value as $item) {
            $candidate = $this->extractFirstImageUrl($item);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeLookupKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'Open' => 'Yeni Talep',
            'NewRequest' => 'İade Talebi',
            'Approved' => 'Onaylandı',
            'Refunded' => 'İade Edildi',
            'Closed' => 'Tamamlandı',
            // Trendyol
            'Created' => 'Yeni Talep',
            'WaitingInAction' => 'İşlem Bekliyor',
            'WaitingFraudCheck' => 'Kontrol Bekliyor',
            'Accepted' => 'Onaylandı',
            'Rejected' => 'Reddedildi',
            'Cancelled' => 'İptal Edildi',
            'Unresolved' => 'Uyuşmazlık',
            'InAnalysis' => 'İnceleniyor',
            // N11 (büyük harfli status_raw)
            'REQUESTED' => 'İade Talebi',
            'APPROVED' => 'Onaylandı',
            'DENIED' => 'Reddedildi',
            'CANCELLED' => 'İptal Edildi',
            'PENDING' => 'Erteleme Talep Edildi',
            'PENDED' => 'Değerlendirme Ertelendi',
            'MANUAL_REFUND' => 'Manuel İade',
            // Pazarama (sayısal status_raw)
            '1' => 'Yeni Talep',
            '2' => 'Onaylandı',
            '3' => 'Reddedildi',
            '4' => 'İptal Edildi',
            '5' => 'Reddedildi (BO)',
            '6' => 'Onaylandı (BO)',
            // Normalize edilmiş status değerleri
            'created' => 'Yeni Talep',
            'approved' => 'Onaylandı',
            'rejected' => 'Reddedildi',
            'cancelled' => 'İptal Edildi',
            'requested' => 'İade Talebi',
            'pending' => 'Beklemede',
            'pended' => 'Değerlendirme Ertelendi',
            'manual_refund' => 'Manuel İade',
            default => $status,
        };
    }

    /**
     * @return array<int, string>
     */
    private function extractClaimLineItemIds(Request $request): array
    {
        $raw = $request->post('claim_line_item_ids', []);
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('strval', $raw)));
    }

    /**
     * @param array<string, mixed>|null $file
     */
    private function resolveAttachmentPath(?array $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        return $tmpName !== '' && is_file($tmpName) ? $tmpName : null;
    }

    private function mapClaimStatus(string $status): string
    {
        return match ($status) {
            'Created' => 'created',
            'WaitingInAction' => 'waiting_in_action',
            'WaitingFraudCheck' => 'waiting_fraud_check',
            'Accepted' => 'accepted',
            'Rejected' => 'rejected',
            'Cancelled' => 'cancelled',
            'Unresolved' => 'unresolved',
            'InAnalysis' => 'in_analysis',
            default => strtolower($status),
        };
    }

    private function formatTimestampMs(mixed $value): ?string
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
     * @return array{created_at: int, data: mixed}|null
     */
    private function readCache(string $key, int $ttl, bool $allowStale = false): ?array
    {
        $path = $this->cacheFilePath($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['created_at'])) {
            return null;
        }

        $createdAt = (int) ($payload['created_at'] ?? 0);
        if (!$allowStale && (time() - $createdAt) > $ttl) {
            return null;
        }

        return [
            'created_at' => $createdAt,
            'data' => $payload['data'] ?? null,
        ];
    }

    private function writeCache(string $key, mixed $data): void
    {
        $directory = $this->cacheDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode([
            'created_at' => time(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        @file_put_contents($this->cacheFilePath($key), $payload, LOCK_EX);
    }

    private function cacheDirectory(): string
    {
        return rtrim((string) $this->container->config('paths.cache', BASE_PATH . '/storage/cache'), '\\/')
            . DIRECTORY_SEPARATOR
            . 'returns';
    }

    private function cacheFilePath(string $key): string
    {
        return $this->cacheDirectory() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    /**
     * İade onaylandığında ilişkili siparişin faturasını kontrol et ve gerekirse iptal et
     *
     * Türkiye e-arşiv kuralları:
     * - Fatura 7 gün içindeyse: GİB'den iptal edilebilir
     * - 7 günden eskiyse: Manuel iade faturası gerekir (bilgilendirme mesajı)
     */
    /**
     * İade onayında stoğu geri ekle + tüm pazaryerlerine broadcast
     */
    private function restoreReturnStock(string $claimId): void
    {
        try {
            $returnRecord = $this->returnModel->findBy(['marketplace_return_id' => $claimId]);
            if ($returnRecord !== false) {
                $this->stockManager->processReturn((int) $returnRecord['id']);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                "İade stok iade hatası (claim #{$claimId}): " . $e->getMessage(),
                'ReturnController'
            );
        }
    }

    private function handleReturnInvoice(string $claimId): ?string
    {
        try {
            // Otomatik iade faturası ayarı kapalıysa çık (per-store)
            $autoReturn = (bool) $this->container->get(\App\Models\StoreSetting::class)->getValue('auto_return_invoice', '0');
            if (!$autoReturn) {
                return null;
            }

            // İade kaydını bul
            $returnRecord = $this->returnModel->findBy(['marketplace_return_id' => $claimId]);
            if ($returnRecord === false || empty($returnRecord['order_id'])) {
                return null;
            }

            $returnRequestId = (int) $returnRecord['id'];
            $orderId = (int) $returnRecord['order_id'];

            // Siparişi ve faturasını kontrol et
            $order = $this->orderModel->find($orderId);
            if ($order === false) {
                return null;
            }

            // Orijinal satış faturası var mı?
            $originalInvoice = null;
            if (!empty($order['invoice_id'])) {
                $originalInvoice = $this->invoiceModel->find((int) $order['invoice_id']);
            }

            // Zaten bu iade için fatura kesilmiş mi?
            $existingReturnInvoice = $this->invoiceModel->findBy([
                'return_request_id' => $returnRequestId,
                'invoice_flow' => 'return',
            ]);
            if ($existingReturnInvoice !== false) {
                return sprintf(
                    'İade faturası zaten mevcut: #%s%s',
                    $existingReturnInvoice['series'] ?? '',
                    $existingReturnInvoice['sequence_no'] ?? $existingReturnInvoice['id']
                );
            }

            // 7 gün kuralı: Orijinal fatura 7 gün içindeyse önce iptal et
            if ($originalInvoice && $originalInvoice['status'] !== 'cancelled') {
                $issuedAt = strtotime($originalInvoice['issued_at'] ?? $originalInvoice['created_at'] ?? 'now');
                $daysSinceIssue = (int) floor((time() - $issuedAt) / 86400);

                if ($daysSinceIssue <= 7) {
                    // 7 gün içinde — GİB'den iptal et
                    $reason = sprintf(
                        'İade onayı — İade No: %s, Sipariş No: %s',
                        $returnRecord['marketplace_return_id'] ?? $claimId,
                        $order['marketplace_order_id'] ?? $order['id']
                    );

                    try {
                        $this->invoiceService->cancel((int) $order['invoice_id'], $reason);
                        $this->logger->info(
                            "Orijinal fatura iptal edildi: #{$originalInvoice['id']} (iade #{$claimId})",
                            'ReturnController'
                        );
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            "Orijinal fatura iptal edilemedi: " . $e->getMessage(),
                            'ReturnController'
                        );
                    }
                }
            }

            // İade faturası oluştur
            $refundAmount = (float) ($returnRecord['refund_amount'] ?? 0);
            $returnInvoiceId = $this->invoiceService->createReturnInvoice(
                $orderId,
                $returnRequestId,
                $refundAmount > 0 ? $refundAmount : null
            );

            // İade kaydına fatura bağlantısı ekle
            $this->returnModel->update($returnRequestId, [
                'cancel_invoice_id' => $returnInvoiceId,
            ]);

            $returnInvoice = $this->invoiceModel->find($returnInvoiceId);
            $invNo = ($returnInvoice['series'] ?? '') . ($returnInvoice['sequence_no'] ?? $returnInvoiceId);

            $this->logger->info(
                "İade faturası oluşturuldu: #{$invNo} → Sipariş #{$order['marketplace_order_id']} (iade #{$claimId})",
                'ReturnController'
            );

            // Taslak kaldıysa (HSM'siz hesap) kullanıcıyı panel SMS onayına yönlendir
            $draftNote = (($returnInvoice['status'] ?? '') === 'draft')
                ? ' — taslak durumda; Faturalar sayfasındaki "SMS Onay" butonuyla imzalayın'
                : '';

            return sprintf('İade faturası #%s oluşturuldu (GİB)%s', $invNo, $draftNote);

        } catch (\Throwable $e) {
            $this->logger->error(
                'İade fatura işlem hatası: ' . $e->getMessage(),
                'ReturnController',
                ['claim_id' => $claimId]
            );
            return 'İade faturası oluşturulurken hata: ' . $e->getMessage();
        }
    }
}
