<?php
/** Faz 7 — Queue Dashboard (admin layout fragment). Durum sayıları + bakım aksiyonları + dead-letter + son işler. Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_helpers.php';
$s = $stats ?? [];
$by = $s['by_status'] ?? [];
$kpis = [
    ['l' => 'Pending',     'n' => (int) ($by['pending'] ?? 0), 'i' => 'clock',          'c' => 'primary',   'sub' => (int) ($s['pending_due_now'] ?? 0) . ' şimdi hazır'],
    ['l' => 'Running',     'n' => (int) ($by['running'] ?? 0), 'i' => 'loader',         'c' => 'info',      'sub' => 'işleniyor'],
    ['l' => 'Completed',   'n' => (int) ($by['done'] ?? 0),    'i' => 'check-check',    'c' => 'success',   'sub' => 'tamamlandı'],
    ['l' => 'Failed',      'n' => (int) ($by['failed'] ?? 0),  'i' => 'x-circle',       'c' => 'warning',   'sub' => 'başarısız'],
    ['l' => 'Dead-letter', 'n' => (int) ($by['dead'] ?? 0),    'i' => 'alert-triangle', 'c' => 'danger',    'sub' => 'kurtarma bekliyor'],
    ['l' => 'Error Rate',  'n' => '%' . $e($s['error_rate_pct'] ?? 0), 'i' => 'percentage', 'c' => 'secondary', 'sub' => 'dead / (done+dead)'],
];
$dead = $dead ?? [];

$actions = '<a href="' . $e(url('/ops/queue')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i data-lucide="refresh-cw"></i> Yenile</a>';
boss_page_header('Queue Dashboard', [['l' => 'Operasyon', 'u' => '/ops'], ['l' => 'Kuyruk']], $actions);
?>

<!-- KPI -->
<div class="row g-3 mb-3">
  <?php foreach ($kpis as $k): ?>
  <div class="col-6 col-md-4 col-xl">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0">
        <div class="boss-stat-label"><?= $e($k['l']) ?></div>
        <div class="boss-stat-value mt-2"><?= $k['n'] ?></div>
        <div class="boss-stat-label text-truncate mt-1 fw-normal"><?= $e($k['sub']) ?></div>
      </div>
      <span class="boss-stat-chip bg-<?= $k['c'] ?>-subtle text-<?= $k['c'] ?>"><i class="ti ti-<?= $e(bti($k['i'])) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>
<p class="mb-3 text-muted fs-2">En eski bekleyen: <span class="fw-medium text-body"><?= $e($s['oldest_pending'] ?? '—') ?></span></p>

<!-- Bakım aksiyonları -->
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-tool me-1 text-muted"></i>Bakım</h3></div>
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <form method="post" action="<?= url('/ops/queue/recover') ?>">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <button type="submit" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"><i data-lucide="life-buoy"></i> Takılı işleri kurtar</button>
      </form>
      <form method="post" action="<?= url('/ops/queue/retry-all-dead') ?>" onsubmit="return confirm('Tüm dead-letter işleri yeniden kuyruğa alınsın mı?')">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <button type="submit" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"><i data-lucide="rotate-cw"></i> Tüm dead-letter'ı yeniden dene</button>
      </form>
      <form method="post" action="<?= url('/ops/queue/purge') ?>" onsubmit="return confirm('7 günden eski tamamlanmış işler silinsin mi?')">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <input type="hidden" name="days" value="7">
        <button type="submit" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"><i data-lucide="trash-2"></i> Eski tamamlananları temizle (7g+)</button>
      </form>
    </div>
  </div>
</div>

<!-- Dead-letter -->
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-alert-triangle me-1 text-warning"></i>Dead-letter <span class="badge bg-warning-subtle text-warning rounded-pill ms-1"><?= count($dead) ?></span></h3></div>
  <?php if (empty($dead)): ?>
    <div class="card-body text-center py-5"><div class="text-success"><i class="ti ti-circle-check d-block mb-2" style="font-size:34px;opacity:.6;"></i>Dead-letter boş.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="deadJobsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr>
          <th class="ps-4">#</th><th>Store</th>
          <th>Tip</th><th>MP</th>
          <th>Deneme</th><th>Hata</th>
          <th>Bitti</th><th class="text-end pe-4">İşlem</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($dead as $j): ?>
        <tr>
          <td class="ps-4 fw-medium"><?= (int) $j['id'] ?></td>
          <td class="text-muted"><?= (int) $j['store_id'] ?></td>
          <td><code><?= $e($j['job_type']) ?></code></td>
          <td class="text-muted"><?= $j['marketplace_id'] !== null ? (int) $j['marketplace_id'] : '—' ?></td>
          <td><span class="badge bg-secondary-subtle text-secondary rounded-pill"><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></span></td>
          <td class="text-danger text-truncate" style="max-width:320px;" title="<?= $e($j['error_message'] ?? '') ?>"><?= $e(mb_substr((string) ($j['error_message'] ?? ''), 0, 160)) ?></td>
          <td class="text-muted text-nowrap"><?= $e($j['finished_at'] ?? '') ?></td>
          <td class="text-end pe-4">
            <form method="post" action="<?= url('/ops/queue/' . (int) $j['id'] . '/retry') ?>" class="inline">
              <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
              <button type="submit" class="btn btn-sm btn-outline-primary"><i class="ti ti-rotate me-1"></i>Yeniden dene</button>
            </form>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div></div>
  <?php endif ?>
</div>

<!-- Son işler -->
<div class="card mb-0">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-checks me-1 text-muted"></i>Son işler <span class="badge bg-secondary-subtle text-secondary rounded-pill ms-1">50</span></h3></div>
  <?php if (empty($recent ?? [])): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Kayıtlı iş yok.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="recentJobsTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr>
          <th class="ps-4">#</th><th>Store</th>
          <th>Tip</th><th>Durum</th>
          <th>Deneme</th><th>Sonraki</th>
          <th class="text-end pe-4">Güncellendi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (($recent ?? []) as $j): ?>
        <tr>
          <td class="ps-4 fw-medium"><?= (int) $j['id'] ?></td>
          <td class="text-muted"><?= (int) $j['store_id'] ?></td>
          <td><code><?= $e($j['job_type']) ?></code></td>
          <td><?= $opsStatusPill((string) $j['status']) ?></td>
          <td><span class="badge bg-secondary-subtle text-secondary rounded-pill"><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></span></td>
          <td class="text-muted text-nowrap"><?= $e($j['next_run_at'] ?? '—') ?></td>
          <td class="text-end text-muted text-nowrap pe-4"><?= $e($j['updated_at'] ?? '') ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div></div>
  <?php endif ?>
</div>
