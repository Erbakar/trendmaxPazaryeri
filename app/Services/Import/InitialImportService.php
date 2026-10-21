<?php
/**
 * OzyConnect Panel — InitialImportService (İlk Verileri Çek)
 *
 * Pazaryeri aktif edildiğinde brands → categories → products toplu çekim.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-06-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Database;
use App\Core\Logger;
use App\Models\FinancialTransaction;
use App\Models\Marketplace;
use App\Models\MarketplaceBrand;
use App\Models\MarketplaceCategory;
use App\Models\Question;
use App\Models\SyncLog;
use App\Services\BrandMappingService;
use App\Services\CategoryMappingService;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\ReturnSyncService;
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

final class InitialImportService
{
    private ?array $marketplacesConfig = null;

    /** Son kategori çekimi listenin tamamını getirdi mi (sayfalama bütçesi dolmadı)? */
    private bool $categoryListComplete = true;

    public function __construct(
        private readonly Database $db,
        private readonly Marketplace $marketplaceModel,
        private readonly MarketplaceBrand $mpBrandModel,
        private readonly MarketplaceCategory $mpCategoryModel,
        private readonly SyncLog $syncLogModel,
        private readonly FinancialTransaction $financeModel,
        private readonly Question $questionModel,
        private readonly BrandMappingService $brandMappingService,
        private readonly CategoryMappingService $categoryMappingService,
        private readonly ProductImportService $productImportService,
        private readonly OrderSyncService $orderSyncService,
        private readonly ReturnSyncService $returnSyncService,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly PazaramaService $pazaramaService,
        private readonly PttavmService $pttavmService,
        private readonly CiceksepetiService $ciceksepetiService,
        private readonly IdefixService $idefixService,
        private readonly AmazonService $amazonService,
        private readonly OziygoService $oziygoService,
        private readonly ShopifyService $shopifyService,
        private readonly WooCommerceService $wooCommerceService,
        private readonly OpenCartService $openCartService,
        private readonly Logger $logger,
        // Ham SQL kullanan finans akışlarında store_id kaynağı (tenant izolasyonu)
        private readonly \App\Core\TenantContext $tenant,
    ) {}

    private function marketplaceConfig(string $slug): array
    {
        if ($this->marketplacesConfig === null) {
            $path = dirname(__DIR__, 3) . '/config/marketplaces.php';
            $this->marketplacesConfig = is_file($path) ? require $path : [];
        }

        $config = $this->marketplacesConfig[$slug] ?? [];
        return is_array($config) ? $config : [];
    }

    private function getHistoryDays(string $slug, string $key, int $default = 365): int
    {
        $api = $this->marketplaceConfig($slug)['api'] ?? [];
        $days = (int) ($api[$key] ?? $default);

        return $days > 0 ? $days : $default;
    }

    /**
     * İlk verileri çek — tek adımda brands + categories + products
     *
     * @return array{brands: int, categories: int, products: array, errors: string[]}
     */
    public function runFullImport(int $marketplaceId, string $slug, ?int $userId = null): array
    {
        $result = [
            'brands'     => 0,
            'categories' => 0,
            'products'   => ['created' => 0, 'updated' => 0, 'failed' => 0, 'total' => 0],
            'errors'     => [],
        ];

        // sync_logs.triggered_by ENUM('cron','manual','api','initial_import'): "user:N" strict modda
        // kaydı reddediyordu (içe aktarım daha başlamadan 500). Kullanıcı tetiklemesi = 'manual'.
        $logId = $this->syncLogModel->start($marketplaceId, 'initial_import', 'manual');

        try {
            // 1. Markalar
            $this->logger->info("İlk Import: Markalar çekiliyor ({$slug})", 'InitialImport');
            $result['brands'] = $this->importBrands($marketplaceId, $slug);

            // 2. Kategoriler
            $this->logger->info("İlk Import: Kategoriler çekiliyor ({$slug})", 'InitialImport');
            $result['categories'] = $this->importCategories($marketplaceId, $slug);

            // 3. Ürünler
            $this->logger->info("İlk Import: Ürünler çekiliyor ({$slug})", 'InitialImport');
            $productResult = $this->productImportService->importFromMarketplace($marketplaceId, $slug, 'full', $userId);
            $result['products'] = [
                'created' => $productResult->created,
                'updated' => $productResult->updated,
                'failed'  => $productResult->failed,
                'total'   => $productResult->total,
            ];

            $totalProcessed = $result['brands'] + $result['categories'] + $productResult->created + $productResult->updated;

            // Süreyi SyncLog::finish kendisi hesaplar (started_at'ten); 6. parametre hata listesidir.
            $this->syncLogModel->finish(
                $logId,
                $productResult->failed > 0 ? 'partial' : 'success',
                $totalProcessed,
                $totalProcessed,
                $productResult->failed,
                array_slice($productResult->errors, 0, 50)
            );
            $this->logger->info("İlk Import tamamlandı ({$slug}): {$result['brands']} marka, {$result['categories']} kategori, {$productResult->total} ürün", 'InitialImport');

        } catch (\Throwable $e) {
            try {
                $this->syncLogModel->finish($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            } catch (\Throwable $logError) {
                $this->logger->warning('İlk Import senkron kaydı kapatılamadı: ' . $logError->getMessage(), 'InitialImport');
            }
            $result['errors'][] = $e->getMessage();
            $this->logger->error("İlk Import hatası ({$slug}): " . $e->getMessage(), 'InitialImport');
        }

        return $result;
    }

    /**
     * Sadece markaları çek ve marketplace_brands tablosuna kaydet
     *
     * NOT (Trendyol): Trendyol'un /brands endpoint'i tüm Türkiye katalogunu (~250k+) döner
     * ve hepsini çekmek 5+ dakika sürer ve çoğu kullanılmaz. Bunun yerine satıcının
     * kendi ürünlerinde geçen markaları (product list endpoint'inden) çekiyoruz.
     */
    public function importBrands(int $marketplaceId, string $slug): int
    {
        // Trendyol için özel akış — kendi ürünlerimize ait markaları çek
        if ($slug === 'trendyol') {
            return $this->importTrendyolOwnBrands($marketplaceId);
        }

        $service = $this->resolveService($slug);
        if ($service === null) return 0;

        $service->loadCredentials();
        $brands = $service->fetchBrands();

        // API çağrısı uzun sürdü — MySQL bağlantısını kontrol et
        $this->db->reconnect();

        // Toplu upsert için veriyi normalize et
        $rows = [];
        foreach ($brands as $brand) {
            $mpBrandId = (string) ($brand['id'] ?? $brand['mp_brand_id'] ?? '');
            $name      = trim((string) ($brand['name'] ?? ''));
            if ($mpBrandId === '' || $name === '') continue;
            $rows[] = ['mp_brand_id' => $mpBrandId, 'name' => $name];
        }
        unset($brands);

        return $this->mpBrandModel->bulkUpsert($marketplaceId, $rows);
    }

    /**
     * Tek sayfa marka import — AJAX progress için (yalnızca Trendyol için sayfalı)
     * Diğer pazaryerleri için fallback olarak tam importBrands çağrılır.
     *
     * @return array{step: string, page: int, totalPages: int, processed: int, totalProcessed: int, done: bool, nextPage: int}
     */
    public function importBrandsPage(int $marketplaceId, string $slug, int $page = 0, int $size = 200): array
    {
        // Trendyol dışı pazaryerlerinde tek sayfada hepsi çekilir
        if ($slug !== 'trendyol') {
            $count = $this->importBrands($marketplaceId, $slug);
            return [
                'step'           => 'brands',
                'page'           => 0,
                'totalPages'     => 1,
                'processed'      => $count,
                'totalProcessed' => $count,
                'done'           => true,
                'nextPage'       => 0,
            ];
        }

        $this->trendyolService->loadCredentials();

        try {
            $resp = $this->trendyolService->fetchProducts($page, $size);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Trendyol ürün listesi çekilemedi (sayfa {$page}): " . $e->getMessage(),
                'InitialImport'
            );
            return [
                'step'           => 'brands',
                'page'           => $page,
                'totalPages'     => $page + 1,
                'processed'      => 0,
                'totalProcessed' => $this->mpBrandModel->countByMarketplace($marketplaceId),
                'done'           => true,
                'nextPage'       => $page,
            ];
        }

        $items = $resp['content'] ?? [];
        $totalPages = (int) ($resp['totalPages'] ?? 1);
        $itemCount = is_array($items) ? count($items) : 0;
        unset($resp);

        $unique = [];
        foreach ($items as $item) {
            $bid  = (string) ($item['brandId'] ?? '');
            $name = trim((string) ($item['brand'] ?? ''));
            if ($bid === '' || $name === '') continue;
            $unique[$bid] = $name;
        }
        unset($items);

        $rows = [];
        foreach ($unique as $bid => $name) {
            $rows[] = ['mp_brand_id' => $bid, 'name' => $name];
        }

        $processed = 0;
        if ($rows !== []) {
            $this->db->reconnect();
            $processed = $this->mpBrandModel->bulkUpsert($marketplaceId, $rows);
        }

        $totalProcessed = $this->mpBrandModel->countByMarketplace($marketplaceId);
        $nextPage = $page + 1;
        $done = $nextPage >= $totalPages || $itemCount < $size;

        return [
            'step'           => 'brands',
            'page'           => $page,
            'totalPages'     => max($totalPages, 1),
            'processed'      => $processed,
            'totalProcessed' => $totalProcessed,
            'done'           => $done,
            'nextPage'       => $nextPage,
        ];
    }

    /**
     * Trendyol'da satıcının kendi ürünlerinde geçen markaları topla — hızlı
     * Product list endpoint'i her ürünle birlikte brand + brandId döndürüyor
     */
    private function importTrendyolOwnBrands(int $marketplaceId): int
    {
        $this->trendyolService->loadCredentials();

        $unique = []; // brandId => brand name
        $page = 0;
        $size = 200; // Trendyol product list max 200

        do {
            try {
                $resp = $this->trendyolService->fetchProducts($page, $size);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Trendyol ürün listesi çekilemedi (sayfa {$page}): " . $e->getMessage(),
                    'InitialImport'
                );
                break;
            }

            $items = $resp['content'] ?? [];
            if (!is_array($items) || $items === []) break;

            foreach ($items as $item) {
                $bid  = (string) ($item['brandId'] ?? '');
                $name = trim((string) ($item['brand'] ?? ''));
                if ($bid === '' || $name === '') continue;
                $unique[$bid] = $name;
            }

            $totalPages = (int) ($resp['totalPages'] ?? 1);
            unset($resp, $items);

            $page++;
            if ($page >= $totalPages) break;
        } while (true);

        $this->logger->info(
            "Trendyol ürünlerinden {$page} sayfa tarandı, " . count($unique) . " benzersiz marka bulundu",
            'InitialImport'
        );

        if ($unique === []) return 0;

        // API çağrıları uzun sürdü — MySQL bağlantısını yenile
        $this->db->reconnect();

        $rows = [];
        foreach ($unique as $bid => $name) {
            $rows[] = ['mp_brand_id' => $bid, 'name' => $name];
        }

        return $this->mpBrandModel->bulkUpsert($marketplaceId, $rows);
    }

    /**
     * Sadece kategorileri çek ve marketplace_categories tablosuna kaydet
     */
    public function importCategories(int $marketplaceId, string $slug): int
    {
        $service = $this->resolveService($slug);
        if ($service === null) return 0;

        @set_time_limit(0);

        $service->loadCredentials();
        $categories = $service->fetchCategories();
        $this->categoryListComplete = !method_exists($service, 'lastCategoryFetchComplete')
            || $service->lastCategoryFetchComplete();

        // Kategori API'si çok sayfalı (HB ~14 sayfa) — MySQL bağlantısı zaman aşımına düşmüş olabilir
        $this->db->reconnect();

        return $this->flattenAndSaveCategories($marketplaceId, $categories);
    }

    /**
     * Tek sayfa ürün import — AJAX progress için
     *
     * @return array{step: string, page: int, totalPages: int, created: int, updated: int, failed: int}
     */
    public function importProductPage(int $marketplaceId, string $slug, int $page, int $size = 100, string $mode = 'full'): array
    {
        $this->productImportService->loadMarketplaceCredentials($slug);
        $result = $this->productImportService->importSinglePage($marketplaceId, $slug, $page, $size, $mode);

        $pageResult = [
            'step'          => 'products',
            'page'          => $result['page'],
            'totalPages'    => $result['totalPages'],
            'totalElements' => $result['totalElements'] ?? 0,
            'pageSize'      => $result['pageSize'] ?? 0,
            'created'       => $result['created'],
            'updated'       => $result['updated'],
            'failed'        => $result['failed'],
            'errors'        => $result['errors'] ?? [],
            'rateLimited'   => $result['rateLimited'] ?? false,
            'notice'        => $result['notice'] ?? '',
        ];

        // İmleç tabanlı sayfalama bilgisi (Shopify since_id) aynen taşınır.
        if (array_key_exists('nextPage', $result)) {
            $pageResult['nextPage'] = $result['nextPage'];
        }
        if (array_key_exists('done', $result)) {
            $pageResult['done'] = (bool) $result['done'];
        }

        return $pageResult;
    }

    /**
     * Siparişleri çek — OrderSyncService pazaryeri geçmiş ayarını kullanır
     *
     * @return array{fetched: int, processed: int, skipped: int, failed: int}
     */
    /**
     * Siparişleri BLOK BLOK çek — sihirbazın parçalı adımı.
     *
     * Tek istekte 60 günlük çekim zaman/bellek sınırına takılıp HTTP 500 veriyordu.
     *
     * @return array<string, mixed>
     */
    public function importOrdersBlock(int $marketplaceId, string $slug, int $days, int $blockIndex): array
    {
        set_time_limit(0);

        $result = $this->orderSyncService->syncMarketplaceBlock($marketplaceId, $slug, $days, $blockIndex);

        return [
            'step'        => 'orders',
            'page'        => $result['blockIndex'],
            'totalPages'  => $result['totalBlocks'],
            'done'        => $result['done'],
            'nextPage'    => $result['blockIndex'] + 1,
            'rangeStart'  => $result['rangeStart'],
            'rangeEnd'    => $result['rangeEnd'],
            'fetched'     => $result['fetched'],
            'processed'   => $result['processed'],
            'skipped'     => $result['skipped'],
            'failed'      => $result['failed'],
            'errors'      => $result['errors'],
        ];
    }

    public function importOrders(int $marketplaceId, string $slug, int $days = 0): array
    {
        $this->logger->info("İlk Import: Siparişler çekiliyor ({$slug}, son {$days} gün)", 'InitialImport');

        set_time_limit(0);

        try {
            $result = $this->orderSyncService->syncMarketplaceFull($marketplaceId, $slug, 'initial_import', $days);
            $this->logger->info("İlk Import: Siparişler tamamlandı ({$slug}) — fetched={$result['fetched']}, processed={$result['processed']}", 'InitialImport');
            return [
                'fetched'   => $result['fetched'] ?? 0,
                'processed' => $result['processed'] ?? 0,
                'skipped'   => $result['skipped'] ?? 0,
                'failed'    => $result['failed'] ?? 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error("Sipariş import hatası ({$slug}): " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'InitialImport');
            throw $e;
        }
    }

    /**
     * İadeleri çek (ReturnSyncService kullanır)
     *
     * @return array{fetched: int, processed: int, skipped: int, failed: int}
     */
    public function importReturns(int $marketplaceId, string $slug, int $days = 0): array
    {
        $this->logger->info("İlk Import: İadeler çekiliyor ({$slug}, son {$days} gün)", 'InitialImport');

        if (!in_array($slug, ['trendyol', 'n11', 'pazarama', 'hepsiburada', 'ciceksepeti', 'pttavm', 'idefix', 'oziygo', 'shopify', 'woocommerce'], true)) {
            return ['fetched' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'skippedReason' => 'not_supported'];
        }

        try {
            $result = $this->returnSyncService->syncMarketplace($marketplaceId, $slug, 'initial_import', true, $days);
            return [
                'fetched'   => $result['fetched'] ?? 0,
                'processed' => $result['processed'] ?? 0,
                'skipped'   => $result['skipped'] ?? 0,
                'failed'    => $result['failed'] ?? 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error("İade import hatası ({$slug}): " . $e->getMessage(), 'InitialImport');
            throw $e;
        }
    }

    /**
     * Finans verilerini çek — sadece Trendyol + Pazarama desteklenir
     * Son 30 gün — günlük itibariyle çeker
     *
     * @return array{created: int, skipped: int, supported: bool}
     */
    public function importFinance(int $marketplaceId, string $slug): array
    {
        if (!in_array($slug, ['trendyol', 'hepsiburada', 'pazarama'], true)) {
            return ['created' => 0, 'skipped' => 0, 'supported' => false];
        }

        $this->logger->info("İlk Import: Finans verileri çekiliyor ({$slug})", 'InitialImport');
        $totalCreated = 0;
        $totalSkipped = 0;

        try {
            if ($slug === 'trendyol') {
                $totalCreated = $this->importFinanceTrendyol($marketplaceId);
            } elseif ($slug === 'hepsiburada') {
                [$totalCreated, $totalSkipped] = $this->importFinanceHepsiburada($marketplaceId);
            } elseif ($slug === 'pazarama') {
                [$totalCreated, $totalSkipped] = $this->importFinancePazarama($marketplaceId);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Finans import hatası ({$slug}): " . $e->getMessage(), 'InitialImport');
        }

        return ['created' => $totalCreated, 'skipped' => $totalSkipped, 'supported' => true];
    }

    /**
     * Trendyol finans — son 30 gün Settlements + OtherFinancials
     */
    private function importFinanceTrendyol(int $marketplaceId): int
    {
        $this->trendyolService->loadCredentials();
        set_time_limit(0);
        $created = 0;
        $now = time();
        $settlementsAvailable = true; // İlk deneme başarısızsa tamamen atla

        // Son 30 gün, günlük bloklar
        $historyDays = $this->getHistoryDays('trendyol', 'finance_history_days', 365);
        for ($dayOffset = 0; $dayOffset < $historyDays; $dayOffset++) {
            $dayStart = ($now - ($dayOffset + 1) * 86400) * 1000;
            $dayEnd   = ($now - $dayOffset * 86400) * 1000;

            // Settlements — API 500 veriyorsa hepsini atla
            if ($settlementsAvailable) {
                try {
                    $page = 0;
                    do {
                        $result = $this->trendyolService->fetchSettlements((int)$dayStart, (int)$dayEnd, $page, 100);
                        $items = $result['content'] ?? [];
                        foreach ($items as $item) {
                            $normalized = $this->trendyolService->normalizeSettlement($item);
                            if ($this->financeModel->existsByExternalId($marketplaceId, $normalized['external_id'])) continue;
                            $normalized['marketplace_id'] = $marketplaceId;
                            $normalized['synced_at'] = date('Y-m-d H:i:s');
                            $this->financeModel->create($normalized);
                            $created++;
                        }
                        $page++;
                    } while ($page < ($result['totalPages'] ?? 1));
                } catch (\Throwable $e) {
                    // API 500 veriyorsa kalan günler için de denemekten vazgeç
                    if (str_contains($e->getMessage(), '500')) {
                        $this->logger->warning("Trendyol Settlements API 500 veriyor, atlanıyor", 'InitialImport');
                        $settlementsAvailable = false;
                    }
                }
            }

            // OtherFinancials
            try {
                $types = ['PaymentOrder', 'Stoppage', 'WireTransfer', 'IncomingTransfer', 'ReturnInvoice', 'CommissionAgreementInvoice'];
                foreach ($types as $txType) {
                    try {
                        $otherItems = $this->trendyolService->fetchOtherFinancials((int)$dayStart, (int)$dayEnd, $txType);
                        if (!is_array($otherItems)) continue;
                        foreach ($otherItems as $item) {
                            $externalId = (string)($item['id'] ?? md5($txType . json_encode($item)));
                            if ($this->financeModel->existsByExternalId($marketplaceId, $externalId)) continue;

                            $debt   = (float)($item['debt'] ?? 0);
                            $credit = (float)($item['credit'] ?? 0);
                            $gross  = $debt > 0 ? $debt : $credit;
                            $rawType = $item['transactionType'] ?? $txType;
                            $detectedType = match(true) {
                                str_contains($rawType, 'deme') || str_contains($rawType, 'Payment') => 'payment',
                                str_contains($rawType, 'Stopaj') || str_contains($rawType, 'Stoppage') => 'stoppage',
                                str_contains($rawType, 'Komisyon') || str_contains($rawType, 'Commission') => 'commission',
                                str_contains($rawType, 'Return') || str_contains($rawType, 'ade') => 'refund',
                                str_contains($rawType, 'Transfer') => 'wire_transfer',
                                default => 'other',
                            };
                            $net = ($detectedType === 'payment') ? $gross : -$gross;

                            $this->financeModel->create([
                                'marketplace_id'    => $marketplaceId,
                                'transaction_type'  => $detectedType,
                                'transaction_date'  => isset($item['transactionDate'])
                                    ? date('Y-m-d H:i:s', (int)($item['transactionDate'] / 1000))
                                    : date('Y-m-d H:i:s'),
                                'order_number'      => (string)($item['orderNumber'] ?? ''),
                                'description'       => $item['description'] ?? $rawType,
                                'gross_amount'      => $gross,
                                'commission_amount' => abs((float)($item['commissionAmount'] ?? 0)),
                                'net_amount'        => $net,
                                'currency'          => 'TRY',
                                'status'            => 'completed',
                                'external_id'       => $externalId,
                                'extra_data_json'   => json_encode($item),
                                'synced_at'         => date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Finans satırı içe aktarılamadı: ' . $e->getMessage(), 'Import');
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Finans dönem batch içe aktarılamadı: ' . $e->getMessage(), 'Import');
            }
        }

        return $created;
    }

    /**
     * Pazarama finans — siparişlerden türetilir (son 30 gün)
     *
     * @return array{0: int, 1: int} [created, skipped]
     */
    private function importFinancePazarama(int $marketplaceId): array
    {
        $created = 0;
        $skipped = 0;
        $now = time();
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare("
            SELECT o.id, o.marketplace_order_id, o.status, o.total_amount,
                   o.subtotal, o.tax_total, o.discount_total, o.ordered_at,
                   o.customer_name
            FROM orders o
            WHERE o.marketplace_id = :mpId
              AND o.store_id = :storeId
              AND o.ordered_at >= :dayStart
            ORDER BY o.ordered_at ASC
        ");
        // store_id ŞARTTI: filtresiz sorgu tüm bayilerin siparişlerini okuyup
        // aktif mağazaya finans kaydı yazıyordu.
        $stmt->execute([
            'mpId'     => $marketplaceId,
            'storeId'  => $this->tenant->currentStoreId(),
            'dayStart' => date('Y-m-d H:i:s', $now - ($this->getHistoryDays('pazarama', 'finance_history_days', 365) * 86400)),
        ]);
        $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($orders as $order) {
            $orderId = $order['marketplace_order_id'];
            $externalId = 'pzr_order_' . $orderId;
            if ($this->financeModel->existsByExternalId($marketplaceId, $externalId)) { $skipped++; continue; }

            $gross = (float)$order['total_amount'];
            $status = $order['status'];
            $isRefund = in_array($status, ['iptal', 'iade', 'cancelled', 'refunded'], true);

            $this->financeModel->create([
                'marketplace_id'    => $marketplaceId,
                'transaction_type'  => $isRefund ? 'refund' : 'sale',
                'transaction_date'  => $order['ordered_at'],
                'order_number'      => $orderId,
                'description'       => ($isRefund ? 'İade' : 'Satış') . " — #{$orderId}" . (!empty($order['customer_name']) ? " ({$order['customer_name']})" : ''),
                'gross_amount'      => $gross,
                'commission_amount' => 0,
                'net_amount'        => $isRefund ? -$gross : $gross,
                'currency'          => 'TRY',
                'status'            => 'completed',
                'external_id'       => $externalId,
                'extra_data_json'   => json_encode(['source' => 'order_based', 'status' => $status]),
                'synced_at'         => date('Y-m-d H:i:s'),
            ]);
            $created++;
        }

        return [$created, $skipped];
    }

    /**
     * Hepsiburada finans — fetchTransactions API (son 30 gün)
     *
     * @return array{0: int, 1: int} [created, skipped]
     */
    private function importFinanceHepsiburada(int $marketplaceId): array
    {
        $this->hepsiburadaService->loadCredentials();
        set_time_limit(0);
        $created = 0;
        $skipped = 0;
        $now = time();

        // Son 30 gün, günlük bloklar
        $historyDays = $this->getHistoryDays('hepsiburada', 'finance_history_days', 365);
        for ($dayOffset = 0; $dayOffset < $historyDays; $dayOffset++) {
            $dayStart = date('Y-m-d', $now - ($dayOffset + 1) * 86400);
            $dayEnd   = date('Y-m-d', $now - $dayOffset * 86400);

            try {
                $offset = 0;
                $limit = 100;

                do {
                    $response = $this->hepsiburadaService->fetchTransactions([
                        'begin_date' => $dayStart,
                        'end_date'   => $dayEnd,
                        'offset'     => $offset,
                        'limit'      => $limit,
                    ]);

                    $items = $response['data'] ?? $response['items'] ?? $response['content'] ?? [];
                    if (!is_array($items)) $items = [];

                    foreach ($items as $item) {
                        $externalId = (string) ($item['id'] ?? $item['transactionId'] ?? md5(json_encode($item)));
                        if ($this->financeModel->existsByExternalId($marketplaceId, $externalId)) {
                            $skipped++;
                            continue;
                        }

                        $gross = (float) ($item['amount'] ?? $item['transactionAmount'] ?? 0);
                        $rawType = (string) ($item['transactionType'] ?? $item['type'] ?? 'other');
                        $detectedType = match(true) {
                            str_contains($rawType, 'Sale') || str_contains($rawType, 'Payment') => 'sale',
                            str_contains($rawType, 'Commission') => 'commission',
                            str_contains($rawType, 'Return') || str_contains($rawType, 'Refund') => 'refund',
                            str_contains($rawType, 'Transfer') => 'wire_transfer',
                            str_contains($rawType, 'Shipping') || str_contains($rawType, 'Cargo') => 'shipping',
                            default => 'other',
                        };
                        $net = in_array($detectedType, ['refund', 'commission', 'shipping']) ? -abs($gross) : $gross;

                        $this->financeModel->create([
                            'marketplace_id'    => $marketplaceId,
                            'transaction_type'  => $detectedType,
                            'transaction_date'  => $item['transactionDate'] ?? $item['createdDate'] ?? $dayStart,
                            'order_number'      => (string) ($item['orderNumber'] ?? $item['orderId'] ?? ''),
                            'description'       => $item['description'] ?? $rawType,
                            'gross_amount'      => abs($gross),
                            'commission_amount' => abs((float) ($item['commissionAmount'] ?? $item['commission'] ?? 0)),
                            'net_amount'        => $net,
                            'currency'          => 'TRY',
                            'status'            => 'completed',
                            'external_id'       => $externalId,
                            'extra_data_json'   => json_encode($item),
                            'synced_at'         => date('Y-m-d H:i:s'),
                        ]);
                        $created++;
                    }

                    $offset += $limit;
                } while (count($items) === $limit);
            } catch (\Throwable $e) {
                $this->logger->warning("HB finans gün {$dayStart} hatası: " . $e->getMessage(), 'InitialImport');
            }
        }

        return [$created, $skipped];
    }

    /**
     * Soru/Cevapları çek (Trendyol + Hepsiburada destekli)
     *
     * @return array{fetched: int, created: int, skipped: int, supported: bool}
     */
    public function importQuestions(int $marketplaceId, string $slug, int $days = 0): array
    {
        $this->logger->info("İlk Import: Soru/Cevaplar çekiliyor ({$slug}, son {$days} gün)", 'InitialImport');

        if (!in_array($slug, ['trendyol', 'hepsiburada', 'pazarama', 'idefix'], true)) {
            return ['fetched' => 0, 'created' => 0, 'skipped' => 0, 'supported' => false];
        }

        try {
            $service = $this->resolveService($slug);
            $questions = [];

            if ($slug === 'trendyol') {
                // Trendyol Q&A API max 2 hafta aralık destekliyor
                // Günleri 14'lük dilimlere böl
                $chunkDays = 14;
                $days = $days > 0 ? $days : 365;
                $now = time();
                $startTs = strtotime("-{$days} days");

                while ($startTs < $now) {
                    $endTs = min($startTs + ($chunkDays * 86400), $now);
                    $startMs = $startTs * 1000;
                    $endMs = $endTs * 1000;

                    $chunk = $service->getQuestions(0, 50, '', $startMs, $endMs);
                    $questions = array_merge($questions, $chunk);

                    $startTs = $endTs + 1;
                }
            } elseif ($slug === 'hepsiburada') {
                // HB Satıcıya Sor API: fetchQuestions tüm durumları (bekleyen/cevaplanan/reddedilen/
                // süresi dolan) kendisi sayfalar; kimlik issueNumber (cevaplama ucu bunu ister) —
                // QuestionSyncService ile aynı eşleme.
                $questions = $service->fetchQuestions();
            } elseif ($slug === 'pazarama') {
                $questions = $service->fetchQuestions(['page_size' => 100, 'page' => 1]);
            } elseif ($slug === 'idefix') {
                $questions = $service->fetchQuestions();
            }

            $created = 0;
            $skipped = 0;

            foreach ($questions as $q) {
                // Bozuk yanıtta satırlar dizi olmayabilir (metin/sayı) — TypeError'a düşmesin
                if (!is_array($q)) continue;

                $qId = $this->extractQuestionId($slug, $q);
                if ($qId === '') continue;

                // Duplicate kontrolü
                $existing = $this->questionModel->findByMarketplaceQuestionId($marketplaceId, $qId);
                if ($existing !== false) {
                    $skipped++;
                    continue;
                }

                $data = $this->normalizeQuestion($slug, $q, $marketplaceId, $qId);
                $this->questionModel->create($data);
                $created++;
            }

            $total = count($questions);
            $this->logger->info("Soru/Cevap import tamamlandı ({$slug}): {$total} çekildi, {$created} yeni, {$skipped} atlandı", 'InitialImport');

            return ['fetched' => $total, 'created' => $created, 'skipped' => $skipped, 'supported' => true];
        } catch (\Throwable $e) {
            $this->logger->error("Soru/Cevap import hatası ({$slug}): " . $e->getMessage(), 'InitialImport');
            throw $e;
        }
    }

    /**
     * Pazaryeri soru verisinden soru ID çıkar
     */
    private function extractQuestionId(string $slug, array $q): string
    {
        return match ($slug) {
            'trendyol'    => (string) ($q['id'] ?? ''),
            'hepsiburada' => (string) ($q['issueNumber'] ?? ''),
            'pazarama'    => (string) ($q['questionId'] ?? $q['id'] ?? ''),
            'idefix'      => (string) ($q['id'] ?? ''),
            default       => '',
        };
    }

    /**
     * Pazaryeri soru verisini normalize et
     */
    private function normalizeQuestion(string $slug, array $q, int $marketplaceId, string $qId): array
    {
        if ($slug === 'trendyol') {
            $questionDate = isset($q['creationDate'])
                ? date('Y-m-d H:i:s', (int) ($q['creationDate'] / 1000))
                : date('Y-m-d H:i:s');
            // API dökümanı: answer tek obje olarak döner (answers array değil)
            $answer = $q['answer'] ?? null;
            $answerDate = !empty($answer['creationDate'])
                ? date('Y-m-d H:i:s', (int) ($answer['creationDate'] / 1000))
                : null;
            $answerText = $answer['text'] ?? null;
            $status = !empty($answerText) ? 'answered' : 'waiting';

            return [
                'marketplace_id'          => $marketplaceId,
                'marketplace_question_id' => $qId,
                'product_name'            => $q['productName'] ?? null,
                'marketplace_product_id'  => isset($q['productId']) ? (string) $q['productId'] : null,
                'product_image_url'       => $q['imageUrl'] ?? null,
                'question_text'           => $q['text'] ?? '',
                'question_date'           => $questionDate,
                'customer_name'           => $q['userName'] ?? $q['customerName'] ?? null,
                'answer_text'             => $answerText,
                'answer_date'             => $answerDate,
                'status'                  => $status,
                'is_public'               => (int) ($q['public'] ?? true),
                'extra_data_json'         => json_encode($q),
                'synced_at'               => date('Y-m-d H:i:s'),
            ];
        }

        // Hepsiburada — satır eşlemesi servisteki ortak normalizeQuestion'dan (QuestionSyncService ile aynı)
        if ($slug === 'hepsiburada') {
            if (method_exists($this->hepsiburadaService, 'normalizeQuestion')) {
                $row = $this->hepsiburadaService->normalizeQuestion($q);
                if (is_array($row)) {
                    return array_merge($row, [
                        'marketplace_id'          => $marketplaceId,
                        'marketplace_question_id' => $qId,
                        'synced_at'               => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            $answerText = $q['answer'] ?? $q['answerText'] ?? null;
            $status = !empty($answerText) ? 'answered' : 'waiting';
            $questionDate = $q['createdDate'] ?? $q['createDate'] ?? $q['questionDate'] ?? date('Y-m-d H:i:s');

            return [
                'marketplace_id'          => $marketplaceId,
                'marketplace_question_id' => $qId,
                'product_name'            => $q['productName'] ?? null,
                'marketplace_product_id'  => isset($q['productId']) ? (string) $q['productId'] : null,
                'product_image_url'       => $q['productImageUrl'] ?? $q['imageUrl'] ?? null,
                'question_text'           => $q['text'] ?? $q['question'] ?? $q['questionText'] ?? '',
                'question_date'           => $questionDate,
                'customer_name'           => $q['customerName'] ?? $q['userName'] ?? null,
                'answer_text'             => $answerText,
                'answer_date'             => $q['answerDate'] ?? null,
                'status'                  => $status,
                'is_public'               => 1,
                'extra_data_json'         => json_encode($q),
                'synced_at'               => date('Y-m-d H:i:s'),
            ];
        }

        // İdefix
        if ($slug === 'idefix') {
            $hasAnswer = !empty($q['productQuestionAnswer']);
            $answerText = $hasAnswer ? ($q['productQuestionAnswer'][0]['answerBody'] ?? null) : null;
            $status = $hasAnswer ? 'answered' : 'waiting';

            $questionDate = $q['createdAt'] ?? null;
            if (is_string($questionDate)) {
                $ts = strtotime($questionDate);
                $questionDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
            } else {
                $questionDate = date('Y-m-d H:i:s');
            }

            return [
                'marketplace_id'          => $marketplaceId,
                'marketplace_question_id' => $qId,
                'product_name'            => $q['product'] ?? null,
                'marketplace_product_id'  => null,
                'product_image_url'       => null,
                'question_text'           => $q['question'] ?? '',
                'question_date'           => $questionDate,
                'customer_name'           => $q['customerName'] ?? null,
                'answer_text'             => $answerText,
                'answer_date'             => $hasAnswer && isset($q['readAt'])
                    ? date('Y-m-d H:i:s', strtotime($q['readAt'])) : null,
                'status'                  => $status,
                'is_public'               => 1,
                'extra_data_json'         => json_encode($q),
                'synced_at'               => date('Y-m-d H:i:s'),
            ];
        }

        // Pazarama
        $answerText = $q['answer'] ?? $q['answerText'] ?? null;
        $status = !empty($answerText) ? 'answered' : 'waiting';

        return [
            'marketplace_id'          => $marketplaceId,
            'marketplace_question_id' => $qId,
            'product_name'            => $q['productName'] ?? null,
            'marketplace_product_id'  => isset($q['productId']) ? (string) $q['productId'] : null,
            'product_image_url'       => $q['productImageUrl'] ?? null,
            'question_text'           => $q['question'] ?? $q['questionText'] ?? '',
            'question_date'           => $q['createdDate'] ?? $q['createDate'] ?? date('Y-m-d H:i:s'),
            'customer_name'           => $q['customerName'] ?? $q['customerFullName'] ?? null,
            'answer_text'             => $answerText,
            'answer_date'             => $q['answerDate'] ?? null,
            'status'                  => $status,
            'is_public'               => 1,
            'extra_data_json'         => json_encode($q),
            'synced_at'               => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Marketplace slug → service çözümle
     */
    private function resolveService(string $slug): ?object
    {
        return match ($slug) {
            'trendyol'    => $this->trendyolService,
            'n11'         => $this->n11Service,
            'hepsiburada' => $this->hepsiburadaService,
            'pazarama'    => $this->pazaramaService,
            'pttavm'      => $this->pttavmService,
            'ciceksepeti' => $this->ciceksepetiService,
            'idefix'      => $this->idefixService,
            'amazon'      => $this->amazonService,
            'oziygo'      => $this->oziygoService,
            'shopify'     => $this->shopifyService,
            'woocommerce' => $this->wooCommerceService,
            'opencart'    => $this->openCartService,
            default       => null,
        };
    }

    /**
     * Kategori ağacını düzleştir ve DB'ye kaydet
     */
    private function flattenAndSaveCategories(int $marketplaceId, array $categories, ?string $parentMpId = null, string $parentPath = ''): int
    {
        $count = 0;

        // Düz liste (HB vb.): subCategories yok VE ilk öğede dolu parentId var (eski koşul) ya da
        // HB'deki gibi kök öğede parentId null ama 'leaf' bilgisi de geliyor. Yalnız null parentId
        // taşıyan, leaf bilgisi olmayan ağaçlar eskisi gibi ağaç modunda kaydedilir.
        $first  = is_array($categories[0] ?? null) ? $categories[0] : [];
        $isFlat = !isset($first['subCategories'])
            && (isset($first['parentId'])
                || (array_key_exists('parentId', $first) && array_key_exists('leaf', $first)));

        if ($isFlat) {
            return $this->saveFlatCategories($marketplaceId, $categories);
        } else {
            // Ağaç yapısı (Trendyol vb.)
            foreach ($categories as $cat) {
                $mpCatId = (string) ($cat['id'] ?? $cat['categoryId'] ?? $cat['mp_category_id'] ?? '');
                $name    = $cat['name'] ?? '';
                if ($mpCatId === '' || $name === '') continue;

                $fullPath = $parentPath !== '' ? "{$parentPath} > {$name}" : $name;
                $isLeaf   = empty($cat['subCategories']);

                $this->mpCategoryModel->upsert($marketplaceId, $mpCatId, $name, $parentMpId, $fullPath, $isLeaf);
                $count++;

                // Alt kategoriler
                if (!empty($cat['subCategories'])) {
                    $count += $this->flattenAndSaveCategories($marketplaceId, $cat['subCategories'], $mpCatId, $fullPath);
                }
            }
        }

        return $count;
    }

    /**
     * Flat kategori listesi (Hepsiburada vb.) — paths dizisinden fullPath, status/available
     * satış durumu ile toplu kaydet. On binlerce satır tek tek sorguyla zaman aşımına düştüğü
     * için INSERT ... ON DUPLICATE KEY UPDATE parçaları kullanılır.
     *
     * Satış durumu kolonları (phase12/052) yoksa bulkUpsert onları yazmaz ve listede olmayan
     * kategorileri kapatma adımı atlanır.
     *
     * @param array<int, mixed> $categories
     */
    private function saveFlatCategories(int $marketplaceId, array $categories): int
    {
        $rows = [];
        $hasAvailability = false;

        // 'leaf' bilgisi gelmeyen öğede yaprak = başka bir öğenin parentId'si değil.
        $parentIds = [];
        foreach ($categories as $cat) {
            if (is_array($cat) && !empty($cat['parentId'])) {
                $parentIds[(string) $cat['parentId']] = true;
            }
        }

        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }

            $mpCatId = (string) ($cat['id'] ?? $cat['categoryId'] ?? $cat['mp_category_id'] ?? '');
            $name    = trim((string) ($cat['name'] ?? ''));
            if ($mpCatId === '' || $name === '') {
                continue;
            }

            $paths     = is_array($cat['paths'] ?? null) ? array_values(array_filter($cat['paths'], 'is_string')) : [];
            $status    = isset($cat['status']) && is_string($cat['status']) && trim($cat['status']) !== ''
                ? strtoupper(trim($cat['status']))
                : null;
            $available = array_key_exists('available', $cat) && $cat['available'] !== null ? (bool) $cat['available'] : null;
            if ($status !== null || $available !== null) {
                $hasAvailability = true;
            }

            $rows[] = [
                'mp_category_id' => $mpCatId,
                'name'           => $name,
                'parent_mp_id'   => !empty($cat['parentId']) ? (string) $cat['parentId'] : null,
                'full_path'      => $paths !== [] ? implode(' > ', $paths) : $name,
                'is_leaf'        => array_key_exists('leaf', $cat) ? (bool) $cat['leaf'] : !isset($parentIds[$mpCatId]),
                'is_active'      => $status === null ? null : $status === 'ACTIVE',
                'is_available'   => $available,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Oran yalnız AÇIK satırlara göre: önceden kapatılmış eski satırlar tam listeyi "eksik" göstermesin.
        $previousCount = $this->mpCategoryModel->countOpenByMarketplace($marketplaceId);
        // bulkUpsert synced_at'i bu andan sonra yazar; listede gelmeyenler bu zamandan eski kalır.
        $syncStartedAt = date('Y-m-d H:i:s');
        $saved = $this->mpCategoryModel->bulkUpsert($marketplaceId, $rows);

        // Tam liste geldiyse (önceki önbelleğin en az %90'ı) listede olmayanları satışa kapalı
        // işaretle. Kısmi/eksik yanıtta (ör. sayfalama bütçesi dolduysa) toplu kapatma yapılmaz.
        if ($hasAvailability && $this->categoryListComplete && $saved >= (int) floor($previousCount * 0.9)) {
            $closed = $this->mpCategoryModel->markUnavailableSyncedBefore($marketplaceId, $syncStartedAt);
            if ($closed > 0) {
                $this->logger->info(
                    "Kategori senkronu: listede olmayan {$closed} kategori satışa kapalı işaretlendi (mp={$marketplaceId})",
                    'InitialImport'
                );
            }
        }

        return $saved;
    }
}
