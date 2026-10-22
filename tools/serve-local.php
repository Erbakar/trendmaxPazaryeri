<?php
/** Router for PHP's development server. Serve public assets only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$relative = preg_replace('~^/(?:public/)?~', '', $path);
$file = realpath($root . '/public/' . $relative);
$public = realpath($root . '/public') . DIRECTORY_SEPARATOR;
if ($file !== false && str_starts_with($file, $public) && is_file($file)
    && !preg_match('~(?:^|/)\.|\.(?:php\d*|phtml|phar)$~i', $relative)) {
    $types = ['css'=>'text/css', 'js'=>'application/javascript', 'svg'=>'image/svg+xml', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp', 'gif'=>'image/gif', 'ico'=>'image/x-icon', 'woff'=>'font/woff', 'woff2'=>'font/woff2', 'ttf'=>'font/ttf', 'zip'=>'application/zip'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return;
}
// Keep SCRIPT_NAME stable for extensionless routes and nested URLs.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/public/index.php';
