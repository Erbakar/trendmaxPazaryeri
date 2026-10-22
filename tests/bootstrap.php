<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $default;
}
