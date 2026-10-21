<?php
/**
 * Entegratör Yazılımı — Kurulum Sihirbazı (installer.php)
 *
 * @package    EntegratorYazilimi
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */

// Eğer zaten kurulmuşsa, yönlendir
function installer_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_replace('\\', '/', dirname($scriptName));

    if ($dir === '.' || $dir === '/') {
        return '';
    }

    return '/' . trim($dir, '/');
}

function installer_url(string $path = ''): string
{
    $base = installer_base_path();
    $path = '/' . ltrim($path, '/');

    if ($path === '/') {
        return $base === '' ? '/' : $base;
    }

    return $base . $path;
}

/**
 * SQL dosyalarını tırnak ve yorumların içindeki noktalı virgülleri bozmadan ayırır.
 *
 * @return list<string>
 */
function installer_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = $inDouble = $inBacktick = $inLineComment = $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $buffer .= $char;
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            $buffer .= $char;
            if ($char === '*' && $next === '/') {
                $buffer .= $next;
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
            } elseif ($char === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
            } elseif ($char === '"') {
                $inDouble = false;
            }
            continue;
        }
        if ($inBacktick) {
            $buffer .= $char;
            if ($char === '`') {
                $inBacktick = false;
            }
            continue;
        }

        if ($char === '-' && $next === '-') {
            $inLineComment = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '#') {
            $inLineComment = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $buffer .= $char;
            continue;
        }
        if ($char === "'") {
            $inSingle = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '"') {
            $inDouble = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '`') {
            $inBacktick = true;
            $buffer .= $char;
            continue;
        }
        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function installer_execute_sql_file(PDO $pdo, string $path): int
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('SQL dosyası okunamadı: ' . basename($path));
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('SQL dosyası yüklenemedi: ' . basename($path));
    }

    $count = 0;
    foreach (installer_split_sql($sql) as $statement) {
        $pdo->exec($statement);
        $count++;
    }
    return $count;
}

/** Güncel uygulama şemasını faz numarasına göre kurar. */
function installer_apply_migrations(PDO $pdo): int
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `filename` varchar(255) NOT NULL,
            `checksum` char(64) NOT NULL,
            `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
            `duration_ms` int(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $migrationFiles = [];

    // Çekirdek SQL, 03.05.2026 tarihine kadarki legacy migration'ları zaten içerir.
    // Sonraki yeni tablolar çalıştırılır; kalanlar tekrar uygulanmadan baseline olarak kaydedilir.
    $legacyToExecute = [
        'create_support_tickets_tables.sql',
        'create_app_updates_tables.sql',
    ];
    foreach (glob(__DIR__ . '/database/migrations/*.{sql,php}', GLOB_BRACE) ?: [] as $path) {
        if (basename($path) === '_guard.php') {
            continue;
        }
        $migrationFiles[] = [
            'key' => 'database/migrations/' . basename($path),
            'path' => $path,
            'mode' => in_array(basename($path), $legacyToExecute, true)
                ? 'execute'
                : (basename($path) === 'create_question_canned_responses_table.php' ? 'question_table' : 'baseline'),
        ];
    }
    foreach (glob(__DIR__ . '/migrations/*.{sql,php}', GLOB_BRACE) ?: [] as $path) {
        $migrationFiles[] = [
            'key' => 'migrations/' . basename($path),
            'path' => $path,
            'mode' => 'baseline',
        ];
    }
    usort($migrationFiles, static fn(array $a, array $b): int => strnatcasecmp($a['key'], $b['key']));

    $phaseDirs = glob(__DIR__ . '/database/migrations/phase*', GLOB_ONLYDIR) ?: [];
    usort($phaseDirs, static function (string $a, string $b): int {
        preg_match('/phase(\d+)$/', str_replace('\\', '/', $a), $ma);
        preg_match('/phase(\d+)$/', str_replace('\\', '/', $b), $mb);
        return ((int) ($ma[1] ?? 0)) <=> ((int) ($mb[1] ?? 0));
    });

    foreach ($phaseDirs as $phaseDir) {
        $files = glob($phaseDir . '/*.sql') ?: [];
        natsort($files);
        foreach ($files as $path) {
            $migrationFiles[] = [
                'key' => 'database/migrations/' . basename($phaseDir) . '/' . basename($path),
                'path' => $path,
                'mode' => 'execute',
            ];
        }
    }

    $applied = array_flip($pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $record = $pdo->prepare(
        'INSERT INTO schema_migrations (filename, checksum, duration_ms) VALUES (:filename, :checksum, :duration)'
    );
    $count = 0;

    foreach ($migrationFiles as $migration) {
        if (isset($applied[$migration['key']])) {
            continue;
        }

        $started = microtime(true);
        if ($migration['mode'] === 'execute') {
            installer_execute_sql_file($pdo, $migration['path']);
        } elseif ($migration['mode'] === 'question_table') {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `question_canned_responses` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `store_id` INT UNSIGNED NOT NULL,
                    `title` VARCHAR(120) NOT NULL,
                    `body` TEXT NOT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_qcr_store` (`store_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $record->execute([
            'filename' => $migration['key'],
            'checksum' => hash_file('sha256', $migration['path']),
            'duration' => (int) round((microtime(true) - $started) * 1000),
        ]);
        $count++;
    }

    return $count;
}

function installer_seed_demo_data(PDO $pdo): void
{
    $seedPath = __DIR__ . '/database/seeds/demo_seed.php';
    if (!is_file($seedPath)) {
        throw new RuntimeException('Demo veri dosyası bulunamadı.');
    }

    $dbAdapter = new class($pdo) {
        public function __construct(private PDO $pdo) {}
        public function query(string $sql, array $params = []): PDOStatement
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement;
        }
        public function fetchOne(string $sql, array $params = []): array|false
        {
            return $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        }
        public function lastInsertId(): string|false
        {
            return $this->pdo->lastInsertId();
        }
    };

    $previousContainer = $GLOBALS['container'] ?? null;
    $GLOBALS['container'] = new class($dbAdapter) {
        public function __construct(private object $database) {}
        public function get(string $id): object
        {
            return $this->database;
        }
    };

    ob_start();
    try {
        require $seedPath;
    } finally {
        ob_end_clean();
        if ($previousContainer === null) {
            unset($GLOBALS['container']);
        } else {
            $GLOBALS['container'] = $previousContainer;
        }
    }
}

function installer_valid_database_name(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
}

if (file_exists(__DIR__ . '/.env') && file_exists(__DIR__ . '/installer_done.lock')) {
    header('Location: ' . installer_url('/login'));
    exit;
}

$step = (int) ($_GET['step'] ?? 1);
$errors = [];
$success = '';

// ─── AJAX Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_POST['action']) {
        case 'check_requirements':
            $checks = [
                'php_version'  => ['label' => 'PHP 8.2+', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'value' => PHP_VERSION],
                'pdo_mysql'    => ['label' => 'PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Yüklü' : 'Eksik'],
                'curl'         => ['label' => 'cURL', 'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? 'Yüklü' : 'Eksik'],
                'openssl'      => ['label' => 'OpenSSL', 'ok' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? 'Yüklü' : 'Eksik'],
                'mbstring'     => ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'Yüklü' : 'Eksik'],
                'json'         => ['label' => 'JSON', 'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'Yüklü' : 'Eksik'],
                'fileinfo'     => ['label' => 'Fileinfo', 'ok' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? 'Yüklü' : 'Eksik'],
                'storage_logs' => ['label' => 'storage/logs yazılabilir', 'ok' => is_writable(__DIR__ . '/storage/logs') || @mkdir(__DIR__ . '/storage/logs', 0755, true), 'value' => ''],
                'storage_cache'=> ['label' => 'storage/cache yazılabilir', 'ok' => is_writable(__DIR__ . '/storage/cache') || @mkdir(__DIR__ . '/storage/cache', 0755, true), 'value' => ''],
                'storage_feeds'=> ['label' => 'storage/feeds yazılabilir', 'ok' => is_writable(__DIR__ . '/storage/feeds') || @mkdir(__DIR__ . '/storage/feeds', 0755, true), 'value' => ''],
                'storage_inv'  => ['label' => 'storage/invoices yazılabilir', 'ok' => is_writable(__DIR__ . '/storage/invoices') || @mkdir(__DIR__ . '/storage/invoices', 0755, true), 'value' => ''],
            ];
            $allOk = array_reduce($checks, fn($carry, $c) => $carry && $c['ok'], true);
            echo json_encode(['success' => true, 'checks' => $checks, 'all_ok' => $allOk]);
            exit;

        case 'test_db':
            $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
            $port = trim((string) ($_POST['db_port'] ?? '3306'));
            $name = trim((string) ($_POST['db_name'] ?? ''));
            $user = trim((string) ($_POST['db_user'] ?? ''));
            $pass = (string) ($_POST['db_pass'] ?? '');
            try {
                if (!installer_valid_database_name($name)) {
                    throw new InvalidArgumentException('Veritabanı adı yalnızca harf, rakam ve alt çizgi içerebilir.');
                }
                if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                    throw new InvalidArgumentException('Geçerli bir veritabanı portu girin.');
                }
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                // DB var mı kontrol et / oluştur
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$name}`");

                echo json_encode(['success' => true, 'message' => 'Veritabanı bağlantısı başarılı!']);
            } catch (\PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Bağlantı hatası: ' . $e->getMessage()]);
            }
            exit;

        case 'install':
            try {
                $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
                $port = trim((string) ($_POST['db_port'] ?? '3306'));
                $name = trim((string) ($_POST['db_name'] ?? 'entegrator_yazilimi'));
                $user = trim((string) ($_POST['db_user'] ?? 'root'));
                $pass = (string) ($_POST['db_pass'] ?? '');
                $siteUrl = rtrim((string) ($_POST['site_url'] ?? ''), '/');
                if ($siteUrl === '') {
                    $installProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $siteUrl = rtrim($installProtocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . installer_base_path(), '/');
                }
                $timezone = (string) ($_POST['timezone'] ?? 'Europe/Istanbul');
                $adminName = trim((string) ($_POST['admin_name'] ?? 'Admin'));
                $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
                $adminPassword = (string) ($_POST['admin_password'] ?? '');
                $installDemoData = ($_POST['install_demo_data'] ?? '0') === '1';

                if (!installer_valid_database_name($name)) {
                    throw new InvalidArgumentException('Veritabanı adı yalnızca harf, rakam ve alt çizgi içerebilir.');
                }
                if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                    throw new InvalidArgumentException('Geçerli bir veritabanı portu girin.');
                }
                if ($adminName === '') {
                    throw new InvalidArgumentException('Yönetici adı boş bırakılamaz.');
                }
                if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Geçerli bir yönetici e-posta adresi girin.');
                }
                if (strlen($adminPassword) < 8) {
                    throw new InvalidArgumentException('Yönetici şifresi en az 8 karakter olmalıdır.');
                }
                if (!in_array($timezone, timezone_identifiers_list(), true)) {
                    throw new InvalidArgumentException('Geçersiz zaman dilimi seçildi.');
                }

                // Şifreleme anahtarı oluştur
                $encryptionKey = bin2hex(random_bytes(32));

                // Veritabanını oluştur ve boş olduğunu doğrula.
                $serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $serverPdo = new PDO($serverDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                $tableCount = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
                )->fetchColumn();
                if ($tableCount > 0) {
                    throw new RuntimeException(
                        'Seçilen veritabanı boş değil. Mevcut verileri korumak için kurulum durduruldu; boş bir veritabanı seçin.'
                    );
                }

                // Güncel ozy veritabanından üretilmiş temiz kurulum şeması + eksik migration'lar.
                $sqlPath = __DIR__ . '/database/entegrator_install.sql';
                if (!file_exists($sqlPath)) {
                    $sqlPath = __DIR__ . '/ozyconnect.sql';
                }
                if (!file_exists($sqlPath)) {
                    $sqlPath = __DIR__ . '/database/ozyconnect.sql';
                }
                if (!file_exists($sqlPath)) {
                    throw new RuntimeException('Kurulum SQL dosyası bulunamadı.');
                }
                installer_execute_sql_file($pdo, $sqlPath);
                $migrationCount = installer_apply_migrations($pdo);

                // Users tablosunun var olduğunu doğrula
                try {
                    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                    if (!$tableCheck) {
                        throw new \RuntimeException('users tablosu oluşturulamadı! SQL dosyası hatalı olabilir.');
                    }
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Veritabanı tabloları oluşturulamadı: ' . $e->getMessage());
                }

                // Admin kullanıcı oluştur
                $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                
                // Önce mevcut kullanıcıyı kontrol et
                $existingUser = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $existingUser->execute([$adminEmail]);
                
                if ($existingUser->fetch()) {
                    // Mevcut kullanıcıyı güncelle
                    $stmt = $pdo->prepare("UPDATE users SET store_id = 1, name = ?, password_hash = ?, role = 'admin', role_id = 1, is_active = 1 WHERE email = ?");
                    $stmt->execute([$adminName, $passwordHash, $adminEmail]);
                } else {
                    // Yeni kullanıcı ekle
                    $stmt = $pdo->prepare("INSERT INTO users (store_id, name, email, password_hash, role, role_id, is_active, created_at) VALUES (1, ?, ?, ?, 'admin', 1, 1, NOW())");
                    $stmt->execute([$adminName, $adminEmail, $passwordHash]);
                }

                // Aynı ilk yönetici hesabını ayrı platform yönetim alanı için de oluştur.
                $adminAccount = $pdo->prepare(
                    "INSERT INTO admin_users (name, email, password_hash, is_active, created_at)
                     VALUES (?, ?, ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), is_active = 1"
                );
                $adminAccount->execute([$adminName, $adminEmail, $passwordHash]);
                
                // Admin oluşturulduğunu doğrula
                $verifyAdmin = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
                $verifyAdmin->execute([$adminEmail]);
                $adminRow = $verifyAdmin->fetch(PDO::FETCH_ASSOC);
                
                if (!$adminRow) {
                    throw new \RuntimeException('Admin kullanıcı oluşturulamadı! Veritabanı yazma izinlerini kontrol edin.');
                }

                $settingsUpdate = $pdo->prepare("UPDATE settings SET value = :value WHERE `key` = :key");
                $settingsUpdate->execute(['value' => $adminEmail, 'key' => 'admin_email']);
                $settingsUpdate->execute(['value' => date('Y-m-d H:i:s'), 'key' => 'install_date']);

                if ($installDemoData) {
                    installer_seed_demo_data($pdo);
                }

                // .env dosyası oluştur
                $envContent = <<<ENV
# Entegratör Yazılımı — Ortam Dosyası
# Oluşturulma: {$timezone} — Installer tarafından

APP_NAME="Entegratör Yazılımı"
APP_ENV=production
APP_DEBUG=false
APP_URL={$siteUrl}
APP_TIMEZONE={$timezone}

DB_HOST={$host}
DB_PORT={$port}
DB_NAME={$name}
DB_USER={$user}
DB_PASS={$pass}
DB_CHARSET=utf8mb4

ENCRYPTION_KEY={$encryptionKey}

SESSION_LIFETIME=7200
SESSION_NAME=EntegratorYazilimi_session

LOG_LEVEL=DEBUG
LOG_RETENTION_DAYS=7

SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM_EMAIL=noreply@example.com
SMTP_FROM_NAME="Entegratör Yazılımı"
SMTP_ENCRYPTION=tls

INVOICE_SERIES=OMR
INVOICE_START_NO=1
INVOICE_AUTO_CREATE=false

FEED_CACHE_HOURS=4

LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15
API_RATE_LIMIT_PER_SECOND=10
ENV;

                file_put_contents(__DIR__ . '/.env', $envContent);

                // Storage klasörleri oluştur ve izinleri ayarla
                $directories = [
                    'storage/logs', 
                    'storage/cache', 
                    'storage/feeds', 
                    'storage/invoices', 
                    'storage/backups', 
                    'storage/labels',
                    'public/uploads',
                    'public/uploads/branding',
                    'public/uploads/products',
                    'public/uploads/invoices'
                ];
                
                foreach ($directories as $dir) {
                    $fullPath = __DIR__ . '/' . $dir;
                    if (!is_dir($fullPath)) {
                        @mkdir($fullPath, 0755, true);
                    }
                    @chmod($fullPath, 0755);
                }
                
                // Public klasörü ve tüm alt klasörlerine 755 izni ver
                function setPermissionsRecursive($path) {
                    if (is_dir($path)) {
                        @chmod($path, 0755);
                        $items = @scandir($path);
                        if ($items !== false) {
                            foreach ($items as $item) {
                                if ($item !== '.' && $item !== '..') {
                                    setPermissionsRecursive($path . '/' . $item);
                                }
                            }
                        }
                    } else if (is_file($path)) {
                        @chmod($path, 0644);
                    }
                }
                
                setPermissionsRecursive(__DIR__ . '/public');
                setPermissionsRecursive(__DIR__ . '/storage');

                // Lock dosyası oluştur (yeniden kurulum engeli)
                file_put_contents(__DIR__ . '/installer_done.lock', date('Y-m-d H:i:s') . ' — Entegratör Yazılımı Installer');

                echo json_encode([
                    'success' => true,
                    'message' => 'Kurulum başarıyla tamamlandı!',
                    'encryption_key' => $encryptionKey,
                    'demo_data_loaded' => $installDemoData,
                    'migrations_applied' => $migrationCount,
                ]);
            } catch (\Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Kurulum hatası: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Site URL otomatik tespit
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$detectedUrl = rtrim($protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . installer_base_path(), '/');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entegratör Yazılımı — Kurulum Sihirbazı</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #2563eb; 
            --primary-dark: #1d4ed8; 
            --primary-light: #60a5fa;
            --success: #10b981; 
            --danger: #ef4444; 
            --warning: #f59e0b; 
            --bg: #f8fafc; 
            --card: #ffffff; 
            --border: #e2e8f0; 
            --text: #0f172a; 
            --text-muted: #64748b; 
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 50%, #e0f2fe 100%);
            color: var(--text); 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            padding: 20px;
        }

        .installer { 
            background: var(--card); 
            border-radius: 8px; 
            padding: 48px; 
            width: 680px; 
            max-width: 100%; 
            border: 1px solid rgba(255,255,255,.2);
        }
        
        .installer-header { 
            text-align: center; 
            margin-bottom: 40px; 
        }
        .installer-header .logo { 
            width: min(360px, 88%); 
            height: 82px; 
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .installer-header .logo img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .installer-header h1 { 
            font-size: 28px; 
            font-weight: 700; 
            color: var(--text);
            margin-bottom: 8px;
        }
        .installer-header p { 
            color: var(--text-muted); 
            font-size: 14px; 
        }

        .steps { 
            display: flex; 
            justify-content: center; 
            gap: 8px; 
            margin-bottom: 40px; 
        }
        .step-dot { 
            width: 40px; 
            height: 8px; 
            border-radius: 4px; 
            background: var(--border); 
            transition: all .3s ease;
        }
        .step-dot.active { 
            background: var(--primary); 
            width: 56px;
        }
        .step-dot.done { 
            background: var(--success); 
        }

        .step-content { 
            display: none; 
            animation: fadeIn .4s ease;
        }
        .step-content.active { 
            display: block; 
        }
        .step-content h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group { 
            margin-bottom: 20px; 
        }
        .form-label { 
            display: block; 
            font-size: 13px; 
            font-weight: 600; 
            color: var(--text); 
            margin-bottom: 8px;
        }
        .form-input, .form-select { 
            width: 100%; 
            padding: 12px 16px; 
            background: var(--bg); 
            border: 2px solid var(--border); 
            border-radius: 6px; 
            color: var(--text); 
            font-family: inherit; 
            font-size: 14px;
            transition: all .2s;
        }
        .form-input:focus, .form-select:focus { 
            outline: none; 
            border-color: var(--primary); 
            background: #fff;
        }
        .form-row { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 16px; 
        }
        .demo-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            cursor: pointer;
            transition: border-color .2s, background .2s;
        }
        .demo-option:hover {
            border-color: var(--primary);
            background: #fff;
        }
        .demo-option input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--primary);
            flex: 0 0 auto;
        }
        .demo-option strong {
            display: block;
            font-size: 14px;
            color: var(--text);
            margin-bottom: 3px;
        }
        .demo-option small {
            display: block;
            color: var(--text-muted);
            line-height: 1.45;
        }

        .btn { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            gap: 8px; 
            padding: 14px 28px; 
            border: none; 
            border-radius: 6px; 
            font-weight: 600; 
            font-size: 15px; 
            cursor: pointer; 
            transition: all .2s;
            text-decoration: none;
        }
        .btn-primary { 
            background: linear-gradient(135deg, var(--primary), var(--primary-dark)); 
            color: #fff;
        }
        .btn-primary:hover { 
            opacity: .9;
        }
        .btn-primary:disabled { 
            opacity: .5; 
            pointer-events: none; 
        }
        .btn-secondary { 
            background: var(--bg); 
            color: var(--text);
            border: 2px solid var(--border);
        }
        .btn-secondary:hover {
            background: #fff;
            border-color: var(--primary);
        }
        .btn-success { 
            background: linear-gradient(135deg, var(--success), #059669); 
            color: #fff;
        }
        .btn-success:hover {
            opacity: .9;
        }
        .btn-group { 
            display: flex; 
            justify-content: space-between; 
            margin-top: 32px; 
        }

        .check-item { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 12px 16px; 
            border-radius: 6px; 
            margin-bottom: 8px; 
            font-size: 14px;
            border: 2px solid transparent;
        }
        .check-item .icon { 
            font-size: 20px; 
            width: 24px; 
            text-align: center; 
        }
        .check-item .value { 
            margin-left: auto; 
            color: var(--text-muted); 
            font-size: 13px;
            font-weight: 500;
        }
        .check-item.ok { 
            background: #f0fdf4; 
            border-color: #bbf7d0;
        }
        .check-item.fail { 
            background: #fef2f2; 
            border-color: #fecaca;
        }

        .alert { 
            padding: 14px 18px; 
            border-radius: 6px; 
            margin-bottom: 20px; 
            font-size: 14px;
            font-weight: 500;
            border: 2px solid;
        }
        .alert-success { 
            background: #f0fdf4; 
            border-color: #86efac; 
            color: #166534; 
        }
        .alert-danger { 
            background: #fef2f2; 
            border-color: #fca5a5; 
            color: #991b1b; 
        }

        .footer { 
            text-align: center; 
            margin-top: 32px; 
            color: var(--text-muted); 
            font-size: 12px; 
        }
        .footer a { 
            color: var(--primary); 
            text-decoration: none;
            font-weight: 600;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        
        .success-box { 
            text-align: center; 
            padding: 20px 0; 
        }
        .success-box .big-icon { 
            font-size: 80px; 
            margin-bottom: 20px;
            animation: bounce 1s ease;
        }
        .success-box h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
        }
        .success-box p {
            color: var(--text-muted);
            font-size: 15px;
            margin-bottom: 20px;
        }
        
        .key-box { 
            background: #fffbeb; 
            border: 2px solid #fde68a; 
            border-radius: 6px; 
            padding: 16px; 
            font-family: 'Courier New', monospace; 
            font-size: 13px; 
            word-break: break-all; 
            margin: 16px 0; 
            color: #92400e;
            font-weight: 600;
        }
        .key-box-label {
            font-size: 13px;
            color: var(--warning);
            margin-bottom: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .loading { 
            display: inline-block; 
            width: 18px; 
            height: 18px; 
            border: 3px solid var(--border); 
            border-top-color: var(--primary); 
            border-radius: 50%; 
            animation: spin .6s linear infinite; 
        }
        
        @keyframes spin { 
            to { transform: rotate(360deg); } 
        }
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(12px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        @media (max-width: 640px) {
            .installer { padding: 32px 24px; }
            .form-row { grid-template-columns: 1fr; }
            .installer-header h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
<div class="installer">
    <div class="installer-header">
        <div class="logo"><img src="public/assets/img/branding/entegrator/entegrator-logo.png" alt="Entegratör Yazılımı"></div>
        <h1>Entegratör Yazılımı Kurulum Sihirbazı</h1>
        <p>© 2025 OziyWeb — Tüm Hakları Saklıdır</p>
    </div>

    <div class="steps">
        <div class="step-dot active" data-step="1"></div>
        <div class="step-dot" data-step="2"></div>
        <div class="step-dot" data-step="3"></div>
        <div class="step-dot" data-step="4"></div>
        <div class="step-dot" data-step="5"></div>
    </div>

    <!-- Step 1: Requirements -->
    <div class="step-content active" id="step1">
        <h3 style="margin-bottom:16px">1️⃣ Sistem Gereksinimleri</h3>
        <div id="requirementsList"><p style="color:var(--text-muted)"><span class="loading"></span> Kontrol ediliyor...</p></div>
        <div class="btn-group"><div></div><button class="btn btn-primary" id="btnStep1Next" disabled onclick="goStep(2)">Devam →</button></div>
    </div>

    <!-- Step 2: Database -->
    <div class="step-content" id="step2">
        <h3 style="margin-bottom:16px">2️⃣ Veritabanı Bağlantısı</h3>
        <div id="dbAlert"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Host</label><input type="text" id="dbHost" class="form-input" value="localhost"></div>
            <div class="form-group"><label class="form-label">Port</label><input type="text" id="dbPort" class="form-input" value="3306"></div>
        </div>
        <div class="form-group"><label class="form-label">Veritabanı Adı</label><input type="text" id="dbName" class="form-input" value="entegrator_yazilimi"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Kullanıcı</label><input type="text" id="dbUser" class="form-input" value="root"></div>
            <div class="form-group"><label class="form-label">Şifre</label><input type="password" id="dbPass" class="form-input" value=""></div>
        </div>
        <button class="btn btn-secondary" onclick="testDb()">🔌 Bağlantıyı Test Et</button>
        <div class="btn-group"><button class="btn btn-secondary" onclick="goStep(1)">← Geri</button><button class="btn btn-primary" id="btnStep2Next" disabled onclick="goStep(3)">Devam →</button></div>
    </div>

    <!-- Step 3: Site Settings -->
    <div class="step-content" id="step3">
        <h3 style="margin-bottom:16px">3️⃣ Site Ayarları</h3>
        <div class="form-group"><label class="form-label">Site URL</label><input type="url" id="siteUrl" class="form-input" value="<?= htmlspecialchars($detectedUrl) ?>"></div>
        <div class="form-group"><label class="form-label">Zaman Dilimi</label>
            <select id="timezone" class="form-select">
                <option value="Europe/Istanbul" selected>Europe/Istanbul (Türkiye)</option>
                <option value="UTC">UTC</option>
                <option value="Europe/London">Europe/London</option>
                <option value="Europe/Berlin">Europe/Berlin</option>
            </select>
        </div>
        <div class="btn-group"><button class="btn btn-secondary" onclick="goStep(2)">← Geri</button><button class="btn btn-primary" onclick="goStep(4)">Devam →</button></div>
    </div>

    <!-- Step 4: Admin Account -->
    <div class="step-content" id="step4">
        <h3 style="margin-bottom:16px">4️⃣ Admin Hesabı</h3>
        <div class="form-group"><label class="form-label">Ad Soyad</label><input type="text" id="adminName" class="form-input" value="Admin"></div>
        <div class="form-group"><label class="form-label">E-posta</label><input type="email" id="adminEmail" class="form-input" placeholder="admin@oziyweb.com"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Şifre (min 8 karakter)</label><input type="password" id="adminPass" class="form-input" placeholder="••••••••"></div>
            <div class="form-group"><label class="form-label">Şifre Tekrar</label><input type="password" id="adminPass2" class="form-input" placeholder="••••••••"></div>
        </div>
        <div class="form-group">
            <label class="demo-option" for="installDemoData">
                <input type="checkbox" id="installDemoData" value="1">
                <span>
                    <strong>Demo verileri yükle</strong>
                    <small>Örnek ürün, müşteri, sipariş ve finans kayıtları eklenir. İşaretlenmezse sistem boş ve temiz kurulur.</small>
                </span>
            </label>
        </div>
        <div id="installAlert"></div>
        <div class="btn-group"><button class="btn btn-secondary" onclick="goStep(3)">← Geri</button><button class="btn btn-primary" onclick="runInstall()">🚀 Kurulumu Başlat</button></div>
    </div>

    <!-- Step 5: Done -->
    <div class="step-content" id="step5">
        <div class="success-box">
            <div class="big-icon">🎉</div>
            <h2>Kurulum Tamamlandı!</h2>
            <p>Entegratör Yazılımı başarıyla kuruldu ve kullanıma hazır.</p>
            <div id="encKeyBox"></div>
            <a href="<?= htmlspecialchars(installer_url('/login'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-success" style="margin-top:20px">🔐 Panele Giriş Yap</a>
        </div>
    </div>

    <div class="footer">© <?= date('Y') ?> OziyWeb — <a href="https://www.oziyweb.com.tr" target="_blank">www.oziyweb.com.tr</a> — Entegratör Yazılımı</div>
</div>

<script>
function goStep(n) {
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    document.getElementById('step' + n).classList.add('active');
    document.querySelectorAll('.step-dot').forEach(dot => {
        const s = parseInt(dot.dataset.step);
        dot.classList.toggle('active', s === n);
        dot.classList.toggle('done', s < n);
    });
}

// Step 1: Requirements check
(async function() {
    const fd = new FormData(); fd.append('action', 'check_requirements');
    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    let html = '';
    for (const [key, check] of Object.entries(data.checks)) {
        html += `<div class="check-item ${check.ok ? 'ok' : 'fail'}"><span class="icon">${check.ok ? '✅' : '❌'}</span><span>${check.label}</span><span class="value">${check.value}</span></div>`;
    }
    document.getElementById('requirementsList').innerHTML = html;
    if (data.all_ok) document.getElementById('btnStep1Next').disabled = false;
})();

// Step 2: Test DB
async function testDb() {
    const fd = new FormData();
    fd.append('action', 'test_db');
    fd.append('db_host', document.getElementById('dbHost').value);
    fd.append('db_port', document.getElementById('dbPort').value);
    fd.append('db_name', document.getElementById('dbName').value);
    fd.append('db_user', document.getElementById('dbUser').value);
    fd.append('db_pass', document.getElementById('dbPass').value);
    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    document.getElementById('dbAlert').innerHTML = `<div class="alert ${data.success ? 'alert-success' : 'alert-danger'}">${data.message}</div>`;
    if (data.success) document.getElementById('btnStep2Next').disabled = false;
}

// Step 4: Install
async function runInstall() {
    const alertEl = document.getElementById('installAlert');
    const pass = document.getElementById('adminPass').value;
    const pass2 = document.getElementById('adminPass2').value;
    if (pass.length < 8) { alertEl.innerHTML = '<div class="alert alert-danger">Şifre en az 8 karakter olmalı</div>'; return; }
    if (pass !== pass2) { alertEl.innerHTML = '<div class="alert alert-danger">Şifreler eşleşmiyor</div>'; return; }

    // Butonu devre dışı bırak ve loading göster
    const installBtn = document.querySelector('#step4 .btn-primary');
    const originalBtnText = installBtn.innerHTML;
    installBtn.disabled = true;
    installBtn.innerHTML = '<span class="loading"></span> Kuruluyor...';
    alertEl.innerHTML = '<div class="alert" style="background:#f0f9ff;border-color:#93c5fd;color:#1e40af">⏳ Veritabanı tabloları oluşturuluyor, lütfen bekleyin...</div>';

    try {
        const fd = new FormData();
        fd.append('action', 'install');
        fd.append('db_host', document.getElementById('dbHost').value);
        fd.append('db_port', document.getElementById('dbPort').value);
        fd.append('db_name', document.getElementById('dbName').value);
        fd.append('db_user', document.getElementById('dbUser').value);
        fd.append('db_pass', document.getElementById('dbPass').value);
        fd.append('site_url', document.getElementById('siteUrl').value);
        fd.append('timezone', document.getElementById('timezone').value);
        fd.append('admin_name', document.getElementById('adminName').value);
        fd.append('admin_email', document.getElementById('adminEmail').value);
        fd.append('admin_password', pass);
        fd.append('install_demo_data', document.getElementById('installDemoData').checked ? '1' : '0');

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        
        // Yanıt durumunu kontrol et
        if (!res.ok) {
            const errorText = await res.text();
            alertEl.innerHTML = `<div class="alert alert-danger">Sunucu hatası (${res.status}): ${errorText.substring(0, 500)}</div>`;
            installBtn.disabled = false;
            installBtn.innerHTML = originalBtnText;
            return;
        }

        const responseText = await res.text();
        
        // JSON parse etmeyi dene
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseErr) {
            alertEl.innerHTML = `<div class="alert alert-danger">Sunucu geçersiz yanıt döndü (JSON parse hatası):<br><pre style="max-height:200px;overflow:auto;font-size:12px;margin-top:8px;white-space:pre-wrap">${responseText.substring(0, 1000)}</pre></div>`;
            installBtn.disabled = false;
            installBtn.innerHTML = originalBtnText;
            return;
        }

        if (data.success) {
            document.getElementById('encKeyBox').innerHTML = `<p style="font-size:12px;color:var(--warning);margin-bottom:6px">⚠️ Şifreleme anahtarınızı güvenli bir yere kaydedin:</p><div class="key-box">${data.encryption_key}</div>`;
            goStep(5);
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger">${data.message || 'Bilinmeyen hata oluştu'}</div>`;
            installBtn.disabled = false;
            installBtn.innerHTML = originalBtnText;
        }
    } catch (err) {
        alertEl.innerHTML = `<div class="alert alert-danger">Bağlantı hatası: ${err.message}</div>`;
        installBtn.disabled = false;
        installBtn.innerHTML = originalBtnText;
        console.error('Install error:', err);
    }
}
</script>
</body>
</html>
