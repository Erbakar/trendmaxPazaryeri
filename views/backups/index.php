<?php
/** Sistem Yedekleri (Super Admin) · Lumina. Toplu (tüm sistem) + mağaza-bazlı yedek; indir/geri-yükle/sil. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$backups     = $data['backups'] ?? [];
$stats       = $data['stats'] ?? [];
$stores      = $data['stores'] ?? [];
$csrf        = $data['_csrf'] ?? '';
$formatBytes = $data['formatBytes'] ?? fn(int $b) => $b . ' B';
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';

$totalBackups = (int) ($stats['total'] ?? 0);
$lastBackup   = $stats['last_backup'] ?? null;
$typeMeta = ['auto' => ['Otomatik', 'bg-success-subtle text-success'], 'manual' => ['Manuel', 'bg-primary-subtle text-primary']];
$statusMeta = [
    'completed' => ['Tamamlandı', 'bg-success-subtle text-success'],
    'creating'  => ['Oluşturuluyor', 'bg-warning-subtle text-warning'],
    'failed'    => ['Başarısız', 'bg-danger-subtle text-danger'],
    'restoring' => ['Geri Yükleniyor', 'bg-info-subtle text-info'],
];
$kpis = [
    ['Toplam Yedek', (string) $totalBackups, 'archive', 'primary'],
    ['Son Yedek', $lastBackup ? date('d.m.Y H:i', strtotime((string) $lastBackup)) : '—', 'clock', 'success'],
    ['Toplam Boyut', (string) $formatBytes((int) ($stats['total_size'] ?? 0)), 'database', 'info'],
    ['Oto / Manuel', ($stats['auto_count'] ?? 0) . ' / ' . ($stats['manual_count'] ?? 0), 'refresh', 'warning'],
];
boss_page_header('Sistem Yedekleri', [['l' => 'Bakım', 'u' => '/admin/updates'], ['l' => 'Yedekler']]);
?>
<div class="row">
  <?php foreach ($kpis as [$l, $n, $i, $c]): ?>
  <div class="col-6 col-xl-3 mb-4">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($l) ?></div><div class="boss-stat-value mt-2"><?= $e($n) ?></div></div>
      <span class="boss-stat-chip bg-<?= $c ?>-subtle text-<?= $c ?>"><i class="ti ti-<?= $e(bti($i)) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Yeni yedek -->
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-archive me-1 text-primary"></i>Yeni Yedek Al</h3></div>
  <div class="card-body">
    <form method="POST" action="<?= url('/backups') ?>" id="backupForm">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
      <div class="ad-form-grid">
        <div class="c6">
          <label class="form-label">Kapsam</label>
          <select name="scope" id="bkScope" class="form-select">
            <option value="full">Tüm Sistem (DB + Faturalar + Etiketler + Uploads + Config)</option>
            <option value="store">Tek Mağaza (yalnız o mağazanın verisi)</option>
          </select>
        </div>
        <div class="c6" id="bkStoreWrap" style="display:none;">
          <label class="form-label">Mağaza</label>
          <select name="store_id" class="form-select">
            <option value="">— Mağaza seçin —</option>
            <?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>"><?= $e($s['name']) ?> <span>#<?= (int) $s['id'] ?></span></option><?php endforeach; ?>
          </select>
        </div>
        <div class="full"><label class="form-label">Not <span class="text-muted">(opsiyonel)</span></label><input type="text" name="notes" maxlength="255" placeholder="Örn. sürüm öncesi yedek" class="form-control"></div>
        <div class="full"><button type="submit" id="btnCreateBackup" class="btn btn-primary"><i class="ti ti-player-record me-1"></i>Yedek Al</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Liste -->
<div class="card mb-0">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-list me-1 text-muted"></i>Yedekler</h3></div>
  <?php if (empty($backups)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-archive d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz yedek alınmamış. Yukarıdan ilk yedeğinizi alın.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="backupsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Kapsam</th><th>Dosya</th><th>Tür</th><th>Boyut</th><th>Durum</th><th>Tarih</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($backups as $b):
          $bid = (int) $b['id'];
          [$tLbl, $tCls] = $typeMeta[$b['type']] ?? [$b['type'], 'bg-secondary-subtle text-muted'];
          [$sLbl, $sCls] = $statusMeta[$b['status']] ?? [$b['status'], 'bg-secondary-subtle text-muted'];
          $isStore = !empty($b['store_id']);
          $done = ($b['status'] ?? '') === 'completed';
        ?>
        <tr>
          <td class="ps-4">
            <?php if ($isStore): ?>
              <span class="badge bg-info-subtle text-info rounded-pill"><i class="ti ti-building-store me-1"></i><?= $e($b['store_name'] ?? ('Mağaza #' . (int) $b['store_id'])) ?></span>
            <?php else: ?>
              <span class="badge bg-primary-subtle text-primary rounded-pill"><i class="ti ti-server me-1"></i>Tüm Sistem</span>
            <?php endif; ?>
          </td>
          <td class="font-monospace fs-1"><?= $e($b['filename']) ?></td>
          <td><span class="badge <?= $tCls ?> rounded-pill"><?= $e($tLbl) ?></span></td>
          <td class="fw-medium"><?= $e($formatBytes((int) $b['file_size'])) ?></td>
          <td><span class="badge <?= $sCls ?> rounded-pill"><?= $e($sLbl) ?></span></td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($b['created_at'] ?? '')) ?>"><?= $e($fmtDT($b['created_at'] ?? null)) ?></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-2 justify-content-end">
              <?php if ($done): ?>
              <a href="<?= url('/backups/' . $bid . '/download') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="İndir"><i class="ti ti-download"></i></a>
              <?php if (!$isStore): ?>
              <form method="POST" action="<?= url('/backups/' . $bid . '/restore') ?>" class="d-inline" onsubmit="return confirm('DİKKAT: Bu işlem mevcut veritabanını ve dosyaları yedektekiyle DEĞİŞTİRİR, geri alınamaz. Devam edilsin mi?')">
                <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning border-0 px-2" title="Geri Yükle (tüm sistem)"><i class="ti ti-rotate"></i></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
              <form method="POST" action="<?= url('/backups/' . $bid . '/delete') ?>" class="d-inline" onsubmit="return confirm('“<?= $e($b['filename']) ?>” silinsin mi?')">
                <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
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
<p class="fs-2 text-muted mt-3 mb-0"><i class="ti ti-info-circle me-1"></i><b>Tüm Sistem</b> yedeği geri yüklenebilir (DB + dosyalar). <b>Mağaza</b> yedeği yalnız o mağazanın verisini içerir (indir/arşiv amaçlı).</p>

<script>
(function(){
  var scope = document.getElementById('bkScope');
  var wrap = document.getElementById('bkStoreWrap');
  function sync(){ if (wrap) { wrap.style.display = (scope && scope.value === 'store') ? '' : 'none'; } }
  if (scope) { scope.addEventListener('change', sync); sync(); }
  var f = document.getElementById('backupForm');
  if (f) { f.addEventListener('submit', function(){ var b = document.getElementById('btnCreateBackup'); if (b) { b.disabled = true; b.innerHTML = '<i class="ti ti-loader me-1"></i>Yedek Alınıyor…'; } }); }
})();
</script>
