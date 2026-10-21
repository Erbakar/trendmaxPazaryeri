<?php
$stats        = $data['stats'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$mpCounts     = $data['mpCounts'] ?? [];
$csrf         = $data['_csrf'] ?? '';
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
$storeName    = $data['_branding']['store_name'] ?? 'Ana Mağaza';

$totalCustomers = $stats['total'] ?? 0;
$totalRevenue   = $stats['total_revenue'] ?? 0;
$avgOrders      = $stats['avg_orders'] ?? 0;
$avgSpent       = $totalCustomers > 0 ? $totalRevenue / $totalCustomers : 0;

// Entitled (yetkili) pazaryerleri — dropdown'u besler
$entitledMarketplaces = array_filter($marketplaces, static fn($mp) => $canMarketplace((int) ($mp['id'] ?? 0)));
?>

<!-- ═══════════════════════════════════════════════════════════
     SAYFA BAŞLIĞI
     ═══════════════════════════════════════════════════════════ -->
<div class="ui-page-head">
  <div>
    <h1>Müşteriler</h1>
    <p>Tüm pazaryerlerinden gelen müşterilerinizi tek listede görün.</p>
  </div>
  <button type="button" class="ui-btn ui-btn-primary"><i data-lucide="download"></i> Dışa Aktar</button>
</div>

<!-- ═══════════════════════════════════════════════════════════
     İKİNCİL ALT-NAV
     ═══════════════════════════════════════════════════════════ -->
<!-- Müşteriler artık üst ana menüde; bu sayfa bağımsız (alt-nav yok). -->

<!-- ═══════════════════════════════════════════════════════════
     ÜST PANEL: pazaryeri pills + araç çubuğu (Ürünler tasarımı — birebir)
     ═══════════════════════════════════════════════════════════ -->
<div class="o-panel">
<div id="mpSeg" role="tablist">
    <button type="button" class="mp-seg-tab is-active" data-mp-id="" onclick="setMpFilter('', this)">
        <svg class="mp-seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Tümü</span>
        <span class="mp-seg-count" data-mp-cnt="all"><?= number_format($totalCustomers) ?></span>
    </button>
    <?php foreach ($marketplaces as $mp):
        $s = $mp['slug'] ?? '';
        $cnt = $mpCounts[$mp['id']] ?? 0;
        $isInactive = !$canMarketplace((int) $mp['id']);
        $nm = htmlspecialchars($mp['display_name'] ?? $s);
    ?>
    <?php if ($isInactive): ?>
    <span class="mp-seg-tab is-disabled" title="<?= $nm ?> · Pasif (yetki yok)">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <svg class="mp-seg-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </span>
    <?php else: ?>
    <button type="button" class="mp-seg-tab" data-mp-id="<?= (int)$mp['id'] ?>" onclick="setMpFilter('<?= (int)$mp['id'] ?>', this)" title="<?= $nm ?>">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <span class="mp-seg-count" data-mp-cnt="<?= (int)$mp['id'] ?>"><?= $cnt ?></span>
    </button>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<div class="o-toolbar">
    <div class="o-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="cuMainSearch" placeholder="Müşteri adı, e-posta veya telefon ara...">
    </div>
    <div class="o-actions">
        <button type="button" class="o-btn o-btn-outline o-btn-sm" onclick="cuClearMainFilters()"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</button>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MÜŞTERİ TABLOSU KARTI
     ═══════════════════════════════════════════════════════════ -->
<div class="o-card">
    <div style="overflow-x:auto">
    <table id="cuMainTable" class="o-table" style="width:100%;min-width:920px">
        <thead><tr>
            <th>Müşteri</th>
            <th>Telefon</th>
            <th>Şehir</th>
            <th style="text-align:right">Sipariş</th>
            <th style="text-align:right">Toplam Harcama</th>
            <th>Son Sipariş</th>
            <th style="text-align:right">İşlem</th>
        </tr></thead>
        <tbody></tbody>
    </table>
    </div>
    <div class="dt-footer"><div id="cuDtInfo"></div><div id="cuDtPaginate"></div></div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     EDIT MODAL
     ═══════════════════════════════════════════════════════════ -->
<div id="cuEditModal" class="hidden fixed inset-0 z-[10000] items-center justify-center bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm p-4">
  <div class="w-full max-w-[600px] max-h-[90vh] overflow-y-auto rounded-2xl bg-surface shadow-pop">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 dark:border-white/5">
      <h3 class="flex items-center gap-2 text-[16px] font-bold"><i data-lucide="user-pen" class="w-[18px] h-[18px] text-brand"></i> Müşteri Düzenle</h3>
      <button type="button" onclick="cuCloseModal()" class="t grid place-items-center w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] hover:text-slate-700 dark:hover:text-white"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <form id="cuEditForm" onsubmit="return cuSaveCustomer(event)">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" id="cuEditId" value="">
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">Ad Soyad</label>
            <input type="text" id="cueName" name="name" required class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">Telefon</label>
            <input type="text" id="cuePhone" name="phone" placeholder="05xx xxx xx xx" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">E-posta</label>
            <input type="email" id="cueEmail" name="email" placeholder="ornek@mail.com" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">TC / Kimlik No</label>
            <input type="text" id="cueIdNo" name="id_no" placeholder="11 haneli TC Kimlik No" maxlength="11" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">Vergi No (Kurumsal)</label>
            <input type="text" id="cueTaxNo" name="tax_no" placeholder="10 haneli VKN" maxlength="10" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">Vergi Dairesi</label>
            <input type="text" id="cueTaxOffice" name="tax_office" placeholder="Vergi dairesi adı" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">İl</label>
            <input type="text" id="cueCity" name="city" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5">
            <label class="eyebrow text-slate-500">İlçe</label>
            <input type="text" id="cueDistrict" name="district" class="t h-10 px-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]">
          </div>
          <div class="flex flex-col gap-1.5 md:col-span-2">
            <label class="eyebrow text-slate-500">Adres</label>
            <textarea id="cueAddress" name="address" rows="2" class="t px-3.5 py-2.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none resize-y focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]"></textarea>
          </div>
          <div class="flex flex-col gap-1.5 md:col-span-2">
            <label class="eyebrow text-slate-500">Notlar</label>
            <textarea id="cueNotes" name="notes" rows="2" placeholder="Müşteri hakkında notlar..." class="t px-3.5 py-2.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] text-[13.5px] outline-none resize-y focus:bg-surface focus:ring-2 focus:ring-brand/40 dark:focus:bg-white/[.07]"></textarea>
          </div>
        </div>
      </div>
      <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-slate-100 dark:border-white/5">
        <button type="button" onclick="cuCloseModal()" class="t flex items-center gap-2 h-10 px-4 rounded-xl border border-slate-200/80 dark:border-white/10 text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:shadow-card">İptal</button>
        <button type="submit" id="cuSaveBtn" class="t flex items-center gap-2 h-10 px-4 rounded-xl bg-brand text-white text-[13.5px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25">
          <i data-lucide="save" class="w-4 h-4"></i> Kaydet
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════ -->
<script>
const CU_CSRF = <?= json_encode($csrf) ?>;
const CU_PAGE_SIZE = 25;
const CU_AVATAR_COLORS = ['#2563eb','#2563eb','#ec4899','#f59e0b','#10b981','#06b6d4','#ef4444','#f97316'];

let cuMainTable = null;
let cuActiveMpId = '';   // pazaryeri filtresi — '' = Tümü (varsayılan)

const cuEsc = v => { const d = document.createElement('div'); d.textContent = v || ''; return d.innerHTML; };
const cuToast = (m, t = 'info') => typeof Toast !== 'undefined' && typeof Toast.show === 'function' ? Toast.show(m, t) : console.log(t + ': ' + m);
const cuFmtMoney = n => parseFloat(n || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
const cuFmtDate = s => {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return s;
    return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' });
};

/* ═══ Column Renderers (mockup Tailwind çıktısı) ═══ */
function cuRenderCustomer(data, type, row, meta) {
    const initial = (row.name || 'M').charAt(0).toUpperCase();
    const isFirst = meta.row === 0;
    const avatarCls = isFirst
        ? 'grid place-items-center w-8 h-8 rounded-full bg-brand/10 text-[11px] font-bold text-brand'
        : 'grid place-items-center w-8 h-8 rounded-full bg-slate-100 dark:bg-white/10 text-[11px] font-bold text-slate-500';
    const email = row.email && !String(row.email).includes('trendyolmail') ? cuEsc(row.email) : '';
    const idNo = row.id_no || '';
    let idBadge = '';
    if (!idNo) {
        idBadge = '<span class="text-[11px] font-bold text-danger" title="TC/VKN girilmemiş">⚠ TC Yok</span>';
    } else if (idNo === '11111111111' || idNo === '1111111111') {
        idBadge = '<span class="font-mono text-[11px] px-2 py-0.5 rounded-md bg-slate-900/[.05] dark:bg-white/10 text-amber-500" title="Pazaryerinden gelen varsayılan TC">' + cuEsc(idNo) + '</span>';
    } else {
        idBadge = '<span class="font-mono text-[11px] px-2 py-0.5 rounded-md bg-slate-900/[.05] dark:bg-white/10 text-slate-500" title="TC/VKN">' + cuEsc(idNo) + '</span>';
    }
    return '<span class="inline-flex items-center gap-2.5">'
        + '<span class="' + avatarCls + '">' + initial + '</span>'
        + '<span class="flex flex-col">'
        + '<span class="font-semibold text-slate-900 dark:text-white">' + cuEsc(row.name) + '</span>'
        + (email ? '<span class="text-[11px] text-slate-400">' + email + '</span>' : '')
        + '<span class="flex items-center gap-1.5 mt-0.5">' + idBadge + '</span>'
        + '</span></span>';
}

function cuRenderPhone(data) {
    return data ? '<span class="text-slate-500 tnum">' + cuEsc(data) + '</span>' : '<span class="text-slate-300">—</span>';
}

function cuRenderCity(data, type, row) {
    const city = [row.district, row.city].filter(Boolean).join(' / ');
    return city ? '<span class="text-slate-500">' + cuEsc(city) + '</span>' : '<span class="text-slate-300">—</span>';
}

function cuRenderOrders(data) {
    return '<span class="tnum font-semibold">' + parseInt(data || 0) + '</span>';
}

function cuRenderSpent(data) {
    return '<span class="tnum font-semibold text-slate-900 dark:text-white">' + cuFmtMoney(data) + '</span>';
}

function cuRenderLastOrder(data) {
    return '<span class="text-slate-400 tnum">' + cuFmtDate(data) + '</span>';
}

function cuRenderActions(data, type, row) {
    return '<div class="o-row-actions" style="justify-content:flex-end">'
        + '<a href="javascript:void(0)" class="o-ra" onclick="event.stopPropagation();cuOpenEdit(' + row.id + ')"><i data-lucide="edit-3" style="width:12px;height:12px"></i> Düzenle</a>'
        + '<a href="/customers/' + row.id + '" class="o-ra" onclick="event.stopPropagation()"><i data-lucide="eye" style="width:12px;height:12px"></i> Detay</a>'
        + '</div>';
}

/* ═══ DataTable columns definition ═══ */
function cuGetColumns(showMp) {
    const cols = [
        { data: null, orderable: true, render: cuRenderCustomer },
        { data: 'phone', orderable: true, render: cuRenderPhone },
        { data: null, orderable: true, render: cuRenderCity },
        { data: 'total_orders', orderable: true, className: 'text-right', render: cuRenderOrders },
        { data: 'total_spent', orderable: true, className: 'text-right', render: cuRenderSpent },
        { data: 'last_order_at', orderable: true, render: cuRenderLastOrder },
        { data: null, orderable: false, className: 'text-right', render: cuRenderActions }
    ];
    return cols;
}

/* ═══ Pazaryeri pills filtresi (Ürünler deseni) — ana tabloyu yerinde süzer.
   marketplace_id her ajax'ta gönderildiği için tab aktifliği sayfa değişince korunur. ═══ */
function setMpFilter(mpId, el) {
    mpId = mpId || '';
    if (el.classList.contains('is-active') && mpId !== '') {
        return setMpFilter('', document.querySelector('#mpSeg [data-mp-id=""]'));
    }
    cuActiveMpId = mpId;
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    el.classList.add('is-active');
    if (cuMainTable) cuMainTable.ajax.reload();  // filtre değişti → 1. sayfaya döner
}
function updateMpCounts(counts, total) {
    counts = counts || {};
    document.querySelectorAll('#mpSeg [data-mp-cnt]').forEach(badge => {
        const key = badge.dataset.mpCnt;
        badge.textContent = key === 'all' ? (total || 0) : (counts[key] || 0);
    });
}

/* ═══ Main Search ═══ */
function cuClearMainFilters() {
    document.getElementById('cuMainSearch').value = '';
    // pazaryeri pill'ini de "Tümü"ne sıfırla
    cuActiveMpId = '';
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    const allTab = document.querySelector('#mpSeg [data-mp-id=""]');
    if (allTab) allTab.classList.add('is-active');
    if (cuMainTable) cuMainTable.search('').ajax.reload();
}

let cuMainSearchTimer;
document.getElementById('cuMainSearch').addEventListener('input', function () {
    clearTimeout(cuMainSearchTimer);
    const val = this.value;
    cuMainSearchTimer = setTimeout(() => { if (cuMainTable) cuMainTable.search(val).draw(); }, 400);
});
document.getElementById('cuMainSearch').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(cuMainSearchTimer); if (cuMainTable) cuMainTable.search(this.value).draw(); }
});

/* ═══ ESC + Click-outside ═══ */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('cuEditModal').classList.contains('flex')) {
        cuCloseModal();
    }
});
document.getElementById('cuEditModal').addEventListener('click', function (e) {
    if (e.target === this) cuCloseModal();
});

/* ═══ Edit Modal ═══ */
function cuOpenEdit(customerId) {
    // Get row data from whichever table is visible
    let rowData = null;
    if (cuMainTable) {
        rowData = cuMainTable.rows().data().toArray().find(r => parseInt(r.id) === customerId);
    }
    if (!rowData) return;

    document.getElementById('cuEditId').value = rowData.id;
    document.getElementById('cueName').value = rowData.name || '';
    document.getElementById('cueEmail').value = (rowData.email && !String(rowData.email).includes('trendyolmail')) ? rowData.email : '';
    document.getElementById('cuePhone').value = rowData.phone || '';
    document.getElementById('cueIdNo').value = rowData.id_no || '';
    document.getElementById('cueTaxNo').value = rowData.tax_no || '';
    document.getElementById('cueTaxOffice').value = rowData.tax_office || '';
    document.getElementById('cueCity').value = rowData.city || '';
    document.getElementById('cueDistrict').value = rowData.district || '';
    document.getElementById('cueAddress').value = rowData.address || '';
    document.getElementById('cueNotes').value = rowData.notes || '';

    const m = document.getElementById('cuEditModal');
    m.classList.remove('hidden');
    m.classList.add('flex');
    if (typeof lucide !== 'undefined') lucide.createIcons();
    setTimeout(() => document.getElementById('cueName').focus(), 100);
}

function cuCloseModal() {
    const m = document.getElementById('cuEditModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}

function cuSaveCustomer(e) {
    e.preventDefault();
    const id = document.getElementById('cuEditId').value;
    const form = document.getElementById('cuEditForm');
    const fd = new FormData(form);
    const btn = document.getElementById('cuSaveBtn');
    const origBtn = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Kaydediliyor...';
    btn.disabled = true;

    fetch('/customers/' + id, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        btn.innerHTML = origBtn; btn.disabled = false;
        if (typeof lucide !== 'undefined') lucide.createIcons();
        if (d.success) {
            cuCloseModal();
            if (cuMainTable) cuMainTable.ajax.reload(null, false);
            cuToast('Müşteri bilgileri güncellendi!', 'success');
        } else {
            cuToast('Hata: ' + (d.message || 'Bilinmeyen hata'), 'error');
        }
    })
    .catch(() => {
        btn.innerHTML = origBtn; btn.disabled = false;
        if (typeof lucide !== 'undefined') lucide.createIcons();
        cuToast('Bağlantı hatası!', 'error');
    });
    return false;
}

/* ═══ Main DataTable Init ═══ */
document.addEventListener('DOMContentLoaded', function () {
    cuMainTable = $('#cuMainTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/customers/ajax',
            data: function (d) {
                d.marketplace_id = cuActiveMpId || '';
            },
            dataSrc: function (json) {
                if (json.mpCounts) updateMpCounts(json.mpCounts, json.recordsTotal);
                return json.data;
            }
        },
        columns: cuGetColumns(true),
        order: [[5, 'desc']],
        pageLength: CU_PAGE_SIZE,
        lengthMenu: [25, 50, 100],
        searching: true,
        dom: 'rtip',
        language: {
            processing: '<div class="inline-flex items-center gap-2"><i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Yükleniyor...</div>',
            emptyTable: '<div class="py-16 text-center text-slate-400"><i data-lucide="users" class="w-12 h-12 mx-auto mb-3 block text-slate-300"></i>Henüz müşteri kaydı yok.<br><span class="text-[12px]">Sipariş senkronizasyonu yapıldığında müşteriler otomatik oluşturulacak.</span></div>',
            zeroRecords: '<div class="py-16 text-center text-slate-400"><i data-lucide="search-x" class="w-12 h-12 mx-auto mb-3 block text-slate-300"></i>Aramanıza uygun müşteri bulunamadı</div>',
            info: 'Toplam _TOTAL_ müşteri · Sayfa _PAGE_/_PAGES_',
            infoEmpty: 'Müşteri yok',
            infoFiltered: '(_MAX_ kayıttan filtrelendi)',
            paginate: { previous: '<i data-lucide="chevron-left" class="w-4 h-4"></i>', next: '<i data-lucide="chevron-right" class="w-4 h-4"></i>' }
        },
        drawCallback: function () {
            if (typeof lucide !== 'undefined') lucide.createIcons();
            // Sayfalama + bilgi metnini özel .dt-footer kutularına taşı (Ürünler deseni)
            $('#cuMainTable_info').appendTo('#cuDtInfo');
            $('#cuMainTable_paginate').appendTo('#cuDtPaginate');
        }
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>
