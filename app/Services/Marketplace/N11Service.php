<?php
/**
 * OzyConnect Panel — N11Service (N11 REST API Entegrasyonu)
 *
 * N11 REST API:
 *  - Auth: Header'da appkey + appsecret (Bearer token yok)
 *  - Ürün işlemleri asenkron task bazlı (taskId ile sorgulanır)
 *  - Siparişler: GET /rest/delivery/v1/shipmentPackages
 *  - Tarihler: timestamp milliseconds, GMT+3
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class N11Service extends AbstractMarketplace
{
    private const SLUG = 'n11';

    /** Asenkron görev sonucu için azami sorgu denemesi */
    private const TASK_POLL_ATTEMPTS = 6;

    /** Görev sorguları arası bekleme (ms) */
    private const TASK_POLL_DELAY_MS = 1500;

    /** Görev/SKU hâlâ işleniyor anlamına gelen durumlar */
    private const TASK_PENDING_STATUSES = ['IN_QUEUE', 'INQUEUE', 'QUEUED', 'IN_PROGRESS', 'PROCESSING', 'WAITING', 'PENDING'];

    /** Görev/SKU başarısız anlamına gelen durumlar */
    private const TASK_FAILED_STATUSES = ['FAIL', 'FAILED', 'REJECT', 'REJECTED', 'ERROR', 'INVALID', 'CANCELLED'];

    private string $appKey = '';
    private string $appSecret = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->baseUrl            = 'https://api.n11.com';
        $this->timeout            = $this->config['api']['timeout_seconds'] ?? 60;
        $this->maxRetries         = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs   = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond = $this->config['api']['rate_limit_per_second'] ?? 5;
    }

    /**
     * API bilgilerini veritabanından yükle
     */
    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('N11 pazaryeri kaydı bulunamadı');
        }

        if (empty($marketplace['api_key_encrypted']) || empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('N11 API bilgileri henüz girilmemiş. Pazaryerleri > N11 > Ayarlar\'dan appkey ve appsecret girin.');
        }

        $this->appKey    = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->appSecret = $this->crypto->decrypt($marketplace['api_secret_encrypted']);
        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
    }

    /**
     * N11 auth header'ları (appkey + appsecret)
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        return [
            "appkey: {$this->appKey}",
            "appsecret: {$this->appSecret}",
        ];
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();

            // Basit sipariş sorgusu ile bağlantı testi
            $url = $this->baseUrl . '/rest/delivery/v1/shipmentPackages?page=0&size=1';
            $response = $this->httpGet($url, $this->authHeaders());

            return [
                'success' => true,
                'message' => 'N11 bağlantısı başarılı!',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $e->getMessage(),
            ];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * N11'den siparişleri çek
     *
     * filters:
     *  - status: Created|Picking|Shipped|Cancelled|Delivered|UnPacked|UnSupplied
     *  - startDate: timestamp ms (GMT+3)
     *  - endDate: timestamp ms (GMT+3)
     *  - orderNumber: string
     */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $allOrders = [];
        $page = 0;
        $size = (int) ($this->config['api']['page_size'] ?? 100);

        $queryParams = [
            'page' => 0,
            'size' => $size,
            'orderByDirection' => 'DESC',
        ];

        if (!empty($filters['status'])) {
            $queryParams['status'] = $filters['status'];
        }

        if (!empty($filters['startDate'])) {
            $queryParams['startDate'] = $filters['startDate'];
        }

        if (!empty($filters['endDate'])) {
            $queryParams['endDate'] = $filters['endDate'];
        }

        if (!empty($filters['orderNumber'])) {
            $queryParams['orderNumber'] = $filters['orderNumber'];
        }

        $this->startPaging('n11.orders');

        do {
            $queryParams['page'] = $page;

            $url = $this->baseUrl . '/rest/delivery/v1/shipmentPackages?' . http_build_query($queryParams);
            $response = $this->httpGet($url, $this->authHeaders());

            $orders = $response['content'] ?? [];
            $totalPages = $response['totalPages'] ?? 1;

            foreach ($orders as $order) {
                $allOrders[] = $this->normalizeOrder($order);
            }

            $page++;
        } while ($page < $totalPages && $this->keepPaging('n11.orders', $page, $orders));

        $this->logger->info(
            "N11'den {$page} sayfa, " . count($allOrders) . " sipariş çekildi",
            'N11Service'
        );

        return $allOrders;
    }

    /**
     * Sipariş durumunu güncelle (şu an sadece Picking desteği var)
     * $orderId: tek bir orderLineId veya virgülle ayrılmış birden fazla orderLineId
     */
    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();

        // N11'de şu an sadece Picking (onaylama) destekleniyor
        if ($status !== 'Picking') {
            $this->logger->warning("N11 UpdateOrder: Sadece Picking destekleniyor, istenen: {$status}", 'N11Service');
            return false;
        }

        // Birden fazla lineId virgülle gelebilir
        $lineIds = array_map('intval', explode(',', $orderId));
        $lines = array_map(fn($id) => ['lineId' => $id], $lineIds);

        $url = $this->baseUrl . '/rest/order/v1/update';
        $body = [
            'lines'  => $lines,
            'status' => 'Picking',
        ];

        $response = $this->httpPut($url, $body, $this->authHeaders());

        $allSuccess = true;
        $failReasons = [];
        foreach ($response['content'] ?? [] as $lineResult) {
            if (($lineResult['status'] ?? '') !== 'SUCCESS') {
                $allSuccess = false;
                $failReasons[] = "Line {$lineResult['lineId']}: " . ($lineResult['reasons'] ?? 'Bilinmeyen hata');
            }
        }

        if (!$allSuccess) {
            $reason = implode('; ', $failReasons);
            $this->logger->error("N11 sipariş onaylama hatası: {$reason}", 'N11Service');
        }

        return $allSuccess;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        // N11 REST API'de kargo bilgisi güncelleme henüz mevcut değil
        $this->logger->warning(
            "N11 kargo güncelleme: REST API'de henüz desteklenmiyor",
            'N11Service'
        );
        return false;
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * N11'den ürünleri listele
     */
    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/ms/product-query?' . http_build_query([
            'page' => $page,
            'size' => min($size, 250),
        ]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * n11 ürünü SATICI STOK KODU (stockCode) ile tanır — barkod ile DEĞİL.
     * Panel tarafında bu değer marketplace_products.marketplace_sku alanında tutulur;
     * yoksa dahili SKU, en son çare olarak barkod denenir.
     */
    private function resolveStockCode(array $item): string
    {
        foreach (['stockCode', 'sku', 'marketplace_sku', 'merchant_sku', 'barcode'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * n11 fiyat kuralı: salePrice ve listPrice BİRLİKTE gönderilir, küsurat noktadan
     * sonra 2 hane olur ve listPrice < salePrice olamaz (aksi halde task REJECT alır).
     *
     * @return array{salePrice: string, listPrice: string, currencyType: string}|null
     */
    private function normalizePricePair(array $item): ?array
    {
        $sale = isset($item['salePrice']) ? (float) $item['salePrice'] : null;
        if ($sale === null || $sale <= 0) {
            return null;
        }

        $list = isset($item['listPrice']) ? (float) $item['listPrice'] : 0.0;
        if ($list <= 0 || $list < $sale) {
            $list = $sale;
        }

        return [
            'salePrice'    => number_format($sale, 2, '.', ''),
            'listPrice'    => number_format($list, 2, '.', ''),
            'currencyType' => (string) ($item['currencyType'] ?? 'TL'),
        ];
    }

    private function integratorName(): string
    {
        // Form/controller anahtarı 'integrator_username', servis ise 'integrator_name'
        // okuyordu → girilen değer hiçbir zaman kullanılmıyor, hep 'OzyConnect'e
        // düşülüyordu. İki anahtar da kabul edilir.
        $name = trim((string) (
            $this->extraConfig['integrator_name']
            ?? $this->extraConfig['integrator_username']
            ?? ''
        ));

        return $name !== '' ? $name : 'OzyConnect';
    }

    /**
     * N11'de stok güncelle (price-stock-update görevi)
     *
     * Fiyat alanları BİLEREK gönderilmez: n11 fiyat doğrulaması (listPrice/salePrice
     * kuralı, günlük fiyat değişim limiti) takılırsa görev tamamen reddedilir ve stok
     * da güncellenmez. Fiyat ayrı görevle (updatePrice) gider.
     */
    public function updateStock(array $items): array
    {
        $skus = [];
        $errors = [];

        foreach ($items as $item) {
            $stockCode = $this->resolveStockCode($item);
            if ($stockCode === '') {
                $errors[] = ['error' => 'n11 stok kodu (stockCode) boş — ürün güncellenemedi'];
                continue;
            }

            if (!array_key_exists('quantity', $item)) {
                $errors[] = ['error' => "n11 stok güncellemesi için miktar yok: {$stockCode}"];
                continue;
            }

            $skus[] = [
                'stockCode' => $stockCode,
                'quantity'  => max(0, (int) $item['quantity']),
            ];
        }

        return $this->submitPriceStockTask($skus, 'stok', $errors);
    }

    /**
     * N11'de fiyat güncelle (price-stock-update görevi)
     */
    public function updatePrice(array $items): array
    {
        $skus = [];
        $errors = [];

        foreach ($items as $item) {
            $stockCode = $this->resolveStockCode($item);
            if ($stockCode === '') {
                $errors[] = ['error' => 'n11 stok kodu (stockCode) boş — fiyat güncellenemedi'];
                continue;
            }

            $prices = $this->normalizePricePair($item);
            if ($prices === null) {
                $errors[] = ['error' => "n11 fiyat güncellemesi için geçerli satış fiyatı yok: {$stockCode}"];
                continue;
            }

            $skus[] = ['stockCode' => $stockCode] + $prices;
        }

        return $this->submitPriceStockTask($skus, 'fiyat', $errors);
    }

    /**
     * price-stock-update görevini gönder ve SONUCUNU DOĞRULA.
     *
     * n11 asenkron çalışır: istek kabul edildiğinde yalnızca taskId döner. Görev
     * detayları sorgulanmadan "başarılı" denemez — panelin yeşil yazıp pazaryerinde
     * hiçbir şeyin değişmemesinin kök nedeni buydu.
     *
     * @param array<int, array<string, mixed>> $skus
     * @param array<int, array<string, mixed>> $preErrors Payload'a alınamayan kayıtlar
     * @return array{success: int, failed: int, errors: array, taskIds: array<int, int>, pending?: bool, notice?: string}
     */
    private function submitPriceStockTask(array $skus, string $label, array $preErrors = []): array
    {
        if ($skus === []) {
            return [
                'success' => 0,
                'failed'  => count($preErrors),
                'errors'  => $preErrors,
                'taskIds' => [],
            ];
        }

        $this->loadCredentials();

        $url     = $this->baseUrl . ($this->config['endpoints']['price_stock_update'] ?? '/ms/product/tasks/price-stock-update');
        $chunks  = array_chunk($skus, (int) ($this->config['api']['batch_limit'] ?? 1000));
        $success = 0;
        $failed  = count($preErrors);
        $errors  = $preErrors;
        $taskIds = [];
        $pending = false;
        $notices = [];

        foreach ($chunks as $chunk) {
            try {
                $response = $this->httpPost($url, [
                    'payload' => [
                        'integrator' => $this->integratorName(),
                        'skus'       => $chunk,
                    ],
                ], $this->authHeaders());

                $status = strtoupper(trim((string) ($response['status'] ?? '')));
                $taskId = (int) ($response['id'] ?? $response['taskId'] ?? 0);

                if ($status === 'REJECT' || $taskId <= 0) {
                    $failed += count($chunk);
                    $errors[] = ['error' => "n11 {$label} isteği reddedildi: " . $this->describeTaskReasons($response)];
                    continue;
                }

                $taskIds[] = $taskId;

                $taskResult = $this->waitForTask($taskId, count($chunk));
                $success += $taskResult['success'];
                $failed  += $taskResult['failed'];
                foreach ($taskResult['errors'] as $taskError) {
                    $errors[] = $taskError;
                }
                if (!empty($taskResult['pending'])) {
                    $pending = true;
                    $notices[] = $taskResult['notice'] ?? '';
                }
            } catch (\Throwable $e) {
                $failed += count($chunk);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        $result = [
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
            'taskIds' => $taskIds,
        ];

        if ($pending) {
            $result['pending'] = true;
            $result['notice']  = trim(implode(' ', array_filter($notices)));
        }

        return $result;
    }

    /**
     * Görev tamamlanana kadar task-details sorgula.
     *
     * @return array{success: int, failed: int, errors: array, pending: bool, notice?: string}
     */
    public function waitForTask(int $taskId, int $expected = 0): array
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::TASK_POLL_ATTEMPTS; $attempt++) {
            try {
                $response = $this->queryTaskDetails($taskId, 0, $expected > 0 ? min(1000, $expected) : 1000);

                $parsed = $this->parseTaskDetails($response, $taskId, $expected);
                if (!$parsed['pending']) {
                    return $parsed;
                }
            } catch (\Throwable $e) {
                // Görev n11 tarafından KABUL EDİLDİ (taskId var); detay sorgusunun
                // geçici hatası "gönderim başarısız" anlamına gelmez.
                $lastError = $e->getMessage();
                $this->logger->warning(
                    "n11 görev sonucu sorgulanamadı (task #{$taskId}): {$lastError}",
                    'N11Service'
                );
            }

            // Toplu gönderimde istek başına bekleme bütçesi aşılmasın
            if ($attempt < self::TASK_POLL_ATTEMPTS && !$this->pollWait(self::TASK_POLL_DELAY_MS)) {
                $lastError = $lastError !== '' ? $lastError : 'bekleme bütçesi doldu (görev kuyrukta)';
                break;
            }
        }

        $notice = "n11 görevi hâlâ kuyrukta (task #{$taskId}); sonuç birkaç dakika içinde işlenir."
            . ($lastError !== '' ? " Son sorgu hatası: {$lastError}" : '');
        $this->logger->info($notice, 'N11Service');

        return [
            'success' => $expected,
            'failed'  => 0,
            'errors'  => [],
            'pending' => true,
            'notice'  => $notice,
        ];
    }

    /**
     * task-details yanıtını sayısal sonuca çevir (SKU bazlı durum + gerekçe).
     *
     * @param array<string, mixed> $response
     * @return array{success: int, failed: int, errors: array, pending: bool}
     */
    private function parseTaskDetails(array $response, int $taskId, int $expected): array
    {
        $rows = $response['content']
            ?? $response['data']
            ?? $response['taskDetails']
            ?? $response['items']
            ?? [];

        if (!is_array($rows)) {
            $rows = [];
        }

        $taskStatus = strtoupper(trim((string) ($response['status'] ?? $response['taskStatus'] ?? '')));

        if ($rows === []) {
            // Detay henüz üretilmemiş: görev kuyrukta demektir.
            if ($taskStatus === '' || in_array($taskStatus, self::TASK_PENDING_STATUSES, true)) {
                return ['success' => 0, 'failed' => 0, 'errors' => [], 'pending' => true];
            }

            if (in_array($taskStatus, self::TASK_FAILED_STATUSES, true)) {
                return [
                    'success' => 0,
                    'failed'  => max(1, $expected),
                    'errors'  => [['error' => "n11 görevi reddedildi (task #{$taskId}): " . $this->describeTaskReasons($response)]],
                    'pending' => false,
                ];
            }

            return ['success' => $expected, 'failed' => 0, 'errors' => [], 'pending' => false];
        }

        $success = 0;
        $failed  = 0;
        $pending = 0;
        $errors  = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowStatus = strtoupper(trim((string) ($row['status'] ?? $row['taskDetailStatus'] ?? '')));
            $itemCode  = (string) ($row['itemCode'] ?? $row['stockCode'] ?? $row['sku']['stockCode'] ?? '');

            if (in_array($rowStatus, self::TASK_PENDING_STATUSES, true)) {
                $pending++;
                continue;
            }

            if (in_array($rowStatus, self::TASK_FAILED_STATUSES, true)) {
                $failed++;
                $errors[] = [
                    'sku'   => $itemCode,
                    'error' => trim($itemCode . ': ' . $this->describeTaskReasons($row), ': '),
                ];
                continue;
            }

            $success++;
        }

        if ($pending > 0 && $failed === 0 && $success === 0) {
            return ['success' => 0, 'failed' => 0, 'errors' => [], 'pending' => true];
        }

        return [
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
            'pending' => false,
        ];
    }

    /**
     * n11 "reasons" alanı string, dizi veya nesne dizisi olabilir.
     *
     * @param array<string, mixed> $payload
     */
    private function describeTaskReasons(array $payload): string
    {
        $reasons = $payload['reasons'] ?? $payload['reason'] ?? $payload['errors'] ?? $payload['message'] ?? null;

        if ($reasons === null || $reasons === '' || $reasons === []) {
            return 'Gerekçe bildirilmedi';
        }

        if (is_string($reasons)) {
            return $reasons;
        }

        $parts = [];
        foreach ((array) $reasons as $reason) {
            if (is_string($reason)) {
                $parts[] = $reason;
            } elseif (is_array($reason)) {
                $parts[] = (string) ($reason['message'] ?? $reason['description'] ?? $reason['reason']
                    ?? json_encode($reason, JSON_UNESCAPED_UNICODE));
            }
        }

        $text = trim(implode('; ', array_filter($parts)));
        return $text !== '' ? $text : 'Gerekçe bildirilmedi';
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN OLUŞTURMA / GÜNCELLEME (Panel → N11)
    // ═══════════════════════════════════════════════════════

    /**
     * N11'e ürün oluştur (async task-based, max 1000 SKU/request)
     *
     * @param array<int, array<string, mixed>> $items buildMarketplaceItem() formatında ürünler
     * @return array{success: bool, taskId: ?int, errors: array}
     */
    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        $skus = [];
        foreach ($items as $item) {
            $images = [];
            foreach ($item['images'] ?? [] as $i => $img) {
                $url = $img['url'] ?? '';
                if ($url !== '') {
                    $images[] = ['url' => $url, 'order' => $i];
                }
            }

            $attributes = [];
            foreach ($item['attributes'] ?? [] as $attr) {
                if (!empty($attr['customAttributeValue'])) {
                    $attributes[] = [
                        'id'          => (int) ($attr['attributeId'] ?? 0),
                        'valueId'     => null,
                        'customValue' => $attr['customAttributeValue'],
                    ];
                } elseif (!empty($attr['attributeValueId'])) {
                    $attributes[] = [
                        'id'          => (int) ($attr['attributeId'] ?? 0),
                        'valueId'     => (int) $attr['attributeValueId'],
                        'customValue' => 'null',
                    ];
                }
            }

            $skus[] = [
                'title'            => $item['title'] ?? '',
                'description'      => $item['description'] ?? $item['title'] ?? '',
                'categoryId'       => (int) ($item['categoryId'] ?? 0),
                'currencyType'     => $item['currencyType'] ?? 'TL',
                'productMainId'    => $item['productMainId'] ?? $item['stockCode'] ?? '',
                'preparingDay'     => (int) ($item['preparingDay'] ?? 3),
                'shipmentTemplate' => $item['shipmentTemplate'] ?? $this->extraConfig['shipment_template'] ?? '1',
                'stockCode'        => $item['stockCode'] ?? $item['barcode'] ?? '',
                'quantity'         => max(0, (int) ($item['quantity'] ?? 0)),
                'images'           => $images,
                'attributes'       => $attributes,
                'salePrice'        => (float) ($item['salePrice'] ?? 0),
                'listPrice'        => (float) ($item['listPrice'] ?? 0),
                'vatRate'          => (int) ($item['vatRate'] ?? 20),
            ];

            if (!empty($item['barcode'])) {
                $skus[array_key_last($skus)]['barcode'] = $item['barcode'];
            }
        }

        $chunks = array_chunk($skus, 1000);
        $lastTaskId = null;
        $allErrors = [];

        foreach ($chunks as $chunk) {
            try {
                $url = $this->baseUrl . '/ms/product/tasks/product-create';
                $body = [
                    'payload' => [
                        'integrator' => $this->extraConfig['integrator_name'] ?? 'OzyConnect',
                        'skus'       => $chunk,
                    ],
                ];

                $response = $this->httpPost($url, $body, $this->authHeaders());

                if (($response['status'] ?? '') === 'REJECT') {
                    $reasons = $response['reasons'] ?? ['Bilinmeyen red sebebi'];
                    $allErrors[] = ['error' => implode('; ', $reasons)];
                } else {
                    $lastTaskId = $response['id'] ?? null;
                }
            } catch (\Throwable $e) {
                $allErrors[] = ['error' => $e->getMessage()];
            }
        }

        $hasErrors = !empty($allErrors);

        return [
            'success' => !$hasErrors,
            'taskId'  => $lastTaskId,
            'errors'  => $allErrors,
        ];
    }

    /**
     * N11'de mevcut ürünleri güncelle (async task-based)
     *
     * @param array<int, array<string, mixed>> $items stockCode zorunlu
     * @return array{success: bool, taskId: ?int, errors: array}
     */
    public function updateProducts(array $items): array
    {
        $this->loadCredentials();

        $skus = [];
        foreach ($items as $item) {
            $sku = ['stockCode' => $this->resolveStockCode($item)];

            // vatRate SADECE açıkça verildiğinde gönderilir; varsayılan 20 ile
            // göndermek ürünün gerçek KDV oranını sessizce bozuyordu.
            if (isset($item['vatRate'])) {
                $sku['vatRate'] = (int) $item['vatRate'];
            }

            if (isset($item['description'])) {
                $sku['description'] = $item['description'];
            }
            if (isset($item['preparingDay'])) {
                $sku['preparingDay'] = (int) $item['preparingDay'];
            }
            if (isset($item['shipmentTemplate'])) {
                $sku['shipmentTemplate'] = $item['shipmentTemplate'];
            }
            if (isset($item['currencyType'])) {
                $sku['currencyType'] = $item['currencyType'];
            }
            if (isset($item['status'])) {
                $sku['status'] = $item['status']; // "Active" or "Suspended"
            }
            if (isset($item['productMainId'])) {
                $sku['productMainId'] = $item['productMainId'];
                $sku['deleteProductMainId'] = true;
            }

            $skus[] = $sku;
        }

        return $this->submitProductUpdateTask($skus);
    }

    /**
     * product-update görevini gönder ve sonucunu doğrula.
     *
     * @param array<int, array<string, mixed>> $skus
     * @param array<int, array<string, mixed>> $preErrors
     * @return array{success: bool, taskId: ?int, errors: array, processed: int, failed: int, pending?: bool, notice?: string}
     */
    private function submitProductUpdateTask(array $skus, array $preErrors = []): array
    {
        if ($skus === []) {
            return [
                'success'   => false,
                'taskId'    => null,
                'errors'    => $preErrors !== [] ? $preErrors : [['error' => 'Gönderilecek ürün yok']],
                'processed' => 0,
                'failed'    => count($preErrors),
            ];
        }

        $this->loadCredentials();

        $url        = $this->baseUrl . ($this->config['endpoints']['product_update'] ?? '/ms/product/tasks/product-update');
        $chunks     = array_chunk($skus, (int) ($this->config['api']['batch_limit'] ?? 1000));
        $lastTaskId = null;
        $allErrors  = $preErrors;
        $processed  = 0;
        $failed     = count($preErrors);
        $pending    = false;
        $notice     = '';

        foreach ($chunks as $chunk) {
            try {
                $response = $this->httpPost($url, [
                    'payload' => [
                        'integrator' => $this->integratorName(),
                        'skus'       => $chunk,
                    ],
                ], $this->authHeaders());

                $status = strtoupper(trim((string) ($response['status'] ?? '')));
                $taskId = (int) ($response['id'] ?? $response['taskId'] ?? 0);

                if ($status === 'REJECT' || $taskId <= 0) {
                    $failed += count($chunk);
                    $allErrors[] = ['error' => 'n11 ürün güncelleme reddedildi: ' . $this->describeTaskReasons($response)];
                    continue;
                }

                $lastTaskId = $taskId;

                $taskResult = $this->waitForTask($taskId, count($chunk));
                $processed += $taskResult['success'];
                $failed    += $taskResult['failed'];
                foreach ($taskResult['errors'] as $taskError) {
                    $allErrors[] = $taskError;
                }
                if (!empty($taskResult['pending'])) {
                    $pending = true;
                    $notice  = (string) ($taskResult['notice'] ?? '');
                }
            } catch (\Throwable $e) {
                $failed += count($chunk);
                $allErrors[] = ['error' => $e->getMessage()];
            }
        }

        $result = [
            'success'   => $failed === 0 && $lastTaskId !== null,
            'taskId'    => $lastTaskId,
            'errors'    => $allErrors,
            'processed' => $processed,
            'failed'    => $failed,
        ];

        if ($pending) {
            $result['pending'] = true;
            $result['notice']  = $notice;
        }

        return $result;
    }

    /**
     * N11'e varyantlı ürün grubu gönder
     * Aynı productMainId ile gönderilen skus otomatik varyant olarak gruplanır.
     *
     * @param array{items: array, groupId: string} $variantGroup
     * @return array{success: bool, taskId: ?int, errors: array}
     */
    public function createVariantProducts(array $variantGroup): array
    {
        // N11'de varyant = aynı productMainId ile createProducts çağırmak
        $items = [];
        foreach ($variantGroup['items'] ?? [] as $item) {
            $item['productMainId'] = $variantGroup['groupId'];
            $items[] = $item;
        }
        return $this->createProducts($items);
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İÇE AKTARMA (N11 → Panel)
    // ═══════════════════════════════════════════════════════

    public function importProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/ms/product-query?' . http_build_query([
            'page' => $page,
            'size' => min($size, 250),
        ]);

        $response = $this->httpGet($url, $this->authHeaders());

        $products = [];
        foreach ($response['content'] ?? [] as $raw) {
            $products[] = $this->normalizeImportProduct($raw);
        }

        return [
            'products'      => $products,
            'totalPages'    => $response['totalPages'] ?? 1,
            'totalElements' => $response['totalElements'] ?? 0,
        ];
    }

    /**
     * Kategori ağacını çek
     */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/cdn/categories';
        $response = $this->httpGet($url, $this->authHeaders());

        // API yanıtı {"categories": [...]} formatında geliyor
        $categories = $response['categories'] ?? $response;

        // Tüm kategori ağacı tek seferde geliyor
        return $this->flattenCategories($categories, $parentId);
    }

    public function fetchBrands(string $search = ''): array
    {
        // N11 REST API'de ayrı marka servisi yok — ürünlerin attributes alanından "Marka" çekiyoruz
        // NOT: Tüm ürünleri taramak çok yavaş, ilk 10 sayfa (2500 ürün) yeterli marka çeşitliliği sağlar
        $brands = [];
        $page = 0;

        while (true) {
            $response = $this->fetchProducts($page, 250);
            $items = $response['content'] ?? [];
            if (empty($items)) {
                break;
            }

            $brandsBefore = count($brands);

            foreach ($items as $product) {
                foreach ($product['attributes'] ?? [] as $attr) {
                    if (mb_strtolower($attr['attributeName'] ?? '') === 'marka') {
                        $brandName = trim($attr['attributeValue'] ?? '');
                        if ($brandName !== '' && !isset($brands[$brandName])) {
                            $brands[$brandName] = [
                                'id'   => md5('n11_brand_' . mb_strtolower($brandName)),
                                'name' => $brandName,
                            ];
                        }
                        break;
                    }
                }
            }

            // Son sayfa kontrolü
            if (($response['last'] ?? false) === true || count($items) < 250) {
                break;
            }

            $page++;
        }

        $this->logger->info("N11 fetchBrands: {$page} sayfa tarandı, " . count($brands) . " marka bulundu", 'N11Service');

        // Arama filtresi varsa uygula
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            $brands = array_filter($brands, fn($b) => str_contains(mb_strtolower($b['name']), $searchLower));
        }

        return array_values($brands);
    }

    /**
     * Kategori özelliklerini çek
     */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/cdn/category/' . $marketplaceCategoryId . '/attribute';
        $response = $this->httpGet($url, $this->authHeaders());

        $attributes = [];
        foreach ($response['categoryAttributes'] ?? [] as $attr) {
            $values = [];
            foreach ($attr['attributeValues'] ?? [] as $val) {
                $values[] = [
                    'id'   => $val['id'] ?? 0,
                    'name' => $val['value'] ?? '',
                ];
            }

            $attributes[] = [
                'id'          => $attr['attributeId'] ?? 0,
                'name'        => $attr['attributeName'] ?? '',
                'required'    => (bool) ($attr['isMandatory'] ?? false),
                'allowCustom' => (bool) ($attr['isCustomValue'] ?? false),
                'isVariant'   => (bool) ($attr['isVariant'] ?? false),
                'isSlicer'    => (bool) ($attr['isSlicer'] ?? false),
                'values'      => $values,
            ];
        }

        return $attributes;
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ (SOAP — ReturnService)
    // WSDL: https://api.n11.com/ws/ReturnService.wsdl
    // ═══════════════════════════════════════════════════════

    private const RETURN_WSDL = 'https://api.n11.com/ws/ReturnService.wsdl';

    private ?\SoapClient $soapReturnClient = null;

    /**
     * SOAP istemcisini oluştur (tek sefer, lazy)
     */
    private function getSoapReturnClient(): \SoapClient
    {
        if ($this->soapReturnClient !== null) {
            return $this->soapReturnClient;
        }

        $this->soapReturnClient = new \SoapClient(self::RETURN_WSDL, [
            'trace'              => true,
            'exceptions'         => true,
            'connection_timeout' => $this->timeout,
            'default_socket_timeout' => $this->timeout,
            'cache_wsdl'         => WSDL_CACHE_BOTH,
            'encoding'           => 'UTF-8',
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);

        return $this->soapReturnClient;
    }

    /**
     * SOAP auth parametresini döndür
     */
    private function soapAuth(): array
    {
        return [
            'appKey'    => $this->appKey,
            'appSecret' => $this->appSecret,
        ];
    }

    /**
     * SOAP yanıtını array'e dönüştür (stdClass → array, recursive)
     */
    private function soapResponseToArray(mixed $data): mixed
    {
        if ($data instanceof \stdClass) {
            return $this->soapResponseToArray((array) $data);
        }

        if (is_array($data)) {
            return array_map([$this, 'soapResponseToArray'], $data);
        }

        return $data;
    }

    /**
     * İade taleplerini listele (ClaimReturnList)
     *
     * @param array{
     *   status?: string,
     *   executer?: string,
     *   searchInfoType?: string,
     *   searchQuery?: string,
     *   startDate?: string,
     *   endDate?: string,
     *   page?: int
     * } $filters
     * @return array{returns: array, total: int, pageCount: int, currentPage: int}
     */
    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        $status = $filters['status'] ?? 'ALL';
        $validStatuses = ['REQUESTED', 'CANCELLED', 'DENIED', 'PENDING', 'PENDED', 'APPROVED', 'MANUAL_REFUND', 'ALL'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'ALL';
        }

        // SOAP struct alanları WSDL'deki tüm property'leri bekler;
        // SoapVar ile sadece dolu alanları göndererek "object has no X property" hatasını önlüyoruz.
        $searchFields = [
            new \SoapVar($status, XSD_STRING, null, null, 'status'),
        ];

        if (!empty($filters['executer'])) {
            $searchFields[] = new \SoapVar($filters['executer'], XSD_STRING, null, null, 'executer');
        }

        if (!empty($filters['searchInfoType']) && !empty($filters['searchQuery'])) {
            $searchFields[] = new \SoapVar($filters['searchInfoType'], XSD_STRING, null, null, 'searchInfoType');
            $searchFields[] = new \SoapVar($filters['searchQuery'], XSD_STRING, null, null, 'searchQuery');
        }

        if (!empty($filters['startDate']) || !empty($filters['endDate'])) {
            $periodFields = [];
            if (!empty($filters['startDate'])) {
                $periodFields[] = new \SoapVar($filters['startDate'], XSD_STRING, null, null, 'startDate');
            }
            if (!empty($filters['endDate'])) {
                $periodFields[] = new \SoapVar($filters['endDate'], XSD_STRING, null, null, 'endDate');
            }
            $searchFields[] = new \SoapVar($periodFields, SOAP_ENC_OBJECT, null, null, 'period');
        }

        $params = [
            'auth'       => $this->soapAuth(),
            'searchData' => new \SoapVar($searchFields, SOAP_ENC_OBJECT),
            'pagingData' => [
                'currentPage' => (int) ($filters['page'] ?? 0),
            ],
        ];

        try {
            $client   = $this->getSoapReturnClient();
            $response = $client->ClaimReturnList($params);
            $result   = $this->soapResponseToArray($response);

            $resultStatus = $result['result']['status'] ?? '';
            if ($resultStatus !== 'success') {
                $errorMsg = $result['result']['errorMessage'] ?? $result['result']['message'] ?? 'Bilinmeyen SOAP hatası';
                $this->logger->error("N11 iade listesi hata: {$errorMsg}", 'N11Service');
                return ['returns' => [], 'total' => 0, 'pageCount' => 0, 'currentPage' => 0];
            }

            $claimList = $result['claimReturnList']['claimReturn'] ?? $result['claimReturnList'] ?? [];
            if (!is_array($claimList)) {
                $claimList = [];
            }
            // SOAP_SINGLE_ELEMENT_ARRAYS sayesinde tek kayıt da array gelir
            // Ama garanti olsun: eğer 'claimReturnId' varsa tek eleman
            if (isset($claimList['claimReturnId'])) {
                $claimList = [$claimList];
            }

            $pagingData  = $result['pagingData'] ?? [];
            $total       = (int) ($pagingData['totalCount'] ?? count($claimList));
            $pageCount   = (int) ($pagingData['pageCount'] ?? 1);
            $currentPage = (int) ($pagingData['currentPage'] ?? 0);

            $returns = [];
            foreach ($claimList as $claim) {
                $returns[] = $this->normalizeReturn($claim);
            }

            $this->logger->debug("N11 iade listesi: {$total} kayıt, sayfa {$currentPage}/{$pageCount}", 'N11Service');

            return [
                'returns'     => $returns,
                'total'       => $total,
                'pageCount'   => $pageCount,
                'currentPage' => $currentPage,
            ];
        } catch (\SoapFault $e) {
            $this->logger->error('N11 SOAP iade listesi hatası: ' . $e->getMessage(), 'N11Service');
            throw new RuntimeException('N11 iade listesi alınamadı: ' . $e->getMessage());
        }
    }

    /**
     * İade ret sebeplerini listele (ClaimReturnDenyReasonType)
     *
     * @return array<int, array{id: int, value: string}>
     */
    public function fetchReturnDenyReasons(): array
    {
        $this->loadCredentials();

        try {
            $client   = $this->getSoapReturnClient();
            $response = $client->ClaimReturnDenyReasonType(['auth' => $this->soapAuth()]);
            $result   = $this->soapResponseToArray($response);

            if (($result['result']['status'] ?? '') !== 'success') {
                $this->logger->error('N11 ret sebepleri alınamadı: ' . ($result['result']['errorMessage'] ?? ''), 'N11Service');
                return [];
            }

            $list = $result['denyReasonTypeDataList'] ?? [];
            if (!is_array($list)) {
                return [];
            }
            // Tek eleman kontrolü
            if (isset($list['id'])) {
                $list = [$list];
            }

            $reasons = [];
            foreach ($list as $item) {
                $reasons[] = [
                    'id'    => (int) ($item['id'] ?? 0),
                    'value' => (string) ($item['value'] ?? ''),
                ];
            }

            return $reasons;
        } catch (\SoapFault $e) {
            $this->logger->error('N11 ret sebepleri SOAP hatası: ' . $e->getMessage(), 'N11Service');
            return [];
        }
    }

    /**
     * İade erteleme sebeplerini listele (ClaimReturnPendingReasonType)
     *
     * @return array<int, array{id: int, value: string}>
     */
    public function fetchReturnPendingReasons(): array
    {
        $this->loadCredentials();

        try {
            $client   = $this->getSoapReturnClient();
            $response = $client->ClaimReturnPendingReasonType(['auth' => $this->soapAuth()]);
            $result   = $this->soapResponseToArray($response);

            if (($result['result']['status'] ?? '') !== 'success') {
                $this->logger->error('N11 erteleme sebepleri alınamadı: ' . ($result['result']['errorMessage'] ?? ''), 'N11Service');
                return [];
            }

            $list = $result['pendingReasonTypeDataList'] ?? [];
            if (!is_array($list)) {
                return [];
            }
            if (isset($list['id'])) {
                $list = [$list];
            }

            $reasons = [];
            foreach ($list as $item) {
                $reasons[] = [
                    'id'    => (int) ($item['id'] ?? 0),
                    'value' => (string) ($item['value'] ?? ''),
                ];
            }

            return $reasons;
        } catch (\SoapFault $e) {
            $this->logger->error('N11 erteleme sebepleri SOAP hatası: ' . $e->getMessage(), 'N11Service');
            return [];
        }
    }

    /**
     * İade talebini onayla (ClaimReturnApprove)
     */
    public function approveReturn(string $claimReturnId): bool
    {
        $this->loadCredentials();

        try {
            $client   = $this->getSoapReturnClient();
            $response = $client->ClaimReturnApprove(new \SoapVar([
                new \SoapVar($this->soapAuth(), SOAP_ENC_OBJECT, null, null, 'auth'),
                new \SoapVar((int) $claimReturnId, XSD_LONG, null, null, 'claimReturnId'),
            ], SOAP_ENC_OBJECT));
            $result = $this->soapResponseToArray($response);

            if (($result['result']['status'] ?? '') === 'success') {
                $this->logger->info("N11 iade onaylandı: #{$claimReturnId}", 'N11Service');
                return true;
            }

            $errorMsg = $result['result']['errorMessage'] ?? 'Bilinmeyen hata';
            $this->logger->error("N11 iade onay hatası #{$claimReturnId}: {$errorMsg}", 'N11Service');
            return false;
        } catch (\SoapFault $e) {
            $this->logger->error("N11 iade onay SOAP hatası #{$claimReturnId}: " . $e->getMessage(), 'N11Service');
            throw new RuntimeException('N11 iade onaylanamadı: ' . $e->getMessage());
        }
    }

    /**
     * İade talebini ertele (ClaimReturnPending)
     *
     * @param string $claimReturnId  İade talep ID
     * @param int    $pendingReasonId Erteleme sebep ID (ClaimReturnPendingReasonType'dan)
     * @param int    $pendingDayCount Erteleme gün sayısı
     * @param string $pendingReasonNote Erteleme açıklaması
     */
    public function pendReturn(string $claimReturnId, int $pendingReasonId, int $pendingDayCount, string $pendingReasonNote): bool
    {
        $this->loadCredentials();

        try {
            $client   = $this->getSoapReturnClient();
            $response = $client->ClaimReturnPending([
                'auth'              => $this->soapAuth(),
                'claimReturnId'     => (int) $claimReturnId,
                'pendingReasonId'   => (int) $pendingReasonId,
                'pendingDayCount'   => $pendingDayCount,
                'pendingReasonNote' => $pendingReasonNote,
            ]);
            $result = $this->soapResponseToArray($response);

            if (($result['result']['status'] ?? '') === 'success') {
                $this->logger->info("N11 iade ertelendi: #{$claimReturnId}, {$pendingDayCount} gün", 'N11Service');
                return true;
            }

            $errorMsg = $result['result']['errorMessage'] ?? 'Bilinmeyen hata';
            $this->logger->error("N11 iade erteleme hatası #{$claimReturnId}: {$errorMsg}", 'N11Service');
            return false;
        } catch (\SoapFault $e) {
            $this->logger->error("N11 iade erteleme SOAP hatası #{$claimReturnId}: " . $e->getMessage(), 'N11Service');
            throw new RuntimeException('N11 iade ertelenemedi: ' . $e->getMessage());
        }
    }

    /**
     * İade talebini reddet (ClaimReturnDeny)
     *
     * @param string $returnId İade talep ID (claimReturnId)
     * @param string $reason   JSON encoded: {"denyReasonId":int,"denyReasonNote":string, ...opsiyonel alanlar}
     */
    public function rejectReturn(string $returnId, string $reason): bool
    {
        $this->loadCredentials();

        $data = json_decode($reason, true);
        if (!is_array($data) || empty($data['denyReasonId'])) {
            $this->logger->error("N11 rejectReturn: Geçersiz reason JSON - {$reason}", 'N11Service');
            return false;
        }

        // SoapVar ile sadece dolu alanları gönder (WSDL struct tüm alanları bekler)
        $denyFields = [
            new \SoapVar($this->soapAuth(), SOAP_ENC_OBJECT, null, null, 'auth'),
            new \SoapVar((int) $returnId, XSD_LONG, null, null, 'claimReturnId'),
            new \SoapVar((int) $data['denyReasonId'], XSD_LONG, null, null, 'denyReasonId'),
            new \SoapVar((string) ($data['denyReasonNote'] ?? 'İade talebi reddedildi.'), XSD_STRING, null, null, 'denyReasonNote'),
        ];

        $optionalFields = [
            'returnShipmentType', 'trackingNumber',
            'deliveryReceiptNumber', 'imageFirstUrl', 'imageSecondUrl',
            'imageThirdUrl', 'videoUrl',
        ];
        foreach ($optionalFields as $field) {
            if (!empty($data[$field])) {
                $denyFields[] = new \SoapVar((string) $data[$field], XSD_STRING, null, null, $field);
            }
        }
        if (!empty($data['shipmentCompanyId'])) {
            $denyFields[] = new \SoapVar((int) $data['shipmentCompanyId'], XSD_LONG, null, null, 'shipmentCompanyId');
        }

        try {
            $client   = $this->getSoapReturnClient();
            $response = $client->ClaimReturnDeny(new \SoapVar($denyFields, SOAP_ENC_OBJECT));
            $result   = $this->soapResponseToArray($response);

            if (($result['result']['status'] ?? '') === 'success') {
                $this->logger->info("N11 iade reddedildi: #{$returnId}", 'N11Service');
                return true;
            }

            $errorMsg = $result['result']['errorMessage'] ?? 'Bilinmeyen hata';
            $this->logger->error("N11 iade ret hatası #{$returnId}: {$errorMsg}", 'N11Service');
            return false;
        } catch (\SoapFault $e) {
            $this->logger->error("N11 iade ret SOAP hatası #{$returnId}: " . $e->getMessage(), 'N11Service');
            throw new RuntimeException('N11 iade reddedilemedi: ' . $e->getMessage());
        }
    }

    /**
     * SOAP iade verisini OzyConnect standart formatına dönüştür
     */
    private function normalizeReturn(array $claim): array
    {
        $statusMap = [
            'REQUESTED'     => 'requested',
            'CANCELLED'     => 'cancelled',
            'DENIED'        => 'rejected',
            'PENDING'       => 'pending',
            'PENDED'        => 'pended',
            'APPROVED'      => 'approved',
            'MANUAL_REFUND' => 'manual_refund',
        ];

        $statusLabelMap = [
            'REQUESTED'     => 'İade Talebi Geldi',
            'CANCELLED'     => 'İptal Edildi',
            'DENIED'        => 'Reddedildi',
            'PENDING'       => 'Erteleme Talep Edildi',
            'PENDED'        => 'Değerlendirme Ertelendi',
            'APPROVED'      => 'Onaylandı',
            'MANUAL_REFUND' => 'Manuel İade Tamamlandı',
        ];

        // SOAP, status ve executer'ı boş enum struct olarak döndürebilir (boş array).
        // Gerçek durumu tarih alanlarından türet.
        $rawStatus = is_string($claim['status'] ?? null) && ($claim['status'] ?? '') !== ''
            ? strtoupper(trim($claim['status']))
            : $this->deriveStatusFromDates($claim);

        // executer: string veya boş array/struct olabilir
        $executer = is_string($claim['executer'] ?? null) && ($claim['executer'] ?? '') !== ''
            ? $claim['executer']
            : ((string) ($claim['sender'] ?? ''));

        $requestDate = $this->parseN11Date($claim['requestDate'] ?? null);

        return [
            'claim_id'                  => (string) ($claim['claimReturnId'] ?? ''),
            'marketplace_return_id'     => (string) ($claim['claimReturnId'] ?? ''),
            'marketplace_id'            => 0, // Controller'da set edilecek
            'marketplace_name'          => 'n11',
            'marketplace_slug'          => 'n11',
            'marketplace_order_id'      => (string) ($claim['orderNumber'] ?? ''),
            'order_id'                  => null,
            'customer_name'             => (string) ($claim['buyerName'] ?? ''),
            'customer_email'            => (string) ($claim['buyerEmail'] ?? ''),
            'customer_phone'            => (string) ($claim['buyerPhone'] ?? ''),
            'product_id'                => (string) ($claim['productId'] ?? ''),
            'product_name'              => (string) ($claim['productName'] ?? ''),
            'attributes'                => (string) ($claim['attributesNames'] ?? ''),
            'quantity'                  => (int) ($claim['quantity'] ?? 1),
            'unit_price'                => (float) ($claim['unitPrice'] ?? 0),
            'final_price'               => (float) ($claim['finalPrice'] ?? 0),
            'reason'                    => (string) ($claim['returnReasonType'] ?? ''),
            'reason_description'        => (string) ($claim['returnReasonDescription'] ?? ''),
            'status'                    => $statusMap[$rawStatus] ?? strtolower($rawStatus),
            'status_raw'                => $rawStatus,
            'status_label'              => $statusLabelMap[$rawStatus] ?? $rawStatus,
            'executer'                  => $executer,
            'shipment_method'           => (string) ($claim['shipmentMethod'] ?? ''),
            'cargo_company'             => (string) ($claim['shipmentCompany'] ?? ''),
            'cargo_tracking_no'         => (string) ($claim['trackingNumber'] ?? ''),
            'seller_tracking_no'        => (string) ($claim['sellerTrackingNumber'] ?? ''),
            'seller_campaign_number'    => (string) ($claim['sellerCampaignNumber'] ?? ''),
            'campaign_number'           => (string) ($claim['campaignNumber'] ?? ''),
            'delivery_fee_type'         => (string) ($claim['deliveryFeeType'] ?? ''),
            'request_date'              => $requestDate,
            'payment_date'              => $this->parseN11Date($claim['paymentDate'] ?? null),
            'cancelled_date'            => $this->parseN11Date($claim['cancelledDate'] ?? null),
            'denied_date'               => $this->parseN11Date($claim['deniedDate'] ?? null),
            'approved_date'             => $this->parseN11Date($claim['approvedDate'] ?? null),
            'pending_request_date'      => $this->parseN11Date($claim['pendingRequestDate'] ?? null),
            'pended_date'               => $this->parseN11Date($claim['pendedDate'] ?? null),
            'created_at'                => $requestDate,
            'action_required'           => $rawStatus === 'REQUESTED',
            'image_url'                 => '',
            'items'                     => [[
                'product_name'   => (string) ($claim['productName'] ?? ''),
                'merchant_sku'   => (string) ($claim['skuId'] ?? ''),
                'barcode'        => '',
                'reason'         => (string) ($claim['returnReasonType'] ?? ''),
                'reason_code'    => '',
                'status'         => $rawStatus,
                'status_label'   => $statusLabelMap[$rawStatus] ?? $rawStatus,
                'customer_note'  => (string) ($claim['returnReasonDescription'] ?? ''),
                'image_url'      => '',
                'customer_images' => [],
            ]],
            'item_count'    => 1,
            'claim_item_ids' => [(string) ($claim['claimReturnId'] ?? '')],
            'raw_data'      => $claim,
        ];
    }

    /**
     * SOAP'ta status boş enum struct olarak gelebilir.
     * Tarih alanlarından durumu türet: en son dolu olan tarih = o durum.
     */
    private function deriveStatusFromDates(array $claim): string
    {
        // Öncelik sırasıyla: en kesin durumdan en belirsiz duruma
        if ($this->hasDate($claim['approvedDate'] ?? null))       return 'APPROVED';
        if ($this->hasDate($claim['deniedDate'] ?? null))         return 'DENIED';
        if ($this->hasDate($claim['cancelledDate'] ?? null))      return 'CANCELLED';
        if ($this->hasDate($claim['pendedDate'] ?? null))         return 'PENDED';
        if ($this->hasDate($claim['pendingRequestDate'] ?? null)) return 'PENDING';
        if ($this->hasDate($claim['requestDate'] ?? null))        return 'REQUESTED';
        return 'REQUESTED';
    }

    private function hasDate(mixed $val): bool
    {
        return is_string($val) && trim($val) !== '';
    }

    /**
     * N11 tarih formatını (dd/mm/yyyy) → Y-m-d H:i:s çevir
     */
    private function parseN11Date(mixed $date): ?string
    {
        // SOAP boş enum/struct alanları array olarak dönebilir
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        $date = trim($date);

        // dd/mm/yyyy formatı
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]} 00:00:00";
        }

        // Zaten Y-m-d formatında ise
        $ts = strtotime($date);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    // ═══════════════════════════════════════════════════════
    // PAKET BÖLME & KISMİ İPTAL
    // ═══════════════════════════════════════════════════════

    /**
     * Paket böl (SplitPackages)
     * Siparişler yalnızca Picking statüsünde bölünebilir
     *
     * @param array<int, array<int>> $splitGroups [[orderLineId1], [orderLineId2, orderLineId3]]
     * @return array{success: bool, error: ?string}
     */
    public function splitPackages(array $splitGroups): array
    {
        $this->loadCredentials();

        $groups = [];
        foreach ($splitGroups as $group) {
            $groups[] = ['orderLineIds' => array_map('intval', $group)];
        }

        $url = $this->baseUrl . '/rest/delivery/v1/splitCombinePackage';
        $body = ['splitGroups' => $groups];

        try {
            $response = $this->httpPost($url, $body, $this->authHeaders());

            $code = $response['code'] ?? 0;
            if ($code === 200 || ($response['message'] ?? '') === 'success') {
                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => $response['message'] ?? 'Bilinmeyen hata'];
        } catch (\Throwable $e) {
            $this->logger->error("N11 paket bölme hatası: " . $e->getMessage(), 'N11Service');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Miktar bazlı paket böl + Kısmi iptal (SplitPackagesByQuantity & PartialCancel)
     *
     * @param array $splitPackages [['orderLineId' => int, 'quantities' => int], ...]
     * @param array $cancelledItems [['orderLineId' => int, 'quantity' => int, 'cancelReasonId' => int], ...]
     */
    public function splitPackagesByQuantity(array $splitPackages, array $cancelledItems = []): array
    {
        $this->loadCredentials();

        // Split paketlerini grupla
        $packages = [];
        foreach ($splitPackages as $pkg) {
            $packages[] = [
                'packageDetails' => [[
                    'orderLineId' => (int) ($pkg['orderLineId'] ?? 0),
                    'quantities'  => (int) ($pkg['quantities'] ?? 1),
                ]],
            ];
        }

        $body = ['splitPackages' => $packages];

        // İptal edilecek ürünler varsa ekle
        if (!empty($cancelledItems)) {
            $body['cancelledItems'] = [];
            foreach ($cancelledItems as $cancel) {
                $body['cancelledItems'][] = [
                    'cancelReasonId' => (int) ($cancel['cancelReasonId'] ?? 65), // 65 = Diğer
                    'orderLineId'    => (int) ($cancel['orderLineId'] ?? 0),
                    'quantity'       => (int) ($cancel['quantity'] ?? 1),
                ];
            }
        }

        $url = $this->baseUrl . '/rest/delivery/v1/splitPackageByQuantity';

        try {
            $response = $this->httpPost($url, $body, $this->authHeaders());

            $code = $response['code'] ?? 0;
            if ($code === 200 || ($response['message'] ?? '') === 'success') {
                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => $response['message'] ?? 'Bilinmeyen hata'];
        } catch (\Throwable $e) {
            $this->logger->error("N11 miktar kısmi iptal hatası: " . $e->getMessage(), 'N11Service');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sipariş kalemi işçilik bedeli ekleme
     *
     * @param array $laborDetails [['orderLineId' => int, 'totalLaborCostExcludingVAT' => float, 'laborVatRate' => int], ...]
     */
    public function addLaborCosts(array $laborDetails): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/rest/order/v1/labor-costs';
        $body = ['laborCostDetails' => $laborDetails];

        try {
            $response = $this->httpPut($url, $body, $this->authHeaders());

            $results = [];
            foreach ($response['content'] ?? [] as $item) {
                $results[] = [
                    'lineId'  => $item['lineId'] ?? 0,
                    'status'  => $item['status'] ?? 'FAIL',
                    'reasons' => $item['reasons'] ?? '',
                    'details' => $item['details'] ?? [],
                ];
            }

            return ['success' => true, 'results' => $results];
        } catch (\Throwable $e) {
            $this->logger->error("N11 işçilik bedeli hatası: " . $e->getMessage(), 'N11Service');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * N11 ürün durumunu güncelle (Active/Suspended)
     */
    public function updateProductStatus(string $stockCode, string $status): array
    {
        $normalized = strcasecmp($status, 'Active') === 0 ? 'Active' : 'Suspended';

        return $this->setListingStatus([['stockCode' => $stockCode]], $normalized === 'Active');
    }

    /**
     * Ürünü n11'de satışa aç / satıştan kaldır.
     *
     * Pazaryeri servislerinin ORTAK sözleşmesi: setListingStatus(items, active).
     * n11 bunu product-update görevindeki status alanıyla yapar (Active/Suspended)
     * ve sonuç task-details ile doğrulanır.
     *
     * @param array<int, array<string, mixed>|string> $items
     * @return array{success: int, failed: int, errors: array, method: string}
     */
    public function setListingStatus(array $items, bool $active): array
    {
        $skus      = [];
        $preErrors = [];

        foreach ($items as $item) {
            $normalized = is_array($item) ? $item : ['stockCode' => (string) $item];
            $stockCode  = $this->resolveStockCode($normalized);

            if ($stockCode === '') {
                $preErrors[] = ['error' => 'n11 stok kodu (stockCode) boş — durum güncellenemedi'];
                continue;
            }

            $skus[] = [
                'stockCode' => $stockCode,
                'status'    => $active ? 'Active' : 'Suspended',
            ];
        }

        $result = $this->submitProductUpdateTask($skus, $preErrors);

        $this->logger->info(
            'N11 listeleme durumu: ' . ($active ? 'Active' : 'Suspended')
            . " — {$result['processed']} başarılı, {$result['failed']} hatalı",
            'N11Service'
        );

        return [
            'success' => $result['processed'],
            'failed'  => $result['failed'],
            'errors'  => $result['errors'],
            'method'  => 'product-update:status',
        ];
    }

    /**
     * N11 iptal sebepleri (cancelReasonId listesi)
     */
    public function getCancelReasons(): array
    {
        return [
            ['id' => 61, 'description' => 'Stok Tükendi'],
            ['id' => 62, 'description' => 'Kusurlu/Defolu/Bozuk Ürün'],
            ['id' => 63, 'description' => 'Hatalı Fiyat'],
            ['id' => 64, 'description' => 'Mücbir Sebep'],
            ['id' => 65, 'description' => 'Diğer'],
        ];
    }

    // ═══════════════════════════════════════════════════════
    // TASK SORGULAMA
    // ═══════════════════════════════════════════════════════

    /**
     * Task detaylarını sorgula (ürün yükleme/güncelleme sonuçları)
     */
    public function queryTaskDetails(int $taskId, int $page = 0, int $size = 1000): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/ms/product/task-details/page-query';
        $body = [
            'taskId'   => $taskId,
            'pageable' => ['page' => $page, 'size' => $size],
        ];

        return $this->httpPost($url, $body, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // NORMALIZE EDİCİLER
    // ═══════════════════════════════════════════════════════

    /**
     * N11 sipariş verisini OzyConnect standart formatına dönüştür
     */
    private function normalizeOrder(array $raw): array
    {
        $statusMapping = $this->config['status_mapping'] ?? [];

        $lines = $raw['lines'] ?? [];
        $items = [];
        $subtotal = 0;
        $discount = 0;
        $taxTotal = 0;

        foreach ($lines as $line) {
            $qty       = (int) ($line['quantity'] ?? 1);
            $unitPrice = (float) ($line['price'] ?? 0);
            $sellerDiscount    = (float) ($line['totalSellerDiscountPrice'] ?? 0);
            $sellerInvoiceAmt  = (float) ($line['sellerInvoiceAmount'] ?? 0);
            $lineTotal = $sellerInvoiceAmt > 0 ? $sellerInvoiceAmt : ($qty * $unitPrice - $sellerDiscount);

            $vatRate = (float) ($line['vatRate'] ?? 20);
            $lineTax = ($lineTotal > 0 && $vatRate > 0)
                ? round($lineTotal * $vatRate / (100 + $vatRate), 2)
                : 0;

            $items[] = [
                'marketplace_item_id' => (string) ($line['orderLineId'] ?? ''),
                'sku'                 => $line['stockCode'] ?? '',
                'barcode'             => $line['barcode'] ?? '',
                'name'                => $line['productName'] ?? '',
                'qty'                 => $qty,
                'unit_price'          => $unitPrice,
                'discount'            => $sellerDiscount,
                'tax_rate'            => $vatRate,
                'line_total'          => $lineTotal,
            ];

            $subtotal += ($lineTotal - $lineTax);
            $taxTotal += $lineTax;
            $discount += $sellerDiscount;
        }

        // Statü: N11 doğrudan shipmentPackageStatus veriyor
        $n11Status = $raw['shipmentPackageStatus'] ?? 'Created';

        // Müşteri bilgileri
        $shipAddr = $raw['shippingAddress'] ?? [];
        $billAddr = $raw['billingAddress'] ?? [];

        $customerName = $raw['customerfullName'] ?? $shipAddr['fullName'] ?? $billAddr['fullName'] ?? '';
        $phone = $shipAddr['gsm'] ?? $billAddr['gsm'] ?? '';
        $email = $raw['customerEmail'] ?? '';

        // TC / Vergi no
        $tcId = $raw['tcIdentityNumber'] ?? $billAddr['tcId'] ?? '';
        $taxId = $raw['taxId'] ?? $billAddr['taxId'] ?? '';
        $taxOffice = $raw['taxOffice'] ?? $billAddr['taxHouse'] ?? '';
        $invoiceType = (int) ($billAddr['invoiceType'] ?? 1); // 1=Bireysel, 2=Kurumsal
        $isCommercial = $invoiceType === 2;

        $shippingAddress = [
            'name'         => $shipAddr['fullName'] ?? $customerName,
            'address'      => $shipAddr['address'] ?? '',
            'city'         => $shipAddr['city'] ?? '',
            'district'     => $shipAddr['district'] ?? '',
            'postal_code'  => $shipAddr['postalCode'] ?? '',
            'phone'        => $phone,
            'neighborhood' => $shipAddr['neighborhood'] ?? '',
        ];

        $billingAddress = [
            'name'        => $billAddr['fullName'] ?? $customerName,
            'address'     => $billAddr['address'] ?? '',
            'city'        => $billAddr['city'] ?? '',
            'district'    => $billAddr['district'] ?? '',
            'postal_code' => $billAddr['postalCode'] ?? '',
            'company'     => $isCommercial ? ($billAddr['fullName'] ?? '') : '',
            'tax_office'  => $taxOffice,
            'tax_number'  => $isCommercial ? $taxId : '',
        ];

        // Kargo bilgileri
        $cargoSentAt = null;
        $packageHistories = $raw['packageHistories'] ?? [];
        foreach ($packageHistories as $history) {
            if (trim($history['status'] ?? '') === 'Shipped' && !empty($history['createdDate'])) {
                $cargoSentAt = date('Y-m-d H:i:s', intdiv((int) $history['createdDate'], 1000));
                break;
            }
        }

        // Sipariş tarihi: agreedDeliveryDate veya packageHistories'den Created
        $orderedAt = null;
        foreach ($packageHistories as $history) {
            if (trim($history['status'] ?? '') === 'Created' && !empty($history['createdDate'])) {
                $orderedAt = date('Y-m-d H:i:s', intdiv((int) $history['createdDate'], 1000));
                break;
            }
        }
        if ($orderedAt === null && !empty($raw['lastModifiedDate'])) {
            $orderedAt = date('Y-m-d H:i:s', intdiv((int) $raw['lastModifiedDate'], 1000));
        }

        return [
            'marketplace_order_id' => (string) ($raw['orderNumber'] ?? ''),
            'status'               => $statusMapping[$n11Status] ?? $n11Status,
            'customer_name'        => $customerName,
            'customer_email'       => $email,
            'customer_phone'       => $phone,
            'customer_id_no'       => $isCommercial ? $taxId : $tcId,
            'customer_tax_office'  => $isCommercial ? $taxOffice : '',
            'notes'                => '',
            'billing_address'      => $billingAddress,
            'shipping_address'     => $shippingAddress,
            'subtotal'             => round($subtotal, 2),
            'tax_total'            => round($taxTotal, 2),
            'discount_total'       => (float) ($raw['totalDiscountAmount'] ?? $discount),
            'total_amount'         => (float) ($raw['totalAmount'] ?? round($subtotal + $taxTotal, 2)),
            'cargo_company'        => $raw['cargoProviderName'] ?? '',
            'cargo_tracking_no'    => (string) ($raw['cargoTrackingNumber'] ?? ''),
            'cargo_tracking_link'  => $raw['cargoTrackingLink'] ?? '',
            'cargo_sent_at'        => $cargoSentAt,
            'ordered_at'           => $orderedAt ?? date('Y-m-d H:i:s'),
            'items'                => $items,
            'raw_data'             => $raw,
        ];
    }

    /**
     * N11 import ürünü normalize et
     */
    private function normalizeImportProduct(array $raw): array
    {
        $images = [];
        foreach ($raw['imageUrls'] ?? [] as $url) {
            $images[] = ['url' => $url];
        }

        $attributes = [];
        $brandFromAttr = '';
        foreach ($raw['attributes'] ?? [] as $attr) {
            $attrName  = $attr['attributeName'] ?? '';
            $attrValue = $attr['attributeValue'] ?? '';
            if (mb_strtolower($attrName) === 'marka' && $attrValue !== '') {
                $brandFromAttr = $attrValue;
            }
            $attributes[] = [
                'name'  => $attrName,
                'value' => $attrValue,
            ];
        }

        $normalized = [
            'marketplace_sku'        => $raw['stockCode'] ?? '',
            'marketplace_product_id' => (string) ($raw['n11ProductId'] ?? ''),
            'barcode'                => $raw['barcode'] ?? '',
            'name'                   => $raw['title'] ?? '',
            'category_id'            => (string) ($raw['categoryId'] ?? ''),
            'category_name'          => $raw['categoryName'] ?? '',
            'brand_name'             => $raw['brand'] ?? $raw['brandName'] ?? $brandFromAttr,
            'brand_id'               => '',
            'price'                  => (float) ($raw['salePrice'] ?? 0),
            'list_price'             => (float) ($raw['listPrice'] ?? 0),
            'vat_rate'               => (int) ($raw['vatRate'] ?? 20),
            'currency'               => $raw['currencyType'] ?? 'TL',
            'description'            => $raw['description'] ?? '',
            'images'                 => $images,
            'attributes'             => $attributes,
            'status'                 => $raw['status'] ?? '',
            'sale_status'            => $raw['saleStatus'] ?? '',
            'group_id'               => (string) ($raw['productMainId'] ?? ''),
            'model_code'             => $raw['productMainId'] ?? '',
            'raw'                    => $raw,
        ];

        if (array_key_exists('quantity', $raw)) {
            $normalized['stock'] = (int) $raw['quantity'];
        }

        return $normalized;
    }

    /**
     * Kategori ağacını düzleştir
     */
    private function flattenCategories(array $categories, int $parentId = 0): array
    {
        $result = [];

        $items = is_array($categories) && isset($categories[0]) ? $categories : [$categories];

        foreach ($items as $cat) {
            $id = (int) ($cat['id'] ?? 0);
            $name = $cat['name'] ?? '';
            $subs = $cat['subCategories'] ?? null;

            $result[] = [
                'id'        => $id,
                'name'      => $name,
                'parent_id' => $parentId,
                'has_children' => $subs !== null && count($subs) > 0,
            ];

            if (!empty($subs) && is_array($subs)) {
                $result = array_merge($result, $this->flattenCategories($subs, $id));
            }
        }

        return $result;
    }
}
