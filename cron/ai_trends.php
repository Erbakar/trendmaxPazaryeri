<?php
// OzyConnect Panel — Cron: AI Trend Merkezi (Modül #7)
// Kullanım: php cron/ai_trends.php
// Crontab: Günde 1 kez (ör. her gece 04:00)
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/public/index.php';

use App\Services\Ai\AiTrendService;
use App\Services\Ai\AiNotEntitledException;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$trendService = $container->get(AiTrendService::class);
$logger = $container->get(\App\Core\Logger::class);

echo "[" . date('Y-m-d H:i:s') . "] AI Trend hesaplama başlıyor (per-store)...\n";

// PER-STORE: her aktif mağaza kendi TenantContext'iyle; yetkisiz/kapalı mağaza atlanır; izole hata.
$cronRunner = $container->get(\App\Services\TenantCronRunner::class);
$storeResults = $cronRunner->runForActiveStores(static function (int $sid) use ($trendService): array {
    try {
        return $trendService->generateForStore();
    } catch (AiNotEntitledException) {
        return ['status' => 'not_entitled', 'rising' => 0, 'falling' => 0, 'opportunity' => 0];
    }
});

foreach ($storeResults as $storeId => $sr) {
    if (!($sr['ok'] ?? false)) {
        echo "  store#{$storeId}: [HATA] " . ($sr['error'] ?? '?') . "\n";
        $logger->error("Cron AI trend store#{$storeId}: " . ($sr['error'] ?? '?'), 'Cron');
        continue;
    }
    $r = $sr['result'];
    echo "  store#{$storeId} — {$r['status']} (yükselen: {$r['rising']}, düşen: {$r['falling']}, fırsat: {$r['opportunity']})\n";
}
echo "[OK] AI Trend tamamlandı (" . count($storeResults) . " mağaza).\n";
