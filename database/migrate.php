<?php
declare(strict_types=1);
/**
 * OzyConnect — Migration Runner (CLI)  [Faz 1: multi-tenant]
 *
 * Kullanım:
 *   php database/migrate.php --baseline   # mevcut gevşek migration'ları ÇALIŞTIRMADAN "applied" işaretle
 *   php database/migrate.php --dry-run    # uygulanacakları listele, DB'ye dokunma
 *   php database/migrate.php              # bekleyen migration'ları uygula
 *
 * NOTLAR:
 *  - MySQL/MariaDB DDL implicit-commit yapar (geri alınamaz). Bu yüzden phase1 migration'ları
 *    MariaDB "IF [NOT] EXISTS" uzantılarıyla idempotent yazıldı + schema_migrations dedup'u.
 *  - ÖNCE boş bir test DB'sinde çalıştırın; canlı DB'de mutlaka yedek alın.
 *  - Sıra: --baseline (mevcut 24 dosyayı işaretle) -> (yedek) -> --dry-run -> uygula.
 *
 * @package OzyConnect
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu script yalnızca CLI'dan çalışır.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

// ─── .env yükle (public/index.php ile aynı mantık) ──────────────
(function (): void {
    $envFile = BASE_PATH . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ((str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
        putenv("{$k}={$v}");
    }
})();

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return $default;
        }
        return match (strtolower((string) $v)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $v,
        };
    }
}

// ─── Autoload ───────────────────────────────────────────────────
$autoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(function (string $c): void {
        if (str_starts_with($c, 'App\\')) {
            $f = BASE_PATH . '/app/' . str_replace('\\', '/', substr($c, 4)) . '.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
    });
}

use App\Core\Database;

/**
 * String-bilinçli SQL splitter: tırnak ('"`) ve yorum (-- # block) içindeki ';' bölmez.
 *
 * @return list<string>
 */
function ozy_split_sql(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $inS = $inD = $inB = $inLine = $inBlock = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = $i + 1 < $len ? $sql[$i + 1] : '';
        if ($inLine) { $buf .= $c; if ($c === "\n") { $inLine = false; } continue; }
        if ($inBlock) { $buf .= $c; if ($c === '*' && $n === '/') { $buf .= $n; $i++; $inBlock = false; } continue; }
        if ($inS) { $buf .= $c; if ($c === '\\') { $buf .= $n; $i++; } elseif ($c === "'") { $inS = false; } continue; }
        if ($inD) { $buf .= $c; if ($c === '\\') { $buf .= $n; $i++; } elseif ($c === '"') { $inD = false; } continue; }
        if ($inB) { $buf .= $c; if ($c === '`') { $inB = false; } continue; }
        if ($c === '-' && $n === '-') { $inLine = true; $buf .= $c; continue; }
        if ($c === '#') { $inLine = true; $buf .= $c; continue; }
        if ($c === '/' && $n === '*') { $inBlock = true; $buf .= $c; continue; }
        if ($c === "'") { $inS = true; $buf .= $c; continue; }
        if ($c === '"') { $inD = true; $buf .= $c; continue; }
        if ($c === '`') { $inB = true; $buf .= $c; continue; }
        if ($c === ';') { $t = trim($buf); if ($t !== '') { $stmts[] = $t; } $buf = ''; continue; }
        $buf .= $c;
    }
    $t = trim($buf);
    if ($t !== '') {
        $stmts[] = $t;
    }
    return $stmts;
}

// ─── Argümanlar ─────────────────────────────────────────────────
$args      = array_slice($argv, 1);
$baseline  = in_array('--baseline', $args, true);
$dryRun    = in_array('--dry-run', $args, true);
$rollback  = in_array('--rollback', $args, true);
$allowLive = in_array('--allow-live', $args, true);

// --database=NAME : hedef DB'yi override et (test DB'sini hedeflemek için; canlıya dokunmadan)
$overrideDb = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--database=')) {
        $overrideDb = substr($a, strlen('--database='));
    }
}

// .env'deki canlı DB adı (override ÖNCESİ yakalanır — guard için)
$liveDbName = (string) env('DB_NAME');

if ($overrideDb !== null && $overrideDb !== '') {
    $_ENV['DB_NAME'] = $overrideDb;
    $_SERVER['DB_NAME'] = $overrideDb;
    putenv('DB_NAME=' . $overrideDb);
}
$targetDb = (string) env('DB_NAME');

// ─── GÜVENLİK KAPISI: canlı DB'ye yazma engellendi (onay olmadan) ──
$isWrite = !$dryRun; // baseline/apply/rollback hepsi yazma
if ($isWrite && $targetDb === $liveDbName && !$allowLive) {
    fwrite(STDERR,
        "GÜVENLİK: Yapılandırılmış (canlı) DB '{$targetDb}' üzerinde yazma ENGELLENDİ.\n" .
        "  • Test için:  php database/migrate.php --database=ozyconnect_tenant_test [--baseline]\n" .
        "  • Sadece liste: --dry-run\n" .
        "  • Canlıya geçiş (YEDEK + onay sonrası): --allow-live\n");
    exit(2);
}

echo "Hedef DB: {$targetDb}\n";
$pdo = (new Database())->getConnection();

// ─── schema_migrations garanti (mevcut şemayla uyumlu) ──────────
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `filename` varchar(255) NOT NULL,
        `checksum` char(64) NOT NULL,
        `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
        `duration_ms` int(10) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `filename` (`filename`),
        KEY `idx_applied_at` (`applied_at`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$appliedRows = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($appliedRows);

// ─── ROLLBACK (--rollback): phase1/phase2 down migration'larını ters sırada uygula ──
// (phaseN/down/<aynı ad>; DESC sıralama → phase2 phase1'den önce geri alınır.)
if ($rollback) {
    $phaseApplied = $pdo->query(
        "SELECT filename FROM schema_migrations
         WHERE filename LIKE 'database/migrations/phase%'
         ORDER BY filename DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($phaseApplied === []) {
        echo "Geri alınacak phase migration yok.\n";
        exit(0);
    }
    $del = $pdo->prepare('DELETE FROM schema_migrations WHERE filename = ?');
    foreach ($phaseApplied as $key) {
        // down dosyası, up ile aynı klasörün altındaki down/ içinde (phaseN bağımsız).
        $downPath = BASE_PATH . '/' . str_replace('\\', '/', dirname($key)) . '/down/' . basename($key);
        if (!is_file($downPath)) {
            fwrite(STDERR, "[HATA] down dosyası yok: " . basename($key) . "\n");
            exit(1);
        }
        if ($dryRun) {
            echo "[DRY-DOWN] {$key}\n";
            continue;
        }
        try {
            foreach (ozy_split_sql((string) file_get_contents($downPath)) as $s) {
                $pdo->exec($s);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "[HATA-DOWN] {$key}: " . $e->getMessage() . "\n");
            exit(1);
        }
        $del->execute([$key]);
        echo "[DOWN] {$key}\n";
    }
    echo "Rollback tamamlandı.\n";
    exit(0);
}

// ─── Migration dosyalarını topla (göreli yol = benzersiz anahtar) ──
// database/migrations + tüm phaseN alt klasörleri (otomatik keşif) + kök migrations.
$dirs = ['database/migrations'];
foreach (glob(BASE_PATH . '/database/migrations/phase*', GLOB_ONLYDIR) ?: [] as $pd) {
    $dirs[] = 'database/migrations/' . basename($pd);
}
$dirs[] = 'migrations';
$files = [];
foreach ($dirs as $rel) {
    $dir = BASE_PATH . '/' . $rel;
    if (!is_dir($dir)) {
        continue;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === '_guard.php') {
            continue;
        }
        if (!preg_match('/\.(sql|php)$/i', $f)) {
            continue; // alt klasörler (phase1) ayrı $dirs girdisi olarak gezilir
        }
        $files[$rel . '/' . $f] = $dir . '/' . $f;
    }
}
// phase2 must run before phase10; lexical order breaks schema dependencies.
uksort($files, 'strnatcasecmp');

$pending = [];
foreach ($files as $key => $path) {
    if (!isset($applied[$key])) {
        $pending[$key] = $path;
    }
}

if ($pending === []) {
    echo "Bekleyen migration yok.\n";
    exit(0);
}

$insert = $pdo->prepare('INSERT INTO schema_migrations (filename, checksum, duration_ms) VALUES (?, ?, ?)');
$count = 0;

foreach ($pending as $key => $path) {
    // TÜM phaseN migration'lari --baseline ile ATLANMAZ (gercekten calismali); yalniz
    // legacy (database/migrations + kök migrations) baseline'lanir.
    $isPhase1  = (bool) preg_match('#/phase\d+/#', str_replace('\\', '/', $key));
    $contents  = (string) file_get_contents($path);
    $checksum  = hash('sha256', $contents);

    if ($dryRun) {
        echo "[DRY] {$key}\n";
        continue;
    }

    // --baseline: phase1 DIŞINDAKİ mevcut migration'ları çalıştırmadan işaretle
    if ($baseline) {
        if ($isPhase1) {
            echo "[ATLA] {$key} (phase1 — baseline değil; --baseline'sız çalıştırın)\n";
            continue;
        }
        $insert->execute([$key, $checksum, 0]);
        echo "[BASELINE] {$key}\n";
        $count++;
        continue;
    }

    // Normal uygulama
    if (preg_match('/\.php$/i', $path)) {
        echo "[UYARI] {$key} bir .php migration — runner çalıştırmıyor; --baseline ile işaretleyin.\n";
        continue;
    }

    $stmts = ozy_split_sql($contents);
    $start = microtime(true);
    try {
        foreach ($stmts as $s) {
            $pdo->exec($s);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[HATA] {$key}: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Migration durduruldu. (Not: DDL geri alınamaz; şema durumunu kontrol edin.)\n");
        exit(1);
    }
    $ms = (int) round((microtime(true) - $start) * 1000);
    $insert->execute([$key, $checksum, $ms]);
    echo "[OK] {$key} ({$ms}ms, " . count($stmts) . " ifade)\n";
    $count++;
}

echo "Tamamlandı. İşlenen: {$count}\n";
