<?php
/** Abonelikler + Trial Dönüşüm — Modernize (Bootstrap). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$subs = $subs ?? [];
$counts = $counts ?? [];
$metrics = $metrics ?? ['trial' => 0, 'active_paid' => 0, 'ended' => 0, 'rate' => 0];
$current = $current ?? '';
$fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '—';
$meta = [
    'trial'     => ['Deneme', 'bg-warning-subtle text-warning'],
    'active'    => ['Aktif', 'bg-success-subtle text-success'],
    'passive'   => ['Pasif', 'bg-secondary-subtle text-secondary'],
    'suspended' => ['Askıda', 'bg-danger-subtle text-danger'],
    'expired'   => ['Süresi Doldu', 'bg-danger-subtle text-danger'],
    'cancelled' => ['İptal', 'bg-secondary-subtle text-secondary'],
];
$tabs = ['' => 'Tümü', 'trial' => 'Deneme', 'active' => 'Aktif', 'suspended' => 'Askıda', 'expired' => 'Süresi Doldu', 'cancelled' => 'İptal'];
$kpi = [
    ['Trial Mağaza', (int) $metrics['trial'], 'hourglass', 'warning'],
    ['Satın Alan (aktif)', (int) $metrics['active_paid'], 'circle-check', 'success'],
    ['Biten (expired/iptal)', (int) $metrics['ended'], 'circle-x', 'secondary'],
    ['Dönüşüm Oranı', '%' . $metrics['rate'], 'trending-up', 'primary'],
];
boss_page_header('Abonelikler', [['l' => 'Siparişler', 'u' => '/admin/packages'], ['l' => 'Abonelikler']]);
?>
<div class="row">
  <?php foreach ($kpi as [$l, $v, $i, $c]): ?>
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($l) ?></div><div class="boss-stat-value mt-2"><?= $e($v) ?></div></div>
      <span class="boss-stat-chip bg-<?= $c ?>-subtle text-<?= $c ?>"><i class="ti ti-<?= $e(bti($i)) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-credit-card me-1 text-muted"></i>Abonelikler</h3>
    <div class="d-flex align-items-center gap-2">
      <label for="subStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
      <select id="subStatusFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">Tümü</option>
        <option value="trial">Deneme</option>
        <option value="active">Aktif</option>
        <option value="suspended">Askıda</option>
        <option value="expired">Süresi Doldu</option>
        <option value="cancelled">İptal</option>
      </select>
    </div>
  </div>
  <?php if (empty($subs)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-receipt-off d-block mb-2" style="font-size:34px;opacity:.5;"></i>Abonelik yok.</div></div>
  <?php else: ?>
  <div class="card-body">
    <div class="table-responsive">
      <table id="subsTable" class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Mağaza</th><th>Paket</th><th>Durum</th><th>Dönem</th><th>Bitiş</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php foreach ($subs as $s): [$sl, $sc] = $meta[$s['status']] ?? ['—', 'bg-secondary-subtle text-secondary']; $st = (int) $s['store_id']; $active = in_array($s['status'], ['active', 'trial'], true); $endDate = $s['current_period_end'] ?? $s['trial_ends_at'] ?? null; ?>
          <tr data-status="<?= $e($s['status'] ?? '') ?>">
            <td class="ps-4"><?= $e($s['store_name'] ?? ('#' . $st)) ?> <span class="text-muted font-monospace fs-1">#<?= $st ?></span></td>
            <td><?= $e($s['package_name'] ?? '—') ?></td>
            <td><span class="badge <?= $sc ?> rounded-pill"><?= $e($sl) ?></span></td>
            <td class="text-muted"><?= $s['billing_cycle'] === 'yearly' ? 'Yıllık' : 'Aylık' ?></td>
            <td class="text-muted" data-order="<?= (int) strtotime((string) $endDate) ?>"><?= $e($fmtD($endDate)) ?></td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/subscriptions/' . $st . '/addons') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Eklentiler (ek pazaryeri/modül)"><i class="ti ti-puzzle"></i></a>
              <a href="<?= url('/admin/subscriptions/' . $st . '/history') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Paket geçmişi"><i class="ti ti-history"></i></a>
              <form method="post" action="<?= url('/admin/subscriptions/' . $st . '/resync') ?>" class="d-inline"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Pakete re-sync"><i class="ti ti-refresh"></i></button></form>
              <?php if ($active): ?>
              <form method="post" action="<?= url('/admin/subscriptions/' . $st . '/suspend') ?>" class="d-inline" onsubmit="return confirm('Askıya alınsın mı? Entitlement iptal edilir.')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-warning border-0 px-2" title="Askıya al"><i class="ti ti-player-pause"></i></button></form>
              <?php else: ?>
              <form method="post" action="<?= url('/admin/subscriptions/' . $st . '/reactivate') ?>" class="d-inline" onsubmit="return confirm('Yeniden etkinleştirilsin mi?')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-success border-0 px-2" title="Yeniden etkinleştir"><i class="ti ti-player-play"></i></button></form>
              <?php endif; ?>
              <form method="post" action="<?= url('/admin/subscriptions/' . $st . '/cancel') ?>" class="d-inline" onsubmit="return confirm('İptal edilsin mi?')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="İptal"><i class="ti ti-x"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
(function(){
  if (!(window.jQuery && jQuery.fn && jQuery.fn.dataTable)) { return; }
  // Durum filtresi — yalnız bu tabloya etki eder
  jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if (settings.nTable.id !== 'subsTable') { return true; }
    var sel = document.getElementById('subStatusFilter');
    var want = sel ? sel.value : '';
    if (!want) { return true; }
    var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
    return tr ? tr.getAttribute('data-status') === want : true;
  });
  var sel = document.getElementById('subStatusFilter');
  if (sel) {
    sel.addEventListener('change', function(){
      if (jQuery.fn.dataTable.isDataTable('#subsTable')) {
        jQuery('#subsTable').DataTable().draw();
      }
    });
  }
})();
</script>
