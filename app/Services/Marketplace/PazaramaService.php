<?php
/**
 * OzyConnect Panel — PazaramaService (Pazarama API Entegrasyonu)
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

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class PazaramaService extends AbstractMarketplace
{
    private const SLUG = 'pazarama';

    private string $clientId = '';
    private string $clientSecret = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];

    /** @var array<string, mixed> */
    private array $config;

    /** Geçerli Bearer access token */
    private string $accessToken = '';

    /** Token son geçerlilik zamanı (unix timestamp) */
    private int $tokenExpiresAt = 0;

    /** Credential'lar yüklendi mi? (tekrar DB sorgusu önleme) */
    private bool $credentialsLoaded = false;

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->baseUrl             = $this->config['base_url'] ?? 'https://isortagimapi.pazarama.com';
        $this->timeout             = $this->config['api']['timeout_seconds'] ?? 30;
        $this->maxRetries          = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs    = $this->config['api']['retry_base_delay_ms'] ?? 500;
        $this->rateLimitPerSecond  = $this->config['api']['rate_limit_per_second'] ?? 5;
    }

    // ═══════════════════════════════════════════════════════
    // KİMLİK BİLGİLERİ & TOKEN YÖNETİMİ
    // ═══════════════════════════════════════════════════════

    /**
     * API bilgilerini veritabanından yükle
     */
    public function loadCredentials(): void
    {
        // Zaten yüklendiyse tekrar DB sorgusu yapma
        if ($this->credentialsLoaded) {
            return;
        }

        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('Pazarama pazaryeri kaydı bulunamadı');
        }

        if (empty($marketplace['api_key_encrypted']) || empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('Pazarama API bilgileri (Client ID / Client Secret) henüz girilmemiş');
        }

        $this->clientId     = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->clientSecret = $this->crypto->decrypt($marketplace['api_secret_encrypted']);

        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
        $this->credentialsLoaded = true;
    }

    /**
     * OAuth2 Bearer token al (client_credentials)
     * Token 1 saat geçerli — süre dolmadıysa cached token döner
     */
    private function ensureToken(): void
    {
        if ($this->accessToken !== '' && time() < $this->tokenExpiresAt) {
            return;
        }

        $tokenUrl = $this->config['token_url'] ?? 'https://isortagimgiris.pazarama.com/connect/token';

        $postFields = http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'merchantgatewayapi.fullaccess',
        ]);

        $authHeader = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);

        $maxRetries = 3;
        $response = false;
        $httpCode = 0;
        $error = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => $tokenUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    $authHeader,
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response !== false) {
                break;
            }

            $this->logger->warning("Pazarama token isteği başarısız (deneme {$attempt}/{$maxRetries}): {$error}", 'PazaramaAPI');

            if ($attempt < $maxRetries) {
                usleep(1000000 * $attempt); // 1s, 2s bekle
            }
        }

        if ($response === false) {
            throw new RuntimeException('Pazarama token isteği başarısız (' . $maxRetries . ' deneme): ' . $error);
        }

        $rawResponse = (string) $response;
        $body = json_decode($rawResponse, true);

        // Pazarama yanıt formatı: {"success":true,"data":{"accessToken":"...","expiresIn":3600}}
        $tokenData = $body['data'] ?? [];
        $token = $tokenData['accessToken'] ?? $body['access_token'] ?? '';

        if ($httpCode !== 200 || $token === '') {
            $errorDesc = $body['message'] ?? $body['error_description'] ?? $body['error'] ?? null;
            $detail = $errorDesc ?? mb_substr($rawResponse, 0, 300);
            throw new RuntimeException("Pazarama token alınamadı ({$httpCode}): {$detail}");
        }

        $this->accessToken = $token;
        // Token 1 saat geçerli — 5 dakika erken yenile
        $expiresIn = (int) ($tokenData['expiresIn'] ?? $body['expires_in'] ?? 3600);
        $this->tokenExpiresAt = time() + $expiresIn - 300;

        $this->logger->debug('Pazarama access token alındı', 'PazaramaAPI', [
            'expires_in' => $expiresIn,
        ]);
    }

    /**
     * Bearer token ile auth header'ları oluştur
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        $this->ensureToken();

        return [
            "Authorization: Bearer {$this->accessToken}",
            "User-Agent: OzyConnect-OziyWeb",
            "Accept: application/json",
            "Content-Type: application/json",
        ];
    }

    /**
     * API URL'ini oluştur
     */
    private function buildUrl(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();

            // Token al — bu başarılıysa credentials doğru demektir
            $this->ensureToken();

            // Marka servisine basit bir istek at (en hafif endpoint)
            $url = $this->buildUrl('/brand/getBrands?Page=1&Size=1');
            $response = $this->httpGet($url, $this->authHeaders());

            if (isset($response['success']) && $response['success'] === true) {
                return [
                    'success' => true,
                    'message' => 'Pazarama bağlantısı başarılı!',
                ];
            }

            return [
                'success' => false,
                'message' => 'Pazarama API yanıt verdi ancak veri alınamadı: '
                    . ($response['message'] ?? 'Bilinmeyen hata'),
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

    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $allOrders = [];
        $pageNumber = 1;
        $pageSize = (int) ($this->config['api']['page_size'] ?? 50);

        $body = [
            'pageSize'   => $pageSize,
            'pageNumber' => $pageNumber,
        ];

        if (!empty($filters['start_date'] ?? $filters['startDate'] ?? '')) {
            $body['startDate'] = $filters['start_date'] ?? $filters['startDate'];
        }
        if (!empty($filters['end_date'] ?? $filters['endDate'] ?? '')) {
            $body['endDate'] = $filters['end_date'] ?? $filters['endDate'];
        }
        if (!empty($filters['order_number'])) {
            $body['orderNumber'] = (int) $filters['order_number'];
        }

        $endpoint = $this->config['endpoints']['orders'] ?? '/order/getOrdersForApi';

        $this->startPaging('pazarama.orders');

        do {
            $body['pageNumber'] = $pageNumber;
            $url = $this->buildUrl($endpoint);

            $response = $this->httpPost($url, $body, $this->authHeaders());

            $this->logger->debug('Pazarama sipariş yanıtı', 'PazaramaAPI', [
                'response_keys' => array_keys($response ?? []),
                'success' => $response['success'] ?? 'N/A',
                'message' => $response['message'] ?? 'N/A',
                'data_type' => isset($response['data']) ? gettype($response['data']) : 'NOT_SET',
                'data_count' => is_array($response['data'] ?? null) ? count($response['data']) : 'N/A',
                'raw_response_500' => substr(json_encode($response, JSON_UNESCAPED_UNICODE), 0, 500),
            ]);

            $orders = $response['data'] ?? [];
            if (!is_array($orders) || empty($orders)) {
                break;
            }

            $allOrders = array_merge($allOrders, $orders);
            $pageNumber++;

            if ($pageNumber > 100) {
                break;
            }
        } while (count($orders) === $pageSize && $this->keepPaging('pazarama.orders', $pageNumber, $orders));

        // Normalize et (processOrder'ın beklediği formata çevir)
        return array_map(fn(array $raw) => $this->normalizeOrder($raw), $allOrders);
    }

    /**
     * Pazarama ham sipariş verisini normalize et
     */
    private function normalizeOrder(array $raw): array
    {
        $statusMapping = $this->config['status_mapping'] ?? [];

        // Pazarama: orderStatus sipariş seviyesinde hep 3 (Sipariş Alındı) kalıyor.
        // Gerçek durum item seviyesindeki orderItemStatus alanında.
        // En ileri item statüsünü al (tüm teslim edildiyse → teslim_edildi, biri kargodaysa → kargoda)
        $rawStatus = 0;
        $orderItems = $raw['items'] ?? $raw['orderItems'] ?? [];
        foreach ($orderItems as $item) {
            $itemStatus = (int) ($item['orderItemStatus'] ?? $item['status'] ?? 0);
            if ($itemStatus > $rawStatus) {
                $rawStatus = $itemStatus;
            }
        }

        // Fallback: item statüsü bulunamazsa sipariş seviyesini kullan
        if ($rawStatus === 0) {
            $rawStatus = (int) ($raw['orderStatus'] ?? $raw['status'] ?? 3);
        }

        $status = $statusMapping[$rawStatus] ?? 'yeni';

        // Müşteri bilgileri
        $customerName = trim((string) ($raw['customerName'] ?? $raw['buyerName'] ?? ''));
        $customerEmail = (string) ($raw['customerEmail'] ?? $raw['buyerEmail'] ?? '');
        $customerPhone = (string) ($raw['customerPhone'] ?? $raw['buyerPhone'] ?? '');

        // Adres bilgileri — Pazarama: shipmentAddress, billingAddress
        $shipAddr = $raw['shipmentAddress'] ?? $raw['shippingAddress'] ?? $raw['deliveryAddress'] ?? [];
        $invAddr  = $raw['billingAddress'] ?? $raw['invoiceAddress'] ?? [];

        if (is_string($shipAddr)) {
            $shipAddr = ['address' => $shipAddr];
        }
        if (is_string($invAddr)) {
            $invAddr = ['address' => $invAddr];
        }

        $shippingAddress = [
            'name'         => $shipAddr['nameSurname'] ?? $shipAddr['fullName'] ?? $shipAddr['name'] ?? $customerName,
            'address'      => $shipAddr['addressDetail'] ?? $shipAddr['displayAddressText'] ?? $shipAddr['address'] ?? '',
            'city'         => $shipAddr['cityName'] ?? $shipAddr['city'] ?? '',
            'district'     => $shipAddr['districtName'] ?? $shipAddr['district'] ?? '',
            'postal_code'  => (string) ($shipAddr['postalCode'] ?? $shipAddr['zipCode'] ?? ''),
            'phone'        => $shipAddr['phoneNumber'] ?? $shipAddr['phone'] ?? $customerPhone,
            'neighborhood' => $shipAddr['neighborhoodName'] ?? $shipAddr['neighborhood'] ?? '',
        ];

        $billingAddress = [
            'name'        => $invAddr['nameSurname'] ?? $invAddr['fullName'] ?? $invAddr['name'] ?? $customerName,
            'address'     => $invAddr['addressDetail'] ?? $invAddr['displayAddressText'] ?? $invAddr['address'] ?? '',
            'city'        => $invAddr['cityName'] ?? $invAddr['city'] ?? '',
            'district'    => $invAddr['districtName'] ?? $invAddr['district'] ?? '',
            'postal_code' => (string) ($invAddr['postalCode'] ?? $invAddr['zipCode'] ?? ''),
            'company'     => $invAddr['companyName'] ?? $invAddr['company'] ?? '',
            'tax_office'  => $invAddr['taxOffice'] ?? '',
            'tax_number'  => (string) ($invAddr['taxNumber'] ?? $invAddr['identityNumber'] ?? ''),
        ];

        // TC / Vergi No
        $idNo = (string) ($raw['tckn'] ?? $raw['identityNumber'] ?? $invAddr['identityNumber'] ?? $invAddr['taxNumber'] ?? '');
        $taxOffice = $invAddr['taxOffice'] ?? '';

        // Kargo bilgileri — Pazarama: cargo bilgisi item bazında gelir, order seviyesinde de olabilir
        $cargoCompany    = (string) ($raw['cargoCompanyName'] ?? $raw['cargoCompany'] ?? '');
        $cargoTrackingNo = (string) ($raw['cargoTrackingNumber'] ?? $raw['trackingNumber'] ?? '');
        $cargoTrackingLink = (string) ($raw['cargoTrackingLink'] ?? $raw['trackingUrl'] ?? '');

        // Sipariş kalemleri
        $orderItems = $raw['orderItems'] ?? $raw['items'] ?? $raw['lines'] ?? [];
        $items = [];
        $subtotal = 0;
        $discount = 0;
        $taxTotal = 0;

        foreach ($orderItems as $line) {
            if (!is_array($line)) continue;

            // Pazarama: product bilgisi nested obje olarak gelir: product.code, product.name, product.vatRate
            $product = $line['product'] ?? [];
            $productName = $product['name'] ?? $line['productName'] ?? $line['name'] ?? '';
            $productCode = $product['stockCode'] ?? $product['code'] ?? $line['merchantSku'] ?? '';
            $productBarcode = $product['barcode'] ?? $line['barcode'] ?? $productCode;
            $vatRate = (float) ($product['vatRate'] ?? $line['vatRate'] ?? $line['taxRate'] ?? 20);

            // Pazarama: fiyatlar nested obje: {value: 406.24, currency: "TL"}
            $rawSalePrice = $line['salePrice'] ?? null;
            $unitPrice = is_array($rawSalePrice) ? (float) ($rawSalePrice['value'] ?? 0) : (float) ($rawSalePrice ?? 0);

            $rawListPrice = $line['listPrice'] ?? null;
            $listPrice = is_array($rawListPrice) ? (float) ($rawListPrice['value'] ?? 0) : (float) ($rawListPrice ?? $unitPrice);

            $rawDiscount = $line['discountAmount'] ?? $line['discount'] ?? null;
            $lineDiscount = is_array($rawDiscount) ? (float) ($rawDiscount['value'] ?? 0) : (float) ($rawDiscount ?? 0);

            $qty = (int) ($line['quantity'] ?? $line['qty'] ?? 1);

            $rawTotal = $line['totalPrice'] ?? $line['amount'] ?? null;
            $lineTotal = is_array($rawTotal) ? (float) ($rawTotal['value'] ?? 0) : (float) ($rawTotal ?? 0);

            if ($lineTotal === 0.0 && $unitPrice > 0) {
                $lineTotal = $unitPrice * $qty;
            }
            if ($lineDiscount === 0.0 && $listPrice > $unitPrice) {
                $lineDiscount = ($listPrice - $unitPrice) * $qty;
            }

            // Kargo bilgisi item bazında gelebilir
            $cargo = $line['cargo'] ?? [];
            if (!empty($cargo['companyName']) && $cargoCompany === '') {
                $cargoCompany = (string) $cargo['companyName'];
            }
            if (!empty($cargo['trackingNumber']) && $cargoTrackingNo === '') {
                $cargoTrackingNo = (string) $cargo['trackingNumber'];
            }
            if (!empty($cargo['trackingUrl']) && $cargoTrackingLink === '') {
                $cargoTrackingLink = (string) $cargo['trackingUrl'];
            }

            // KDV hesapla — Pazarama taxAmount nested obje olarak gelebilir
            $rawTaxAmt = $line['taxAmount'] ?? null;
            $lineTax = is_array($rawTaxAmt) ? (float) ($rawTaxAmt['value'] ?? 0) : (float) ($rawTaxAmt ?? 0);
            if ($lineTax === 0.0 && $lineTotal > 0 && $vatRate > 0) {
                $lineTax = round($lineTotal * $vatRate / (100 + $vatRate), 2);
            }

            $items[] = [
                'marketplace_item_id' => (string) ($line['orderItemId'] ?? $line['id'] ?? ''),
                'sku'                 => $productCode,
                'barcode'             => $productBarcode,
                'name'                => $productName,
                'qty'                 => $qty,
                'unit_price'          => $unitPrice,
                'discount'            => $lineDiscount,
                'tax_rate'            => $vatRate,
                'line_total'          => $lineTotal,
            ];

            $subtotal += ($lineTotal - $lineTax);
            $taxTotal += $lineTax;
            $discount += $lineDiscount;
        }

        // Sipariş tarihi
        $orderedAt = $raw['orderDate'] ?? $raw['createdDate'] ?? null;
        if (is_string($orderedAt)) {
            $ts = strtotime($orderedAt);
            $orderedAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        } elseif (is_numeric($orderedAt)) {
            $orderedAt = date('Y-m-d H:i:s', intdiv((int) $orderedAt, ($orderedAt > 9999999999 ? 1000 : 1)));
        } else {
            $orderedAt = date('Y-m-d H:i:s');
        }

        // Kargo verilme tarihi
        $cargoSentAt = $raw['shippedDate'] ?? $raw['cargoSentDate'] ?? null;
        if (is_string($cargoSentAt) && $cargoSentAt !== '') {
            $ts = strtotime($cargoSentAt);
            $cargoSentAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        } elseif (is_numeric($cargoSentAt)) {
            $cargoSentAt = date('Y-m-d H:i:s', intdiv((int) $cargoSentAt, ($cargoSentAt > 9999999999 ? 1000 : 1)));
        } else {
            $cargoSentAt = null;
        }

        return [
            'marketplace_order_id' => (string) ($raw['orderNumber'] ?? $raw['id'] ?? ''),
            'status'               => $status,
            'customer_name'        => $customerName,
            'customer_email'       => $customerEmail,
            'customer_phone'       => $customerPhone,
            'customer_id_no'       => $idNo,
            'customer_tax_office'  => $taxOffice,
            'notes'                => (string) ($raw['customerNote'] ?? $raw['note'] ?? ''),
            'billing_address'      => $billingAddress,
            'shipping_address'     => $shippingAddress,
            'subtotal'             => round($subtotal, 2),
            'tax_total'            => round($taxTotal, 2),
            'discount_total'       => (float) ($raw['discountAmount'] ?? $raw['totalDiscount'] ?? $discount),
            'total_amount'         => (float) ($raw['orderAmount'] ?? $raw['totalAmount'] ?? $raw['totalPrice'] ?? round($subtotal + $taxTotal, 2)),
            'cargo_company'        => $cargoCompany,
            'cargo_tracking_no'    => $cargoTrackingNo,
            'cargo_tracking_link'  => $cargoTrackingLink,
            'cargo_sent_at'        => $cargoSentAt,
            'ordered_at'           => $orderedAt,
            'items'                => $items,
            'raw_data'             => $raw,
        ];
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();

        $orderNumber = (int) ($extra['order_number'] ?? $orderId);
        $orderItemId = $extra['order_item_id'] ?? '';

        // Tekli item güncelleme
        if ($orderItemId !== '') {
            $url = $this->buildUrl(
                $this->config['endpoints']['order_update'] ?? '/order/updateOrderStatus'
            );

            $payload = [
                'orderNumber' => $orderNumber,
                'item' => [
                    'orderItemId' => $orderItemId,
                    'status'      => (int) $status,
                ],
            ];

            // Kargoya verildi ise kargo bilgilerini ekle
            if ((int) $status === 5) {
                if (!empty($extra['tracking_number'])) {
                    $payload['item']['shippingTrackingNumber'] = $extra['tracking_number'];
                }
                if (!empty($extra['tracking_url'])) {
                    $payload['item']['trackingUrl'] = $extra['tracking_url'];
                }
                if (!empty($extra['cargo_company_id'])) {
                    $payload['item']['cargoCompanyId'] = $extra['cargo_company_id'];
                }
                if (!empty($extra['delivery_type'])) {
                    $payload['item']['deliveryType'] = (int) $extra['delivery_type'];
                }
            }

            $this->httpPut($url, $payload, $this->authHeaders());
            return true;
        }

        // Toplu sipariş güncelleme (tüm item'lar)
        $url = $this->buildUrl(
            $this->config['endpoints']['order_update_list'] ?? '/order/updateOrderStatusList'
        );

        $this->httpPut($url, [
            'orderNumber' => $orderNumber,
            'status'      => (int) $status,
        ], $this->authHeaders());

        return true;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        return $this->updateOrderStatus($orderId, '5', [
            'order_number'    => $orderId,
            'tracking_number' => $trackingNo,
            'cargo_company_id' => $cargoCompany,
            'delivery_type'   => 1,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['products'] ?? '/product/products'
        );

        // Controller 0-based gönderir, Pazarama API 1-based bekler
        $url .= '?' . http_build_query([
            'Approved' => 'true',
            'Page'     => $page + 1,
            'Size'     => $size,
        ]);

        return $this->httpGet($url, $this->authHeaders());
    }

    public function updateStock(array $items): array
    {
        $this->loadCredentials();

        // Pazarama max 3000 ürün/istek
        $batchLimit = (int) ($this->config['api']['stock_batch_limit'] ?? 3000);
        $chunks = array_chunk($items, $batchLimit);

        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        $dataIds = [];

        $url = $this->buildUrl(
            $this->config['endpoints']['stock_update'] ?? '/product/updateStock-v2'
        );

        foreach ($chunks as $chunk) {
            $payload = ['items' => []];
            foreach ($chunk as $item) {
                $payload['items'][] = [
                    'code'       => $item['barcode'] ?? '',
                    'stockCount' => (int) ($item['quantity'] ?? 0),
                ];
            }

            $response = $this->httpPost($url, $payload, $this->authHeaders());

            if (($response['success'] ?? false) === true) {
                $totalSuccess += count($chunk);
                if (!empty($response['data'])) {
                    $dataIds[] = $response['data'];
                }
            } else {
                $totalFailed += count($chunk);
                $errors[] = $response['message'] ?? 'Bilinmeyen hata';
            }
        }

        return [
            'success'  => $totalSuccess,
            'failed'   => $totalFailed,
            'errors'   => $errors,
            'dataIds'  => $dataIds,
        ];
    }

    public function updatePrice(array $items): array
    {
        $this->loadCredentials();

        $batchLimit = (int) ($this->config['api']['price_batch_limit'] ?? 3000);
        $chunks = array_chunk($items, $batchLimit);

        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        $dataIds = [];

        $url = $this->buildUrl(
            $this->config['endpoints']['price_update'] ?? '/product/updatePrice-v2'
        );

        foreach ($chunks as $chunk) {
            $payload = ['items' => []];
            foreach ($chunk as $item) {
                $payload['items'][] = [
                    'code'      => $item['barcode'] ?? '',
                    'listPrice' => (float) ($item['listPrice'] ?? $item['price'] ?? 0),
                    'salePrice' => (float) ($item['salePrice'] ?? $item['price'] ?? 0),
                ];
            }

            $response = $this->httpPost($url, $payload, $this->authHeaders());

            if (($response['success'] ?? false) === true) {
                $totalSuccess += count($chunk);
                if (!empty($response['data'])) {
                    $dataIds[] = $response['data'];
                }
            } else {
                $totalFailed += count($chunk);
                $errors[] = $response['message'] ?? 'Bilinmeyen hata';
            }
        }

        return [
            'success'  => $totalSuccess,
            'failed'   => $totalFailed,
            'errors'   => $errors,
            'dataIds'  => $dataIds,
        ];
    }

    /**
     * Pazarama'ya ürün oluştur / güncelle (Batch API)
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success: bool, batchId: ?string, errors: array}
     */
    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['product_create'] ?? '/product/api/product'
        );

        $payload = ['products' => []];

        foreach ($items as $item) {
            $product = [
                'barcode'         => $item['barcode'] ?? '',
                'title'           => $item['title'] ?? $item['name'] ?? '',
                'productMainId'   => $item['productMainId'] ?? $item['sku'] ?? '',
                // Pazarama marka/kategori ID'leri GUID string'dir — int cast 0'a çevirip
                // her gönderimi reddettiriyordu
                'brandId'         => (string) ($item['brandId'] ?? ''),
                'categoryId'      => (string) ($item['categoryId'] ?? ''),
                'quantity'        => (int) ($item['quantity'] ?? 0),
                'stockCode'       => $item['stockCode'] ?? $item['sku'] ?? '',
                'description'     => $item['description'] ?? $item['title'] ?? '',
                'listPrice'       => (float) ($item['listPrice'] ?? 0),
                'salePrice'       => (float) ($item['salePrice'] ?? 0),
                'vatRate'         => (int) ($item['vatRate'] ?? 20),
                'currencyType'    => 'TL',
            ];

            // Görseller
            if (!empty($item['images'])) {
                $product['images'] = [];
                foreach ($item['images'] as $i => $img) {
                    $url2 = $img['url'] ?? '';
                    if ($url2 !== '') {
                        $product['images'][] = [
                            'url'   => $url2,
                            'order' => $i,
                        ];
                    }
                }
            }

            // Kategori özellikleri — Pazarama attribute ID'leri de GUID string'dir
            if (!empty($item['attributes'])) {
                $product['attributes'] = [];
                foreach ($item['attributes'] as $attr) {
                    $a = ['attributeId' => (string) ($attr['attributeId'] ?? '')];
                    if (!empty($attr['customAttributeValue'])) {
                        $a['customAttributeValue'] = $attr['customAttributeValue'];
                    } elseif (!empty($attr['attributeValueId'])) {
                        $a['attributeValueId'] = (string) $attr['attributeValueId'];
                    }
                    $product['attributes'][] = $a;
                }
            }

            // Desi / ağırlık
            if (isset($item['dimensionalWeight'])) {
                $product['dimensionalWeight'] = (float) $item['dimensionalWeight'];
            }

            $payload['products'][] = $product;
        }

        $response = $this->httpPost($url, $payload, $this->authHeaders());

        // data string (doğrudan batch id) veya obje ({batchRequestId: ...}) gelebilir
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $batchId = $data['batchRequestId'] ?? $data['batchId'] ?? $data['id'] ?? null;
        } else {
            $batchId = is_string($data) || is_numeric($data) ? (string) $data : null;
        }
        $batchId = $batchId !== null ? (string) $batchId : null;
        $success = ($response['success'] ?? false) === true;

        if (!$success) {
            $errorMsg = $response['message'] ?? 'Bilinmeyen hata';
            return [
                'success' => false,
                'batchId' => $batchId,
                'errors'  => [['error' => $errorMsg]],
            ];
        }

        return [
            'success' => true,
            'batchId' => $batchId,
            'errors'  => [],
        ];
    }

    /**
     * Pazarama'ya varyantlı ürün grubu gönder
     * Aynı groupCode ile gönderilen ürünler tek kart altında gruplanır.
     *
     * @param array{items: array, groupId: string} $variantGroup
     * @return array{success: bool, batchId: ?string, errors: array}
     */
    public function createVariantProducts(array $variantGroup): array
    {
        $items = [];
        foreach ($variantGroup['items'] ?? [] as $item) {
            $item['productMainId'] = $variantGroup['groupId'];
            $item['groupCode']     = $variantGroup['groupId'];
            $items[] = $item;
        }
        return $this->createProducts($items);
    }

    /**
     * Ürün batch durumunu sorgula
     */
    public function getProductBatchStatus(string $batchId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_batch_status'] ?? '/product/api/product/{batchId}';
        $endpoint = str_replace('{batchId}', urlencode($batchId), $endpoint);

        return $this->httpGet($this->buildUrl($endpoint), $this->authHeaders());
    }

    /**
     * ProductPublishService'in beklediği ortak isim — getProductBatchStatus sarmalayıcısı
     */
    public function getBatchStatus(string $batchId): array
    {
        return $this->getProductBatchStatus($batchId);
    }

    /**
     * Ürünleri aç (aktifleştir)
     *
     * @param array<string> $barcodes
     */
    public function openProducts(array $barcodes): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['product_status_open'] ?? '/product/openProduct'
        );

        return $this->httpPost($url, ['barcodes' => $barcodes], $this->authHeaders());
    }

    /**
     * Ürünleri kapat (pasifleştir)
     *
     * @param array<string> $barcodes
     */
    public function closeProducts(array $barcodes): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['product_status_close'] ?? '/product/closeProducts'
        );

        return $this->httpPost($url, ['barcodes' => $barcodes], $this->authHeaders());
    }

    /**
     * Ürün detayını getir
     */
    public function fetchProductDetail(string $barcode): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['product_detail'] ?? '/product/getProductDetail'
        );

        $response = $this->httpPost($url, ['Code' => $barcode], $this->authHeaders());

        return $response['data'] ?? [];
    }

    /**
     * Katalog arama (mevcut Pazarama ürünleri arasında)
     */
    public function catalogSearch(string $keyword, int $page = 1, int $size = 20): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['catalog_search'] ?? '/product/catalogSearch'
        );

        return $this->httpPost($url, [
            'keyword'    => $keyword,
            'pageNumber' => $page,
            'pageSize'   => $size,
        ], $this->authHeaders());
    }

    /**
     * Hızlı ürün ekleme (katalogdan)
     */
    public function quickProductAdd(array $items): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['quick_product_add'] ?? '/product/quickProductAdd'
        );

        return $this->httpPost($url, ['items' => $items], $this->authHeaders());
    }

    /**
     * Teslimat tiplerini getir
     */
    public function getDeliveryTypes(): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['delivery_types'] ?? '/product/getDeliveryTypes'
        );

        $response = $this->httpGet($url, $this->authHeaders());
        return $response['data'] ?? [];
    }

    /**
     * KDV oranı güncelle
     */
    public function updateVat(array $items): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['vat_update'] ?? '/product/api/product/vat'
        );

        return $this->httpPost($url, ['products' => $items], $this->authHeaders());
    }

    /**
     * Ürün ticari bilgilerini getir
     */
    public function getProductCommercialInfo(string $barcode): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['product_commercial'] ?? '/product/getProductCommercialInfo'
        );
        $url .= '?barcode=' . urlencode($barcode);

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İÇE AKTARMA
    // ═══════════════════════════════════════════════════════

    /**
     * Ürünleri import et
     *
     * @param bool $hydrate true ise her ürün için detay endpoint'i çağrılır (yavaş!)
     *                       Bulk import'ta false bırakın — liste verisindeki bilgilerle yetinilir.
     *                       Tek ürün detayı gerektiğinde true kullanın.
     */
    public function importProducts(int $page = 0, int $size = 100, bool $hydrate = false): array
    {
        $response = $this->fetchProducts($page, $size);

        // Pazarama yanıt yapısı: {data: [...], totalPages: int, totalCount: int, ...}
        $rawProducts = $response['data'] ?? [];

        $products = [];
        foreach ($rawProducts as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            // Bulk import'ta hydrate=false → detay API çağrısı yapılmaz (performans)
            // Her ürün için ayrı fetchProductDetail() çağrısı, rate limit (1 req/sn) ile
            // 100 ürünlük sayfa başına ~100 saniye bekletiyordu → timeout
            $normalized = $hydrate
                ? $this->normalizeImportProduct($this->hydrateImportProduct($raw))
                : $this->normalizeImportProduct($raw);

            $products[] = $normalized;
        }

        // totalPages: API yanıtından al, yoksa data boyutundan tahmin et
        $totalPages = $response['totalPages']
            ?? $response['pageCount']
            ?? (($size > 0 && count($rawProducts) === $size) ? ($page + 2) : ($page + 1));

        $totalElements = $response['totalCount']
            ?? $response['totalElements']
            ?? count($rawProducts);

        return [
            'products'      => $products,
            'totalPages'    => (int) $totalPages,
            'totalElements' => (int) $totalElements,
        ];
    }

    /**
     * Pazarama ürün verisini standart formata dönüştür
     *
     * Pazarama API alanı → Normalize alanı:
     *   code → barcode (Pazarama'da barkod 'code' alanında)
     *   stockCode → marketplace_sku
     *   groupCode → marketplace_product_id (grup kodu yoksa code kullan)
     *   brandName → brand_name (brandId yok, adı ile md5 hash üretilir)
     *   stockCount → stock
     */
    private function normalizeImportProduct(array $raw): array
    {
        $images = [];
        if (is_array($raw['images'] ?? null)) {
            foreach ($raw['images'] as $i => $img) {
                $url = '';
                if (is_string($img)) {
                    $url = $img;
                } elseif (is_array($img)) {
                    $url = $img['url'] ?? $img['imageUrl'] ?? '';
                }
                if ($url !== '') {
                    $images[] = [
                        'url'      => $url,
                        'is_cover' => $i === 0,
                        'sort'     => $i + 1,
                    ];
                }
            }
        }

        $attributes = [];
        if (is_array($raw['attributes'] ?? null)) {
            foreach ($raw['attributes'] as $attr) {
                $attributes[] = [
                    'name'  => $attr['attributeName'] ?? $attr['name'] ?? '',
                    'value' => $attr['attributeValue'] ?? $attr['value'] ?? '',
                ];
            }
        }

        // brandId Pazarama'da yok — brandName'den deterministik ID üret
        $brandName = trim($raw['brandName'] ?? $raw['brand'] ?? '');
        $brandId = $brandName !== '' ? md5('pazarama_brand_' . mb_strtolower($brandName)) : '';

        // code = barkod, groupCode = ürün grubu (ana ürün ID)
        $code = trim($raw['code'] ?? '');
        $groupCode = trim($raw['groupCode'] ?? '');

        $normalized = [
            'marketplace_sku'         => $raw['stockCode'] ?? $code,
            'marketplace_product_id'  => $groupCode !== '' ? $groupCode : $code,
            'barcode'                 => $code,
            'name'                    => $raw['name'] ?? $raw['displayName'] ?? '',
            'category_name'           => $raw['categoryName'] ?? '',
            'category_id'             => (string) ($raw['categoryId'] ?? ''),
            'brand_name'              => $brandName,
            'brand_id'                => $brandId,
            'price'                   => (float) ($raw['salePrice'] ?? 0),
            'list_price'              => (float) ($raw['listPrice'] ?? 0),
            'vat_rate'                => (int) ($raw['vatRate'] ?? 20),
            'currency'                => $raw['currencyType'] ?? 'TL',
            'description'             => $raw['description'] ?? '',
            'images'                  => $images,
            'attributes'              => $attributes,
            'status'                  => $raw['productStatus'] ?? $raw['status'] ?? '',
            'group_id'                => $groupCode,
            'raw'                     => $raw,
        ];

        if (array_key_exists('stockCount', $raw)) {
            $normalized['stock'] = (int) $raw['stockCount'];
        } elseif (array_key_exists('quantity', $raw)) {
            $normalized['stock'] = (int) $raw['quantity'];
        }

        return $normalized;
    }

    /**
     * Liste endpoint'inde eksik gelen alanları ürün detayından tamamla.
     *
     * Pazarama ürün listesi çoğu hesapta images alanını boş döndürüyor.
     * Detay endpoint'i ise aynı ürün için görselleri sağlıyor.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function hydrateImportProduct(array $raw): array
    {
        $code = trim((string) ($raw['code'] ?? ''));
        if ($code === '') {
            return $raw;
        }

        $needsDetail = empty($raw['images']) || empty($raw['description']) || empty($raw['groupCode']);
        if (!$needsDetail) {
            return $raw;
        }

        try {
            $detail = $this->fetchProductDetail($code);
            if ($detail === []) {
                return $raw;
            }

            foreach (['description', 'brandName', 'categoryId', 'categoryName', 'groupCode', 'images'] as $field) {
                if ((empty($raw[$field]) || $field === 'images') && !empty($detail[$field])) {
                    $raw[$field] = $detail[$field];
                }
            }

            foreach (['name', 'displayName', 'stockCount', 'stockCode', 'listPrice', 'salePrice', 'vatRate'] as $field) {
                if (empty($raw[$field]) && array_key_exists($field, $detail)) {
                    $raw[$field] = $detail[$field];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Pazarama ürün detay zenginleştirme hatası', 'PazaramaAPI', [
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);
        }

        return $raw;
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ / MARKA
    // ═══════════════════════════════════════════════════════

    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['categories'] ?? '/category/getCategoryTree'
        );

        $response = $this->httpGet($url, $this->authHeaders());

        return $response['data'] ?? [];
    }

    /**
     * Pazarama marka katalogunda ada göre GERÇEK marka GUID'ini bul.
     * Ürün oluşturmada brandId System.Guid olmak zorundadır.
     */
    public function findBrandGuidByName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $this->loadCredentials();

        $url = $this->buildUrl('/brand/getBrands?Approved=true&Page=1&Size=20&Name=' . rawurlencode($name));

        try {
            $result = $this->httpGet($url, $this->authHeaders());
        } catch (\Throwable $e) {
            $this->logger->warning('Pazarama marka arama hatası: ' . $e->getMessage(), 'PazaramaAPI');
            return null;
        }

        $rows = $result['data'] ?? [];
        if (!is_array($rows)) {
            return null;
        }

        // Önce birebir eşleşme, yoksa ilk sonuç
        foreach ($rows as $row) {
            if (is_array($row) && mb_strtolower(trim((string) ($row['name'] ?? ''))) === mb_strtolower($name)) {
                return (string) ($row['id'] ?? '') ?: null;
            }
        }

        $first = $rows[0] ?? null;
        return is_array($first) && !empty($first['id']) ? (string) $first['id'] : null;
    }

    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();

        $baseUrl = $this->buildUrl(
            $this->config['endpoints']['brands'] ?? '/brand/getBrands'
        );

        // Arama varsa doğrudan API'den çek
        if ($search !== '') {
            $url = $baseUrl . '?' . http_build_query([
                'Page' => 1,
                'Size' => 1000,
                'name' => $search,
            ]);
            $response = $this->httpGet($url, $this->authHeaders());
            return $response['data'] ?? [];
        }

        // Tüm markaları çekmek çok yavaş — kendi ürünlerimizden markaları çıkar
        $brands = [];
        $page = 0;
        $pageSize = 200;

        // Sayfalama koruması: API totalPages'i yanlış bildirirse döngü sonsuza gitmesin
        $this->startPaging('pazarama.brands');

        while (true) {
            $response = $this->fetchProducts($page, $pageSize);
            $items = $response['data'] ?? [];

            if (empty($items)) {
                break;
            }

            foreach ($items as $product) {
                // Pazarama API'sinde brandId yok, sadece brandName var
                $brandName = trim($product['brandName'] ?? $product['brand'] ?? '');

                if ($brandName !== '' && !isset($brands[$brandName])) {
                    $brands[$brandName] = [
                        'id'   => md5('pazarama_brand_' . mb_strtolower($brandName)),
                        'name' => $brandName,
                    ];
                }
            }

            // Son sayfa kontrolü
            $totalPages = $response['totalPages'] ?? null;
            if ($totalPages !== null && ($page + 1) >= (int) $totalPages) {
                break;
            }
            if (count($items) < $pageSize) {
                break;
            }

            $page++;

            if (!$this->keepPaging('pazarama.brands', $page, $items)) {
                break;
            }
        }

        $this->logger->info("Pazarama marka çekimi (ürünlerden): " . count($brands) . " marka, " . ($page + 1) . " sayfa", 'PazaramaAPI');

        return array_values($brands);
    }

    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['category_attributes'] ?? '/category/getCategoryWithAttributes'
        );
        $url .= '?Id=' . urlencode($marketplaceCategoryId);

        $response = $this->httpGet($url, $this->authHeaders());

        $data = $response['data'] ?? [];
        $attributes = $data['attributes'] ?? [];

        $result = [];
        foreach ($attributes as $attr) {
            $values = [];
            foreach ($attr['attributeValues'] ?? [] as $val) {
                $values[] = [
                    'id'   => $val['id'] ?? '',
                    'name' => $val['value'] ?? '',
                ];
            }

            $result[] = [
                'id'          => $attr['id'] ?? '',
                'name'        => $attr['name'] ?? '',
                'required'    => (bool) ($attr['isRequired'] ?? false),
                'allowCustom' => false,
                'values'      => $values,
            ];
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        $body = [
            'pageSize'   => (int) ($filters['page_size'] ?? 100),
            'pageNumber' => (int) ($filters['page'] ?? 1),
        ];

        if (!empty($filters['status'])) {
            $body['refundStatus'] = (int) $filters['status'];
        }
        if (!empty($filters['start_date'])) {
            $body['requestStartDate'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $body['requestEndDate'] = $filters['end_date'];
        }

        $url = $this->buildUrl(
            $this->config['endpoints']['refunds'] ?? '/order/getRefund'
        );

        $response = $this->httpPost($url, $body, $this->authHeaders());

        return $response['data']['refundList'] ?? [];
    }

    public function approveReturn(string $returnId): bool
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['refund_update'] ?? '/order/updateRefund'
        );

        $this->httpPost($url, [
            'refundId' => $returnId,
            'status'   => 2, // Tedarikçi Tarafından Onaylandı
        ], $this->authHeaders());

        return true;
    }

    public function rejectReturn(string $returnId, string $reason = ''): bool
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['refund_update'] ?? '/order/updateRefund'
        );

        $payload = [
            'refundId' => $returnId,
            'status'   => 3, // Tedarikçi Tarafından Reddedildi
        ];

        if ($reason !== '') {
            $payload['RefundRejectType'] = (int) $reason;
        }

        $this->httpPost($url, $payload, $this->authHeaders());

        return true;
    }

    // ═══════════════════════════════════════════════════════
    // FATURA İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Pazarama'ya fatura linki gönder
     */
    public function sendInvoiceLink(int $orderNumber, string $invoiceUrl): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['invoice_link'] ?? '/order/invoiceLink'
        );

        return $this->httpPost($url, [
            'orderNumber' => $orderNumber,
            'invoiceLink' => $invoiceUrl,
        ], $this->authHeaders());
    }

    /**
     * Pazarama'ya fatura dosyası gönder (Base64)
     * Pazarama invoiceLink'ten farklı olarak invoiceItem endpoint'ine dosya gönderimi yapılır
     */
    public function sendInvoiceFile(int $orderNumber, string $filePath, array $orderItems = []): array
    {
        $this->loadCredentials();

        // Pazarama fatura dosyası gönderimi — invoiceItem kullanarak kalem bazlı fatura
        // Her item için fatura kesildi bilgisi gönderilir
        if (!empty($orderItems)) {
            return $this->sendInvoiceItem($orderNumber, $orderItems);
        }

        // Fallback: invoiceLink endpoint'i ile fatura URL'si gönder
        // Dosya varsa Base64 olarak link'e encode edebiliriz, ancak Pazarama API'si
        // doğrudan dosya upload'u yerine link tabanlı çalışır
        // Bu nedenle file varsa önce public URL oluştur
        $appUrl = rtrim($this->extraConfig['app_url'] ?? '', '/');
        if ($appUrl === '') {
            $this->logger->warning("Pazarama fatura gönderimi: app_url extraConfig'de tanımlı değil", 'PazaramaInvoice');
            return ['success' => false, 'message' => 'Pazarama extra_config içinde app_url tanımlı değil'];
        }
        if (!file_exists($filePath)) {
            $this->logger->warning("Pazarama fatura dosyası bulunamadı: {$filePath}", 'PazaramaInvoice');
            return ['success' => false, 'message' => "Fatura dosyası bulunamadı: {$filePath}"];
        }

        $fileName = basename($filePath);
        $invoiceUrl = $appUrl . '/storage/invoices/' . $fileName;
        return $this->sendInvoiceLink($orderNumber, $invoiceUrl);
    }

    /**
     * Pazarama'ya kalem bazlı fatura gönder
     */
    public function sendInvoiceItem(int $orderNumber, array $items): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['invoice_item'] ?? '/order/invoiceItem'
        );

        return $this->httpPost($url, [
            'orderNumber' => $orderNumber,
            'items'       => $items,
        ], $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // FİNANS İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Finans raporu çek
     * @see fetchSettlements() — aynı endpoint, normalizeSettlement() ile birlikte kullanın
     */
    public function fetchFinanceReport(array $filters = []): array
    {
        return $this->fetchSettlements($filters);
    }

    // ═══════════════════════════════════════════════════════
    // SORU & CEVAP İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün sorularını listele
     */
    public function fetchQuestions(array $filters = []): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['questions'] ?? '/qAndA/getQandA'
        );

        $body = [
            'pageSize'   => (int) ($filters['page_size'] ?? 100),
            'pageNumber' => (int) ($filters['page'] ?? 1),
        ];

        if (!empty($filters['status'])) {
            $body['status'] = (int) $filters['status'];
        }

        $response = $this->httpPost($url, $body, $this->authHeaders());

        return $response['data'] ?? [];
    }

    /**
     * Soruya cevap ver
     */
    public function answerQuestion(string $questionId, string $answer): bool
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['question_answer'] ?? '/qAndA/answerQuestion'
        );

        $this->httpPost($url, [
            'questionId' => $questionId,
            'answer'     => $answer,
        ], $this->authHeaders());

        return true;
    }

    // ═══════════════════════════════════════════════════════
    // İPTAL YÖNETİMİ
    // ═══════════════════════════════════════════════════════

    /**
     * İptal listesi
     */
    public function fetchCancellations(array $filters = []): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['cancel_management'] ?? '/order/getCancellationManagement'
        );

        return $this->httpPost($url, $filters, $this->authHeaders());
    }

    /**
     * İptal sorgulama
     */
    public function queryCancellation(int $orderNumber): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['cancel_query'] ?? '/order/cancellationQuery'
        );

        return $this->httpPost($url, [
            'orderNumber' => $orderNumber,
        ], $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ BÖLME & İADE REVİZYON
    // ═══════════════════════════════════════════════════════

    /**
     * İade için sipariş bölme
     */
    public function splitOrderForRefund(int $orderNumber, array $items): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['order_split'] ?? '/order/splitOrderForRefund'
        );

        return $this->httpPost($url, [
            'orderNumber' => $orderNumber,
            'items'       => $items,
        ], $this->authHeaders());
    }

    /**
     * İade revizyon gönder
     */
    public function refundRevision(string $refundId, array $data): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['refund_revision'] ?? '/order/refundRevision'
        );

        $payload = array_merge(['refundId' => $refundId], $data);
        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    /**
     * İade inceleme gönder
     */
    public function reviewSend(string $refundId, array $data): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['refund_review'] ?? '/order/reviewSend'
        );

        $payload = array_merge(['refundId' => $refundId], $data);
        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    /**
     * Sipariş paket bilgisi
     */
    public function getPackage(int $orderNumber): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['order_package'] ?? '/order/getPackage'
        );

        return $this->httpPost($url, [
            'orderNumber' => $orderNumber,
        ], $this->authHeaders());
    }

    /**
     * İşçilik maliyeti gönder
     */
    public function addLaborCost(int $orderNumber, array $items): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['order_labor_cost'] ?? '/order/laborCost'
        );

        return $this->httpPost($url, [
            'orderNumber' => $orderNumber,
            'items'       => $items,
        ], $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // FİNANS - GENİŞLETİLMİŞ
    // ═══════════════════════════════════════════════════════

    /**
     * Finans raporu çek (detaylı)
     */
    public function fetchSettlements(array $filters = []): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['finance_accounting'] ?? '/finance/getReport'
        );

        return $this->httpPost($url, $filters, $this->authHeaders());
    }

    /**
     * Finans öğesini normalize et
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function normalizeSettlement(array $item): array
    {
        $gross = (float) ($item['amount'] ?? $item['totalAmount'] ?? 0);
        $commission = (float) ($item['commissionAmount'] ?? $item['commission'] ?? 0);
        $net = $gross - $commission;

        $rawType = (string) ($item['transactionType'] ?? $item['type'] ?? 'other');
        $detectedType = match (true) {
            str_contains(strtolower($rawType), 'ödeme'),
            str_contains(strtolower($rawType), 'payment') => 'payment',
            str_contains(strtolower($rawType), 'komisyon'),
            str_contains(strtolower($rawType), 'commission') => 'commission',
            str_contains(strtolower($rawType), 'iade'),
            str_contains(strtolower($rawType), 'refund') => 'refund',
            str_contains(strtolower($rawType), 'kargo'),
            str_contains(strtolower($rawType), 'cargo') => 'shipping',
            str_contains(strtolower($rawType), 'stopaj'),
            str_contains(strtolower($rawType), 'stoppage') => 'stoppage',
            str_contains(strtolower($rawType), 'transfer'),
            str_contains(strtolower($rawType), 'virman') => 'wire_transfer',
            default => 'other',
        };

        return [
            'external_id'       => (string) ($item['id'] ?? $item['transactionId'] ?? md5(json_encode($item))),
            'transaction_type'  => $detectedType,
            'transaction_date'  => $item['transactionDate'] ?? $item['createdDate'] ?? date('Y-m-d H:i:s'),
            'order_number'      => (string) ($item['orderNumber'] ?? ''),
            'description'       => $item['description'] ?? $rawType,
            'gross_amount'      => $gross,
            'commission_amount' => abs($commission),
            'net_amount'        => $net,
            'currency'          => 'TRY',
            'status'            => 'completed',
            'extra_data_json'   => json_encode($item),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ YARDIMCI
    // ═══════════════════════════════════════════════════════

    /**
     * Kategori ağacını düzleştir (recursive)
     *
     * @param array<int, array<string, mixed>> $categories
     * @param string $parentPath
     * @return array<int, array{id: int|string, name: string, path: string}>
     */
    public function flattenCategoryTree(array $categories, string $parentPath = ''): array
    {
        $flat = [];

        foreach ($categories as $cat) {
            $id   = $cat['id'] ?? $cat['categoryId'] ?? '';
            $name = $cat['name'] ?? $cat['categoryName'] ?? '';
            $path = $parentPath !== '' ? "{$parentPath} > {$name}" : $name;

            $flat[] = [
                'id'   => $id,
                'name' => $name,
                'path' => $path,
            ];

            $children = $cat['subCategories'] ?? $cat['children'] ?? [];
            if (!empty($children)) {
                $flat = array_merge($flat, $this->flattenCategoryTree($children, $path));
            }
        }

        return $flat;
    }
}
