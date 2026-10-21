<?php
/**
 * OzyConnect Panel — CiceksepetiService (ÇiçekSepeti REST API Entegrasyonu)
 *
 * ÇiçekSepeti Entegrasyon API (REST):
 *  - Base URL: https://apis.ciceksepeti.com/api/v1
 *  - Auth: Header — x-api-key
 *  - Sipariş: POST /Order/GetOrders (max 14 gün aralık)
 *  - Stok/Fiyat: PUT /Products/price-and-stock (batchId ile takip)
 *  - Ürün Listesi: GET /Products?Page=&PageSize=
 *  - Ürün Güncelle: PUT /Products
 *  - Ürün Oluştur: POST /Products
 *  - Kategori: GET /Category
 *  - Kargo: POST /Cargo/SendWithOwnCargo, POST /Cargo/SendWithCiceksepeti
 *  - İade: POST /CanceledOrder/GetCanceledOrders
 *  - Fatura: POST /Branch/SendInvoiceMail
 *
 * Rate Limit: Aynı endpoint'e dakikada 1, farklı endpoint'e 5 saniyede 1
 *
 * @see https://ciceksepeti.dev
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

final class CiceksepetiService extends AbstractMarketplace
{
    private const SLUG = 'ciceksepeti';
    private const BASE_URL = 'https://apis.ciceksepeti.com/api/v1';

    private string $apiKey = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;

    /** Sipariş durum mapping (API statusId → sistem) */
    private const ORDER_STATUS_MAP = [
        1  => 'yeni',              // New
        2  => 'hazirlaniyor',      // Preparing
        5  => 'kargoda',           // Shipped
        7  => 'teslim_edildi',     // Delivered
        11 => 'hazirlaniyor',      // Will be shipped
    ];

    /** Sipariş durum adı mapping */
    private const ORDER_STATUS_NAME_MAP = [
        'Yeni Sipariş'     => 'yeni',
        'Hazırlanıyor'     => 'hazirlaniyor',
        'Kargoya Verildi'  => 'kargoda',
        'Teslim Edildi'    => 'teslim_edildi',
        'İptal'            => 'iptal',
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
        $this->timeout            = (int) ($this->config['api']['timeout_seconds'] ?? 30);
        $this->maxRetries         = (int) ($this->config['api']['max_retries'] ?? 3);
        $this->retryBaseDelayMs   = (int) ($this->config['api']['retry_base_delay_ms'] ?? 5000);
        $this->rateLimitPerSecond = (int) ($this->config['api']['rate_limit_per_second'] ?? 1);

        // ÇiçekSepeti: farklı isteklere 5 sn'de 1, aynı isteğe dakikada 1 limit uygular.
        // Ardışık çağrılar (sipariş dilimi/sayfalama) arasında en az 5.5 sn bırak.
        $this->minRequestIntervalMs = (int) ($this->config['api']['min_request_interval_ms'] ?? 5500);
    }

    // ═══════════════════════════════════════════════════════
    // KİMLİK DOĞRULAMA & HEADER
    // ═══════════════════════════════════════════════════════

    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('ÇiçekSepeti pazaryeri kaydı bulunamadı');
        }

        if (empty($marketplace['api_key_encrypted'])) {
            throw new RuntimeException('ÇiçekSepeti API bilgileri henüz girilmemiş. Pazaryerleri > ÇiçekSepeti > Ayarlar\'dan API Key girin.');
        }

        $this->apiKey      = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
    }

    /**
     * ÇiçekSepeti REST API header'ları
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        $sellerId = $this->extraConfig['seller_id'] ?? '';

        return [
            'x-api-key: ' . $this->apiKey,
            'User-Agent: ' . ($sellerId !== '' ? $sellerId . ' - OzyConnect' : 'OzyConnect'),
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

            // Ürün listesi çek (1 adet) — hafif bir endpoint
            $url = $this->baseUrl . '/Products?Page=1&PageSize=1';
            $result = $this->httpGet($url, $this->authHeaders());

            $totalCount = $result['totalCount'] ?? 0;

            return [
                'success' => true,
                'message' => "ÇiçekSepeti bağlantısı başarılı! {$totalCount} ürün bulundu.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("ÇiçekSepeti bağlantı testi hatası: " . $e->getMessage(), 'CiceksepetiAPI');
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
     * Sipariş listesi çek (max 14 gün aralık)
     *
     * POST /Order/GetOrders
     */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $startDate = $filters['startDate'] ?? $filters['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $endDate   = $filters['endDate'] ?? $filters['end_date'] ?? date('Y-m-d');

        // Tarih formatını düzelt (sadece Y-m-d bekliyoruz)
        if (strlen($startDate) > 10) {
            $startDate = substr($startDate, 0, 10);
        }
        if (strlen($endDate) > 10) {
            $endDate = substr($endDate, 0, 10);
        }

        $allOrders = [];

        // Max 14 gün aralık kısıtlaması — dilimlere böl
        $startTs = strtotime($startDate);
        $endTs   = strtotime($endDate);

        while ($startTs < $endTs) {
            $blockEnd = min($startTs + (13 * 86400), $endTs);

            $page = 0;
            $hasMore = true;

            while ($hasMore) {
                $payload = [
                    'startDate' => date('Y-m-d', $startTs),
                    'endDate'   => date('Y-m-d', $blockEnd),
                    'pageSize'  => 100,
                    'page'      => $page,
                ];

                if (!empty($filters['status'])) {
                    $payload['status'] = (int) $filters['status'];
                }

                $url = $this->baseUrl . '/Order/GetOrders';
                $result = $this->httpPost($url, $payload, $this->authHeaders());

                $orders = $result['supplierOrderListWithBranch'] ?? [];
                $totalCount = $result['orderListCount'] ?? 0;
                $totalPages = $result['pageCount'] ?? 1;

                foreach ($orders as $order) {
                    $allOrders[] = $this->normalizeOrder($order);
                }

                $page++;
                $hasMore = $page < $totalPages;
            }

            $startTs = $blockEnd + 86400;
        }

        $this->logger->debug('ÇiçekSepeti sipariş çekildi', 'CiceksepetiAPI', [
            'count' => count($allOrders),
        ]);

        return $allOrders;
    }

    /**
     * ÇiçekSepeti sipariş verisini normalize et
     */
    private function normalizeOrder(array $raw): array
    {
        // Sipariş durumu
        $statusId  = (int) ($raw['orderItemStatusId'] ?? 0);
        $statusText = (string) ($raw['orderProductStatus'] ?? '');
        $status = self::ORDER_STATUS_MAP[$statusId]
            ?? self::ORDER_STATUS_NAME_MAP[$statusText]
            ?? 'yeni';

        $customerName = trim((string) ($raw['receiverName'] ?? ''));

        // Ürün bilgileri — her satır bir orderItem
        $qty       = (int) ($raw['quantity'] ?? 1);
        $totalPrice = (float) ($raw['totalPrice'] ?? 0);
        $discount  = (float) ($raw['discount'] ?? 0);
        $tax       = (float) ($raw['tax'] ?? 0);
        $cargoPrice = (float) ($raw['cargoPrice'] ?? 0);

        $unitPrice = $qty > 0 ? round(($totalPrice + $discount) / $qty, 2) : $totalPrice;
        $subtotal  = $totalPrice;
        $taxAmount = round($subtotal * ($tax / 100), 2);

        $normalizedItems = [
            [
                'marketplace_item_id' => (string) ($raw['orderItemId'] ?? ''),
                'sku'                 => (string) ($raw['barcode'] ?? ''),
                'barcode'             => (string) ($raw['barcode'] ?? ''),
                'name'                => (string) ($raw['name'] ?? $raw['productName'] ?? ''),
                'qty'                 => $qty,
                'unit_price'          => $unitPrice,
                'discount'            => $discount,
                'tax_rate'            => $tax,
                'line_total'          => $totalPrice,
            ],
        ];

        $orderedAt = null;
        $createDate = $raw['orderCreateDate'] ?? null;
        $createTime = $raw['orderCreateTime'] ?? '00:00';
        if ($createDate !== null) {
            // Format: "31/03/2026" + "14:09"
            $ts = \DateTime::createFromFormat('d/m/Y H:i', $createDate . ' ' . $createTime);
            $orderedAt = $ts !== false ? $ts->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
        } else {
            $orderedAt = date('Y-m-d H:i:s');
        }

        return [
            'marketplace_order_id'  => (string) ($raw['orderId'] ?? ''),
            'status'                => $status,
            'status_raw'            => $statusText ?: (string) $statusId,
            'customer_name'         => $customerName,
            'customer_email'        => '',
            'customer_phone'        => (string) ($raw['receiverPhone'] ?? ''),
            'customer_id_no'        => '',
            'customer_tax_office'   => '',
            'notes'                 => (string) ($raw['cardMessage'] ?? ''),
            'billing_address'       => [
                'name'        => $customerName,
                'address'     => (string) ($raw['receiverAddress'] ?? ''),
                'city'        => (string) ($raw['receiverCity'] ?? ''),
                'district'    => (string) ($raw['receiverDistrict'] ?? ''),
                'postal_code' => '',
                'company'     => '',
                'tax_office'  => '',
                'tax_number'  => '',
            ],
            'shipping_address'      => [
                'name'         => $customerName,
                'address'      => (string) ($raw['receiverAddress'] ?? ''),
                'city'         => (string) ($raw['receiverCity'] ?? ''),
                'district'     => (string) ($raw['receiverDistrict'] ?? ''),
                'postal_code'  => '',
                'phone'        => (string) ($raw['receiverPhone'] ?? ''),
                'neighborhood' => '',
            ],
            'subtotal'              => round($subtotal, 2),
            'tax_total'             => round($taxAmount, 2),
            'discount_total'        => round($discount, 2),
            'shipping_total'        => $cargoPrice,
            'total_amount'          => round($subtotal + $cargoPrice, 2),
            'currency'              => 'TRY',
            'payment_method'        => (string) ($raw['orderPaymentType'] ?? ''),
            'cargo_company'         => (string) ($raw['cargoCompany'] ?? ''),
            'cargo_tracking_no'     => (string) ($raw['cargoNumber'] ?? ''),
            'cargo_tracking_link'   => (string) ($raw['shipmentTrackingUrl'] ?? ''),
            'cargo_sent_at'         => null,
            'ordered_at'            => $orderedAt,
            'items'                 => $normalizedItems,
            'raw_data'              => $raw,
        ];
    }

    /**
     * Sipariş detayı çek
     */
    public function getOrderDetail(string $orderId): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/Order/GetOrders';
        $result = $this->httpPost($url, [
            'orderNo' => $orderId,
            'pageSize' => 1,
            'page' => 0,
        ], $this->authHeaders());

        return $result['supplierOrderListWithBranch'][0] ?? [];
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->logger->info("ÇiçekSepeti: Sipariş durumu kargo entegrasyonu ile güncellenir", 'CiceksepetiAPI');
        return false;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        $this->loadCredentials();

        // Kargo firması mapping
        $cargoMap = [
            'mng'     => 'mng_kargo',
            'yurtiçi' => 'yurtici_Kargo',
            'sürat'   => 'surat_kargo',
            'aras'    => 'aras_kargo',
            'ptt'     => 'ptt_kargo',
            'ups'     => 'ups_kargo',
        ];

        $mappedCargo = $cargoMap[mb_strtolower($cargoCompany)] ?? $cargoCompany;

        $payload = [
            'orderItemId'       => $orderId,
            'orderItemStatus'   => 5, // Shipped
            'cargoBusiness'     => $mappedCargo,
            'shipmentNumber'    => $trackingNo,
        ];

        try {
            $url = $this->baseUrl . '/Cargo/SendWithOwnCargo';
            $this->httpPost($url, $payload, $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("ÇiçekSepeti kargo güncelleme hatası: " . $e->getMessage(), 'CiceksepetiAPI');
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün listesi çek
     *
     * GET /Products?Page=&PageSize=&ProductStatus=&StockCode=
     */
    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        // ÇiçekSepeti page 1'den başlar
        $csPage = $page + 1;

        $url = $this->baseUrl . '/Products?' . http_build_query([
            'Page'     => $csPage,
            'PageSize' => min($size, 100),
        ]);

        $result = $this->httpGet($url, $this->authHeaders());

        $products = $result['products'] ?? [];
        $totalCount = $result['totalCount'] ?? 0;

        return [
            'products'      => $products,
            'totalPages'    => $totalCount > 0 ? (int) ceil($totalCount / max(1, $size)) : 0,
            'totalElements' => $totalCount,
        ];
    }

    /**
     * Stok güncelle
     *
     * PUT /Products/price-and-stock
     * NOT: Stok ve fiyat aynı anda güncellenemez — bu metod sadece stok gönderir
     */
    public function updateStock(array $items): array
    {
        $this->loadCredentials();

        $success = 0;
        $failed = 0;
        $errors = [];

        // Max 100 ürün per batch (API limiti)
        $batches = array_chunk($items, 100);

        foreach ($batches as $batch) {
            $payload = ['items' => []];

            foreach ($batch as $item) {
                $stockCode = (string) ($item['barcode'] ?? $item['stockCode'] ?? '');
                if ($stockCode === '') continue;

                $payload['items'][] = [
                    'stockCode'     => $stockCode,
                    'stockQuantity' => (int) ($item['quantity'] ?? 0),
                ];
            }

            if (empty($payload['items'])) continue;

            try {
                $url = $this->baseUrl . '/Products/price-and-stock';
                $result = $this->httpPut($url, $payload, $this->authHeaders());

                $batchId = $result['batchId'] ?? null;
                if ($batchId !== null) {
                    $success += count($payload['items']);
                    $this->logger->info("ÇiçekSepeti stok güncelleme gönderildi", 'CiceksepetiAPI', [
                        'batchId' => $batchId,
                        'count'   => count($payload['items']),
                    ]);
                } else {
                    $success += count($payload['items']);
                }

            } catch (\Throwable $e) {
                $failed += count($payload['items']);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Fiyat güncelle
     *
     * PUT /Products/price-and-stock
     * NOT: Stok ve fiyat aynı anda güncellenemez — bu metod sadece fiyat gönderir
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
                $stockCode = (string) ($item['barcode'] ?? $item['stockCode'] ?? '');
                if ($stockCode === '') continue;

                $entry = [
                    'stockCode' => $stockCode,
                ];

                // salesPrice zorunlu (satış fiyatı)
                if (!empty($item['salePrice'])) {
                    $entry['salesPrice'] = (float) $item['salePrice'];
                } elseif (!empty($item['listPrice'])) {
                    $entry['salesPrice'] = (float) $item['listPrice'];
                }

                // listPrice opsiyonel (was fiyatı — salesPrice ile birlikte verilmeli)
                if (!empty($item['listPrice']) && !empty($item['salePrice'])) {
                    $entry['listPrice'] = (float) $item['listPrice'];
                }

                $payload['items'][] = $entry;
            }

            if (empty($payload['items'])) continue;

            try {
                $url = $this->baseUrl . '/Products/price-and-stock';
                $result = $this->httpPut($url, $payload, $this->authHeaders());

                $batchId = $result['batchId'] ?? null;
                if ($batchId !== null) {
                    $success += count($payload['items']);
                    $this->logger->info("ÇiçekSepeti fiyat güncelleme gönderildi", 'CiceksepetiAPI', [
                        'batchId' => $batchId,
                        'count'   => count($payload['items']),
                    ]);
                } else {
                    $success += count($payload['items']);
                }

            } catch (\Throwable $e) {
                $failed += count($payload['items']);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Ürün oluştur (toplu)
     *
     * POST /Products — asenkron çalışır, batchId döner; sonuç
     * GET /Products/batch-status/{batchId} ile takip edilir.
     *
     * Zorunlu alanlar: productName, mainProductCode, stockCode, categoryId,
     * salesPrice, stockQuantity, barcode, images, deliveryMessageType, deliveryType
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success: bool, batchId?: ?string, errors: array}
     */
    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        $products = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $products[] = $this->buildCreateProductPayload($item);
        }

        if ($products === []) {
            return ['success' => false, 'errors' => [['error' => 'Gönderilecek ürün yok']]];
        }

        try {
            $url = $this->baseUrl . '/Products';
            $result = $this->httpPost($url, ['products' => $products], $this->authHeaders());

            $batchId = $result['batchId'] ?? $result['batchID'] ?? null;

            $this->logger->info('ÇiçekSepeti ürün oluşturma gönderildi', 'CiceksepetiAPI', [
                'count'   => count($products),
                'batchId' => $batchId,
            ]);

            return [
                'success' => true,
                'batchId' => $batchId !== null ? (string) $batchId : null,
                'errors'  => [],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('ÇiçekSepeti ürün oluşturma hatası: ' . $e->getMessage(), 'CiceksepetiAPI');
            return ['success' => false, 'errors' => [['error' => $e->getMessage()]]];
        }
    }

    /**
     * ÇiçekSepeti'ne varyantlı ürün grubu gönder
     * Tüm varyantlar aynı mainProductCode ile gönderilir.
     *
     * @param array{items: array, groupId: string} $variantGroup
     * @return array{success: bool, errors: array}
     */
    public function createVariantProducts(array $variantGroup): array
    {
        $this->loadCredentials();

        $products = [];
        foreach ($variantGroup['items'] ?? [] as $item) {
            $payload = $this->buildCreateProductPayload($item);
            $payload['mainProductCode'] = (string) $variantGroup['groupId'];
            $products[] = $payload;
        }

        if ($products === []) {
            return ['success' => false, 'errors' => [['error' => 'Gönderilecek varyant yok']]];
        }

        try {
            // Ürün oluşturma POST /Products'tır (price-and-stock yalnız fiyat/stok günceller)
            $url = $this->baseUrl . '/Products';
            $result = $this->httpPost($url, ['products' => $products], $this->authHeaders());

            return [
                'success' => true,
                'batchId' => isset($result['batchId']) ? (string) $result['batchId'] : null,
                'errors'  => [],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'errors' => [['error' => $e->getMessage()]]];
        }
    }

    /**
     * Tek ürünü ÇiçekSepeti POST /Products şemasına dönüştür
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildCreateProductPayload(array $item): array
    {
        $images = [];
        foreach ((array) ($item['images'] ?? []) as $img) {
            $url = is_array($img) ? (string) ($img['url'] ?? '') : (string) $img;
            if ($url !== '') {
                $images[] = $url;
            }
        }

        $name = (string) ($item['productName'] ?? $item['title'] ?? $item['name'] ?? '');
        $stockCode = (string) ($item['stockCode'] ?? $item['sku'] ?? $item['barcode'] ?? '');

        $payload = [
            'productName'         => $name,
            'mainProductCode'     => (string) ($item['mainProductCode'] ?? $item['productMainId'] ?? $stockCode),
            'stockCode'           => $stockCode,
            'categoryId'          => (int) ($item['categoryId'] ?? 0),
            'description'         => (string) ($item['description'] ?? $name),
            // 1 = kargo ile teslimat; deliveryMessageType 18 = "kargoya verilecektir" mesajı
            'deliveryMessageType' => (int) ($item['deliveryMessageType'] ?? 18),
            'deliveryType'        => (int) ($item['deliveryType'] ?? 1),
            'stockQuantity'       => max(0, (int) ($item['stockQuantity'] ?? $item['quantity'] ?? 0)),
            'salesPrice'          => (float) ($item['salesPrice'] ?? $item['salePrice'] ?? 0),
            'barcode'             => (string) ($item['barcode'] ?? ''),
            'images'              => $images,
            'attributes'          => array_values((array) ($item['attributes'] ?? [])),
        ];

        $listPrice = (float) ($item['listPrice'] ?? 0);
        if ($listPrice > 0 && $listPrice >= $payload['salesPrice']) {
            $payload['listPrice'] = $listPrice;
        }

        return $payload;
    }

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
     * ÇiçekSepeti ürün verisini import formatına normalize et
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeImportProduct(array $raw): array
    {
        $images = [];
        foreach ($raw['images'] ?? [] as $i => $img) {
            $url = '';

            if (is_string($img)) {
                $url = $img;
            } elseif (is_array($img)) {
                $url = (string) ($img['url'] ?? $img['imageUrl'] ?? '');
            }

            if ($url === '') {
                continue;
            }

            $images[] = [
                'url'      => $url,
                'is_cover' => $i === 0,
                'sort'     => $i + 1,
            ];
        }

        $attributes = [];
        foreach ($raw['attributes'] ?? [] as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $attrName = (string) ($attr['parentName'] ?? $attr['name'] ?? '');
            $attrValue = (string) ($attr['name'] ?? $attr['value'] ?? '');

            if ($attrName === '' || $attrValue === '') {
                continue;
            }

            $attributes[] = [
                'name'  => $attrName,
                'value' => $attrValue,
            ];
        }

        $normalized = [
            'marketplace_sku'        => (string) ($raw['stockCode'] ?? ''),
            'marketplace_product_id' => (string) ($raw['productCode'] ?? $raw['mainProductCode'] ?? ''),
            'barcode'                => (string) ($raw['barcode'] ?? ''),
            'name'                   => (string) ($raw['productName'] ?? ''),
            'category_name'          => (string) ($raw['categoryName'] ?? ''),
            'category_id'            => (string) ($raw['categoryId'] ?? ''),
            'brand_name'             => $this->extractBrandNameFromAttributes($raw['attributes'] ?? []),
            'brand_id'               => '',
            'price'                  => (float) ($raw['salesPrice'] ?? 0),
            'list_price'             => (float) ($raw['listPrice'] ?? 0),
            'currency'               => 'TRY',
            'is_active'              => (bool) ($raw['isActive'] ?? true),
            'group_id'               => (string) ($raw['mainProductCode'] ?? ''),
            'images'                 => $images,
            'attributes'             => $attributes,
            'raw'                    => $raw,
        ];

        if (array_key_exists('stockQuantity', $raw)) {
            $normalized['stock'] = (int) $raw['stockQuantity'];
        }

        return $normalized;
    }

    /**
     * Ürün özelliklerinden marka adını çıkar
     *
     * @param mixed $attributes
     */
    private function extractBrandNameFromAttributes(mixed $attributes): string
    {
        if (!is_array($attributes)) {
            return '';
        }

        foreach ($attributes as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $parentName = mb_strtolower(trim((string) ($attr['parentName'] ?? '')));
            if ($parentName === 'marka') {
                return trim((string) ($attr['name'] ?? ''));
            }
        }

        return '';
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /**
     * Kategori listesi çek
     *
     * GET /Category
     */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        // /Category endpoint'ini dene — tek seferde, retry yok (çoğu satıcıda 404 döner)
        try {
            $url = $this->baseUrl . '/Category';
            $headers = $this->authHeaders();

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $body === false) {
                $this->logger->info("ÇiçekSepeti /Category endpointi HTTP {$httpCode} döndü. Kategoriler ürün importu sırasında toplanacak.", 'CiceksepetiAPI');
                return [];
            }

            $result = json_decode((string) $body, true) ?: [];

            $categories = [];
            $rawCats = $result['result'] ?? $result['categories'] ?? $result['data'] ?? [];
            if (!is_array($rawCats) || (isset($rawCats['categories']) && is_array($rawCats['categories']))) {
                $rawCats = $rawCats['categories'] ?? [];
            }

            foreach ($rawCats as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $categories[] = [
                    'id'        => (string) ($cat['categoryId'] ?? $cat['id'] ?? ''),
                    'name'      => (string) ($cat['categoryName'] ?? $cat['name'] ?? ''),
                    'parent_id' => (string) ($cat['parentCategoryId'] ?? $cat['parentId'] ?? ''),
                    'children'  => [],
                ];
            }

            return $categories;
        } catch (\Throwable $e) {
            $this->logger->info('ÇiçekSepeti /Category erişilemedi. Kategoriler ürün importu sırasında toplanacak.', 'CiceksepetiAPI', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function fetchBrands(string $search = ''): array
    {
        return [];
    }

    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        return [];
    }

    // ═══════════════════════════════════════════════════════
    // FATURA İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Fatura gönder
     *
     * POST /Branch/SendInvoiceMail
     */
    public function sendInvoice(string $orderItemId, ?string $pdfUrl = null, ?string $pdfBase64 = null): array
    {
        $this->loadCredentials();

        $item = [
            'orderItemId' => (int) $orderItemId,
        ];

        if ($pdfUrl !== null) {
            $item['documentUrl'] = $pdfUrl;
        } elseif ($pdfBase64 !== null) {
            $item['document'] = $pdfBase64;
        }

        $url = $this->baseUrl . '/Branch/SendInvoiceMail';
        return $this->httpPost($url, ['items' => [$item]], $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * İade taleplerini çek
     *
     * POST /CanceledOrder/GetCanceledOrders (tahmin edilen endpoint)
     */
    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        $startDate = $filters['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate   = $filters['endDate'] ?? date('Y-m-d');

        try {
            $url = $this->baseUrl . '/CanceledOrder/GetCanceledOrders';
            $result = $this->httpPost($url, [
                'startDate' => $startDate,
                'endDate'   => $endDate,
                'pageSize'  => 100,
                'page'      => 0,
            ], $this->authHeaders());

            $returns = $result['canceledOrders'] ?? $result['supplierOrderListWithBranch'] ?? [];

            return array_map(fn(array $r) => $this->normalizeReturn($r), array_filter($returns, 'is_array'));

        } catch (\Throwable $e) {
            $this->logger->error("ÇiçekSepeti iade çekme hatası: " . $e->getMessage(), 'CiceksepetiAPI');
            return [];
        }
    }

    /**
     * İade verisini normalize et
     */
    private function normalizeReturn(array $raw): array
    {
        return [
            'marketplace_return_id' => (string) ($raw['orderItemId'] ?? $raw['orderId'] ?? ''),
            'marketplace_order_id'  => (string) ($raw['orderId'] ?? ''),
            'status'                => 'pending',
            'status_raw'            => (string) ($raw['orderItemStatus'] ?? ''),
            'reason'                => (string) ($raw['cancelReason'] ?? ''),
            'customer_name'         => (string) ($raw['receiverName'] ?? ''),
            'items'                 => [
                [
                    'sku'      => (string) ($raw['barcode'] ?? ''),
                    'name'     => (string) ($raw['productName'] ?? ''),
                    'qty'      => (int) ($raw['quantity'] ?? 1),
                    'amount'   => (float) ($raw['totalPrice'] ?? 0),
                ],
            ],
            'created_at'            => $raw['orderCreateDate'] ?? date('Y-m-d'),
            'raw_data'              => $raw,
        ];
    }

    public function approveReturn(string $returnId): bool
    {
        $this->loadCredentials();

        try {
            $url = $this->baseUrl . '/CanceledOrder/ApproveOrRejectCanceledOrder';
            $this->httpPost($url, [
                'orderItemId' => (int) $returnId,
                'process'     => 'approve',
            ], $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("ÇiçekSepeti iade onay hatası: " . $e->getMessage(), 'CiceksepetiAPI');
            return false;
        }
    }

    public function rejectReturn(string $returnId, string $reason): bool
    {
        $this->loadCredentials();

        try {
            $url = $this->baseUrl . '/CanceledOrder/ApproveOrRejectCanceledOrder';
            $this->httpPost($url, [
                'orderItemId' => (int) $returnId,
                'process'     => 'reject',
            ], $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("ÇiçekSepeti iade red hatası: " . $e->getMessage(), 'CiceksepetiAPI');
            return false;
        }
    }

    /**
     * Batch işlem durumunu kontrol et
     *
     * GET /Products/batch-status/{batchId}
     */
    public function getBatchStatus(string $batchId): array
    {
        $this->loadCredentials();

        $url = $this->baseUrl . '/Products/batch-status/' . urlencode($batchId);
        return $this->httpGet($url, $this->authHeaders());
    }
}
