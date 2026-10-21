<?php
/**
 * Mevcut marketplace ürünlerinin product_name ve main_image alanlarını
 * API'den tekrar çekerek günceller.
 *
 * Kullanım: php tools/update_mp_images.php [marketplace_slug]
 * Örnek:    php tools/update_mp_images.php n11
 */
declare(strict_types=1);

// CLI-only bootstrap (router dispatch etmez)
define('BASE_PATH', dirname(__DIR__));
define('CLI_MODE', true);

// .env yükle
(function (): void {
    $envFile = BASE_PATH . '/.env';
    if (!file_exists($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
})();

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) return $default;
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

require BASE_PATH . '/vendor/autoload.php';
$config    = require BASE_PATH . '/config/app.php';
$container = new \App\Core\Container($config);
$db  = $container->get(\App\Core\Database::class);
$pdo = $db->getConnection();

$slug = $argv[1] ?? 'n11';
echo "=== Marketplace ürün resim/isim güncelleme: {$slug} ===\n";

// Marketplace ID bul
$stmt = $pdo->prepare('SELECT id FROM marketplaces WHERE slug = ?');
$stmt->execute([$slug]);
$mp = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$mp) {
    echo "Marketplace bulunamadı: {$slug}\n";
    exit(1);
}
$mpId = (int) $mp['id'];

// Uygun servisi al
if ($slug === 'n11') {
    $service = $container->get(\App\Services\Marketplace\N11Service::class);
} elseif ($slug === 'trendyol') {
    $service = $container->get(\App\Services\Marketplace\TrendyolService::class);
} else {
    echo "Desteklenmeyen marketplace: {$slug}\n";
    exit(1);
}

// Ürünleri çek ve güncelle
$page = 0;
$totalPages = 1;
$updated = 0;
$total = 0;

$updateStmt = $pdo->prepare(
    'UPDATE marketplace_products SET product_name = ?, main_image = ? WHERE marketplace_id = ? AND marketplace_product_id = ?'
);

while ($page < $totalPages) {
    $result = $service->importProducts($page, 250);
    $totalPages = $result['totalPages'] ?? 1;

    foreach ($result['products'] ?? [] as $prod) {
        $total++;
        $mpProdId = $prod['marketplace_product_id'] ?? '';
        $name     = $prod['name'] ?? '';
        $image    = '';

        if (!empty($prod['images']) && is_array($prod['images'])) {
            $image = $prod['images'][0]['url'] ?? '';
        }

        if ($mpProdId && ($name || $image)) {
            $updateStmt->execute([$name, $image, $mpId, $mpProdId]);
            if ($updateStmt->rowCount() > 0) {
                $updated++;
            }
        }
    }

    $page++;
    echo "Sayfa {$page}/{$totalPages} — toplam: {$total}, güncellenen: {$updated}\r";
}

echo "\n=== Tamamlandı! Toplam: {$total}, Güncellenen: {$updated} ===\n";
