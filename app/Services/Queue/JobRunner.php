<?php
/**
 * OzyConnect Panel — JobRunner (Faz 6)
 *
 * Bir kuyruk işini ÇALIŞTIRIR. Tenant + entitlement güvenliğinin uygulandığı yer burası:
 *  1. Job'ın store_id'sinden TenantContext KURULUR (worker içinde).
 *  2. ENTITLEMENT doğrulanır: pazaryeri-özel job ise canAccessMarketplace; entitlement
 *     yoksa JobEntitlementException (TERMİNAL → dead, retry edilmez). Per-store job'larda
 *     iç syncAll zaten getActiveForStore ile entitlement-filtreli → revoke edilmiş
 *     pazaryeri senkronize EDİLMEZ.
 *  3. job_type'a göre ilgili servise DISPATCH edilir (Container'dan tembel çözümlenir).
 *  4. finally → TenantContext TEMİZLENİR (uzun ömürlü worker'da tenant SIZINTISI olmaz).
 *
 * PERİYODİK SENKRON POLİTİKASI (kuyruk, legacy cron ve HTTP cron ortak kaynağı):
 *  - Bir senkron tipi bir mağazada ancak platform anahtarı (settings.sync_{tip}_enabled)
 *    VE mağaza anahtarı (store_settings.sync_{tip}_enabled; yoksa AÇIK) birlikte açıksa çalışır.
 *  - Aralık: mağaza ayarı → platform ayarı → varsayılan. Son tamamlanan koşudan
 *    (sync_jobs 'done' / sync_logs success|partial) bu yana aralık dolmadıysa atlanır.
 *  - Ayarlar her çağrıda DB'den okunur (static önbellek yok) → uzun ömürlü worker'da da güncel.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\EntitlementService;
use App\Services\TenantCronRunner;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\ReturnSyncService;
use App\Services\Sync\StockSyncService;
use App\Services\Sync\PriceSyncService;
use App\Services\Sync\QuestionSyncService;
use App\Services\Import\ProductImportService;

final class JobRunner
{
    /** Ayarlanabilir senkron tipleri (sync_{tip}_enabled / sync_{tip}_interval). */
    public const SYNC_TYPES = ['orders', 'returns', 'questions', 'stock', 'price', 'products'];

    /** İzin verilen çalışma aralıkları (dakika). */
    public const SYNC_INTERVALS = [1, 2, 5, 10, 15, 30, 60, 120, 360];

    public const DEFAULT_SYNC_INTERVAL = 15;

    /** Kuyrukta periyodik üretilen job_type → senkron tipi. */
    public const PERIODIC_JOB_TYPES = [
        'order_sync'    => 'orders',
        'return_sync'   => 'returns',
        'stock_sync'    => 'stock',
        'price_sync'    => 'price',
        'question_sync' => 'questions',
    ];

    public const SYNC_LABELS = [
        'orders'    => 'Sipariş',
        'returns'   => 'İade',
        'questions' => 'Soru',
        'stock'     => 'Stok',
        'price'     => 'Fiyat',
        'products'  => 'Ürün',
    ];

    /** Senkron tipi → sync_logs.sync_type eş adları (eski kayıtlar dahil). */
    private const SYNC_LOG_TYPES = [
        'orders'    => ['orders', 'orders_pull'],
        'returns'   => ['returns', 'returns_pull'],
        'questions' => ['questions', 'questions_pull'],
        'stock'     => ['stock', 'stock_push'],
        'price'     => ['price', 'prices', 'price_push'],
        'products'  => ['products', 'products_pull'],
    ];

    /**
     * Çekme senkronu tipi → mağazanın paket modülü (store_modules). Modülü olmayan mağazada bu
     * verinin ekranı da kapalıdır (RBAC modül kapısı) → arka planda çekilmez. Stok/fiyat/ürün
     * GÖNDERİM senkronları bilinçli olarak modüle bağlanmaz (pazaryeri ilanı güncel kalmalı).
     */
    private const SYNC_MODULES = [
        'orders'    => 'orders',
        'returns'   => 'returns',
        'questions' => 'questions',
    ];

    /** Cron tik kaymasını tolere eden pay: aralık bundan kısa sürede dolmuş sayılır. */
    private const SCHEDULE_GRACE_SECONDS = 60;

    /** Son koşu araması için geriye bakış (en uzun aralık 6 saat). */
    private const LAST_RUN_LOOKBACK_SECONDS = 86400;

    /** @var array<string, callable(int,array):mixed> Override edilmiş handler'lar (test/özel). */
    private array $handlers = [];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlement,
        private readonly Container $container,
    ) {}

    /**
     * Bir job_type için handler'ı değiştir (test/özel kullanım).
     * @param callable(int $storeId, array $job):mixed $handler
     */
    public function registerHandler(string $jobType, callable $handler): void
    {
        $this->handlers[$jobType] = $handler;
    }

    /**
     * Job'ı tenant + entitlement güvenliğiyle çalıştırır.
     *
     * @param array<string,mixed> $job  sync_jobs satırı
     * @return mixed handler dönüşü
     * @throws JobEntitlementException pazaryeri entitlement yoksa (terminal)
     * @throws \Throwable handler hatası (worker retry/backoff uygular)
     */
    public function run(array $job): mixed
    {
        $storeId = (int) ($job['store_id'] ?? 0);
        if ($storeId <= 0) {
            // FAIL-CLOSED: store_id taşımayan job çalıştırılmaz (tenant bağlamı kurulamaz).
            throw new \InvalidArgumentException('Job store_id taşımıyor — tenant bağlamı kurulamaz');
        }
        $jobType       = (string) ($job['job_type'] ?? '');
        $marketplaceId = isset($job['marketplace_id']) && $job['marketplace_id'] !== null
            ? (int) $job['marketplace_id'] : null;

        $this->tenant->setStoreId($storeId);   // ← worker'ın bu işe ait tenant bağlamı
        try {
            // Pazaryeri-özel job → entitlement ZORUNLU. Revoke edilmişse TERMİNAL hata.
            if ($marketplaceId !== null && !$this->entitlement->canAccessMarketplace($marketplaceId)) {
                throw new JobEntitlementException(
                    "store#{$storeId} için pazaryeri#{$marketplaceId} entitlement YOK — job çalıştırılmadı"
                );
            }

            // Kuyruğa alındıktan sonra anahtar kapatıldıysa / modül geri alındıysa periyodik iş çalıştırılmaz.
            $syncType = self::PERIODIC_JOB_TYPES[$jobType] ?? null;
            if ($syncType !== null) {
                $settings = $this->syncSettingsForStore($storeId)[$syncType];
                if (!$settings['enabled']) {
                    return [
                        'skipped' => true,
                        'reason'  => $settings['platform_enabled'] ? 'store_disabled' : 'platform_disabled',
                    ];
                }
                if (!$this->storeHasSyncModule($storeId, $syncType)) {
                    return ['skipped' => true, 'reason' => 'module'];
                }
            }

            return $this->handlerFor($jobType)($storeId, $job);
        } finally {
            $this->tenant->clear();             // ← ZORUNLU: tenant sızıntısı önleme
        }
    }

    /**
     * Mağazanın etkin senkron ayarları. Tenant gerektirmez (store_id açıkça taşınır).
     *
     * @return array<string, array{enabled:bool, platform_enabled:bool, store_enabled:bool, interval:int}>
     */
    public function syncSettingsForStore(int $storeId): array
    {
        $keys = [];
        foreach (self::SYNC_TYPES as $type) {
            $keys[] = "sync_{$type}_enabled";
            $keys[] = "sync_{$type}_interval";
        }

        $global = $this->keyValues('SELECT `key` AS k, `value` AS v FROM settings WHERE `key` IN (%s)', $keys);
        $store  = $this->keyValues(
            'SELECT skey AS k, svalue AS v FROM store_settings WHERE store_id = :sid AND skey IN (%s)',
            $keys,
            ['sid' => $storeId]
        );

        $out = [];
        foreach (self::SYNC_TYPES as $type) {
            $platformEnabled = self::flag($global["sync_{$type}_enabled"] ?? null, true);
            // Mağaza anahtarı yoksa platform değeri gösterilir; etkin karar ikisinin VE'sidir.
            $storeEnabled = self::flag($store["sync_{$type}_enabled"] ?? null, $platformEnabled);

            $out[$type] = [
                'enabled'          => $platformEnabled && $storeEnabled,
                'platform_enabled' => $platformEnabled,
                'store_enabled'    => $storeEnabled,
                'interval'         => self::interval($store["sync_{$type}_interval"] ?? null)
                    ?? self::interval($global["sync_{$type}_interval"] ?? null)
                    ?? self::DEFAULT_SYNC_INTERVAL,
            ];
        }
        return $out;
    }

    /**
     * Mağaza için senkron tiplerinin şimdi çalışıp çalışmayacağı.
     * reason: due | platform_disabled | store_disabled | module | interval
     *
     * @param list<string>|null $types  null → tüm tipler
     * @return array<string, array{due:bool, reason:string, interval:int, next_in:?int}>
     */
    public function periodicSyncPlan(int $storeId, ?array $types = null): array
    {
        $settings = $this->syncSettingsForStore($storeId);
        $elapsed  = $this->secondsSinceLastRun($storeId);
        $modules  = $this->storeModuleKeys($storeId);

        $plan = [];
        foreach ($types ?? self::SYNC_TYPES as $type) {
            if (!isset($settings[$type])) {
                throw new \InvalidArgumentException("Bilinmeyen senkron tipi: {$type}");
            }
            $s = $settings[$type];
            $decision = ['due' => false, 'reason' => 'due', 'interval' => $s['interval'], 'next_in' => null];
            $module = self::SYNC_MODULES[$type] ?? null;

            if (!$s['platform_enabled']) {
                $decision['reason'] = 'platform_disabled';
            } elseif (!$s['store_enabled']) {
                $decision['reason'] = 'store_disabled';
            } elseif ($module !== null && !in_array($module, $modules, true)) {
                $decision['reason'] = 'module';
            } else {
                $wait  = $s['interval'] * 60 - self::SCHEDULE_GRACE_SECONDS;
                $since = $elapsed[$type] ?? null;
                if ($since !== null && $since < $wait) {
                    $decision['reason']  = 'interval';
                    $decision['next_in'] = $wait - $since;
                } else {
                    $decision['due'] = true;
                }
            }
            $plan[$type] = $decision;
        }
        return $plan;
    }

    /**
     * Legacy cron / HTTP cron: $job'ı aktif her mağaza için kendi TenantContext'inde çalıştırır.
     * Mağaza anahtarı/aralığı uygulanır; bir mağazanın hatası diğerlerini durdurmaz.
     * Aynı tip için eşzamanlı ikinci koşu kilitle engellenir.
     *
     * degraded: senkronu çalışan ama pazaryeri düzeyinde hata bildiren mağaza sayısı
     * (servis hatayı yutup errors[] ile döndüğünde: OrderSync/ReturnSync/QuestionSync/Stock/Price).
     *
     * @param callable(int $storeId):mixed $job
     * @return array{type:string, locked:bool, ran:int, skipped:int, failed:int, degraded:int,
     *               stores: array<int, array{status:string, reason?:string, next_in?:?int, result?:mixed, error?:string, marketplace_errors?:list<string>}>}
     */
    public function runPeriodicForActiveStores(string $syncType, callable $job, bool $ignoreInterval = false): array
    {
        if (!in_array($syncType, self::SYNC_TYPES, true)) {
            throw new \InvalidArgumentException("Bilinmeyen senkron tipi: {$syncType}");
        }

        $summary = ['type' => $syncType, 'locked' => false, 'ran' => 0, 'skipped' => 0, 'failed' => 0, 'degraded' => 0, 'stores' => []];

        $lock = $this->openLock($syncType);
        if ($lock !== null && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $summary['locked'] = true;
            return $summary;
        }

        try {
            $raw = $this->container->get(TenantCronRunner::class)->runForActiveStores(
                function (int $storeId) use ($syncType, $job, $ignoreInterval): array {
                    $decision = $this->periodicSyncPlan($storeId, [$syncType])[$syncType];
                    $blocked  = !$decision['due'] && !($ignoreInterval && $decision['reason'] === 'interval');
                    if ($blocked) {
                        return ['status' => 'skipped', 'reason' => $decision['reason'], 'next_in' => $decision['next_in']];
                    }
                    return ['status' => 'ran', 'result' => $job($storeId)];
                }
            );
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $logger = $this->container->get(Logger::class);
        foreach ($raw as $storeId => $entry) {
            if (!($entry['ok'] ?? false)) {
                $error = (string) ($entry['error'] ?? 'Bilinmeyen hata');
                $summary['stores'][$storeId] = ['status' => 'failed', 'error' => $error];
                $summary['failed']++;
                $logger->error("Cron {$syncType} sync store#{$storeId}: {$error}", 'Cron');
                continue;
            }
            $result = $entry['result'];
            if ($result['status'] === 'ran') {
                $summary['ran']++;
                $mpErrors = self::marketplaceErrors($result['result'] ?? null);
                if ($mpErrors !== []) {
                    $result['marketplace_errors'] = $mpErrors;
                    $summary['degraded']++;
                    $logger->warning("Cron {$syncType} sync store#{$storeId} pazaryeri hatası: " . implode('; ', $mpErrors), 'Cron');
                }
            } else {
                $summary['skipped']++;
            }
            $summary['stores'][$storeId] = $result;
        }
        return $summary;
    }

    /**
     * Senkron sonucundaki PAZARYERİ düzeyi hatalar ("slug: mesaj").
     * Düz biçim (sipariş/iade/soru): errors[] içinde 'marketplace' anahtarlı kayıtlar.
     * Pazaryeri bazlı biçim (stok/fiyat): {slug: {sent, failed, errors[]}} — hiç gönderim yapılamamış
     * ve hata bildiren pazaryeri. Kayıt düzeyi tekil hatalar (sipariş satırı vb.) sayılmaz.
     *
     * @return list<string>
     */
    public static function marketplaceErrors(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        $out = [];
        foreach ((array) ($result['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['marketplace'])) {
                $out[] = (string) $error['marketplace'] . ': ' . self::errorText($error);
            }
        }

        foreach ($result as $slug => $entry) {
            if (!is_string($slug) || !is_array($entry) || !array_key_exists('sent', $entry)) {
                continue;
            }
            $errors = is_array($entry['errors'] ?? null) ? $entry['errors'] : [];
            if ((int) ($entry['failed'] ?? 0) > 0 && (int) ($entry['sent'] ?? 0) === 0 && $errors !== []) {
                $first = reset($errors);
                $out[] = $slug . ': ' . (is_array($first) ? self::errorText($first) : (string) $first);
            }
        }

        return $out;
    }

    /**
     * Legacy cron çıkış kodu: hiçbir mağaza çalışamadıysa (hepsi hata) ya da çalışan her mağaza
     * pazaryeri hatası bildirdiyse 1 — izleme toplu kesintiyi görebilsin.
     *
     * @param array<string,mixed> $summary
     */
    public static function exitCode(array $summary): int
    {
        $ran      = (int) ($summary['ran'] ?? 0);
        $failed   = (int) ($summary['failed'] ?? 0);
        $degraded = (int) ($summary['degraded'] ?? 0);

        if ($ran === 0) {
            return $failed > 0 ? 1 : 0;
        }
        return $degraded >= $ran ? 1 : 0;
    }

    /** @param array<string,mixed> $error */
    private static function errorText(array $error): string
    {
        $text = trim((string) ($error['error'] ?? $error['message'] ?? ''));
        return $text === '' ? 'bilinmeyen hata' : mb_substr($text, 0, 300);
    }

    /**
     * runPeriodicForActiveStores özetini CLI satırlarına çevirir.
     *
     * @param array<string,mixed> $summary
     * @return list<string>
     */
    public static function summaryLines(array $summary): array
    {
        $label = self::SYNC_LABELS[$summary['type'] ?? ''] ?? (string) ($summary['type'] ?? '');
        if (!empty($summary['locked'])) {
            return ["[SKIP] {$label} sync: önceki koşu hâlâ sürüyor (kilit)."];
        }

        $lines = [];
        foreach ($summary['stores'] ?? [] as $storeId => $entry) {
            $mpErrors = (array) ($entry['marketplace_errors'] ?? []);
            $lines[] = match ($entry['status'] ?? '') {
                'ran'     => "  store#{$storeId}: " . self::describeResult($entry['result'] ?? null)
                    . ($mpErrors !== [] ? ' [PAZARYERİ HATASI] ' . implode('; ', $mpErrors) : ''),
                'skipped' => "  store#{$storeId}: [ATLANDI] " . self::reasonText((string) ($entry['reason'] ?? ''), $entry['next_in'] ?? null),
                default   => "  store#{$storeId}: [HATA] " . ($entry['error'] ?? '?'),
            };
        }
        $failed   = (int) ($summary['failed'] ?? 0);
        $degraded = (int) ($summary['degraded'] ?? 0);
        $lines[] = sprintf(
            '[%s] %s sync: %d çalıştı, %d atlandı, %d hata, %d mağazada pazaryeri hatası (%d mağaza).',
            $failed === 0 && $degraded === 0 ? 'OK' : 'UYARI',
            $label,
            (int) ($summary['ran'] ?? 0),
            (int) ($summary['skipped'] ?? 0),
            $failed,
            $degraded,
            count($summary['stores'] ?? [])
        );
        return $lines;
    }

    /** Atlama nedeni (Türkçe). */
    public static function reasonText(string $reason, ?int $nextIn = null): string
    {
        return match ($reason) {
            'platform_disabled' => 'platform genelinde kapalı',
            'store_disabled'    => 'mağaza ayarında kapalı',
            'module'            => 'mağaza paketinde bu modül yok',
            'interval'          => 'aralık dolmadı' . ($nextIn !== null ? ' (' . (int) ceil($nextIn / 60) . ' dk sonra)' : ''),
            default             => $reason,
        };
    }

    /** Senkron servis dönüşünü (toplu ya da pazaryeri bazlı) tek satırlık özete çevirir. */
    public static function describeResult(mixed $result, int $depth = 0): string
    {
        if ($result === null || $result === []) {
            return '-';
        }
        if (is_bool($result)) {
            return $result ? 'evet' : 'hayır';
        }
        if (is_scalar($result)) {
            return (string) $result;
        }
        if (!is_array($result)) {
            return get_debug_type($result);
        }

        $counts = [];
        $nested = [];
        foreach ($result as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $counts[] = "{$key}={$value}";
            } elseif ($key === 'errors' && is_array($value)) {
                if ($value !== []) {
                    $counts[] = 'errors=' . count($value);
                }
            } elseif ($key === 'message' && is_string($value)) {
                $counts[] = $value;
            } elseif (is_array($value) && $value !== [] && $depth === 0) {
                $nested[] = "{$key}: " . self::describeResult($value, $depth + 1);
            }
        }

        $parts = array_filter([implode(', ', $counts), implode('; ', $nested)], static fn(string $p): bool => $p !== '');
        return $parts === [] ? '-' : implode('; ', $parts);
    }

    /**
     * job_type → handler. Önce override, yoksa varsayılan (Container'dan servis çözer).
     * triggered_by olarak 'cron' geçilir: sync_logs.triggered_by enum'u (cron|manual|api|
     * initial_import) 'queue' kabul etmez; strict modda log kaydı ve senkron düşerdi.
     *
     * @return callable(int,array):mixed
     */
    private function handlerFor(string $jobType): callable
    {
        if (isset($this->handlers[$jobType])) {
            return $this->handlers[$jobType];
        }

        return match ($jobType) {
            'order_sync'    => fn(int $s, array $j) => $this->container->get(OrderSyncService::class)->syncAll('cron'),
            'return_sync'   => fn(int $s, array $j) => $this->container->get(ReturnSyncService::class)->syncAll('cron'),
            'stock_sync'    => fn(int $s, array $j) => $this->container->get(StockSyncService::class)->syncAll('cron', isset($j['marketplace_id']) ? (int)$j['marketplace_id'] : null),
            'price_sync'    => fn(int $s, array $j) => $this->container->get(PriceSyncService::class)->syncAll('cron'),
            'question_sync' => fn(int $s, array $j) => $this->container->get(QuestionSyncService::class)->syncAll('cron'),
            'import'        => fn(int $s, array $j) => $this->runImport($j),
            'saas_invoice_earsiv' => fn(int $s, array $j) => $this->container->get(\App\Services\SubscriptionInvoiceService::class)
                ->issue((int) ((json_decode((string) ($j['payload_json'] ?? '{}'), true) ?: [])['invoice_id'] ?? 0)),
            default         => throw new \InvalidArgumentException("Bilinmeyen job_type: {$jobType}"),
        };
    }

    /** Import job'ı: payload'tan marketplace slug/mode okunur (marketplace_id zorunlu). */
    private function runImport(array $job): array
    {
        $mpId = isset($job['marketplace_id']) ? (int) $job['marketplace_id'] : 0;
        if ($mpId <= 0) {
            throw new \InvalidArgumentException('import job marketplace_id taşımalı');
        }
        $payload = [];
        if (!empty($job['payload_json'])) {
            $decoded = json_decode((string) $job['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $slug = (string) ($payload['slug'] ?? '');
        $mode = (string) ($payload['mode'] ?? 'full');
        if ($slug === '') {
            throw new \InvalidArgumentException('import job payload.slug zorunlu');
        }
        return $this->container->get(ProductImportService::class)
            ->importFromMarketplace($mpId, $slug, $mode)
            ->toArray();
    }

    /**
     * Senkron tipi → son tamamlanan koşudan bu yana geçen saniye.
     * sync_jobs zamanları DB saatiyle (NOW), sync_logs zamanları PHP saatiyle yazılır;
     * her biri kendi saatiyle karşılaştırılır.
     *
     * @return array<string,int>
     */
    private function secondsSinceLastRun(int $storeId): array
    {
        $db  = $this->container->get(Database::class);
        $out = [];

        $jobRows = $db->fetchAll(
            "SELECT job_type, TIMESTAMPDIFF(SECOND, MAX(COALESCE(started_at, created_at)), NOW()) AS ago
               FROM sync_jobs
              WHERE store_id = :sid AND status = 'done'
                AND created_at >= NOW() - INTERVAL " . self::LAST_RUN_LOOKBACK_SECONDS . " SECOND
              GROUP BY job_type",
            ['sid' => $storeId]
        );
        foreach ($jobRows as $row) {
            $type = self::PERIODIC_JOB_TYPES[(string) $row['job_type']] ?? null;
            if ($type !== null && $row['ago'] !== null) {
                $out[$type] = min($out[$type] ?? PHP_INT_MAX, max(0, (int) $row['ago']));
            }
        }

        $logRows = $db->fetchAll(
            "SELECT sync_type, MAX(started_at) AS last_at
               FROM sync_logs
              WHERE store_id = :sid AND status IN ('success', 'partial') AND started_at >= :since
              GROUP BY sync_type",
            ['sid' => $storeId, 'since' => date('Y-m-d H:i:s', time() - self::LAST_RUN_LOOKBACK_SECONDS)]
        );
        foreach ($logRows as $row) {
            $type = self::syncTypeForLog((string) $row['sync_type']);
            $at   = strtotime((string) $row['last_at']);
            if ($type !== null && $at !== false) {
                $out[$type] = min($out[$type] ?? PHP_INT_MAX, max(0, time() - $at));
            }
        }
        return $out;
    }

    /**
     * Mağazanın etkin (active + süresi geçmemiş) paket modülleri. Tenant gerektirmez.
     *
     * @return list<string>
     */
    private function storeModuleKeys(int $storeId): array
    {
        $rows = $this->container->get(Database::class)->fetchAll(
            "SELECT module_key FROM store_modules
              WHERE store_id = :sid AND status = 'active'
                AND (expires_at IS NULL OR expires_at > NOW())",
            ['sid' => $storeId]
        );
        return array_map(static fn(array $r): string => (string) $r['module_key'], $rows);
    }

    private function storeHasSyncModule(int $storeId, string $syncType): bool
    {
        $module = self::SYNC_MODULES[$syncType] ?? null;
        return $module === null || in_array($module, $this->storeModuleKeys($storeId), true);
    }

    private static function syncTypeForLog(string $logType): ?string
    {
        foreach (self::SYNC_LOG_TYPES as $type => $aliases) {
            if (in_array($logType, $aliases, true)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * "k IN (%s)" sorgusunu benzersiz yer tutucularla çalıştırır (EMULATE_PREPARES=false).
     *
     * @param list<string> $keys
     * @param array<string,mixed> $params
     * @return array<string,?string>
     */
    private function keyValues(string $sqlTemplate, array $keys, array $params = []): array
    {
        $placeholders = [];
        foreach (array_values($keys) as $i => $key) {
            $placeholders[] = ":k{$i}";
            $params["k{$i}"] = $key;
        }
        $rows = $this->container->get(Database::class)
            ->fetchAll(sprintf($sqlTemplate, implode(', ', $placeholders)), $params);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['k']] = $row['v'] === null ? null : (string) $row['v'];
        }
        return $out;
    }

    private static function flag(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
    }

    private static function interval(?string $value): ?int
    {
        if ($value === null || !ctype_digit(trim($value))) {
            return null;
        }
        $minutes = (int) trim($value);
        return in_array($minutes, self::SYNC_INTERVALS, true) ? $minutes : null;
    }

    /**
     * Senkron tipi başına kilit dosyası. Açılamazsa null (kilitsiz devam edilir).
     *
     * @return resource|null
     */
    private function openLock(string $syncType)
    {
        try {
            $dir = rtrim((string) $this->container->config('paths.cache', sys_get_temp_dir()), '/\\') . '/locks';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $handle = fopen($dir . '/sync-' . $syncType . '.lock', 'c');
            return $handle === false ? null : $handle;
        } catch (\Throwable $e) {
            $this->container->get(Logger::class)->warning("Cron kilidi açılamadı ({$syncType}): " . $e->getMessage(), 'Cron');
            return null;
        }
    }
}
