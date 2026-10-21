<?php
$marketplaces    = $data['marketplaces'] ?? [];
$mpInvoiceCounts = $data['mpInvoiceCounts'] ?? [];
$statusCounts    = $data['statusCounts'] ?? [];
$totalInvoices   = $data['totalInvoices'] ?? 0;
$csrf            = $data['_csrf'] ?? '';
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
$currentMp = '';
$canCancelInvoice = $can('invoices.cancel');
$canManageInvoiceSettings = $can('invoices.create') || $can('settings.edit');
$canViewCustomers = $can('customers.view');

$stMap = [
    'draft'     => ['l' => 'Taslak', 'c' => '#f59e0b', 'bg' => '#fffbeb'],
    'pending'   => ['l' => 'Beklemede', 'c' => '#3b82f6', 'bg' => '#eff6ff'],
    'sent'      => ['l' => 'Gönderildi', 'c' => '#10b981', 'bg' => '#f0fdf4'],
    'cancelled' => ['l' => 'İptal', 'c' => '#ef4444', 'bg' => '#fef2f2'],
    'error'     => ['l' => 'Hata', 'c' => '#dc2626', 'bg' => '#fef2f2'],
];

$flowMap = [
    'sale'   => ['l' => 'Satış', 'c' => '#2563eb', 'bg' => '#eff6ff', 'icon' => 'file-text'],
    'return' => ['l' => 'İade', 'c' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'rotate-ccw'],
];

$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63','idefix'=>'#1a1a2e'];
$mpBgs    = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff3ed','n11'=>'#faf5ff','amazon'=>'#fffbeb','pazarama'=>'#f0f9ff','pttavm'=>'#fffde7','ciceksepeti'=>'#fdf2f8','idefix'=>'#f1f5f9'];
?>
<style>
/* ================================================================
   INVOICES PAGE — OzyConnect Premium v3
   ================================================================ */

/* === Toolbar === */
.fi-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.fi-search{display:flex;align-items:center;gap:8px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 14px;width:260px;transition:border-color .15s}
.fi-search:focus-within{border-color:var(--primary)}
.fi-search input{border:none;outline:none;background:transparent;font-size:13px;width:100%;color:var(--text-primary);font-family:inherit}
.fi-search input::placeholder{color:var(--text-muted)}
.fi-sel{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);cursor:pointer;font-family:inherit;font-weight:500}
.fi-sel:focus{border-color:var(--primary);outline:none}
.fi-date{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);font-family:inherit;width:140px}
.fi-date:focus{border-color:var(--primary);outline:none}
.fi-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.fi-btn-primary{background:var(--primary);color:#fff}.fi-btn-primary:hover{opacity:.9}
.fi-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.fi-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.fi-btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}

/* === Marketplace Tiles Grid === */
.mp-grid{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.mp-grid>.mp-tile{flex:1 1 0;min-width:110px}
.mp-tile{
    position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;
    border:1.5px solid var(--border);background:var(--bg-card);
    transition:all .18s ease;min-height:80px;overflow:hidden;
}
.mp-tile:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.mp-tile:active{transform:translateY(0);box-shadow:0 0 0 1px var(--border)}
.mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.8;transition:opacity .15s}
.mp-tile:hover .mp-tile-logo{opacity:1}
.mp-tile-count{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;
    color:#fff;background:var(--mp-color,var(--primary));
    padding:0 6px;line-height:1;
}
.mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.mp-tile-inactive{opacity:.3;pointer-events:none;filter:grayscale(.7)}
.mp-tile-inactive .mp-tile-soon{
    position:absolute;bottom:4px;right:4px;font-size:8px;background:#ef4444;color:#fff;
    padding:1px 5px;border-radius:4px;font-weight:700;
}

/* === Üst Filtre Paneli (pazaryeri tab'leri + araç çubuğu — tek beyaz bütün) === */
.o-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);margin-bottom:20px;overflow:hidden}
.o-panel .fi-toolbar{margin:0;padding:12px 14px}
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

/* === Table Card === */
.fi-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card)}
.fi-table{width:100%;border-collapse:collapse}
.fi-table thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
.fi-table thead th.r{text-align:right}
.fi-table tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.fi-table tbody tr:last-child{border-bottom:none}
.fi-table tbody tr:hover{background:var(--bg-sidebar-hover)}
.fi-table td{padding:14px 16px;font-size:13px;vertical-align:middle}
.fi-no{font-weight:700;font-family:'Fira Code',monospace;color:var(--primary);font-size:13px;text-decoration:none}
.fi-no:hover{text-decoration:underline;cursor:pointer}
.fi-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;white-space:nowrap}
.fi-amount{font-weight:700;font-size:15px;color:var(--text-primary);white-space:nowrap}
.fi-actions{display:flex;gap:4px;flex-wrap:wrap}
.fi-ra{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer;transition:all .12s;white-space:nowrap;font-family:inherit}
.fi-ra:hover{border-color:var(--primary);color:var(--primary);background:var(--bg-sidebar-hover)}
.fi-ra-view{color:#06b6d4;border-color:#06b6d4}.fi-ra-view:hover{background:#ecfeff}
.fi-ra-dl{color:var(--primary);border-color:var(--primary)}.fi-ra-dl:hover{background:var(--bg-sidebar-hover)}
.fi-ra-cancel{color:#ef4444;border-color:#ef4444}.fi-ra-cancel:hover{background:#fef2f2}
.fi-mp-logo{height:20px;width:auto;max-width:80px;object-fit:contain;opacity:.8}
.fi-cust{font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:4px}
.fi-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}

/* === DataTables Overrides === */
.dataTables_wrapper{background:transparent!important}
.dataTables_wrapper .dataTables_processing{background:var(--bg-card)!important;color:var(--text-primary)!important;border:1px solid var(--border)!important;border-radius:10px!important;padding:12px 24px!important;box-shadow:var(--shadow-card)!important;font-size:13px!important;z-index:10!important}
table.dataTable{border-collapse:collapse!important}
table.dataTable thead th{border-bottom:1px solid var(--border)!important;cursor:pointer}
table.dataTable thead .sorting:after,table.dataTable thead .sorting_asc:after,table.dataTable thead .sorting_desc:after{opacity:.4;font-size:10px}
table.dataTable thead .sorting_asc:after,table.dataTable thead .sorting_desc:after{opacity:.9;color:var(--primary)}
table.dataTable tbody tr{background:transparent!important}
table.dataTable.no-footer{border-bottom:none!important}
table.dataTable td.dataTables_empty{padding:60px 20px!important;text-align:center;color:var(--text-muted);font-size:14px}
.dt-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px}
.dataTables_info{font-size:12px;color:var(--text-muted);padding:0!important}
.dataTables_paginate{padding:0!important}
.dataTables_paginate .paginate_button{min-width:32px;height:32px;display:inline-flex!important;align-items:center;justify-content:center;border-radius:8px!important;font-size:12px!important;font-weight:700;color:var(--text-secondary)!important;background:var(--bg-card)!important;border:1px solid var(--border)!important;margin:0 2px!important;padding:0 8px!important;transition:all .12s;cursor:pointer}
.dataTables_paginate .paginate_button:hover{border-color:var(--primary)!important;color:var(--primary)!important;background:var(--bg-sidebar-hover)!important}
.dataTables_paginate .paginate_button.current{background:var(--primary)!important;color:#fff!important;border-color:var(--primary)!important}
.dataTables_paginate .paginate_button.disabled{opacity:.3!important;pointer-events:none}
.dataTables_length,.dataTables_filter{display:none!important}

/* ================================================================
   MARKETPLACE OVERLAY WINDOW
   ================================================================ */
.mp-overlay{
    position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;
    display:none;flex-direction:column;
    background:rgba(0,0,0,.45);
    animation:mpOverlayIn .2s ease;
}
.mp-overlay.active{display:flex}
@keyframes mpOverlayIn{from{opacity:0}to{opacity:1}}

.mp-window{
    position:absolute;top:20px;left:20px;right:20px;bottom:20px;
    background:var(--bg-page,#f8fafc);border-radius:12px;
    box-shadow:0 0 0 1px var(--border);
    display:flex;flex-direction:column;overflow:hidden;
    animation:mpWindowIn .25s ease;
}
@keyframes mpWindowIn{from{transform:scale(.96) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}

.mp-win-header{
    display:flex;align-items:center;gap:16px;padding:16px 24px;
    border-bottom:2px solid var(--mp-color,var(--border));
    background:var(--bg-card);flex-shrink:0;
}
.mp-win-logo{height:32px;width:auto;max-width:160px;object-fit:contain}
.mp-win-title{font-size:18px;font-weight:600;color:var(--text-primary);flex:1}
.mp-win-stats{display:flex;gap:16px;align-items:center}
.mp-win-stat{
    display:flex;flex-direction:column;align-items:center;
    padding:6px 14px;border-radius:10px;background:var(--bg-input);
}
.mp-win-stat-val{font-size:16px;font-weight:600;color:var(--text-primary)}
.mp-win-stat-lbl{font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase}
.mp-win-close{
    width:40px;height:40px;border-radius:10px;border:1px solid var(--border);
    background:transparent;cursor:pointer;display:flex;align-items:center;
    justify-content:center;color:var(--text-muted);transition:all .15s;
}
.mp-win-close:hover{background:#fef2f2;color:#ef4444;border-color:#ef4444}

.mp-win-toolbar{
    display:flex;align-items:center;gap:10px;padding:12px 24px;
    background:var(--bg-card);border-bottom:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;
}

.mp-win-body{flex:1;overflow-y:auto;padding:0}
.mp-win-body .fi-card{border-radius:0;border-left:none;border-right:none;box-shadow:none}

/* === Invoice Detail Overlay === */
.inv-overlay{
    position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;
    display:none;align-items:center;justify-content:center;
    background:rgba(0,0,0,.5);
    animation:mpOverlayIn .2s ease;
}
.inv-overlay.active{display:flex}
.inv-popup{
    background:var(--bg-card);border-radius:12px;width:92%;max-width:960px;
    max-height:88vh;display:flex;flex-direction:column;overflow:hidden;
    box-shadow:0 0 0 1px var(--border);animation:mpWindowIn .25s ease;
}
.inv-popup-header{
    display:flex;align-items:center;gap:14px;padding:16px 24px;
    border-bottom:1px solid var(--border);background:var(--bg-card);flex-shrink:0;
}
.inv-popup-info{flex:1;display:flex;flex-direction:column;gap:2px}
.inv-popup-no{font-size:18px;font-weight:700;color:var(--text-primary);font-family:'Fira Code',monospace}
.inv-popup-meta{font-size:12px;color:var(--text-muted);display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.inv-popup-actions{display:flex;gap:8px;align-items:center}
.inv-popup-body{flex:1;overflow:auto;padding:0}
.inv-popup-body iframe{width:100%;height:100%;min-height:60vh;border:none}
.inv-popup-close{
    width:36px;height:36px;border-radius:10px;border:1px solid var(--border);
    background:transparent;cursor:pointer;display:flex;align-items:center;
    justify-content:center;color:var(--text-muted);transition:all .15s;
}
.inv-popup-close:hover{background:#fef2f2;color:#ef4444;border-color:#ef4444}

/* === Cancel Modal === */
.fi-cancel-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10001;align-items:center;justify-content:center}
.fi-cancel-bg.show{display:flex}
.fi-cancel-modal{background:var(--bg-card);border-radius:12px;width:420px;max-width:90%;box-shadow:0 0 0 1px var(--border);overflow:hidden}
.fi-cancel-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border)}
.fi-cancel-header h3{margin:0;font-size:15px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.fi-cancel-body{padding:20px}
.fi-cancel-ta{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;resize:vertical;min-height:60px;font-family:inherit;background:var(--bg-input);color:var(--text-primary);box-sizing:border-box}
.fi-cancel-ta:focus{border-color:var(--primary);outline:none}

/* === Responsive === */
@media(max-width:1200px){.mp-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){
    .mp-grid{grid-template-columns:repeat(2,1fr);gap:8px}
    .mp-tile{padding:12px 6px 10px;min-height:60px}
    .mp-window{top:10px;left:10px;right:10px;bottom:10px}
    .mp-win-stats{display:none}
    .inv-popup{width:96%;max-height:92vh}
}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<!-- İkincil sekme şeridi: Finans / Faturalar (Finans grubu alt-nav) -->
<?php $subnav = ['active' => '/invoices', 'items' => [
    ['u' => '/finance', 'l' => 'Finans'],
    ['u' => '/invoices', 'l' => 'Faturalar'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<!-- ================================================================
     SECTION 1: Üst Filtre Paneli (pazaryeri tab'leri + araç çubuğu — tek beyaz bütün)
     ================================================================ -->
<div class="o-panel">
<div class="mp-seg" id="mpSeg" role="tablist">
    <button type="button" class="mp-seg-tab<?= ((string) $currentMp === '') ? ' is-active' : '' ?>" data-mp-id="" onclick="setMpFilter('', this)">
        <svg class="mp-seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Tümü</span>
        <span class="mp-seg-count" data-mp-cnt="all"><?= (int) ($statusCounts['all'] ?? 0) ?></span>
    </button>
    <?php foreach ($marketplaces as $mp):
        $s = $mp['slug'] ?? '';
        $cnt = $mpInvoiceCounts[$mp['id']] ?? 0;
        // Kilitli: globalde pasif VEYA mağazanın bu pazaryerine entitlement'ı yok
        $isInactive = !$canMarketplace((int) $mp['id']);
        $nm = htmlspecialchars($mp['display_name'] ?? $s);
    ?>
    <?php if ($isInactive): ?>
    <span class="mp-seg-tab is-disabled" title="<?= $nm ?> · Pasif (yetki yok)">
        <img src="/public/assets/img/marketplaces/<?= $s ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $s ?>.jpg'" alt="<?= $nm ?>">
        <svg class="mp-seg-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </span>
    <?php else: ?>
    <button type="button" class="mp-seg-tab<?= ((string) $currentMp === (string) $mp['id']) ? ' is-active' : '' ?>" data-mp-id="<?= $mp['id'] ?>" onclick="setMpFilter('<?= $mp['id'] ?>', this)" title="<?= $nm ?>">
        <img src="/public/assets/img/marketplaces/<?= $s ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $s ?>.jpg'" alt="<?= $nm ?>">
        <span class="mp-seg-count" data-mp-cnt="<?= $mp['id'] ?>"><?= $cnt ?></span>
    </button>
    <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- ---- Aynı panelin alt satırı: arama / durum / akış / tarih / filtre + ayar butonları ---- -->
<div class="fi-toolbar">
    <div class="fi-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="dtSearch" placeholder="Fatura no, müşteri, sipariş no...">
    </div>
    <select id="dtStatus" class="fi-sel">
        <option value="">Tüm Durumlar (<?= $statusCounts['all'] ?? 0 ?>)</option>
        <?php foreach ($stMap as $k => $c): $cnt = $statusCounts[$k] ?? 0; ?>
        <option value="<?= $k ?>"><?= $c['l'] ?> (<?= $cnt ?>)</option>
        <?php endforeach; ?>
    </select>
    <select id="dtFlow" class="fi-sel">
        <option value="">Tüm Akışlar</option>
        <option value="sale">Satış Faturası</option>
        <option value="return">İade Faturası</option>
    </select>
    <input type="date" id="dtDateFrom" class="fi-date" placeholder="Başlangıç">
    <input type="date" id="dtDateTo" class="fi-date" placeholder="Bitiş">
    <button type="button" class="fi-btn fi-btn-primary fi-btn-sm" onclick="reloadTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
    <button type="button" class="fi-btn fi-btn-outline fi-btn-sm" onclick="clearFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
    <div style="margin-left:auto;display:flex;gap:8px">
        <?php if ($canManageInvoiceSettings): ?>
        <a href="/invoices/settings" class="fi-btn fi-btn-outline fi-btn-sm"><i data-lucide="settings" style="width:14px;height:14px"></i> Fatura Ayarları</a>
        <?php endif; ?>
    </div>
</div>
</div><!-- /.o-panel: pazaryeri tab'leri + filtre araç çubuğu tek beyaz bütün -->

<!-- ================================================================
     SECTION 3: Main Invoices Table
     ================================================================ -->
<div class="fi-card">
    <div style="overflow-x:auto">
    <table id="invoicesTable" class="fi-table" style="width:100%">
    <thead><tr>
        <th>Fatura</th>
        <th>Müşteri</th>
        <th>Sipariş</th>
        <th>Pazaryeri</th>
        <th class="r">Tutar</th>
        <th>Durum</th>
        <th>Tarih</th>
        <th>İşlemler</th>
    </tr></thead>
    <tbody></tbody>
    </table>
    </div>
    <div class="dt-footer">
        <div id="dtInfo"></div>
        <div id="dtPaginate"></div>
    </div>
</div>

<!-- ================================================================
     SECTION 4: Marketplace Overlay Window
     ================================================================ -->
<div class="mp-overlay" id="mpOverlay">
    <div class="mp-window" id="mpWindow">
        <div class="mp-win-header" id="mpWinHeader">
            <img class="mp-win-logo" id="mpWinLogo" src="" alt="">
            <div class="mp-win-title" id="mpWinTitle">Faturalar</div>
            <div class="mp-win-stats" id="mpWinStats"></div>
            <button class="mp-win-close" onclick="closeMpWindow()" title="Kapat (ESC)">
                <i data-lucide="x" style="width:20px;height:20px"></i>
            </button>
        </div>
        <div class="mp-win-toolbar">
            <div class="fi-search" style="width:220px">
                <i data-lucide="search" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0"></i>
                <input type="text" id="mpWinSearch" placeholder="Fatura no, müşteri...">
            </div>
            <select id="mpWinStatus" class="fi-sel">
                <option value="">Tüm Durumlar</option>
                <?php foreach ($stMap as $k => $c): ?>
                <option value="<?= $k ?>"><?= $c['l'] ?></option>
                <?php endforeach; ?>
            </select>
            <select id="mpWinFlow" class="fi-sel">
                <option value="">Tüm Akışlar</option>
                <option value="sale">Satış</option>
                <option value="return">İade</option>
            </select>
            <input type="date" id="mpWinDateFrom" class="fi-date" placeholder="Başlangıç">
            <input type="date" id="mpWinDateTo" class="fi-date" placeholder="Bitiş">
            <button type="button" class="fi-btn fi-btn-primary fi-btn-sm" onclick="reloadMpTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
            <button type="button" class="fi-btn fi-btn-outline fi-btn-sm" onclick="clearMpFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
        </div>
        <div class="mp-win-body">
            <div class="fi-card">
                <div style="overflow-x:auto">
                <table id="mpInvoicesTable" class="fi-table" style="width:100%">
                <thead><tr>
                    <th>Fatura</th>
                    <th>Müşteri</th>
                    <th>Sipariş</th>
                    <th class="r">Tutar</th>
                    <th>Durum</th>
                    <th>Tarih</th>
                    <th>İşlemler</th>
                </tr></thead>
                <tbody></tbody>
                </table>
                </div>
                <div class="dt-footer">
                    <div id="mpDtInfo"></div>
                    <div id="mpDtPaginate"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================
     SECTION 5: Invoice Detail Popup
     ================================================================ -->
<div class="inv-overlay" id="invOverlay">
    <div class="inv-popup">
        <div class="inv-popup-header">
            <div style="width:40px;height:40px;border-radius:10px;background:rgb(var(--brand) / .08);display:flex;align-items:center;justify-content:center;color:var(--primary);flex-shrink:0">
                <i data-lucide="file-text" style="width:20px;height:20px"></i>
            </div>
            <div class="inv-popup-info">
                <div class="inv-popup-no" id="invPopupNo"></div>
                <div class="inv-popup-meta" id="invPopupMeta"></div>
            </div>
            <div class="inv-popup-actions" id="invPopupActions"></div>
            <button class="inv-popup-close" onclick="closeInvPopup()" title="Kapat">
                <i data-lucide="x" style="width:18px;height:18px"></i>
            </button>
        </div>
        <div class="inv-popup-body">
            <iframe id="invFrame" src="about:blank"></iframe>
        </div>
    </div>
</div>

<!-- ================================================================
     SECTION 6: Cancel Modal
     ================================================================ -->
<?php if ($canCancelInvoice): ?>
<div class="fi-cancel-bg" id="cancelModal">
    <div class="fi-cancel-modal">
        <div class="fi-cancel-header">
            <h3><i data-lucide="x-circle" style="width:16px;height:16px;color:#ef4444"></i> <span id="cancelTitle">Fatura İptal</span></h3>
            <button type="button" class="inv-popup-close" onclick="closeCancel()" style="width:32px;height:32px"><i data-lucide="x" style="width:16px;height:16px"></i></button>
        </div>
        <div class="fi-cancel-body">
            <form method="POST" id="cancelForm">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <p style="margin:0 0 12px;font-size:13px;color:var(--text-muted)">Bu faturayı iptal etmek istediğinize emin misiniz?</p>
                <textarea name="reason" class="fi-cancel-ta" placeholder="İptal sebebi (opsiyonel)..."></textarea>
                <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
                    <button type="button" class="fi-btn fi-btn-outline fi-btn-sm" onclick="closeCancel()">Vazgeç</button>
                    <button type="submit" class="fi-btn fi-btn-sm" style="background:#ef4444;color:#fff"><i data-lucide="x-circle" style="width:14px;height:14px"></i> İptal Et</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ================================================================
     SECTION 6b: SMS Onay (İmza) Modal
     ================================================================ -->
<div id="fiSmsModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1001;align-items:center;justify-content:center">
    <div style="background:var(--bg-card);border-radius:16px;padding:26px;width:380px;max-width:92vw">
        <h3 style="margin:0 0 6px;font-size:16px;font-weight:700;color:var(--text-primary)" id="fiSmsTitle">
            SMS ile Fatura Onayı
        </h3>
        <p id="fiSmsInfo" style="margin:0 0 14px;font-size:13px;color:var(--text-secondary);line-height:1.5">Kod gönderiliyor…</p>
        <div id="fiSmsCodeArea" style="display:none">
            <input type="text" id="fiSmsCode" inputmode="numeric" maxlength="8" placeholder="SMS kodu"
                   style="width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:10px;font-size:18px;letter-spacing:4px;text-align:center;font-weight:700;background:var(--bg-body);color:var(--text-primary)">
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">Kod GİB'de kayıtlı telefona gönderilir; geçerlilik süresi ~3 dakikadır.</div>
        </div>
        <div id="fiSmsMsg" style="display:none;margin-top:12px;padding:10px 12px;border-radius:10px;font-size:12px;line-height:1.5"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
            <button type="button" class="fi-btn fi-btn-outline fi-btn-sm" onclick="fiSmsClose()">Vazgeç</button>
            <button type="button" class="fi-btn fi-btn-sm" id="fiSmsVerifyBtn" style="display:none;background:#10b981;color:#fff" onclick="fiSmsVerify()">Onayla ve İmzala</button>
        </div>
    </div>
</div>

<!-- ================================================================
     SCRIPTS
     ================================================================ -->
<script>
const CSRF = <?= json_encode($csrf) ?>;
const ST_MAP = <?= json_encode($stMap, JSON_UNESCAPED_UNICODE) ?>;
const FLOW_MAP = <?= json_encode($flowMap, JSON_UNESCAPED_UNICODE) ?>;
const CAN_CANCEL = <?= $canCancelInvoice ? 'true' : 'false' ?>;
const CAN_VIEW_CUSTOMERS = <?= $canViewCustomers ? 'true' : 'false' ?>;
const MP_COLORS = <?= json_encode($mpColors, JSON_UNESCAPED_UNICODE) ?>;

/* Aktif pazaryeri tab filtresi (boş = Tümü) */
let MAIN_MARKETPLACE_ID = <?= json_encode((string) $currentMp) ?>;

/* === Helpers === */
function esc(s){ const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fmtDate(s){
    if(!s) return '—';
    const d=new Date(s.replace(' ','T'));
    if(isNaN(d)) return s;
    return d.toLocaleDateString('tr-TR',{day:'2-digit',month:'2-digit',year:'numeric'})
        +' '+d.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});
}
function fmtMoney(n){ return '₺'+n.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

/* === Row data store === */
const INV_ROWS = new Map();

/* === Column renderers === */
function renderInvoiceNo(d,t,r){
    INV_ROWS.set(r.id, r);
    const flow = FLOW_MAP[r.invoice_flow] || FLOW_MAP['sale'];
    let related = '';
    if(r.invoice_flow === 'return' && (r.related_invoice_series || r.related_invoice_seq)){
        related = '<div style="font-size:10px;color:var(--text-muted);margin-top:2px">İlgili: #'+esc(r.related_invoice_series+r.related_invoice_seq)+'</div>';
    }
    return '<div style="display:flex;flex-direction:column;gap:4px">'
        +'<span class="fi-no" onclick="viewInv('+r.id+')">'
        +esc(r.series+r.sequence_no)+'</span>'
        +'<span class="fi-badge" style="color:'+flow.c+';background:'+flow.bg+';width:max-content"><i data-lucide="'+(flow.icon||'file-text')+'" style="width:11px;height:11px"></i> '+flow.l+'</span>'
        +related+'</div>';
}
function renderCustomer(d,t,r){
    let h = '<div>';
    if(CAN_VIEW_CUSTOMERS && r.order_customer_id){
        h += '<a href="/customers/'+r.order_customer_id+'" target="_blank" class="fi-cust" style="color:var(--text-primary);font-weight:700;text-decoration:none"><i data-lucide="user" style="width:12px;height:12px"></i> '+esc(r.customer_name||'—')+'</a>';
    } else {
        h += '<div class="fi-cust" style="font-weight:700">'+esc(r.customer_name||'—')+'</div>';
    }
    if(r.customer_tax_no){
        h += '<div style="font-size:10px;color:var(--text-muted);font-family:monospace;margin-top:2px">'+esc(r.customer_tax_no)+'</div>';
    }
    h += '</div>';
    return h;
}
function renderOrder(d,t,r){
    return '<a href="/orders/'+(r.order_id||'')+'" class="fi-no" style="font-size:12px">#'+esc(r.marketplace_order_id||'')+'</a>';
}
function renderMp(d,t,r){
    const slug = r.marketplace_slug||'';
    return '<img class="fi-mp-logo" src="/public/assets/img/marketplaces/'+slug+'.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/'+slug+'.jpg\'" title="'+esc(r.marketplace_name||'')+'">';
}
function renderAmount(d){ return '<span class="fi-amount">'+fmtMoney(d)+'</span>'; }
function renderStatus(d){
    const s = ST_MAP[d] || {l:d, c:'#64748b', bg:'#f1f5f9'};
    return '<span class="fi-badge" style="color:'+s.c+';background:'+s.bg+'">'+s.l+'</span>';
}
function renderDate(d){ return '<span style="font-size:12px;color:var(--text-muted);white-space:nowrap">'+fmtDate(d)+'</span>'; }
function renderActions(d,t,r){
    const invNo = esc(r.series+r.sequence_no);
    let h = '<div class="fi-actions">';
    if(r.status === 'draft'){
        h += '<button class="fi-ra" style="background:#10b981;color:#fff;border-color:#10b981" onclick="fiSmsStart('+r.id+',\''+invNo+'\')"><i data-lucide="smartphone" style="width:13px;height:13px"></i> SMS Onay</button>';
    }
    h += '<button class="fi-ra fi-ra-view" onclick="viewInv('+r.id+')"><i data-lucide="eye" style="width:13px;height:13px"></i> Görüntüle</button>';
    h += '<a href="/invoices/'+r.id+'/download" class="fi-ra fi-ra-dl"><i data-lucide="download" style="width:13px;height:13px"></i> İndir</a>';
    if(CAN_CANCEL && r.status !== 'cancelled'){
        h += '<button class="fi-ra fi-ra-cancel" onclick="openCancel('+r.id+',\''+invNo+'\')"><i data-lucide="x-circle" style="width:13px;height:13px"></i> İptal</button>';
    }
    h += '</div>';
    return h;
}

/* ================================================================
   MAIN TABLE
   ================================================================ */
const table = $('#invoicesTable').DataTable({
    processing: true,
    serverSide: true,
    ajax: {
        url: '/invoices/ajax',
        data: function(d) {
            d.marketplace_id = MAIN_MARKETPLACE_ID || '';
            d.status = document.getElementById('dtStatus').value;
            d.invoice_flow = document.getElementById('dtFlow').value;
            d.date_from = document.getElementById('dtDateFrom').value;
            d.date_to = document.getElementById('dtDateTo').value;
        },
        dataSrc: function(json) {
            if(json.statusCounts) updateStatusCounts(json.statusCounts);
            if(json.mpInvoiceCounts) updateMpTileCounts(json.mpInvoiceCounts);
            return json.data;
        }
    },
    columns: [
        {data:'series',       render:renderInvoiceNo, orderable:true},
        {data:'customer_name',render:renderCustomer},
        {data:'marketplace_order_id', render:renderOrder},
        {data:'marketplace_slug',     render:renderMp, className:'center'},
        {data:'total',        render:renderAmount, className:'r'},
        {data:'status',       render:renderStatus},
        {data:'issued_at',    render:renderDate},
        {data:null,           render:renderActions, orderable:false, searchable:false}
    ],
    order: [[6, 'desc']],
    pageLength: 25,
    dom: 'rt',
    language: {
        processing: '<div style="display:flex;align-items:center;gap:8px"><div style="width:16px;height:16px;border:2px solid rgba(37,99,235,.2);border-top-color:#2563eb;border-radius:50%;animation:spin .6s linear infinite"></div> Yükleniyor…</div>',
        emptyTable: '<div class="fi-empty"><i data-lucide="file-text" style="width:48px;height:48px;margin:0 auto 12px;display:block"></i>Fatura bulunamadı</div>',
        zeroRecords: '<div class="fi-empty"><i data-lucide="search" style="width:40px;height:40px;margin:0 auto 12px;display:block"></i>Aramanızla eşleşen fatura yok</div>',
        info: '_TOTAL_ faturadan _START_-_END_ arası',
        infoEmpty: 'Fatura yok',
        paginate: {first:'«',last:'»',next:'›',previous:'‹'}
    },
    drawCallback: function(){
        if(typeof lucide!=='undefined') lucide.createIcons();
        $('#invoicesTable_info').appendTo('#dtInfo');
        $('#invoicesTable_paginate').appendTo('#dtPaginate');
    }
});

/* Search debounce */
let searchTimer;
document.addEventListener('DOMContentLoaded', function() {
    const dtSearch = document.getElementById('dtSearch');
    if (dtSearch) {
        dtSearch.addEventListener('input', function(){
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { table.search(this.value).draw(); }, 400);
        });
    }
});

function reloadTable(){ table.ajax.reload(); }
function clearFilters(){
    document.getElementById('dtSearch').value = '';
    document.getElementById('dtStatus').value = '';
    document.getElementById('dtFlow').value = '';
    document.getElementById('dtDateFrom').value = '';
    document.getElementById('dtDateTo').value = '';
    // pazaryeri tabını da Tümü'ne sıfırla
    MAIN_MARKETPLACE_ID = '';
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    const allTab = document.querySelector('#mpSeg [data-mp-id=""]');
    if (allTab) allTab.classList.add('is-active');
    table.search('').ajax.reload();
}

/* Pazaryeri tab filtresi — ana tabloyu yerinde süzer (overlay yok).
   marketplace_id her ajax'ta (sayfalama dahil) gönderildiği için tab aktifliği sayfa değişince korunur. */
function setMpFilter(mpId, el){
    mpId = mpId || '';
    // aynı (Tümü dışı) aktif taba tekrar tıkla → seçim kalkar, Tümü'ne dön
    if (el.classList.contains('is-active') && mpId !== '') {
        return setMpFilter('', document.querySelector('#mpSeg [data-mp-id=""]'));
    }
    MAIN_MARKETPLACE_ID = mpId;
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    el.classList.add('is-active');
    table.ajax.reload(); // filtre değişti → 1. sayfaya döner
}

function updateStatusCounts(sc){
    const allCnt = sc.all || 0;
    const opts = document.getElementById('dtStatus').options;
    opts[0].text = 'Tüm Durumlar ('+allCnt+')';
    for(let i=1;i<opts.length;i++){
        const key = opts[i].value;
        const cnt = sc[key] || 0;
        const lbl = (ST_MAP[key]||{}).l || key;
        opts[i].text = lbl+' ('+cnt+')';
    }
}
function updateMpTileCounts(mc){
    mc = mc || {};
    // Tümü rozeti = tüm pazaryeri sayılarının toplamı; her pazaryeri rozeti kendi anahtarını gösterir
    let total = 0;
    Object.keys(mc).forEach(k => { total += (mc[k] || 0); });
    document.querySelectorAll('#mpSeg [data-mp-cnt]').forEach(badge => {
        const key = badge.dataset.mpCnt;
        badge.textContent = key === 'all' ? total : (mc[key] || 0);
    });
}

/* ================================================================
   MARKETPLACE OVERLAY WINDOW
   ================================================================ */
let mpTable = null;
let currentMpId = null;

function openMpWindow(mpId, slug, name, color){
    currentMpId = mpId;
    const el = document.getElementById('mpOverlay');
    const hdr = document.getElementById('mpWinHeader');
    hdr.style.borderBottomColor = color;
    document.getElementById('mpWinLogo').src = '/public/assets/img/marketplaces/'+slug+'.png';
    document.getElementById('mpWinTitle').textContent = 'Faturalar';
    el.classList.add('active');
    document.body.style.overflow = 'hidden';

    if(mpTable){
        mpTable.destroy();
        $('#mpInvoicesTable').empty();
        $('#mpInvoicesTable').html('<thead><tr><th>Fatura</th><th>Müşteri</th><th>Sipariş</th><th class="r">Tutar</th><th>Durum</th><th>Tarih</th><th>İşlemler</th></tr></thead><tbody></tbody>');
    }

    mpTable = $('#mpInvoicesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/invoices/ajax',
            data: function(d){
                d.marketplace_id = mpId;
                d.status = document.getElementById('mpWinStatus').value;
                d.invoice_flow = document.getElementById('mpWinFlow').value;
                d.date_from = document.getElementById('mpWinDateFrom').value;
                d.date_to = document.getElementById('mpWinDateTo').value;
            },
            dataSrc: function(json){
                // Update overlay stats
                const sc = json.statusCounts || {};
                let statsHtml = '';
                statsHtml += '<div class="mp-win-stat"><div class="mp-win-stat-val">'+(sc.all||0)+'</div><div class="mp-win-stat-lbl">Toplam</div></div>';
                statsHtml += '<div class="mp-win-stat" style="color:#f59e0b"><div class="mp-win-stat-val">'+(sc.draft||0)+'</div><div class="mp-win-stat-lbl">Taslak</div></div>';
                statsHtml += '<div class="mp-win-stat" style="color:#10b981"><div class="mp-win-stat-val">'+(sc.sent||0)+'</div><div class="mp-win-stat-lbl">Gönderildi</div></div>';
                statsHtml += '<div class="mp-win-stat" style="color:#ef4444"><div class="mp-win-stat-val">'+(sc.cancelled||0)+'</div><div class="mp-win-stat-lbl">İptal</div></div>';
                document.getElementById('mpWinStats').innerHTML = statsHtml;
                return json.data;
            }
        },
        columns: [
            {data:'series',       render:renderInvoiceNo, orderable:true},
            {data:'customer_name',render:renderCustomer},
            {data:'marketplace_order_id', render:renderOrder},
            {data:'total',        render:renderAmount, className:'r'},
            {data:'status',       render:renderStatus},
            {data:'issued_at',    render:renderDate},
            {data:null,           render:renderActions, orderable:false, searchable:false}
        ],
        order: [[5, 'desc']],
        pageLength: 25,
        dom: 'rt',
        language: {
            processing: '<div style="display:flex;align-items:center;gap:8px"><div style="width:16px;height:16px;border:2px solid rgba(37,99,235,.2);border-top-color:#2563eb;border-radius:50%;animation:spin .6s linear infinite"></div> Yükleniyor…</div>',
            emptyTable: '<div class="fi-empty"><i data-lucide="file-text" style="width:40px;height:40px;margin:0 auto 10px;display:block"></i>Bu pazaryerinde fatura yok</div>',
            zeroRecords: '<div class="fi-empty"><i data-lucide="search" style="width:36px;height:36px;margin:0 auto 10px;display:block"></i>Sonuç bulunamadı</div>',
            info: '_TOTAL_ faturadan _START_-_END_ arası',
            infoEmpty: 'Fatura yok',
            paginate: {first:'«',last:'»',next:'›',previous:'‹'}
        },
        drawCallback: function(){
            if(typeof lucide!=='undefined') lucide.createIcons();
            $('#mpDtInfo').empty();
            $('#mpDtPaginate').empty();
            $('#mpInvoicesTable_info').appendTo('#mpDtInfo');
            $('#mpInvoicesTable_paginate').appendTo('#mpDtPaginate');
        }
    });

    if(typeof lucide!=='undefined') lucide.createIcons();
}

function closeMpWindow(){
    document.getElementById('mpOverlay').classList.remove('active');
    document.body.style.overflow = '';
    currentMpId = null;
}
function reloadMpTable(){ if(mpTable) mpTable.ajax.reload(); }
function clearMpFilters(){
    document.getElementById('mpWinSearch').value = '';
    document.getElementById('mpWinStatus').value = '';
    document.getElementById('mpWinFlow').value = '';
    document.getElementById('mpWinDateFrom').value = '';
    document.getElementById('mpWinDateTo').value = '';
    if(mpTable) mpTable.search('').draw();
}

// Overlay search — tek seferde bağla
let mpSearchTimer;
document.addEventListener('DOMContentLoaded', function() {
    const mpWinSearch = document.getElementById('mpWinSearch');
    if (mpWinSearch) {
        mpWinSearch.addEventListener('input', function(){
            clearTimeout(mpSearchTimer);
            mpSearchTimer = setTimeout(() => { if(mpTable) mpTable.search(this.value).draw(); }, 400);
        });
    }
});

/* ================================================================
   INVOICE DETAIL POPUP
   ================================================================ */
let currentInvData = null;

function viewInv(id){
    const r = INV_ROWS.get(id);
    if(!r) return;
    openInvPopup(id, (r.series||'')+(r.sequence_no||''), r);
}

function openInvPopup(id, no, rowData){
    currentInvData = rowData;
    document.getElementById('invPopupNo').textContent = '#' + no;

    // Meta info
    const flow = FLOW_MAP[rowData.invoice_flow] || FLOW_MAP['sale'];
    const st = ST_MAP[rowData.status] || {l:rowData.status, c:'#64748b', bg:'#f1f5f9'};
    let meta = '<span class="fi-badge" style="color:'+flow.c+';background:'+flow.bg+'"><i data-lucide="'+(flow.icon||'file-text')+'" style="width:11px;height:11px"></i> '+flow.l+'</span>';
    meta += '<span class="fi-badge" style="color:'+st.c+';background:'+st.bg+'">'+st.l+'</span>';
    meta += '<span style="font-size:12px;color:var(--text-muted)">'+fmtDate(rowData.issued_at)+'</span>';
    meta += '<span style="font-size:14px;font-weight:700;color:var(--text-primary)">'+fmtMoney(rowData.total)+'</span>';
    if(rowData.marketplace_slug){
        meta += '<img src="/public/assets/img/marketplaces/'+rowData.marketplace_slug+'.png" style="height:18px;width:auto;object-fit:contain" onerror="this.style.display=\'none\'">';
    }
    document.getElementById('invPopupMeta').innerHTML = meta;

    // Action buttons
    let acts = '';
    if(rowData.status === 'draft'){
        acts += '<button class="fi-btn fi-btn-sm" style="background:#10b981;color:#fff;font-size:12px" onclick="closeInvPopup();fiSmsStart('+id+',\''+esc(no)+'\')"><i data-lucide="smartphone" style="width:14px;height:14px"></i> SMS Onay</button>';
    }
    acts += '<a href="/invoices/'+id+'/download" class="fi-btn fi-btn-sm fi-btn-primary" style="font-size:12px"><i data-lucide="download" style="width:14px;height:14px"></i> İndir</a>';
    acts += '<button class="fi-btn fi-btn-sm fi-btn-outline" style="font-size:12px" onclick="printInvFrame()"><i data-lucide="printer" style="width:14px;height:14px"></i> Yazdır</button>';
    if(CAN_CANCEL && rowData.status !== 'cancelled'){
        acts += '<button class="fi-btn fi-btn-sm" style="background:#ef4444;color:#fff;font-size:12px" onclick="closeInvPopup();openCancel('+id+',\''+esc(no)+'\')"><i data-lucide="x-circle" style="width:14px;height:14px"></i> İptal</button>';
    }
    document.getElementById('invPopupActions').innerHTML = acts;

    // Load iframe
    document.getElementById('invFrame').src = '/invoices/'+id+'/html';
    document.getElementById('invOverlay').classList.add('active');
    if(typeof lucide!=='undefined') lucide.createIcons();
}

function closeInvPopup(){
    document.getElementById('invOverlay').classList.remove('active');
    document.getElementById('invFrame').src = 'about:blank';
    currentInvData = null;
}

function printInvFrame(){
    const frame = document.getElementById('invFrame');
    if(frame && frame.contentWindow) frame.contentWindow.print();
}

/* ================================================================
   CANCEL MODAL
   ================================================================ */
function openCancel(id, no){
    document.getElementById('cancelTitle').textContent = 'Fatura İptal — #' + no;
    document.getElementById('cancelForm').action = '/invoices/' + id + '/cancel';
    document.getElementById('cancelModal').classList.add('show');
}
function closeCancel(){
    document.getElementById('cancelModal').classList.remove('show');
}

/* ================================================================
   SMS ONAY (İMZA) MODAL — taslak faturayı GİB SMS koduyla imzalar
   ================================================================ */
var fiSmsInvId = 0;

function fiSmsMsg(text, ok){
    const el = document.getElementById('fiSmsMsg');
    el.style.display = 'block';
    el.style.background = ok ? '#f0fdf4' : '#fef2f2';
    el.style.color = ok ? '#166534' : '#991b1b';
    el.textContent = text;
}

function fiSmsClose(){
    document.getElementById('fiSmsModal').style.display = 'none';
}

function fiSmsStart(id, no){
    fiSmsInvId = id;
    document.getElementById('fiSmsModal').style.display = 'flex';
    document.getElementById('fiSmsTitle').textContent = 'SMS ile Fatura Onayı — #' + no;
    document.getElementById('fiSmsInfo').textContent = 'GİB\'de kayıtlı telefona kod gönderiliyor…';
    document.getElementById('fiSmsCodeArea').style.display = 'none';
    document.getElementById('fiSmsVerifyBtn').style.display = 'none';
    document.getElementById('fiSmsMsg').style.display = 'none';

    fetch('/invoices/' + id + '/sign-sms-start', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: '_csrf_token=' + encodeURIComponent(CSRF)
    }).then(r => r.json()).then(j => {
        if(j.success){
            document.getElementById('fiSmsInfo').textContent = j.message || 'Kod gönderildi. Gelen kodu girin.';
            document.getElementById('fiSmsCodeArea').style.display = 'block';
            document.getElementById('fiSmsVerifyBtn').style.display = 'inline-flex';
            document.getElementById('fiSmsCode').value = '';
            document.getElementById('fiSmsCode').focus();
        } else {
            document.getElementById('fiSmsInfo').textContent = '';
            fiSmsMsg(j.message || 'SMS gönderilemedi', false);
        }
    }).catch(() => fiSmsMsg('Bağlantı hatası — tekrar deneyin', false));
}

function fiSmsVerify(){
    const code = document.getElementById('fiSmsCode').value.trim();
    if(!code){ fiSmsMsg('SMS kodunu girin', false); return; }
    const btn = document.getElementById('fiSmsVerifyBtn');
    btn.disabled = true;

    fetch('/invoices/' + fiSmsInvId + '/sign-sms-verify', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: '_csrf_token=' + encodeURIComponent(CSRF) + '&code=' + encodeURIComponent(code)
    }).then(r => r.json()).then(j => {
        btn.disabled = false;
        fiSmsMsg(j.message || (j.success ? 'İmzalandı' : 'Doğrulanamadı'), !!j.success);
        if(j.success){
            setTimeout(() => { fiSmsClose(); table.ajax.reload(null, false); }, 1500);
        }
    }).catch(() => { btn.disabled = false; fiSmsMsg('Bağlantı hatası — tekrar deneyin', false); });
}

/* ================================================================
   KEYBOARD SHORTCUTS
   ================================================================ */
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        if(document.getElementById('fiSmsModal')?.style.display === 'flex'){
            fiSmsClose();
        } else if(document.getElementById('invOverlay').classList.contains('active')){
            closeInvPopup();
        } else if(document.getElementById('cancelModal')?.classList.contains('show')){
            closeCancel();
        } else if(document.getElementById('mpOverlay').classList.contains('active')){
            closeMpWindow();
        }
    }
    if(e.key === 'Enter' && document.getElementById('fiSmsModal')?.style.display === 'flex'){
        const vb = document.getElementById('fiSmsVerifyBtn');
        if(vb.style.display !== 'none' && !vb.disabled){ fiSmsVerify(); }
    }
});

// Click outside to close
document.addEventListener('DOMContentLoaded', function() {
    const mpOverlay = document.getElementById('mpOverlay');
    const invOverlay = document.getElementById('invOverlay');
    const cancelModal = document.getElementById('cancelModal');
    
    if (mpOverlay) {
        mpOverlay.addEventListener('click', function(e){ if(e.target===this) closeMpWindow(); });
    }
    if (invOverlay) {
        invOverlay.addEventListener('click', function(e){ if(e.target===this) closeInvPopup(); });
    }
    if (cancelModal) {
        cancelModal.addEventListener('click', function(e){ if(e.target===this) closeCancel(); });
    }
});
</script>
