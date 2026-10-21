<?php
/** Ödeme Bildirimleri — DataTables tablo (üstte durum filtresi) + KPI. Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$payments = $payments ?? [];
$pendingCount = (int) ($pendingCount ?? 0);
$monthTotal = (float) ($monthTotal ?? 0);
$fmtP = fn($v) => '₺' . number_format((float) $v, 2, ',', '.');
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$meta = [
    'pending'   => ['Onay Bekliyor', 'bg-warning-subtle text-warning'],
    'confirmed' => ['Onaylandı',     'bg-success-subtle text-success'],
    'rejected'  => ['Reddedildi',    'bg-danger-subtle text-danger'],
];
boss_page_header('Ödeme Bildirimleri', [['l' => 'Faturalar & Ödemeler', 'u' => '/admin/invoices'], ['l' => 'Ödeme Bildirimleri']]);
?>
<div class="row">
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label">Bekleyen Ödeme</div><div class="boss-stat-value mt-2"><?= $pendingCount ?></div></div>
      <span class="boss-stat-chip bg-warning-subtle text-warning"><i class="ti ti-clock fs-5"></i></span>
    </div></div>
  </div>
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label">Bu Ay Tahsilat</div><div class="boss-stat-value mt-2"><?= $e($fmtP($monthTotal)) ?></div></div>
      <span class="boss-stat-chip bg-success-subtle text-success"><i class="ti ti-cash fs-5"></i></span>
    </div></div>
  </div>
</div>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-credit-card me-1 text-muted"></i>Ödeme Bildirimleri</h3>
    <div class="d-flex align-items-center gap-2">
      <label for="payStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
      <select id="payStatusFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">Tümü</option>
        <option value="pending">Bekleyen</option>
        <option value="confirmed">Onaylı</option>
        <option value="rejected">Reddedilen</option>
      </select>
    </div>
  </div>

  <?php if (empty($payments)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz ödeme bildirimi yok.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="paymentsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Mağaza</th><th>Fatura</th><th>Yöntem</th><th>Tutar</th><th>Durum</th><th>Tarih</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p): [$pl, $pc] = $meta[$p['status']] ?? ['—', 'bg-secondary-subtle text-secondary']; $pending = ($p['status'] ?? '') === 'pending'; $pid = (int) $p['id']; ?>
        <tr data-status="<?= $e($p['status'] ?? '') ?>">
          <td class="ps-4 fw-medium"><?= $e($p['store_name'] ?? ('#' . $p['store_id'])) ?></td>
          <td class="font-monospace fs-2"><?= $e($p['invoice_number'] ?? '—') ?></td>
          <td class="text-capitalize"><?= $e($p['method']) ?></td>
          <td class="fw-semibold"><?= $e($fmtP($p['amount'])) ?></td>
          <td><span class="badge <?= $pc ?> rounded-pill"><?= $e($pl) ?></span></td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($p['created_at'] ?? '')) ?>"><?= $e($fmtDT($p['created_at'] ?? null)) ?></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-2 justify-content-end">
              <?php if (!empty($p['receipt_path'])): ?><a href="<?= url('/admin/payments/' . $pid . '/receipt') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Dekont"><i class="ti ti-file-download"></i></a><?php endif; ?>
              <?php if ($pending): ?>
              <form method="post" action="<?= url('/admin/payments/' . $pid . '/confirm') ?>" class="d-inline" onsubmit="return confirm('Ödeme onaylanıp abonelik aktifleştirilsin mi?')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-success"><i class="ti ti-check me-1"></i>Onayla</button></form>
              <form method="post" action="<?= url('/admin/payments/' . $pid . '/reject') ?>" class="d-inline" onsubmit="return confirm('Reddedilsin mi?')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-x me-1"></i>Reddet</button></form>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>

<script>
(function(){
  if (!(window.jQuery && jQuery.fn && jQuery.fn.dataTable)) { return; }
  jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if (settings.nTable.id !== 'paymentsTable') { return true; }
    var sel = document.getElementById('payStatusFilter');
    var want = sel ? sel.value : '';
    if (!want) { return true; }
    var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
    return tr ? tr.getAttribute('data-status') === want : true;
  });
  var sel = document.getElementById('payStatusFilter');
  if (sel) { sel.addEventListener('change', function(){ if (jQuery.fn.dataTable.isDataTable('#paymentsTable')) { jQuery('#paymentsTable').DataTable().draw(); } }); }
})();
</script>
