<?php
$filters      = $data['filters'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$statusCounts = $data['statusCounts'] ?? [];
$mpOrderCounts = $data['mpOrderCounts'] ?? [];
$currentStatus = $filters['status'] ?? '';
$currentMp     = $filters['marketplace_id'] ?? '';
$allCount      = $statusCounts['all'] ?? 0;
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canCreateInvoice = $can('invoices.create');
$canSendInvoice = $can('invoices.send');
$canArchiveOrder = $can('orders.archive');
$canTriggerSync = $can('sync.trigger');
$canShipAction = $can('orders.ship') ?? true;

$stMap = [
    'beklemede'=>['l'=>'Beklemede','c'=>'#94a3b8','bg'=>'#f1f5f9'],
    'yeni'=>['l'=>'Yeni','c'=>'#3b82f6','bg'=>'#eff6ff'],
    'hazirlaniyor'=>['l'=>'Hazırlanıyor','c'=>'#f59e0b','bg'=>'#fffbeb'],
    'faturalandi'=>['l'=>'Faturalandı','c'=>'#06b6d4','bg'=>'#ecfeff'],
    'kargoda'=>['l'=>'Kargoda','c'=>'#4B8AF5','bg'=>'#f5f3ff'],
    'teslim_noktasinda'=>['l'=>'T. Noktasında','c'=>'#1967F2','bg'=>'#eef2ff'],
    'teslim_edildi'=>['l'=>'Teslim Edildi','c'=>'#10b981','bg'=>'#f0fdf4'],
    'teslim_edilemedi'=>['l'=>'Teslim Edilemedi','c'=>'#f97316','bg'=>'#fff7ed'],
    'iptal'=>['l'=>'İptal','c'=>'#ef4444','bg'=>'#fef2f2'],
    'iade'=>['l'=>'İade','c'=>'#f97316','bg'=>'#fff7ed'],
];
$csrf = $data['_csrf'] ?? '';
$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63'];
$mpBgs    = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff3ed','n11'=>'#faf5ff','amazon'=>'#fffbeb','pazarama'=>'#f0f9ff','pttavm'=>'#fffde7','ciceksepeti'=>'#fdf2f8'];
?>
<style>
/* ================================================================
   ORDERS PAGE
   ================================================================ */

/* === Toolbar === */
.o-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.o-search{display:flex;align-items:center;gap:8px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 14px;width:260px;transition:border-color .15s}
.o-search:focus-within{border-color:var(--primary)}
.o-search input{border:none;outline:none;background:transparent;font-size:13px;width:100%;color:var(--text-primary);font-family:inherit}
.o-search input::placeholder{color:var(--text-muted)}
.o-sel{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);cursor:pointer;font-family:inherit;font-weight:500}
.o-sel:focus{border-color:var(--primary);outline:none}
.o-date{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);font-family:inherit;width:140px}
.o-date:focus{border-color:var(--primary);outline:none}
.o-actions{margin-left:auto;display:flex;gap:8px}
.o-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit}
.o-btn-primary{background:var(--primary);color:#fff}.o-btn-primary:hover{opacity:.9}
.o-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.o-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.o-btn-success{background:#10b981;color:#fff}.o-btn-success:hover{background:#059669}
.o-btn-danger{background:#ef4444;color:#fff}.o-btn-danger:hover{background:#dc2626}
.o-btn-warning{background:#f59e0b;color:#fff}.o-btn-warning:hover{background:#d97706}
.o-btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}

/* === Marketplace Tiles Grid === */
.mp-grid{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.mp-grid>.mp-tile{flex:1 1 0;min-width:110px}
.mp-tile{
    position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;
    border:1.5px solid var(--border);background:var(--bg-card);
    transition:all .18s ease;min-height:80px;overflow:hidden;
}
.mp-tile:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.1);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.mp-tile:active{transform:translateY(0);box-shadow:0 2px 6px rgba(0,0,0,.06)}
.mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.8;transition:opacity .15s}
.mp-tile:hover .mp-tile-logo{opacity:1}
.mp-tile-count{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;
    color:#fff;background:var(--mp-color,var(--primary));
    padding:0 6px;line-height:1;
}
.mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.mp-tile-label{font-size:10px;color:var(--text-muted);font-weight:600;letter-spacing:.2px}
.mp-tile-inactive{opacity:.3;pointer-events:none;filter:grayscale(.7)}
.mp-tile-inactive .mp-tile-soon{
    position:absolute;bottom:4px;right:4px;font-size:8px;background:#ef4444;color:#fff;
    padding:1px 5px;border-radius:4px;font-weight:700;
}

/* === Table Card === */
.o-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow-card)}
.o-table{width:100%;border-collapse:collapse}
.o-table thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
.o-table tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.o-table tbody tr:last-child{border-bottom:none}
.o-table tbody tr:hover{background:var(--bg-sidebar-hover)}
.o-table td{padding:14px 16px;font-size:13px;vertical-align:middle}
.o-id{font-weight:700;font-family:'Fira Code',monospace;font-size:13px;color:var(--primary);text-decoration:none}
.o-id:hover{text-decoration:underline}
.o-date-sm{font-size:11px;color:var(--text-muted);margin-top:2px}
.o-customer{font-size:12px;color:var(--text-secondary);margin-top:3px;display:flex;align-items:center;gap:4px}
.o-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.o-amount{font-weight:700;font-size:15px;color:var(--text-primary);white-space:nowrap}
.o-cargo{font-size:12px;color:var(--text-secondary);line-height:1.5}
.o-cargo-no{font-family:monospace;font-size:11px;color:var(--text-muted)}
.o-row-actions{display:flex;gap:4px;flex-wrap:wrap}
.o-ra{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer;transition:all .12s;white-space:nowrap;font-family:inherit}
.o-ra:hover{border-color:var(--primary);color:var(--primary);background:var(--bg-sidebar-hover)}
.o-ra-inv{color:#10b981;border-color:#10b981}.o-ra-inv:hover{background:#f0fdf4}
.o-ra-warn{color:#f59e0b;border-color:#f59e0b}.o-ra-warn:hover{background:#fffbeb}
.o-bulk{display:none;align-items:center;gap:12px;padding:10px 16px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:13px;font-weight:700;color:#1d4ed8}
.o-bulk.show{display:flex}
.o-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.o-empty i{display:block;margin-bottom:8px}
.o-inv-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700;white-space:nowrap}
.o-inv-done{background:#f0fdf4;color:#10b981}
.o-inv-sent{background:#eff6ff;color:#3b82f6}
.o-inv-pending{background:#fef3c7;color:#f59e0b}
.o-archive-toggle{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:var(--bg-card);color:var(--text-secondary);transition:all .12s;font-family:inherit}
.o-archive-toggle:hover{border-color:var(--primary);color:var(--primary)}
.o-archive-toggle.active{background:var(--primary-bg);color:var(--primary);border-color:var(--primary)}
.o-mp-logo-sm{height:18px;width:auto;object-fit:contain;vertical-align:middle}

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
    background:rgba(0,0,0,.45);backdrop-filter:blur(4px);
    animation:mpOverlayIn .2s ease;
}
.mp-overlay.active{display:flex}
@keyframes mpOverlayIn{from{opacity:0}to{opacity:1}}

.mp-window{
    position:absolute;top:20px;left:20px;right:20px;bottom:20px;
    background:var(--bg-page,#f8fafc);border-radius:16px;
    box-shadow:0 25px 60px rgba(0,0,0,.3);
    display:flex;flex-direction:column;overflow:hidden;
    animation:mpWindowIn .25s ease;
}
@keyframes mpWindowIn{from{transform:scale(.96) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}

/* Window header */
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

/* Window toolbar */
.mp-win-toolbar{
    display:flex;align-items:center;gap:10px;padding:12px 24px;
    background:var(--bg-card);border-bottom:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;
}

/* Window body */
.mp-win-body{flex:1;overflow-y:auto;padding:0}
.mp-win-body .o-card{border-radius:0;border-left:none;border-right:none;box-shadow:none}

/* Responsive */
@media(max-width:1200px){
    .mp-grid{grid-template-columns:repeat(4,1fr)}
}
@media(max-width:768px){
    .mp-grid{grid-template-columns:repeat(2,1fr);gap:8px}
    .mp-tile{padding:12px 6px 10px;min-height:60px}
    .mp-tile-logo{height:20px;max-width:70px}
    .mp-window{top:10px;left:10px;right:10px;bottom:10px}
    .mp-win-header{padding:12px 16px;gap:10px}
    .mp-win-toolbar{padding:10px 16px}
    .mp-win-stats{display:none}
}

@keyframes spin{to{transform:rotate(360deg)}}
</style>

<!-- ================================================================
     SECTION 1: Marketplace Tiles
     ================================================================ -->
<div class="mp-grid">
    <?php foreach ($marketplaces as $mp):
        $s = $mp['slug'] ?? '';
        $mColor = $mpColors[$s] ?? '#1967F2';
        $mBg    = $mpBgs[$s] ?? 'var(--bg-card)';
        $cnt    = $mpOrderCounts[$mp['id']] ?? 0;
        $isInactive = empty($mp['is_active']);
    ?>
    <div class="mp-tile <?= $isInactive ? 'mp-tile-inactive' : '' ?>"
         style="--mp-color:<?= $mColor ?>;--mp-bg:<?= $mBg ?>"
         <?= $isInactive ? '' : "onclick=\"openMpWindow('{$mp['id']}','{$s}','" . htmlspecialchars($mp['display_name'] ?? $s, ENT_QUOTES) . "','{$mColor}')\"" ?>
         data-mp-id="<?= $mp['id'] ?>" data-mp-slug="<?= $s ?>">
        <img class="mp-tile-logo" src="/public/assets/img/marketplaces/<?= $s ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $s ?>.jpg'" alt="<?= htmlspecialchars($mp['display_name'] ?? $s) ?>">
        <span class="mp-tile-count <?= $cnt === 0 ? 'zero' : '' ?>" data-mp-cnt="<?= $mp['id'] ?>"><?= $cnt ?> sipariş</span>
        <?php if ($isInactive): ?>
        <span class="mp-tile-soon">Pasif</span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ================================================================
     SECTION 2: Toolbar (sync, search, archive)
     ================================================================ -->
<div class="o-toolbar">
    <div class="o-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="dtSearch" placeholder="Sipariş no, müşteri, telefon..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
    </div>
    <select id="dtStatus" class="o-sel">
        <option value="">Tüm Durumlar (<?= $allCount ?>)</option>
        <?php foreach ($stMap as $k => $c): $cnt = $statusCounts[$k] ?? 0; ?>
        <option value="<?= $k ?>" <?= $currentStatus === $k ? 'selected' : '' ?>><?= $c['l'] ?> (<?= $cnt ?>)</option>
        <?php endforeach; ?>
    </select>
    <input type="date" id="dtDateFrom" class="o-date" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" placeholder="Başlangıç">
    <input type="date" id="dtDateTo" class="o-date" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" placeholder="Bitiş">
    <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="reloadTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
    <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="clearFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
    <?php if ($canArchiveOrder): ?>
    <button type="button" class="o-archive-toggle" id="archiveToggle" onclick="toggleArchive()">
        <i data-lucide="archive" style="width:14px;height:14px"></i> <span id="archiveLabel">Arşiv</span>
    </button>
    <?php endif; ?>
    <div class="o-actions">
        <?php if ($canTriggerSync): ?>
        <button type="button" class="o-btn o-btn-success o-btn-sm" onclick="doSync(false)"><i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Senkronize Et</button>
        <button type="button" class="o-btn o-btn-danger o-btn-sm" onclick="if(confirm('Tüm siparişler silinip son 90 günün siparişleri baştan çekilecek. Emin misiniz?'))doSync(true)" title="Hepsini Sil ve Baştan Çek"><i data-lucide="rotate-ccw" style="width:14px;height:14px"></i> Sıfırla</button>
        <?php endif; ?>
    </div>
</div>

<!-- ================================================================
     SECTION 3: Main Orders Table (ALL marketplaces — sorted by date)
     ================================================================ -->
<div class="o-card">
    <div class="o-bulk" id="bulkBar">
        <span><strong id="bulkCount">0</strong> sipariş seçildi</span>
        <?php if ($canCreateInvoice): ?>
        <form method="POST" action="/orders/bulk-invoice" style="display:inline" id="bulkForm">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div id="bulkInputs"></div>
            <button type="submit" class="o-btn o-btn-warning o-btn-sm"><i data-lucide="file-text" style="width:14px;height:14px"></i> Toplu Fatura Kes</button>
        </form>
        <?php endif; ?>
        <button class="o-btn o-btn-outline o-btn-sm" onclick="clearSelection()"><i data-lucide="x" style="width:14px;height:14px"></i></button>
    </div>
    <div style="overflow-x:auto">
    <table id="ordersTable" class="o-table" style="width:100%">
    <thead><tr>
        <th style="width:36px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
        <th>Sipariş</th>
        <th>Pazaryeri</th>
        <th>Ürün</th>
        <th>Tutar</th>
        <th>Kargo</th>
        <th>Durum</th>
        <th>Fatura</th>
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
        <!-- Header -->
        <div class="mp-win-header" id="mpWinHeader">
            <img class="mp-win-logo" id="mpWinLogo" src="" alt="">
            <div class="mp-win-title" id="mpWinTitle">Siparişler</div>
            <div class="mp-win-stats" id="mpWinStats"></div>
            <button class="mp-win-close" onclick="closeMpWindow()" title="Kapat (ESC)">
                <i data-lucide="x" style="width:20px;height:20px"></i>
            </button>
        </div>
        <!-- Toolbar -->
        <div class="mp-win-toolbar">
            <div class="o-search" style="width:220px">
                <i data-lucide="search" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0"></i>
                <input type="text" id="mpWinSearch" placeholder="Sipariş no, müşteri...">
            </div>
            <select id="mpWinStatus" class="o-sel">
                <option value="">Tüm Durumlar</option>
                <?php foreach ($stMap as $k => $c): ?>
                <option value="<?= $k ?>"><?= $c['l'] ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" id="mpWinDateFrom" class="o-date" placeholder="Başlangıç">
            <input type="date" id="mpWinDateTo" class="o-date" placeholder="Bitiş">
            <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="reloadMpTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
            <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="clearMpFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
            <div style="margin-left:auto;display:flex;gap:8px">
                <div class="o-bulk" id="mpBulkBar" style="padding:6px 12px;border:none;background:#eff6ff;border-radius:8px;margin:0">
                    <span><strong id="mpBulkCount">0</strong> seçildi</span>
                    <?php if ($canCreateInvoice): ?>
                    <form method="POST" action="/orders/bulk-invoice" style="display:inline" id="mpBulkForm">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <div id="mpBulkInputs"></div>
                        <button type="submit" class="o-btn o-btn-warning o-btn-sm"><i data-lucide="file-text" style="width:13px;height:13px"></i> Toplu Fatura</button>
                    </form>
                    <?php endif; ?>
                    <button class="o-btn o-btn-outline o-btn-sm" onclick="clearMpSelection()" style="padding:4px 8px"><i data-lucide="x" style="width:12px;height:12px"></i></button>
                </div>
            </div>
        </div>
        <!-- Body: DataTable -->
        <div class="mp-win-body">
            <div class="o-card">
                <div style="overflow-x:auto">
                <table id="mpOrdersTable" class="o-table" style="width:100%">
                <thead><tr>
                    <th style="width:36px"><input type="checkbox" id="mpSelectAll" onchange="toggleMpAll(this)"></th>
                    <th>Sipariş</th>
                    <th>Ürün</th>
                    <th>Tutar</th>
                    <th>Kargo</th>
                    <th>Durum</th>
                    <th>Fatura</th>
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
     SCRIPTS
     ================================================================ -->
<script>
const CSRF = <?= json_encode($csrf) ?>;
const ST_MAP = <?= json_encode($stMap, JSON_UNESCAPED_UNICODE) ?>;
const CAN_CREATE_INVOICE = <?= $canCreateInvoice ? 'true' : 'false' ?>;
const CAN_SEND_INVOICE = <?= $canSendInvoice ? 'true' : 'false' ?>;
const CAN_ARCHIVE_ORDER = <?= $canArchiveOrder ? 'true' : 'false' ?>;
const CAN_SHIP_ACTION = <?= $canShipAction ? 'true' : 'false' ?>;
const MP_COLORS = <?= json_encode($mpColors, JSON_UNESCAPED_UNICODE) ?>;

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

/* === Column render functions (shared between main & popup tables) === */
function renderCheck(selectedSet) {
    return function(d,t,r){
        if(r.invoice_id) return '<span style="color:var(--text-muted);font-size:11px" title="Faturalı"><i data-lucide="check" style="width:14px;height:14px"></i></span>';
        const chk = selectedSet.has(r.id) ? 'checked' : '';
        return '<input type="checkbox" class="rowCheck" value="'+r.id+'" '+chk+'>';
    };
}
function renderOrder(d,t,r){
    return '<a href="/orders/'+r.id+'" class="o-id">#'+esc(r.marketplace_order_id)+'</a>'
        +'<div class="o-date-sm">'+fmtDate(r.ordered_at)+'</div>'
        +'<div class="o-customer"><i data-lucide="user" style="width:12px;height:12px"></i> '+esc(r.customer_name||'—')+'</div>';
}
function renderMpBadge(d,t,r){
    const slug = r.marketplace_slug || '';
    return '<img class="o-mp-logo-sm" src="/public/assets/img/marketplaces/'+slug+'.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/'+slug+'.jpg\'" title="'+esc(slug)+'">';
}
function renderProduct(d,t,r){
    let img = r.product_image ? '<img src="'+esc(r.product_image)+'" style="width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0" onerror="this.style.display=\'none\'">' : '';
    let extra = r.extra_items > 0 ? '<div style="font-size:10px;color:var(--primary);font-weight:700;margin-top:2px">+'+r.extra_items+' ürün daha</div>' : '';
    let sku = r.product_sku ? '<div style="font-size:10px;color:var(--text-muted);font-family:monospace;margin-top:2px">'+esc(r.product_sku)+'</div>' : '';
    return '<div style="display:flex;gap:10px;align-items:center">'+img
        +'<div><div style="font-size:12px;font-weight:700;color:var(--text-primary);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-width:280px">'+esc(r.product_name)+'</div>'
        +sku+extra+'</div></div>';
}
function renderAmount(d){ return '<span class="o-amount">'+fmtMoney(d)+'</span>'; }
function renderCargo(d,t,r){
    let h = '<div class="o-cargo">';
    if(r.cargo_company) h += '<div style="font-weight:700">'+esc(r.cargo_company)+'</div>';
    if(r.cargo_tracking_no) h += '<div class="o-cargo-no">'+esc(r.cargo_tracking_no)+'</div>';
    h += '</div>';
    return h;
}
function renderStatus(d){
    const s = ST_MAP[d] || {l:d, c:'#64748b', bg:'#f1f5f9'};
    return '<span class="o-badge" style="color:'+s.c+';background:'+s.bg+'">'+s.l+'</span>';
}
function renderInvoice(d,t,r){
    let h = '<div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">';
    if(r.invoice_id){
        if(r.invoice_sent_to_mp){
            h += '<span class="o-inv-badge o-inv-sent"><i data-lucide="check-circle" style="width:11px;height:11px"></i> Gönderildi</span>';
        } else {
            h += '<span class="o-inv-badge o-inv-done"><i data-lucide="file-check" style="width:11px;height:11px"></i> Kesildi</span>';
        }
        h += '<div style="display:flex;gap:3px;margin-top:2px">';
        h += '<a href="/invoices/'+r.invoice_id+'/html" target="_blank" class="o-ra" style="font-size:11px" title="Fatura Görüntüle &amp; İndir"><i data-lucide="eye" style="width:11px;height:11px"></i> Görüntüle</a>';
        if(CAN_SEND_INVOICE && !r.invoice_sent_to_mp){
            h += '<form method="POST" action="/orders/'+r.id+'/send-invoice-to-marketplace" style="display:inline" onsubmit="return confirm(\'Fatura pazaryerine gönderilecek. Onaylıyor musunuz?\')">'
                +'<input type="hidden" name="_csrf_token" value="'+CSRF+'">'
                +'<button type="submit" class="o-ra o-ra-inv" style="font-size:11px"><i data-lucide="send" style="width:11px;height:11px"></i> Gönder</button></form>';
        }
        h += '</div>';
    } else {
        if(CAN_CREATE_INVOICE){
            h += '<form method="POST" action="/orders/'+r.id+'/invoice" style="display:inline" onsubmit="return confirm(\'e-Arşiv fatura kesilecek. Onaylıyor musunuz?\')">'
                +'<input type="hidden" name="_csrf_token" value="'+CSRF+'">'
                +'<button type="submit" class="o-ra o-ra-warn" style="font-size:11px"><i data-lucide="file-plus" style="width:11px;height:11px"></i> Fatura Kes</button></form>';
        } else {
            h += '<span style="font-size:11px;color:var(--text-muted)">—</span>';
        }
    }
    h += '</div>';
    return h;
}
function renderActions(d,t,r){
    const canShip = CAN_SHIP_ACTION && ['yeni','beklemede','hazirlaniyor','faturalandi'].includes(r.status);
    const isShipped = ['kargoda','teslim_noktasinda','teslim_edildi'].includes(r.status);
    let h = '<div class="o-row-actions">';
    h += '<a href="/orders/'+r.id+'" class="o-ra" style="font-size:11px" title="Detay"><i data-lucide="eye" style="width:12px;height:12px"></i></a>';
    if(canShip){
        h += '<button class="o-ra o-ra-inv" style="font-size:11px" onclick="event.stopPropagation();quickShip('+r.id+',\''+esc(r.marketplace_slug)+'\',\''+esc(r.marketplace_order_id)+'\')" title="Kargola"><i data-lucide="send" style="width:12px;height:12px"></i> Kargola</button>';
    }
    if(isShipped || r.cargo_tracking_no){
        h += '<a href="/orders/'+r.id+'/cargo-label" target="_blank" class="o-ra" style="font-size:11px" title="Kargo Etiketi İndir"><i data-lucide="tag" style="width:12px;height:12px"></i> Etiket</a>';
    }
    if(r.cargo_tracking_link){
        h += '<a href="'+esc(r.cargo_tracking_link)+'" target="_blank" class="o-ra" style="font-size:11px" title="Kargo Takip"><i data-lucide="truck" style="width:12px;height:12px"></i></a>';
    }
    if(CAN_ARCHIVE_ORDER){
        h += '<button class="o-ra" style="font-size:11px;color:'+(r.is_archived?'var(--success)':'var(--text-muted)')+'" onclick="event.stopPropagation();archiveOrder('+r.id+')" title="'+(r.is_archived?'Arşivden Çıkar':'Arşivle')+'"><i data-lucide="'+(r.is_archived?'archive-restore':'archive')+'" style="width:12px;height:12px"></i></button>';
    }
    h += '</div>';
    return h;
}

/* ================================================================
   MAIN TABLE (all marketplaces)
   ================================================================ */
let selectedIds = new Set();
let showArchived = false;
const MAIN_MARKETPLACE_ID = <?= json_encode((string) $currentMp) ?>;

const table = $('#ordersTable').DataTable({
    processing: true,
    serverSide: true,
    ajax: {
        url: '/orders/ajax',
        data: function(d) {
            d.marketplace_id = MAIN_MARKETPLACE_ID || '';
            d.status = document.getElementById('dtStatus').value;
            d.date_from = document.getElementById('dtDateFrom').value;
            d.date_to = document.getElementById('dtDateTo').value;
            if (showArchived) d.show_archived = '1';
        },
        dataSrc: function(json) {
            if (json.statusCounts) updateStatusCounts(json.statusCounts);
            if (json.mpOrderCounts) updateMpTileCounts(json.mpOrderCounts);
            return json.data;
        }
    },
    columns: [
        { data: null, orderable: false, className: 'dt-center', width: '36px', render: renderCheck(selectedIds) },
        { data: null, orderable: true, render: renderOrder },
        { data: null, orderable: false, width: '60px', className: 'dt-center', render: renderMpBadge },
        { data: null, orderable: false, render: renderProduct },
        { data: 'total_amount', orderable: true, render: renderAmount },
        { data: null, orderable: true, render: renderCargo },
        { data: 'status', orderable: true, render: renderStatus },
        { data: null, orderable: false, render: renderInvoice },
        { data: null, orderable: false, render: renderActions }
    ],
    order: [[1, 'desc']],
    pageLength: 25,
    lengthMenu: [25, 50, 100],
    deferRender: true,
    autoWidth: false,
    searching: true,
    search: { search: <?= json_encode($filters['search'] ?? '') ?> },
    dom: 'rtip',
    language: {
        processing: '<div style="display:flex;align-items:center;gap:8px"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
        emptyTable: '<div class="o-empty"><i data-lucide="package" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Sipariş bulunamadı</div>',
        zeroRecords: '<div class="o-empty"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Aramanıza uygun sipariş bulunamadı</div>',
        info: 'Toplam _TOTAL_ sipariş · Sayfa _PAGE_/_PAGES_',
        infoEmpty: 'Sipariş yok',
        infoFiltered: '(_MAX_ kayıttan filtrelendi)',
        paginate: { previous: '<i data-lucide="chevron-left" style="width:14px;height:14px"></i>', next: '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>' }
    },
    drawCallback: function() {
        lucide.createIcons();
        updateBulkBar();
        document.querySelectorAll('#ordersTable .rowCheck').forEach(cb => {
            if(selectedIds.has(parseInt(cb.value))) cb.checked = true;
        });
        // Move pagination and info to custom footer
        $('#ordersTable_info').appendTo('#dtInfo');
        $('#ordersTable_paginate').appendTo('#dtPaginate');
    }
});

/* Main table helpers */
let searchTimer;
document.addEventListener('DOMContentLoaded', function() {
    const dtSearch = document.getElementById('dtSearch');
    const dtStatus = document.getElementById('dtStatus');
    
    if (dtSearch) {
        dtSearch.addEventListener('input', function(){
            clearTimeout(searchTimer);
            const val = this.value;
            searchTimer = setTimeout(() => { table.search(val).draw(); }, 400);
        });
        dtSearch.addEventListener('keydown', function(e){
            if(e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); table.search(this.value).draw(); }
        });
    }
    
    if (dtStatus) {
        dtStatus.addEventListener('change', function(){ table.ajax.reload(); });
    }
});

function reloadTable(){ table.ajax.reload(); }
function clearFilters(){
    document.getElementById('dtSearch').value = '';
    document.getElementById('dtStatus').value = '';
    document.getElementById('dtDateFrom').value = '';
    document.getElementById('dtDateTo').value = '';
    table.search('').ajax.reload();
}
function updateStatusCounts(counts){
    const sel = document.getElementById('dtStatus');
    const allCnt = counts.all || 0;
    sel.options[0].textContent = 'Tüm Durumlar ('+allCnt+')';
    for(let i=1; i<sel.options.length; i++){
        const key = sel.options[i].value;
        const cnt = counts[key] || 0;
        const label = ST_MAP[key] ? ST_MAP[key].l : key;
        sel.options[i].textContent = label+' ('+cnt+')';
    }
}
function updateMpTileCounts(counts){
    document.querySelectorAll('[data-mp-cnt]').forEach(badge => {
        const mpId = badge.dataset.mpCnt;
        const cnt = counts[mpId] || 0;
        badge.textContent = cnt + ' sipariş';
        badge.classList.toggle('zero', cnt === 0);
    });
}

/* Selection */
$('#ordersTable tbody').on('change', '.rowCheck', function(){
    const id = parseInt(this.value);
    if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
    updateBulkBar();
});
function toggleAll(c){
    document.querySelectorAll('#ordersTable .rowCheck').forEach(x => {
        x.checked = c.checked;
        const id = parseInt(x.value);
        if(c.checked) selectedIds.add(id); else selectedIds.delete(id);
    });
    updateBulkBar();
}
function updateBulkBar(){
    const bar = document.getElementById('bulkBar');
    const inp = document.getElementById('bulkInputs');
    if(selectedIds.size > 0){
        bar.classList.add('show');
        document.getElementById('bulkCount').textContent = selectedIds.size;
        inp.innerHTML = '';
        selectedIds.forEach(id => {
            const i = document.createElement('input'); i.type='hidden'; i.name='order_ids[]'; i.value=id;
            inp.appendChild(i);
        });
    } else {
        bar.classList.remove('show');
        document.getElementById('selectAll').checked = false;
    }
}
function clearSelection(){
    selectedIds.clear();
    document.querySelectorAll('#ordersTable .rowCheck,#selectAll').forEach(c => c.checked=false);
    updateBulkBar();
}

/* Sync — SSE Progress Modal */
function doSync(force){
    // Progress overlay oluştur
    let overlay = document.getElementById('syncOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'syncOverlay';
        overlay.innerHTML = `
        <div class="sync-modal">
            <div class="sync-modal-header">
                <div class="sync-modal-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 12 12 12"/></svg></div>
                <div>
                    <h3 class="sync-modal-title">Senkronizasyon</h3>
                    <p class="sync-modal-sub" id="syncSubtitle">Pazaryerleri taranıyor...</p>
                </div>
            </div>
            <div class="sync-modal-body" id="syncBody"></div>
            <div class="sync-modal-progress">
                <div class="sync-progress-track"><div class="sync-progress-bar" id="syncBar"></div></div>
                <span class="sync-progress-text" id="syncProgressText">0%</span>
            </div>
            <div class="sync-modal-footer" id="syncFooter" style="display:none">
                <button class="o-btn o-btn-primary o-btn-sm" onclick="closeSyncModal()">Tamam</button>
            </div>
        </div>`;
        document.body.appendChild(overlay);

        // Style inject
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
            .sync-mp-status .skip{color:#9ca3af;font-size:14px}
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
    const text = document.getElementById('syncProgressText');
    const subtitle = document.getElementById('syncSubtitle');
    const footer = document.getElementById('syncFooter');
    const icon = overlay.querySelector('.sync-modal-icon');
    body.innerHTML = '';
    bar.style.width = '0%';
    text.textContent = '0%';
    subtitle.textContent = 'Pazaryerleri taranıyor...';
    footer.style.display = 'none';
    icon.className = 'sync-modal-icon';

    const mpRows = {};
    let totalMps = 0;
    let doneMps = 0;

    function addRow(slug, displayName, statusHtml, detail) {
        const logoSrc = '/public/assets/img/marketplaces/' + slug + '.png';
        const row = document.createElement('div');
        row.className = 'sync-mp-row';
        row.id = 'syncRow_' + slug;
        row.innerHTML = `
            <img class="sync-mp-logo" src="${logoSrc}" alt="${displayName}" onerror="this.style.display='none'">
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
        text.textContent = doneMps + '/' + totalMps;
    }

    // SSE bağlantısı — POST ile fetch, sonra stream oku
    const fd = new FormData();
    fd.append('_csrf_token', CSRF);
    if(force) fd.append('force_resync','1');

    fetch('/orders/sync-stream', { method: 'POST', body: fd })
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
                if (line.startsWith('event: ')) {
                    currentEvent = line.substring(7).trim();
                } else if (line.startsWith('data: ')) {
                    const dataStr = line.substring(6);
                    try {
                        const data = JSON.parse(dataStr);
                        handleSSE(currentEvent, data);
                    } catch(e) {}
                    currentEvent = '';
                }
            }
        }

        function handleSSE(event, data) {
            const slug = data.slug || '';
            const name = data.display_name || slug;

            if (event === 'start') {
                totalMps++;
                subtitle.textContent = name + ' siparişleri çekiliyor...';
                addRow(slug, name, '<div class="spinner"></div>', 'Çekiliyor...');
                updateProgress();
            } else if (event === 'done') {
                doneMps++;
                const r = data.result || {};
                const fetched = r.fetched || 0;
                const processed = r.processed || 0;
                const skipped = r.skipped || 0;
                const detailEl = document.getElementById('syncDetail_' + slug);
                const statusEl = document.getElementById('syncStatus_' + slug);
                if (detailEl) detailEl.textContent = fetched + ' çekildi, ' + processed + ' yeni, ' + skipped + ' güncellendi';
                if (statusEl) statusEl.innerHTML = '<span class="check">✓</span>';
                subtitle.textContent = name + ' tamamlandı';
                updateProgress();
            } else if (event === 'error') {
                doneMps++;
                const errMsg = (data.result || {}).error || 'Bilinmeyen hata';
                if (!mpRows[slug]) {
                    totalMps++;
                    addRow(slug, name, '', '');
                }
                const detailEl = document.getElementById('syncDetail_' + slug);
                const statusEl = document.getElementById('syncStatus_' + slug);
                if (detailEl) { detailEl.textContent = errMsg; detailEl.style.color = '#ef4444'; }
                if (statusEl) statusEl.innerHTML = '<span class="fail">✗</span>';
                updateProgress();
            } else if (event === 'skip') {
                // Atlanmış pazaryeri — gösterme (credential eksik)
            } else if (event === 'info') {
                subtitle.textContent = data.message || '';
            } else if (event === 'complete') {
                bar.style.width = '100%';
                text.textContent = '✓';
                icon.className = data.success ? 'sync-modal-icon done' : 'sync-modal-icon error';
                subtitle.textContent = data.message || 'Tamamlandı';
                footer.style.display = 'flex';
                table.ajax.reload();
            }
        }

        function read() {
            reader.read().then(({ done, value }) => {
                if (done) {
                    if (buffer.trim()) processChunk('\n');
                    return;
                }
                processChunk(decoder.decode(value, { stream: true }));
                read();
            }).catch(() => {
                bar.style.width = '100%';
                subtitle.textContent = 'Bağlantı kesildi';
                footer.style.display = 'flex';
                icon.className = 'sync-modal-icon error';
            });
        }
        read();
    })
    .catch(() => {
        subtitle.textContent = 'Bağlantı hatası';
        footer.style.display = 'flex';
        icon.className = 'sync-modal-icon error';
    });
}

function closeSyncModal() {
    const overlay = document.getElementById('syncOverlay');
    if (overlay) overlay.style.display = 'none';
}

/* Archive */
function toggleArchive(){
    if(!CAN_ARCHIVE_ORDER) return;
    showArchived = !showArchived;
    const btn = document.getElementById('archiveToggle');
    const lbl = document.getElementById('archiveLabel');
    btn.classList.toggle('active', showArchived);
    lbl.textContent = showArchived ? 'Arşivden Çık' : 'Arşiv';
    table.ajax.reload();
}
function archiveOrder(orderId){
    if(!CAN_ARCHIVE_ORDER) return;
    const fd = new FormData();
    fd.append('_csrf_token', CSRF);
    fetch('/orders/'+orderId+'/archive',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            Toast.show(d.message,'success');
            table.ajax.reload(null, false);
            if(mpTable) mpTable.ajax.reload(null, false);
        } else {
            Toast.show('Hata: '+(d.message||''),'error');
        }
    }).catch(()=>Toast.show('Bağlantı hatası!','error'));
}

/* ================================================================
   MARKETPLACE POPUP WINDOW
   ================================================================ */
let mpTable = null;
let mpSelectedIds = new Set();
let activeMpId = '';
let activeMpSlug = '';

function openMpWindow(mpId, slug, displayName, color){
    activeMpId = mpId;
    activeMpSlug = slug;

    // Set header
    const header = document.getElementById('mpWinHeader');
    header.style.borderBottomColor = color;
    document.getElementById('mpWinLogo').src = '/public/assets/img/marketplaces/'+slug+'.png';
    document.getElementById('mpWinLogo').onerror = function(){ this.src = '/public/assets/img/marketplaces/'+slug+'.jpg'; };
    document.getElementById('mpWinTitle').textContent = 'Siparişler';

    // Reset filters
    document.getElementById('mpWinSearch').value = '';
    document.getElementById('mpWinStatus').value = '';
    document.getElementById('mpWinDateFrom').value = '';
    document.getElementById('mpWinDateTo').value = '';
    mpSelectedIds.clear();
    updateMpBulkBar();

    // Show overlay
    document.getElementById('mpOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    // Init or reload DataTable
    if(mpTable){
        mpTable.destroy();
        $('#mpDtInfo, #mpDtPaginate').empty();
        $('#mpOrdersTable').empty();
        // Recreate thead
        $('#mpOrdersTable').html('<thead><tr>'
            +'<th style="width:36px"><input type="checkbox" id="mpSelectAll" onchange="toggleMpAll(this)"></th>'
            +'<th>Sipariş</th><th>Ürün</th><th>Tutar</th><th>Kargo</th><th>Durum</th><th>Fatura</th><th>İşlemler</th>'
            +'</tr></thead><tbody></tbody>');
    }

    mpTable = $('#mpOrdersTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/orders/ajax',
            data: function(d) {
                d.marketplace_id = activeMpId;
                d.status = document.getElementById('mpWinStatus').value;
                d.date_from = document.getElementById('mpWinDateFrom').value;
                d.date_to = document.getElementById('mpWinDateTo').value;
            },
            dataSrc: function(json){
                // Update stats in header
                const stats = document.getElementById('mpWinStats');
                const sc = json.statusCounts || {};
                const total = sc.all || 0;
                const yeni = (sc.yeni||0) + (sc.beklemede||0) + (sc.hazirlaniyor||0);
                const kargoda = sc.kargoda || 0;
                const teslim = sc.teslim_edildi || 0;
                stats.innerHTML =
                    '<div class="mp-win-stat"><span class="mp-win-stat-val">'+total+'</span><span class="mp-win-stat-lbl">Toplam</span></div>'
                   +'<div class="mp-win-stat" style="background:#eff6ff"><span class="mp-win-stat-val" style="color:#3b82f6">'+yeni+'</span><span class="mp-win-stat-lbl">Yeni/Bekleyen</span></div>'
                   +'<div class="mp-win-stat" style="background:#f5f3ff"><span class="mp-win-stat-val" style="color:#4B8AF5">'+kargoda+'</span><span class="mp-win-stat-lbl">Kargoda</span></div>'
                   +'<div class="mp-win-stat" style="background:#f0fdf4"><span class="mp-win-stat-val" style="color:#10b981">'+teslim+'</span><span class="mp-win-stat-lbl">Teslim</span></div>';
                return json.data;
            }
        },
        columns: [
            { data: null, orderable: false, className: 'dt-center', width: '36px', render: renderCheck(mpSelectedIds) },
            { data: null, orderable: true, render: renderOrder },
            { data: null, orderable: false, render: renderProduct },
            { data: 'total_amount', orderable: true, render: renderAmount },
            { data: null, orderable: true, render: renderCargo },
            { data: 'status', orderable: true, render: renderStatus },
            { data: null, orderable: false, render: renderInvoice },
            { data: null, orderable: false, render: renderActions }
        ],
        order: [[1, 'desc']],
        pageLength: 25,
        lengthMenu: [25, 50, 100],
        deferRender: true,
        autoWidth: false,
        searching: true,
        dom: 'rtip',
        language: {
            processing: '<div style="display:flex;align-items:center;gap:8px"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
            emptyTable: '<div class="o-empty"><i data-lucide="package" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Bu pazaryerinde sipariş bulunamadı</div>',
            zeroRecords: '<div class="o-empty"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>Aramanıza uygun sipariş bulunamadı</div>',
            info: 'Toplam _TOTAL_ sipariş · Sayfa _PAGE_/_PAGES_',
            infoEmpty: 'Sipariş yok',
            infoFiltered: '(_MAX_ kayıttan filtrelendi)',
            paginate: { previous: '<i data-lucide="chevron-left" style="width:14px;height:14px"></i>', next: '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>' }
        },
        drawCallback: function() {
            lucide.createIcons();
            updateMpBulkBar();
            document.querySelectorAll('#mpOrdersTable .rowCheck').forEach(cb => {
                if(mpSelectedIds.has(parseInt(cb.value))) cb.checked = true;
            });
            // Move pagination and info to custom footer
            $('#mpOrdersTable_info').appendTo('#mpDtInfo');
            $('#mpOrdersTable_paginate').appendTo('#mpDtPaginate');
        }
    });

    lucide.createIcons();
}

function closeMpWindow(){
    document.getElementById('mpOverlay').classList.remove('active');
    document.body.style.overflow = '';
    // Refresh main table to catch any changes made in popup
    table.ajax.reload(null, false);
}

/* Popup search with debounce */
let mpSearchTimer;
document.addEventListener('DOMContentLoaded', function() {
    const mpWinSearch = document.getElementById('mpWinSearch');
    const mpWinStatus = document.getElementById('mpWinStatus');
    
    if (mpWinSearch) {
        mpWinSearch.addEventListener('input', function(){
            clearTimeout(mpSearchTimer);
            const val = this.value;
            mpSearchTimer = setTimeout(() => { if(mpTable) mpTable.search(val).draw(); }, 400);
        });
        mpWinSearch.addEventListener('keydown', function(e){
            if(e.key === 'Enter') { e.preventDefault(); clearTimeout(mpSearchTimer); if(mpTable) mpTable.search(this.value).draw(); }
        });
    }
    
    if (mpWinStatus) {
        mpWinStatus.addEventListener('change', function(){ if(mpTable) mpTable.ajax.reload(); });
    }
});

function reloadMpTable(){ if(mpTable) mpTable.ajax.reload(); }
function clearMpFilters(){
    document.getElementById('mpWinSearch').value = '';
    document.getElementById('mpWinStatus').value = '';
    document.getElementById('mpWinDateFrom').value = '';
    document.getElementById('mpWinDateTo').value = '';
    if(mpTable) mpTable.search('').ajax.reload();
}

/* Popup selection */
$('#mpOrdersTable').on('change', '.rowCheck', function(){
    const id = parseInt(this.value);
    if(this.checked) mpSelectedIds.add(id); else mpSelectedIds.delete(id);
    updateMpBulkBar();
});
function toggleMpAll(c){
    document.querySelectorAll('#mpOrdersTable .rowCheck').forEach(x => {
        x.checked = c.checked;
        const id = parseInt(x.value);
        if(c.checked) mpSelectedIds.add(id); else mpSelectedIds.delete(id);
    });
    updateMpBulkBar();
}
function updateMpBulkBar(){
    const bar = document.getElementById('mpBulkBar');
    const inp = document.getElementById('mpBulkInputs');
    if(mpSelectedIds.size > 0){
        bar.classList.add('show');
        document.getElementById('mpBulkCount').textContent = mpSelectedIds.size;
        if(inp){
            inp.innerHTML = '';
            mpSelectedIds.forEach(id => {
                const i = document.createElement('input'); i.type='hidden'; i.name='order_ids[]'; i.value=id;
                inp.appendChild(i);
            });
        }
    } else {
        bar.classList.remove('show');
        const sa = document.getElementById('mpSelectAll');
        if(sa) sa.checked = false;
    }
}
function clearMpSelection(){
    mpSelectedIds.clear();
    document.querySelectorAll('#mpOrdersTable .rowCheck,#mpSelectAll').forEach(c => c.checked=false);
    updateMpBulkBar();
}

/* ESC key closes popup */
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        if(document.getElementById('qsOverlay').classList.contains('show')){
            closeQuickShip(); return;
        }
        if(document.getElementById('mpOverlay').classList.contains('active')){
            closeMpWindow();
        }
    }
});
/* Click outside window closes popup */
document.addEventListener('DOMContentLoaded', function() {
    const mpOverlay = document.getElementById('mpOverlay');
    if (mpOverlay) {
        mpOverlay.addEventListener('click', function(e){
            if(e.target === this) closeMpWindow();
        });
    }
});

/* ================================================================
   QUICK SHIP (from order table — one-click kargolama)
   ================================================================ */
let qsOrderId = 0, qsSlug = '', qsMpOrderId = '';

function quickShip(orderId, slug, mpOrderId) {
    qsOrderId = orderId;
    qsSlug = slug;
    qsMpOrderId = mpOrderId;

    const overlay = document.getElementById('qsOverlay');
    const title = document.getElementById('qsTitle');
    const form = document.getElementById('qsForm');
    const progress = document.getElementById('qsProgress');
    const result = document.getElementById('qsResult');

    title.textContent = '#' + mpOrderId + ' Kargola';
    form.style.display = '';
    progress.style.display = 'none';
    result.style.display = 'none';

    // Marketplace-specific UI
    const trkGroup = document.getElementById('qsTrkGroup');
    const cargoGroup = document.getElementById('qsCargoGroup');
    const btnLabel = document.getElementById('qsBtnLabel');

    if (slug === 'pttavm') {
        trkGroup.style.display = 'none';
        cargoGroup.style.display = 'none';
        btnLabel.textContent = 'PttAVM Barkod Oluştur';
    } else if (slug === 'n11') {
        trkGroup.style.display = 'none';
        cargoGroup.style.display = 'none';
        btnLabel.textContent = 'Siparişi Onayla';
    } else {
        trkGroup.style.display = '';
        cargoGroup.style.display = '';
        btnLabel.textContent = 'Kargola';
        document.getElementById('qsTrackingNo').value = '';
    }

    overlay.classList.add('show');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeQuickShip() {
    document.getElementById('qsOverlay').classList.remove('show');
}

async function executeQuickShip() {
    const cargo = document.getElementById('qsCargo') ? document.getElementById('qsCargo').value : '';
    const tracking = document.getElementById('qsTrackingNo') ? document.getElementById('qsTrackingNo').value.trim() : '';

    // Validate based on marketplace
    if (['trendyol','hepsiburada','ciceksepeti','pazarama','amazon','idefix'].includes(qsSlug)) {
        if (!cargo) { Toast.show('Kargo firması seçin', 'error'); return; }
        if (!tracking) { Toast.show('Takip numarası girin', 'error'); return; }
    }

    document.getElementById('qsForm').style.display = 'none';
    const progress = document.getElementById('qsProgress');
    progress.style.display = '';
    progress.innerHTML = '<div style="text-align:center;padding:20px"><i data-lucide="loader" style="width:24px;height:24px;animation:spin 1s linear infinite;color:var(--primary)"></i><p style="margin-top:8px;font-size:13px;color:var(--text-muted)">İşlem yapılıyor...</p></div>';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    try {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        if (tracking) fd.append('tracking_no', tracking);
        if (cargo) fd.append('cargo_company', cargo);

        const res = await fetch('/orders/' + qsOrderId + '/ship', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const d = await res.json();

        if (d.success) {
            const data = d.data || {};
            const result = document.getElementById('qsResult');
            progress.style.display = 'none';
            result.style.display = '';
            result.innerHTML = '<div style="text-align:center;padding:16px">'
                + '<i data-lucide="check-circle" style="width:40px;height:40px;color:#10b981"></i>'
                + '<p style="font-weight:700;font-size:15px;margin:8px 0 4px;color:var(--text-primary)">Kargolama Tamamlandı!</p>'
                + (data.trackingNo ? '<p style="font-size:12px;color:var(--text-muted)">Takip No: ' + esc(data.trackingNo) + '</p>' : '')
                + '<div style="display:flex;gap:8px;justify-content:center;margin-top:12px">'
                + '<a href="/orders/' + qsOrderId + '/cargo-label" target="_blank" class="o-btn o-btn-primary o-btn-sm"><i data-lucide="tag" style="width:14px;height:14px"></i> Etiket İndir</a>'
                + '<button class="o-btn o-btn-outline o-btn-sm" onclick="closeQuickShip()"><i data-lucide="x" style="width:14px;height:14px"></i> Kapat</button>'
                + '</div></div>';
            if (typeof lucide !== 'undefined') lucide.createIcons();
            Toast.show('Sipariş kargoya verildi!', 'success');
            table.ajax.reload(null, false);
            if (mpTable) mpTable.ajax.reload(null, false);
        } else {
            Toast.show(d.message || 'Kargolama hatası', 'error');
            document.getElementById('qsForm').style.display = '';
            progress.style.display = 'none';
        }
    } catch (e) {
        Toast.show('Bağlantı hatası', 'error');
        document.getElementById('qsForm').style.display = '';
        progress.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const qsOverlay = document.getElementById('qsOverlay');
    if (qsOverlay) {
        qsOverlay.addEventListener('click', function(e){
            if(e.target === this) closeQuickShip();
        });
    }
});
</script>

<!-- ================================================================
     Quick Ship Modal
     ================================================================ -->
<style>
.qs-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;backdrop-filter:blur(4px);align-items:center;justify-content:center}
.qs-overlay.show{display:flex}
.qs-modal{background:var(--bg-card);border-radius:16px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;animation:slideUp .25s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.qs-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input)}
.qs-header h3{margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--text-primary)}
.qs-body{padding:20px}
.qs-field{margin-bottom:14px}
.qs-field label{display:block;font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.qs-field select,.qs-field input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit;box-sizing:border-box}
.qs-field select:focus,.qs-field input:focus{border-color:var(--primary);outline:none}
</style>

<div class="qs-overlay" id="qsOverlay">
<div class="qs-modal">
    <div class="qs-header">
        <h3><i data-lucide="truck" style="width:18px;height:18px"></i> <span id="qsTitle">Kargola</span></h3>
        <button onclick="closeQuickShip()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px">&times;</button>
    </div>
    <div class="qs-body">
        <div id="qsForm">
            <div class="qs-field" id="qsCargoGroup">
                <label>Kargo Firması</label>
                <select id="qsCargo">
                    <option value="">Seçin...</option>
                    <option value="TEXKARGO">TEX Kargo</option>
                    <option value="ARASKARGO">Aras Kargo</option>
                    <option value="YKARGO">Yurtiçi Kargo</option>
                    <option value="SURATCARGO">Sürat Kargo</option>
                    <option value="MNGKARGO">MNG Kargo</option>
                    <option value="PTTKARGO">PTT Kargo</option>
                    <option value="HOROZ">Horoz Lojistik</option>
                    <option value="CEVA">CEVA Lojistik</option>
                    <option value="SENDEO">Sendeo</option>
                    <option value="HEPSIJET">Hepsijet</option>
                    <option value="UPS">UPS</option>
                </select>
            </div>
            <div class="qs-field" id="qsTrkGroup">
                <label>Kargo Takip No</label>
                <input type="text" id="qsTrackingNo" placeholder="Takip numarasını girin" style="font-family:monospace">
            </div>
            <button class="o-btn o-btn-success" style="width:100%;justify-content:center;padding:12px" onclick="executeQuickShip()">
                <i data-lucide="send" style="width:16px;height:16px"></i> <span id="qsBtnLabel">Kargola</span>
            </button>
        </div>
        <div id="qsProgress" style="display:none"></div>
        <div id="qsResult" style="display:none"></div>
    </div>
</div>
</div>
