<?php
// OzyConnect Panel — Cron: XML Feed Üretimi
// Kullanım: php cron/generate_feeds.php
// Crontab: Her 4 saatte bir
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Feed\XmlFeedService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$feedService = $container->get(XmlFeedService::class);
$cronRunner  = $container->get(\App\Services\TenantCronRunner::class);
$logger = $container->get(\App\Core\Logger::class);

echo "[" . date('Y-m-d H:i:s') . "] Feed üretimi başlıyor (per-store)...\n";

// Faz 4 — PER-STORE: xml_feeds tenant-scoped; her mağaza kendi bağlamında.
$storeResults = $cronRunner->runForActiveStores(static fn(int $sid): array => $feedService->regenerateAll());

foreach ($storeResults as $storeId => $sr) {
    if (!($sr['ok'] ?? false)) {
        echo "  store#{$storeId}: [HATA] " . ($sr['error'] ?? '?') . "\n";
        $logger->error("Cron feed store#{$storeId}: " . ($sr['error'] ?? '?'), 'Cron');
        continue;
    }
    foreach (($sr['result'] ?? []) as $feedId => $count) {
        echo "  store#{$storeId} Feed #{$feedId}: " . ($count >= 0 ? "{$count} ürün" : "HATA") . "\n";
    }
}
echo "[OK] Feed üretimi tamamlandı (" . count($storeResults) . " mağaza).\n";
