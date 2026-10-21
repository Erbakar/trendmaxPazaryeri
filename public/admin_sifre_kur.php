<?php
/**
 * TEK SEFER — Ana platform admin şifresini veritabanında günceller.
 * https://SITE/public/admin_sifre_kur.php?anahtar=TrendmaxSifre20261015
 * (docroot public/ ise: https://SITE/admin_sifre_kur.php?anahtar=...)
 * Başarılı olunca bu dosyayı SİLİN.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$beklenenAnahtar = 'TrendmaxSifre20261015';
if (($_GET['anahtar'] ?? '') !== $beklenenAnahtar) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$yeniSifre = 'T124451**Max';
$email = 'admin@trendmaxtr.com';
$base = dirname(__DIR__);
$envFile = $base . '/.env';

if (!is_readable($envFile)) {
    echo ".env okunamadi\n";
    exit(1);
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v, " \t\"'");
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

if ($name === '' || $user === '') {
    echo "DB ayarlari eksik\n";
    exit(1);
}

$hash = password_hash($yeniSifre, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $u1 = $pdo->prepare('UPDATE admin_users SET password_hash = :h, updated_at = NOW() WHERE email = :e LIMIT 1');
    $u1->execute(['h' => $hash, 'e' => $email]);
    $u2 = $pdo->prepare('UPDATE users SET password_hash = :h, updated_at = NOW() WHERE email = :e LIMIT 1');
    $u2->execute(['h' => $hash, 'e' => $email]);
    echo "OK admin_users: {$u1->rowCount()} satir, users: {$u2->rowCount()} satir\n";
    echo "Giris: {$email} / {$yeniSifre}\n";
    echo "Bu dosyayi (public/admin_sifre_kur.php) simdi silin.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'HATA: ' . $e->getMessage() . "\n";
    echo "Alternatif: phpMyAdmin ile database/migrations/admin_ana_sifre_20261015.sql calistirin.\n";
    exit(1);
}
