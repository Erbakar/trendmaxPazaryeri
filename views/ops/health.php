<?php
/** Faz 7 — Sistem Sağlığı (admin layout fragment). DB / queue / worker / cron / API detay. Lumina: page-header + boss-stat-card KPI + düz .card. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_helpers.php';
$h = $health ?? [];
$reasons = function (array $c) use ($e): string {
    return empty($c['reasons']) ? '' : ' <span class="text-muted fw-normal fs-1">(' . implode('; ', array_map($e, $c['reasons'])) . ')</span>';
};
/** Detay satırı: etiket + değer. */
$row = function (string $label, string $value) use ($e): string {
    return '<div class="d-flex align-items-center justify-content-between gap-3 py-2 border-bottom">'
         . '<span class="text-muted fs-2">' . $e($label) . '</span>'
         . '<span class="fw-medium text-end fs-2">' . $value . '</span></div>';
};

// KPI özet kartları (boss-stat-card). Tüm değerler $health'ten türetilir.
$queuePending = (int) ($h['queue']['pending_due_now'] ?? 0);
$queueDead    = (int) ($h['queue']['dead_letter'] ?? 0);
$workersAct   = (int) ($h['workers']['active_count'] ?? 0);
$apiErrPct    = $h['api']['error_rate_pct'] ?? 0;
$apiAvgMs     = (int) ($h['api']['avg_response_ms'] ?? 0);
$kpis = [
    ['l' => 'Bekleyen İş',  'v' => (string) $queuePending,         'i' => 'stack-2',      'c' => $queuePending > 0 ? 'warning' : 'secondary'],
    ['l' => 'Ölü İş',       'v' => (string) $queueDead,            'i' => 'alert-octagon','c' => $queueDead > 0 ? 'danger' : 'success'],
    ['l' => 'Aktif İşleyici','v' => (string) $workersAct,          'i' => 'cpu',          'c' => $workersAct > 0 ? 'success' : 'secondary'],
    ['l' => 'API Hata Oranı','v' => '%' . $e($apiErrPct),          'i' => 'world',        'c' => 'info'],
    ['l' => 'Ort. Yanıt',   'v' => $apiAvgMs . ' ms',              'i' => 'clock-bolt',   'c' => 'primary'],
];

// page-header sağ aksiyon: genel durum rozeti
$actions = '<span class="d-inline-flex align-items-center gap-2 fw-medium fs-2">Genel '
         . $opsBadge((string) ($h['overall'] ?? 'ok')) . '</span>';
boss_page_header('Sistem Sağlığı', [['l' => 'Operasyon', 'u' => '/ops'], ['l' => 'Sağlık']], $actions);
?>

<!-- KPI özeti -->
<div class="row g-3 mb-3">
  <?php foreach ($kpis as $k): ?>
  <div class="col-6 col-md-4 col-xl">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($k['l']) ?></div><div class="boss-stat-value mt-2"><?= $k['v'] ?></div></div>
      <span class="boss-stat-chip bg-<?= $k['c'] ?>-subtle text-<?= $k['c'] ?>"><i class="ti ti-<?= $e($k['i']) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">

  <!-- Veritabanı -->
  <div class="col-12 col-lg-6">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="boss-stat-chip bg-secondary-subtle text-secondary"><i class="ti ti-database"></i></span> Veritabanı</h3>
        <?= $opsBadge((string) ($h['db']['level'] ?? 'ok')) ?>
      </div>
      <div class="card-body">
        <?= $row('Bağlantı', !empty($h['db']['connected']) ? '<span class="badge bg-success-subtle text-success rounded-pill">açık</span>' : '<span class="badge bg-danger-subtle text-danger rounded-pill">KAPALI</span>') ?>
        <?= $row('Sürüm', $e($h['db']['version'] ?? '—')) ?>
        <?= $row('Veritabanı', '<span class="font-monospace fs-1">' . $e($h['db']['database'] ?? '—') . '</span>') ?>
      </div>
    </div>
  </div>

  <!-- Queue -->
  <div class="col-12 col-lg-6">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="boss-stat-chip bg-secondary-subtle text-secondary"><i class="ti ti-stack-2"></i></span> Kuyruk<?= $reasons($h['queue'] ?? []) ?></h3>
        <?= $opsBadge((string) ($h['queue']['level'] ?? 'ok')) ?>
      </div>
      <div class="card-body">
        <?= $row('Bekleyen (hazır)', '<span class="tnum">' . (int) ($h['queue']['pending_due_now'] ?? 0) . '</span>') ?>
        <?= $row('Dead-letter', '<span class="tnum">' . (int) ($h['queue']['dead_letter'] ?? 0) . '</span>') ?>
        <?= $row('Hata oranı', '<span class="tnum">%' . $e($h['queue']['error_rate_pct'] ?? 0) . '</span>') ?>
        <?= $row('En eski bekleyen', $h['queue']['oldest_pending_sec'] !== null ? '<span class="tnum">' . (int) $h['queue']['oldest_pending_sec'] . 's</span>' : '—') ?>
      </div>
    </div>
  </div>

  <!-- Workers -->
  <div class="col-12 col-lg-6">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="boss-stat-chip bg-secondary-subtle text-secondary"><i class="ti ti-cpu"></i></span> İşleyiciler<?= $reasons($h['workers'] ?? []) ?></h3>
        <?= $opsBadge((string) ($h['workers']['level'] ?? 'ok')) ?>
      </div>
      <div class="card-body">
        <?= $row('Aktif işleyici', '<span class="tnum">' . (int) ($h['workers']['active_count'] ?? 0) . '</span>') ?>
        <?= $row('Bekleyen iş', '<span class="tnum">' . (int) ($h['workers']['pending_jobs'] ?? 0) . '</span>') ?>
        <a href="<?= url('/ops/workers') ?>" class="mt-3 d-inline-flex align-items-center gap-1 fw-medium text-primary text-decoration-none fs-2">İşleyici detayları <i class="ti ti-arrow-right"></i></a>
      </div>
    </div>
  </div>

  <!-- API -->
  <div class="col-12 col-lg-6">
    <div class="card h-100 mb-0">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="boss-stat-chip bg-secondary-subtle text-secondary"><i class="ti ti-world"></i></span> API<?= $reasons($h['api'] ?? []) ?></h3>
        <?= $opsBadge((string) ($h['api']['level'] ?? 'ok')) ?>
      </div>
      <div class="card-body">
        <?= $row('Pencere', 'son ' . (int) ($h['api']['window_minutes'] ?? 15) . ' dk') ?>
        <?= $row('Toplam istek', '<span class="tnum">' . (int) ($h['api']['total_requests'] ?? 0) . '</span>') ?>
        <?= $row('Hata (4xx+5xx)', '<span class="tnum">' . (int) ($h['api']['errors'] ?? 0) . '</span> (5xx: ' . (int) ($h['api']['server_errors'] ?? 0) . ')') ?>
        <?= $row('Hata oranı', '<span class="tnum">%' . $e($h['api']['error_rate_pct'] ?? 0) . '</span>') ?>
        <?= $row('Ort. yanıt', '<span class="tnum">' . (int) ($h['api']['avg_response_ms'] ?? 0) . ' ms</span>') ?>
      </div>
    </div>
  </div>

  <!-- Cron (geniş) -->
  <div class="col-12">
    <div class="card mb-0">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0 d-flex align-items-center gap-2"><span class="boss-stat-chip bg-secondary-subtle text-secondary"><i class="ti ti-clock"></i></span> Cron<?= $reasons($h['cron'] ?? []) ?></h3>
        <?= $opsBadge((string) ($h['cron']['level'] ?? 'ok')) ?>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-12 col-sm-4"><?= $row('Son enqueue', $e($h['cron']['last_enqueue'] ?? '—')) ?></div>
          <div class="col-12 col-sm-4"><?= $row('Son sync', $e($h['cron']['last_sync'] ?? '—')) ?></div>
          <div class="col-12 col-sm-4"><?= $row('Son aktivite', $h['cron']['last_activity_sec'] !== null ? '<span class="tnum">' . (int) $h['cron']['last_activity_sec'] . 's önce</span>' : '—') ?></div>
        </div>
        <?php if (!empty($h['cron']['per_type'])): ?>
        <div class="table-responsive">
        <table id="opsCronTable" class="table align-middle text-nowrap mb-0">
          <thead class="fs-2 text-muted text-uppercase">
            <tr>
              <th class="ps-4">Sync tipi</th><th>Son çalışma</th>
              <th class="text-end">Çalışma (24s)</th><th class="text-end pe-4">Hatalı (24s)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($h['cron']['per_type'] as $t): ?>
            <tr>
              <td class="ps-4 font-monospace fs-1"><?= $e($t['sync_type']) ?></td>
              <td class="text-muted tnum text-nowrap"><?= $e($t['last_run'] ?? '') ?></td>
              <td class="text-end tnum"><?= (int) $t['runs'] ?></td>
              <td class="text-end pe-4">
                <?php if ((int) $t['recent_errors'] > 0): ?>
                  <span class="badge bg-danger-subtle text-danger rounded-pill tnum"><?= (int) $t['recent_errors'] ?></span>
                <?php else: ?>
                  <span class="tnum text-muted">0</span>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        </div>
        <?php endif ?>
      </div>
    </div>
  </div>

</div>
