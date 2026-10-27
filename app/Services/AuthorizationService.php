<?php
declare(strict_types=1);

namespace App\Services;

use App\Controllers\AuthorizationController;
use App\Controllers\AutoMappingController;
use App\Controllers\BrandController;
use App\Controllers\BrandMappingController;
use App\Controllers\CategoryController;
use App\Controllers\CategoryMappingController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\EnrichmentController;
use App\Controllers\FeedController;
use App\Controllers\FinanceController;
use App\Controllers\ImportController;
use App\Controllers\InvoiceController;
use App\Controllers\BackupController;
use App\Controllers\ListingMatchController;
use App\Controllers\LogController;
use App\Controllers\MarketplaceController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Controllers\ProductPublishController;
use App\Controllers\QuestionController;
use App\Controllers\ReturnController;
use App\Controllers\RoleController;
use App\Controllers\SettingController;
use App\Controllers\SyncController;
use App\Controllers\UserController;
use App\Controllers\XmlProfileController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;

final class AuthorizationService
{
    /** @var array<string, array<string, array<int, string>>> */
    private const ROUTE_PERMISSION_MAP = [
        DashboardController::class => [
            'index' => ['dashboard.view'],
            'stats' => ['dashboard.view'],
        ],
        // Faz 8 — merchant abonelik/fatura self-service (always-on; ROUTE_MODULE_MAP'e EKLENMEZ
        // ki süresi dolan mağaza ödeme yapmak için sayfaya ulaşabilsin).
        \App\Controllers\SubscriptionController::class => [
            'index' => ['billing.view'],
            'pay'   => ['billing.view'],
        ],
        AuthorizationController::class => [
            'index' => ['roles.view', 'users.view'],
        ],
        UserController::class => [
            'index' => ['users.view'],
            'create' => ['users.create'],
            'store' => ['users.create'],
            'edit' => ['users.edit'],
            'update' => ['users.edit'],
            'delete' => ['users.delete'],
        ],
        RoleController::class => [
            'index' => ['roles.view'],
            'create' => ['roles.create'],
            'store' => ['roles.create'],
            'edit' => ['roles.edit'],
            'update' => ['roles.edit'],
            'delete' => ['roles.delete'],
            'users' => ['roles.view'],
        ],
        OrderController::class => [
            'index' => ['orders.view'],
            'ajaxList' => ['orders.view'],
            'show' => ['orders.view'],
            'sync' => ['sync.trigger'],
            'cargoLabel' => ['orders.view'],
            'cargoLabelPreview' => ['orders.view'],
            'createInvoice' => ['invoices.create'],
            'sendInvoiceToMarketplace' => ['invoices.send'],
            'bulkInvoice' => ['invoices.create'],
            'archive' => ['orders.archive'],
            'shipOrder' => ['orders.ship'],
            'export' => ['orders.view'],
            'quickDetail' => ['orders.view'],
            // Senkron düğmesi görünümde sync.trigger ile gösteriliyor (OrderController::sync ile aynı kapı)
            'syncStream' => ['sync.trigger'],
            // Pazaryerine iptal/paket bölme bildiren sipariş işlemleri: kargolama ile aynı operasyon yetkisi
            'splitPackage' => ['orders.ship'],
            'partialCancel' => ['orders.ship'],
            'cancelOrder' => ['orders.ship'],
        ],
        ReturnController::class => [
            'index' => ['returns.view'],
            'ajaxList' => ['returns.view'],
            'issueReasons' => ['returns.view'],
            'pendingReasons' => ['returns.view'],
            'syncStatus' => ['returns.view'],
            'approve' => ['returns.approve'],
            'reject' => ['returns.reject'],
            // Erteleme düğmesi görünümde onay VEYA ret yetkisiyle gösteriliyor
            'pend' => ['returns.approve', 'returns.reject'],
            // Elle iade senkronu (pazaryerinden çekme): genel senkron yetkisi YA DA iadeleri görme yetkisi —
            // bu sayfadaki senkron eskiden herkese açıktı; satış rolü (returns.view) yetkisini kaybetmez.
            'sync' => ['sync.trigger', 'returns.view'],
            'syncStream' => ['sync.trigger', 'returns.view'],
        ],
        // Sorular: menü/alt-nav orders.view ile açılır; müşteriye giden yanıt ve şablonları
        // sipariş operasyon yetkisi (orders.ship); elle senkron sync.trigger YA DA yanıtlama yetkisi
        // (yanıtlayabilen kullanıcı listeyi de yenileyebilsin — satış/depo rolleri).
        QuestionController::class => [
            'index' => ['orders.view'],
            'ajaxList' => ['orders.view'],
            'cannedList' => ['orders.view'],
            'cannedStore' => ['orders.ship'],
            'cannedDelete' => ['orders.ship'],
            'answer' => ['orders.ship'],
            'sync' => ['sync.trigger', 'orders.ship'],
        ],
        InvoiceController::class => [
            'index' => ['invoices.view'],
            'ajaxList' => ['invoices.view'],
            'settings' => ['invoices.create', 'settings.edit'],
            'updateSettings' => ['invoices.create', 'settings.edit'],
            'testGibConnection' => ['invoices.create', 'settings.edit'],
            'show' => ['invoices.view'],
            'viewHtml' => ['invoices.view'],
            'downloadHtml' => ['invoices.view'],
            'cancel' => ['invoices.cancel'],
            'signSmsStart' => ['invoices.create'],
            'signSmsVerify' => ['invoices.create'],
        ],
        CustomerController::class => [
            'index' => ['customers.view'],
            'ajax' => ['customers.view'],
            'show' => ['customers.view'],
            'update' => ['customers.edit'],
        ],
        ProductController::class => [
            'index' => ['products.view'],
            'ajaxList' => ['products.view'],
            'catalogStatus' => ['products.view'],
            'create' => ['products.create'],
            'store' => ['products.create'],
            'bulkAction' => ['products.edit'],
            'bulkJobPrepare' => ['products.edit'],
            'bulkJobChunk' => ['products.edit'],
            'bulkJobCancel' => ['products.edit'],
            'manualSync' => ['products.sync'],
            'inlineUpdate' => ['products.edit'],
            'mpProductList' => ['products.view'],
            'autoMatchProducts' => ['products.edit'],
            'searchProducts' => ['products.view'],
            'linkMpProduct' => ['products.edit'],
            'addMpProductToSystem' => ['products.create'],
            'uploadImage' => ['products.edit'],
            'show' => ['products.view'],
            'edit' => ['products.edit'],
            'update' => ['products.edit'],
            'adjustStock' => ['products.edit'],
            'syncProduct' => ['products.sync'],
            'listingStatus' => ['products.sync'],
            'priceRules' => ['products.view'],
            'storePriceRule' => ['products.edit'],
            'updatePriceRule' => ['products.edit'],
            'deletePriceRule' => ['products.edit'],
            'previewPriceRule' => ['products.edit'],
            'bulkStock' => ['products.edit'],
            'bulkStockCsv' => ['products.edit'],
            'stockMovements' => ['products.view'],
            'getMarketplaceSettings' => ['products.view'],
            'saveMarketplaceSettings' => ['products.edit'],
        ],
        \App\Controllers\CatalogAuditController::class => ['index'=>['products.view'],'configure'=>['products.edit'],'approve'=>['products.edit']],
        ProductPublishController::class => [
            // Toplu gönderim uçları haritada YOKTU; kayıt olmayınca RBAC atlanıyor
            // (routeAllowed fail-open). Oturum + CSRF vardı ama rol denetimi yoktu.
            'bulkPrepare' => ['products.edit'],
            'bulkChunk' => ['products.edit'],
            'bulkCancel' => ['products.edit'],
            'pricePreview' => ['products.view'],
            'priceApply' => ['products.edit'],
            'publish' => ['products.edit'],
            'validate' => ['products.edit'],
            'publishResult' => ['products.edit'],
        ],
        // Hepsiburada İlan Eşleştirme (controller içinde ayrıca modül + pazaryeri yetkisi denetlenir)
        ListingMatchController::class => [
            'index' => ['products.view'],
            'data' => ['products.view'],
            'search' => ['products.view'],
            'refresh' => ['products.edit'],
            'approve' => ['products.edit'],
            'unlink' => ['products.edit'],
            'bulkApprove' => ['products.edit'],
        ],
        // XML profilleri: menü ürün görüntüleme/oluşturma ile açılır; içe aktarım ve
        // ürün/stok etkileyen işlemler ürün oluşturma VEYA düzenleme yetkisi ister.
        XmlProfileController::class => [
            'index' => ['products.view', 'products.create'],
            'logs' => ['products.view', 'products.create'],
            'create' => ['products.create', 'products.edit'],
            'edit' => ['products.create', 'products.edit'],
            'analyze' => ['products.create', 'products.edit'],
            'store' => ['products.create', 'products.edit'],
            'update' => ['products.create', 'products.edit'],
            'run' => ['products.create', 'products.edit'],
            'delete' => ['products.create', 'products.edit'],
            'resetStock' => ['products.create', 'products.edit'],
            'deleteProducts' => ['products.create', 'products.edit'],
        ],
        CategoryController::class => [
            'index' => ['categories.view'],
            'ajaxList' => ['categories.view'],
            'searchCache' => ['categories.view'],
            'store' => ['categories.edit'],
            'syncFromMarketplace' => ['categories.sync'],
            'autoMatch' => ['categories.sync'],
            'manualMap' => ['categories.edit'],
            'deleteAllCategories' => ['categories.sync'],
            'clearCache' => ['categories.sync'],
            'mpCategoryList' => ['categories.view'],
            'mpCategoryTree' => ['categories.view'],
            'addToSystem' => ['categories.edit'],
            'bulkAddToSystem' => ['categories.edit'],
            'update' => ['categories.edit'],
            'delete' => ['categories.edit'],
        ],
        \App\Controllers\ProductCategoryMappingController::class => [
            'index' => ['categories.view','products.view'], 'save' => ['categories.edit'],
        ],
        CategoryMappingController::class => [
            'index' => ['categories.view'],
            'store' => ['categories.edit'],
            'delete' => ['categories.edit'],
            'searchTrendyol' => ['categories.view'],
            'attributes' => ['categories.view'],
            'hbAttributes' => ['categories.view'],
            'hbAttributeSave' => ['categories.edit'],
        ],
        \App\Controllers\TrendyolAttributeController::class => [
            'show' => ['categories.view'], 'save' => ['categories.edit'], 'preview' => ['categories.view','products.view'],
        ],
        // Akıllı Eşleştirme sihirbazı kategori VE marka için çalışır (?kind=category|brand)
        AutoMappingController::class => [
            'index' => ['categories.view', 'brands.view'],
            'start' => ['categories.edit', 'brands.edit'],
            'chunk' => ['categories.edit', 'brands.edit'],
            'aiChunk' => ['categories.edit', 'brands.edit'],
            'apply' => ['categories.edit', 'brands.edit'],
            'undo' => ['categories.edit', 'brands.edit'],
            'cancel' => ['categories.edit', 'brands.edit'],
            'proposals' => ['categories.edit', 'brands.edit'],
        ],
        BrandController::class => [
            'index' => ['brands.view'],
            'ajaxList' => ['brands.view'],
            'searchCache' => ['brands.view'],
            'store' => ['brands.edit'],
            'syncFromMarketplace' => ['brands.sync'],
            'bulkDelete' => ['brands.edit'],
            'autoMatch' => ['brands.sync'],
            'manualMap' => ['brands.edit'],
            'deleteAllBrands' => ['brands.sync'],
            'clearCache' => ['brands.sync'],
            'mpBrandList' => ['brands.view'],
            'addToSystem' => ['brands.edit'],
            'bulkAddToSystem' => ['brands.edit'],
            'update' => ['brands.edit'],
            'delete' => ['brands.edit'],
        ],
        BrandMappingController::class => [
            'storeBulk' => ['brands.edit'],
            'unmappedCount' => ['brands.view'],
            'index' => ['brands.view'],
            'store' => ['brands.edit'],
            'delete' => ['brands.edit'],
            'searchTrendyol' => ['brands.view'],
        ],
        MarketplaceController::class => [
            'index' => ['marketplaces.view'],
            'edit' => ['marketplaces.view'],
            'update' => ['marketplaces.edit'],
            'testConnection' => ['marketplaces.edit'],
            // WooCommerce sipariş webhook'larını kurar (bağlantı ayarını değiştirir)
            'wooWebhooks' => ['marketplaces.edit'],
            // Yıkıcı işlem: pazaryerine ait tüm mağaza verisini siler
            'purgePreview' => ['marketplaces.edit'],
            'purge'        => ['marketplaces.edit'],
            // İlk içe aktarım sihirbazı ve Amazon yetkilendirme dönüşü bağlantı ayarını değiştirir
            'initialImport'       => ['marketplaces.edit'],
            'initialImportStep'   => ['marketplaces.edit'],
            'amazonOAuthCallback' => ['marketplaces.edit'],
        ],
        SyncController::class => [
            'index' => ['sync.trigger'],
            'triggerOrders' => ['sync.trigger'],
            'triggerStock' => ['sync.trigger'],
            'triggerPrice' => ['sync.trigger'],
            'triggerProducts' => ['sync.trigger'],
            'triggerReturns' => ['sync.trigger'],
            'triggerQuestions' => ['sync.trigger'],
            'saveSettings' => ['sync.trigger'],
        ],
        FinanceController::class => [
            'index' => ['finance.view'],
            'ajax' => ['finance.view'],
            'syncDay' => ['finance.sync'],
        ],
        // 'save' bilinçli olarak haritada yok: bölüm bazlı kural SettingController::save içinde
        // (kendi şifresini değiştirme oturum yeterli; diğer bölümler settings.edit).
        SettingController::class => [
            'index' => ['settings.view'],
        ],
        // Merchant salt-görüntüleme (yönetim admin'de); menüdeki /settings/api-tokens izniyle aynı
        \App\Controllers\ApiTokenController::class => [
            'index' => ['settings.view'],
        ],
        // NOT: LogController + BackupController PLATFORM ADMIN sayfalarıdır (AdminAuthMiddleware
        // ile korunur; admin layout + AdminSession). Merchant RBAC (users oturumu) burada
        // uygulanmaz — aksi halde platform admin'in merchant izni olmadığı için 403 alınırdı.
        ImportController::class => [
            'index' => ['products.view'],
            'startMarketplace' => ['products.create'],
            'importPage' => ['products.create'],
            'uploadCsv' => ['products.create'],
            'uploadXml' => ['products.create'],
            'downloadTemplate' => ['products.view'],
            'exportExcel' => ['products.view'],
            'exportXml' => ['products.view'],
            'saveFeedSettings' => ['settings.edit'],
        ],
        FeedController::class => [
            'index' => ['settings.view'],
            'create' => ['settings.edit'],
            'store' => ['settings.edit'],
            'regenerate' => ['settings.edit'],
            'regenerateAll' => ['settings.edit'],
            'saveFeedSettings' => ['settings.edit'],
        ],
        EnrichmentController::class => [
            'dashboard' => ['products.view'],
            'bulkEnrich' => ['products.edit'],
            'enrichProduct' => ['products.edit'],
            'applyCategoryMapping' => ['products.edit'],
            'applyBrandMapping' => ['products.edit'],
            'addBrandAlias' => ['products.edit'],
            'deleteBrandAlias' => ['products.edit'],
            'retryFailed' => ['products.edit'],
            'incomplete' => ['products.view'],
        ],
    ];

    /**
     * Faz 2 — Route → store entitlement modülü eşlemesi (FEATURE controller'ları).
     * Burada OLMAYAN controller'lar modül-gated DEĞİL (always-on; yalnız RBAC).
     * Değer liste ise modüllerden BİRİ yeterlidir (ör. kategori + marka eşleştirme sihirbazı).
     * @var array<string, string|list<string>>
     */
    private const ROUTE_MODULE_MAP = [
        'App\\Controllers\\OrderController'    => 'orders',
        'App\\Controllers\\ReturnController'   => 'returns',
        'App\\Controllers\\QuestionController' => 'questions',
        'App\\Controllers\\ProductController'  => 'products',
        'App\\Controllers\\CategoryController' => 'categories',
        'App\\Controllers\\BrandController'    => 'brands',
        'App\\Controllers\\CustomerController' => 'customers',
        'App\\Controllers\\InvoiceController'  => 'invoices',
        'App\\Controllers\\FinanceController'  => 'finance',
        'App\\Controllers\\SyncController'     => 'sync',
        'App\\Controllers\\ImportController'   => 'import',
        'App\\Controllers\\FeedController'     => 'feeds',
        'App\\Controllers\\CatalogAuditController' => 'products',
        'App\\Controllers\\ProductPublishController'  => 'products',
        'App\\Controllers\\ListingMatchController'    => 'products',
        'App\\Controllers\\XmlProfileController'      => 'products',
        'App\\Controllers\\EnrichmentController'      => 'products',
        'App\\Controllers\\CategoryMappingController' => 'categories',
        'App\\Controllers\\ProductCategoryMappingController' => 'categories',
        'App\\Controllers\\TrendyolAttributeController' => 'categories',
        'App\\Controllers\\BrandMappingController'    => 'brands',
        'App\\Controllers\\AutoMappingController'     => ['categories', 'brands'],
    ];

    /**
     * Oturumsuz, anahtar (token) ile korunan uçlar: RBAC ve modül kapısı burada UYGULANMAZ.
     * Oturum olmadığı için modül kapısı TenantContext'e erişip patlıyordu (HTTP cron 500).
     * Anahtar doğrulaması controller içinde, mağaza başına yetki TenantCronRunner/getActiveForStore'da.
     * @var array<string, list<string>>
     */
    private const TOKEN_AUTH_ROUTES = [
        SyncController::class => ['cronOrders', 'cronStock', 'cronPrice', 'cronProducts', 'cronReturns', 'cronQuestions'],
        // XML dışa aktarım çıktısı (GET /xml-islemleri/xml-ver-x?profil=<anahtar>) — profil anahtarıyla herkese açık
        XmlProfileController::class => ['xmlVerX'],
        // Herkese açık XML feed (GET /feed/{token}) — feed anahtarıyla; mağaza + 'feeds' modülü controller'da
        FeedController::class => ['serve'],
        // Platform webhook alıcısı (POST /webhooks/{slug}/{token}) — mağazaya özel anahtar + HMAC controller'da
        \App\Controllers\WebhookController::class => ['handle'],
    ];

    public function __construct(
        private readonly Session $session,
        private readonly Response $response,
        private readonly User $userModel,
        private readonly EntitlementService $entitlement,
    ) {}

    /** Bir controller'ın store-entitlement modülü (yoksa null = always-on; çoklu ise ilki). */
    public function moduleForController(string $controllerClass): ?string
    {
        $modules = $this->modulesForController($controllerClass);
        return $modules[0] ?? null;
    }

    /**
     * Route izinli mi? RBAC ∩ Store Entitlement (modül). Testlenebilir saf karar.
     * (Menüde gizli olsa bile route bu karardan geçer — "menüde yok ≠ güvenlik".)
     */
    public function routeAllowed(string $controllerClass, string $methodName): bool
    {
        if ($this->isTokenAuthRoute($controllerClass, $methodName)) {
            return true;
        }
        $permissions = self::ROUTE_PERMISSION_MAP[$controllerClass][$methodName] ?? [];
        if ($permissions !== [] && !$this->hasAnyPermission($permissions)) {
            return false; // RBAC
        }
        return $this->controllerModuleAllowed($controllerClass); // Store Entitlement (modül)
    }

    /** Oturumsuz, anahtarla korunan uç mu (HTTP cron, XML dışa aktarım çıktısı)? */
    private function isTokenAuthRoute(string $controllerClass, string $methodName): bool
    {
        return in_array($methodName, self::TOKEN_AUTH_ROUTES[$controllerClass] ?? [], true);
    }

    /** @return list<string> Controller'ın modül kapısı (boş = always-on) */
    private function modulesForController(string $controllerClass): array
    {
        $modules = self::ROUTE_MODULE_MAP[$controllerClass] ?? [];
        return is_array($modules) ? $modules : [$modules];
    }

    /** Modül kapısı: eşlenen modüllerden en az biri mağazada aktifse geçer. */
    private function controllerModuleAllowed(string $controllerClass): bool
    {
        $modules = $this->modulesForController($controllerClass);
        if ($modules === []) {
            return true;
        }
        foreach ($modules as $module) {
            if ($this->canAccessModule($module)) {
                return true;
            }
        }
        return false;
    }

    /** Menü öğesi görünür mü? RBAC ∩ (varsa) modül entitlement. */
    public function menuItemAllowed(array $permissions, ?string $module = null): bool
    {
        if (!$this->hasAnyPermission($permissions)) {
            return false;
        }
        return $module === null || $this->canAccessModule($module);
    }

    // ─── Faz 2: Store Entitlement katmanı (Tenant ∩ Store Entitlement ∩ RBAC) ──

    /** Aktif mağazanın erişebileceği pazaryeri id'leri (entitlement). */
    public function allowedMarketplaceIds(): array
    {
        return $this->entitlement->allowedMarketplaceIds();
    }

    /** Mağaza bu pazaryerini satın aldı/aktif mi (entitlement). */
    public function canAccessMarketplace(int $marketplaceId): bool
    {
        return $this->entitlement->canAccessMarketplace($marketplaceId);
    }

    /** Mağaza bu modülü kullanabilir mi (entitlement; gated değilse daima true). */
    public function canAccessModule(string $moduleKey): bool
    {
        return $this->entitlement->canAccessModule($moduleKey);
    }

    /**
     * ÜÇ KATMAN KESİŞİMİ: Tenant ∩ Store Entitlement ∩ RBAC.
     * Üçünden biri başarısızsa false. (Tenant: entitlement->currentStoreId fail-closed;
     * RBAC: hasPermission; Entitlement: canAccessMarketplace.)
     */
    public function canAccessMarketplaceData(string $permission, int $marketplaceId): bool
    {
        if (!$this->hasPermission($permission)) {            // RBAC
            return false;
        }
        return $this->entitlement->canAccessMarketplace($marketplaceId); // Tenant + Store Entitlement
    }

    /** Modül entitlement route guard'ı (1d): mağaza modülü almamışsa 403. */
    public function authorizeModule(Request $request, string $moduleKey): void
    {
        if ($this->canAccessModule($moduleKey)) {
            return;
        }
        $message = 'Mağazanız bu modülü kullanma yetkisine sahip değil.';
        if ($request->isAjax()) {
            $this->response->error($message, 403);
        }
        $this->response->forbidden($message);
    }

    public function refreshCurrentUserContext(): void
    {
        $userId = $this->session->userId();
        if ($userId === null || $userId <= 0) {
            return;
        }

        $user = $this->userModel->findAuthUserById($userId);
        if ($user === false) {
            return;
        }

        $permissions = json_decode((string) ($user['permissions_json'] ?? '[]'), true);
        if (!is_array($permissions)) {
            $permissions = [];
        }

        // Rol satırı çözülemediyse (role_id NULL/silinmiş rol → LEFT JOIN boş döner)
        // izin listesi boş kalıyor ve mağaza sahibi kendi abonelik/fatura sayfasında bile
        // "Erişim yetkiniz yok" görüyordu. Eski `users.role` sütunu yedek kaynaktır.
        if ($permissions === [] && (string) ($user['role'] ?? '') === 'admin') {
            $permissions = ['*'];
        }

        $this->session->set('user_name', (string) ($user['name'] ?? ''));
        $this->session->set('user_email', (string) ($user['email'] ?? ''));
        $this->session->set('user_role', (string) ($user['role_slug'] ?? $user['role'] ?? ''));
        $this->session->set('user_role_id', isset($user['role_id']) ? (int) $user['role_id'] : null);
        $this->session->set('user_role_name', (string) ($user['role_name'] ?? $user['role'] ?? ''));
        $this->session->set('user_permissions', array_values(array_map('strval', $permissions)));
    }

    /**
     * @return array<int, string>
     */
    public function currentPermissions(): array
    {
        $permissions = $this->session->userPermissions();
        if ($permissions !== []) {
            return $permissions;
        }

        if ($this->session->userRole() === 'admin') {
            return ['*'];
        }

        $this->refreshCurrentUserContext();

        $permissions = $this->session->userPermissions();
        if ($permissions === [] && $this->session->userRole() === 'admin') {
            // Tazeleme sonrası rol netleştiyse aynı istekte uygula (aksi halde
            // kullanıcı bir isteklik "yetkiniz yok" hatası alıyordu).
            return ['*'];
        }

        return $permissions;
    }

    public function hasPermission(string $permission): bool
    {
        if ($permission === '') {
            return true;
        }

        $permissions = $this->currentPermissions();
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function authorizeRoute(Request $request, string $controllerClass, string $methodName): void
    {
        // 0) Anahtarla korunan oturumsuz uçlar (HTTP cron): kimlik/kiracı controller'da kurulur.
        if ($this->isTokenAuthRoute($controllerClass, $methodName)) {
            return;
        }

        // 1) RBAC
        $permissions = self::ROUTE_PERMISSION_MAP[$controllerClass][$methodName] ?? [];
        if ($permissions !== [] && !$this->hasAnyPermission($permissions)) {
            $this->denyRoute($request, 'Bu alanı görüntüleme veya işlem yapma yetkiniz bulunmuyor.');
        }

        // 2) Store Entitlement (modül) — Faz 2. "Menüde yok ≠ güvenlik": route burada da kapanır.
        if (!$this->controllerModuleAllowed($controllerClass)) {
            $this->denyRoute($request, 'Mağazanız bu modülü kullanma yetkisine sahip değil.');
        }
    }

    private function denyRoute(Request $request, string $message): never
    {
        if ($request->isAjax()) {
            $this->response->error($message, 403);
        }
        $this->response->forbidden($message);
    }
}
