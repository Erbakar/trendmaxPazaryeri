<?php
/**
 * OzyConnect Panel — WooCommerceService (WooCommerce REST API v3 entegrasyonu)
 *
 * Kimlik: mağaza URL (extra_config.store_url) + Consumer Key (api_key_encrypted, ck_…)
 * + Consumer Secret (api_secret_encrypted, cs_…). Base URL mağaza başına: {store_url}/wp-json/wc/{version}.
 *
 * Kimlik doğrulama (WooCommerce, anahtarı düz metin olarak yalnız SSL üzerinden kabul eder):
 *  - https: consumer_key/consumer_secret sorgu parametresi (Authorization başlığını düşüren sunucularda da
 *    çalışır). WooCommerce reddederse (SSL'in vekil sunucuda sonlandığı kurulumlar) OAuth 1.0a denenir.
 *  - http : tek ayaklı OAuth 1.0a (HMAC-SHA256) imzalı URL. İmza METOT + uç adresi + sorgu parametreleri
 *    üzerinden kurulur, JSON gövde imzaya girmez; her istekte tekil nonce ve zaman damgası kullanılır.
 *
 * Çok kiracılı: servis container'da tekildir. Kimlik, SKU önbelleği ve çalıştığı doğrulanan kimlik
 * doğrulama yöntemi AKTİF MAĞAZAYA (TenantContext store_id) göre tutulur; mağaza değişince yeniden yüklenir.
 *
 * Ürün gönderme (createProducts / createVariantProducts) SKU ile upsert yapar: WooCommerce'te aynı stok
 * kodlu kayıt varsa güncellenir, yoksa oluşturulur. Görseller yalnız yeni üründe ya da kaynak URL listesi
 * değiştiğinde gönderilir (ürün meta'sında kaynak listesinin özeti tutulur).
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
use App\Core\TenantContext;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class WooCommerceService extends AbstractMarketplace
{
    private const SLUG = 'woocommerce';

    /** Kimlik bilgisi süreç içinde en fazla bu kadar saniye önbellekte kalır (uzun ömürlü kuyruk işçisi). */
    private const CREDENTIAL_TTL = 60;

    private const AUTH_QUERY = 'query';
    private const AUTH_OAUTH = 'oauth';
    /** https adresine OAuth; imza tabanı "http" (SSL vekil sunucuda sonlanıyor, WordPress is_ssl() = false). */
    private const AUTH_OAUTH_HTTP = 'oauth_http';

    /** ?sku=a,b,c ile tek istekte aranan stok kodu sayısı (URL boyu sınırı). */
    private const SKU_LOOKUP_CHUNK = 40;

    /** Görsel indirten toplu isteklerde öğe sınırı (WooCommerce görselleri istek içinde indirir). */
    private const IMAGE_BATCH_LIMIT = 10;

    /** Görsel indirten toplu isteklerde bekleme süresi (sn). */
    private const BATCH_TIMEOUT_SECONDS = 120;

    /** Ürüne/varyasyona gönderilen kaynak görsel URL listesi (JSON, sıralı) — yeniden indirtmeyi önler. */
    private const IMAGE_SOURCES_META = '_pzc_image_sources';

    /** Varyasyon özniteliği gelmeyen varyant için kullanılan öznitelik adı. */
    private const FALLBACK_ATTRIBUTE = 'Seçenek';

    /** @var array<string, string> WooCommerce webhook konusu => görünen ad */
    private const WEBHOOK_TOPICS = [
        'order.created' => 'Sipariş oluşturuldu',
        'order.updated' => 'Sipariş güncellendi',
    ];

    /** @var array<string, string> WooCommerce TR il kodu => resmî il adı */
    private const PROVINCES = [
        'TR01' => 'Adana', 'TR02' => 'Adıyaman', 'TR03' => 'Afyonkarahisar', 'TR04' => 'Ağrı',
        'TR05' => 'Amasya', 'TR06' => 'Ankara', 'TR07' => 'Antalya', 'TR08' => 'Artvin',
        'TR09' => 'Aydın', 'TR10' => 'Balıkesir', 'TR11' => 'Bilecik', 'TR12' => 'Bingöl',
        'TR13' => 'Bitlis', 'TR14' => 'Bolu', 'TR15' => 'Burdur', 'TR16' => 'Bursa',
        'TR17' => 'Çanakkale', 'TR18' => 'Çankırı', 'TR19' => 'Çorum', 'TR20' => 'Denizli',
        'TR21' => 'Diyarbakır', 'TR22' => 'Edirne', 'TR23' => 'Elazığ', 'TR24' => 'Erzincan',
        'TR25' => 'Erzurum', 'TR26' => 'Eskişehir', 'TR27' => 'Gaziantep', 'TR28' => 'Giresun',
        'TR29' => 'Gümüşhane', 'TR30' => 'Hakkari', 'TR31' => 'Hatay', 'TR32' => 'Isparta',
        'TR33' => 'Mersin', 'TR34' => 'İstanbul', 'TR35' => 'İzmir', 'TR36' => 'Kars',
        'TR37' => 'Kastamonu', 'TR38' => 'Kayseri', 'TR39' => 'Kırklareli', 'TR40' => 'Kırşehir',
        'TR41' => 'Kocaeli', 'TR42' => 'Konya', 'TR43' => 'Kütahya', 'TR44' => 'Malatya',
        'TR45' => 'Manisa', 'TR46' => 'Kahramanmaraş', 'TR47' => 'Mardin', 'TR48' => 'Muğla',
        'TR49' => 'Muş', 'TR50' => 'Nevşehir', 'TR51' => 'Niğde', 'TR52' => 'Ordu',
        'TR53' => 'Rize', 'TR54' => 'Sakarya', 'TR55' => 'Samsun', 'TR56' => 'Siirt',
        'TR57' => 'Sinop', 'TR58' => 'Sivas', 'TR59' => 'Tekirdağ', 'TR60' => 'Tokat',
        'TR61' => 'Trabzon', 'TR62' => 'Tunceli', 'TR63' => 'Şanlıurfa', 'TR64' => 'Uşak',
        'TR65' => 'Van', 'TR66' => 'Yozgat', 'TR67' => 'Zonguldak', 'TR68' => 'Aksaray',
        'TR69' => 'Bayburt', 'TR70' => 'Karaman', 'TR71' => 'Kırıkkale', 'TR72' => 'Batman',
        'TR73' => 'Şırnak', 'TR74' => 'Bartın', 'TR75' => 'Ardahan', 'TR76' => 'Iğdır',
        'TR77' => 'Yalova', 'TR78' => 'Karabük', 'TR79' => 'Kilis', 'TR80' => 'Osmaniye',
        'TR81' => 'Düzce',
    ];

    private string $consumerKey = '';
    private string $consumerSecret = '';
    private string $storeUrl = '';
    private string $apiVersion = 'v3';
    /** @var array<string, mixed> */
    private array $extraConfig = [];
    /** @var array<string, mixed> */
    private array $config;

    /** Kimliği yüklü mağaza (null = yüklenmedi, '0' = mağaza bağlamı yok). */
    private ?string $credsStore = null;
    private int $credsLoadedAt = 0;

    /** Önbellek anahtarı: mağaza + adres/anahtar parmak izi (kimlik değişirse önbellek de değişir). */
    private string $cacheKey = '';

    /** @var array<string, array<string, array{product_id:int, variation_id:int, parent_id:int, type:string, sku:string}>> önbellek anahtarı → SKU (küçük harf) → kimlikler */
    private array $skuCache = [];

    /** @var array<string, bool> önbellek anahtarı → tüm ürünler tarandı mı */
    private array $skuScanDone = [];

    /** @var array<string, bool> önbellek anahtarı → ?sku= araması varyasyon döndürdü mü */
    private array $skuLookupSeesVariations = [];

    /** @var array<string, string> önbellek anahtarı → çalıştığı doğrulanan kimlik doğrulama yöntemi */
    private array $authModeCache = [];

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
        private readonly ?TenantContext $tenant = null,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->apiVersion         = (string) ($this->config['api_version'] ?? 'v3');
        $this->timeout            = $this->config['api']['timeout_seconds'] ?? 30;
        $this->maxRetries         = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs   = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond = $this->config['api']['rate_limit_per_second'] ?? 3;
    }

    /**
     * Aktif mağazanın kimliğini yükler. Mağaza değiştiyse (çok kiracılı cron/kuyruk döngüsü) ya da önbellek
     * süresi dolduysa yeniden okur; yükleme başarısız olursa önceki mağazanın kimliği etkin kalmaz.
     */
    public function loadCredentials(): void
    {
        $store = (string) $this->activeStoreId();
        if ($this->credsStore === $store && (time() - $this->credsLoadedAt) < self::CREDENTIAL_TTL) {
            return;
        }

        $this->resetCredentials();

        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('WooCommerce pazaryeri kaydı bulunamadı');
        }
        if (empty($marketplace['api_key_encrypted']) || empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('WooCommerce Consumer Key / Secret henüz girilmemiş');
        }

        $consumerKey    = trim($this->crypto->decrypt((string) $marketplace['api_key_encrypted']));
        $consumerSecret = trim($this->crypto->decrypt((string) $marketplace['api_secret_encrypted']));
        $extraConfig    = json_decode((string) ($marketplace['extra_config_json'] ?? '{}'), true) ?: [];

        $url = $this->normalizeStoreUrl((string) ($extraConfig['store_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('WooCommerce mağaza adresi (store_url) girilmemiş');
        }

        // SSRF koruması: canlı panelde iç ağ/yerel hedef reddedilir; doğrulanan IP cURL'e sabitlenir
        $guard = StoreUrlGuard::check($url);
        if (!$guard['ok']) {
            throw new RuntimeException($guard['error']);
        }
        $this->curlResolve = $guard['resolve'];

        // Uzun ömürlü kuyruk/cron sürecinde yalnız aktif mağazanın önbellekleri tutulur (bellek birikmesin)
        $this->trimCachesTo($store . '|');

        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->extraConfig    = $extraConfig;
        $this->storeUrl       = $url;
        $this->baseUrl        = "{$url}/wp-json/wc/{$this->apiVersion}";
        $this->cacheKey       = $store . '|' . substr(hash('sha256', $url . '|' . $consumerKey), 0, 16);
        $this->credsStore     = $store;
        $this->credsLoadedAt  = time();
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();
            // Yetkili bir uç (kimlik doğrulaması) — index herkese açık olduğu için ürün listesi kullanılır.
            $probe = $this->request('GET', '/products', ['per_page' => 1]);
            if (isset($probe['raw_response']) || !array_is_list($probe)) {
                throw new RuntimeException('WooCommerce REST API JSON yanıtı vermedi (rest_no_route)');
            }

            $name = '';
            try {
                $status = $this->request('GET', '/system_status');
                $name = (string) ($status['environment']['home_url'] ?? '');
            } catch (\Throwable) {
                // system_status yetkisi yoksa sorun değil — bağlantı zaten doğrulandı.
            }

            $method = $this->activeAuthMode() === self::AUTH_QUERY ? 'anahtar ile' : 'OAuth 1.0a imzası ile';
            $message = $name !== '' ? "WooCommerce bağlantısı başarılı: {$name}" : 'WooCommerce bağlantısı başarılı!';

            return ['success' => true, 'message' => "{$message} ({$method})"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Bağlantı hatası: ' . $this->explainError($e->getMessage())];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ ÇEKME
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();
        $params = ['status' => $filters['status'] ?? 'any', 'orderby' => 'date', 'order' => 'asc'];

        if (!empty($filters['after'])) {
            $params['after'] = (string) $filters['after'];
        } elseif (!empty($filters['begin_date']) && ($begin = strtotime((string) $filters['begin_date'])) !== false) {
            $params['after'] = date('c', $begin);
        } else {
            $days = (int) ($filters['days'] ?? ($this->config['api']['order_history_days'] ?? 60));
            if ($days > 0) {
                $params['after'] = date('c', strtotime("-{$days} days"));
            }
        }
        if (!empty($filters['end_date']) && ($end = strtotime((string) $filters['end_date'])) !== false) {
            $params['before'] = date('c', $end);
        }

        $orders = $this->paginate('/orders', $params, 100, 30);
        $out = [];
        foreach ($orders as $o) {
            $out[] = $this->normalizeOrder($o);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function normalizeOrder(array $o): array
    {
        $statusKey = (string) ($o['status'] ?? '');
        $status = $this->config['status_mapping'][$statusKey] ?? 'yeni';

        $bill = is_array($o['billing'] ?? null) ? $o['billing'] : [];
        $ship = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
        // Teslimat adresi boşsa (dijital ürün vb.) fatura adresine düşülür
        $shipHasAddress = trim(((string) ($ship['address_1'] ?? '')) . ((string) ($ship['city'] ?? '')) . ((string) ($ship['state'] ?? ''))) !== '';
        $shipSource = $shipHasAddress ? $ship : $bill;

        $shipName = trim(((string) ($ship['first_name'] ?? '')) . ' ' . ((string) ($ship['last_name'] ?? '')));
        $billName = trim(((string) ($bill['first_name'] ?? '')) . ' ' . ((string) ($bill['last_name'] ?? '')));
        $billPlace = $this->addressPlace($bill);
        $shipPlace = $this->addressPlace($shipSource);

        $cargoCompany = (string) (($o['shipping_lines'][0]['method_title'] ?? '') ?: '');
        $trackingNo = '';
        foreach ($o['meta_data'] ?? [] as $m) {
            $k = (string) ($m['key'] ?? '');
            if (in_array($k, ['_tracking_number', '_wc_shipment_tracking_items', 'tracking_number'], true)) {
                $v = $m['value'] ?? '';
                $trackingNo = is_array($v) ? (string) ($v[0]['tracking_number'] ?? '') : (string) $v;
                if ($trackingNo !== '') { break; }
            }
        }

        $items = [];
        $subtotal = 0.0;
        foreach ($o['line_items'] ?? [] as $li) {
            $qty = (int) ($li['quantity'] ?? 0);
            $lineSubtotal = (float) ($li['subtotal'] ?? 0);
            $lineTotal = (float) ($li['total'] ?? 0);
            $unit = $qty > 0 ? $lineSubtotal / $qty : (float) ($li['price'] ?? 0);
            $subtotal += $lineSubtotal;
            $sku = (string) ($li['sku'] ?? '');
            $gtin = $this->validGtin((string) ($li['global_unique_id'] ?? ''));
            $variationId = (int) ($li['variation_id'] ?? 0);
            $items[] = [
                'marketplace_item_id'    => (string) ($li['id'] ?? ''),
                'marketplace_product_id' => (string) ($variationId > 0 ? $variationId : (int) ($li['product_id'] ?? 0)),
                'sku'                    => $sku,
                'barcode'                => $gtin !== '' ? $gtin : $sku,
                'name'                   => $this->decodeText((string) ($li['name'] ?? '')),
                'qty'                    => $qty,
                'unit_price'             => $unit,
                'discount'               => max(0.0, $lineSubtotal - $lineTotal),
                'tax_rate'               => 0.0,
                'line_total'             => $lineTotal,
            ];
        }

        return [
            // Yazma-geri (/orders/{id}) tamsayı id gerektirir; 'number' eklentilerle ('ORD-1001') sapabilir → id öncelikli
            'marketplace_order_id' => (string) ($o['id'] ?? (string) ($o['number'] ?? '')),
            'status'               => $status,
            'customer_name'        => $billName ?: $shipName,
            'customer_email'       => (string) ($bill['email'] ?? ''),
            'customer_phone'       => (string) ($bill['phone'] ?? ''),
            'customer_id_no'       => '',
            'customer_tax_office'  => '',
            'notes'                => (string) ($o['customer_note'] ?? ''),
            'billing_address' => [
                'name'        => $billName,
                'address'     => trim(((string) ($bill['address_1'] ?? '')) . ' ' . ((string) ($bill['address_2'] ?? ''))),
                'city'        => $billPlace['city'],
                'district'    => $billPlace['district'],
                'postal_code' => (string) ($bill['postcode'] ?? ''),
                'company'     => (string) ($bill['company'] ?? ''),
                'tax_office'  => '',
                'tax_number'  => '',
            ],
            'shipping_address' => [
                'name'         => $shipName ?: $billName,
                'address'      => trim(((string) ($shipSource['address_1'] ?? '')) . ' ' . ((string) ($shipSource['address_2'] ?? ''))),
                'city'         => $shipPlace['city'],
                'district'     => $shipPlace['district'],
                'postal_code'  => (string) ($shipSource['postcode'] ?? ''),
                'phone'        => (string) (($ship['phone'] ?? '') ?: ($bill['phone'] ?? '')),
                'neighborhood' => '',
            ],
            'subtotal'         => $subtotal,
            'tax_total'        => (float) ($o['total_tax'] ?? 0),
            'discount_total'   => (float) ($o['discount_total'] ?? 0),
            'total_amount'     => (float) ($o['total'] ?? 0),
            'cargo_company'    => $cargoCompany,
            'cargo_tracking_no'=> $trackingNo,
            'cargo_sent_at'    => null,
            'ordered_at'       => $this->toMysqlDate($o['date_created_gmt'] ?? null, true) ?? ($this->toMysqlDate($o['date_created'] ?? null) ?? ''),
            'items'            => $items,
            'raw_data'         => $o,
        ];
    }

    /**
     * WooCommerce adresinden il/ilçe: 'state' il kodudur (TR34) → il adı; WooCommerce 'city' alanı ilçedir.
     * İl kodu yoksa şehir alanı il kabul edilir.
     *
     * @param array<string, mixed> $address
     * @return array{city: string, district: string}
     */
    private function addressPlace(array $address): array
    {
        $wcCity = trim((string) ($address['city'] ?? ''));
        $province = self::provinceName((string) ($address['state'] ?? ''), (string) ($address['country'] ?? ''));
        if ($province === '') {
            return ['city' => $wcCity, 'district' => ''];
        }

        $district = mb_strtolower($wcCity, 'UTF-8') === mb_strtolower($province, 'UTF-8') ? '' : $wcCity;

        return ['city' => $province, 'district' => $district];
    }

    /**
     * WooCommerce il kodu (TR01..TR81, "TR-34", "34") → il adı. Türkiye dışı ya da tanınmayan değer aynen döner.
     */
    public static function provinceName(string $state, string $country = 'TR'): string
    {
        $state = trim($state);
        $country = strtoupper(trim($country));
        if ($state === '' || ($country !== '' && $country !== 'TR')) {
            return $state;
        }
        if (preg_match('/^(?:TR[-\s]?)?(\d{1,2})$/i', $state, $m) === 1) {
            return self::PROVINCES['TR' . str_pad($m[1], 2, '0', STR_PAD_LEFT)] ?? $state;
        }
        return $state;
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();
        $id = $this->numericId($orderId);
        if ($id === 0) { return false; }

        $wooStatus = null;
        $s = strtolower($status);
        if (in_array($s, ['cancelled', 'canceled', 'iptal'], true)) {
            $wooStatus = 'cancelled';
        } elseif (in_array($s, ['completed', 'teslim_edildi', 'delivered'], true)) {
            $wooStatus = 'completed';
        } elseif (in_array($s, ['processing', 'hazirlaniyor'], true)) {
            $wooStatus = 'processing';
        } elseif (in_array($s, ['refunded', 'iade'], true)) {
            $wooStatus = 'refunded';
        }
        if ($wooStatus === null) { return true; } // bilinmeyen → no-op (yanlış geçiş yapma)

        try {
            $this->request('PUT', "/orders/{$id}", [], ['status' => $wooStatus]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('WooCommerce updateOrderStatus hatası: ' . $e->getMessage(), 'MarketplaceAPI', ['order' => $orderId]);
            return false;
        }
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        $this->loadCredentials();
        $id = $this->numericId($orderId);
        if ($id === 0) { return false; }
        try {
            // Woo core'da kargo takibi yok → meta_data (tracking eklentileri okur) + müşteri notu.
            $this->request('PUT', "/orders/{$id}", [], [
                'meta_data' => [
                    ['key' => '_tracking_number', 'value' => $trackingNo],
                    ['key' => '_tracking_provider', 'value' => $cargoCompany],
                ],
            ]);
            $this->request('POST', "/orders/{$id}/notes", [], [
                'note'          => "Kargo: {$cargoCompany} — Takip No: {$trackingNo}",
                'customer_note' => true,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('WooCommerce updateCargoInfo hatası: ' . $e->getMessage(), 'MarketplaceAPI', ['order' => $orderId]);
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
        $perPage = min(100, max(1, $size));
        $wooPage = $page + 1; // dahili 0-tabanlı → Woo 1-tabanlı
        $products = $this->request('GET', '/products', ['per_page' => $perPage, 'page' => $wooPage, 'status' => 'any']);
        $products = array_is_list($products) ? $products : [];

        $normalized = [];
        foreach ($products as $p) {
            if (!is_array($p)) { continue; }
            $this->rememberProduct($p);
            $type = (string) ($p['type'] ?? 'simple');
            if ($type === 'variable') {
                $variations = [];
                $pid = (int) ($p['id'] ?? 0);
                try {
                    $variations = $this->paginate("/products/{$pid}/variations", [], 100, 5);
                } catch (\Throwable $e) {
                    $this->logger->warning('WooCommerce varyasyon çekilemedi: ' . $e->getMessage(), 'MarketplaceAPI');
                }
                if ($variations === []) {
                    $normalized[] = $this->normalizeImportProduct($p, null);
                } else {
                    foreach ($variations as $v) {
                        $this->rememberVariation($v, $pid);
                        $normalized[] = $this->normalizeImportProduct($p, $v);
                    }
                }
            } else {
                $normalized[] = $this->normalizeImportProduct($p, null);
            }
        }

        $hasMore = count($products) === $perPage;
        return [
            'products'      => $normalized,
            'totalPages'    => $hasMore ? $wooPage + 1 : $wooPage,
            'totalElements' => count($normalized),
            'nextPage'      => $hasMore ? $page + 1 : null,
            'done'          => !$hasMore,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeImportProduct(array $p, ?array $v): array
    {
        $isVar = $v !== null;
        // Varyasyonun kendi görseli (Woo'da tekil 'image' nesnesi) varsa onu kapak yap; yoksa ürün galerisi
        $srcImages = ($isVar && !empty($v['image']['src'])) ? [$v['image']] : ($p['images'] ?? []);
        $images = [];
        foreach ($srcImages as $i => $img) {
            $images[] = ['url' => (string) ($img['src'] ?? ''), 'is_cover' => $i === 0, 'sort' => $i];
        }
        $cat = $p['categories'][0] ?? [];
        $brand = $p['brands'][0] ?? [];
        // Varyasyon adı: ürün adı + öznitelik değerleri
        $varSuffix = '';
        if ($isVar && !empty($v['attributes'])) {
            $parts = [];
            foreach ($v['attributes'] as $a) { $parts[] = (string) ($a['option'] ?? ''); }
            $parts = array_filter($parts);
            if ($parts !== []) { $varSuffix = ' - ' . implode(' / ', $parts); }
        }

        // ?? iç ternary'yi korumadığı için her alan erişimini tek tek koru
        $sku       = $isVar ? (string) ($v['sku'] ?? '') : (string) ($p['sku'] ?? '');
        $mpId      = $isVar ? (string) ($v['id'] ?? '') : (string) ($p['id'] ?? '');
        $price     = $isVar ? (float) ($v['price'] ?? 0) : (float) ($p['price'] ?? 0);
        $listPrice = $isVar ? (float) ($v['regular_price'] ?? $v['price'] ?? 0) : (float) ($p['regular_price'] ?? $p['price'] ?? 0);
        $stockRaw  = $isVar ? ($v['stock_quantity'] ?? null) : ($p['stock_quantity'] ?? null);
        // Barkod: geçerli GTIN/EAN varsa o (sistemdeki ürünle barkodla eşleşir), yoksa stok kodu
        $gtin      = $this->validGtin($isVar ? (string) ($v['global_unique_id'] ?? '') : (string) ($p['global_unique_id'] ?? ''));

        return [
            'marketplace_sku'        => $sku,
            'marketplace_product_id' => $mpId,
            'barcode'                => $gtin !== '' ? $gtin : $sku,
            'name'                   => $this->decodeText((string) ($p['name'] ?? '')) . $varSuffix,
            'category_name'          => $this->decodeText((string) ($cat['name'] ?? '')),
            'category_id'            => (string) ($cat['id'] ?? ''),
            'brand_name'             => $this->decodeText((string) ($brand['name'] ?? '')),
            'brand_id'               => (string) ($brand['id'] ?? ''),
            'price'                  => $price,
            'list_price'             => $listPrice,
            'stock'                  => $stockRaw !== null ? (int) $stockRaw : null,
            'group_id'               => (string) ($p['id'] ?? ''),
            'attributes'             => [],
            'images'                 => $images,
            'raw'                    => $isVar ? ['product' => $p, 'variation' => $v] : ['product' => $p],
        ];
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /**
     * WooCommerce kategori ağacı (düz liste). Her öğe hem eski anahtarları (marketplace_category_id,
     * parent_id '0'=kök, is_leaf) hem de genel kategori kaydedicilerin okuduğu anahtarları taşır:
     * id, parentId (kökte null), leaf, paths (kökten yaprağa adlar), full_path ("Giyim > Tişört").
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();
        $rows = $this->paginate('/products/categories', ['orderby' => 'name', 'order' => 'asc', 'hide_empty' => false], 100, 20);

        $byId = [];
        $hasChildren = [];
        foreach ($rows as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id <= 0) { continue; }
            $parent = (int) ($c['parent'] ?? 0);
            $byId[$id] = ['name' => $this->decodeText((string) ($c['name'] ?? '')), 'parent' => $parent, 'raw' => $c];
            if ($parent > 0) { $hasChildren[$parent] = true; }
        }

        $out = [];
        foreach ($byId as $id => $c) {
            $paths = $this->categoryPath($byId, $id);
            $isLeaf = !isset($hasChildren[$id]);
            $out[] = [
                'id'                      => (string) $id,
                'marketplace_category_id' => (string) $id,
                'mp_category_id'          => (string) $id,
                'name'                    => $c['name'],
                'parent_id'               => (string) $c['parent'],
                'parentId'                => $c['parent'] > 0 ? (string) $c['parent'] : null,
                'leaf'                    => $isLeaf,
                'is_leaf'                 => $isLeaf,
                'paths'                   => $paths,
                'full_path'               => implode(' > ', $paths),
                'slug'                    => (string) ($c['raw']['slug'] ?? ''),
                'count'                   => (int) ($c['raw']['count'] ?? 0),
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp(
            mb_strtolower((string) $a['full_path'], 'UTF-8'),
            mb_strtolower((string) $b['full_path'], 'UTF-8')
        ));

        return $out;
    }

    /**
     * @param array<int, array{name: string, parent: int, raw: array}> $byId
     * @return list<string> kökten yaprağa kategori adları
     */
    private function categoryPath(array $byId, int $id): array
    {
        $names = [];
        $seen = [];
        $current = $id;
        while ($current > 0 && isset($byId[$current]) && !isset($seen[$current]) && count($names) < 20) {
            $seen[$current] = true;
            array_unshift($names, $byId[$current]['name']);
            $current = $byId[$current]['parent'];
        }
        return $names;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();
        // Marka: WooCommerce ≥ 9.6 çekirdekte (product_brand) ya da Brands eklentisi; yoksa boş.
        try {
            $rows = $this->paginate('/products/brands', ['orderby' => 'name', 'order' => 'asc'], 100, 10);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $b) {
            $name = $this->decodeText((string) ($b['name'] ?? ''));
            if ($name === '') { continue; }
            if ($search !== '' && mb_stripos($name, $search, 0, 'UTF-8') === false) { continue; }
            $id = (string) ($b['id'] ?? '');
            if ($id === '' || !ctype_digit($id)) { continue; } // WooCommerce marka kimliği sayısal terim ID'si
            // 'id' + 'mp_brand_id' birlikte: marka kaydedicilerin ikisi de kimliği doğru okur (adı kimlik sanmaz)
            $out[] = ['id' => $id, 'mp_brand_id' => $id, 'name' => $name];
        }
        return $out;
    }

    /** @return array<int, array{id: int, name: string, required: bool, allowCustom: bool, values: array}> */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        return []; // Woo'da öznitelikler kategoriye bağlı değil (global) — kategori bazlı şart yok.
    }

    // ═══════════════════════════════════════════════════════
    // STOK & FİYAT GÖNDERME
    // ═══════════════════════════════════════════════════════

    /** @param array<int, array{barcode?: string, sku?: string, quantity: int}> $items
     *  @return array{success: int, failed: int, errors: array<int, array{sku: string, barcode: string, error: string}>} */
    public function updateStock(array $items): array
    {
        return $this->pushListingUpdates($items, 'stock');
    }

    /** @param array<int, array{barcode?: string, sku?: string, salePrice?: float, listPrice?: float}> $items
     *  @return array{success: int, failed: int, errors: array<int, array{sku: string, barcode: string, error: string}>} */
    public function updatePrice(array $items): array
    {
        return $this->pushListingUpdates($items, 'price');
    }

    /**
     * Stok ya da fiyatı toplu uçlarla gönderir: basit ürünler /products/batch, varyasyonlar ana ürün başına
     * /products/{id}/variations/batch (100'lük parçalar). Ürün stok kodu (SKU) ile bulunur; önbellekte yoksa
     * ?sku= araması yapılır (varyasyonlar dahil). Hatalar ['sku','barcode','error'] biçimindedir.
     *
     * @param array<int, mixed> $items
     * @return array{success: int, failed: int, errors: array<int, array{sku: string, barcode: string, error: string}>}
     */
    private function pushListingUpdates(array $items, string $kind): array
    {
        $label = $kind === 'stock' ? 'stok' : 'fiyat';
        $items = array_values(array_filter($items, 'is_array'));

        try {
            $this->loadCredentials();
            $keys = [];
            foreach ($items as $item) {
                array_push($keys, ...$this->itemLookupKeys($item));
            }
            $entries = $this->findSkuEntries($keys);
        } catch (\Throwable $e) {
            $error = "WooCommerce {$label} hazırlık hatası: " . $e->getMessage();
            return ['success' => 0, 'failed' => count($items), 'errors' => array_map(fn(array $item): array => $this->itemError($item, $error), $items)];
        }

        $errors = [];
        $jobs = [];
        $success = 0;
        foreach ($items as $index => $item) {
            $entry = null;
            foreach ($this->itemLookupKeys($item) as $key) {
                $entry = $entries[$this->skuKey($key)] ?? null;
                if ($entry !== null) { break; }
            }
            if ($entry === null) {
                $errors[$index] = 'SKU eşleşmedi: ' . ($this->itemLookupKeys($item)[0] ?? '(boş)');
                continue;
            }
            // Değişken (varyantlı) ana ürünün fiyatı/stoğu WooCommerce'te varyasyonlardan hesaplanır; ana ürüne
            // stok yazmak ana düzey stok yönetimini açıp ürünü "stokta yok" gösterebilir → gönderilecek bir şey yok.
            if ($entry['type'] === 'variable') {
                $success++;
                continue;
            }

            $body = $kind === 'stock' ? $this->stockFields($item) : $this->priceFields($item);
            if ($body === null) {
                $errors[$index] = $kind === 'stock' ? 'Stok miktarı yok' : 'Geçerli fiyat yok (0 fiyat gönderilmez)';
                continue;
            }

            $jobs[$index] = $entry['variation_id'] > 0
                ? $this->job("/products/{$entry['parent_id']}/variations/batch", 'update', ['id' => $entry['variation_id']] + $body, $entry['parent_id'])
                : $this->job('/products/batch', 'update', ['id' => $entry['product_id']] + $body, 0);
        }

        $outcomes = $this->runJobs($jobs);
        foreach ($jobs as $index => $job) {
            $outcome = $outcomes[$index] ?? null;
            if ($outcome !== null && $outcome['ok']) {
                $success++;
                continue;
            }
            $errors[$index] = $outcome['error'] ?? 'WooCommerce yanıtı alınamadı';
            $this->forgetSku($this->itemLookupKeys($items[$index])[0] ?? '');
        }

        ksort($errors);
        $errorRows = [];
        foreach ($errors as $index => $message) {
            $errorRows[] = $this->itemError($items[$index], $message);
        }

        return ['success' => $success, 'failed' => count($errorRows), 'errors' => $errorRows];
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN GÖNDERME (oluştur / güncelle — SKU ile upsert)
    // ═══════════════════════════════════════════════════════

    /**
     * Basit ürünleri WooCommerce'e gönderir. Aynı SKU'lu ürün varsa güncellenir (varyasyonsa varyasyon
     * güncellenir), yoksa oluşturulur. Toplu uç kullanılır; sonuç öğe başına ve senkrondur.
     *
     * Öğe alanları: sku|stockCode (zorunlu), name|title (yeni üründe zorunlu), description, short_description,
     * salePrice/listPrice (ya da price/regular_price/sale_price), quantity, categoryId|categories[{id}] (Woo
     * kategori id), brandId|brands[{id}] (Woo marka id), images (URL listesi, [{url}] ya da [{src}]), barcode
     * (8-14 haneli ise GTIN olarak), weight, dimensions{length,width,height}, status (yeni üründe varsayılan
     * 'publish'; güncellemede yalnız verilirse değişir), marketplace_product_id (WooCommerce ürün id ipucu:
     * SKU bulunamazsa bu ürün güncellenir — SKU değişmiş ürün için).
     *
     * @param array<int, mixed> $items
     * @return array{success: bool, results: list<array<string, mixed>>, errors: list<array{sku: string, barcode: string, error: string}>, sent: int, failed: int, created: int, updated: int}
     */
    public function createProducts(array $items): array
    {
        $items = array_values($items);
        try {
            $this->loadCredentials();
        } catch (\Throwable $e) {
            return $this->failAll($items, 'WooCommerce bağlantı bilgileri: ' . $e->getMessage());
        }

        $results = [];
        $pending = [];
        $seen = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $results[$index] = $this->resultRow('', '', 'Geçersiz ürün verisi');
                continue;
            }
            $sku = $this->itemSku($item);
            if ($sku === '') {
                $results[$index] = $this->resultRow('', $this->itemBarcode($item), 'Stok kodu (SKU) zorunlu');
                continue;
            }
            if (isset($seen[$this->skuKey($sku)])) {
                $results[$index] = $this->resultRow($sku, $this->itemBarcode($item), 'Aynı stok kodu bu gönderimde birden fazla kez var');
                continue;
            }
            $seen[$this->skuKey($sku)] = true;
            $pending[$index] = $item;
        }

        $found = [];
        if ($pending !== []) {
            try {
                $found = $this->lookupSkus(array_map(fn(array $item): string => $this->itemSku($item), array_values($pending)));
            } catch (\Throwable $e) {
                foreach ($pending as $index => $item) {
                    $results[$index] = $this->resultRow($this->itemSku($item), $this->itemBarcode($item), 'WooCommerce ürün araması başarısız: ' . $e->getMessage());
                }
                $pending = [];
            }
        }

        $jobs = [];
        foreach ($pending as $index => $item) {
            $raw = $found[$this->skuKey($this->itemSku($item))] ?? $this->productByHint($item);
            $job = $this->simpleJob($item, $raw);
            if (is_string($job)) {
                $results[$index] = $this->resultRow($this->itemSku($item), $this->itemBarcode($item), $job);
                continue;
            }
            $jobs[$index] = $job;
        }

        $outcomes = $this->runJobs($jobs);
        $outcomes = $this->repairOutcomes(
            $jobs,
            $outcomes,
            fn(int $index, array $raw): array|string => $this->simpleJob($pending[$index], $raw)
        );

        foreach ($jobs as $index => $job) {
            $results[$index] = $this->resultFromOutcome($pending[$index], $outcomes[$index] ?? null, $job);
        }

        ksort($results);
        return $this->publishSummary(array_values($results));
    }

    /**
     * Varyantlı ürün(ler): ana ürün type=variable + öznitelikler (variation: true) + varyasyonlar
     * (/products/{id}/variations/batch). Ana ürün parentSku ile, varyasyonlar kendi SKU'ları ile bulunur:
     * varsa güncellenir, yoksa oluşturulur (yinelenme yok).
     *
     * Girdi: tek grup (VariantPublishService biçimi: {parentSku, parentName, description, categoryId, brandId,
     * images, items[]}) ya da grup listesi. Varyant öğesi createProducts öğesiyle aynı alanları taşır; ayrıca
     * variationAttributes {"Renk":"Siyah","Beden":"M"} ya da attributes [{name, value|option}]. Öznitelik
     * gelmezse "Seçenek" özniteliği varyant adından (ana ad çıkarılarak) ya da SKU'dan türetilir.
     *
     * Dönüş createProducts ile aynıdır (results varyasyon başına: marketplace_product_id = varyasyon id,
     * parent_id = ana ürün id) + parent (ilk grubun ana ürün sonucu) + parents (grup başına).
     *
     * @param array<int|string, mixed> $groups
     * @return array<string, mixed>
     */
    public function createVariantProducts(array $groups): array
    {
        $list = isset($groups['items']) ? [$groups] : array_values(array_filter($groups, 'is_array'));

        try {
            $this->loadCredentials();
        } catch (\Throwable $e) {
            $items = [];
            foreach ($list as $group) {
                array_push($items, ...array_values(array_filter((array) ($group['items'] ?? []), 'is_array')));
            }
            return $this->failAll($items, 'WooCommerce bağlantı bilgileri: ' . $e->getMessage()) + ['parent' => null, 'parents' => []];
        }

        $results = [];
        $parents = [];
        foreach ($list as $group) {
            $published = $this->publishVariantGroup($group);
            $parents[] = $published['parent'];
            array_push($results, ...$published['results']);
        }

        return $this->publishSummary($results) + ['parent' => $parents[0] ?? null, 'parents' => $parents];
    }

    /**
     * @param array<string, mixed> $group
     * @return array{parent: array<string, mixed>, results: list<array<string, mixed>>}
     */
    private function publishVariantGroup(array $group): array
    {
        $items = array_values(array_filter((array) ($group['items'] ?? []), 'is_array'));
        $parentSku = $this->firstText($group, ['parentSku', 'parent_sku', 'groupId', 'sku']);
        $parentName = $this->firstText($group, ['parentName', 'parent_name', 'name', 'title']);
        if ($parentName === '' && $items !== []) {
            $parentName = $this->itemName($items[0]);
        }

        if ($items === []) {
            return ['parent' => $this->parentRow($parentSku, null, 'Varyant listesi boş', null), 'results' => []];
        }

        $results = [];
        $valid = [];
        $seen = $parentSku !== '' ? [$this->skuKey($parentSku) => true] : [];
        foreach ($items as $index => $item) {
            $sku = $this->itemSku($item);
            if ($sku === '') {
                $results[$index] = $this->resultRow('', $this->itemBarcode($item), 'Varyantın stok kodu (SKU) yok');
                continue;
            }
            if (isset($seen[$this->skuKey($sku)])) {
                $results[$index] = $this->resultRow($sku, $this->itemBarcode($item), 'Stok kodu ana ürünle ya da başka bir varyantla aynı');
                continue;
            }
            $seen[$this->skuKey($sku)] = true;
            $valid[$index] = ['item' => $item, 'sku' => $sku, 'attrs' => $this->variationAttributes($item, $parentName)];
        }

        $failGroup = function (string $error) use (&$results, $valid, $parentSku): array {
            foreach ($valid as $index => $v) {
                $results[$index] = $this->resultRow($v['sku'], $this->itemBarcode($v['item']), $error);
            }
            ksort($results);
            return ['parent' => $this->parentRow($parentSku, null, $error, null), 'results' => array_values($results)];
        };

        if ($valid === []) {
            return $failGroup('Gönderilecek geçerli varyant yok');
        }

        try {
            $lookup = array_column($valid, 'sku');
            if ($parentSku !== '') {
                $lookup[] = $parentSku;
            }
            $found = $this->lookupSkus($lookup);
            $parentRaw = $this->resolveVariableParent($parentSku, $valid, $found);
        } catch (\Throwable $e) {
            return $failGroup('WooCommerce ürün araması başarısız: ' . $e->getMessage());
        }

        if (is_string($parentRaw)) {
            return $failGroup($parentRaw);
        }

        $attributes = $this->mergeParentAttributes($valid, $parentRaw);
        $parentBody = $this->variableParentPayload($group, $items[0], $parentName, $parentSku, $attributes, $parentRaw);

        try {
            $parentResponse = $parentRaw === null
                ? $this->request('POST', '/products', [], $parentBody, $this->hasImageDownloads([$parentBody]) ? self::BATCH_TIMEOUT_SECONDS : null)
                : $this->request('PUT', '/products/' . (int) $parentRaw['id'], [], $parentBody, $this->hasImageDownloads([$parentBody]) ? self::BATCH_TIMEOUT_SECONDS : null);
        } catch (\Throwable $e) {
            $error = $this->wooErrorMessage($e->getMessage());
            if ($parentRaw === null && stripos($error, 'SKU') !== false) {
                $error = $this->trashedSkuMessage($parentSku) ?? $error;
            }
            return $failGroup('Ana ürün kaydedilemedi: ' . $error);
        }

        $parentId = (int) ($parentResponse['id'] ?? 0);
        if ($parentId <= 0) {
            return $failGroup('Ana ürün kaydedilemedi: WooCommerce geçersiz yanıt döndürdü');
        }
        $this->rememberProduct($parentResponse);
        $parentAction = $parentRaw === null ? 'created' : 'updated';
        $refs = $this->attributeRefs($parentResponse);

        $jobs = [];
        foreach ($valid as $index => $v) {
            $raw = $found[$this->skuKey($v['sku'])] ?? null;
            $job = $this->variationJob($v, $raw, $parentId, $refs);
            if (is_string($job)) {
                $results[$index] = $this->resultRow($v['sku'], $this->itemBarcode($v['item']), $job);
                continue;
            }
            $jobs[$index] = $job;
        }

        $outcomes = $this->runJobs($jobs);
        $outcomes = $this->repairOutcomes(
            $jobs,
            $outcomes,
            fn(int $index, array $raw): array|string => $this->variationJob($valid[$index], $raw, $parentId, $refs)
        );

        foreach ($jobs as $index => $job) {
            $results[$index] = $this->resultFromOutcome($valid[$index]['item'], $outcomes[$index] ?? null, $job);
        }

        ksort($results);
        return [
            'parent'  => $this->parentRow($parentSku, (string) $parentId, null, $parentAction) + [
                'permalink' => (string) ($parentResponse['permalink'] ?? ''),
            ],
            'results' => array_values($results),
        ];
    }

    /**
     * Varyantlı ana ürünü bulur: önce ana SKU, yoksa varyasyonlardan birinin bağlı olduğu ana ürün.
     * Hiçbiri yoksa null (yeni oluşturulur). Çakışmada hata metni döner.
     *
     * @param array<int, array{item: array, sku: string, attrs: array<string, string>}> $valid
     * @param array<string, array<string, mixed>> $found
     * @return array<string, mixed>|string|null
     */
    private function resolveVariableParent(string $parentSku, array $valid, array $found): array|string|null
    {
        if ($parentSku !== '') {
            $raw = $found[$this->skuKey($parentSku)] ?? null;
            if ($raw !== null) {
                if ((string) ($raw['type'] ?? '') === 'variation') {
                    return "Ana ürün stok kodu ({$parentSku}) WooCommerce'te başka bir ürünün varyasyonuna ait";
                }
                return $raw;
            }
        }

        foreach ($valid as $v) {
            $raw = $found[$this->skuKey($v['sku'])] ?? null;
            $parentId = (int) ($raw['parent_id'] ?? 0);
            if ($raw !== null && (string) ($raw['type'] ?? '') === 'variation' && $parentId > 0) {
                return $this->request('GET', "/products/{$parentId}");
            }
        }

        return null;
    }

    /**
     * @param array{item: array, sku: string, attrs: array<string, string>} $variant
     * @param array<string, mixed>|null $raw WooCommerce'teki aynı SKU'lu kayıt
     * @param array<string, array{id: int, name: string}> $refs ana ürün öznitelikleri (küçük harf ad → id/ad)
     * @return array<string, mixed>|string iş tanımı ya da hata metni
     */
    private function variationJob(array $variant, ?array $raw, int $parentId, array $refs): array|string
    {
        $attributes = [];
        foreach ($variant['attrs'] as $name => $value) {
            $name = (string) $name;
            $ref = $refs[mb_strtolower($name, 'UTF-8')] ?? ['id' => 0, 'name' => $name];
            $attributes[] = $ref['id'] > 0
                ? ['id' => $ref['id'], 'option' => $value]
                : ['name' => $ref['name'], 'option' => $value];
        }

        $endpoint = "/products/{$parentId}/variations/batch";
        if ($raw === null) {
            return $this->job($endpoint, 'create', $this->variationPayload($variant['item'], null, $attributes), $parentId);
        }

        $id = (int) ($raw['id'] ?? 0);
        $type = (string) ($raw['type'] ?? '');
        $rawParent = (int) ($raw['parent_id'] ?? 0);
        if ($type === 'variation' && $rawParent === $parentId) {
            return $this->job($endpoint, 'update', ['id' => $id] + $this->variationPayload($variant['item'], $raw, $attributes), $parentId);
        }
        if ($type === 'variation') {
            return "Stok kodu WooCommerce'te başka bir ürünün (#{$rawParent}) varyasyonunda kayıtlı";
        }
        return "Stok kodu WooCommerce'te ayrı bir ürün olarak kayıtlı (#{$id}); varyasyon olarak kullanılamaz";
    }

    // ═══════════════════════════════════════════════════════
    // WEBHOOK KURULUMU
    // ═══════════════════════════════════════════════════════

    /**
     * Sipariş webhook'larını (order.created + order.updated) kurar. Aynı teslim adresi + konu için kayıt
     * varsa günceller (etkin, gizli anahtar yenilenir), yoksa oluşturur; aynı adres+konuda fazladan kayıt
     * varsa siler (yinelenme yok). Başka adreslere ait webhook'lara dokunmaz.
     *
     * @return array{success: bool, created: int, updated: int, removed: int, ids: list<int>, errors: list<string>, message: string}
     */
    public function ensureWebhooks(string $deliveryUrl, string $secret): array
    {
        $out = ['success' => false, 'created' => 0, 'updated' => 0, 'removed' => 0, 'ids' => [], 'errors' => [], 'message' => ''];
        $deliveryUrl = trim($deliveryUrl);
        if (preg_match('#^https?://[^\s]+$#i', $deliveryUrl) !== 1) {
            $out['message'] = 'Webhook teslim adresi geçersiz';
            return $out;
        }
        if (trim($secret) === '') {
            $out['message'] = 'Webhook gizli anahtarı boş olamaz';
            return $out;
        }

        try {
            $this->loadCredentials();
            $hooks = $this->paginate('/webhooks', ['status' => 'all'], 100, 10);
        } catch (\Throwable $e) {
            $out['message'] = 'WooCommerce webhook listesi alınamadı: ' . $this->explainError($e->getMessage());
            return $out;
        }

        $target = $this->comparableUrl($deliveryUrl);
        $byTopic = [];
        foreach ($hooks as $hook) {
            $topic = (string) ($hook['topic'] ?? '');
            if (isset(self::WEBHOOK_TOPICS[$topic]) && $this->comparableUrl((string) ($hook['delivery_url'] ?? '')) === $target) {
                $byTopic[$topic][] = $hook;
            }
        }

        foreach (self::WEBHOOK_TOPICS as $topic => $label) {
            $body = [
                'name'         => 'PazarConnect — ' . $label,
                'topic'        => $topic,
                'delivery_url' => $deliveryUrl,
                'secret'       => $secret,
                'status'       => 'active',
            ];
            try {
                $existing = $byTopic[$topic] ?? [];
                if ($existing === []) {
                    $row = $this->request('POST', '/webhooks', [], $body);
                    $out['created']++;
                    $out['ids'][] = (int) ($row['id'] ?? 0);
                    continue;
                }

                $keep = array_shift($existing);
                $row = $this->request('PUT', '/webhooks/' . (int) $keep['id'], [], $body);
                $out['updated']++;
                $out['ids'][] = (int) ($row['id'] ?? $keep['id']);
                foreach ($existing as $duplicate) {
                    $this->request('DELETE', '/webhooks/' . (int) $duplicate['id'], ['force' => true]);
                    $out['removed']++;
                }
            } catch (\Throwable $e) {
                $out['errors'][] = "{$topic}: " . $this->explainError($e->getMessage());
            }
        }

        $out['success'] = $out['errors'] === [];
        $out['message'] = $out['success']
            ? sprintf('Webhook kurulumu tamam: %d oluşturuldu, %d güncellendi%s.', $out['created'], $out['updated'], $out['removed'] > 0 ? ", {$out['removed']} yinelenen silindi" : '')
            : 'Webhook kurulumu tamamlanamadı: ' . implode(' | ', $out['errors']);

        return $out;
    }

    // ═══════════════════════════════════════════════════════
    // İADE (Woo core'da iade API'si yok)
    // ═══════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    public function fetchReturns(array $filters = []): array
    {
        return [];
    }

    public function approveReturn(string $returnId): bool { return false; }
    public function rejectReturn(string $returnId, string $reason): bool { return false; }

    // ═══════════════════════════════════════════════════════
    // WEBHOOK (anlık) — alıcı tarafı
    // ═══════════════════════════════════════════════════════

    /** Webhook payload'ı (tek ham sipariş) → normalize sipariş. @return array<string,mixed> */
    public function normalizeWebhookOrder(array $rawOrder): array
    {
        return $this->normalizeOrder($rawOrder);
    }

    /** WooCommerce webhook imzası: base64(hmac_sha256(rawBody, webhook_secret)) — X-WC-Webhook-Signature. */
    public function verifyWebhookHmac(string $rawBody, string $signatureHeader): bool
    {
        try {
            $this->loadCredentials();
            $secret = (string) ($this->extraConfig['webhook_secret'] ?? '');
        } catch (\Throwable) {
            return false;
        }
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }
        $calc = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($calc, $signatureHeader);
    }

    // ═══════════════════════════════════════════════════════
    // HTTP + KİMLİK DOĞRULAMA
    // ═══════════════════════════════════════════════════════

    /**
     * Tek WooCommerce isteği. Kimlik doğrulama yöntemi mağazaya göre seçilir; https'te anahtar reddedilirse
     * OAuth yöntemleri sırayla denenir ve çalışan yöntem bu mağaza için hatırlanır.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, ?int $timeout = null): array
    {
        $this->loadCredentials();
        $modes = $this->authModes();
        $previousTimeout = $this->timeout;
        if ($timeout !== null) {
            $this->timeout = max($this->timeout, $timeout);
        }

        try {
            $firstError = null;
            foreach ($modes as $position => $mode) {
                try {
                    $response = $this->send($method, $path, $query, $body, $mode);
                    if (count($modes) > 1) {
                        $this->authModeCache[$this->cacheKey] = $mode;
                    }
                    return $response;
                } catch (RuntimeException $e) {
                    $isLast = $position === count($modes) - 1;
                    if ($isLast || !$this->isAuthRejected($e)) {
                        throw ($firstError !== null && $this->isAuthRejected($e)) ? $firstError : $e;
                    }
                    $firstError ??= $e;
                }
            }
        } finally {
            $this->timeout = $previousTimeout;
        }

        throw new RuntimeException('WooCommerce isteği gönderilemedi');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body, string $mode): array
    {
        for ($attempt = 0; ; $attempt++) {
            $url = $this->url($method, $path, $query, $mode);
            try {
                return match ($method) {
                    'GET'    => $this->httpGet($url),
                    'POST'   => $this->httpPost($url, $body),
                    'PUT'    => $this->httpPut($url, $body),
                    'DELETE' => $this->httpDelete($url),
                    default  => throw new RuntimeException("Desteklenmeyen HTTP metodu: {$method}"),
                };
            } catch (RuntimeException $e) {
                // Sunucu hatasında üst katman AYNI imzalı URL'yi yeniden dener; WooCommerce kullanılmış
                // nonce'u reddeder → yeni imzayla bir kez daha gönder.
                if ($mode !== self::AUTH_QUERY && $attempt === 0 && str_contains($e->getMessage(), 'nonce')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Kimlik doğrulamalı tam URL. https + 'query' → consumer_key/consumer_secret sorguda; diğerleri OAuth 1.0a.
     *
     * @param array<string, mixed> $params
     */
    private function url(string $method, string $path, array $params = [], ?string $mode = null): string
    {
        $mode ??= $this->authModes()[0];
        $endpoint = $this->baseUrl . $path;
        $params = $this->flattenParams($params);

        if ($mode === self::AUTH_QUERY) {
            $params['consumer_key']    = $this->consumerKey;
            $params['consumer_secret'] = $this->consumerSecret;
            return $endpoint . '?' . $this->buildQuery($params);
        }

        $params += [
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp'        => (string) time(),
        ];
        $signatureBase = $mode === self::AUTH_OAUTH_HTTP ? (string) preg_replace('#^https://#i', 'http://', $endpoint) : $endpoint;
        $params['oauth_signature'] = $this->oauthSignature($method, $signatureBase, $params);

        return $endpoint . '?' . $this->buildQuery($params);
    }

    /**
     * WooCommerce (WC_REST_Authentication::check_oauth_signature) ile birebir: parametreler ham anahtara
     * göre sıralanır, anahtar ve değer RFC 3986 kodlanır, "anahtar=değer" çifti yeniden kodlanır, çiftler
     * %26 ile birleşir; taban metin METOT&kodlu(URL)&çiftler; anahtar consumer_secret + '&'.
     *
     * @param array<string, string> $params
     */
    private function oauthSignature(string $method, string $url, array $params): string
    {
        unset($params['oauth_signature']);
        uksort($params, 'strcmp');

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = self::rfc3986(self::rfc3986((string) $key) . '=' . self::rfc3986($value));
        }
        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . implode('%26', $pairs);

        return base64_encode(hash_hmac('sha256', $base, $this->consumerSecret . '&', true));
    }

    private static function rfc3986(string $value): string
    {
        return str_replace(['+', '%7E'], [' ', '~'], rawurlencode($value));
    }

    /**
     * Sorgu parametrelerini düz metne indirger (imza ile sunucunun gördüğü değer aynı olsun):
     * bool → 'true'/'false', dizi → virgüllü liste, null → atlanır.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function flattenParams(array $params): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $flat[(string) $key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $flat[(string) $key] = implode(',', array_map('strval', array_filter($value, 'is_scalar')));
            } else {
                $flat[(string) $key] = (string) $value;
            }
        }
        return $flat;
    }

    /** @param array<string, string> $params */
    private function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        return implode('&', $parts);
    }

    /** @return list<string> denenecek kimlik doğrulama yöntemleri (ilk = birincil) */
    private function authModes(): array
    {
        $forced = strtolower(trim((string) ($this->extraConfig['auth_mode'] ?? '')));
        if ($forced === self::AUTH_OAUTH || $forced === self::AUTH_QUERY) {
            return [$forced];
        }
        if (isset($this->authModeCache[$this->cacheKey])) {
            return [$this->authModeCache[$this->cacheKey]];
        }
        return str_starts_with(strtolower($this->storeUrl), 'https://')
            ? [self::AUTH_QUERY, self::AUTH_OAUTH, self::AUTH_OAUTH_HTTP]
            : [self::AUTH_OAUTH];
    }

    private function activeAuthMode(): string
    {
        return $this->authModeCache[$this->cacheKey] ?? $this->authModes()[0];
    }

    /** 401 = anahtar tanınmadı (yöntem değişince düzelebilir). İzin eksikliği (yazma/okuma) yöntemle düzelmez. */
    private function isAuthRejected(RuntimeException $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, '(401)') && !str_contains($message, 'permissions');
    }

    /** Sayfa-tabanlı toplama (per_page dolu geldiği sürece devam). @return array<int,array<string,mixed>> */
    private function paginate(string $path, array $params = [], int $perPage = 100, int $maxPages = 20): array
    {
        $all = [];
        $page = 1;
        do {
            $p = $params;
            $p['per_page'] = $perPage;
            $p['page'] = $page;
            $rows = $this->request('GET', $path, $p);
            $count = 0;
            foreach ($rows as $r) {
                if (is_array($r)) { $all[] = $r; $count++; }
            }
            $page++;
        } while ($count === $perPage && $page <= $maxPages);
        return $all;
    }

    /** WooCommerce hata metnine yapılacak işi ekler (kullanıcıya gösterilir). */
    private function explainError(string $message): string
    {
        $hints = [
            'Invalid signature'               => "OAuth imzası tutmadı: mağaza adresi WordPress › Ayarlar › Genel'deki site adresiyle birebir aynı olmalı (http/https, www, alt klasör).",
            'Consumer key is invalid'         => 'Consumer Key geçersiz ya da silinmiş.',
            'Consumer secret is invalid'      => 'Consumer Secret hatalı.',
            'Invalid timestamp'               => 'Sunucu saatleri arasında 15 dakikadan fazla fark var.',
            'does not have write permissions' => 'API anahtarının yazma izni yok (WooCommerce › Ayarlar › Gelişmiş › REST API › Okuma/Yazma).',
            'does not have read permissions'  => 'API anahtarının okuma izni yok.',
            'woocommerce_rest_cannot_view'    => 'WooCommerce anahtarı tanımadı: Consumer Key/Secret ve mağaza adresini kontrol edin.',
            'rest_no_route'                   => 'WooCommerce REST API bulunamadı: WooCommerce etkin mi, kalıcı bağlantılar "Düz" dışında bir ayarda mı?',
            '(301)'                           => 'Mağaza adresi başka bir adrese yönlendiriyor (ör. http→https ya da www). Adresi WordPress site adresiyle birebir girin.',
            '(302)'                           => 'Mağaza adresi başka bir adrese yönlendiriyor (ör. http→https ya da www). Adresi WordPress site adresiyle birebir girin.',
        ];
        foreach ($hints as $needle => $hint) {
            if (str_contains($message, $needle)) {
                return $hint . ' [' . mb_substr($message, 0, 300) . ']';
            }
        }
        // İpucu yoksa da ham yanıt gövdesi kullanıcıya kesilmeden yansımasın
        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    /** "API hatası (400): {"code":..,"message":".."}" → okunur WooCommerce mesajı. */
    private function wooErrorMessage(string $message): string
    {
        if (preg_match('/\{.*\}/s', $message, $m) === 1) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && !empty($decoded['message'])) {
                return $this->decodeText(strip_tags((string) $decoded['message']));
            }
        }
        return $message;
    }

    // ═══════════════════════════════════════════════════════
    // SKU ÖNBELLEĞİ (mağaza anahtarlı)
    // ═══════════════════════════════════════════════════════

    /**
     * SKU → kimlik. Önce önbellek, sonra ?sku= araması (varyasyonlar dahil). WooCommerce araması
     * varyasyon döndürmüyorsa (eski sürüm) bir kez tüm ürünler + varyasyonlar taranır.
     *
     * @param list<string> $skus
     * @return array<string, array{product_id:int, variation_id:int, parent_id:int, type:string, sku:string}> küçük harf SKU → kimlik
     */
    private function findSkuEntries(array $skus): array
    {
        $wanted = [];
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if ($sku !== '') {
                $wanted[$this->skuKey($sku)] = $sku;
            }
        }

        $missing = array_values(array_diff_key($wanted, $this->skuCache[$this->cacheKey] ?? []));
        if ($missing !== []) {
            $this->lookupSkus($missing);
        }

        $stillMissing = array_diff_key($wanted, $this->skuCache[$this->cacheKey] ?? []);
        if ($stillMissing !== []
            && empty($this->skuScanDone[$this->cacheKey])
            && empty($this->skuLookupSeesVariations[$this->cacheKey])) {
            $this->scanSkuMap();
        }

        return array_intersect_key($this->skuCache[$this->cacheKey] ?? [], $wanted);
    }

    /**
     * ?sku=a,b,c ile güncel WooCommerce kayıtlarını getirir (ürün ve varyasyon) ve önbelleğe ekler.
     *
     * @param list<string> $skus
     * @return array<string, array<string, mixed>> küçük harf SKU → ham WooCommerce kaydı
     */
    private function lookupSkus(array $skus): array
    {
        $unique = [];
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if ($sku !== '') {
                $unique[$this->skuKey($sku)] = $sku;
            }
        }

        $plain = array_values(array_filter($unique, static fn(string $sku): bool => !str_contains($sku, ',')));
        $withComma = array_values(array_filter($unique, static fn(string $sku): bool => str_contains($sku, ',')));

        $rows = [];
        foreach (array_chunk($plain, self::SKU_LOOKUP_CHUNK) as $chunk) {
            array_push($rows, ...$this->listRows($this->request('GET', '/products', ['sku' => implode(',', $chunk), 'per_page' => 100, 'status' => 'any'])));
        }
        // WooCommerce sku parametresini virgülden böler → virgüllü SKU kısmi aramayla bulunup birebir süzülür
        foreach ($withComma as $sku) {
            array_push($rows, ...$this->listRows($this->request('GET', '/products', ['search_sku' => $sku, 'per_page' => 100, 'status' => 'any'])));
        }

        $found = [];
        foreach ($rows as $row) {
            $key = $this->skuKey((string) ($row['sku'] ?? ''));
            if ($key === '' || !isset($unique[$key])) {
                continue;
            }
            $this->rememberProduct($row);
            $found[$key] = $row;
        }
        return $found;
    }

    /** Eski WooCommerce yedeği: tüm ürünler + varyasyonlar (en çok 2000 ürün) önbelleğe alınır. */
    private function scanSkuMap(): void
    {
        foreach ($this->paginate('/products', ['status' => 'any'], 100, 20) as $product) {
            $this->rememberProduct($product);
            if ((string) ($product['type'] ?? 'simple') !== 'variable') {
                continue;
            }
            $pid = (int) ($product['id'] ?? 0);
            try {
                foreach ($this->paginate("/products/{$pid}/variations", [], 100, 5) as $variation) {
                    $this->rememberVariation($variation, $pid);
                }
            } catch (\Throwable) {
                // varyasyon çekilemezse bu ürünü atla
            }
        }
        $this->skuScanDone[$this->cacheKey] = true;
    }

    /** @param array<string, mixed> $row ürün ya da varyasyon (products uçlarından) */
    private function rememberProduct(array $row): void
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        $id = (int) ($row['id'] ?? 0);
        if ($sku === '' || $id <= 0) {
            return;
        }
        $type = (string) ($row['type'] ?? 'simple');
        $parentId = (int) ($row['parent_id'] ?? 0);
        if ($type === 'variation' && $parentId > 0) {
            $this->skuLookupSeesVariations[$this->cacheKey] = true;
            $this->skuCache[$this->cacheKey][$this->skuKey($sku)] = ['product_id' => $parentId, 'variation_id' => $id, 'parent_id' => $parentId, 'type' => 'variation', 'sku' => $sku];
            return;
        }
        $this->skuCache[$this->cacheKey][$this->skuKey($sku)] = ['product_id' => $id, 'variation_id' => 0, 'parent_id' => $id, 'type' => $type, 'sku' => $sku];
    }

    /** @param array<string, mixed> $row varyasyon (/products/{id}/variations) */
    private function rememberVariation(array $row, int $parentId): void
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        $id = (int) ($row['id'] ?? 0);
        if ($sku !== '' && $id > 0 && $parentId > 0) {
            $this->skuCache[$this->cacheKey][$this->skuKey($sku)] = ['product_id' => $parentId, 'variation_id' => $id, 'parent_id' => $parentId, 'type' => 'variation', 'sku' => $sku];
        }
    }

    private function forgetSku(string $sku): void
    {
        unset($this->skuCache[$this->cacheKey][$this->skuKey($sku)]);
    }

    private function skuKey(string $sku): string
    {
        return mb_strtolower(trim($sku), 'UTF-8');
    }

    /**
     * WooCommerce ürün id ipucu (marketplace_product_id / wc_product_id): SKU bulunamazsa bu ürün güncellenir
     * (SKU değişmiş olabilir). Yerel ürün id'siyle karışmasın diye genel 'product_id' anahtarı okunmaz.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function productByHint(array $item): ?array
    {
        $hint = $item['marketplace_product_id'] ?? $item['wc_product_id'] ?? null;
        $id = is_numeric($hint) ? (int) $hint : 0;
        if ($id <= 0) {
            return null;
        }
        try {
            $row = $this->request('GET', "/products/{$id}");
        } catch (\Throwable) {
            return null;
        }
        return ((int) ($row['id'] ?? 0)) === $id ? $row : null;
    }

    // ═══════════════════════════════════════════════════════
    // TOPLU İŞ YÜRÜTME
    // ═══════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $body
     * @return array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int}
     */
    private function job(string $endpoint, string $operation, array $body, int $parentId): array
    {
        return ['endpoint' => $endpoint, 'op' => $operation, 'body' => $body, 'parent_id' => $parentId];
    }

    /**
     * İşleri uç + işlem başına toplu isteklere böler (≤100; görsel indirtenlerde ≤10).
     *
     * @param array<int, array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int}> $jobs
     * @return array<int, array{ok: bool, row: ?array, error: string, code: string, action: string, warning: ?string}>
     */
    private function runJobs(array $jobs): array
    {
        $groups = [];
        foreach ($jobs as $index => $job) {
            $groups[$job['endpoint'] . '|' . $job['op']][$index] = $job;
        }

        $outcomes = [];
        foreach ($groups as $group) {
            $first = reset($group);
            $operation = $first['op'];
            $action = $operation === 'create' ? 'created' : 'updated';
            foreach ($this->chunkJobs($group) as $chunk) {
                $bodies = array_values(array_map(static fn(array $job): array => $job['body'], $chunk));
                try {
                    $response = $this->request('POST', $first['endpoint'], [], [$operation => $bodies], $this->hasImageDownloads($bodies) ? self::BATCH_TIMEOUT_SECONDS : null);
                } catch (\Throwable $e) {
                    foreach (array_keys($chunk) as $index) {
                        $outcomes[$index] = $this->outcome(false, null, $this->wooErrorMessage($e->getMessage()), '', $action);
                    }
                    continue;
                }
                $rows = is_array($response[$operation] ?? null) ? array_values($response[$operation]) : [];
                $position = 0;
                foreach (array_keys($chunk) as $index) {
                    $outcomes[$index] = $this->batchOutcome($rows[$position] ?? null, $action);
                    $position++;
                }
            }
        }
        return $outcomes;
    }

    /**
     * @param array<int, array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int}> $jobs
     * @return list<array<int, array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int}>>
     */
    private function chunkJobs(array $jobs): array
    {
        $limit = max(1, min(100, (int) ($this->config['api']['batch_limit'] ?? 100)));
        $chunks = [];
        $current = [];
        $withImages = 0;
        foreach ($jobs as $index => $job) {
            $downloads = $this->hasImageDownloads([$job['body']]);
            if ($current !== [] && (count($current) >= $limit || ($downloads && $withImages >= self::IMAGE_BATCH_LIMIT))) {
                $chunks[] = $current;
                $current = [];
                $withImages = 0;
            }
            $current[$index] = $job;
            if ($downloads) {
                $withImages++;
            }
        }
        if ($current !== []) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Geçici olmayan iki hatayı öğe bazında onarır: GTIN başka üründe kayıtlı → GTIN'siz yeniden gönder;
     * oluştururken SKU zaten var (zaman aşımına uğramış önceki deneme/eşzamanlı gönderim) → kaydı bul, güncelle.
     *
     * @param array<int, array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int}> $jobs
     * @param array<int, array{ok: bool, row: ?array, error: string, code: string, action: string, warning: ?string}> $outcomes
     * @param callable(int, array<string, mixed>): (array|string) $rebuild bulunan kayıt için güncelleme işi
     * @return array<int, array{ok: bool, row: ?array, error: string, code: string, action: string, warning: ?string}>
     */
    private function repairOutcomes(array &$jobs, array $outcomes, callable $rebuild): array
    {
        foreach ($jobs as $index => $job) {
            $outcome = $outcomes[$index] ?? null;
            if ($outcome === null || $outcome['ok']) {
                continue;
            }

            $warning = null;
            if ($this->isGtinConflict($outcome) && isset($job['body']['global_unique_id'])) {
                unset($job['body']['global_unique_id']);
                $jobs[$index] = $job;
                $warning = 'GTIN/EAN başka bir WooCommerce ürününde kayıtlı; ürün GTIN olmadan gönderildi';
                $outcome = $this->runJobs([$index => $job])[$index];
            }

            if (!$outcome['ok'] && $job['op'] === 'create' && $this->isSkuConflict($outcome)) {
                $sku = (string) ($job['body']['sku'] ?? '');
                try {
                    $raw = $this->lookupSkus([$sku])[$this->skuKey($sku)] ?? null;
                } catch (\Throwable) {
                    $raw = null;
                }
                if ($raw !== null) {
                    $retry = $rebuild($index, $raw);
                    if (is_string($retry)) {
                        $outcome = $this->outcome(false, null, $retry, '', 'created');
                    } else {
                        if ($warning !== null) {
                            unset($retry['body']['global_unique_id']);
                        }
                        $jobs[$index] = $retry;
                        $outcome = $this->runJobs([$index => $retry])[$index];
                    }
                } elseif (($trashed = $this->trashedSkuMessage($sku)) !== null) {
                    $outcome = $this->outcome(false, null, $trashed, $outcome['code'], 'created');
                }
            }

            if ($outcome['ok'] && $warning !== null) {
                $outcome['warning'] = $warning;
            }
            $outcomes[$index] = $outcome;
        }
        return $outcomes;
    }

    /** @return array{ok: bool, row: ?array, error: string, code: string, action: string, warning: ?string} */
    private function batchOutcome(mixed $row, string $action): array
    {
        if (!is_array($row)) {
            return $this->outcome(false, null, 'WooCommerce bu öğe için yanıt döndürmedi', '', $action);
        }
        if (!empty($row['error'])) {
            $error = is_array($row['error']) ? $row['error'] : ['message' => (string) $row['error']];
            $message = $this->decodeText(strip_tags((string) ($error['message'] ?? 'Bilinmeyen hata')));
            return $this->outcome(false, null, $message, (string) ($error['code'] ?? ''), $action);
        }
        if ((int) ($row['id'] ?? 0) <= 0) {
            return $this->outcome(false, null, 'WooCommerce geçersiz yanıt döndürdü', '', $action);
        }
        return $this->outcome(true, $row, '', '', $action);
    }

    /** @return array{ok: bool, row: ?array, error: string, code: string, action: string, warning: ?string} */
    private function outcome(bool $ok, ?array $row, string $error, string $code, string $action): array
    {
        return ['ok' => $ok, 'row' => $row, 'error' => $error, 'code' => $code, 'action' => $action, 'warning' => null];
    }

    /** @param array{error: string, code: string} $outcome */
    private function isGtinConflict(array $outcome): bool
    {
        return str_contains($outcome['code'], 'global_unique_id') || stripos($outcome['error'], 'GTIN') !== false;
    }

    /** @param array{error: string, code: string} $outcome */
    private function isSkuConflict(array $outcome): bool
    {
        return $outcome['code'] === 'product_invalid_sku' || stripos($outcome['error'], 'SKU') !== false;
    }

    /**
     * Oluşturma "SKU zaten var" hatası verdi ama etkin kayıtlarda bulunamadı: WooCommerce çöp kutusundaki
     * ürünün SKU'sunu korur, ?sku= araması (status=any) çöpü göstermez. SKU çöpteki bir kayıttaysa
     * kullanıcıya ne yapacağını söyleyen hata metni; değilse (ya da sorgu başarısızsa) null.
     */
    private function trashedSkuMessage(string $sku): ?string
    {
        $sku = trim($sku);
        if ($sku === '' || str_contains($sku, ',')) {
            return null;
        }

        try {
            $rows = $this->listRows($this->request('GET', '/products', ['sku' => $sku, 'status' => 'trash', 'per_page' => 10]));
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            if ($this->skuKey((string) ($row['sku'] ?? '')) === $this->skuKey($sku)) {
                return "Stok kodu WooCommerce'te çöp kutusundaki bir üründe (#" . (int) ($row['id'] ?? 0) . ') kayıtlı. '
                    . "WooCommerce › Ürünler › Çöp Kutusu'ndan ürünü geri yükleyin (yeniden gönderimde güncellenir) "
                    . 'ya da kalıcı olarak silin, sonra tekrar gönderin.';
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $bodies */
    private function hasImageDownloads(array $bodies): bool
    {
        foreach ($bodies as $body) {
            foreach ((array) ($body['images'] ?? []) as $image) {
                if (is_array($image) && !empty($image['src'])) {
                    return true;
                }
            }
            if (is_array($body['image'] ?? null) && !empty($body['image']['src'])) {
                return true;
            }
        }
        return false;
    }

    // ═══════════════════════════════════════════════════════
    // GÖNDERİM YÜKLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $raw WooCommerce'teki aynı SKU'lu kayıt
     * @return array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int}|string
     */
    private function simpleJob(array $item, ?array $raw): array|string
    {
        if ($raw === null) {
            if ($this->itemName($item) === '') {
                return 'Ürün adı zorunlu';
            }
            return $this->job('/products/batch', 'create', $this->simplePayload($item, null), 0);
        }

        $id = (int) ($raw['id'] ?? 0);
        $type = (string) ($raw['type'] ?? 'simple');
        if ($type === 'variable') {
            return "WooCommerce'te bu stok kodu varyantlı bir ana ürüne ait (#{$id}); ürünü varyantlarıyla gönderin";
        }
        if ($type === 'variation') {
            $parentId = (int) ($raw['parent_id'] ?? 0);
            if ($parentId <= 0) {
                return 'Varyasyonun ana ürünü bulunamadı';
            }
            return $this->job("/products/{$parentId}/variations/batch", 'update', ['id' => $id] + $this->variationPayload($item, $raw, null), $parentId);
        }
        return $this->job('/products/batch', 'update', ['id' => $id] + $this->simplePayload($item, $raw), 0);
    }

    /**
     * Basit ürün gövdesi. Güncellemede boş alanlar gönderilmez (WooCommerce'teki veri silinmez); durum
     * (yayında/taslak) yalnız açıkça istenirse değişir; görseller yalnız değiştiyse gönderilir.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>
     */
    private function simplePayload(array $item, ?array $raw): array
    {
        $body = [];
        $sku = $this->itemSku($item);
        $status = $this->itemStatus($item);
        if ($raw === null) {
            $body['type'] = 'simple';
            $body['status'] = $status ?? 'publish';
            $body['sku'] = $this->text($sku);
        } else {
            if ($status !== null) {
                $body['status'] = $status;
            }
            if ($this->skuKey((string) ($raw['sku'] ?? '')) !== $this->skuKey($sku)) {
                $body['sku'] = $this->text($sku);
            }
        }

        $name = $this->itemName($item);
        if ($name !== '') {
            $body['name'] = $this->text($name);
        }
        $description = $this->firstText($item, ['description']);
        if ($description !== '') {
            $body['description'] = $this->text($description);
        }
        $short = $this->firstText($item, ['short_description', 'shortDescription']);
        if ($short !== '') {
            $body['short_description'] = $this->text($short);
        }

        $body += $this->priceFields($item) ?? [];
        $body += $this->stockFields($item) ?? [];
        $body += $this->physicalFields($item);

        $categories = $this->categoryRefs($item);
        if ($categories !== []) {
            $body['categories'] = $categories;
        }
        $brands = $this->brandRefs($item);
        if ($brands !== []) {
            $body['brands'] = $brands;
        }

        $images = $this->imageDecision($this->itemImageUrls($item), $raw);
        if ($images['images'] !== null) {
            $body['images'] = $images['images'];
        }
        if ($images['meta'] !== null) {
            $body['meta_data'] = [['key' => self::IMAGE_SOURCES_META, 'value' => $images['meta']]];
        }

        return $body;
    }

    /**
     * Varyasyon gövdesi (yeni ya da güncelleme). Görsel: varyantın ilk görseli, yalnız değiştiyse.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $raw
     * @param list<array<string, mixed>>|null $attributes null = öznitelikler değiştirilmez
     * @return array<string, mixed>
     */
    private function variationPayload(array $item, ?array $raw, ?array $attributes): array
    {
        $body = [];
        if ($raw === null) {
            $body['sku'] = $this->text($this->itemSku($item));
            $body['status'] = 'publish';
        }
        if ($attributes !== null && $attributes !== []) {
            $body['attributes'] = $attributes;
        }

        $body += $this->priceFields($item) ?? [];
        $body += $this->stockFields($item) ?? [];
        $body += $this->physicalFields($item);

        $image = $this->imageDecision(array_slice($this->itemImageUrls($item), 0, 1), $raw);
        if ($image['images'] !== null) {
            $body['image'] = $image['images'][0];
        }
        if ($image['meta'] !== null) {
            $body['meta_data'] = [['key' => self::IMAGE_SOURCES_META, 'value' => $image['meta']]];
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $firstItem
     * @param list<array<string, mixed>> $attributes
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>
     */
    private function variableParentPayload(array $group, array $firstItem, string $parentName, string $parentSku, array $attributes, ?array $raw): array
    {
        $body = ['attributes' => $attributes];
        $status = $this->itemStatus($group);
        if ($raw === null) {
            $body['type'] = 'variable';
            $body['status'] = $status ?? 'publish';
            if ($parentSku !== '') {
                $body['sku'] = $this->text($parentSku);
            }
        } else {
            if ((string) ($raw['type'] ?? '') !== 'variable') {
                $body['type'] = 'variable';
            }
            if ($parentSku !== '' && trim((string) ($raw['sku'] ?? '')) === '') {
                $body['sku'] = $this->text($parentSku);
            }
            if ($status !== null) {
                $body['status'] = $status;
            }
        }

        if ($parentName !== '') {
            $body['name'] = $this->text($parentName);
        }
        $description = $this->firstText($group, ['description']) ?: $this->firstText($firstItem, ['description']);
        if ($description !== '') {
            $body['description'] = $this->text($description);
        }
        $short = $this->firstText($group, ['short_description', 'shortDescription']) ?: $this->firstText($firstItem, ['short_description', 'shortDescription']);
        if ($short !== '') {
            $body['short_description'] = $this->text($short);
        }

        $categories = $this->categoryRefs($group) ?: $this->categoryRefs($firstItem);
        if ($categories !== []) {
            $body['categories'] = $categories;
        }
        $brands = $this->brandRefs($group) ?: $this->brandRefs($firstItem);
        if ($brands !== []) {
            $body['brands'] = $brands;
        }

        $urls = $this->imageUrls($group['images'] ?? []) ?: $this->itemImageUrls($firstItem);
        $images = $this->imageDecision($urls, $raw);
        if ($images['images'] !== null) {
            $body['images'] = $images['images'];
        }
        if ($images['meta'] !== null) {
            $body['meta_data'] = [['key' => self::IMAGE_SOURCES_META, 'value' => $images['meta']]];
        }

        return $body;
    }

    /**
     * Ana ürün öznitelikleri: mevcut öznitelikler korunur (genel/pa_ öznitelikler id'leriyle), varyant
     * öznitelikleri aynı adlı olanla birleştirilir (seçenekler birleşimi, variation: true), yenileri eklenir.
     *
     * @param array<int, array{item: array, sku: string, attrs: array<string, string>}> $valid
     * @param array<string, mixed>|null $raw
     * @return list<array<string, mixed>>
     */
    private function mergeParentAttributes(array $valid, ?array $raw): array
    {
        $desired = [];
        foreach ($valid as $v) {
            foreach ($v['attrs'] as $name => $value) {
                $name = (string) $name;
                $key = mb_strtolower($name, 'UTF-8');
                $desired[$key]['name'] ??= $name;
                $desired[$key]['options'][mb_strtolower($value, 'UTF-8')] = $value;
            }
        }

        $out = [];
        $merged = [];
        foreach ((array) ($raw['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $name = (string) ($attribute['name'] ?? '');
            $key = mb_strtolower($name, 'UTF-8');
            $options = [];
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                $options[mb_strtolower((string) $option, 'UTF-8')] = (string) $option;
            }
            $entry = [
                'name'      => $name,
                'position'  => (int) ($attribute['position'] ?? count($out)),
                'visible'   => (bool) ($attribute['visible'] ?? true),
                'variation' => (bool) ($attribute['variation'] ?? false),
            ];
            if ((int) ($attribute['id'] ?? 0) > 0) {
                $entry['id'] = (int) $attribute['id'];
            }
            if (isset($desired[$key])) {
                $options += $desired[$key]['options'];
                $entry['variation'] = true;
                $merged[$key] = true;
            }
            $entry['options'] = array_values($options);
            $out[] = $entry;
        }

        foreach ($desired as $key => $attribute) {
            if (isset($merged[$key])) {
                continue;
            }
            $out[] = [
                'name'      => $this->text($attribute['name']),
                'position'  => count($out),
                'visible'   => true,
                'variation' => true,
                'options'   => array_values($attribute['options']),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $parent kaydedilmiş ana ürün
     * @return array<string, array{id: int, name: string}> küçük harf ad → öznitelik kimliği
     */
    private function attributeRefs(array $parent): array
    {
        $refs = [];
        foreach ((array) ($parent['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && (string) ($attribute['name'] ?? '') !== '') {
                $name = (string) $attribute['name'];
                $refs[mb_strtolower($name, 'UTF-8')] = ['id' => (int) ($attribute['id'] ?? 0), 'name' => $name];
            }
        }
        return $refs;
    }

    /**
     * Varyant öznitelikleri (ad → değer). Kaynaklar: variationAttributes {ad: değer}, attributes [{name, value|option}].
     * Hiçbiri yoksa "Seçenek" = varyant adı (ana ad çıkarılarak) ya da SKU.
     *
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function variationAttributes(array $item, string $parentName): array
    {
        $attributes = [];
        foreach (['variationAttributes', 'variation_attributes', 'variantAttributes'] as $key) {
            if (!is_array($item[$key] ?? null)) {
                continue;
            }
            foreach ($item[$key] as $name => $value) {
                if (is_string($name) && is_scalar($value) && trim((string) $value) !== '' && trim($name) !== '') {
                    $attributes[trim($name)] = trim((string) $value);
                }
            }
        }

        if ($attributes === [] && is_array($item['attributes'] ?? null)) {
            foreach ($item['attributes'] as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $name = trim((string) ($attribute['name'] ?? $attribute['attributeName'] ?? ''));
                $value = $attribute['value'] ?? $attribute['option'] ?? $attribute['attributeValue'] ?? null;
                if ($name !== '' && is_scalar($value) && trim((string) $value) !== '') {
                    $attributes[$name] = trim((string) $value);
                }
            }
        }

        if ($attributes !== []) {
            return $attributes;
        }

        $label = $this->itemName($item);
        if ($parentName !== '' && mb_stripos($label, $parentName, 0, 'UTF-8') === 0) {
            $label = trim(mb_substr($label, mb_strlen($parentName, 'UTF-8'), null, 'UTF-8'), " \t-–—/|:,");
        }
        return [self::FALLBACK_ATTRIBUTE => $label !== '' ? $label : $this->itemSku($item)];
    }

    /**
     * Görsel kararı. Kaynak URL listesi (sıralı) ürün meta'sında tutulur.
     *  - Yeni kayıt: görseller gönderilir.
     *  - Liste meta'dakiyle aynı: gönderilmez (her güncellemede yeniden indirtme yok).
     *  - Meta yok ama kayıtta görsel var (WooCommerce'te elle yüklenmiş): dokunulmaz, yalnız liste yazılır;
     *    sonraki değişiklikler gönderilir.
     *  - Liste değişti / kayıtta görsel yok: gönderilir. Daha önce yüklenmiş kaynaklar (meta sırası ↔ kayıttaki
     *    görsel sırası) ve WooCommerce'teki adresiyle verilenler ek id ile gönderilir, yeniden indirilmez.
     *
     * @param list<string> $urls
     * @param array<string, mixed>|null $raw
     * @return array{images: ?list<array<string, mixed>>, meta: ?string}
     */
    private function imageDecision(array $urls, ?array $raw): array
    {
        if ($urls === []) {
            return ['images' => null, 'meta' => null];
        }
        $meta = (string) json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($raw === null) {
            return ['images' => $this->imagePayload($urls, []), 'meta' => $meta];
        }

        $stored = $this->storedImageSources($raw);
        if ($stored === $urls) {
            return ['images' => null, 'meta' => null];
        }

        $current = $this->currentImages($raw);
        if ($stored === null && $current !== []) {
            return ['images' => null, 'meta' => $meta];
        }

        $known = [];
        foreach ($current as $image) {
            $known[$this->comparableUrl($image['src'])] = $image['id'];
        }
        if ($stored !== null && count($stored) === count($current)) {
            foreach ($stored as $position => $source) {
                $known[$this->comparableUrl($source)] = $current[$position]['id'];
            }
        }

        return ['images' => $this->imagePayload($urls, $known), 'meta' => $meta];
    }

    /**
     * @param list<string> $urls
     * @param array<string, int> $known karşılaştırılabilir URL → WooCommerce ek (attachment) id
     * @return list<array<string, mixed>>
     */
    private function imagePayload(array $urls, array $known): array
    {
        $payload = [];
        foreach ($urls as $url) {
            $id = $known[$this->comparableUrl($url)] ?? 0;
            $payload[] = $id > 0 ? ['id' => $id] : ['src' => $url];
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>|null meta'daki kaynak listesi (yoksa null)
     */
    private function storedImageSources(array $raw): ?array
    {
        $value = $this->metaValue($raw, self::IMAGE_SOURCES_META);
        if ($value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_string'))) : [];
    }

    /**
     * @param array<string, mixed> $raw ürün (images[]) ya da varyasyon (image{})
     * @return list<array{id: int, src: string}>
     */
    private function currentImages(array $raw): array
    {
        $images = is_array($raw['images'] ?? null) ? $raw['images'] : [];
        if ($images === [] && is_array($raw['image'] ?? null)) {
            $images = [$raw['image']];
        }

        $out = [];
        foreach ($images as $image) {
            if (is_array($image) && (int) ($image['id'] ?? 0) > 0) {
                $out[] = ['id' => (int) $image['id'], 'src' => (string) ($image['src'] ?? '')];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $raw */
    private function metaValue(array $raw, string $key): string
    {
        foreach ((array) ($raw['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && (string) ($meta['key'] ?? '') === $key && is_scalar($meta['value'] ?? null)) {
                return (string) $meta['value'];
            }
        }
        return '';
    }

    // ═══════════════════════════════════════════════════════
    // ÖĞE ALANLARI
    // ═══════════════════════════════════════════════════════

    /** @param array<string, mixed> $item */
    private function itemSku(array $item): string
    {
        return $this->firstText($item, ['sku', 'stockCode', 'stock_code', 'merchantSku', 'merchant_sku']);
    }

    /** @param array<string, mixed> $item */
    private function itemBarcode(array $item): string
    {
        return $this->firstText($item, ['barcode', 'gtin', 'ean']);
    }

    /** @param array<string, mixed> $item */
    private function itemName(array $item): string
    {
        return $this->firstText($item, ['name', 'title', 'productName']);
    }

    /** @param array<string, mixed> $item */
    private function itemStatus(array $item): ?string
    {
        $status = strtolower($this->firstText($item, ['status']));
        return in_array($status, ['publish', 'draft', 'pending', 'private'], true) ? $status : null;
    }

    /**
     * Stok/fiyat güncellemesinde aranacak anahtarlar (öncelik sırası): pazaryeri SKU'su, sonra barkod.
     *
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function itemLookupKeys(array $item): array
    {
        $keys = [];
        foreach (['sku', 'stockCode', 'merchantSku', 'barcode'] as $field) {
            $value = $item[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $keys[$this->skuKey((string) $value)] = trim((string) $value);
            }
        }
        return array_values($keys);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{sku: string, barcode: string, error: string}
     */
    private function itemError(array $item, string $message): array
    {
        $sku = trim((string) (is_scalar($item['sku'] ?? null) ? $item['sku'] : ''));
        $barcode = trim((string) (is_scalar($item['barcode'] ?? null) ? $item['barcode'] : ''));
        $label = $sku !== '' ? $sku : $barcode;
        return [
            'sku'     => $sku,
            'barcode' => $barcode,
            'error'   => $label !== '' && !str_contains($message, $label) ? "{$label}: {$message}" : $message,
        ];
    }

    /**
     * Fiyat alanları (WooCommerce: metin, ondalık nokta). İndirim yoksa sale_price "" (eski indirim temizlenir).
     *
     * @param array<string, mixed> $item
     * @return array{regular_price: string, sale_price: string}|null
     */
    private function priceFields(array $item): ?array
    {
        $sale = $this->firstNumber($item, ['salePrice', 'sale_price', 'price']) ?? 0.0;
        $list = $this->firstNumber($item, ['listPrice', 'list_price', 'regular_price']) ?? 0.0;
        if ($sale <= 0 && $list <= 0) {
            return null;
        }
        $regular = $sale > 0 ? max($sale, $list) : $list;

        return [
            'regular_price' => $this->money($regular),
            'sale_price'    => ($sale > 0 && $list > $sale) ? $this->money($sale) : '',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{manage_stock: bool, stock_quantity: int}|null
     */
    private function stockFields(array $item): ?array
    {
        $quantity = $this->firstNumber($item, ['quantity', 'stock_quantity', 'stockQuantity', 'stock']);
        return $quantity === null ? null : ['manage_stock' => true, 'stock_quantity' => (int) $quantity];
    }

    /**
     * Ağırlık / ölçüler / GTIN (yalnız dolu olanlar).
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function physicalFields(array $item): array
    {
        $fields = [];
        $weight = $this->firstNumber($item, ['weight', 'weight_kg']);
        if ($weight !== null && $weight > 0) {
            $fields['weight'] = (string) round($weight, 3);
        }
        if (is_array($item['dimensions'] ?? null)) {
            $dimensions = [];
            foreach (['length', 'width', 'height'] as $axis) {
                $value = $this->firstNumber($item['dimensions'], [$axis]);
                if ($value !== null && $value > 0) {
                    $dimensions[$axis] = (string) round($value, 2);
                }
            }
            if ($dimensions !== []) {
                $fields['dimensions'] = $dimensions;
            }
        }
        $gtin = $this->validGtin($this->itemBarcode($item));
        if ($gtin !== '') {
            $fields['global_unique_id'] = $gtin;
        }
        return $fields;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{id: int}>
     */
    private function categoryRefs(array $item): array
    {
        return $this->termRefs($item, ['categories', 'categoryIds', 'category_ids'], ['categoryId', 'category_id']);
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{id: int}>
     */
    private function brandRefs(array $item): array
    {
        return $this->termRefs($item, ['brands', 'brandIds', 'brand_ids'], ['brandId', 'brand_id']);
    }

    /**
     * WooCommerce terim (kategori/marka) id listesi: liste alanları ([12, 15] ya da [{id: 12}]) + tekil alanlar.
     *
     * @param array<string, mixed> $item
     * @param list<string> $listKeys
     * @param list<string> $singleKeys
     * @return list<array{id: int}>
     */
    private function termRefs(array $item, array $listKeys, array $singleKeys): array
    {
        $ids = [];
        foreach ($listKeys as $key) {
            foreach ((array) ($item[$key] ?? []) as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? null) : $entry;
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        foreach ($singleKeys as $key) {
            if (is_numeric($item[$key] ?? null)) {
                $ids[] = (int) $item[$key];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        return array_map(static fn(int $id): array => ['id' => $id], $ids);
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function itemImageUrls(array $item): array
    {
        $urls = $this->imageUrls($item['images'] ?? []);
        foreach (['image', 'main_image'] as $key) {
            if ($urls === [] && isset($item[$key])) {
                $urls = $this->imageUrls([$item[$key]]);
            }
        }
        return $urls;
    }

    /** @return list<string> geçerli http(s) görsel adresleri (sıra korunur, tekrarsız) */
    private function imageUrls(mixed $images): array
    {
        $urls = [];
        foreach (is_array($images) ? $images : [$images] as $image) {
            $url = is_array($image) ? (string) ($image['url'] ?? $image['src'] ?? '') : (is_string($image) ? $image : '');
            $url = trim($url);
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $urls[$url] = true;
            }
        }
        return array_map('strval', array_keys($urls));
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     */
    private function firstText(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     */
    private function firstNumber(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $item[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
            if (is_string($value)) {
                $normalized = str_replace(',', '.', trim($value));
                if ($normalized !== '' && is_numeric($normalized)) {
                    return (float) $normalized;
                }
            }
        }
        return null;
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /** 8-14 haneli GTIN/EAN/UPC/ISBN; değilse boş. */
    private function validGtin(string $barcode): string
    {
        $barcode = trim($barcode);
        return preg_match('/^\d{8,14}$/', $barcode) === 1 ? $barcode : '';
    }

    /** Geçersiz UTF-8 (eski veriler) JSON kodlamayı bozmasın. */
    private function text(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1254');
    }

    private function decodeText(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ═══════════════════════════════════════════════════════
    // SONUÇ BİÇİMİ
    // ═══════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function resultRow(string $sku, string $barcode, string $error): array
    {
        return [
            'sku'                    => $sku,
            'barcode'                => $barcode,
            'status'                 => 'FAILED',
            'marketplace_product_id' => null,
            'parent_id'              => null,
            'error'                  => $error,
            'action'                 => null,
            'permalink'              => '',
            'warning'                => null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array{ok: bool, row: ?array, error: string, code: string, action: string, warning: ?string}|null $outcome
     * @param array{endpoint: string, op: string, body: array<string, mixed>, parent_id: int} $job
     * @return array<string, mixed>
     */
    private function resultFromOutcome(array $item, ?array $outcome, array $job): array
    {
        $sku = $this->itemSku($item);
        $barcode = $this->itemBarcode($item);
        if ($outcome === null || !$outcome['ok'] || $outcome['row'] === null) {
            return $this->resultRow($sku, $barcode, $outcome['error'] ?? 'WooCommerce yanıtı alınamadı');
        }

        $row = $outcome['row'];
        $id = (int) $row['id'];
        $parentId = $job['parent_id'];
        if ($parentId > 0) {
            $this->rememberVariation(['sku' => $sku, 'id' => $id], $parentId);
        } else {
            $this->rememberProduct($row);
        }

        return [
            'sku'                    => $sku,
            'barcode'                => $barcode,
            'status'                 => 'SUCCESS',
            'marketplace_product_id' => (string) $id,
            'parent_id'              => $parentId > 0 ? (string) $parentId : null,
            'error'                  => null,
            'action'                 => $outcome['action'],
            'permalink'              => (string) ($row['permalink'] ?? ''),
            'warning'                => $outcome['warning'],
        ];
    }

    /** @return array<string, mixed> */
    private function parentRow(string $sku, ?string $id, ?string $error, ?string $action): array
    {
        return [
            'sku'                    => $sku,
            'status'                 => $error === null ? 'SUCCESS' : 'FAILED',
            'marketplace_product_id' => $id,
            'error'                  => $error,
            'action'                 => $action,
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array{success: bool, results: list<array<string, mixed>>, errors: list<array{sku: string, barcode: string, error: string}>, sent: int, failed: int, created: int, updated: int}
     */
    private function publishSummary(array $results): array
    {
        $errors = [];
        $sent = 0;
        $created = 0;
        $updated = 0;
        foreach ($results as $result) {
            if ($result['status'] === 'SUCCESS') {
                $sent++;
                $result['action'] === 'created' ? $created++ : $updated++;
                continue;
            }
            $label = $result['sku'] !== '' ? $result['sku'] : (string) $result['barcode'];
            $errors[] = [
                'sku'     => (string) $result['sku'],
                'barcode' => (string) $result['barcode'],
                'error'   => ($label !== '' ? "{$label}: " : '') . (string) $result['error'],
            ];
        }

        return [
            'success' => $results !== [] && $errors === [],
            'results' => $results,
            'errors'  => $errors,
            'sent'    => $sent,
            'failed'  => count($errors),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array{success: bool, results: list<array<string, mixed>>, errors: list<array{sku: string, barcode: string, error: string}>, sent: int, failed: int, created: int, updated: int}
     */
    private function failAll(array $items, string $error): array
    {
        $results = [];
        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $results[] = $this->resultRow($this->itemSku($item), $this->itemBarcode($item), $error);
        }
        return $this->publishSummary($results);
    }

    // ═══════════════════════════════════════════════════════
    // YARDIMCILAR
    // ═══════════════════════════════════════════════════════

    private function activeStoreId(): int
    {
        if ($this->tenant === null || !$this->tenant->hasStore()) {
            return 0;
        }
        return $this->tenant->currentStoreId();
    }

    private function resetCredentials(): void
    {
        $this->consumerKey    = '';
        $this->consumerSecret = '';
        $this->storeUrl       = '';
        $this->baseUrl        = '';
        $this->extraConfig    = [];
        $this->cacheKey       = '';
        $this->credsStore     = null;
        $this->credsLoadedAt  = 0;
        $this->curlResolve    = [];
    }

    /**
     * SKU/kimlik doğrulama önbelleklerinde yalnız verilen önekle (aktif mağaza "store|") başlayan anahtarları
     * tutar; başka mağazaların SKU haritaları bırakılır (çok mağazalı döngüde bellek birikmesin).
     */
    private function trimCachesTo(string $keyPrefix): void
    {
        foreach (['skuCache', 'skuScanDone', 'skuLookupSeesVariations', 'authModeCache'] as $property) {
            foreach (array_keys($this->{$property}) as $key) {
                if (!str_starts_with((string) $key, $keyPrefix)) {
                    unset($this->{$property}[$key]);
                }
            }
        }
    }

    /** Mağaza adresi: şema eksikse https, sondaki /wp-json… ya da /wp-admin… kısmı ve '/' atılır. */
    private function normalizeStoreUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $url = (string) preg_replace('#/(wp-json|wp-admin)(/.*)?$#i', '', $url);
        return rtrim($url, '/');
    }

    /** Karşılaştırma için URL (şema yok sayılır, sondaki '/' atılır, küçük harf). */
    private function comparableUrl(string $url): string
    {
        return strtolower(rtrim((string) preg_replace('#^https?://#i', '', trim($url)), '/'));
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function listRows(array $response): array
    {
        return array_values(array_filter(array_is_list($response) ? $response : [], 'is_array'));
    }

    /** ISO8601 → MySQL datetime (Y-m-d H:i:s), sunucu saat dilimine çevirir. GMT alanı ($isGmt=true) UTC kabul edilir
     *  (Woo date_created_gmt TZ eki taşımaz, çıplak strtotime sunucu-yerel sanıp kaydırır). Boş/geçersiz → null. */
    private function toMysqlDate(?string $iso, bool $isGmt = false): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($iso, $isGmt ? new \DateTimeZone('UTC') : null);
            return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $ts = strtotime($iso);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }
    }

    private function numericId(string $id): int
    {
        if (preg_match('/(\d+)/', $id, $m)) { return (int) $m[1]; }
        return (int) $id;
    }
}
