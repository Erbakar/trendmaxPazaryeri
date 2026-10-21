<?php
/** Faz 2 — Mağaza entitlement yönetimi (Super Admin) · admin layout · store-entitlement.html birebir.
 * Pazaryeri + Modül kartları; her switch bir SUBMIT (grant/revoke POST). Sebep + bitiş opsiyonel (expander).
 * Korunan: 2 form (/marketplaces, /modules) + _csrf_token + marketplace_id/module_key + action + expires_at + reason. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$sid = (int) ($store['id'] ?? 0);
$csrf = (string) ($_csrf ?? '');
$store = $store ?? [];
$marketplaces = $marketplaces ?? [];
$smRows = $smRows ?? [];
$modules = $modules ?? [];
$modRows = $modRows ?? [];
$pkgMpIds = $pkgMpIds ?? [];
$pkgModKeys = $pkgModKeys ?? [];
$moduleLabels = $moduleLabels ?? [];
$srcBadge = static function (bool $inPkg, bool $granted): string {
    if ($inPkg) { return '<span class="inline-block px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-brand/10 text-brand align-middle">PAKET</span>'; }
    if ($granted) { return '<span class="inline-block px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-amber-500/10 text-amber-600 align-middle">EKSTRA</span>'; }
    return '';
};

$months = [1=>'Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$fmtExp = function (?string $dt) use ($months): string {
    if (!$dt) { return ''; }
    $ts = strtotime($dt);
    return $ts ? (date('d', $ts) . ' ' . ($months[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts)) : (string) $dt;
};
$stateText = function (?array $row) use ($fmtExp): string {
    if (!$row || ($row['status'] ?? '') !== 'active') { return 'Kapalı'; }
    $exp = $fmtExp($row['expires_at'] ?? null);
    return $exp !== '' ? 'Açık · bitiş ' . $exp : 'Açık';
};

$modMeta = [
    'orders'     => ['Siparişler',      'shopping-bag'],
    'returns'    => ['İadeler',         'rotate-ccw'],
    'questions'  => ['Sorular',         'message-circle-question'],
    'products'   => ['Ürünler',         'package'],
    'categories' => ['Kategoriler',     'folder-tree'],
    'brands'     => ['Markalar',        'tag'],
    'customers'  => ['Müşteriler',      'users'],
    'invoices'   => ['Faturalar',       'file-text'],
    'finance'    => ['Finans',          'wallet'],
    'shipping'   => ['Kargo',           'truck'],
    'feeds'      => ['XML Feed',        'rss'],
    'import'     => ['İçe Aktarma',     'upload'],
    'sync'       => ['Senkronizasyon',  'refresh-cw'],
];

$mpActive = 0;
foreach ($marketplaces as $m) { if (($smRows[(int) $m['id']] ?? null) && ($smRows[(int) $m['id']]['status'] ?? '') === 'active') { $mpActive++; } }
$modActive = 0;
foreach ($modules as $k) { if (($modRows[$k] ?? null) && ($modRows[$k]['status'] ?? '') === 'active') { $modActive++; } }

$storeStatus = (string) ($store['status'] ?? 'active');
$storeStMeta = ['active' => ['Aktif','bg-success/10 text-success','bg-success'], 'suspended' => ['Askıda','bg-warning/10 text-warning','bg-warning'], 'archived' => ['Arşiv','bg-slate-500/10 text-slate-500','bg-slate-400']][$storeStatus] ?? ['—','bg-slate-500/10 text-slate-500','bg-slate-400'];
?>
<!-- breadcrumb -->
<nav class="flex items-center gap-1.5 text-[12.5px] text-slate-400 mb-4">
  <a href="<?= url('/stores') ?>" class="t hover:text-slate-700 dark:hover:text-slate-200 font-medium">Mağazalar</a>
  <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
  <span class="font-semibold text-slate-600 dark:text-slate-300"><?= $e($store['name'] ?? '') ?></span>
</nav>

<!-- başlık -->
<div class="flex flex-wrap items-end justify-between gap-4 mb-8">
  <div>
    <div class="eyebrow text-slate-400">Mağaza Yetkileri</div>
    <div class="mt-1.5 flex flex-wrap items-center gap-3">
      <h1 class="text-[28px] font-bold tracking-tight leading-none"><?= $e($store['name'] ?? '') ?></h1>
      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $storeStMeta[1] ?>"><span class="w-1.5 h-1.5 rounded-full <?= $storeStMeta[2] ?>"></span><?= $e($storeStMeta[0]) ?></span>
    </div>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-[12.5px] text-slate-400">
      <span class="font-mono">store#<?= $sid ?></span>
      <span class="w-1 h-1 rounded-full bg-slate-300 dark:bg-slate-600"></span>
      <span class="font-mono"><?= $e($store['slug'] ?? '') ?></span>
    </div>
  </div>
  <a href="<?= url('/ops/audit') ?>" class="t flex items-center gap-2 h-10 px-3.5 rounded-xl border border-slate-200/80 dark:border-white/10 text-[13px] font-medium hover:shadow-card"><i data-lucide="history" class="w-4 h-4 text-slate-400"></i> Audit Geçmişi</a>
</div>

<!-- ════ BÖLÜM 1: Pazaryeri Yetkileri ════ -->
<section class="rounded-2xl bg-surface p-5 lg:p-6 shadow-card mb-5">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex items-center gap-2.5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="store" class="w-[18px] h-[18px]"></i></span>
      <div>
        <h2 class="text-[15px] font-bold tracking-tight leading-none">Pazaryeri Yetkileri</h2>
        <div class="mt-1 text-[12px] text-slate-400"><span class="font-semibold text-success"><?= $mpActive ?></span> aktif · <?= count($marketplaces) ?> pazaryeri</div>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
    <?php foreach ($marketplaces as $m):
      $mid = (int) $m['id'];
      $row = $smRows[$mid] ?? null;
      $active = $row && ($row['status'] ?? '') === 'active';
      $slug = (string) ($m['slug'] ?? '');
      $name = (string) ($m['display_name'] ?? $slug);
    ?>
    <form method="post" action="<?= url('/stores/' . $sid . '/marketplaces') ?>" class="ent-card t rounded-xl border border-slate-100 dark:border-white/[.06] hover:shadow-card overflow-hidden">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="marketplace_id" value="<?= $mid ?>">
      <input type="hidden" name="action" value="<?= $active ? 'revoke' : 'grant' ?>">
      <div class="flex items-center justify-between gap-3 p-3.5">
        <div class="flex items-center gap-3 min-w-0">
          <span class="logo-chip shrink-0" style="padding:6px 9px"><img class="mp-logo" style="height:17px<?= $active ? '' : ';filter:grayscale(1);opacity:.7' ?>" src="<?= $e(asset('img/marketplaces/' . $slug . '.png')) ?>" alt="<?= $e($name) ?>" onerror="this.onerror=null;this.src='<?= $e(asset('img/marketplaces/' . $slug . '.jpg')) ?>';this.classList.add('mp-logo-fb')"></span>
          <div class="min-w-0">
            <div class="text-[13.5px] font-semibold leading-tight truncate"><?= $e($name) ?> <?= $srcBadge(in_array($mid, $pkgMpIds, true), (bool) $row) ?></div>
            <div class="text-[11.5px] text-slate-400"><?= $e($stateText($row)) ?></div>
          </div>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
          <button type="button" onclick="entToggleOpts(this)" class="t grid place-items-center w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-900/[.05] dark:hover:bg-white/10" title="Sebep / bitiş ekle"><i data-lucide="sliders-horizontal" class="w-4 h-4"></i></button>
          <button type="submit" class="switch appearance-none border-0 p-0 box-border" aria-checked="<?= $active ? 'true' : 'false' ?>" title="<?= $active ? 'Yetkiyi kaldır' : 'Yetki ver' ?>"></button>
        </div>
      </div>
      <div class="ent-opts hidden border-t border-slate-100 dark:border-white/[.06] px-3.5 py-3 flex flex-wrap items-center gap-2 bg-slate-900/[.015] dark:bg-white/[.02]">
        <?php if (!$active): ?>
        <label class="text-[11.5px] text-slate-400 flex items-center gap-1.5">Bitiş
          <input type="date" name="expires_at" class="h-8 px-2 rounded-lg border border-slate-200 dark:border-white/10 bg-surface text-[12px] outline-none focus:border-brand">
        </label>
        <?php endif; ?>
        <input type="text" name="reason" maxlength="255" placeholder="Sebep (opsiyonel)" class="flex-1 min-w-[120px] h-8 px-2.5 rounded-lg border border-slate-200 dark:border-white/10 bg-surface text-[12px] outline-none focus:border-brand">
        <button type="submit" class="t h-8 px-3 rounded-lg bg-brand text-white text-[12px] font-semibold hover:brightness-110"><?= $active ? 'Kaldır' : 'Ver' ?></button>
      </div>
    </form>
    <?php endforeach; ?>
  </div>
</section>

<!-- ════ BÖLÜM 2: Modül Yetkileri ════ -->
<section class="rounded-2xl bg-surface p-5 lg:p-6 shadow-card">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex items-center gap-2.5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="layout-grid" class="w-[18px] h-[18px]"></i></span>
      <div>
        <h2 class="text-[15px] font-bold tracking-tight leading-none">Modül Yetkileri</h2>
        <div class="mt-1 text-[12px] text-slate-400"><span class="font-semibold text-success"><?= $modActive ?></span> aktif · <?= count($modules) ?> modül</div>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
    <?php foreach ($modules as $key):
      $row = $modRows[$key] ?? null;
      $active = $row && ($row['status'] ?? '') === 'active';
      [$mLbl, $icon] = $modMeta[$key] ?? [$key, 'box'];
      $label = $moduleLabels[$key] ?? $mLbl;
    ?>
    <form method="post" action="<?= url('/stores/' . $sid . '/modules') ?>" class="ent-card t rounded-xl border border-slate-100 dark:border-white/[.06] hover:shadow-card overflow-hidden">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="module_key" value="<?= $e($key) ?>">
      <input type="hidden" name="action" value="<?= $active ? 'revoke' : 'grant' ?>">
      <div class="flex items-center justify-between gap-3 px-3.5 py-3">
        <span class="flex items-center gap-2.5 min-w-0">
          <span class="grid place-items-center w-7 h-7 rounded-lg <?= $active ? 'bg-brand/10 text-brand' : 'bg-slate-100 dark:bg-white/10 text-slate-500' ?> shrink-0"><i data-lucide="<?= $e($icon) ?>" class="w-4 h-4"></i></span>
          <span class="min-w-0">
            <span class="block text-[13px] font-medium truncate"><?= $e($label) ?> <?= $srcBadge(in_array($key, $pkgModKeys, true), (bool) $row) ?></span>
            <span class="block text-[11px] text-slate-400 font-mono truncate"><?= $e($key) ?></span>
          </span>
        </span>
        <div class="flex items-center gap-1.5 shrink-0">
          <button type="button" onclick="entToggleOpts(this)" class="t grid place-items-center w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-900/[.05] dark:hover:bg-white/10" title="Sebep / bitiş ekle"><i data-lucide="sliders-horizontal" class="w-4 h-4"></i></button>
          <button type="submit" class="switch appearance-none border-0 p-0 box-border" aria-checked="<?= $active ? 'true' : 'false' ?>" title="<?= $active ? 'Yetkiyi kaldır' : 'Yetki ver' ?>"></button>
        </div>
      </div>
      <div class="ent-opts hidden border-t border-slate-100 dark:border-white/[.06] px-3.5 py-3 flex flex-wrap items-center gap-2 bg-slate-900/[.015] dark:bg-white/[.02]">
        <?php if (!$active): ?>
        <label class="text-[11.5px] text-slate-400 flex items-center gap-1.5">Bitiş
          <input type="date" name="expires_at" class="h-8 px-2 rounded-lg border border-slate-200 dark:border-white/10 bg-surface text-[12px] outline-none focus:border-brand">
        </label>
        <?php endif; ?>
        <input type="text" name="reason" maxlength="255" placeholder="Sebep (opsiyonel)" class="flex-1 min-w-[120px] h-8 px-2.5 rounded-lg border border-slate-200 dark:border-white/10 bg-surface text-[12px] outline-none focus:border-brand">
        <button type="submit" class="t h-8 px-3 rounded-lg bg-brand text-white text-[12px] font-semibold hover:brightness-110"><?= $active ? 'Kaldır' : 'Ver' ?></button>
      </div>
    </form>
    <?php endforeach; ?>
  </div>

  <div class="mt-5 flex items-center gap-2 text-[12px] text-slate-400">
    <i data-lucide="shield-check" class="w-3.5 h-3.5 text-brand"></i>
    Her değişiklik audit-lenir · anahtara tıklamak anında uygular. Sebep/bitiş için <i data-lucide="sliders-horizontal" class="w-3.5 h-3.5"></i> simgesini kullanın.
  </div>
</section>

<script>
function entToggleOpts(btn){
  var form = btn.closest('form');
  if (!form) return;
  var panel = form.querySelector('.ent-opts');
  if (panel) panel.classList.toggle('hidden');
}
</script>
