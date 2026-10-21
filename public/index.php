<?php
/**
 * OzyConnect Panel — Front Controller
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

// ─── Zaman dilimi ──────────────────────────────────────
date_default_timezone_set('Europe/Istanbul');

// ─── Hata raporlama ────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ─── Sabitler ──────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);
define('APP_START', microtime(true));

// ─── .env Yükleyici ────────────────────────────────────
(function (): void {
    $envFile = BASE_PATH . '/.env';
    if (!file_exists($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        // Yorum satırlarını atla
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Tırnak temizle
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Ortam değişkenine yaz
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
})();

// ─── env() yardımcı fonksiyon ──────────────────────────
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        // Boolean dönüşümler
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $scriptBase = null;
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptName !== '') {
            $scriptDir = str_replace('\\', '/', dirname($scriptName));

            if ($scriptDir === '.' || $scriptDir === '/') {
                $scriptDir = '';
            }

            if ($scriptDir === '/public') {
                $scriptDir = '';
            } elseif (str_ends_with($scriptDir, '/public')) {
                $scriptDir = substr($scriptDir, 0, -7);
            }

            $scriptBase = $scriptDir;
        }

        $path = $scriptBase ?? '';
        $appUrl = (string) env('APP_URL', '');

        if ($scriptBase === null && ($path === '' || $path === '/') && $appUrl !== '') {
            $parts = parse_url($appUrl);
            if (is_array($parts) && isset($parts['path'])) {
                $path = (string) $parts['path'];
            }
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '' : $path;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = app_base_path();
        $path = '/' . ltrim($path, '/');

        if ($path === '/') {
            return $base === '' ? '/' : $base;
        }

        return $base . $path;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        $origin = '';
        $appUrl = (string) env('APP_URL', '');

        if ($appUrl !== '') {
            $parts = parse_url($appUrl);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (isset($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
            }
        }

        if ($origin === '') {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
            $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        return rtrim($origin . app_base_path(), '/');
    }
}

if (!function_exists('public_url')) {
    function public_url(string $path = ''): string
    {
        return url('/public/' . ltrim($path, '/'));
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'public/')) {
            return url('/' . $path);
        }

        return public_url('assets/' . $path);
    }
}

// ─── Pazaryeri / feed görsel adresleri ─────────────────
// Pazaryerleri ve katalog servisleri görseli kendi sunucularından çeker: adres
// mutlak ve dışarıdan erişilebilir olmalı. Aşağıdaki yardımcılar app_base_url()
// davranışını DEĞİŞTİRMEZ; yalnız görsel/feed adresi kurarken kullanılır.

if (!function_exists('app_url_is_public')) {
    /**
     * Adres internetten erişilebilir bir http(s) adresi mi?
     * localhost, *.local/*.localhost/*.test, tek etiketli ağ adları ve özel/ayrılmış IP'ler değildir.
     */
    function app_url_is_public(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.internal', '.lan', '.home.arpa'] as $localSuffix) {
            if (str_ends_with($host, $localSuffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }
}

if (!function_exists('app_asset_base_url')) {
    /**
     * Görsel/feed adreslerinin kökü (sonda '/' yok).
     *
     * Web: app_base_url(). O yerel kalmışsa (ör. kurulumdan kalan APP_URL=http://localhost)
     * ve istek canlı alan adından geliyorsa isteğin adresi kullanılır.
     * CLI (cron/kuyruk): app_base_path() betik yolundan türediği için yanlış alt dizin
     * üretir; doğrudan .env APP_URL kullanılır.
     */
    function app_asset_base_url(): string
    {
        $normalize = static function (string $value): string {
            $value = rtrim(trim($value), '/');
            if ($value === '') {
                return '';
            }
            if (str_starts_with($value, '//')) {
                $value = 'https:' . $value;
            } elseif (preg_match('~^[a-z][a-z0-9+.-]*://~i', $value) !== 1) {
                $value = 'https://' . $value;
                if (!app_url_is_public($value)) {
                    $value = 'http://' . substr($value, 8);
                }
            }

            $parts = parse_url($value);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

            return ($scheme === 'http' || $scheme === 'https') && !empty($parts['host']) ? $value : '';
        };

        $appUrl = $normalize((string) env('APP_URL', ''));

        if (PHP_SAPI === 'cli' || !function_exists('app_base_url')) {
            return $appUrl !== '' ? $appUrl : 'http://localhost';
        }

        $base = $normalize(app_base_url());
        if ($base !== '' && app_url_is_public($base)) {
            return $base;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (preg_match('/^(?:[a-z0-9-]+\.)*[a-z0-9-]+(?::\d{1,5})?$/i', $host) === 1) {
            $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
            $requestBase = rtrim(($https ? 'https' : 'http') . '://' . $host . app_base_path(), '/');
            if (app_url_is_public($requestBase)) {
                return $requestBase;
            }
        }

        if ($base !== '') {
            return $base;
        }

        return $appUrl !== '' ? $appUrl : 'http://localhost';
    }
}

if (!function_exists('app_asset_url')) {
    /**
     * Görsel adresini pazaryerinin/feed okuyucusunun çekebileceği mutlak adrese çevirir.
     *
     * - Göreli yol ('uploads/…', '/public/uploads/…', '<alt-dizin>/public/…') → kök + '/public/…'
     *   (yüklemeler public/uploads altında servis edilir).
     * - Herkese açık mutlak adres korunur (kökle aynı alan adındaysa ve kök https ise https'e yükseltilir).
     * - localhost/özel ağ mutlak adresi: kök canlıysa ve yol public/ ya da uploads/ içeriyorsa köke
     *   taşınır; kök de yerelse (geliştirme) ya da yol tanınmıyorsa aynen döner (yayın doğrulaması raporlar).
     */
    function app_asset_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $base = app_asset_base_url();

        if (preg_match('~^https?://~i', $url) === 1) {
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return $url;
            }

            if (app_url_is_public($url)) {
                $baseParts = parse_url($base);
                $upgrade = is_array($baseParts)
                    && strtolower((string) ($baseParts['scheme'] ?? '')) === 'https'
                    && strtolower((string) $parts['scheme']) === 'http'
                    && !isset($parts['port'])
                    && strcasecmp((string) $parts['host'], (string) ($baseParts['host'] ?? '')) === 0;

                return $upgrade ? 'https://' . substr($url, 7) : $url;
            }

            if (!app_url_is_public($base)) {
                return $url;
            }

            $path = (string) ($parts['path'] ?? '');
            if (preg_match('~(?:^|/)public/(.+)$~', $path, $match) === 1) {
                $relative = 'public/' . $match[1];
            } elseif (preg_match('~(?:^|/)(uploads/.+)$~', $path, $match) === 1) {
                $relative = 'public/' . $match[1];
            } else {
                return $url;
            }

            return $base . '/' . $relative . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) {
            return $url;
        }

        $suffix = '';
        $cut = strcspn($url, '?#');
        if ($cut < strlen($url)) {
            $suffix = substr($url, $cut);
            $url = substr($url, 0, $cut);
        }

        $path = str_replace('\\', '/', $url);
        while (preg_match('~^(?:/+|\./|\.\./)~', $path) === 1) {
            $path = (string) preg_replace('~^(?:/+|\./|\.\./)~', '', $path);
        }
        if ($path === '') {
            return $base . $suffix;
        }

        $basePath = trim((string) parse_url($base, PHP_URL_PATH), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath . '/public/')) {
            $path = substr($path, strlen($basePath) + 1);
        }

        if (str_starts_with($path, 'public/')) {
            $relative = $path;
        } elseif (str_starts_with($path, 'uploads/')) {
            $relative = 'public/' . $path;
        } elseif (preg_match('~(?:^|/)(public/uploads/.+)$~', $path, $match) === 1) {
            $relative = $match[1];
        } else {
            $relative = $path;
        }

        return $base . '/' . $relative . $suffix;
    }
}

if (!function_exists('app_rewrite_legacy_path')) {
    function app_rewrite_legacy_path(string $value): string
    {
        return str_replace('/OzyConnect', app_base_path(), $value);
    }
}

if (!function_exists('app_rewrite_output')) {
    function app_rewrite_output(string $content): string
    {
        return app_rewrite_legacy_path($content);
    }
}

if (!function_exists('brand')) {
    /**
     * Faz 8 — Marka değeri (global Setting group='branding'). DI dışı bağlamlarda da (error page,
     * cron, e-posta) çalışır. Setting/DB erişilemezse veya boşsa $default'a düşer → SIFIR regresyon.
     */
    function brand(string $key, string $default = 'Entegratör Yazılımı'): string
    {
        static $model = null;
        if ($model === null) {
            try {
                $model = isset($GLOBALS['container'])
                    ? $GLOBALS['container']->get(\App\Models\Setting::class)
                    : false;
            } catch (\Throwable) {
                $model = false;
            }
        }
        if ($model === false) {
            return $default;
        }
        try {
            $v = trim((string) $model->get($key, $default));
            return $v !== '' ? $v : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}

// ─── Admin (Modernize) sunum yardımcıları: view'lardan ÖNCE yüklenir ───
if (is_file(BASE_PATH . '/app/ui_helpers.php')) {
    require_once BASE_PATH . '/app/ui_helpers.php';
}

// ─── Installer kontrolü ───────────────────────────────
if (!file_exists(BASE_PATH . '/.env') && file_exists(BASE_PATH . '/installer.php')) {
    header('Location: ' . url('/installer.php'));
    exit;
}

// ─── Composer autoload ─────────────────────────────────
$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Composer yoksa basit PSR-4 autoloader
    spl_autoload_register(function (string $class): void {
        // App\ namespace → app/ dizini
        if (str_starts_with($class, 'App\\')) {
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4));
            $file = BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relative . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

// ─── Yapılandırma yükle ────────────────────────────────
$config = require BASE_PATH . '/config/app.php';

// ─── Debug modu ────────────────────────────────────────
if ($config['debug'] === true || $config['debug'] === 'true') {
    ini_set('display_errors', '1');
}

// ─── Zaman dilimi (config'den) ─────────────────────────
date_default_timezone_set($config['timezone']);

// ─── Session başlat ────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $sessionName = $config['session']['name'];
    $defaultLifetime = $config['session']['lifetime'];

    // "Beni hatırla" cookie'si varsa ömrünü korumak için kontrol et
    $rememberLifetime = 60 * 60 * 24 * 30; // 30 gün
    $hasRememberCookie = isset($_COOKIE[$sessionName]);

    // gc_maxlifetime'ı uzun tut ki sunucu tarafında session silinmesin
    ini_set('session.gc_maxlifetime', (string) max($defaultLifetime, $rememberLifetime));

    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => $defaultLifetime,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();

    // "Beni hatırla" aktifse cookie ömrünü her istekte yenile (sliding expiration)
    if ($hasRememberCookie && isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        $params = session_get_cookie_params();
        // Cookie expire'ını korumak için yeniden set et
        setcookie(
            $sessionName,
            session_id(),
            time() + $rememberLifetime,
            $params['path'],
            $params['domain'] ?? '',
            $params['secure'],
            $params['httponly']
        );
    }
}

// Güvenlik: exception trace'lerine fonksiyon argümanlarını KOYMA —
// şifre/token gibi hassas değerler getTrace()/getTraceAsString() ile
// loga ve debug ekranına düşmesin (ör. PasswordResetService::resetPassword).
ini_set('zend.exception_ignore_args', '1');

// ─── Global exception handler ──────────────────────────
set_exception_handler(function (Throwable $e) use ($config): void {
    $errorId = bin2hex(random_bytes(8));

    // Tek loglama yolu — Container hazırsa Logger'dan geç (tenant etiketi, kanal, retention).
    // Container henüz oluşmadan fırlayan bootstrap hataları aşağıdaki dosya fallback'ine düşer.
    $loggedViaContainer = false;
    if (isset($GLOBALS['container']) && $GLOBALS['container'] instanceof App\Core\Container) {
        try {
            $GLOBALS['container']->get(App\Core\Logger::class)->exception($e, 'ExceptionHandler');
            $loggedViaContainer = true;
        } catch (\Throwable) {
            // Logger alınamazsa aşağıdaki dosya fallback'i devreye girer
        }
    }

    if (!$loggedViaContainer) {
        $logDir = $config['paths']['logs'];
        if (is_dir($logDir) && is_writable($logDir)) {
            $logLine = sprintf(
                "[%s] [CRITICAL] [ExceptionHandler] [ID:%s] %s in %s:%d\n%s\n\n",
                date('Y-m-d H:i:s'),
                $errorId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            file_put_contents(
                $logDir . '/' . date('Y-m-d') . '.log',
                $logLine,
                FILE_APPEND | LOCK_EX
            );
        }
    }

    http_response_code(500);

    if ($config['debug'] === true || $config['debug'] === 'true') {
        echo '<h1>Hata Oluştu</h1>';
        echo '<p><strong>Mesaj:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        echo '<p><strong>Dosya:</strong> ' . htmlspecialchars($e->getFile(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . ':' . $e->getLine() . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre>';
    } else {
        echo '<h1>Bir hata oluştu</h1>';
        echo '<p>Hata ID: <code>' . htmlspecialchars($errorId, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code></p>';
        echo '<p>Bu kodu sistem yöneticinize bildirin.</p>';
    }
    exit(1);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ─── DI Container Oluştur ve Rotala ───────────────────
try {
    $container = new App\Core\Container($config);
    $GLOBALS['container'] = $container;

    // CLI modunda (cron vb.) sadece container oluştur, dispatch yapma.
    // CLI/worker tenant bağlamını AÇIKÇA besler (Session yok).
    if (PHP_SAPI === 'cli') {
        return;
    }

    // ─── Tenant bağlamı (web): aktif mağazayı Session'dan TenantContext'e aktar ──
    // Giriş yapılmamışsa store_id yoktur; tenant-scoped sorgu fail-closed patlar.
    // (Login'in kullanıcı arama sorgusu tenant-öncesi global yoldur — bkz. AuthController.)
    $session = $container->get(App\Core\Session::class);
    $storeId = $session->storeId();
    if ($storeId !== null) {
        $container->get(App\Core\TenantContext::class)->setStoreId($storeId);
    }

    $router    = $container->get(App\Core\Router::class);

    // Rotaları yükle
    require BASE_PATH . '/app/routes.php';

    // İsteği işle
    $request = $container->get(App\Core\Request::class);
    $router->dispatch($request);
} catch (Throwable $e) {
    // exception handler zaten yakalayacak
    throw $e;
}
