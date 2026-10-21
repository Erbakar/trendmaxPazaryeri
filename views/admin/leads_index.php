<?php
/** Gelen Kutusu (Leads) — DataTables tablo + Durum/Tip filtresi + detay popup. Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$leads = $leads ?? [];
$counts = $counts ?? ['new' => 0, 'contacted' => 0, 'closed' => 0, 'all' => 0];
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$typeMeta = [
    'demo'         => ['Demo', 'bg-primary-subtle text-primary'],
    'contact'      => ['İletişim', 'bg-info-subtle text-info'],
    'store_signup' => ['Mağaza Kaydı', 'bg-warning-subtle text-warning'],
];
$statusMeta = [
    'new'       => ['Yeni', 'bg-primary-subtle text-primary'],
    'contacted' => ['İletişime Geçildi', 'bg-warning-subtle text-warning'],
    'closed'    => ['Kapatıldı', 'bg-success-subtle text-success'],
];
$kpi = [
    ['Toplam', (int) $counts['all'], 'inbox', 'primary'],
    ['Yeni', (int) $counts['new'], 'sparkles', 'primary'],
    ['İletişime Geçildi', (int) $counts['contacted'], 'phone-call', 'warning'],
    ['Kapatıldı', (int) $counts['closed'], 'circle-check', 'success'],
];
boss_page_header('Gelen Kutusu', [['l' => 'İletişim Merkezi', 'u' => '/admin/support'], ['l' => 'Gelen Kutusu']]);
?>
<div class="row">
  <?php foreach ($kpi as [$l, $v, $i, $c]): ?>
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($l) ?></div><div class="boss-stat-value mt-2"><?= (int) $v ?></div></div>
      <span class="boss-stat-chip bg-<?= $c ?>-subtle text-<?= $c ?>"><i class="ti ti-<?= $e(bti($i)) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-inbox me-1 text-muted"></i>Gelen Kayıtlar</h3>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <label for="leadStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
        <select id="leadStatusFilter" class="form-select form-select-sm" style="width:auto;">
          <option value="">Tümü</option>
          <option value="new">Yeni</option>
          <option value="contacted">İletişime Geçildi</option>
          <option value="closed">Kapatıldı</option>
        </select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <label for="leadTypeFilter" class="form-label mb-0 fs-2 text-muted">Tip</label>
        <select id="leadTypeFilter" class="form-select form-select-sm" style="width:auto;">
          <option value="">Tüm Tipler</option>
          <option value="demo">Demo</option>
          <option value="contact">İletişim</option>
          <option value="store_signup">Mağaza Kaydı</option>
        </select>
      </div>
    </div>
  </div>

  <?php if (empty($leads)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz kayıt yok.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="leadsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr>
          <th class="ps-4">Tarih</th>
          <th>Tip</th>
          <th>Ad / Firma</th>
          <th>E-posta</th>
          <th>Telefon</th>
          <th>Durum</th>
          <th class="text-end pe-4">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $row):
          $id = (int) ($row['id'] ?? 0);
          $type = (string) ($row['type'] ?? '');
          [$tl, $tc] = $typeMeta[$type] ?? [$type !== '' ? $type : '—', 'bg-secondary-subtle text-secondary'];
          $st = (string) ($row['status'] ?? 'new');
          [$sl, $sc] = $statusMeta[$st] ?? ['—', 'bg-secondary-subtle text-secondary'];
          $email = (string) ($row['email'] ?? '');
          $phone = (string) ($row['phone'] ?? '');
        ?>
        <tr data-status="<?= $e($st) ?>" data-type="<?= $e($type) ?>">
          <td class="ps-4 text-muted" data-order="<?= (int) strtotime((string) ($row['created_at'] ?? '')) ?>"><?= $e($fmtDT($row['created_at'] ?? null)) ?></td>
          <td><span class="badge <?= $tc ?> rounded-pill"><?= $e($tl) ?></span></td>
          <td><div class="fw-semibold"><?= $e($row['name'] ?? '—') ?></div><?php if (!empty($row['company'])): ?><div class="fs-1 text-muted"><?= $e($row['company']) ?></div><?php endif; ?></td>
          <td><?php if ($email !== ''): ?><a href="mailto:<?= $e($email) ?>" class="text-primary"><?= $e($email) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td><?php if ($phone !== ''): ?><a href="tel:<?= $e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= $e($phone) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td><span class="badge <?= $sc ?> rounded-pill"><?= $e($sl) ?></span></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-1 justify-content-end">
              <button type="button" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Detay"
                data-bs-toggle="modal" data-bs-target="#leadModal"
                data-id="<?= $id ?>" data-name="<?= $e($row['name'] ?? '—') ?>" data-company="<?= $e($row['company'] ?? '') ?>"
                data-email="<?= $e($email) ?>" data-phone="<?= $e($phone) ?>" data-source="<?= $e($row['source'] ?? '') ?>"
                data-type="<?= $e($tl) ?>" data-typecls="<?= $e($tc) ?>" data-status="<?= $e($st) ?>"
                data-statuslbl="<?= $e($sl) ?>" data-statuscls="<?= $e($sc) ?>"
                data-created="<?= $e($fmtDT($row['created_at'] ?? null)) ?>" data-message="<?= $e($row['message'] ?? '') ?>"><i class="ti ti-eye"></i></button>
              <form method="post" action="<?= url('/admin/leads/' . $id . '/delete') ?>" class="d-inline" onsubmit="return confirm('Bu kayıt kalıcı olarak silinsin mi?')">
                <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>

<!-- Lead detay popup -->
<div class="modal fade" id="leadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1" id="lmName">—</h5>
          <div class="d-flex align-items-center gap-2">
            <span id="lmTypeBadge" class="badge rounded-pill"></span>
            <span id="lmStatusBadge" class="badge rounded-pill"></span>
            <span class="fs-1 text-muted" id="lmCreated"></span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-3 fs-2">
          <dt class="col-4 col-sm-3 text-muted fw-normal">Firma</dt><dd class="col-8 col-sm-9 mb-2" id="lmCompany">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">E-posta</dt><dd class="col-8 col-sm-9 mb-2" id="lmEmail">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">Telefon</dt><dd class="col-8 col-sm-9 mb-2" id="lmPhone">—</dd>
          <dt class="col-4 col-sm-3 text-muted fw-normal">Kaynak</dt><dd class="col-8 col-sm-9 mb-0" id="lmSource">—</dd>
        </dl>
        <div class="fw-semibold fs-2 text-muted mb-1"><i class="ti ti-message-2 me-1"></i>Mesaj</div>
        <div id="lmMessage" class="p-3 rounded-3" style="background:var(--ad-surface-2);white-space:pre-wrap;word-break:break-word;min-height:46px;">—</div>
      </div>
      <div class="modal-footer justify-content-between flex-wrap gap-2">
        <form method="post" id="lmStatusForm" action="" class="d-flex align-items-center gap-2">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <label class="form-label mb-0 fs-2 text-muted">Durum</label>
          <select name="status" id="lmStatus" class="form-select form-select-sm" style="width:auto;">
            <?php foreach ($statusMeta as $sk => $sm): ?><option value="<?= $e($sk) ?>"><?= $e($sm[0]) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
        </form>
        <form method="post" id="lmDeleteForm" action="" onsubmit="return confirm('Bu kayıt kalıcı olarak silinsin mi?')">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash me-1"></i>Sil</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var base = '<?= url('/admin/leads') ?>';
  // Detay popup doldurma
  var modal = document.getElementById('leadModal');
  if (modal) {
    var set = function(id, v){ var el = document.getElementById(id); if (el) { el.textContent = (v && v !== '') ? v : '—'; } };
    modal.addEventListener('show.bs.modal', function(ev){
      var b = ev.relatedTarget; if (!b) { return; }
      var d = b.dataset;
      set('lmName', d.name); set('lmCompany', d.company); set('lmSource', d.source);
      set('lmCreated', d.created); set('lmMessage', d.message);
      var mkLink = function(elId, href, text){
        var el = document.getElementById(elId); if (!el) { return; }
        el.textContent = '';
        if (text) { var a = document.createElement('a'); a.href = href; a.textContent = text; el.appendChild(a); }
        else { el.textContent = '—'; }
      };
      mkLink('lmEmail', 'mailto:' + (d.email||''), d.email);
      mkLink('lmPhone', 'tel:' + (d.phone||'').replace(/[^\d+]/g,''), d.phone);
      var tb = document.getElementById('lmTypeBadge'); if (tb) { tb.className = 'badge rounded-pill ' + (d.typecls||''); tb.textContent = d.type||''; }
      var sb = document.getElementById('lmStatusBadge'); if (sb) { sb.className = 'badge rounded-pill ' + (d.statuscls||''); sb.textContent = d.statuslbl||''; }
      var ss = document.getElementById('lmStatus'); if (ss && d.status) { ss.value = d.status; }
      document.getElementById('lmStatusForm').action = base + '/' + d.id + '/status';
      document.getElementById('lmDeleteForm').action = base + '/' + d.id + '/delete';
    });
  }
  // Durum + Tip filtreleri (DataTables)
  if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
    jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
      if (settings.nTable.id !== 'leadsTable') { return true; }
      var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
      if (!tr) { return true; }
      var ws = (document.getElementById('leadStatusFilter')||{}).value || '';
      var wt = (document.getElementById('leadTypeFilter')||{}).value || '';
      if (ws && tr.getAttribute('data-status') !== ws) { return false; }
      if (wt && tr.getAttribute('data-type') !== wt) { return false; }
      return true;
    });
    ['leadStatusFilter','leadTypeFilter'].forEach(function(idv){
      var el = document.getElementById(idv);
      if (el) { el.addEventListener('change', function(){ if (jQuery.fn.dataTable.isDataTable('#leadsTable')) { jQuery('#leadsTable').DataTable().draw(); } }); }
    });
  }
})();
</script>
