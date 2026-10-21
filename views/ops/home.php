<?php
/** Operasyon Özet — canlı (count-up + health.json poll) + mağaza-bazlı operasyon paneli. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_helpers.php';
$h = $health ?? [];
$jobs = $jobs ?? [];
$stores = $stores ?? [];
$activeStores = (int) ($activeStores ?? 0);
$audit24 = (int) ($audit24 ?? 0);
$overall = (string) ($h['overall'] ?? 'ok');
$allOk = $overall === 'ok';

$workersActive = (int) ($h['workers']['active_count'] ?? 0);
$queuePending  = (int) ($h['queue']['pending_due_now'] ?? ($jobs['pending'] ?? 0));
$queueDead     = (int) ($h['queue']['dead_letter'] ?? ($jobs['dead'] ?? 0));

$cards = [
    ['l' => 'Veritabanı', 'i' => 'database', 'lvl' => $h['db']['level'] ?? 'ok',      'sub' => $e($h['db']['version'] ?? '—')],
    ['l' => 'Kuyruk',     'i' => 'layers',   'lvl' => $h['queue']['level'] ?? 'ok',   'sub' => $queuePending . ' bekleyen · ' . $queueDead . ' dead'],
    ['l' => "Worker'lar", 'i' => 'cpu',      'lvl' => $h['workers']['level'] ?? 'ok', 'sub' => $workersActive . ' aktif'],
    ['l' => 'Cron',       'i' => 'clock',    'lvl' => $h['cron']['level'] ?? 'ok',    'sub' => 'son: ' . ($h['cron']['last_activity_sec'] !== null ? (int) $h['cron']['last_activity_sec'] . ' sn önce' : '—')],
    ['l' => 'API',        'i' => 'globe',    'lvl' => $h['api']['level'] ?? 'ok',     'sub' => '%' . $e($h['api']['error_rate_pct'] ?? 0) . ' hata'],
];
$kpis = [
    ['id' => 'kpiWorkers', 'l' => 'Aktif Worker', 'v' => $workersActive, 'i' => 'cpu',           'c' => 'success'],
    ['id' => 'kpiPending', 'l' => 'Bekleyen İş',  'v' => $queuePending,  'i' => 'layers',        'c' => 'warning'],
    ['id' => 'kpiDead',    'l' => 'Ölü İş',       'v' => $queueDead,     'i' => 'alert-octagon', 'c' => $queueDead > 0 ? 'danger' : 'secondary'],
    ['id' => 'kpiStores',  'l' => 'Aktif Mağaza', 'v' => $activeStores,  'i' => 'store',         'c' => 'primary'],
    ['id' => 'kpiAudit',   'l' => '24s Denetim',  'v' => $audit24,       'i' => 'scroll-text',   'c' => 'info'],
];
$statusCls = $allOk ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
$actions = '<span id="opsStatus" class="badge ' . $statusCls . ' d-inline-flex align-items-center gap-1 fw-medium" style="height:32px;padding:0 .75rem;">'
         . '<span class="ops-pulse rounded-circle d-inline-block" style="width:8px;height:8px;background:currentColor;"></span> '
         . '<span id="opsStatusText">' . ($allOk ? 'Tüm sistemler çalışıyor' : 'Dikkat gerekiyor') . '</span></span>';
boss_page_header('Operasyon Paneli', [['l' => 'Operasyon']], $actions);
?>
<style>
.ops-pulse{ animation:opsPulse 1.6s ease-in-out infinite; }
@keyframes opsPulse{ 0%,100%{ opacity:1; transform:scale(1); } 50%{ opacity:.35; transform:scale(1.6); } }
.ops-kpi .boss-stat-value{ font-size:30px; line-height:1; }
.ops-quick{ transition:transform .12s; text-decoration:none; }
.ops-quick:hover{ transform:translateY(-2px); }
.ops-store-stat{ text-align:center; padding:10px 6px; border:1px solid var(--ad-border); border-radius:10px; }
.ops-store-stat .n{ font-size:22px; font-weight:700; line-height:1; }
.ops-store-stat .l{ font-size:11px; color:var(--ad-muted); margin-top:4px; }
</style>

<!-- Canlı KPI'lar (count-up) -->
<div class="row g-3 mb-3 ops-kpi">
  <?php foreach ($kpis as $k): ?>
  <div class="col-6 col-md-4 col-xl">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0"><div class="boss-stat-label"><?= $e($k['l']) ?></div><div class="boss-stat-value mt-2" id="<?= $e($k['id']) ?>" data-countup="<?= (int) $k['v'] ?>">0</div></div>
      <span class="boss-stat-chip bg-<?= $k['c'] ?>-subtle text-<?= $k['c'] ?>"><i class="ti ti-<?= $e(bti($k['i'])) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Sağlık kartları -->
<div class="card mb-3">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-activity me-1 text-muted"></i>Sistem Sağlığı <span class="fs-1 text-muted fw-normal">(15 sn'de bir canlı)</span></h3></div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($cards as $c): ?>
      <div class="col-6 col-md-4 col-xl">
        <div class="card h-100 mb-0">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between gap-2">
              <span class="boss-stat-chip bg-secondary-subtle text-secondary"><i data-lucide="<?= $e($c['i']) ?>"></i></span>
              <?= $opsBadge((string) $c['lvl']) ?>
            </div>
            <div class="fw-semibold mt-3" style="font-size:14px;"><?= $e($c['l']) ?></div>
            <div class="text-muted mt-1 text-truncate" style="font-size:12.5px;"><?= $c['sub'] ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="row g-3 align-items-start">
  <!-- Mağaza bazlı operasyon -->
  <div class="col-12 col-lg-7">
    <div class="card mb-0">
      <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="card-title mb-0"><i class="ti ti-building-store me-1 text-muted"></i>Mağaza Bazlı Operasyon</h3>
        <select id="opsStoreSelect" class="form-select form-select-sm" style="width:auto;max-width:260px;">
          <option value="">Mağaza seçin…</option>
          <?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>"><?= $e($s['name']) ?> #<?= (int) $s['id'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="card-body">
        <div id="opsStoreEmpty" class="text-center text-muted py-4"><i class="ti ti-arrow-up me-1"></i>Bir mağaza seçin — o mağazanın iş kuyruğu, senkron, denetim ve durumu gelsin.</div>
        <div id="opsStorePanel" class="d-none">
          <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <span class="fw-bold fs-4" id="ossName">—</span>
            <span id="ossStatus" class="badge rounded-pill"></span>
            <span id="ossVerified" class="badge rounded-pill"></span>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-3"><div class="ops-store-stat"><div class="n text-warning" id="ossPending">0</div><div class="l">Bekleyen İş</div></div></div>
            <div class="col-3"><div class="ops-store-stat"><div class="n text-info" id="ossRunning">0</div><div class="l">Çalışan</div></div></div>
            <div class="col-3"><div class="ops-store-stat"><div class="n text-danger" id="ossDead">0</div><div class="l">Ölü / Hata</div></div></div>
            <div class="col-3"><div class="ops-store-stat"><div class="n text-primary" id="ossAudit">0</div><div class="l">24s Denetim</div></div></div>
          </div>
          <dl class="row mb-3 fs-2">
            <dt class="col-4 text-muted fw-normal">Son Senkron</dt><dd class="col-8 mb-2" id="ossSync">—</dd>
            <dt class="col-4 text-muted fw-normal">Son Giriş</dt><dd class="col-8 mb-0" id="ossLogin">—</dd>
          </dl>
          <div class="d-flex flex-wrap gap-2">
            <a id="ossLinkStore" href="#" class="btn btn-sm btn-outline-secondary"><i class="ti ti-building-store me-1"></i>Mağaza Detayı</a>
            <a id="ossLinkLogs" href="#" class="btn btn-sm btn-outline-secondary"><i class="ti ti-file-text me-1"></i>Logları</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Hızlı erişim -->
  <div class="col-12 col-lg-5">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-grid-dots me-1 text-muted"></i>Hızlı Erişim</h3></div>
      <div class="card-body">
        <div class="row g-2">
          <?php
          $quick = [
            ['Kuyruk', 'layers', '/ops/queue'],
            ['İşleyiciler', 'cpu', '/ops/workers'],
            ['Sağlık', 'activity', '/ops/health'],
            ['Denetim', 'scroll-text', '/ops/audit'],
            ['Loglar', 'scroll', '/logs'],
            ['Yedekler', 'database-backup', '/backups'],
          ];
          foreach ($quick as [$l, $i, $u]): ?>
          <div class="col-6">
            <a href="<?= url($u) ?>" class="ops-quick card mb-0 h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
              <span class="boss-stat-chip bg-primary-subtle text-primary"><i class="ti ti-<?= $e(bti($i)) ?>"></i></span>
              <span class="fw-semibold"><?= $e($l) ?></span>
            </div></a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // 1) Count-up
  document.querySelectorAll('[data-countup]').forEach(function(el){
    var target = parseInt(el.getAttribute('data-countup'), 10) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    var dur = 700, t0 = null;
    function step(ts){ if (!t0) t0 = ts; var p = Math.min((ts - t0) / dur, 1); el.textContent = Math.round(target * p); if (p < 1) requestAnimationFrame(step); }
    requestAnimationFrame(step);
  });

  // 2) Canlı sağlık (15 sn)
  var healthUrl = '<?= url('/ops/health.json') ?>';
  function setNum(id, v){ var el = document.getElementById(id); if (el && v != null) { el.textContent = String(v); } }
  function pollHealth(){
    fetch(healthUrl, {headers:{'Accept':'application/json'}}).then(function(r){ return r.json(); }).then(function(d){
      if (!d) return;
      setNum('kpiWorkers', d.workers && d.workers.active_count);
      setNum('kpiPending', d.queue && d.queue.pending_due_now);
      setNum('kpiDead', d.queue && d.queue.dead_letter);
      var ok = (d.overall || 'ok') === 'ok';
      var badge = document.getElementById('opsStatus'), txt = document.getElementById('opsStatusText');
      if (badge) { badge.className = 'badge ' + (ok ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning') + ' d-inline-flex align-items-center gap-1 fw-medium'; badge.style.height = '32px'; badge.style.padding = '0 .75rem'; }
      if (txt) { txt.textContent = ok ? 'Tüm sistemler çalışıyor' : 'Dikkat gerekiyor'; }
    }).catch(function(){});
  }
  setInterval(pollHealth, 15000);

  // 3) Mağaza-bazlı panel
  var sel = document.getElementById('opsStoreSelect');
  var empty = document.getElementById('opsStoreEmpty'), panel = document.getElementById('opsStorePanel');
  var base = '<?= url('/ops/store-summary') ?>';
  function fmtDT(s){ if (!s) return '—'; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d.getTime()) ? s : d.toLocaleString('tr-TR'); }
  if (sel) sel.addEventListener('change', function(){
    var id = this.value;
    if (!id) { panel.classList.add('d-none'); empty.classList.remove('d-none'); return; }
    fetch(base + '?store_id=' + encodeURIComponent(id)).then(function(r){ return r.json(); }).then(function(d){
      if (!d || !d.ok) { return; }
      empty.classList.add('d-none'); panel.classList.remove('d-none');
      document.getElementById('ossName').textContent = d.store.name;
      var st = document.getElementById('ossStatus'); st.className = 'badge rounded-pill ' + (d.store.status === 'active' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'); st.textContent = d.store.status === 'active' ? 'Aktif' : (d.store.status === 'suspended' ? 'Askıda' : d.store.status);
      var vf = document.getElementById('ossVerified'); vf.className = 'badge rounded-pill ' + (d.store.verified ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-muted'); vf.textContent = d.store.verified ? 'Doğrulandı' : 'Doğrulanmadı';
      document.getElementById('ossPending').textContent = d.jobs.pending || 0;
      document.getElementById('ossRunning').textContent = d.jobs.running || 0;
      document.getElementById('ossDead').textContent = (d.jobs.dead || 0) + (d.jobs.failed || 0);
      document.getElementById('ossAudit').textContent = d.audit_24h || 0;
      document.getElementById('ossSync').textContent = d.last_sync ? (d.last_sync.status + ' · ' + fmtDT(d.last_sync.at)) : 'Senkron kaydı yok';
      document.getElementById('ossLogin').textContent = fmtDT(d.last_login);
      document.getElementById('ossLinkStore').href = '<?= url('/stores') ?>/' + id;
      document.getElementById('ossLinkLogs').href = '<?= url('/logs') ?>?store=' + id;
    }).catch(function(){});
  });
})();
</script>
