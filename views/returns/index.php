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
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
$canApprove = $can('returns.approve');
$canReject = $can('returns.reject');
// Elle senkron: sync.trigger YA DA iadeleri görme yetkisi (AuthorizationService ile aynı kural)
$canTriggerSync = (bool) ($can('sync.trigger') || $can('returns.view'));
$currentMp = (string) ($filters['marketplace_id'] ?? '');
$allCount = (int) ($counts['all'] ?? ($initial['total'] ?? 0));
$stMap = ['requested'=>['l'=>'İşlem Bekliyor','c'=>'#f59e0b'],'approved'=>['l'=>'Onaylandı','c'=>'#10b981'],'rejected'=>['l'=>'Reddedildi','c'=>'#ef4444'],'completed'=>['l'=>'Tamamlandı','c'=>'#3b82f6'],'refunded'=>['l'=>'İade Edildi','c'=>'var(--primary)']];

// ── İstatistik kartları (view-side, backend DEĞİŞMEZ): statusCounts varsa onu, yoksa initialPayload satırlarından türet ──
$statusCounts = is_array($data['statusCounts'] ?? null) ? $data['statusCounts'] : [];
$rtRows = is_array($initial['rows'] ?? null) ? $initial['rows'] : [];
$rtOpen = (int) ($statusCounts['requested'] ?? 0);
$rtApproved = (int) (($statusCounts['approved'] ?? 0) + ($statusCounts['completed'] ?? 0) + ($statusCounts['refunded'] ?? 0));
$rtRejected = (int) ($statusCounts['rejected'] ?? 0);
if ($statusCounts === [] && $rtRows !== []) {
    foreach ($rtRows as $r) {
        $st = (string) ($r['status'] ?? '');
        if (!empty($r['action_required']) || $st === 'requested') {
            $rtOpen++;
        } elseif (in_array($st, ['approved', 'accepted', 'completed', 'refunded', 'manual_refund'], true)) {
            $rtApproved++;
        } elseif (in_array($st, ['rejected', 'denied'], true)) {
            $rtRejected++;
        }
    }
}
$rtDecided = $rtApproved + $rtRejected;
$rtRejectPct = $rtDecided > 0 ? (int) round(($rtRejected / $rtDecided) * 100) : 0;

$hdrStore = htmlspecialchars((string) ($data['_branding']['store_name'] ?? $data['_branding']['site_name'] ?? ''));
?>

<!-- ════════ İÇERİK ════════ -->

<!-- SAYFA BAŞLIĞI -->
<div class="ui-page-head">
  <div>
    <h1>İadeler</h1>
    <p>İade taleplerini pazaryeri bazında inceleyin ve sonuçlandırın.</p>
  </div>
  <?php if ($canTriggerSync): ?>
  <button type="button" id="btnRefresh" class="ui-btn ui-btn-primary"><i data-lucide="refresh-cw"></i> İadeleri Senkronize Et</button>
  <?php endif; ?>
</div>

<!-- İKİNCİL ALT-NAV -->
<?php $subnav = ['active' => '/returns', 'items' => [
  ['u' => '/orders', 'l' => 'Siparişler'],
  ['u' => '/returns', 'l' => 'İadeler'],
  ['u' => '/questions', 'l' => 'Sorular'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<!-- ÜST PANEL: pazaryeri pills + araç çubuğu (Ürünler tasarımı — birebir) -->
<div class="o-panel">
<div id="mpSeg" role="tablist">
    <button type="button" class="mp-seg-tab<?= ($currentMp === '') ? ' is-active' : '' ?>" data-mp-id="" onclick="setMpFilter('', this)">
        <svg class="mp-seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Tümü</span>
        <span class="mp-seg-count" data-mp-cnt="all"><?= (int) $allCount ?></span>
    </button>
    <?php foreach ($marketplaces as $mp):
        $s = $mp['slug'] ?? '';
        $cnt = $counts[$mp['id']] ?? 0;
        $isInactive = !$canMarketplace((int) $mp['id']);
        $nm = htmlspecialchars($mp['display_name'] ?? $s);
    ?>
    <?php if ($isInactive): ?>
    <span class="mp-seg-tab is-disabled" title="<?= $nm ?> · Pasif (yetki yok)">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <svg class="mp-seg-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </span>
    <?php else: ?>
    <button type="button" class="mp-seg-tab<?= ($currentMp === (string) $mp['id']) ? ' is-active' : '' ?>" data-mp-id="<?= (int)$mp['id'] ?>" onclick="setMpFilter('<?= (int)$mp['id'] ?>', this)" title="<?= $nm ?>">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <span class="mp-seg-count" data-mp-cnt="<?= (int)$mp['id'] ?>"><?= $cnt ?></span>
    </button>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<div class="o-toolbar">
    <div class="o-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="rtMainSearch" value="<?= htmlspecialchars((string) ($filters['search'] ?? '')) ?>" placeholder="İade no, sipariş no veya müşteri ara...">
    </div>
    <select id="rtMainStatus" class="o-sel">
        <option value="">Tüm durumlar</option>
        <?php foreach ($stMap as $k => $c): ?>
        <option value="<?= htmlspecialchars($k) ?>" <?= (($filters['status'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($c['l']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" id="rtMainDateFrom" class="o-sel" value="<?= htmlspecialchars((string) ($filters['date_from'] ?? '')) ?>">
    <input type="date" id="rtMainDateTo" class="o-sel" value="<?= htmlspecialchars((string) ($filters['date_to'] ?? '')) ?>">
    <button type="button" class="o-btn o-btn-primary o-btn-sm" onclick="reloadMainTable()"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
    <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="clearMainFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
</div>
</div>

<div class="o-card">
    <div style="overflow-x:auto">
    <table id="rtMainTable" class="o-table" style="width:100%;min-width:1080px">
        <thead><tr>
            <th>İade No</th>
            <th>Pazaryeri</th>
            <th>Sipariş No</th>
            <th>Müşteri</th>
            <th>Ürün</th>
            <th>Sebep</th>
            <th>Durum</th>
            <th style="text-align:right">Tarih</th>
            <th style="text-align:right">İşlem</th>
        </tr></thead>
        <tbody></tbody>
    </table>
    </div>
    <div class="dt-footer"><div id="rtInfo"></div><div id="rtPaginate"></div></div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODALS — Approve / Reject / Pend
     ═══════════════════════════════════════════════════════════ -->
<?php if ($canApprove): ?>
<div id="approveOverlay" class="hidden fixed inset-0 z-[10001] items-center justify-center p-4 bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)closeApproveModal()">
  <div class="w-full max-w-[520px] max-h-[90vh] overflow-auto rounded-2xl bg-surface shadow-pop">
    <div class="flex items-center justify-between px-5 h-14 border-b border-slate-100 dark:border-white/[.06]">
      <h3 class="text-[15px] font-bold">İade Onayla</h3>
      <button type="button" onclick="closeApproveModal()" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500"><i data-lucide="x" class="w-[18px] h-[18px]"></i></button>
    </div>
    <div class="p-5">
      <div class="rounded-xl p-3.5 mb-4 text-[13px] leading-relaxed bg-success/10 text-success"><strong>İade onaylanacak.</strong> Bu işlem geri alınamaz.</div>
      <div id="approveItems" class="space-y-2 mb-4"></div>
      <div class="flex items-center justify-end gap-2">
        <button type="button" onclick="closeApproveModal()" class="t inline-flex items-center h-9 px-4 rounded-lg text-[13px] font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.05] dark:hover:bg-white/[.08]">İptal</button>
        <button type="button" id="btnConfirmApprove" onclick="confirmApprove()" class="t inline-flex items-center h-9 px-4 rounded-lg bg-success text-white text-[13px] font-semibold hover:brightness-110">Onayla</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canReject): ?>
<div id="rejectOverlay" class="hidden fixed inset-0 z-[10001] items-center justify-center p-4 bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)closeRejectModal()">
  <div class="w-full max-w-[520px] max-h-[90vh] overflow-auto rounded-2xl bg-surface shadow-pop">
    <div class="flex items-center justify-between px-5 h-14 border-b border-slate-100 dark:border-white/[.06]">
      <h3 class="text-[15px] font-bold">İade Reddet</h3>
      <button type="button" onclick="closeRejectModal()" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500"><i data-lucide="x" class="w-[18px] h-[18px]"></i></button>
    </div>
    <div class="p-5">
      <div class="rounded-xl p-3.5 mb-4 text-[13px] leading-relaxed bg-danger/10 text-danger"><strong>İade reddedilecek.</strong> Ret sebebi zorunludur.</div>
      <div id="rejectItems" class="space-y-2 mb-4"></div>
      <div class="mb-3.5">
        <label for="rejectReasonSelect" class="block mb-1.5 text-[12px] font-semibold text-slate-500">Ret Sebebi *</label>
        <select id="rejectReasonSelect" class="w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13px] outline-none focus:ring-2 focus:ring-brand/40"><option value="">Yükleniyor...</option></select>
      </div>
      <div class="mb-4">
        <label for="rejectDescription" class="block mb-1.5 text-[12px] font-semibold text-slate-500">Açıklama</label>
        <textarea id="rejectDescription" placeholder="Opsiyonel ret açıklaması" class="w-full min-h-[90px] p-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13px] outline-none focus:ring-2 focus:ring-brand/40 resize-y"></textarea>
      </div>
      <div class="flex items-center justify-end gap-2">
        <button type="button" onclick="closeRejectModal()" class="t inline-flex items-center h-9 px-4 rounded-lg text-[13px] font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.05] dark:hover:bg-white/[.08]">İptal</button>
        <button type="button" id="btnConfirmReject" onclick="confirmReject()" class="t inline-flex items-center h-9 px-4 rounded-lg bg-danger text-white text-[13px] font-semibold hover:brightness-110">Reddet</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="pendOverlay" class="hidden fixed inset-0 z-[10001] items-center justify-center p-4 bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)closePendModal()">
  <div class="w-full max-w-[520px] max-h-[90vh] overflow-auto rounded-2xl bg-surface shadow-pop">
    <div class="flex items-center justify-between px-5 h-14 border-b border-slate-100 dark:border-white/[.06]">
      <h3 class="text-[15px] font-bold">İade Ertele (N11)</h3>
      <button type="button" onclick="closePendModal()" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500"><i data-lucide="x" class="w-[18px] h-[18px]"></i></button>
    </div>
    <div class="p-5">
      <div class="rounded-xl p-3.5 mb-4 text-[13px] leading-relaxed bg-warning/10 text-warning"><strong>İade ertelenecek.</strong> Erteleme sebebi ve gün sayısı zorunludur.</div>
      <div class="mb-3.5">
        <label for="pendReasonSelect" class="block mb-1.5 text-[12px] font-semibold text-slate-500">Erteleme Sebebi *</label>
        <select id="pendReasonSelect" class="w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13px] outline-none focus:ring-2 focus:ring-brand/40"><option value="">Yükleniyor...</option></select>
      </div>
      <div class="mb-3.5">
        <label for="pendDayCount" class="block mb-1.5 text-[12px] font-semibold text-slate-500">Erteleme Gün Sayısı * (1-30)</label>
        <input type="number" id="pendDayCount" min="1" max="30" value="3" class="w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13px] outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div class="mb-4">
        <label for="pendReasonNote" class="block mb-1.5 text-[12px] font-semibold text-slate-500">Açıklama</label>
        <textarea id="pendReasonNote" placeholder="Erteleme açıklaması" class="w-full min-h-[90px] p-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13px] outline-none focus:ring-2 focus:ring-brand/40 resize-y"></textarea>
      </div>
      <div class="flex items-center justify-end gap-2">
        <button type="button" onclick="closePendModal()" class="t inline-flex items-center h-9 px-4 rounded-lg text-[13px] font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.05] dark:hover:bg-white/[.08]">İptal</button>
        <button type="button" id="btnConfirmPend" onclick="confirmPend()" class="t inline-flex items-center h-9 px-4 rounded-lg bg-warning text-white text-[13px] font-semibold hover:brightness-110">Ertele</button>
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
const NO_RETURN_SLUGS = <?= json_encode($noReturnSlugs, JSON_UNESCAPED_UNICODE) ?>;
const RT_ST_MAP = <?= json_encode($stMap, JSON_UNESCAPED_UNICODE) ?>;

let RT_MARKETPLACE_ID = <?= json_encode($currentMp) ?>;
let mainTable = null;
let currentClaimId = '';
let currentClaimLineItemIds = [];
let currentMpSlug = '';
let issueReasonsLoaded = false;
let pendReasonsLoaded = false;

const toast = (m, t = 'info') => typeof Toast !== 'undefined' && typeof Toast.show === 'function' ? Toast.show(m, t) : console.log(t + ': ' + m);
/* HTML metni VE öznitelik değeri için güvenli kaçış (& < > " ' — title="…" / data-payload='…' bağlamı dahil). */
const RT_ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = v => String(v || '').replace(/[&<>"']/g, ch => RT_ESC_MAP[ch]);

function rtInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '—';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

/* ── Status dot-pill (renkler $stMap'ten, inline style korunur) ── */
function statusBadge(r) {
    const s = String(r.status || 'requested');
    const aliases = {
        accepted: 'approved', manual_refund: 'approved',
        denied: 'rejected', cancelled: 'rejected',
        waiting_in_action: 'requested', waiting_fraud_check: 'requested', unresolved: 'requested', in_analysis: 'requested', pending: 'requested', pended: 'requested', created: 'requested'
    };
    const key = RT_ST_MAP[s] ? s : (aliases[s] || 'requested');
    const c = (RT_ST_MAP[key] && RT_ST_MAP[key].c) || '#64748b';
    const label = r.status_label || (RT_ST_MAP[key] && RT_ST_MAP[key].l) || r.status || '—';
    return '<span class="o-badge" style="background:' + c + '1a;color:' + c + '">' + esc(label) + '</span>';
}

/* ── DataTable Column Renderers ── */
function renderReturnPulse(data, type, row) {
    var rid = String(row.marketplace_return_id || row.claim_id || '—');
    return '<span class="font-mono text-[11.5px] font-medium inline-block max-w-[120px] truncate align-middle" title="' + esc(rid) + '">#' + esc(rid) + '</span>';
}

function renderReturnMarketplace(data, type, row) {
    const slug = row.marketplace_slug || '';
    const name = row.marketplace_name || slug;
    if (!slug) return esc(name);
    return '<img class="mp-logo" style="height:16px" src="/public/assets/img/marketplaces/' + esc(slug) + '.png" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + esc(slug) + '.jpg\'" alt="' + esc(name) + '">';
}

function renderReturnProduct(data, type, row) {
    const items = Array.isArray(row.items) ? row.items : [];
    const f = items[0] || {};
    const extra = Number(row.item_count || 0) > 1 ? '<span class="text-brand font-semibold"> +' + (Number(row.item_count || 0) - 1) + '</span>' : '';
    return '<span class="block text-slate-600 dark:text-slate-300 max-w-[180px] truncate" title="' + esc(f.product_name || '') + '">' + esc(f.product_name || '—') + extra + '</span>';
}

function renderReturnOrder(data, type, row) {
    if (row.order_id) return '<a href="/orders/' + esc(String(row.order_id)) + '" class="font-mono text-[12px] text-slate-500 hover:text-brand">#' + esc(row.marketplace_order_id || '—') + '</a>';
    return '<span class="font-mono text-[12px] text-slate-500">#' + esc(row.marketplace_order_id || '—') + '</span>';
}

function renderReturnCustomer(data, type, row) {
    const name = String(row.customer_name || '').trim();
    if (!name) return '<span class="inline-flex items-center gap-2 text-slate-400"><span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 dark:bg-white/10"><i data-lucide="user" class="w-3 h-3"></i></span>—</span>';
    return '<span class="inline-flex items-center gap-2"><span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 dark:bg-white/10 text-[10px] font-bold text-slate-500">' + esc(rtInitials(name)) + '</span>' + esc(name) + '</span>';
}

function renderReturnReason(data, type, row) {
    return '<span class="text-slate-500" title="' + esc(row.reason || '') + '">' + esc(row.reason || '—') + '</span>';
}

function renderReturnStatus(data, type, row) {
    return statusBadge(row);
}

function renderReturnDate(data, type, row) {
    const raw = String(row.claim_date || row.created_at || '').trim();
    if (!raw) return '<span class="text-slate-400 tnum whitespace-nowrap">—</span>';
    const ts = Date.parse(raw.replace(' ', 'T'));
    const txt = Number.isNaN(ts) ? raw : new Date(ts).toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    return '<span class="text-slate-400 tnum whitespace-nowrap">' + esc(txt) + '</span>';
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
        if (CAN_APPROVE) h += '<button type="button" class="o-ra o-ra-success" data-action="approve" data-claim="' + esc(claimId) + '" data-ids=\'' + esc(ids) + '\' data-payload=\'' + esc(payload) + '\' data-mp="' + esc(mpSlug) + '"><i data-lucide="check" class="w-3.5 h-3.5"></i>Onayla</button>';
        if (isN11) h += '<button type="button" class="o-ra" data-action="pend" data-claim="' + esc(claimId) + '" data-mp="' + esc(mpSlug) + '"><i data-lucide="clock" class="w-3.5 h-3.5"></i>Ertele</button>';
        if (CAN_REJECT) h += '<button type="button" class="o-ra o-ra-danger" data-action="reject" data-claim="' + esc(claimId) + '" data-ids=\'' + esc(ids) + '\' data-payload=\'' + esc(payload) + '\' data-mp="' + esc(mpSlug) + '"><i data-lucide="x" class="w-3.5 h-3.5"></i>Reddet</button>';
        return '<div class="o-row-actions" style="justify-content:flex-end">' + h + '</div>';
    }
    const detailBtn = '<div class="o-row-actions" style="justify-content:flex-end"><button type="button" class="o-ra"><i data-lucide="eye" style="width:12px;height:12px"></i>Detay</button></div>';
    if (isPttavm && row.action_required) return '<div class="flex items-center justify-end"><span class="text-[11px] text-slate-400">Panel gerekli</span></div>';
    if (row.status === 'accepted' || row.status === 'approved') return detailBtn;
    if (row.status === 'rejected') return detailBtn;
    if (row.status === 'pending' || row.status === 'pended') return detailBtn;
    if (row.status === 'manual_refund') return detailBtn;
    return detailBtn;
}

/* ── Main DataTable Init ── */
document.addEventListener('DOMContentLoaded', function() {
    mainTable = $('#rtMainTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/returns/ajax',
            data: function(d) {
                // Pazaryeri filtresi (dropdown kaynaklı) her ajax'ta (sayfalama dahil) gönderilir.
                d.marketplace_id = RT_MARKETPLACE_ID || '';
                d.status = document.getElementById('rtMainStatus').value;
                d.date_from = document.getElementById('rtMainDateFrom').value;
                d.date_to = document.getElementById('rtMainDateTo').value;
                d.search = document.getElementById('rtMainSearch').value.trim();
            },
            dataSrc: function(json) {
                if (json.mpReturnCounts) updateMpCounts(json.mpReturnCounts, json.recordsTotal);
                return json.data;
            }
        },
        columns: [
            { data: null, orderable: false, render: renderReturnPulse },
            { data: null, orderable: false, render: renderReturnMarketplace },
            { data: null, orderable: true, render: renderReturnOrder },
            { data: null, orderable: false, render: renderReturnCustomer },
            { data: null, orderable: false, render: renderReturnProduct },
            { data: null, orderable: false, render: renderReturnReason },
            { data: 'status', orderable: true, render: renderReturnStatus },
            { data: null, orderable: true, className: 'text-right', render: renderReturnDate },
            { data: null, orderable: false, className: 'text-right', render: renderReturnActions }
        ],
        order: [[7, 'desc']],
        pageLength: PAGE_SIZE,
        lengthMenu: [25, 50, 100],
        searching: false,
        dom: 'rtip',
        language: {
            processing: '<div style="display:flex;align-items:center;gap:8px"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
            emptyTable: '<div class="py-12 text-center text-slate-400"><i data-lucide="inbox" style="width:48px;height:48px;margin:0 auto 12px;display:block"></i>İade talebi bulunamadı</div>',
            zeroRecords: '<div class="py-12 text-center text-slate-400"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block"></i>Aramanıza uygun iade bulunamadı</div>',
            info: 'Toplam _TOTAL_ iade · Sayfa _PAGE_/_PAGES_',
            infoEmpty: 'İade yok',
            infoFiltered: '(_MAX_ kayıttan filtrelendi)',
            paginate: { previous: '<i data-lucide="chevron-left" style="width:14px;height:14px"></i>', next: '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>' }
        },
        drawCallback: function() { lucide.createIcons(); $('#rtMainTable_info').appendTo('#rtInfo'); $('#rtMainTable_paginate').appendTo('#rtPaginate'); }
    });

    // Ana tablo filtre kontrolleri — değişince otomatik reload (ayrı Filtrele butonu yok)
    let rtMainSearchTimer;
    const rtMainSearchEl = document.getElementById('rtMainSearch');
    if (rtMainSearchEl) {
        rtMainSearchEl.addEventListener('input', function() {
            clearTimeout(rtMainSearchTimer);
            rtMainSearchTimer = setTimeout(() => { if (mainTable) mainTable.ajax.reload(); }, 400);
        });
        rtMainSearchEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(rtMainSearchTimer); if (mainTable) mainTable.ajax.reload(); }
        });
    }
    const rtMainStatusEl = document.getElementById('rtMainStatus');
    if (rtMainStatusEl) rtMainStatusEl.addEventListener('change', function() { if (mainTable) mainTable.ajax.reload(); });
    const rtMainDateFromEl = document.getElementById('rtMainDateFrom');
    if (rtMainDateFromEl) rtMainDateFromEl.addEventListener('change', function() { if (mainTable) mainTable.ajax.reload(); });
    const rtMainDateToEl = document.getElementById('rtMainDateTo');
    if (rtMainDateToEl) rtMainDateToEl.addEventListener('change', function() { if (mainTable) mainTable.ajax.reload(); });

    // Pazaryeri filtresi = dropdown → RT_MARKETPLACE_ID kaynağı (param adı 'marketplace_id' aynı)
    const rtMainMpEl = document.getElementById('rtMainMarketplace');
    if (rtMainMpEl) rtMainMpEl.addEventListener('change', function() {
        RT_MARKETPLACE_ID = this.value || '';
        if (mainTable) mainTable.ajax.reload();
    });

    lucide.createIcons();
});

function reloadMainTable() { if (mainTable) mainTable.ajax.reload(); }
function clearMainFilters() {
    document.getElementById('rtMainSearch').value = '';
    document.getElementById('rtMainStatus').value = '';
    document.getElementById('rtMainDateFrom').value = '';
    document.getElementById('rtMainDateTo').value = '';
    RT_MARKETPLACE_ID = '';
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    const allTab = document.querySelector('#mpSeg [data-mp-id=""]');
    if (allTab) allTab.classList.add('is-active');
    if (mainTable) mainTable.ajax.reload();
}
function setMpFilter(mpId, el){
    mpId = mpId || '';
    if (el.classList.contains('is-active') && mpId !== '') {
        return setMpFilter('', document.querySelector('#mpSeg [data-mp-id=""]'));
    }
    RT_MARKETPLACE_ID = mpId;
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    el.classList.add('is-active');
    if (mainTable) mainTable.ajax.reload();
}
function updateMpCounts(counts, total){
    counts = counts || {};
    document.querySelectorAll('#mpSeg [data-mp-cnt]').forEach(badge => {
        const key = badge.dataset.mpCnt;
        badge.textContent = key === 'all' ? (total || 0) : (counts[key] || 0);
    });
}

/* ── Sync Button — SSE Progress Modal ── */
document.getElementById('btnRefresh')?.addEventListener('click', function() {
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
                <button class="t inline-flex items-center justify-center h-9 px-4 rounded-lg bg-brand text-white text-[13px] font-semibold hover:brightness-110" onclick="document.getElementById('syncOverlay').style.display='none'">Tamam</button>
            </div>
        </div>`;
        document.body.appendChild(overlay);

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
                btnRef.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> İadeleri Senkronize Et';
                lucide.createIcons();
                if (mainTable) mainTable.ajax.reload(null, false);
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
                btnRef.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> İadeleri Senkronize Et';
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
        btnRef.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> İadeleri Senkronize Et';
        lucide.createIcons();
    });
});

/* ── Action Delegation ── */
function renderMini(items) {
    return (items || []).map(i => '<div class="flex items-center justify-between gap-2.5 px-3 py-2.5 rounded-xl border border-slate-100 dark:border-white/[.06]"><div><div class="text-[13px] font-semibold">' + esc(i.product_name || '—') + '</div><div class="text-[11px] text-slate-400">' + esc(i.merchant_sku || '') + '</div></div>' + (i.reason ? '<span class="text-[11px] text-slate-500">' + esc(i.reason) + '</span>' : '') + '</div>').join('');
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

/* ── ESC closes open modals ── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('approveOverlay')?.classList.contains('flex')) { closeApproveModal(); return; }
        if (document.getElementById('rejectOverlay')?.classList.contains('flex')) { closeRejectModal(); return; }
        if (document.getElementById('pendOverlay')?.classList.contains('flex')) { closePendModal(); return; }
    }
});

function rtShowModal(id) { const m = document.getElementById(id); if (m) { m.classList.remove('hidden'); m.classList.add('flex'); } }
function rtHideModal(id) { const m = document.getElementById(id); if (m) { m.classList.add('hidden'); m.classList.remove('flex'); } }

/* ── Approve Modal ── */
function openApproveModal(claimId, claimItemIds, items, mpSlug) {
    currentClaimId = claimId;
    currentClaimLineItemIds = Array.isArray(claimItemIds) ? claimItemIds : [];
    currentMpSlug = mpSlug || '';
    const list = document.getElementById('approveItems');
    if (list) list.innerHTML = renderMini(items);
    rtShowModal('approveOverlay');
    lucide.createIcons();
}
function closeApproveModal() { rtHideModal('approveOverlay'); }

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
    currentMpSlug = mpSlug || '';
    const list = document.getElementById('rejectItems');
    if (list) list.innerHTML = renderMini(items);
    document.getElementById('rejectDescription').value = '';
    rtShowModal('rejectOverlay');
    if (!issueReasonsLoaded) loadIssueReasons();
}
function closeRejectModal() { rtHideModal('rejectOverlay'); }

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
    rtShowModal('pendOverlay');
    if (!pendReasonsLoaded) loadPendReasons();
}
function closePendModal() { rtHideModal('pendOverlay'); }

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
    if (mainTable) mainTable.ajax.reload(null, false);
}

function openImageLightbox(url) {
    const bg = document.createElement('div');
    bg.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10002;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px';
    bg.onclick = () => bg.remove();
    const im = document.createElement('img');
    im.src = url;
    im.style.cssText = 'max-width:90vw;max-height:85vh;border-radius:12px;box-shadow:0 0 0 1px var(--border)';
    bg.appendChild(im);
    document.body.appendChild(bg);
}
</script>
