<?php
/**
 * OzyConnect Panel — PttavmService (PttAVM REST API Entegrasyonu)
 *
 * PttAVM Entegrasyon API (REST):
 *  - Base URL: https://integration-api.pttavm.com
 *  - Auth: Header — Api-Key + Access-Token + X-Correlation-Id
 *  - Sipariş: GET /api/v1/orders/search (max 40 gün)
 *  - Stok/Fiyat: POST /api/v1/products/stock-prices (max 1000 ürün)
 *  - Ürün Listesi: GET /api/v1/products/search
 *  - Kategori: GET /api/v1/categories/main, /api/v1/categories/category-tree
 *  - Kargo Profil: GET /api/v1/shipping/cargo-profiles
 *  - Fatura: POST /api/v1/orders/{orderId}/invoice
 *
 * @see https://developers.pttavm.com/tr
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

final class PttavmService extends AbstractMarketplace
{
    private const SLUG = 'pttavm';
    private const BASE_URL = 'https://integration-api.pttavm.com';

    /** PttAVM tek talepte en fazla 1000 ürün kabul eder (doküman kuralı) */
    private const UPSERT_BATCH_LIMIT = 1000;

    private string $apiKey = '';
    private string $accessToken = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;

    /** Sipariş durum mapping (API → sistem) */
    private const STATUS_MAP = [
        'kargo_yapilmasi_bekleniyor' => 'yeni',
        'havale_onayi_bekleniyor'    => 'beklemede',
        'onay_surecinde'             => 'beklemede',
        'gondericisine_teslim_edildi' => 'kargoda',
        'gonderilmis'                => 'kargoda',
        'gonderilmiş'                => 'kargoda',
        'tamamlandi'                 => 'teslim_edildi',
        'iptal'                      => 'iptal',
        'iade'                       => 'iade',
        'odeme_gecersiz'             => 'iptal',
    ];

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->baseUrl            = self::BASE_URL;
        $this->timeout            = (int) ($this->config['api']['timeout_seconds'] ?? 60);
        $this->maxRetries         = (int) ($this->config['api']['max_retries'] ?? 3);
        $this->retryBaseDelayMs   = (int) ($this->config['api']['retry_base_delay_ms'] ?? 2000);
        $this->rateLimitPerSecond = (int) ($this->config['api']['rate_limit_per_second'] ?? 2);
    }

    // ═══════════════════════════════════════════════════════
    // KİMLİK DOĞRULAMA & HEADER
    // ═══════════════════════════════════════════════════════

    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('PttAVM pazaryeri kaydı bulunamadı');
        }

        if (empty($this->config['api_key'])) {
            throw new RuntimeException('PttAVM sabit API Key config/marketplaces.php içinde tanımlı değil.');
        }

        if (empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('PttAVM Access Token henüz girilmemiş. Pazaryerleri > PttAVM > Ayarlar\'dan Access Token girin.');
        }

        $this->apiKey      = (string) $this->config['api_key'];
        $this->accessToken = $this->crypto->decrypt($marketplace['api_secret_encrypted']);
        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
    }

    /**
     * PttAVM REST API header'ları
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        return [
            'Api-Key: ' . $this->apiKey,
            'Access-Token: ' . $this->accessToken,
            'X-Correlation-Id: ' . $this->generateCorrelationId(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * Unique correlation ID üret (istek takibi için)
     */
    private function generateCorrelationId(): string
    {
        return sprintf(
            'ms-%s-%s',
            date('YmdHis'),
            substr(bin2hex(random_bytes(4)), 0, 8)
        );
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();

            // Kargo profilleri çek — hafif bir GET endpoint
            $url = $this->baseUrl . '/api/v1/shipping/cargo-profiles';
            $result = $this->httpGet($url, $this->authHeaders());

            // Başarılı yanıt aldıysak bağlantı çalışıyor
            $profiles = $result['cargoProfiles'] ?? $result;
            $count = is_array($profiles) ? count($profiles) : 0;

            return [
                'success' => true,
                'message' => "PttAVM bağlantısı başarılı! {$count} kargo profili bulundu.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("PttAVM bağlantı testi hatası: " . $e->getMessage(), 'PttavmAPI');
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
     * Sipariş listesi çek (max 40 gün aralık)
     *
     * GET /api/v1/orders/search?startDate=&endDate=&isActiveOrders=
     */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $startDate = $filters['startDate'] ?? $filters['start_date'] ?? date('Y-m-d\TH:i:s', strtotime('-30 days'));
        $endDate   = $filters['endDate'] ?? $filters['end_date'] ?? date('Y-m-d\TH:i:s');
        $isActive  = $filters['isActiveOrders'] ?? 'false';

        // Tarih formatı: 2024-01-01T14:57:12
        if (strlen($startDate) === 10) {
            $startDate .= 'T00:00:00';
        }
        if (strlen($endDate) === 10) {
            $endDate .= 'T23:59:59';
        }

        $url = $this->baseUrl . '/api/v1/orders/search?' . http_build_query([
            'startDate'      => $startDate,
            'endDate'        => $endDate,
            'isActiveOrders' => $isActive,
        ]);

        $result = $this->httpGet($url, $this->authHeaders());

        $this->logger->debug('PttAVM sipariş yanıtı', 'PttavmAPI', [
            'count' => is_array($result) ? count($result) : 0,
        ]);

        // API direkt array döner (JSON array of orders)
        if (!is_array($result)) {
            return [];
        }

        // İlk eleman string key'li ise tek sipariş gelmiştir
        if (isset($result['siparisNo'])) {
            $result = [$result];
        }

        return array_map(fn(array $raw) => $this->normalizeOrder($raw), array_filter($result, 'is_array'));
    }

    /**
     * PttAVM REST API sipariş verisini normalize et
     */
    private function normalizeOrder(array $raw): array
    {
        // Ürün düzeyindeki siparisDurumu
        $items = $raw['siparisUrunler'] ?? [];
        if (isset($items['urunId']) || isset($items['urunBarkod'])) {
            $items = [$items];
        }

        // Sipariş durumu: item-level siparisDurumu veya üst seviye
        $rawStatus = '';
        if (!empty($items)) {
            $firstItem = $items[0] ?? [];
            $rawStatus = (string) ($firstItem['siparisDurumu'] ?? '');
        }
        if (empty($rawStatus)) {
            $rawStatus = (string) ($raw['siparisDurumu'] ?? '');
        }
        $status = self::STATUS_MAP[$rawStatus] ?? 'yeni';

        $customerName = trim(
            ($raw['musteriAdi'] ?? '') . ' ' . ($raw['musteriSoyadi'] ?? '')
        );

        $normalizedItems = [];
        $subtotal = 0;
        $taxTotal = 0;
        $discount = 0;

        foreach ($items as $line) {
            if (!is_array($line)) continue;

            $qty       = (int) ($line['toplamIslemAdedi'] ?? 1);
            $kdvDahil  = (float) ($line['kdvDahilToplamTutar'] ?? 0);
            $kdvHaric  = (float) ($line['kdvHaricToplamTutar'] ?? 0);
            $unitPrice = (float) ($line['kdvHaricTutar'] ?? 0);
            $vatRate   = (float) ($line['kdvOrani'] ?? 20);
            $lineTax   = $kdvDahil - $kdvHaric;

            $normalizedItems[] = [
                'marketplace_item_id' => (string) ($line['lineItemId'] ?? $line['urunId'] ?? ''),
                'sku'                 => (string) ($line['urunBarkod'] ?? ''),
                'barcode'             => (string) ($line['urunBarkod'] ?? ''),
                'name'                => (string) ($line['urun'] ?? $raw['urunAdi'] ?? ''),
                'qty'                 => $qty,
                'unit_price'          => $unitPrice,
                'discount'            => (float) ($line['indirimToplam'] ?? 0),
                'tax_rate'            => $vatRate,
                'line_total'          => $kdvDahil,
            ];

            $subtotal += $kdvHaric;
            $taxTotal += $lineTax;
            $discount += (float) ($line['indirimToplam'] ?? 0);
        }

        $orderedAt = $raw['islemTarihi'] ?? null;
        if (is_string($orderedAt)) {
            $ts = strtotime($orderedAt);
            $orderedAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        } else {
            $orderedAt = date('Y-m-d H:i:s');
        }

        return [
            'marketplace_order_id'  => (string) ($raw['siparisNo'] ?? ''),
            'status'                => $status,
            'status_raw'            => $rawStatus,
            'customer_name'         => $customerName,
            'customer_email'        => (string) ($raw['eposta'] ?? ''),
            'customer_phone'        => (string) ($raw['telefonNo'] ?? ''),
            'customer_id_no'        => (string) ($raw['tckn'] ?? ''),
            'customer_tax_office'   => (string) ($raw['vergiDaire'] ?? ''),
            'notes'                 => '',
            'billing_address'       => [
                'name'        => trim(($raw['faturaMusteriAdi'] ?? '') . ' ' . ($raw['faturaMusteriSoyadi'] ?? '')),
                'address'     => (string) ($raw['faturaAdresi'] ?? ''),
                'city'        => (string) ($raw['faturaIli'] ?? ''),
                'district'    => (string) ($raw['faturaIlce'] ?? ''),
                'postal_code' => '',
                'company'     => (string) ($raw['firmaUnvani'] ?? ''),
                'tax_office'  => (string) ($raw['vergiDaire'] ?? ''),
                'tax_number'  => (string) ($raw['vergiNo'] ?? ''),
            ],
            'shipping_address'      => [
                'name'         => $customerName,
                'address'      => (string) ($raw['siparisAdresi'] ?? ''),
                'city'         => (string) ($raw['siparisIli'] ?? ''),
                'district'     => (string) ($raw['siparisIlce'] ?? ''),
                'postal_code'  => '',
                'phone'        => (string) ($raw['telefonNo'] ?? ''),
                'neighborhood' => '',
            ],
            'subtotal'              => round($subtotal, 2),
            'tax_total'             => round($taxTotal, 2),
            'discount_total'        => round($discount, 2),
            'shipping_total'        => (float) ($raw['kargoTutari'] ?? 0),
            'total_amount'          => round($subtotal + $taxTotal + (float)($raw['kargoTutari'] ?? 0), 2),
            'currency'              => 'TRY',
            'payment_method'        => '',
            'cargo_company'         => !empty($raw['kargoBarkod']) ? 'PTT Kargo' : '',
            'cargo_tracking_no'     => (string) ($raw['kargoBarkod'] ?? ''),
            'cargo_tracking_link'   => '',
            'cargo_sent_at'         => null,
            'ordered_at'            => $orderedAt,
            'items'                 => $normalizedItems,
            'raw_data'              => $raw,
        ];
    }

    /**
     * Sipariş detayı çek
     *
     * GET /api/v1/orders/{orderId}
     */
    public function getOrderDetail(string $orderId): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/api/v1/orders/' . urlencode($orderId);
        return $this->httpGet($url, $this->authHeaders());
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->logger->info("PttAVM updateOrderStatus: sipariş durumu API'den otomatik yönetilir", 'PttavmAPI');
        return false;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        // PttAVM'de kargo bilgisi güncelleme kargo entegrasyonu üzerinden yapılır.
        // Barkod oluşturulup etiket yazdırıldığında otomatik kargoya verilmiş sayılır.
        $this->logger->info("PttAVM updateCargoInfo: Kargo barkod oluşturup etiket yazdırın.", 'PttavmAPI');
        return false;
    }

    // ═══════════════════════════════════════════════════════
    // KARGO BARKOD İŞLEMLERİ (shipment.pttavm.com)
    // ═══════════════════════════════════════════════════════

    private const SHIPMENT_BASE_URL = 'https://shipment.pttavm.com';

    /**
     * Kargo API için Basic Auth header oluştur
     * PttAVM kargo API'si aynı Api-Key:Access-Token ile Basic Auth kullanır
     */
    private function shipmentAuthHeaders(): array
    {
        return [
            'Authorization: Basic ' . base64_encode($this->apiKey . ':' . $this->accessToken),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * Depo listesini çek
     *
     * POST https://shipment.pttavm.com/api/v1/get-warehouse
     */
    public function getWarehouses(): array
    {
        $this->loadCredentials();

        $url = self::SHIPMENT_BASE_URL . '/api/v1/get-warehouse';
        $result = $this->httpPost($url, new \stdClass(), $this->shipmentAuthHeaders());

        if (!empty($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        return [];
    }

    /**
     * Sipariş(ler) için kargo barkodu oluştur
     *
     * POST https://shipment.pttavm.com/api/v1/create-barcode
     *
     * @param array<int, array{order_id: string, warehouse_id: int}> $orders
     * @return array{tracking_id: string, count: int, success: bool}
     */
    public function createCargoBarcode(array $orders): array
    {
        $this->loadCredentials();

        $url = self::SHIPMENT_BASE_URL . '/api/v1/create-barcode';
        $payload = ['orders' => $orders];

        $result = $this->httpPost($url, $payload, $this->shipmentAuthHeaders());

        $this->logger->info('PttAVM kargo barkod oluşturma', 'PttavmAPI', [
            'tracking_id' => $result['tracking_id'] ?? null,
            'count'       => $result['count'] ?? 0,
            'success'     => $result['success'] ?? false,
        ]);

        return $result;
    }

    /**
     * Barkod oluşturma durumunu kontrol et
     *
     * POST https://shipment.pttavm.com/api/v1/barcode-status
     *
     * @return array{tracking_id: string, status: string, data: array}
     */
    public function checkBarcodeStatus(string $trackingId): array
    {
        $this->loadCredentials();

        $url = self::SHIPMENT_BASE_URL . '/api/v1/barcode-status';
        $result = $this->httpPost($url, ['tracking_id' => $trackingId], $this->shipmentAuthHeaders());

        return $result;
    }

    /**
     * Barkod etiket bilgisi al (HTML veya ZPL formatında)
     *
     * POST https://shipment.pttavm.com/api/v1/get-barcode-tag
     *
     * @param string $barcode   Kargo barkodu
     * @param string $orderId   Sipariş numarası
     * @param string|null $type null=HTML çıktısı, 'zpl'=Zebra yazıcı formatı
     * @return string HTML veya ZPL etiket içeriği
     */
    public function getBarcodeLabel(string $barcode, string $orderId, ?string $type = null): string
    {
        $this->loadCredentials();

        $url = self::SHIPMENT_BASE_URL . '/api/v1/get-barcode-tag';
        $payload = [
            'barcode'  => $barcode,
            'order_id' => $orderId,
            'type'     => $type,
        ];

        // Bu endpoint HTML döndürür, JSON parse etmemeli
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $this->shipmentAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            $this->logger->error("PttAVM barkod etiket hatası: HTTP {$httpCode}", 'PttavmAPI');
            return '';
        }

        return (string) $response;
    }

    /**
     * Dijital ürün siparişi için kargosuz teslim durumu güncelle
     *
     * POST https://shipment.pttavm.com/api/v1/update-no-shipping-order
     */
    public function updateNoShippingOrder(string $orderId): array
    {
        $this->loadCredentials();

        $url = self::SHIPMENT_BASE_URL . '/api/v1/update-no-shipping-order';
        return $this->httpPost($url, ['order_id' => $orderId], $this->shipmentAuthHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün listesi çek
     *
     * PttAVM /products/search endpoint'i çalışmadığı için
     * siparişlerden benzersiz barkodları toplayıp her birini
     * GET /api/v1/products?Barcode=XXX ile tek tek çekeriz.
     */
    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();
        $headers = $this->authHeaders();

        // PttAVM /products/search endpoint'i 400 dönüyor (API bozuk).
        // Fallback: siparişlerden barkod topla → tek tek ürün çek

        // Sayfalama: sadece page 0'da çalışır (tüm ürünler tek seferde)
        if ($page > 0) {
            return ['products' => [], 'totalPages' => 1, 'totalElements' => 0];
        }

        $barcodes = $this->collectBarcodesFromOrders($headers);
        if (empty($barcodes)) {
            return ['products' => [], 'totalPages' => 0, 'totalElements' => 0];
        }

        $products = [];
        foreach ($barcodes as $barcode) {
            $product = $this->fetchProductByBarcode($barcode, $headers);
            if ($product !== null) {
                $products[] = $product;
            }
        }

        return [
            'products'      => $products,
            'totalPages'    => 1,
            'totalElements' => count($products),
        ];
    }

    /**
     * Tek ürün çek: GET /api/v1/products?Barcode=XXX
     */
    public function fetchProductByBarcode(string $barcode, ?array $headers = null): ?array
    {
        if ($headers === null) {
            $this->loadCredentials();
            $headers = $this->authHeaders();
        }

        try {
            $url = $this->baseUrl . '/api/v1/products?Barcode=' . urlencode($barcode);
            $result = $this->httpGet($url, $headers);

            if (is_array($result) && !empty($result['urunId'] ?? $result['barkod'] ?? null)) {
                return $result;
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Ürün çekme hatası [{$barcode}]: " . $e->getMessage(), 'PttavmAPI');
        }
        return null;
    }

    /**
     * Siparişlerden benzersiz barkodları topla
     */
    private function collectBarcodesFromOrders(array $headers): array
    {
        // Son 6 aylık siparişlerden barkod topla
        $startDate = date('Y-m-d\TH:i:s', strtotime('-180 days'));
        $endDate   = date('Y-m-d\TH:i:s');

        $url = $this->baseUrl . '/api/v1/orders/search?' . http_build_query([
            'startDate'      => $startDate,
            'endDate'        => $endDate,
            'isActiveOrders' => 'false',
        ]);

        $orders = $this->httpGet($url, $headers);
        if (!is_array($orders)) {
            return [];
        }

        if (isset($orders['siparisNo'])) {
            $orders = [$orders];
        }

        $barcodes = [];
        foreach ($orders as $order) {
            if (!is_array($order)) continue;
            $items = $order['siparisUrunler'] ?? [];
            if (isset($items['urunBarkod'])) {
                $items = [$items];
            }
            foreach ($items as $item) {
                $bc = trim($item['urunBarkod'] ?? '');
                if ($bc !== '') {
                    $barcodes[$bc] = true;
                }
            }
        }

        $this->logger->info('Siparişlerden ' . count($barcodes) . ' benzersiz barkod toplandı', 'PttavmAPI');
        return array_keys($barcodes);
    }

    /**
     * Fiyat ve Stok Güncelle
     *
     * POST /api/v1/products/stock-prices
     * Max 1000 ürün per request
     */
    public function updateStock(array $items): array
    {
        $this->loadCredentials();

        $success = 0;
        $failed = 0;
        $errors = [];

        $batches = array_chunk($items, 1000);

        foreach ($batches as $batch) {
            $payload = ['items' => []];

            foreach ($batch as $item) {
                $entry = [
                    'barcode'  => (string) ($item['barcode'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                ];

                if (isset($item['active'])) {
                    $entry['active'] = (bool) $item['active'];
                }

                $payload['items'][] = $entry;
            }

            try {
                $url = $this->baseUrl . '/api/v1/products/stock-prices';
                $result = $this->httpPost($url, $payload, $this->authHeaders());

                if (!empty($result['success'])) {
                    $success += (int) ($result['countOfProductsToBeProcessed'] ?? count($batch));
                    $this->logger->info("PttAVM stok güncelleme başarılı", 'PttavmAPI', [
                        'trackingId' => $result['trackingId'] ?? null,
                        'count'      => $result['countOfProductsToBeProcessed'] ?? 0,
                    ]);
                } else {
                    $failed += count($batch);
                    $errors[] = ['error' => $result['message'] ?? 'Bilinmeyen hata'];
                }

            } catch (\Throwable $e) {
                $failed += count($batch);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Fiyat Güncelle (stok+fiyat aynı endpoint)
     *
     * POST /api/v1/products/stock-prices
     */
    public function updatePrice(array $items): array
    {
        $this->loadCredentials();

        $success = 0;
        $failed = 0;
        $errors = [];

        $batches = array_chunk($items, 1000);

        foreach ($batches as $batch) {
            $payload = ['items' => []];

            foreach ($batch as $item) {
                $entry = [
                    'barcode' => (string) ($item['barcode'] ?? ''),
                ];

                if (!empty($item['priceExclVat'])) {
                    $entry['priceWithoutVAT'] = (float) $item['priceExclVat'];
                } elseif (!empty($item['salePrice'])) {
                    $entry['priceWithVAT'] = (float) $item['salePrice'];
                }

                if (isset($item['listPrice']) && !isset($entry['priceWithVAT'])) {
                    $entry['priceWithVAT'] = (float) $item['listPrice'];
                }

                if (isset($item['vatRate'])) {
                    $entry['vatRate'] = (int) $item['vatRate'];
                }

                if (isset($item['quantity'])) {
                    $entry['quantity'] = (int) $item['quantity'];
                }

                $payload['items'][] = $entry;
            }

            try {
                $url = $this->baseUrl . '/api/v1/products/stock-prices';
                $result = $this->httpPost($url, $payload, $this->authHeaders());

                if (!empty($result['success'])) {
                    $success += (int) ($result['countOfProductsToBeProcessed'] ?? count($batch));
                } else {
                    $failed += count($batch);
                    $errors[] = ['error' => $result['message'] ?? 'Bilinmeyen hata'];
                }

            } catch (\Throwable $e) {
                $failed += count($batch);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * PttAVM'ye varyantlı ürün grubu gönder
     * Her varyant ayrı barkod olarak stock-prices üzerinden gönderilir.
     *
     * @param array{items: array, groupId: string} $variantGroup
     * @return array{success: bool, errors: array}
     */
    /**
     * Ürünleri PttAVM'ye gönder (ekle/güncelle)
     * POST /api/v1/products/upsert
     *
     * Sisteme kayıtlı OLMAYAN barkod yeni ürün olarak açılır. PttAVM kuralları
     * (doküman: "Dikkat Edilmesi Gereken Kurallar") gönderim öncesi burada
     * doğrulanır — böylece tüm parti tek bir hatalı satır yüzünden reddedilmez.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success: bool, trackingId?: string, message?: string, errors: array<int, array<string, string>>}
     */
    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        $gonderilecek = [];
        $errors = [];

        foreach ($items as $item) {
            $barcode = trim((string) ($item['barcode'] ?? ''));
            $name    = trim((string) ($item['name'] ?? $item['title'] ?? ''));
            $catId   = (int) ($item['categoryId'] ?? 0);
            $adet    = (int) ($item['quantity'] ?? 0);
            $kdvli   = (float) ($item['priceWithVat'] ?? $item['salePrice'] ?? $item['price'] ?? 0);
            $kdv     = (int) ($item['vatRate'] ?? 20);

            $gorseller = [];
            foreach ((array) ($item['images'] ?? []) as $gorsel) {
                $url = is_array($gorsel) ? (string) ($gorsel['url'] ?? '') : (string) $gorsel;
                $url = trim($url);
                if ($url !== '' && preg_match('#^https?://#i', $url)) {
                    $gorseller[] = ['url' => $url];
                }
            }

            // ── PttAVM kuralları ──
            $hata = match (true) {
                $barcode === ''          => 'Barkod zorunludur.',
                $name === ''             => 'Ürün adı zorunludur.',
                mb_strlen($name) > 200   => 'Ürün adı 200 karakteri geçemez.',
                $catId <= 0              => 'Geçerli bir PttAVM kategorisi eşleştirilmelidir.',
                $gorseller === []        => 'Yeni ürün için en az bir geçerli görsel (http/https) gereklidir.',
                $adet < 0                => 'Stok negatif olamaz.',
                $kdvli <= 0              => 'KDV dahil fiyat sıfırdan büyük olmalıdır.',
                !in_array($kdv, [0, 1, 10, 20], true) => 'KDV oranı yalnız 0, 1, 10 veya 20 olabilir.',
                $kdv === 0               => 'KDV dahil fiyat gönderiliyorsa KDV oranı 0 olamaz.',
                default                  => '',
            };

            if ($hata !== '') {
                $errors[] = ['barcode' => $barcode, 'error' => $hata];
                continue;
            }

            $satir = [
                'categoryId'   => $catId,
                'barcode'      => $barcode,
                'name'         => mb_substr($name, 0, 200),
                'priceWithVat' => round($kdvli, 2),
                'vatRate'      => $kdv,
                'quantity'     => max(0, $adet),
                'images'       => $gorseller,
                'active'       => (bool) ($item['active'] ?? true),
            ];

            // İsteğe bağlı alanlar — yalnız geçerli değer varsa gönderilir
            $kisaAciklama = trim((string) ($item['shortDescription'] ?? ''));
            if ($kisaAciklama !== '') {
                $satir['shortDescription'] = mb_substr($kisaAciklama, 0, 500);
            }
            $uzunAciklama = trim((string) ($item['longDescription'] ?? $item['description'] ?? ''));
            if ($uzunAciklama !== '') {
                $satir['longDescription'] = $uzunAciklama;
            }

            $stokKodu = trim((string) ($item['productCode'] ?? $item['sku'] ?? ''));
            if ($stokKodu !== '') {
                $satir['productCode'] = $stokKodu;
            }
            $marka = trim((string) ($item['brand'] ?? ''));
            if ($marka !== '') {
                $satir['brand'] = $marka;
            }
            $gtin = trim((string) ($item['gtin'] ?? ''));
            if ($gtin !== '') {
                $satir['gtin'] = $gtin;
            }

            // Desi: kargolu ürünlerde 0-300 aralığı doğrulanıyor
            $desi = (float) ($item['desi'] ?? 0);
            if ($desi > 0) {
                $satir['desi'] = min(300.0, round($desi, 2));
            }

            $kargoProfil = (int) ($item['cargoProfileId'] ?? 0);
            if ($kargoProfil > 0) {
                $satir['cargoProfileId'] = $kargoProfil;
            }

            $teslimat = (int) ($item['estimatedCourierDelivery'] ?? 0);
            if ($teslimat > 0) {
                $satir['estimatedCourierDelivery'] = min(30, $teslimat);
            }
            $garantiSure = (int) ($item['warrantyDuration'] ?? 0);
            if ($garantiSure > 0) {
                $satir['warrantyDuration'] = min(24, $garantiSure);
            }
            $garantiFirma = trim((string) ($item['warrantySupplier'] ?? ''));
            if ($garantiFirma !== '') {
                $satir['warrantySupplier'] = mb_substr($garantiFirma, 0, 250);
            }
            $sepetUst = (int) ($item['basketMaxQuantity'] ?? 0);
            if ($sepetUst > 0) {
                $satir['basketMaxQuantity'] = min(1000, $sepetUst);
            }

            // DİKKAT: varyant gönderilmezse PttAVM mevcut varyantları SİLER.
            // Bu yüzden yalnız açıkça varyant verildiyse alan eklenir.
            if (!empty($item['variants']) && is_array($item['variants'])) {
                $varyantlar = [];
                foreach ($item['variants'] as $v) {
                    if (!is_array($v) || trim((string) ($v['variantBarcode'] ?? '')) === '') {
                        continue;
                    }
                    $varyantlar[] = [
                        'variantBarcode' => (string) $v['variantBarcode'],
                        'attributes'     => array_values((array) ($v['attributes'] ?? [])),
                        'quantity'       => max(0, (int) ($v['quantity'] ?? 0)),
                        'price'          => round((float) ($v['price'] ?? 0), 2),
                        'catalogBarcode' => (string) ($v['catalogBarcode'] ?? $v['variantBarcode']),
                    ];
                }
                if ($varyantlar !== []) {
                    $satir['variants'] = $varyantlar;
                }
            }

            $gonderilecek[] = $satir;
        }

        if ($gonderilecek === []) {
            return ['success' => false, 'errors' => $errors ?: [['error' => 'Gönderilecek geçerli ürün yok.']]];
        }

        // PttAVM tek talepte en fazla 1000 ürün kabul eder
        $sonTracking = '';
        $mesaj = '';

        foreach (array_chunk($gonderilecek, self::UPSERT_BATCH_LIMIT) as $parca) {
            try {
                $response = $this->httpPost(
                    $this->baseUrl . '/api/v1/products/upsert',
                    ['items' => $parca],
                    $this->authHeaders()
                );
            } catch (\Throwable $e) {
                $this->logger->error('PttAVM ürün gönderme hatası: ' . $e->getMessage(), 'PttavmService');
                return ['success' => false, 'errors' => array_merge($errors, [['error' => $e->getMessage()]])];
            }

            if (($response['success'] ?? false) !== true) {
                $mesaj = (string) ($response['message'] ?? 'PttAVM ürün gönderimi reddedildi.');
                $this->logger->warning('PttAVM ürün gönderimi reddedildi: ' . $mesaj, 'PttavmService');
                return ['success' => false, 'message' => $mesaj, 'errors' => array_merge($errors, [['error' => $mesaj]])];
            }

            $sonTracking = (string) ($response['trackingId'] ?? $sonTracking);
            $mesaj = (string) ($response['message'] ?? '');

            $this->logger->info(
                sprintf(
                    'PttAVM ürün gönderildi: %d ürün (takip: %s)',
                    (int) ($response['countOfProductsToBeProcessed'] ?? count($parca)),
                    $sonTracking !== '' ? $sonTracking : '-'
                ),
                'PttavmService'
            );
        }

        return [
            'success'    => true,
            'trackingId' => $sonTracking,
            'message'    => $mesaj,
            'errors'     => $errors,
        ];
    }

    /**
     * Gönderim durumunu sorgula
     * GET /api/v1/products/tracking-result/{trackingId}
     *
     * @return array<string, mixed>
     */
    public function getBatchStatus(string $batchId): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/api/v1/products/tracking-result/' . urlencode($batchId);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Ürünü aktif/pasif yap
     * PUT /api/v1/products/{productId}/status
     */
    public function setProductActive(string $productId, bool $isActive): bool
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/api/v1/products/' . urlencode($productId) . '/status';

        try {
            $response = $this->httpPut($url, ['isActive' => $isActive], $this->authHeaders());
            return ($response['success'] ?? false) === true;
        } catch (\Throwable $e) {
            $this->logger->error("PttAVM ürün durumu güncellenemedi (#{$productId}): " . $e->getMessage(), 'PttavmService');
            return false;
        }
    }

    public function createVariantProducts(array $variantGroup): array
    {
        $items = [];
        foreach ($variantGroup['items'] ?? [] as $item) {
            $items[] = [
                'barcode'  => $item['barcode'],
                'quantity' => (int) ($item['quantity'] ?? 0),
                'salePrice' => (float) ($item['salePrice'] ?? 0),
            ];
        }
        return $this->updateStock($items);
    }

    public function importProducts(int $page = 0, int $size = 100): array
    {
        $raw = $this->fetchProducts($page, $size);
        $rawProducts = $raw['products'] ?? [];

        $normalized = [];
        foreach ($rawProducts as $p) {
            if (!is_array($p)) continue;
            $normalized[] = $this->normalizeProduct($p);
        }

        return [
            'products'      => $normalized,
            'totalPages'    => $raw['totalPages'] ?? 0,
            'totalElements' => $raw['totalElements'] ?? 0,
        ];
    }

    /**
     * PttAVM ürün verisini sistem formatına normalize et
     */
    private function normalizeProduct(array $raw): array
    {
        $barcode = (string) ($raw['barkod'] ?? '');
        $sku     = (string) ($raw['urunKodu'] ?? $barcode);
        $name    = (string) ($raw['urunAdi'] ?? '');
        $hasStock = array_key_exists('miktar', $raw);
        $stock   = $hasStock ? (int) $raw['miktar'] : 0;
        $priceExclVat = (float) ($raw['kdVsiz'] ?? 0);
        $priceInclVat = (float) ($raw['kdVli'] ?? 0);
        $vatRate = (float) ($raw['kdvOran'] ?? 0);

        // kdVli bazen 0 dönüyor, kdVsiz'den hesapla
        if ($priceInclVat <= 0 && $priceExclVat > 0 && $vatRate > 0) {
            $priceInclVat = round($priceExclVat * (1 + $vatRate / 100), 2);
        } elseif ($priceInclVat <= 0) {
            $priceInclVat = $priceExclVat;
        }

        // Görseller
        $images = [];
        $imageList = $raw['resimListesi'] ?? [];
        if (is_array($imageList)) {
            foreach ($imageList as $img) {
                $url = $img['url'] ?? null;
                if ($url) {
                    $images[] = ['url' => $url, 'order' => (int) ($img['sira'] ?? 0)];
                }
            }
        }
        // Fallback: resim1Url, resim2Url, resim3Url
        for ($i = 1; $i <= 3; $i++) {
            $key = "resim{$i}Url";
            if (!empty($raw[$key]) && empty($images)) {
                $images[] = ['url' => $raw[$key], 'order' => $i];
            }
        }

        // Kategori
        $categoryId   = (string) ($raw['altKategoriId'] ?? $raw['anaKategoriId'] ?? '');
        $categoryName = (string) ($raw['altKategoriAdi'] ?? '');

        $normalized = [
            'marketplace_product_id' => (string) ($raw['urunId'] ?? ''),
            'marketplace_sku'        => $sku,
            'barcode'                => $barcode,
            'name'                   => $name,
            'description'            => (string) ($raw['aciklama'] ?? ''),
            'long_description'       => (string) ($raw['uzunAciklama'] ?? ''),
            'price'                  => $priceInclVat,
            'list_price'             => $priceInclVat,
            'price_excl_vat'         => $priceExclVat,
            'vat_rate'               => $vatRate,
            'is_active'              => (bool) ($raw['aktif'] ?? false),
            'images'                 => $images,
            'category_id'            => $categoryId,
            'category_name'          => $categoryName,
            'brand_name'             => (string) ($raw['garantiVerenFirma'] ?? ''),
            'brand_id'               => '',
            'weight'                 => (float) ($raw['agirlik'] ?? 0),
            'gtin'                   => (string) ($raw['gtin'] ?? ''),
            'attributes'             => [],
            'raw'                    => $raw,
        ];

        if ($hasStock) {
            $normalized['stock'] = $stock;
        }

        return $normalized;
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /**
     * Kategori ağacı çek
     *
     * GET /api/v1/categories/category-tree
     */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/api/v1/categories/category-tree';
        $result = $this->httpGet($url, $this->authHeaders());

        if (!empty($result['success']) && !empty($result['category_tree'])) {
            return $this->flattenCategoryTree($result['category_tree']);
        }

        // Ana kategoriler fallback
        $url = $this->baseUrl . '/api/v1/categories/main';
        $result = $this->httpGet($url, $this->authHeaders());

        if (!empty($result['success']) && !empty($result['main_category'])) {
            return array_map(fn(array $cat) => [
                'id'        => (string) ($cat['id'] ?? ''),
                'name'      => (string) ($cat['name'] ?? ''),
                'parent_id' => null,
                'children'  => [],
            ], $result['main_category']);
        }

        return [];
    }

    /**
     * Kategori ağacını düzleştir
     */
    private function flattenCategoryTree(array $tree, ?string $parentId = null): array
    {
        $result = [];
        foreach ($tree as $node) {
            $catId = (string) ($node['id'] ?? '');
            $result[] = [
                'id'        => $catId,
                'name'      => (string) ($node['name'] ?? ''),
                'parent_id' => $parentId,
                'children'  => [],
            ];
            if (!empty($node['children'])) {
                $result = array_merge($result, $this->flattenCategoryTree($node['children'], $catId));
            }
        }
        return $result;
    }

    public function fetchBrands(string $search = ''): array
    {
        // PttAVM'de marka endpoint'i yok.
        // Marka bilgisi ürün import'unda brand_name field'ından otomatik eşleştirilir.
        return [];
    }

    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        return [];
    }

    // ═══════════════════════════════════════════════════════
    // KARGO İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Kargo profilleri
     *
     * GET /api/v1/shipping/cargo-profiles
     */
    public function getCargoProfiles(): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/api/v1/shipping/cargo-profiles';
        $result = $this->httpGet($url, $this->authHeaders());

        return $result['cargoProfiles'] ?? $result;
    }

    /**
     * Sipariş kargo bilgileri
     *
     * GET /api/v1/orders/{orderId}/cargo-infos
     */
    public function getCargoInfo(string $orderId): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/api/v1/orders/' . urlencode($orderId) . '/cargo-infos';
        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // FATURA İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Fatura gönder
     *
     * POST /api/v1/orders/{orderId}/invoice
     */
    public function sendInvoice(string $orderId, array $lineItemIds, ?string $pdfUrl = null, ?string $pdfBase64 = null): array
    {
        $this->loadCredentials();

        $payload = [
            'lineItemId' => $lineItemIds,
        ];

        if ($pdfUrl !== null) {
            $payload['url'] = $pdfUrl;
        } elseif ($pdfBase64 !== null) {
            $payload['content'] = $pdfBase64;
        }

        $url = $this->baseUrl . '/api/v1/orders/' . urlencode($orderId) . '/invoice';
        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * PttAVM iade listesi — sipariş durumu 'iade' olan siparişlerden çıkarılır
     * PttAVM'nin ayrı bir iade API'si yoktur; iadeler sipariş statüsünden tespit edilir.
     */
    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        $startDate = $filters['startDate'] ?? $filters['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate   = $filters['endDate'] ?? $filters['end_date'] ?? date('Y-m-d');

        $orders = $this->fetchOrders([
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'isActiveOrders' => 'false',
        ]);

        $returns = [];
        foreach ($orders as $order) {
            if (($order['status'] ?? '') !== 'iade') {
                continue;
            }

            $mpOrderId = $order['marketplace_order_id'] ?? '';
            $items = $order['items'] ?? [];

            foreach ($items as $item) {
                $returns[] = [
                    'marketplace_return_id' => 'RET-' . $mpOrderId . '-' . ($item['marketplace_item_id'] ?? ''),
                    'marketplace_order_id'  => $mpOrderId,
                    'status'                => 'created',
                    'reason'                => 'İade talebi (PttAVM)',
                    'customer_name'         => $order['customer_name'] ?? '',
                    'customer_email'        => $order['customer_email'] ?? '',
                    'product_name'          => $item['name'] ?? '',
                    'product_sku'           => $item['sku'] ?? '',
                    'quantity'              => $item['qty'] ?? 1,
                    'refund_amount'         => $item['line_total'] ?? 0,
                    'created_at'            => $order['ordered_at'] ?? date('Y-m-d H:i:s'),
                    'items'                 => [[
                        'merchant_sku'   => $item['sku'] ?? '',
                        'product_name'   => $item['name'] ?? '',
                        'quantity'       => $item['qty'] ?? 1,
                        'refund_amount'  => $item['line_total'] ?? 0,
                    ]],
                ];
            }
        }

        return $returns;
    }

    public function approveReturn(string $returnId): bool
    {
        // PttAVM'de iade onayı sipariş yönetimi üzerinden yapılır
        $this->logger->info("PttAVM approveReturn: İade onayı pazaryeri panelinden yapılmalıdır", 'PttavmAPI');
        return false;
    }

    public function rejectReturn(string $returnId, string $reason): bool
    {
        // PttAVM'de iade reddi sipariş yönetimi üzerinden yapılır
        $this->logger->info("PttAVM rejectReturn: İade reddi pazaryeri panelinden yapılmalıdır", 'PttavmAPI');
        return false;
    }
}
