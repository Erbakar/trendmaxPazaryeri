<?php
/**
 * OzyConnect Panel — AmazonService (Amazon SP-API Entegrasyonu)
 *
 * Amazon Selling Partner API (SP-API) ile entegrasyon.
 * LWA (Login with Amazon) OAuth2 akışı kullanır.
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

final class AmazonService extends AbstractMarketplace
{
    private const SLUG = 'amazon';

    private string $clientId = '';
    private string $clientSecret = '';
    private string $refreshToken = '';
    private string $sellerId = '';
    private string $marketplaceId = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];

    /** @var array<string, mixed> */
    private array $config;

    /** LWA Access Token (kısa ömürlü) */
    private string $accessToken = '';

    /** Token son geçerlilik zamanı (unix timestamp) */
    private int $tokenExpiresAt = 0;

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->baseUrl             = $this->config['base_url'] ?? 'https://sellingpartnerapi-eu.amazon.com';
        $this->timeout             = $this->config['api']['timeout_seconds'] ?? 30;
        $this->maxRetries          = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs    = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond  = $this->config['api']['rate_limit_per_second'] ?? 2;
        $this->marketplaceId       = $this->config['marketplace_id'] ?? 'A33AVAJ2PDY3EV';
    }

    // ═══════════════════════════════════════════════════════
    // KİMLİK BİLGİLERİ & LWA TOKEN YÖNETİMİ
    // ═══════════════════════════════════════════════════════

    /**
     * API bilgilerini veritabanından yükle
     */
    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('Amazon pazaryeri kaydı bulunamadı');
        }

        if (empty($marketplace['api_key_encrypted']) || empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('Amazon API bilgileri (Client ID / Client Secret) henüz girilmemiş');
        }

        // api_key = LWA Client ID, api_secret = LWA Client Secret
        $this->clientId     = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->clientSecret = $this->crypto->decrypt($marketplace['api_secret_encrypted']);

        $this->extraConfig  = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
        $this->refreshToken = (string) ($this->extraConfig['refresh_token'] ?? '');
        $this->sellerId     = (string) ($this->extraConfig['seller_id'] ?? '');

        if ($this->refreshToken === '') {
            throw new RuntimeException('Amazon Refresh Token girilmemiş — Amazon test uygulamasından alınan tokeni girin');
        }

        if ($this->sellerId === '') {
            $this->logger->warning('Amazon Seller ID henüz girilmemiş — sipariş/ürün işlemleri için gereklidir', 'AmazonAPI');
        }
    }

    /**
     * LWA (Login with Amazon) Access Token al
     * Token 1 saat geçerli — süre dolmadıysa cached token döner
     */
    private function ensureToken(): void
    {
        // Token hâlâ geçerliyse (5 dakika erken yenile)
        if ($this->accessToken !== '' && time() < ($this->tokenExpiresAt - 300)) {
            return;
        }

        $tokenUrl = $this->config['token_url'] ?? 'https://api.amazon.com/auth/o2/token';

        $postFields = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

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
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            curl_close($ch);

            if ($response !== false && $httpCode === 200) {
                break;
            }

            $this->logger->warning(
                "Amazon LWA token isteği başarısız (Deneme {$attempt}/{$maxRetries})",
                'AmazonAPI',
                ['http_code' => $httpCode, 'error' => $error]
            );

            if ($attempt < $maxRetries) {
                usleep($this->retryBaseDelayMs * $attempt * 1000);
            }
        }

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException(
                "Amazon LWA token alınamadı (HTTP {$httpCode}): " . ($error ?: (string) $response)
            );
        }

        $data = json_decode((string) $response, true);

        if (empty($data['access_token'])) {
            throw new RuntimeException(
                'Amazon LWA token yanıtı geçersiz: ' . json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        }

        $this->accessToken    = $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);

        $this->logger->info(
            'Amazon LWA access token alındı',
            'AmazonAPI',
            ['expires_in' => $data['expires_in'] ?? 3600]
        );
    }

    /**
     * OAuth yetkilendirme URL'i oluştur (müşteriye gönderilecek link)
     */
    public function getAuthorizationUrl(string $appId, string $state): string
    {
        $authUrl = $this->config['auth_url']
            ?? 'https://sellercentral.amazon.com.tr/apps/authorize/consent';

        $params = http_build_query([
            'application_id' => $appId,
            'state'          => $state,
            'version'        => 'beta',
        ]);

        return $authUrl . '?' . $params;
    }

    /**
     * OAuth authorization code → refresh_token dönüştür
     */
    public function exchangeAuthCode(string $authCode): string
    {
        $tokenUrl = $this->config['token_url'] ?? 'https://api.amazon.com/auth/o2/token';

        $postFields = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $authCode,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

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
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException(
                "Amazon OAuth code exchange başarısız (HTTP {$httpCode}): " . ($error ?: (string) $response)
            );
        }

        $data = json_decode((string) $response, true);

        if (empty($data['refresh_token'])) {
            throw new RuntimeException(
                'Amazon OAuth yanıtında refresh_token bulunamadı: ' . json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        }

        return $data['refresh_token'];
    }

    /**
     * SP-API auth header'ları oluştur
     *
     * @return array<int, string>
     */
    /**
     * Kısıtlı Veri Jetonu (RDT) al.
     *
     * Alıcı adı/adresi Amazon tarafında PII sayılır; normal jetonla istenirse
     * BuyerInfo boş, ShippingAddress ise adsız döner. Bu yüzden sipariş çağrıları
     * RDT ile imzalanır. Uygulamanın PII rolü yoksa Amazon 403 verir — o durumda
     * null dönüp normal jetonla devam edilir (siparişler yine gelir, ad gelmez).
     *
     * @param array<int, array<string, mixed>> $resources
     */
    private function restrictedDataToken(array $resources): ?string
    {
        $anahtar = md5(json_encode($resources) ?: '');

        if (isset($this->rdtCache[$anahtar]) && $this->rdtCache[$anahtar]['exp'] > time() + 60) {
            return $this->rdtCache[$anahtar]['token'];
        }

        try {
            $url = $this->baseUrl . '/tokens/2021-03-01/restrictedDataToken';
            $response = $this->httpPost($url, ['restrictedResources' => $resources], $this->authHeaders());

            $token = (string) ($response['restrictedDataToken'] ?? '');
            if ($token === '') {
                return null;
            }

            $this->rdtCache[$anahtar] = [
                'token' => $token,
                'exp'   => time() + (int) ($response['expiresIn'] ?? 3600),
            ];

            return $token;
        } catch (\Throwable $e) {
            // 403 = uygulamanın PII (Direct-to-Consumer Shipping) rolü yok.
            $this->logger->info(
                'Amazon kısıtlı veri jetonu alınamadı, alıcı adı gizli kalacak: ' . $e->getMessage(),
                'AmazonAPI'
            );

            return null;
        }
    }

    /**
     * @param string|null $overrideToken Kısıtlı Veri Jetonu (RDT) verilirse onunla imzalar.
     */
    /** @var array<string, array{token: string, exp: int}> RDT önbelleği (istek ömrü boyunca) */
    private array $rdtCache = [];

    private function authHeaders(?string $overrideToken = null): array
    {
        if ($overrideToken === null || $overrideToken === '') {
            $this->ensureToken();
        }

        $token = $overrideToken !== null && $overrideToken !== '' ? $overrideToken : $this->accessToken;

        return [
            "x-amz-access-token: {$token}",
            'x-amz-date: ' . gmdate('Ymd\THis\Z'),
            'User-Agent: OzyConnect/1.0 (OziyWeb; PHP)',
        ];
    }

    /**
     * Endpoint URL'ini oluştur
     *
     * @param array<string, string> $params  Placeholder değerleri
     */
    private function buildUrl(string $endpointKey, array $params = []): string
    {
        $endpoint = $this->config['endpoints'][$endpointKey]
            ?? throw new RuntimeException("Amazon endpoint bulunamadı: {$endpointKey}");

        foreach ($params as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', urlencode($value), $endpoint);
        }

        return $this->baseUrl . $endpoint;
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();
            $this->ensureToken();

            // Seller bilgisini çek — bağlantı testi
            $url = $this->buildUrl('sellers');
            $response = $this->httpGet($url, $this->authHeaders());

            $participations = $response['payload'] ?? [];
            $mpFound = false;
            $storeName = '';

            foreach ($participations as $p) {
                $mp = $p['marketplace'] ?? [];
                if (($mp['id'] ?? '') === $this->marketplaceId) {
                    $mpFound = true;
                    $storeName = $mp['name'] ?? 'Amazon TR';
                    break;
                }
            }

            if ($mpFound) {
                return [
                    'success' => true,
                    'message' => "Amazon bağlantısı başarılı — Mağaza: {$storeName}",
                ];
            }

            return [
                'success' => true,
                'message' => 'Amazon bağlantısı başarılı — Seller API erişimi aktif',
            ];

        } catch (\Throwable $e) {
            $this->logger->error('Amazon bağlantı testi başarısız: ' . $e->getMessage(), 'AmazonAPI');
            return [
                'success' => false,
                'message' => 'Amazon bağlantı hatası: ' . $e->getMessage(),
            ];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $createdAfter = $filters['start_date']
            ?? (new \DateTime('-7 days'))->format('Y-m-d\TH:i:s\Z');

        $statusMap = $this->config['status_mapping'] ?? [];
        $result = [];
        $nextToken = null;
        $amazonOrderPage = 0;
        $this->startPaging('amazon.orders');

        // Alıcı adı/adresi PII: yalnız Kısıtlı Veri Jetonu ile gelir.
        // Jeton alınamazsa (rol yoksa) normal jetonla devam edilir.
        $rdt = $this->restrictedDataToken([
            [
                'method'       => 'GET',
                'path'         => '/orders/v0/orders',
                'dataElements' => ['buyerInfo', 'shippingAddress'],
            ],
        ]);

        do {
            if ($nextToken !== null && $nextToken !== '') {
                $url = $this->buildUrl('orders') . '?' . http_build_query(['NextToken' => $nextToken]);
            } else {
                $queryParams = [
                    'MarketplaceIds'    => $this->marketplaceId,
                    'CreatedAfter'      => $createdAfter,
                    'MaxResultsPerPage' => $this->config['api']['page_size'] ?? 100,
                ];

                if (!empty($filters['status'])) {
                    $queryParams['OrderStatuses'] = $filters['status'];
                }

                $url = $this->buildUrl('orders') . '?' . http_build_query($queryParams);
            }

            $response = $this->httpGet($url, $this->authHeaders($rdt));
            $payload = $response['payload'] ?? [];
            $orders = $payload['Orders'] ?? [];

            foreach ($orders as $order) {
                $amzStatus = $order['OrderStatus'] ?? '';
                $items = $this->fetchOrderItems($order['AmazonOrderId']);

                $result[] = [
                    'marketplace_order_id' => $order['AmazonOrderId'] ?? '',
                    'status'               => $statusMap[$amzStatus] ?? 'beklemede',
                    'original_status'      => $amzStatus,
                    'customer_name'        => $this->resolveCustomerName($order),
                    'customer_email'       => $order['BuyerInfo']['BuyerEmail'] ?? '',
                    // Diğer servislerin sözleşmesiyle aynı anahtar seti — eksik anahtar
                    // müşteri oluşturmayı komple düşürüyordu (1.485 sipariş, 2026-08-25).
                    'customer_phone'       => $order['ShippingAddress']['Phone'] ?? '',
                    // Amazon Orders API'si TC/VKN döndürmez — boş bırakılır.
                    // (Buraya bölge kodu gibi bir değer yazmak TÜM müşterileri
                    //  aynı id_no ile eşleştirip tek kayda çökertir.)
                    'customer_id_no'       => '',
                    'customer_tax_office'  => '',
                    // Diğer altı pazaryeri 'total_amount' döndürüyor; OrderSyncService de
                    // bu anahtarı okuyor. 'total_price' yazıldığı için tutar 0 kalıyordu.
                    'total_amount'         => (float) ($order['OrderTotal']['Amount'] ?? 0),
                    'currency'             => $order['OrderTotal']['CurrencyCode'] ?? 'TRY',
                    'order_date'           => $order['PurchaseDate'] ?? '',
                    'shipping_address'     => $this->formatAddress($order['ShippingAddress'] ?? []),
                    'items'                => $items,
                    'raw_data'             => $order,
                ];
            }

            $nextToken = (string) ($payload['NextToken'] ?? '');
        } while ($nextToken !== '' && $this->keepPaging('amazon.orders', ++$amazonOrderPage, $orders));

        return $result;
    }

    /**
     * Sipariş için müşteri adını çöz.
     *
     * Amazon PII rolü yoksa alıcı adı hiç gelmez. Bu durumda TÜM siparişleri
     * "Amazon Musteri" adlı TEK kayda toplamak yerine sipariş numarasıyla ayrık
     * bir ad üretilir; müşteri listesi anlamlı kalır ve sipariş sayıları
     * tek bir sahte müşteride birikmez.
     *
     * @param array<string, mixed> $order
     */
    private function resolveCustomerName(array $order): string
    {
        $ad = trim((string) (
            $order['ShippingAddress']['Name']
            ?? $order['BuyerInfo']['BuyerName']
            ?? ''
        ));

        if ($ad !== '' && $ad !== 'null') {
            return $ad;
        }

        // Ad gizli — sipariş numarasının son bloğuyla ayırt edilebilir kıl
        $siparisNo = (string) ($order['AmazonOrderId'] ?? '');
        $sehir = trim((string) ($order['ShippingAddress']['City'] ?? ''));

        $parcalar = explode('-', $siparisNo);
        $son = end($parcalar) ?: $siparisNo;

        $etiket = 'Amazon Müşterisi';
        if ($son !== '') {
            $etiket .= ' #' . $son;
        }
        if ($sehir !== '' && $sehir !== 'null') {
            $etiket .= ' (' . $sehir . ')';
        }

        return $etiket;
    }

    /**
     * Siparis kalemlerini cek
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrderItems(string $orderId): array
    {
        $result = [];
        $nextToken = null;
        $amazonItemPage = 0;
        $this->startPaging('amazon.items', 40, 30.0);

        $rdt = $this->restrictedDataToken([
            [
                'method'       => 'GET',
                'path'         => '/orders/v0/orders/' . $orderId . '/orderItems',
                'dataElements' => ['buyerInfo'],
            ],
        ]);

        do {
            if ($nextToken !== null && $nextToken !== '') {
                $url = $this->buildUrl('order_items', ['orderId' => $orderId]) . '?' . http_build_query(['NextToken' => $nextToken]);
            } else {
                $url = $this->buildUrl('order_items', ['orderId' => $orderId]);
            }

            $response = $this->httpGet($url, $this->authHeaders($rdt));
            $payload = $response['payload'] ?? [];
            $items = $payload['OrderItems'] ?? [];

            foreach ($items as $item) {
                // Kalem sözleşmesi OrderSyncService ile aynı olmalı: name/qty/line_total.
                // 'title'/'quantity' yazıldığı için ürün adı boş, adet hep 1 kaydediliyordu.
                $adet      = (int) ($item['QuantityOrdered'] ?? $item['QuantityShipped'] ?? 1);
                $satirTutar = (float) ($item['ItemPrice']['Amount'] ?? 0);
                $satirVergi = (float) ($item['ItemTax']['Amount'] ?? 0);

                // Amazon ItemPrice'ı SATIR toplamı olarak verir (adet dahil).
                $birimFiyat = $adet > 0 ? $satirTutar / $adet : $satirTutar;

                // Amazon KDV ORANI değil, KDV TUTARI döndürür — orana çevir.
                $netTutar = $satirTutar - $satirVergi;
                $kdvOrani = $netTutar > 0 ? round(($satirVergi / $netTutar) * 100) : 0.0;

                $result[] = [
                    'marketplace_item_id' => (string) ($item['OrderItemId'] ?? ''),
                    'sku'                 => $item['SellerSKU'] ?? '',
                    'barcode'             => '',
                    'asin'                => $item['ASIN'] ?? '',
                    'name'                => $item['Title'] ?? '',
                    'qty'                 => $adet,
                    'unit_price'          => $birimFiyat,
                    'line_total'          => $satirTutar,
                    'tax_rate'            => $kdvOrani,
                    'discount'            => (float) ($item['PromotionDiscount']['Amount'] ?? 0),
                    'currency'            => $item['ItemPrice']['CurrencyCode'] ?? 'TRY',
                ];
            }

            $nextToken = (string) ($payload['NextToken'] ?? '');
        } while ($nextToken !== '' && $this->keepPaging('amazon.items', ++$amazonItemPage, $items));

        return $result;
    }
    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        // Amazon'da sipariş durumu doğrudan güncellenemez
        // Sadece kargo onayı (shipment confirmation) yapılabilir
        if ($status === 'kargoda' || $status === 'shipped') {
            return $this->updateCargoInfo(
                $orderId,
                $extra['tracking_no'] ?? '',
                $extra['cargo_company'] ?? ''
            );
        }

        $this->logger->warning(
            "Amazon'da sipariş durumu doğrudan güncellenemez: {$status}",
            'AmazonAPI',
            ['orderId' => $orderId]
        );

        return false;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        $this->loadCredentials();

        $url = $this->buildUrl('order_confirm_ship', ['orderId' => $orderId]);

        $data = [
            'marketplaceId' => $this->marketplaceId,
            'packageDetail' => [
                'packageReferenceId' => 'PKG-' . $orderId,
                'carrierCode'        => $this->mapCargoCompany($cargoCompany),
                'trackingNumber'     => $trackingNo,
                'shipDate'           => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];

        try {
            $this->httpPost($url, $data, $this->authHeaders());
            $this->logger->info("Amazon kargo onayı gönderildi: {$orderId}", 'AmazonAPI');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("Amazon kargo onayı başarısız: {$e->getMessage()}", 'AmazonAPI');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        // Amazon'da ürün listesi Reports API üzerinden alınır: rapor SİPARİŞ EDİLİR,
        // Amazon arka planda üretir (çoğu zaman 1-5 dakika), sonra indirilir.
        // Tek bir web isteğinde bitmesi beklenemez; bu yüzden rapor kimliği saklanır
        // ve sonraki denemede AYNI rapor sorgulanır — her denemede yeni rapor açmak
        // hiçbir zaman sonuç vermezdi.
        $reportType = 'GET_MERCHANT_LISTINGS_DATA';

        $reportId = $this->readPendingReport();
        $yeniRapor = false;

        if ($reportId === null) {
            $url = $this->buildUrl('reports');
            $response = $this->httpPost($url, [
                'reportType'     => $reportType,
                'marketplaceIds' => [$this->marketplaceId],
            ], $this->authHeaders());

            $reportId = (string) ($response['reportId'] ?? '');
            if ($reportId === '') {
                throw new RuntimeException('Amazon ürün raporu oluşturulamadı — Reports API rapor kimliği döndürmedi.');
            }

            $this->writePendingReport($reportId);
            $yeniRapor = true;
        }

        // 2. Rapor hazır mı? (istek başına ortak bekleme bütçesi kadar)
        $reportData = $this->waitForReport($reportId);

        if ($reportData === null) {
            // Hazır değil — bu bir HATA DEĞİL, "henüz üretiliyor" durumu.
            // Sessizce 0 ürün dönmek "mağazada ürün yok" gibi görünüyordu.
            throw new RuntimeException(
                'Amazon ürün raporu henüz hazır değil (rapor #' . $reportId . ').'
                . ($yeniRapor ? ' Rapor şimdi sipariş edildi.' : '')
                . ' Amazon bu raporu arka planda üretiyor; birkaç dakika sonra "Ürünler" adımını'
                . ' tekrar çalıştırın — aynı rapor kaldığı yerden sorgulanacak.'
            );
        }

        $this->clearPendingReport();

        if ($reportData === []) {
            return ['products' => [], 'totalPages' => 0, 'totalElements' => 0];
        }

        return [
            'products'      => $reportData,
            'totalPages'    => 1,
            'totalElements' => count($reportData),
        ];
    }

    /**
     * Bekleyen ürün raporunun disk yolu (satıcı bazında).
     *
     * Amazon raporu istekler arası dakikalarca üretilebilir; kimliği saklamazsak
     * her denemede yeni rapor açılır ve hiçbiri tamamlanmadan kullanıcıya "0 ürün"
     * gösterilir.
     */
    private function pendingReportPath(): string
    {
        if (!defined('BASE_PATH') || $this->sellerId === '') {
            return '';
        }

        $dizin = BASE_PATH . '/storage/cache';
        if (!is_dir($dizin)) {
            @mkdir($dizin, 0755, true);
        }
        if (!is_dir($dizin) || !is_writable($dizin)) {
            return '';
        }

        return $dizin . '/az_report_' . substr(sha1($this->sellerId), 0, 16) . '.json';
    }

    /** Bekleyen rapor kimliği (yoksa ya da bayatladıysa null). */
    private function readPendingReport(): ?string
    {
        $yol = $this->pendingReportPath();
        if ($yol === '' || !is_file($yol)) {
            return null;
        }

        $veri = json_decode((string) @file_get_contents($yol), true);
        if (!is_array($veri)) {
            return null;
        }

        // 2 saat içinde bitmediyse rapor ölmüş sayılır, yenisi açılır
        if ((int) ($veri['t'] ?? 0) < time() - 7200) {
            $this->clearPendingReport();
            return null;
        }

        $id = (string) ($veri['reportId'] ?? '');

        return $id !== '' ? $id : null;
    }

    private function writePendingReport(string $reportId): void
    {
        $yol = $this->pendingReportPath();
        if ($yol === '') {
            return;
        }

        @file_put_contents($yol, json_encode(['reportId' => $reportId, 't' => time()]), LOCK_EX);
    }

    private function clearPendingReport(): void
    {
        $yol = $this->pendingReportPath();
        if ($yol !== '' && is_file($yol)) {
            @unlink($yol);
        }
    }

    /**
     * Rapor hazır olana kadar bekle ve indir
     *
     * @return array<int, array<string, mixed>>
     */
    private function waitForReport(string $reportId, int $maxWait = 120): ?array
    {
        $start = time();

        // 120 sn'ye kadar bekleme tek bir web isteğini zaman aşımına düşürüyordu;
        // istek başına ortak bekleme bütçesi ayrıca sınırlar.
        while ((time() - $start) < $maxWait && !$this->pollBudgetExhausted()) {
            $url = $this->buildUrl('report_detail', ['reportId' => $reportId]);
            $report = $this->httpGet($url, $this->authHeaders());

            $status = $report['processingStatus'] ?? '';

            if ($status === 'DONE') {
                $documentId = $report['reportDocumentId'] ?? '';
                if ($documentId !== '') {
                    return $this->downloadReport($documentId);
                }
                return [];
            }

            if ($status === 'CANCELLED' || $status === 'FATAL') {
                $this->logger->error("Amazon rapor oluşturulamadı: {$status}", 'AmazonAPI');
                $this->clearPendingReport();
                return [];
            }

            // 5 saniye bekle ve tekrar kontrol et (bütçe dolduysa bekleme, çık)
            if (!$this->pollWait(5000)) {
                break;
            }
        }

        // null = "henüz hazır değil" (rapor kimliği saklı kalır),
        // [] = "hazır ama boş" — ikisi karıştırılmamalı.
        $this->logger->info("Amazon raporu henüz hazır değil: {$reportId}", 'AmazonAPI');

        return null;
    }

    /**
     * Rapor dökümanını indir ve parse et
     *
     * @return array<int, array<string, mixed>>
     */
    private function downloadReport(string $documentId): array
    {
        $url = $this->buildUrl('report_document', ['reportDocumentId' => $documentId]);
        $docInfo = $this->httpGet($url, $this->authHeaders());

        $downloadUrl = $docInfo['url'] ?? '';
        if ($downloadUrl === '') {
            return [];
        }

        // Rapor dosyasını indir (ayrı URL'den)
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $downloadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $content = curl_exec($ch);
        curl_close($ch);

        if ($content === false) {
            return [];
        }

        $content = (string) $content;

        // Amazon rapor dosyasını GZIP ile sıkıştırıp gönderebilir. Açılmazsa
        // dosya ikili veri olarak TSV sanılır ve TÜM sütunlar boş çıkar.
        $sikistirma = strtoupper((string) ($docInfo['compressionAlgorithm'] ?? ''));
        $gzipBasligi = strncmp($content, "\x1f\x8b", 2) === 0;

        if ($sikistirma === 'GZIP' || $gzipBasligi) {
            $acilan = @gzdecode($content);
            if ($acilan === false) {
                $this->logger->error('Amazon rapor dosyası açılamadı (GZIP)', 'AmazonAPI');
                return [];
            }
            $content = $acilan;
        }

        // Amazon TR raporları çoğunlukla Cp1254/ISO-8859-9 gelir; UTF-8'e çevir.
        $karakterSeti = strtoupper((string) ($docInfo['charset'] ?? ''));
        if ($karakterSeti !== '' && $karakterSeti !== 'UTF-8') {
            $cevrilen = @iconv($karakterSeti, 'UTF-8//TRANSLIT', $content);
            if ($cevrilen !== false) {
                $content = $cevrilen;
            }
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $cevrilen = @iconv('Windows-1254', 'UTF-8//TRANSLIT', $content);
            if ($cevrilen !== false) {
                $content = $cevrilen;
            }
        }

        // BOM ve Windows satır sonlarını temizle
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace("\r\n", "\n", $content);

        // TSV parse
        $lines = explode("\n", $content);
        if (count($lines) < 2) {
            return [];
        }

        $headers = array_map(
            static fn(?string $h): string => strtolower(trim((string) $h, " \t\r\n\0\x0B\xEF\xBB\xBF")),
            str_getcsv(array_shift($lines), "\t")
        );
        $products = [];

        $sutunSayisi = count($headers);

        foreach ($lines as $line) {
            // DİKKAT: trim() satır sonundaki SEKMELERİ de siler. Raporun son
            // sütunu boş olduğu için her satır bir alan eksik kalıyor ve katı
            // alan-sayısı denetimi bütün satırları eliyordu.
            $line = rtrim($line, "\r\n");
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line, "\t");

            // Eksik sütunları boşla tamamla, fazlasını kırp — tek bozuk satır
            // yüzünden tüm rapor kaybolmasın.
            if (count($fields) < $sutunSayisi) {
                $fields = array_pad($fields, $sutunSayisi, '');
            } elseif (count($fields) > $sutunSayisi) {
                $fields = array_slice($fields, 0, $sutunSayisi);
            }

            $row = array_combine($headers, $fields);

            $products[] = $this->normalizeImportProduct($row);
        }

        return $products;
    }

    /**
     * Amazon rapor satırını (GET_MERCHANT_LISTINGS_DATA, TSV) panelin ürün şemasına çevir.
     *
     * Rapor ham sütun adlarıyla gelir (seller-sku, item-name, quantity...). Diğer
     * pazaryerleri normalize edilmiş şema döndürdüğü için Amazon da aynı şemayı
     * döndürmelidir; aksi halde ürünler adsız/fiyatsız içeri girer.
     *
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    private function normalizeImportProduct(array $row): array
    {
        $al = static function (array $r, string ...$anahtarlar): string {
            foreach ($anahtarlar as $a) {
                if (isset($r[$a]) && trim((string) $r[$a]) !== '') {
                    return trim((string) $r[$a]);
                }
            }
            return '';
        };

        $sku      = $al($row, 'seller-sku', 'sku');
        $asin     = $al($row, 'asin1', 'asin');
        $barkod   = $al($row, 'product-id', 'barcode');
        $gorselUrl = $al($row, 'image-url', 'main-image-url');

        $images = [];
        if ($gorselUrl !== '') {
            $images[] = ['url' => $gorselUrl, 'is_cover' => true, 'sort' => 1];
        }

        // product-id-type: 1=ASIN, 2=ISBN, 3=UPC, 4=EAN. ASIN barkod değildir.
        $idTipi = $al($row, 'product-id-type');
        if ($idTipi === '1' || ($barkod !== '' && $barkod === $asin)) {
            $barkod = '';
        }

        $fiyat = (float) str_replace(',', '.', $al($row, 'price') ?: '0');

        return [
            'marketplace_sku'        => $sku,
            'marketplace_product_id' => $asin !== '' ? $asin : $sku,
            'barcode'                => $barkod,
            'name'                   => $al($row, 'item-name', 'title'),
            'description'            => $al($row, 'item-description'),
            'category_name'          => '',
            'category_id'            => '',
            'brand_name'             => $al($row, 'brand-name', 'brand'),
            'brand_id'               => '',
            'price'                  => $fiyat,
            'list_price'             => $fiyat,
            'stock'                  => (int) ($al($row, 'quantity') ?: '0'),
            'group_id'               => $al($row, 'parent-sku') ?: $sku,
            'asin'                   => $asin,
            // GET_MERCHANT_LISTINGS_DATA raporunda status sutunu YOKTUR; bu rapor
            // zaten yalnizca yayindaki ilanlari dondurur. Sutun yoksa aktif sayilir.
            'status'                 => ($d = $al($row, 'status')) === '' ? 'active' : ($d === 'Active' ? 'active' : 'inactive'),
            'attributes'             => [],
            'images'                 => $images,
            'raw'                    => $row,
        ];
    }
    public function updateStock(array $items): array
    {
        $this->loadCredentials();

        // Amazon'da stok güncelleme Feeds API ile yapılır
        $feedItems = [];
        foreach ($items as $item) {
            $feedItems[] = [
                'sku'      => $item['barcode'] ?? $item['sku'] ?? '',
                'quantity' => $item['quantity'],
            ];
        }

        $feedContent = $this->buildInventoryFeed($feedItems);

        try {
            $feedId = $this->submitFeed('POST_INVENTORY_AVAILABILITY_DATA', $feedContent);

            return [
                'success' => count($items),
                'failed'  => 0,
                'errors'  => [],
                'feed_id' => $feedId,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => 0,
                'failed'  => count($items),
                'errors'  => [$e->getMessage()],
            ];
        }
    }

    public function updatePrice(array $items): array
    {
        $this->loadCredentials();

        $feedItems = [];
        foreach ($items as $item) {
            $feedItems[] = [
                'sku'       => $item['barcode'] ?? $item['sku'] ?? '',
                'price'     => $item['salePrice'] ?? $item['price'] ?? 0,
                'listPrice' => $item['listPrice'] ?? null,
            ];
        }

        $feedContent = $this->buildPriceFeed($feedItems);

        try {
            $feedId = $this->submitFeed('POST_PRODUCT_PRICING_DATA', $feedContent);

            return [
                'success' => count($items),
                'failed'  => 0,
                'errors'  => [],
                'feed_id' => $feedId,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => 0,
                'failed'  => count($items),
                'errors'  => [$e->getMessage()],
            ];
        }
    }

    /**
     * Amazon'a varyantlı ürün grubu gönder
     * Parent-Child SKU ilişkisi ile Feeds API üzerinden gönderilir.
     *
     * @param array{items: array, groupId: string, parentSku: string} $variantGroup
     * @return array{success: bool, feed_id: ?string, errors: array}
     */
    /**
     * Ürünleri Amazon'a gönder — SP-API Listings Items
     * PUT /listings/2021-08-01/items/{sellerId}/{sku}?marketplaceIds={marketplaceId}
     *
     * Amazon'da toplu ürün açma uç noktası YOKTUR; her SKU ayrı PUT ile gönderilir.
     * İki mod desteklenir:
     *   ASIN biliniyorsa  → LISTING_OFFER_ONLY (mevcut ürüne satış teklifi = "sen de sat")
     *   ASIN bilinmiyorsa → LISTING (barkod/EAN ile eşleştirme denenir)
     *
     * Amazon'un döndürdüğü "issues" kayıtları OLDUĞU GİBİ aktarılır; hangi alanın
     * eksik olduğunu satıcı tam olarak görür.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success: bool, submissionId?: string, accepted: int, errors: array<int, array<string, string>>}
     */
    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        if ($this->sellerId === '') {
            return ['success' => false, 'accepted' => 0, 'errors' => [['error' => 'Amazon Seller ID girilmemiş — Pazaryerleri > Amazon ayarlarından girin.']]];
        }

        $accepted = 0;
        $sonSubmission = '';
        $errors = [];

        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? $item['stockCode'] ?? ''));
            $ad  = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            $fiyat = (float) ($item['price'] ?? $item['salePrice'] ?? 0);
            $adet  = max(0, (int) ($item['quantity'] ?? 0));
            $asin  = trim((string) ($item['asin'] ?? $item['marketplace_product_id'] ?? ''));
            $barkod = trim((string) ($item['barcode'] ?? ''));

            if ($sku === '') {
                $errors[] = ['error' => 'Stok kodu (SKU) boş olan ürün gönderilemez.'];
                continue;
            }
            if ($fiyat <= 0) {
                $errors[] = ['sku' => $sku, 'error' => 'Satış fiyatı sıfırdan büyük olmalıdır.'];
                continue;
            }
            if ($asin === '' && $barkod === '') {
                $errors[] = ['sku' => $sku, 'error' => 'Amazon için ASIN ya da barkod (EAN/UPC) gereklidir.'];
                continue;
            }

            $pazaryeri = $this->marketplaceId;

            $attributes = [
                'condition_type' => [
                    ['value' => (string) ($item['conditionType'] ?? 'new_new'), 'marketplace_id' => $pazaryeri],
                ],
                'purchasable_offer' => [[
                    'currency'       => (string) ($item['currency'] ?? 'TRY'),
                    'marketplace_id' => $pazaryeri,
                    'our_price'      => [['schedule' => [['value_with_tax' => round($fiyat, 2)]]]],
                ]],
                'fulfillment_availability' => [[
                    'fulfillment_channel_code' => 'DEFAULT',
                    'quantity'                 => $adet,
                ]],
            ];

            if ($asin !== '') {
                // Mevcut ASIN'e teklif — en yaygın ve en az alan isteyen yol
                $requirements = 'LISTING_OFFER_ONLY';
                $attributes['merchant_suggested_asin'] = [
                    ['value' => $asin, 'marketplace_id' => $pazaryeri],
                ];
            } else {
                // Barkodla eşleştirme denemesi — Amazon eksik alanları issues ile bildirir
                $requirements = 'LISTING';
                $attributes['externally_assigned_product_identifier'] = [[
                    'value'          => $barkod,
                    'type'           => strlen($barkod) === 12 ? 'upc' : 'ean',
                    'marketplace_id' => $pazaryeri,
                ]];
                if ($ad !== '') {
                    $attributes['item_name'] = [['value' => mb_substr($ad, 0, 200), 'marketplace_id' => $pazaryeri]];
                }
                $marka = trim((string) ($item['brand'] ?? ''));
                if ($marka !== '') {
                    $attributes['brand'] = [['value' => $marka, 'marketplace_id' => $pazaryeri]];
                }
            }

            $url = $this->buildUrl('listing_put', ['sellerId' => $this->sellerId, 'sku' => $sku])
                . '?' . http_build_query(['marketplaceIds' => $pazaryeri, 'issueLocale' => 'tr_TR']);

            try {
                $response = $this->httpPut($url, [
                    'productType'  => (string) ($item['productType'] ?? 'PRODUCT'),
                    'requirements' => $requirements,
                    'attributes'   => $attributes,
                ], $this->authHeaders());
            } catch (\Throwable $e) {
                $errors[] = ['sku' => $sku, 'error' => mb_substr($e->getMessage(), 0, 220)];
                continue;
            }

            $durum = strtoupper((string) ($response['status'] ?? ''));
            $sonSubmission = (string) ($response['submissionId'] ?? $sonSubmission);

            if ($durum === 'ACCEPTED') {
                $accepted++;
                continue;
            }

            // Amazon'un gerekçelerini birebir aktar
            $gerekce = [];
            foreach ((array) ($response['issues'] ?? []) as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $gerekce[] = trim(
                    (string) ($issue['code'] ?? '') . ': ' . (string) ($issue['message'] ?? '')
                    . (!empty($issue['attributeNames']) ? ' [' . implode(', ', (array) $issue['attributeNames']) . ']' : '')
                );
            }

            $errors[] = [
                'sku'   => $sku,
                'error' => $gerekce !== []
                    ? implode(' | ', array_slice($gerekce, 0, 3))
                    : ('Amazon listeleme reddedildi (durum: ' . ($durum !== '' ? $durum : 'bilinmiyor') . ')'),
            ];
        }

        $this->logger->info(
            sprintf('Amazon listeleme: %d kabul, %d hatalı', $accepted, count($errors)),
            'AmazonAPI'
        );

        return [
            'success'      => $accepted > 0,
            'submissionId' => $sonSubmission,
            'accepted'     => $accepted,
            'errors'       => $errors,
        ];
    }

    public function createVariantProducts(array $variantGroup): array
    {
        $this->loadCredentials();

        $parentSku = htmlspecialchars($variantGroup['parentSku'] ?? $variantGroup['groupId'], ENT_XML1);
        $messages = '';
        $seq = 1;

        // Parent ürün mesajı
        $messages .= <<<XML
        <Message>
            <MessageID>{$seq}</MessageID>
            <OperationType>Update</OperationType>
            <Relationship>
                <ParentSKU>{$parentSku}</ParentSKU>
            </Relationship>
        </Message>
XML;

        // Child ürün mesajları
        foreach ($variantGroup['items'] ?? [] as $item) {
            $seq++;
            $childSku = htmlspecialchars($item['stockCode'] ?? $item['barcode'], ENT_XML1);
            $messages .= <<<XML
        <Message>
            <MessageID>{$seq}</MessageID>
            <OperationType>Update</OperationType>
            <Relationship>
                <ParentSKU>{$parentSku}</ParentSKU>
                <Relation>
                    <SKU>{$childSku}</SKU>
                    <Type>Variation</Type>
                </Relation>
            </Relationship>
        </Message>
XML;
        }

        $feedContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
    <Header>
        <DocumentVersion>1.01</DocumentVersion>
        <MerchantIdentifier>{$this->sellerId}</MerchantIdentifier>
    </Header>
    <MessageType>Relationship</MessageType>
    {$messages}
</AmazonEnvelope>
XML;

        try {
            $feedId = $this->submitFeed('POST_PRODUCT_RELATIONSHIP_DATA', $feedContent);
            return ['success' => true, 'feed_id' => $feedId, 'errors' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'feed_id' => null, 'errors' => [['error' => $e->getMessage()]]];
        }
    }

    public function importProducts(int $page = 0, int $size = 100): array
    {
        return $this->fetchProducts($page, $size);
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        // Amazon'da Product Type Definitions kullanılır
        $url = $this->buildUrl('product_types') . '?' . http_build_query([
            'marketplaceIds' => $this->marketplaceId,
        ]);

        try {
            $response = $this->httpGet($url, $this->authHeaders());
            $types = $response['productTypes'] ?? [];
            $result = [];

            foreach ($types as $i => $type) {
                $result[] = [
                    'id'        => $type['name'] ?? '',
                    'name'      => $type['displayName'] ?? $type['name'] ?? '',
                    'parent_id' => 0,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Amazon kategori çekme hatası: {$e->getMessage()}", 'AmazonAPI');
            return [];
        }
    }

    public function fetchBrands(string $search = ''): array
    {
        // Amazon'da ayrı bir brand API yok
        // Marka bilgisi listing attribute olarak yönetilir
        return [];
    }

    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl('product_type_def', ['productType' => $marketplaceCategoryId])
            . '?' . http_build_query([
                'marketplaceIds'   => $this->marketplaceId,
                'sellerId'         => $this->sellerId,
                'requirements'     => 'LISTING',
                'locale'           => 'tr_TR',
            ]);

        try {
            $response = $this->httpGet($url, $this->authHeaders());
            $schema = $response['schema'] ?? [];
            $properties = $schema['properties'] ?? [];
            $required = $schema['required'] ?? [];
            $result = [];

            foreach ($properties as $key => $prop) {
                $result[] = [
                    'id'          => $key,
                    'name'        => $prop['title'] ?? $key,
                    'required'    => in_array($key, $required, true),
                    'allowCustom' => true,
                    'values'      => $prop['enum'] ?? [],
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Amazon kategori özellik hatası: {$e->getMessage()}", 'AmazonAPI');
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        // Amazon'da iadeler Reports API ile çekilir
        // GET_FBA_RETURNS_DATA veya GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE
        $reportType = 'GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE';

        $url = $this->buildUrl('reports');
        try {
            $response = $this->httpPost($url, [
                'reportType'     => $reportType,
                'marketplaceIds' => [$this->marketplaceId],
                'dataStartTime'  => $filters['start_date']
                    ?? (new \DateTime('-30 days'))->format('Y-m-d\TH:i:s\Z'),
                'dataEndTime'    => $filters['end_date']
                    ?? (new \DateTime())->format('Y-m-d\TH:i:s\Z'),
            ], $this->authHeaders());

            $reportId = $response['reportId'] ?? '';
            if ($reportId === '') {
                return [];
            }

            $data = $this->waitForReport($reportId, 60);
            return $data;
        } catch (\Throwable $e) {
            $this->logger->error("Amazon iade çekme hatası: {$e->getMessage()}", 'AmazonAPI');
            return [];
        }
    }

    public function approveReturn(string $returnId): bool
    {
        // Amazon'da iade otomatik işlenir — seller tarafında onay yok
        $this->logger->info("Amazon iadesi otomatik onaylıdır: {$returnId}", 'AmazonAPI');
        return true;
    }

    public function rejectReturn(string $returnId, string $reason): bool
    {
        // Amazon'da iade reddi Seller Central üzerinden yapılır
        $this->logger->warning(
            "Amazon iade reddi API'den yapılamaz — Seller Central'dan yapılmalı: {$returnId}",
            'AmazonAPI'
        );
        return false;
    }

    // ═══════════════════════════════════════════════════════
    // FEED YARDIMCILARI
    // ═══════════════════════════════════════════════════════

    /**
     * Feed gönder ve feed ID döndür
     */
    private function submitFeed(string $feedType, string $content): string
    {
        // 1. Feed dökümanı oluştur
        $url = $this->buildUrl('feed_document');
        $docResponse = $this->httpPost($url, [
            'contentType' => 'text/xml; charset=UTF-8',
        ], $this->authHeaders());

        $feedDocumentId = $docResponse['feedDocumentId'] ?? '';
        $uploadUrl = $docResponse['url'] ?? '';

        if ($feedDocumentId === '' || $uploadUrl === '') {
            throw new RuntimeException('Amazon feed dökümanı oluşturulamadı');
        }

        // 2. İçeriği upload et
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $uploadUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=UTF-8',
            ],
        ]);

        $uploadResult = curl_exec($ch);
        $uploadCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($uploadCode < 200 || $uploadCode >= 300) {
            throw new RuntimeException("Amazon feed upload başarısız (HTTP {$uploadCode})");
        }

        // 3. Feed oluştur
        $feedUrl = $this->buildUrl('feeds');
        $feedResponse = $this->httpPost($feedUrl, [
            'feedType'            => $feedType,
            'marketplaceIds'      => [$this->marketplaceId],
            'inputFeedDocumentId' => $feedDocumentId,
        ], $this->authHeaders());

        return $feedResponse['feedId'] ?? '';
    }

    /**
     * Stok feed XML'i oluştur
     *
     * @param array<int, array{sku: string, quantity: int}> $items
     */
    private function buildInventoryFeed(array $items): string
    {
        $messages = '';
        foreach ($items as $i => $item) {
            $seq = $i + 1;
            $sku = htmlspecialchars($item['sku'], ENT_XML1);
            $qty = (int) $item['quantity'];

            $messages .= <<<XML
        <Message>
            <MessageID>{$seq}</MessageID>
            <OperationType>Update</OperationType>
            <Inventory>
                <SKU>{$sku}</SKU>
                <Quantity>{$qty}</Quantity>
                <FulfillmentLatency>2</FulfillmentLatency>
            </Inventory>
        </Message>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
    <Header>
        <DocumentVersion>1.01</DocumentVersion>
        <MerchantIdentifier>{$this->sellerId}</MerchantIdentifier>
    </Header>
    <MessageType>Inventory</MessageType>
    {$messages}
</AmazonEnvelope>
XML;
    }

    /**
     * Fiyat feed XML'i oluştur
     *
     * @param array<int, array{sku: string, price: float, listPrice: float|null}> $items
     */
    private function buildPriceFeed(array $items): string
    {
        $messages = '';
        foreach ($items as $i => $item) {
            $seq = $i + 1;
            $sku = htmlspecialchars($item['sku'], ENT_XML1);
            $price = number_format((float) $item['price'], 2, '.', '');

            $salePart = '';
            if (!empty($item['listPrice']) && (float) $item['listPrice'] > (float) $item['price']) {
                $listPrice = number_format((float) $item['listPrice'], 2, '.', '');
                $salePart = <<<XML

                <Sale>
                    <StartDate>2020-01-01T00:00:00Z</StartDate>
                    <EndDate>2030-12-31T23:59:59Z</EndDate>
                    <SalePrice currency="TRY">{$price}</SalePrice>
                </Sale>
XML;
                $price = $listPrice;
            }

            $messages .= <<<XML
        <Message>
            <MessageID>{$seq}</MessageID>
            <Price>
                <SKU>{$sku}</SKU>
                <StandardPrice currency="TRY">{$price}</StandardPrice>{$salePart}
            </Price>
        </Message>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
    <Header>
        <DocumentVersion>1.01</DocumentVersion>
        <MerchantIdentifier>{$this->sellerId}</MerchantIdentifier>
    </Header>
    <MessageType>Price</MessageType>
    {$messages}
</AmazonEnvelope>
XML;
    }

    // ═══════════════════════════════════════════════════════
    // YARDIMCI FONKSİYONLAR
    // ═══════════════════════════════════════════════════════

    /**
     * Amazon adres formatı → tek satır
     *
     * @param array<string, string> $addr
     */
    private function formatAddress(array $addr): string
    {
        $parts = array_filter([
            $addr['AddressLine1'] ?? '',
            $addr['AddressLine2'] ?? '',
            $addr['AddressLine3'] ?? '',
            $addr['City'] ?? '',
            $addr['StateOrRegion'] ?? '',
            $addr['PostalCode'] ?? '',
            $addr['CountryCode'] ?? '',
        ]);

        return implode(', ', $parts);
    }

    /**
     * Kargo şirketi adını Amazon carrier code'a çevir
     */
    private function mapCargoCompany(string $company): string
    {
        $map = [
            'yurtici'   => 'Yurtici Kargo',
            'aras'      => 'Aras Kargo',
            'mng'       => 'MNG Kargo',
            'ptt'       => 'PTT Kargo',
            'ups'       => 'UPS',
            'fedex'     => 'FedEx',
            'dhl'       => 'DHL',
            'tnt'       => 'TNT',
            'surat'     => 'Surat Kargo',
            'hepsijet'  => 'Other',
            'sendeo'    => 'Other',
        ];

        $key = strtolower(trim($company));
        return $map[$key] ?? $company;
    }
}
