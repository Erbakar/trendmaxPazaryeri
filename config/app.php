<?php
/**
 * OzyConnect Panel — Uygulama Yapılandırması
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

return [
    // ─── Uygulama ──────────────────────────────────────────
    'app_name'    => env('APP_NAME', 'Entegratör Yazılımı'),
    'version'     => '1.0.0',
    'developer'   => 'OziyWeb',
    'website'     => 'https://www.oziyweb.com.tr',
    'copyright'   => '© 2025 OziyWeb',
    'build_date'  => '2025-01-01',

    'env'         => env('APP_ENV', 'production'),
    'debug'       => env('APP_DEBUG', false),
    'url'         => function_exists('app_base_url') ? app_base_url() : env('APP_URL', 'http://localhost'),
    'timezone'    => env('APP_TIMEZONE', 'Europe/Istanbul'),

    // ─── Oturum ────────────────────────────────────────────
    'session' => [
        'lifetime' => (int) env('SESSION_LIFETIME', 7200),
        'name'     => env('SESSION_NAME', 'EntegratorYazilimi_session'),
    ],

    // ─── Güvenlik ──────────────────────────────────────────
    'encryption_key' => env('ENCRYPTION_KEY', ''),

    // ─── Loglama ───────────────────────────────────────────
    'log' => [
        'level'          => env('LOG_LEVEL', 'DEBUG'),
        'retention_days' => (int) env('LOG_RETENTION_DAYS', 7),
    ],

    // ─── Rate Limit ────────────────────────────────────────
    'rate_limit' => [
        'login_max_attempts'     => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_lockout_minutes'  => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
        'api_per_second'         => (int) env('API_RATE_LIMIT_PER_SECOND', 10),
    ],

    // ─── Feed ──────────────────────────────────────────────
    'feed' => [
        'cache_hours' => (int) env('FEED_CACHE_HOURS', 4),
    ],

    // ─── Paths ─────────────────────────────────────────────
    'paths' => [
        'storage'  => BASE_PATH . '/storage',
        'logs'     => BASE_PATH . '/storage/logs',
        'cache'    => BASE_PATH . '/storage/cache',
        'feeds'    => BASE_PATH . '/storage/feeds',
        'invoices' => BASE_PATH . '/storage/invoices',
        'views'    => BASE_PATH . '/views',
    ],
];
