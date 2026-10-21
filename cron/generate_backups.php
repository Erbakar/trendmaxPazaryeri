<?php
// OzyConnect Panel — Cron: Otomatik Yedekleme
// Kullanım: php cron/generate_backups.php
// Crontab: Günde 1 kez (gün sonu)
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\BackupService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$backupService = $container->get(BackupService::class);
$logger = $container->get(\App\Core\Logger::class);

echo "[" . date('Y-m-d H:i:s') . "] Otomatik yedekleme başlıyor...\n";

try {
    $result = $backupService->createBackup('auto');
    echo "  Dosya: {$result['filename']}\n";
    echo "  Boyut: {$backupService->formatBytes($result['file_size'])}\n";
    echo "  Tablolar: {$result['tables_count']}, Dosyalar: {$result['files_count']}\n";
    echo "[OK] Yedek tamamlandı.\n";
} catch (\Throwable $e) {
    echo "[ERROR] Yedek hatası: " . $e->getMessage() . "\n";
    $logger->error('Cron yedekleme hatası: ' . $e->getMessage(), 'Cron');
    exit(1);
}

// Eski yedekleri temizle (30 günden eski)
try {
    $cleaned = $backupService->cleanOldBackups(30);
    if ($cleaned > 0) {
        echo "[CLEAN] {$cleaned} eski yedek silindi.\n";
    }
} catch (\Throwable $e) {
    echo "[WARN] Temizleme hatası: " . $e->getMessage() . "\n";
}
