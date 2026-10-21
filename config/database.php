<?php
/**
 * OzyConnect Panel — Veritabanı Yapılandırması
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
    'driver'  => 'mysql',
    'host'    => env('DB_HOST', 'localhost'),
    'port'    => (int) env('DB_PORT', 3306),
    'name'    => env('DB_NAME', env('DB_DATABASE', 'entegrator_yazilimi')),
    'user'    => env('DB_USER', env('DB_USERNAME', 'root')),
    'pass'    => env('DB_PASS', env('DB_PASSWORD', '')),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',

    'options' => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
    ],
];
