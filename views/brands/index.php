<?php
$csrf           = $data['_csrf'] ?? '';
$marketplaces   = $data['marketplaces'] ?? [];
$mpStats        = $data['mpStats'] ?? [];
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
$totalBrands     = $data['totalBrands'] ?? 0;
$brandedProducts = $data['brandedProducts'] ?? 0;
$unmatchedBrands = $data['unmatchedBrands'] ?? 0;
$inactiveBrands  = $data['inactiveBrands'] ?? 0;

$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63','idefix'=>'#1e40af'];
$mpBgs    = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff4ed','n11'=>'#faf5ff','amazon'=>'#fff8e1','pazarama'=>'#ecfeff','pttavm'=>'#fefce8','ciceksepeti'=>'#fdf2f8','idefix'=>'#eff6ff'];
?>
<style>
/* ── Tile Grid ── */
.mp-grid{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.mp-grid>.mp-tile{flex:1 1 0;min-width:110px}
.mp-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;border:1.5px solid var(--border);background:var(--bg-card);transition:all .18s ease;min-height:80px;overflow:hidden}
.mp-tile:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.mp-tile:active{transform:translateY(0);box-shadow:0 0 0 1px var(--border)}
.mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.8;transition:opacity .15s}
.mp-tile:hover .mp-tile-logo{opacity:1}
.mp-tile-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:var(--mp-color,var(--primary));padding:0 6px;line-height:1}
.mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.mp-tile-count.no-api{background:#fee2e2;color:#dc2626;font-size:9px;font-weight:600;padding:0 8px}
.mp-tile-inactive{opacity:.3;pointer-events:none;filter:grayscale(.7)}
.mp-tile-inactive .mp-tile-soon{position:absolute;bottom:4px;right:4px;font-size:8px;background:#ef4444;color:#fff;padding:1px 5px;border-radius:4px;font-weight:700}

/* === Üst Filtre Paneli (pazaryeri tab'leri + araç çubuğu — tek beyaz bütün) === */
.o-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);margin-bottom:20px;overflow:hidden}
.o-panel .o-toolbar{margin:0;padding:12px 14px}
.mp-seg{display:flex;align-items:center;gap:3px;padding:9px 10px;overflow-x:auto;border-bottom:1px solid var(--border)}
.mp-seg-tab{flex:none;display:inline-flex;align-items:center;gap:8px;height:46px;padding:0 15px;border:0;border-radius:10px;background:transparent;font-family:inherit;font-size:13px;font-weight:600;color:var(--text-muted);cursor:pointer;white-space:nowrap;transition:all .15s}
.mp-seg-tab:hover:not(.is-active):not(.is-disabled){color:var(--text-primary);background:var(--bg-input)}
.mp-seg-tab img{height:26px;width:auto;max-width:120px;object-fit:contain;display:block}
.mp-seg-ico{width:18px;height:18px;flex-shrink:0}
.mp-seg-count{display:inline-grid;place-items:center;min-width:20px;height:18px;padding:0 6px;border-radius:6px;background:rgba(15,23,42,.06);color:var(--text-muted);font-size:11px;font-weight:700;font-variant-numeric:tabular-nums}
.mp-seg-tab.is-active{background:var(--primary-bg);color:var(--primary)}
.mp-seg-tab.is-active .mp-seg-count{background:var(--primary);color:#fff}
.mp-seg-tab.is-disabled{color:var(--text-muted);cursor:not-allowed;opacity:.5}
.mp-seg-tab.is-disabled img{filter:grayscale(1);opacity:.5}
.mp-seg-lock{width:13px;height:13px;opacity:.6}

/* ── Overlay ── */
.mp-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;display:none;flex-direction:column;background:rgba(0,0,0,.45);animation:mpOverlayIn .2s ease}
.mp-overlay.active{display:flex}
@keyframes mpOverlayIn{from{opacity:0}to{opacity:1}}
.mp-window{position:absolute;top:20px;left:20px;right:20px;bottom:20px;background:var(--bg-page,#f8fafc);border-radius:12px;box-shadow:0 0 0 1px var(--border);display:flex;flex-direction:column;overflow:hidden;animation:mpWindowIn .25s ease}
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
.mp-win-body{flex:1;overflow-y:auto;padding:16px 24px}

/* ── Shared Controls ── */
.o-search{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input)}
.o-search input{border:none;background:none;outline:none;font:500 13px inherit;color:var(--text-primary);min-width:0;flex:1}
.o-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);font:600 13px inherit;cursor:pointer;transition:all .15s;white-space:nowrap}
.o-btn:hover{border-color:var(--primary);color:var(--primary)}
.o-btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.o-btn-primary:hover{opacity:.88;color:#fff}
.o-btn-outline{background:transparent}.o-btn-outline:hover{background:var(--bg-input)}
.o-btn-sm{padding:6px 10px;font-size:12px}
.o-btn-success{background:#10b981;border-color:#10b981;color:#fff}.o-btn-success:hover{background:#059669;border-color:#059669;color:#fff}
.o-btn-danger{background:#ef4444;border-color:#ef4444;color:#fff}.o-btn-danger:hover{background:#dc2626;border-color:#dc2626;color:#fff}

/* ── Header ── */
.br-h{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.br-h h1{margin:0;font-size:24px;font-weight:700}
.br-h p{margin:6px 0 0;color:var(--text-muted);font-size:13px}

/* ── System Card ── */
.sys-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);overflow:hidden}
.sys-card-h{display:flex;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input);flex-wrap:wrap;align-items:center}
.sys-card-h h3{margin:0;font-size:15px;font-weight:700}

/* ── System Table ── */
.sys-table{width:100%;border-collapse:collapse}
.sys-table th{padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.sys-table td{padding:10px 12px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
.sys-table tr:last-child td{border-bottom:none}
.sys-table tr:hover{background:rgb(var(--brand) / .03)}
.badge-mapped{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;background:#f0fdf4;color:#10b981;border:1px solid #bbf7d0}
.badge-unmapped{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;background:#fef3c7;color:#d97706;border:1px solid #fde68a}
.mp-cell-name{font-size:12px;color:var(--text-primary);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mp-cell-btn{padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:var(--bg-card);color:var(--text-muted);font-family:inherit;transition:all .12s}
.mp-cell-btn:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .05)}
.sys-table th.mp-col,.sys-table td.mp-col{text-align:center}
.row-actions{display:flex;gap:4px}
.row-actions button{width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--bg-card);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .12s}
.row-actions button:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .05)}

/* ── MP Table (overlay) ── */
.mp-table{width:100%;border-collapse:collapse;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.mp-table th{padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);text-align:left}
.mp-table td{padding:8px 12px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
.mp-table tr:hover{background:rgb(var(--brand) / .03)}

/* ── Sync Box ── */
.sync-box{display:none;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:var(--shadow-card)}
.sync-box.show{display:block}
.sync-bar{height:6px;background:var(--bg-input);border-radius:3px;margin:12px 0;overflow:hidden}
.sync-bar-fill{height:100%;border-radius:3px;transition:width .4s;width:0}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex}
.modal-box{background:var(--bg-card);border-radius:12px;width:95%;max-width:540px;max-height:85vh;overflow-y:auto;box-shadow:0 0 0 1px var(--border);padding:24px}
.modal-box h3{margin:0 0 16px;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;font-family:inherit;box-sizing:border-box;background:var(--bg-input);color:var(--text-primary)}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--primary)}
.form-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}

/* ── Map Modal ── */
.map-modal-box{width:95%;max-width:600px}
.map-results{max-height:250px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;margin-top:8px}
.map-result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px;transition:background .1s;display:flex;justify-content:space-between;align-items:center}
.map-result-item:hover{background:rgb(var(--brand) / .05)}
.map-result-item.selected{background:#eff6ff;border-left:3px solid var(--primary)}

/* ── Confirm Modal ── */
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;align-items:center;justify-content:center;animation:fadeIn .15s}
.confirm-overlay.show{display:flex}
.confirm-box{background:var(--bg-card);border-radius:12px;width:95%;max-width:400px;padding:28px;box-shadow:0 0 0 1px var(--border);text-align:center;animation:fadeInDown .2s ease}
.confirm-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px}
.confirm-icon.warn{background:#fef3c7;color:#f59e0b}
.confirm-icon.danger{background:#fee2e2;color:#ef4444}
.confirm-icon.info{background:#eff6ff;color:var(--primary)}
.confirm-title{font-size:16px;font-weight:700;margin:0 0 8px;color:var(--text-primary)}
.confirm-msg{font-size:13px;color:var(--text-muted);margin:0 0 24px;line-height:1.5}
.confirm-btns{display:flex;gap:10px;justify-content:center}
.confirm-btns button{padding:10px 24px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .12s}
.confirm-btn-cancel{background:var(--bg-input);color:var(--text-secondary)}
.confirm-btn-cancel:hover{background:var(--border)}
.confirm-btn-ok{background:#ef4444;color:#fff}
.confirm-btn-ok:hover{background:#dc2626}
.confirm-btn-ok.primary{background:var(--primary)}
.confirm-btn-ok.primary:hover{background:#0F52D4}
.confirm-btn-ok.success{background:#10b981}
.confirm-btn-ok.success:hover{background:#059669}

/* ── Pagination ── */
.mp-pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 0}
.mp-pagination button{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;color:var(--text-secondary);background:var(--bg-card);border:1px solid var(--border);cursor:pointer;padding:0 8px;transition:all .12s}
.mp-pagination button:hover{border-color:var(--primary);color:var(--primary)}
.mp-pagination button.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.mp-pagination button:disabled{opacity:.3;pointer-events:none}

/* ── Responsive ── */
@media(max-width:1200px){.mp-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){.mp-grid{grid-template-columns:repeat(2,1fr);gap:8px}.mp-tile{padding:12px 6px 10px;min-height:60px}.mp-tile-logo{height:20px;max-width:70px}.mp-window{top:10px;left:10px;right:10px;bottom:10px}.mp-win-header{padding:12px 16px;gap:10px}.mp-win-toolbar{padding:10px 16px}.mp-win-stats{display:none}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeInDown{from{transform:translateY(-10px);opacity:0}to{transform:none;opacity:1}}
</style>

<!-- ═══════════════════════════════════════════════════════════
     HEADER + İKİNCİL ALT-NAV (Ürünler / Kategoriler / Markalar)
     ═══════════════════════════════════════════════════════════ -->
<div class="ui-page-head">
  <div>
    <h1>Markalar</h1>
    <p>Markalarınızı yönetin ve pazaryeri markalarıyla eşleştirin.</p>
  </div>
  <button type="button" onclick="brandOpenCreateModal()" class="ui-btn ui-btn-primary"><i data-lucide="plus"></i> Marka Ekle</button>
</div>
<?php $subnav = ['active' => '/brands', 'items' => [
  ['u' => '/products', 'l' => 'Ürünler'],
  ['u' => '/categories', 'l' => 'Kategoriler'],
  ['u' => '/brands', 'l' => 'Markalar'],
  ['u' => '/feeds', 'l' => 'Feed'],
  ['u' => '/xml-profilleri', 'l' => 'XML Profilleri'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<!-- ═══════════════════════════════════════════════════════════
     ÖZET ŞERİDİ (4 kart)
     ═══════════════════════════════════════════════════════════ -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="rounded-2xl bg-surface p-4 shadow-card">
    <div class="flex items-center gap-2.5">
      <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand/10 text-brand shrink-0"><i data-lucide="tags" class="w-[18px] h-[18px]"></i></span>
      <div class="min-w-0"><div class="text-[20px] font-bold tracking-tight tnum leading-none" id="brStatTotal"><?= number_format($totalBrands) ?></div><div class="mt-1 text-[12px] text-slate-400">Toplam Marka</div></div>
    </div>
  </div>
  <div class="rounded-2xl bg-surface p-4 shadow-card">
    <div class="flex items-center gap-2.5">
      <span class="grid place-items-center w-9 h-9 rounded-xl bg-success/10 text-success shrink-0"><i data-lucide="package" class="w-[18px] h-[18px]"></i></span>
      <div class="min-w-0"><div class="text-[20px] font-bold tracking-tight tnum leading-none"><?= number_format($brandedProducts) ?></div><div class="mt-1 text-[12px] text-slate-400">Markalı Ürün</div></div>
    </div>
  </div>
  <div class="rounded-2xl bg-surface p-4 shadow-card">
    <div class="flex items-center gap-2.5">
      <span class="grid place-items-center w-9 h-9 rounded-xl bg-warning/10 text-warning shrink-0"><i data-lucide="link-2-off" class="w-[18px] h-[18px]"></i></span>
      <div class="min-w-0"><div class="text-[20px] font-bold tracking-tight tnum leading-none"><?= number_format($unmatchedBrands) ?></div><div class="mt-1 text-[12px] text-slate-400">Eşleşmemiş Marka</div></div>
    </div>
  </div>
  <div class="rounded-2xl bg-surface p-4 shadow-card">
    <div class="flex items-center gap-2.5">
      <span class="grid place-items-center w-9 h-9 rounded-xl bg-slate-500/10 text-slate-500 shrink-0"><i data-lucide="eye-off" class="w-[18px] h-[18px]"></i></span>
      <div class="min-w-0"><div class="text-[20px] font-bold tracking-tight tnum leading-none"><?= number_format($inactiveBrands) ?></div><div class="mt-1 text-[12px] text-slate-400">Pasif Marka</div></div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     TOOLBAR kartı (arama + filtreler + otomatik eşle + içe aktar + dışa aktar)
     ═══════════════════════════════════════════════════════════ -->
<section class="rounded-2xl bg-surface p-4 shadow-card mb-5">
  <div class="flex flex-wrap items-center gap-2.5">
    <div class="relative flex-1 min-w-[200px]">
      <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-[17px] h-[17px] text-slate-400"></i>
      <input type="text" id="sysSearch" placeholder="Marka adı ara…" class="t w-full h-10 pl-10 pr-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
    </div>
    <div class="relative">
      <select id="brMatchFilter" onchange="brandRenderRows()" class="t h-10 pl-3 pr-9 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] font-medium text-slate-600 dark:text-slate-300 outline-none focus:ring-2 focus:ring-brand/40 appearance-none cursor-pointer">
        <option value="">Tüm Eşleşmeler</option>
        <option value="full">Tam Eşleşti</option>
        <option value="partial">Kısmi Eşleşti</option>
        <option value="none">Eşleşmedi</option>
      </select>
      <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
    </div>
    <div class="relative">
      <select id="brStatusFilter" onchange="brandRenderRows()" class="t h-10 pl-3 pr-9 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] font-medium text-slate-600 dark:text-slate-300 outline-none focus:ring-2 focus:ring-brand/40 appearance-none cursor-pointer">
        <option value="">Tüm Durumlar</option>
        <option value="1">Aktif</option>
        <option value="0">Pasif</option>
      </select>
      <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
    </div>
    <button type="button" onclick="brandAutoMatchAll()" class="t flex items-center gap-2 h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.06] dark:hover:bg-white/[.08]"><i data-lucide="git-merge" class="w-4 h-4 text-slate-400"></i> Otomatik Eşle</button>
    <button type="button" onclick="brandBulkMapOpen()" title="Eşleşmemiş tüm markaları tek bir pazaryeri markasına bağla" class="t flex items-center gap-2 h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.06] dark:hover:bg-white/[.08]"><i data-lucide="layers" class="w-4 h-4 text-slate-400"></i> Toplu Marka Ata</button>
    <div class="relative">
      <button type="button" onclick="brandToggleMpMenu(event)" class="t flex items-center gap-2 h-10 px-3 rounded-lg border border-slate-200/80 dark:border-white/10 text-[13.5px] font-medium hover:shadow-card"><i data-lucide="download-cloud" class="w-4 h-4 text-slate-400"></i> Pazaryerinden Çek</button>
      <div id="brMpMenu" hidden class="absolute right-0 mt-1 w-60 z-20 rounded-xl bg-surface shadow-pop border border-slate-100 dark:border-white/10 p-1.5">
        <div class="eyebrow text-slate-400 px-2.5 pt-1.5 pb-1">Pazaryeri Markaları</div>
        <?php foreach ($marketplaces as $mp):
            $slug = (string) ($mp['slug'] ?? ''); $mpId = (string) ($mp['id'] ?? '');
            $nm = htmlspecialchars((string) ($mp['display_name'] ?? $slug));
            $mColor = $mpColors[$slug] ?? 'var(--primary)';
            $isLocked = !$canMarketplace((int) $mp['id']);
        ?>
        <?php if ($isLocked): ?>
        <div class="flex items-center gap-2 px-2.5 h-9 rounded-lg opacity-50 cursor-not-allowed text-[13px]"><img src="/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.jpg'" style="height:14px;filter:grayscale(1)" alt=""> <?= $nm ?> <span class="ml-auto text-[10px] text-slate-400">Pasif</span></div>
        <?php else: ?>
        <button type="button" onclick="brandCloseMpMenu();brandOpenWindow('<?= htmlspecialchars($mpId) ?>','<?= htmlspecialchars($slug) ?>','<?= htmlspecialchars((string) ($mp['display_name'] ?? $slug), ENT_QUOTES) ?>','<?= htmlspecialchars($mColor) ?>')" class="t w-full flex items-center gap-2 px-2.5 h-9 rounded-lg hover:bg-brand/[.08] text-[13px] font-medium"><img src="/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.jpg'" style="height:14px" alt=""> <?= $nm ?></button>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <button type="button" onclick="brandExportCsv()" class="t flex items-center gap-2 h-10 px-3 rounded-lg border border-slate-200/80 dark:border-white/10 text-[13.5px] font-medium hover:shadow-card"><i data-lucide="download" class="w-4 h-4 text-slate-400"></i> Dışa Aktar</button>
  </div>
</section>


<!-- ═══════════════════════════════════════════════════════════
     SYNC BOX
     ═══════════════════════════════════════════════════════════ -->
<div class="sync-box" id="syncBox">
  <div style="display:flex;align-items:center;gap:10px">
    <i data-lucide="refresh-cw" style="width:18px;height:18px;color:var(--primary);animation:spin 1s linear infinite"></i>
    <div><strong id="syncTitle">İşlem yapılıyor...</strong><div id="syncStatus" style="font-size:12px;color:var(--text-muted);margin-top:2px"></div></div>
  </div>
  <div class="sync-bar"><div class="sync-bar-fill" id="syncBarFill"></div></div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TABLO kartı — Sistem Markaları
     ═══════════════════════════════════════════════════════════ -->
<section class="rounded-2xl bg-surface shadow-card overflow-hidden">
  <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-slate-100 dark:border-white/[.06]">
    <h3 class="text-[14px] font-bold tracking-tight">Sistem Markaları</h3>
    <button type="button" onclick="brandDeleteAllSystem()" class="t inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg text-[12px] font-medium text-slate-400 hover:text-danger hover:bg-danger/10"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Tümünü Sil</button>
  </div>
  <div class="overflow-x-auto" id="sysTableWrap"></div>
  <div id="sysPagination" class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-slate-100 dark:border-white/5"></div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     OVERLAY — Per-Marketplace Brands
     ═══════════════════════════════════════════════════════════ -->
<div class="mp-overlay" id="brandOverlay">
  <div class="mp-window" id="brandWindow">
    <div class="mp-win-header" id="brandWinHeader">
      <img class="mp-win-logo" id="brandWinLogo" src="" alt="">
      <div class="mp-win-title" id="brandWinTitle">Markalar</div>
      <div class="mp-win-stats" id="brandWinStats"></div>
      <button class="mp-win-close" onclick="brandCloseWindow()" title="Kapat (ESC)">
        <i data-lucide="x" style="width:20px;height:20px"></i>
      </button>
    </div>
    <div class="mp-win-toolbar">
      <div class="o-search" style="width:260px">
        <i data-lucide="search" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="brandWinSearch" placeholder="Marka ara...">
      </div>
      <button type="button" class="o-btn o-btn-primary o-btn-sm" id="brandWinSyncBtn" onclick="brandSyncFromMp()"><i data-lucide="download" style="width:14px;height:14px"></i> Çek / Güncelle</button>
      <button type="button" class="o-btn o-btn-success o-btn-sm" id="brandWinBulkBtn" style="display:none" onclick="brandBulkAdd()"><i data-lucide="plus-circle" style="width:14px;height:14px"></i> Seçilenleri Sisteme Ekle</button>
      <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="brandClearCache()"><i data-lucide="trash" style="width:14px;height:14px"></i> Cache Temizle</button>
    </div>
    <div class="mp-win-body">
      <div id="brandWinTableWrap"></div>
      <div id="brandWinPagination" class="mp-pagination"></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     CREATE/EDIT MODAL
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="brandModal">
  <div class="modal-box">
    <h3 id="brandModalTitle"><i data-lucide="tag" style="width:18px;height:18px"></i> Yeni Marka</h3>
    <form onsubmit="brandSaveForm(event)">
      <input type="hidden" id="brandId">
      <div class="form-group"><label>Marka Adı</label><input type="text" id="brandName" required></div>
      <div class="form-group"><label>Durum</label><select id="brandActive"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
      <div class="form-actions">
        <button type="button" class="o-btn" onclick="brandCloseModal()">İptal</button>
        <button type="submit" class="o-btn o-btn-primary" id="brandSaveBtn"><i data-lucide="save" style="width:14px;height:14px"></i> Kaydet</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MAP MODAL
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="mapModal">
  <div class="modal-box map-modal-box">
    <h3 id="mapModalTitle"><i data-lucide="git-merge" style="width:18px;height:18px"></i> Manuel Eşleştir</h3>
    <input type="hidden" id="mapBrandId">
    <input type="hidden" id="mapMpId">
    <input type="hidden" id="mapMpSlug">
    <div class="form-group">
      <label>Pazaryeri Markası Ara</label>
      <input type="text" id="mapSearchInput" placeholder="Marka adı yazın...">
    </div>
    <div class="map-results" id="mapResults"></div>
    <input type="hidden" id="mapSelectedMpBrandId">
    <input type="hidden" id="mapSelectedMpBrandName">
    <div class="form-actions">
      <button type="button" class="o-btn" onclick="brandCloseMapModal()">İptal</button>
      <button type="button" class="o-btn o-btn-primary" id="mapSaveBtn" disabled onclick="brandSaveMapping()"><i data-lucide="check" style="width:14px;height:14px"></i> Eşleştir</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TOPLU MARKA ATAMA — eşleşmemiş tüm markalar tek hedefe
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="bulkMapModal">
  <div class="modal-box map-modal-box">
    <h3><i data-lucide="layers" style="width:18px;height:18px"></i> Toplu Marka Ata</h3>
    <p style="margin:-4px 0 14px;font-size:12.5px;line-height:1.55;color:var(--text-muted)">
      Seçtiğiniz pazaryerinde <strong>eşleşmemiş markaların tamamı</strong> tek bir pazaryeri
      markasına bağlanır. Zaten eşleşmiş olanlara dokunulmaz.
    </p>

    <div class="form-group">
      <label>Pazaryeri</label>
      <select id="bulkMapMp" onchange="brandBulkMapMpChanged()">
        <option value="">— Pazaryeri seçin —</option>
<?php foreach ($marketplaces as $mp):
    $mpId  = (int) ($mp['id'] ?? 0);
    $mpAd  = (string) ($mp['display_name'] ?? ($mp['slug'] ?? ''));
    $kilit = !$canMarketplace($mpId);
?>
        <option value="<?= $mpId ?>"<?= $kilit ? ' disabled' : '' ?>>
          <?= htmlspecialchars($mpAd) ?><?= $kilit ? ' (paketinizde yok)' : '' ?>
        </option>
<?php endforeach; ?>
      </select>
    </div>

    <div id="bulkMapCount" style="display:none;font-size:12.5px;padding:9px 11px;border-radius:8px;
         background:rgba(245,158,11,.10);color:#92400e;margin-bottom:12px"></div>

    <div class="form-group">
      <label>Hedef Pazaryeri Markası</label>
      <input type="text" id="bulkMapSearch" placeholder="En az 2 harf — ör. Genel, Diğer, Muhtelif" disabled>
    </div>
    <div class="map-results" id="bulkMapResults"></div>

    <input type="hidden" id="bulkMapBrandId">
    <input type="hidden" id="bulkMapBrandName">

    <div class="form-actions">
      <button type="button" class="o-btn" onclick="brandBulkMapClose()">İptal</button>
      <button type="button" class="o-btn o-btn-primary" id="bulkMapSaveBtn" disabled onclick="brandBulkMapRun()">
        <i data-lucide="check" style="width:14px;height:14px"></i> Tümünü Eşleştir
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     CONFIRM MODAL
     ═══════════════════════════════════════════════════════════ -->
<div class="confirm-overlay" id="confirmModal">
  <div class="confirm-box">
    <div class="confirm-icon warn" id="confirmIcon">⚠️</div>
    <div class="confirm-title" id="confirmTitle">Emin misiniz?</div>
    <div class="confirm-msg" id="confirmMsg"></div>
    <div class="confirm-btns">
      <button type="button" class="confirm-btn-cancel" id="confirmCancel">İptal</button>
      <button type="button" class="confirm-btn-ok" id="confirmOk">Evet, Devam Et</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════ -->
<script>
const BR_CSRF = '<?= $csrf ?>';
const BR_MARKETPLACES = <?= json_encode(array_map(fn($m)=>['id'=>$m['id'],'slug'=>$m['slug'],'name'=>$m['display_name'],'entitled'=>$canMarketplace((int)$m['id'])], $marketplaces)) ?>;
const BR_MP_COLORS = <?= json_encode($mpColors) ?>;

const brEsc = v => { const d = document.createElement('div'); d.textContent = v || ''; return d.innerHTML; };
const brToast = (m, t = 'info') => typeof Toast !== 'undefined' && typeof Toast.show === 'function' ? Toast.show(m, t) : console.log(t + ': ' + m);

let brandActiveMpId = '';
let brandActiveMpSlug = '';
let brandActiveMpName = '';
let brandMpSelectedIds = {};
let brandMpCurrentPage = 1;

/* ═══ Custom Confirm ═══ */
function brandConfirm(msg, opts = {}) {
    return new Promise(resolve => {
        const modal = document.getElementById('confirmModal');
        document.getElementById('confirmTitle').textContent = opts.title || 'Emin misiniz?';
        document.getElementById('confirmMsg').textContent = msg;
        document.getElementById('confirmIcon').textContent = opts.icon || '⚠️';
        document.getElementById('confirmIcon').className = 'confirm-icon ' + (opts.iconClass || 'warn');
        const okBtn = document.getElementById('confirmOk');
        okBtn.textContent = opts.okText || 'Evet, Devam Et';
        okBtn.className = 'confirm-btn-ok ' + (opts.okClass || '');
        document.getElementById('confirmCancel').textContent = opts.cancelText || 'İptal';
        modal.classList.add('show');
        const cleanup = r => { modal.classList.remove('show'); resolve(r); };
        okBtn.onclick = () => cleanup(true);
        document.getElementById('confirmCancel').onclick = () => cleanup(false);
        modal.onclick = e => { if (e.target === modal) cleanup(false); };
    });
}

/* ═══════════════════════════════════════
   SYSTEM BRANDS — Flat Table
   ═══════════════════════════════════════ */
let brSysCurrentPage = 1;
const BR_SYS_PAGE_SIZE = 50;
let brSysAllLoaded = [];   // son yüklenen sayfa markaları (client-side filtre için)
let brSysTotal = 0;
const BR_AVATAR_BG = ['bg-slate-900','bg-blue-600','bg-orange-500','bg-sky-600','bg-red-600','bg-indigo-600','bg-cyan-600','bg-emerald-600','bg-rose-600','bg-violet-600'];
function brAvatarBg(name) { const s = String(name || '?'); let h = 0; for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % BR_AVATAR_BG.length; return BR_AVATAR_BG[h]; }

function brandLoadSystemTable(page) {
    const wrap = document.getElementById('sysTableWrap');
    const search = (document.getElementById('sysSearch')?.value || '').trim();
    if (page) brSysCurrentPage = page;
    wrap.innerHTML = '<div class="px-6 py-16 text-center text-slate-400"><i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    const start = (brSysCurrentPage - 1) * BR_SYS_PAGE_SIZE;
    let url = '/brands/ajax?length=' + BR_SYS_PAGE_SIZE + '&start=' + start + '&draw=1';
    if (search.length >= 2) url += '&search[value]=' + encodeURIComponent(search);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            brSysAllLoaded = data.data || [];
            brSysTotal = data.recordsFiltered || 0;
            const totalEl = document.getElementById('brStatTotal');
            if (totalEl && search === '') totalEl.textContent = (data.recordsTotal || 0).toLocaleString('tr-TR');
            brandRenderRows();
            brandRenderSysPagination(brSysTotal);
        });
}

/* Eşleşme + durum filtrelerini uygula → mockup tablosunu çiz */
function brandRenderRows() {
    const wrap = document.getElementById('sysTableWrap');
    const matchF = document.getElementById('brMatchFilter')?.value || '';
    const statusF = document.getElementById('brStatusFilter')?.value || '';
    const entitled = BR_MARKETPLACES.filter(m => m.entitled);
    const rows = (brSysAllLoaded || []).filter(brand => {
        const mc = (brand.mappings || []).length;
        if (statusF !== '' && String(brand.is_active ?? 1) !== statusF) return false;
        if (matchF === 'full' && !(entitled.length > 0 && mc >= entitled.length)) return false;
        if (matchF === 'partial' && !(mc > 0 && mc < entitled.length)) return false;
        if (matchF === 'none' && mc !== 0) return false;
        return true;
    });
    brandRenderSystemTable(rows, wrap);
}

function brandRenderSysPagination(total) {
    const wrap = document.getElementById('sysPagination');
    const totalPages = Math.ceil(total / BR_SYS_PAGE_SIZE);
    const from = total === 0 ? 0 : (brSysCurrentPage - 1) * BR_SYS_PAGE_SIZE + 1;
    const to = Math.min(total, brSysCurrentPage * BR_SYS_PAGE_SIZE);
    let pages = '';
    if (totalPages > 1) {
        const btn = (label, page, o = {}) => '<button ' + (o.disabled ? 'disabled ' : '') + 'class="t grid place-items-center w-9 h-9 rounded-lg text-[13px] font-medium tnum ' + (o.active ? 'bg-brand text-white' : (o.disabled ? 'text-slate-300 dark:text-slate-600 cursor-not-allowed' : 'hover:bg-slate-900/[.05] dark:hover:bg-white/[.06] text-slate-500')) + '" ' + (o.disabled ? '' : 'onclick="brandLoadSystemTable(' + page + ')"') + '>' + label + '</button>';
        pages += btn('‹', brSysCurrentPage - 1, { disabled: brSysCurrentPage <= 1 });
        for (let i = 1; i <= totalPages; i++) {
            if (totalPages > 9 && i > 2 && i < totalPages - 1 && Math.abs(i - brSysCurrentPage) > 1) {
                if (i === 3 || i === totalPages - 2) pages += '<span class="px-1 text-slate-400">…</span>';
                continue;
            }
            pages += btn(i, i, { active: i === brSysCurrentPage });
        }
        pages += btn('›', brSysCurrentPage + 1, { disabled: brSysCurrentPage >= totalPages });
    }
    wrap.innerHTML = '<span class="text-[12.5px] text-slate-400 tnum"><span class="font-semibold text-slate-600 dark:text-slate-300">' + from + '-' + to + '</span> / ' + total + ' marka</span><div class="flex items-center gap-1">' + pages + '</div>';
}

function brandRenderSystemTable(brands, container) {
    const entitled = BR_MARKETPLACES.filter(m => m.entitled);
    let body = '';
    if (!brands || brands.length === 0) {
        body = '<tr><td colspan="5" class="px-6 py-16 text-center text-slate-400"><i data-lucide="tag" class="w-7 h-7 mx-auto mb-2 opacity-40"></i>Marka bulunamadı</td></tr>';
    } else {
        brands.forEach(brand => {
            const mappings = brand.mappings || [];
            const mappedSlugs = new Set(mappings.map(m => m.marketplace_slug));
            const mc = mappings.length;
            const productCount = parseInt(brand.product_count) || 0;
            const isActive = String(brand.is_active ?? 1) !== '0';
            const safeName = brEsc(brand.name).replace(/'/g, "\\'");
            const initial = brEsc((brand.name || '?').trim().charAt(0).toUpperCase());

            let mapCell;
            if (mc === 0) {
                const firstUnmapped = entitled.find(m => !mappedSlugs.has(m.slug));
                const eslBtn = firstUnmapped
                    ? '<button class="t inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold text-brand hover:bg-brand/10" onclick="brandOpenMapModalFor(' + brand.id + ',\'' + safeName + '\',' + firstUnmapped.id + ',\'' + firstUnmapped.slug + '\')"><i data-lucide="link" class="w-3.5 h-3.5"></i> Eşle</button>'
                    : '';
                mapCell = '<span class="inline-flex items-center gap-1.5"><span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-medium text-slate-400 bg-slate-900/[.03] dark:bg-white/[.05]"><i data-lucide="link-2-off" class="w-3.5 h-3.5"></i> Eşlenmedi</span>' + eslBtn + '</span>';
            } else {
                let chips = '', shown = 0;
                entitled.forEach(m => { if (shown < 5 && mappedSlugs.has(m.slug)) { chips += '<span class="logo-chip" style="padding:4px 7px;border-radius:8px" title="' + brEsc(m.name) + ' · eşleşti"><img class="mp-logo" style="height:13px" src="/public/assets/img/marketplaces/' + m.slug + '.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + m.slug + '.jpg\'" alt="' + brEsc(m.name) + '"></span>'; shown++; } });
                entitled.forEach(m => { if (shown < 5 && !mappedSlugs.has(m.slug)) { chips += '<span class="logo-chip" style="padding:4px 7px;border-radius:8px;opacity:.45" title="' + brEsc(m.name) + ' · eşleşmedi"><img class="mp-logo" style="height:13px;filter:grayscale(1)" src="/public/assets/img/marketplaces/' + m.slug + '.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + m.slug + '.jpg\'" alt="' + brEsc(m.name) + '"></span>'; shown++; } });
                const full = entitled.length > 0 && mc >= entitled.length;
                const pill = full
                    ? '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10.5px] font-semibold bg-success/10 text-success"><i data-lucide="check" class="w-3 h-3"></i> ' + mc + '/' + entitled.length + '</span>'
                    : '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10.5px] font-semibold bg-warning/10 text-warning"><i data-lucide="alert-circle" class="w-3 h-3"></i> ' + mc + '/' + entitled.length + '</span>';
                mapCell = '<span class="inline-flex items-center gap-1.5 flex-wrap">' + chips + pill + '</span>';
            }

            const statusPill = isActive
                ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-success/10 text-success"><span class="w-1.5 h-1.5 rounded-full bg-success"></span>Aktif</span>'
                : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-500/10 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>Pasif</span>';

            body += '<tr class="t hover:bg-slate-900/[.02] dark:hover:bg-white/[.03]">'
                + '<td class="px-6 py-3.5"><span class="inline-flex items-center gap-2.5"><span class="grid place-items-center w-8 h-8 rounded-lg ' + brAvatarBg(brand.name) + ' text-white text-[12px] font-bold shrink-0">' + initial + '</span><span class="font-semibold text-[13.5px]' + (isActive ? '' : ' text-slate-500') + '">' + brEsc(brand.name) + '</span></span></td>'
                + '<td class="px-3 py-3.5 text-right tnum font-semibold' + (isActive ? '' : ' text-slate-500') + '">' + productCount + '</td>'
                + '<td class="px-3 py-3.5">' + mapCell + '</td>'
                + '<td class="px-3 py-3.5">' + statusPill + '</td>'
                + '<td class="px-6 py-3.5 text-right"><button type="button" onclick="brandRowMenu(event,' + brand.id + ')" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.06] dark:hover:bg-white/[.08] text-slate-400 ml-auto"><i data-lucide="more-horizontal" class="w-4 h-4"></i></button></td>'
                + '</tr>';
        });
    }
    container.innerHTML = '<table class="w-full text-[13px] min-w-[920px]"><thead><tr class="text-left text-slate-400 bg-slate-900/[.02] dark:bg-white/[.03]"><th class="eyebrow font-medium px-6 py-2.5">Marka</th><th class="eyebrow font-medium px-3 py-2.5 text-right">Ürün</th><th class="eyebrow font-medium px-3 py-2.5">Pazaryeri Eşlemesi</th><th class="eyebrow font-medium px-3 py-2.5">Durum</th><th class="eyebrow font-medium px-6 py-2.5 text-right"></th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-white/5">' + body + '</tbody></table>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

/* System search */
let brSysSearchTimer;
document.getElementById('sysSearch').addEventListener('input', function () {
    clearTimeout(brSysSearchTimer);
    brSysCurrentPage = 1;
    brSysSearchTimer = setTimeout(() => brandLoadSystemTable(), 400);
});

/* ═══════════════════════════════════════
   CRUD
   ═══════════════════════════════════════ */
function brandOpenCreateModal() {
    document.getElementById('brandModalTitle').innerHTML = '<i data-lucide="tag" style="width:18px;height:18px"></i> Yeni Marka';
    document.getElementById('brandId').value = '';
    document.getElementById('brandName').value = '';
    document.getElementById('brandActive').value = '1';
    document.getElementById('brandModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
    setTimeout(() => document.getElementById('brandName').focus(), 200);
}

function brandOpenEditModal(id, name, isActive) {
    document.getElementById('brandModalTitle').innerHTML = '<i data-lucide="pencil" style="width:18px;height:18px"></i> Marka Düzenle';
    document.getElementById('brandId').value = id;
    document.getElementById('brandName').value = name;
    document.getElementById('brandActive').value = isActive ? '1' : '0';
    document.getElementById('brandModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function brandCloseModal() { document.getElementById('brandModal').classList.remove('show'); }
document.getElementById('brandModal').addEventListener('click', e => { if (e.target.id === 'brandModal') brandCloseModal(); });

async function brandSaveForm(e) {
    e.preventDefault();
    const id = document.getElementById('brandId').value;
    const name = document.getElementById('brandName').value.trim();
    if (!name) { brToast('Marka adı zorunludur', 'error'); return; }
    const btn = document.getElementById('brandSaveBtn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('name', name);
    fd.append('is_active', document.getElementById('brandActive').value);
    fd.append('_csrf_token', BR_CSRF);
    try {
        const res = await fetch(id ? '/brands/' + id : '/brands', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { brToast(data.data?.message || data.message, 'success'); brandCloseModal(); brandLoadSystemTable(); }
        else { brToast(data.message, 'error'); }
    } catch (e) { brToast('Hata', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i data-lucide="save" style="width:14px;height:14px"></i> Kaydet'; if (typeof lucide !== 'undefined') lucide.createIcons(); }
}

async function brandDeleteItem(id, name) {
    const ok = await brandConfirm('"' + name + '" markası silinecek. Emin misiniz?', { title: 'Marka Sil', icon: '⚠️', iconClass: 'danger', okText: 'Sil' });
    if (!ok) return;
    const fd = new FormData();
    fd.append('_csrf_token', BR_CSRF);
    try {
        const res = await fetch('/brands/' + id + '/delete', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { brToast(data.data?.message || data.message, 'success'); brandLoadSystemTable(); }
        else { brToast(data.message, 'error'); }
    } catch (e) { brToast('Hata', 'error'); }
}

async function brandDeleteAllSystem() {
    const ok = await brandConfirm('TÜM sistem markaları silinecek. Bu işlem geri alınamaz!', { title: 'Dikkat!', icon: '⚠️', iconClass: 'danger', okText: 'Evet, Hepsini Sil' });
    if (!ok) return;
    const fd = new FormData();
    fd.append('_csrf_token', BR_CSRF);
    try {
        const res = await fetch('/brands/delete-all', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { brToast(data.data?.message || data.message, 'success'); setTimeout(() => location.reload(), 1000); }
        else { brToast(data.message, 'error'); }
    } catch (e) { brToast('Hata', 'error'); }
}

async function brandAutoMatchAll() {
    const ok = await brandConfirm('Tüm pazaryerlerinde otomatik eşleştirme yapılacak. Devam etmek istiyor musunuz?', { title: 'Otomatik Eşleştirme', icon: '⚡', iconClass: 'info', okText: 'Eşleştir', okClass: 'success' });
    if (!ok) return;
    const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
    box.classList.add('show'); bar.style.width = '20%'; bar.style.background = '#10b981';
    title.textContent = 'Otomatik eşleştirme yapılıyor...'; status.textContent = 'Lütfen bekleyin...';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace', '');
    fd.append('_csrf_token', BR_CSRF);
    try {
        bar.style.width = '50%';
        const res = await fetch('/brands/auto-match', { method: 'POST', body: fd });
        const data = await res.json(); bar.style.width = '100%';
        const rd = data.data || {};
        if (data.success) {
            title.textContent = '✅ Eşleştirme Tamamlandı!';
            let html = '';
            if (rd.mp_results?.length > 0) {
                rd.mp_results.forEach(r => {
                    const ok2 = r.matched > 0 ? '<span style="color:#10b981;font-weight:700">' + r.matched + ' eşleşti</span>' : '';
                    const nf = r.not_found > 0 ? '<span style="color:#f59e0b;font-weight:700">' + r.not_found + ' bulunamadı</span>' : '';
                    const al = r.already > 0 ? '<span style="color:#6b7280">' + r.already + ' zaten</span>' : '';
                    html += '<div style="margin-bottom:4px"><strong>' + brEsc(r.name) + ':</strong> ' + [ok2, nf, al].filter(Boolean).join(', ') + '</div>';
                });
            }
            status.innerHTML = html || rd.message || data.message;
            brToast(rd.message || data.message, 'success');
            setTimeout(() => { box.classList.remove('show'); brandLoadSystemTable(); }, 3000);
        } else { title.textContent = '❌ Hata'; status.textContent = data.message; bar.style.background = '#ef4444'; }
    } catch (e) { title.textContent = '❌ Bağlantı Hatası'; status.textContent = e.message; bar.style.background = '#ef4444'; }
}

/* ═══════════════════════════════════════
   MANUAL MAP MODAL
   ═══════════════════════════════════════ */
function brandOpenMapModalFor(brandId, brandName, mpId, mpSlug) {
    document.getElementById('mapBrandId').value = brandId;
    document.getElementById('mapMpId').value = mpId;
    document.getElementById('mapMpSlug').value = mpSlug;
    document.getElementById('mapModalTitle').innerHTML = '<i data-lucide="git-merge" style="width:18px;height:18px"></i> "' + brEsc(brandName) + '" ↔ ' + mpSlug.charAt(0).toUpperCase() + mpSlug.slice(1) + ' Eşleştir';
    document.getElementById('mapSearchInput').value = brandName;
    document.getElementById('mapResults').innerHTML = '';
    document.getElementById('mapSelectedMpBrandId').value = '';
    document.getElementById('mapSelectedMpBrandName').value = '';
    document.getElementById('mapSaveBtn').disabled = true;
    document.getElementById('mapModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
    brandSearchMapCache(brandName, mpId);
}
function brandCloseMapModal() { document.getElementById('mapModal').classList.remove('show'); }
document.getElementById('mapModal').addEventListener('click', e => { if (e.target.id === 'mapModal') brandCloseMapModal(); });

let brMapSearchTimer;
document.getElementById('mapSearchInput').addEventListener('input', function () {
    clearTimeout(brMapSearchTimer);
    brMapSearchTimer = setTimeout(() => {
        const q = this.value;
        const mpId = document.getElementById('mapMpId').value;
        if (q.length >= 2) brandSearchMapCache(q, mpId);
    }, 300);
});

async function brandSearchMapCache(q, mpId) {
    const res = await fetch('/brands/search-cache?q=' + encodeURIComponent(q) + '&marketplace_id=' + mpId);
    const data = await res.json();
    const items = data.data || [];
    let html = '';
    if (items.length === 0) { html = '<div style="padding:20px;text-align:center;color:var(--text-muted)">Sonuç bulunamadı</div>'; }
    items.forEach(item => {
        html += '<div class="map-result-item" onclick="brandSelectMapItem(\'' + (item.mp_brand_id || item.id) + '\',\'' + brEsc(item.name).replace(/'/g, "\\'") + '\',this)">'
            + '<div><strong>' + brEsc(item.name) + '</strong></div></div>';
    });
    document.getElementById('mapResults').innerHTML = html;
}

function brandSelectMapItem(id, name, el) {
    document.querySelectorAll('.map-result-item').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('mapSelectedMpBrandId').value = id;
    document.getElementById('mapSelectedMpBrandName').value = name;
    document.getElementById('mapSaveBtn').disabled = false;
}

/* ═══════════════════════════════════════
   TOPLU MARKA ATAMA
   "Elle genel bir marka yazayım, hepsine işlesin" ihtiyacı için:
   eşleşmemiş TÜM markaları tek bir pazaryeri markasına bağlar.
   ═══════════════════════════════════════ */
let brBulkTimer = null;
let brBulkUnmapped = 0;

function brandBulkMapOpen() {
    document.getElementById('bulkMapMp').value = '';
    document.getElementById('bulkMapSearch').value = '';
    document.getElementById('bulkMapSearch').disabled = true;
    document.getElementById('bulkMapResults').innerHTML = '';
    document.getElementById('bulkMapBrandId').value = '';
    document.getElementById('bulkMapBrandName').value = '';
    document.getElementById('bulkMapCount').style.display = 'none';
    document.getElementById('bulkMapSaveBtn').disabled = true;
    brBulkUnmapped = 0;
    document.getElementById('bulkMapModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function brandBulkMapClose() {
    document.getElementById('bulkMapModal').classList.remove('show');
}

async function brandBulkMapMpChanged() {
    const mpId = document.getElementById('bulkMapMp').value;
    const arama = document.getElementById('bulkMapSearch');
    const kutu = document.getElementById('bulkMapCount');

    document.getElementById('bulkMapResults').innerHTML = '';
    document.getElementById('bulkMapBrandId').value = '';
    document.getElementById('bulkMapSaveBtn').disabled = true;
    arama.value = '';
    arama.disabled = !mpId;

    if (!mpId) { kutu.style.display = 'none'; return; }

    try {
        const res = await fetch('/brands/mapping/unmapped-count?marketplace_id=' + encodeURIComponent(mpId));
        const data = await res.json();
        brBulkUnmapped = (data.data && data.data.count) || 0;
        kutu.style.display = 'block';
        kutu.textContent = brBulkUnmapped > 0
            ? brBulkUnmapped + ' marka bu pazaryerinde eşleşmemiş — hepsi seçeceğiniz markaya bağlanacak.'
            : 'Bu pazaryerinde eşleşmemiş marka yok.';
    } catch (e) {
        kutu.style.display = 'none';
    }
}

document.getElementById('bulkMapSearch').addEventListener('input', function () {
    clearTimeout(brBulkTimer);
    const q = this.value.trim();
    const mpId = document.getElementById('bulkMapMp').value;
    if (q.length < 2 || !mpId) { document.getElementById('bulkMapResults').innerHTML = ''; return; }
    brBulkTimer = setTimeout(() => brandBulkMapSearch(q, mpId), 350);
});

async function brandBulkMapSearch(q, mpId) {
    const kutu = document.getElementById('bulkMapResults');
    try {
        const res = await fetch('/brands/search-cache?q=' + encodeURIComponent(q) + '&marketplace_id=' + mpId);
        const data = await res.json();
        const items = data.data || [];

        if (items.length === 0) {
            kutu.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted)">Sonuç yok. Katalog boşsa önce Pazaryerinden Çek adımını çalıştırın.</div>';
            return;
        }

        kutu.innerHTML = '';
        items.forEach((item) => {
            const satir = document.createElement('div');
            satir.className = 'map-result-item';
            satir.dataset.mpBrandId = item.mp_brand_id || item.id || '';
            satir.dataset.mpBrandName = item.name || '';
            satir.innerHTML = '<div><strong></strong></div>';
            satir.querySelector('strong').textContent = item.name || '';
            satir.onclick = function () { brandBulkMapSelect(this); };
            kutu.appendChild(satir);
        });
    } catch (e) {
        brToast('Marka listesi alınamadı', 'error');
    }
}

function brandBulkMapSelect(el) {
    document.querySelectorAll('#bulkMapResults .map-result-item').forEach((e) => e.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('bulkMapBrandId').value = el.dataset.mpBrandId;
    document.getElementById('bulkMapBrandName').value = el.dataset.mpBrandName;
    document.getElementById('bulkMapSaveBtn').disabled = false;
}

async function brandBulkMapRun() {
    const mpId = document.getElementById('bulkMapMp').value;
    const mpBrandId = document.getElementById('bulkMapBrandId').value;
    const mpBrandName = document.getElementById('bulkMapBrandName').value;

    if (!mpId || !mpBrandId) { brToast('Pazaryeri ve hedef marka seçin', 'error'); return; }

    const onay = await brandConfirm(
        brBulkUnmapped + ' eşleşmemiş markanın tamamı "' + mpBrandName + '" markasına bağlanacak. Devam edilsin mi?',
        { title: 'Toplu Marka Ata', icon: '🔗', iconClass: 'info', okText: 'Eşleştir', okClass: 'success' }
    );
    if (!onay) return;

    const btn = document.getElementById('bulkMapSaveBtn');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('_csrf_token', BR_CSRF);
    fd.append('marketplace_id', mpId);
    fd.append('marketplace_brand_id', mpBrandId);
    fd.append('marketplace_brand_name', mpBrandName);
    fd.append('tumu', '1');

    try {
        const res = await fetch('/brands/mapping/bulk', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            brToast(data.message || 'Eşleştirildi', 'success');
            brandBulkMapClose();
            brandLoadSystemTable();
        } else {
            brToast(data.message || 'İşlem başarısız', 'error');
            btn.disabled = false;
        }
    } catch (e) {
        brToast('Bağlantı hatası', 'error');
        btn.disabled = false;
    }
}

async function brandSaveMapping() {
    const brandId = document.getElementById('mapBrandId').value;
    const mpId = document.getElementById('mapMpId').value;
    const mpBrandId = document.getElementById('mapSelectedMpBrandId').value;
    const mpBrandName = document.getElementById('mapSelectedMpBrandName').value;
    if (!mpBrandId) { brToast('Bir marka seçin', 'error'); return; }
    const fd = new FormData();
    fd.append('brand_id', brandId); fd.append('marketplace_id', mpId);
    fd.append('mp_brand_id', mpBrandId); fd.append('mp_brand_name', mpBrandName); fd.append('_csrf_token', BR_CSRF);
    try {
        const res = await fetch('/brands/manual-map', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { brToast(data.data?.message || data.message, 'success'); brandCloseMapModal(); brandLoadSystemTable(); }
        else { brToast(data.message, 'error'); }
    } catch (e) { brToast('Hata', 'error'); }
}

/* ═══════════════════════════════════════
   OVERLAY — Marketplace Brands
   ═══════════════════════════════════════ */
function brandOpenWindow(mpId, slug, displayName, color) {
    brandActiveMpId = mpId;
    brandActiveMpSlug = slug;
    brandActiveMpName = displayName;
    brandMpSelectedIds[slug] = brandMpSelectedIds[slug] || new Set();
    brandMpCurrentPage = 1;

    document.getElementById('brandWinHeader').style.borderBottomColor = color;
    document.getElementById('brandWinLogo').src = '/public/assets/img/marketplaces/' + slug + '.png';
    document.getElementById('brandWinLogo').onerror = function () { this.src = '/public/assets/img/marketplaces/' + slug + '.jpg'; };
    document.getElementById('brandWinTitle').textContent = 'Markalar';
    document.getElementById('brandWinSearch').value = '';
    document.getElementById('brandWinStats').innerHTML = '';
    document.getElementById('brandWinBulkBtn').style.display = 'none';

    document.getElementById('brandOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    brandLoadMpList();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function brandCloseWindow() {
    document.getElementById('brandOverlay').classList.remove('active');
    document.body.style.overflow = '';
    brandActiveMpSlug = '';
    brandActiveMpId = '';
    brandActiveMpName = '';
    brandLoadSystemTable();
    brandUpdateTileCounts();
}

function brandUpdateTileCounts() {
    if (!document.getElementById('mpSeg')) return;  // yeni tasarımda pazaryeri pill'leri yok → atla
    BR_MARKETPLACES.forEach(mp => {
        if (mp.slug === 'pttavm') return;
        fetch('/brands/mp-list?marketplace=' + mp.slug + '&page=1')
            .then(r => r.json())
            .then(data => {
                const total = data.pagination?.total || 0;
                const el = document.querySelector('[data-mp-cnt="' + mp.id + '"]');
                if (el) el.textContent = total;
                brandRefreshAllSegCount();
            }).catch(() => {});
    });
}

/* "Tümü" pill rozeti = pazaryeri pill sayılarının toplamı (canlı) */
function brandRefreshAllSegCount() {
    const allEl = document.querySelector('#mpSeg [data-mp-cnt="all"]');
    if (!allEl) return;
    let sum = 0;
    document.querySelectorAll('#mpSeg [data-mp-cnt]').forEach(el => {
        if (el.dataset.mpCnt === 'all') return;
        const n = parseInt(el.textContent, 10);
        if (!isNaN(n)) sum += n;
    });
    allEl.textContent = sum;
}

function brandLoadMpList(page) {
    const slug = brandActiveMpSlug;
    const search = document.getElementById('brandWinSearch').value;
    if (page) brandMpCurrentPage = page;
    const wrap = document.getElementById('brandWinTableWrap');
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)"><i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    fetch('/brands/mp-list?marketplace=' + slug + '&page=' + brandMpCurrentPage + '&search=' + encodeURIComponent(search))
        .then(r => r.json())
        .then(data => {
            if (!data.success) { wrap.innerHTML = '<div style="padding:20px;color:#ef4444">Hata: ' + (data.message || '') + '</div>'; return; }
            const items = data.data || [];
            const pag = data.pagination || {};
            brandRenderMpTable(items, wrap);
            brandRenderPagination(pag);
            const totalItems = pag.total || 0;
            document.getElementById('brandWinStats').innerHTML =
                '<div class="mp-win-stat"><span class="mp-win-stat-val">' + totalItems + '</span><span class="mp-win-stat-lbl">Toplam</span></div>';
        })
        .catch(() => { wrap.innerHTML = '<div style="padding:20px;color:#ef4444">Bağlantı hatası</div>'; });
}

function brandRenderMpTable(items, container) {
    const slug = brandActiveMpSlug;
    let html = '<table class="mp-table"><thead><tr><th style="width:30px"><input type="checkbox" onchange="brandToggleAllMp(this)"></th><th>Marka Adı</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';

    if (!items || items.length === 0) {
        html += '<tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted)"><i data-lucide="tag" style="width:24px;height:24px;display:block;margin:0 auto 8px;opacity:.4"></i>Marka bulunamadı. Önce markaları çekin.</td></tr>';
    } else {
        const sel = brandMpSelectedIds[slug] || new Set();
        items.forEach(item => {
            const isMapped = !!item.mapping_id;
            const isChecked = sel.has(item.mp_brand_id) ? 'checked' : '';

            const statusBadge = isMapped
                ? '<span class="badge-mapped"><i data-lucide="check-circle" style="width:12px;height:12px"></i> ' + brEsc(item.system_brand_name || 'Eşleşmiş') + '</span>'
                : '<span class="badge-unmapped"><i data-lucide="alert-circle" style="width:12px;height:12px"></i> Eşleşmemiş</span>';

            const actionBtn = isMapped
                ? '<span style="font-size:11px;color:var(--text-muted)">Zaten eşleşik</span>'
                : '<button class="o-btn o-btn-sm" style="padding:4px 10px;font-size:11px" onclick="brandAddToSystem(\'' + item.mp_brand_id + '\',\'' + brEsc(item.name).replace(/'/g, "\\'") + '\',this)"><i data-lucide="plus" style="width:12px;height:12px"></i> Sisteme Ekle</button>';

            html += '<tr>'
                + '<td><input type="checkbox" class="mp-check" value="' + item.mp_brand_id + '" ' + isChecked + ' ' + (isMapped ? 'disabled' : '') + ' onchange="brandToggleMpCheck(this)"></td>'
                + '<td><i data-lucide="tag" style="width:14px;height:14px;color:#94a3b8"></i> <strong>' + brEsc(item.name) + '</strong></td>'
                + '<td>' + statusBadge + '</td>'
                + '<td>' + actionBtn + '</td>'
                + '</tr>';
        });
    }
    html += '</tbody></table>';
    container.innerHTML = html;
    brandUpdateBulkBtn();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function brandRenderPagination(pag) {
    const wrap = document.getElementById('brandWinPagination');
    const totalPages = pag.total_pages || 1;
    const currentPage = pag.page || 1;
    if (totalPages <= 1) { wrap.innerHTML = ''; return; }

    let html = '';
    html += '<button ' + (currentPage <= 1 ? 'disabled' : '') + ' onclick="brandLoadMpList(' + (currentPage - 1) + ')">‹</button>';
    for (let i = 1; i <= totalPages; i++) {
        if (totalPages > 10 && i > 3 && i < totalPages - 2 && Math.abs(i - currentPage) > 1) {
            if (i === 4 || i === totalPages - 3) html += '<button disabled>...</button>';
            continue;
        }
        html += '<button class="' + (i === currentPage ? 'active' : '') + '" onclick="brandLoadMpList(' + i + ')">' + i + '</button>';
    }
    html += '<button ' + (currentPage >= totalPages ? 'disabled' : '') + ' onclick="brandLoadMpList(' + (currentPage + 1) + ')">›</button>';
    wrap.innerHTML = html;
}

/* Checkbox handling */
function brandToggleMpCheck(cb) {
    const slug = brandActiveMpSlug;
    if (!brandMpSelectedIds[slug]) brandMpSelectedIds[slug] = new Set();
    if (cb.checked) brandMpSelectedIds[slug].add(cb.value);
    else brandMpSelectedIds[slug].delete(cb.value);
    brandUpdateBulkBtn();
}
function brandToggleAllMp(cb) {
    document.querySelectorAll('.mp-check:not(:disabled)').forEach(c => { c.checked = cb.checked; brandToggleMpCheck(c); });
}
function brandUpdateBulkBtn() {
    const slug = brandActiveMpSlug;
    const count = brandMpSelectedIds[slug] ? brandMpSelectedIds[slug].size : 0;
    const btn = document.getElementById('brandWinBulkBtn');
    if (btn) {
        btn.style.display = count > 0 ? '' : 'none';
        btn.innerHTML = '<i data-lucide="plus-circle" style="width:14px;height:14px"></i> ' + count + ' Seçileni Sisteme Ekle';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

/* Add to system */
async function brandAddToSystem(mpBrandId, name, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" style="width:12px;height:12px;animation:spin 1s linear infinite"></i>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace_id', brandActiveMpId);
    fd.append('mp_brand_id', mpBrandId);
    fd.append('_csrf_token', BR_CSRF);
    try {
        const res = await fetch('/brands/add-to-system', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { brToast(data.data?.message || data.message, 'success'); brandLoadMpList(); }
        else { brToast(data.message, 'error'); btn.disabled = false; btn.innerHTML = '<i data-lucide="plus" style="width:12px;height:12px"></i> Sisteme Ekle'; }
    } catch (e) { brToast('Hata', 'error'); btn.disabled = false; }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function brandBulkAdd() {
    const slug = brandActiveMpSlug;
    const ids = [...(brandMpSelectedIds[slug] || [])];
    if (ids.length === 0) return;
    const ok = await brandConfirm(ids.length + ' marka sisteme eklenecek. Devam?', { title: 'Toplu Ekleme', icon: '📦', iconClass: 'info', okText: 'Ekle', okClass: 'success' });
    if (!ok) return;
    const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
    box.classList.add('show'); bar.style.width = '30%'; bar.style.background = '#10b981';
    title.textContent = 'Toplu ekleme yapılıyor...'; status.textContent = ids.length + ' marka işleniyor...';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace_id', brandActiveMpId);
    fd.append('items', JSON.stringify(ids));
    fd.append('_csrf_token', BR_CSRF);
    try {
        bar.style.width = '60%';
        const res = await fetch('/brands/bulk-add-to-system', { method: 'POST', body: fd });
        const data = await res.json(); bar.style.width = '100%';
        const rd = data.data || {};
        if (data.success) {
            title.textContent = '✅ Toplu Ekleme Tamamlandı!';
            status.textContent = rd.message || data.message;
            brToast(rd.message || data.message, 'success');
            brandMpSelectedIds[slug] = new Set();
            setTimeout(() => { box.classList.remove('show'); brandLoadMpList(); }, 2000);
        } else { title.textContent = '❌ Hata'; status.textContent = data.message; bar.style.background = '#ef4444'; }
    } catch (e) { title.textContent = '❌ Bağlantı Hatası'; status.textContent = e.message; bar.style.background = '#ef4444'; }
}

/* Sync / Cache */
async function brandSyncFromMp() {
    const ok = await brandConfirm(brandActiveMpName + '\'dan markaları çekmek istediğinize emin misiniz?', { title: 'Markaları Çek', icon: '📥', iconClass: 'info', okText: 'Çek/Güncelle', okClass: 'primary' });
    if (!ok) return;
    const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
    box.classList.add('show'); bar.style.width = '20%'; bar.style.background = '#2563eb';
    title.textContent = brandActiveMpName + '\'dan markalar çekiliyor...'; status.textContent = 'API bağlantısı kuruluyor...';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace', brandActiveMpSlug);
    fd.append('_csrf_token', BR_CSRF);
    try {
        bar.style.width = '50%'; status.textContent = 'Markalar alınıyor...';
        const res = await fetch('/brands/sync-marketplace', { method: 'POST', body: fd });
        const data = await res.json(); bar.style.width = '100%';
        if (data.success) {
            title.textContent = '✅ Tamamlandı!';
            status.textContent = data.data?.message || data.message;
            brToast(data.data?.message || data.message, 'success');
            setTimeout(() => { box.classList.remove('show'); brandLoadMpList(); }, 1500);
        } else {
            title.textContent = '❌ Hata'; status.textContent = data.message || 'Bilinmeyen hata';
            bar.style.background = '#ef4444'; brToast(data.message || 'Hata', 'error');
        }
    } catch (e) { title.textContent = '❌ Bağlantı Hatası'; status.textContent = e.message; bar.style.background = '#ef4444'; brToast('Bağlantı hatası: ' + e.message, 'error'); }
}

async function brandClearCache() {
    const ok = await brandConfirm(brandActiveMpName + ' marka cache\'i silinecek. Devam?', { title: 'Cache Temizle', icon: '🧹', iconClass: 'warn', okText: 'Temizle', okClass: 'primary' });
    if (!ok) return;
    const fd = new FormData();
    fd.append('marketplace', brandActiveMpSlug);
    fd.append('_csrf_token', BR_CSRF);
    try {
        const res = await fetch('/brands/clear-cache', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { brToast(data.data?.message || data.message, 'success'); brandLoadMpList(); }
        else { brToast(data.message, 'error'); }
    } catch (e) { brToast('Hata', 'error'); }
}

/* Overlay search */
let brWinSearchTimer;
document.getElementById('brandWinSearch').addEventListener('input', function () {
    clearTimeout(brWinSearchTimer);
    brandMpCurrentPage = 1;
    brWinSearchTimer = setTimeout(() => brandLoadMpList(), 400);
});
document.getElementById('brandWinSearch').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(brWinSearchTimer); brandMpCurrentPage = 1; brandLoadMpList(); }
});

/* ═══ ESC + Click-outside ═══ */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        if (document.getElementById('confirmModal').classList.contains('show')) return;
        if (document.getElementById('mapModal').classList.contains('show')) { brandCloseMapModal(); return; }
        if (document.getElementById('brandModal').classList.contains('show')) { brandCloseModal(); return; }
        if (document.getElementById('brandOverlay').classList.contains('active')) { brandCloseWindow(); return; }
    }
});
document.getElementById('brandOverlay').addEventListener('click', function (e) {
    if (e.target === this) brandCloseWindow();
});

/* ═══ Toolbar "Pazaryerinden Çek" menüsü ═══ */
function brandToggleMpMenu(e) { if (e) e.stopPropagation(); const m = document.getElementById('brMpMenu'); if (m) m.hidden = !m.hidden; }
function brandCloseMpMenu() { const m = document.getElementById('brMpMenu'); if (m) m.hidden = true; }
document.addEventListener('click', function (e) {
    const m = document.getElementById('brMpMenu');
    if (m && !m.hidden && !m.contains(e.target) && !e.target.closest('[onclick*="brandToggleMpMenu"]')) m.hidden = true;
});

/* ═══ Satır "..." menüsü (gövdeye sabitlenir → tablo kaydırma kırpmaz) ═══ */
function brandRowMenu(e, id) {
    e.stopPropagation();
    const brand = (brSysAllLoaded || []).find(b => String(b.id) === String(id));
    if (!brand) return;
    const menu = document.getElementById('brRowMenu');
    const safeName = brEsc(brand.name).replace(/'/g, "\\'");
    const isActive = String(brand.is_active ?? 1) !== '0' ? 1 : 0;
    menu.innerHTML =
        '<button type="button" onclick="brandCloseRowMenu();brandOpenEditModal(' + brand.id + ',\'' + safeName + '\',' + isActive + ')" class="t w-full flex items-center gap-2 px-2.5 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-[13px] font-medium"><i data-lucide="pencil" class="w-4 h-4 text-slate-400"></i> Düzenle</button>'
        + '<button type="button" onclick="brandCloseRowMenu();brandDeleteItem(' + brand.id + ',\'' + safeName + '\')" class="t w-full flex items-center gap-2 px-2.5 h-9 rounded-lg hover:bg-danger/10 text-danger text-[13px] font-medium"><i data-lucide="trash-2" class="w-4 h-4"></i> Sil</button>';
    const r = e.currentTarget.getBoundingClientRect();
    menu.style.top = (r.bottom + 4) + 'px';
    menu.style.left = Math.max(8, r.right - 176) + 'px';
    menu.hidden = false;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function brandCloseRowMenu() { const m = document.getElementById('brRowMenu'); if (m) m.hidden = true; }
document.addEventListener('click', function (e) {
    if (!e.target.closest('[onclick^="brandRowMenu"]') && !e.target.closest('#brRowMenu')) brandCloseRowMenu();
});
document.addEventListener('scroll', brandCloseRowMenu, true);

/* ═══ Dışa Aktar (yüklü + filtrelenmiş markaların CSV'si) ═══ */
function brandExportCsv() {
    const entitled = BR_MARKETPLACES.filter(m => m.entitled);
    const cell = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
    const lines = [['Marka', 'Ürün', 'Eşleşen Pazaryeri', 'Toplam Pazaryeri', 'Durum'].join(';')];
    (brSysAllLoaded || []).forEach(b => {
        const mc = (b.mappings || []).length;
        lines.push([cell(b.name), cell(parseInt(b.product_count) || 0), cell(mc), cell(entitled.length), cell(String(b.is_active ?? 1) !== '0' ? 'Aktif' : 'Pasif')].join(';'));
    });
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'markalar.csv';
    document.body.appendChild(a); a.click(); a.remove();
    brToast('Marka listesi dışa aktarıldı', 'success');
}

/* ═══ Init ═══ */
brandLoadSystemTable();
</script>
<!-- Satır aksiyon menüsü (gövde seviyesinde, fixed) -->
<div id="brRowMenu" hidden class="fixed z-[10001] w-44 rounded-xl bg-surface shadow-pop border border-slate-100 dark:border-white/10 p-1.5"></div>
