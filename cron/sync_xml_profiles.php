<?php
// OzyConnect Panel — Cron: XML Profilleri Otomatik Senkron
// Periyodu (period_hours) gelen aktif profilleri çalıştırır:
//   import → tedarikçi XML'inden ürün senkronu (Ticimax/dbentegrasyon uyumlu)
//   export → Ticimax formatlı XML çıktısını yeniden üretir
// Kullanım: php cron/sync_xml_profiles.php
// Crontab : Saatte bir → 0 * * * * php cron/sync_xml_profiles.php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Models\XmlProfile;
use App\Services\Feed\XmlProfileExportService;
use App\Services\Import\XmlProfileImportService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) { echo "[ERROR] Container yüklenemedi\n"; exit(1); }

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$profileModel  = $container->get(XmlProfile::class);
$importService = $container->get(XmlProfileImportService::class);
$exportService = $container->get(XmlProfileExportService::class);
$cronRunner    = $container->get(\App\Services\TenantCronRunner::class);
$logger        = $container->get(\App\Core\Logger::class);

echo "[" . date('Y-m-d H:i:s') . "] XML profil senkronu başlıyor (per-store)...\n";

$storeResults = $cronRunner->runForActiveStores(static function (int $storeId) use ($profileModel, $importService, $exportService): array {
    $out = [];
    foreach ($profileModel->getDue() as $profile) {
        $pid = (int) $profile['id'];
        $label = "#{$pid} {$profile['name']}";
        try {
            if (($profile['direction'] ?? 'import') === 'export') {
                $count = $exportService->generate($pid);
                $out[$label] = "export: {$count} ürün üretildi";
            } else {
                $r = $importService->run($pid, 'cron');
                $out[$label] = ($r['ok'] ? '' : 'HATA - ') . $r['message'];
            }
        } catch (\Throwable $e) {
            $out[$label] = 'HATA - ' . $e->getMessage();
        }
    }
    return $out;
});

foreach ($storeResults as $storeId => $sr) {
    if (!($sr['ok'] ?? false)) {
        echo "  store#{$storeId}: [HATA] " . ($sr['error'] ?? '?') . "\n";
        $logger->error("XML profil cron store#{$storeId}: " . ($sr['error'] ?? '?'), 'Cron');
        continue;
    }
    $lines = $sr['result'] ?? [];
    if ($lines === []) {
        continue; // vadesi gelen profil yok
    }
    foreach ($lines as $label => $line) {
        echo "  store#{$storeId} {$label}: {$line}\n";
    }
}
echo "[OK] XML profil senkronu tamamlandı (" . count($storeResults) . " mağaza).\n";
