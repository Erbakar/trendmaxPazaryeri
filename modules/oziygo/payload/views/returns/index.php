<?php
$filters = $data['filters'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$supported = $data['supportedReturnMarketplaceSlugs'] ?? [];
$noReturnSlugs = $data['noReturnSupportSlugs'] ?? [];
$csrf = $data['_csrf'] ?? '';
$pageSize = (int) ($data['pageSize'] ?? 25);
$initial = $data['initialPayload'] ?? ['total' => 0, 'rows' => [], 'marketplaceCounts' => ['all' => 0], 'warning' => null, 'emptyMessage' => 'İade talebi bulunamadı.'];
$counts = is_array($initial['marketplaceCounts'] ?? null) ? $initial['marketplaceCounts'] : ['all' => 0];
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canApprove = $can('returns.approve');
$canReject = $can('returns.reject');
$mpColors = ['trendyol'=>'#f27a1a','amazon'=>'#ff9900','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63','idefix'=>'#1e40af'];
$mpBgs = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff4ed','n11'=>'#faf5ff','pazarama'=>'#ecfeff','pttavm'=>'#fefce8','ciceksepeti'=>'#fdf2f8','idefix'=>'#eff6ff'];
$stMap = ['requested'=>['l'=>'İşlem Bekliyor','c'=>'#f59e0b'],'approved'=>['l'=>'Onaylandı','c'=>'#10b981'],'rejected'=>['l'=>'Reddedildi','c'=>'#ef4444'],'completed'=>['l'=>'Tamamlandı','c'=>'#3b82f6'],'refunded'=>['l'=>'İade Edildi','c'=>'#4B8AF5']];
?>
<style>
/* === DataTables Overrides === */
.dataTables_wrapper{background:transparent!important}
.dataTables_wrapper .dataTables_processing{background:var(--bg-card)!important;color:var(--text-primary)!important;border:1px solid var(--border)!important;border-radius:10px!important;padding:12px 24px!important;box-shadow:var(--shadow-card)!important;font-size:13px!important;z-index:10!important}
table.dataTable{border-collapse:collapse!important}
table.dataTable thead th{border-bottom:1px solid var(--border)!important}
table.dataTable.no-footer{border-bottom:none!important}
table.dataTable td.dataTables_empty{padding:60px 20px!important;text-align:center;color:var(--text-muted);font-size:14px}
table.dataTable tbody tr{border-bottom:none!important}
.dataTables_info{font-size:12px;color:var(--text-muted);padding:0!important}
.dataTables_paginate{padding:0!important}
.dataTables_paginate .paginate_button{min-width:32px;height:32px;display:inline-flex!important;align-items:center;justify-content:center;border-radius:8px!important;font-size:12px!important;font-weight:700;color:var(--text-secondary)!important;background:var(--bg-card)!important;border:1px solid var(--border)!important;margin:0 2px!important;padding:0 8px!important;transition:all .12s;cursor:pointer}
.dataTables_paginate .paginate_button:hover{border-color:var(--primary)!important;color:var(--primary)!important;background:var(--bg-sidebar-hover)!important}
.dataTables_paginate .paginate_button.current{background:var(--primary)!important;color:#fff!important;border-color:var(--primary)!important}
.dataTables_paginate .paginate_button.disabled{opacity:.3!important;pointer-events:none}
.dataTables_length,.dataTables_filter{display:none!important}
/* ── Tile Grid ── */
.mp-grid{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.mp-grid>.mp-tile{flex:1 1 0;min-width:110px}
.mp-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;border:1.5px solid var(--border);background:var(--bg-card);transition:all .18s ease;min-height:80px;overflow:hidden}
.mp-tile:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.1);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.mp-tile:active{transform:translateY(0);box-shadow:0 2px 6px rgba(0,0,0,.06)}
.mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.8;transition:opacity .15s}
.mp-tile:hover .mp-tile-logo{opacity:1}
.mp-tile-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:var(--mp-color,var(--primary));padding:0 6px;line-height:1}
.mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.mp-tile-label{font-size:10px;color:var(--text-muted);font-weight:600;letter-spacing:.2px}
.mp-tile-inactive{opacity:.3;pointer-events:none;filter:grayscale(.7)}
.mp-tile-inactive .mp-tile-soon{position:absolute;bottom:4px;right:4px;font-size:8px;background:#ef4444;color:#fff;padding:1px 5px;border-radius:4px;font-weight:700}
.mp-tile-noreturn{opacity:.55;cursor:help}.mp-tile-noreturn:hover{transform:none;box-shadow:none}
/* ── Overlay ── */
.mp-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;display:none;flex-direction:column;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);animation:mpOverlayIn .2s ease}
.mp-overlay.active{display:flex}
@keyframes mpOverlayIn{from{opacity:0}to{opacity:1}}
.mp-window{position:absolute;top:20px;left:20px;right:20px;bottom:20px;background:var(--bg-page,#f8fafc);border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden;animation:mpWindowIn .25s ease}
@keyframes mpWindowIn{from{transform:scale(.96) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.mp-win-header{display:flex;align-items:center;gap:16px;padding:16px 24px;border-bottom:2px solid var(--mp-color,var(--border));background:var(--bg-card);flex-shrink:0}
.mp-win-logo{height:32px;width:auto;max-width:160px;object-fit:contain}
.mp-win-title{font-size:18px;font-weight:600;color:var(--text-primary);flex:1}
.mp-win-stats{display:flex;gap:16px;align-items:center}
.mp-win-stat{display:flex;flex-direction:column;align-items:center;padding:6px 14px;border-radius:10px;background:var(--bg-input)}
.mp-win-stat-val{font-size:16px;font-weight:600;color:var(--text-primary)}
.mp-win-stat-lbl{font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase}
.mp-win-close{width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:all .15s}
.mp-win-close:hover{background:#fef2f2;color:#ef4444;border-color:#ef4444}
.mp-win-toolbar{display:flex;align-items:center;gap:10px;padding:12px 24px;background:var(--bg-card);border-bottom:1px solid var(--border);flex-shrink:0;flex-wrap:wrap}
.mp-win-body{flex:1;overflow-y:auto;padding:0}
.mp-win-body .o-card{border-radius:0;border-left:none;border-right:none;box-shadow:none}
/* ── Shared ── */
.o-search{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input)}
.o-search input{border:none;background:none;outline:none;font:500 13px inherit;color:var(--text-primary);min-width:0;flex:1}
.o-sel{padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input);color:var(--text-primary);font:500 13px inherit;cursor:pointer}
.o-date{padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input);color:var(--text-primary);font:500 13px inherit}
.o-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);font:600 13px inherit;cursor:pointer;transition:all .15s;white-space:nowrap}
.o-btn:hover{border-color:var(--primary);color:var(--primary)}
.o-btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.o-btn-primary:hover{opacity:.88;color:#fff}
.o-btn-outline{background:transparent}.o-btn-outline:hover{background:var(--bg-input)}
.o-btn-sm{padding:6px 10px;font-size:12px}
.o-table{width:100%;border-collapse:collapse}
.o-table th,.o-table td{padding:12px 14px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
.o-table th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--bg-input);white-space:nowrap}
.o-table tbody tr:hover{background:rgba(25,103,242,.03)}
.o-empty{padding:44px 20px;text-align:center;color:var(--text-muted)}
.dt-footer{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px}
/* ── Main Table Card ── */
.rt-h{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}.rt-h h1{margin:0;font-size:24px;font-weight:700}.rt-h p{margin:6px 0 0;color:var(--text-muted);font-size:13px}.rt-total{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:24px;padding:0 10px;border-radius:999px;background:var(--primary);color:#fff;font-size:12px;font-weight:700}
.o-btn-success{background:#10b981;border-color:#10b981;color:#fff}.o-btn-success:hover{background:#059669;border-color:#059669;color:#fff}
.rt-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-card);overflow:hidden}.rt-card-h{display:flex;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input)}.rt-card-h h3{margin:0;font-size:15px;font-weight:700}.rt-card-h span{font-size:12px;color:var(--text-muted)}
/* ── Row Styles ── */
.rt-pulse{display:inline-block;width:8px;height:8px;border-radius:999px;background:#ef4444;animation:rtp 1.5s infinite}@keyframes rtp{0%,100%{opacity:1}50%{opacity:.35}}
.rt-prod{display:flex;align-items:center;gap:10px}.rt-media{width:44px;height:44px;border-radius:10px;border:1px solid var(--border);background:var(--bg-input);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}.rt-media img{width:100%;height:100%;object-fit:cover}.rt-title{font-size:13px;font-weight:700;color:var(--text-primary);line-height:1.45}.rt-meta{margin-top:2px;font-size:11px;color:var(--text-muted)}.rt-extra{margin-top:3px;font-size:10px;color:var(--primary);font-weight:700}.rt-order{color:var(--primary);font:600 13px monospace;text-decoration:none}.rt-reason{display:inline-flex;max-width:220px;padding:5px 8px;border-radius:8px;background:#f8fafc;color:#475569;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rt-cust-imgs{display:flex;gap:4px;margin-top:4px}.rt-cust-img{width:32px;height:32px;border-radius:6px;border:1px solid var(--border);overflow:hidden;cursor:pointer;flex-shrink:0}.rt-cust-img img{width:100%;height:100%;object-fit:cover}.rt-no-img{display:inline-flex;align-items:center;gap:4px;margin-top:4px;padding:2px 6px;border-radius:6px;background:var(--bg-input);color:var(--text-muted);font-size:10px;font-weight:700}
.rt-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid transparent;font-size:11px;font-weight:700}.rt-created{background:#eff6ff;color:#2563eb;border-color:#bfdbfe}.rt-wait{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.rt-accepted{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}.rt-rejected{background:#fef2f2;color:#dc2626;border-color:#fecaca}.rt-cancelled{background:#f1f5f9;color:#64748b;border-color:#e2e8f0}
.rt-actions{display:flex;justify-content:flex-end;gap:6px;flex-wrap:wrap}.rt-btn{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer}.rt-ok{background:#10b981;color:#fff}.rt-no{background:#ef4444;color:#fff}.rt-state-ok{font-size:11px;font-weight:700;color:#10b981}.rt-state-no{font-size:11px;font-weight:700;color:#ef4444}.rt-state-muted{font-size:11px;color:var(--text-muted)}
.rt-mp-badge{display:inline-flex;align-items:center;gap:6px}.rt-mp-badge img{height:18px;width:auto;max-width:60px;object-fit:contain}
/* ── Modals ── */
.rt-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.52);backdrop-filter:blur(4px);z-index:10001;align-items:center;justify-content:center;padding:18px}.rt-modal-bg.show{display:flex}.rt-modal{width:520px;max-width:100%;max-height:90vh;overflow:auto;background:var(--bg-card);border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.28)}.rt-modal-h{display:flex;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input)}.rt-modal-h h3{margin:0;font-size:15px;font-weight:700}.rt-modal-b{padding:20px}.rt-close{border:none;background:none;font-size:22px;color:var(--text-muted);cursor:pointer}.rt-note{padding:14px 16px;border-radius:12px;margin-bottom:16px;font-size:13px;line-height:1.6}.rt-note.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.rt-note.no{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.rt-mini{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px}.rt-mini-title{font-size:13px;font-weight:700}.rt-mini-meta{font-size:11px;color:var(--text-muted)}.rt-form{margin-bottom:14px}.rt-form label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;color:var(--text-muted)}.rt-form select,.rt-form textarea{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input);color:var(--text-primary);font:500 13px inherit;box-sizing:border-box}.rt-form textarea{min-height:90px;resize:vertical}.rt-modal-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
/* ── Responsive ── */
@media(max-width:1200px){.mp-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){.mp-grid{grid-template-columns:repeat(2,1fr);gap:8px}.mp-tile{padding:12px 6px 10px;min-height:60px}.mp-tile-logo{height:20px;max-width:70px}.mp-window{top:10px;left:10px;right:10px;bottom:10px}.mp-win-header{padding:12px 16px;gap:10px}.mp-win-toolbar{padding:10px 16px}.mp-win-stats{display:none}}
@media(max-width:920px){.rt-table-main th:nth-child(5),.rt-table-main td:nth-child(5){display:none}}
</style>

<!-- ═══════════════════════════════════════════════════════════
     HEADER
     ═══════════════════════════════════════════════════════════ -->
<div class="rt-h">
  <div>
    <h1>İade Yönetimi <span class="rt-total" id="rtTotalCount"><?= (int) ($initial['total'] ?? 0) ?></span></h1>
    <p>İadeler yeni tarihten eskiye listelenir.<?php if (!empty($initial['lastSync'])): ?> <span id="rtLastSync" style="margin-left:8px;font-size:11px;color:var(--text-muted)">Son güncelleme: <?= date('d.m.Y H:i', strtotime((string) $initial['lastSync'])) ?></span><?php else: ?> <span id="rtLastSync" style="margin-left:8px;font-size:11px;color:var(--text-muted)"></span><?php endif; ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:flex-start">
    <button type="button" class="o-btn o-btn-success" id="btnRefresh"><i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Senkronize Et</button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MARKETPLACE TILE GRID
     ═══════════════════════════════════════════════════════════ -->
<div class="mp-grid">
  <?php foreach ($marketplaces as $mp):
    $slug  = (string) ($mp['slug'] ?? '');
    $mpId  = (string) ($mp['id'] ?? '');
    $mColor = $mpColors[$slug] ?? '#1967F2';
    $mBg    = $mpBgs[$slug] ?? 'var(--bg-card)';
    $cnt    = (int) ($counts[$mpId] ?? 0);
    $ok     = in_array($slug, $supported, true);
    $isNo   = in_array($slug, $noReturnSlugs, true);
    $isDbInactive = empty($mp['is_active']);
    $isInactive = $isDbInactive || (!$ok && !$isNo);
  ?>
  <div class="mp-tile <?= $isInactive ? 'mp-tile-inactive' : '' ?> <?= $isNo ? 'mp-tile-noreturn' : '' ?>"
       style="--mp-color:<?= $mColor ?>;--mp-bg:<?= $mBg ?>"
       <?php if ($ok && !$isDbInactive): ?>onclick="openReturnWindow('<?= htmlspecialchars($mpId) ?>','<?= htmlspecialchars($slug) ?>','<?= htmlspecialchars((string) ($mp['display_name'] ?? $slug), ENT_QUOTES) ?>','<?= htmlspecialchars($mColor) ?>')"<?php elseif ($isNo && !$isDbInactive): ?>onclick="Toast.show('<?= htmlspecialchars((string) ($mp['display_name'] ?? $slug)) ?> iadeleri sadece pazaryeri panelinden yönetilebilir.','info')"<?php endif; ?>
       data-mp-id="<?= htmlspecialchars($mpId) ?>" data-mp-slug="<?= htmlspecialchars($slug) ?>">
    <img class="mp-tile-logo" src="/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.jpg'" alt="<?= htmlspecialchars((string) ($mp['display_name'] ?? $slug)) ?>">
    <span class="mp-tile-count <?= $cnt === 0 ? 'zero' : '' ?>" data-mp-cnt="<?= htmlspecialchars($mpId) ?>"><?= $cnt ?> iade</span>

    <?php if ($isDbInactive): ?><span style="position:absolute;bottom:4px;right:4px;font-size:8px;background:#9ca3af;color:#fff;padding:1px 5px;border-radius:4px;font-weight:700">Pasif</span><?php elseif ($isNo): ?><span style="position:absolute;bottom:4px;right:4px;font-size:8px;background:#f59e0b;color:#fff;padding:1px 5px;border-radius:4px;font-weight:700">Panel</span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MAIN TABLE — Tüm Pazaryerleri Toplam İadeler
     ═══════════════════════════════════════════════════════════ -->
<div class="rt-card">
  <div class="rt-card-h">
    <h3>Son İade Talepleri</h3>
    <span id="rtPageInfo"></span>
  </div>
  <div style="overflow-x:auto">
    <table id="rtMainTable" class="o-table rt-table-main" style="width:100%">
      <thead><tr>
        <th style="width:28px"></th>
        <th>Pazaryeri</th>
        <th>Ürün</th>
        <th>Sipariş</th>
        <th>Müşteri</th>
        <th>Sebep</th>
        <th>Durum</th>
        <th>Tarih</th>
        <th style="text-align:right">İşlem</th>
      </tr></thead>
      <tbody></tbody>
    </table>
  </div>
  <div class="dt-footer">
    <div id="rtDtInfo"></div>
    <div id="rtDtPaginate"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MP OVERLAY — Per-Marketplace Return Details
     ═══════════════════════════════════════════════════════════ -->
<div class="mp-overlay" id="rtOverlay">
  <div class="mp-window" id="rtWindow">
    <!-- Header -->
    <div class="mp-win-header" id="rtWinHeader">
      <img class="mp-win-logo" id="rtWinLogo" src="" alt="">
      <div class="mp-win-title" id="rtWinTitle">İadeler</div>
      <div class="mp-win-stats" id="rtWinStats"></div>
      <button class="mp-win-close" onclick="closeReturnWindow()" title="Kapat (ESC)">
        <i data-lucide="x" style="width:20px;height:20px"></i>
      </button>
    </div>
    <!-- Toolbar -->
    <div class="mp-win-toolbar">
      <div class="o-search" style="width:220px">
        <i data-lucide="search" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="rtWinSearch" placeholder="İade no, sipariş, müşteri...">
      </div>
      <select id="rtWinStatus" class="o-sel">
        <option value="">Tüm Durumlar</option>
        <?php foreach ($stMap as $k => $c): ?>
        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($c['l']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" id="rtWinDateFrom" class="o-date" placeholder="Başlangıç">
      <input type="date" id="rtWinDateTo" class="o-date" placeholder="Bitiş">
      <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="reloadReturnMpTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
      <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="clearReturnMpFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
    </div>
    <!-- Body -->
    <div class="mp-win-body">
      <div class="o-card">
        <div style="overflow-x:auto">
          <table id="rtMpTable" class="o-table" style="width:100%">
            <thead><tr>
              <th style="width:28px"></th>
              <th>Ürün</th>
              <th>Sipariş</th>
              <th>Müşteri</th>
              <th>Sebep</th>
              <th>Durum</th>
              <th>Tarih</th>
              <th style="text-align:right">İşlem</th>
            </tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="dt-footer">
          <div id="rtMpDtInfo"></div>
          <div id="rtMpDtPaginate"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODALS — Approve / Reject / Pend
     ═══════════════════════════════════════════════════════════ -->
<?php if ($canApprove): ?>
<div class="rt-modal-bg" id="approveOverlay" onclick="if(event.target===this)closeApproveModal()">
  <div class="rt-modal">
    <div class="rt-modal-h"><h3>İade Onayla</h3><button type="button" class="rt-close" onclick="closeApproveModal()">&times;</button></div>
    <div class="rt-modal-b">
      <div class="rt-note ok"><strong>İade onaylanacak.</strong> Bu işlem geri alınamaz.</div>
      <div id="approveItems"></div>
      <div class="rt-modal-actions">
        <button type="button" class="o-btn o-btn-outline" onclick="closeApproveModal()">İptal</button>
        <button type="button" class="rt-btn rt-ok" id="btnConfirmApprove" onclick="confirmApprove()">Onayla</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canReject): ?>
<div class="rt-modal-bg" id="rejectOverlay" onclick="if(event.target===this)closeRejectModal()">
  <div class="rt-modal">
    <div class="rt-modal-h"><h3>İade Reddet</h3><button type="button" class="rt-close" onclick="closeRejectModal()">&times;</button></div>
    <div class="rt-modal-b">
      <div class="rt-note no"><strong>İade reddedilecek.</strong> Ret sebebi zorunludur.</div>
      <div id="rejectItems"></div>
      <div class="rt-form"><label for="rejectReasonSelect">Ret Sebebi *</label><select id="rejectReasonSelect"><option value="">Yükleniyor...</option></select></div>
      <div class="rt-form"><label for="rejectDescription">Açıklama</label><textarea id="rejectDescription" placeholder="Opsiyonel ret açıklaması"></textarea></div>
      <div class="rt-modal-actions">
        <button type="button" class="o-btn o-btn-outline" onclick="closeRejectModal()">İptal</button>
        <button type="button" class="rt-btn rt-no" id="btnConfirmReject" onclick="confirmReject()">Reddet</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="rt-modal-bg" id="pendOverlay" onclick="if(event.target===this)closePendModal()">
  <div class="rt-modal">
    <div class="rt-modal-h"><h3>İade Ertele (N11)</h3><button type="button" class="rt-close" onclick="closePendModal()">&times;</button></div>
    <div class="rt-modal-b">
      <div class="rt-note" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e"><strong>İade ertelenecek.</strong> Erteleme sebebi ve gün sayısı zorunludur.</div>
      <div class="rt-form"><label for="pendReasonSelect">Erteleme Sebebi *</label><select id="pendReasonSelect"><option value="">Yükleniyor...</option></select></div>
      <div class="rt-form"><label for="pendDayCount">Erteleme Gün Sayısı * (1-30)</label><input type="number" id="pendDayCount" class="o-sel" min="1" max="30" value="3" style="width:100%;box-sizing:border-box"></div>
      <div class="rt-form"><label for="pendReasonNote">Açıklama</label><textarea id="pendReasonNote" placeholder="Erteleme açıklaması"></textarea></div>
      <div class="rt-modal-actions">
        <button type="button" class="o-btn o-btn-outline" onclick="closePendModal()">İptal</button>
        <button type="button" class="rt-btn" style="background:#f59e0b;color:#fff" id="btnConfirmPend" onclick="confirmPend()">Ertele</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════ -->
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || <?= json_encode($csrf) ?>;
const PAGE_SIZE = <?= $pageSize ?>;
const CAN_APPROVE = <?= $canApprove ? 'true' : 'false' ?>;
const CAN_REJECT = <?= $canReject ? 'true' : 'false' ?>;
const INITIAL_COUNTS = <?= json_encode($counts, JSON_UNESCAPED_UNICODE) ?>;
const NO_RETURN_SLUGS = <?= json_encode($noReturnSlugs, JSON_UNESCAPED_UNICODE) ?>;

let mainTable = null;
let mpReturnTable = null;
let activeMpId = '';
let activeMpSlug = '';
let currentClaimId = '';
let currentClaimLineItemIds = [];
let currentMpSlug = '';
let issueReasonsLoaded = false;
let pendReasonsLoaded = false;

const toast = (m, t = 'info') => typeof Toast !== 'undefined' && typeof Toast.show === 'function' ? Toast.show(m, t) : console.log(t + ': ' + m);
const esc = v => { const d = document.createElement('div'); d.textContent = v || ''; return d.innerHTML; };

/* ── Status Badge ── */
function statusBadge(r) {
    const s = String(r.status || 'created');
    const m = {
        accepted: 'rt-accepted', approved: 'rt-accepted',
        rejected: 'rt-rejected', denied: 'rt-rejected',
        waiting_in_action: 'rt-wait', waiting_fraud_check: 'rt-wait', unresolved: 'rt-wait', in_analysis: 'rt-wait', pending: 'rt-wait', pended: 'rt-wait', requested: 'rt-created',
        manual_refund: 'rt-accepted', cancelled: 'rt-cancelled', completed: 'rt-accepted', refunded: 'rt-accepted'
    };
    return '<span class="rt-badge ' + (m[s] || 'rt-created') + '">' + esc(r.status_label || r.status || '—') + '</span>';
}

/* ── DataTable Column Renderers ── */
function renderReturnPulse(data, type, row) {
    return row.action_required ? '<span class="rt-pulse"></span>' : '';
}

function renderReturnMarketplace(data, type, row) {
    const slug = row.marketplace_slug || '';
    const name = row.marketplace_name || slug;
    if (!slug) return esc(name);
    return '<span class="rt-mp-badge"><img src="/public/assets/img/marketplaces/' + esc(slug) + '.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + esc(slug) + '.jpg\'" alt="' + esc(name) + '"></span>';
}

function renderReturnProduct(data, type, row) {
    const items = Array.isArray(row.items) ? row.items : [];
    const f = items[0] || {};
    const img = row.image_url && String(row.image_url).trim() !== ''
        ? '<img src="' + esc(row.image_url) + '" alt="" onerror="this.style.display=\'none\'">'
        : '<i data-lucide="package" style="width:18px;height:18px;color:var(--text-muted)"></i>';
    const extra = Number(row.item_count || 0) > 1 ? '<div class="rt-extra">+' + (Number(row.item_count || 0) - 1) + ' ürün daha</div>' : '';
    return '<div class="rt-prod"><div class="rt-media">' + img + '</div><div><div class="rt-title">' + esc(f.product_name || '—') + '</div><div class="rt-meta">' + esc(f.merchant_sku || '') + '</div>' + extra + '</div></div>';
}

function renderReturnOrder(data, type, row) {
    if (row.order_id) return '<a href="/orders/' + esc(String(row.order_id)) + '" class="rt-order">#' + esc(row.marketplace_order_id || '—') + '</a>';
    return '<span class="rt-order">#' + esc(row.marketplace_order_id || '—') + '</span>';
}

function renderReturnCustomer(data, type, row) {
    return esc(row.customer_name || '—');
}

function renderReturnReason(data, type, row) {
    return '<span class="rt-reason" title="' + esc(row.reason || '') + '">' + esc(row.reason || '—') + '</span>';
}

function renderReturnStatus(data, type, row) {
    return statusBadge(row);
}

function renderReturnDate(data, type, row) {
    const raw = String(row.claim_date || row.created_at || '').trim();
    if (!raw) return '—';
    const ts = Date.parse(raw.replace(' ', 'T'));
    if (Number.isNaN(ts)) return esc(raw);
    return new Date(ts).toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function renderReturnActions(data, type, row) {
    const items = Array.isArray(row.items) ? row.items : [];
    const mpSlug = row.marketplace_slug || '';
    const isN11 = mpSlug === 'n11';
    const isPttavm = NO_RETURN_SLUGS.includes(mpSlug);

    if (row.action_required && !isPttavm && (CAN_APPROVE || CAN_REJECT)) {
        const claimId = row.claim_id || '';
        const ids = JSON.stringify(row.claim_item_ids || []);
        const payload = JSON.stringify(items);
        let h = '';
        if (CAN_APPROVE) h += '<button type="button" class="rt-btn rt-ok" data-action="approve" data-claim="' + esc(claimId) + '" data-ids=\'' + esc(ids) + '\' data-payload=\'' + esc(payload) + '\' data-mp="' + esc(mpSlug) + '">Onayla</button>';
        if (isN11) h += '<button type="button" class="rt-btn" style="background:#f59e0b;color:#fff" data-action="pend" data-claim="' + esc(claimId) + '" data-mp="' + esc(mpSlug) + '">Ertele</button>';
        if (CAN_REJECT) h += '<button type="button" class="rt-btn rt-no" data-action="reject" data-claim="' + esc(claimId) + '" data-ids=\'' + esc(ids) + '\' data-payload=\'' + esc(payload) + '\' data-mp="' + esc(mpSlug) + '">Reddet</button>';
        return '<div class="rt-actions">' + h + '</div>';
    }
    if (isPttavm && row.action_required) return '<span class="rt-state-muted">Panel gerekli</span>';
    if (row.status === 'accepted' || row.status === 'approved') return '<span class="rt-state-ok">Onaylandı</span>';
    if (row.status === 'rejected') return '<span class="rt-state-no">Reddedildi</span>';
    if (row.status === 'pending' || row.status === 'pended') return '<span style="font-size:11px;font-weight:700;color:#f59e0b">Ertelendi</span>';
    if (row.status === 'manual_refund') return '<span class="rt-state-ok">Manuel İade</span>';
    return '<span class="rt-state-muted">Aksiyon gerekmiyor</span>';
}

/* ── Tile Count Update ── */
function updateTileCounts(c) {
    document.querySelectorAll('[data-mp-cnt]').forEach(el => {
        const id = el.getAttribute('data-mp-cnt') || '';
        const n = Number(c?.[id] || 0);
        el.textContent = n + ' iade';
        el.classList.toggle('zero', n === 0);
    });
}

/* ── Main DataTable Init ── */
document.addEventListener('DOMContentLoaded', function() {
    updateTileCounts(INITIAL_COUNTS);

    mainTable = $('#rtMainTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/returns/ajax',
            data: function(d) {
                d.marketplace_id = '';
                d.status = '';
                d.date_from = '';
                d.date_to = '';
            },
            dataSrc: function(json) {
                updateTileCounts(json.mpReturnCounts || {});
                const total = json.recordsTotal || 0;
                document.getElementById('rtTotalCount').textContent = String(total);
                return json.data;
            }
        },
        columns: [
            { data: null, orderable: false, className: 'dt-center', width: '28px', render: renderReturnPulse },
            { data: null, orderable: false, render: renderReturnMarketplace },
            { data: null, orderable: false, render: renderReturnProduct },
            { data: null, orderable: true, render: renderReturnOrder },
            { data: null, orderable: false, render: renderReturnCustomer },
            { data: null, orderable: false, render: renderReturnReason },
            { data: 'status', orderable: true, render: renderReturnStatus },
            { data: null, orderable: true, render: renderReturnDate },
            { data: null, orderable: false, className: 'dt-right', render: renderReturnActions }
        ],
        order: [[7, 'desc']],
        pageLength: PAGE_SIZE,
        lengthMenu: [25, 50, 100],
        searching: false,
        dom: 'rt<"dt-footer"ip>',
        language: {
            processing: '<div style="display:flex;align-items:center;gap:8px"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
            emptyTable: '<div class="o-empty"><i data-lucide="inbox" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>İade talebi bulunamadı</div>',
            zeroRecords: '<div class="o-empty"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Aramanıza uygun iade bulunamadı</div>',
            info: 'Toplam _TOTAL_ iade · Sayfa _PAGE_/_PAGES_',
            infoEmpty: 'İade yok',
            infoFiltered: '(_MAX_ kayıttan filtrelendi)',
            paginate: { previous: '<i data-lucide="chevron-left" style="width:14px;height:14px"></i>', next: '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>' }
        },
        drawCallback: function() { lucide.createIcons(); }
    });

    lucide.createIcons();
});

/* ── Overlay: Open / Close ── */
function openReturnWindow(mpId, slug, displayName, color) {
    activeMpId = mpId;
    activeMpSlug = slug;
    currentMpSlug = slug;
    issueReasonsLoaded = false;

    // Header
    const header = document.getElementById('rtWinHeader');
    header.style.borderBottomColor = color;
    document.getElementById('rtWinLogo').src = '/public/assets/img/marketplaces/' + slug + '.png';
    document.getElementById('rtWinLogo').onerror = function() { this.src = '/public/assets/img/marketplaces/' + slug + '.jpg'; };
    document.getElementById('rtWinTitle').textContent = 'İadeler';

    // Reset filters
    document.getElementById('rtWinSearch').value = '';
    document.getElementById('rtWinStatus').value = '';
    document.getElementById('rtWinDateFrom').value = '';
    document.getElementById('rtWinDateTo').value = '';

    // Show overlay
    document.getElementById('rtOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    // Init or reload DataTable
    if (mpReturnTable) {
        mpReturnTable.destroy();
        $('#rtMpTable').empty();
        $('#rtMpTable').html('<thead><tr><th style="width:28px"></th><th>Ürün</th><th>Sipariş</th><th>Müşteri</th><th>Sebep</th><th>Durum</th><th>Tarih</th><th style="text-align:right">İşlem</th></tr></thead><tbody></tbody>');
    }

    mpReturnTable = $('#rtMpTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/returns/ajax',
            data: function(d) {
                d.marketplace_id = activeMpId;
                d.status = document.getElementById('rtWinStatus').value;
                d.date_from = document.getElementById('rtWinDateFrom').value;
                d.date_to = document.getElementById('rtWinDateTo').value;
            },
            dataSrc: function(json) {
                // Update stats in header
                const stats = document.getElementById('rtWinStats');
                const sc = json.statusCounts || {};
                const total = sc.all || 0;
                const bekleyen = (sc.requested || 0) + (sc.waiting_in_action || 0) + (sc.pending || 0);
                const onaylanan = (sc.approved || 0) + (sc.completed || 0) + (sc.refunded || 0);
                const reddedilen = sc.rejected || 0;
                stats.innerHTML =
                    '<div class="mp-win-stat"><span class="mp-win-stat-val">' + total + '</span><span class="mp-win-stat-lbl">Toplam</span></div>' +
                    '<div class="mp-win-stat" style="background:#fff7ed"><span class="mp-win-stat-val" style="color:#f59e0b">' + bekleyen + '</span><span class="mp-win-stat-lbl">Bekleyen</span></div>' +
                    '<div class="mp-win-stat" style="background:#f0fdf4"><span class="mp-win-stat-val" style="color:#10b981">' + onaylanan + '</span><span class="mp-win-stat-lbl">Onaylı</span></div>' +
                    '<div class="mp-win-stat" style="background:#fef2f2"><span class="mp-win-stat-val" style="color:#ef4444">' + reddedilen + '</span><span class="mp-win-stat-lbl">Reddedildi</span></div>';
                // Update tile counts from response
                updateTileCounts(json.mpReturnCounts || {});
                return json.data;
            }
        },
        columns: [
            { data: null, orderable: false, className: 'dt-center', width: '28px', render: renderReturnPulse },
            { data: null, orderable: false, render: renderReturnProduct },
            { data: null, orderable: true, render: renderReturnOrder },
            { data: null, orderable: false, render: renderReturnCustomer },
            { data: null, orderable: false, render: renderReturnReason },
            { data: 'status', orderable: true, render: renderReturnStatus },
            { data: null, orderable: true, render: renderReturnDate },
            { data: null, orderable: false, className: 'dt-right', render: renderReturnActions }
        ],
        order: [[6, 'desc']],
        pageLength: PAGE_SIZE,
        lengthMenu: [25, 50, 100],
        searching: true,
        dom: 'rt<"dt-footer"ip>',
        language: {
            processing: '<div style="display:flex;align-items:center;gap:8px"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
            emptyTable: '<div class="o-empty"><i data-lucide="inbox" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Bu pazaryerinde iade talebi bulunamadı</div>',
            zeroRecords: '<div class="o-empty"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Aramanıza uygun iade bulunamadı</div>',
            info: 'Toplam _TOTAL_ iade · Sayfa _PAGE_/_PAGES_',
            infoEmpty: 'İade yok',
            infoFiltered: '(_MAX_ kayıttan filtrelendi)',
            paginate: { previous: '<i data-lucide="chevron-left" style="width:14px;height:14px"></i>', next: '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>' }
        },
        drawCallback: function() { lucide.createIcons(); }
    });

    lucide.createIcons();
}

function closeReturnWindow() {
    document.getElementById('rtOverlay').classList.remove('active');
    document.body.style.overflow = '';
    activeMpSlug = '';
    activeMpId = '';
    // Refresh main table to catch changes
    if (mainTable) mainTable.ajax.reload(null, false);
}

/* ── Overlay Filters ── */
function reloadReturnMpTable() { if (mpReturnTable) mpReturnTable.ajax.reload(); }
function clearReturnMpFilters() {
    document.getElementById('rtWinSearch').value = '';
    document.getElementById('rtWinStatus').value = '';
    document.getElementById('rtWinDateFrom').value = '';
    document.getElementById('rtWinDateTo').value = '';
    if (mpReturnTable) mpReturnTable.search('').ajax.reload();
}

/* Debounced search in overlay */
let rtSearchTimer;
document.getElementById('rtWinSearch').addEventListener('input', function() {
    clearTimeout(rtSearchTimer);
    const val = this.value;
    rtSearchTimer = setTimeout(() => { if (mpReturnTable) mpReturnTable.search(val).draw(); }, 400);
});
document.getElementById('rtWinSearch').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(rtSearchTimer); if (mpReturnTable) mpReturnTable.search(this.value).draw(); }
});
document.getElementById('rtWinStatus').addEventListener('change', function() { if (mpReturnTable) mpReturnTable.ajax.reload(); });

/* ── ESC + Click-outside ── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close modals first
        if (document.getElementById('approveOverlay')?.classList.contains('show')) { closeApproveModal(); return; }
        if (document.getElementById('rejectOverlay')?.classList.contains('show')) { closeRejectModal(); return; }
        if (document.getElementById('pendOverlay')?.classList.contains('show')) { closePendModal(); return; }
        // Then close overlay
        if (document.getElementById('rtOverlay').classList.contains('active')) { closeReturnWindow(); }
    }
});
document.getElementById('rtOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeReturnWindow();
});

/* ── Sync Button — SSE Progress Modal ── */
document.getElementById('btnRefresh').addEventListener('click', function() {
    this.disabled = true;

    // Progress overlay oluştur (orders ile aynı CSS paylaşılır)
    let overlay = document.getElementById('syncOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'syncOverlay';
        overlay.innerHTML = `
        <div class="sync-modal">
            <div class="sync-modal-header">
                <div class="sync-modal-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 12 12 12"/></svg></div>
                <div>
                    <h3 class="sync-modal-title">İade Senkronizasyonu</h3>
                    <p class="sync-modal-sub" id="syncSubtitle">Pazaryerleri taranıyor...</p>
                </div>
            </div>
            <div class="sync-modal-body" id="syncBody"></div>
            <div class="sync-modal-progress">
                <div class="sync-progress-track"><div class="sync-progress-bar" id="syncBar"></div></div>
                <span class="sync-progress-text" id="syncProgressText">0%</span>
            </div>
            <div class="sync-modal-footer" id="syncFooter" style="display:none">
                <button class="o-btn o-btn-primary o-btn-sm" onclick="document.getElementById('syncOverlay').style.display='none'">Tamam</button>
            </div>
        </div>`;
        document.body.appendChild(overlay);

        if (!document.getElementById('syncModalCSS')) {
            const css = document.createElement('style');
            css.id = 'syncModalCSS';
            css.textContent = `
            #syncOverlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;animation:syncFadeIn .25s ease}
            @keyframes syncFadeIn{from{opacity:0}to{opacity:1}}
            .sync-modal{background:var(--card-bg,#fff);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:420px;max-width:92vw;overflow:hidden;animation:syncSlideUp .3s ease}
            @keyframes syncSlideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
            .sync-modal-header{display:flex;align-items:center;gap:14px;padding:20px 24px 12px;border-bottom:1px solid var(--border,#e5e7eb)}
            .sync-modal-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;color:#fff;animation:spin 2s linear infinite;flex-shrink:0}
            .sync-modal-icon.done{animation:none;background:linear-gradient(135deg,#10b981,#059669)}
            .sync-modal-icon.error{animation:none;background:linear-gradient(135deg,#ef4444,#dc2626)}
            .sync-modal-title{font-size:16px;font-weight:700;margin:0;color:var(--text,#111)}
            .sync-modal-sub{font-size:12px;color:var(--text-muted,#6b7280);margin:4px 0 0}
            .sync-modal-body{padding:16px 24px;max-height:340px;overflow-y:auto}
            .sync-mp-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border,#f3f4f6)}
            .sync-mp-row:last-child{border-bottom:none}
            .sync-mp-logo{width:28px;height:28px;border-radius:6px;object-fit:contain;flex-shrink:0}
            .sync-mp-info{flex:1;min-width:0}
            .sync-mp-name{font-size:13px;font-weight:600;color:var(--text,#111)}
            .sync-mp-detail{font-size:11px;color:var(--text-muted,#9ca3af);margin-top:2px}
            .sync-mp-status{flex-shrink:0;width:28px;height:28px;display:flex;align-items:center;justify-content:center}
            .sync-mp-status .spinner{width:20px;height:20px;border:2.5px solid var(--border,#e5e7eb);border-top-color:#10b981;border-radius:50%;animation:spin .7s linear infinite}
            .sync-mp-status .check{color:#10b981;font-size:18px;font-weight:700}
            .sync-mp-status .fail{color:#ef4444;font-size:18px;font-weight:700}
            .sync-modal-progress{padding:12px 24px;display:flex;align-items:center;gap:12px}
            .sync-progress-track{flex:1;height:6px;border-radius:3px;background:var(--border,#e5e7eb);overflow:hidden}
            .sync-progress-bar{height:100%;border-radius:3px;background:linear-gradient(90deg,#10b981,#34d399);transition:width .4s ease;width:0}
            .sync-progress-text{font-size:12px;font-weight:600;color:var(--text-muted,#6b7280);min-width:32px;text-align:right}
            .sync-modal-footer{padding:12px 24px 16px;display:flex;justify-content:flex-end;border-top:1px solid var(--border,#e5e7eb)}
            @keyframes spin{to{transform:rotate(360deg)}}
            `;
            document.head.appendChild(css);
        }
    }

    overlay.style.display = 'flex';
    const body = document.getElementById('syncBody');
    const bar = document.getElementById('syncBar');
    const ptext = document.getElementById('syncProgressText');
    const subtitle = document.getElementById('syncSubtitle');
    const footer = document.getElementById('syncFooter');
    const icon = overlay.querySelector('.sync-modal-icon');
    body.innerHTML = '';
    bar.style.width = '0%';
    ptext.textContent = '0%';
    subtitle.textContent = 'İadeler taranıyor...';
    footer.style.display = 'none';
    icon.className = 'sync-modal-icon';

    const mpRows = {};
    let totalMps = 0, doneMps = 0;
    const btnRef = this;

    function addRow(slug, displayName, statusHtml, detail) {
        const row = document.createElement('div');
        row.className = 'sync-mp-row';
        row.innerHTML = `
            <img class="sync-mp-logo" src="/public/assets/img/marketplaces/${slug}.png" alt="${displayName}" onerror="this.style.display='none'">
            <div class="sync-mp-info">
                <div class="sync-mp-name">${displayName}</div>
                <div class="sync-mp-detail" id="syncDetail_${slug}">${detail}</div>
            </div>
            <div class="sync-mp-status" id="syncStatus_${slug}">${statusHtml}</div>
        `;
        body.appendChild(row);
        mpRows[slug] = row;
    }

    function updateProgress() {
        const pct = totalMps > 0 ? Math.round((doneMps / totalMps) * 100) : 0;
        bar.style.width = pct + '%';
        ptext.textContent = doneMps + '/' + totalMps;
    }

    const fd = new FormData();
    fd.append('_csrf_token', CSRF);

    fetch('/returns/sync-stream', { method: 'POST', body: fd })
    .then(response => {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        function processChunk(chunk) {
            buffer += chunk;
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';
            let currentEvent = '';
            for (const line of lines) {
                if (line.startsWith('event: ')) currentEvent = line.substring(7).trim();
                else if (line.startsWith('data: ')) {
                    try { handleSSE(currentEvent, JSON.parse(line.substring(6))); } catch(e) {}
                    currentEvent = '';
                }
            }
        }

        function handleSSE(event, data) {
            const slug = data.slug || '';
            const name = data.display_name || slug;
            if (event === 'start') {
                totalMps++;
                subtitle.textContent = name + ' iadeleri çekiliyor...';
                addRow(slug, name, '<div class="spinner"></div>', 'Çekiliyor...');
                updateProgress();
            } else if (event === 'done') {
                doneMps++;
                const r = data.result || {};
                const detailEl = document.getElementById('syncDetail_' + slug);
                const statusEl = document.getElementById('syncStatus_' + slug);
                if (detailEl) detailEl.textContent = (r.fetched||0) + ' çekildi, ' + (r.processed||0) + ' işlendi';
                if (statusEl) statusEl.innerHTML = '<span class="check">✓</span>';
                subtitle.textContent = name + ' tamamlandı';
                updateProgress();
            } else if (event === 'error') {
                doneMps++;
                if (!mpRows[slug]) { totalMps++; addRow(slug, name, '', ''); }
                const detailEl = document.getElementById('syncDetail_' + slug);
                const statusEl = document.getElementById('syncStatus_' + slug);
                if (detailEl) { detailEl.textContent = (data.result||{}).error||'Hata'; detailEl.style.color='#ef4444'; }
                if (statusEl) statusEl.innerHTML = '<span class="fail">✗</span>';
                updateProgress();
            } else if (event === 'complete') {
                bar.style.width = '100%';
                ptext.textContent = '✓';
                icon.className = data.success ? 'sync-modal-icon done' : 'sync-modal-icon error';
                subtitle.textContent = data.message || 'Tamamlandı';
                footer.style.display = 'flex';
                btnRef.disabled = false;
                btnRef.innerHTML = '<i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Senkronize Et';
                lucide.createIcons();
                if (mainTable) mainTable.ajax.reload(null, false);
                const el = document.getElementById('rtLastSync');
                if (el) el.textContent = 'Son güncelleme: ' + new Date().toLocaleString('tr-TR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
            }
        }

        function read() {
            reader.read().then(({ done, value }) => {
                if (done) { if (buffer.trim()) processChunk('\n'); return; }
                processChunk(decoder.decode(value, { stream: true }));
                read();
            }).catch(() => {
                subtitle.textContent = 'Bağlantı kesildi';
                footer.style.display = 'flex';
                icon.className = 'sync-modal-icon error';
                btnRef.disabled = false;
                btnRef.innerHTML = '<i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Senkronize Et';
                lucide.createIcons();
            });
        }
        read();
    })
    .catch(() => {
        subtitle.textContent = 'Bağlantı hatası';
        footer.style.display = 'flex';
        icon.className = 'sync-modal-icon error';
        btnRef.disabled = false;
        btnRef.innerHTML = '<i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Senkronize Et';
        lucide.createIcons();
    });
});

/* ── Action Delegation (works in both tables + overlay) ── */
function renderMini(items) {
    return (items || []).map(i => '<div class="rt-mini"><div><div class="rt-mini-title">' + esc(i.product_name || '—') + '</div><div class="rt-mini-meta">' + esc(i.merchant_sku || '') + '</div></div>' + (i.reason ? '<span class="rt-reason">' + esc(i.reason) + '</span>' : '') + '</div>').join('');
}

document.addEventListener('click', function(e) {
    const lb = e.target.closest('[data-lightbox]');
    if (lb) { openImageLightbox(lb.getAttribute('data-lightbox')); return; }

    const act = e.target.closest('[data-action]');
    if (act) {
        const action = act.getAttribute('data-action');
        const claimId = act.getAttribute('data-claim') || '';
        const mpSlug = act.getAttribute('data-mp') || '';
        let ids = [], payload = [];
        try { ids = JSON.parse(act.getAttribute('data-ids') || '[]'); } catch (x) {}
        try { payload = JSON.parse(act.getAttribute('data-payload') || '[]'); } catch (x) {}
        if (action === 'approve') openApproveModal(claimId, ids, payload, mpSlug);
        else if (action === 'reject') openRejectModal(claimId, ids, payload, mpSlug);
        else if (action === 'pend') openPendModal(claimId, mpSlug);
    }
});

/* ── Approve Modal ── */
function openApproveModal(claimId, claimItemIds, items, mpSlug) {
    currentClaimId = claimId;
    currentClaimLineItemIds = Array.isArray(claimItemIds) ? claimItemIds : [];
    currentMpSlug = mpSlug || activeMpSlug || '';
    const list = document.getElementById('approveItems');
    if (list) list.innerHTML = renderMini(items);
    document.getElementById('approveOverlay')?.classList.add('show');
    lucide.createIcons();
}
function closeApproveModal() { document.getElementById('approveOverlay')?.classList.remove('show'); }

async function confirmApprove() {
    const btn = document.getElementById('btnConfirmApprove');
    if (!btn) return;
    btn.disabled = true; btn.textContent = 'İşleniyor...';
    try {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('marketplace', currentMpSlug);
        currentClaimLineItemIds.forEach(id => fd.append('claim_line_item_ids[]', id));
        const res = await fetch('/returns/' + encodeURIComponent(currentClaimId) + '/approve', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const d = await res.json();
        closeApproveModal();
        if (d.success) {
            toast(d.message || 'İade başarıyla onaylandı', 'success');
            reloadAllTables();
        } else {
            toast(d.message || 'Onay işlemi başarısız', 'error');
        }
    } catch (e) { toast('Bağlantı hatası oluştu', 'error'); }
    finally { btn.disabled = false; btn.textContent = 'Onayla'; }
}

/* ── Reject Modal ── */
function openRejectModal(claimId, claimItemIds, items, mpSlug) {
    currentClaimId = claimId;
    currentClaimLineItemIds = Array.isArray(claimItemIds) ? claimItemIds : [];
    currentMpSlug = mpSlug || activeMpSlug || '';
    const list = document.getElementById('rejectItems');
    if (list) list.innerHTML = renderMini(items);
    document.getElementById('rejectDescription').value = '';
    document.getElementById('rejectOverlay')?.classList.add('show');
    if (!issueReasonsLoaded) loadIssueReasons();
}
function closeRejectModal() { document.getElementById('rejectOverlay')?.classList.remove('show'); }

async function loadIssueReasons() {
    try {
        const mpParam = currentMpSlug === 'n11' ? '?marketplace=n11' : (currentMpSlug === 'pazarama' ? '?marketplace=pazarama' : '');
        const res = await fetch('/returns/issue-reasons' + mpParam, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await res.json();
        const sel = document.getElementById('rejectReasonSelect');
        if (!sel) return;
        if (d.success && Array.isArray(d.reasons) && d.reasons.length > 0) {
            sel.innerHTML = '<option value="">Sebep seçin</option>' + d.reasons.map(r => '<option value="' + esc(String(r.id || '')) + '">' + esc(r.name || r.description || r.value || ('Sebep #' + r.id)) + '</option>').join('');
            issueReasonsLoaded = true;
            return;
        }
        sel.innerHTML = '<option value="">Sebep alınamadı</option>';
    } catch (e) {
        const sel = document.getElementById('rejectReasonSelect');
        if (sel) sel.innerHTML = '<option value="">Sebep alınamadı</option>';
    }
}

async function confirmReject() {
    const reasonId = document.getElementById('rejectReasonSelect')?.value || '';
    if (!reasonId) { toast('Ret sebebi seçin', 'error'); return; }
    const btn = document.getElementById('btnConfirmReject');
    if (!btn) return;
    btn.disabled = true; btn.textContent = 'İşleniyor...';
    try {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('marketplace', currentMpSlug);
        fd.append('claim_issue_reason_id', reasonId);
        fd.append('description', document.getElementById('rejectDescription')?.value.trim() || '');
        currentClaimLineItemIds.forEach(id => fd.append('claim_line_item_ids[]', id));
        const res = await fetch('/returns/' + encodeURIComponent(currentClaimId) + '/reject', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const d = await res.json();
        closeRejectModal();
        if (d.success) {
            toast('İade başarıyla reddedildi', 'success');
            reloadAllTables();
        } else {
            toast(d.message || 'Ret işlemi başarısız', 'error');
        }
    } catch (e) { toast('Bağlantı hatası oluştu', 'error'); }
    finally { btn.disabled = false; btn.textContent = 'Reddet'; }
}

/* ── Pend Modal (N11) ── */
function openPendModal(claimId, mpSlug) {
    currentClaimId = claimId;
    currentMpSlug = mpSlug || 'n11';
    document.getElementById('pendDayCount').value = '3';
    document.getElementById('pendReasonNote').value = '';
    document.getElementById('pendOverlay')?.classList.add('show');
    if (!pendReasonsLoaded) loadPendReasons();
}
function closePendModal() { document.getElementById('pendOverlay')?.classList.remove('show'); }

async function loadPendReasons() {
    try {
        const res = await fetch('/returns/pending-reasons', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await res.json();
        const sel = document.getElementById('pendReasonSelect');
        if (!sel) return;
        if (d.success && Array.isArray(d.reasons) && d.reasons.length > 0) {
            sel.innerHTML = '<option value="">Sebep seçin</option>' + d.reasons.map(r => '<option value="' + esc(String(r.id || '')) + '">' + esc(r.name || r.value || ('Sebep #' + r.id)) + '</option>').join('');
            pendReasonsLoaded = true;
            return;
        }
        sel.innerHTML = '<option value="">Sebep alınamadı</option>';
    } catch (e) {
        const sel = document.getElementById('pendReasonSelect');
        if (sel) sel.innerHTML = '<option value="">Sebep alınamadı</option>';
    }
}

async function confirmPend() {
    const reasonId = document.getElementById('pendReasonSelect')?.value || '';
    if (!reasonId) { toast('Erteleme sebebi seçin', 'error'); return; }
    const dayCount = parseInt(document.getElementById('pendDayCount')?.value || '0', 10);
    if (dayCount < 1 || dayCount > 30) { toast('Gün sayısı 1-30 arasında olmalıdır', 'error'); return; }
    const btn = document.getElementById('btnConfirmPend');
    if (!btn) return;
    btn.disabled = true; btn.textContent = 'İşleniyor...';
    try {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('pending_reason_id', reasonId);
        fd.append('pending_day_count', String(dayCount));
        fd.append('pending_reason_note', document.getElementById('pendReasonNote')?.value.trim() || '');
        const res = await fetch('/returns/' + encodeURIComponent(currentClaimId) + '/pend', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
        const d = await res.json();
        closePendModal();
        if (d.success) {
            toast('İade başarıyla ertelendi', 'success');
            reloadAllTables();
        } else {
            toast(d.message || 'Erteleme başarısız', 'error');
        }
    } catch (e) { toast('Bağlantı hatası oluştu', 'error'); }
    finally { btn.disabled = false; btn.textContent = 'Ertele'; }
}

/* ── Helpers ── */
function reloadAllTables() {
    if (mpReturnTable && document.getElementById('rtOverlay').classList.contains('active')) mpReturnTable.ajax.reload(null, false);
    if (mainTable) mainTable.ajax.reload(null, false);
}

function openImageLightbox(url) {
    const bg = document.createElement('div');
    bg.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);z-index:10002;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px';
    bg.onclick = () => bg.remove();
    const im = document.createElement('img');
    im.src = url;
    im.style.cssText = 'max-width:90vw;max-height:85vh;border-radius:12px;box-shadow:0 12px 48px rgba(0,0,0,.4)';
    bg.appendChild(im);
    document.body.appendChild(bg);
}
</script>
