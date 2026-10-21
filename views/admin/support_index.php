<?php
/** Admin — tüm destek talepleri (cross-store): DataTables tablo (üstte durum filtresi). Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tickets = $tickets ?? [];
$counts  = $counts ?? ['all' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
$stMeta = [
    'open'     => ['Açık', 'bg-warning-subtle text-warning'],
    'answered' => ['Yanıtlandı', 'bg-success-subtle text-success'],
    'closed'   => ['Kapalı', 'bg-secondary-subtle text-secondary'],
];
$prMeta = [
    'critical' => ['Kritik', 'bg-danger-subtle text-danger'],
    'high'     => ['Yüksek', 'bg-danger-subtle text-danger'],
    'normal'   => ['Normal', 'bg-info-subtle text-info'],
    'low'      => ['Düşük', 'bg-secondary-subtle text-muted'],
];
$fmt = fn($s) => $s ? date('d.m.Y H:i', strtotime((string) $s)) : '—';
$initOf = fn(string $s) => mb_strtoupper(mb_substr(trim($s) !== '' ? $s : '?', 0, 1, 'UTF-8'), 'UTF-8');
$kpis = [
    ['Açık', (int) ($counts['open'] ?? 0), 'inbox', 'warning'],
    ['Yanıtlandı', (int) ($counts['answered'] ?? 0), 'checks', 'success'],
    ['Kapalı', (int) ($counts['closed'] ?? 0), 'archive', 'secondary'],
    ['Toplam', (int) ($counts['all'] ?? 0), 'ticket', 'primary'],
];
boss_page_header('Destek Talepleri', [['l' => 'İletişim Merkezi', 'u' => '/admin/support'], ['l' => 'Destek Talepleri']]);
?>
<style>
#supportTable td{ padding-top:11px; padding-bottom:11px; }
#supportTable .tk-subj{ font-size:13.5px; font-weight:600; }
#supportTable .tk-ava{ width:32px; height:32px; border-radius:9px; display:inline-grid; place-items:center; font-weight:700; font-size:13px; background:var(--ad-surface-3); color:var(--ad-accent); }
</style>

<div class="row">
  <?php foreach ($kpis as [$l, $n, $i, $c]): ?>
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($l) ?></div><div class="boss-stat-value mt-2"><?= $n ?></div></div>
      <span class="boss-stat-chip bg-<?= $c ?>-subtle text-<?= $c ?>"><i class="ti ti-<?= $e($i) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-headset me-1 text-muted"></i>Talepler</h3>
    <div class="d-flex align-items-center gap-2">
      <label for="tkStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
      <select id="tkStatusFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">Tümü</option>
        <option value="open">Açık</option>
        <option value="answered">Yanıtlandı</option>
        <option value="closed">Kapalı</option>
      </select>
    </div>
  </div>

  <?php if (empty($tickets)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz destek talebi yok.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="supportTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr>
          <th class="ps-4">#</th>
          <th>Mağaza / Konu</th>
          <th>Öncelik</th>
          <th class="text-center">Mesaj</th>
          <th>Durum</th>
          <th class="text-end pe-4">Son Yanıt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tickets as $t):
          [$lbl, $cls] = $stMeta[$t['status']] ?? $stMeta['open'];
          [$prl, $prc] = $prMeta[$t['priority'] ?? 'normal'] ?? $prMeta['normal'];
          $tid   = (int) $t['id'];
          $store = (string) ($t['store_name'] ?? ('#' . $t['store_id']));
          $byMerchant = ($t['last_reply_by'] ?? '') === 'merchant';
        ?>
        <tr data-status="<?= $e($t['status'] ?? '') ?>" style="cursor:pointer;" onclick="location.href='<?= url('/admin/support/' . $tid) ?>'">
          <td class="ps-4 text-muted font-monospace">#<?= $tid ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="tk-ava"><?= $e($initOf($store)) ?></span>
              <div class="min-w-0">
                <div class="tk-subj"><?= $e($t['subject']) ?></div>
                <div class="fs-1 text-muted"><i class="ti ti-building-store me-1"></i><?= $e($store) ?></div>
              </div>
            </div>
          </td>
          <td><span class="badge <?= $prc ?> rounded-pill"><?= $e($prl) ?></span></td>
          <td class="text-center"><span class="badge bg-secondary-subtle text-muted rounded-pill"><i class="ti ti-message-2 me-1"></i><?= (int) ($t['msg_count'] ?? 0) ?></span></td>
          <td><span class="badge <?= $cls ?> rounded-pill"><?= $e($lbl) ?></span></td>
          <td class="text-end pe-4 text-muted" data-order="<?= (int) strtotime((string) ($t['last_reply_at'] ?? $t['created_at'] ?? '')) ?>">
            <?= $e($fmt($t['last_reply_at'] ?? $t['created_at'])) ?>
            <?php if ($byMerchant): ?><span class="badge bg-warning-subtle text-warning rounded-pill ms-1">mağaza</span><?php endif; ?>
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
    if (settings.nTable.id !== 'supportTable') { return true; }
    var sel = document.getElementById('tkStatusFilter');
    var want = sel ? sel.value : '';
    if (!want) { return true; }
    var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
    return tr ? tr.getAttribute('data-status') === want : true;
  });
  var sel = document.getElementById('tkStatusFilter');
  if (sel) {
    sel.addEventListener('change', function(){
      if (jQuery.fn.dataTable.isDataTable('#supportTable')) { jQuery('#supportTable').DataTable().draw(); }
    });
  }
})();
</script>
