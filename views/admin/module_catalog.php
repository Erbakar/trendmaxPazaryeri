<?php
/** Satılabilir Modüller — Modernize/Lumina. GLOBAL module_catalog. Kompakt satır-liste (her satır kendi formu). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$modules = $modules ?? [];
boss_page_header('Satılabilir Modüller', [['l' => 'Siparişler', 'u' => '/admin/packages'], ['l' => 'Modüller']]);
?>
<style>
.modcat-row, .modcat-head{ display:grid; grid-template-columns: minmax(0,1fr) 150px 132px 116px; gap:16px; align-items:center; }
.modcat-head{ padding:10px 16px; font-size:11px; font-weight:600; letter-spacing:.03em; text-transform:uppercase; color:var(--ad-muted); }
.modcat-row{ padding:10px 16px; margin:0; border-top:1px solid var(--ad-border); }
.modcat-row:hover{ background:var(--ad-surface-2); }
.modcat-sub{ font-size:11px; color:var(--ad-muted); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width: 820px){
  .modcat-head{ display:none; }
  .modcat-row{ grid-template-columns: 1fr 1fr; gap:10px; padding:14px 16px; }
  .modcat-row .mc-name{ grid-column:1 / -1; }
  .modcat-row .mc-act{ grid-column:1 / -1; }
}
</style>

<p class="text-muted fs-2 mb-3">Bu fiyatlar mağazaların «Modül Ekle» ekranında add-on olarak görünür.</p>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-package me-1 text-muted"></i>Modül Kataloğu</h3>
    <span class="badge bg-secondary-subtle text-muted fw-normal"><?= count($modules) ?> modül</span>
  </div>

  <?php if ($modules === []): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-package-off d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz katalogda modül bulunmuyor.</div></div>
  <?php else: ?>
  <div class="card-body p-0">
    <div class="modcat-head">
      <div>Modül</div>
      <div>Aylık Fiyat (₺)</div>
      <div>Satışa Açık</div>
      <div class="text-end"></div>
    </div>

    <?php foreach ($modules as $m):
      $key   = (string) ($m['module_key'] ?? '');
      $label = (string) ($m['label'] ?? '');
      $desc  = (string) ($m['description'] ?? '');
      $price = (float) ($m['price_monthly'] ?? 0);
      $sell  = (int) ($m['is_sellable'] ?? 0) === 1;
      $tid   = 'sell_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
    ?>
    <form method="post" action="<?= url('/admin/module-catalog/' . rawurlencode($key)) ?>" class="modcat-row">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <div class="mc-name">
        <input type="text" name="label" value="<?= $e($label) ?>" placeholder="<?= $e($key) ?>" class="form-control form-control-sm">
        <div class="modcat-sub"><span class="font-monospace"><?= $e($key) ?></span><?php if ($desc !== ''): ?> · <?= $e($desc) ?><?php endif; ?></div>
      </div>
      <div class="mc-price">
        <input type="number" step="0.01" min="0" name="price_monthly" value="<?= $e(number_format($price, 2, '.', '')) ?>" class="form-control form-control-sm">
      </div>
      <div class="mc-sell">
        <input type="hidden" name="is_sellable" value="0">
        <?= boss_switch('is_sellable', $sell, ['id' => $tid, 'on' => 'Açık', 'off' => 'Kapalı']) ?>
      </div>
      <div class="mc-act text-end">
        <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
      </div>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
