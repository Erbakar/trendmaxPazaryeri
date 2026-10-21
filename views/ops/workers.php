<?php
/** Faz 7 — Worker Yönetimi (admin layout fragment). Aktif/durmuş worker'lar, heartbeat, işlenen iş. Lumina: boss-stat-card KPI + DataTables tablo. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_helpers.php';
$thr = (int) ($threshold ?? 60);
$active = (int) ($activeCount ?? 0);
$total = count($workers ?? []);
$stopped = $total - $active;
if ($stopped < 0) { $stopped = 0; }

$actions = '<a href="' . $e(url('/ops/workers')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i data-lucide="refresh-cw"></i> Yenile</a>';
boss_page_header('Worker Yönetimi', [['l' => 'Operasyon', 'u' => '/ops'], ['l' => 'Worker']], $actions);
?>

<!-- Worker KPI'ları -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0">
        <div class="boss-stat-label">Aktif Worker</div>
        <div class="boss-stat-value mt-2"><?= $active ?></div>
        <div class="text-muted mt-1" style="font-size:12px;">son <?= $thr ?> sn içinde heartbeat</div>
      </div>
      <span class="boss-stat-chip <?= $active > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>"><i class="ti ti-cpu fs-5"></i></span>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0">
        <div class="boss-stat-label">Kayıtlı Worker</div>
        <div class="boss-stat-value mt-2"><?= $total ?></div>
        <div class="text-muted mt-1" style="font-size:12px;">durmuş/ölü dahil</div>
      </div>
      <span class="boss-stat-chip bg-secondary-subtle text-secondary"><i class="ti ti-server fs-5"></i></span>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0">
        <div class="boss-stat-label">Durmuş / Yanıtsız</div>
        <div class="boss-stat-value mt-2"><?= $stopped ?></div>
        <div class="text-muted mt-1" style="font-size:12px;">eşik aşıldı veya kapatıldı</div>
      </div>
      <span class="boss-stat-chip <?= $stopped > 0 ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary' ?>"><i class="ti ti-plug-connected-x fs-5"></i></span>
    </div></div>
  </div>
</div>

<?php if ($active === 0): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="ti ti-alert-circle fs-4"></i>
    <span>Aktif worker yok. Bekleyen işler işlenmez → <code>php cron/queue_worker.php</code> başlatın (systemd/supervisor önerilir).</span>
  </div>
<?php endif ?>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-cpu me-1 text-muted"></i>İşleyiciler</h3>
  </div>
  <?php if (empty($workers)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Henüz işleyici sinyali kaydı yok.</div></div>
  <?php else: ?>
  <div class="card-body">
  <div class="table-responsive">
  <table id="opsWorkersTable" class="table align-middle text-nowrap mb-0">
    <thead class="fs-2 text-muted text-uppercase">
      <tr>
        <th>İşleyici ID</th><th>Sunucu</th>
        <th>PID</th><th>Durum</th>
        <th class="text-end">İşlenen</th><th>Şu anki iş</th>
        <th>Başladı</th><th>Son heartbeat</th>
        <th class="text-end">Canlılık</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($workers as $w):
        $secs = $w['seconds_since_heartbeat']; $secs = $secs === null ? null : (int) $secs;
        $alive = $w['status'] !== 'stopped' && $secs !== null && $secs <= $thr;
    ?>
      <tr>
        <td class="font-monospace fw-medium"><?= $e($w['worker_id']) ?></td>
        <td class="text-muted"><?= $e($w['hostname'] ?? '—') ?></td>
        <td class="text-muted"><?= $w['pid'] !== null ? (int) $w['pid'] : '—' ?></td>
        <td><?= $opsStatusPill((string) $w['status']) ?></td>
        <td class="text-end fw-semibold"><?= (int) $w['jobs_processed'] ?></td>
        <td class="text-muted"><?= $w['current_job_id'] !== null ? '#' . (int) $w['current_job_id'] : '—' ?></td>
        <td class="text-muted text-nowrap"><?= $e($w['started_at'] ?? '') ?></td>
        <td class="text-muted text-nowrap"><?= $e($w['last_heartbeat_at'] ?? '') ?><?= $secs !== null ? ' (' . $secs . 's önce)' : '' ?></td>
        <td class="text-end">
          <?php if ($alive): ?>
            <span class="badge bg-success-subtle text-success rounded-pill d-inline-flex align-items-center gap-1"><span class="rounded-circle d-inline-block" style="width:7px;height:7px;background:currentColor;"></span>canlı</span>
          <?php else: ?>
            <span class="badge bg-danger-subtle text-danger rounded-pill d-inline-flex align-items-center gap-1"><span class="rounded-circle d-inline-block" style="width:7px;height:7px;background:currentColor;"></span><?= $w['status'] === 'stopped' ? 'durduruldu' : 'ölü/yanıtsız' ?></span>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  </div>
  <div class="card-footer text-muted" style="font-size:12px;">Not: "durduruldu" = graceful kapanış (SIGTERM/max-seconds). "ölü/yanıtsız" = heartbeat eşiği aşıldı (çökme olası → <code>recoverStuck</code> takılı işleri kurtarır).</div>
  <?php endif ?>
</div>
