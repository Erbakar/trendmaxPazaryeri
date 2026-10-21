<?php
/**
 * OzyConnect Panel — Abonelik Yaşam Döngüsü Cron (Faz 8)
 *
 * Her aktif mağaza için: trial bitti → expired, active dönem bitti → expired (entitlement revoke).
 * Geçiş olursa in-app bildirim + subscription_ended maili. TenantCronRunner ile per-store izolasyon
 * (setStoreId → iş → clear) → uzun süreçte tenant SIZINTISI yok. store#1 (internal, 2099) hiç etkilenmez.
 *
 * Kullanım:
 *   php cron/subscriptions_lifecycle.php            # uygula
 *   php cron/subscriptions_lifecycle.php --dry-run  # yalnız raporla
 * Crontab (günlük):  0 3 * * * php cron/subscriptions_lifecycle.php
 *
 * @package OzyConnect
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Core\Database;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Services\SubscriptionService;
use App\Services\TenantCronRunner;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    fwrite(STDERR, "[ERROR] Container yüklenemedi\n");
    exit(1);
}
$dryRun = in_array('--dry-run', $argv, true);

$db       = $container->get(Database::class);
$runner   = $container->get(TenantCronRunner::class);
$subSvc   = $container->get(SubscriptionService::class);
$notifs   = $container->get(Notification::class);
$notifier = $container->get(NotificationService::class);

echo '[' . date('Y-m-d H:i:s') . "] Abonelik lifecycle başlıyor" . ($dryRun ? ' (DRY-RUN)' : '') . "\n";

$expired = 0;
$results = $runner->runForActiveStores(function (int $storeId) use ($db, $subSvc, $notifs, $notifier, $dryRun, &$expired): string {
    if ($dryRun) {
        // Yalnız raporla: ne yapılacağını göster, durum DEĞİŞTİRME.
        $sub = $db->fetchOne("SELECT status, trial_ends_at, current_period_end FROM subscriptions WHERE store_id = :s", ['s' => $storeId]);
        if ($sub === false) { return 'none'; }
        $now = date('Y-m-d H:i:s');
        if ($sub['status'] === 'trial' && !empty($sub['trial_ends_at']) && $sub['trial_ends_at'] < $now) { return 'WOULD expire_trial'; }
        if ($sub['status'] === 'active' && !empty($sub['current_period_end']) && $sub['current_period_end'] < $now) { return 'WOULD expire_period'; }
        return 'none';
    }

    $action = $subSvc->runLifecycle($storeId);
    if (str_starts_with($action, 'expired')) {
        $expired++;
        $notifs->push($storeId, 'subscription', 'Aboneliğiniz sona erdi', 'Devam etmek için ödeme yapabilirsiniz.', '/subscription');
        // Sahibe e-posta (best-effort)
        try {
            $owner = $db->fetchOne("SELECT email, name FROM users WHERE store_id = :s AND role = 'admin' ORDER BY id LIMIT 1", ['s' => $storeId]);
            if ($owner !== false && !empty($owner['email'])) {
                $notifier->sendTemplate((string) $owner['email'], 'subscription_ended', [
                    'name' => (string) ($owner['name'] ?? ''), 'package' => '', 'date' => date('d.m.Y'),
                ]);
            }
        } catch (\Throwable) {
            // sessiz
        }
    }
    return $action;
});

foreach ($results as $storeId => $r) {
    $line = $r['ok'] ? (string) ($r['result'] ?? '') : ('HATA: ' . ($r['error'] ?? ''));
    if ($line !== '' && $line !== 'none') {
        echo "  store#{$storeId}: {$line}\n";
    }
}
echo '[' . date('Y-m-d H:i:s') . "] Bitti. " . ($dryRun ? 'rapor' : "{$expired} abonelik expire edildi") . "\n";
exit(0);
