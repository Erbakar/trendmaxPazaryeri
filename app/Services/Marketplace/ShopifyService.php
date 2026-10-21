<?php
/**
 * OzyConnect Panel — ShopifyService (Shopify Admin REST API entegrasyonu)
 *
 * Kimlik (Custom App): mağaza alan adı (extra_config.shop_domain) + Admin API access token
 * (api_secret_encrypted). Auth: header `X-Shopify-Access-Token`. Base URL mağaza başına:
 * https://{shop}.myshopify.com/admin/api/{version}. Sayfalama: since_id (Link header gerektirmez).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.1.0
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class ShopifyService extends AbstractMarketplace
{
    private const SLUG = 'shopify';

    private string $accessToken = '';
    private string $shopDomain = '';
    private string $apiVersion = '2024-10';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;

    private bool $credsLoaded = false;
    private ?int $primaryLocationId = null;
    /** @var array<string, array{variant_id:int, inventory_item_id:int}>|null SKU → kimlikler (cache) */
    private ?array $skuMap = null;

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->apiVersion         = (string) ($this->config['api_version'] ?? '2024-10');
        $this->timeout            = $this->config['api']['timeout_seconds'] ?? 30;
        $this->maxRetries         = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs   = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond = $this->config['api']['rate_limit_per_second'] ?? 2;
    }

    public function loadCredentials(): void
    {
        if ($this->credsLoaded) {
            return;
        }
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('Shopify pazaryeri kaydı bulunamadı');
        }
        if (empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('Shopify Admin API access token henüz girilmemiş');
        }

        $this->accessToken = $this->crypto->decrypt($marketplace['api_secret_encrypted']);
        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];

        $shop = trim((string) ($this->extraConfig['shop_domain'] ?? ''));
        $shop = (string) preg_replace('#^https?://#i', '', $shop);
        $shop = rtrim($shop, '/');
        if ($shop === '') {
            throw new RuntimeException('Shopify mağaza alan adı (shop_domain) girilmemiş');
        }
        if (!str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }
        $this->shopDomain = $shop;
        $this->baseUrl    = "https://{$shop}/admin/api/{$this->apiVersion}";
        $this->credsLoaded = true;
    }

    /** @return array<int, string> */
    private function authHeaders(): array
    {
        return [
            "X-Shopify-Access-Token: {$this->accessToken}",
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** since_id sayfalaması — tüm kayıtları toplar (maxPages ile sınırlı). @return array<int,array<string,mixed>> */
    private function paginate(string $path, array $params, string $key, int $maxPages = 20): array
    {
        $all = [];
        $limit = (int) ($params['limit'] ?? 250);
        $sinceId = (int) ($params['since_id'] ?? 0);
        $pages = 0;
        do {
            $p = $params;
            $p['limit'] = $limit;
            if ($sinceId > 0) { $p['since_id'] = $sinceId; }
            $resp = $this->httpGet($this->baseUrl . $path . '?' . http_build_query($p), $this->authHeaders());
            $rows = $resp[$key] ?? [];
            $count = count($rows);
            foreach ($rows as $r) { $all[] = $r; }
            if ($count > 0) { $sinceId = (int) ($rows[$count - 1]['id'] ?? 0); }
            $pages++;
        } while ($count === $limit && $pages < $maxPages && $sinceId > 0);
        return $all;
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();
            $response = $this->httpGet($this->baseUrl . '/shop.json', $this->authHeaders());
            $name = $response['shop']['name'] ?? null;
            return [
                'success' => true,
                'message' => $name !== null ? "Shopify bağlantısı başarılı: {$name}" : 'Shopify bağlantısı başarılı!',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Bağlantı hatası: ' . $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ ÇEKME
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();
        $params = ['status' => $filters['status'] ?? 'any'];
        if (!empty($filters['updated_at_min'])) {
            $params['updated_at_min'] = $filters['updated_at_min'];
        } else {
            $days = (int) ($filters['days'] ?? ($this->config['api']['order_history_days'] ?? 60));
            if ($days > 0) {
                $params['updated_at_min'] = date('c', strtotime("-{$days} days"));
            }
        }
        $params['limit'] = 250;
        $orders = $this->paginate('/orders.json', $params, 'orders', 20);
        $out = [];
        foreach ($orders as $o) {
            $out[] = $this->normalizeOrder($o);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function normalizeOrder(array $o): array
    {
        $cancelled = !empty($o['cancelled_at']);
        $fin = (string) ($o['financial_status'] ?? '');
        $ful = (string) ($o['fulfillment_status'] ?? '');
        $statusKey = $cancelled ? 'cancelled' : ($ful === 'fulfilled' ? 'fulfilled' : ($fin !== '' ? $fin : 'open'));
        $status = $this->config['status_mapping'][$statusKey] ?? 'yeni';

        $ship = $o['shipping_address'] ?? [];
        $bill = $o['billing_address'] ?? [];
        $cust = $o['customer'] ?? [];

        $ful0 = $o['fulfillments'][0] ?? [];

        $items = [];
        foreach ($o['line_items'] ?? [] as $li) {
            $qty = (int) ($li['quantity'] ?? 0);
            $price = (float) ($li['price'] ?? 0);
            $disc = 0.0;
            foreach ($li['discount_allocations'] ?? [] as $da) { $disc += (float) ($da['amount'] ?? 0); }
            $items[] = [
                'marketplace_item_id' => (string) ($li['id'] ?? ''),
                'sku'                 => (string) ($li['sku'] ?? ''),
                'barcode'             => (string) ($li['sku'] ?? ''),
                'name'                => (string) ($li['name'] ?? $li['title'] ?? ''),
                'qty'                 => $qty,
                'unit_price'          => $price,
                'discount'            => $disc,
                'tax_rate'            => 0.0,
                'line_total'          => $price * $qty,
            ];
        }

        return [
            'marketplace_order_id' => (string) ($o['order_number'] ?? ltrim((string) ($o['name'] ?? (string) ($o['id'] ?? '')), '#')),
            'status'               => $status,
            'customer_name'        => trim(((string) ($cust['first_name'] ?? '')) . ' ' . ((string) ($cust['last_name'] ?? ''))) ?: (string) ($ship['name'] ?? ''),
            'customer_email'       => (string) ($o['email'] ?? $cust['email'] ?? ''),
            'customer_phone'       => (string) ($o['phone'] ?? ($ship['phone'] ?? '')),
            'customer_id_no'       => '',
            'customer_tax_office'  => '',
            'notes'                => (string) ($o['note'] ?? ''),
            'billing_address' => [
                'name'        => (string) ($bill['name'] ?? ''),
                'address'     => trim(((string) ($bill['address1'] ?? '')) . ' ' . ((string) ($bill['address2'] ?? ''))),
                'city'        => (string) ($bill['province'] ?? $bill['city'] ?? ''),
                'district'    => (string) ($bill['city'] ?? ''),
                'postal_code' => (string) ($bill['zip'] ?? ''),
                'company'     => (string) ($bill['company'] ?? ''),
                'tax_office'  => '',
                'tax_number'  => '',
            ],
            'shipping_address' => [
                'name'         => (string) ($ship['name'] ?? ''),
                'address'      => trim(((string) ($ship['address1'] ?? '')) . ' ' . ((string) ($ship['address2'] ?? ''))),
                'city'         => (string) ($ship['province'] ?? $ship['city'] ?? ''),
                'district'     => (string) ($ship['city'] ?? ''),
                'postal_code'  => (string) ($ship['zip'] ?? ''),
                'phone'        => (string) ($ship['phone'] ?? ''),
                'neighborhood' => '',
            ],
            'subtotal'         => (float) ($o['subtotal_price'] ?? 0),
            'tax_total'        => (float) ($o['total_tax'] ?? 0),
            'discount_total'   => (float) ($o['total_discounts'] ?? 0),
            'total_amount'     => (float) ($o['total_price'] ?? 0),
            'cargo_company'    => (string) ($ful0['tracking_company'] ?? ''),
            'cargo_tracking_no'=> (string) ($ful0['tracking_number'] ?? ''),
            'cargo_sent_at'    => $this->toMysqlDate($ful0['created_at'] ?? null),
            'ordered_at'       => $this->toMysqlDate($o['created_at'] ?? null) ?? '',
            'items'            => $items,
            'raw_data'         => $o,
        ];
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();
        $id = $this->numericId($orderId);
        if ($id === 0) { return false; }
        try {
            if (in_array(strtolower($status), ['cancelled', 'iptal', 'canceled'], true)) {
                $this->httpPost($this->baseUrl . "/orders/{$id}/cancel.json", [], $this->authHeaders());
                return true;
            }
            // Diğer durumlar Shopify'da fulfillment ile yönetilir (updateCargoInfo).
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Shopify updateOrderStatus hatası: ' . $e->getMessage(), 'MarketplaceAPI', ['order' => $orderId]);
            return false;
        }
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        $this->loadCredentials();
        $id = $this->numericId($orderId);
        if ($id === 0) { return false; }
        try {
            // Modern akış: fulfillment_orders → fulfillment (tracking ile)
            $resp = $this->httpGet($this->baseUrl . "/orders/{$id}/fulfillment_orders.json", $this->authHeaders());
            $fos = $resp['fulfillment_orders'] ?? [];
            $lineItemsByFo = [];
            foreach ($fos as $fo) {
                if (($fo['status'] ?? '') === 'closed' || ($fo['status'] ?? '') === 'cancelled') { continue; }
                $lineItemsByFo[] = ['fulfillment_order_id' => (int) ($fo['id'] ?? 0)];
            }
            if ($lineItemsByFo === []) { return false; }
            $this->httpPost($this->baseUrl . '/fulfillments.json', [
                'fulfillment' => [
                    'line_items_by_fulfillment_order' => $lineItemsByFo,
                    'tracking_info' => ['number' => $trackingNo, 'company' => $cargoCompany],
                    'notify_customer' => true,
                ],
            ], $this->authHeaders());
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Shopify updateCargoInfo hatası: ' . $e->getMessage(), 'MarketplaceAPI', ['order' => $orderId]);
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN ÇEKME / İÇE AKTARMA
    // ═══════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        return $this->importProducts($page, $size);
    }

    /** @return array{products: array, totalPages: int, totalElements: int} */
    public function importProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();
        $limit = min(250, max(1, $size));
        $params = ['limit' => $limit];
        if ($page > 0) { $params['since_id'] = $page; } // Shopify'da "page" = since_id imleci
        $resp = $this->httpGet($this->baseUrl . '/products.json?' . http_build_query($params), $this->authHeaders());
        $products = $resp['products'] ?? [];

        $normalized = [];
        $lastId = 0;
        foreach ($products as $p) {
            $lastId = (int) ($p['id'] ?? 0);
            foreach ($p['variants'] ?? [] as $v) {
                $normalized[] = $this->normalizeImportProduct($p, $v);
            }
        }
        // Sonraki sayfa imleci: tam sayfa geldiyse devam (since_id = son ürün id)
        $hasMore = count($products) === $limit;
        return [
            'products'      => $normalized,
            'totalPages'    => $hasMore ? ($page > 0 ? $page + 1 : 2) : ($page > 0 ? $page : 1),
            'totalElements' => count($normalized),
            'nextPage'      => $hasMore ? $lastId : null,
            'done'          => !$hasMore,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeImportProduct(array $p, array $v): array
    {
        $images = [];
        foreach ($p['images'] ?? [] as $i => $img) {
            $images[] = ['url' => (string) ($img['src'] ?? ''), 'is_cover' => $i === 0, 'sort' => $i];
        }
        return [
            'marketplace_sku'        => (string) ($v['sku'] ?? ''),
            'marketplace_product_id' => (string) ($v['id'] ?? ''),
            'barcode'                => (string) ($v['barcode'] ?? $v['sku'] ?? ''),
            'name'                   => (string) ($p['title'] ?? '') . (count($p['variants'] ?? []) > 1 ? ' - ' . (string) ($v['title'] ?? '') : ''),
            'category_name'          => (string) ($p['product_type'] ?? ''),
            'category_id'            => (string) ($p['product_type'] ?? ''),
            'brand_name'             => (string) ($p['vendor'] ?? ''),
            'brand_id'               => (string) ($p['vendor'] ?? ''),
            'price'                  => (float) ($v['price'] ?? 0),
            'list_price'             => (float) ($v['compare_at_price'] ?? $v['price'] ?? 0),
            'stock'                  => isset($v['inventory_quantity']) ? (int) $v['inventory_quantity'] : null,
            'group_id'               => (string) ($p['id'] ?? ''),
            'attributes'             => [],
            'images'                 => $images,
            'inventory_item_id'      => (int) ($v['inventory_item_id'] ?? 0),
            'raw'                    => ['product' => $p, 'variant' => $v],
        ];
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();
        $out = [];
        foreach (['custom_collections', 'smart_collections'] as $type) {
            try {
                $rows = $this->paginate("/{$type}.json", ['limit' => 250], $type, 10);
                foreach ($rows as $c) {
                    $out[] = [
                        'marketplace_category_id' => (string) ($c['id'] ?? ''),
                        'name'                    => (string) ($c['title'] ?? ''),
                        'parent_id'               => '0',
                        'is_leaf'                 => true,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Shopify {$type} çekilemedi: " . $e->getMessage(), 'MarketplaceAPI');
            }
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();
        // Shopify'da ayrı marka kaynağı yok → ürünlerdeki benzersiz "vendor" değerleri.
        $products = $this->paginate('/products.json', ['limit' => 250, 'fields' => 'id,vendor'], 'products', 20);
        $seen = [];
        foreach ($products as $p) {
            $vendor = trim((string) ($p['vendor'] ?? ''));
            if ($vendor !== '') { $seen[$vendor] = true; }
        }
        $out = [];
        foreach (array_keys($seen) as $vendor) {
            if ($search !== '' && stripos($vendor, $search) === false) { continue; }
            $out[] = ['mp_brand_id' => $vendor, 'name' => $vendor];
        }
        return $out;
    }

    /** @return array<int, array{id: int, name: string, required: bool, allowCustom: bool, values: array}> */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        return []; // Shopify'da kategori-özniteliği yok
    }

    // ═══════════════════════════════════════════════════════
    // STOK & FİYAT GÖNDERME
    // ═══════════════════════════════════════════════════════

    /** @param array<int, array{barcode?: string, sku?: string, quantity: int}> $items
     *  @return array{success: int, failed: int, errors: array} */
    public function updateStock(array $items): array
    {
        $this->loadCredentials();
        $ok = 0; $fail = 0; $errors = [];
        try {
            $locationId = $this->getPrimaryLocationId();
            $map = $this->getSkuMap();
        } catch (\Throwable $e) {
            return ['success' => 0, 'failed' => count($items), 'errors' => ['Shopify stok hazırlık hatası: ' . $e->getMessage()]];
        }
        foreach ($items as $it) {
            $sku = (string) ($it['barcode'] ?? $it['sku'] ?? '');
            $qty = (int) ($it['quantity'] ?? 0);
            $inv = $map[$sku]['inventory_item_id'] ?? 0;
            if ($sku === '' || $inv === 0) { $fail++; $errors[] = "SKU eşleşmedi: {$sku}"; continue; }
            try {
                $this->httpPost($this->baseUrl . '/inventory_levels/set.json', [
                    'location_id' => $locationId, 'inventory_item_id' => $inv, 'available' => $qty,
                ], $this->authHeaders());
                $ok++;
            } catch (\Throwable $e) {
                $fail++; $errors[] = $sku . ': ' . $e->getMessage();
            }
        }
        return ['success' => $ok, 'failed' => $fail, 'errors' => $errors];
    }

    /** @param array<int, array{barcode?: string, sku?: string, salePrice?: float, listPrice?: float}> $items
     *  @return array{success: int, failed: int, errors: array} */
    public function updatePrice(array $items): array
    {
        $this->loadCredentials();
        $ok = 0; $fail = 0; $errors = [];
        try {
            $map = $this->getSkuMap();
        } catch (\Throwable $e) {
            return ['success' => 0, 'failed' => count($items), 'errors' => ['Shopify fiyat hazırlık hatası: ' . $e->getMessage()]];
        }
        foreach ($items as $it) {
            $sku = (string) ($it['barcode'] ?? $it['sku'] ?? '');
            $variantId = $map[$sku]['variant_id'] ?? 0;
            if ($sku === '' || $variantId === 0) { $fail++; $errors[] = "SKU eşleşmedi: {$sku}"; continue; }
            $variant = ['id' => $variantId, 'price' => (string) ($it['salePrice'] ?? $it['price'] ?? 0)];
            if (!empty($it['listPrice'])) { $variant['compare_at_price'] = (string) $it['listPrice']; }
            try {
                $this->httpPut($this->baseUrl . "/variants/{$variantId}.json", ['variant' => $variant], $this->authHeaders());
                $ok++;
            } catch (\Throwable $e) {
                $fail++; $errors[] = $sku . ': ' . $e->getMessage();
            }
        }
        return ['success' => $ok, 'failed' => $fail, 'errors' => $errors];
    }

    // ═══════════════════════════════════════════════════════
    // İADE (asgari — Shopify REST sınırlı)
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchReturns(array $filters = []): array
    {
        return []; // TODO: GraphQL returns API (REST sınırlı). İlk fazda boş.
    }

    public function approveReturn(string $returnId): bool { return false; }
    public function rejectReturn(string $returnId, string $reason): bool { return false; }

    // ═══════════════════════════════════════════════════════
    // YARDIMCILAR
    // ═══════════════════════════════════════════════════════

    private function getPrimaryLocationId(): int
    {
        if ($this->primaryLocationId !== null) { return $this->primaryLocationId; }
        $resp = $this->httpGet($this->baseUrl . '/locations.json', $this->authHeaders());
        foreach ($resp['locations'] ?? [] as $loc) {
            if (!empty($loc['active'])) { return $this->primaryLocationId = (int) $loc['id']; }
        }
        $first = $resp['locations'][0]['id'] ?? 0;
        return $this->primaryLocationId = (int) $first;
    }

    /** SKU → {variant_id, inventory_item_id} eşlemesi (ürün taraması, cache). @return array<string, array{variant_id:int, inventory_item_id:int}> */
    private function getSkuMap(): array
    {
        if ($this->skuMap !== null) { return $this->skuMap; }
        $products = $this->paginate('/products.json', ['limit' => 250, 'fields' => 'id,variants'], 'products', 20);
        $map = [];
        foreach ($products as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $sku = (string) ($v['sku'] ?? '');
                if ($sku === '') { continue; }
                $map[$sku] = [
                    'variant_id'        => (int) ($v['id'] ?? 0),
                    'inventory_item_id' => (int) ($v['inventory_item_id'] ?? 0),
                ];
            }
        }
        return $this->skuMap = $map;
    }

    // ═══════════════════════════════════════════════════════
    // WEBHOOK (anlık) — alıcı tarafı
    // ═══════════════════════════════════════════════════════

    /** Webhook payload'ı (tek ham sipariş) → normalize sipariş (orders ingest için). @return array<string,mixed> */
    public function normalizeWebhookOrder(array $rawOrder): array
    {
        return $this->normalizeOrder($rawOrder);
    }

    /** Shopify webhook HMAC doğrulaması — app API secret key (extra_config.webhook_secret) ile. */
    public function verifyWebhookHmac(string $rawBody, string $hmacHeader): bool
    {
        $secret = '';
        try {
            $this->loadCredentials();
            $secret = (string) ($this->extraConfig['webhook_secret'] ?? '');
        } catch (\Throwable) {
            return false;
        }
        if ($secret === '' || $hmacHeader === '') {
            return false;
        }
        $calc = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($calc, $hmacHeader);
    }

    /** Shopify ISO8601 (TZ'li) → MySQL datetime (Y-m-d H:i:s). Boş/geçersiz → null. */
    private function toMysqlDate(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function numericId(string $id): int
    {
        // "#1001" veya "gid://..." gibi değerlerden sayısal id çıkar
        if (preg_match('/(\d{3,})/', $id, $m)) { return (int) $m[1]; }
        return (int) $id;
    }
}
