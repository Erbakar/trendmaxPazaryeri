<?php
/** Mağaza başvuruları — Modernize/Lumina. DataTables tablo (üstte durum filtresi) + altta Kayıt & Onay Modu. Onayla(modal)→provizyon / reddet. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$signups = $signups ?? [];
$packages = $packages ?? [];
$fmt = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$stMeta = [
    'pending'  => ['Bekliyor', 'bg-warning-subtle text-warning'],
    'approved' => ['Onaylandı', 'bg-success-subtle text-success'],
    'rejected' => ['Reddedildi', 'bg-secondary-subtle text-secondary'],
];
$regMode    = $regMode ?? 'admin';
$regSubmode = $regSubmode ?? 'admin';
$modeMeta = [
    'admin' => ['Admin onaylı', 'Başvuru gelir, siz inceleyip onaylarsınız.'],
    'email' => ['E-posta doğrulamalı', 'Başvuran e-posta linkiyle doğrular; sonra onay/otomatik.'],
    'auto'  => ['Otomatik onay', 'Başvuru anında mağaza açılır (paket seçiliyse).'],
];
$initOf = fn(string $s) => mb_strtoupper(mb_substr(trim($s) !== '' ? $s : 'M', 0, 1, 'UTF-8'), 'UTF-8');
boss_page_header('Mağaza Başvuruları', [['l' => 'Mağazalar', 'u' => '/stores'], ['l' => 'Mağaza Başvuruları']]);
?>

<!-- Başvuru tablosu (DataTables: arama + sayfalama; üstte durum filtresi) -->
<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-users me-1 text-muted"></i>Başvurular</h3>
    <div class="d-flex align-items-center gap-2">
      <label for="signupStatusFilter" class="form-label mb-0 fs-2 text-muted">Durum</label>
      <select id="signupStatusFilter" class="form-select form-select-sm" style="width:auto;">
        <option value="">Tümü</option>
        <option value="pending">Bekleyen</option>
        <option value="approved">Onaylı</option>
        <option value="rejected">Reddedilen</option>
      </select>
    </div>
  </div>

  <?php if (empty($signups)): ?>
    <div class="card-body text-center py-5">
      <div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz başvuru yok.</div>
    </div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="signupsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr>
          <th class="ps-4">Firma</th>
          <th>Yetkili / İletişim</th>
          <th>İstenen Paket</th>
          <th>Tarih</th>
          <th>Durum</th>
          <th class="text-end pe-4">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($signups as $s): [$sl, $sc] = $stMeta[$s['status']] ?? ['—', 'bg-secondary-subtle text-secondary']; $pending = ($s['status'] ?? '') === 'pending'; $sid = (int) $s['id']; ?>
        <tr data-status="<?= $e($s['status'] ?? '') ?>">
          <td class="ps-4">
            <div class="d-flex align-items-center gap-2">
              <span class="d-inline-grid flex-shrink-0" style="place-items:center;width:34px;height:34px;border-radius:9px;background:var(--ad-surface-3);color:var(--ad-accent);font-weight:700;font-size:13px;"><?= $e($initOf((string) $s['company'])) ?></span>
              <div class="min-w-0">
                <div class="fw-semibold"><?= $e($s['company']) ?></div>
                <?php if (!empty($s['store_id'])): ?><a href="<?= url('/stores/' . (int) $s['store_id']) ?>" class="fs-1 text-info text-decoration-none font-monospace">→ store#<?= (int) $s['store_id'] ?></a><?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <div class="fw-medium"><?= $e($s['contact_name']) ?></div>
            <div class="fs-1 text-muted"><i class="ti ti-mail me-1"></i><?= $e($s['email']) ?><?php if (!empty($s['phone'])): ?> · <i class="ti ti-phone me-1"></i><?= $e($s['phone']) ?><?php endif; ?></div>
          </td>
          <td><?= !empty($s['package_name']) ? '<span class="badge bg-primary-subtle text-primary">' . $e($s['package_name']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($s['created_at'] ?? '')) ?>"><?= $e($fmt($s['created_at'] ?? null)) ?></td>
          <td><span class="badge <?= $sc ?> rounded-pill"><?= $e($sl) ?></span></td>
          <td class="text-end pe-4">
            <?php
              $st = (string) ($s['status'] ?? '');
              $hasStore = !empty($s['store_id']);
              $rejMsg = $hasStore
                ? '“' . $e($s['company']) . '” reddedilsin mi? Mağaza (#' . (int) $s['store_id'] . ') askıya alınacak.'
                : '“' . $e($s['company']) . '” başvurusu reddedilsin mi?';
            ?>
            <div class="d-inline-flex gap-2 justify-content-end align-items-center">
              <?php if ($hasStore): ?>
              <a href="<?= url('/stores/' . (int) $s['store_id']) ?>" class="btn btn-sm btn-outline-secondary border-0" title="Mağazaya git"><i class="ti ti-external-link"></i></a>
              <?php endif; ?>
              <?php if ($st !== 'approved'): ?>
              <button type="button" class="btn btn-sm btn-primary"
                      data-bs-toggle="modal" data-bs-target="#approveModal"
                      data-id="<?= $sid ?>" data-company="<?= $e($s['company']) ?>"
                      data-package="<?= (int) ($s['requested_package_id'] ?? 0) ?>"
                      data-cycle="<?= $e($s['billing_cycle'] ?? 'monthly') ?>"><i class="ti ti-check me-1"></i>Onayla</button>
              <?php endif; ?>
              <?php if ($st !== 'rejected'): ?>
              <form method="post" action="<?= url('/admin/signups/' . $sid . '/reject') ?>" class="d-inline" onsubmit="return confirm('<?= $rejMsg ?>')">
                <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-x me-1"></i>Reddet</button>
              </form>
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

<!-- Kayıt & Onay Modu (tablonun altında) -->
<div class="card mb-0">
  <div class="card-header">
    <div>
      <h3 class="card-title"><i class="ti ti-settings-cog me-1 text-muted"></i>Kayıt & Onay Modu</h3>
      <div class="card-subtitle">Yeni mağaza başvurularının nasıl işleneceğini belirler.</div>
    </div>
    <span class="badge bg-primary-subtle text-primary d-inline-flex align-items-center gap-1">
      <span class="d-inline-block rounded-circle bg-primary" style="width:7px;height:7px;"></span>
      <?= $e($modeMeta[$regMode][0] ?? $regMode) ?>
    </span>
  </div>
  <div class="card-body">
    <form method="post" action="<?= url('/admin/signups/settings') ?>" class="ad-form-grid">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <div class="c6">
        <label class="form-label">Kayıt modu</label>
        <select name="registration_mode" class="form-select">
          <?php foreach ($modeMeta as $k => $m): ?>
          <option value="<?= $e($k) ?>" <?= $regMode === $k ? 'selected' : '' ?>><?= $e($m[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><?= $e($modeMeta[$regMode][1] ?? '') ?></div>
      </div>
      <div class="c6">
        <label class="form-label">E-posta doğrulandıktan sonra</label>
        <select name="registration_email_submode" class="form-select">
          <option value="admin" <?= $regSubmode === 'admin' ? 'selected' : '' ?>>Admin onayına düşsün</option>
          <option value="auto"  <?= $regSubmode === 'auto'  ? 'selected' : '' ?>>Otomatik açılsın</option>
        </select>
        <div class="form-text">Yalnızca “E-posta doğrulamalı” modunda geçerli.</div>
      </div>
      <div class="full"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1"></i>Kaydet</button></div>
    </form>
  </div>
</div>

<!-- Onayla & Provizyon modalı (paket + dönem seçimi) -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" id="approveForm" action="" onsubmit="return confirm('Başvuru onaylanıp mağaza oluşturulsun mu?')">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Başvuruyu Onayla — <span id="apCompany">—</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <p class="fs-2 text-muted mb-3">Onaylandığında mağaza + sahip kullanıcı oluşturulur, seçtiğiniz paketin deneme aboneliği başlatılır ve sahibe şifre-belirleme bağlantısı gönderilir.</p>
          <div class="mb-3">
            <label class="form-label">Paket</label>
            <select name="package_id" id="apPackage" class="form-select">
              <?php foreach ($packages as $p): ?><option value="<?= (int) $p['id'] ?>"><?= $e($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-1">
            <label class="form-label">Faturalama Dönemi</label>
            <select name="billing_cycle" id="apCycle" class="form-select">
              <option value="monthly">Aylık</option>
              <option value="yearly">Yıllık</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1"></i>Onayla & Provizyon</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // Onay modalı doldurma
  var modal = document.getElementById('approveModal');
  if (modal) {
    var base = '<?= url('/admin/signups') ?>';
    modal.addEventListener('show.bs.modal', function(ev){
      var b = ev.relatedTarget; if (!b) { return; }
      var d = b.dataset;
      document.getElementById('approveForm').action = base + '/' + d.id + '/approve';
      var c = document.getElementById('apCompany'); if (c) { c.textContent = d.company || '—'; }
      var pkg = document.getElementById('apPackage'); if (pkg && d.package) { pkg.value = d.package; }
      var cyc = document.getElementById('apCycle'); if (cyc && d.cycle) { cyc.value = d.cycle; }
    });
  }

  // Durum filtresi — DataTables'a bağlı (yalnız bu tabloya etki eder)
  if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
    jQuery.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
      if (settings.nTable.id !== 'signupsTable') { return true; }
      var sel = document.getElementById('signupStatusFilter');
      var want = sel ? sel.value : '';
      if (!want) { return true; }
      var tr = settings.aoData[dataIndex] ? settings.aoData[dataIndex].nTr : null;
      return tr ? tr.getAttribute('data-status') === want : true;
    });
    var sel = document.getElementById('signupStatusFilter');
    if (sel) {
      sel.addEventListener('change', function(){
        if (jQuery.fn.dataTable.isDataTable('#signupsTable')) {
          jQuery('#signupsTable').DataTable().draw();
        }
      });
    }
  }
})();
</script>
