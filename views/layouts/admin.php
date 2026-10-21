<?php
/**
 * OzyConnect — ADMIN layout (sıfırdan kabuk).
 * Bağımsız header + üst yatay ikonlu menü + içerik. Modernize chrome KULLANILMAZ;
 * yalnız Bootstrap (grid/bileşen) + tabler ikon fontu + ozy-admin.css teması.
 * Vurgu rengi DİNAMİK: brand_color → --bs-primary (tema buradan türetir).
 * Route / controller / CSRF / AdminAuth mantığı DEĞİŞMEDİ. $content echo'lanır.
 */
$adminEmail   = (string) ($data['_adminEmail'] ?? '');
$adminInitial = $adminEmail !== '' ? mb_strtoupper(mb_substr($adminEmail, 0, 1)) : 'A';
// Yöneticinin kendi hesabı — "Şifre Değiştir" menü öğesi için
$adminSelfId  = (int) ($data['_adminId'] ?? ($_SESSION['admin']['id'] ?? 0));
$csrf         = (string) ($data['_csrf'] ?? '');
$pt           = (string) ($data['pageTitle'] ?? 'Platform');
$supportUnread = (int) ($data['_supportUnread'] ?? 0);
$updateUnread  = (int) ($data['_updateUnread'] ?? 0);

/* bti()/boss_page_header()/boss_status_badge()/boss_switch() → app/ui_helpers.php (global). */

$adminGroups = [
    ['l' => 'Genel Bakış', 'u' => '/admin', 'i' => 'layout-dashboard', 'items' => []],
    ['l' => 'Mağazalar', 'u' => '/stores', 'i' => 'store', 'items' => [
        ['u' => '/admin/stores/create', 'l' => 'Mağaza Ekle',   'i' => 'plus',      'perm' => 'stores.edit'],
        ['u' => '/stores',         'l' => 'Mağazalar',          'i' => 'store',     'perm' => 'stores.view'],
        ['u' => '/admin/signups',  'l' => 'Mağaza Başvuruları', 'i' => 'user-plus', 'perm' => 'stores.view'],
        ['u' => '/admin/documents',      'l' => 'Belgeler',      'i' => 'files', 'perm' => 'documents.view'],
        ['u' => '/admin/document-types', 'l' => 'Belge Türleri', 'i' => 'tags',  'perm' => 'documents.view'],
    ]],
    ['l' => 'Ürün & Abonelik', 'sl' => 'Abonelik', 'u' => '/admin/packages', 'i' => 'package', 'items' => [
        ['u' => '/admin/packages',       'l' => 'Paketler',    'i' => 'box',       'perm' => 'packages.view'],
        ['u' => '/admin/module-catalog', 'l' => 'Modüller',    'i' => 'tags',      'perm' => 'packages.view'],
        ['u' => '/admin/marketplace-catalog', 'l' => 'Pazaryerleri', 'i' => 'building-store', 'perm' => 'packages.view'],
        ['u' => '/admin/subscriptions',  'l' => 'Abonelikler', 'i' => 'refresh-cw','perm' => 'subscriptions.view'],
    ]],
    ['l' => 'Faturalar & Ödemeler', 'sl' => 'Faturalar', 'u' => '/admin/invoices', 'i' => 'receipt', 'items' => [
        ['u' => '/admin/invoices',         'l' => 'Faturalar',          'i' => 'file-text',   'perm' => 'invoices.view'],
        ['u' => '/admin/payments',         'l' => 'Ödeme Bildirimleri', 'i' => 'banknote',    'perm' => 'payments.view'],
        ['u' => '/admin/earsiv-settings',  'l' => 'e-Arşiv Ayarları',   'i' => 'file-check',  'perm' => 'settings.view'],
        ['u' => '/admin/payment-settings', 'l' => 'Ödeme Ayarları',     'i' => 'credit-card', 'perm' => 'settings.view'],
    ]],
    ['l' => 'İletişim Merkezi', 'sl' => 'İletişim', 'u' => '/admin/support', 'i' => 'message-square', 'badge' => $supportUnread, 'items' => [
        ['u' => '/admin/support',        'l' => 'Destek Talepleri', 'i' => 'life-buoy', 'perm' => 'support.view'],
        ['u' => '/admin/leads',          'l' => 'Gelen Kutusu',     'i' => 'inbox',     'perm' => 'leads.view'],
        ['u' => '/admin/broadcast',      'l' => 'Bildirim Gönder',  'i' => 'bell',      'perm' => 'support.view'],
        ['u' => '/admin/mail-templates', 'l' => 'Mail Şablonları',  'i' => 'mail',      'perm' => 'content.view'],
    ]],
    ['l' => 'Operasyon', 'u' => '/ops', 'i' => 'settings-2', 'items' => [
        ['u' => '/ops',         'l' => 'Özet',   'i' => 'gauge',    'perm' => 'ops.view'],
        ['u' => '/ops/queue',   'l' => 'Kuyruk', 'i' => 'layers',   'perm' => 'ops.view'],
        ['u' => '/ops/workers', 'l' => 'İşleyiciler', 'i' => 'cpu',  'perm' => 'ops.view'],
        ['u' => '/ops/health',  'l' => 'Sağlık', 'i' => 'activity', 'perm' => 'ops.view'],
        ['u' => '/ops/audit',   'l' => 'Denetim Kaydı',  'i' => 'scroll-text', 'perm' => 'ops.view'],
        ['u' => '/admin/sync-settings', 'l' => 'Senkron Ayarları', 'i' => 'refresh-cw', 'perm' => 'settings.view'],
        ['u' => '/logs',        'l' => 'Loglar',         'i' => 'scroll',      'perm' => 'logs.view'],
    ]],
    ['l' => 'Site İçeriği', 'sl' => 'İçerik', 'u' => '/admin/site-modules', 'i' => 'layout-grid', 'items' => [
        ['u' => '/admin/site-modules', 'l' => 'Site Modülleri',  'i' => 'grid-3x3', 'perm' => 'content.view'],
        ['u' => '/admin/screenshots',  'l' => 'Site Resimleri', 'i' => 'monitor',   'perm' => 'content.view'],
        ['u' => '/admin/testimonials', 'l' => 'Referanslar',    'i' => 'quote',     'perm' => 'content.view'],
        ['u' => '/admin/blog',         'l' => 'Blog',           'i' => 'newspaper', 'perm' => 'content.view'],
        ['u' => '/admin/faqs',         'l' => 'SSS',            'i' => 'help-circle','perm' => 'content.view'],
    ]],
    ['l' => 'Sistem Ayarları', 'sl' => 'Ayarlar', 'u' => '/admin/branding', 'i' => 'settings', 'items' => [
        ['u' => '/admin/branding',      'l' => 'Site Ayarları',    'i' => 'palette',    'perm' => 'settings.view'],
        ['u' => '/admin/marketing',     'l' => 'Pazarlama & Piksel','i' => 'ad-2',      'perm' => 'settings.view'],
        ['u' => '/admin/legal',         'l' => 'Yasal Metinler',    'i' => 'file-text', 'perm' => 'settings.view'],
        ['u' => '/admin/ai-suite',      'l' => 'AI Suite',          'i' => 'robot',     'perm' => 'settings.view'],
        ['u' => '/admin/mail-settings', 'l' => 'E-posta Ayarları',  'i' => 'mail',       'perm' => 'settings.view'],
    ]],
    ['l' => 'Yönetim', 'sl' => 'Yönetim', 'u' => '/admin/admin-users', 'i' => 'shield-lock', 'items' => [
        ['u' => '/admin/admin-users',   'l' => 'Admin Kullanıcılar','i' => 'users',      'perm' => 'admins.view'],
        ['u' => '/admin/admin-roles',   'l' => 'Roller',           'i' => 'shield-lock','perm' => 'admins.view'],
    ]],
    ['l' => 'Bakım', 'u' => '/admin/updates', 'i' => 'tool', 'badge' => $updateUnread, 'items' => [
        ['u' => '/admin/updates', 'l' => 'Güncellemeler', 'i' => 'megaphone',       'perm' => 'content.view'],
        ['u' => '/backups',       'l' => 'Yedekler',      'i' => 'database-backup', 'perm' => 'settings.view'],
    ]],
    ['l' => 'API Yönetimi', 'sl' => 'API', 'u' => '/admin/api-tokens', 'i' => 'key-round', 'perm' => 'api.view', 'items' => []],
];

// ── Rol bazlı menü görünürlüğü (admin RBAC) ──
// Grup: alt-öğesi varsa ≥1 görünür öğe kalınca görünür; alt-öğesiz grup kendi 'perm'ine göre.
if (function_exists('admin_can')) {
    $canSee = static fn(?string $p): bool => $p === null || $p === '' || admin_can($p);
    $visible = [];
    foreach ($adminGroups as $g) {
        if (!empty($g['items'])) {
            $items = array_values(array_filter($g['items'], static fn($it) => $canSee($it['perm'] ?? null)));
            if ($items === []) { continue; }
            $g['items'] = $items;
            $visible[] = $g;
        } elseif ($canSee($g['perm'] ?? null)) {
            $visible[] = $g;
        }
    }
    $adminGroups = $visible;
}

// ── Aktif yol (longest-match) ──
$reqPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$base    = function_exists('app_base_path') ? (string) app_base_path() : '';
if ($base !== '' && $base !== '/' && str_starts_with($reqPath, $base)) {
    $reqPath = substr($reqPath, strlen($base));
}
$reqPath = $reqPath === '/' ? '/' : rtrim('/' . trim($reqPath, '/'), '/');

$activeSub = ''; $bestLen = -1;
foreach ($adminGroups as $g) {
    $urls = [$g['u']];
    foreach ($g['items'] as $it) { $urls[] = $it['u']; }
    foreach ($urls as $u) {
        $matches = ($reqPath === $u) || str_starts_with($reqPath . '/', rtrim($u, '/') . '/');
        if ($matches && strlen($u) > $bestLen) { $bestLen = strlen($u); $activeSub = $u; }
    }
}
$grpActive = function (array $g) use ($activeSub): bool {
    if ($g['u'] === $activeSub) { return true; }
    foreach ($g['items'] as $it) { if ($it['u'] === $activeSub) { return true; } }
    return false;
};

$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$brandName = brand('site_name');
$brandLogo = trim((string) brand('logo_url', ''));
$amp = fn(string $p): string => $e(asset($p));

// ── Dinamik marka rengi ──
$bcRaw = trim((string) brand('brand_color', '#2563eb'));
$bcHex = '#2563eb';
if (preg_match('/^#?[0-9a-fA-F]{6}$/', $bcRaw)) {
    $bcHex = '#' . strtolower(ltrim($bcRaw, '#'));
} elseif (preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/', $bcRaw, $bcM)) {
    $bcHex = sprintf('#%02x%02x%02x', min(255, (int) $bcM[1]), min(255, (int) $bcM[2]), min(255, (int) $bcM[3]));
}
$bcR = hexdec(substr($bcHex, 1, 2)); $bcG = hexdec(substr($bcHex, 3, 2)); $bcB = hexdec(substr($bcHex, 5, 2));
$bcRgbCsv = "$bcR,$bcG,$bcB";
$bcRgbSp  = "$bcR $bcG $bcB";
$bcDk = static fn(int $c): int => (int) round($c * 0.84);
$bcDark = sprintf('#%02x%02x%02x', $bcDk($bcR), $bcDk($bcG), $bcDk($bcB));
?>
<!DOCTYPE html>
<html lang="tr" dir="ltr" data-panel="admin" data-bs-theme="light">
<head>
    <script>(function(){try{var t=localStorage.getItem('boss-theme')||'light';document.documentElement.setAttribute('data-bs-theme',t);window.__bossTheme=t;}catch(e){window.__bossTheme='light';}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="app-base-path" content="<?= $e(function_exists('app_base_path') ? app_base_path() : '') ?>">
    <meta name="csrf-token" content="<?= $e($csrf) ?>">
    <title><?= $e($pt) ?> — <?= $e($brandName) ?> Platform</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Bootstrap grid/bileşen + tabler ikon fontu (Modernize styles.css içinden) -->
    <link rel="stylesheet" href="<?= $amp('modernize/assets/css/styles.css') ?>?v=20260621poppins">
    <!-- Tailwind (Tailwind tabanlı ops/stores görünümleri + ⌘K paleti için) -->
    <link rel="stylesheet" href="<?= $amp('css/app.css') ?>?v=20260913">
    <link rel="stylesheet" href="<?= $amp('css/ozy.css') ?>?v=20260913">
    <link rel="stylesheet" href="<?= $amp('css/ozy-ui.css') ?>?v=20260913b">
    <link rel="stylesheet" href="<?= $amp('vendor/datatables/jquery.dataTables.min.css') ?>">
    <!-- Summernote (WYSIWYG) — YEREL (CDN bağımlılığı yok); ozy-admin'den ÖNCE; tema onu Lumina'ya boyar -->
    <link rel="stylesheet" href="<?= $amp('vendor/summernote/summernote-bs5.min.css') ?>?v=20260618">
    <!-- Admin Konsol teması (Lumina · EN SON → kazanır) -->
    <link rel="stylesheet" href="<?= $amp('css/ozy-admin.css') ?>?v=20260913">

    <!-- Poppins font kilidi: buton/kontrol/link fontunu sabitle (tüm stylesheet'lerden SONRA → kazanır) -->
    <style>html,body.ozy-admin,button,input,select,textarea,optgroup,a,.btn{font-family:'Poppins','Inter','Segoe UI',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif}</style>

    <style>
    html[data-panel="admin"]{
      --bs-primary:<?= $bcHex ?>; --bs-primary-rgb:<?= $bcRgbCsv ?>;
      --bs-primary-text-emphasis:<?= $bcDark ?>; --bs-primary-bg-subtle:rgba(<?= $bcRgbCsv ?>,.12);
      --bs-primary-border-subtle:rgba(<?= $bcRgbCsv ?>,.30);
      --brand:<?= $bcRgbSp ?>;
    }
    </style>

    <script src="<?= $amp('vendor/jquery/jquery-3.7.1.min.js') ?>"></script>
    <script src="<?= $amp('vendor/lucide/lucide.min.js') ?>"></script>
    <script src="<?= $amp('vendor/datatables/jquery.dataTables.min.js') ?>"></script>
</head>
<body class="ozy-admin">

<!-- ════════ HEADER (YoraUI: 2 satır) ════════ -->
<header class="ad-header">
  <!-- üst satır: marka + büyük arama + araçlar -->
  <div class="ad-topbar">
    <div class="ad-topbar-inner">
      <a href="<?= $e(url('/admin')) ?>" class="ad-brand">
        <?php if ($brandLogo !== ''): ?>
          <img src="<?= $e($brandLogo) ?>" alt="<?= $e($brandName) ?>" style="height:30px;width:auto;max-width:170px;object-fit:contain;display:block;">
        <?php else: ?>
          <span class="ad-brand-logo">O</span>
          <span class="ad-brand-name"><?= $e($brandName) ?></span>
        <?php endif; ?>
      </a>

      <button type="button" class="ad-search" onclick="adCmdk()" title="Ara (⌘K)">
        <i class="ti ti-search"></i>
        <span class="ad-search-txt">Ara veya komut yaz…</span>
        <kbd>⌘K</kbd>
      </button>

      <div class="ad-tools">
        <a class="ad-tool d-none d-sm-grid" href="<?= $e(url('/')) ?>" target="_blank" title="Siteyi Görüntüle"><i class="ti ti-world"></i></a>
        <button type="button" class="ad-tool" id="adThemeBtn" title="Tema"><i class="ti ti-moon" data-theme-moon></i><i class="ti ti-sun d-none" data-theme-sun></i></button>

        <!-- bildirimler (canlı poll + sesli uyarı) -->
        <div class="dropdown">
          <button type="button" class="ad-tool" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-offset="0,8" aria-expanded="false" title="Bildirimler">
            <i class="ti ti-bell"></i>
            <span class="ad-badge" id="notifBadge" hidden>0</span>
          </button>
          <div class="dropdown-menu dropdown-menu-end ad-menu-card">
            <div class="ad-menu-head d-flex align-items-center justify-content-between">
              <span class="fw-semibold">Bildirimler</span>
              <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-muted" id="notifMute" title="Sesli uyarı"><i class="ti ti-bell" data-mute-on></i><i class="ti ti-bell-off d-none" data-mute-off></i></button>
            </div>
            <a href="<?= $e(url('/admin/support')) ?>" class="ad-menu-row">
              <span class="ad-ico-badge bg-warning-subtle text-warning"><i class="ti ti-lifebuoy fs-5"></i></span>
              <span class="flex-grow-1"><span class="d-block fw-semibold text-body">Destek Talepleri</span><span class="fs-1 text-muted"><span id="notifSupport">0</span> açık talep</span></span>
            </a>
            <a href="<?= $e(url('/admin/signups')) ?>" class="ad-menu-row">
              <span class="ad-ico-badge bg-info-subtle text-info"><i class="ti ti-user-plus fs-5"></i></span>
              <span class="flex-grow-1"><span class="d-block fw-semibold text-body">Mağaza Başvuruları</span><span class="fs-1 text-muted"><span id="notifSignups">0</span> onay bekliyor</span></span>
            </a>
            <a href="<?= $e(url('/admin/documents')) ?>" class="ad-menu-row">
              <span class="ad-ico-badge bg-success-subtle text-success"><i class="ti ti-files fs-5"></i></span>
              <span class="flex-grow-1"><span class="d-block fw-semibold text-body">Belgeler</span><span class="fs-1 text-muted"><span id="notifDocs">0</span> inceleme bekliyor</span></span>
            </a>
          </div>
        </div>

        <!-- profil -->
        <div class="dropdown">
          <button type="button" class="ad-profile-btn" data-bs-toggle="dropdown" data-bs-offset="0,8" aria-expanded="false">
            <span class="ad-profile-meta d-none d-md-flex">
              <span class="ad-profile-name"><?= $e($adminEmail !== '' ? $adminEmail : 'Yönetici') ?></span>
              <span class="ad-profile-role">Platform Yöneticisi</span>
            </span>
            <span class="ad-avatar"><?= $e($adminInitial) ?></span>
            <i class="ti ti-chevron-down fs-3 d-none d-lg-inline"></i>
          </button>
          <div class="dropdown-menu dropdown-menu-end ad-menu-card">
            <div class="ad-menu-head">
              <span class="d-block fw-semibold text-truncate" style="max-width:230px;"><?= $e($adminEmail !== '' ? $adminEmail : 'Yönetici') ?></span>
              <span class="fs-1 text-muted">Platform Yöneticisi</span>
            </div>
            <div class="p-1">
              <?php if ($adminSelfId > 0): ?>
              <a href="<?= $e(url('/admin/admin-users/' . $adminSelfId . '/edit')) ?>" class="dropdown-item"><i class="ti ti-key"></i> Şifre Değiştir</a>
              <?php endif; ?>
              <a href="<?= $e(url('/admin/2fa/setup')) ?>" class="dropdown-item"><i class="ti ti-shield-lock"></i> İki Adımlı Doğrulama</a>
              <a href="<?= $e(url('/admin/branding')) ?>" class="dropdown-item"><i class="ti ti-settings"></i> Site Ayarları</a>
              <a href="<?= $e(url('/')) ?>" target="_blank" class="dropdown-item"><i class="ti ti-world"></i> Siteyi Görüntüle</a>
            </div>
            <div class="ad-menu-foot">
              <form method="post" action="<?= $e(url('/admin/logout')) ?>">
                <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="ti ti-logout me-1"></i> Çıkış Yap</button>
              </form>
            </div>
          </div>
        </div>

        <button type="button" class="ad-burger ms-1" id="adBurger" title="Menü"><i class="ti ti-menu-2"></i></button>
      </div>
    </div>
  </div>

  <!-- alt satır: yatay ikonlu menü (≥992px) -->
  <nav class="ad-nav">
    <div class="ad-nav-inner">
      <ul class="ad-nav-list">
        <?php foreach ($adminGroups as $g): $gact = $grpActive($g); $lbl = $g['sl'] ?? $g['l']; ?>
          <?php if (empty($g['items'])): ?>
          <li class="ad-nav-item <?= $gact ? 'active' : '' ?>">
            <a class="ad-nav-link" href="<?= $e(url($g['u'])) ?>" title="<?= $e($g['l']) ?>">
              <i class="ti ti-<?= $e(bti($g['i'])) ?>"></i><span><?= $e($lbl) ?></span>
              <?php if (!empty($g['badge'])): ?><span class="ad-nav-badge"><?= (int) $g['badge'] > 99 ? '99+' : (int) $g['badge'] ?></span><?php endif; ?>
            </a>
          </li>
          <?php else: ?>
          <li class="ad-nav-item dropdown <?= $gact ? 'active' : '' ?>">
            <button type="button" class="ad-nav-link" data-bs-toggle="dropdown" data-bs-offset="0,2" aria-expanded="false" title="<?= $e($g['l']) ?>">
              <i class="ti ti-<?= $e(bti($g['i'])) ?>"></i><span><?= $e($lbl) ?></span>
              <?php if (!empty($g['badge'])): ?><span class="ad-nav-badge"><?= (int) $g['badge'] > 99 ? '99+' : (int) $g['badge'] ?></span><?php endif; ?>
              <i class="ti ti-chevron-down ad-nav-caret"></i>
            </button>
            <div class="dropdown-menu">
              <?php foreach ($g['items'] as $it): ?>
              <a class="dropdown-item <?= $it['u'] === $activeSub ? 'active' : '' ?>" href="<?= $e(url($it['u'])) ?>">
                <i class="ti ti-<?= $e(bti($it['i'])) ?>"></i><?= $e($it['l']) ?>
              </a>
              <?php endforeach; ?>
            </div>
          </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>

  <!-- mobil menü (<1200px) -->
  <div class="ad-mobile" id="adMobile">
    <div class="ad-mobile-inner">
      <?php foreach ($adminGroups as $g): ?>
        <?php if (empty($g['items'])): ?>
        <a class="ad-mobile-link <?= $grpActive($g) ? 'active' : '' ?>" href="<?= $e(url($g['u'])) ?>"><i class="ti ti-<?= $e(bti($g['i'])) ?>"></i><?= $e($g['l']) ?><?php if (!empty($g['badge'])): ?> <span class="ad-nav-badge ms-auto"><?= (int) $g['badge'] ?></span><?php endif; ?></a>
        <?php else: ?>
        <div class="ad-mobile-group"><?= $e($g['l']) ?></div>
        <?php foreach ($g['items'] as $it): ?>
        <a class="ad-mobile-link <?= $it['u'] === $activeSub ? 'active' : '' ?>" href="<?= $e(url($it['u'])) ?>"><i class="ti ti-<?= $e(bti($it['i'])) ?>"></i><?= $e($it['l']) ?></a>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<!-- ════════ FLASH → TOAST (sağ üst, otomatik kapanır) ════════ -->
<?php $ozyHasFlash = !empty($data['_flash']['success']) || !empty($data['_flash']['error']) || !empty($data['_flash']['warning']); ?>
<?php if ($ozyHasFlash): ?>
<div class="ozy-toasts" id="ozyToasts">
  <?php if (!empty($data['_flash']['success'])): ?>
    <div class="alert alert-success" role="alert"><i class="ti ti-circle-check"></i><div class="alert-body"><div class="alert-title">Başarılı</div><div><?= $e($data['_flash']['success']) ?></div></div></div>
  <?php endif; ?>
  <?php if (!empty($data['_flash']['error'])): ?>
    <div class="alert alert-danger" role="alert"><i class="ti ti-alert-circle"></i><div class="alert-body"><div class="alert-title">Hata</div><div><?= $e($data['_flash']['error']) ?></div></div></div>
  <?php endif; ?>
  <?php if (!empty($data['_flash']['warning'])): ?>
    <div class="alert alert-warning" role="alert"><i class="ti ti-alert-triangle"></i><div class="alert-body"><div class="alert-title">Uyarı</div><div><?= $e($data['_flash']['warning']) ?></div></div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ════════ İÇERİK ════════ -->
<main class="ad-main">
  <div class="ad-container">

    <?= $content ?? '' ?>
  </div>
</main>

<!-- ════════ Command Palette (⌘K) ════════ -->
<div id="ozyCmdk" hidden class="fixed inset-0 z-[60] flex items-start justify-center pt-[14vh] px-4 bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)ozyCloseCmdk()">
    <div class="w-full max-w-[560px] rounded-2xl bg-surface shadow-pop border border-slate-200/80 dark:border-white/[.07] overflow-hidden">
        <div class="flex items-center gap-3 px-4 h-14 border-b border-slate-100 dark:border-white/[.06]">
            <i data-lucide="search" class="w-[18px] h-[18px] text-slate-400"></i>
            <input id="ozyCmdkInput" autocomplete="off" placeholder="Ara veya komut yaz…" class="flex-1 bg-transparent outline-none text-[14px] placeholder:text-slate-400">
            <kbd class="text-[11px] font-mono px-1.5 py-0.5 rounded-md bg-slate-900/[.05] dark:bg-white/10 text-slate-500">ESC</kbd>
        </div>
        <div class="p-2 max-h-[340px] overflow-y-auto" id="ozyCmdkList">
            <div class="eyebrow text-slate-400 px-3 pt-2 pb-1">Git</div>
            <?php
            $flat = [];
            foreach ($adminGroups as $g) {
                if ($g['items']) { foreach ($g['items'] as $it) { $flat[] = $it; } }
                else { $flat[] = ['u' => $g['u'], 'l' => $g['l'], 'i' => $g['i']]; }
            }
            foreach ($flat as $it): ?>
            <a href="<?= $e(url($it['u'])) ?>" class="ozy-cmd t flex items-center gap-3 px-3 h-11 rounded-xl hover:bg-brand/[.08] text-[13.5px]" data-k="<?= $e(mb_strtolower($it['l'], 'UTF-8')) ?>">
                <span class="grid place-items-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-white/10 text-slate-500"><i data-lucide="<?= $e($it['i']) ?>" class="w-4 h-4"></i></span><?= $e($it['l']) ?>
            </a>
            <?php endforeach; ?>
            <div class="ozy-cmd-empty hidden px-3 py-6 text-center text-[12px] text-slate-400">Sonuç yok</div>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<!-- ════════ JS ════════ -->
<script src="<?= $amp('modernize/assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') ?>"></script>
<!-- Summernote (WYSIWYG) — YEREL; jQuery + Bootstrap bundle'dan SONRA -->
<script src="<?= $amp('vendor/summernote/summernote-bs5.min.js') ?>?v=20260618"></script>
<script src="<?= $amp('vendor/summernote/lang/summernote-tr-TR.min.js') ?>?v=20260618"></script>
<script src="<?= $amp('js/app.js') ?>?v=20260913"></script>
<script src="<?= $amp('js/ozy.js') ?>?v=20260614"></script>
<script>
(function(){
  if (window.lucide) { try { lucide.createIcons(); } catch(e){} }

  // Summernote — içerik alanları (textarea.summernote); jQuery + summernote yüklüyse
  if (window.jQuery && jQuery.fn && jQuery.fn.summernote) {
    var snLang = (jQuery.fn.summernote.lang && jQuery.fn.summernote.lang['tr-TR']) ? 'tr-TR' : 'en-US';
    // Bootstrap 5 jQuery .tooltip() eklentisi sağlamaz → summernote-bs5 çağırınca "tooltip is not a function"
    // ile init patlıyordu. No-op shim + tooltip:false ile engellenir (buton tooltip'leri kapalı).
    if (jQuery.fn && !jQuery.fn.tooltip) { jQuery.fn.tooltip = function(){ return this; }; }
    jQuery('textarea.summernote').each(function(){
      jQuery(this).summernote({
        lang: snLang, height: 300, dialogsInBody: true, tooltip: false,
        placeholder: this.getAttribute('placeholder') || '',
        fontNames: ['Poppins','Arial','Helvetica','Georgia','Tahoma','Times New Roman','Trebuchet MS','Verdana','Courier New'],
        fontSizes: ['8','9','10','11','12','14','16','18','20','24','28','32','36','48'],
        toolbar: [
          ['style', ['style']],
          ['font', ['bold','italic','underline','strikethrough','clear']],
          ['fontname', ['fontname']],
          ['fontsize', ['fontsize']],
          ['color', ['color']],
          ['para', ['ul','ol','paragraph']],
          ['height', ['height']],
          ['table', ['table']],
          ['insert', ['link','picture','video','hr']],
          ['view', ['fullscreen','codeview','help']]
        ]
        // Görsel: varsayılan davranış = base64 gömme (kullanıcı tercihi).
      });
    });
  }

  // Toggle (boss_switch .ozy-switch) — Aktif/Pasif durum kelimesini canlı güncelle
  document.querySelectorAll('.ozy-switch-input').forEach(function(inp){
    var field = inp.closest('.ozy-switch-field');
    var st = field ? field.querySelector('.ozy-switch-state') : null;
    function sync(){ if(!st) return; var on = inp.checked; st.textContent = on ? (inp.dataset.on || 'Aktif') : (inp.dataset.off || 'Pasif'); st.classList.toggle('on', on); }
    inp.addEventListener('change', sync); sync();
  });

  // DataTables — admin veri tabloları (opt-out: .no-dt; başlıksız / <2 satır / colspan-empty tablolar atlanır)
  if (window.jQuery && $.fn && $.fn.DataTable) {
    $('table.table').each(function(){
      var $t = $(this);
      if ($t.hasClass('no-dt') || $t.closest('.no-dt').length) { return; }
      if ($t.children('thead').find('th,td').length === 0) { return; }
      if ($t.children('tbody').children('tr').length < 2) { return; }
      if ($t.children('tbody').find('td[colspan]').length) { return; }
      try {
        $t.DataTable({
          pageLength: 25, lengthChange: false, autoWidth: false, order: [],
          dom: "<'dt-top'f>rt<'dt-bottom'ip>",
          language: {
            search: '', searchPlaceholder: 'Ara…',
            info: '_START_–_END_ / _TOTAL_ kayıt', infoEmpty: '0 kayıt', infoFiltered: '(_MAX_ içinden)',
            zeroRecords: 'Eşleşen kayıt bulunamadı', emptyTable: 'Kayıt yok',
            paginate: { first: '«', previous: '‹', next: '›', last: '»' }
          }
        });
      } catch (e) {}
    });
  }

  // Tema toggle (light/dark)
  function applyTheme(t){
    document.documentElement.setAttribute('data-bs-theme', t);
    try{ localStorage.setItem('boss-theme', t); }catch(_){}
    var m=document.querySelector('[data-theme-moon]'), s=document.querySelector('[data-theme-sun]');
    if(m&&s){ var dark=t==='dark'; m.classList.toggle('d-none',dark); s.classList.toggle('d-none',!dark); }
  }
  applyTheme(document.documentElement.getAttribute('data-bs-theme')||'light');
  var tb=document.getElementById('adThemeBtn');
  if(tb){ tb.addEventListener('click', function(){ applyTheme(document.documentElement.getAttribute('data-bs-theme')==='dark'?'light':'dark'); }); }

  // Mobil menü
  var burger=document.getElementById('adBurger'), mob=document.getElementById('adMobile');
  if(burger&&mob){ burger.addEventListener('click', function(){ mob.classList.toggle('open'); }); }

  // Command palette (⌘K / Ctrl+K)
  window.adCmdk=function(){ var m=document.getElementById('ozyCmdk'); if(!m)return; m.hidden=false; var i=document.getElementById('ozyCmdkInput'); if(i){ i.value=''; setTimeout(function(){i.focus();},30); } if(window.lucide)lucide.createIcons(); };
  if(typeof window.ozyCloseCmdk!=='function'){ window.ozyCloseCmdk=function(){ var m=document.getElementById('ozyCmdk'); if(m)m.hidden=true; }; }
  document.addEventListener('keydown', function(e){
    if((e.metaKey||e.ctrlKey)&&(e.key==='k'||e.key==='K')){ e.preventDefault(); adCmdk(); }
    else if(e.key==='Escape'){ window.ozyCloseCmdk(); }
  });
  var ci=document.getElementById('ozyCmdkInput');
  if(ci){ ci.addEventListener('input', function(){
    var q=this.value.toLowerCase().trim(), n=0;
    document.querySelectorAll('#ozyCmdkList .ozy-cmd').forEach(function(a){ var ok=a.getAttribute('data-k').indexOf(q)>=0; a.classList.toggle('hidden',!ok); if(ok)n++; });
    var em=document.querySelector('.ozy-cmd-empty'); if(em)em.classList.toggle('hidden',n>0);
  }); }

  // Flash toast (sağ üst): tıklayınca veya 4.5 sn sonra kayarak kapanır
  (function(){
    function dismiss(el){ if(!el || el.__out){ return; } el.__out=true; el.classList.add('ozy-toast-out'); setTimeout(function(){ el.remove(); }, 360); }
    document.querySelectorAll('.ozy-toasts .alert').forEach(function(el, i){
      el.addEventListener('click', function(){ dismiss(el); });
      setTimeout(function(){ dismiss(el); }, 4500 + i * 600);
    });
  })();

  // Üst-menü bildirim: periyodik poll + sesli uyarı (artışta bip) + sustur (kalıcı)
  (function(){
    var POLL='<?= $e(url('/admin/notifications/poll')) ?>', KEY='ozyNotifLast', MUTEKEY='ozyNotifMute';
    var muted=false; try{ muted=localStorage.getItem(MUTEKEY)==='1'; }catch(_){}
    var muteBtn=document.getElementById('notifMute');
    function paintMute(){ if(!muteBtn)return; var on=muteBtn.querySelector('[data-mute-on]'), off=muteBtn.querySelector('[data-mute-off]'); if(on)on.classList.toggle('d-none',muted); if(off)off.classList.toggle('d-none',!muted); muteBtn.title=muted?'Sesli uyarı kapalı':'Sesli uyarı açık'; }
    paintMute();
    if(muteBtn){ muteBtn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); muted=!muted; try{ localStorage.setItem(MUTEKEY, muted?'1':'0'); }catch(_){} paintMute(); }); }
    function beep(){ if(muted)return; try{ var AC=window.AudioContext||window.webkitAudioContext; if(!AC)return; var ac=new AC(); var o=ac.createOscillator(), g=ac.createGain(); o.connect(g); g.connect(ac.destination); o.type='sine'; o.frequency.value=880; g.gain.setValueAtTime(0.0001,ac.currentTime); g.gain.exponentialRampToValueAtTime(0.18,ac.currentTime+0.02); g.gain.exponentialRampToValueAtTime(0.0001,ac.currentTime+0.4); o.start(); o.stop(ac.currentTime+0.42); }catch(_){} }
    function setN(id,v){ var el=document.getElementById(id); if(el)el.textContent=v; }
    function update(d){
      d=d||{}; setN('notifSupport', d.support||0); setN('notifSignups', d.signups||0); setN('notifDocs', d.documents||0);
      var total=d.total||0, badge=document.getElementById('notifBadge');
      if(badge){ badge.textContent= total>99?'99+':String(total); badge.hidden= total<=0; }
      var prevRaw=null; try{ prevRaw=localStorage.getItem(KEY); }catch(_){}
      var prev= prevRaw===null?null:parseInt(prevRaw,10);
      if(prev!==null && total>prev){ beep(); }
      try{ localStorage.setItem(KEY, String(total)); }catch(_){}
    }
    function tick(){ fetch(POLL,{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'}).then(function(r){return r.ok?r.json():null;}).then(function(d){ if(d)update(d); }).catch(function(){}); }
    tick(); setInterval(tick, 45000);
  })();
})();
</script>
</body>
</html>
