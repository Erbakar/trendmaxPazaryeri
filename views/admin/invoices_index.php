<?php
/** Faturalar — global abonelik faturaları (subscription_invoices) · Lumina.
 * KPI + DataTables tablo (üstte Durum + e-Arşiv filtreleri) + satır-içi e-Arşiv Kes/İptal. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$invoices = $invoices ?? [];
$stats    = $stats ?? ['total' => 0, 'paid' => 0, 'issued' => 0, 'pending_earsiv' => 0];
$csrf     = $e($_csrf ?? '');

$months = [1=>'Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$fd  = function (?string $dt) use ($months): string { if (!$dt) { return '—'; } $ts = strtotime($dt); return $ts ? date('d', $ts).' '.($months[(int)date('n',$ts)]??'').' '.date('Y',$ts) : '—'; };
$fmtP = fn($v, $c = 'TRY') => ($c === 'TRY' ? '₺' : $e($c) . ' ') . number_format((float) $v, 2, ',', '.');

$stMeta = [
    'draft'   => ['Taslak',     'bg-secondary-subtle text-muted'],
    'sent'    => ['Gönderildi', 'bg-info-subtle text-info'],
    'paid'    => ['Ödendi',     'bg-success-subtle text-success'],
    'overdue' => ['Gecikmiş',   'bg-danger-subtle text-danger'],
    'void'    => ['İptal',      'bg-secondary-subtle text-muted'],
];
$eaMeta = [
    'issued'       => ['Kesildi',     'bg-success-subtle text-success'],
    'cancelled'    => ['İptal',       'bg-secondary-subtle text-muted'],
    'failed'       => ['Başarısız',   'bg-danger-subtle text-danger'],
    'missing_info' => ['Bilgi eksik', 'bg-warning-subtle text-warning'],
    'no_creds'     => ['GİB yok',     'bg-warning-subtle text-warning'],
];
$kindMeta = ['subscription' => 'Abonelik', 'upgrade' => 'Yükseltme', 'addon' => 'Eklenti'];

boss_page_header('Faturalar', [['l' => 'Faturalar & Ödemeler', 'u' => '/admin/invoices'], ['l' => 'Faturalar']]);
?>
<div class="row">
  <?php
  $kpis = [
      ['Toplam Fatura',      $stats['total'],          'file-invoice',  'bg-primary-subtle text-primary'],
      ['Ödenmiş',            $stats['paid'],           'circle-check',  'bg-success-subtle text-success'],
      ['e-Arşiv Kesilmiş',   $stats['issued'],         'receipt',       'bg-info-subtle text-info'],
      ['e-Arşiv Bekleyen',   $stats['pending_earsiv'], 'clock',         'bg-warning-subtle text-warning'],
  ];
  foreach ($kpis as [$lbl, $val, $ic, $cls]): ?>
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($lbl) ?></div><div class="boss-stat-value mt-2"><?= (int) $val ?></div></div>
      <span class="boss-stat-chip <?= $cls ?>"><i class="ti ti-<?= $e($ic) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-file-invoice me-1 text-muted"></i>Faturalar</h3>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <label for="invStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
        <select id="invStatusFilter" class="form-select form-select-sm" style="width:auto;">
          <option value="">Tümü</option>
          <option value="paid">Ödenen</option>
          <option value="sent">Gönderilen</option>
          <option value="draft">Taslak</option>
          <option value="overdue">Gecikmiş</option>
          <option value="void">İptal</option>
        </select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <label for="invEarsivFilter" class="form-label mb-0 fs-2 text-muted">e-Arşiv</label>
        <select id="invEarsivFilter" class="form-select form-select-sm" style="width:auto;">
          <option value="">Tümü</option>
          <option value="issued">Kesilmiş</option>
          <option value="none">Kesilmemiş</option>
          <option value="failed">Başarısız</option>
          <option value="missing_info">Bilgi eksik</option>
          <option value="cancelled">İptal</option>
        </select>
      </div>
    </div>
  </div>

  <?php if (empty($invoices)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-file-off d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz fatura yok.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="invoicesTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Fatura No</th><th>Mağaza</th><th>Tür</th><th>Dönem</th><th>Tutar</th><th>Durum</th><th>e-Arşiv</th><th>Tarih</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv):
          $iid = (int) $inv['id'];
          $st  = (string) ($inv['status'] ?? 'draft');
          [$stLbl, $stCls] = $stMeta[$st] ?? ['—','bg-secondary-subtle text-muted'];
          $ea  = (string) ($inv['earsiv_status'] ?? '');
          $issued = $ea === 'issued';
          $period = $inv['period_start'] ? $fd($inv['period_start']) . ' – ' . $fd($inv['period_end']) : '—';
          $sid = (int) ($inv['store_id'] ?? 0);
        ?>
        <tr data-status="<?= $e($st) ?>" data-earsiv="<?= $e($ea !== '' ? $ea : 'none') ?>">
          <td class="ps-4 font-monospace fw-semibold"><?= $e($inv['number'] ?? '—') ?></td>
          <td><?php if ($sid): ?><a href="<?= url('/stores/'.$sid) ?>" class="text-body fw-medium"><?= $e($inv['store_name'] ?? ('#'.$sid)) ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="text-muted fs-2"><?= $e($kindMeta[$inv['kind'] ?? ''] ?? ($inv['kind'] ?? '—')) ?></td>
          <td class="text-muted fs-2"><?= $e($period) ?></td>
          <td class="fw-semibold"><?= $e($fmtP($inv['amount'] ?? 0, (string) ($inv['currency'] ?? 'TRY'))) ?></td>
          <td><span class="badge <?= $stCls ?> fw-medium"><?= $e($stLbl) ?></span></td>
          <td>
            <?php if ($ea === '' || $ea === null): ?><span class="text-muted">—</span>
            <?php else: [$eaLbl, $eaCls] = $eaMeta[$ea] ?? [$ea, 'bg-secondary-subtle text-muted']; ?>
              <span class="badge <?= $eaCls ?> fw-medium" <?= !empty($inv['earsiv_uuid']) ? 'title="'.$e($inv['earsiv_uuid']).'"' : '' ?>><?= $e($eaLbl) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($inv['created_at'] ?? '')) ?>"><?= $e($fd($inv['created_at'] ?? null)) ?></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-2 justify-content-end">
              <?php if ($issued): ?>
              <form method="post" action="<?= url('/admin/invoices/'.$iid.'/cancel-earsiv') ?>" onsubmit="return confirm('“<?= $e($inv['number'] ?? '') ?>” e-Arşiv faturası iptal edilsin mi?');"><input type="hidden" name="_csrf_token" value="<?= $csrf ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="e-Arşiv İptal"><i class="ti ti-receipt-off"></i></button></form>
              <?php else: ?>
              <form method="post" action="<?= url('/admin/invoices/'.$iid.'/issue-earsiv') ?>" onsubmit="return confirm('“<?= $e($inv['number'] ?? '') ?>” için e-Arşiv faturası kesilsin mi?');"><input type="hidden" name="_csrf_token" value="<?= $csrf ?>"><button type="submit" class="btn btn-sm btn-outline-success border-0 px-2" title="e-Arşiv Kes"><i class="ti ti-receipt"></i></button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>
<p class="fs-2 text-muted mt-3 mb-0"><i class="ti ti-info-circle me-1"></i>e-Arşiv hesabı ve KDV ayarları: <a href="<?= url('/admin/earsiv-settings') ?>">Faturalar › e-Arşiv Ayarları</a>. Fatura, mağaza fatura bilgileri (VKN/ünvan) eksikse kesilemez — mağaza detayından doldurun.</p>

<script>
(function(){
  if (!(window.jQuery && jQuery.fn && jQuery.fn.dataTable)) { return; }
  jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if (settings.nTable.id !== 'invoicesTable') { return true; }
    var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
    if (!tr) { return true; }
    var ws = (document.getElementById('invStatusFilter')||{}).value || '';
    var we = (document.getElementById('invEarsivFilter')||{}).value || '';
    if (ws && tr.getAttribute('data-status') !== ws) { return false; }
    if (we && tr.getAttribute('data-earsiv') !== we) { return false; }
    return true;
  });
  ['invStatusFilter','invEarsivFilter'].forEach(function(idv){
    var el = document.getElementById(idv);
    if (el) { el.addEventListener('change', function(){ if (jQuery.fn.dataTable.isDataTable('#invoicesTable')) { jQuery('#invoicesTable').DataTable().draw(); } }); }
  });
})();
</script>
