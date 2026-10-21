<?php
/**
 * OzyConnect Panel — IdefixService (İdefix REST API Entegrasyonu)
 *
 * İdefix Entegrasyon API (REST):
 *  - PIM Base URL: https://merchantapi.idefix.com/pim
 *  - OMS Base URL: https://merchantapi.idefix.com/oms
 *  - Auth: Header — X-API-KEY: base64_encode(ApiKey:ApiSecret)
 *  - Ürün: PIM endpointleri (kategori, marka, stok/fiyat, ürün CRUD)
 *  - Sipariş: OMS endpointleri (shipment list, statü güncelleme, kargo kodu)
 *  - İade: OMS endpointleri (iade listesi, onay/ret)
 *
 * Rate Limit: X-Api-Call-Limit header'ı kontrol edilir, 429 durumunda Retry-After bekle
 *
 * @see https://developer.idefix.com
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

final class IdefixService extends AbstractMarketplace
{
    private const SLUG = 'idefix';
    private const PIM_BASE_URL = 'https://merchantapi.idefix.com/pim';
    private const OMS_BASE_URL = 'https://merchantapi.idefix.com/oms';

    private string $apiKey = '';
    private string $apiSecret = '';
    private string $vendorId = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;

    /** Shipment statü mapping (İdefix API → sistem) */
    private const ORDER_STATUS_MAP = [
        'created'              => 'yeni',
        'shipment_ready'       => 'yeni',
        'shipment_picking'     => 'hazirlaniyor',
        'shipment_invoiced'    => 'faturalandi',
        'shipment_in_cargo'    => 'kargoda',
        'shipment_delivered'   => 'teslim_edildi',
        'shipment_undeliver'   => 'teslim_edilemedi',
        'shipment_cancelled'   => 'iptal',
        'shipment_unsupplied'  => 'iptal',
        'shipment_split'       => 'bolundu',
        'shipment_approved'    => 'teslim_edildi',
    ];

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->baseUrl            = self::PIM_BASE_URL;
        $this->timeout            = (int) ($this->config['api']['timeout_seconds'] ?? 30);
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
            throw new RuntimeException('İdefix pazaryeri kaydı bulunamadı');
        }

        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
        $this->vendorId    = (string) ($this->extraConfig['vendor_id'] ?? '');

        if (empty($marketplace['api_key_encrypted'])) {
            throw new RuntimeException('İdefix API bilgileri henüz girilmemiş. Pazaryerleri > İdefix > Ayarlar\'dan Token ve Secret Key girin.');
        }

        $this->apiKey    = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->apiSecret = !empty($marketplace['api_secret_encrypted'])
            ? $this->crypto->decrypt($marketplace['api_secret_encrypted'])
            : '';

        if ($this->vendorId === '') {
            throw new RuntimeException('İdefix Vendor ID (Satıcı ID) girilmemiş. Pazaryerleri > İdefix > Ayarlar\'dan Vendor ID girin.');
        }
    }

    /**
     * İdefix REST API header'ları
     * VENDOR TOKEN = base64_encode(Token:Secret) → X-API-KEY header
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        $token = base64_encode($this->apiKey . ':' . $this->apiSecret);

        return [
            'X-API-KEY: ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();

            // Kategori listesi çek — hafif bir endpoint
            $url = self::PIM_BASE_URL . '/product-category';
            $result = $this->httpGet($url, $this->authHeaders());

            $catCount = is_array($result) ? count($result) : 0;

            return [
                'success' => true,
                'message' => "İdefix bağlantısı başarılı! {$catCount} ana kategori bulundu.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("İdefix bağlantı testi hatası: " . $e->getMessage(), 'IdefixAPI');
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
     * Sipariş (Shipment) listesi çek
     *
     * GET https://merchantapi.idefix.com/oms/{vendorId}/list
     */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $allOrders = [];
        $page = 1;
        $hasMore = true;

        while ($hasMore) {
            $params = [
                'page'   => $page,
                'limit'  => 20,
            ];

            if (!empty($filters['state'])) {
                $params['state'] = $filters['state'];
            }
            if (!empty($filters['start_date'])) {
                $params['startDate'] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $params['endDate'] = $filters['end_date'];
            }
            if (!empty($filters['lastUpdatedAt'])) {
                $params['lastUpdatedAt'] = $filters['lastUpdatedAt'];
            }

            $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/list?' . http_build_query($params);
            $result = $this->httpGet($url, $this->authHeaders());

            $items = $result['items'] ?? [];
            $pageCount = (int) ($result['pageCount'] ?? 1);

            foreach ($items as $item) {
                $allOrders[] = $this->normalizeOrder($item);
            }

            $page++;
            $hasMore = $page <= $pageCount;
        }

        $this->logger->debug('İdefix sipariş çekildi', 'IdefixAPI', [
            'count' => count($allOrders),
        ]);

        return $allOrders;
    }

    /**
     * İdefix shipment verisini normalize et
     */
    private function normalizeOrder(array $raw): array
    {
        $status = self::ORDER_STATUS_MAP[$raw['status'] ?? ''] ?? 'yeni';

        $customerName = trim((string) ($raw['customerContactName'] ?? ''));

        // Ürün bilgileri
        $normalizedItems = [];
        foreach ($raw['items'] ?? [] as $item) {
            $normalizedItems[] = [
                'marketplace_item_id' => (string) ($item['id'] ?? ''),
                'sku'                 => (string) ($item['merchantSku'] ?? $item['barcode'] ?? ''),
                'barcode'             => (string) ($item['barcode'] ?? ''),
                'name'                => (string) ($item['productName'] ?? ''),
                'qty'                 => 1,
                'unit_price'          => (float) ($item['price'] ?? 0),
                'discount'            => (float) ($item['totalDiscount'] ?? 0),
                'tax_rate'            => (float) ($item['vatRate'] ?? 0),
                'line_total'          => (float) ($item['discountedTotalPrice'] ?? $item['price'] ?? 0),
            ];
        }

        // Adres bilgileri
        $invoiceAddr = $raw['invoiceAddress'] ?? [];
        $shippingAddr = $raw['shippingAddress'] ?? [];

        $orderedAt = null;
        if (!empty($raw['orderDate'])) {
            $ts = strtotime($raw['orderDate']);
            $orderedAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        }

        $totalPrice = (float) ($raw['totalPrice'] ?? 0);
        $totalDiscount = (float) ($raw['totalDiscount'] ?? 0);
        $discountedTotal = (float) ($raw['discountedTotalPrice'] ?? $totalPrice);

        return [
            'marketplace_order_id'  => (string) ($raw['orderNumber'] ?? $raw['id'] ?? ''),
            'marketplace_shipment_id' => (string) ($raw['id'] ?? ''),
            'status'                => $status,
            'status_raw'            => (string) ($raw['status'] ?? ''),
            'customer_name'         => $customerName,
            'customer_email'        => (string) ($raw['customerContactMail'] ?? ''),
            'customer_phone'        => (string) ($shippingAddr['phone'] ?? $invoiceAddr['phone'] ?? ''),
            'customer_id_no'        => (string) ($invoiceAddr['identificationNumber'] ?? ''),
            'customer_tax_office'   => (string) ($invoiceAddr['taxOffice'] ?? ''),
            'notes'                 => '',
            'billing_address'       => [
                'name'        => (string) ($invoiceAddr['fullName'] ?? $customerName),
                'address'     => (string) ($invoiceAddr['fullAddress'] ?? $invoiceAddr['address1'] ?? ''),
                'city'        => (string) ($invoiceAddr['city'] ?? ''),
                'district'    => (string) ($invoiceAddr['county'] ?? ''),
                'postal_code' => (string) ($invoiceAddr['postalCode'] ?? ''),
                'company'     => (string) ($invoiceAddr['company'] ?? ''),
                'tax_office'  => (string) ($invoiceAddr['taxOffice'] ?? ''),
                'tax_number'  => (string) ($invoiceAddr['taxNumber'] ?? ''),
            ],
            'shipping_address'      => [
                'name'         => (string) ($shippingAddr['fullName'] ?? $customerName),
                'address'      => (string) ($shippingAddr['fullAddress'] ?? $shippingAddr['address1'] ?? ''),
                'city'         => (string) ($shippingAddr['city'] ?? ''),
                'district'     => (string) ($shippingAddr['county'] ?? ''),
                'postal_code'  => (string) ($shippingAddr['postalCode'] ?? ''),
                'phone'        => (string) ($shippingAddr['phone'] ?? ''),
                'neighborhood' => (string) ($shippingAddr['neighboorhood'] ?? ''),
            ],
            'subtotal'              => round($totalPrice, 2),
            'tax_total'             => 0.0,
            'discount_total'        => round($totalDiscount, 2),
            'shipping_total'        => 0.0,
            'total_amount'          => round($discountedTotal, 2),
            'currency'              => 'TRY',
            'payment_method'        => '',
            'cargo_company'         => (string) ($raw['cargoCompany'] ?? ''),
            'cargo_tracking_no'     => (string) ($raw['cargoTrackingNumber'] ?? ''),
            'cargo_tracking_link'   => (string) ($raw['cargoTrackingUrl'] ?? ''),
            'cargo_sent_at'         => null,
            'ordered_at'            => $orderedAt,
            'items'                 => $normalizedItems,
            'raw_data'              => $raw,
        ];
    }

    /**
     * Shipment statü güncelle (picking / invoiced)
     *
     * POST https://merchantapi.idefix.com/oms/{vendorId}/{shipmentId}/update-shipment-status
     */
    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();

        // Sistem statülerini İdefix statülerine çevir
        $statusMap = [
            'hazirlaniyor' => 'picking',
            'faturalandi'  => 'invoiced',
        ];

        $idefixStatus = $statusMap[$status] ?? $status;

        $payload = [
            'status' => $idefixStatus,
        ];

        if (!empty($extra['invoice_number'])) {
            $payload['invoiceNumber'] = $extra['invoice_number'];
        }

        try {
            $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/' . $orderId . '/update-shipment-status';
            $this->httpPost($url, $payload, $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("İdefix statü güncelleme hatası: " . $e->getMessage(), 'IdefixAPI');
            return false;
        }
    }

    /**
     * Kargo kodu bildir — shipment otomatik olarak shipment_in_cargo olur
     *
     * POST https://merchantapi.idefix.com/oms/{vendorId}/{shipmentId}/update-tracking-number
     */
    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        $this->loadCredentials();

        $payload = [
            'trackingNumber' => $trackingNo,
            'trackingUrl'    => $this->resolveCargoTrackingUrl($cargoCompany, $trackingNo),
        ];

        try {
            $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/' . $orderId . '/update-tracking-number';
            $this->httpPost($url, $payload, $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("İdefix kargo güncelleme hatası: " . $e->getMessage(), 'IdefixAPI');
            return false;
        }
    }

    /**
     * Kargo firmasına göre takip URL'si oluştur
     */
    private function resolveCargoTrackingUrl(string $cargoCompany, string $trackingNo): string
    {
        $company = mb_strtolower(trim($cargoCompany));

        $urlMap = [
            'yurtiçi'  => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . $trackingNo,
            'yurtici'  => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . $trackingNo,
            'aras'     => 'https://www.araskargo.com.tr/taki.php?code=' . $trackingNo,
            'mng'      => 'https://www.mngkargo.com.tr/gonderi-takip?code=' . $trackingNo,
            'ptt'      => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q=' . $trackingNo,
            'sürat'    => 'https://www.suratkargo.com.tr/gonderi-takip?code=' . $trackingNo,
            'surat'    => 'https://www.suratkargo.com.tr/gonderi-takip?code=' . $trackingNo,
            'ups'      => 'https://www.ups.com/track?tracknum=' . $trackingNo,
            'sendeo'   => 'https://www.sendeo.com.tr/gonderi-takip?code=' . $trackingNo,
            'horoz'    => 'https://www.horozlojistik.com.tr/gonderi-takip?code=' . $trackingNo,
            'tex'      => 'https://www.texkargo.com/gonderi-takip?code=' . $trackingNo,
        ];

        foreach ($urlMap as $key => $url) {
            if (str_contains($company, $key)) {
                return $url;
            }
        }

        return 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . $trackingNo;
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Ürünlerim listesi çek
     *
     * GET https://merchantapi.idefix.com/pim/pool/{vendorId}/list
     */
    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        // İdefix page 1'den başlar
        $idefixPage = $page + 1;

        $url = self::PIM_BASE_URL . '/pool/' . $this->vendorId . '/list?' . http_build_query([
            'page'  => $idefixPage,
            'limit' => min($size, 100),
        ]);

        $result = $this->httpGet($url, $this->authHeaders());

        $products = $result['products'] ?? [];
        $totalCount = (int) ($result['totalCount'] ?? count($products));

        return [
            'products'      => $products,
            'totalPages'    => $totalCount > 0 ? (int) ceil($totalCount / max(1, $size)) : 0,
            'totalElements' => $totalCount,
        ];
    }

    /**
     * Stok ve fiyat güncelle
     *
     * POST https://merchantapi.idefix.com/pim/catalog/{vendorId}/inventory-upload
     */
    public function updateStock(array $items): array
    {
        $this->loadCredentials();

        $success = 0;
        $failed = 0;
        $errors = [];

        // Batch'ler halinde gönder
        $batches = array_chunk($items, 100);

        foreach ($batches as $batch) {
            $payload = ['items' => []];

            foreach ($batch as $item) {
                $barcode = (string) ($item['barcode'] ?? '');
                if ($barcode === '') continue;

                $payload['items'][] = [
                    'barcode'            => $barcode,
                    'inventoryQuantity'  => (int) ($item['quantity'] ?? 0),
                    'deliveryDuration'   => (int) ($item['delivery_duration'] ?? 1),
                    'deliveryType'       => (string) ($item['delivery_type'] ?? 'regular'),
                ];
            }

            if (empty($payload['items'])) continue;

            try {
                $url = self::PIM_BASE_URL . '/catalog/' . $this->vendorId . '/inventory-upload';
                $result = $this->httpPost($url, $payload, $this->authHeaders());

                $batchId = $result['batchRequestId'] ?? null;
                $success += count($payload['items']);

                $this->logger->info("İdefix stok güncelleme gönderildi", 'IdefixAPI', [
                    'batchRequestId' => $batchId,
                    'count'          => count($payload['items']),
                ]);

            } catch (\Throwable $e) {
                $failed += count($payload['items']);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Fiyat güncelle (inventory-upload ile birlikte)
     *
     * POST https://merchantapi.idefix.com/pim/catalog/{vendorId}/inventory-upload
     */
    public function updatePrice(array $items): array
    {
        $this->loadCredentials();

        $success = 0;
        $failed = 0;
        $errors = [];

        $batches = array_chunk($items, 100);

        foreach ($batches as $batch) {
            $payload = ['items' => []];

            foreach ($batch as $item) {
                $barcode = (string) ($item['barcode'] ?? '');
                if ($barcode === '') continue;

                $entry = [
                    'barcode'       => $barcode,
                    'deliveryDuration' => (int) ($item['delivery_duration'] ?? 1),
                    'deliveryType'  => (string) ($item['delivery_type'] ?? 'regular'),
                ];

                if (!empty($item['salePrice'])) {
                    $entry['price'] = (float) $item['salePrice'];
                } elseif (!empty($item['listPrice'])) {
                    $entry['price'] = (float) $item['listPrice'];
                }

                if (!empty($item['listPrice'])) {
                    $entry['comparePrice'] = (float) $item['listPrice'];
                }

                $payload['items'][] = $entry;
            }

            if (empty($payload['items'])) continue;

            try {
                $url = self::PIM_BASE_URL . '/catalog/' . $this->vendorId . '/inventory-upload';
                $result = $this->httpPost($url, $payload, $this->authHeaders());

                $batchId = $result['batchRequestId'] ?? null;
                $success += count($payload['items']);

                $this->logger->info("İdefix fiyat güncelleme gönderildi", 'IdefixAPI', [
                    'batchRequestId' => $batchId,
                    'count'          => count($payload['items']),
                ]);

            } catch (\Throwable $e) {
                $failed += count($payload['items']);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * İdefix'e varyantlı ürün grubu gönder
     * Aynı productMainId ile gönderilen ürünler tek kart altında gruplanır.
     *
     * @param array{items: array, groupId: string} $variantGroup
     * @return array{success: bool, errors: array}
     */
    /**
     * Ürünleri İdefix'e gönder — "Hızlı Ürün Ekleme"
     * POST https://merchantapi.idefix.com/pim/catalog/{vendorId}/fast-listing
     *
     * Barkodu İdefix kataloğunda bulunan ürünler doğrudan satışa açılır; katalogda
     * eşleşme yoksa ürün "eşleşmedi" olarak döner ve İdefix panelinden tamamlanır.
     * Yanıttaki batchRequestId ile durum sorgulanır (getBatchStatus).
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success: bool, batchRequestId?: string, status?: string, errors: array<int, array<string, string>>}
     */
    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        $gonderilecek = [];
        $errors = [];

        foreach ($items as $item) {
            $barcode = trim((string) ($item['barcode'] ?? ''));
            $title   = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            $stokKod = trim((string) ($item['vendorStockCode'] ?? $item['stockCode'] ?? $item['sku'] ?? ''));
            $fiyat   = (float) ($item['price'] ?? $item['salePrice'] ?? 0);
            $adet    = (int) ($item['inventoryQuantity'] ?? $item['quantity'] ?? 0);

            // İdefix hızlı ürün eklemede barkod ZORUNLU — katalog eşleşmesi buna dayanır
            if ($barcode === '' || $title === '' || $stokKod === '') {
                $errors[] = [
                    'barcode' => $barcode,
                    'error'   => 'İdefix hızlı ürün ekleme için barkod, ürün adı ve stok kodu zorunludur.',
                ];
                continue;
            }
            if ($fiyat <= 0) {
                $errors[] = ['barcode' => $barcode, 'error' => 'Satış fiyatı sıfırdan büyük olmalıdır.'];
                continue;
            }

            $satir = [
                'barcode'           => $barcode,
                'title'             => mb_substr($title, 0, 250),
                'vendorStockCode'   => $stokKod,
                'price'             => round($fiyat, 2),
                'inventoryQuantity' => max(0, $adet),
            ];

            $karsilastirma = (float) ($item['comparePrice'] ?? $item['listPrice'] ?? 0);
            if ($karsilastirma > 0) {
                $satir['comparePrice'] = round($karsilastirma, 2);
            }

            $gonderilecek[] = $satir;
        }

        if ($gonderilecek === []) {
            return ['success' => false, 'errors' => $errors ?: [['error' => 'Gönderilecek geçerli ürün yok.']]];
        }

        // $this->baseUrl kurucuda PIM_BASE_URL'e eşitlenir; sabit yerine bunu kullanmak
        // üretimde aynı adresi verir, testte adresin yönlendirilebilmesini sağlar.
        $url = $this->baseUrl . '/catalog/' . $this->vendorId . '/fast-listing';

        try {
            $response = $this->httpPost($url, ['items' => $gonderilecek], $this->authHeaders());
        } catch (\Throwable $e) {
            $this->logger->error('İdefix hızlı ürün ekleme hatası: ' . $e->getMessage(), 'IdefixService');
            return ['success' => false, 'errors' => array_merge($errors, [['error' => $e->getMessage()]])];
        }

        $batchId = trim((string) ($response['batchRequestId'] ?? ''));

        $this->logger->info(
            sprintf('İdefix hızlı ürün ekleme: %d ürün gönderildi (batch: %s)', count($gonderilecek), $batchId !== '' ? $batchId : '-'),
            'IdefixService'
        );

        return [
            'success'        => true,
            'batchRequestId' => $batchId,
            'status'         => (string) ($response['status'] ?? ''),
            'errors'         => $errors,
        ];
    }

    public function createVariantProducts(array $variantGroup): array
    {
        // İdefix'te varyant = aynı productMainId ile inventory-upload
        $items = [];
        foreach ($variantGroup['items'] ?? [] as $item) {
            $items[] = [
                'barcode'   => $item['barcode'],
                'quantity'  => (int) ($item['quantity'] ?? 0),
                'salePrice' => (float) ($item['salePrice'] ?? 0),
            ];
        }
        return $this->updateStock($items);
    }

    /**
     * Ürünleri import et (normalize edilmiş formatta)
     */
    public function importProducts(int $page = 0, int $size = 100): array
    {
        $response = $this->fetchProducts($page, $size);

        $rawProducts = $response['products'] ?? [];
        $products = [];

        foreach ($rawProducts as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $products[] = $this->normalizeImportProduct($raw);
        }

        return [
            'products'      => $products,
            'totalPages'    => (int) ($response['totalPages'] ?? 0),
            'totalElements' => (int) ($response['totalElements'] ?? 0),
        ];
    }

    /**
     * İdefix ürün verisini import formatına normalize et
     */
    private function normalizeImportProduct(array $raw): array
    {
        $images = [];
        foreach ($raw['images'] ?? [] as $i => $img) {
            $url = '';
            if (is_string($img)) {
                $url = $img;
            } elseif (is_array($img)) {
                $url = (string) ($img['url'] ?? '');
            }
            if ($url === '') continue;

            $images[] = [
                'url'      => $url,
                'is_cover' => $i === 0,
                'sort'     => $i + 1,
            ];
        }

        $attributes = [];
        foreach ($raw['attributes'] ?? [] as $attr) {
            if (!is_array($attr)) continue;

            $attributes[] = [
                'name'  => 'attribute_' . ($attr['attributeId'] ?? ''),
                'value' => (string) ($attr['customAttributeValue'] ?? $attr['attributeValueId'] ?? ''),
            ];
        }

        $normalized = [
            'marketplace_sku'        => (string) ($raw['vendorStockCode'] ?? $raw['barcode'] ?? ''),
            'marketplace_product_id' => (string) ($raw['productMainId'] ?? ''),
            'barcode'                => (string) ($raw['barcode'] ?? ''),
            'name'                   => (string) ($raw['title'] ?? ''),
            'category_name'          => '',
            'category_id'            => (string) ($raw['categoryId'] ?? ''),
            'brand_name'             => (string) ($raw['brandName'] ?? ''),
            'brand_id'               => (string) ($raw['brandId'] ?? ''),
            'price'                  => (float) ($raw['price'] ?? 0),
            'list_price'             => (float) ($raw['comparePrice'] ?? 0),
            'currency'               => 'TRY',
            'is_active'              => ($raw['status'] ?? '') !== 'not_matched',
            'images'                 => $images,
            'attributes'             => $attributes,
            'raw'                    => $raw,
        ];

        if (array_key_exists('inventoryQuantity', $raw)) {
            $normalized['stock'] = (int) $raw['inventoryQuantity'];
        }

        return $normalized;
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /**
     * Kategori listesi çek
     *
     * GET https://merchantapi.idefix.com/pim/product-category
     */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        $url = self::PIM_BASE_URL . '/product-category';
        $result = $this->httpGet($url, $this->authHeaders());

        $categories = [];
        $rawCats = is_array($result) ? $result : [];

        $this->flattenCategories($rawCats, $categories);

        return $categories;
    }

    /**
     * İdefix'in iç içe kategori yapısını düzleştir
     */
    private function flattenCategories(array $cats, array &$output): void
    {
        foreach ($cats as $cat) {
            if (!is_array($cat)) continue;

            $output[] = [
                'id'        => (string) ($cat['id'] ?? ''),
                'name'      => (string) ($cat['name'] ?? ''),
                'parent_id' => (string) ($cat['parentId'] ?? '0'),
                'children'  => [],
            ];

            if (!empty($cat['subs']) && is_array($cat['subs'])) {
                $this->flattenCategories($cat['subs'], $output);
            }
        }
    }

    /**
     * Kategori özellik listesi çek
     *
     * GET https://merchantapi.idefix.com/pim/product-category/{categoryId}/attribute
     */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        $this->loadCredentials();

        try {
            $url = self::PIM_BASE_URL . '/product-category/' . urlencode($marketplaceCategoryId) . '/attribute';
            $result = $this->httpGet($url, $this->authHeaders());

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            $this->logger->error("İdefix kategori özellik hatası: " . $e->getMessage(), 'IdefixAPI');
            return [];
        }
    }

    /**
     * Marka listesi çek
     *
     * GET https://merchantapi.idefix.com/pim/brand
     */
    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();

        $params = ['page' => 1, 'size' => 100];

        $url = self::PIM_BASE_URL . '/brand?' . http_build_query($params);
        $result = $this->httpGet($url, $this->authHeaders());

        $brands = [];
        $rawBrands = is_array($result) ? $result : [];

        foreach ($rawBrands as $brand) {
            if (!is_array($brand)) continue;

            $title = (string) ($brand['title'] ?? '');
            if ($search !== '' && !str_contains(mb_strtolower($title), mb_strtolower($search))) {
                continue;
            }

            $brands[] = [
                'id'   => (string) ($brand['id'] ?? ''),
                'name' => $title,
            ];
        }

        return $brands;
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * İade listesi çek
     *
     * GET https://merchantapi.idefix.com/oms/{vendorId}/claim-list
     */
    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        try {
            $params = [];

            if (!empty($filters['start_date'])) {
                $params['startDate'] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $params['endDate'] = $filters['end_date'];
            }

            $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/claim-list?' . http_build_query($params);
            $result = $this->httpGet($url, $this->authHeaders());

            $returns = $result['items'] ?? [];

            return array_map(fn(array $r) => $this->normalizeReturn($r), array_filter($returns, 'is_array'));

        } catch (\Throwable $e) {
            $this->logger->error("İdefix iade çekme hatası: " . $e->getMessage(), 'IdefixAPI');
            return [];
        }
    }

    /**
     * İade verisini normalize et
     */
    private function normalizeReturn(array $raw): array
    {
        // İdefix'te iade statüsü item seviyesinde: items[0]['state']
        $itemState = '';
        if (!empty($raw['items'][0]['state'])) {
            $itemState = $raw['items'][0]['state'];
        }

        $statusMap = [
            'ready'                  => 'pending',
            'in_cargo'               => 'pending',
            'waiting_vendor_approve'  => 'pending',
            'approved'               => 'approved',
            'decline'                => 'rejected',
            'vendor_decline_request' => 'rejected',
        ];

        return [
            'marketplace_return_id' => (string) ($raw['id'] ?? ''),
            'marketplace_order_id'  => (string) ($raw['orderNumber'] ?? ''),
            'status'                => $statusMap[$itemState] ?? 'pending',
            'status_raw'            => $itemState,
            'reason'                => (string) ($raw['items'][0]['customerReason'] ?? $raw['items'][0]['vendorReason'] ?? ''),
            'customer_name'         => (string) ($raw['customerName'] ?? ''),
            'items'                 => array_map(fn($item) => [
                'sku'    => (string) ($item['barcode'] ?? ''),
                'name'   => (string) ($item['productName'] ?? ''),
                'qty'    => 1,
                'amount' => (float) ($item['discountedTotalPrice'] ?? $item['productPrice'] ?? 0),
            ], array_filter($raw['items'] ?? [], 'is_array')),
            'created_at'            => $raw['createdAt'] ?? date('Y-m-d'),
            'raw_data'              => $raw,
        ];
    }

    /**
     * İade onayla
     *
     * POST https://merchantapi.idefix.com/oms/{vendorId}/{claimId}/approve-claim
     */
    public function approveReturn(string $returnId): bool
    {
        $this->loadCredentials();

        try {
            $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/' . $returnId . '/approve-claim';
            $this->httpPost($url, [], $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("İdefix iade onay hatası: " . $e->getMessage(), 'IdefixAPI');
            return false;
        }
    }

    /**
     * İade reddet
     *
     * POST https://merchantapi.idefix.com/oms/{vendorId}/{claimId}/reject-claim
     */
    public function rejectReturn(string $returnId, string $reason): bool
    {
        $this->loadCredentials();

        try {
            $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/' . $returnId . '/reject-claim';
            $this->httpPost($url, ['reason' => $reason], $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("İdefix iade red hatası: " . $e->getMessage(), 'IdefixAPI');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════
    // FATURA İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Fatura linki gönder
     *
     * POST https://merchantapi.idefix.com/oms/{vendorId}/{shipmentId}/invoice-link
     */
    public function sendInvoiceLink(string $shipmentId, string $invoiceUrl): array
    {
        $this->loadCredentials();

        $url = self::OMS_BASE_URL . '/' . $this->vendorId . '/' . $shipmentId . '/invoice-link';
        return $this->httpPost($url, ['invoiceLink' => $invoiceUrl], $this->authHeaders());
    }

    /**
     * Batch işlem durumunu kontrol et
     *
     * GET https://merchantapi.idefix.com/pim/catalog/{vendorId}/inventory-result/{batchRequestId}
     */
    public function getBatchStatus(string $batchId): array
    {
        $this->loadCredentials();

        $url = self::PIM_BASE_URL . '/catalog/' . $this->vendorId . '/inventory-result/' . urlencode($batchId);
        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // SORU & CEVAP
    // ═══════════════════════════════════════════════════════

    /**
     * Müşteri sorularını çek
     * GET /pim/vendor/{vendor}/question/filter?page=&limit=&startDate=&endDate=&sort=newest
     *
     * @param array{page?: int, limit?: int, startDate?: string, endDate?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function fetchQuestions(array $filters = []): array
    {
        $this->loadCredentials();

        $allQuestions = [];
        $page = (int) ($filters['page'] ?? 1);
        $limit = min((int) ($filters['limit'] ?? 50), 50);

        $params = ['limit' => $limit, 'sort' => 'newest'];

        if (!empty($filters['startDate'])) {
            $ts = is_numeric($filters['startDate']) ? (int) $filters['startDate'] : strtotime($filters['startDate']) * 1000;
            $params['startDate'] = $ts;
        }
        if (!empty($filters['endDate'])) {
            $ts = is_numeric($filters['endDate']) ? (int) $filters['endDate'] : strtotime($filters['endDate']) * 1000;
            $params['endDate'] = $ts;
        }

        $this->startPaging('idefix.questions');

        do {
            $params['page'] = $page;
            $url = self::PIM_BASE_URL . '/vendor/' . $this->vendorId . '/question/filter?' . http_build_query($params);

            $result = $this->httpGet($url, $this->authHeaders());
            $questions = is_array($result) ? $result : [];

            if (empty($questions)) {
                break;
            }

            $allQuestions = array_merge($allQuestions, $questions);
            $page++;
        } while (count($questions) >= $limit && $this->keepPaging('idefix.questions', $page, $questions));

        return $allQuestions;
    }

    /**
     * Müşteri sorusunu cevapla
     * POST /pim/vendor/{vendor}/question/{questionId}/answer
     */
    public function answerQuestion(string $questionId, string $answerText): array
    {
        $this->loadCredentials();

        $url = self::PIM_BASE_URL . '/vendor/' . $this->vendorId . '/question/' . urlencode($questionId) . '/answer';
        $body = json_encode(['answer_body' => $answerText], JSON_UNESCAPED_UNICODE);

        return $this->httpPost($url, $body, $this->authHeaders());
    }
}
