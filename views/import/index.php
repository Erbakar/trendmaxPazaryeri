<?php
$marketplaces = $data['marketplaces'] ?? [];
$recentJobs = $data['recentJobs'] ?? [];
$feedToken = $data['feedToken'] ?? '';
$feedIpWhitelist = $data['feedIpWhitelist'] ?? '';
$feedPassword = $data['feedPassword'] ?? '';
$feedEnabled = $data['feedEnabled'] ?? false;
$xmlFields = $data['xmlFields'] ?? [];
$xmlUploadLimit = $data['xmlUploadLimit'] ?? '';
$xmlUploadBytes = (int) ($data['xmlUploadBytes'] ?? 0);
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$feedUrl = $baseUrl . '/feed/products.xml?token=' . urlencode($feedToken);
?>

<style>
.ie-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.ie-full { grid-column:1/-1; }
.ie-card { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; overflow:hidden; }
.ie-head { padding:12px 16px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; display:flex; align-items:center; gap:6px; border-bottom:1px solid var(--border); background:var(--bg-sidebar); }
.ie-head [data-lucide] { width:14px; height:14px; }
.ie-body { padding:16px; }
.ie-row { display:grid; gap:10px; margin-bottom:10px; }
.ie-r2 { grid-template-columns:1fr 1fr; }
.ie-f { display:flex; flex-direction:column; gap:3px; }
.ie-f label { font-size:11px; font-weight:700; color:var(--text-secondary); }
.ie-f input,.ie-f select,.ie-f textarea { padding:7px 10px; border:1px solid var(--border); border-radius:6px; background:var(--bg-sidebar); color:var(--text-primary); font-size:12px; font-family:inherit; }
.ie-f input:focus,.ie-f select:focus,.ie-f textarea:focus { outline:none; border-color:var(--primary); }
.ie-f textarea { min-height:60px; resize:vertical; font-family:'JetBrains Mono',monospace; font-size:11px; }
.ie-foot { padding:12px 16px; border-top:1px solid var(--border); display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* Progress */
.ie-progress { display:none; padding:12px; background:var(--bg-sidebar); border-radius:8px; margin-top:10px; }
.ie-progress.show { display:block; }
.ie-bar-wrap { width:100%; height:8px; background:var(--bg-sidebar-hover,rgba(0,0,0,.1)); border-radius:6px; overflow:hidden; margin:6px 0; }
.ie-bar { height:100%; background:var(--primary); border-radius:6px; transition:width .3s; width:0; }
.ie-stats { display:flex; gap:14px; font-size:11px; color:var(--text-muted); }
.ie-stats strong { font-weight:700; }

/* Feed URL */
.ie-url { display:flex; gap:6px; align-items:center; margin:8px 0; }
.ie-url-box { flex:1; padding:7px 10px; background:var(--bg-sidebar); border:1px solid var(--border); border-radius:6px; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--text-secondary); word-break:break-all; cursor:text; user-select:all; }
.ie-url-copy { padding:5px 10px; border:1px solid var(--border); border-radius:5px; background:none; color:var(--text-muted); font-size:10px; cursor:pointer; white-space:nowrap; }
.ie-url-copy:hover { border-color:var(--primary); color:var(--primary); }

/* Toggle */
.ie-toggle { display:flex; align-items:center; gap:8px; cursor:pointer; }
.ie-toggle input[type="checkbox"] { width:36px; height:20px; appearance:none; background:var(--border); border-radius:10px; position:relative; cursor:pointer; transition:all .2s; }
.ie-toggle input[type="checkbox"]::after { content:''; width:16px; height:16px; background:#fff; border-radius:50%; position:absolute; top:2px; left:2px; transition:all .2s; }
.ie-toggle input[type="checkbox"]:checked { background:var(--primary); }
.ie-toggle input[type="checkbox"]:checked::after { left:18px; }
.ie-toggle span { font-size:12px; font-weight:700; color:var(--text-secondary); }

/* Export buttons */
.ie-export-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.ie-export-btn { display:flex; align-items:center; gap:8px; padding:12px 14px; border:1px solid var(--border); border-radius:8px; background:var(--bg-sidebar); color:var(--text-primary); text-decoration:none; font-size:13px; font-weight:700; transition:all .15s; cursor:pointer; }
.ie-export-btn:hover { border-color:var(--primary); transform:translateY(-1px); box-shadow:0 0 0 1px var(--border); }
.ie-export-btn [data-lucide] { width:18px; height:18px; }
.ie-export-btn small { display:block; font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px; }

/* Status dot */
.ie-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.ie-dot.on { background:#10b981; box-shadow:0 0 0 1px var(--border); }
.ie-dot.off { background:var(--text-muted); }

/* XML kaynak seçici */
.ie-tabs { display:inline-flex; padding:3px; background:var(--bg-sidebar); border:1px solid var(--border); border-radius:8px; gap:2px; margin-bottom:12px; }
.ie-tab { padding:6px 14px; border:0; border-radius:6px; background:none; color:var(--text-secondary); font-size:12px; font-weight:700; font-family:inherit; cursor:pointer; display:flex; align-items:center; gap:6px; }
.ie-tab [data-lucide] { width:13px; height:13px; }
.ie-tab[aria-selected="true"] { background:var(--bg-card); color:var(--primary); box-shadow:0 0 0 1px var(--border); }
.ie-tab:focus-visible { outline:2px solid var(--primary); outline-offset:1px; }

/* Çözümleme özeti */
.ie-summary { display:flex; flex-wrap:wrap; gap:8px 18px; align-items:center; padding:10px 12px; background:var(--bg-sidebar); border:1px solid var(--border); border-radius:8px; margin-bottom:12px; font-size:12px; color:var(--text-secondary); }
.ie-summary strong { color:var(--text-primary); }
.ie-summary [data-lucide] { width:14px; height:14px; color:var(--success,#10b981); }

/* Eşleme tablosu */
.ie-map-head { display:grid; grid-template-columns:1fr 20px 1fr; gap:8px; align-items:center; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; padding:0 4px 4px; }
.ie-map-row { display:grid; grid-template-columns:1fr 20px 1fr; gap:8px; align-items:center; padding:5px 4px; border-bottom:1px solid var(--border); }
.ie-map-row:last-child { border-bottom:0; }
.ie-map-row label { font-size:12px; color:var(--text-secondary); }
.ie-map-row.is-required label { font-weight:700; color:var(--text-primary); }
.ie-map-row select { padding:6px 8px; border:1px solid var(--border); border-radius:6px; background:var(--bg-sidebar); color:var(--text-primary); font-size:12px; font-family:inherit; width:100%; }
.ie-map-row select:focus { outline:none; border-color:var(--primary); }
.ie-map-row [data-lucide] { width:12px; height:12px; color:var(--text-muted); }
.ie-req { color:var(--danger,#ef4444); }

/* Önizleme */
.ie-preview { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:5px; margin-top:8px; }
.ie-preview div { padding:5px 8px; background:var(--bg-sidebar); border-radius:5px; font-size:11px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ie-preview span { color:var(--text-muted); }
.ie-preview b { color:var(--text-primary); font-weight:600; }

/* Boş / hata durumu */
.ie-note { display:flex; gap:8px; align-items:flex-start; padding:10px 12px; border-radius:8px; font-size:12px; line-height:1.5; }
.ie-note [data-lucide] { width:15px; height:15px; flex-shrink:0; margin-top:1px; }
.ie-note.is-info { background:var(--bg-sidebar); color:var(--text-secondary); }
.ie-note.is-error { background:rgba(239,68,68,.08); color:var(--danger,#ef4444); }
.ie-spin { animation:ie-rotate 1s linear infinite; }
@keyframes ie-rotate { to { transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce) { .ie-spin { animation:none; } .ie-bar { transition:none; } }

@media (max-width:768px) { .ie-grid,.ie-r2,.ie-export-grid { grid-template-columns:1fr; } .ie-map-head,.ie-map-row { grid-template-columns:1fr; } .ie-map-row [data-lucide] { display:none; } }
</style>

<div class="ie-grid">

    <!-- === XML IMPORT === -->
    <div class="ie-card ie-full">
        <div class="ie-head"><i data-lucide="file-code"></i> XML İçe Aktar</div>
        <form method="POST" action="/import/xml" enctype="multipart/form-data" id="xmlImportForm">
            <input type="hidden" name="_csrf_token" id="xmlCsrf" value="<?=htmlspecialchars($data['_csrf']??'')?>">
            <input type="hidden" name="field_map" id="fieldMapInput" value="">
            <input type="hidden" name="xml_token" id="xmlToken" value="">
            <div class="ie-body">

                <div class="ie-tabs" role="tablist" aria-label="XML kaynağı">
                    <button type="button" class="ie-tab" role="tab" id="xmlTabFile" aria-selected="true" aria-controls="xmlPaneFile" data-source="file">
                        <i data-lucide="file-up"></i> Dosya Yükle
                    </button>
                    <button type="button" class="ie-tab" role="tab" id="xmlTabUrl" aria-selected="false" aria-controls="xmlPaneUrl" data-source="url">
                        <i data-lucide="link"></i> XML Linki
                    </button>
                </div>

                <div class="ie-row ie-r2">
                    <div class="ie-f" id="xmlPaneFile" role="tabpanel" aria-labelledby="xmlTabFile">
                        <label for="xmlFileInput">XML Dosyası</label>
                        <input type="file" name="xml_file" accept=".xml,text/xml,application/xml" id="xmlFileInput">
                        <span style="font-size:10px;color:var(--text-muted)">
                            Herhangi bir XML formatı — alanlar otomatik eşleşir<?php if ($xmlUploadLimit !== ''): ?> · sunucu yükleme sınırı: <?=htmlspecialchars((string) $xmlUploadLimit)?><?php endif; ?>
                        </span>
                    </div>

                    <div class="ie-f" id="xmlPaneUrl" role="tabpanel" aria-labelledby="xmlTabUrl" hidden>
                        <label for="xmlUrlInput">XML Linki</label>
                        <input type="url" name="xml_url" id="xmlUrlInput" placeholder="https://tedarikci.com/xml/urunler.xml" autocomplete="off" spellcheck="false">
                        <span style="font-size:10px;color:var(--text-muted)">Dosya indirmenize gerek yok — linki yapıştırın, sistem kendisi çeker</span>
                    </div>

                    <div class="ie-f">
                        <label for="xmlModeSelect">Mevcut ürünler (SKU eşleşirse)</label>
                        <select name="xml_mode" id="xmlModeSelect">
                            <option value="skip">Atla (sadece yeni ekle)</option>
                            <option value="update">Güncelle (fiyat / stok / bilgi)</option>
                            <option value="overwrite">Üzerine yaz (boş alanlar dahil)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top:12px">
                    <button type="button" class="btn btn-secondary btn-sm" id="xmlAnalyzeBtn">
                        <i data-lucide="search" style="width:14px;height:14px"></i> <span>XML'i Çözümle</span>
                    </button>
                </div>

                <div id="xmlError" class="ie-note is-error" style="margin-top:12px" hidden>
                    <i data-lucide="alert-circle"></i><span></span>
                </div>

                <!-- Çözümleme sonucu + eşleme -->
                <div id="xmlMapping" hidden style="margin-top:12px">
                    <div class="ie-summary">
                        <span><i data-lucide="check-circle-2"></i> <strong id="xmlCount">0</strong> ürün bulundu</span>
                        <span>Dosya: <strong id="xmlFileName">—</strong></span>
                        <label for="xmlNodeSelect" style="display:flex;align-items:center;gap:6px;margin:0">
                            Ürün düğümü:
                            <select id="xmlNodeSelect" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card);color:var(--text-primary);font-size:11px;font-family:inherit"></select>
                        </label>
                    </div>

                    <div style="font-size:12px;font-weight:700;color:var(--text-primary);margin-bottom:6px">Alan Eşleştirme</div>
                    <div class="ie-map-head"><span>Sistem Alanı</span><span></span><span>XML Etiketi</span></div>
                    <div id="xmlMappingRows"></div>

                    <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-top:12px">İlk Ürün Önizleme</div>
                    <div id="xmlPreview" class="ie-preview"></div>
                </div>

                <!-- İlerleme -->
                <div id="xmlProgress" class="ie-progress" role="status" aria-live="polite">
                    <div style="font-size:12px;font-weight:700;color:var(--text-primary)" id="xmlProgressLabel">İçe aktarılıyor…</div>
                    <div class="ie-bar-wrap"><div class="ie-bar" id="xmlBar"></div></div>
                    <div class="ie-stats">
                        <span><strong id="xmlStatDone">0</strong> / <strong id="xmlStatTotal">0</strong> ürün</span>
                        <span style="color:var(--success,#10b981)"><strong id="xmlStatCreated">0</strong> yeni</span>
                        <span style="color:var(--primary)"><strong id="xmlStatUpdated">0</strong> güncellendi</span>
                        <span><strong id="xmlStatSkipped">0</strong> atlandı</span>
                        <span style="color:var(--danger,#ef4444)"><strong id="xmlStatFailed">0</strong> hatalı</span>
                    </div>
                </div>
            </div>

            <div class="ie-foot">
                <button type="submit" class="btn btn-primary btn-sm" id="xmlSubmitBtn" disabled>
                    <i data-lucide="upload" style="width:14px;height:14px"></i> <span>İçe Aktarmayı Başlat</span>
                </button>
                <span style="font-size:11px;color:var(--text-muted)" id="xmlHint">Önce dosya seçin ya da link girin, ardından "XML'i Çözümle" deyin.</span>
            </div>
        </form>
    </div>

    <!-- === CSV IMPORT === -->
    <div class="ie-card">
        <div class="ie-head"><i data-lucide="file-spreadsheet"></i> CSV / Excel İçe Aktar</div>
        <form method="POST" action="/import/csv" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?=htmlspecialchars($data['_csrf']??'')?>">
            <div class="ie-body">
                <div class="ie-f" style="margin-bottom:10px">
                    <label>CSV Dosyası</label>
                    <input type="file" name="csv_file" accept=".csv,.txt,.xlsx" required>
                </div>
                <div class="ie-row ie-r2">
                    <div class="ie-f">
                        <label>Ayırıcı</label>
                        <select name="delimiter">
                            <option value=";">Noktalı virgül ( ; )</option>
                            <option value=",">Virgül ( , )</option>
                            <option value="&#9;">Tab</option>
                        </select>
                    </div>
                    <div class="ie-f" style="justify-content:flex-end">
                        <a href="/import/template" class="btn btn-secondary btn-sm" style="margin-top:auto">
                            <i data-lucide="file-down" style="width:12px;height:12px"></i> Şablon İndir
                        </a>
                    </div>
                </div>
            </div>
            <div class="ie-foot">
                <button type="submit" class="btn btn-success btn-sm"><i data-lucide="upload" style="width:14px;height:14px"></i> Yükle & İçe Aktar</button>
            </div>
        </form>
    </div>

    <!-- === DIŞA AKTARMA === -->
    <div class="ie-card">
        <div class="ie-head"><i data-lucide="upload-cloud"></i> Dışa Aktar</div>
        <div class="ie-body">
            <div class="ie-export-grid">
                <a href="/import/export-excel" class="ie-export-btn">
                    <i data-lucide="file-spreadsheet" style="color:#10b981"></i>
                    <div>Excel / CSV<small>Tüm ürünler · UTF-8 BOM</small></div>
                </a>
                <a href="/import/export-xml" class="ie-export-btn">
                    <i data-lucide="file-code" style="color:var(--primary)"></i>
                    <div>XML<small>Tüm aktif ürünler</small></div>
                </a>
            </div>
        </div>
    </div>

    <!-- === SON İŞLEMLER === -->
    <div class="ie-card ie-full">
        <div class="ie-head"><i data-lucide="list"></i> Son İçe Aktarma İşlemleri</div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Tarih</th><th>Mod</th><th>Durum</th><th class="text-right">Toplam</th><th class="text-right">Başarılı</th><th class="text-right">Hatalı</th></tr></thead>
                <tbody>
                    <?php if (empty($recentJobs)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted)">
                        <i data-lucide="inbox" style="width:24px;height:24px;opacity:.4;margin-bottom:4px"></i>
                        <p style="font-size:12px">Henüz import yapılmamış</p>
                    </td></tr>
                    <?php else: foreach ($recentJobs as $j): ?>
                    <tr>
                        <td class="text-nowrap" style="font-size:12px"><?=date('d.m.Y H:i', strtotime($j['started_at']??$j['created_at']??'now'))?></td>
                        <td><span class="badge badge-cyan" style="font-size:10px"><?=htmlspecialchars($j['import_mode']??'')?></span></td>
                        <td><?php $s=$j['status']??''; $bc=match($s){'completed'=>'badge-success','completed_with_errors'=>'badge-warning','failed'=>'badge-danger','processing'=>'badge-info',default=>'badge-secondary'}; echo "<span class='badge {$bc}' style='font-size:10px'>{$s}</span>"; ?></td>
                        <td class="text-right"><?=(int)($j['total_rows']??0)?></td>
                        <td class="text-right" style="color:var(--success)"><?=(int)($j['success_rows']??0)?></td>
                        <td class="text-right" style="color:var(--danger)"><?=(int)($j['failed_rows']??0)?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // Sunucudaki XmlFileImportService::SYSTEM_FIELDS ile tek kaynaktan beslenir.
    // Kontrolcü listeyi geçirmeyi unutursa çözümleme yanıtındaki kopya devreye girer
    // (ekran iki adresten açılıyor: /import ve /settings/import).
    let FIELDS = <?=json_encode($xmlFields, JSON_UNESCAPED_UNICODE)?>;
    let REQUIRED = <?=json_encode(\App\Services\Import\XmlFileImportService::REQUIRED_FIELDS)?>;
    const CHUNK_DELAY = 60;
    const UPLOAD_LIMIT = <?=$xmlUploadBytes?>;
    const UPLOAD_LIMIT_LABEL = '<?=htmlspecialchars((string) $xmlUploadLimit, ENT_QUOTES)?>';

    const form      = document.getElementById('xmlImportForm');
    if (!form) return;

    const el = {
        tabs:      Array.prototype.slice.call(document.querySelectorAll('.ie-tab')),
        paneFile:  document.getElementById('xmlPaneFile'),
        paneUrl:   document.getElementById('xmlPaneUrl'),
        file:      document.getElementById('xmlFileInput'),
        url:       document.getElementById('xmlUrlInput'),
        mode:      document.getElementById('xmlModeSelect'),
        analyze:   document.getElementById('xmlAnalyzeBtn'),
        submit:    document.getElementById('xmlSubmitBtn'),
        hint:      document.getElementById('xmlHint'),
        error:     document.getElementById('xmlError'),
        mapping:   document.getElementById('xmlMapping'),
        rows:      document.getElementById('xmlMappingRows'),
        preview:   document.getElementById('xmlPreview'),
        count:     document.getElementById('xmlCount'),
        fileName:  document.getElementById('xmlFileName'),
        node:      document.getElementById('xmlNodeSelect'),
        token:     document.getElementById('xmlToken'),
        map:       document.getElementById('fieldMapInput'),
        csrf:      document.getElementById('xmlCsrf'),
        progress:  document.getElementById('xmlProgress'),
        progressLabel: document.getElementById('xmlProgressLabel'),
        bar:       document.getElementById('xmlBar'),
        statDone:    document.getElementById('xmlStatDone'),
        statTotal:   document.getElementById('xmlStatTotal'),
        statCreated: document.getElementById('xmlStatCreated'),
        statUpdated: document.getElementById('xmlStatUpdated'),
        statSkipped: document.getElementById('xmlStatSkipped'),
        statFailed:  document.getElementById('xmlStatFailed'),
    };

    const state = { source: 'file', token: '', tags: [], count: 0, running: false };

    el.rows.addEventListener('change', syncMap);

    // ─── Yardımcılar ──────────────────────────────────

    function notify(message, type) {
        if (typeof Toast !== 'undefined' && Toast.show) Toast.show(message, type || 'info');
    }

    function showError(message) {
        el.error.querySelector('span').textContent = message;
        el.error.hidden = false;
    }

    function clearError() {
        el.error.hidden = true;
    }

    function busy(button, isBusy, busyText) {
        const label = button.querySelector('span');
        if (isBusy) {
            button.dataset.label = label ? label.textContent : '';
            if (label && busyText) label.textContent = busyText;
        } else if (label && button.dataset.label) {
            label.textContent = button.dataset.label;
        }
        button.disabled = isBusy;
    }

    function post(fields, file) {
        const body = new FormData();
        body.append('_csrf_token', el.csrf.value);
        Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });
        if (file) body.append('xml_file', file);

        return fetch(form.getAttribute('action'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('Sunucu beklenmeyen bir yanıt döndü (HTTP ' + response.status + ').');
            });
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                throw new Error((payload && payload.message) || 'İşlem başarısız.');
            }
            return payload.data || {};
        });
    }

    function resetAnalysis() {
        state.token = '';
        state.tags = [];
        state.count = 0;
        el.token.value = '';
        el.mapping.hidden = true;
        el.progress.classList.remove('show');
        el.submit.disabled = true;
        el.hint.textContent = 'Kaynak değişti — "XML\'i Çözümle" ile yeniden okutun.';
    }

    // ─── Kaynak sekmeleri ─────────────────────────────

    el.tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            state.source = tab.dataset.source;
            el.tabs.forEach(function (other) {
                other.setAttribute('aria-selected', String(other === tab));
            });
            el.paneFile.hidden = state.source !== 'file';
            el.paneUrl.hidden  = state.source !== 'url';
            clearError();
            resetAnalysis();
        });
    });

    el.file.addEventListener('change', resetAnalysis);
    el.url.addEventListener('input', resetAnalysis);

    // ─── Çözümleme ────────────────────────────────────

    el.analyze.addEventListener('click', function () {
        clearError();

        const fields = { xml_action: 'analyze', xml_source: state.source };
        let file = null;

        if (state.source === 'file') {
            file = el.file.files && el.file.files[0];
            if (!file) { showError('Önce bir XML dosyası seçin.'); return; }

            // post_max_size aşılırsa PHP tüm form verisini düşürür (CSRF hatası gibi
            // görünür) — sınırı burada yakalayıp kullanıcıyı link seçeneğine yönlendir
            if (UPLOAD_LIMIT > 0 && file.size > UPLOAD_LIMIT) {
                showError(
                    'Dosya ' + (file.size / 1048576).toFixed(1) + ' MB — sunucu yükleme sınırı ' +
                    (UPLOAD_LIMIT_LABEL || (UPLOAD_LIMIT / 1048576).toFixed(0) + 'M') +
                    '. "XML Linki" sekmesinden adresi yapıştırın ya da hosting panelinden ' +
                    'upload_max_filesize / post_max_size değerlerini yükseltin.'
                );
                return;
            }
        } else {
            const url = el.url.value.trim();
            if (!url) { showError('XML linkini yapıştırın.'); return; }
            fields.xml_url = url;
        }

        busy(el.analyze, true, 'Okunuyor…');
        el.hint.textContent = state.source === 'url'
            ? 'Link indiriliyor…'
            : 'Dosya yükleniyor — büyük dosyalarda bu adım biraz sürebilir.';

        post(fields, file).then(function (data) {
            renderAnalysis(data);
            el.hint.textContent = 'Eşleşmeleri kontrol edip içe aktarmayı başlatın.';
        }).catch(function (err) {
            showError(err.message);
            el.hint.textContent = 'Çözümleme başarısız.';
        }).then(function () {
            busy(el.analyze, false);
        });
    });

    function renderAnalysis(data) {
        state.token = data.token || '';
        state.tags  = data.tags || [];
        state.count = data.count || 0;

        el.token.value = state.token;
        el.count.textContent = state.count.toLocaleString('tr-TR');
        el.fileName.textContent = data.original_name || '—';

        el.node.innerHTML = '';
        (data.candidates || []).forEach(function (candidate) {
            const option = document.createElement('option');
            option.value = candidate.path;
            option.textContent = candidate.path + ' (' + candidate.count + ')';
            option.selected = candidate.path === data.item_path;
            el.node.appendChild(option);
        });

        adoptFieldList(data);
        buildRows(data.suggested || {}, data.sample || {});

        el.mapping.hidden = false;
        el.submit.disabled = false;

        if (data.repaired) {
            notify('XML dosyası onarılarak okundu (kodlama / tarayıcı sarmalı düzeltildi).', 'warning');
        }
        if (data.warning) {
            showError(data.warning);
            notify(data.warning, 'warning');
        }
    }

    // Çözümleme yanıtı alan listesini de taşır; doluysa sayfayla gelen kopyanın
    // yerine geçer. Böylece ekran, kontrolcünün listeyi geçirmesine bağlı kalmaz.
    function adoptFieldList(data) {
        if (data && data.fields && Object.keys(data.fields).length) {
            FIELDS = data.fields;
        }
        if (data && Array.isArray(data.required) && data.required.length) {
            REQUIRED = data.required;
        }
    }

    function buildRows(suggested, sample) {
        el.rows.innerHTML = '';

        // Alan listesi sunucudan gelmediyse ekran sessizce boş kalmasın
        if (!FIELDS || Object.keys(FIELDS).length === 0) {
            showError('Alan listesi sunucudan gelmedi — güncelleme paketi eksik yüklenmiş olabilir (app/Controllers/SettingController.php ve views/import/index.php). Yöneticinize bildirin.');
            el.submit.disabled = true;
            return;
        }

        Object.keys(FIELDS).forEach(function (key) {
            const required = REQUIRED.indexOf(key) !== -1;
            const row = document.createElement('div');
            row.className = 'ie-map-row' + (required ? ' is-required' : '');

            const label = document.createElement('label');
            label.setAttribute('for', 'xmlmap_' + key);
            label.textContent = FIELDS[key];
            if (required) {
                const star = document.createElement('span');
                star.className = 'ie-req';
                star.textContent = ' *';
                label.appendChild(star);
            }

            const arrow = document.createElement('i');
            arrow.setAttribute('data-lucide', 'arrow-right');

            const select = document.createElement('select');
            select.id = 'xmlmap_' + key;
            select.className = 'xml-map-select';
            select.dataset.field = key;
            if (required) select.required = true;

            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '— Eşleştirme yok —';
            select.appendChild(empty);

            state.tags.forEach(function (tag) {
                const option = document.createElement('option');
                option.value = tag;
                option.textContent = tag;
                if (suggested[key] === tag) option.selected = true;
                select.appendChild(option);
            });

            row.appendChild(label);
            row.appendChild(arrow);
            row.appendChild(select);
            el.rows.appendChild(row);
        });

        syncMap();
        renderPreview(sample);

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function renderPreview(sample) {
        el.preview.innerHTML = '';
        state.tags.forEach(function (tag) {
            const cell = document.createElement('div');
            const name = document.createElement('span');
            name.textContent = tag + ': ';
            const value = document.createElement('b');
            value.textContent = sample[tag] || '—';
            cell.title = tag + ': ' + (sample[tag] || '');
            cell.appendChild(name);
            cell.appendChild(value);
            el.preview.appendChild(cell);
        });
    }

    function syncMap() {
        const map = {};
        document.querySelectorAll('.xml-map-select').forEach(function (select) {
            if (select.value) map[select.dataset.field] = select.value;
        });
        el.map.value = JSON.stringify(map);
        return map;
    }

    // ─── Ürün düğümünü değiştir ───────────────────────

    el.node.addEventListener('change', function () {
        if (!state.token) return;
        clearError();

        post({ xml_action: 'node', xml_token: state.token, item_path: el.node.value })
            .then(function (data) {
                state.tags = data.tags || [];
                state.count = data.count || 0;
                el.count.textContent = state.count.toLocaleString('tr-TR');
                adoptFieldList(data);
                buildRows(syncMap(), data.sample || {});
            })
            .catch(function (err) { showError(err.message); });
    });

    // ─── İçe aktarma ──────────────────────────────────

    form.addEventListener('submit', function (event) {
        // Jeton yoksa JavaScript'siz yedek yola (tam sayfa gönderimi) izin ver
        if (!state.token) return;

        event.preventDefault();
        if (state.running) return;

        const map = syncMap();
        const missing = REQUIRED.filter(function (key) { return !map[key]; });
        if (missing.length) {
            showError('Zorunlu alanları eşleştirin: ' + missing.map(function (k) { return FIELDS[k] || k; }).join(', '));
            return;
        }

        clearError();
        startImport(map);
    });

    function startImport(map) {
        state.running = true;
        el.submit.disabled = true;
        el.analyze.disabled = true;
        el.progress.classList.add('show');
        el.progressLabel.textContent = 'İçe aktarılıyor…';
        el.statTotal.textContent = state.count.toLocaleString('tr-TR');
        el.hint.textContent = 'Sayfayı kapatmayın — aktarım sürüyor.';

        const payload = {
            xml_action: 'chunk',
            xml_token: state.token,
            xml_mode: el.mode.value,
            field_map: JSON.stringify(map)
        };

        function runChunk(offset) {
            payload.offset = offset;

            post(payload).then(function (data) {
                paint(data);

                if (data.done) {
                    finish(data);
                    return;
                }
                if (data.offset <= offset) {
                    throw new Error('Aktarım ilerlemedi — işlem durduruldu.');
                }
                window.setTimeout(function () { runChunk(data.offset); }, CHUNK_DELAY);
            }).catch(function (err) {
                state.running = false;
                el.analyze.disabled = false;
                el.submit.disabled = false;
                el.progressLabel.textContent = 'Aktarım durdu.';
                showError(err.message);
                notify(err.message, 'error');
            });
        }

        runChunk(0);
    }

    function paint(data) {
        const total = data.total || state.count || 0;
        const done  = data.offset || 0;
        const ratio = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

        el.bar.style.width = ratio + '%';
        el.statDone.textContent    = done.toLocaleString('tr-TR');
        el.statTotal.textContent   = total.toLocaleString('tr-TR');
        el.statCreated.textContent = (data.created || 0).toLocaleString('tr-TR');
        el.statUpdated.textContent = (data.updated || 0).toLocaleString('tr-TR');
        el.statSkipped.textContent = (data.skipped || 0).toLocaleString('tr-TR');
        el.statFailed.textContent  = (data.failed || 0).toLocaleString('tr-TR');
        el.progressLabel.textContent = 'İçe aktarılıyor… %' + ratio;
    }

    function finish(data) {
        state.running = false;
        el.progressLabel.textContent = data.message || 'Aktarım tamamlandı.';
        el.hint.textContent = 'Tamamlandı.';
        notify(data.message || 'XML içe aktarma tamamlandı', (data.failed || 0) > 0 ? 'warning' : 'success');

        if (data.errors && data.errors.length) {
            showError('İlk hatalar: ' + data.errors.slice(0, 3).join(' · '));
        }

        window.setTimeout(function () { window.location.reload(); }, 1800);
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
})();
</script>

