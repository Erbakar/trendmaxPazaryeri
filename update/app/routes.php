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
use App\Controllers\CategoryController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\FeedController;
use App\Controllers\ImportController;
use App\Controllers\InvoiceController;
use App\Controllers\LogController;
use App\Controllers\BackupController;
use App\Controllers\MarketplaceController;
use App\Controllers\FinanceController;
use App\Controllers\NotificationController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Controllers\QuestionController;
use App\Controllers\ReturnController;
use App\Controllers\RoleController;
use App\Controllers\SettingController;
use App\Controllers\SupportController;
use App\Controllers\SyncController;
use App\Controllers\UpdateController;
use App\Controllers\UserController;
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

// ─── Root → Dashboard ──────────────────────────────────
$router->get('/', [DashboardController::class, 'index'], [AuthMiddleware::class]);


// ═══════════════════════════════════════════════════════
// KORUNMALI ROTALAR (Auth + CSRF gerektirir)
// ═══════════════════════════════════════════════════════

$authMiddleware = [AuthMiddleware::class];
$authCsrf       = [AuthMiddleware::class, CsrfMiddleware::class];

// ─── Dashboard ─────────────────────────────────────────
$router->get('/dashboard',       [DashboardController::class, 'index'], $authMiddleware);
$router->get('/dashboard/stats', [DashboardController::class, 'stats'], $authMiddleware);

// ─── Siparişler ────────────────────────────────────────
$router->get('/orders',                      [OrderController::class, 'index'],         $authMiddleware);
$router->get('/orders/ajax',                 [OrderController::class, 'ajaxList'],      $authMiddleware);
$router->get('/orders/{id}',                 [OrderController::class, 'show'],          $authMiddleware);
$router->post('/orders/sync',               [OrderController::class, 'sync'],          $authCsrf);
$router->post('/orders/sync-stream',        [OrderController::class, 'syncStream'],    $authMiddleware);
$router->get('/orders/{id}/cargo-label',     [OrderController::class, 'cargoLabel'],    $authMiddleware);
$router->get('/orders/{id}/cargo-preview',   [OrderController::class, 'cargoLabelPreview'], $authMiddleware);
$router->post('/orders/{id}/invoice',        [OrderController::class, 'createInvoice'], $authCsrf);
$router->post('/orders/{id}/send-invoice-to-marketplace', [OrderController::class, 'sendInvoiceToMarketplace'], $authCsrf);
$router->post('/orders/bulk-invoice',        [OrderController::class, 'bulkInvoice'],   $authCsrf);
$router->post('/orders/{id}/archive',        [OrderController::class, 'archive'],       $authCsrf);
$router->post('/orders/{id}/ship',           [OrderController::class, 'shipOrder'],     $authCsrf);
$router->post('/orders/{id}/split-package',  [OrderController::class, 'splitPackage'],   $authCsrf);
$router->post('/orders/{id}/partial-cancel', [OrderController::class, 'partialCancel'],  $authCsrf);

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
$router->post('/products/manual-sync',       [ProductController::class, 'manualSync'],  $authMiddleware);
$router->post('/products/inline-update',     [ProductController::class, 'inlineUpdate'], $authMiddleware);
$router->get('/products/mp-products',        [ProductController::class, 'mpProductList'],    $authMiddleware);
$router->post('/products/auto-match',        [ProductController::class, 'autoMatchProducts'], $authMiddleware);
$router->post('/products/add-to-system',     [ProductController::class, 'addMpProductToSystem'], $authMiddleware);
$router->post('/products/upload-image',      [ProductController::class, 'uploadImage'],          $authMiddleware);
$router->get('/products/{id}',               [ProductController::class, 'show'],        $authMiddleware);
$router->get('/products/{id}/edit',          [ProductController::class, 'edit'],        $authMiddleware);
$router->post('/products/{id}',              [ProductController::class, 'update'],      $authCsrf);
$router->post('/products/{id}/stock',        [ProductController::class, 'adjustStock'], $authCsrf);
$router->post('/products/{id}/sync',         [ProductController::class, 'syncProduct'], $authMiddleware);
$router->get('/products/{id}/marketplace-settings',  [ProductController::class, 'getMarketplaceSettings'],  $authMiddleware);
$router->post('/products/{id}/marketplace-settings', [ProductController::class, 'saveMarketplaceSettings'], $authCsrf);

// ─── Kategoriler ───────────────────────────────────────
$router->get('/categories',                  [CategoryController::class, 'index'],               $authMiddleware);
$router->get('/categories/ajax',             [CategoryController::class, 'ajaxList'],            $authMiddleware);
$router->get('/categories/search-cache',     [CategoryController::class, 'searchCache'],         $authMiddleware);
$router->post('/categories',                 [CategoryController::class, 'store'],               $authMiddleware);
$router->post('/categories/sync-marketplace', [CategoryController::class, 'syncFromMarketplace'], $authMiddleware);
$router->post('/categories/auto-match',      [CategoryController::class, 'autoMatch'],           $authMiddleware);
$router->post('/categories/manual-map',      [CategoryController::class, 'manualMap'],           $authMiddleware);
$router->post('/categories/delete-all',      [CategoryController::class, 'deleteAllCategories'], $authMiddleware);
$router->post('/categories/clear-cache',     [CategoryController::class, 'clearCache'],          $authMiddleware);
$router->get('/categories/mp-list',          [CategoryController::class, 'mpCategoryList'],      $authMiddleware);
$router->get('/categories/mp-tree',          [CategoryController::class, 'mpCategoryTree'],      $authMiddleware);
$router->post('/categories/add-to-system',   [CategoryController::class, 'addToSystem'],         $authMiddleware);
$router->post('/categories/bulk-add-to-system', [CategoryController::class, 'bulkAddToSystem'],  $authMiddleware);
$router->post('/categories/{id}',            [CategoryController::class, 'update'],              $authMiddleware);
$router->post('/categories/{id}/delete',     [CategoryController::class, 'delete'],              $authMiddleware);

// ─── Markalar ──────────────────────────────────────────
$router->get('/brands',                      [BrandController::class, 'index'],                  $authMiddleware);
$router->get('/brands/ajax',                 [BrandController::class, 'ajaxList'],               $authMiddleware);
$router->get('/brands/search-cache',         [BrandController::class, 'searchCache'],            $authMiddleware);
$router->get('/brands/mp-list',              [BrandController::class, 'mpBrandList'],            $authMiddleware);
$router->post('/brands',                     [BrandController::class, 'store'],                  $authMiddleware);
$router->post('/brands/sync-marketplace',    [BrandController::class, 'syncFromMarketplace'],    $authMiddleware);
$router->post('/brands/bulk-delete',         [BrandController::class, 'bulkDelete'],             $authMiddleware);
$router->post('/brands/auto-match',          [BrandController::class, 'autoMatch'],              $authMiddleware);
$router->post('/brands/manual-map',          [BrandController::class, 'manualMap'],              $authMiddleware);
$router->post('/brands/delete-all',          [BrandController::class, 'deleteAllBrands'],        $authMiddleware);
$router->post('/brands/clear-cache',         [BrandController::class, 'clearCache'],             $authMiddleware);
$router->post('/brands/add-to-system',       [BrandController::class, 'addToSystem'],            $authMiddleware);
$router->post('/brands/bulk-add-to-system',  [BrandController::class, 'bulkAddToSystem'],        $authMiddleware);
$router->post('/brands/{id}',                [BrandController::class, 'update'],                 $authMiddleware);
$router->post('/brands/{id}/delete',         [BrandController::class, 'delete'],                 $authMiddleware);

// ─── Pazaryerleri ──────────────────────────────────────
$router->get('/marketplaces',                [MarketplaceController::class, 'index'],          $authMiddleware);
$router->get('/marketplaces/{id}/edit',      [MarketplaceController::class, 'edit'],           $authMiddleware);
$router->post('/marketplaces/{id}',          [MarketplaceController::class, 'update'],         $authCsrf);
$router->post('/marketplaces/{id}/test',     [MarketplaceController::class, 'testConnection'],   $authCsrf);
$router->post('/marketplaces/{id}/initial-import',      [MarketplaceController::class, 'initialImport'],    $authCsrf);
$router->post('/marketplaces/{id}/initial-import-step', [MarketplaceController::class, 'initialImportStep'],$authCsrf);

// ─── Amazon OAuth Callback ─────────────────────────────
$router->get('/marketplaces/amazon/oauth/callback', [MarketplaceController::class, 'amazonOAuthCallback'], $authMiddleware);

// ─── Amazon OAuth Callback ─────────────────────────────
$router->get('/marketplaces/amazon/oauth/callback', [MarketplaceController::class, 'amazonOAuthCallback'], $authMiddleware);

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

// ─── Ürün İçe Aktarma ─────────────────────────────────
$router->get('/import',                      [ImportController::class, 'index'],            $authMiddleware);
$router->post('/import/marketplace',         [ImportController::class, 'startMarketplace'], $authCsrf);
$router->post('/import/marketplace/page',    [ImportController::class, 'importPage'],       $authMiddleware);
$router->post('/import/csv',                 [ImportController::class, 'uploadCsv'],        $authCsrf);
$router->post('/import/xml',                 [ImportController::class, 'uploadXml'],        $authCsrf);
$router->get('/import/template',             [ImportController::class, 'downloadTemplate'], $authMiddleware);
$router->get('/import/export-excel',         [ImportController::class, 'exportExcel'],      $authMiddleware);
$router->get('/import/export-xml',           [ImportController::class, 'exportXml'],        $authMiddleware);
$router->post('/import/save-feed-settings',  [ImportController::class, 'saveFeedSettings'], $authCsrf);

// Public XML Feed (token + IP auth)
$router->get('/feed/products.xml',           [ImportController::class, 'publicXmlFeed'],   []);

// ─── XML Feedler ───────────────────────────────────────
$router->get('/feeds',                       [FeedController::class, 'index'],         $authMiddleware);
$router->get('/feeds/create',                [FeedController::class, 'create'],        $authMiddleware);
$router->post('/feeds',                      [FeedController::class, 'store'],         $authCsrf);
$router->post('/feeds/{id}/regenerate',      [FeedController::class, 'regenerate'],    $authCsrf);
$router->post('/feeds/regenerate-all',       [FeedController::class, 'regenerateAll'], $authCsrf);
$router->post('/feeds/save-settings',       [FeedController::class, 'saveFeedSettings'], $authCsrf);

// ─── İadeler ───────────────────────────────────────────
$router->get('/returns',                     [ReturnController::class, 'index'], $authMiddleware);
$router->get('/returns/ajax',                [ReturnController::class, 'ajaxList'], $authMiddleware);
$router->get('/returns/issue-reasons',       [ReturnController::class, 'issueReasons'], $authMiddleware);
$router->get('/returns/pending-reasons',     [ReturnController::class, 'pendingReasons'], $authMiddleware);
$router->get('/returns/sync-status',         [ReturnController::class, 'syncStatus'], $authMiddleware);
$router->post('/returns/sync',               [ReturnController::class, 'sync'], $authCsrf);
$router->post('/returns/sync-stream',        [ReturnController::class, 'syncStream'], $authMiddleware);
$router->post('/returns/{id}/approve',       [ReturnController::class, 'approve'], $authCsrf);
$router->post('/returns/{id}/reject',        [ReturnController::class, 'reject'], $authCsrf);
$router->post('/returns/{id}/pend',          [ReturnController::class, 'pend'], $authCsrf);

// ─── Sorular (Q&A) ─────────────────────────────────────
$router->get('/questions',                   [QuestionController::class, 'index'], $authMiddleware);
$router->get('/questions/ajax',              [QuestionController::class, 'ajaxList'], $authMiddleware);
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

// ─── Ayarlar ───────────────────────────────────────────
$router->get('/settings',                    [SettingController::class, 'index'],  $authMiddleware);
$router->post('/settings',                   [SettingController::class, 'update'], $authCsrf);

// ─── Destek & Güncelleme ───────────────────────────────
$router->get('/support',                     [SupportController::class, 'index'],  $authMiddleware);
$router->get('/updates',                     [UpdateController::class, 'index'],   $authMiddleware);

// ─── Bildirimler (Polling) ─────────────────────────────
$router->get('/notifications/check',         [NotificationController::class, 'check'], $authMiddleware);

// ─── Loglar ────────────────────────────────────────────
$router->get('/logs',                        [LogController::class, 'index'], $authMiddleware);
$router->post('/logs/clean',                 [LogController::class, 'clean'], $authCsrf);

// ─── Yedekler ──────────────────────────────────────────
$router->get('/backups',                     [BackupController::class, 'index'],    $authMiddleware);
$router->post('/backups',                    [BackupController::class, 'store'],    $authCsrf);
$router->get('/backups/{id}/download',       [BackupController::class, 'download'], $authMiddleware);
$router->post('/backups/{id}/restore',       [BackupController::class, 'restore'],  $authCsrf);
$router->post('/backups/{id}/delete',        [BackupController::class, 'delete'],   $authCsrf);

// ═══════════════════════════════════════════════════════
// YENİ ROTALAR — Product Marketplace Sync (Phase 2)
// ═══════════════════════════════════════════════════════

use App\Controllers\CategoryMappingController;
use App\Controllers\BrandMappingController;

// ─── Kategori Eşleştirme ──────────────────────────────
$router->get('/categories/mapping',                         [CategoryMappingController::class, 'index'],          $authMiddleware);
$router->post('/categories/mapping',                        [CategoryMappingController::class, 'store'],          $authCsrf);
$router->post('/categories/mapping/{id}/delete',            [CategoryMappingController::class, 'delete'],         $authCsrf);
$router->get('/categories/mapping/search',                  [CategoryMappingController::class, 'searchTrendyol'], $authMiddleware);
$router->get('/categories/mapping/attributes/{categoryId}', [CategoryMappingController::class, 'attributes'],     $authMiddleware);

// ─── Marka Eşleştirme ─────────────────────────────────
$router->get('/brands/mapping',                             [BrandMappingController::class, 'index'],             $authMiddleware);
$router->post('/brands/mapping',                            [BrandMappingController::class, 'store'],             $authCsrf);
$router->post('/brands/mapping/{id}/delete',                [BrandMappingController::class, 'delete'],            $authCsrf);
$router->get('/brands/mapping/search',                      [BrandMappingController::class, 'searchTrendyol'],    $authMiddleware);

// ─── Fiyat Kuralları ──────────────────────────────────
$router->get('/price-rules',                                [ProductController::class, 'priceRules'],             $authMiddleware);
$router->post('/price-rules',                               [ProductController::class, 'storePriceRule'],         $authCsrf);
$router->post('/price-rules/{id}',                          [ProductController::class, 'updatePriceRule'],        $authCsrf);
$router->post('/price-rules/{id}/delete',                   [ProductController::class, 'deletePriceRule'],        $authCsrf);
$router->post('/price-rules/preview',                       [ProductController::class, 'previewPriceRule'],       $authMiddleware);

// ─── Toplu Stok İşlemleri ─────────────────────────────
$router->post('/products/bulk-stock',                       [ProductController::class, 'bulkStock'],              $authCsrf);
$router->post('/products/bulk-stock/csv',                   [ProductController::class, 'bulkStockCsv'],           $authCsrf);
$router->get('/products/stock-movements',                   [ProductController::class, 'stockMovements'],         $authMiddleware);

// ─── Ürün Gönderme ────────────────────────────────────
use App\Controllers\ProductPublishController;

$router->post('/products/publish',                          [ProductPublishController::class, 'publish'],          $authCsrf);
$router->post('/products/publish/validate',                 [ProductPublishController::class, 'validate'],         $authMiddleware);

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
$router->get('/settings/api-tokens',                         [ApiTokenController::class, 'index'],        $authMiddleware);
$router->get('/settings/api-tokens/create',                  [ApiTokenController::class, 'create'],       $authMiddleware);
$router->post('/settings/api-tokens',                        [ApiTokenController::class, 'store'],        $authCsrf);
$router->get('/settings/api-tokens/{id}',                    [ApiTokenController::class, 'show'],         $authMiddleware);
$router->post('/settings/api-tokens/{id}/toggle',            [ApiTokenController::class, 'toggle'],       $authCsrf);
$router->post('/settings/api-tokens/{id}/delete',            [ApiTokenController::class, 'delete'],       $authCsrf);
$router->post('/settings/api-tokens/{id}/regenerate',        [ApiTokenController::class, 'regenerate'],   $authCsrf);
