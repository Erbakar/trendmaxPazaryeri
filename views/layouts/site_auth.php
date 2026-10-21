<?php
/**
 * OzyConnect — giriş ekranı düzeni (login / şifremi unuttum / şifre sıfırla).
 * Poppins + ozy-auth.css; nav/footer YOK — tam ekran bölünmüş kabuk. $content echo edilir.
 * marketing.css'in --blue değişkenleri kurumsal parçalar için korunur; --brand RGB kanalı ozy-auth.css için yazılır.
 */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$siteName = brand('site_name');
$brandColor = trim((string) brand('brand_color', '#2563eb'));
$pageTitle = trim((string) ($data['pageTitle'] ?? 'Giriş'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="app-base-path" content="<?= $e(function_exists('app_base_path') ? app_base_path() : '') ?>">
<meta name="csrf-token" content="<?= $e($data['_csrf'] ?? '') ?>">
<title><?= $e($pageTitle) ?> — <?= $e($siteName) ?></title>
<meta name="robots" content="noindex,nofollow">
<?php $fav = trim((string) brand('favicon_url', '')); if ($fav !== ''): ?><link rel="icon" href="<?= $e($fav) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="<?= $e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<link rel="stylesheet" href="<?= $e(asset('css/ozy-auth.css')) ?>?v=20260913">
<?php
$bcHex = strtolower(ltrim($brandColor, '#'));
if (preg_match('/^[0-9a-f]{6}$/', $bcHex)):
    $r = hexdec(substr($bcHex, 0, 2)); $g = hexdec(substr($bcHex, 2, 2)); $b = hexdec(substr($bcHex, 4, 2));
    $hx = static fn(int $c): string => str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT);
    $mix = static fn(int $c, float $f): string => $hx((int) round($c * $f));
    $blueDark   = '#' . $mix($r, .82) . $mix($g, .82) . $mix($b, .82);
    $blueDarker = '#' . $mix($r, .42) . $mix($g, .42) . $mix($b, .42);
?>
<style>:root{--brand:<?= $r ?> <?= $g ?> <?= $b ?>;--blue:#<?= $bcHex ?>;--blue-dark:<?= $blueDark ?>;--blue-darker:<?= $blueDarker ?>;--blue-rgb:<?= $r ?>,<?= $g ?>,<?= $b ?>}</style>
<?php endif; ?>
</head>
<body class="auth-body">
<?= $content ?? '' ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
