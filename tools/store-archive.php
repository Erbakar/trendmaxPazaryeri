<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['store:', 'export', 'import:', 'apply']);
$storeId = filter_var($options['store'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$storeId || (isset($options['export']) === isset($options['import']))) {
    fwrite(STDERR, "Kullanım: php tools/store-archive.php --store=ID --export\n" .
        "         php tools/store-archive.php --store=ID --import=ARSIV.json [--apply]\n" .
        "Varsayılan: doğrulama ve geri alma; hedef aynı mağaza ve boş olmalıdır.\n");
    exit(1);
}
require dirname(__DIR__) . '/public/index.php';
$container = $GLOBALS['container'];
$tenant = $container->get(\App\Core\TenantContext::class);
$tenant->setStoreId((int) $storeId);
try {
    $service = $container->get(\App\Services\TenantExportService::class);
    if (isset($options['export'])) echo $service->exportCurrentStoreToFile() . PHP_EOL;
    else {
        $dryRun = !isset($options['apply']);
        $counts = $service->importIntoCurrentStore((string) $options['import'], $dryRun);
        echo json_encode(['mode' => $dryRun ? 'validated_rolled_back' : 'imported', 'counts' => $counts], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e instanceof \PDOException ? "Veritabanı doğrulaması başarısız; işlem geri alındı.\n" : $e->getMessage() . PHP_EOL);
    exit(1);
} finally { $tenant->clear(); }
