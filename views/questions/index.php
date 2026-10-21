<?php
$filters = $data['filters'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$supported = $data['supportedQuestionMarketplaceSlugs'] ?? [];
$noQaSlugs = $data['noQaSupportSlugs'] ?? [];
$statusCounts = $data['statusCounts'] ?? [];
$csrf = $data['_csrf'] ?? '';
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;
// RBAC (AuthorizationService ile aynı): yanıt + hazır yanıt yönetimi orders.ship,
// elle senkron sync.trigger YA DA yanıtlama yetkisi (orders.ship)
$qaCanAnswer = (bool) $can('orders.ship');
$qaCanSync = (bool) ($can('sync.trigger') || $qaCanAnswer);
$currentMp = (string) ($filters['marketplace_id'] ?? '');
$currentStatus = (string) ($filters['status'] ?? 'waiting');
if (!in_array($currentStatus, ['waiting', 'answered', 'rejected'], true)) {
    $currentStatus = 'waiting';
}
$storeName = $data['_branding']['store_name'] ?? 'Ana Mağaza';
$pageSize = 25;
// Entitlement ile süzülmüş pazaryeri listesi (dropdown).
$entitledMarketplaces = array_values(array_filter($marketplaces, static function ($mp) use ($canMarketplace) {
    return $canMarketplace((int) ($mp['id'] ?? 0));
}));
$waitingCount = (int) ($statusCounts['waiting'] ?? 0);
$answeredCount = (int) ($statusCounts['answered'] ?? 0);
$closedCount = (int) ($statusCounts['rejected'] ?? 0);
// Pazaryeri pills sayıları (Ürünler deseni) — entitlement-scoped, durumdan bağımsız toplamlar
$mpQuestionCounts = $data['mpQuestionCounts'] ?? [];
$qaAllCount = array_sum($mpQuestionCounts);
?>

<!-- SAYFA BAŞLIĞI -->
<div class="ui-page-head">
  <div>
    <h1>Müşteri Soruları</h1>
    <p>Müşteri sorularını tek yerden yanıtlayın, hazır yanıtlar oluşturun.</p>
  </div>
  <?php if ($qaCanSync || $qaCanAnswer): ?>
  <div class="ui-page-actions">
    <?php if ($qaCanSync): ?>
    <button type="button" id="btnSync" class="ui-btn ui-btn-outline">
      <i data-lucide="download-cloud"></i> Senkronize
    </button>
    <?php endif; ?>
    <?php if ($qaCanAnswer): ?>
    <button type="button" onclick="qaOpenCanned()" class="ui-btn ui-btn-primary">
      <i data-lucide="message-square-plus"></i> Hazır Yanıt Oluştur
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- İKİNCİL ALT-NAV -->
<?php $subnav = ['active' => '/questions', 'items' => [
  ['u' => '/orders', 'l' => 'Siparişler'],
  ['u' => '/returns', 'l' => 'İadeler'],
  ['u' => '/questions', 'l' => 'Sorular'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<!-- ÜST PANEL: pazaryeri pills + araç çubuğu (Ürünler tasarımı — birebir) -->
<div class="o-panel">
<div id="mpSeg" role="tablist">
    <button type="button" class="mp-seg-tab<?= $currentMp === '' ? ' is-active' : '' ?>" data-mp-id="" onclick="setMpFilter('', this)">
        <svg class="mp-seg-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span>Tümü</span>
        <span class="mp-seg-count" data-mp-cnt="all"><?= number_format($qaAllCount) ?></span>
    </button>
    <?php foreach ($marketplaces as $mp):
        $s = $mp['slug'] ?? '';
        $cnt = $mpQuestionCounts[$mp['id']] ?? 0;
        $isInactive = !$canMarketplace((int) $mp['id']);
        $nm = htmlspecialchars($mp['display_name'] ?? $s);
    ?>
    <?php if ($isInactive): ?>
    <span class="mp-seg-tab is-disabled" title="<?= $nm ?> · Pasif (yetki yok)">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <svg class="mp-seg-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </span>
    <?php else: ?>
    <button type="button" class="mp-seg-tab<?= $currentMp === (string)$mp['id'] ? ' is-active' : '' ?>" data-mp-id="<?= (int)$mp['id'] ?>" onclick="setMpFilter('<?= (int)$mp['id'] ?>', this)" title="<?= $nm ?>">
        <img src="/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($s) ?>.jpg'" alt="<?= $nm ?>">
        <span class="mp-seg-count" data-mp-cnt="<?= (int)$mp['id'] ?>"><?= $cnt ?></span>
    </button>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<div class="o-toolbar">
    <!-- Durum segment toggle (Bekleyen / Yanıtlanan / Kapalı) -->
    <div class="flex items-center p-1 rounded-xl bg-slate-900/[.04] dark:bg-white/[.06] text-[13px] font-medium" role="group" aria-label="Soru durumu">
      <button type="button" id="qaTabWaiting" data-qfilter data-status="waiting" aria-pressed="<?= $currentStatus === 'waiting' ? 'true' : 'false' ?>" onclick="qaSetStatusFilter('waiting', this)" class="t inline-flex items-center gap-2 px-3.5 h-9 rounded-lg<?= $currentStatus === 'waiting' ? ' bg-surface shadow-xs text-slate-900 dark:text-white' : ' text-slate-500' ?>">
        Bekleyen <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-warning/15 text-warning text-[11px] font-bold tnum" id="qaCntWaiting"><?= $waitingCount ?></span>
      </button>
      <button type="button" id="qaTabAnswered" data-qfilter data-status="answered" aria-pressed="<?= $currentStatus === 'answered' ? 'true' : 'false' ?>" onclick="qaSetStatusFilter('answered', this)" class="t inline-flex items-center gap-2 px-3.5 h-9 rounded-lg<?= $currentStatus === 'answered' ? ' bg-surface shadow-xs text-slate-900 dark:text-white' : ' text-slate-500' ?>">
        Yanıtlanan <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-slate-500/10 text-slate-500 text-[11px] font-bold tnum" id="qaCntAnswered"><?= $answeredCount ?></span>
      </button>
      <button type="button" id="qaTabClosed" data-qfilter data-status="rejected" aria-pressed="<?= $currentStatus === 'rejected' ? 'true' : 'false' ?>" onclick="qaSetStatusFilter('rejected', this)" title="Reddedilen ya da cevap süresi dolan sorular" class="t inline-flex items-center gap-2 px-3.5 h-9 rounded-lg<?= $currentStatus === 'rejected' ? ' bg-surface shadow-xs text-slate-900 dark:text-white' : ' text-slate-500' ?>">
        Kapalı <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-slate-500/10 text-slate-500 text-[11px] font-bold tnum" id="qaCntClosed"><?= $closedCount ?></span>
      </button>
    </div>
    <div class="o-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" id="qaSearch" placeholder="Soru veya ürün ara...">
    </div>
</div>
</div>

<!-- SORULAR TABLOSU (Ürünler tasarımı) -->
<div class="o-card">
    <div style="overflow-x:auto">
    <table id="qaMainTable" class="o-table" style="width:100%;min-width:900px">
        <thead><tr>
            <th>Pazaryeri</th>
            <th>Ürün</th>
            <th>Müşteri</th>
            <th>Soru</th>
            <th>Durum</th>
            <th>Tarih</th>
            <th style="text-align:right">Aksiyon</th>
        </tr></thead>
        <tbody></tbody>
    </table>
    </div>
    <div class="dt-footer"><div id="qaInfo"></div><div id="qaPaginate"></div></div>
</div>

<!-- ════════ HAZIR YANIT MODAL (#cannedModal) ════════ -->
<div id="cannedModal" hidden class="fixed inset-0 z-50 flex items-start justify-center pt-[10vh] px-4 bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)qaCloseCanned()">
  <div class="w-full max-w-[560px] rounded-2xl bg-surface shadow-pop overflow-hidden">
    <div class="flex items-center justify-between px-5 h-14 border-b border-slate-100 dark:border-white/5">
      <h3 class="text-[15px] font-semibold">Hazır Yanıtlar</h3>
      <button type="button" onclick="qaCloseCanned()" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/10 text-slate-500"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
      <form onsubmit="return qaCreateCanned(event)" class="space-y-3">
        <div>
          <label class="block text-[12px] font-medium text-slate-500 mb-1.5">Başlık</label>
          <input id="cannedTitle" required placeholder="Örn. Kargo süresi" class="w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] border border-slate-200/80 dark:border-white/10 outline-none text-[13px] focus:border-brand/40">
        </div>
        <div>
          <label class="block text-[12px] font-medium text-slate-500 mb-1.5">Yanıt Metni</label>
          <textarea id="cannedBody" required rows="3" placeholder="Hazır yanıt içeriği…" class="w-full px-3 py-2 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] border border-slate-200/80 dark:border-white/10 outline-none text-[13px] resize-y focus:border-brand/40"></textarea>
        </div>
        <button type="submit" class="t inline-flex items-center gap-2 h-9 px-4 rounded-lg bg-brand text-white text-[13px] font-semibold hover:brightness-110"><i data-lucide="plus" class="w-4 h-4"></i> Şablon Ekle</button>
      </form>
      <div class="border-t border-slate-100 dark:border-white/5 pt-4">
        <div class="eyebrow text-slate-400 mb-2">Mevcut Şablonlar</div>
        <div id="cannedList" class="space-y-2">
          <div class="text-[13px] text-slate-400">Yükleniyor…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ════════ YANITLA / GÖRÜNTÜLE MODAL (#qaAnswerModal) ════════ -->
<div id="qaAnswerModal" hidden class="fixed inset-0 z-50 flex items-start justify-center pt-[8vh] px-4 bg-slate-900/40 dark:bg-black/60 backdrop-blur-sm" onclick="if(event.target===this)qaCloseAnswer()">
  <div class="w-full max-w-[600px] rounded-2xl bg-surface shadow-pop overflow-hidden">
    <div class="flex items-center justify-between px-5 h-14 border-b border-slate-100 dark:border-white/5">
      <h3 class="text-[15px] font-semibold" id="qaAnswerTitle">Soruyu Yanıtla</h3>
      <button type="button" onclick="qaCloseAnswer()" class="t grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/10 text-slate-500"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <div class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
      <div id="qaAnswerProduct" class="flex items-center gap-3"></div>
      <div class="rounded-xl bg-slate-900/[.03] dark:bg-white/[.05] p-3.5">
        <div class="eyebrow text-slate-400 mb-1">Müşteri Sorusu</div>
        <p id="qaAnswerQuestionText" class="text-[13.5px] leading-relaxed text-slate-700 dark:text-slate-200"></p>
      </div>
      <div id="qaExistingAnswer" hidden class="rounded-xl border border-success/20 bg-success/[.06] p-3.5">
        <div class="eyebrow text-success mb-1">Yanıtınız</div>
        <p id="qaExistingAnswerText" class="text-[13.5px] leading-relaxed text-slate-700 dark:text-slate-200"></p>
      </div>
      <div id="qaClosedNote" hidden role="note" class="rounded-xl border border-slate-200/80 dark:border-white/10 bg-slate-900/[.03] dark:bg-white/[.05] p-3.5">
        <div class="eyebrow text-slate-500 mb-1 inline-flex items-center gap-1.5"><i data-lucide="lock" class="w-3.5 h-3.5" aria-hidden="true"></i> Kapalı soru</div>
        <p id="qaClosedNoteText" class="text-[13.5px] leading-relaxed text-slate-700 dark:text-slate-200"></p>
      </div>
      <div id="qaAnswerComposer">
        <div class="flex items-center justify-between mb-1.5">
          <label class="text-[12px] font-medium text-slate-500">Yanıtınız</label>
          <select id="qaCannedPicker" onchange="qaInsertCanned(this.value)" class="t h-8 px-2 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] border border-slate-200/80 dark:border-white/10 outline-none text-[12px] focus:border-brand/40">
            <option value="">Hazır yanıt ekle…</option>
          </select>
        </div>
        <textarea id="qaModalAnswerInput" rows="4" placeholder="Cevabınızı yazın…" class="w-full px-3 py-2 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] border border-slate-200/80 dark:border-white/10 outline-none text-[13px] resize-y focus:border-brand/40"></textarea>
        <div class="flex justify-end mt-3">
          <button type="button" id="qaModalSendBtn" onclick="qaSubmitModalAnswer()" class="t inline-flex items-center gap-2 h-9 px-4 rounded-lg bg-success text-white text-[13px] font-semibold hover:brightness-110"><i data-lucide="send" class="w-4 h-4"></i> Gönder</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ════════ JAVASCRIPT ════════ -->
<script>
const QA_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || <?= json_encode($csrf) ?>;
const QA_CAN_ANSWER = <?= $qaCanAnswer ? 'true' : 'false' ?>;
const QA_PAGE_SIZE = <?= $pageSize ?>;

let qaMainTable = null;
/* Aktif pazaryeri filtresi (dropdown). Varsayılan: Tümü (''). Her ajax'ta gönderilir. */
let qaMainMpId = <?= json_encode($currentMp) ?>;
/* Aktif durum filtresi (segment toggle). 'waiting' | 'answered'. */
let qaMainStatus = <?= json_encode($currentStatus) ?>;
const QA_STATUSES = ['waiting', 'answered', 'rejected'];
/* Modal için açık satır verisi. */
let qaActiveRow = null;
/* Hazır yanıt önbelleği. */
let qaCannedCache = [];

/* HTML metni VE öznitelik değeri için güvenli kaçış (& < > " ' — title="…" / data-…='…' bağlamı dahil). */
const QA_ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const qaEsc = v => String(v || '').replace(/[&<>"']/g, ch => QA_ESC_MAP[ch]);
const qaToast = (m, t = 'info') => typeof Toast !== 'undefined' && typeof Toast.show === 'function' ? Toast.show(m, t) : console.log(t + ': ' + m);

/* ═══ Müşteri baş harfleri (avatar) ═══ */
function qaInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toLocaleUpperCase('tr-TR');
    return (parts[0][0] + parts[parts.length - 1][0]).toLocaleUpperCase('tr-TR');
}

/* ═══ Tarih formatı ═══ */
function qaFormatDate(raw) {
    raw = String(raw || '').trim();
    if (!raw) return '—';
    const ts = Date.parse(raw.replace(' ', 'T'));
    if (Number.isNaN(ts)) return qaEsc(raw);
    return new Date(ts).toLocaleString('tr-TR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).replace(',', ' ·');
}

/* ═══ Pazaryeri pills filtresi (Ürünler deseni) — ana tabloyu yerinde süzer.
   marketplace_id her ajax'ta gönderildiği için tab aktifliği sayfa değişince korunur. ═══ */
function setMpFilter(mpId, el) {
    mpId = mpId || '';
    if (el.classList.contains('is-active') && mpId !== '') {
        return setMpFilter('', document.querySelector('#mpSeg [data-mp-id=""]'));
    }
    qaMainMpId = mpId;
    document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
    el.classList.add('is-active');
    if (qaMainTable) qaMainTable.ajax.reload();
}
function updateMpCounts(counts, total) {
    counts = counts || {};
    document.querySelectorAll('#mpSeg [data-mp-cnt]').forEach(badge => {
        const key = badge.dataset.mpCnt;
        badge.textContent = key === 'all' ? (total || 0) : (counts[key] || 0);
    });
}

/* ═══ Durum Segment Toggle (Bekleyen / Yanıtlanan) ═══ */
function qaSetStatusFilter(status, el) {
    qaMainStatus = QA_STATUSES.includes(status) ? status : 'waiting';
    const activeCls = ['bg-surface', 'shadow-xs', 'text-slate-900', 'dark:text-white'];
    document.querySelectorAll('[data-qfilter]').forEach(b => {
        b.classList.remove(...activeCls);
        b.classList.add('text-slate-500');
        b.setAttribute('aria-pressed', 'false');
    });
    if (el) {
        el.classList.remove('text-slate-500');
        el.classList.add(...activeCls);
        el.setAttribute('aria-pressed', 'true');
    }
    if (qaMainTable) qaMainTable.ajax.reload();
}

/* Kapalı soru: pazaryeri reddetti ya da cevap süresi doldu (HB AutoClosed) — cevap gönderilemez. */
function qaClosedReason(row) {
    if (!row || row.status === 'answered') return '';
    if (row.closed_reason) return row.closed_reason;
    return (row.status === 'rejected' || row.status === 'reddedildi') ? 'rejected' : '';
}

/* ═══ Column Renderer — tek kolon, mockup KART markup'ı döndürür ═══
   renderQaProduct + renderQaText + renderQaStatus + renderQaActions tek kartta birleşti;
   tüm isimler (DataTables/dış referans güvenliği için) korunur. */

function renderQaMp(d, t, row) {
    var slug = row.marketplace_slug || '';
    var nm = row.marketplace_name || slug;
    if (!slug) return '<span class="text-slate-300">—</span>';
    return '<img class="mp-logo" style="height:16px" src="/public/assets/img/marketplaces/' + qaEsc(slug) + '.svg" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + qaEsc(slug) + '.png\'" alt="' + qaEsc(nm) + '">';
}
function renderQaProductCell(d, t, row) {
    var nm = String(row.product_name || '').trim() || 'Ürün bilgisi yok';
    return '<span class="block max-w-[200px] truncate text-slate-700 dark:text-slate-200" title="' + qaEsc(nm) + '">' + qaEsc(nm) + '</span>';
}
function renderQaCustomer(d, t, row) {
    var cust = String(row.customer_name || '').trim();
    if (!cust) return '<span class="inline-flex items-center gap-2 text-slate-400"><span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 dark:bg-white/10"><i data-lucide="user" class="w-3 h-3"></i></span>Müşteri</span>';
    return '<span class="inline-flex items-center gap-2"><span class="grid place-items-center w-6 h-6 rounded-full bg-slate-100 dark:bg-white/10 text-[10px] font-bold text-slate-500">' + qaEsc(qaInitials(cust)) + '</span>' + qaEsc(cust) + '</span>';
}
function renderQaTextCell(d, t, row) {
    var q = String(row.question_text || '').trim();
    return '<span class="block max-w-[300px] truncate text-slate-600 dark:text-slate-300" title="' + qaEsc(q) + '">' + qaEsc(q || '—') + '</span>';
}
function renderQaDateCell(d, t, row) {
    return '<span class="text-slate-400 tnum whitespace-nowrap">' + qaFormatDate(row.question_date) + '</span>';
}

function renderQaStatus(row) {
    if (row.status === 'answered') return '<span class="o-badge" style="background:#10b9811a;color:#10b981">Yanıtlandı</span>';
    const closed = qaClosedReason(row);
    if (closed === 'expired') return '<span class="o-badge" style="background:#64748b1a;color:#64748b" title="Cevap süresi dolduğu için pazaryeri soruyu kapattı">Süresi doldu</span>';
    if (closed === 'rejected') return '<span class="o-badge" style="background:#ef44441a;color:#ef4444">Reddedildi</span>';
    return '<span class="o-badge" style="background:#f59e0b1a;color:#f59e0b">Bekliyor</span>';
}

function renderQaActions(row) {
    if (QA_CAN_ANSWER && row.status === 'waiting' && !qaClosedReason(row)) {
        return '<div class="o-row-actions" style="justify-content:flex-end"><button type="button" class="o-ra o-ra-sync" onclick="qaOpenAnswer(' + Number(row.id) + ')"><i data-lucide="reply" style="width:12px;height:12px"></i> Yanıtla</button></div>';
    }
    return '<div class="o-row-actions" style="justify-content:flex-end"><button type="button" class="o-ra" onclick="qaOpenAnswer(' + Number(row.id) + ')"><i data-lucide="eye" style="width:12px;height:12px"></i> Görüntüle</button></div>';
}


/* ═══ Yanıt / Görüntüle Modalı ═══ */
function qaOpenAnswer(id) {
    if (!qaMainTable) return;
    const row = qaMainTable.rows().data().toArray().find(r => String(r.id) === String(id));
    if (!row) { qaToast('Soru bulunamadı', 'error'); return; }
    qaActiveRow = row;

    const slug = row.marketplace_slug || '';
    const mpName = row.marketplace_name || slug;
    let logo = slug
        ? '<span class="logo-chip shrink-0" style="padding:6px 9px"><img class="mp-logo" style="height:16px" src="/public/assets/img/marketplaces/' + qaEsc(slug) + '.svg" onerror="this.onerror=null;this.src=\'/public/assets/img/marketplaces/' + qaEsc(slug) + '.png\'" alt="' + qaEsc(mpName) + '"></span>'
        : '';
    document.getElementById('qaAnswerProduct').innerHTML = logo
        + '<div class="min-w-0"><div class="text-[14px] font-semibold truncate">' + qaEsc(row.product_name || '—') + '</div>'
        + '<div class="text-[12px] text-slate-400">' + qaEsc(row.customer_name || '—') + '</div></div>';

    document.getElementById('qaAnswerQuestionText').innerHTML = qaEsc(row.question_text || '');

    const isAnswered = (row.status === 'answered');
    const closedReason = qaClosedReason(row);
    document.getElementById('qaAnswerTitle').textContent = (isAnswered || closedReason || !QA_CAN_ANSWER) ? 'Soru Detayı' : 'Soruyu Yanıtla';

    const existing = document.getElementById('qaExistingAnswer');
    const composer = document.getElementById('qaAnswerComposer');
    const closedNote = document.getElementById('qaClosedNote');
    closedNote.hidden = true;
    if (isAnswered && row.answer_text) {
        document.getElementById('qaExistingAnswerText').innerHTML = qaEsc(row.answer_text);
        existing.hidden = false;
        composer.hidden = true;
    } else if (closedReason) {
        document.getElementById('qaClosedNoteText').textContent = closedReason === 'expired'
            ? 'Cevap süresi (1 iş günü) dolduğu için pazaryeri bu soruyu kapattı; cevap gönderilemez.'
            : 'Bu soru pazaryeri tarafından reddedildi; cevap gönderilemez.';
        closedNote.hidden = false;
        existing.hidden = true;
        composer.hidden = true;
    } else {
        existing.hidden = true;
        composer.hidden = !QA_CAN_ANSWER;
        document.getElementById('qaModalAnswerInput').value = '';
    }

    if (QA_CAN_ANSWER) qaLoadCannedInto('qaCannedPicker');
    document.getElementById('qaAnswerModal').hidden = false;
    if (window.lucide) lucide.createIcons();
}

function qaCloseAnswer() {
    document.getElementById('qaAnswerModal').hidden = true;
    qaActiveRow = null;
}

function qaInsertCanned(id) {
    if (!id) return;
    const item = qaCannedCache.find(c => String(c.id) === String(id));
    if (!item) return;
    const ta = document.getElementById('qaModalAnswerInput');
    ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + (item.body || '');
    document.getElementById('qaCannedPicker').value = '';
    ta.focus();
}

function qaSubmitModalAnswer() {
    if (!qaActiveRow) return;
    qaAnswerQuestion(qaActiveRow.id);
}

/* ═══ Answer Question (POST /questions/{id}/answer) — KORUNDU ═══ */
async function qaAnswerQuestion(id) {
    const textarea = document.getElementById('qaModalAnswerInput');
    if (!textarea) return;
    const text = textarea.value.trim();
    if (!text) { qaToast('Cevap metni boş olamaz', 'error'); return; }

    const btn = document.getElementById('qaModalSendBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = 'Gönderiliyor...'; }

    try {
        const fd = new FormData();
        fd.append('_csrf_token', QA_CSRF);
        fd.append('answer_text', text);

        const res = await fetch('/questions/' + id + '/answer', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const d = await res.json();

        if (d.success) {
            qaToast('Cevap başarıyla gönderildi!', 'success');
            qaCloseAnswer();
            if (qaMainTable) qaMainTable.ajax.reload(null, false);
        } else {
            qaToast(d.message || 'Cevap gönderilemedi', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="send" class="w-4 h-4"></i> Gönder'; if (window.lucide) lucide.createIcons(); }
        }
    } catch (e) {
        qaToast('Bağlantı hatası oluştu', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="send" class="w-4 h-4"></i> Gönder'; if (window.lucide) lucide.createIcons(); }
    }
}

/* ═══ Hazır Yanıtlar (GET/POST /questions/canned) ═══ */
async function qaFetchCanned() {
    try {
        const res = await fetch('/questions/canned', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await res.json();
        qaCannedCache = (d && d.data) ? d.data : [];
    } catch (e) {
        qaCannedCache = [];
    }
    return qaCannedCache;
}

function qaLoadCannedInto(selectId) {
    const sel = document.getElementById(selectId);
    const fill = () => {
        if (!sel) return;
        sel.innerHTML = '<option value="">Hazır yanıt ekle…</option>'
            + qaCannedCache.map(c => '<option value="' + qaEsc(String(c.id)) + '">' + qaEsc(c.title || '') + '</option>').join('');
    };
    if (qaCannedCache.length) { fill(); } else { qaFetchCanned().then(fill); }
}

function qaRenderCannedList() {
    const wrap = document.getElementById('cannedList');
    if (!wrap) return;
    if (!qaCannedCache.length) {
        wrap.innerHTML = '<div class="text-[13px] text-slate-400">Henüz şablon yok.</div>';
        return;
    }
    wrap.innerHTML = qaCannedCache.map(c =>
        '<div class="flex items-start justify-between gap-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] p-3">'
        + '<div class="min-w-0"><div class="text-[13px] font-semibold truncate">' + qaEsc(c.title || '') + '</div>'
        + '<div class="text-[12px] text-slate-500 mt-0.5 line-clamp-2">' + qaEsc(c.body || '') + '</div></div>'
        + '<button type="button" onclick="qaDeleteCanned(' + Number(c.id) + ')" class="t shrink-0 grid place-items-center w-8 h-8 rounded-lg hover:bg-danger/10 text-slate-400 hover:text-danger"><i data-lucide="trash-2" class="w-4 h-4"></i></button>'
        + '</div>'
    ).join('');
    if (window.lucide) lucide.createIcons();
}

function qaOpenCanned() {
    document.getElementById('cannedModal').hidden = false;
    qaFetchCanned().then(qaRenderCannedList);
    if (window.lucide) lucide.createIcons();
}

function qaCloseCanned() {
    document.getElementById('cannedModal').hidden = true;
}

async function qaCreateCanned(ev) {
    ev.preventDefault();
    const title = document.getElementById('cannedTitle').value.trim();
    const body = document.getElementById('cannedBody').value.trim();
    if (!title || !body) { qaToast('Başlık ve metin gerekli', 'error'); return false; }
    try {
        const fd = new FormData();
        fd.append('_csrf_token', QA_CSRF);
        fd.append('title', title);
        fd.append('body', body);
        const res = await fetch('/questions/canned', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const d = await res.json();
        if (d.success) {
            qaToast('Şablon eklendi', 'success');
            document.getElementById('cannedTitle').value = '';
            document.getElementById('cannedBody').value = '';
            await qaFetchCanned();
            qaRenderCannedList();
        } else {
            qaToast(d.message || 'Şablon eklenemedi', 'error');
        }
    } catch (e) {
        qaToast('Bağlantı hatası oluştu', 'error');
    }
    return false;
}

async function qaDeleteCanned(id) {
    try {
        const fd = new FormData();
        fd.append('_csrf_token', QA_CSRF);
        const res = await fetch('/questions/canned/' + id + '/delete', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const d = await res.json();
        if (d.success) {
            qaToast('Şablon silindi', 'success');
            await qaFetchCanned();
            qaRenderCannedList();
        } else {
            qaToast(d.message || 'Şablon silinemedi', 'error');
        }
    } catch (e) {
        qaToast('Bağlantı hatası oluştu', 'error');
    }
}

/* ═══ Sync Questions (POST /questions/sync) — KORUNDU ═══ */
async function qaSyncQuestions() {
    const btn = document.getElementById('btnSync');
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(100,116,139,.3);border-top-color:currentColor;border-radius:50%;animation:spin .75s linear infinite;vertical-align:middle"></span> Çekiliyor...';

    try {
        const fd = new FormData();
        fd.append('_csrf_token', QA_CSRF);
        if (qaMainMpId) fd.append('marketplace_id', qaMainMpId);

        const res = await fetch('/questions/sync', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const d = await res.json();

        if (d.success) {
            qaToast(d.message || 'Senkronizasyon tamamlandı!', 'success');
            if (qaMainTable) qaMainTable.ajax.reload(null, false);
        } else {
            qaToast(d.message || 'Senkronizasyon başarısız', 'error');
        }
    } catch (e) {
        qaToast('Bağlantı hatası oluştu', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="download-cloud" class="w-4 h-4"></i> Senkronize';
        if (window.lucide) lucide.createIcons();
    }
}

/* ═══ Sync Button (yalnız sync.trigger yetkisinde çizilir) ═══ */
document.getElementById('btnSync')?.addEventListener('click', qaSyncQuestions);

/* ═══ Arama (debounce) — mevcut arama mekanizması KORU ═══ */
let qaSearchTimer = null;
document.getElementById('qaSearch').addEventListener('input', function() {
    clearTimeout(qaSearchTimer);
    const v = this.value;
    qaSearchTimer = setTimeout(function() {
        if (qaMainTable) qaMainTable.search(v).draw();
    }, 350);
});

/* ═══ Main DataTable Init ═══ */
document.addEventListener('DOMContentLoaded', function() {
    qaMainTable = $('#qaMainTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/questions/ajax',
            data: function(d) {
                d.marketplace_id = qaMainMpId || '';
                d.status = qaMainStatus || '';
                d.date_from = '';
                d.date_to = '';
            },
            dataSrc: function(json) {
                if (json.statusCounts) {
                    const w = document.getElementById('qaCntWaiting');
                    const a = document.getElementById('qaCntAnswered');
                    if (w && json.statusCounts.waiting != null) w.textContent = String(json.statusCounts.waiting);
                    if (a && json.statusCounts.answered != null) a.textContent = String(json.statusCounts.answered);
                    const c = document.getElementById('qaCntClosed');
                    if (c && json.statusCounts.rejected != null) c.textContent = String(json.statusCounts.rejected);
                }
                // Pazaryeri pill rozetleri (Ürünler deseni). 'all' = pazaryeri toplamlarının toplamı
                // (recordsTotal status'e göre süzülü olduğundan toplam soru sayısını yansıtmaz).
                if (json.mpQuestionCounts) {
                    const tot = Object.keys(json.mpQuestionCounts).reduce(
                        (s, k) => k === 'all' ? s : s + (parseInt(json.mpQuestionCounts[k]) || 0), 0);
                    updateMpCounts(json.mpQuestionCounts, tot);
                }
                return json.data;
            }
        },
        columns: [
            { data: null, orderable: false, render: renderQaMp },
            { data: null, orderable: false, render: renderQaProductCell },
            { data: null, orderable: false, render: renderQaCustomer },
            { data: null, orderable: false, render: renderQaTextCell },
            { data: null, orderable: false, render: function(d,t,r){ return renderQaStatus(r); } },
            { data: null, orderable: false, render: renderQaDateCell },
            { data: null, orderable: false, className: 'text-right', render: function(d,t,r){ return renderQaActions(r); } }
        ],
        order: [],
        pageLength: QA_PAGE_SIZE,
        lengthMenu: [25, 50, 100],
        searching: true,
        dom: 'rtip',
        language: {
            processing: '<div style="display:flex;align-items:center;gap:8px;justify-content:center"><i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Yükleniyor...</div>',
            emptyTable: '<div class="py-16 text-center text-slate-400"><i data-lucide="message-circle-question" style="width:48px;height:48px;margin:0 auto 12px;display:block"></i>Soru bulunamadı</div>',
            zeroRecords: '<div class="py-16 text-center text-slate-400"><i data-lucide="search-x" style="width:48px;height:48px;margin:0 auto 12px;display:block"></i>Aramanıza uygun soru bulunamadı</div>',
            info: 'Toplam _TOTAL_ soru · Sayfa _PAGE_/_PAGES_',
            infoEmpty: 'Soru yok',
            infoFiltered: '(_MAX_ kayıttan filtrelendi)',
            paginate: { previous: '<i data-lucide="chevron-left" style="width:14px;height:14px"></i>', next: '<i data-lucide="chevron-right" style="width:14px;height:14px"></i>' }
        },
        drawCallback: function() {
            if (window.lucide) lucide.createIcons();
            $('#qaMainTable_info').appendTo('#qaInfo');
            $('#qaMainTable_paginate').appendTo('#qaPaginate');
        }
    });

    if (window.lucide) lucide.createIcons();
});
</script>
