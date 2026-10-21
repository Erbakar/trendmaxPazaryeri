<?php
$filters      = $data['filters'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$statusCounts = $data['statusCounts'] ?? [];
$currentStatus = $filters['status'] ?? '';
$currentMp     = $filters['marketplace_id'] ?? '';
$allCount      = $statusCounts['all'] ?? 0;
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
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
    'kargoda'=>['l'=>'Kargoda','c'=>'var(--primary)','bg'=>'#f5f3ff'],
    'teslim_noktasinda'=>['l'=>'T. Noktasında','c'=>'var(--primary)','bg'=>'#eef2ff'],
    'teslim_edildi'=>['l'=>'Teslim Edildi','c'=>'#10b981','bg'=>'#f0fdf4'],
    'teslim_edilemedi'=>['l'=>'Teslim Edilemedi','c'=>'#f97316','bg'=>'#fff7ed'],
    'iptal'=>['l'=>'İptal','c'=>'#ef4444','bg'=>'#fef2f2'],
    'iade'=>['l'=>'İade','c'=>'#f97316','bg'=>'#fff7ed'],
];
$csrf = $data['_csrf'] ?? '';
$mpOrderCounts = $data['mpOrderCounts'] ?? [];
$mpNewCounts   = $data['mpNewCounts'] ?? [];
$mpConnected   = $data['mpConnected'] ?? [];
?>
<style>
/* === DataTables overrides (mockup footer'a taşınan info + sayfalama) === */
.dataTables_wrapper{background:transparent!important}
.dataTables_wrapper .dataTables_processing{background:rgb(var(--surface))!important;color:inherit!important;border:1px solid rgba(148,163,184,.25)!important;border-radius:10px!important;padding:12px 24px!important;box-shadow:0 0 0 1px var(--border);font-size:13px!important;z-index:10!important}
table.dataTable{border-collapse:collapse!important}
table.dataTable tbody tr{background:transparent!important}
table.dataTable.no-footer{border-bottom:none!important}
table.dataTable td.dataTables_empty{padding:60px 20px!important;text-align:center;color:rgb(148 163 184);font-size:14px}
.dataTables_info{font-size:12.5px;color:rgb(148 163 184);padding:0!important}
.dataTables_paginate{padding:0!important}
.dataTables_paginate .paginate_button{min-width:36px;height:36px;display:inline-flex!important;align-items:center;justify-content:center;border-radius:8px!important;font-size:13px!important;font-weight:600;color:rgb(100 116 139)!important;background:transparent!important;border:0!important;margin:0 1px!important;padding:0 8px!important;transition:all .12s;cursor:pointer;font-variant-numeric:tabular-nums}
.dataTables_paginate .paginate_button:hover{color:inherit!important;background:rgba(15,23,42,.06)!important}
.dataTables_paginate .paginate_button.current{background:rgb(var(--brand))!important;color:#fff!important}
.dataTables_paginate .paginate_button.disabled{opacity:.3!important;pointer-events:none}
.dataTables_length,.dataTables_filter{display:none!important}
.dt-center{text-align:center}.dt-right{text-align:right}
#ordersTable tbody tr{cursor:pointer}
/* QuickShip modal aksiyon butonları için minimal yardımcılar */
.o-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit}
.o-btn-primary{background:rgb(var(--brand));color:#fff}.o-btn-primary:hover{opacity:.9}
.o-btn-outline{background:transparent;border:1px solid rgba(148,163,184,.4);color:inherit}.o-btn-outline:hover{border-color:rgb(var(--brand));color:rgb(var(--brand))}
.o-btn-success{background:#10b981;color:#fff}.o-btn-success:hover{background:#059669}
.o-btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}
@keyframes spin{to{transform:rotate(360deg)}}
/* Pazaryeri sekmesindeki "N yeni" sipariş rozeti */
.mp-seg-new{display:inline-flex;align-items:center;height:18px;padding:0 6px;border-radius:6px;background:rgb(var(--brand) / .12);color:rgb(var(--brand));font-size:10.5px;font-weight:700;white-space:nowrap;font-variant-numeric:tabular-nums}
.mp-seg-new[hidden]{display:none}
.mp-seg-tab.is-active .mp-seg-new{background:rgb(var(--brand));color:#fff}
.mp-seg-connect{text-decoration:none}
.mp-seg-connect [data-lucide]{width:16px;height:16px}
.mp-seg-tab:focus-visible{outline:2px solid rgb(var(--brand));outline-offset:2px}
</style>

<?php $hdrStore = htmlspecialchars((string) ($data['_branding']['store_name'] ?? $data['_branding']['site_name'] ?? '')); ?>
<div class="ui-page-head">
    <div>
        <h1>Siparişler</h1>
    <p>Tüm pazaryeri siparişlerinizi tek ekrandan takip edin ve yönetin.</p>
    </div>
    <?php if ($canTriggerSync): ?>
    <button type="button" onclick="doSync(false)" class="ui-btn ui-btn-primary"><i data-lucide="refresh-cw"></i> Senkronize Et</button>
    <?php endif; ?>
</div>
<?php $subnav = ['active' => '/orders', 'items' => [
    ['u' => '/orders', 'l' => 'Siparişler'],
    ['u' => '/returns', 'l' => 'İadeler'],
    ['u' => '/questions', 'l' => 'Sorular'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<!-- Özet kartları — tıklanınca durum süzgeci uygulanır -->
<?php $ozetKart = [
    ['',              'Toplam Sipariş', 'shopping-cart',  '',          (int) $allCount],
    ['yeni',          'Yeni',           'sparkles',       'is-cyan',   (int) ($statusCounts['yeni'] ?? 0)],
    ['hazirlaniyor',  'Hazırlanıyor',   'clock',          'is-amber',  (int) ($statusCounts['hazirlaniyor'] ?? 0)],
    ['kargoda',       'Kargoda',        'truck',          'is-violet', (int) ($statusCounts['kargoda'] ?? 0)],
    ['teslim_edildi', 'Teslim Edildi',  'check-circle-2', 'is-green',  (int) ($statusCounts['teslim_edildi'] ?? 0)],
]; ?>
<div class="ui-stats">
  <?php foreach ($ozetKart as [$k, $l, $ico, $renk, $deger]): ?>
  <div class="ui-stat" role="button" tabindex="0" style="cursor:pointer" title="<?= htmlspecialchars($l) ?> siparişleri listele"
       onclick="var s=document.getElementById('dtStatus');if(s){s.value='<?= $k ?>';reloadTable();}" onkeydown="if(event.key==='Enter')this.click()">
    <span class="ui-stat-icon <?= $renk ?>"><i data-lucide="<?= $ico ?>"></i></span>
    <div class="ui-stat-body"><p class="ui-stat-label"><?= htmlspecialchars($l) ?></p><div class="ui-stat-value"><?= number_format($deger, 0, ',', '.') ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ÜST PANEL: pazaryeri pills + araç çubuğu (Ürünler tasarımı — birebir) -->
<div class="o-panel">
<div id="mpSeg" role="tablist">
    <button type="button" class="mp-seg-tab<?= ((string) $currentMp === '') ? ' is-active' : '' ?>" data-mp-id="" onclick="setMpFilter('', this)">
        <svg class="mp-seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Tümü</span>
        <span class="mp-seg-count" data-mp-cnt="all"><?= (int) $allCount ?></span>
    </button>
    <?php $visibleMpTabs = 0;
    foreach ($marketplaces as $mp):
        $mpTabId = (int) $mp['id'];
        $s = $mp['slug'] ?? '';
        $cnt = (int) ($mpOrderCounts[$mpTabId] ?? 0);
        $newCnt = (int) ($mpNewCounts[$mpTabId] ?? 0);
        $isSelectedTab = (string) $currentMp === (string) $mpTabId;
        // Bağlantısı girilmemiş ve siparişi olmayan pazaryeri sekmesi gösterilmez (seçili olan hariç).
        if (!$isSelectedTab && empty($mpConnected[$mpTabId]) && $cnt === 0) {
            continue;
        }
        $visibleMpTabs++;
        $isInactive = !$canMarketplace($mpTabId);
        $nm = htmlspecialchars($mp['display_name'] ?? $s);
    ?>
    <?php if ($isInactive): ?>
    <span class="mp-seg-tab is-disabled" title="<?= $nm ?> · Pasif (yetki yok)">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <svg class="mp-seg-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </span>
    <?php else: ?>
    <button type="button" class="mp-seg-tab<?= $isSelectedTab ? ' is-active' : '' ?>" data-mp-id="<?= $mpTabId ?>" onclick="setMpFilter('<?= $mpTabId ?>', this)" title="<?= $nm ?><?= $newCnt > 0 ? ' · ' . $newCnt . ' yeni sipariş' : '' ?>">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <span class="mp-seg-count" data-mp-cnt="<?= $mpTabId ?>"><?= $cnt ?></span>
        <span class="mp-seg-new" data-mp-new="<?= $mpTabId ?>"<?= $newCnt > 0 ? '' : ' hidden' ?>><?= $newCnt ?> yeni</span>
    </button>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($visibleMpTabs === 0 && $can('marketplaces.view')): ?>
    <a class="mp-seg-tab mp-seg-connect" href="/marketplaces"><i data-lucide="plug" aria-hidden="true"></i> Pazaryeri bağla</a>
    <?php endif; ?>
</div>
<div class="o-toolbar">
    <div class="o-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="dtSearch" placeholder="Sipariş no, müşteri veya ürün ara..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
    </div>
    <select id="dtStatus" class="o-sel">
        <option value="">Tüm Durumlar (<?= $allCount ?>)</option>
        <?php foreach ($stMap as $k => $c): $cnt = $statusCounts[$k] ?? 0; ?>
        <option value="<?= $k ?>" <?= $currentStatus === $k ? 'selected' : '' ?>><?= $c['l'] ?> (<?= $cnt ?>)</option>
        <?php endforeach; ?>
    </select>
    <input type="date" id="dtDateFrom" class="o-sel" onchange="reloadTable()" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
    <input type="date" id="dtDateTo" class="o-sel" onchange="reloadTable()" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
    <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="reloadTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
    <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="clearFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
    <div class="o-actions">
        <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="exportOrders()"><i data-lucide="download" style="width:14px;height:14px"></i> Dışa Aktar</button>
        <?php if ($canArchiveOrder): ?>
        <button type="button" id="archiveToggle" class="o-btn o-btn-outline o-btn-sm" onclick="toggleArchive()"><i data-lucide="archive" style="width:14px;height:14px"></i> <span id="archiveLabel">Arşiv</span></button>
        <?php endif; ?>
        <?php if ($canTriggerSync): ?>
        <button type="button" class="o-btn o-btn-danger o-btn-sm" onclick="if(confirm('Tüm siparişler silinip son 90 günün siparişleri baştan çekilecek. Emin misiniz?'))doSync(true)" title="Hepsini Sil ve Baştan Çek"><i data-lucide="rotate-ccw" style="width:14px;height:14px"></i> Sıfırla</button>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- TABLO kartı (Ürünler tasarımı) -->
<div class="o-card">
    <div class="o-bulk" id="bulkBar">
        <span><span id="bulkCount">0</span> sipariş seçili</span>
        <div class="o-bulk-actions">
            <?php if ($canCreateInvoice): ?>
            <form method="POST" action="/orders/bulk-invoice" id="bulkForm" style="display:inline">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div id="bulkInputs"></div>
                <button type="submit" class="o-btn o-btn-primary o-btn-sm"><i data-lucide="file-text" style="width:12px;height:12px"></i> Toplu Fatura Kes</button>
            </form>
            <?php endif; ?>
        </div>
        <button class="o-btn o-btn-outline o-btn-sm" onclick="clearSelection()"><i data-lucide="x" style="width:12px;height:12px"></i></button>
    </div>

    <div style="overflow-x:auto">
    <table id="ordersTable" class="o-table" style="width:100%;min-width:920px">
    <thead><tr>
        <th style="width:36px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
        <th>Sipariş No</th>
        <th>Pazaryeri</th>
        <th>Müşteri</th>
        <th style="text-align:center">Adet</th>
        <th style="text-align:right">Tutar</th>
        <th>Durum</th>
        <th>Tarih</th>
        <th style="text-align:right">İşlemler</th>
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
     Sipariş hızlı-bakış DRAWER (satıra / ⋯ tıkla) — detay + aksiyonlar
     ================================================================ -->
<div id="odBd" onclick="closeOrderDrawer()" class="fixed inset-0 z-40 bg-slate-900/30 dark:bg-black/50 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-200"></div>
<aside id="orderDrawer" class="fixed top-0 right-0 z-50 h-full w-[460px] max-w-full bg-surface shadow-pop translate-x-full transition-transform duration-200 ease-out flex flex-col">
    <div class="flex items-center justify-between px-5 h-16 border-b border-slate-200/70 dark:border-white/[.06] shrink-0">
        <div class="flex items-center gap-2.5"><span id="odNo" class="font-mono font-semibold text-[14px]">#—</span><span id="odStatus"></span></div>
        <button onclick="closeOrderDrawer()" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500"><i data-lucide="x" class="w-[18px] h-[18px]"></i></button>
    </div>
    <div class="flex-1 overflow-y-auto p-5 space-y-5">
        <div class="flex items-center justify-between">
            <img id="odMp" class="mp-logo" style="height:18px" src="" alt="">
            <span id="odDate" class="text-[12.5px] text-slate-400"></span>
        </div>
        <div class="flex items-center gap-3">
            <span id="odCustAv" class="grid place-items-center w-9 h-9 rounded-full bg-slate-100 dark:bg-white/10 text-[11px] font-bold text-slate-500"></span>
            <div class="min-w-0"><div id="odCustomer" class="text-[13.5px] font-semibold truncate"></div><div id="odCustEmail" class="text-[12px] text-slate-400 truncate"></div></div>
        </div>
        <div>
            <div class="eyebrow text-slate-400 mb-2.5">Ürünler</div>
            <div id="odItems" class="space-y-3"></div>
        </div>
        <div id="odBreakdown" class="rounded-xl bg-slate-50 dark:bg-white/[.03] p-3.5 space-y-1.5 text-[13px]"></div>
        <div>
            <div class="eyebrow text-slate-400 mb-3">Durum</div>
            <div id="odTimeline"></div>
        </div>
    </div>
    <div id="odActions" class="p-4 border-t border-slate-200/70 dark:border-white/[.06] shrink-0"></div>
</aside>
<script>
function openOrderDrawerFromRow(tr){ if(!tr) return; var r=table.row(tr).data(); if(!r) return; populateOrderDrawer(r); document.getElementById('orderDrawer').classList.remove('translate-x-full'); var bd=document.getElementById('odBd'); bd.classList.remove('opacity-0','pointer-events-none'); }
function closeOrderDrawer(){ var dr=document.getElementById('orderDrawer'); if(!dr) return; dr.classList.add('translate-x-full'); var bd=document.getElementById('odBd'); bd.classList.add('opacity-0','pointer-events-none'); }
function odStatusBadge(st){ var s=ST_MAP[st]||{l:st,c:'#64748b'}; return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-[11px] font-bold text-white" style="background:'+s.c+'">'+esc(s.l)+'</span>'; }
function odBreakRow(label,val,strong){ return '<div class="flex justify-between '+(strong?'font-bold text-[13.5px]':'')+'"><span class="'+(strong?'':'text-slate-500')+'">'+label+'</span><span class="tnum '+(strong?'':'font-medium text-slate-700 dark:text-slate-200')+'">'+val+'</span></div>'; }
function odStep(icon,color,label,sub,last){
    var dot=icon?'<span class="relative z-10 grid place-items-center w-5 h-5 rounded-full text-white shrink-0" style="background:'+color+'"><i data-lucide="'+icon+'" class="w-3 h-3"></i></span>':'<span class="relative z-10 grid place-items-center w-5 h-5 rounded-full shrink-0 border-2" style="border-color:'+color+';background:#fff"></span>';
    var line=last?'':'<span class="absolute left-[9px] top-5 -bottom-1 w-0.5 bg-slate-200 dark:bg-white/10"></span>';
    return '<div class="relative flex gap-3 pb-4 last:pb-0">'+line+dot+'<div class="-mt-0.5 min-w-0"><div class="text-[13px] font-medium leading-tight">'+label+'</div>'+(sub?'<div class="text-[11.5px] text-slate-400 mt-0.5">'+sub+'</div>':'')+'</div></div>';
}
function odTimeline(status, orderedAt){
    if(status==='iptal'||status==='iade'){
        return odStep('check','#10b981','Sipariş alındı',fmtDate(orderedAt),false)
             + odStep('x','#ef4444',(status==='iptal'?'İptal edildi':'İade edildi'),'şimdi',true);
    }
    var rank={yeni:1,beklemede:1,onay_bekliyor:1,hazirlaniyor:2,faturalandi:2,kargoda:3,teslim_noktasinda:3,teslim_edilemedi:3,teslim_edildi:4}[status]||1;
    var steps=[
        {done:'Sipariş alındı', up:'Sipariş alındı', cur:'Sipariş alındı', r:1},
        {done:'Hazırlandı', cur:'Hazırlanıyor', up:'Hazırlanacak', r:2},
        {done:'Kargolandı', cur:'Kargoda', up:'Kargolanacak', r:3},
        {done:'Teslim edildi', cur:'Teslim edildi', up:'Teslimat', r:4}
    ];
    var html='';
    steps.forEach(function(s,i){
        var state = rank>s.r ? 'done' : (rank===s.r ? 'current' : 'up');
        var color = state==='done' ? '#10b981' : (state==='current' ? '#2563eb' : '#cbd5e1');
        var label = state==='up' ? s.up : (state==='current' ? s.cur : s.done);
        var sub   = state==='current' ? 'şimdi' : (state==='done' ? (i===0 ? fmtDate(orderedAt) : 'tamamlandı') : '');
        var icon  = state==='done' ? 'check' : '';
        html += odStep(icon, color, label, sub, i===steps.length-1);
    });
    return html;
}
function odActions(r){
    var canShip=CAN_SHIP_ACTION && ['yeni','beklemede','hazirlaniyor','faturalandi'].indexOf(r.status)!==-1;
    var a='<div class="flex items-center gap-2">';
    if(!r.invoice_id && CAN_CREATE_INVOICE){ a+='<form method="POST" action="/orders/'+r.id+'/invoice" onsubmit="return confirm(\'e-Arşiv fatura kesilecek. Onaylıyor musunuz?\')"><input type="hidden" name="_csrf_token" value="'+CSRF+'"><button type="submit" class="t inline-flex items-center gap-1.5 h-10 px-3 rounded-xl text-[12px] font-medium bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-400 whitespace-nowrap"><i data-lucide="file-text" class="w-4 h-4"></i> Fatura Kes</button></form>'; }
    a+='<a href="/orders/'+r.id+'" class="t flex-1 inline-flex items-center justify-center gap-1.5 h-10 rounded-xl bg-brand text-white text-[12.5px] font-semibold hover:brightness-110 shadow-sm whitespace-nowrap">Tam Detay <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i></a>';
    if(canShip){ a+='<button onclick="quickShip('+r.id+',\''+esc(r.marketplace_slug)+'\',\''+esc(r.marketplace_order_id)+'\')" class="t grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-400" title="Kargola"><i data-lucide="send" class="w-4 h-4"></i></button>'; }
    if(r.cargo_tracking_no){ a+='<a href="/orders/'+r.id+'/cargo-label" target="_blank" class="t grid place-items-center w-10 h-10 shrink-0 rounded-xl border border-slate-200/80 dark:border-white/10 text-slate-500 hover:shadow-card" title="Etiket"><i data-lucide="tag" class="w-4 h-4"></i></a>'; }
    if(CAN_ARCHIVE_ORDER){ a+='<button onclick="archiveOrder('+r.id+')" class="t grid place-items-center w-10 h-10 shrink-0 rounded-xl border border-slate-200/80 dark:border-white/10 text-slate-500 hover:shadow-card" title="'+(r.is_archived?'Arşivden Çıkar':'Arşivle')+'"><i data-lucide="'+(r.is_archived?'archive-restore':'archive')+'" class="w-4 h-4"></i></button>'; }
    a+='</div>';
    return a;
}
function populateOrderDrawer(r){
    var d=document;
    d.getElementById('odNo').textContent='#'+(r.marketplace_order_id||'');
    d.getElementById('odStatus').innerHTML=odStatusBadge(r.status);
    var mp=d.getElementById('odMp'); mp.style.display=''; mp.src='/public/assets/img/marketplaces/'+(r.marketplace_slug||'')+'.svg'; mp.onerror=function(){ this.onerror=function(){this.style.display='none'}; this.src='/public/assets/img/marketplaces/'+(r.marketplace_slug||'')+'.png'; };
    d.getElementById('odDate').textContent=fmtDate(r.ordered_at);
    var cn=String(r.customer_name||'').trim();
    d.getElementById('odCustomer').textContent=cn||'—';
    d.getElementById('odCustAv').textContent=cn?initials(cn):'?';
    d.getElementById('odCustEmail').textContent='';
    d.getElementById('odTimeline').innerHTML=odTimeline(r.status, r.ordered_at);
    d.getElementById('odItems').innerHTML='<div class="flex items-center gap-2 text-[13px] text-slate-400 py-2"><i data-lucide="loader" style="width:15px;height:15px;animation:spin 1s linear infinite"></i> Ürünler yükleniyor…</div>';
    d.getElementById('odBreakdown').innerHTML=odBreakRow('Genel toplam', fmtMoney(r.total_amount||0), true);
    d.getElementById('odActions').innerHTML=odActions(r);
    if(window.lucide) lucide.createIcons();
    fetch('/orders/'+r.id+'/quick',{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(x){ return x.json(); })
      .then(function(j){
        if(!j || !j.success){ d.getElementById('odItems').innerHTML='<div class="text-[13px] text-slate-400">Ürün bilgisi alınamadı.</div>'; return; }
        var items=j.items||[], ord=j.order||{};
        var em=ord.customer_email||ord.email||''; if(em) d.getElementById('odCustEmail').textContent=em;
        var sub=0;
        d.getElementById('odItems').innerHTML = items.length ? items.map(function(it){
            var qty=parseInt(it.quantity!=null?it.quantity:(it.qty!=null?it.qty:1))||1;
            var line=parseFloat(it.total_price!=null?it.total_price:(it.total!=null?it.total:((parseFloat(it.unit_price||it.price||0))*qty)))||0;
            sub+=line;
            var nm=esc(it.product_name||it.name||it.title||'Ürün');
            var sku=esc(it.product_sku||it.sku||it.merchant_sku||'');
            var im=it.item_image||it.product_main_image||'';
            var thumb=im?'<img src="'+esc(im)+'" class="w-11 h-11 rounded-lg object-cover shrink-0" onerror="this.style.display=\'none\'">':'<span class="grid place-items-center w-11 h-11 rounded-lg bg-slate-100 dark:bg-white/10 text-slate-400 shrink-0"><i data-lucide="package" class="w-4 h-4"></i></span>';
            return '<div class="flex items-center gap-3">'+thumb+'<div class="flex-1 min-w-0"><div class="text-[13px] font-medium leading-tight" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">'+nm+'</div><div class="text-[12px] text-slate-400 mt-0.5">'+(sku?'<span class="font-mono">'+sku+'</span> · ':'')+qty+' adet</div></div><div class="text-[13px] font-semibold tnum whitespace-nowrap">'+fmtMoney(line)+'</div></div>';
        }).join('') : '<div class="text-[13px] text-slate-400">Ürün bulunamadı.</div>';
        var total=parseFloat(ord.total_amount!=null?ord.total_amount:(r.total_amount||0))||0;
        var cargo=Math.round((total-sub)*100)/100;
        var bd='';
        if(sub>0 && Math.abs(total-sub)>0.009){
            bd+=odBreakRow('Ara toplam', fmtMoney(sub), false);
            bd+=odBreakRow('Kargo', cargo>0.009?fmtMoney(cargo):'Ücretsiz', false);
            bd+='<div class="border-t border-slate-200/70 dark:border-white/10 my-1.5"></div>';
        }
        bd+=odBreakRow('Genel toplam', fmtMoney(total), true);
        d.getElementById('odBreakdown').innerHTML=bd;
        if(window.lucide) lucide.createIcons();
      })
      .catch(function(){ d.getElementById('odItems').innerHTML='<div class="text-[13px] text-slate-400">Ürün bilgisi alınamadı.</div>'; });
}
$(document).on('click','#ordersTable tbody tr',function(e){ if($(e.target).closest('input,button,a,label,form,.rowCheck').length) return; openOrderDrawerFromRow(this); });
document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeOrderDrawer(); });
</script>

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
        if(r.invoice_id) return '<span class="grid place-items-center text-slate-400" title="Faturalı"><i data-lucide="check" class="w-3.5 h-3.5"></i></span>';
        const chk = selectedSet.has(r.id) ? 'checked' : '';
        return '<input type="checkbox" class="rowCheck w-4 h-4 rounded border-slate-300 dark:border-white/20 text-brand focus:ring-brand/40 cursor-pointer align-middle" value="'+r.id+'" '+chk+'>';
    };
}
function renderMpBadge(d,t,r){
    const slug = r.marketplace_slug || '';
    return '<img class="mp-logo" style="height:16px" src="/public/assets/img/marketplaces/'+slug+'.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/'+slug+'.jpg\'" title="'+esc(slug)+'" alt="'+esc(slug)+'">';
}
function renderAmount(d){ return '<span class="tnum font-semibold">'+fmtMoney(d)+'</span>'; }
function renderStatus(d){
    const s = ST_MAP[d] || {l:d, c:'#64748b'};
    return '<span class="o-badge" style="background:'+s.c+'1a;color:'+s.c+'">'+esc(s.l)+'</span>';
}

/* === Mockup ana tablo render'ları (sade kolonlar; detay/aksiyon satır-tıkla drawer'da) === */
function initials(name){ const p=(name||'').trim().split(/\s+/).filter(Boolean); if(!p.length) return '–'; return ((p[0][0]||'')+(p.length>1?(p[p.length-1][0]||''):'')).toUpperCase(); }
function fmtDateShort(s){ if(!s) return '—'; const d=new Date(s.replace(' ','T')); if(isNaN(d)) return s; return d.toLocaleDateString('tr-TR',{day:'2-digit',month:'short'})+' · '+d.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}); }
function renderOrderNo(d,t,r){ return '<a href="/orders/'+r.id+'" class="font-mono text-[12px] font-medium text-slate-900 dark:text-white hover:text-brand" onclick="event.stopPropagation()">#'+esc(r.marketplace_order_id)+'</a>'; }
function renderCustomer(d,t,r){ const n=String(r.customer_name||'').trim(); if(!n) return '<span class="inline-flex items-center gap-2 text-slate-400"><span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 dark:bg-white/10"><i data-lucide="user" class="w-3 h-3"></i></span>—</span>'; return '<span class="inline-flex items-center gap-2"><span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 dark:bg-white/10 text-[10px] font-bold text-slate-500">'+esc(initials(n))+'</span>'+esc(n)+'</span>'; }
function renderQty(d,t,r){ const q=(parseInt(r.item_count)||0)>0?parseInt(r.item_count):((parseInt(r.extra_items)||0)+1); return '<span class="text-slate-500 tnum">'+q+'</span>'; }
function renderDate(d,t,r){ return '<span class="text-slate-400 tnum whitespace-nowrap">'+fmtDateShort(r.ordered_at)+'</span>'; }
function renderRowAction(d,t,r){ return '<div class="o-row-actions" style="justify-content:flex-end"><button class="o-ra" onclick="event.stopPropagation();openOrderDrawerFromRow(this.closest(\'tr\'))"><i data-lucide="eye" style="width:12px;height:12px"></i> Detay</button></div>'; }

/* ================================================================
   MAIN TABLE (all marketplaces)
   ================================================================ */
let selectedIds = new Set();
let showArchived = false;
let MAIN_MARKETPLACE_ID = <?= json_encode((string) $currentMp) ?>;

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
            if (json.mpOrderCounts) updateMpCounts(json.mpOrderCounts, json.recordsTotal);
            if (json.mpNewCounts) updateMpNewCounts(json.mpNewCounts);
            return json.data;
        }
    },
    columns: [
        { data: null, orderable: false, className: 'dt-center', width: '36px', render: renderCheck(selectedIds) },
        { data: null, orderable: false, render: renderOrderNo },
        { data: null, orderable: false, render: renderMpBadge },
        { data: null, orderable: false, render: renderCustomer },
        { data: null, orderable: false, className: 'dt-center', render: renderQty },
        { data: 'total_amount', orderable: false, className: 'dt-right', render: renderAmount },
        { data: 'status', orderable: false, render: renderStatus },
        { data: null, orderable: false, render: renderDate },
        { data: null, orderable: false, className: 'dt-right', render: renderRowAction }
    ],
    order: [],
    pageLength: 25,
    lengthMenu: [25, 50, 100],
    deferRender: true,
    autoWidth: false,
    searching: true,
    search: { search: <?= json_encode($filters['search'] ?? '') ?> },
    dom: 'rtip',
    language: {
        processing: '<div class="flex items-center gap-2"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
        emptyTable: '<div class="py-14 text-center text-slate-400"><i data-lucide="package" class="w-12 h-12 mx-auto mb-3"></i>Sipariş bulunamadı</div>',
        zeroRecords: '<div class="py-14 text-center text-slate-400"><i data-lucide="search-x" class="w-12 h-12 mx-auto mb-3"></i>Aramanıza uygun sipariş bulunamadı</div>',
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
    // pazaryeri pill'ini de "Tümü"ne sıfırla
    MAIN_MARKETPLACE_ID = '';
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    const allTab = document.querySelector('#mpSeg [data-mp-id=""]');
    if (allTab) allTab.classList.add('is-active');
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

/* Pazaryeri pills filtresi (Ürünler deseni) — ana tabloyu yerinde süzer.
   marketplace_id her ajax'ta gönderildiği için tab aktifliği sayfa değişince korunur. */
function setMpFilter(mpId, el){
    mpId = mpId || '';
    if (el.classList.contains('is-active') && mpId !== '') {
        return setMpFilter('', document.querySelector('#mpSeg [data-mp-id=""]'));
    }
    MAIN_MARKETPLACE_ID = mpId;
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    el.classList.add('is-active');
    table.ajax.reload();
}
function updateMpCounts(counts, total){
    counts = counts || {};
    document.querySelectorAll('#mpSeg [data-mp-cnt]').forEach(badge => {
        const key = badge.dataset.mpCnt;
        badge.textContent = key === 'all' ? (total || 0) : (counts[key] || 0);
    });
}
function updateMpNewCounts(counts){
    counts = counts || {};
    document.querySelectorAll('#mpSeg [data-mp-new]').forEach(badge => {
        const n = parseInt(counts[badge.dataset.mpNew], 10) || 0;
        badge.textContent = n + ' yeni';
        badge.hidden = n === 0;
        const tab = badge.closest('.mp-seg-tab');
        const img = tab ? tab.querySelector('img') : null;
        if (tab && img) tab.title = img.alt + (n > 0 ? ' · ' + n + ' yeni sipariş' : '');
    });
}

/* Dışa Aktar — mevcut filtrelerle CSV/Excel uç noktasına yönlendir */
function exportOrders(){
    const p = new URLSearchParams();
    if (MAIN_MARKETPLACE_ID) p.set('marketplace_id', MAIN_MARKETPLACE_ID);
    const st = document.getElementById('dtStatus');
    if (st && st.value) p.set('status', st.value);
    const df = document.getElementById('dtDateFrom');
    if (df && df.value) p.set('date_from', df.value);
    const dt = document.getElementById('dtDateTo');
    if (dt && dt.value) p.set('date_to', dt.value);
    const s = document.getElementById('dtSearch');
    if (s && s.value) p.set('search', s.value);
    if (showArchived) p.set('show_archived', '1');
    window.location = '/orders/export' + (p.toString() ? ('?' + p.toString()) : '');
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
        if(inp){
            inp.innerHTML = '';
            selectedIds.forEach(id => {
                const i = document.createElement('input'); i.type='hidden'; i.name='order_ids[]'; i.value=id;
                inp.appendChild(i);
            });
        }
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
            #syncOverlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;animation:syncFadeIn .25s ease}
            @keyframes syncFadeIn{from{opacity:0}to{opacity:1}}
            .sync-modal{background:var(--card-bg,#fff);border-radius:16px;box-shadow:0 0 0 1px var(--border);width:420px;max-width:92vw;overflow:hidden;animation:syncSlideUp .3s ease}
            @keyframes syncSlideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
            .sync-modal-header{display:flex;align-items:center;gap:14px;padding:20px 24px 12px;border-bottom:1px solid var(--border,#e5e7eb)}
            .sync-modal-icon{width:44px;height:44px;border-radius:12px;background:#10b981;display:flex;align-items:center;justify-content:center;color:#fff;animation:spin 2s linear infinite;flex-shrink:0}
            .sync-modal-icon.done{animation:none;background:#10b981}
            .sync-modal-icon.error{animation:none;background:#ef4444}
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
            .sync-progress-bar{height:100%;border-radius:3px;background:#10b981;transition:width .4s ease;width:0}
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
    if(btn) btn.setAttribute('data-on', showArchived ? 'true' : 'false');
    if(lbl) lbl.textContent = showArchived ? 'Arşivden Çık' : 'Arşiv';
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
        } else {
            Toast.show('Hata: '+(d.message||''),'error');
        }
    }).catch(()=>Toast.show('Bağlantı hatası!','error'));
}

/* ESC tuşu QuickShip modalını kapatır */
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        const qs = document.getElementById('qsOverlay');
        if(qs && qs.classList.contains('show')){ closeQuickShip(); }
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
.qs-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center}
.qs-overlay.show{display:flex}
.qs-modal{background:var(--bg-card);border-radius:12px;width:420px;max-width:95vw;box-shadow:0 0 0 1px var(--border);overflow:hidden;animation:slideUp .25s ease}
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
