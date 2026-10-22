<?php
/**
 * OzyConnect Panel — SyncController
 *
 * Mağaza senkron ekranı (/sync): mağaza bazlı otomatik senkron anahtarları/aralıkları,
 * "otomatik stok yayını" anahtarı ve elle tetikleme. Ayarlar store_settings'e yazılır;
 * platform geneli (settings.sync_*) yalnız platform yöneticisinin alanıdır, buradan yazılmaz.
 *
 * HTTP cron uçları (/cron/sync-*?token=) platform cron anahtarıyla çalışır ve işi
 * JobRunner::runPeriodicForActiveStores üzerinden mağaza başına (TenantContext kurularak,
 * anahtar + aralık uygulanarak, hata izole) yürütür.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    2.1.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Models\SyncLog;
use App\Services\Import\InitialImportService;
use App\Services\Queue\JobRunner;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\PriceSyncService;
use App\Services\Sync\QuestionSyncService;
use App\Services\Sync\ReturnSyncService;
use App\Services\Sync\StockSyncService;

final class SyncController extends BaseController
{
    /** Mağaza ayarı: '0' → sipariş/iade/elle stok sonrası anlık stok yayını KAPALI; yoksa AÇIK. */
    public const AUTO_STOCK_BROADCAST_KEY = 'auto_stock_broadcast';

    /** Ürün içe aktarmada pazaryeri başına en fazla sayfa turu (sonsuz döngü koruması). */
    private const PRODUCT_IMPORT_MAX_ROUNDS = 500;

    private readonly SyncLog $syncLogModel;
    private readonly Marketplace $marketplaceModel;
    private readonly OrderSyncService $orderSync;
    private readonly StockSyncService $stockSync;
    private readonly PriceSyncService $priceSync;
    private readonly ReturnSyncService $returnSync;
    private readonly QuestionSyncService $questionSync;
    private readonly Setting $settings;
    private readonly StoreSetting $storeSettings;
    private readonly TenantContext $tenant;
    private readonly JobRunner $jobRunner;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->syncLogModel     = $container->get(SyncLog::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->orderSync        = $container->get(OrderSyncService::class);
        $this->stockSync        = $container->get(StockSyncService::class);
        $this->priceSync        = $container->get(PriceSyncService::class);
        $this->returnSync       = $container->get(ReturnSyncService::class);
        $this->questionSync     = $container->get(QuestionSyncService::class);
        $this->settings         = $container->get(Setting::class);
        $this->storeSettings    = $container->get(StoreSetting::class);
        $this->tenant           = $container->get(TenantContext::class);
        $this->jobRunner        = $container->get(JobRunner::class);
    }

    public function index(Request $request): void
    {
        $this->view('sync.index', ['pageTitle' => 'Senkronizasyon'] + $this->pageData());
    }

    /**
     * /sync görünüm verisi (ayarlar sayfasındaki gömülü "Senkronizasyon" bölümü de kullanır).
     * Cron anahtarı bilinçli olarak YOK: platform sırrıdır, mağazaya gösterilmez.
     *
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $storeId = $this->tenant->currentStoreId();

        $syncConfig = [];
        $platformDisabled = [];
        foreach ($this->jobRunner->syncSettingsForStore($storeId) as $type => $s) {
            $syncConfig["sync_{$type}_enabled"]  = $s['store_enabled'];
            $syncConfig["sync_{$type}_interval"] = $s['interval'];
            if (!$s['platform_enabled']) {
                $platformDisabled[$type] = true;
            }
        }

        return [
            'logs'               => $this->syncLogModel->getRecent(50),
            'marketplaces'       => $this->marketplaceModel->getActiveForStore($storeId),
            'lastSuccessful'     => $this->syncLogModel->getLastSuccessful(),
            'lastAutomatic'      => $this->syncLogModel->getLastSuccessful(null, true),
            'syncConfig'         => $syncConfig,
            'platformDisabled'   => $platformDisabled,
            'autoStockBroadcast' => $this->isAutoStockBroadcastEnabled(),
        ];
    }

    /**
     * Mağaza senkron ayarlarını kaydet (AJAX, JSON gövde).
     * Kabul edilen anahtarlar: sync_{tip}_enabled (bool), sync_{tip}_interval (dk),
     * auto_stock_broadcast (bool). Yalnız aktif mağazanın store_settings kaydı değişir.
     */
    public function saveSettings(Request $request): void
    {
        $data = $request->json();
        $changes = [];

        foreach (JobRunner::SYNC_TYPES as $type) {
            $enabledKey = "sync_{$type}_enabled";
            if (array_key_exists($enabledKey, $data)) {
                $flag = $this->parseFlag($data[$enabledKey]);
                if ($flag !== null) {
                    $changes[$enabledKey] = $flag ? '1' : '0';
                }
            }

            $intervalKey = "sync_{$type}_interval";
            if (array_key_exists($intervalKey, $data)) {
                $interval = filter_var($data[$intervalKey], FILTER_VALIDATE_INT);
                if ($interval !== false && in_array($interval, JobRunner::SYNC_INTERVALS, true)) {
                    $changes[$intervalKey] = (string) $interval;
                }
            }
        }

        if (array_key_exists(self::AUTO_STOCK_BROADCAST_KEY, $data)) {
            $flag = $this->parseFlag($data[self::AUTO_STOCK_BROADCAST_KEY]);
            if ($flag !== null) {
                $changes[self::AUTO_STOCK_BROADCAST_KEY] = $flag ? '1' : '0';
            }
        }

        if ($changes === []) {
            $this->error('Kaydedilecek geçerli bir ayar bulunamadı.', 422);
        }

        $this->storeSettings->setMany($changes);
        $this->logger->info(
            sprintf(
                'Senkron ayarı güncellendi store#%d user#%d: %s',
                $this->tenant->currentStoreId(),
                (int) ($this->session->userId() ?? 0),
                json_encode($changes, JSON_UNESCAPED_UNICODE)
            ),
            'Sync'
        );

        $message = 'Senkronizasyon ayarları güncellendi';
        if (isset($changes[self::AUTO_STOCK_BROADCAST_KEY])) {
            $message = $changes[self::AUTO_STOCK_BROADCAST_KEY] === '1'
                ? 'Otomatik stok yayını açıldı'
                : 'Otomatik stok yayını kapatıldı';
        }
        $this->success($changes, $message);
    }

    // ─── Manual Triggers ───────────────────────────────────

    public function triggerOrders(Request $request): void
    {
        $this->runManual($request, 'orders', fn(): array => $this->orderSync->syncAll('manual'));
    }

    public function triggerStock(Request $request): void
    {
        $this->runManual($request, 'stock', fn(): array => $this->stockSync->syncAll('manual'));
    }

    public function triggerPrice(Request $request): void
    {
        $this->runManual($request, 'price', fn(): array => $this->priceSync->syncAll('manual'));
    }

    public function triggerProducts(Request $request): void
    {
        $this->runManual($request, 'products', fn(): array => $this->importProductsForCurrentStore());
    }

    public function triggerReturns(Request $request): void
    {
        $this->runManual($request, 'returns', fn(): array => $this->returnSync->syncAll('manual'));
    }

    /** Sorular: QuestionSyncService ile çekilir VE yazılır (entitlement'li pazaryerleri). */
    public function triggerQuestions(Request $request): void
    {
        $this->runManual($request, 'questions', fn(): array => $this->questionSync->syncAll('manual'));
    }

    // ─── Cron Endpoints (platform cron anahtarı; mağaza başına) ──

    public function cronOrders(Request $request): void
    {
        $this->runCron($request, 'orders', fn(int $storeId): array => $this->orderSync->syncAll('cron'));
    }

    public function cronStock(Request $request): void
    {
        $this->runCron($request, 'stock', fn(int $storeId): array => $this->stockSync->syncAll('cron'));
    }

    public function cronPrice(Request $request): void
    {
        $this->runCron($request, 'price', fn(int $storeId): array => $this->priceSync->syncAll('cron'));
    }

    public function cronProducts(Request $request): void
    {
        // Zamanlanmış ürün senkronu yeni ürün OLUŞTURMAZ (delta: yalnız eşleşen ürünler güncellenir);
        // eşleşmeyen ilanlardan ürün yaratımı yalnız elle tetiklemede / ilk içe aktarma sihirbazında.
        $this->runCron($request, 'products', fn(int $storeId): array => $this->importProductsForCurrentStore('delta'));
    }

    public function cronReturns(Request $request): void
    {
        $this->runCron($request, 'returns', fn(int $storeId): array => $this->returnSync->syncAll('cron'));
    }

    public function cronQuestions(Request $request): void
    {
        $this->runCron($request, 'questions', fn(int $storeId): array => $this->questionSync->syncAll('cron'));
    }

    // ─── Helpers ───────────────────────────────────────────

    /**
     * Elle tetikleme: uzun sürebilir (timeout kaldırılır); AJAX'ta JSON, değilse yönlendirme.
     *
     * @param callable(): array<string|int, mixed> $action
     */
    private function runManual(Request $request, string $type, callable $action): void
    {
        $label = JobRunner::SYNC_LABELS[$type] ?? $type;
        set_time_limit(0);

        try {
            $results = $action();
        } catch (\Throwable $e) {
            $this->logger->error("{$label} sync hatası: " . $e->getMessage(), 'Sync');
            $message = "{$label} senkronizasyonu hatası: " . $e->getMessage();
            if ($request->isAjax()) {
                $this->error($message);
            }
            $this->redirectWithError('/sync', $message);
        }

        // Servisler pazaryeri hatasını yutup errors[] ile döner: "tamamlandı" denmez, hata gösterilir.
        $marketplaceErrors = JobRunner::marketplaceErrors($results);
        if ($marketplaceErrors !== []) {
            $message = "{$label} senkronizasyonunda pazaryeri hatası: " . implode('; ', $marketplaceErrors);
            $this->logger->warning($message, 'Sync');
            if ($request->isAjax()) {
                $this->json(['success' => false, 'message' => $message, 'data' => $this->jsonSafe($results)]);
            }
            $this->redirectWithError('/sync', $message);
        }

        $message = "{$label} senkronizasyonu tamamlandı";
        if ($request->isAjax()) {
            $this->success($this->jsonSafe($results), $message);
        }
        $this->redirectWithSuccess('/sync', $message);
    }

    /**
     * HTTP cron: anahtar doğrulanır, iş aktif her mağaza için kendi TenantContext'inde
     * çalışır (platform + mağaza anahtarı, mağaza aralığı, tip kilidi). JSON özet döner.
     *
     * @param callable(int $storeId): mixed $job
     */
    /**
     * HTTP cron: pazaryeri gönderim sonuçlarını (batch) çöz.
     *
     * Tipli senkron ayarlarına bağlanmaz (yeni ayar/sütun gerektirmesin diye);
     * doğrudan aktif mağazaları dolaşır. SSH'si olmayan kurulumlar bu ucu
     * zamanlanmış görev olarak çağırır: /cron/sync-publish-batches?token=...
     */
    public function cronPublishBatches(Request $request): void
    {
        $this->validateCronToken($request);

        set_time_limit(0);
        ignore_user_abort(true);

        $publish = $this->container->get(\App\Services\ProductPublishService::class);
        $runner  = $this->container->get(\App\Services\TenantCronRunner::class);

        $toplam = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
        $hatali = 0;

        try {
            $sonuclar = $runner->runForActiveStores(
                static fn(int $storeId): array => $publish->resolvePendingBatches(0, 500)
            );
        } catch (\Throwable $e) {
            $this->logger->error('HTTP cron publish_batch hatası: ' . $e->getMessage(), 'Cron');
            $this->json(['success' => false, 'type' => 'publish_batch', 'error' => $e->getMessage()], 500);
            return;
        }

        foreach ($sonuclar as $s) {
            if (!($s['ok'] ?? false)) {
                $hatali++;
                continue;
            }
            foreach (array_keys($toplam) as $k) {
                $toplam[$k] += (int) ($s['result'][$k] ?? 0);
            }
        }

        $this->json([
            'success'  => $hatali === 0,
            'type'     => 'publish_batch',
            'stores'   => count($sonuclar),
            'failed'   => $hatali,
            'checked'  => $toplam['checked'],
            'approved' => $toplam['approved'],
            'rejected' => $toplam['rejected'],
            'pending'  => $toplam['pending'],
            'time'     => date('Y-m-d H:i:s'),
        ]);
    }

    private function runCron(Request $request, string $type, callable $job): void
    {
        $this->validateCronToken($request);

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $summary = $this->jobRunner->runPeriodicForActiveStores($type, $job);
        } catch (\Throwable $e) {
            $this->logger->error("HTTP cron {$type} hatası: " . $e->getMessage(), 'Cron');
            $this->json(['success' => false, 'type' => $type, 'error' => $e->getMessage()], 500);
        }

        $this->json($this->jsonSafe([
            'success'  => $summary['failed'] === 0 && $summary['degraded'] === 0,
            'type'     => $type,
            'locked'   => $summary['locked'],
            'stores'   => count($summary['stores']),
            'ran'      => $summary['ran'],
            'skipped'  => $summary['skipped'],
            'failed'   => $summary['failed'],
            'degraded' => $summary['degraded'],
            'results' => $summary['stores'],
            'time'    => date('Y-m-d H:i:s'),
        ]));
    }

    /** Geçersiz/eksik anahtarda 403 JSON ile sonlanır. */
    private function validateCronToken(Request $request): void
    {
        $token = $request->query('token') ?? $request->server('HTTP_X_CRON_TOKEN') ?? '';
        $validToken = (string) $this->settings->get('cron_token', '');

        if ($validToken === '' || !is_string($token) || !hash_equals($validToken, $token)) {
            $this->json([
                'success' => false,
                'message' => 'Geçersiz cron anahtarı',
                'error'   => 'Invalid cron token',
            ], 403);
        }
    }

    /**
     * Aktif mağazanın yetkili pazaryerlerinden ürün içe aktarımı (sayfa sayfa).
     * $mode: 'full' (eşleşmeyen ilan yeni ürün olur — elle tetikleme) | 'delta' (yalnız eşleşenler — cron).
     *
     * @return array{marketplaces: array<string, array<string, mixed>>, totalCreated: int, totalUpdated: int, failed: int, errors: list<array{marketplace:string, error:string}>}
     */
    private function importProductsForCurrentStore(string $mode = 'full'): array
    {
        set_time_limit(0);
        $importer = $this->container->get(InitialImportService::class);
        $results = [];
        $errors = [];
        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($this->entitledActiveMarketplaces() as $mp) {
            $slug   = (string) ($mp['slug'] ?? '');
            $mpName = (string) ($mp['display_name'] ?? $slug);

            try {
                [$created, $updated] = $this->importMarketplaceProducts($importer, (int) $mp['id'], $slug, $mode);
                $totalCreated += $created;
                $totalUpdated += $updated;
                $results[$mpName] = ['created' => $created, 'updated' => $updated, 'total' => $created + $updated];
            } catch (\Throwable $e) {
                $this->logger->error("Product sync error ({$mpName}): " . $e->getMessage(), 'Sync');
                $results[$mpName] = ['error' => $e->getMessage()];
                $errors[] = ['marketplace' => $mpName, 'error' => $e->getMessage()];
            }
        }

        return [
            'marketplaces' => $results,
            'totalCreated' => $totalCreated,
            'totalUpdated' => $totalUpdated,
            'failed'       => count($errors),
            'errors'       => $errors,
        ];
    }

    /**
     * Bir pazaryerinin tüm ürün sayfalarını içe aktarır.
     * Sayfa değeri imleç olabileceği için (Shopify since_id) TUR sayısı sınırlanır.
     *
     * @return array{0:int, 1:int} [created, updated]
     */
    private function importMarketplaceProducts(InitialImportService $importer, int $mpId, string $slug, string $mode = 'full'): array
    {
        $created = 0;
        $updated = 0;
        $page = 0;

        for ($round = 0; $round < self::PRODUCT_IMPORT_MAX_ROUNDS; $round++) {
            $result = $importer->importProductPage($mpId, $slug, $page, 100, $mode);
            $created += (int) ($result['created'] ?? 0);
            $updated += (int) ($result['updated'] ?? 0);

            if (array_key_exists('done', $result)) {
                if (!empty($result['done'])) {
                    break;
                }
            } elseif (($result['pageSize'] ?? 0) === 0
                || ($result['page'] ?? 0) >= (($result['totalPages'] ?? 1) - 1)) {
                break;
            }

            $page = isset($result['nextPage']) && $result['nextPage'] !== null
                ? (int) $result['nextPage']
                : $page + 1;
        }

        return [$created, $updated];
    }

    /**
     * Mağazanın yetkisi (store_marketplaces) olan VE mağazada açık pazaryerleri.
     *
     * @return list<array<string, mixed>>
     */
    private function entitledActiveMarketplaces(): array
    {
        $rows = $this->marketplaceModel->getActiveForStore($this->tenant->currentStoreId());
        return array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
    }

    private function isAutoStockBroadcastEnabled(): bool
    {
        return $this->storeSettings->getValue(self::AUTO_STOCK_BROADCAST_KEY) !== '0';
    }

    private function parseFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Pazaryeri hata metinlerinde bozuk UTF-8 olabilir; Response::json JSON_THROW_ON_ERROR
     * kullandığı için yanıt öncesi temizlenir.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    private function jsonSafe(array $data): array
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $decoded = $encoded === false ? null : json_decode($encoded, true);
        return is_array($decoded) ? $decoded : [];
    }
}
