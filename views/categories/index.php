<?php
$csrf            = $data['_csrf'] ?? '';
$marketplaces    = $data['marketplaces'] ?? [];
$flatTree        = $data['flatTree'] ?? [];
$totalCategories = $data['totalCategories'] ?? 0;
$totalMapped     = $data['totalMapped'] ?? 0;
$totalUnmapped   = $data['totalUnmapped'] ?? 0;
$mpStats         = $data['mpStats'] ?? [];
$canMarketplace  = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
$can             = $data['_can'] ?? static fn(string $permission): bool => true;
$canSyncCategories = (bool) $can('categories.sync');
$unsellableSummary = $data['unsellableSummary'] ?? [];
$hbCacheStatus     = $data['hbCacheStatus'] ?? null;

$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63','idefix'=>'#1e40af'];
$mpBgs    = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff4ed','n11'=>'#faf5ff','amazon'=>'#fff8e1','pazarama'=>'#ecfeff','pttavm'=>'#fefce8','ciceksepeti'=>'#fdf2f8','idefix'=>'#eff6ff'];
?>
<style>
/* === Üst Filtre Paneli (pazaryeri tab'leri — tek beyaz bütün) === */
.o-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);margin-bottom:20px;overflow:hidden}
.o-panel .o-toolbar{margin:0;padding:12px 14px}
.mp-seg{display:flex;align-items:center;gap:3px;padding:9px 10px;overflow-x:auto}
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

/* ── Tile Grid ── */
.mp-grid{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.mp-grid>.mp-tile{flex:1 1 0;min-width:110px}
.mp-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;border:1.5px solid var(--border);background:var(--bg-card);transition:all .18s ease;min-height:80px;overflow:hidden}
.mp-tile:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.mp-tile:active{transform:translateY(0);box-shadow:0 0 0 1px var(--border)}
.mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.8;transition:opacity .15s}
.mp-tile:hover .mp-tile-logo{opacity:1}
.mp-tile-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:var(--mp-color,var(--primary));padding:0 6px;line-height:1}
.mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.mp-tile-inactive{opacity:.3;pointer-events:none;filter:grayscale(.7)}
.mp-tile-inactive .mp-tile-soon{position:absolute;bottom:4px;right:4px;font-size:8px;background:#ef4444;color:#fff;padding:1px 5px;border-radius:4px;font-weight:700}

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
.cat-h{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.cat-h h1{margin:0;font-size:24px;font-weight:700}
.cat-h p{margin:6px 0 0;color:var(--text-muted);font-size:13px}

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
.sys-table .cat-indent{display:inline-flex;align-items:center;gap:6px}
.sys-table .indent-line{width:16px;border-left:2px solid var(--border);height:20px;margin-left:4px}
.tree-toggle{cursor:pointer;width:20px;height:20px;border:none;background:none;padding:0;display:inline-flex;align-items:center;justify-content:center;color:#94a3b8;transition:transform .2s}
.tree-toggle.open{transform:rotate(90deg)}
.badge-mapped{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;background:#f0fdf4;color:#10b981;border:1px solid #bbf7d0}
.badge-unmapped{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;background:#fef3c7;color:#d97706;border:1px solid #fde68a}
.mp-cell-name{font-size:12px;color:var(--text-primary);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mp-cell-btn{padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:var(--bg-card);color:var(--text-muted);font-family:inherit;transition:all .12s}
.mp-cell-btn:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .05)}
.sys-table th.mp-col,.sys-table td.mp-col{text-align:center}
.row-actions{display:flex;gap:4px}
.row-actions button{width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--bg-card);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .12s}
.row-actions button:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .05)}

/* ── MP Tree Table (overlay) ── */
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
.map-result-item.selected{background:var(--primary-bg,#eff6ff);border-left:3px solid var(--primary)}
.map-result-item:focus-visible{outline:2px solid var(--primary);outline-offset:-2px}
.map-result-item.is-disabled{cursor:not-allowed;opacity:.7}
.map-result-item.is-disabled:hover{background:transparent}
.map-result-path{font-size:11px;color:var(--text-muted);margin-top:2px}
.map-result-heading{padding:8px 14px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--danger);background:var(--danger-bg);border-bottom:1px solid var(--border)}
.map-result-reason{flex-shrink:0;margin-left:12px;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;background:var(--danger-bg);color:var(--danger)}
.map-result-empty{padding:20px;text-align:center;color:var(--text-muted)}
.map-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

/* ── Satış durumu uyarıları ── */
.cat-alert{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;margin:0 0 16px;padding:12px 16px;border-radius:12px;font-size:13px;line-height:1.55;border:1px solid transparent;color:var(--text-primary)}
.cat-alert.is-danger{background:var(--danger-bg);border-color:rgba(239,68,68,.28)}
.cat-alert.is-warning{background:var(--warning-bg);border-color:rgba(245,158,11,.28)}
.cat-alert ul{margin:4px 0;padding-left:18px}
.cat-alert p{margin:4px 0 0;color:var(--text-muted)}
.cat-alert .o-btn:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
.cat-alert .o-btn [data-lucide]{width:14px;height:14px}
.badge-closed{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;background:var(--danger-bg);color:var(--danger);border:1px solid rgba(239,68,68,.25)}
.cat-pill-danger{background:var(--danger-bg);color:var(--danger)}
.cat-map-invalid{background:var(--danger-bg);box-shadow:0 0 0 1px rgba(239,68,68,.3);color:var(--text-primary)}

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
    <h1>Kategoriler</h1>
    <p>Kategorilerinizi düzenleyin ve pazaryeri kategorileriyle eşleştirin.</p>
  </div>
  <div class="ui-page-actions">
    <button type="button" onclick="catAutoMatchAll()" class="ui-btn ui-btn-outline"><i data-lucide="wand-sparkles"></i> Tümünü Eşleştir</button>
    <button type="button" onclick="catOpenCreateModal()" class="ui-btn ui-btn-primary"><i data-lucide="plus"></i> Kategori Ekle</button>
  </div>
</div>
<?php $subnav = ['active' => '/categories', 'items' => [
  ['u' => '/products', 'l' => 'Ürünler'],
  ['u' => '/categories', 'l' => 'Kategoriler'],
  ['u' => '/brands', 'l' => 'Markalar'],
  ['u' => '/feeds', 'l' => 'Feed'],
  ['u' => '/xml-profilleri', 'l' => 'XML Profilleri'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<?php if ($unsellableSummary !== []): ?>
<div class="cat-alert is-danger" role="alert" id="catUnsellableAlert">
  <div>
    <strong>Geçersiz kategori eşleşmeleri var.</strong>
    <ul>
      <?php foreach ($unsellableSummary as $row): ?>
      <li data-unsellable-mp="<?= htmlspecialchars((string) $row['slug']) ?>">
        <span data-unsellable-count><?= (int) $row['count'] ?></span> eşleşme <?= htmlspecialchars((string) $row['name']) ?>'da satışa kapalı, ara ya da listede olmayan bir kategoriye bağlı.
      </li>
      <?php endforeach; ?>
    </ul>
    <p>Bu kategorilerdeki ürünler gönderilemez. Soldan kategoriyi seçip kırmızı işaretli eşleşmeyi değiştirin ya da <strong>Otomatik Eşle</strong> ile düzeltmeyi deneyin.</p>
  </div>
</div>
<?php endif; ?>

<?php if (is_array($hbCacheStatus) && !empty($hbCacheStatus['stale'])): ?>
<div class="cat-alert is-warning" role="status" id="catHbStale">
  <div>
    <strong>Hepsiburada kategori listesi eski.</strong>
    Listede satış durumu bilgisi yok; satışa kapalı kategoriler ayırt edilemiyor ve eşleştirmede seçilebiliyor.
    <?= $canSyncCategories ? 'Listeyi yenileyin.' : 'Yenilemek için yöneticinize başvurun.' ?>
  </div>
  <?php if ($canSyncCategories): ?>
  <button type="button" class="o-btn o-btn-sm" id="catHbRefresh"><i data-lucide="refresh-cw" aria-hidden="true"></i> Kategorileri yenile</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Pazaryeri kategori penceresi artık sol paneldeki "Pazaryerinden Çek" menüsünden
     ve sağ paneldeki pazaryeri satırlarındaki içe-aktar ikonundan açılır (catOpenWindow). -->

<!-- ═══════════════════════════════════════════════════════════
     SYNC BOX (progress indicator)
     ═══════════════════════════════════════════════════════════ -->
<div class="sync-box" id="syncBox">
  <div style="display:flex;align-items:center;gap:10px">
    <i data-lucide="refresh-cw" style="width:18px;height:18px;color:var(--primary);animation:spin 1s linear infinite"></i>
    <div><strong id="syncTitle">İşlem yapılıyor...</strong><div id="syncStatus" style="font-size:12px;color:var(--text-muted);margin-top:2px"></div></div>
  </div>
  <div class="sync-bar"><div class="sync-bar-fill" id="syncBarFill"></div></div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     2 KOLON: SOL = sistem kategori ağacı · SAĞ = pazaryeri eşlemesi
     ═══════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

  <!-- ════ SOL: KATEGORİ AĞACI ════ -->
  <section class="lg:col-span-5 rounded-2xl bg-surface shadow-card overflow-hidden">
    <div class="flex items-center gap-2.5 px-4 py-3.5 border-b border-slate-100 dark:border-white/[.06]">
      <div class="relative flex-1 min-w-0">
        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-[16px] h-[16px] text-slate-400"></i>
        <input type="text" id="sysSearch" placeholder="Kategori ara…" class="t w-full h-9 pl-9 pr-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <!-- Pazaryerinden çek menüsü (overlay'i açar) -->
      <div class="relative shrink-0">
        <button type="button" onclick="catToggleMpMenu(event)" class="t grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-400" title="Pazaryerinden kategori çek"><i data-lucide="download-cloud" class="w-[18px] h-[18px]"></i></button>
        <div id="catMpMenu" hidden class="absolute right-0 mt-1 w-60 z-20 rounded-xl bg-surface shadow-pop border border-slate-100 dark:border-white/10 p-1.5">
          <div class="eyebrow text-slate-400 px-2.5 pt-1.5 pb-1">Pazaryerinden Çek</div>
          <?php foreach ($marketplaces as $mp):
              $slug = (string) ($mp['slug'] ?? ''); $mpId = (string) ($mp['id'] ?? '');
              $nm = htmlspecialchars((string) ($mp['display_name'] ?? $slug));
              $mColor = $mpColors[$slug] ?? 'var(--primary)';
              $isInactive = !$canMarketplace((int) $mp['id']);
          ?>
          <?php if ($isInactive): ?>
          <div class="flex items-center gap-2 px-2.5 h-9 rounded-lg opacity-50 cursor-not-allowed text-[13px]"><img src="/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.jpg'" style="height:14px;filter:grayscale(1)" alt=""> <?= $nm ?> <span class="ml-auto text-[10px] text-slate-400">Pasif</span></div>
          <?php else: ?>
          <button type="button" onclick="catCloseMpMenu();catOpenWindow('<?= htmlspecialchars($mpId) ?>','<?= htmlspecialchars($slug) ?>','<?= htmlspecialchars((string) ($mp['display_name'] ?? $slug), ENT_QUOTES) ?>','<?= htmlspecialchars($mColor) ?>')" class="t w-full flex items-center gap-2 px-2.5 h-9 rounded-lg hover:bg-brand/[.08] text-[13px] font-medium"><img src="/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($slug) ?>.jpg'" style="height:14px" alt=""> <?= $nm ?> <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded-md text-[10.5px] font-semibold tnum bg-slate-900/[.05] dark:bg-white/[.08] text-slate-500"><?= (int) ($mpStats[$slug] ?? 0) ?></span></button>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="button" onclick="catDeleteAllSystem()" class="t grid place-items-center w-9 h-9 rounded-lg hover:bg-danger/10 text-slate-400 hover:text-danger shrink-0" title="Tüm sistem kategorilerini sil"><i data-lucide="trash-2" class="w-[17px] h-[17px]"></i></button>
    </div>
    <div id="sysTableWrap" class="p-2 max-h-[640px] overflow-y-auto text-[13.5px]"></div>
  </section>

  <!-- ════ SAĞ: PAZARYERİ KATEGORİ EŞLEMESİ ════ -->
  <section class="lg:col-span-7 rounded-2xl bg-surface shadow-card overflow-hidden">
    <div class="px-5 pt-5 pb-4 border-b border-slate-100 dark:border-white/[.06]">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="eyebrow text-slate-400">Pazaryeri Kategori Eşlemesi</div>
          <h2 id="selPath" class="mt-1.5 text-[17px] font-bold tracking-tight leading-tight">Kategori seçilmedi</h2>
          <div class="mt-1.5 text-[12.5px] text-slate-400" id="selMeta">Soldan bir kategori seçin.</div>
        </div>
        <button type="button" onclick="catAutoMatchAll()" class="t flex items-center gap-1.5 h-9 px-3 rounded-lg border border-slate-200/80 dark:border-white/10 text-[12.5px] font-medium hover:shadow-card shrink-0"><i data-lucide="wand-sparkles" class="w-3.5 h-3.5 text-brand"></i> Otomatik Eşle</button>
      </div>
    </div>
    <div id="mapPanelBody">
      <div class="px-5 py-16 text-center text-slate-400" id="mapEmpty">
        <i data-lucide="git-merge" class="w-10 h-10 mx-auto mb-3 opacity-40"></i>
        <div class="text-[13.5px]">Eşlemeleri görmek için soldaki ağaçtan bir kategori seçin.</div>
      </div>
    </div>
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-t border-slate-100 dark:border-white/5">
      <span class="text-[12px] text-slate-400">Eşlemeler anında kaydedilir.</span>
    </div>
  </section>

</div>

<!-- ═══════════════════════════════════════════════════════════
     OVERLAY — Per-Marketplace Categories
     ═══════════════════════════════════════════════════════════ -->
<div class="mp-overlay" id="catOverlay">
  <div class="mp-window" id="catWindow">
    <div class="mp-win-header" id="catWinHeader">
      <img class="mp-win-logo" id="catWinLogo" src="" alt="">
      <div class="mp-win-title" id="catWinTitle">Kategoriler</div>
      <div class="mp-win-stats" id="catWinStats"></div>
      <button class="mp-win-close" onclick="catCloseWindow()" title="Kapat (ESC)">
        <i data-lucide="x" style="width:20px;height:20px"></i>
      </button>
    </div>
    <div class="mp-win-toolbar">
      <div class="o-search" style="width:260px">
        <i data-lucide="search" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="catWinSearch" placeholder="Kategori ara...">
      </div>
      <button type="button" class="o-btn o-btn-primary o-btn-sm" id="catWinSyncBtn" onclick="catSyncFromMp()"><i data-lucide="download" style="width:14px;height:14px"></i> Çek / Güncelle</button>
      <button type="button" class="o-btn o-btn-success o-btn-sm" id="catWinBulkBtn" style="display:none" onclick="catBulkAdd()"><i data-lucide="plus-circle" style="width:14px;height:14px"></i> Seçilenleri Sisteme Ekle</button>
      <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="catClearCache()"><i data-lucide="trash" style="width:14px;height:14px"></i> Cache Temizle</button>
    </div>
    <div class="mp-win-body">
      <div id="catWinTableWrap"></div>
      <div id="catWinPagination" class="mp-win-pagination" style="display:flex;justify-content:center;gap:4px;padding:12px 24px;flex-shrink:0"></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     CREATE/EDIT MODAL
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="catModal">
  <div class="modal-box">
    <h3 id="catModalTitle"><i data-lucide="folder-plus" style="width:18px;height:18px"></i> Yeni Kategori</h3>
    <form onsubmit="catSaveCategory(event)">
      <input type="hidden" id="catId">
      <div class="form-group"><label>Kategori Adı</label><input type="text" id="catName" required></div>
      <div class="form-group"><label>Üst Kategori</label>
        <select id="catParent"><option value="">— Kök Kategori —</option>
        <?php foreach ($flatTree as $ft): ?>
        <option value="<?= $ft['id'] ?>"><?= str_repeat('— ', $ft['depth']) . htmlspecialchars($ft['name']) ?></option>
        <?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label>Sıra</label><input type="number" id="catSort" value="0"></div>
        <div class="form-group"><label>Durum</label><select id="catActive"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
      </div>
      <div class="form-actions">
        <button type="button" class="o-btn" onclick="catCloseModal()">İptal</button>
        <button type="submit" class="o-btn o-btn-primary" id="catSaveBtn"><i data-lucide="save" style="width:14px;height:14px"></i> Kaydet</button>
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
    <input type="hidden" id="mapCategoryId">
    <input type="hidden" id="mapMpId">
    <input type="hidden" id="mapMpSlug">
    <div id="mapStatusArea"></div>
    <div class="form-group">
      <label>Pazaryeri Kategorisi Ara</label>
      <input type="text" id="mapSearchInput" placeholder="Kategori adı yazın..." aria-describedby="mapResultsStatus">
    </div>
    <div class="map-results" id="mapResults"></div>
    <div id="mapResultsStatus" class="map-sr-only" role="status" aria-live="polite"></div>
    <input type="hidden" id="mapSelectedMpCatId">
    <input type="hidden" id="mapSelectedMpCatName">
    <div class="form-actions">
      <button type="button" class="o-btn" onclick="catCloseMapModal()">İptal</button>
      <button type="button" class="o-btn o-btn-primary" id="mapSaveBtn" disabled onclick="catSaveMapping()"><i data-lucide="check" style="width:14px;height:14px"></i> Eşleştir</button>
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
const CAT_CSRF = <?= json_encode((string) $csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CAT_MARKETPLACES = <?= json_encode(array_map(fn($m)=>['id'=>$m['id'],'slug'=>$m['slug'],'name'=>$m['display_name'],'entitled'=>$canMarketplace((int)$m['id'])], $marketplaces)) ?>;
const CAT_MP_COLORS = <?= json_encode($mpColors) ?>;
const CAT_PROBLEM_LABELS = { kapali: 'Satışa kapalı', pasif: 'Satışa kapalı (pasif)', ara: 'Ara kategori', bulunamadi: 'Listede yok' };

const catEsc = v => { const d = document.createElement('div'); d.textContent = v || ''; return d.innerHTML; };
const catAttr = v => catEsc(v).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
/* onclick="fn('…')" içindeki tek tırnaklı JS dizesi argümanı: önce JS kaçışı (\ ve '), sonra öznitelik kaçışı. */
const catJsArg = v => catAttr(String(v == null ? '' : v).replace(/\\/g, '\\\\').replace(/'/g, "\\'"));
const catToast = (m, t = 'info') => typeof Toast !== 'undefined' && typeof Toast.show === 'function' ? Toast.show(m, t) : console.log(t + ': ' + m);

let catActiveMpId = '';
let catActiveMpSlug = '';
let catActiveMpName = '';
let catMpSelectedIds = {};
let catMpCurrentPage = 1;
let catNodeData = {};      // id -> kategori verisi (mappings dahil) — sağ panel için
let catSelectedId = null;  // seçili kategori (sağ panelde gösterilen)

/* ═══ Custom Confirm ═══ */
function catConfirm(msg, opts = {}) {
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

/* ═══ Tile Count Update ═══ */
function catUpdateTileCounts() {
    // Tile counts updated from mpStats which are category mapping counts per marketplace
}

/* ═══════════════════════════════════════
   SYSTEM CATEGORIES — Tree View
   ═══════════════════════════════════════ */
function catLoadSystemTable() {
    const wrap = document.getElementById('sysTableWrap');
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)"><i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    fetch('/categories/ajax')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            catRenderSystemTree(data.data, wrap);
        });
}

function catResetMapPanel() {
    catSelectedId = null;
    const sp = document.getElementById('selPath'); if (sp) sp.textContent = 'Kategori seçilmedi';
    const sm = document.getElementById('selMeta'); if (sm) sm.textContent = 'Soldan bir kategori seçin.';
    const body = document.getElementById('mapPanelBody');
    if (body) body.innerHTML = '<div class="px-5 py-16 text-center text-slate-400"><i data-lucide="git-merge" class="w-10 h-10 mx-auto mb-3 opacity-40"></i><div class="text-[13.5px]">Eşlemeleri görmek için soldaki ağaçtan bir kategori seçin.</div></div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function catRenderSystemTree(categories, container) {
    catNodeData = {};
    catResetMapPanel();
    if (!categories || categories.length === 0) {
        container.innerHTML = '<div class="px-3 py-16 text-center text-slate-400"><i data-lucide="folder-open" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>Henüz kategori yok</div>';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        return;
    }
    container.innerHTML = catRenderCategoryRows(categories, 0);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

/* Mockup ağaç düğümü (iç-içe div'ler). Her düğüm catNodeData'ya yazılır → sağ panel seçimi. */
function catRenderCategoryRows(categories, depth) {
    let html = '';
    (categories || []).forEach(cat => {
        catNodeData[cat.id] = cat;
        const hasChildren = (cat.child_count || cat.children_count || 0) > 0;
        const count = (cat.product_count != null ? cat.product_count : 0);
        const safeName = catJsArg(cat.name);
        const chevron = hasChildren
            ? '<button class="tree-toggle shrink-0 grid place-items-center w-5 h-5 text-slate-400" onclick="event.stopPropagation();catToggleTreeNode(' + cat.id + ',this,' + (depth + 1) + ')"><i data-lucide="chevron-right" style="width:15px;height:15px"></i></button>'
            : '<span class="w-5 shrink-0"></span>';
        const icon = hasChildren
            ? '<i data-lucide="folder" class="w-[17px] h-[17px] text-slate-400 shrink-0"></i>'
            : '<i data-lucide="tag" class="w-[16px] h-[16px] text-slate-400 shrink-0"></i>';
        html += '<div data-node="' + cat.id + '">'
            + '<div class="cat-row group t flex items-center gap-1.5 h-9 px-2 rounded-lg cursor-pointer hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]" data-id="' + cat.id + '" data-name="' + catEsc(cat.name).toLowerCase() + '" onclick="catSelectLeaf(' + cat.id + ')">'
            + chevron + icon
            + '<span class="cat-row-name font-medium text-slate-700 dark:text-slate-200 truncate">' + catEsc(cat.name) + '</span>'
            + '<span class="cat-row-count ml-auto shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold tnum bg-slate-900/[.05] dark:bg-white/[.08] text-slate-500 group-hover:hidden">' + count + '</span>'
            + '<span class="hidden group-hover:flex items-center gap-0.5 ml-auto shrink-0">'
            + '<button class="t grid place-items-center w-7 h-7 rounded-md hover:bg-success/10 text-slate-400 hover:text-success" title="Alt kategori ekle" onclick="event.stopPropagation();catOpenCreateModal(' + cat.id + ',\'' + safeName + '\')"><i data-lucide="folder-plus" style="width:13px;height:13px"></i></button>'
            + '<button class="t grid place-items-center w-7 h-7 rounded-md hover:bg-slate-900/[.06] dark:hover:bg-white/10 text-slate-400" title="Düzenle" onclick="event.stopPropagation();catOpenEditModal(' + cat.id + ',\'' + safeName + '\',\'' + (cat.parent_id || '') + '\',' + (cat.sort_order || 0) + ',' + cat.is_active + ')"><i data-lucide="pencil" style="width:13px;height:13px"></i></button>'
            + '<button class="t grid place-items-center w-7 h-7 rounded-md hover:bg-danger/10 text-slate-400 hover:text-danger" title="Sil" onclick="event.stopPropagation();catDeleteCategory(' + cat.id + ',\'' + safeName + '\')"><i data-lucide="trash-2" style="width:13px;height:13px"></i></button>'
            + '</span>'
            + '</div>';
        if (hasChildren) {
            html += '<div class="cat-children pl-4 mt-0.5 space-y-0.5" data-children-of="' + cat.id + '" hidden></div>';
        }
        html += '</div>';
    });
    return html;
}

function catToggleTreeNode(catId, btn, depth) {
    const kids = document.querySelector('.cat-children[data-children-of="' + catId + '"]');
    if (!kids) return;
    if (kids.dataset.loaded === '1') {
        const willOpen = kids.hidden;
        kids.hidden = !willOpen;
        btn.classList.toggle('open', willOpen);
        return;
    }
    btn.classList.add('open');
    btn.innerHTML = '<i data-lucide="loader" style="width:15px;height:15px;animation:spin 1s linear infinite"></i>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    fetch('/categories/ajax?parent_id=' + catId)
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = '<i data-lucide="chevron-right" style="width:15px;height:15px"></i>';
            if (!data.success) { if (typeof lucide !== 'undefined') lucide.createIcons(); return; }
            kids.innerHTML = catRenderCategoryRows(data.data, depth);
            kids.dataset.loaded = '1';
            kids.hidden = false;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
}

/* ═══ Sol ağaçta seçim → sağ pazaryeri eşleme panelini doldur ═══ */
function catBuildPath(cat) { return cat.full_path || cat.path || cat.name || ''; }

function catSelectLeaf(catId) {
    const cat = catNodeData[catId];
    if (!cat) return;
    catSelectedId = catId;
    document.querySelectorAll('#sysTableWrap .cat-row').forEach(r => {
        r.classList.remove('ring-1', 'ring-brand/30', 'bg-brand/[.06]');
        const nm = r.querySelector('.cat-row-name'); if (nm) nm.classList.remove('text-brand');
    });
    const row = document.querySelector('#sysTableWrap .cat-row[data-id="' + catId + '"]');
    if (row) {
        row.classList.add('ring-1', 'ring-brand/30', 'bg-brand/[.06]');
        const nm = row.querySelector('.cat-row-name'); if (nm) nm.classList.add('text-brand');
    }
    catRenderMappingPanel(cat);
}

/* Eşleşme neden geçersiz: sunucunun satış durumu nedeni ya da ara (yaprak olmayan) kategori. */
function catMappingProblem(m) {
    if (!m) return '';
    if (m.unsellable_reason) return m.unsellable_reason;
    return parseInt(m.marketplace_category_is_leaf ?? 1) === 0 ? 'ara' : '';
}

function catRenderMappingPanel(cat) {
    const mappings = cat.mappings || [];
    const entitled = CAT_MARKETPLACES.filter(m => m.entitled);
    const validMappings = mappings.filter(x => !catMappingProblem(x) && entitled.some(e => e.slug === x.marketplace_slug));
    document.getElementById('selPath').textContent = (catBuildPath(cat) || cat.name);
    const pc = (cat.product_count != null ? cat.product_count : 0);
    document.getElementById('selMeta').innerHTML = '<span class="font-semibold text-slate-600 dark:text-slate-300 tnum">' + pc + '</span> ürün · <span class="font-semibold text-success">' + validMappings.length + '</span> / ' + entitled.length + ' pazaryeri eşlendi';

    const safeName = catJsArg(cat.name);
    let rows = '';
    CAT_MARKETPLACES.forEach(mp => {
        const logo = '<span class="logo-chip" style="padding:6px 9px"><img class="mp-logo" style="height:17px' + (mp.entitled ? '' : ';filter:grayscale(1)') + '" src="/public/assets/img/marketplaces/' + mp.slug + '.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + mp.slug + '.jpg\'" alt="' + catEsc(mp.name) + '"></span>';
        if (!mp.entitled) {
            rows += '<div class="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-4 opacity-70">'
                + '<div class="flex items-center gap-3 sm:w-48 shrink-0">' + logo
                + '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-500/10 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>Pasif</span></div>'
                + '<div class="flex-1 min-w-0 flex items-center justify-between gap-3"><span class="text-[12.5px] text-slate-400">Pazaryeri bağlı değil</span>'
                + '<a href="/marketplaces" class="t text-[12.5px] font-semibold text-brand hover:underline shrink-0">Bağla →</a></div></div>';
            return;
        }
        const m = mappings.find(x => x.marketplace_slug === mp.slug);
        const problem = catMappingProblem(m);
        const invalid = !!m && problem !== '';
        const mapped = !!m && !invalid;
        const pill = mapped
            ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-success/10 text-success"><span class="w-1.5 h-1.5 rounded-full bg-success"></span>Eşlendi</span>'
            : (invalid
                ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold cat-pill-danger">' + catEsc(CAT_PROBLEM_LABELS[problem] || 'Geçersiz') + '</span>'
                : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-warning/10 text-warning"><span class="w-1.5 h-1.5 rounded-full bg-warning"></span>Eksik</span>');
        const label = mapped
            ? catEsc(m.marketplace_category_name || 'Eşlendi')
            : (invalid ? catEsc(m.marketplace_category_name || 'Eşleşme') + ' — değiştirin' : 'Kategori seçin…');
        const boxCls = mapped
            ? 'bg-slate-900/[.03] dark:bg-white/[.05] text-slate-700 dark:text-slate-200'
            : (invalid ? 'cat-map-invalid' : 'bg-warning/5 ring-1 ring-warning/30 text-slate-500 dark:text-slate-300');
        const selBox = '<button type="button" onclick="catOpenMapModalFor(' + cat.id + ',\'' + safeName + '\',' + mp.id + ',\'' + mp.slug + '\')" class="t w-full h-10 pl-3 pr-9 rounded-lg text-left text-[13px] font-medium outline-none focus:ring-2 focus:ring-brand/40 truncate relative ' + boxCls + '">' + label + '<i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i></button>';
        const importBtn = '<button type="button" onclick="catOpenWindow(\'' + mp.id + '\',\'' + mp.slug + '\',\'' + catJsArg(mp.name) + '\',\'' + (CAT_MP_COLORS[mp.slug] || '#2563eb') + '\')" class="t grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-400 shrink-0" title="Pazaryerinden kategori çek"><i data-lucide="download-cloud" class="w-4 h-4"></i></button>';
        rows += '<div class="flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-4' + (mapped ? '' : ' bg-warning/[.03]') + '">'
            + '<div class="flex items-center gap-3 sm:w-48 shrink-0">' + logo + pill + '</div>'
            + '<div class="relative flex-1 min-w-0">' + selBox + '</div>'
            + importBtn + '</div>';
    });
    document.getElementById('mapPanelBody').innerHTML = '<div class="divide-y divide-slate-100 dark:divide-white/5">' + rows + '</div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

/* ═══ Sol panel "Pazaryerinden Çek" menüsü ═══ */
function catToggleMpMenu(e) { if (e) e.stopPropagation(); const m = document.getElementById('catMpMenu'); if (m) m.hidden = !m.hidden; }
function catCloseMpMenu() { const m = document.getElementById('catMpMenu'); if (m) m.hidden = true; }
document.addEventListener('click', function (e) {
    const m = document.getElementById('catMpMenu');
    if (m && !m.hidden && !m.contains(e.target) && !e.target.closest('[onclick*="catToggleMpMenu"]')) m.hidden = true;
});

/* System search */
let catSysSearchTimer;
document.getElementById('sysSearch').addEventListener('input', function () {
    clearTimeout(catSysSearchTimer);
    const q = this.value.toLowerCase();
    catSysSearchTimer = setTimeout(() => {
        if (q.length >= 2) {
            fetch('/categories/ajax?search=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (data.success) catRenderSystemTree(data.data, document.getElementById('sysTableWrap'));
                });
        } else if (q === '') {
            catLoadSystemTable();
        }
    }, 400);
});

/* ═══════════════════════════════════════
   CRUD — Create / Edit / Delete
   ═══════════════════════════════════════ */
function catOpenCreateModal(parentId, parentName) {
    document.getElementById('catModalTitle').innerHTML = '<i data-lucide="folder-plus" style="width:18px;height:18px"></i> ' + (parentId ? catEsc(parentName) + ' > Alt Kategori' : 'Yeni Kategori');
    document.getElementById('catId').value = '';
    document.getElementById('catName').value = '';
    document.getElementById('catParent').value = parentId || '';
    document.getElementById('catSort').value = '0';
    document.getElementById('catActive').value = '1';
    document.getElementById('catModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
    setTimeout(() => document.getElementById('catName').focus(), 200);
}

function catOpenEditModal(id, name, parentId, sortOrder, isActive) {
    document.getElementById('catModalTitle').innerHTML = '<i data-lucide="pencil" style="width:18px;height:18px"></i> Kategori Düzenle';
    document.getElementById('catId').value = id;
    document.getElementById('catName').value = name;
    document.getElementById('catParent').value = parentId || '';
    document.getElementById('catSort').value = sortOrder;
    document.getElementById('catActive').value = isActive ? '1' : '0';
    document.getElementById('catModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function catCloseModal() { document.getElementById('catModal').classList.remove('show'); }
document.getElementById('catModal').addEventListener('click', e => { if (e.target.id === 'catModal') catCloseModal(); });

async function catSaveCategory(e) {
    e.preventDefault();
    const id = document.getElementById('catId').value;
    const name = document.getElementById('catName').value.trim();
    if (!name) { catToast('Kategori adı zorunludur', 'error'); return; }
    const btn = document.getElementById('catSaveBtn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('name', name);
    fd.append('parent_id', document.getElementById('catParent').value);
    fd.append('sort_order', document.getElementById('catSort').value);
    fd.append('is_active', document.getElementById('catActive').value);
    fd.append('_csrf_token', CAT_CSRF);
    try {
        const res = await fetch(id ? '/categories/' + id : '/categories', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { catToast(data.data?.message || data.message, 'success'); catCloseModal(); catLoadSystemTable(); }
        else { catToast(data.message, 'error'); }
    } catch (e) { catToast('Hata', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i data-lucide="save" style="width:14px;height:14px"></i> Kaydet'; if (typeof lucide !== 'undefined') lucide.createIcons(); }
}

async function catDeleteCategory(id, name) {
    const ok = await catConfirm('"' + name + '" kategorisi silinecek. Emin misiniz?', { title: 'Kategori Sil', icon: '⚠️', iconClass: 'danger', okText: 'Sil' });
    if (!ok) return;
    const fd = new FormData();
    fd.append('_csrf_token', CAT_CSRF);
    try {
        const res = await fetch('/categories/' + id + '/delete', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { catToast(data.data?.message || data.message, 'success'); catLoadSystemTable(); }
        else { catToast(data.message, 'error'); }
    } catch (e) { catToast('Hata', 'error'); }
}

async function catDeleteAllSystem() {
    const ok = await catConfirm('TÜM sistem kategorileri silinecek. Bu işlem geri alınamaz!', { title: 'Dikkat!', icon: '⚠️', iconClass: 'danger', okText: 'Evet, Hepsini Sil' });
    if (!ok) return;
    const fd = new FormData();
    fd.append('_csrf_token', CAT_CSRF);
    try {
        const res = await fetch('/categories/delete-all', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { catToast(data.data?.message || data.message, 'success'); setTimeout(() => location.reload(), 1000); }
        else { catToast(data.message, 'error'); }
    } catch (e) { catToast('Hata', 'error'); }
}

async function catAutoMatchAll() {
    const ok = await catConfirm('Tüm pazaryerlerinde otomatik eşleştirme yapılacak. Devam etmek istiyor musunuz?', { title: 'Otomatik Eşleştirme', icon: '⚡', iconClass: 'info', okText: 'Eşleştir', okClass: 'success' });
    if (!ok) return;
    const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
    box.classList.add('show'); bar.style.width = '20%'; bar.style.background = '#10b981';
    title.textContent = 'Otomatik eşleştirme yapılıyor...'; status.textContent = 'Lütfen bekleyin...';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace', '');
    fd.append('_csrf_token', CAT_CSRF);
    try {
        bar.style.width = '50%';
        const res = await fetch('/categories/auto-match', { method: 'POST', body: fd });
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
                    const fx = r.fixed > 0 ? '<span style="color:#10b981;font-weight:700">' + r.fixed + ' satışa kapalı eşleşme düzeltildi</span>' : '';
                    html += '<div style="margin-bottom:4px"><strong>' + catEsc(r.name) + ':</strong> ' + [ok2, fx, nf, al].filter(Boolean).join(', ') + '</div>';
                });
            }
            status.innerHTML = html || rd.message || data.message;
            catToast(rd.message || data.message, 'success');
            setTimeout(() => { box.classList.remove('show'); catLoadSystemTable(); }, 3000);
        } else { title.textContent = '❌ Hata'; status.textContent = data.message; bar.style.background = '#ef4444'; }
    } catch (e) { title.textContent = '❌ Bağlantı Hatası'; status.textContent = e.message; bar.style.background = '#ef4444'; }
}

/* ═══════════════════════════════════════
   MANUAL MAP MODAL
   ═══════════════════════════════════════ */
function catOpenMapModalFor(catId, catName, mpId, mpSlug) {
    document.getElementById('mapCategoryId').value = catId;
    document.getElementById('mapMpId').value = mpId;
    document.getElementById('mapMpSlug').value = mpSlug;
    document.getElementById('mapModalTitle').innerHTML = '<i data-lucide="git-merge" style="width:18px;height:18px"></i> "' + catEsc(catName) + '" ↔ ' + mpSlug.charAt(0).toUpperCase() + mpSlug.slice(1) + ' Eşleştir';
    document.getElementById('mapSearchInput').value = catName;
    document.getElementById('mapResults').innerHTML = '';
    document.getElementById('mapSelectedMpCatId').value = '';
    document.getElementById('mapSelectedMpCatName').value = '';
    document.getElementById('mapSaveBtn').disabled = true;
    document.getElementById('mapStatusArea').innerHTML = '';
    document.getElementById('mapModal').classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
    catSearchMapCache(catName, mpId);
}
function catCloseMapModal() { document.getElementById('mapModal').classList.remove('show'); }
document.getElementById('mapModal').addEventListener('click', e => { if (e.target.id === 'mapModal') catCloseMapModal(); });

let catMapSearchTimer;
document.getElementById('mapSearchInput').addEventListener('input', function () {
    clearTimeout(catMapSearchTimer);
    catMapSearchTimer = setTimeout(() => {
        const q = this.value;
        const mpId = document.getElementById('mapMpId').value;
        if (q.length >= 2) catSearchMapCache(q, mpId);
    }, 300);
});

/* Sonuç durumu: görsel mesaj sonuç kutusunda (listbox DIŞINDA), ekran okuyucu metni kalıcı aria-live alanında. */
function catSetMapStatus(text, visible) {
    document.getElementById('mapResultsStatus').textContent = text;
    if (visible) document.getElementById('mapResults').innerHTML = '<div class="map-result-empty">' + catEsc(text) + '</div>';
}

async function catSearchMapCache(q, mpId) {
    const box = document.getElementById('mapResults');
    let data = {};
    try {
        const res = await fetch('/categories/search-cache?q=' + encodeURIComponent(q) + '&marketplace_id=' + encodeURIComponent(mpId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        data = await res.json();
    } catch (e) {
        catSetMapStatus('Arama yapılamadı. Bağlantınızı kontrol edip tekrar deneyin.', true);
        return;
    }
    const items = data.data || data.results || [];
    const closed = data.closed || [];
    const mp = CAT_MARKETPLACES.find(m => String(m.id) === String(mpId));
    if (items.length === 0 && closed.length === 0) {
        catSetMapStatus('Sonuç bulunamadı', true);
        return;
    }
    /* listbox yalnız option ve group içerir; ilk seçilebilir öğe sekme sırasında (roving tabindex). */
    let html = '<div role="listbox" id="mapResultsList" aria-label="Pazaryeri kategorisi sonuçları">';
    items.forEach((item, i) => {
        html += '<div class="map-result-item" role="option" tabindex="' + (i === 0 ? '0' : '-1') + '" aria-selected="false"'
            + ' data-mp-cat-id="' + catAttr(item.mp_category_id) + '" data-mp-cat-name="' + catAttr(item.name) + '">'
            + '<div><strong>' + catEsc(item.name) + '</strong><div class="map-result-path">' + catEsc(item.full_path || '') + '</div></div></div>';
    });
    if (closed.length > 0) {
        const closedLabel = (mp ? mp.name : 'Pazaryeri') + '\'da seçilemez';
        html += '<div role="group" aria-label="' + catAttr(closedLabel) + '">'
            + '<div class="map-result-heading" aria-hidden="true">' + catEsc(closedLabel) + '</div>';
        closed.forEach(item => {
            html += '<div class="map-result-item is-disabled" role="option" aria-disabled="true" aria-selected="false">'
                + '<div><strong>' + catEsc(item.name) + '</strong><div class="map-result-path">' + catEsc(item.full_path || '') + '</div></div>'
                + '<span class="map-result-reason">' + catEsc(item.reason || 'Satışa kapalı') + '</span></div>';
        });
        html += '</div>';
    }
    box.innerHTML = html + '</div>';
    catSetMapStatus(items.length + ' seçilebilir kategori' + (closed.length > 0 ? ', ' + closed.length + ' seçilemez kategori' : '') + ' bulundu. Ok tuşlarıyla gezinip Enter ile seçin.', false);
}

function catSelectMapItem(id, name, el) {
    document.querySelectorAll('#mapResults .map-result-item').forEach(e => {
        e.classList.remove('selected');
        e.setAttribute('aria-selected', 'false');
        if (e.getAttribute('aria-disabled') !== 'true') e.setAttribute('tabindex', '-1');
    });
    el.classList.add('selected');
    el.setAttribute('aria-selected', 'true');
    el.setAttribute('tabindex', '0');
    document.getElementById('mapSelectedMpCatId').value = id;
    document.getElementById('mapSelectedMpCatName').value = name;
    document.getElementById('mapSaveBtn').disabled = false;
}

function catPickMapResult(target) {
    const item = target.closest('#mapResults .map-result-item');
    if (!item || item.getAttribute('aria-disabled') === 'true') return;
    catSelectMapItem(item.dataset.mpCatId || '', item.dataset.mpCatName || '', item);
}
document.getElementById('mapResults').addEventListener('click', e => catPickMapResult(e.target));
document.getElementById('mapResults').addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); catPickMapResult(e.target); return; }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
    const options = Array.from(document.querySelectorAll('#mapResults .map-result-item[role="option"]:not([aria-disabled="true"])'));
    if (options.length === 0) return;
    e.preventDefault();
    const current = options.indexOf(e.target.closest('.map-result-item'));
    let next = 0;
    if (e.key === 'End') next = options.length - 1;
    else if (e.key === 'ArrowDown') next = current < 0 ? 0 : Math.min(options.length - 1, current + 1);
    else if (e.key === 'ArrowUp') next = current < 0 ? 0 : Math.max(0, current - 1);
    options.forEach((opt, i) => opt.setAttribute('tabindex', i === next ? '0' : '-1'));
    options[next].focus();
});

/* Düzeltilen geçersiz eşleşmeyi sayfa üstü uyarıdan düş. */
function catDecrementUnsellable(slug) {
    const row = document.querySelector('#catUnsellableAlert [data-unsellable-mp="' + CSS.escape(slug) + '"]');
    if (!row) return;
    const counter = row.querySelector('[data-unsellable-count]');
    const next = Math.max(0, (parseInt(counter ? counter.textContent : '0', 10) || 0) - 1);
    if (next === 0) row.remove(); else if (counter) counter.textContent = String(next);
    const alertBox = document.getElementById('catUnsellableAlert');
    if (alertBox && !alertBox.querySelector('[data-unsellable-mp]')) alertBox.hidden = true;
}

async function catSaveMapping() {
    const catId2 = document.getElementById('mapCategoryId').value;
    const mpId = document.getElementById('mapMpId').value;
    const mpCatId = document.getElementById('mapSelectedMpCatId').value;
    const mpCatName = document.getElementById('mapSelectedMpCatName').value;
    if (!mpCatId) { catToast('Bir kategori seçin', 'error'); return; }
    const fd = new FormData();
    fd.append('category_id', catId2); fd.append('marketplace_id', mpId);
    fd.append('mp_category_id', mpCatId); fd.append('mp_category_name', mpCatName); fd.append('_csrf_token', CAT_CSRF);
    try {
        const res = await fetch('/categories/manual-map', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            catToast(data.data?.message || data.message, 'success');
            catCloseMapModal();
            // Sağ paneli yerinde güncelle (ağacı yeniden yüklemeye gerek yok; seçim korunur)
            const slug = document.getElementById('mapMpSlug').value;
            const cat = catNodeData[catId2];
            if (cat) {
                cat.mappings = cat.mappings || [];
                const entry = { marketplace_slug: slug, marketplace_category_name: mpCatName, marketplace_category_is_leaf: 1, unsellable_reason: '' };
                const idx = cat.mappings.findIndex(x => x.marketplace_slug === slug);
                if (idx >= 0 && cat.mappings[idx].unsellable_reason) catDecrementUnsellable(slug);
                if (idx >= 0) cat.mappings[idx] = entry; else cat.mappings.push(entry);
                if (String(catSelectedId) === String(catId2)) catRenderMappingPanel(cat);
            }
        } else { catToast(data.message, 'error'); }
    } catch (e) { catToast('Hata', 'error'); }
}

/* ═══════════════════════════════════════
   OVERLAY — Marketplace Categories
   ═══════════════════════════════════════ */
function catOpenWindow(mpId, slug, displayName, color) {
    catActiveMpId = mpId;
    catActiveMpSlug = slug;
    catActiveMpName = displayName;
    catMpSelectedIds[slug] = catMpSelectedIds[slug] || new Set();

    document.getElementById('catWinHeader').style.borderBottomColor = color;
    document.getElementById('catWinLogo').src = '/public/assets/img/marketplaces/' + slug + '.png';
    document.getElementById('catWinLogo').onerror = function () { this.src = '/public/assets/img/marketplaces/' + slug + '.jpg'; };
    document.getElementById('catWinTitle').textContent = 'Kategoriler';
    document.getElementById('catWinSearch').value = '';
    document.getElementById('catWinStats').innerHTML = '';
    document.getElementById('catWinBulkBtn').style.display = 'none';

    document.getElementById('catOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    catMpCurrentPage = 1;
    catLoadMpTree();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function catCloseWindow() {
    document.getElementById('catOverlay').classList.remove('active');
    document.body.style.overflow = '';
    catActiveMpSlug = '';
    catActiveMpId = '';
    catActiveMpName = '';
    catLoadSystemTable();
}

function catLoadMpTree(page) {
    const slug = catActiveMpSlug;
    const search = document.getElementById('catWinSearch').value;
    if (page) catMpCurrentPage = page;
    const wrap = document.getElementById('catWinTableWrap');
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)"><i data-lucide="loader" style="width:20px;height:20px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    fetch('/categories/mp-tree?marketplace=' + slug + '&page=' + catMpCurrentPage + '&search=' + encodeURIComponent(search))
        .then(r => r.json())
        .then(data => {
            if (!data.success) { wrap.innerHTML = '<div style="padding:20px;color:#ef4444">Hata: ' + (data.message || '') + '</div>'; return; }
            catRenderMpTree(data.data, wrap);
            const pag = data.pagination || {};
            catRenderTreePagination(pag);
            const totalItems = pag.total || 0;
            const items = data.data || [];
            let mapped = 0;
            items.forEach(i => { if (i.mapping_id) mapped++; });
            document.getElementById('catWinStats').innerHTML =
                '<div class="mp-win-stat"><span class="mp-win-stat-val">' + totalItems + '</span><span class="mp-win-stat-lbl">Toplam</span></div>' +
                '<div class="mp-win-stat" style="background:#f0fdf4"><span class="mp-win-stat-val" style="color:#10b981">' + mapped + '</span><span class="mp-win-stat-lbl">Eşleşmiş</span></div>' +
                '<div class="mp-win-stat" style="background:#fef3c7"><span class="mp-win-stat-val" style="color:#f59e0b">' + (items.length - mapped) + '</span><span class="mp-win-stat-lbl">Eşleşmemiş</span></div>';
        })
        .catch(() => { wrap.innerHTML = '<div style="padding:20px;color:#ef4444">Bağlantı hatası</div>'; });
}

function catRenderTreePagination(pag) {
    const wrap = document.getElementById('catWinPagination');
    const totalPages = pag.total_pages || 1;
    const currentPage = pag.page || 1;
    if (totalPages <= 1) { wrap.innerHTML = ''; return; }
    let html = '';
    html += '<button class="o-btn o-btn-sm" ' + (currentPage <= 1 ? 'disabled' : '') + ' onclick="catLoadMpTree(' + (currentPage - 1) + ')">‹</button>';
    for (let i = 1; i <= totalPages; i++) {
        if (totalPages > 10 && i > 3 && i < totalPages - 2 && Math.abs(i - currentPage) > 1) {
            if (i === 4 || i === totalPages - 3) html += '<button class="o-btn o-btn-sm" disabled>...</button>';
            continue;
        }
        html += '<button class="o-btn o-btn-sm' + (i === currentPage ? ' o-btn-primary' : '') + '" onclick="catLoadMpTree(' + i + ')">' + i + '</button>';
    }
    html += '<button class="o-btn o-btn-sm" ' + (currentPage >= totalPages ? 'disabled' : '') + ' onclick="catLoadMpTree(' + (currentPage + 1) + ')">›</button>';
    wrap.innerHTML = html;
}

function catRenderMpTree(items, container) {
    const slug = catActiveMpSlug;
    const mp = CAT_MARKETPLACES.find(m => m.slug === slug);
    let html = '<table class="mp-table"><thead><tr><th style="width:30px"><input type="checkbox" onchange="catToggleAllMp(this)"></th><th>Kategori Adı</th><th>Full Path</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';
    html += catRenderMpRows(items, 0);
    if (!items || items.length === 0) {
        html += '<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted)"><i data-lucide="folder-open" style="width:24px;height:24px;display:block;margin:0 auto 8px;opacity:.4"></i>Kategori bulunamadı. Önce kategorileri çekin.</td></tr>';
    }
    html += '</tbody></table>';
    container.innerHTML = html;
    catUpdateBulkBtn();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function catRenderMpRows(items, depth) {
    const slug = catActiveMpSlug;
    const mpId = catActiveMpId;
    const sel = catMpSelectedIds[slug] || new Set();
    let html = '';
    (items || []).forEach(item => {
        const indent = '<span class="indent-line"></span>'.repeat(depth);
        const isMapped = !!item.mapping_id;
        const hasChildren = (parseInt(item.child_count) || 0) > 0;
        const isLeaf = (parseInt(item.is_leaf) || 0) === 1 || (!hasChildren && typeof item.is_leaf === 'undefined');
        const isClosed = isLeaf && [item.is_active, item.is_available].some(v => v !== null && v !== undefined && v !== '' && parseInt(v, 10) === 0);
        const isChecked = sel.has(item.mp_category_id) ? 'checked' : '';

        const toggle = hasChildren
            ? '<button type="button" class="tree-toggle" data-mp-cat-id="' + catAttr(item.mp_category_id) + '" aria-label="' + catAttr(item.name) + ' alt kategorileri" onclick="catToggleMpTreeNode(this.dataset.mpCatId,this,' + (depth + 1) + ')"><i data-lucide="chevron-right" style="width:14px;height:14px"></i></button>'
            : '<span style="width:20px;display:inline-block"></span>';

        const closedBadge = '<span class="badge-closed"><i data-lucide="ban" style="width:12px;height:12px"></i> Satışa kapalı</span>';
        const statusBadge = isMapped
            ? '<span class="badge-mapped"><i data-lucide="check-circle" style="width:12px;height:12px"></i> ' + catEsc(item.system_category_name || 'Eşleşmiş') + '</span>' + (isClosed ? ' ' + closedBadge : '')
            : (!isLeaf
                ? '<span class="badge-unmapped"><i data-lucide="folder" style="width:12px;height:12px"></i> Ara kategori</span>'
                : (isClosed ? closedBadge : '<span class="badge-unmapped"><i data-lucide="alert-circle" style="width:12px;height:12px"></i> Eşleşmemiş</span>'));

        const actionBtn = isMapped
            ? '<span style="font-size:11px;color:var(--text-muted)">Zaten eşleşik</span>'
            : (!isLeaf
                ? '<span style="font-size:11px;color:var(--text-muted)">Final kategori seçin</span>'
                : (isClosed
                    ? '<span style="font-size:11px;color:var(--text-muted)">Seçilemez</span>'
                    : '<button class="o-btn o-btn-sm" style="padding:4px 10px;font-size:11px" data-mp-cat-id="' + catAttr(item.mp_category_id) + '" data-mp-cat-name="' + catAttr(item.name) + '" onclick="catAddToSystem(this.dataset.mpCatId,this.dataset.mpCatName,this)"><i data-lucide="plus" style="width:12px;height:12px"></i> Sisteme Ekle</button>'));

        html += '<tr data-mpcatid="' + catAttr(item.mp_category_id) + '" data-mpparent="' + catAttr(item.parent_mp_id || 'root') + '" data-depth="' + Number(depth) + '">'
            + '<td><input type="checkbox" class="mp-check" value="' + catAttr(item.mp_category_id) + '" ' + isChecked + ' ' + ((isMapped || !isLeaf || isClosed) ? 'disabled' : '') + ' aria-label="' + catAttr(item.name) + ' seç" onchange="catToggleMpCheck(this)"></td>'
            + '<td><span class="cat-indent">' + indent + toggle + '<i data-lucide="' + (hasChildren ? 'folder' : 'file') + '" style="width:14px;height:14px;color:' + (hasChildren ? '#2563eb' : '#94a3b8') + '"></i> <strong>' + catEsc(item.name) + '</strong></span></td>'
            + '<td style="font-size:11px;color:var(--text-muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + catAttr(item.full_path || '') + '">' + catEsc(item.full_path || item.name) + '</td>'
            + '<td>' + statusBadge + '</td>'
            + '<td>' + actionBtn + '</td>'
            + '</tr>';
    });
    return html;
}

function catToggleMpTreeNode(parentMpId, btn, depth) {
    const tbody = btn.closest('tbody');
    const existingRows = tbody.querySelectorAll('tr[data-mpparent="' + CSS.escape(String(parentMpId)) + '"]');
    if (existingRows.length > 0) {
        const isOpen = btn.classList.toggle('open');
        existingRows.forEach(r => {
            r.style.display = isOpen ? '' : 'none';
            if (!isOpen) {
                const childId = r.dataset.mpcatid;
                tbody.querySelectorAll('tr[data-mpparent="' + CSS.escape(String(childId)) + '"]').forEach(gc => gc.style.display = 'none');
                const childToggle = r.querySelector('.tree-toggle');
                if (childToggle) childToggle.classList.remove('open');
            }
        });
        return;
    }
    btn.classList.add('open');
    btn.innerHTML = '<i data-lucide="loader" style="width:14px;height:14px;animation:spin 1s linear infinite"></i>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    fetch('/categories/mp-tree?marketplace=' + catActiveMpSlug + '&parent_mp_id=' + encodeURIComponent(parentMpId))
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const childHtml = catRenderMpRows(data.data, depth);
            const currentRow = btn.closest('tr');
            currentRow.insertAdjacentHTML('afterend', childHtml);
            btn.innerHTML = '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
}

/* Checkbox handling */
function catToggleMpCheck(cb) {
    const slug = catActiveMpSlug;
    if (!catMpSelectedIds[slug]) catMpSelectedIds[slug] = new Set();
    if (cb.checked) catMpSelectedIds[slug].add(cb.value);
    else catMpSelectedIds[slug].delete(cb.value);
    catUpdateBulkBtn();
}
function catToggleAllMp(cb) {
    document.querySelectorAll('.mp-check:not(:disabled)').forEach(c => { c.checked = cb.checked; catToggleMpCheck(c); });
}
function catUpdateBulkBtn() {
    const slug = catActiveMpSlug;
    const count = catMpSelectedIds[slug] ? catMpSelectedIds[slug].size : 0;
    const btn = document.getElementById('catWinBulkBtn');
    if (btn) {
        btn.style.display = count > 0 ? '' : 'none';
        btn.innerHTML = '<i data-lucide="plus-circle" style="width:14px;height:14px"></i> ' + count + ' Seçileni Sisteme Ekle';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

/* Add to system */
async function catAddToSystem(mpCatId, name, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" style="width:12px;height:12px;animation:spin 1s linear infinite"></i>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace_id', catActiveMpId);
    fd.append('mp_category_id', mpCatId);
    fd.append('name', name);
    fd.append('_csrf_token', CAT_CSRF);
    try {
        const res = await fetch('/categories/add-to-system', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { catToast(data.data?.message || data.message, 'success'); catLoadMpTree(); }
        else { catToast(data.message, 'error'); btn.disabled = false; btn.innerHTML = '<i data-lucide="plus" style="width:12px;height:12px"></i> Sisteme Ekle'; }
    } catch (e) { catToast('Hata', 'error'); btn.disabled = false; }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function catBulkAdd() {
    const slug = catActiveMpSlug;
    const ids = [...(catMpSelectedIds[slug] || [])];
    if (ids.length === 0) return;
    const ok = await catConfirm(ids.length + ' kategori sisteme eklenecek. Devam?', { title: 'Toplu Ekleme', icon: '📦', iconClass: 'info', okText: 'Ekle', okClass: 'success' });
    if (!ok) return;
    const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
    box.classList.add('show'); bar.style.width = '30%'; bar.style.background = '#10b981';
    title.textContent = 'Toplu ekleme yapılıyor...'; status.textContent = ids.length + ' kategori işleniyor...';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace_id', catActiveMpId);
    fd.append('items', JSON.stringify(ids));
    fd.append('_csrf_token', CAT_CSRF);
    try {
        bar.style.width = '60%';
        const res = await fetch('/categories/bulk-add-to-system', { method: 'POST', body: fd });
        const data = await res.json(); bar.style.width = '100%';
        const rd = data.data || {};
        if (data.success) {
            title.textContent = '✅ Toplu Ekleme Tamamlandı!';
            status.textContent = rd.message || data.message;
            catToast(rd.message || data.message, 'success');
            catMpSelectedIds[slug] = new Set();
            setTimeout(() => { box.classList.remove('show'); catLoadMpTree(); }, 2000);
        } else { title.textContent = '❌ Hata'; status.textContent = data.message; bar.style.background = '#ef4444'; }
    } catch (e) { title.textContent = '❌ Bağlantı Hatası'; status.textContent = e.message; bar.style.background = '#ef4444'; }
}

/* Sync / Cache */
async function catSyncFromMp() {
    const ok = await catConfirm(catActiveMpName + '\'dan kategorileri çekmek istediğinize emin misiniz?', { title: 'Kategorileri Çek', icon: '📥', iconClass: 'info', okText: 'Çek/Güncelle', okClass: 'primary' });
    if (!ok) return;
    const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
    box.classList.add('show'); bar.style.width = '20%'; bar.style.background = '#2563eb';
    title.textContent = catActiveMpName + '\'dan kategoriler çekiliyor...'; status.textContent = 'API bağlantısı kuruluyor...';
    if (typeof lucide !== 'undefined') lucide.createIcons();
    const fd = new FormData();
    fd.append('marketplace', catActiveMpSlug);
    fd.append('_csrf_token', CAT_CSRF);
    try {
        bar.style.width = '50%'; status.textContent = 'Kategoriler alınıyor...';
        const res = await fetch('/categories/sync-marketplace', { method: 'POST', body: fd });
        const data = await res.json(); bar.style.width = '100%';
        if (data.success) {
            title.textContent = '✅ Tamamlandı!';
            status.textContent = data.data?.message || data.message;
            catToast(data.data?.message || data.message, 'success');
            setTimeout(() => { box.classList.remove('show'); catLoadMpTree(); }, 1500);
        } else {
            title.textContent = '❌ Hata'; status.textContent = data.message || 'Bilinmeyen hata';
            bar.style.background = '#ef4444'; catToast(data.message || 'Hata', 'error');
        }
    } catch (e) { title.textContent = '❌ Bağlantı Hatası'; status.textContent = e.message; bar.style.background = '#ef4444'; catToast('Bağlantı hatası: ' + e.message, 'error'); }
}

async function catClearCache() {
    const ok = await catConfirm(catActiveMpName + ' kategori cache\'i silinecek. Devam etmek istiyor musunuz?', { title: 'Cache Temizle', icon: '🧹', iconClass: 'warn', okText: 'Temizle', okClass: 'primary' });
    if (!ok) return;
    const fd = new FormData();
    fd.append('marketplace', catActiveMpSlug);
    fd.append('_csrf_token', CAT_CSRF);
    try {
        const res = await fetch('/categories/clear-cache', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { catToast(data.data?.message || data.message, 'success'); catLoadMpTree(); }
        else { catToast(data.message, 'error'); }
    } catch (e) { catToast('Hata', 'error'); }
}

/* Overlay search */
let catWinSearchTimer;
document.getElementById('catWinSearch').addEventListener('input', function () {
    clearTimeout(catWinSearchTimer);
    catMpCurrentPage = 1;
    catWinSearchTimer = setTimeout(() => catLoadMpTree(), 400);
});
document.getElementById('catWinSearch').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(catWinSearchTimer); catMpCurrentPage = 1; catLoadMpTree(); }
});

/* ═══ ESC + Click-outside ═══ */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        if (document.getElementById('confirmModal').classList.contains('show')) return;
        if (document.getElementById('mapModal').classList.contains('show')) { catCloseMapModal(); return; }
        if (document.getElementById('catModal').classList.contains('show')) { catCloseModal(); return; }
        if (document.getElementById('catOverlay').classList.contains('active')) { catCloseWindow(); return; }
    }
});
document.getElementById('catOverlay').addEventListener('click', function (e) {
    if (e.target === this) catCloseWindow();
});

/* ═══ Eski Hepsiburada kategori listesi → satış durumuyla yeniden çek ═══ */
(function () {
    const btn = document.getElementById('catHbRefresh');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        const ok = await catConfirm('Hepsiburada kategori listesi satış durumu bilgisiyle yeniden çekilecek. İşlem bir dakika kadar sürebilir.', { title: 'Kategorileri Yenile', icon: '📥', iconClass: 'info', okText: 'Yenile', okClass: 'primary' });
        if (!ok) return;
        const box = document.getElementById('syncBox'), bar = document.getElementById('syncBarFill'), title = document.getElementById('syncTitle'), status = document.getElementById('syncStatus');
        btn.disabled = true;
        box.classList.add('show'); bar.style.width = '35%'; bar.style.background = '#2563eb';
        title.textContent = 'Hepsiburada kategorileri yenileniyor...'; status.textContent = 'Kategori listesi alınıyor, lütfen bekleyin...';
        const fd = new FormData();
        fd.append('marketplace', 'hepsiburada');
        fd.append('_csrf_token', CAT_CSRF);
        try {
            const res = await fetch('/categories/sync-marketplace', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CAT_CSRF } });
            const data = await res.json();
            bar.style.width = '100%';
            if (data.success) {
                const msg = (data.data && data.data.message) || data.message || 'Kategoriler yenilendi.';
                title.textContent = 'Tamamlandı'; status.textContent = msg;
                catToast(msg, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                title.textContent = 'Yenilenemedi'; status.textContent = data.message || 'Bilinmeyen hata'; bar.style.background = '#ef4444';
                catToast(data.message || 'Kategoriler yenilenemedi', 'error');
                btn.disabled = false;
            }
        } catch (e) {
            title.textContent = 'Bağlantı hatası'; status.textContent = 'Sunucuya ulaşılamadı, tekrar deneyin.'; bar.style.background = '#ef4444';
            btn.disabled = false;
        }
    });
})();

/* ═══ Init ═══ */
catLoadSystemTable();
</script>
