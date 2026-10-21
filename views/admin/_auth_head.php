<?php
/** Admin auth (pre-auth) ortak <head> — standalone (layout YOK).
 *  Vurgu rengi DİNAMİK (brand_color → --brand). Inter + Tailwind (ozy.css).
 *  Kullanım: view başında require; $pageTitle/$_csrf/$_flash kapsamdan gelir. */
$e = $e ?? fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$authTitle = $pageTitle ?? 'Platform';

// Dinamik marka rengi → Tailwind --brand (R G B)
$abcRaw = trim((string) brand('brand_color', '#2563eb'));
$abcHex = '#2563eb';
if (preg_match('/^#?[0-9a-fA-F]{6}$/', $abcRaw)) { $abcHex = '#' . strtolower(ltrim($abcRaw, '#')); }
elseif (preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/', $abcRaw, $abcM)) { $abcHex = sprintf('#%02x%02x%02x', min(255, (int) $abcM[1]), min(255, (int) $abcM[2]), min(255, (int) $abcM[3])); }
$abcR = hexdec(substr($abcHex, 1, 2)); $abcG = hexdec(substr($abcHex, 3, 2)); $abcB = hexdec(substr($abcHex, 5, 2));
$abcRgbSp = "$abcR $abcG $abcB";
?>
<!doctype html>
<html lang="tr" data-panel="admin">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($_csrf ?? '') ?>">
<title><?= $e($authTitle) ?> — <?= $e(brand('site_name')) ?> Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $e(asset('css/app.css')) ?>?v=20260501">
<link rel="stylesheet" href="<?= $e(asset('css/ozy.css')) ?>?v=20260617">
<style>
  html[data-panel="admin"]{ --brand: <?= $abcRgbSp ?>; }
  body{ font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif; }
</style>
<script src="<?= $e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script>try{if(localStorage.getItem('ozy-theme')==='dark')document.documentElement.classList.add('dark');}catch(e){}</script>
</head>
<body class="text-slate-900 dark:text-slate-100 selection:bg-brand/20 min-h-screen bg-bg">
