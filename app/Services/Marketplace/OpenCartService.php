<?php
/**
 * OzyConnect Panel — OpenCartService (OzyConnect OpenCart Connector köprüsü)
 *
 * OpenCart core'da gerçek REST yok → mağazaya kurulan token korumalı tek-dosya köprü
 * (connectors/opencart/ozyconnect.php) ile konuşur. Kimlik: extra_config.connector_url
 * (ozyconnect.php tam adresi) + api_secret_encrypted (bağlantı token'ı). Auth: ?token= + header.
 * Senkron yalnız cron/manuel (OpenCart webhook yok). Uyumlu: OC 2.3/3.0/4.0/4.1.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class OpenCartService extends AbstractMarketplace
{
    private const SLUG = 'opencart';

    private string $token = '';
    private string $connectorUrl = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;
    private bool $credsLoaded = false;

    /** Dahili statü → OpenCart varsayılan order_status_id (mağaza özelleştirirse değişebilir). */
    private const STATUS_TO_OC = [
        'beklemede'      => 1,
        'yeni'           => 1,
        'hazirlaniyor'   => 2,
        'kargoda'        => 3,
        'teslim_edildi'  => 5,
        'iptal'          => 7,
        'iade'           => 11,
    ];

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->timeout            = $this->config['api']['timeout_seconds'] ?? 45;
        $this->maxRetries         = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs   = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond = $this->config['api']['rate_limit_per_second'] ?? 4;
    }

    public function loadCredentials(): void
    {
        if ($this->credsLoaded) {
            return;
        }
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('OpenCart pazaryeri kaydı bulunamadı');
        }
        if (empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('OpenCart bağlantı token\'ı henüz girilmemiş');
        }
        $this->token       = $this->crypto->decrypt($marketplace['api_secret_encrypted']);
        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];

        $url = trim((string) ($this->extraConfig['connector_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('OpenCart Connector URL (ozyconnect.php adresi) girilmemiş');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $this->connectorUrl = $url;
        $this->baseUrl      = $url;
        $this->credsLoaded  = true;
    }

    /** @param array<string,mixed> $params */
    private function url(string $action, array $params = []): string
    {
        $params = array_merge(['action' => $action, 'token' => $this->token], $params);
        return $this->connectorUrl . '?' . http_build_query($params);
    }

    /** @return array<int,string> */
    private function authHeaders(): array
    {
        return ['X-OzyConnect-Token: ' . $this->token];
    }

    /** Connector yanıtını doğrula; success=false ise hata fırlat. @return array<string,mixed> */
    private function unwrap(array $resp): array
    {
        if (($resp['success'] ?? false) !== true) {
            throw new RuntimeException('Connector hatası: ' . (string) ($resp['error'] ?? 'bilinmeyen'));
        }
        return $resp;
    }

    /** Sayfa-tabanlı toplama (data dolu geldiği sürece). @return array<int,array<string,mixed>> */
    private function paginate(string $action, array $params, int $limit = 100, int $maxPages = 30): array
    {
        $all = []; $page = 1;
        do {
            $p = $params; $p['page'] = $page; $p['limit'] = $limit;
            $resp = $this->unwrap($this->httpGet($this->url($action, $p), $this->authHeaders()));
            $rows = $resp['data'] ?? [];
            $count = 0;
            foreach ($rows as $r) { if (is_array($r)) { $all[] = $r; $count++; } }
            $page++;
        } while ($count === $limit && $page <= $maxPages);
        return $all;
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();
            $resp = $this->unwrap($this->httpGet($this->url('ping'), $this->authHeaders()));
            $name = (string) ($resp['store']['name'] ?? '');
            $ver  = (string) ($resp['connector_version'] ?? '');
            return [
                'success' => true,
                'message' => 'OpenCart bağlantısı başarılı' . ($name !== '' ? ": {$name}" : '') . ($ver !== '' ? " (connector v{$ver})" : ''),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Bağlantı hatası: ' . $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();
        $params = [];
        if (!empty($filters['after'])) {
            $params['after'] = $filters['after'];
        } else {
            $days = (int) ($filters['days'] ?? ($this->config['api']['order_history_days'] ?? 60));
            if ($days > 0) { $params['after'] = date('Y-m-d H:i:s', strtotime("-{$days} days")); }
        }
        $orders = $this->paginate('orders', $params, 100, 30);
        $out = [];
        foreach ($orders as $o) { $out[] = $this->normalizeOrder($o); }
        return $out;
    }

    /** @return array<string, mixed> */
    private function normalizeOrder(array $o): array
    {
        $statusId = (int) ($o['order_status_id'] ?? 0);
        $status   = $this->config['status_mapping'][$statusId] ?? 'yeni';

        $pay  = $o['payment'] ?? [];
        $ship = $o['shipping'] ?? [];
        $custName = trim(((string) ($o['firstname'] ?? '')) . ' ' . ((string) ($o['lastname'] ?? '')));
        $shipName = trim(((string) ($ship['firstname'] ?? '')) . ' ' . ((string) ($ship['lastname'] ?? '')));
        $billName = trim(((string) ($pay['firstname'] ?? '')) . ' ' . ((string) ($pay['lastname'] ?? '')));

        $items = []; $subtotal = 0.0;
        foreach ($o['products'] ?? [] as $li) {
            $qty   = (int) ($li['quantity'] ?? 0);
            $total = (float) ($li['total'] ?? 0);
            $unit  = (float) ($li['price'] ?? ($qty > 0 ? $total / $qty : 0));
            $subtotal += $total;
            $items[] = [
                'marketplace_item_id' => (string) ($li['product_id'] ?? ''),
                'sku'                 => (string) ($li['sku'] ?? $li['model'] ?? ''),
                'barcode'             => (string) ($li['barcode'] ?? $li['sku'] ?? ''),
                'name'                => (string) ($li['name'] ?? ''),
                'qty'                 => $qty,
                'unit_price'          => $unit,
                'discount'            => 0.0,
                'tax_rate'            => 0.0,
                'line_total'          => $total,
            ];
        }

        return [
            'marketplace_order_id' => (string) ($o['order_id'] ?? ''),
            'status'               => $status,
            'customer_name'        => $custName ?: ($billName ?: $shipName),
            'customer_email'       => (string) ($o['email'] ?? ''),
            'customer_phone'       => (string) ($o['telephone'] ?? ''),
            'customer_id_no'       => '',
            'customer_tax_office'  => '',
            'notes'                => (string) ($o['comment'] ?? ''),
            'billing_address' => [
                'name'        => $billName ?: $custName,
                'address'     => trim(((string) ($pay['address_1'] ?? '')) . ' ' . ((string) ($pay['address_2'] ?? ''))),
                'city'        => (string) (($pay['zone'] ?? '') ?: ($pay['city'] ?? '')),
                'district'    => (string) ($pay['city'] ?? ''),
                'postal_code' => (string) ($pay['postcode'] ?? ''),
                'company'     => (string) ($pay['company'] ?? ''),
                'tax_office'  => '',
                'tax_number'  => '',
            ],
            'shipping_address' => [
                'name'         => $shipName ?: $custName,
                'address'      => trim(((string) ($ship['address_1'] ?? $pay['address_1'] ?? '')) . ' ' . ((string) ($ship['address_2'] ?? ''))),
                'city'         => (string) (($ship['zone'] ?? '') ?: ($ship['city'] ?? '') ?: ($pay['zone'] ?? '')),
                'district'     => (string) (($ship['city'] ?? '') ?: ($pay['city'] ?? '')),
                'postal_code'  => (string) ($ship['postcode'] ?? $pay['postcode'] ?? ''),
                'phone'        => (string) ($o['telephone'] ?? ''),
                'neighborhood' => '',
            ],
            'subtotal'         => $subtotal,
            'tax_total'        => 0.0,
            'discount_total'   => 0.0,
            'total_amount'     => (float) ($o['total'] ?? 0),
            'cargo_company'    => '',
            'cargo_tracking_no'=> '',
            'cargo_sent_at'    => null,
            'ordered_at'       => $this->toMysqlDate($o['date_added'] ?? null) ?? '',
            'items'            => $items,
            'raw_data'         => $o,
        ];
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();
        $oid = (int) preg_replace('/\D/', '', $orderId);
        $s = strtolower($status);
        // Çıkış statü id'si: önce yapılandırılabilir config map'ten ters türet (mağaza özelleştirmişse uyar), yoksa varsayılan
        $statusId = 0;
        foreach (($this->config['status_mapping'] ?? []) as $ocId => $internal) {
            if ($internal === $s) { $statusId = (int) $ocId; break; }
        }
        if ($statusId <= 0) { $statusId = self::STATUS_TO_OC[$s] ?? 0; }
        if ($oid <= 0 || $statusId <= 0) { return false; }
        try {
            $this->unwrap($this->httpPost($this->url('order_update'), [
                'order_id' => $oid, 'status_id' => $statusId, 'comment' => 'OzyConnect',
            ], $this->authHeaders()));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('OpenCart updateOrderStatus hatası: ' . $e->getMessage(), 'MarketplaceAPI', ['order' => $orderId]);
            return false;
        }
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        // OpenCart core'da kargo takip alanı yok — durum güncellemesi dışında push edilmez.
        $this->logger->info("OpenCart kargo bilgisi (core'da alan yok, atlandı): #{$orderId} {$cargoCompany} {$trackingNo}", 'MarketplaceAPI');
        return true;
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN
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
        $resp  = $this->unwrap($this->httpGet($this->url('products', ['page' => $page + 1, 'limit' => $limit]), $this->authHeaders()));
        $data  = $resp['data'] ?? [];
        $total = (int) ($resp['total'] ?? 0);

        $normalized = [];
        foreach ($data as $p) { if (is_array($p)) { $normalized[] = $this->normalizeImportProduct($p); } }

        $hasMore    = count($data) === $limit;
        $totalPages = $total > 0 ? (int) ceil($total / $limit) : ($hasMore ? $page + 2 : $page + 1);
        return [
            'products'      => $normalized,
            'totalPages'    => $totalPages,
            'totalElements' => $total ?: count($normalized),
            'nextPage'      => $hasMore ? $page + 1 : null,
            'done'          => !$hasMore,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeImportProduct(array $p): array
    {
        $sku = (string) ($p['sku'] ?? '');
        if ($sku === '') { $sku = (string) ($p['model'] ?? ''); } // OC'de sku boş olabilir → model'e düş
        $images = [];
        if (!empty($p['image'])) { $images[] = ['url' => (string) $p['image'], 'is_cover' => true, 'sort' => 0]; }
        return [
            'marketplace_sku'        => $sku,
            'marketplace_product_id' => (string) ($p['product_id'] ?? ''),
            'barcode'                => (string) ($p['barcode'] ?? $sku),
            'name'                   => (string) ($p['name'] ?? ''),
            'category_name'          => (string) ($p['category'] ?? ''),
            'category_id'            => (string) ($p['category_id'] ?? ''),
            'brand_name'             => (string) ($p['manufacturer'] ?? ''),
            'brand_id'               => (string) ($p['manufacturer_id'] ?? ''),
            'price'                  => (float) ($p['price'] ?? 0),
            'list_price'             => (float) ($p['price'] ?? 0),
            'stock'                  => isset($p['quantity']) ? (int) $p['quantity'] : null,
            'group_id'               => (string) ($p['product_id'] ?? ''),
            'attributes'             => [],
            'images'                 => $images,
            'raw'                    => $p,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();
        $resp = $this->unwrap($this->httpGet($this->url('categories'), $this->authHeaders()));
        $rows = $resp['data'] ?? [];
        // Parent olarak geçen id'ler yaprak değil
        $parents = [];
        foreach ($rows as $c) { $pid = (int) ($c['parent_id'] ?? 0); if ($pid > 0) { $parents[$pid] = true; } }
        $out = [];
        foreach ($rows as $c) {
            $id = (int) ($c['category_id'] ?? 0);
            $out[] = [
                'marketplace_category_id' => (string) ($c['category_id'] ?? ''),
                'name'                    => (string) ($c['name'] ?? ''),
                'parent_id'               => (string) ($c['parent_id'] ?? '0'),
                'is_leaf'                 => !isset($parents[$id]),
            ];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();
        $resp = $this->unwrap($this->httpGet($this->url('manufacturers'), $this->authHeaders()));
        $rows = $resp['data'] ?? [];
        $out = [];
        foreach ($rows as $b) {
            $name = (string) ($b['name'] ?? '');
            if ($name === '') { continue; }
            if ($search !== '' && stripos($name, $search) === false) { continue; }
            $out[] = ['mp_brand_id' => (string) ($b['manufacturer_id'] ?? $name), 'name' => $name];
        }
        return $out;
    }

    /** @return array<int, array{id: int, name: string, required: bool, allowCustom: bool, values: array}> */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        return []; // OpenCart öznitelikleri kategoriye bağlı değil
    }

    // ═══════════════════════════════════════════════════════
    // STOK & FİYAT
    // ═══════════════════════════════════════════════════════

    /** @param array<int, array{barcode?: string, sku?: string, quantity: int}> $items
     *  @return array{success: int, failed: int, errors: array} */
    public function updateStock(array $items): array
    {
        $this->loadCredentials();
        $payload = []; $skipped = 0;
        foreach ($items as $it) {
            $sku = (string) ($it['barcode'] ?? $it['sku'] ?? '');
            if ($sku === '') { $skipped++; continue; }
            $payload[] = ['sku' => $sku, 'quantity' => (int) ($it['quantity'] ?? 0)];
        }
        if ($payload === []) { return ['success' => 0, 'failed' => count($items), 'errors' => ['Eşleşecek SKU yok']]; }
        try {
            $resp = $this->unwrap($this->httpPost($this->url('stock_update'), ['items' => $payload], $this->authHeaders()));
            return ['success' => (int) ($resp['updated'] ?? 0), 'failed' => (int) ($resp['failed'] ?? 0) + $skipped, 'errors' => $resp['errors'] ?? []];
        } catch (\Throwable $e) {
            return ['success' => 0, 'failed' => count($payload) + $skipped, 'errors' => ['OpenCart stok hatası: ' . $e->getMessage()]];
        }
    }

    /** @param array<int, array{barcode?: string, sku?: string, salePrice?: float}> $items
     *  @return array{success: int, failed: int, errors: array} */
    public function updatePrice(array $items): array
    {
        $this->loadCredentials();
        $payload = []; $skipped = 0;
        foreach ($items as $it) {
            $sku = (string) ($it['barcode'] ?? $it['sku'] ?? '');
            if ($sku === '') { $skipped++; continue; }
            $payload[] = ['sku' => $sku, 'price' => (float) ($it['salePrice'] ?? $it['price'] ?? 0)];
        }
        if ($payload === []) { return ['success' => 0, 'failed' => count($items), 'errors' => ['Eşleşecek SKU yok']]; }
        try {
            $resp = $this->unwrap($this->httpPost($this->url('price_update'), ['items' => $payload], $this->authHeaders()));
            return ['success' => (int) ($resp['updated'] ?? 0), 'failed' => (int) ($resp['failed'] ?? 0) + $skipped, 'errors' => $resp['errors'] ?? []];
        } catch (\Throwable $e) {
            return ['success' => 0, 'failed' => count($payload) + $skipped, 'errors' => ['OpenCart fiyat hatası: ' . $e->getMessage()]];
        }
    }

    // ═══════════════════════════════════════════════════════
    // ANLIK BİLDİRİM (OpenCart event → panel webhook)
    // ═══════════════════════════════════════════════════════

    /** Tek siparişi connector'dan çek + normalize (event/webhook anlık akışı). @return array<string,mixed>|null */
    public function fetchOrderById(int $orderId): ?array
    {
        if ($orderId <= 0) { return null; }
        $this->loadCredentials();
        $resp = $this->unwrap($this->httpGet($this->url('orders', ['order_id' => $orderId, 'page' => 1, 'limit' => 1]), $this->authHeaders()));
        $row = $resp['data'][0] ?? null;
        return is_array($row) ? $this->normalizeOrder($row) : null;
    }

    /** OpenCart'a anlık bildirim event'ini kur: webhook URL'sini mağaza setting'ine yazar + event kaydı oluşturur. @return array<string,mixed> */
    public function installEvent(string $webhookUrl, int $ocVersion): array
    {
        $this->loadCredentials();
        return $this->unwrap($this->httpPost($this->url('install_event'), [
            'webhook_url' => $webhookUrl,
            'oc_version'  => $ocVersion,
        ], $this->authHeaders()));
    }

    /** Anlık bildirim event'ini kaldır. @return array<string,mixed> */
    public function uninstallEvent(): array
    {
        $this->loadCredentials();
        return $this->unwrap($this->httpPost($this->url('uninstall_event'), [], $this->authHeaders()));
    }

    /** Anlık bildirim event'i kurulu mu? (hata = false) */
    public function eventStatus(): bool
    {
        try {
            $this->loadCredentials();
            $r = $this->unwrap($this->httpGet($this->url('event_status'), $this->authHeaders()));
            return (bool) ($r['installed'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════
    // İADE (OpenCart connector ilk fazda iade kapsamıyor)
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchReturns(array $filters = []): array { return []; }
    public function approveReturn(string $returnId): bool { return false; }
    public function rejectReturn(string $returnId, string $reason): bool { return false; }

    // ═══════════════════════════════════════════════════════
    // YARDIMCI
    // ═══════════════════════════════════════════════════════

    /** OpenCart tarihi (zaten 'Y-m-d H:i:s' mağaza-yerel) → MySQL datetime. Boş/geçersiz → null. */
    private function toMysqlDate(?string $dt): ?string
    {
        if ($dt === null || $dt === '' || str_starts_with($dt, '0000')) { return null; }
        $ts = strtotime($dt);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
