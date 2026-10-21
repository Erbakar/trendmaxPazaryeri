<?php
/** Sistem Logları (Super Admin) · admin layout. Yeni tasarım: breadcrumb'lı page-header + düz .card.
 * Konsol terminal estetiği (koyu) kasıtlı korunur; arama/highlight + tarih/seviye filtresi (GET) +
 * temizle (POST delete_all) + CSRF KORUNDU. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../ops/_helpers.php';
$lines = $data['lines'] ?? [];
$files = $data['files'] ?? [];
$date  = $data['date'] ?? date('Y-m-d');
$level = $data['level'] ?? '';
$store = $data['store'] ?? '';
$stores = $data['stores'] ?? [];
$csrf  = $data['_csrf'] ?? '';

$levelColors = [
    'DEBUG'    => ['c'=>'#64748b','bg'=>'rgba(100,116,139,.10)','icon'=>'bug',           'tr'=>'Debug'],
    'INFO'     => ['c'=>'#10b981','bg'=>'rgba(16,185,129,.10)', 'icon'=>'info',          'tr'=>'Bilgi'],
    'WARNING'  => ['c'=>'#f59e0b','bg'=>'rgba(245,158,11,.10)', 'icon'=>'alert-triangle','tr'=>'Uyarı'],
    'ERROR'    => ['c'=>'#ef4444','bg'=>'rgba(239,68,68,.10)',  'icon'=>'x-circle',      'tr'=>'Hata'],
    'CRITICAL' => ['c'=>'#dc2626','bg'=>'rgba(220,38,38,.10)',  'icon'=>'alert-octagon', 'tr'=>'Kritik'],
];
$counts = ['DEBUG'=>0,'INFO'=>0,'WARNING'=>0,'ERROR'=>0,'CRITICAL'=>0];
foreach ($lines as $l) {
    foreach ($counts as $k => &$v) { if (str_contains($l, "[{$k}]")) { $v++; break; } }
}
unset($v);
$totalLines = count(array_filter($lines, fn($l) => trim($l) !== ''));
$errorCount = $counts['ERROR'] + $counts['CRITICAL'];
$warnCount = $counts['WARNING'];

// page-header sağ aksiyon: temizle formu (POST delete_all + CSRF KORUNDU)
$actions = '<form method="POST" action="' . $e(url('/logs/clean')) . '" class="d-inline">'
         . '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">'
         . '<input type="hidden" name="delete_all" value="1">'
         . '<button type="submit" onclick="return confirm(\'Bugün hariç tüm log dosyaları silinecek. Emin misiniz?\')" class="btn btn-outline-danger d-inline-flex align-items-center gap-1"><i data-lucide="trash-2"></i> Temizle</button>'
         . '</form>';
boss_page_header('Sistem Logları', [['l' => 'Bakım', 'u' => '/admin/updates'], ['l' => 'Loglar']], $actions);
?>
<style>
  /* Terminal konsol (tema-bağımsız koyu) — admin chrome dışında kasıtlı korunur */
  .lg-console-body{max-height:68vh;overflow-y:auto;background:#0c111b}
  .lg-console-body::-webkit-scrollbar{width:8px}
  .lg-console-body::-webkit-scrollbar-thumb{background:#1e293b;border-radius:4px}
  .lg-entry{display:flex;align-items:flex-start;border-bottom:1px solid rgba(30,41,59,.5);transition:background .1s}
  .lg-entry:hover{background:rgba(16,185,129,.05)}
  .lg-entry-num{padding:8px 10px;color:#334155;text-align:right;min-width:44px;font-family:'JetBrains Mono',monospace;font-size:11px;user-select:none;border-right:1px solid rgba(30,41,59,.5);flex-shrink:0;line-height:1.7}
  .lg-entry-lvl{padding:8px 6px;flex-shrink:0}
  .lg-entry-lvl-badge{display:inline-flex;align-items:center;justify-content:center;padding:1px 6px;border-radius:4px;font-size:9px;font-weight:700;letter-spacing:.3px;font-family:'JetBrains Mono',monospace;min-width:52px}
  .lg-entry-time{padding:8px 8px 8px 0;color:#475569;font-family:'JetBrains Mono',monospace;font-size:11px;flex-shrink:0;line-height:1.7;white-space:nowrap}
  .lg-entry-msg{padding:8px 12px 8px 0;font-family:'JetBrains Mono',monospace;font-size:11.5px;line-height:1.7;white-space:pre-wrap;word-break:break-all;flex:1}
  .lg-entry-ctx{padding:2px 12px 6px 120px;font-size:10px;color:#475569;font-family:'JetBrains Mono',monospace;white-space:pre-wrap;word-break:break-all;border-bottom:1px solid rgba(30,41,59,.5);background:rgba(15,23,42,.5)}
  .lg-debug .lg-entry-msg{color:#94a3b8} .lg-info .lg-entry-msg{color:#34d399} .lg-warning .lg-entry-msg{color:#fbbf24}
  .lg-error .lg-entry-msg{color:#f87171} .lg-critical .lg-entry-msg{color:#f87171;font-weight:700} .lg-critical{background:rgba(239,68,68,.05)}
  .lvl-debug{color:#94a3b8;background:rgba(148,163,184,.12)} .lvl-info{color:#34d399;background:rgba(52,211,153,.12)}
  .lvl-warning{color:#fbbf24;background:rgba(251,191,36,.12)} .lvl-error{color:#f87171;background:rgba(248,113,113,.18)} .lvl-critical{color:#fff;background:#dc2626}
  .lg-highlight{background:rgba(250,204,21,.25);color:#fef08a;border-radius:2px;padding:0 1px}
  .lg-no-results{display:none;text-align:center;padding:40px 20px;color:#475569;font-size:13px}
  .lg-no-results.show{display:block}
  .lg-stat-card{text-decoration:none}
  .lg-stat-card[data-active="1"] .card{outline:2px solid var(--bs-primary);outline-offset:-2px}
</style>

<!-- seviye KPI kartları (tıklanınca filtreler) -->
<div class="row g-3 mb-3">
  <?php foreach ($counts as $lvl => $cnt): $lc = $levelColors[$lvl]; $isActive = $level === $lvl; ?>
  <div class="col-6 col-md-4 col-xl">
    <a href="<?= url('/logs?date=' . urlencode($date) . '&level=' . $lvl) ?>" class="lg-stat-card d-block h-100" <?= $isActive ? 'data-active="1"' : '' ?>>
      <div class="card h-100 mb-0">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <span class="boss-stat-chip bg-secondary-subtle text-secondary"><i data-lucide="<?= $e($lc['icon']) ?>"></i></span>
            <div>
              <div class="boss-stat-value" style="<?= $cnt > 0 ? 'color:' . $lc['c'] : '' ?>"><?= $cnt ?></div>
              <div class="boss-stat-label"><?= $e($lc['tr']) ?></div>
            </div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- toolbar (filtre + arama + sıfırla) -->
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <form method="GET" action="<?= url('/logs') ?>" class="d-flex flex-wrap align-items-center gap-2">
        <input type="date" name="date" value="<?= $e($date) ?>" onchange="this.form.submit()" class="form-control" style="width:auto;">
        <select name="level" onchange="this.form.submit()" class="form-select" style="width:auto;">
          <option value="">Tüm Seviyeler</option>
          <?php foreach ($levelColors as $l => $lc): ?><option value="<?= $l ?>" <?= $level === $l ? 'selected' : '' ?>><?= $e($lc['tr']) ?> (<?= $l ?>)</option><?php endforeach; ?>
        </select>
        <select name="store" onchange="this.form.submit()" class="form-select" style="width:auto;max-width:240px;" title="Mağaza bazlı log">
          <option value="">Tüm Mağazalar</option>
          <option value="global" <?= $store === 'global' ? 'selected' : '' ?>>Global / Platform</option>
          <?php foreach ($stores as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $store === (string) $s['id'] ? 'selected' : '' ?>>#<?= (int) $s['id'] ?> · <?= $e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-1"><i data-lucide="filter"></i> Filtrele</button>
      </form>
      <a href="<?= url('/logs/export?date=' . urlencode($date) . '&level=' . urlencode($level) . '&store=' . urlencode($store)) ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" title="Bu görünümü .txt indir"><i data-lucide="download"></i> Çıktı Al</a>
      <div class="input-group flex-grow-1" style="min-width:200px;max-width:420px;">
        <span class="input-group-text"><i data-lucide="search"></i></span>
        <input type="text" id="logSearchInput" placeholder="Log içeriğinde ara… (ctrl+f)" class="form-control">
      </div>
      <?php if ($level || $date !== date('Y-m-d')): ?>
      <a href="<?= url('/logs') ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"><i data-lucide="x"></i> Sıfırla</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- grid: dosya listesi + konsol -->
<div class="row g-3 align-items-start">
  <!-- dosya listesi -->
  <div class="col-12 col-lg-3">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title d-inline-flex align-items-center gap-2"><i data-lucide="folder-open"></i> Log Dosyaları</h3></div>
      <div class="card-body p-0">
        <?php if (empty($files)): ?>
          <div class="text-center text-muted p-4"><i data-lucide="inbox" class="d-block mx-auto mb-2 opacity-50"></i> Dosya bulunamadı</div>
        <?php else: foreach ($files as $f):
          $isActive = ($f['date'] ?? '') === $date;
          $sizeKb = round(($f['size'] ?? 0) / 1024, 1);
          $isToday = ($f['date'] ?? '') === date('Y-m-d');
        ?>
        <a href="<?= url('/logs?date=' . $f['date'] . ($level ? '&level=' . $level : '')) ?>" class="ad-listrow<?= $isActive ? ' active' : '' ?>">
          <span class="ad-listrow-ico"><span class="rounded-circle d-inline-block <?= $isToday ? 'bg-success' : 'bg-secondary' ?>" style="width:8px;height:8px;"></span></span>
          <span class="ad-listrow-main">
            <span class="ad-listrow-title"><?= $e($f['date']) ?><?= $isToday ? ' (bugün)' : '' ?></span>
            <span class="ad-listrow-sub"><?= $sizeKb ?> KB</span>
          </span>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- konsol -->
  <div class="col-12 col-lg-9">
    <div class="card mb-0">
      <div class="card-header d-flex align-items-center gap-3">
        <div class="d-flex gap-1">
          <span class="rounded-circle bg-danger d-inline-block" style="width:12px;height:12px;"></span>
          <span class="rounded-circle bg-warning d-inline-block" style="width:12px;height:12px;"></span>
          <span class="rounded-circle bg-success d-inline-block" style="width:12px;height:12px;"></span>
        </div>
        <span class="card-title flex-grow-1 text-center font-monospace mb-0"><?= $e($date) ?>.log</span>
        <?php if ($errorCount > 0): ?><span class="badge bg-danger-subtle text-danger fw-medium"><?= $errorCount ?> hata</span><?php endif; ?>
        <?php if ($warnCount > 0): ?><span class="badge bg-warning-subtle text-warning fw-medium"><?= $warnCount ?> uyarı</span><?php endif; ?>
        <span class="badge bg-primary-subtle text-primary fw-medium"><?= $totalLines ?> satır</span>
      </div>
      <div class="card-body p-0">
        <div class="lg-console-body" id="logConsole">
          <?php if (empty($lines) || (count($lines) === 1 && trim($lines[0]) === '')): ?>
          <div class="text-center py-5" style="color:#475569">
            <i data-lucide="file-x" class="d-block mx-auto mb-3 opacity-50" style="width:48px;height:48px;"></i>
            <p class="fw-medium mb-1">Bu tarih için log bulunamadı</p>
            <p class="mb-0" style="font-size:12px;"><?= $e($date) ?> tarihinde kayıt yok</p>
          </div>
          <?php else:
            $lineNum = 0;
            foreach ($lines as $line):
                if (trim($line) === '') continue;
                $lineNum++;
                $isContext = str_starts_with(trim($line), 'Context:');
                if ($isContext): ?>
          <div class="lg-entry-ctx"><?= $e($line) ?></div>
                <?php continue; endif;
                $lvlClass = 'lg-debug'; $lvlBadge = 'lvl-debug'; $lvlText = 'DEBUG';
                if (str_contains($line, '[CRITICAL]')) { $lvlClass = 'lg-critical'; $lvlBadge = 'lvl-critical'; $lvlText = 'CRIT'; }
                elseif (str_contains($line, '[ERROR]'))   { $lvlClass = 'lg-error';    $lvlBadge = 'lvl-error';    $lvlText = 'ERR'; }
                elseif (str_contains($line, '[WARNING]')) { $lvlClass = 'lg-warning';  $lvlBadge = 'lvl-warning';  $lvlText = 'WARN'; }
                elseif (str_contains($line, '[INFO]'))    { $lvlClass = 'lg-info';     $lvlBadge = 'lvl-info';     $lvlText = 'INFO'; }
                $time = '';
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s(\d{2}:\d{2}:\d{2}))\]/', $line, $tm)) { $time = $tm[2]; }
                $msg = $line;
                if (preg_match('/\]\s*\[(?:DEBUG|INFO|WARNING|ERROR|CRITICAL)\]\s*(.+)$/s', $line, $mm)) { $msg = $mm[1]; }
          ?>
          <div class="lg-entry <?= $lvlClass ?>" data-log-line>
            <div class="lg-entry-num"><?= $lineNum ?></div>
            <div class="lg-entry-lvl"><span class="lg-entry-lvl-badge <?= $lvlBadge ?>"><?= $lvlText ?></span></div>
            <div class="lg-entry-time"><?= $time ?></div>
            <div class="lg-entry-msg"><?= $e($msg) ?></div>
          </div>
          <?php endforeach; endif; ?>
          <div class="lg-no-results" id="logNoResults"><i data-lucide="search-x" class="d-block mx-auto mb-2 opacity-50" style="width:32px;height:32px;"></i> Aramanızla eşleşen log bulunamadı</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var searchInput = document.getElementById('logSearchInput');
  var noResults = document.getElementById('logNoResults');
  if (searchInput) {
    var debounce;
    searchInput.addEventListener('input', function(){ clearTimeout(debounce); var v=this.value; debounce = setTimeout(function(){ filterLogs(v); }, 150); });
    document.addEventListener('keydown', function(e){ if ((e.ctrlKey || e.metaKey) && e.key === 'f') { e.preventDefault(); searchInput.focus(); searchInput.select(); } });
  }
  function filterLogs(query){
    var q = query.toLowerCase().trim();
    var entries = document.querySelectorAll('[data-log-line]');
    var visibleCount = 0;
    entries.forEach(function(el){
      var msgEl = el.querySelector('.lg-entry-msg');
      if (!msgEl) return;
      msgEl.querySelectorAll('.lg-highlight').forEach(function(h){ h.replaceWith(document.createTextNode(h.textContent)); });
      msgEl.normalize();
      if (!q) { el.style.display = ''; visibleCount++; return; }
      if (el.textContent.toLowerCase().includes(q)) { el.style.display = ''; visibleCount++; highlightText(msgEl, q); }
      else { el.style.display = 'none'; }
    });
    if (noResults) noResults.classList.toggle('show', !!q && visibleCount === 0);
  }
  function highlightText(el, query){
    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    var nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(node){
      var idx = node.textContent.toLowerCase().indexOf(query);
      if (idx === -1) return;
      var before = node.textContent.substring(0, idx), match = node.textContent.substring(idx, idx + query.length), after = node.textContent.substring(idx + query.length);
      var span = document.createElement('span'); span.className = 'lg-highlight'; span.textContent = match;
      var frag = document.createDocumentFragment();
      if (before) frag.appendChild(document.createTextNode(before));
      frag.appendChild(span);
      if (after) frag.appendChild(document.createTextNode(after));
      node.parentNode.replaceChild(frag, node);
    });
  }
  var logConsole = document.getElementById('logConsole');
  if (logConsole && logConsole.scrollHeight > logConsole.clientHeight) { logConsole.scrollTop = logConsole.scrollHeight; }
  // aktif seviye kartına tekrar tıklayınca filtreyi kaldır
  document.querySelectorAll('.lg-stat-card[data-active="1"]').forEach(function(el){
    el.addEventListener('click', function(e){ e.preventDefault(); var u = new URL(window.location); u.searchParams.delete('level'); window.location.href = u.toString(); });
  });
})();
</script>
