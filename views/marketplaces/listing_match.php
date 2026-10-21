<?php
/**
 * Hepsiburada İlan Eşleştirme — ürünleri Hepsiburada'da ZATEN açık olan ilanlarla eşleştirir.
 * Veri: GET /marketplaces/{id}/listing-match/data · İşlemler: refresh / approve / unlink / bulk-approve / search
 * Hiçbir işlem Hepsiburada'ya yazmaz.
 */
$mpView      = $data['marketplace'] ?? ['id' => 0, 'name' => 'Hepsiburada', 'connected' => false];
$mpId        = (int) ($mpView['id'] ?? 0);
$isConnected = !empty($mpView['connected']);
$canEdit     = !empty($data['canEdit']);
$thresholds  = $data['thresholds'] ?? ['suggest' => 60, 'bulk' => 90];
$canCheck    = $data['_can'] ?? static fn(string $permission): bool => false;
$canSettings = $canCheck('marketplaces.view');
$editUrl     = '/marketplaces/' . $mpId . '/edit';
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<style>
.lm-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.lm-stack [hidden]{display:none !important}
.lm-stack{display:flex;flex-direction:column;gap:16px}
.lm-crumb{font-size:12px;color:var(--text-muted);margin:0 0 4px}
.lm-crumb a{color:inherit;text-decoration:none}
.lm-crumb a:hover{color:rgb(var(--brand));text-decoration:underline}
.lm-progress{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md,12px);padding:12px 16px;display:flex;flex-direction:column;gap:8px}
.lm-progress-head{display:flex;justify-content:space-between;gap:12px;font-size:13px;color:var(--text-secondary)}
.lm-progress .ui-progress>span{width:0;transition:width .2s ease}
.lm-head{flex-wrap:wrap}
.lm-filters{display:flex;gap:4px;flex-wrap:wrap}
.lm-chip{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:99px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-secondary);font-size:12.5px;font-weight:500;font-family:inherit;cursor:pointer}
.lm-chip:hover{border-color:var(--border-strong,#d1d5db);color:var(--text-primary)}
.lm-chip[aria-pressed="true"]{background:rgb(var(--brand) / .10);border-color:rgb(var(--brand) / .35);color:rgb(var(--brand))}
.lm-chip-count{font-weight:700;font-variant-numeric:tabular-nums}
.lm-search{flex:0 1 280px;min-width:200px}
.lm-bulk{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--border)}
.lm-threshold{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary)}
.lm-threshold .ui-input{width:72px;height:32px}
.lm-muted{font-size:12px;color:var(--text-muted)}
.lm-bulk-result{margin:0;padding:10px 18px;border-bottom:1px solid var(--border);font-size:12.5px;color:var(--text-secondary)}
.lm-bulk-result ul{margin:6px 0 0;padding-left:18px}
.lm-table td{vertical-align:top}
.lm-item{display:flex;gap:10px;align-items:flex-start;min-width:220px}
.lm-thumb{width:40px;height:40px;border-radius:8px;object-fit:cover;border:1px solid var(--border);background:var(--bg-page);flex-shrink:0}
.lm-thumb-empty{display:grid;place-items:center;color:var(--text-muted)}
.lm-thumb-empty [data-lucide],.lm-thumb-empty svg{width:16px;height:16px}
.lm-name{display:block;font-weight:600;color:var(--text-primary);line-height:1.35;word-break:break-word}
.lm-meta{display:block;font-size:11.5px;color:var(--text-muted);margin-top:3px;line-height:1.5;word-break:break-word}
.lm-meta code{font-family:'JetBrains Mono',ui-monospace,monospace;font-size:11px;padding:0 5px;border-radius:5px;background:var(--bg-badge);color:var(--text-secondary)}
.lm-reason{display:block;font-size:11px;color:var(--text-muted);margin-top:4px}
.lm-badges{display:flex;flex-direction:column;align-items:flex-start;gap:4px}
.lm-badges .ui-badge{text-transform:none;letter-spacing:0;font-weight:600}
.lm-actions-cell{white-space:nowrap}
.lm-change td{background:var(--bg-page)}
.lm-change-box{display:flex;flex-direction:column;gap:8px;max-width:640px}
.lm-pick{list-style:none;margin:0;padding:0;border:1px solid var(--border);border-radius:var(--radius-sm,8px);max-height:320px;overflow:auto;background:var(--bg-card)}
.lm-pick li+li{border-top:1px solid var(--border-light)}
.lm-pick-btn{display:flex;width:100%;gap:12px;align-items:flex-start;justify-content:space-between;text-align:left;background:none;border:0;padding:10px 12px;font-family:inherit;font-size:12.5px;color:var(--text-primary);cursor:pointer}
.lm-pick-btn:hover{background:var(--bg-table-hover)}
.lm-pick-btn:disabled{cursor:not-allowed;opacity:.6}
.lm-pick-note{display:block;padding:10px 12px;font-size:12.5px;color:var(--text-muted)}
.lm-skel{display:block;border-radius:6px;background:var(--bg-badge);position:relative;overflow:hidden}
.lm-skel::after{content:"";position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgb(255 255 255 / .35),transparent);animation:lm-shimmer 1.3s infinite}
.dark .lm-skel::after{background:linear-gradient(90deg,transparent,rgb(255 255 255 / .06),transparent)}
@keyframes lm-shimmer{100%{transform:translateX(100%)}}
.lm-skel-stat{height:78px;border-radius:var(--radius-md,12px)}
.lm-skel-line{height:12px;margin:6px 0}
.lm-skel-row{display:flex;gap:16px;padding:14px 18px;border-bottom:1px solid var(--border-light)}
.lm-skel-thumb{width:40px;height:40px;flex-shrink:0}
.lm-skel-col{flex:1}
.lm-skel-line.is-lg{width:60%}
.lm-skel-line.is-md{width:45%}
.lm-skel-line.is-sm{width:30%}
.lm-flush{margin:0}
.lm-inline-form{display:flex;gap:8px;flex-wrap:wrap}
.lm-inline-form .ui-input{flex:1 1 260px;width:auto}
.lm-pager-btns{display:flex;gap:6px}
.lm-dialog{border:1px solid var(--border);border-radius:var(--radius-md,12px);padding:0;width:min(560px,calc(100vw - 32px));background:var(--bg-card);color:var(--text-primary)}
.lm-dialog.is-wide{width:min(880px,calc(100vw - 32px))}
.lm-dialog::backdrop{background:rgb(15 23 42 / .45)}
.lm-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border)}
.lm-dialog-head h2{margin:0;font-size:15px;font-weight:600}
.lm-dialog-body{padding:18px;display:flex;flex-direction:column;gap:12px;max-height:70vh;overflow:auto;font-size:13px;line-height:1.55;color:var(--text-secondary)}
.lm-dialog-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--border);background:var(--bg-page)}
.lm-result{border:1px solid var(--border);border-radius:var(--radius-sm,8px);overflow:hidden}
.lm-result-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-page);border-bottom:1px solid var(--border)}
.lm-result-head p{margin:0;flex:1 1 240px;color:var(--text-secondary)}
.lm-result .ui-table{font-size:12px}
.lm-result .ui-table tbody td{padding:8px 12px}
.ui-btn:focus-visible,.lm-chip:focus-visible,.lm-pick-btn:focus-visible,.lm-crumb a:focus-visible,.lm-dialog [type=button]:focus-visible{outline:2px solid rgb(var(--brand));outline-offset:2px}
@media (max-width:760px){
  .lm-search{flex:1 1 100%;min-width:0}
  .lm-table thead{display:none}
  .lm-table,.lm-table tbody,.lm-table tr,.lm-table td{display:block;width:100%}
  .lm-table tbody tr{border-bottom:1px solid var(--border)}
  .lm-table tbody td{border-bottom:0;padding:8px 16px}
  .lm-table tbody td[data-label]::before{content:attr(data-label);display:block;font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px}
  .lm-table .ui-cell-actions{justify-content:flex-start;flex-wrap:wrap}
  .lm-item{min-width:0}
  .lm-actions-cell{white-space:normal}
}
@media (prefers-reduced-motion:reduce){
  .lm-skel::after{animation:none}
  .lm-progress .ui-progress>span{transition:none}
  .lm-spin{animation:none !important}
}
@media (forced-colors:active){
  .lm-chip[aria-pressed="true"]{outline:2px solid CanvasText}
  .ui-badge{border:1px solid CanvasText}
}
@keyframes lm-rotate{to{transform:rotate(360deg)}}
.lm-spin{animation:lm-rotate 1s linear infinite}
</style>

<div class="ui-page-head">
  <div>
    <p class="lm-crumb"><?php if ($canSettings): ?><a href="/marketplaces">Pazaryerleri</a><?php else: ?>Pazaryerleri<?php endif; ?> / <?= $e($mpView['name'] ?? 'Hepsiburada') ?></p>
    <h1>İlan Eşleştirme</h1>
    <p>Ürünlerinizi Hepsiburada'da zaten açık olan ilanlarla eşleştirin. Yeni ilan açılmaz, Hepsiburada'ya hiçbir şey gönderilmez.</p>
  </div>
  <div class="ui-page-actions">
    <?php if ($canEdit): ?>
    <button type="button" class="ui-btn ui-btn-outline" id="lmResultOpen"><i data-lucide="clipboard-check" aria-hidden="true"></i> Gönderim sonuçlarını sorgula</button>
    <?php if ($isConnected): ?>
    <button type="button" class="ui-btn ui-btn-primary" data-lm-refresh><i data-lucide="refresh-cw" aria-hidden="true"></i> <span>Hepsiburada'dan Yenile</span></button>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($canSettings): ?>
    <a href="<?= $e($editUrl) ?>" class="ui-btn ui-btn-ghost"><i data-lucide="settings" aria-hidden="true"></i> Bağlantı ayarları</a>
    <?php endif; ?>
  </div>
</div>

<div class="lm-stack">
  <div id="lmLive" class="lm-sr" role="status" aria-live="polite"></div>

  <?php if (!$isConnected): ?>
  <div class="ui-banner is-warning" role="note">
    <span class="ui-banner-icon"><i data-lucide="plug-zap" aria-hidden="true"></i></span>
    <div class="ui-banner-body">
      <p class="ui-banner-title">Hepsiburada bağlantısı kurulmamış</p>
      <p class="ui-banner-text">İlanları çekebilmek için pazaryeri ayarlarında Mağaza ID (Merchant ID) ve Servis Anahtarı girin.</p>
    </div>
    <?php if ($canSettings): ?><a href="<?= $e($editUrl) ?>" class="ui-btn ui-btn-outline is-sm">Bağlantıyı kur</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="lm-progress" id="lmProgress" hidden>
    <div class="lm-progress-head"><span id="lmProgressText">Başlatılıyor…</span><span id="lmProgressStep"></span></div>
    <div class="ui-progress" role="progressbar" aria-label="Hepsiburada yenileme ilerlemesi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="lmProgressBar"><span></span></div>
  </div>

  <div id="lmRoot" aria-busy="true">
    <div class="ui-stats" aria-hidden="true">
      <span class="lm-skel lm-skel-stat"></span><span class="lm-skel lm-skel-stat"></span><span class="lm-skel lm-skel-stat"></span>
      <span class="lm-skel lm-skel-stat"></span><span class="lm-skel lm-skel-stat"></span><span class="lm-skel lm-skel-stat"></span>
    </div>
    <div class="ui-card" aria-hidden="true">
      <?php for ($i = 0; $i < 6; $i++): ?>
      <div class="lm-skel-row">
        <span class="lm-skel lm-skel-thumb"></span>
        <div class="lm-skel-col"><span class="lm-skel lm-skel-line is-md"></span><span class="lm-skel lm-skel-line is-sm"></span></div>
        <div class="lm-skel-col"><span class="lm-skel lm-skel-line is-lg"></span><span class="lm-skel lm-skel-line is-sm"></span></div>
      </div>
      <?php endfor; ?>
    </div>
    <span class="lm-sr">Eşleştirme verileri yükleniyor…</span>
  </div>
</div>

<dialog class="lm-dialog" id="lmConfirm" aria-labelledby="lmConfirmTitle" aria-describedby="lmConfirmText">
  <div class="lm-dialog-head"><h2 id="lmConfirmTitle">Onay</h2></div>
  <div class="lm-dialog-body"><p id="lmConfirmText" class="lm-flush"></p></div>
  <div class="lm-dialog-foot">
    <button type="button" class="ui-btn ui-btn-outline" data-confirm="no">Vazgeç</button>
    <button type="button" class="ui-btn ui-btn-primary" data-confirm="yes">Onayla</button>
  </div>
</dialog>

<?php if ($canEdit): ?>
<dialog class="lm-dialog is-wide" id="lmResultDialog" aria-labelledby="lmResultTitle">
  <div class="lm-dialog-head">
    <h2 id="lmResultTitle">Hepsiburada gönderim sonuçları</h2>
    <button type="button" class="ui-btn ui-btn-ghost is-sm is-icon" data-result-close aria-label="Kapat"><i data-lucide="x" aria-hidden="true"></i></button>
  </div>
  <div class="lm-dialog-body">
    <p class="lm-flush">Ürün gönderiminden sonra Hepsiburada'nın işlemeyi bitirdiği gönderimlerin sonucunu alır; başarılı ürünler ilanlarına bağlanır, reddedilenlerin nedeni kaydedilir. Hepsiburada'ya yalnız sorgu yapılır.</p>
    <form id="lmResultForm" class="ui-field" novalidate>
      <label class="ui-label" for="lmTrackingId">Takip numarası (isteğe bağlı)</label>
      <div class="lm-inline-form">
        <input type="text" class="ui-input" id="lmTrackingId" name="tracking_id" maxlength="80" autocomplete="off" pattern="[A-Za-z0-9-]*" placeholder="Boş bırakırsanız bekleyen tüm gönderimler sorgulanır" aria-describedby="lmTrackingHint">
        <button type="submit" class="ui-btn ui-btn-primary" id="lmResultSubmit"><i data-lucide="search-check" aria-hidden="true"></i> Sorgula</button>
      </div>
      <span class="ui-hint" id="lmTrackingHint">Yalnız harf, rakam ve tire. Tek seferde en fazla 10 gönderim sorgulanır.</span>
    </form>
    <div id="lmResultArea" aria-live="polite"></div>
  </div>
</dialog>
<?php endif; ?>

<script>
(function () {
    'use strict';

    var MP_ID = <?= json_encode($mpId) ?>;
    var BASE = '/marketplaces/' + MP_ID + '/listing-match';
    var CAN_EDIT = <?= json_encode($canEdit) ?>;
    var CONNECTED = <?= json_encode($isConnected) ?>;
    var CAN_SETTINGS = <?= json_encode($canSettings) ?>;
    var EDIT_URL = <?= json_encode($editUrl) ?>;
    var THRESHOLD = { suggest: <?= (int) ($thresholds['suggest'] ?? 60) ?>, bulk: <?= (int) ($thresholds['bulk'] ?? 90) ?> };
    var PAGE_SIZE = 50;
    var STATUS = {
        approved:  { label: 'Eşleşti',   badge: 'is-success', icon: 'check-circle-2' },
        exact:     { label: 'Kesin',     badge: 'is-info',    icon: 'equal' },
        suggested: { label: 'Öneri',     badge: 'is-warning', icon: 'lightbulb' },
        none:      { label: 'Eşleşmedi', badge: 'is-muted',   icon: 'circle-minus' }
    };
    var WARN_CLASS = { danger: 'is-danger', warning: 'is-warning', muted: 'is-muted', info: 'is-info' };

    var root = document.getElementById('lmRoot');
    var live = document.getElementById('lmLive');
    var skeleton = root.innerHTML;
    var state = { data: null, filter: '', query: '', page: 1, unmatchedQuery: '', threshold: THRESHOLD.bulk };

    /* ─── yardımcılar ─── */
    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }
    function esc(value) {
        return (value === null || value === undefined ? '' : String(value))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function fold(value) {
        return String(value || '').replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr-TR')
            .replace(/ç/g, 'c').replace(/ğ/g, 'g').replace(/ı/g, 'i').replace(/ö/g, 'o').replace(/ş/g, 's').replace(/ü/g, 'u');
    }
    function money(value) {
        if (value === null || value === undefined) { return '—'; }
        return Number(value).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
    }
    function num(value) { return Number(value || 0).toLocaleString('tr-TR'); }
    function safeSrc(url) {
        url = String(url || '').trim();
        if (/^https?:\/\//i.test(url) || /^\/(?!\/)/.test(url)) { return url; }
        if (url !== '' && !/^[a-z][a-z0-9+.-]*:/i.test(url) && url.indexOf('//') !== 0) { return '/' + url.replace(/^\.?\/+/, ''); }
        return '';
    }
    function announce(message) { if (live) { live.textContent = ''; live.textContent = message; } }
    function toast(message, type) {
        announce(message);
        if (window.Toast && typeof window.Toast.show === 'function') { window.Toast.show(esc(message), type || 'success', type === 'error' ? 6000 : 4000); }
    }
    function icons(scope) { if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons({ root: scope || document }); } }
    function request(method, url, body) {
        var options = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } };
        if (method === 'POST') {
            var form = new FormData();
            form.append('_csrf_token', csrf());
            Object.keys(body || {}).forEach(function (key) { form.append(key, body[key]); });
            options.body = form;
            options.headers['X-CSRF-Token'] = csrf();
        }
        return fetch(url, options)
            .then(function (response) { return response.text(); })
            .then(function (text) {
                try { return JSON.parse(text); } catch (err) { return { success: false, message: 'Sunucudan beklenmeyen yanıt geldi.' }; }
            })
            .catch(function () { return { success: false, message: 'Bağlantı hatası — tekrar deneyin.' }; });
    }

    /* ─── onay penceresi (native dialog: odak tuzağı + Esc) ─── */
    var confirmDialog = document.getElementById('lmConfirm');
    function confirmAction(title, text, okLabel) {
        return new Promise(function (resolve) {
            if (!confirmDialog || typeof confirmDialog.showModal !== 'function') { resolve(window.confirm(text)); return; }
            var previous = document.activeElement;
            document.getElementById('lmConfirmTitle').textContent = title;
            document.getElementById('lmConfirmText').textContent = text;
            var yes = confirmDialog.querySelector('[data-confirm="yes"]');
            var no = confirmDialog.querySelector('[data-confirm="no"]');
            yes.textContent = okLabel || 'Onayla';
            function done(result) {
                yes.removeEventListener('click', onYes); no.removeEventListener('click', onNo); confirmDialog.removeEventListener('close', onClose);
                if (confirmDialog.open) { confirmDialog.close(); }
                if (previous && typeof previous.focus === 'function') { previous.focus(); }
                resolve(result);
            }
            function onYes() { done(true); }
            function onNo() { done(false); }
            function onClose() { done(false); }
            yes.addEventListener('click', onYes); no.addEventListener('click', onNo); confirmDialog.addEventListener('close', onClose);
            confirmDialog.showModal();
            no.focus();
        });
    }

    /* ─── veri ─── */
    /**
     * @param {boolean} keepView filtre/sayfa/arama korunur
     * @param {string|number} [focusProductId] yeniden çizimden sonra odaklanacak ürün satırı
     */
    function load(keepView, focusProductId) {
        root.setAttribute('aria-busy', 'true');
        return request('GET', BASE + '/data').then(function (json) {
            root.setAttribute('aria-busy', 'false');
            if (!json || !json.success) { renderError(json && json.message ? json.message : 'Eşleştirme verileri okunamadı.'); return; }
            json.data.rows.forEach(function (row) {
                var hb = row.hb || {};
                row._q = fold([row.product.name, row.product.sku, row.product.barcode, hb.name, hb.barcode, hb.merchant_sku, hb.hb_sku].join(' '));
            });
            json.data.unmatched.forEach(function (h) { h._q = fold([h.name, h.barcode, h.merchant_sku, h.hb_sku].join(' ')); });
            state.data = json.data;
            if (!keepView) { state.page = 1; }
            render();
            restoreFocus(focusProductId);
        });
    }

    function restoreFocus(productId) {
        if (productId === undefined || productId === null) { return; }
        var target = root.querySelector('tr[data-id="' + String(productId).replace(/[^0-9]/g, '') + '"] .ui-cell-actions button')
            || document.getElementById('lmSearch');
        if (target) { target.focus(); }
    }

    function renderError(message) {
        root.innerHTML = '<div class="ui-card"><div class="ui-empty" role="alert">'
            + '<div class="ui-empty-icon"><i data-lucide="triangle-alert" aria-hidden="true"></i></div>'
            + '<h4>Eşleştirme verileri okunamadı</h4><p>' + esc(message) + '</p>'
            + '<button type="button" class="ui-btn ui-btn-outline" data-lm-retry><i data-lucide="rotate-ccw" aria-hidden="true"></i> Tekrar dene</button>'
            + '</div></div>';
        icons(root);
        announce('Eşleştirme verileri okunamadı. ' + message);
    }

    function render() {
        var d = state.data;
        if (!d.cache.count) { renderEmptyCache(); return; }

        var c = d.counts;
        var refreshed = d.cache.refreshed_at ? new Date(d.cache.refreshed_at.replace(' ', 'T')) : null;
        var refreshedText = refreshed && !isNaN(refreshed.getTime())
            ? refreshed.toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

        var html = '<div class="ui-stats">'
            + stat('package', 'is-slate', 'Ürün', c.products, 'Eşleştirilebilir ürün')
            + stat('check-circle-2', 'is-green', 'Eşleşti', c.approved, 'Kayıtlı bağlantı')
            + stat('equal', '', 'Kesin eşleşme', c.exact, 'Onay bekliyor')
            + stat('lightbulb', 'is-amber', 'Öneri', c.suggested, 'Ad ve miktar benzer')
            + stat('circle-minus', 'is-slate', 'Eşleşmedi', c.none, 'Uygun ilan yok')
            + stat('store', 'is-red', "HB'de karşılıksız", c.unmatched, num(c.unmatched_listed) + ' tanesinin ilanı var')
            + '</div>';

        html += '<div class="ui-banner" role="note"><span class="ui-banner-icon"><i data-lucide="info" aria-hidden="true"></i></span>'
            + '<div class="ui-banner-body"><p class="ui-banner-title">Eşleştirme yalnız bu panelde kaydedilir</p>'
            + '<p class="ui-banner-text">Hepsiburada\'ya hiçbir şey gönderilmez; fiyat ve stok farkı rozetleri bilgi amaçlıdır. Senkronizasyonda stok/fiyat gönderimi açıksa eşleşen ilanlara ürün stok ve fiyatı gönderilir — açmadan önce farkları kontrol edin. '
            + 'Son yenileme: ' + esc(refreshedText) + ' · ' + num(d.cache.count) + ' Hepsiburada kaydı</p></div></div>';

        html += '<div class="ui-card">'
            + '<div class="ui-card-head lm-head">'
            + '<div class="lm-filters" role="group" aria-label="Eşleşme durumuna göre filtrele">'
            + chip('', 'Tümü', c.products) + chip('approved', 'Eşleşti', c.approved) + chip('exact', 'Kesin', c.exact)
            + chip('suggested', 'Öneri', c.suggested) + chip('none', 'Eşleşmedi', c.none)
            + '</div>'
            + '<div class="ui-input-wrap lm-search"><i data-lucide="search" aria-hidden="true"></i>'
            + '<input type="search" class="ui-input" id="lmSearch" placeholder="Ürün, barkod veya SKU ara…" aria-label="Ürünlerde ara" value="' + esc(state.query) + '"></div>'
            + '</div>';

        if (CAN_EDIT) {
            html += '<div class="lm-bulk">'
                + '<button type="button" class="ui-btn ui-btn-success is-sm" data-bulk="exact"' + (c.exact ? '' : ' disabled') + '><i data-lucide="check-check" aria-hidden="true"></i> Tüm kesin eşleşmeleri onayla (' + num(c.exact) + ')</button>'
                + '<button type="button" class="ui-btn ui-btn-soft is-sm" data-bulk="suggested"' + ((c.exact + c.suggested) ? '' : ' disabled') + '><i data-lucide="lightbulb" aria-hidden="true"></i> Önerileri onayla</button>'
                + '<label class="lm-threshold" for="lmThreshold">Puan en az <input type="number" class="ui-input" id="lmThreshold" min="' + THRESHOLD.suggest + '" max="100" step="5" value="' + state.threshold + '" aria-describedby="lmThresholdInfo"> %</label>'
                + '<span class="lm-muted" id="lmThresholdInfo"></span>'
                + '</div><div id="lmBulkResult" class="lm-bulk-result" role="status" hidden></div>';
        }

        html += '<div class="ui-table-wrap"><table class="ui-table lm-table"><caption class="lm-sr">Ürünler ve Hepsiburada ilan eşleşmeleri</caption>'
            + '<thead><tr><th scope="col">Ürün</th><th scope="col">Eşleşme</th><th scope="col">Hepsiburada ilanı</th><th scope="col">Uyarılar</th><th scope="col"><span class="lm-sr">İşlemler</span></th></tr></thead>'
            + '<tbody id="lmBody"></tbody></table></div>'
            + '<div id="lmTableEmpty" hidden></div>'
            + '<div class="ui-pagination" id="lmPager" hidden><span id="lmPagerInfo"></span><div class="lm-pager-btns">'
            + '<button type="button" class="ui-btn ui-btn-outline is-sm" data-page="prev"><i data-lucide="chevron-left" aria-hidden="true"></i> Önceki</button>'
            + '<button type="button" class="ui-btn ui-btn-outline is-sm" data-page="next">Sonraki <i data-lucide="chevron-right" aria-hidden="true"></i></button>'
            + '</div></div></div>';

        html += renderUnmatchedCard(d);

        root.innerHTML = html;
        renderRows();
        renderUnmatchedRows();
        updateThresholdInfo();
        icons(root);
    }

    function stat(icon, tone, label, value, caption) {
        return '<div class="ui-stat"><span class="ui-stat-icon ' + tone + '"><i data-lucide="' + icon + '" aria-hidden="true"></i></span>'
            + '<div class="ui-stat-body"><p class="ui-stat-label">' + esc(label) + '</p><div class="ui-stat-value">' + num(value) + '</div>'
            + '<p class="ui-stat-caption">' + esc(caption) + '</p></div></div>';
    }
    function chip(filter, label, count) {
        return '<button type="button" class="lm-chip" data-filter="' + filter + '" aria-pressed="' + (state.filter === filter ? 'true' : 'false') + '">'
            + esc(label) + ' <span class="lm-chip-count">' + num(count) + '</span></button>';
    }

    function renderEmptyCache() {
        var action = '';
        if (CAN_EDIT && CONNECTED) {
            action = '<button type="button" class="ui-btn ui-btn-primary" data-lm-refresh><i data-lucide="refresh-cw" aria-hidden="true"></i> <span>Hepsiburada\'dan Yenile</span></button>';
        } else if (!CONNECTED && CAN_SETTINGS) {
            action = '<a class="ui-btn ui-btn-primary" href="' + esc(EDIT_URL) + '"><i data-lucide="plug-zap" aria-hidden="true"></i> Bağlantıyı kur</a>';
        }
        root.innerHTML = '<div class="ui-card"><div class="ui-empty">'
            + '<div class="ui-empty-icon"><i data-lucide="cloud-download" aria-hidden="true"></i></div>'
            + '<h4>Hepsiburada ilanları henüz çekilmedi</h4>'
            + '<p>Eşleştirme için mağazanızın Hepsiburada\'daki katalog ürünlerini ve ilanlarını bir kez çekmeniz gerekir. Bu işlem yalnız okuma yapar; Hepsiburada\'da hiçbir şey değişmez.</p>'
            + action + '</div></div>';
        icons(root);
    }

    function visibleRows() {
        var q = fold(state.query.trim());
        return state.data.rows.filter(function (row) {
            return (!state.filter || row.status === state.filter) && (!q || row._q.indexOf(q) !== -1);
        });
    }

    function renderRows() {
        var body = document.getElementById('lmBody');
        var empty = document.getElementById('lmTableEmpty');
        var pager = document.getElementById('lmPager');
        if (!body) { return; }
        var rows = visibleRows();
        var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
        state.page = Math.min(Math.max(1, state.page), pages);
        var slice = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);

        body.innerHTML = slice.map(rowHtml).join('');
        if (!state.data.rows.length) {
            empty.innerHTML = '<div class="ui-empty"><div class="ui-empty-icon"><i data-lucide="package-open" aria-hidden="true"></i></div>'
                + '<h4>Eşleştirilecek ürün yok</h4><p>Ürün ekledikten sonra burada Hepsiburada ilanlarıyla eşleştirebilirsiniz.</p>'
                + '<a href="/products/create" class="ui-btn ui-btn-primary"><i data-lucide="plus" aria-hidden="true"></i> Ürün ekle</a></div>';
        } else {
            empty.innerHTML = '<div class="ui-empty"><div class="ui-empty-icon"><i data-lucide="filter-x" aria-hidden="true"></i></div>'
                + '<h4>Bu filtrede ürün yok</h4><p>Farklı bir filtre seçin ya da aramayı temizleyin.</p></div>';
        }
        empty.hidden = rows.length > 0;
        pager.hidden = rows.length <= PAGE_SIZE;
        document.getElementById('lmPagerInfo').textContent = rows.length
            ? ((state.page - 1) * PAGE_SIZE + 1) + '–' + Math.min(state.page * PAGE_SIZE, rows.length) + ' / ' + num(rows.length) + ' ürün' : '';
        pager.querySelector('[data-page="prev"]').disabled = state.page <= 1;
        pager.querySelector('[data-page="next"]').disabled = state.page >= pages;
        icons(body);
        icons(empty);
    }

    function productCell(p) {
        var src = safeSrc(p.image);
        var image = src
            ? '<img class="lm-thumb" src="' + esc(src) + '" alt="" loading="lazy" decoding="async">'
            : '<span class="lm-thumb lm-thumb-empty" aria-hidden="true"><i data-lucide="image" aria-hidden="true"></i></span>';
        var flags = (p.is_active ? '' : ' · <span class="ui-badge is-muted">Pasif</span>') + (p.is_variant ? ' · <span class="ui-badge is-muted">Varyant</span>' : '');
        return '<div class="lm-item">' + image + '<div><span class="lm-name">' + esc(p.name || ('#' + p.id)) + '</span>'
            + '<span class="lm-meta">SKU <code>' + esc(p.sku || '—') + '</code> · Barkod <code>' + esc(p.barcode || '—') + '</code></span>'
            + '<span class="lm-meta">' + money(p.price) + ' · Stok ' + num(p.stock) + flags + '</span></div></div>';
    }

    function listingState(h) {
        if (h.is_salable) { return '<span class="ui-badge is-success">Satışta</span>'; }
        return h.has_listing ? '<span class="ui-badge is-muted">Satışta değil</span>' : '<span class="ui-badge is-danger">İlan yok</span>';
    }

    function hbCell(row) {
        var h = row.hb;
        if (h) {
            var src = safeSrc(h.image);
            var image = src
                ? '<img class="lm-thumb" src="' + esc(src) + '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">'
                : '<span class="lm-thumb lm-thumb-empty" aria-hidden="true"><i data-lucide="image" aria-hidden="true"></i></span>';
            return '<div class="lm-item">' + image + '<div><span class="lm-name">' + esc(h.name) + '</span>'
                + '<span class="lm-meta">Barkod <code>' + esc(h.barcode || '—') + '</code> · SKU <code>' + esc(h.merchant_sku || '—') + '</code></span>'
                + '<span class="lm-meta">' + money(h.price) + ' · Stok ' + (h.stock === null ? '—' : num(h.stock)) + ' · ' + listingState(h) + '</span></div></div>';
        }
        if (row.mp) {
            return '<span class="lm-meta">SKU <code>' + esc(row.mp.marketplace_sku || '—') + '</code> · HB <code>' + esc(row.mp.marketplace_product_id || '—') + '</code></span>';
        }
        return '<span class="lm-meta">Uygun ilan bulunamadı' + (CAN_EDIT ? ' — <b>Değiştir</b> ile elle seçebilirsiniz.' : '.') + '</span>';
    }

    function warningsCell(row) {
        if (!row.warnings.length) { return '<span class="lm-muted">—</span>'; }
        return '<div class="lm-badges">' + row.warnings.map(function (w) {
            var detail = w.detail ? '<span class="lm-sr"> (' + esc(w.detail) + ')</span>' : '';
            var text = w.code === 'transfer' && row.transfer_from ? w.text + ': ' + row.transfer_from.name : w.text;
            return '<span class="ui-badge ' + (WARN_CLASS[w.type] || 'is-muted') + '"' + (w.detail ? ' title="' + esc(w.detail) + '"' : '') + '>' + esc(text) + detail + '</span>';
        }).join('') + '</div>';
    }

    function rowHtml(row) {
        var s = STATUS[row.status] || STATUS.none;
        var p = row.product;
        var scoreText = (row.status === 'suggested' || (row.status === 'approved' && row.score > 0 && row.score < 100)) ? ' %' + row.score : '';
        var actions = '';
        if (CAN_EDIT) {
            if ((row.status === 'exact' || row.status === 'suggested') && row.hb) {
                actions += '<button type="button" class="ui-btn ui-btn-success is-sm" data-approve="' + esc(row.hb.hb_sku) + '" aria-label="' + esc('Onayla: ' + p.name) + '"><i data-lucide="check" aria-hidden="true"></i> Onayla</button>';
            }
            actions += '<button type="button" class="ui-btn ui-btn-outline is-sm" data-change aria-expanded="false" aria-controls="lmChange' + p.id + '" aria-label="' + esc('Değiştir: ' + p.name) + '"><i data-lucide="arrow-left-right" aria-hidden="true"></i> Değiştir</button>';
            if (row.status === 'approved') {
                actions += '<button type="button" class="ui-btn ui-btn-danger-soft is-sm" data-unlink aria-label="' + esc('Kaldır: ' + p.name) + '"><i data-lucide="unlink" aria-hidden="true"></i> Kaldır</button>';
            }
        }
        return '<tr data-id="' + p.id + '">'
            + '<td data-label="Ürün">' + productCell(p) + '</td>'
            + '<td data-label="Eşleşme"><span class="ui-badge ' + s.badge + '"><i data-lucide="' + s.icon + '" aria-hidden="true"></i> ' + esc(s.label) + scoreText + '</span>'
            + (row.reason ? '<span class="lm-reason">' + esc(row.reason) + '</span>' : '') + '</td>'
            + '<td data-label="Hepsiburada ilanı">' + hbCell(row) + '</td>'
            + '<td data-label="Uyarılar">' + warningsCell(row) + '</td>'
            + '<td class="lm-actions-cell">' + (actions ? '<div class="ui-cell-actions">' + actions + '</div>' : '') + '</td>'
            + '</tr>';
    }

    function renderUnmatchedCard(d) {
        var head = '<div class="ui-card"><div class="ui-card-head lm-head"><h3><i data-lucide="store" aria-hidden="true"></i> Hepsiburada\'da olup ürünlerinizde karşılığı olmayanlar</h3>';
        if (!d.unmatched.length) {
            return head + '</div><div class="ui-empty"><div class="ui-empty-icon"><i data-lucide="check" aria-hidden="true"></i></div>'
                + '<h4>Hepsi eşleşti</h4><p>Hepsiburada\'daki tüm kayıtların ürünlerinizde bir karşılığı var.</p></div></div>';
        }
        var note = d.counts.unmatched > d.unmatched.length
            ? '<div class="ui-card-foot lm-muted">İlk ' + num(d.unmatched.length) + ' kayıt gösteriliyor (toplam ' + num(d.counts.unmatched) + '; satıştakiler önce).</div>' : '';
        return head + '<div class="ui-input-wrap lm-search"><i data-lucide="search" aria-hidden="true"></i>'
            + '<input type="search" class="ui-input" id="lmUnmatchedSearch" placeholder="Filtrele…" aria-label="Karşılıksız kayıtlarda ara" value="' + esc(state.unmatchedQuery) + '"></div></div>'
            + '<div class="ui-table-wrap"><table class="ui-table"><caption class="lm-sr">Ürünlerinizde karşılığı olmayan Hepsiburada kayıtları</caption>'
            + '<thead><tr><th scope="col">Hepsiburada ürünü</th><th scope="col">Barkod</th><th scope="col">Satıcı SKU</th><th scope="col" class="is-num">Fiyat</th><th scope="col" class="is-num">Stok</th><th scope="col">Durum</th></tr></thead>'
            + '<tbody id="lmUnmatchedBody"></tbody></table></div><div id="lmUnmatchedEmpty" hidden><div class="ui-empty"><h4>Sonuç yok</h4><p>Aramayı değiştirin.</p></div></div>' + note + '</div>';
    }

    function renderUnmatchedRows() {
        var body = document.getElementById('lmUnmatchedBody');
        if (!body) { return; }
        var q = fold(state.unmatchedQuery.trim());
        var rows = state.data.unmatched.filter(function (h) { return !q || h._q.indexOf(q) !== -1; });
        body.innerHTML = rows.map(function (h) {
            return '<tr><td><span class="ui-cell-title">' + esc(h.name) + '</span><div class="ui-cell-sub">HB SKU ' + esc(h.hb_sku) + '</div></td>'
                + '<td><span class="ui-mono">' + esc(h.barcode || '—') + '</span></td><td><span class="ui-mono">' + esc(h.merchant_sku || '—') + '</span></td>'
                + '<td class="is-num">' + money(h.price) + '</td><td class="is-num">' + (h.stock === null ? '—' : num(h.stock)) + '</td><td>' + listingState(h) + '</td></tr>';
        }).join('');
        document.getElementById('lmUnmatchedEmpty').hidden = rows.length > 0;
    }

    function updateThresholdInfo() {
        var input = document.getElementById('lmThreshold');
        var info = document.getElementById('lmThresholdInfo');
        if (!input || !info) { return; }
        var value = clampThreshold(input.value);
        state.threshold = value;
        var count = state.data.rows.filter(function (row) { return row.status === 'suggested' && row.score >= value; }).length;
        info.textContent = num(count) + ' öneri %' + value + ' ve üstü';
    }
    function clampThreshold(value) {
        var n = parseInt(value, 10);
        if (isNaN(n)) { n = THRESHOLD.bulk; }
        return Math.max(THRESHOLD.suggest, Math.min(100, n));
    }

    /* ─── Hepsiburada'dan Yenile: katalog sayfaları → ilanlar → bitir (her adım tek HB isteği) ─── */
    var refreshing = false;
    function refresh() {
        if (refreshing) { return; }
        refreshing = true;
        var buttons = document.querySelectorAll('[data-lm-refresh]');
        var progress = document.getElementById('lmProgress');
        var bar = document.getElementById('lmProgressBar');
        var text = document.getElementById('lmProgressText');
        var stepText = document.getElementById('lmProgressStep');
        var round = 0;
        buttons.forEach(function (b) { b.disabled = true; });
        progress.hidden = false;

        function show(message, step, percent) {
            text.textContent = message;
            stepText.textContent = step;
            bar.setAttribute('aria-valuenow', String(Math.round(percent)));
            bar.firstElementChild.style.width = Math.max(2, Math.min(100, percent)) + '%';
            announce(message);
        }
        function finish(ok, message) {
            refreshing = false;
            buttons.forEach(function (b) { b.disabled = false; });
            progress.hidden = true;
            toast(message, ok ? 'success' : 'error');
            if (ok) { load(true); }
        }
        function step(name, cursor) {
            request('POST', BASE + '/refresh', { step: name, cursor: cursor, round: round }).then(function (json) {
                if (!json || !json.success) { finish(false, (json && json.message) || 'Yenileme tamamlanamadı.'); return; }
                var r = json.data;
                round = r.round;
                if (name === 'catalog') {
                    var catalogDone = Math.min(r.next * 100, r.total);
                    show('Katalog ' + num(catalogDone) + ' / ' + num(r.total) + '…', 'Adım 1 / 3', r.total ? (catalogDone / r.total) * 45 : 45);
                    if (r.done) { step('listings', 0); } else { step('catalog', r.next); }
                } else if (name === 'listings') {
                    var listingDone = Math.min(r.next, r.total);
                    show('İlanlar ' + num(listingDone) + ' / ' + num(r.total) + '…', 'Adım 2 / 3', 45 + (r.total ? (listingDone / r.total) * 50 : 50));
                    if (r.done) { show('Tamamlanıyor…', 'Adım 3 / 3', 97); step('finish', 0); } else { step('listings', r.next); }
                } else {
                    finish(true, num(r.total) + ' Hepsiburada kaydı güncellendi.');
                }
            });
        }
        show('Başlatılıyor…', 'Adım 1 / 3', 2);
        step('catalog', 0);
    }

    /* ─── satır işlemleri ─── */
    function approve(productId, hbSku, button) {
        if (button) { button.disabled = true; }
        return request('POST', BASE + '/approve', { product_id: productId, hb_sku: hbSku }).then(function (json) {
            var ok = !!(json && json.success);
            toast((json && json.message) || 'Eşleşme kaydedilemedi.', ok ? 'success' : 'error');
            if (ok) { load(true, productId); } else if (button) { button.disabled = false; }
        });
    }

    function unlink(productId, name, button) {
        confirmAction('Eşleşmeyi kaldır', '"' + name + '" ürününün Hepsiburada eşleşmesi kaldırılsın mı? Ürüne özel pazaryeri fiyat kuralı ve stok düşümü de silinir; Hepsiburada ilanına dokunulmaz.', 'Kaldır')
            .then(function (yes) {
                if (!yes) { return; }
                button.disabled = true;
                request('POST', BASE + '/unlink', { product_id: productId }).then(function (json) {
                    var ok = !!(json && json.success);
                    toast((json && json.message) || 'Eşleşme kaldırılamadı.', ok ? 'success' : 'error');
                    if (ok) { load(true, productId); } else { button.disabled = false; }
                });
            });
    }

    function bulkApprove(mode, button) {
        var threshold = clampThreshold((document.getElementById('lmThreshold') || {}).value);
        var text = mode === 'exact'
            ? 'Barkod ya da SKU ile birebir eşleşen tüm ürünler onaylansın mı?'
            : 'Kesin eşleşmeler ve puanı %' + threshold + ' ve üstü olan öneriler onaylansın mı? Eşleşme sunucuda yeniden hesaplanır.';
        confirmAction('Toplu onay', text, 'Onayla').then(function (yes) {
            if (!yes) { return; }
            button.disabled = true;
            var result = document.getElementById('lmBulkResult');
            result.hidden = false;
            result.textContent = 'Onaylanıyor…';
            request('POST', BASE + '/bulk-approve', { mode: mode, threshold: threshold }).then(function (json) {
                var data = (json && json.data) || {};
                var ok = !!(json && json.success);
                toast((json && json.message) || 'Toplu onay tamamlanamadı.', ok ? 'success' : 'error');
                if ((data.approved || 0) > 0) {
                    load(true).then(function () { showBulkErrors(json, data); });
                } else {
                    button.disabled = false;
                    showBulkErrors(json, data);
                }
            });
        });
    }
    function showBulkErrors(json, data) {
        var result = document.getElementById('lmBulkResult');
        if (!result) { return; }
        var errors = data.errors || [];
        if (!errors.length) { result.hidden = true; return; }
        result.hidden = false;
        result.innerHTML = esc((json && json.message) || '') + '<ul>' + errors.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>';
    }

    /* 'Değiştir' — aday listesi / arama */
    var searchTimer = null;
    function toggleChange(row, button) {
        var id = row.getAttribute('data-id');
        var existing = document.getElementById('lmChange' + id);
        if (existing) {
            existing.remove();
            button.setAttribute('aria-expanded', 'false');
            button.focus();
            return;
        }
        var tr = document.createElement('tr');
        tr.className = 'lm-change';
        tr.id = 'lmChange' + id;
        tr.setAttribute('data-for', id);
        tr.innerHTML = '<td colspan="5"><div class="lm-change-box">'
            + '<label class="ui-label" for="lmChangeQ' + id + '">Hepsiburada ilanı seçin</label>'
            + '<span class="ui-hint" id="lmChangeHint' + id + '">Ad, barkod veya SKU ile arayın (en az 2 karakter). Boşken en uygun adaylar listelenir. Kapatmak için Esc.</span>'
            + '<input type="search" class="ui-input" id="lmChangeQ' + id + '" autocomplete="off" aria-describedby="lmChangeHint' + id + '" placeholder="Örn. 8680000000011 · SKU-123 · ürün adı">'
            + '<ul class="lm-pick" aria-label="Hepsiburada ilanları" aria-live="polite"></ul></div></td>';
        row.parentNode.insertBefore(tr, row.nextSibling);
        button.setAttribute('aria-expanded', 'true');
        loadCandidates(tr, '');
        tr.querySelector('input').focus();
    }

    function loadCandidates(changeRow, query) {
        var list = changeRow.querySelector('.lm-pick');
        var productId = changeRow.getAttribute('data-for');
        list.innerHTML = '<li><span class="lm-pick-note"><i data-lucide="loader-circle" class="lm-spin" aria-hidden="true"></i> Yükleniyor…</span></li>';
        icons(list);
        var url = BASE + '/search?product_id=' + encodeURIComponent(productId) + '&q=' + encodeURIComponent(query);
        request('GET', url).then(function (json) {
            if (!document.body.contains(list)) { return; }
            if (!json || !json.success) { list.innerHTML = '<li><span class="lm-pick-note">' + esc((json && json.message) || 'Arama yapılamadı.') + '</span></li>'; return; }
            var items = (json.data && json.data.items) || [];
            if (!items.length) { list.innerHTML = '<li><span class="lm-pick-note">Sonuç yok.</span></li>'; return; }
            list.innerHTML = items.map(function (item) {
                var stateText = item.is_salable ? 'Satışta' : (item.has_listing ? 'Satışta değil' : 'İlan yok');
                var note = item.linked_to ? ' · başka ürüne bağlı: ' + esc(item.linked_to)
                    : (item.transfer_from ? ' · içeri alınmış kopyadan devralınır: ' + esc(item.transfer_from) : '');
                var score = item.score > 0
                    ? '<span class="ui-badge ' + (item.score >= THRESHOLD.bulk ? 'is-success' : 'is-warning') + '">%' + item.score + '</span>'
                    : '<span class="ui-badge is-danger">Ad/miktar uyumsuz</span>';
                return '<li><button type="button" class="lm-pick-btn" data-pick="' + esc(item.hb_sku) + '"' + (item.linked_to ? ' disabled' : '') + '>'
                    + '<span><span class="lm-name">' + esc(item.name) + '</span><span class="lm-meta">Barkod ' + esc(item.barcode || '—') + ' · SKU ' + esc(item.merchant_sku || '—')
                    + ' · ' + money(item.price) + ' · Stok ' + (item.stock === null ? '—' : num(item.stock)) + ' · ' + stateText + note + '</span></span>'
                    + score + '</button></li>';
            }).join('');
        });
    }

    /* ─── gönderim sonuçları (POST /products/publish/result) ─── */
    var resultDialog = document.getElementById('lmResultDialog');
    function openResults() {
        if (!resultDialog || typeof resultDialog.showModal !== 'function') { return; }
        resultDialog.showModal();
        document.getElementById('lmTrackingId').focus();
    }
    function queryResults(event) {
        event.preventDefault();
        var input = document.getElementById('lmTrackingId');
        var submit = document.getElementById('lmResultSubmit');
        var area = document.getElementById('lmResultArea');
        var tracking = input.value.trim();
        if (tracking && !/^[A-Za-z0-9-]+$/.test(tracking)) {
            input.setAttribute('aria-invalid', 'true');
            area.innerHTML = '<p class="ui-hint" role="alert">Takip numarası yalnız harf, rakam ve tire içerebilir.</p>';
            input.focus();
            return;
        }
        input.removeAttribute('aria-invalid');
        submit.disabled = true;
        area.innerHTML = '<p class="lm-muted"><i data-lucide="loader-circle" class="lm-spin" aria-hidden="true"></i> Hepsiburada\'dan sorgulanıyor…</p>';
        icons(area);
        request('POST', '/products/publish/result', { marketplace_id: MP_ID, tracking_id: tracking }).then(function (json) {
            submit.disabled = false;
            if (!json || !json.success) {
                area.innerHTML = '<div class="ui-banner is-danger" role="alert"><div class="ui-banner-body"><p class="ui-banner-title">Sorgulanamadı</p><p class="ui-banner-text">'
                    + esc((json && json.message) || 'Bilinmeyen hata') + '</p></div></div>';
                return;
            }
            var results = (json.data && json.data.results) || [];
            var html = '<p class="lm-flush" role="status">' + esc(json.message || '') + '</p>';
            var changed = false;
            results.forEach(function (r) {
                var badge = r.status === 'tamamlandi' ? 'is-success' : (r.status === 'isleniyor' ? 'is-info' : (r.status === 'kismi' ? 'is-warning' : 'is-danger'));
                var label = { tamamlandi: 'Tamamlandı', isleniyor: 'İşleniyor', kismi: 'Kısmen', hata: 'Hata' }[r.status] || r.status;
                html += '<div class="lm-result"><div class="lm-result-head"><span class="ui-badge ' + badge + '">' + esc(label) + '</span>'
                    + '<span class="ui-mono">' + esc(r.tracking_id) + '</span><p>' + esc(r.message) + '</p></div>';
                if (r.products && r.products.length) {
                    html += '<div class="ui-table-wrap"><table class="ui-table"><thead><tr><th scope="col">Ürün</th><th scope="col">Satıcı SKU</th><th scope="col">Barkod</th><th scope="col">HB SKU</th><th scope="col">Durum</th></tr></thead><tbody>'
                        + r.products.map(function (p) {
                            if (p.product_id && p.status !== 'isleniyor') { changed = true; }
                            var tone = p.status === 'basarili' ? 'is-success' : (p.status === 'hata' ? 'is-danger' : 'is-info');
                            var text = p.status === 'basarili' ? 'Başarılı' : (p.status === 'hata' ? 'Reddedildi' : 'İşleniyor');
                            return '<tr><td>' + esc(p.product_name || (p.product_id ? '#' + p.product_id : '—')) + '</td><td class="ui-mono">' + esc(p.merchant_sku || '—')
                                + '</td><td class="ui-mono">' + esc(p.barcode || '—') + '</td><td class="ui-mono">' + esc(p.hb_sku || '—') + '</td><td><span class="ui-badge ' + tone + '">' + esc(text) + '</span>'
                                + (p.messages && p.messages.length ? '<div class="ui-cell-sub">' + esc(p.messages.join(', ')) + '</div>' : '') + '</td></tr>';
                        }).join('') + '</tbody></table></div>';
                }
                html += '</div>';
            });
            area.innerHTML = html;
            announce(json.message || '');
            if (changed) { load(true); }
        });
    }

    /* ─── olaylar ─── */
    document.addEventListener('click', function (event) {
        var target = event.target.closest('button, a');
        if (!target) { return; }
        if (target.matches('[data-lm-refresh]')) { refresh(); return; }
        if (target.matches('[data-lm-retry]')) { root.innerHTML = skeleton; root.setAttribute('aria-busy', 'true'); load(false); return; }
        if (target.id === 'lmResultOpen') { openResults(); return; }
        if (target.matches('[data-result-close]')) { resultDialog.close(); document.getElementById('lmResultOpen').focus(); return; }
        if (!root.contains(target)) { return; }

        if (target.matches('[data-filter]')) {
            state.filter = target.getAttribute('data-filter');
            state.page = 1;
            root.querySelectorAll('[data-filter]').forEach(function (b) { b.setAttribute('aria-pressed', b === target ? 'true' : 'false'); });
            renderRows();
            announce(visibleRows().length + ' ürün listeleniyor.');
            return;
        }
        if (target.matches('[data-page]')) {
            state.page += target.getAttribute('data-page') === 'next' ? 1 : -1;
            renderRows();
            var table = root.querySelector('.lm-table');
            if (table) { table.scrollIntoView({ block: 'start' }); }
            return;
        }
        if (target.matches('[data-bulk]')) { bulkApprove(target.getAttribute('data-bulk'), target); return; }

        var row = target.closest('tr[data-id]');
        if (row && target.matches('[data-approve]')) { approve(row.getAttribute('data-id'), target.getAttribute('data-approve'), target); return; }
        if (row && target.matches('[data-change]')) { toggleChange(row, target); return; }
        if (row && target.matches('[data-unlink]')) {
            var name = row.querySelector('.lm-name');
            unlink(row.getAttribute('data-id'), name ? name.textContent : '', target);
            return;
        }
        var changeRow = target.closest('tr.lm-change');
        if (changeRow && target.matches('[data-pick]') && !target.disabled) {
            approve(changeRow.getAttribute('data-for'), target.getAttribute('data-pick'), target);
        }
    });

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (input.id === 'lmSearch') { state.query = input.value; state.page = 1; renderRows(); return; }
        if (input.id === 'lmUnmatchedSearch') { state.unmatchedQuery = input.value; renderUnmatchedRows(); return; }
        if (input.id === 'lmThreshold') { updateThresholdInfo(); return; }
        if (input.matches && input.matches('tr.lm-change input')) {
            var query = input.value.trim();
            var changeRow = input.closest('tr.lm-change');
            clearTimeout(searchTimer);
            if (query.length === 1) { return; }
            searchTimer = setTimeout(function () { loadCandidates(changeRow, query); }, 300);
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.id === 'lmThreshold') {
            event.target.value = clampThreshold(event.target.value);
            updateThresholdInfo();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !event.target.closest) { return; }
        var changeRow = event.target.closest('tr.lm-change');
        if (!changeRow) { return; }
        event.preventDefault();
        var toggle = root.querySelector('tr[data-id="' + changeRow.getAttribute('data-for') + '"] [data-change]');
        changeRow.remove();
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
    });

    var resultForm = document.getElementById('lmResultForm');
    if (resultForm) { resultForm.addEventListener('submit', queryResults); }

    load(false);
})();
</script>
