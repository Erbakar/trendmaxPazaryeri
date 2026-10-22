<?php
/**
 * OzyConnect Panel — Route Tanımlamaları
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

use App\Controllers\AuthController;
use App\Controllers\AuthorizationController;
use App\Controllers\BrandController;
use App\Controllers\BrandMappingController;
use App\Controllers\CategoryController;
use App\Controllers\CategoryMappingController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\FeedController;
use App\Controllers\ImportController;
use App\Controllers\InvoiceController;
use App\Controllers\ListingMatchController;
use App\Controllers\LogController;
use App\Controllers\BackupController;
use App\Controllers\MarketplaceController;
use App\Controllers\FinanceController;
use App\Controllers\NotificationController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Controllers\ProductPublishController;
use App\Controllers\QuestionController;
use App\Controllers\ReturnController;
use App\Controllers\RoleController;
use App\Controllers\SettingController;
use App\Controllers\SupportController;
use App\Controllers\SyncController;
use App\Controllers\UpdateController;
use App\Controllers\UserController;
use App\Controllers\XmlProfileController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;

/** @var \App\Core\Router $router */

// ═══════════════════════════════════════════════════════
// PUBLIC ROTALAR (Auth gerektirmez)
// ═══════════════════════════════════════════════════════

// ─── Auth ──────────────────────────────────────────────
$router->get('/login',  [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login'], [RateLimitMiddleware::class, CsrfMiddleware::class]);
$router->get('/logout', [AuthController::class, 'logout']);

// ─── Şifre Sıfırlama ──────────────────────────────────
$router->get('/password/forgot',   [AuthController::class, 'forgotForm']);
$router->post('/password/forgot',  [AuthController::class, 'forgotSubmit'],  [RateLimitMiddleware::class, CsrfMiddleware::class]);
$router->get('/password/reset',    [AuthController::class, 'resetForm']);
$router->post('/password/reset',   [AuthController::class, 'resetSubmit'],   [CsrfMiddleware::class]);


// ─── Feed public serving (token ile erişim) ────────────
$router->get('/feed/{token}', [FeedController::class, 'serve']);

// ─── Fatura belgesi (pazaryeri fatura linki — auth YOK, GİB UUID ile korunur) ──
// Hepsiburada/Trendyol/Pazarama fatura URL'sini kendi sunucusundan indirir;
// adres ".html" ile bitmeli ve text/html dönmelidir.
$router->get('/fatura/{uuid}', [\App\Controllers\PublicInvoiceController::class, 'document']);

// ─── Admin (Faz 3) — merchant'tan AYRI giriş akışı (AuthMiddleware YOK) ──
$router->get('/admin/login',   [\App\Controllers\AdminAuthController::class, 'showLogin']);
$router->post('/admin/login',  [\App\Controllers\AdminAuthController::class, 'login'],     [RateLimitMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/2fa',     [\App\Controllers\AdminAuthController::class, 'show2fa']);
$router->post('/admin/2fa',    [\App\Controllers\AdminAuthController::class, 'verify2fa'], [CsrfMiddleware::class]);
$router->post('/admin/logout', [\App\Controllers\AdminAuthController::class, 'logout'],    [CsrfMiddleware::class]);
$router->get('/admin/forgot',  [\App\Controllers\AdminAuthController::class, 'showForgot']);
$router->post('/admin/forgot', [\App\Controllers\AdminAuthController::class, 'forgot'],    [RateLimitMiddleware::class, CsrfMiddleware::class]);
$router->get('/admin/reset',   [\App\Controllers\AdminAuthController::class, 'showReset']);
$router->post('/admin/reset',  [\App\Controllers\AdminAuthController::class, 'reset'],     [CsrfMiddleware::class]);

// ─── Faz 8: Kurumsal Site (public, no-auth). "/" → authed merchant /dashboard'a (controller içinde). ──
$router->get('/',                           [\App\Controllers\PublicSiteController::class, 'root'],              []);
$router->get('/ozellikler',                 [\App\Controllers\PublicSiteController::class, 'features'],          []);
$router->get('/paketler',                   [\App\Controllers\PublicSiteController::class, 'pricing'],           []);
$router->get('/referanslar',                [\App\Controllers\PublicSiteController::class, 'references'],        []);
$router->get('/blog',                       [\App\Controllers\PublicSiteController::class, 'blog'],              []);
$router->get('/blog/{slug}',                [\App\Controllers\PublicSiteController::class, 'blogPost'],          []);
$router->get('/sss',                        [\App\Controllers\PublicSiteController::class, 'faq'],               []);
$router->get('/iletisim',                   [\App\Controllers\PublicSiteController::class, 'contact'],           []);
$router->get('/demo',                       [\App\Controllers\PublicSiteController::class, 'demo'],              []);
$router->post('/demo',                      [\App\Controllers\PublicSiteController::class, 'demoSubmit'],        [CsrfMiddleware::class]);
$router->get('/magaza-olustur',             [\App\Controllers\PublicSiteController::class, 'storeCreate'],       []);
$router->post('/magaza-olustur',            [\App\Controllers\PublicSiteController::class, 'storeCreateSubmit'], [CsrfMiddleware::class]);
$router->get('/magaza-olustur/dogrula',     [\App\Controllers\PublicSiteController::class, 'storeCreateVerify'], []);
$router->get('/magaza-olustur/tesekkurler', [\App\Controllers\PublicSiteController::class, 'storeCreateThanks'], []);
$router->get('/gizlilik',                   [\App\Controllers\PublicSiteController::class, 'privacy'],           []);
$router->get('/kullanim-kosullari',         [\App\Controllers\PublicSiteController::class, 'terms'],             []);
$router->get('/cerez-politikasi',           [\App\Controllers\PublicSiteController::class, 'cookies'],           []);
$router->get('/sitemap.xml',                [\App\Controllers\PublicSiteController::class, 'sitemap'],           []);
$router->get('/robots.txt',                 [\App\Controllers\PublicSiteController::class, 'robots'],            []);


// ═══════════════════════════════════════════════════════
// KORUNMALI ROTALAR (Auth + CSRF gerektirir)
// ═══════════════════════════════════════════════════════

$authMiddleware = [AuthMiddleware::class];
$authCsrf       = [AuthMiddleware::class, CsrfMiddleware::class];

// ─── Dashboard ─────────────────────────────────────────
$router->get('/dashboard',       [DashboardController::class, 'index'], $authMiddleware);
$router->get('/dashboard/stats', [DashboardController::class, 'stats'], $authMiddleware);

// ─── Faz 8: Abonelik & Fatura (merchant self-service; billing.view, always-on) ──
$router->get('/subscription',         [\App\Controllers\SubscriptionController::class, 'index'],         $authMiddleware);
$router->post('/subscription/pay',    [\App\Controllers\SubscriptionController::class, 'pay'],           $authCsrf);
// Faz 9: paket yükseltme + modül add-on + PayTR kart
$router->get('/subscription/upgrade',     [\App\Controllers\SubscriptionController::class, 'upgrade'],       $authMiddleware);
$router->post('/subscription/upgrade',    [\App\Controllers\SubscriptionController::class, 'upgradeSubmit'], $authCsrf);
$router->get('/subscription/modules',     [\App\Controllers\SubscriptionController::class, 'modules'],       $authMiddleware);
$router->post('/subscription/buy-module', [\App\Controllers\SubscriptionController::class, 'buyModule'],      $authCsrf);
$router->post('/subscription/buy-marketplace', [\App\Controllers\SubscriptionController::class, 'buyMarketplace'], $authCsrf);
$router->post('/subscription/buy-credits', [\App\Controllers\SubscriptionController::class, 'buyCredits'],      $authCsrf);
$router->post('/subscription/pay-card',   [\App\Controllers\SubscriptionController::class, 'payCard'],        $authCsrf);
// Faz 9: PayTR callback (PUBLIC — sunucu-sunucu, CSRF YOK, HMAC ile korunur)
$router->post('/paytr-callback',      [\App\Controllers\PayTrCallbackController::class, 'handle'], []);

// Pazaryeri/e-ticaret webhook alıcısı (PUBLIC — sunucu-sunucu, CSRF YOK, platforma özgü HMAC ile korunur)
$router->post('/webhooks/{slug}/{token}', [\App\Controllers\WebhookController::class, 'handle'], []);

// ─── Siparişler ────────────────────────────────────────
$router->get('/orders',                      [OrderController::class, 'index'],         $authMiddleware);
$router->get('/orders/ajax',                 [OrderController::class, 'ajaxList'],      $authMiddleware);
$router->get('/orders/export',               [OrderController::class, 'export'],        $authMiddleware);
$router->get('/orders/{id}/quick',           [OrderController::class, 'quickDetail'],   $authMiddleware);
$router->get('/orders/{id}',                 [OrderController::class, 'show'],          $authMiddleware);
$router->post('/orders/sync',               [OrderController::class, 'sync'],          $authCsrf);
$router->post('/orders/sync-stream',        [OrderController::class, 'syncStream'],    $authCsrf);
$router->get('/orders/{id}/cargo-label',     [OrderController::class, 'cargoLabel'],    $authMiddleware);
$router->get('/orders/{id}/cargo-preview',   [OrderController::class, 'cargoLabelPreview'], $authMiddleware);
$router->post('/orders/{id}/invoice',        [OrderController::class, 'createInvoice'], $authCsrf);
$router->post('/orders/{id}/send-invoice-to-marketplace', [OrderController::class, 'sendInvoiceToMarketplace'], $authCsrf);
$router->post('/orders/bulk-invoice',        [OrderController::class, 'bulkInvoice'],   $authCsrf);
$router->post('/orders/{id}/archive',        [OrderController::class, 'archive'],       $authCsrf);
$router->post('/orders/{id}/ship',           [OrderController::class, 'shipOrder'],     $authCsrf);
$router->post('/orders/{id}/split-package',  [OrderController::class, 'splitPackage'],   $authCsrf);
$router->post('/orders/{id}/partial-cancel', [OrderController::class, 'partialCancel'],  $authCsrf);
$router->post('/orders/{id}/cancel',         [OrderController::class, 'cancelOrder'],    $authCsrf);

// ─── Müşteriler ────────────────────────────────────────
$router->get('/customers',                   [CustomerController::class, 'index'],  $authMiddleware);
$router->get('/customers/ajax',              [CustomerController::class, 'ajax'],   $authMiddleware);
$router->get('/customers/{id}',              [CustomerController::class, 'show'],   $authMiddleware);
$router->post('/customers/{id}',             [CustomerController::class, 'update'], $authCsrf);

// ─── Ürünler ───────────────────────────────────────────
$router->get('/products',                    [ProductController::class, 'index'],       $authMiddleware);
$router->get('/products/ajax',              [ProductController::class, 'ajaxList'],    $authMiddleware);
$router->get('/products/create',             [ProductController::class, 'create'],      $authMiddleware);
$router->post('/products',                   [ProductController::class, 'store'],       $authCsrf);
$router->post('/products/bulk-action',       [ProductController::class, 'bulkAction'],  $authCsrf);
$router->post('/products/bulk/prepare',      [ProductController::class, 'bulkJobPrepare'], $authCsrf);
$router->post('/products/bulk/chunk',        [ProductController::class, 'bulkJobChunk'],   $authCsrf);
$router->post('/products/bulk/cancel',       [ProductController::class, 'bulkJobCancel'],  $authCsrf);
$router->post('/products/manual-sync',       [ProductController::class, 'manualSync'],  $authCsrf);
$router->post('/products/inline-update',     [ProductController::class, 'inlineUpdate'], $authCsrf);
$router->get('/products/mp-products',        [ProductController::class, 'mpProductList'],    $authMiddleware);
$router->get('/products/search',             [ProductController::class, 'searchProducts'],   $authMiddleware);
$router->post('/products/mp-products/link',  [ProductController::class, 'linkMpProduct'],    $authCsrf);
$router->post('/products/auto-match',        [ProductController::class, 'autoMatchProducts'], $authCsrf);
$router->post('/products/add-to-system',     [ProductController::class, 'addMpProductToSystem'], $authCsrf);
$router->post('/products/upload-image',      [ProductController::class, 'uploadImage'],          $authCsrf);

// DİKKAT: Statik /products/... yolları {id} joker rotalarından ÖNCE tanımlanmalı;
// aksi halde /products/publish gibi istekler {id}='publish' olarak eşleşip
// ProductController::update'e düşer ve "Ad alanı zorunludur" hatası üretir.
$router->post('/products/bulk-stock',        [ProductController::class, 'bulkStock'],    $authCsrf);
$router->post('/products/bulk-stock/csv',    [ProductController::class, 'bulkStockCsv'], $authCsrf);
$router->get('/products/stock-movements',    [ProductController::class, 'stockMovements'], $authMiddleware);
$router->post('/products/publish/validate',  [ProductPublishController::class, 'validate'], $authCsrf);
// Hepsiburada gönderim sonucu sorgulama (trackingId / ürün listesi) — yalnız HB'den okur, yerel durumu günceller
$router->post('/products/publish/result',    [ProductPublishController::class, 'publishResult'], $authCsrf);
// Toplu pazaryerine gönderim — parça parça ilerler (DİKKAT: {id} rotasından ÖNCE)
$router->post('/products/publish/bulk/prepare',       [ProductPublishController::class, 'bulkPrepare'],  $authCsrf);
$router->post('/products/publish/bulk/chunk',         [ProductPublishController::class, 'bulkChunk'],    $authCsrf);
$router->post('/products/publish/bulk/cancel',        [ProductPublishController::class, 'bulkCancel'],   $authCsrf);
$router->post('/products/publish/bulk/price-preview', [ProductPublishController::class, 'pricePreview'], $authCsrf);
$router->post('/products/publish/bulk/price-apply',   [ProductPublishController::class, 'priceApply'],   $authCsrf);
$router->post('/products/publish',           [ProductPublishController::class, 'publish'],  $authCsrf);

$router->get('/products/{id}',               [ProductController::class, 'show'],        $authMiddleware);
$router->get('/products/{id}/edit',          [ProductController::class, 'edit'],        $authMiddleware);
$router->post('/products/{id}',              [ProductController::class, 'update'],      $authCsrf);
$router->post('/products/{id}/stock',        [ProductController::class, 'adjustStock'], $authCsrf);
$router->post('/products/{id}/sync',         [ProductController::class, 'syncProduct'], $authCsrf);
$router->post('/products/{id}/listing-status', [ProductController::class, 'listingStatus'], $authCsrf);
$router->get('/products/{id}/marketplace-settings',  [ProductController::class, 'getMarketplaceSettings'],  $authMiddleware);
$router->post('/products/{id}/marketplace-settings', [ProductController::class, 'saveMarketplaceSettings'], $authCsrf);

// ─── AI Suite (MERCHANT) ──
$router->post('/ai/content', [\App\Controllers\AiController::class, 'contentGenerate'], $authCsrf);
$router->post('/ai/vision',  [\App\Controllers\AiController::class, 'visionAnalyze'],    $authCsrf);
$router->post('/ai/optimize',[\App\Controllers\AiController::class, 'optimizeAnalyze'],  $authCsrf);
$router->get('/ai/competitor',         [\App\Controllers\AiController::class, 'competitor'],        $authMiddleware);
$router->post('/ai/competitor-analyze',[\App\Controllers\AiController::class, 'competitorAnalyze'],  $authCsrf);
$router->get('/ai/bulk',       [\App\Controllers\AiController::class, 'bulk'],     $authMiddleware);
$router->post('/ai/bulk-fill', [\App\Controllers\AiController::class, 'bulkFill'], $authCsrf);
$router->get('/ai/credits',  [\App\Controllers\AiController::class, 'credits'],         $authMiddleware);
$router->get('/ai/trends',       [\App\Controllers\AiController::class, 'trendsPage'], $authMiddleware);
$router->get('/ai/advisor',      [\App\Controllers\AiController::class, 'advisor'],    $authMiddleware);
$router->post('/ai/advisor/ask', [\App\Controllers\AiController::class, 'advisorAsk'], $authCsrf);

// ─── Kategoriler ───────────────────────────────────────
$router->get('/categories',                  [CategoryController::class, 'index'],               $authMiddleware);
$router->get('/categories/ajax',             [CategoryController::class, 'ajaxList'],            $authMiddleware);
$router->get('/categories/search-cache',     [CategoryController::class, 'searchCache'],         $authMiddleware);
$router->post('/categories',                 [CategoryController::class, 'store'],               $authCsrf);
$router->post('/categories/sync-marketplace', [CategoryController::class, 'syncFromMarketplace'], $authCsrf);
$router->post('/categories/auto-match',      [CategoryController::class, 'autoMatch'],           $authCsrf);
$router->post('/categories/manual-map',      [CategoryController::class, 'manualMap'],           $authCsrf);
$router->post('/categories/delete-all',      [CategoryController::class, 'deleteAllCategories'], $authCsrf);
$router->post('/categories/clear-cache',     [CategoryController::class, 'clearCache'],          $authCsrf);
$router->get('/categories/mp-list',          [CategoryController::class, 'mpCategoryList'],      $authMiddleware);
$router->get('/categories/mp-tree',          [CategoryController::class, 'mpCategoryTree'],      $authMiddleware);
$router->post('/categories/add-to-system',   [CategoryController::class, 'addToSystem'],         $authCsrf);
$router->post('/categories/bulk-add-to-system', [CategoryController::class, 'bulkAddToSystem'],  $authCsrf);

// ─── Kategori Eşleştirme ──────────────────────────────
// DİKKAT: /categories/mapping* rotaları POST /categories/{id} jokerinden ÖNCE kalmalı;
// aksi halde POST /categories/mapping, CategoryController::update('mapping')'e düşer.
$router->get('/categories/{id}/product-mappings', [\App\Controllers\ProductCategoryMappingController::class, 'index'], $authMiddleware);
$router->post('/categories/{id}/product-mappings', [\App\Controllers\ProductCategoryMappingController::class, 'save'], $authCsrf);
$router->get('/categories/mapping',                         [CategoryMappingController::class, 'index'],          $authMiddleware);
$router->post('/categories/mapping',                        [CategoryMappingController::class, 'store'],          $authCsrf);
$router->get('/categories/mapping/search',                  [CategoryMappingController::class, 'searchTrendyol'], $authMiddleware);
$router->get('/categories/mapping/attributes/{categoryId}', [CategoryMappingController::class, 'attributes'],     $authMiddleware);
// Hepsiburada kategori özellikleri çekmecesi (zorunlu özellikler + geçerli değerler + varsayılanlar)
$router->get('/categories/mapping/{id}/hb-attributes',      [CategoryMappingController::class, 'hbAttributes'],   $authMiddleware);
$router->post('/categories/mapping/{id}/hb-attribute',      [CategoryMappingController::class, 'hbAttributeSave'], $authCsrf);
$router->get('/categories/mapping/{id}/trendyol-attributes', [\App\Controllers\TrendyolAttributeController::class, 'show'], $authMiddleware);
$router->post('/categories/mapping/{id}/trendyol-attributes', [\App\Controllers\TrendyolAttributeController::class, 'save'], $authCsrf);
$router->get('/categories/mapping/{id}/trendyol-preview', [\App\Controllers\TrendyolAttributeController::class, 'preview'], $authMiddleware);
$router->post('/categories/mapping/{id}/delete',            [CategoryMappingController::class, 'delete'],         $authCsrf);

$router->post('/categories/{id}',            [CategoryController::class, 'update'],              $authCsrf);
$router->post('/categories/{id}/delete',     [CategoryController::class, 'delete'],              $authCsrf);

// ─── Markalar ──────────────────────────────────────────
$router->get('/brands',                      [BrandController::class, 'index'],                  $authMiddleware);
$router->get('/brands/ajax',                 [BrandController::class, 'ajaxList'],               $authMiddleware);
$router->get('/brands/search-cache',         [BrandController::class, 'searchCache'],            $authMiddleware);
$router->get('/brands/mp-list',              [BrandController::class, 'mpBrandList'],            $authMiddleware);
$router->post('/brands',                     [BrandController::class, 'store'],                  $authCsrf);
$router->post('/brands/sync-marketplace',    [BrandController::class, 'syncFromMarketplace'],    $authCsrf);
$router->post('/brands/bulk-delete',         [BrandController::class, 'bulkDelete'],             $authCsrf);
$router->post('/brands/auto-match',          [BrandController::class, 'autoMatch'],              $authCsrf);
$router->post('/brands/manual-map',          [BrandController::class, 'manualMap'],              $authCsrf);
$router->post('/brands/delete-all',          [BrandController::class, 'deleteAllBrands'],        $authCsrf);
$router->post('/brands/clear-cache',         [BrandController::class, 'clearCache'],             $authCsrf);
$router->post('/brands/add-to-system',       [BrandController::class, 'addToSystem'],            $authCsrf);
$router->post('/brands/bulk-add-to-system',  [BrandController::class, 'bulkAddToSystem'],        $authCsrf);

// ─── Marka Eşleştirme ─────────────────────────────────
// DİKKAT: /brands/mapping* rotaları POST /brands/{id} jokerinden ÖNCE kalmalı.
$router->get('/brands/mapping',                             [BrandMappingController::class, 'index'],             $authMiddleware);
$router->get('/brands/mapping/unmapped-count',              [BrandMappingController::class, 'unmappedCount'],     $authMiddleware);
$router->get('/brands/mapping/search',                      [BrandMappingController::class, 'searchTrendyol'],    $authMiddleware);
$router->post('/brands/mapping',                            [BrandMappingController::class, 'store'],             $authCsrf);
$router->post('/brands/mapping/bulk',                       [BrandMappingController::class, 'storeBulk'],         $authCsrf);
$router->post('/brands/mapping/{id}/delete',                [BrandMappingController::class, 'delete'],            $authCsrf);

$router->post('/brands/{id}',                [BrandController::class, 'update'],                 $authCsrf);
$router->post('/brands/{id}/delete',         [BrandController::class, 'delete'],                 $authCsrf);

// ─── Pazaryerleri ──────────────────────────────────────
$router->get('/marketplaces',                [MarketplaceController::class, 'index'],          $authMiddleware);
// Amazon OAuth dönüşü (statik yol; {id} rotalarından önce)
$router->get('/marketplaces/amazon/oauth/callback', [MarketplaceController::class, 'amazonOAuthCallback'], $authMiddleware);
$router->get('/marketplaces/{id}/edit',      [MarketplaceController::class, 'edit'],           $authMiddleware);
$router->post('/marketplaces/{id}',          [MarketplaceController::class, 'update'],         $authCsrf);
$router->post('/marketplaces/{id}/test',     [MarketplaceController::class, 'testConnection'],   $authCsrf);
// WooCommerce sipariş webhook'larını mağazaya özel adresle kur (çok segmentli; /marketplaces/{id} ile çakışmaz)
$router->post('/marketplaces/{id}/woo-webhooks', [MarketplaceController::class, 'wooWebhooks'],  $authCsrf);
$router->post('/marketplaces/{id}/initial-import',      [MarketplaceController::class, 'initialImport'],    $authCsrf);
$router->post('/marketplaces/{id}/initial-import-step', [MarketplaceController::class, 'initialImportStep'],$authCsrf);
// Pazaryeri verisini silme (bağlantıyı kaldır) — YIKICI, yetki AuthorizationService'te
$router->post('/marketplaces/{id}/purge-preview', [MarketplaceController::class, 'purgePreview'], $authCsrf);
$router->post('/marketplaces/{id}/purge',         [MarketplaceController::class, 'purge'],        $authCsrf);

// ─── Hepsiburada İlan Eşleştirme (çok segmentli; POST /marketplaces/{id} ile çakışmaz) ──
// Yalnız yerel kayıt eşler, Hepsiburada'ya yazmaz. Yetki: products.view / products.edit + 'products' modülü.
$router->get('/marketplaces/{id}/listing-match',               [ListingMatchController::class, 'index'],       $authMiddleware);
$router->get('/marketplaces/{id}/listing-match/data',          [ListingMatchController::class, 'data'],        $authMiddleware);
$router->get('/marketplaces/{id}/listing-match/search',        [ListingMatchController::class, 'search'],      $authMiddleware);
$router->post('/marketplaces/{id}/listing-match/refresh',      [ListingMatchController::class, 'refresh'],     $authCsrf);
$router->post('/marketplaces/{id}/listing-match/approve',      [ListingMatchController::class, 'approve'],     $authCsrf);
$router->post('/marketplaces/{id}/listing-match/unlink',       [ListingMatchController::class, 'unlink'],      $authCsrf);
$router->post('/marketplaces/{id}/listing-match/bulk-approve', [ListingMatchController::class, 'bulkApprove'], $authCsrf);

// ─── Faturalar ─────────────────────────────────────────
$router->get('/invoices',                    [InvoiceController::class, 'index'],       $authMiddleware);
$router->get('/invoices/ajax',               [InvoiceController::class, 'ajaxList'],    $authMiddleware);
$router->get('/invoices/settings',           [InvoiceController::class, 'settings'],    $authMiddleware);
$router->post('/invoices/settings',          [InvoiceController::class, 'updateSettings'], $authCsrf);
$router->post('/invoices/test-gib',          [InvoiceController::class, 'testGibConnection'], $authCsrf);
$router->get('/invoices/{id}',               [InvoiceController::class, 'show'],        $authMiddleware);
$router->get('/invoices/{id}/html',          [InvoiceController::class, 'viewHtml'],    $authMiddleware);
$router->get('/invoices/{id}/download',      [InvoiceController::class, 'downloadHtml'], $authMiddleware);
$router->post('/invoices/{id}/cancel',       [InvoiceController::class, 'cancel'],      $authCsrf);
$router->post('/invoices/{id}/sign-sms-start',  [InvoiceController::class, 'signSmsStart'],  $authCsrf);
$router->post('/invoices/{id}/sign-sms-verify', [InvoiceController::class, 'signSmsVerify'], $authCsrf);

// ─── Ürün İçe Aktarma ─────────────────────────────────
$router->get('/import',                      [ImportController::class, 'index'],            $authMiddleware);
$router->post('/import/marketplace',         [ImportController::class, 'startMarketplace'], $authCsrf);
$router->post('/import/marketplace/page',    [ImportController::class, 'importPage'],       $authCsrf);
$router->post('/import/csv',                 [ImportController::class, 'uploadCsv'],        $authCsrf);
$router->post('/import/xml',                 [ImportController::class, 'uploadXml'],        $authCsrf);
$router->get('/import/template',             [ImportController::class, 'downloadTemplate'], $authMiddleware);
$router->get('/import/export-excel',         [ImportController::class, 'exportExcel'],      $authMiddleware);
$router->get('/import/export-xml',           [ImportController::class, 'exportXml'],        $authMiddleware);
$router->post('/import/save-feed-settings',  [ImportController::class, 'saveFeedSettings'], $authCsrf);

// Public XML Feed (token + IP auth)
// NOT: GET /feed/{token} (yukarıda) bu yolu yakalar; publicXmlFeed GLOBAL feed ayarı + tenant'sız
// ürün sorgusu kullandığı için çok kiracılı yapıda bilerek ÖNE ALINMADI (mağaza bazlı feed: /feed/{token}).
$router->get('/feed/products.xml',           [ImportController::class, 'publicXmlFeed'],   []);

// ─── XML Feedler ───────────────────────────────────────
$router->get('/feeds',                       [FeedController::class, 'index'],         $authMiddleware);
$router->get('/feeds/create',                [FeedController::class, 'create'],        $authMiddleware);
$router->post('/feeds',                      [FeedController::class, 'store'],         $authCsrf);
$router->post('/feeds/{id}/regenerate',      [FeedController::class, 'regenerate'],    $authCsrf);
$router->post('/feeds/regenerate-all',       [FeedController::class, 'regenerateAll'], $authCsrf);
$router->post('/feeds/save-settings',       [FeedController::class, 'saveFeedSettings'], $authCsrf);

// ─── XML Profilleri (Ticimax/dbentegrasyon tarzı içe/dışa aktarım) ───
// Statik yollar {id} jokerinden ÖNCE tanımlanır (router kayıt sırasıyla eşleşir)
$router->get('/xml-profilleri',                    [XmlProfileController::class, 'index'],          $authMiddleware);
$router->get('/xml-profilleri/yeni',               [XmlProfileController::class, 'create'],         $authMiddleware);
$router->post('/xml-profilleri/analiz',            [XmlProfileController::class, 'analyze'],        $authCsrf);
$router->post('/xml-profilleri',                   [XmlProfileController::class, 'store'],          $authCsrf);
$router->get('/xml-profilleri/{id}/duzenle',       [XmlProfileController::class, 'edit'],           $authMiddleware);
$router->get('/xml-profilleri/{id}/kayitlar',      [XmlProfileController::class, 'logs'],           $authMiddleware);
$router->post('/xml-profilleri/{id}/calistir',     [XmlProfileController::class, 'run'],            $authCsrf);
$router->post('/xml-profilleri/{id}/sil',          [XmlProfileController::class, 'delete'],         $authCsrf);
$router->post('/xml-profilleri/{id}/stok-sifirla', [XmlProfileController::class, 'resetStock'],     $authCsrf);
$router->post('/xml-profilleri/{id}/urunleri-sil', [XmlProfileController::class, 'deleteProducts'], $authCsrf);
$router->post('/xml-profilleri/{id}',              [XmlProfileController::class, 'update'],         $authCsrf);
// Dışa aktarım XML çıktısı — PUBLIC (token ile, auth yok)
$router->get('/xml-islemleri/xml-ver-x',           [XmlProfileController::class, 'xmlVerX'],        []);

// ─── İadeler ───────────────────────────────────────────
$router->get('/returns',                     [ReturnController::class, 'index'], $authMiddleware);
$router->get('/returns/ajax',                [ReturnController::class, 'ajaxList'], $authMiddleware);
$router->get('/returns/issue-reasons',       [ReturnController::class, 'issueReasons'], $authMiddleware);
$router->get('/returns/pending-reasons',     [ReturnController::class, 'pendingReasons'], $authMiddleware);
$router->get('/returns/sync-status',         [ReturnController::class, 'syncStatus'], $authMiddleware);
$router->post('/returns/sync',               [ReturnController::class, 'sync'], $authCsrf);
$router->post('/returns/sync-stream',        [ReturnController::class, 'syncStream'], $authCsrf);
$router->post('/returns/{id}/approve',       [ReturnController::class, 'approve'], $authCsrf);
$router->post('/returns/{id}/reject',        [ReturnController::class, 'reject'], $authCsrf);
$router->post('/returns/{id}/pend',          [ReturnController::class, 'pend'], $authCsrf);

// ─── Sorular (Q&A) ─────────────────────────────────────
$router->get('/questions',                   [QuestionController::class, 'index'], $authMiddleware);
$router->get('/questions/ajax',              [QuestionController::class, 'ajaxList'], $authMiddleware);
$router->get('/questions/canned',            [QuestionController::class, 'cannedList'], $authMiddleware);
$router->post('/questions/canned',           [QuestionController::class, 'cannedStore'], $authCsrf);
$router->post('/questions/canned/{id}/delete', [QuestionController::class, 'cannedDelete'], $authCsrf);
$router->post('/questions/{id}/answer',      [QuestionController::class, 'answer'], $authCsrf);
$router->post('/questions/sync',             [QuestionController::class, 'sync'], $authCsrf);

// ─── Roller & Yetkiler ─────────────────────────────────
$router->get('/authorization',               [AuthorizationController::class, 'index'], $authMiddleware);
$router->get('/users',                       [UserController::class, 'index'],  $authMiddleware);
$router->get('/users/create',                [UserController::class, 'create'], $authMiddleware);
$router->post('/users',                      [UserController::class, 'store'],  $authCsrf);
$router->get('/users/{id}/edit',             [UserController::class, 'edit'],   $authMiddleware);
$router->post('/users/{id}',                 [UserController::class, 'update'], $authCsrf);
$router->post('/users/{id}/delete',          [UserController::class, 'delete'], $authCsrf);
$router->get('/roles',                       [RoleController::class, 'index'],  $authMiddleware);
$router->get('/roles/create',                [RoleController::class, 'create'], $authMiddleware);
$router->post('/roles',                      [RoleController::class, 'store'],  $authCsrf);
$router->get('/roles/{id}/edit',             [RoleController::class, 'edit'],   $authMiddleware);
$router->post('/roles/{id}',                 [RoleController::class, 'update'], $authCsrf);
$router->post('/roles/{id}/delete',          [RoleController::class, 'delete'], $authCsrf);
$router->get('/roles/{id}/users',            [RoleController::class, 'users'],  $authMiddleware);

// ─── Senkronizasyon ────────────────────────────────────
$router->get('/sync',                        [SyncController::class, 'index'],          $authMiddleware);
$router->post('/sync/orders',                [SyncController::class, 'triggerOrders'],  $authCsrf);
$router->post('/sync/stock',                 [SyncController::class, 'triggerStock'],   $authCsrf);
$router->post('/sync/price',                 [SyncController::class, 'triggerPrice'],   $authCsrf);
$router->post('/sync/products',              [SyncController::class, 'triggerProducts'],$authCsrf);
$router->post('/sync/returns',              [SyncController::class, 'triggerReturns'], $authCsrf);
$router->post('/sync/questions',            [SyncController::class, 'triggerQuestions'],$authCsrf);
$router->post('/sync/settings',             [SyncController::class, 'saveSettings'],  $authCsrf);

// ─── Finans ────────────────────────────────────────────
$router->get('/finance',                     [FinanceController::class, 'index'],         $authMiddleware);
$router->get('/finance/ajax',                [FinanceController::class, 'ajax'],          $authMiddleware);
$router->post('/finance/sync-day',           [FinanceController::class, 'syncDay'],       $authCsrf);

// Cron Endpoints (token auth - no session needed)
$router->get('/cron/sync-orders',            [SyncController::class, 'cronOrders'],    []);
$router->get('/cron/sync-stock',             [SyncController::class, 'cronStock'],     []);
$router->get('/cron/sync-price',             [SyncController::class, 'cronPrice'],     []);
$router->get('/cron/sync-products',          [SyncController::class, 'cronProducts'],  []);
$router->get('/cron/sync-returns',           [SyncController::class, 'cronReturns'],   []);
$router->get('/cron/sync-questions',         [SyncController::class, 'cronQuestions'], []);
$router->get('/cron/sync-publish-batches',  [SyncController::class, 'cronPublishBatches'], []);

// ─── Ayarlar ───────────────────────────────────────────
$router->get('/settings',                    [SettingController::class, 'index'],  $authMiddleware);
$router->post('/settings',                   [SettingController::class, 'save'],   $authCsrf);

// ─── Destek (ticket sistemi) ───────────────────────────
$router->get('/support',                     [SupportController::class, 'index'],  $authMiddleware);
$router->post('/support',                    [SupportController::class, 'store'],  $authCsrf);
$router->get('/support/{id}',                [SupportController::class, 'show'],   $authMiddleware);
$router->post('/support/{id}/reply',         [SupportController::class, 'reply'],  $authCsrf);
$router->post('/support/{id}/close',         [SupportController::class, 'close'],  $authCsrf);

// ─── Güncelleme ────────────────────────────────────────
$router->get('/updates',                     [UpdateController::class, 'index'],   $authMiddleware);

// ─── Bildirimler (Polling) ─────────────────────────────
$router->get('/notifications/check',         [NotificationController::class, 'check'], $authMiddleware);
// Faz 8 — Bildirim Merkezi (kalıcı sistem bildirimleri)
$router->get('/notifications',               [NotificationController::class, 'center'], $authMiddleware);
$router->post('/notifications/read',         [NotificationController::class, 'read'],   $authCsrf);

// ─── Belgeler & Doğrulama (MERCHANT — KYC, tenant-scoped) ──
$router->get('/documents',                   [\App\Controllers\StoreDocumentController::class, 'index'],    $authMiddleware);
$router->post('/documents/upload',           [\App\Controllers\StoreDocumentController::class, 'upload'],   $authCsrf);
$router->get('/documents/{id}/download',     [\App\Controllers\StoreDocumentController::class, 'download'], $authMiddleware);

// ─── Loglar (PLATFORM ADMIN — admin layout + AdminSession; merchant erişemez) ──
// $adminMw/$adminCsrf aşağıda tanımlı olduğu için burada FQN dizisi kullanılır.
$router->get('/logs',                        [LogController::class, 'index'], [\App\Middleware\AdminAuthMiddleware::class]);
$router->get('/logs/export',                 [LogController::class, 'export'], [\App\Middleware\AdminAuthMiddleware::class]);
$router->post('/logs/clean',                 [LogController::class, 'clean'], [\App\Middleware\AdminAuthMiddleware::class, CsrfMiddleware::class]);

// ─── Yedekler (Faz 3: ADMIN — AdminAuthMiddleware, merchant erişemez) ──
$adminMw   = [\App\Middleware\AdminAuthMiddleware::class];
$adminCsrf = [\App\Middleware\AdminAuthMiddleware::class, CsrfMiddleware::class];
$router->get('/backups',                     [BackupController::class, 'index'],    $adminMw);
$router->post('/backups',                    [BackupController::class, 'store'],    $adminCsrf);
$router->get('/backups/{id}/download',       [BackupController::class, 'download'], $adminMw);
$router->post('/backups/{id}/restore',       [BackupController::class, 'restore'],  $adminCsrf);
$router->post('/backups/{id}/delete',        [BackupController::class, 'delete'],   $adminCsrf);

// ─── Mağaza Entitlement Yönetimi (Faz 3: ADMIN) ──
$router->get('/stores',                          [\App\Controllers\StoreController::class, 'index'],        $adminMw);
$router->get('/admin/stores/create',             [\App\Controllers\StoreController::class, 'create'],       $adminMw);
$router->post('/admin/stores',                   [\App\Controllers\StoreController::class, 'store'],        $adminCsrf);
$router->get('/stores/{id}/entitlements',        [\App\Controllers\StoreController::class, 'entitlements'], $adminMw);
$router->post('/stores/{id}/marketplaces',       [\App\Controllers\StoreController::class, 'marketplace'],  $adminCsrf);
$router->post('/stores/{id}/modules',            [\App\Controllers\StoreController::class, 'module'],       $adminCsrf);
$router->get('/stores/{id}',                      [\App\Controllers\StoreController::class, 'show'],         $adminMw);
$router->post('/stores/{id}',                     [\App\Controllers\StoreController::class, 'update'],       $adminCsrf);
$router->post('/stores/{id}/status',             [\App\Controllers\StoreController::class, 'setStatus'],    $adminCsrf);
$router->post('/stores/{id}/verify',             [\App\Controllers\StoreController::class, 'verify'],       $adminCsrf);
$router->get('/stores/{id}/audit-log',           [\App\Controllers\StoreController::class, 'auditLog'],     $adminMw);
$router->get('/stores/{id}/data-export',         [\App\Controllers\StoreController::class, 'dataExport'],   $adminMw);
$router->post('/stores/{id}/invoices/{invId}/issue-earsiv',  [\App\Controllers\StoreController::class, 'issueEarsiv'],  $adminCsrf);
$router->post('/stores/{id}/invoices/{invId}/cancel-earsiv', [\App\Controllers\StoreController::class, 'cancelEarsiv'], $adminCsrf);
$router->get('/admin/notifications/poll',        [\App\Controllers\AdminNotificationController::class, 'poll'], $adminMw);
$router->get('/admin/mail-settings',              [\App\Controllers\AdminMailSettingsController::class, 'index'],      $adminMw);
$router->post('/admin/mail-settings',             [\App\Controllers\AdminMailSettingsController::class, 'save'],       $adminCsrf);
$router->post('/admin/mail-settings/test-smtp',   [\App\Controllers\AdminMailSettingsController::class, 'testSmtp'],   $adminCsrf);
$router->post('/admin/mail-settings/test-earsiv', [\App\Controllers\AdminMailSettingsController::class, 'testEarsiv'], $adminCsrf);
$router->post('/admin/mail-settings/bulk',        [\App\Controllers\AdminMailSettingsController::class, 'sendBulk'],   $adminCsrf);
$router->get('/admin/earsiv-settings',            [\App\Controllers\AdminMailSettingsController::class, 'earsiv'],     $adminMw);

// ─── Yönetim: Admin kullanıcılar + Roller (RBAC) ──
$router->get('/admin/admin-users',             [\App\Controllers\AdminUserController::class, 'index'],   $adminMw);
$router->get('/admin/admin-users/create',      [\App\Controllers\AdminUserController::class, 'create'],  $adminMw);
$router->post('/admin/admin-users',            [\App\Controllers\AdminUserController::class, 'store'],   $adminCsrf);
$router->get('/admin/admin-users/{id}/edit',   [\App\Controllers\AdminUserController::class, 'edit'],    $adminMw);
$router->post('/admin/admin-users/{id}',       [\App\Controllers\AdminUserController::class, 'update'],  $adminCsrf);
$router->post('/admin/admin-users/{id}/delete',[\App\Controllers\AdminUserController::class, 'delete'],  $adminCsrf);
$router->get('/admin/admin-roles',             [\App\Controllers\AdminRoleController::class, 'index'],   $adminMw);
$router->get('/admin/admin-roles/create',      [\App\Controllers\AdminRoleController::class, 'create'],  $adminMw);
$router->post('/admin/admin-roles',            [\App\Controllers\AdminRoleController::class, 'store'],   $adminCsrf);
$router->get('/admin/admin-roles/{id}/edit',   [\App\Controllers\AdminRoleController::class, 'edit'],    $adminMw);
$router->post('/admin/admin-roles/{id}',       [\App\Controllers\AdminRoleController::class, 'update'],  $adminCsrf);
$router->post('/admin/admin-roles/{id}/delete',[\App\Controllers\AdminRoleController::class, 'delete'],  $adminCsrf);

// ─── Destek Talepleri (ADMIN — tüm mağazalar) ──
$router->get('/admin/support',               [\App\Controllers\AdminSupportController::class, 'index'],  $adminMw);
$router->get('/admin/support/{id}',          [\App\Controllers\AdminSupportController::class, 'show'],   $adminMw);
$router->post('/admin/support/{id}/reply',   [\App\Controllers\AdminSupportController::class, 'reply'],  $adminCsrf);
$router->post('/admin/support/{id}/status',  [\App\Controllers\AdminSupportController::class, 'status'], $adminCsrf);

// ─── İletişim: Bildirim Gönder (mağazalara anlık in-app) ──
$router->get('/admin/broadcast',         [\App\Controllers\AdminBroadcastController::class, 'index'], $adminMw);
$router->post('/admin/broadcast/send',   [\App\Controllers\AdminBroadcastController::class, 'send'],  $adminCsrf);

// ─── Güncelleme / Changelog (ADMIN — yayınla/düzenle/taslak/sil) ──
$router->get('/admin/updates',               [\App\Controllers\AdminUpdateController::class, 'index'],         $adminMw);
$router->post('/admin/updates',              [\App\Controllers\AdminUpdateController::class, 'store'],         $adminCsrf);
$router->post('/admin/updates/{id}',         [\App\Controllers\AdminUpdateController::class, 'update'],        $adminCsrf);
$router->post('/admin/updates/{id}/toggle',  [\App\Controllers\AdminUpdateController::class, 'togglePublish'], $adminCsrf);
$router->post('/admin/updates/{id}/delete',  [\App\Controllers\AdminUpdateController::class, 'delete'],        $adminCsrf);

// ─── API Anahtarları (ADMIN — tüm mağazalar; merchant salt-görüntüleme) ──
$router->get('/admin/api-tokens',                  [\App\Controllers\AdminApiTokenController::class, 'index'],      $adminMw);
$router->get('/admin/api-tokens/create',           [\App\Controllers\AdminApiTokenController::class, 'create'],     $adminMw);
$router->post('/admin/api-tokens',                 [\App\Controllers\AdminApiTokenController::class, 'store'],      $adminCsrf);
$router->post('/admin/api-tokens/{id}/toggle',     [\App\Controllers\AdminApiTokenController::class, 'toggle'],     $adminCsrf);
$router->post('/admin/api-tokens/{id}/regenerate', [\App\Controllers\AdminApiTokenController::class, 'regenerate'], $adminCsrf);
$router->post('/admin/api-tokens/{id}/delete',     [\App\Controllers\AdminApiTokenController::class, 'delete'],     $adminCsrf);

// ─── Faz 8: Marka & SEO (ADMIN — global Setting group='branding') ──
$router->get('/admin/branding',  [\App\Controllers\AdminBrandingController::class, 'index'], $adminMw);
$router->post('/admin/branding', [\App\Controllers\AdminBrandingController::class, 'save'],  $adminCsrf);

// ─── Faz 8: Pazarlama & Reklam Pikselleri (ADMIN — global Setting group='marketing') ──
$router->get('/admin/marketing',  [\App\Controllers\AdminMarketingController::class, 'index'], $adminMw);
$router->post('/admin/marketing', [\App\Controllers\AdminMarketingController::class, 'save'],  $adminCsrf);

// ─── Platform senkron ayarları (ADMIN — settings group='sync' + HTTP cron anahtarı; mağazaya gösterilmez) ──
$router->get('/admin/sync-settings',             [\App\Controllers\AdminSyncSettingsController::class, 'index'],           $adminMw);
$router->post('/admin/sync-settings/cron-token', [\App\Controllers\AdminSyncSettingsController::class, 'regenerateToken'], $adminCsrf);
$router->post('/admin/sync-settings',            [\App\Controllers\AdminSyncSettingsController::class, 'save'],            $adminCsrf);

// ─── Yasal Metinler (ADMIN — global Setting group='legal'; public yasal sayfalarda basılır) ──
$router->get('/admin/legal',  [\App\Controllers\AdminLegalController::class, 'index'], $adminMw);
$router->post('/admin/legal', [\App\Controllers\AdminLegalController::class, 'save'],  $adminCsrf);

// ─── AI Suite (ADMIN — sağlayıcı/anahtar/model/kredi; settings group='ai_suite') ──
$router->get('/admin/ai-suite',          [\App\Controllers\AdminAiSuiteController::class, 'index'],      $adminMw);
$router->post('/admin/ai-suite',         [\App\Controllers\AdminAiSuiteController::class, 'save'],       $adminCsrf);
$router->post('/admin/ai-suite/test',    [\App\Controllers\AdminAiSuiteController::class, 'test'],       $adminCsrf);
$router->get('/admin/ai-suite/credits',  [\App\Controllers\AdminAiSuiteController::class, 'credits'],    $adminMw);
$router->post('/admin/ai-suite/credits', [\App\Controllers\AdminAiSuiteController::class, 'creditsAdd'], $adminCsrf);

// ─── Faz 8: Mail Şablonları (ADMIN — email_templates) ──
$router->get('/admin/mail-templates',        [\App\Controllers\AdminMailTemplateController::class, 'index'],  $adminMw);
$router->get('/admin/mail-templates/{key}',  [\App\Controllers\AdminMailTemplateController::class, 'edit'],   $adminMw);
$router->post('/admin/mail-templates/{key}', [\App\Controllers\AdminMailTemplateController::class, 'update'], $adminCsrf);

// ─── Faz 9: Site CMS — Modüller (kurumsal site "Modüller" kartları) ──
$router->get('/admin/site-modules',               [\App\Controllers\AdminSiteModuleController::class, 'index'],  $adminMw);
$router->get('/admin/site-modules/create',        [\App\Controllers\AdminSiteModuleController::class, 'create'], $adminMw);
$router->post('/admin/site-modules',              [\App\Controllers\AdminSiteModuleController::class, 'store'],  $adminCsrf);
$router->get('/admin/site-modules/{id}/edit',     [\App\Controllers\AdminSiteModuleController::class, 'edit'],   $adminMw);
$router->post('/admin/site-modules/{id}',         [\App\Controllers\AdminSiteModuleController::class, 'update'], $adminCsrf);
$router->post('/admin/site-modules/{id}/delete',  [\App\Controllers\AdminSiteModuleController::class, 'delete'], $adminCsrf);

// ─── Faz 9: Site CMS — Ekran Görüntüleri (yükleme) ──
$router->get('/admin/screenshots',               [\App\Controllers\AdminScreenshotController::class, 'index'],  $adminMw);
$router->get('/admin/screenshots/create',        [\App\Controllers\AdminScreenshotController::class, 'create'], $adminMw);
$router->post('/admin/screenshots',              [\App\Controllers\AdminScreenshotController::class, 'store'],  $adminCsrf);
$router->get('/admin/screenshots/{id}/edit',     [\App\Controllers\AdminScreenshotController::class, 'edit'],   $adminMw);
$router->post('/admin/screenshots/{id}',         [\App\Controllers\AdminScreenshotController::class, 'update'], $adminCsrf);
$router->post('/admin/screenshots/{id}/delete',  [\App\Controllers\AdminScreenshotController::class, 'delete'], $adminCsrf);

// ─── Faz 9: Site CMS — SSS ──
$router->get('/admin/faqs',               [\App\Controllers\AdminFaqController::class, 'index'],  $adminMw);
$router->get('/admin/faqs/create',        [\App\Controllers\AdminFaqController::class, 'create'], $adminMw);
$router->post('/admin/faqs',              [\App\Controllers\AdminFaqController::class, 'store'],  $adminCsrf);
$router->get('/admin/faqs/{id}/edit',     [\App\Controllers\AdminFaqController::class, 'edit'],   $adminMw);
$router->post('/admin/faqs/{id}',         [\App\Controllers\AdminFaqController::class, 'update'], $adminCsrf);
$router->post('/admin/faqs/{id}/delete',  [\App\Controllers\AdminFaqController::class, 'delete'], $adminCsrf);

// ─── Faz 10: Site CMS — Referanslar (Testimonials) ──
$router->get('/admin/testimonials',              [\App\Controllers\AdminTestimonialController::class, 'index'],  $adminMw);
$router->get('/admin/testimonials/create',       [\App\Controllers\AdminTestimonialController::class, 'create'], $adminMw);
$router->post('/admin/testimonials',             [\App\Controllers\AdminTestimonialController::class, 'store'],  $adminCsrf);
$router->get('/admin/testimonials/{id}/edit',    [\App\Controllers\AdminTestimonialController::class, 'edit'],   $adminMw);
$router->post('/admin/testimonials/{id}',        [\App\Controllers\AdminTestimonialController::class, 'update'], $adminCsrf);
$router->post('/admin/testimonials/{id}/delete', [\App\Controllers\AdminTestimonialController::class, 'delete'], $adminCsrf);

// ─── Faz 10: Site CMS — Blog ──
$router->get('/admin/blog',              [\App\Controllers\AdminBlogController::class, 'index'],  $adminMw);
$router->get('/admin/blog/create',       [\App\Controllers\AdminBlogController::class, 'create'], $adminMw);
$router->post('/admin/blog',             [\App\Controllers\AdminBlogController::class, 'store'],  $adminCsrf);
$router->get('/admin/blog/{id}/edit',    [\App\Controllers\AdminBlogController::class, 'edit'],   $adminMw);
$router->post('/admin/blog/{id}',        [\App\Controllers\AdminBlogController::class, 'update'], $adminCsrf);
$router->post('/admin/blog/{id}/delete', [\App\Controllers\AdminBlogController::class, 'delete'], $adminCsrf);

// ─── Faz 10: Site CMS — Gelen Kutusu (Leads) ──
$router->get('/admin/leads',              [\App\Controllers\AdminLeadController::class, 'index'],  $adminMw);
$router->post('/admin/leads/{id}/status', [\App\Controllers\AdminLeadController::class, 'status'], $adminCsrf);
$router->post('/admin/leads/{id}/delete', [\App\Controllers\AdminLeadController::class, 'delete'], $adminCsrf);

// ─── Faz 9: Ödeme Ayarları (PayTR + havale) ──
$router->get('/admin/payment-settings',  [\App\Controllers\AdminPaymentSettingsController::class, 'index'], $adminMw);
$router->post('/admin/payment-settings', [\App\Controllers\AdminPaymentSettingsController::class, 'save'],  $adminCsrf);

// ─── Faz 9: Satılabilir Modül Kataloğu (add-on fiyatları) ──
$router->get('/admin/module-catalog',         [\App\Controllers\AdminModuleCatalogController::class, 'index'],  $adminMw);
$router->post('/admin/module-catalog/{key}',  [\App\Controllers\AdminModuleCatalogController::class, 'update'], $adminCsrf);

// ─── Faz 11: Satılabilir Pazaryeri Kataloğu (add-on fiyatları) ──
$router->get('/admin/marketplace-catalog',        [\App\Controllers\AdminMarketplaceCatalogController::class, 'index'],  $adminMw);
$router->post('/admin/marketplace-catalog/{id}',  [\App\Controllers\AdminMarketplaceCatalogController::class, 'update'], $adminCsrf);

// ─── Faz 8: Ticaret — Paketler ──
$router->get('/admin/packages',              [\App\Controllers\AdminPackageController::class, 'index'],  $adminMw);
$router->get('/admin/packages/create',       [\App\Controllers\AdminPackageController::class, 'create'], $adminMw);
$router->post('/admin/packages',             [\App\Controllers\AdminPackageController::class, 'store'],  $adminCsrf);
$router->get('/admin/packages/{id}/edit',    [\App\Controllers\AdminPackageController::class, 'edit'],   $adminMw);
$router->post('/admin/packages/{id}',        [\App\Controllers\AdminPackageController::class, 'update'], $adminCsrf);
$router->post('/admin/packages/{id}/copy',   [\App\Controllers\AdminPackageController::class, 'copy'],   $adminCsrf);
$router->post('/admin/packages/{id}/toggle', [\App\Controllers\AdminPackageController::class, 'toggle'], $adminCsrf);
$router->post('/admin/packages/{id}/delete', [\App\Controllers\AdminPackageController::class, 'delete'], $adminCsrf);

// ─── Faz 8: Ticaret — Mağaza Başvuruları (onay→provizyon) ──
$router->get('/admin/signups',               [\App\Controllers\AdminSignupController::class, 'index'],   $adminMw);
$router->post('/admin/signups/settings',     [\App\Controllers\AdminSignupController::class, 'saveSettings'], $adminCsrf);

// ─── Mağaza Belgeleri (ADMIN — KYC inceleme + tür yönetimi) ──
$router->get('/admin/documents',                   [\App\Controllers\AdminDocumentController::class, 'index'],      $adminMw);
$router->get('/admin/documents/{id}/download',     [\App\Controllers\AdminDocumentController::class, 'download'],   $adminMw);
$router->post('/admin/documents/{id}/review',      [\App\Controllers\AdminDocumentController::class, 'review'],     $adminCsrf);
$router->get('/admin/document-types',              [\App\Controllers\AdminDocumentController::class, 'types'],      $adminMw);
$router->post('/admin/document-types',             [\App\Controllers\AdminDocumentController::class, 'saveType'],   $adminCsrf);
$router->post('/admin/document-types/{id}/toggle', [\App\Controllers\AdminDocumentController::class, 'toggleType'], $adminCsrf);
$router->post('/admin/document-types/{id}/toggle-required', [\App\Controllers\AdminDocumentController::class, 'toggleRequired'], $adminCsrf);
$router->post('/admin/document-types/{id}/delete',          [\App\Controllers\AdminDocumentController::class, 'deleteType'],     $adminCsrf);
$router->post('/admin/signups/{id}/approve', [\App\Controllers\AdminSignupController::class, 'approve'], $adminCsrf);
$router->post('/admin/signups/{id}/reject',  [\App\Controllers\AdminSignupController::class, 'reject'],  $adminCsrf);

// ─── Faz 8: Ticaret — Abonelikler (durum yönetimi + geçmiş) ──
$router->get('/admin/subscriptions',                       [\App\Controllers\AdminSubscriptionController::class, 'index'],      $adminMw);
$router->get('/admin/subscriptions/{storeId}/addons',        [\App\Controllers\AdminSubscriptionController::class, 'addons'],      $adminMw);
$router->post('/admin/subscriptions/{storeId}/addons/add',   [\App\Controllers\AdminSubscriptionController::class, 'addonAdd'],    $adminCsrf);
$router->post('/admin/subscriptions/{storeId}/addons/remove',[\App\Controllers\AdminSubscriptionController::class, 'addonRemove'], $adminCsrf);
$router->get('/admin/subscriptions/{storeId}/history',     [\App\Controllers\AdminSubscriptionController::class, 'history'],    $adminMw);
$router->post('/admin/subscriptions/{storeId}/suspend',    [\App\Controllers\AdminSubscriptionController::class, 'suspend'],    $adminCsrf);
$router->post('/admin/subscriptions/{storeId}/cancel',     [\App\Controllers\AdminSubscriptionController::class, 'cancel'],     $adminCsrf);
$router->post('/admin/subscriptions/{storeId}/reactivate', [\App\Controllers\AdminSubscriptionController::class, 'reactivate'], $adminCsrf);
$router->post('/admin/subscriptions/{storeId}/resync',     [\App\Controllers\AdminSubscriptionController::class, 'resync'],     $adminCsrf);

// ─── Faz 8: Ticaret — Ödemeler (havale/dekont onay) ──
$router->get('/admin/payments',               [\App\Controllers\AdminPaymentController::class, 'index'],   $adminMw);
$router->get('/admin/payments/{id}/receipt',  [\App\Controllers\AdminPaymentController::class, 'receipt'], $adminMw);
$router->post('/admin/payments/{id}/confirm', [\App\Controllers\AdminPaymentController::class, 'confirm'], $adminCsrf);
$router->post('/admin/payments/{id}/reject',  [\App\Controllers\AdminPaymentController::class, 'reject'],  $adminCsrf);

// ─── Faturalar & Ödemeler: global abonelik faturası listesi + e-Arşiv kes/iptal ──
$router->get('/admin/invoices',                    [\App\Controllers\AdminInvoiceController::class, 'index'],        $adminMw);
$router->post('/admin/invoices/{id}/issue-earsiv', [\App\Controllers\AdminInvoiceController::class, 'issueEarsiv'],  $adminCsrf);
$router->post('/admin/invoices/{id}/cancel-earsiv',[\App\Controllers\AdminInvoiceController::class, 'cancelEarsiv'], $adminCsrf);

// ─── Admin Paneli + 2FA Onboarding (Faz 3/4 — tam yetkili admin) ──
$router->get('/admin',                [\App\Controllers\AdminController::class, 'home'],            $adminMw);
$router->get('/admin/2fa/setup',      [\App\Controllers\AdminController::class, 'setup2faShow'],    $adminMw);
$router->post('/admin/2fa/setup',     [\App\Controllers\AdminController::class, 'setup2faVerify'],  $adminCsrf);
$router->post('/admin/2fa/recovery',  [\App\Controllers\AdminController::class, 'regenerateRecovery'], $adminCsrf);

// ─── Operasyon Paneli (Faz 7: Observability) — ADMIN, merchant erişemez ──
$router->get('/ops',                  [\App\Controllers\OpsController::class, 'home'],        $adminMw);
$router->get('/ops/queue',            [\App\Controllers\OpsController::class, 'queue'],       $adminMw);
$router->get('/ops/workers',          [\App\Controllers\OpsController::class, 'workers'],     $adminMw);
$router->get('/ops/health',           [\App\Controllers\OpsController::class, 'healthView'],  $adminMw);
$router->get('/ops/health.json',      [\App\Controllers\OpsController::class, 'healthJson'],  $adminMw);
$router->get('/ops/audit',            [\App\Controllers\OpsController::class, 'audit'],       $adminMw);
$router->get('/ops/store-summary',    [\App\Controllers\OpsController::class, 'storeSummary'], $adminMw);
// Operasyonel aksiyonlar (admin + CSRF)
$router->post('/ops/queue/recover',         [\App\Controllers\OpsController::class, 'recoverStuck'],   $adminCsrf);
$router->post('/ops/queue/retry-all-dead',  [\App\Controllers\OpsController::class, 'retryAllDead'],   $adminCsrf);
$router->post('/ops/queue/purge',           [\App\Controllers\OpsController::class, 'purgeCompleted'], $adminCsrf);
$router->post('/ops/queue/{id}/retry',      [\App\Controllers\OpsController::class, 'retryDeadJob'],   $adminCsrf);

// ═══════════════════════════════════════════════════════
// YENİ ROTALAR — Product Marketplace Sync (Phase 2)
// ═══════════════════════════════════════════════════════
// NOT: Kategori/Marka Eşleştirme rotaları POST /categories/{id} ve /brands/{id}
// jokerlerinden önce eşleşmeleri için yukarıya (kendi bölümlerine) taşındı.

// ─── Akıllı Eşleştirme sihirbazı (bulanık + yapay zekâ) ──
$router->get('/mapping/auto',           [\App\Controllers\AutoMappingController::class, 'index'],     $authMiddleware);
$router->post('/mapping/auto/start',    [\App\Controllers\AutoMappingController::class, 'start'],     $authCsrf);
$router->post('/mapping/auto/chunk',    [\App\Controllers\AutoMappingController::class, 'chunk'],     $authCsrf);
$router->post('/mapping/auto/ai',       [\App\Controllers\AutoMappingController::class, 'aiChunk'],   $authCsrf);
$router->post('/mapping/auto/apply',    [\App\Controllers\AutoMappingController::class, 'apply'],     $authCsrf);
$router->post('/mapping/auto/undo',     [\App\Controllers\AutoMappingController::class, 'undo'],      $authCsrf);
$router->post('/mapping/auto/cancel',   [\App\Controllers\AutoMappingController::class, 'cancel'],    $authCsrf);
$router->post('/mapping/auto/proposals',[\App\Controllers\AutoMappingController::class, 'proposals'], $authCsrf);

// ─── Fiyat Kuralları ──────────────────────────────────
// Statik /price-rules/preview, POST /price-rules/{id} jokerinden ÖNCE.
$router->get('/price-rules',                                [ProductController::class, 'priceRules'],             $authMiddleware);
$router->post('/price-rules',                               [ProductController::class, 'storePriceRule'],         $authCsrf);
$router->post('/price-rules/preview',                       [ProductController::class, 'previewPriceRule'],       $authCsrf);
$router->post('/price-rules/{id}',                          [ProductController::class, 'updatePriceRule'],        $authCsrf);
$router->post('/price-rules/{id}/delete',                   [ProductController::class, 'deletePriceRule'],        $authCsrf);

// ─── Toplu Stok İşlemleri ─────────────────────────────
// NOT: /products/bulk-stock, /products/stock-movements ve /products/publish
// rotaları /products/{id} joker rotasından önce eşleşmeleri için yukarı
// (ürün rotaları bölümüne) taşındı.

// ─── Zenginleştirme (Post-Import Enrichment) ──────────
use App\Controllers\EnrichmentController;

$router->get('/enrichment',                                 [EnrichmentController::class, 'dashboard'],            $authMiddleware);
$router->post('/enrichment/bulk',                           [EnrichmentController::class, 'bulkEnrich'],           $authCsrf);
$router->post('/enrichment/product/{id}',                   [EnrichmentController::class, 'enrichProduct'],        $authCsrf);
$router->post('/enrichment/apply-category-mapping',         [EnrichmentController::class, 'applyCategoryMapping'], $authCsrf);
$router->post('/enrichment/apply-brand-mapping',            [EnrichmentController::class, 'applyBrandMapping'],    $authCsrf);
$router->post('/enrichment/brand-alias',                    [EnrichmentController::class, 'addBrandAlias'],        $authCsrf);
$router->post('/enrichment/brand-alias/{id}/delete',        [EnrichmentController::class, 'deleteBrandAlias'],     $authCsrf);
$router->post('/enrichment/retry-failed',                   [EnrichmentController::class, 'retryFailed'],          $authCsrf);
$router->get('/enrichment/incomplete',                      [EnrichmentController::class, 'incomplete'],           $authMiddleware);


// ═══════════════════════════════════════════════════════
// PUBLIC REST API  (v1) — Token Auth, No CSRF
// ═══════════════════════════════════════════════════════

use App\Controllers\Api\ApiProductController;
use App\Controllers\Api\ApiOrderController;
use App\Controllers\Api\ApiCategoryController;
use App\Controllers\Api\ApiBrandController;
use App\Controllers\Api\ApiStockController;
use App\Controllers\Api\ApiDocsController;
use App\Controllers\ApiTokenController;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\ApiRateLimitMiddleware;

$apiAuth = [ApiAuthMiddleware::class, ApiRateLimitMiddleware::class];

// ─── API Docs (public, auth gerektirmez) ───────────────
$router->get('/api/docs',                                   [ApiDocsController::class, 'index'],         []);

// ─── Products API ──────────────────────────────────────
$router->get('/api/v1/products',                             [ApiProductController::class, 'list'],       $apiAuth);
$router->get('/api/v1/products/{id}',                        [ApiProductController::class, 'show'],       $apiAuth);
$router->get('/api/v1/products/sku/{sku}',                   [ApiProductController::class, 'showBySku'],  $apiAuth);
$router->post('/api/v1/products',                            [ApiProductController::class, 'create'],     $apiAuth);
$router->put('/api/v1/products/{id}',                        [ApiProductController::class, 'update'],     $apiAuth);
$router->put('/api/v1/products/{id}/stock',                  [ApiProductController::class, 'updateStock'],$apiAuth);
$router->put('/api/v1/products/{id}/price',                  [ApiProductController::class, 'updatePrice'],$apiAuth);

// ─── Orders API ────────────────────────────────────────
$router->get('/api/v1/orders',                               [ApiOrderController::class, 'list'],         $apiAuth);
$router->get('/api/v1/orders/statuses',                      [ApiOrderController::class, 'statuses'],     $apiAuth);
$router->get('/api/v1/orders/{id}',                          [ApiOrderController::class, 'show'],         $apiAuth);
$router->post('/api/v1/orders',                              [ApiOrderController::class, 'create'],       $apiAuth);
$router->put('/api/v1/orders/{id}/status',                   [ApiOrderController::class, 'updateStatus'], $apiAuth);

// ─── Categories API ────────────────────────────────────
$router->get('/api/v1/categories',                           [ApiCategoryController::class, 'list'],      $apiAuth);
$router->get('/api/v1/categories/tree',                      [ApiCategoryController::class, 'tree'],      $apiAuth);
$router->get('/api/v1/categories/{id}',                      [ApiCategoryController::class, 'show'],      $apiAuth);

// ─── Brands API ────────────────────────────────────────
$router->get('/api/v1/brands',                               [ApiBrandController::class, 'list'],         $apiAuth);
$router->get('/api/v1/brands/{id}',                          [ApiBrandController::class, 'show'],         $apiAuth);

// ─── Stock API ─────────────────────────────────────────
$router->get('/api/v1/stock/summary',                        [ApiStockController::class, 'summary'],      $apiAuth);
$router->post('/api/v1/stock/bulk-update',                   [ApiStockController::class, 'bulkUpdate'],   $apiAuth);

// ─── API Token Yönetimi (Panel) ────────────────────────
// API Anahtarları artık ADMIN kontrolünde (yönetim: /admin/api-tokens). Merchant YALNIZ salt-görüntüleme.
$router->get('/settings/api-tokens',                         [ApiTokenController::class, 'index'],        $authMiddleware);

// ─── Ayarlar — bölüm bazlı (api-tokens literal route'larından SONRA; {section} catch-all) ───
$router->get('/settings/{section}',          [SettingController::class, 'index'], $authMiddleware);
$router->post('/settings/{section}',         [SettingController::class, 'save'],  $authCsrf);
