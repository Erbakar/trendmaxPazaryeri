<?php
/** Satılabilir Pazaryerleri — Modernize/Lumina. GLOBAL marketplaces add-on fiyatları. Kompakt satır-liste (her satır kendi formu). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$marketplaces = $marketplaces ?? [];
boss_page_header('Satılabilir Pazaryerleri', [['l' => 'Abonelik', 'u' => '/admin/packages'], ['l' => 'Pazaryerleri']]);
?>
<style>
.mpcat-row, .mpcat-head{ display:grid; grid-template-columns: minmax(0,1fr) 150px 132px 116px; gap:16px; align-items:center; }
.mpcat-head{ padding:10px 16px; font-size:11px; font-weight:600; letter-spacing:.03em; text-transform:uppercase; color:var(--ad-muted); }
.mpcat-row{ padding:12px 16px; margin:0; border-top:1px solid var(--ad-border); }
.mpcat-row:hover{ background:var(--ad-surface-2); }
.mpcat-sub{ font-size:11px; color:var(--ad-muted); margin-top:3px; }
@media (max-width: 820px){
  .mpcat-head{ display:none; }
  .mpcat-row{ grid-template-columns: 1fr 1fr; gap:10px; padding:14px 16px; }
  .mpcat-row .mp-name, .mpcat-row .mp-act{ grid-column:1 / -1; }
}
</style>

<p class="text-muted fs-2 mb-3">Bu fiyatlar, mağazaya pakete ek olarak pazaryeri eklenirken add-on ücreti olarak kullanılır.</p>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-building-store me-1 text-muted"></i>Pazaryeri Kataloğu</h3>
    <span class="badge bg-secondary-subtle text-muted fw-normal"><?= count($marketplaces) ?> pazaryeri</span>
  </div>

  <?php if ($marketplaces === []): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-building-store d-block mb-2" style="font-size:34px;opacity:.5;"></i>Tanımlı pazaryeri yok.</div></div>
  <?php else: ?>
  <div class="card-body p-0">
    <div class="mpcat-head">
      <div>Pazaryeri</div>
      <div>Aylık Fiyat (₺)</div>
      <div>Satışa Açık</div>
      <div class="text-end"></div>
    </div>

    <?php foreach ($marketplaces as $m):
      $id     = (int) ($m['id'] ?? 0);
      $name   = (string) ($m['display_name'] ?? '');
      $slug   = (string) ($m['slug'] ?? '');
      $price  = (float) ($m['price_monthly'] ?? 0);
      $sell   = (int) ($m['is_sellable'] ?? 0) === 1;
      $active = (int) ($m['is_active'] ?? 0) === 1;
      $tid    = 'mpsell_' . $id;
    ?>
    <form method="post" action="<?= url('/admin/marketplace-catalog/' . $id) ?>" class="mpcat-row">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <div class="mp-name">
        <div class="fw-semibold d-flex align-items-center gap-2">
          <img src="/assets/img/marketplaces/<?= $e($slug) ?>.png" alt="<?= $e($slug) ?>" style="height:24px; object-fit:contain; min-width:24px;" title="<?= $e($name) ?>" onerror="this.style.display='none';">
        </div>
        <div class="mpcat-sub font-monospace"><?= $e($slug) ?></div>
      </div>
      <div class="mp-price">
        <input type="number" step="0.01" min="0" name="price_monthly" value="<?= $e(number_format($price, 2, '.', '')) ?>" class="form-control form-control-sm">
      </div>
      <div class="mp-sell">
        <input type="hidden" name="is_sellable" value="0">
        <?= boss_switch('is_sellable', $sell, ['id' => $tid, 'on' => 'Açık', 'off' => 'Kapalı']) ?>
      </div>
      <div class="mp-act text-end">
        <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
      </div>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
