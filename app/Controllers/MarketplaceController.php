<?php
/**
 * OzyConnect Panel — MarketplaceController
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Marketplace;
use App\Services\CryptoService;
use App\Services\Import\InitialImportService;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\PttavmService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\AmazonService;
use App\Services\Marketplace\IdefixService;
use App\Services\Marketplace\OziygoService;
use App\Services\Marketplace\ShopifyService;
use App\Services\Marketplace\WooCommerceService;
use App\Services\Marketplace\OpenCartService;
use App\Services\Marketplace\MarketplacePurgeService;

final class MarketplaceController extends BaseController
{
    private readonly Marketplace $marketplaceModel;
    private readonly CryptoService $crypto;
    private readonly InitialImportService $initialImportService;
    private readonly TrendyolService $trendyolService;
    private readonly N11Service $n11Service;
    private readonly HepsiburadaService $hepsiburadaService;
    private readonly PazaramaService $pazaramaService;
    private readonly PttavmService $pttavmService;
    private readonly CiceksepetiService $ciceksepetiService;
    private readonly AmazonService $amazonService;
    private readonly IdefixService $idefixService;
    private readonly OziygoService $oziygoService;
    private readonly ShopifyService $shopifyService;
    private readonly WooCommerceService $wooCommerceService;
    private readonly OpenCartService $openCartService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->marketplaceModel    = $container->get(Marketplace::class);
        $this->crypto              = $container->get(CryptoService::class);
        $this->initialImportService = $container->get(InitialImportService::class);
        $this->trendyolService     = $container->get(TrendyolService::class);
        $this->n11Service          = $container->get(N11Service::class);
        $this->hepsiburadaService  = $container->get(HepsiburadaService::class);
        $this->pazaramaService     = $container->get(PazaramaService::class);
        $this->pttavmService       = $container->get(PttavmService::class);
        $this->ciceksepetiService  = $container->get(CiceksepetiService::class);
        $this->amazonService       = $container->get(AmazonService::class);
        $this->idefixService       = $container->get(IdefixService::class);
        $this->oziygoService       = $container->get(OziygoService::class);
        $this->shopifyService      = $container->get(ShopifyService::class);
        $this->wooCommerceService  = $container->get(WooCommerceService::class);
        $this->openCartService     = $container->get(OpenCartService::class);
    }

    /** Aktif mağaza bu pazaryerine YETKİLİ mi? (store_marketplaces entitlement) — yetki yoksa bağlan/test/import yok. */
    private function isEntitled(int $marketplaceId): bool
    {
        return $this->container->get(\App\Services\EntitlementService::class)->canAccessMarketplace($marketplaceId);
    }

    /**
     * Hepsiburada bağlantı bilgisi doğrulaması. İlk bağlantıda (kayıtlı Servis Anahtarı / Mağaza ID yokken)
     * ikisi de zorunlu; Mağaza ID Hepsiburada panelindeki biçimde olmalı. Hata yoksa null.
     *
     * @param array<string, mixed> $mp mağaza bağlantı bilgisi bindirilmiş pazaryeri satırı
     */
    private function validateHepsiburadaCredentials(array $mp, string $apiSecret, string $merchantId): ?string
    {
        $storedExtra = json_decode((string) ($mp['extra_config_json'] ?? '{}'), true);
        $storedMerchantId = is_array($storedExtra) ? trim((string) ($storedExtra['merchant_id'] ?? '')) : '';
        $effectiveMerchantId = $merchantId !== '' ? $merchantId : $storedMerchantId;

        if ($apiSecret === '' && empty($mp['api_secret_encrypted'])) {
            return 'Servis Anahtarı zorunludur (Hepsiburada satıcı paneli › Bilgilerim › Entegrasyon › Entegratör Bilgileri › Servis Anahtarı).';
        }
        if ($effectiveMerchantId === '') {
            return 'Mağaza ID (Merchant ID) zorunludur — Hepsiburada satıcı panelindeki Mağaza ID değerini girin.';
        }
        if (!preg_match('~^[0-9a-fA-F-]{20,64}$~', $effectiveMerchantId)) {
            return 'Mağaza ID biçimi hatalı: Hepsiburada panelindeki "Mağaza ID" değerini (örn. a1b2c3d4-e5f6-7890-abcd-ef1234567890) olduğu gibi yapıştırın.';
        }

        return null;
    }

    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getAllWithStatus();
        $entitledIds  = $this->container->get(\App\Services\EntitlementService::class)->allowedMarketplaceIds();

        // Her pazaryeri için istatistik + mağaza yetkisi (entitlement)
        foreach ($marketplaces as &$mp) {
            $stats = $this->marketplaceModel->getStats((int) $mp['id']);
            $mp['active_products'] = $stats['active_products'];
            $mp['total_orders'] = $stats['total_orders'];
            $mp['monthly_orders'] = $stats['monthly_orders'];
            $mp['entitled'] = in_array((int) $mp['id'], $entitledIds, true);
        }
        unset($mp);

        $this->view('marketplaces.index', [
            'pageTitle'    => 'Pazaryerleri',
            'marketplaces' => $marketplaces,
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $marketplace = $this->marketplaceModel->find((int) $id);
        if ($marketplace === false) {
            $this->response->notFound('Pazaryeri bulunamadı');
        }

        if (!$this->isEntitled((int) $id)) {
            $this->redirectWithError('/marketplaces', 'Bu pazaryeri paketinizde bulunmuyor; önce eklenti olarak eklenmeli.');
            return;
        }

        $viewData = [
            'pageTitle'   => $marketplace['display_name'] . ' Ayarları',
            'marketplace' => $marketplace,
        ];
        // OpenCart: bağlantı token'ını connector'a yapıştırmak için çöz ve görünüme geçir
        // (yalnız düzenleme yetkisi olana — salt-görüntüleme rolü sırrı görmez)
        if (($marketplace['slug'] ?? '') === 'opencart' && !empty($marketplace['api_secret_encrypted']) && $this->canEditMarketplaces()) {
            try { $viewData['opencartToken'] = $this->crypto->decrypt($marketplace['api_secret_encrypted']); }
            catch (\Throwable) { $viewData['opencartToken'] = ''; }
        }
        if (($marketplace['slug'] ?? '') === 'woocommerce') {
            $viewData['wooWebhook'] = $this->wooWebhookViewData($marketplace);
        }
        $this->view('marketplaces.edit', $viewData);
    }

    public function update(Request $request, string $id): void
    {
        $mp = $this->marketplaceModel->find((int) $id);
        if ($mp === false) {
            $this->response->notFound('Pazaryeri bulunamadı');
        }

        if (!$this->isEntitled((int) $id)) {
            $this->redirectWithError('/marketplaces', 'Bu pazaryeri paketinizde bulunmuyor; önce eklenti olarak eklenmeli.');
            return;
        }

        $apiKey    = $request->post('api_key', '');
        $apiSecret = $request->post('api_secret', '');

        $data = [
            'is_active'    => $request->boolean('is_active') ? 1 : 0,
            'display_name' => $request->post('display_name', $mp['display_name']),
        ];

        // Hepsiburada'da API Key yok: Mağaza ID (Merchant ID) = Basic auth kullanıcı adı, Servis Anahtarı = şifre
        if ($mp['slug'] === 'hepsiburada') {
            $apiSecret = trim((string) $apiSecret);
            $hbError = $this->validateHepsiburadaCredentials($mp, $apiSecret, trim((string) $request->post('merchant_id', '')));
            if ($hbError !== null) {
                $this->redirectWithError('/marketplaces/' . (int) $id . '/edit', $hbError);
                return;
            }
            if ($apiSecret !== '') {
                $data['api_secret_encrypted'] = $this->crypto->encrypt($apiSecret);
            }
        // Shopify: Admin API access token (api_secret) — ayrı API key yok
        } elseif ($mp['slug'] === 'shopify') {
            if ($apiSecret !== '') {
                $data['api_secret_encrypted'] = $this->crypto->encrypt($apiSecret);
            }
            $data['api_key_encrypted'] = null;
        // ÇiçekSepeti / İdefix: Secret opsiyonel, sadece API Key zorunlu
        } elseif ($mp['slug'] === 'pttavm') {
            if ($apiSecret !== '') {
                $data['api_secret_encrypted'] = $this->crypto->encrypt($apiSecret);
            }
            $data['api_key_encrypted'] = null;
        } elseif ($mp['slug'] === 'oziygo') {
            if ($apiKey !== '') {
                $data['api_key_encrypted'] = $this->crypto->encrypt($apiKey);
            }
            $data['api_secret_encrypted'] = null;
        } elseif (in_array($mp['slug'], ['ciceksepeti', 'idefix'], true)) {
            if ($apiKey !== '') {
                $data['api_key_encrypted'] = $this->crypto->encrypt($apiKey);
            }
            if ($apiSecret !== '') {
                $data['api_secret_encrypted'] = $this->crypto->encrypt($apiSecret);
            }
        // WooCommerce: Consumer Key (ck_…) + Consumer Secret (cs_…) birlikte
        } elseif ($mp['slug'] === 'woocommerce') {
            $apiKey    = trim((string) $apiKey);
            $apiSecret = trim((string) $apiSecret);
            $wooError  = self::validateWooCredentials($mp, $apiKey, $apiSecret);
            if ($wooError !== null) {
                $this->redirectWithError('/marketplaces/' . (int) $id . '/edit', $wooError);
                return;
            }
            if ($apiKey !== '') {
                $data['api_key_encrypted']    = $this->crypto->encrypt($apiKey);
                $data['api_secret_encrypted'] = $this->crypto->encrypt($apiSecret);
            }
        } elseif ($apiKey !== '' && $apiSecret !== '') {
            $data['api_key_encrypted']    = $this->crypto->encrypt($apiKey);
            $data['api_secret_encrypted'] = $this->crypto->encrypt($apiSecret);
        } elseif ($apiKey !== '' || $apiSecret !== '') {
            // Yalnız biri doldurulduğunda hiçbiri kaydedilmiyor ama kullanıcı
            // "güncellendi" mesajı görüyordu → sonra "bilgiler girilmemiş" hatası.
            $this->redirectWithError(
                '/marketplaces/' . (int) $id . '/edit',
                'API Key ve API Secret alanlarının İKİSİ birden doldurulmalıdır — hiçbiri kaydedilmedi.'
            );
            return;
        }

        // Extra config (supplier_id, merchant_id, price_adjustment vs.)
        $extraConfig = json_decode($mp['extra_config_json'] ?? '{}', true) ?: [];
        $supplierId = $request->post('supplier_id', '');
        if ($supplierId !== '') {
            $extraConfig['supplier_id'] = $supplierId;
        }

        // Hepsiburada Merchant ID
        $merchantId = trim((string) $request->post('merchant_id', ''));
        if ($merchantId !== '') {
            $extraConfig['merchant_id'] = $merchantId;
        }

        if ($mp['slug'] === 'oziygo') {
            $apiUrl = trim($request->post('api_url', ''));
            if ($apiUrl !== '') {
                $extraConfig['api_url'] = rtrim($apiUrl, '/');
            }
        }

        // Hepsiburada Entegratör Kullanıcı Adı
        if ($mp['slug'] === 'hepsiburada') {
            $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
            $extraConfig['integrator_username'] = $marketplacesConfig['hepsiburada']['developer_username'] ?? 'oziyweb_dev';
        } else {
            $integratorUsername = $request->post('integrator_username', '');
            if ($integratorUsername !== '') {
                $extraConfig['integrator_username'] = $integratorUsername;
            }
        }

        // Fiyat farkı yüzdesi
        $priceAdj = $request->post('price_adjustment_percent');
        if ($priceAdj !== null && $priceAdj !== '') {
            $extraConfig['price_adjustment_percent'] = (float) $priceAdj;
        } else {
            $extraConfig['price_adjustment_percent'] = 0;
        }

        // Kargo ayarları (Trendyol)
        if ($mp['slug'] === 'trendyol') {
            $extraConfig['ty_cargo_payment']  = $request->boolean('ty_cargo_payment') ? 1 : 0;
            $extraConfig['ty_auto_label']     = $request->boolean('ty_auto_label') ? 1 : 0;

            // ty_default_cargo formda YOK; koşulsuz yazıldığında her kayıtta boşa
            // eziliyordu (sipariş ekranında seçilen varsayılan kargo kayboluyordu).
            $varsayilanKargo = $request->post('ty_default_cargo');
            if ($varsayilanKargo !== null && $varsayilanKargo !== '') {
                $extraConfig['ty_default_cargo']  = $varsayilanKargo;
                $extraConfig['ty_cargo_provider'] = $varsayilanKargo;
            }
        }

        // Pazarama: Satıcı ID + panel adresi (fatura bağlantısı için)
        if ($mp['slug'] === 'pazarama') {
            $saticiId = trim((string) $request->post('seller_id', ''));
            if ($saticiId !== '') {
                $extraConfig['seller_id'] = $saticiId;
            }
            $panelAdresi = trim((string) $request->post('app_url', ''));
            if ($panelAdresi !== '') {
                $extraConfig['app_url'] = rtrim($panelAdresi, '/');
            }
        }

        // PttAVM özel ayarları
        if ($mp['slug'] === 'pttavm') {
            $shopId = $request->post('shop_id', '');
            if ($shopId !== '') {
                $extraConfig['shop_id'] = (int) $shopId;
            }
        }

        // ÇiçekSepeti özel ayarları
        if ($mp['slug'] === 'ciceksepeti') {
            $sellerId = $request->post('seller_id', '');
            if ($sellerId !== '') {
                $extraConfig['seller_id'] = $sellerId;
            }
        }

        // İdefix özel ayarları
        if ($mp['slug'] === 'idefix') {
            $vendorId = $request->post('vendor_id', '');
            if ($vendorId !== '') {
                $extraConfig['vendor_id'] = $vendorId;
            }
        }

        // Shopify mağaza alan adı + webhook ayarları (anlık sipariş)
        if ($mp['slug'] === 'shopify') {
            $shopDomain = trim($request->post('shop_domain', ''));
            if ($shopDomain !== '') {
                $shopDomain = (string) preg_replace('#^https?://#i', '', $shopDomain);
                $extraConfig['shop_domain'] = rtrim($shopDomain, '/');
            }
            $webhookSecret = trim($request->post('webhook_secret', ''));
            if ($webhookSecret !== '') {
                $extraConfig['webhook_secret'] = $webhookSecret;
            }
            // Webhook token yoksa üret (anlık webhook URL'si için)
            if (empty($mp['webhook_token'])) {
                $data['webhook_token'] = bin2hex(random_bytes(16));
            }
        }

        // WooCommerce mağaza adresi + webhook ayarları (anlık sipariş)
        if ($mp['slug'] === 'woocommerce') {
            $storeUrlInput = trim((string) $request->post('store_url', ''));
            if ($storeUrlInput !== '') {
                $storeUrl = self::normalizeWooStoreUrl($storeUrlInput);
                if ($storeUrl === null) {
                    $this->redirectWithError(
                        '/marketplaces/' . (int) $id . '/edit',
                        'Mağaza adresi geçersiz. WooCommerce\'in kurulu olduğu sitenin adresini http:// ya da https:// ile yazın (örn. https://magazaniz.com).'
                    );
                    return;
                }
                $extraConfig['store_url'] = $storeUrl;
            } elseif (trim((string) ($extraConfig['store_url'] ?? '')) === '') {
                $this->redirectWithError('/marketplaces/' . (int) $id . '/edit', 'Mağaza adresi (Store URL) zorunludur.');
                return;
            }

            // Webhook gizli anahtarı: girilen değer (değiştiyse doğrulanır); hiç yoksa güçlü bir anahtar üretilir.
            $webhookSecret = trim((string) $request->post('webhook_secret', ''));
            if ($webhookSecret !== '' && $webhookSecret !== (string) ($extraConfig['webhook_secret'] ?? '')) {
                if (strlen($webhookSecret) < 16 || strlen($webhookSecret) > 200 || preg_match('/\s/', $webhookSecret) === 1) {
                    $this->redirectWithError(
                        '/marketplaces/' . (int) $id . '/edit',
                        'Webhook gizli anahtarı 16-200 karakter olmalı ve boşluk içermemeli. Boş bırakırsanız otomatik üretilir.'
                    );
                    return;
                }
                $extraConfig['webhook_secret'] = $webhookSecret;
            }
            if (trim((string) ($extraConfig['webhook_secret'] ?? '')) === '') {
                $extraConfig['webhook_secret'] = self::newWooWebhookSecret();
            }

            // Mağazaya özel webhook adresi anahtarı (tüm mağazalarda benzersiz)
            if (!Marketplace::isWellFormedWebhookToken((string) ($mp['webhook_token'] ?? ''))) {
                $data['webhook_token'] = $this->marketplaceModel->generateStoreWebhookToken();
            }
        }

        // OpenCart connector ayarları (token korumalı DB-direkt köprü)
        if ($mp['slug'] === 'opencart') {
            $connectorUrl = trim($request->post('connector_url', ''));
            if ($connectorUrl !== '') {
                if (!preg_match('#^https?://#i', $connectorUrl)) {
                    $connectorUrl = 'https://' . $connectorUrl;
                }
                $extraConfig['connector_url'] = rtrim($connectorUrl, '/');
            }
            // Bağlantı token'ı yoksa otomatik üret (merchant ozyconnect.php'ye yapıştırır)
            if (empty($mp['api_secret_encrypted'])) {
                $data['api_secret_encrypted'] = $this->crypto->encrypt(bin2hex(random_bytes(20)));
            }
            // Anlık sipariş bildirimi (OpenCart event) tercihleri
            $extraConfig['oc_version'] = (int) $request->post('oc_version', (string) ($extraConfig['oc_version'] ?? 3));
            $extraConfig['oc_instant'] = $request->boolean('oc_instant') ? 1 : 0;
            // Webhook URL'si için token gerekli (anlık bildirim)
            if (empty($mp['webhook_token'])) {
                $data['webhook_token'] = bin2hex(random_bytes(16));
            }
        }

        // Amazon SP-API özel ayarları
        if ($mp['slug'] === 'amazon') {
            $amzSellerId = $request->post('seller_id', '');
            if ($amzSellerId !== '') {
                $extraConfig['seller_id'] = $amzSellerId;
            }
            $amzRefreshToken = $request->post('refresh_token', '');
            if ($amzRefreshToken !== '') {
                $extraConfig['refresh_token'] = $amzRefreshToken;
            }
            $amzAppId = $request->post('app_id', '');
            if ($amzAppId !== '') {
                $extraConfig['app_id'] = $amzAppId;
            }
        }

        $data['extra_config_json'] = json_encode($extraConfig, JSON_UNESCAPED_UNICODE);

        // Bağlantı bilgileri MAĞAZAYA yazılır. (Önceden global `marketplaces` satırına
        // yazılıyordu; bu yüzden bir bayinin girdiği anahtarları TÜM bayiler kullanıyordu.)
        $this->marketplaceModel->saveStoreConfig((int) $id, [
            'is_enabled'           => (bool) $data['is_active'],
            'display_name'         => $data['display_name'] ?? null,
            'extra_config_json'    => $data['extra_config_json'],
        ] + array_intersect_key($data, array_flip([
            'api_key_encrypted',
            'api_secret_encrypted',
            'webhook_token',
        ])));

        // OpenCart anlık sipariş bildirimi: event'i kur/kaldır (en iyi çaba — connector kuruluysa)
        $eventNote = '';
        if ($mp['slug'] === 'opencart') {
            $token  = (string) ($data['webhook_token'] ?? $mp['webhook_token'] ?? '');
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $webhookUrl = ($host !== '' && $token !== '') ? "{$scheme}://{$host}/webhooks/opencart/{$token}" : '';
            try {
                if (!empty($extraConfig['oc_instant']) && $webhookUrl !== '') {
                    $this->openCartService->installEvent($webhookUrl, (int) ($extraConfig['oc_version'] ?? 3));
                    $eventNote = ' Anlık sipariş bildirimi kuruldu.';
                } elseif (empty($extraConfig['oc_instant'])) {
                    $this->openCartService->uninstallEvent();
                }
            } catch (\Throwable $e) {
                $eventNote = ' (Anlık bildirim kurulamadı — önce connector\'ı yükleyip "Test Et" yapın: ' . $e->getMessage() . ')';
            }
        }

        $this->redirectWithSuccess('/marketplaces', 'Pazaryeri ayarları güncellendi.' . $eventNote);
    }

    public function testConnection(Request $request, string $id): void
    {
        $mp = $this->marketplaceModel->find((int) $id);
        if ($mp === false) {
            $this->error('Pazaryeri bulunamadı', 404);
        }

        if (!$this->isEntitled((int) $id)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor. Abonelik › Eklenti Ekle üzerinden ekleyebilirsiniz.']);
            return;
        }

        $result = match ($mp['slug']) {
            'trendyol'    => $this->trendyolService->testConnection(),
            'n11'         => $this->n11Service->testConnection(),
            'hepsiburada' => $this->hepsiburadaService->testConnection(),
            'pazarama'    => $this->pazaramaService->testConnection(),
            'pttavm'      => $this->pttavmService->testConnection(),
            'ciceksepeti' => $this->ciceksepetiService->testConnection(),
            'amazon'      => $this->amazonService->testConnection(),
            'idefix'      => $this->idefixService->testConnection(),
            'oziygo'      => $this->oziygoService->testConnection(),
            'shopify'     => $this->shopifyService->testConnection(),
            'woocommerce' => $this->wooCommerceService->testConnection(),
            'opencart'    => $this->openCartService->testConnection(),
            default       => ['success' => false, 'message' => 'Bu pazaryeri henüz desteklenmiyor'],
        };

        if ($result['success']) {
            // Son bağlantı zamanı MAĞAZANIN yapılandırmasına yazılır. Global katalog satırına
            // (marketplaces.is_active / last_connected_at) dokunulmaz: bir mağazanın testi platformda
            // kapalı bir pazaryerini tüm mağazalar için açıyordu.
            $this->marketplaceModel->updateLastConnected((int) $id);

            $result['marketplace_id'] = (int) $id;
            $result['slug'] = $mp['slug'];
            $result['display_name'] = $mp['display_name'] ?? $mp['slug'];
        }

        $this->json($result);
    }

    // ─── WooCommerce: bağlantı doğrulama + webhook kurulumu ───────────

    /**
     * WooCommerce'te sipariş webhook'larını (order.created + order.updated) mağazaya özel
     * teslim adresi ve webhook gizli anahtarıyla kurar; varsa günceller, yinelenen oluşturmaz.
     * Kayıtlı bağlantı bilgileriyle çalışır. Yanıt JSON.
     */
    public function wooWebhooks(Request $request, string $id): void
    {
        $marketplaceId = (int) $id;
        $mp = $this->marketplaceModel->find($marketplaceId);
        if ($mp === false || ($mp['slug'] ?? '') !== 'woocommerce') {
            $this->json(['success' => false, 'message' => 'WooCommerce bağlantısı bulunamadı.'], 404);
        }

        if (!$this->isEntitled($marketplaceId)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor. Abonelik › Eklenti Ekle üzerinden ekleyebilirsiniz.'], 403);
        }

        $extraConfig = json_decode((string) ($mp['extra_config_json'] ?? '{}'), true) ?: [];
        if (empty($mp['api_key_encrypted']) || empty($mp['api_secret_encrypted']) || trim((string) ($extraConfig['store_url'] ?? '')) === '') {
            $this->json(['success' => false, 'message' => 'Önce mağaza adresini, Consumer Key ve Consumer Secret bilgilerini kaydedin.'], 422);
        }

        try {
            $token  = $this->marketplaceModel->ensureStoreWebhookToken($marketplaceId);
            $secret = (string) ($extraConfig['webhook_secret'] ?? '');
            if (trim($secret) === '') {
                $secret = self::newWooWebhookSecret();
                $extraConfig['webhook_secret'] = $secret;
                $this->marketplaceModel->saveStoreConfig($marketplaceId, [
                    'extra_config_json' => json_encode($extraConfig, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $deliveryUrl = self::wooDeliveryUrl($token);
            $result = $this->wooCommerceService->ensureWebhooks($deliveryUrl, $secret);
        } catch (\Throwable $e) {
            $message = self::maskWooSecrets($e->getMessage());
            $this->logger->warning('WooCommerce webhook kurulamadı: ' . $message, 'Marketplace', ['mp' => $marketplaceId]);
            $this->json(['success' => false, 'message' => 'Webhook kurulamadı: ' . $message], 502);
        }

        $rows    = self::wooWebhookRows($result);
        $success = (bool) ($result['success'] ?? false);
        $hasRowErrors = in_array(true, array_map(static fn(array $row): bool => $row['error'] !== '', $rows), true);

        if ($success) {
            $message = trim((string) ($result['message'] ?? '')) ?: 'Webhook\'lar kuruldu.';
            $message .= ' Yeni ve güncellenen siparişler panele anında düşer.';
        } elseif ($hasRowErrors) {
            // Ayrıntılar konu bazında satırlarda; özet tekrar etmesin.
            $message = 'Webhook\'ların bir kısmı kurulamadı; ayrıntılar aşağıda.';
        } else {
            $message = trim(self::maskWooSecrets((string) ($result['message'] ?? ''))) ?: 'Webhook kurulamadı.';
        }

        $this->json([
            'success'      => $success,
            'message'      => $message,
            'delivery_url' => $deliveryUrl,
            'webhooks'     => $rows,
        ], $success ? 200 : 502);
    }

    /**
     * WooCommerce kimlik doğrulaması. Anahtarlar birlikte girilir; ilk bağlantıda zorunludur.
     * Hata yoksa null.
     *
     * @param array<string, mixed> $mp mağaza bağlantı bilgisi bindirilmiş pazaryeri satırı
     */
    private static function validateWooCredentials(array $mp, string $consumerKey, string $consumerSecret): ?string
    {
        if ($consumerKey === '' && $consumerSecret === '') {
            if (empty($mp['api_key_encrypted']) || empty($mp['api_secret_encrypted'])) {
                return 'Consumer Key ve Consumer Secret zorunludur (WooCommerce › Ayarlar › Gelişmiş › REST API › Anahtar ekle, izin: Okuma/Yazma).';
            }
            return null;
        }
        if ($consumerKey === '' || $consumerSecret === '') {
            return 'Consumer Key ve Consumer Secret birlikte girilmelidir — hiçbiri kaydedilmedi.';
        }
        if (str_starts_with($consumerKey, 'cs_') && str_starts_with($consumerSecret, 'ck_')) {
            return 'Consumer Key ile Consumer Secret yer değiştirmiş görünüyor: "ck_" ile başlayan değer Consumer Key alanına yazılmalı.';
        }
        if (preg_match('/^ck_[A-Za-z0-9]{20,64}$/', $consumerKey) !== 1) {
            return 'Consumer Key biçimi hatalı: WooCommerce\'in verdiği "ck_" ile başlayan değeri olduğu gibi yapıştırın.';
        }
        if (preg_match('/^cs_[A-Za-z0-9]{20,64}$/', $consumerSecret) !== 1) {
            return 'Consumer Secret biçimi hatalı: WooCommerce\'in verdiği "cs_" ile başlayan değeri olduğu gibi yapıştırın.';
        }

        return null;
    }

    /**
     * Mağaza adresini doğrular ve sadeleştirir: yalnız http/https, kullanıcı bilgisi yok, sorgu/parça atılır,
     * yapıştırılan WordPress yönetim/REST yolu (wp-admin, wp-json…) site köküne indirilir, sondaki / atılır.
     * Şema yoksa https varsayılır. Geçersizse null.
     */
    private static function normalizeWooStoreUrl(string $input): ?string
    {
        $url = trim($input);
        if ($url === '' || strlen($url) > 500) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (preg_match('/[^\x20-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host  = is_string($ascii) ? strtolower($ascii) : '';
        }
        $isIpv6 = str_starts_with($host, '[') && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$isIpv6 && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = (string) preg_replace('#/(?:wp-json|wp-admin|wp-login\.php|index\.php)(?:/.*)?$#i', '', $path);
        $path = rtrim($path, '/');
        if (preg_match('#^[A-Za-z0-9/._~%!$&\'()*+,;=:@-]*$#', $path) !== 1) {
            return null;
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
    }

    /** Oturumdaki kullanıcının pazaryeri ayarlarını düzenleme yetkisi (sırlar yalnız ona gösterilir). */
    private function canEditMarketplaces(): bool
    {
        try {
            return $this->container->get(\App\Services\AuthorizationService::class)->hasPermission('marketplaces.edit');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Webhook imzası için tahmin edilemez gizli anahtar (48 hex). */
    private static function newWooWebhookSecret(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** Panelin dışarıdan erişilen kök adresi (APP_URL; yoksa istek adresi). */
    private static function panelBaseUrl(): string
    {
        $base = function_exists('app_base_url') ? (string) app_base_url() : '';
        if ($base === '') {
            $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
            $base  = ($https ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($base, '/');
    }

    /** Mağazaya özel WooCommerce teslim adresi: <panel adresi>/webhooks/woocommerce/<anahtar>. */
    private static function wooDeliveryUrl(string $token): string
    {
        return self::panelBaseUrl() . '/webhooks/woocommerce/' . rawurlencode($token);
    }

    /**
     * Düzenleme ekranının WooCommerce bölümü için webhook bilgileri.
     *
     * Webhook gizli anahtarı yalnız düzenleme yetkisi (marketplaces.edit) olana döner: teslim adresi + sır
     * birlikte geçerli imzalı sahte sipariş bildirimi üretmeye yeter; salt-görüntüleme rolü sırrı görmez
     * (secret_saved ile yalnız "kayıtlı" bilgisi).
     *
     * @param array<string, mixed> $mp mağaza bağlantı bilgisi bindirilmiş pazaryeri satırı
     * @return array{delivery_url: string, secret: string, secret_saved: bool, can_edit: bool, connected: bool, base_is_local: bool}
     */
    private function wooWebhookViewData(array $mp): array
    {
        $extraConfig = json_decode((string) ($mp['extra_config_json'] ?? '{}'), true) ?: [];
        $token       = (string) ($mp['webhook_token'] ?? '');
        $secret      = (string) ($extraConfig['webhook_secret'] ?? '');
        $canEdit     = $this->canEditMarketplaces();

        return [
            'delivery_url'  => Marketplace::isWellFormedWebhookToken($token) ? self::wooDeliveryUrl($token) : '',
            'secret'        => $canEdit ? $secret : '',
            'secret_saved'  => $secret !== '',
            'can_edit'      => $canEdit,
            'connected'     => !empty($mp['api_key_encrypted']) && !empty($mp['api_secret_encrypted'])
                && trim((string) ($extraConfig['store_url'] ?? '')) !== '',
            'base_is_local' => function_exists('app_url_is_public') && !app_url_is_public(self::panelBaseUrl()),
        ];
    }

    /**
     * WooCommerceService::ensureWebhooks sonucunu ({success, created, updated, removed, ids[], errors[], message})
     * arayüz satırlarına çevirir: kurulan her webhook (#id) ve konu bazında hata ("order.created: …").
     * Hata metinlerindeki kimlik parametreleri gizlenir; sır alanları yanıta konmaz.
     *
     * @param array<string, mixed> $result
     * @return list<array{topic: string, label: string, id: int, error: string}>
     */
    private static function wooWebhookRows(array $result): array
    {
        $labels = [
            'order.created' => 'Sipariş oluşturuldu',
            'order.updated' => 'Sipariş güncellendi',
        ];

        $rows = [];
        foreach ((array) ($result['ids'] ?? []) as $webhookId) {
            if ((int) $webhookId > 0) {
                $rows[] = ['topic' => '', 'label' => 'Sipariş webhook\'u', 'id' => (int) $webhookId, 'error' => ''];
            }
        }
        foreach ((array) ($result['errors'] ?? []) as $error) {
            $error = self::maskWooSecrets((string) $error);
            $topic = '';
            if (preg_match('/^([a-z_]+\.[a-z_]+):\s*(.*)$/s', $error, $match) === 1) {
                [$topic, $error] = [$match[1], $match[2]];
            }
            $rows[] = ['topic' => $topic, 'label' => $labels[$topic] ?? ($topic !== '' ? $topic : 'Webhook'), 'id' => 0, 'error' => $error !== '' ? $error : 'Bilinmeyen hata'];
        }

        return $rows;
    }

    /** Hata metinlerinde URL'ye eklenmiş kimlik/imza parametrelerini gizler. */
    private static function maskWooSecrets(string $text): string
    {
        return (string) preg_replace(
            '/((?:consumer_key|consumer_secret|oauth_consumer_key|oauth_signature|oauth_nonce|secret)=)[^&\s"\']+/i',
            '$1***',
            $text
        );
    }

    /**
     * İlk Verileri Çek — Markalar + Kategoriler + Ürünler (tek buton)
     */
    public function initialImport(Request $request, string $id): void
    {
        $mp = $this->marketplaceModel->find((int) $id);
        if ($mp === false) {
            $this->json(['success' => false, 'message' => 'Pazaryeri bulunamadı']);
            return;
        }

        if (!$this->isEntitled((int) $id)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor.']);
            return;
        }

        $slug = $mp['slug'] ?? '';
        $userId = $this->session->get('user_id');

        try {
            $result = $this->initialImportService->runFullImport((int) $id, $slug, $userId ? (int) $userId : null);

            $hasErrors = !empty($result['errors']);
            $message = sprintf(
                '%s: %d marka, %d kategori, %d ürün çekildi%s',
                htmlspecialchars($mp['display_name'] ?? $slug),
                $result['brands'],
                $result['categories'],
                $result['products']['created'] + $result['products']['updated'],
                $hasErrors ? ' (bazı hatalar oluştu)' : ''
            );

            $this->json([
                'success' => !$hasErrors,
                'message' => $message,
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            // Ayrıntı (dosya yolu, SQL vb.) günlüğe; kullanıcıya sade mesaj
            $this->logger->error('İlk içe aktarma hatası (' . $slug . '): ' . $e->getMessage(), 'InitialImport', [
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->json([
                'success' => false,
                'message' => 'İçe aktarma tamamlanamadı. Ayrıntı sistem günlüğüne yazıldı; bağlantıyı test edip tekrar deneyin.',
            ]);
        }
    }

    /**
     * Adım adım import — AJAX progress (brands → categories → products → orders → returns → finance)
     */
    /**
     * Pazaryeri verisi silme — ÖNİZLEME.
     *
     * Yalnız sayım yapar, hiçbir şey silmez. Onay penceresi bunu gösterir.
     */
    public function purgePreview(Request $request, string $id): void
    {
        if (!$this->isEntitled((int) $id)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor.']);
            return;
        }

        try {
            $servis = $this->container->get(MarketplacePurgeService::class);
            $this->json(['success' => true, 'data' => $servis->preview((int) $id)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Pazaryeri verisi silme — UYGULA.
     *
     * Parçalı çalışır: süre bütçesi dolunca done=false döner, istemci tekrar
     * çağırır. 9.000 ürünlük bir pazaryeri tek istekte silinemez.
     */
    public function purge(Request $request, string $id): void
    {
        @set_time_limit(0);

        if (!$this->isEntitled((int) $id)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor.']);
            return;
        }

        try {
            $servis = $this->container->get(MarketplacePurgeService::class);
            $this->json(['success' => true, 'data' => $servis->run((int) $id, 20.0)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function initialImportStep(Request $request, string $id): void
    {
        $mp = $this->marketplaceModel->find((int) $id);
        if ($mp === false) {
            $this->json(['success' => false, 'message' => 'Pazaryeri bulunamadı']);
            return;
        }

        if (!$this->isEntitled((int) $id)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor.']);
            return;
        }

        $slug = $mp['slug'] ?? '';
        $step = $request->post('step', 'brands');
        $page = (int) $request->post('page', '0');
        $days = (int) $request->post('days', '0');

        // Uzun süren importlar için timeout kaldır + bellek tavanını yükselt
        // (sipariş/ürün listeleri işlenirken varsayılan 128M yetmiyordu)
        set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $result = match ($step) {
                'brands' => $this->initialImportService->importBrandsPage((int) $id, $slug, $page),
                'categories' => [
                    'step'    => 'categories',
                    'count'   => $this->initialImportService->importCategories((int) $id, $slug),
                    'next'    => 'products',
                    'nextPage'=> 0,
                    'done'    => false,
                ],
                'products' => $this->initialImportService->importProductPage((int) $id, $slug, $page),
                // Siparişler artık BLOK BLOK çekilir ('page' = blok numarası).
                // Tek istekte 60 günlük çekim zaman/bellek sınırına takılıp 500 veriyordu.
                'orders' => $this->initialImportService->importOrdersBlock((int) $id, $slug, $days, $page)
                    + ['next' => 'returns'],
                'returns' => [
                    'step'    => 'returns',
                    'data'    => $this->initialImportService->importReturns((int) $id, $slug, $days),
                    'next'    => 'questions',
                    'done'    => false,
                ],
                'questions' => [
                    'step'    => 'questions',
                    'data'    => $this->initialImportService->importQuestions((int) $id, $slug, $days),
                    'next'    => null,
                    'done'    => true,
                ],
                default => ['success' => false, 'message' => 'Geçersiz adım'],
            };

            // Ürün adımında tamamlanma kontrolü — sonraki adım orders
            if ($step === 'products') {
                // İmleç tabanlı servisler (Shopify since_id) bir sonraki sayfayı
                // KENDİSİ bildirir; sayfa numarasını +1 artırmak imleci bozar ve
                // ilk sayfadan sonrası hiç çekilemez.
                $serviceSaysDone     = array_key_exists('done', $result) ? (bool) $result['done'] : null;
                $serviceNextPage     = array_key_exists('nextPage', $result) ? $result['nextPage'] : null;
                $emptyOrLastByNumber = (($result['pageSize'] ?? 0) === 0)
                    || (($result['page'] ?? 0) >= (($result['totalPages'] ?? 1) - 1));

                $done = $serviceSaysDone ?? $emptyOrLastByNumber;
                if ($serviceSaysDone === null && $serviceNextPage !== null) {
                    $done = false;
                }

                $result['done'] = $done;
                $result['next'] = $done ? 'orders' : 'products';
                $result['nextPage'] = $done
                    ? null
                    : ($serviceNextPage !== null ? (int) $serviceNextPage : $page + 1);
            }

            $this->json(array_merge(['success' => true], $result));
        } catch (\Throwable $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'step'    => $step,
            ]);
        }
    }

    /**
     * Amazon OAuth Callback — müşteri yetkilendirme sonrası Amazon buraya yönlendirir
     */
    public function amazonOAuthCallback(Request $request): void
    {
        $authCode = $request->get('spapi_oauth_code', '');
        $state    = $request->get('state', '');
        $error    = $request->get('error', '');

        if ($error !== '') {
            $this->redirectWithError('/marketplaces', 'Amazon yetkilendirme reddedildi: ' . $error);
            return;
        }

        if ($authCode === '') {
            $this->redirectWithError('/marketplaces', 'Amazon yetkilendirme kodu alınamadı');
            return;
        }

        // State doğrula
        $expectedState = $this->session->get('amazon_oauth_state', '');
        if ($expectedState !== '' && $state !== $expectedState) {
            $this->redirectWithError('/marketplaces', 'Amazon OAuth güvenlik doğrulaması başarısız');
            return;
        }

        try {
            // Auth code → refresh_token
            $refreshToken = $this->amazonService->exchangeAuthCode($authCode);

            // DB'ye kaydet
            $mp = $this->marketplaceModel->findBySlug('amazon');
            if ($mp !== false) {
                $extraConfig = json_decode($mp['extra_config_json'] ?? '{}', true) ?: [];
                $extraConfig['refresh_token'] = $refreshToken;
                $this->marketplaceModel->update((int) $mp['id'], [
                    'extra_config_json' => json_encode($extraConfig, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $this->redirectWithSuccess('/marketplaces', 'Amazon yetkilendirme başarılı! Refresh Token kaydedildi.');
        } catch (\Throwable $e) {
            $this->redirectWithError('/marketplaces', 'Amazon token alınamadı: ' . $e->getMessage());
        }
    }
}
