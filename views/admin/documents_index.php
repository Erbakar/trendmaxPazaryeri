<?php
/** Admin — Mağaza belgeleri inceleme: DataTables tablo (üstte durum filtresi) + geri-alınabilir onayla/reddet/indir. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$documents = $documents ?? [];
$fmt = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$stMeta = [
    'pending'  => ['Bekliyor', 'bg-warning-subtle text-warning'],
    'approved' => ['Onaylandı', 'bg-success-subtle text-success'],
    'rejected' => ['Reddedildi', 'bg-danger-subtle text-danger'],
];
$action = '<a href="' . $e(url('/admin/document-types')) . '" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"><i class="ti ti-settings"></i> Belge Türleri</a>';
boss_page_header('Mağaza Belgeleri', [['l' => 'Mağazalar', 'u' => '/stores'], ['l' => 'Belgeler']], $action);
?>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-files me-1 text-muted"></i>Belge İnceleme Kuyruğu</h3>
    <div class="d-flex align-items-center gap-2">
      <label for="docStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
      <select id="docStatusFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">Tümü</option>
        <option value="pending">Bekleyen</option>
        <option value="approved">Onaylı</option>
        <option value="rejected">Reddedilen</option>
      </select>
    </div>
  </div>

  <?php if (empty($documents)): ?>
    <div class="card-body text-center py-5">
      <div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz belge yüklenmemiş.</div>
    </div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="docsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr>
          <th class="ps-4">Belge Türü</th>
          <th>Mağaza</th>
          <th>Dosya</th>
          <th>Tarih</th>
          <th>Durum</th>
          <th class="text-end pe-4">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($documents as $d): [$sl, $sc] = $stMeta[$d['status']] ?? ['—', 'bg-secondary-subtle text-secondary']; $did = (int) $d['id']; $dst = (string) ($d['status'] ?? ''); ?>
        <tr data-status="<?= $e($dst) ?>">
          <td class="ps-4">
            <div class="fw-semibold"><?= $e($d['type_label'] ?? 'Belge') ?></div>
            <?php if ($dst === 'rejected' && !empty($d['note'])): ?>
            <div class="fs-1 text-danger"><i class="ti ti-info-circle me-1"></i><?= $e($d['note']) ?></div>
            <?php endif; ?>
          </td>
          <td><i class="ti ti-building-store me-1 text-muted"></i><?= $e($d['store_name'] ?? ('store#' . ($d['store_id'] ?? '?'))) ?></td>
          <td>
            <a href="<?= url('/admin/documents/' . $did . '/download') ?>" class="text-decoration-none">
              <i class="ti ti-paperclip me-1"></i><?= $e($d['original_name'] ?? 'belge') ?>
            </a>
          </td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($d['created_at'] ?? '')) ?>"><?= $e($fmt($d['created_at'] ?? null)) ?></td>
          <td><span class="badge <?= $sc ?> rounded-pill"><?= $e($sl) ?></span></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-2 justify-content-end align-items-center">
              <a href="<?= url('/admin/documents/' . $did . '/download') ?>" class="btn btn-sm btn-outline-secondary border-0" title="İndir"><i class="ti ti-download"></i></a>
              <?php if ($dst !== 'approved'): ?>
              <form method="post" action="<?= url('/admin/documents/' . $did . '/review') ?>" class="d-inline" onsubmit="return confirm('Belge onaylansın mı?')">
                <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-check me-1"></i>Onayla</button>
              </form>
              <?php endif; ?>
              <?php if ($dst !== 'rejected'): ?>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      data-bs-toggle="modal" data-bs-target="#rejectDocModal"
                      data-id="<?= $did ?>" data-label="<?= $e($d['type_label'] ?? 'Belge') ?>"
                      data-store="<?= $e($d['store_name'] ?? '') ?>"><i class="ti ti-x me-1"></i>Reddet</button>
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

<!-- Belge reddet modalı (opsiyonel not) -->
<div class="modal fade" id="rejectDocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" id="rejectDocForm" action="">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <input type="hidden" name="action" value="reject">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Belgeyi Reddet</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <p class="fs-2 text-muted mb-3"><span id="rjLabel">Belge</span> — <span id="rjStore" class="fw-medium"></span></p>
          <label class="form-label">Red nedeni <span class="text-muted">(opsiyonel — mağazaya gösterilir)</span></label>
          <textarea name="note" class="form-control" rows="3" maxlength="500" placeholder="Örn. Belge okunaksız, lütfen yeniden yükleyin."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-danger"><i class="ti ti-x me-1"></i>Reddet</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // Reddet modalı doldurma
  var modal = document.getElementById('rejectDocModal');
  if (modal) {
    var base = '<?= url('/admin/documents') ?>';
    modal.addEventListener('show.bs.modal', function(ev){
      var b = ev.relatedTarget; if (!b) { return; }
      var d = b.dataset;
      document.getElementById('rejectDocForm').action = base + '/' + d.id + '/review';
      var l = document.getElementById('rjLabel'); if (l) { l.textContent = d.label || 'Belge'; }
      var s = document.getElementById('rjStore'); if (s) { s.textContent = d.store || ''; }
    });
  }

  // Durum filtresi — DataTables'a bağlı (yalnız bu tabloya etki eder)
  if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
    jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
      if (settings.nTable.id !== 'docsTable') { return true; }
      var sel = document.getElementById('docStatusFilter');
      var want = sel ? sel.value : '';
      if (!want) { return true; }
      var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
      return tr ? tr.getAttribute('data-status') === want : true;
    });
    var sel = document.getElementById('docStatusFilter');
    if (sel) {
      sel.addEventListener('change', function(){
        if (jQuery.fn.dataTable.isDataTable('#docsTable')) {
          jQuery('#docsTable').DataTable().draw();
        }
      });
    }
  }
})();
</script>
