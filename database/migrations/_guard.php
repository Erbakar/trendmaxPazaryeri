<?php
/**
 * OzyConnect — Tek başına çalıştırılabilen migration scriptleri (add_*.php) için
 * ORTAK GÜVENLİK KAPISI.
 *
 * migrate.php'deki guard mantığının aynısı:
 *  - YALNIZCA CLI (tarayıcıdan çalıştırma 403 ile engellenir).
 *  - Canlı (yapılandırılmış DB_NAME) DB'ye yazma, --allow-live OLMADAN ENGELLENİR.
 *  - --database=NAME ile test DB'sine yönlendirilebilir (canlıya dokunmadan).
 *
 * Kullanım (script içinde):
 *   $pdo = require __DIR__ . '/_guard.php';
 *
 * @return \PDO  Hedef (güvenli) DB'ye bağlı PDO.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu script yalnızca CLI'dan çalışır (tarayıcıdan erişim engellendi).\n");
}

$envFile = dirname(__DIR__, 2) . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "HATA: .env dosyası bulunamadı\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
}

$argvSafe  = $GLOBALS['argv'] ?? [];
$args      = array_slice($argvSafe, 1);
$allowLive = in_array('--allow-live', $args, true);

$overrideDb = null;
foreach ($args as $a) {
    if (str_starts_with((string) $a, '--database=')) {
        $overrideDb = substr((string) $a, strlen('--database='));
    }
}

$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';

$liveDbName = (string) ($env['DB_NAME'] ?? '');
$targetDb   = ($overrideDb !== null && $overrideDb !== '') ? $overrideDb : $liveDbName;

// ─── GÜVENLİK KAPISI: canlı DB'ye yazma engellendi (onay olmadan) ──
if ($targetDb === $liveDbName && !$allowLive) {
    fwrite(STDERR,
        "GÜVENLİK: Yapılandırılmış (canlı) DB '{$targetDb}' üzerinde yazma ENGELLENDİ.\n" .
        "  • Test için:  php database/migrations/<script>.php --database=ozyconnect_tenant_test\n" .
        "  • Canlıya geçiş (YEDEK + onay sonrası): --allow-live\n");
    exit(2);
}

echo "Hedef DB: {$targetDb}\n";

return new PDO(
    "mysql:host={$host};port={$port};dbname={$targetDb};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
