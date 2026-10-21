<?php
/** Admin — Mağaza Eklentileri (add-on): pakete ek pazaryeri/modül ekle-çıkar + opsiyonel ek fatura. Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$store        = $store ?? [];
$sub          = $sub ?? false;
$marketplaces = $marketplaces ?? [];
$modules      = $modules ?? [];
$activeMpIds  = $activeMpIds ?? [];
$activeModKeys= $activeModKeys ?? [];
$pkgMpIds     = $pkgMpIds ?? [];
$pkgModKeys   = $pkgModKeys ?? [];
$addonMpIds   = $addonMpIds ?? [];   // id => price
$addonModKeys = $addonModKeys ?? []; // key => price
$sid          = (int) ($store['id'] ?? 0);
$csrf         = $e($_csrf ?? '');
$money        = fn($v) => '₺' . number_format((float) $v, ((float) $v == (int) $v) ? 0 : 2, ',', '.');
$pkgName      = (string) ($sub['package_name'] ?? ($store['plan'] ?? '—'));
$cycle        = ($sub && ($sub['billing_cycle'] ?? '') === 'yearly') ? 'Yıllık' : 'Aylık';

// Aktif add-on toplamı (aylık) + yenileme projeksiyonu (paket + add-on, döngüye göre)
$addonTotal    = array_sum($addonMpIds) + array_sum($addonModKeys);
$cycleYearly   = ($sub && ($sub['billing_cycle'] ?? '') === 'yearly');
$baseForCycle  = (float) ($sub ? ($cycleYearly ? ($sub['price_yearly'] ?? 0) : ($sub['price_monthly'] ?? 0)) : 0);
$addonForCycle = $cycleYearly ? $addonTotal * 12 : $addonTotal;
$renewalTotal  = $baseForCycle + $addonForCycle;
$cycleSuffix   = $cycleYearly ? '/yıl' : '/ay';

$addUrl = url('/admin/subscriptions/' . $sid . '/addons/add');
$remUrl = url('/admin/subscriptions/' . $sid . '/addons/remove');
boss_page_header('Eklentiler', [['l' => 'Abonelikler', 'u' => '/admin/subscriptions'], ['l' => (string) ($store['name'] ?? ('#' . $sid))]]);
?>
<style>
.addon-row{ display:grid; grid-template-columns: minmax(0,1fr) 130px minmax(220px,300px); gap:14px; align-items:center; padding:11px 16px; border-top:1px solid var(--ad-border); }
.addon-row:hover{ background:var(--ad-surface-2); }
.addon-head{ display:grid; grid-template-columns: minmax(0,1fr) 130px minmax(220px,300px); gap:14px; padding:9px 16px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:var(--ad-muted); }
.addon-sub{ font-size:11px; color:var(--ad-muted); margin-top:2px; }
.addon-act{ display:flex; align-items:center; justify-content:flex-end; gap:10px; }
@media (max-width:780px){ .addon-head{ display:none; } .addon-row{ grid-template-columns:1fr; gap:8px; } .addon-act{ justify-content:flex-start; } }
</style>

<div class="d-flex flex-wrap align-items-center gap-3 mb-3">
  <a href="<?= url('/admin/subscriptions') ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Abonelikler</a>
  <div class="fs-2 text-muted">
    <i class="ti ti-building-store me-1"></i><b class="text-body"><?= $e($store['name'] ?? ('#' . $sid)) ?></b>
    · Paket: <b class="text-body"><?= $e($pkgName) ?></b> (<?= $e($cycle) ?>)
  </div>
  <a href="<?= url('/stores/' . $sid . '/entitlements') ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="ti ti-adjustments-horizontal me-1"></i>Detaylı Yetkiler</a>
</div>

<?php if ($sub): ?>
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3 py-3">
    <div class="d-flex flex-wrap align-items-center gap-2 fs-2">
      <span class="text-muted">Yenileme tutarı:</span>
      <span>Paket <b class="text-body"><?= $e($money($baseForCycle)) ?></b></span>
      <span class="text-muted">+</span>
      <span>Eklenti <b class="text-body"><?= $e($money($addonForCycle)) ?></b></span>
      <span class="text-muted">=</span>
    </div>
    <div class="fs-4 fw-bold text-primary"><?= $e($money($renewalTotal)) ?><span class="fs-2 text-muted fw-normal"><?= $e($cycleSuffix) ?></span></div>
  </div>
</div>
<?php endif; ?>

<?php
/** Tek satır render (hem pazaryeri hem modül için). */
$row = function (string $type, string $idStr, string $name, string $subText, float $price, bool $inPkg, bool $active, bool $isAddon)
    use ($e, $csrf, $money, $addUrl, $remUrl): void {
    $idField = $type === 'marketplace' ? 'marketplace_id' : 'module_key';
    ?>
    <div class="addon-row">
      <div>
        <div class="fw-semibold"><?= $e($name) ?></div>
        <?php if ($subText !== ''): ?><div class="addon-sub"><?= $e($subText) ?></div><?php endif; ?>
      </div>
      <div class="fw-medium <?= $price > 0 ? '' : 'text-muted' ?>"><?= $price > 0 ? $e($money($price)) . '<span class="text-muted fs-1">/ay</span>' : '—' ?></div>
      <div class="addon-act">
        <?php if ($inPkg): ?>
          <span class="badge bg-info-subtle text-info rounded-pill"><i class="ti ti-package me-1"></i>Pakete dahil</span>
        <?php elseif ($active): ?>
          <span class="badge bg-success-subtle text-success rounded-pill"><i class="ti ti-plus me-1"></i><?= $isAddon ? 'Eklenti' : 'Aktif (ekstra)' ?></span>
          <form method="post" action="<?= $remUrl ?>" onsubmit="return confirm('“<?= $e($name) ?>” çıkarılsın mı? Yetki kalkar, yenileme faturası düşer.')">
            <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="type" value="<?= $e($type) ?>">
            <input type="hidden" name="<?= $idField ?>" value="<?= $e($idStr) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-x me-1"></i>Çıkar</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= $addUrl ?>" class="d-flex align-items-center gap-2">
            <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="type" value="<?= $e($type) ?>">
            <input type="hidden" name="<?= $idField ?>" value="<?= $e($idStr) ?>">
            <?php if ($price > 0): ?>
            <div class="form-check mb-0" title="İşaretlenirse ek fatura kesilir">
              <input class="form-check-input" type="checkbox" name="create_invoice" value="1" id="ci_<?= $e($type . '_' . $idStr) ?>">
              <label class="form-check-label fs-1 text-muted" for="ci_<?= $e($type . '_' . $idStr) ?>">Ek fatura</label>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-plus me-1"></i>Ekle</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>

<!-- Pazaryerleri -->
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-building-store me-1 text-muted"></i>Pazaryerleri</h3></div>
  <div class="card-body p-0">
    <div class="addon-head"><div>Pazaryeri</div><div>Aylık Fiyat</div><div class="text-end pe-1">Durum / İşlem</div></div>
    <?php foreach ($marketplaces as $m):
      $mid = (int) $m['id'];
      $sellable = (int) ($m['is_sellable'] ?? 0) === 1;
      $activeM  = in_array($mid, array_map('intval', $activeMpIds), true);
      $inPkg    = in_array($mid, array_map('intval', $pkgMpIds), true);
      $isAddon  = isset($addonMpIds[$mid]);
      $price    = (float) ($m['price_monthly'] ?? 0);
      // Pasif/satışa kapalı ve aktif değilse eklenemez → bilgi satırı
      if (!$inPkg && !$activeM && !$sellable) {
        echo '<div class="addon-row"><div><div class="fw-semibold text-muted">' . $e($m['display_name'] ?? '') . '</div><div class="addon-sub font-monospace">' . $e($m['slug'] ?? '') . '</div></div><div class="text-muted">—</div><div class="addon-act"><span class="badge bg-secondary-subtle text-muted rounded-pill">Satışa kapalı</span></div></div>';
        continue;
      }
      $row('marketplace', (string) $mid, (string) ($m['display_name'] ?? ''), (string) ($m['slug'] ?? ''), $price, $inPkg, $activeM, $isAddon);
    endforeach; ?>
  </div>
</div>

<!-- Modüller -->
<div class="card mb-0">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-puzzle me-1 text-muted"></i>Modüller</h3></div>
  <div class="card-body p-0">
    <div class="addon-head"><div>Modül</div><div>Aylık Fiyat</div><div class="text-end pe-1">Durum / İşlem</div></div>
    <?php foreach ($modules as $m):
      $key = (string) ($m['module_key'] ?? '');
      if ($key === '') { continue; }
      $sellable = (int) ($m['is_sellable'] ?? 0) === 1;
      $activeM  = in_array($key, $activeModKeys, true);
      $inPkg    = in_array($key, $pkgModKeys, true);
      $isAddon  = isset($addonModKeys[$key]);
      $price    = (float) ($m['price_monthly'] ?? 0);
      if (!$inPkg && !$activeM && !$sellable) {
        echo '<div class="addon-row"><div><div class="fw-semibold text-muted">' . $e($m['label'] ?? $key) . '</div><div class="addon-sub font-monospace">' . $e($key) . '</div></div><div class="text-muted">—</div><div class="addon-act"><span class="badge bg-secondary-subtle text-muted rounded-pill">Satışa kapalı</span></div></div>';
        continue;
      }
      $row('module', $key, (string) ($m['label'] ?? $key), (string) ($m['description'] ?? $key), $price, $inPkg, $activeM, $isAddon);
    endforeach; ?>
  </div>
</div>
