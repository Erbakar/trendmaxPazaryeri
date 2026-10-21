<?php
$branding = $data['_branding'] ?? [];
$brandLogo = (string) ($branding['logo_url'] ?? '');
$brandName = (string) ($branding['site_name'] ?? 'Entegratör Yazılımı');
$storeName = trim((string) ($branding['store_name'] ?? '')) ?: $brandName;
$storeLogo = trim((string) ($branding['store_logo_url'] ?? ''));
$pt = $data['pageTitle'] ?? '';
$canAny = $data['_canAny'] ?? static fn(array $permissions): bool => true;
// Faz 2 — Store Entitlement: satın alınmamış/pasif modül menüde GİZLENİR (route/controller/API ayrıca zorlar).
$canModule = $data['_canModule'] ?? static fn(string $m): bool => true;
$userName = (string) ($data['_user']['name'] ?? 'Admin');
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$userRoleLabel = match ((string) ($data['_user']['role'] ?? '')) {
    'admin', 'owner' => 'Yönetici',
    'manager'        => 'Müdür',
    'staff'          => 'Personel',
    'viewer'         => 'Görüntüleyici',
    default          => 'Kullanıcı',
};

// ── Marka rengi (admin ile TEK kaynak: settings.brand_color) ──
// Mağaza paneli bugüne dek sabit indigo kullanıyordu; artık admin ile aynı
// ayarı okur, iki panel birebir aynı vurguya sahip olur.
$bcRaw = function_exists('brand') ? trim((string) brand('brand_color', '#2563eb')) : '#2563eb';
$bcHex = '#2563eb';
if (preg_match('/^#?[0-9a-fA-F]{6}$/', $bcRaw)) {
    $bcHex = '#' . strtolower(ltrim($bcRaw, '#'));
}
$bcRgbSp = implode(' ', [hexdec(substr($bcHex, 1, 2)), hexdec(substr($bcHex, 3, 2)), hexdec(substr($bcHex, 5, 2))]);

$navModules = [
    '/orders'=>'orders','/returns'=>'returns','/questions'=>'questions',
    '/products'=>'products','/categories'=>'categories','/brands'=>'brands','/customers'=>'customers',
    '/sync'=>'sync','/import'=>'import','/feeds'=>'feeds','/xml-profilleri'=>'products',
    '/invoices'=>'invoices','/finance'=>'finance',
];
$navPermissions = [
    '/dashboard' => ['dashboard.view'], '/orders' => ['orders.view'], '/returns' => ['returns.view'],
    '/products' => ['products.view'], '/categories' => ['categories.view'], '/brands' => ['brands.view'],
    '/customers' => ['customers.view'], '/marketplaces' => ['marketplaces.view'], '/sync' => ['sync.trigger'],
    '/import' => ['products.view', 'products.create'], '/xml-profilleri' => ['products.view', 'products.create'],
    '/invoices' => ['invoices.view'], '/finance' => ['finance.view'],
    '/questions' => ['orders.view'], '/authorization' => ['users.view', 'roles.view'], '/settings' => ['settings.view'],
    '/settings/api-tokens' => ['settings.view'], '/logs' => ['logs.view'], '/backups' => ['backups.view'],
    '/subscription' => ['billing.view'],
];
// HB İlan Eşleştirme: pazaryeri kimliği kuruluma göre değişir → slug'dan çözülür.
// Görünürlük: products.view + 'products' modülü + mağazanın Hepsiburada yetkisi (entitlement).
$canMarketplaceNav = $data['_canMarketplace'] ?? null;
$hbListingMatchUrl = null;
if ($canMarketplaceNav !== null && $canAny(['products.view']) && $canModule('products')) {
    try {
        $hbRow = isset($GLOBALS['container'])
            ? $GLOBALS['container']->get(\App\Core\Database::class)->fetchOne("SELECT id FROM marketplaces WHERE slug = 'hepsiburada' LIMIT 1")
            : false;
        $hbId = is_array($hbRow) ? (int) ($hbRow['id'] ?? 0) : 0;
        if ($hbId > 0 && $canMarketplaceNav($hbId)) {
            $hbListingMatchUrl = '/marketplaces/' . $hbId . '/listing-match';
            $navPermissions[$hbListingMatchUrl] = ['products.view'];
            $navModules[$hbListingMatchUrl] = 'products';
        }
    } catch (\Throwable) {
        $hbListingMatchUrl = null;
    }
}
// Mockup üst menü: 6 ANA öğe (üstte) + her grubun alt öğeleri (sayfa alt-navı + mobil + ⌘K).
$navGroups = [
    ['l'=>'Anasayfa','i'=>'layout-dashboard','items'=>[
        ['u'=>'/dashboard','i'=>'layout-dashboard','l'=>'Anasayfa','m'=>'Dashboard'],
    ]],
    ['l'=>'Siparişler','i'=>'package','items'=>[
        ['u'=>'/orders','i'=>'package','l'=>'Siparişler','m'=>'Sipariş'],
        ['u'=>'/returns','i'=>'rotate-ccw','l'=>'İadeler','m'=>'İade'],
        ['u'=>'/questions','i'=>'message-circle-question','l'=>'Sorular','m'=>'Soru'],
    ]],
    ['l'=>'Müşteriler','i'=>'users','items'=>[
        ['u'=>'/customers','i'=>'users','l'=>'Müşteriler','m'=>'Müşteri'],
    ]],
    ['l'=>'Ürünler','i'=>'tag','items'=>[
        ['u'=>'/products','i'=>'tag','l'=>'Ürünler','m'=>'Ürün'],
        ['u'=>'/categories','i'=>'folder-tree','l'=>'Kategoriler','m'=>'Kategori'],
        ['u'=>'/brands','i'=>'award','l'=>'Markalar','m'=>'Marka'],
        ['u'=>'/feeds','i'=>'rss','l'=>'Feed','m'=>'Feed'],
        ['u'=>'/xml-profilleri','i'=>'file-code-2','l'=>'XML Profilleri','m'=>'XML'],
    ]],
    ['l'=>'AI Suite','i'=>'sparkles','items'=>[
        ['u'=>'/ai/advisor','i'=>'message-circle','l'=>'Satış Danışmanı','m'=>'AI'],
        ['u'=>'/ai/trends','i'=>'trending-up','l'=>'Trend Merkezi','m'=>'AI'],
        ['u'=>'/ai/competitor','i'=>'swords','l'=>'Rakip Analizi','m'=>'AI'],
        ['u'=>'/ai/bulk','i'=>'wand-2','l'=>'Toplu Ürün Doldurma','m'=>'AI'],
        ['u'=>'/ai/credits','i'=>'coins','l'=>'AI Kredi & Kullanım','m'=>'AI'],
    ]],
    ['l'=>'Pazaryerleri','i'=>'store','items'=>array_values(array_filter([
        ['u'=>'/marketplaces','i'=>'store','l'=>'Pazaryerleri','m'=>'Pazaryeri'],
        $hbListingMatchUrl !== null ? ['u'=>$hbListingMatchUrl,'i'=>'link-2','l'=>'HB İlan Eşleştirme','m'=>'İlan Eşleştirme'] : null,
    ]))],
    ['l'=>'Finans','i'=>'wallet','items'=>[
        ['u'=>'/finance','i'=>'wallet','l'=>'Finans','m'=>'Finans'],
        ['u'=>'/invoices','i'=>'file-text','l'=>'Faturalar','m'=>'Fatura'],
    ]],
    ['l'=>'Ayarlar','i'=>'settings','items'=>[
        ['u'=>'/settings','i'=>'settings','l'=>'Ayarlar','m'=>'Ayar'],
        ['u'=>'/documents','i'=>'file-text','l'=>'Belgeler','m'=>'Belge'],
        ['u'=>'/subscription','i'=>'credit-card','l'=>'Abonelik','m'=>'Abonelik'],
        ['u'=>'/notifications','i'=>'bell','l'=>'Bildirimler','m'=>'Bildirim'],
        ['u'=>'/authorization','i'=>'shield-check','l'=>'Yetki','m'=>'Yetkilendirme'],
    ]],
];
$updateUnread      = (int) ($data['_updateUnread'] ?? 0);
$supportUnread     = (int) ($data['_supportUnread'] ?? 0);
$notifUnread       = (int) ($data['_notifUnread'] ?? 0);
$documentsPending  = (int) ($data['_documentsPending'] ?? 0);
$isActive = static function(array $n) use ($pt): bool {
    return ($n['m']==='Dashboard' && $pt==='Dashboard') || ($n['m']!=='Dashboard' && str_contains($pt, $n['m']));
};
$vis = static function(array $it) use ($canAny, $canModule, $navPermissions, $navModules): bool {
    return $canAny($navPermissions[$it['u']] ?? []) && (!isset($navModules[$it['u']]) || $canModule($navModules[$it['u']]));
};
// Filtrelenmiş gruplar: görünür alt öğesi olanlar + aktif-grup tespiti + üst öğenin hedef URL'si.
$navGroupsVis = [];
$flatNav = [];
foreach ($navGroups as $g) {
    $items = array_values(array_filter($g['items'], $vis));
    if (!$items) { continue; }
    $active = false;
    foreach ($items as $it) { if ($isActive($it)) { $active = true; break; } }
    $navGroupsVis[] = ['l'=>$g['l'], 'i'=>$g['i'], 'u'=>$items[0]['u'], 'active'=>$active, 'items'=>$items];
    foreach ($items as $it) { $flatNav[] = $it; }
}
// Mağaza çipi baş harfleri (mockup: "Ana Mağaza" → "AM")
$storeWords = preg_split('/\s+/', trim($storeName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$storeInitials = mb_strtoupper(mb_substr($storeWords[0] ?? 'O', 0, 1) . (isset($storeWords[1]) ? mb_substr($storeWords[1], 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="tr" data-panel="merchant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="app-base-path" content="<?= htmlspecialchars(function_exists('app_base_path') ? app_base_path() : '', ENT_QUOTES) ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($data['_csrf'] ?? '', ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($pt ?: 'Anasayfa') ?> — <?= htmlspecialchars($brandName) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Eski tema (legacy view içerikleri için yapısal class'lar) -->
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('css/app.css')) ?>?v=20260913">
    <!-- Yeni SaaS tasarım sistemi (app.css'ten SONRA: değişken köprüsü + Poppins + navbar) -->
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('css/ozy.css')) ?>?v=20260913">
    <!-- Ortak bileşen kitaplığı (.ui-*): iki panel aynı parçaları kullanır -->
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('css/ozy-ui.css')) ?>?v=20260913b">
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('vendor/datatables/jquery.dataTables.min.css')) ?>">
    <!-- Poppins font kilidi: buton/kontrol/link fontunu sabitle (Tailwind @layer + ağ/fallback kırılganlığına karşı; tüm stylesheet'lerden SONRA gelir → kazanır) -->
    <style>html,body,button,input,select,textarea,optgroup,a,.btn,.o-btn{font-family:'Poppins',ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}</style>
    <!-- Marka rengi: ozy.css yedeğini ezer; --primary bu kanaldan türer -->
    <style>html[data-panel="merchant"]{--brand:<?= $bcRgbSp ?>}</style>

    <script src="<?= htmlspecialchars(asset('vendor/jquery/jquery-3.7.1.min.js')) ?>"></script>
    <script src="<?= htmlspecialchars(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
    <script src="<?= htmlspecialchars(asset('vendor/lucide/lucide.min.js')) ?>"></script>
    <script src="<?= htmlspecialchars(asset('vendor/datatables/jquery.dataTables.min.js')) ?>"></script>
</head>
<body class="text-slate-900 dark:text-slate-100">

<!-- ════════ NAVBAR (beyaz, sticky, blur) ════════ -->
<header class="sticky top-0 z-30 bg-surface border-b border-slate-200 dark:border-white/[.07]">
    <!-- Tek satır: marka + mağaza çipi + ana menü + aksiyonlar (mockup) -->
    <div class="mx-auto max-w-[95%] px-4 lg:px-6 h-16 flex items-center gap-1.5">
        <button id="mobMenuToggle" class="lg:hidden grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" aria-label="Menü"><i data-lucide="menu" class="w-5 h-5"></i></button>
        <!-- Mağaza kimliği baş öğedir: mağaza logosu yüklüyse logo, değilse baş harf rozeti -->
        <a href="/settings" class="inline-flex items-center gap-2 h-9 pl-1 pr-2.5 rounded-xl hover:bg-slate-900/[.04] dark:hover:bg-white/[.06] transition-colors shrink-0" title="<?= htmlspecialchars($storeName) ?> — Mağaza ayarları">
            <?php if ($storeLogo !== ''): ?>
            <img src="<?= htmlspecialchars($storeLogo) ?>" alt="<?= htmlspecialchars($storeName) ?>"
                 class="w-8 h-8 rounded-[10px] object-contain bg-white shadow-lift shadow-slate-900/10"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
            <span style="display:none" class="grid place-items-center w-8 h-8 rounded-[10px] bg-gradient-to-br from-brand to-violet-500 text-white text-[12px] font-bold leading-none shadow-lift shadow-brand/25"><?= htmlspecialchars($storeInitials) ?></span>
            <?php else: ?>
            <span class="grid place-items-center w-8 h-8 rounded-[10px] bg-gradient-to-br from-brand to-violet-500 text-white text-[12px] font-bold leading-none shadow-lift shadow-brand/25"><?= htmlspecialchars($storeInitials) ?></span>
            <?php endif; ?>
            <span class="text-[14px] font-bold tracking-tight max-w-[180px] truncate hidden sm:block"><?= htmlspecialchars($storeName) ?></span>
            <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 shrink-0 hidden sm:block"></i>
        </a>

        <span class="hidden lg:block w-px h-6 bg-slate-200 dark:bg-white/10 mx-1.5 shrink-0"></span>

        <!-- Ana menü: 6 öğe (mockup — tek satır, aktif underline). Alt öğeler her sayfanın alt-navında. -->
        <nav class="hidden lg:flex items-stretch self-stretch min-w-0">
            <?php foreach ($navGroupsVis as $g): ?>
            <a href="<?= htmlspecialchars($g['u']) ?>" class="relative inline-flex items-center h-full px-2.5 text-[13px] font-medium whitespace-nowrap transition-colors <?= $g['active'] ? 'text-slate-900 dark:text-white font-semibold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                <?= htmlspecialchars($g['l']) ?>
                <?php if ($g['active']): ?><span class="absolute left-2 right-2 bottom-0 h-[2.5px] rounded-full bg-brand"></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="flex-1 min-w-[8px]"></div>

        <!-- ⌘K arama -->
        <button onclick="ozyOpenCmdk()" class="t hidden md:flex lg:hidden xl:flex items-center gap-2 h-9 pl-3 pr-2 rounded-lg bg-slate-900/[.04] dark:bg-white/[.06] hover:bg-slate-900/[.07] dark:hover:bg-white/[.1] text-slate-400 w-36 xl:w-52 shrink-0">
            <i data-lucide="search" class="w-[17px] h-[17px] shrink-0"></i>
            <span class="text-[13px] truncate">Ara…</span>
            <kbd class="ml-auto shrink-0 text-[11px] font-mono px-1.5 py-0.5 rounded bg-surface text-slate-500 shadow-xs">⌘K</kbd>
        </button>

        <span class="hidden xl:block text-[12px] text-slate-400 font-medium tnum"><?= date('d.m.Y H:i') ?></span>

        <!-- Güncelleme + Destek (artık Ayarlar altında; üst menüde hızlı erişim + badge korunur) -->
        <a href="/settings/guncellemeler" class="t relative grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" title="Güncellemeler">
            <i data-lucide="megaphone" class="w-[18px] h-[18px]"></i>
            <?php if ($updateUnread > 0): ?><span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold grid place-items-center leading-none"><?= $updateUnread > 9 ? '9+' : $updateUnread ?></span><?php endif; ?>
        </a>
        <a href="/settings/destek" class="t relative grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" title="Destek">
            <i data-lucide="life-buoy" class="w-[18px] h-[18px]"></i>
            <?php if ($supportUnread > 0): ?><span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold grid place-items-center leading-none"><?= $supportUnread > 9 ? '9+' : $supportUnread ?></span><?php endif; ?>
        </a>
        <a href="/documents" class="t relative grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" title="Belgeler">
            <i data-lucide="file-text" class="w-[18px] h-[18px]"></i>
            <?php if ($documentsPending > 0): ?><span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold grid place-items-center leading-none"><?= $documentsPending > 9 ? '9+' : $documentsPending ?></span><?php endif; ?>
        </a>

        <!-- Faz 8: Bildirim Merkezi (kalıcı sistem bildirimleri — abonelik/ödeme/destek) -->
        <a href="/notifications" class="t relative grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" title="Bildirimler">
            <i data-lucide="inbox" class="w-[18px] h-[18px]"></i>
            <?php if ($notifUnread > 0): ?><span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold grid place-items-center leading-none"><?= $notifUnread > 9 ? '9+' : $notifUnread ?></span><?php endif; ?>
        </a>

        <!-- Bildirim sesi butonu (#notifMuteBtn) yer açmak için bildirim dropdown'ının altına taşındı -->
        <!-- Bildirim zili + dropdown (app.js: #notifToggle/#notifDropdown/#notifList/#notifBadge/#notifClearAll) -->
        <div class="relative">
            <button class="t relative grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" id="notifToggle" title="Bildirimler">
                <i data-lucide="bell-ring" class="w-[18px] h-[18px]"></i>
                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold grid place-items-center leading-none" id="notifBadge" style="display:none">0</span>
            </button>
            <div class="hidden absolute right-0 top-[calc(100%+8px)] w-72 rounded-xl bg-surface shadow-pop border border-slate-200/80 dark:border-white/[.07] overflow-hidden z-50" id="notifDropdown">
                <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 dark:border-white/[.06] text-[13px] font-bold">
                    <span>Bildirimler</span>
                    <button class="text-[11px] font-semibold text-brand hover:underline" id="notifClearAll">Tümünü Gördüm</button>
                </div>
                <div class="max-h-60 overflow-y-auto text-[13px]" id="notifList">
                    <div class="px-4 py-6 text-center text-[12px] text-slate-400">Yeni bildirim yok</div>
                </div>
                <div class="flex items-center justify-between gap-2 px-2 py-1 border-t border-slate-100 dark:border-white/[.06]" style="background:rgba(100,116,139,.06)">
                    <button type="button" id="notifMuteBtn" class="flex items-center gap-2 px-3 py-2 rounded-md text-[11px] font-medium text-slate-500 hover:text-brand transition" title="Bildirim Sesi Aç/Kapat"><i data-lucide="bell" class="w-3.5 h-3.5"></i> Sesi Aç/Kapat</button>
                    <a href="/settings?tab=sounds" class="flex items-center gap-2 px-3 py-2 rounded-md text-[11px] font-medium text-slate-500 hover:text-brand transition"><i data-lucide="settings" class="w-3.5 h-3.5"></i> Ayarlar</a>
                </div>
            </div>
        </div>

        <!-- Tema -->
        <button onclick="ozyToggleTheme()" class="t grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500" title="Tema">
            <i data-lucide="moon" class="w-[18px] h-[18px] dark:hidden"></i>
            <i data-lucide="sun" class="w-[18px] h-[18px] hidden dark:block"></i>
        </button>

        <span class="w-px h-5 bg-slate-200 dark:bg-white/10 mx-0.5"></span>

        <!-- Kullanıcı (mağaza adı artık sol üstteki çipte) -->
        <div class="ozy-user-block flex items-center gap-2.5 h-9 pl-1" title="<?= htmlspecialchars($userName) ?>">
            <span class="hidden md:flex flex-col items-end leading-tight">
                <span class="text-[13px] font-semibold text-slate-900 dark:text-white max-w-[160px] truncate"><?= htmlspecialchars($userName) ?></span>
                <span class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($userRoleLabel) ?></span>
            </span>
            <span class="grid place-items-center w-9 h-9 rounded-full bg-brand text-white text-[12px] font-bold shrink-0"><?= htmlspecialchars($userInitial) ?></span>
        </div>
        <a href="/logout" class="t grid place-items-center w-9 h-9 rounded-lg hover:bg-danger/10 hover:text-danger text-slate-500" title="Çıkış"><i data-lucide="log-out" class="w-[18px] h-[18px]"></i></a>
    </div>
</header>

<!-- ════════ İÇERİK ════════ -->
<main class="mx-auto max-w-[95%] px-4 lg:px-6 py-6">
    <?php if (!empty($data['_flash']['success'])): ?>
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-[13px] text-emerald-700 dark:text-emerald-400" data-dismiss><i data-lucide="check-circle-2" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= htmlspecialchars($data['_flash']['success']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($data['_flash']['error'])): ?>
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-[13px] text-rose-700 dark:text-rose-400" data-dismiss><i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= htmlspecialchars($data['_flash']['error']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($data['_flash']['warning'])): ?>
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-warning/20 bg-warning/10 px-4 py-3 text-[13px] text-amber-700 dark:text-amber-400" data-dismiss><i data-lucide="alert-triangle" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= htmlspecialchars($data['_flash']['warning']) ?></span></div>
    <?php endif; ?>
    <?php
    // ── Otomatik sayfa başlığı ─────────────────────────────────────────────
    // Kendi başlığı olmayan görünümler için tek tipografi kaynağı (.ui-page-head).
    // Anahtar: Response::view() kapsamındaki $viewPath ('finance.index' gibi).
    // Başlık boşsa $pt (pageTitle) kullanılır. Kendi başlığını çizen görünümler listede YOK.
    $otoBaslik = [
        'finance.index'           => ['Finans', 'Pazaryeri hakedişleri, komisyonlar ve kârlılık özeti.'],
        'invoices.index'          => ['Faturalar', 'Siparişlerinize ait faturaları oluşturun, gönderin ve takip edin.'],
        'invoices.settings'       => ['Fatura Ayarları', 'e-Fatura sağlayıcı, seri ve gönderim ayarları.'],
        'invoices.show'           => ['', 'Fatura ayrıntıları ve gönderim durumu.'],
        'sync.index'              => ['Senkronizasyon', 'Pazaryeri veri eşitleme işlemleri ve geçmişi.'],
        'import.index'            => ['İçe / Dışa Aktarma', 'XML, Excel ve pazaryeri kaynaklarından ürün alın; kataloğunuzu dışa aktarın.'],
        'xml_profiles.index'      => ['XML Profilleri', 'Tedarikçi XML kaynaklarını tanımlayın, zamanlayın ve içe aktarın.'],
        'xml_profiles.form'       => ['', 'Kaynak, alan eşlemesi ve ürün varsayılanları.'],
        'xml_profiles.logs'       => ['XML Profili Kayıtları', 'İçe aktarım geçmişi ve hata ayrıntıları.'],
        'feeds.index'             => ['XML Feedler', 'Pazaryerleri ve reklam platformları için ürün feed bağlantıları.'],
        'feeds.form'              => ['', 'Feed kaynağı ve alan ayarları.'],
        'feeds.show'              => ['', 'Feed çıktısı ve bağlantı bilgileri.'],
        'brands.mapping'          => ['Marka Eşleştirme', 'Markalarınızı seçili pazaryerinin marka listesine bağlayın.'],
        'categories.mapping'      => ['Kategori Eşleştirme', 'Kategorilerinizi seçili pazaryerinin kategori ağacına bağlayın.'],
        'mapping.auto'            => ['Akıllı Eşleştirme', 'Kategori ve markaları yapay zekâ ile toplu eşleştirin.'],
        'products.price_rules'    => ['Fiyat Kuralları', 'Pazaryeri bazında otomatik fiyatlandırma kuralları.'],
        'products.stock_movements'=> ['Stok Hareketleri', 'Satış, iade ve düzeltme kaynaklı stok değişimleri.'],
        'authorization.index'     => ['Yetkilendirme', 'Kullanıcılar, roller ve izinler.'],
        'backups.index'           => ['Yedekler', 'Mağaza verilerinizi dışa aktarın ve yedekleyin.'],
        'enrichment.dashboard'    => ['Zenginleştirme', 'Ürün içeriklerinin tamamlanma durumu ve öneriler.'],
    ];
    $otoKey = (string) ($viewPath ?? '');
    if (isset($otoBaslik[$otoKey])):
        [$otoT, $otoS] = $otoBaslik[$otoKey];
        $otoT = $otoT !== '' ? $otoT : (string) $pt;
    ?>
    <div class="ui-page-head">
        <div>
            <h1><?= htmlspecialchars($otoT) ?></h1>
            <?php if ($otoS !== ''): ?><p><?= htmlspecialchars($otoS) ?></p><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?= $content ?? '' ?>
</main>

<!-- ════════ Mobil çekmece ════════ -->
<div id="mobDrawerOverlay" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-200 lg:hidden"></div>
<nav id="mobDrawer" class="fixed top-0 left-0 z-50 h-full w-72 max-w-[85%] bg-surface shadow-pop -translate-x-full transition-transform duration-200 ease-out lg:hidden flex flex-col">
    <div class="flex items-center justify-between px-5 h-[60px] border-b border-slate-200/80 dark:border-white/[.07]">
        <span class="font-bold tracking-tight text-[16px]"><?= htmlspecialchars($brandName) ?></span>
        <button id="mobDrawerClose" class="grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500"><i data-lucide="x" class="w-[18px] h-[18px]"></i></button>
    </div>
    <div class="flex-1 overflow-y-auto p-3">
        <?php foreach ($navGroupsVis as $g): ?>
        <div class="pt-3 first:pt-0">
            <div class="px-3 pb-1 text-[10.5px] font-bold uppercase tracking-wider text-slate-400"><?= htmlspecialchars($g['l']) ?></div>
            <?php foreach ($g['items'] as $n): $a = $isActive($n); ?>
            <a href="<?= htmlspecialchars($n['u']) ?>" class="t flex items-center gap-3 h-10 px-3 rounded-lg text-[13.5px] font-medium <?= $a ? 'bg-brand/10 text-brand font-semibold' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]' ?>">
                <i data-lucide="<?= htmlspecialchars($n['i']) ?>" class="w-[18px] h-[18px]"></i><?= htmlspecialchars($n['l']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="p-3 border-t border-slate-200/80 dark:border-white/[.07] space-y-0.5">
        <a href="/settings/guncellemeler" class="t flex items-center gap-3 h-10 px-3 rounded-lg text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]"><i data-lucide="megaphone" class="w-[18px] h-[18px]"></i> Güncellemeler<?php if ($updateUnread > 0): ?><span class="ml-auto grid place-items-center min-w-[18px] h-[18px] px-1 rounded-full bg-danger text-white text-[10px] font-bold leading-none"><?= $updateUnread > 99 ? '99+' : $updateUnread ?></span><?php endif; ?></a>
        <a href="/settings/destek" class="t flex items-center gap-3 h-10 px-3 rounded-lg text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]"><i data-lucide="life-buoy" class="w-[18px] h-[18px]"></i> Destek<?php if ($supportUnread > 0): ?><span class="ml-auto grid place-items-center min-w-[18px] h-[18px] px-1 rounded-full bg-danger text-white text-[10px] font-bold leading-none"><?= $supportUnread > 99 ? '99+' : $supportUnread ?></span><?php endif; ?></a>
        <a href="/logout" class="t flex items-center gap-3 h-10 px-3 rounded-lg text-[13.5px] font-medium text-danger hover:bg-danger/10"><i data-lucide="log-out" class="w-[18px] h-[18px]"></i> Çıkış Yap</a>
    </div>
</nav>

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
            <?php /* ⌘K öğeleri navbar ile AYNI entitlement-filtreli $flatNav'den — yetkisiz modül çıkmaz */ ?>
            <?php foreach ($flatNav as $n): ?>
            <a href="<?= htmlspecialchars($n['u']) ?>" class="ozy-cmd t flex items-center gap-3 px-3 h-11 rounded-xl hover:bg-brand/[.08] text-[13.5px]" data-k="<?= htmlspecialchars(mb_strtolower($n['l'] . ' ' . $n['m'], 'UTF-8')) ?>">
                <span class="grid place-items-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-white/10 text-slate-500"><i data-lucide="<?= htmlspecialchars($n['i']) ?>" class="w-4 h-4"></i></span>
                <?= htmlspecialchars($n['l']) ?>
            </a>
            <?php endforeach; ?>
            <div class="ozy-cmd-empty hidden px-3 py-6 text-center text-[12px] text-slate-400">Sonuç yok</div>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script src="<?= htmlspecialchars(asset('js/app.js')) ?>?v=20260913"></script>
<script src="<?= htmlspecialchars(asset('js/ozy.js')) ?>?v=20260614"></script>
<script>
(function(){
    if (window.lucide) lucide.createIcons();
    // Bildirim dropdown aç/kapat (app.js zaten içeriği doldurabilir)
    var nt = document.getElementById('notifToggle'), nd = document.getElementById('notifDropdown');
    if (nt && nd) {
        nt.addEventListener('click', function(e){ e.stopPropagation(); nd.classList.toggle('hidden'); });
        document.addEventListener('click', function(){ nd.classList.add('hidden'); });
        nd.addEventListener('click', function(e){ e.stopPropagation(); });
    }
    // Mobil çekmece
    var mt=document.getElementById('mobMenuToggle'), md=document.getElementById('mobDrawer'), mo=document.getElementById('mobDrawerOverlay'), mc=document.getElementById('mobDrawerClose');
    function openD(){ md.classList.remove('-translate-x-full'); mo.classList.remove('opacity-0','pointer-events-none'); }
    function shutD(){ md.classList.add('-translate-x-full'); mo.classList.add('opacity-0','pointer-events-none'); }
    if(mt) mt.addEventListener('click', openD);
    if(mo) mo.addEventListener('click', shutD);
    if(mc) mc.addEventListener('click', shutD);
    // Flash auto-dismiss
    setTimeout(function(){ document.querySelectorAll('[data-dismiss]').forEach(function(el){ el.style.transition='opacity .4s'; el.style.opacity='0'; setTimeout(function(){ el.remove(); }, 400); }); }, 5000);
})();
</script>
</body>
</html>
