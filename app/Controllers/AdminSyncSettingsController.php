<?php
/**
 * OzyConnect Panel — AdminSyncSettingsController
 *
 * Platform geneli otomatik senkron ayarları (settings group='sync': sync_{tip}_enabled /
 * sync_{tip}_interval) ve HTTP cron anahtarı (cron_token).
 *
 * - Senkron bir mağazada ancak platform anahtarı VE mağaza anahtarı (store_settings, /sync ekranı)
 *   birlikte açıksa çalışır (JobRunner). Mağazalar platform anahtarını değiştiremez; bu ekran tek yer.
 * - Platform aralığı, mağaza kendi aralığını seçmemişse kullanılan varsayılandır.
 * - Cron anahtarı mağazalara gösterilmez; yalnız burada görülür / yenilenir.
 *
 * Pattern A: AdminSession self-guard, AdminAuthMiddleware arkasında (AdminMarketingController deseni).
 * settings.updated_by = null (admin id'si users tablosunda yok → FK ihlali olmasın).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Setting;
use App\Services\Queue\JobRunner;

final class AdminSyncSettingsController
{
    /** Senkron tipi → HTTP cron ucu (app/routes.php). */
    private const CRON_PATHS = [
        'orders'    => '/cron/sync-orders',
        'returns'   => '/cron/sync-returns',
        'questions' => '/cron/sync-questions',
        'stock'     => '/cron/sync-stock',
        'price'     => '/cron/sync-price',
        'products'  => '/cron/sync-products',
    ];

    /** Senkron tipi → ekranda kısa açıklama. */
    private const TYPE_NOTES = [
        'orders'    => 'Pazaryerlerinden yeni siparişleri çeker.',
        'returns'   => 'İade taleplerini çeker.',
        'questions' => 'Müşteri sorularını çeker.',
        'stock'     => 'Değişen stokları pazaryerlerine gönderir.',
        'price'     => 'Değişen fiyatları pazaryerlerine gönderir.',
        'products'  => 'Pazaryeri ilanlarını eşleşen ürünlere işler (zamanlanmış koşu yeni ürün oluşturmaz).',
    ];

    private const FALSY = ['', '0', 'false', 'off', 'no'];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Setting $settingModel;
    private readonly Database $db;
    private readonly Logger $logger;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->settingModel = $container->get(Setting::class);
        $this->db           = $container->get(Database::class);
        $this->logger       = $container->get(Logger::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    public function index(Request $request): void
    {
        $values   = $this->platformValues();
        $storeOff = $this->storesDisabledCounts();

        $rows = [];
        foreach (JobRunner::SYNC_TYPES as $type) {
            $interval = (int) ($values["sync_{$type}_interval"] ?? 0);
            $rows[] = [
                'type'      => $type,
                'label'     => JobRunner::SYNC_LABELS[$type] ?? $type,
                'note'      => self::TYPE_NOTES[$type] ?? '',
                'enabled'   => self::flag($values["sync_{$type}_enabled"] ?? null),
                'interval'  => in_array($interval, JobRunner::SYNC_INTERVALS, true) ? $interval : JobRunner::DEFAULT_SYNC_INTERVAL,
                'store_off' => (int) ($storeOff["sync_{$type}_enabled"] ?? 0),
                'cron_path' => self::CRON_PATHS[$type] ?? '',
            ];
        }

        $this->render('admin.sync_settings', [
            'pageTitle'   => 'Senkron Ayarları',
            'rows'        => $rows,
            'intervals'   => JobRunner::SYNC_INTERVALS,
            'cronToken'   => (string) ($values['cron_token'] ?? ''),
            'baseUrl'     => function_exists('app_base_url') ? rtrim((string) app_base_url(), '/') : '',
            'activeStores' => (int) ($this->db->fetchOne("SELECT COUNT(*) AS c FROM stores WHERE status = 'active'")['c'] ?? 0),
        ]);
    }

    public function save(Request $request): void
    {
        $in = $request->post('s', []);
        $in = is_array($in) ? $in : [];

        $changes = [];
        foreach (JobRunner::SYNC_TYPES as $type) {
            $label = JobRunner::SYNC_LABELS[$type] ?? $type;

            $enabledKey = "sync_{$type}_enabled";
            $enabled = array_key_exists($enabledKey, $in) && !in_array(strtolower(trim((string) $in[$enabledKey])), self::FALSY, true);
            $this->settingModel->set($enabledKey, $enabled ? '1' : '0', null, 'bool', 'sync', "{$label} senkronizasyonu");
            $changes[$enabledKey] = $enabled ? '1' : '0';

            $intervalKey = "sync_{$type}_interval";
            $interval = filter_var($in[$intervalKey] ?? null, FILTER_VALIDATE_INT);
            if ($interval !== false && in_array($interval, JobRunner::SYNC_INTERVALS, true)) {
                $this->settingModel->set($intervalKey, (string) $interval, null, 'int', 'sync', "{$label} sıklığı (dk)");
                $changes[$intervalKey] = (string) $interval;
            }
        }
        Setting::clearCache();

        $this->audit('platform.sync_settings.update', 'settings:sync', $changes);
        $this->session->flash('success', 'Platform senkron ayarları kaydedildi.');
        $this->response->redirectTo('/admin/sync-settings');
    }

    /** Yeni HTTP cron anahtarı üretir (eski anahtarla çağrılar 403 almaya başlar). */
    public function regenerateToken(Request $request): void
    {
        $this->settingModel->set('cron_token', bin2hex(random_bytes(16)), null, 'string', 'general', 'cron_token');
        Setting::clearCache();

        $this->audit('platform.cron_token.regenerate', 'settings:cron_token', []);
        $this->session->flash('success', 'Yeni cron anahtarı oluşturuldu. Sunucudaki cron komutlarını yeni anahtarla güncelleyin.');
        $this->response->redirectTo('/admin/sync-settings');
    }

    /**
     * settings tablosundaki ham değerler (Setting::get tip dönüştürmesi olmadan; JobRunner ile aynı okuma).
     *
     * @return array<string, ?string>
     */
    private function platformValues(): array
    {
        $keys = ['cron_token'];
        foreach (JobRunner::SYNC_TYPES as $type) {
            $keys[] = "sync_{$type}_enabled";
            $keys[] = "sync_{$type}_interval";
        }

        [$in, $params] = self::inClause($keys);
        $out = [];
        foreach ($this->db->fetchAll("SELECT `key` AS k, `value` AS v FROM settings WHERE `key` IN ({$in})", $params) as $row) {
            $out[(string) $row['k']] = $row['v'] === null ? null : (string) $row['v'];
        }
        return $out;
    }

    /**
     * Tipi kendi /sync ekranında kapatmış mağaza sayısı (bilgi amaçlı).
     *
     * @return array<string, int>
     */
    private function storesDisabledCounts(): array
    {
        $keys = array_map(static fn(string $t): string => "sync_{$t}_enabled", JobRunner::SYNC_TYPES);
        [$in, $params] = self::inClause($keys);

        $out = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT ss.skey AS k, COUNT(*) AS c
                   FROM store_settings ss
                   JOIN stores s ON s.id = ss.store_id AND s.status = 'active'
                  WHERE ss.skey IN ({$in}) AND LOWER(TRIM(COALESCE(ss.svalue, ''))) IN ('', '0', 'false', 'off', 'no')
                  GROUP BY ss.skey",
                $params
            );
            foreach ($rows as $row) {
                $out[(string) $row['k']] = (int) $row['c'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Mağaza senkron anahtarları okunamadı: ' . $e->getMessage(), 'AdminSync');
        }
        return $out;
    }

    /**
     * Benzersiz adlı yer tutucularla IN listesi (EMULATE_PREPARES=false: aynı ad iki kez kullanılamaz).
     *
     * @param list<string> $values
     * @return array{0: string, 1: array<string, string>}
     */
    private static function inClause(array $values): array
    {
        $names = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $names[] = ":k{$i}";
            $params["k{$i}"] = $value;
        }
        return [implode(', ', $names), $params];
    }

    /** Değer yoksa AÇIK (JobRunner ile aynı varsayılan). */
    private static function flag(?string $value): bool
    {
        return $value === null || !in_array(strtolower(trim($value)), self::FALSY, true);
    }

    /** @param array<string, mixed> $detail */
    private function audit(string $action, string $target, array $detail): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_audit_log (user_id, admin_user_id, actor_type, actor_email, action, target, result, detail_json, ip_address, created_at)
                 VALUES (NULL, :aid, 'admin', :em, :ac, :tg, 'success', :dj, :ip, NOW())",
                ['aid' => $this->adminSession->adminId(), 'em' => $this->adminSession->adminEmail(), 'ac' => $action, 'tg' => $target,
                 'dj' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]
            );
        } catch (\Throwable $e) {
            $this->logger->critical('system_audit_log kaydı yazılamadı: ' . $e->getMessage(), 'AdminSync', [
                'action' => $action, 'target' => $target,
            ]);
        }
    }
}
