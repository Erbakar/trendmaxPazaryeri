<?php
/**
 * Akıllı Eşleştirme sihirbazı.
 *
 * İç kategori/markaları pazaryeri karşılıklarına önce bulanık eşleştirmeyle,
 * kalanları yapay zekâyla eşler. Öneriler "Uygula" denene kadar DB'ye yazılmaz.
 */
$kind         = $data['kind'] ?? 'category';
$marketplaces = $data['marketplaces'] ?? [];
$mpId         = (int) ($data['marketplaceId'] ?? 0);
$aiAvailable  = (bool) ($data['aiAvailable'] ?? false);
$aiCredits    = (int) ($data['aiCredits'] ?? 0);
$aiReason     = (string) ($data['aiReason'] ?? '');
$minThreshold = (int) ($data['minThreshold'] ?? 50);
$confidenceSafe = (int) ($data['confidenceSafe'] ?? 75);
$csrf         = $data['_csrf'] ?? '';
$tur          = $kind === 'brand' ? 'Marka' : 'Kategori';
// RBAC (AuthorizationService): tarama/YZ/uygulama/geri alma = kategori VEYA marka düzenleme yetkisi
$can          = $data['_can'] ?? static fn(string $permission): bool => true;
$canRun       = (bool) ($can('categories.edit') || $can('brands.edit'));
?>

<style>
.am-wrap{display:flex;flex-direction:column;gap:14px}
.am-card{background:var(--bg-card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:12px;padding:16px}
.am-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.am-f{display:flex;flex-direction:column;gap:4px}
.am-f label{font-size:11.5px;font-weight:700;color:var(--text-secondary,#475569)}
.am-f select,.am-f input{padding:7px 10px;border:1px solid var(--border,#e5e7eb);border-radius:8px;font-size:13px;background:var(--bg-sidebar,#f8fafc);color:inherit}
.am-kpi{display:flex;gap:20px;flex-wrap:wrap;padding:12px 14px;background:rgba(127,127,127,.06);border-radius:10px;font-size:12.5px}
.am-kpi b{display:block;font-size:17px}
.am-bar{height:9px;background:rgba(127,127,127,.15);border-radius:6px;overflow:hidden;margin:8px 0}
.am-bar span{display:block;height:100%;background:var(--primary,var(--primary));width:0;transition:width .3s}
.am-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.am-tbl th,.am-tbl td{padding:7px 9px;border-bottom:1px solid var(--border,#e5e7eb);text-align:left}
.am-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted,#6b7280)}
.am-badge{display:inline-block;padding:2px 8px;border-radius:16px;font-size:11px;font-weight:700}
.am-hi{background:#dcfce7;color:#15803d}
.am-mid{background:#fef3c7;color:#92400e}
.am-no{background:#fee2e2;color:#b91c1c}
.am-msg{padding:11px 13px;border-radius:9px;font-size:12.5px;margin-bottom:10px}
.am-msg.info{background:rgba(59,130,246,.08);color:#1d4ed8}
.am-msg.warn{background:rgba(239,68,68,.08);color:#b91c1c}
.am-msg.ok{background:rgba(16,185,129,.10);color:#047857}
@media (max-width:768px){.am-row{flex-direction:column;align-items:stretch}}
</style>

<div class="am-wrap">

  <div class="am-card">
    <div class="am-row">
      <div class="am-f">
        <label for="amKind">Ne eşleştirilecek?</label>
        <select id="amKind">
          <option value="category" <?= $kind === 'category' ? 'selected' : '' ?>>Kategoriler</option>
          <option value="brand" <?= $kind === 'brand' ? 'selected' : '' ?>>Markalar</option>
        </select>
      </div>
      <div class="am-f">
        <label for="amMp">Pazaryeri</label>
        <select id="amMp">
          <?php foreach ($marketplaces as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= $mpId === (int) $m['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($m['display_name'] ?? $m['slug']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="am-f">
        <label for="amThreshold">Otomatik uygulama eşiği: <strong id="amThresholdVal"><?= $minThreshold ?></strong></label>
        <input type="range" id="amThreshold" min="<?= $minThreshold ?>" max="95" value="<?= $minThreshold ?>" step="5"
               oninput="document.getElementById('amThresholdVal').textContent=this.value">
      </div>
      <?php if ($canRun): ?>
      <button class="btn btn-primary btn-sm" id="amStartBtn" onclick="amStart()" type="button">
        <i data-lucide="wand-2" style="width:14px;height:14px"></i> 1 · Bulanık Tara
      </button>
      <button class="btn btn-secondary btn-sm" id="amAiBtn" onclick="amRunAi()" type="button" disabled>
        <i data-lucide="sparkles" style="width:14px;height:14px"></i> 2 · Kalanları YZ ile Eşleştir
      </button>
      <button class="btn btn-success btn-sm" id="amApplyBtn" onclick="amApply()" type="button" disabled>
        <i data-lucide="check-check" style="width:14px;height:14px"></i> 3 · Uygula
      </button>
      <button class="btn btn-outline btn-sm" id="amUndoBtn" onclick="amUndo()" type="button" style="display:none">
        <i data-lucide="undo-2" style="width:14px;height:14px"></i> Son uygulamayı geri al
      </button>
      <?php endif; ?>
    </div>

    <?php if (!$canRun): ?>
    <p class="am-msg info" role="note" style="margin:10px 0 0">Akıllı Eşleştirme kategori ya da marka düzenleme yetkisi ister; rolünüzde bu yetki yok. Yöneticinizden yetki isteyin.</p>
    <?php endif; ?>

    <p style="margin:10px 0 0;font-size:11.5px;color:var(--text-muted,#6b7280)">
      Eşiğin altında kalan öneriler uygulanmaz; en düşük eşik %<?= $minThreshold ?>'dir.
      <?php if ($aiAvailable): ?>
        Yapay zekâ kredisi: <strong id="amCredits"><?= $aiCredits ?></strong> (parti başına 3 kredi).
      <?php else: ?>
        <span style="color:#b45309">&#9888; <?= htmlspecialchars($aiReason !== ''
            ? $aiReason
            : 'Yapay zekâ şu an kullanılamıyor.') ?></span>
        Bulanık eşleştirme normal çalışmaya devam eder.
      <?php endif; ?>
    </p>
  </div>

  <div id="amMessages"></div>

  <div class="am-card" id="amProgressCard" style="display:none">
    <div style="font-size:13px;font-weight:700" id="amPhase">Taranıyor…</div>
    <div class="am-bar"><span id="amBar"></span></div>
    <div class="am-kpi" id="amStats"></div>
  </div>

  <div class="am-card">
    <div style="overflow-x:auto"><table class="am-tbl" id="amTable">
      <thead><tr>
        <th style="width:34px"><input type="checkbox" id="amAll" onchange="amToggleAll(this)"></th>
        <th>İç <?= $tur ?></th><th>Önerilen pazaryeri karşılığı</th>
        <th style="width:70px">Güven</th><th style="width:90px">Yöntem</th>
      </tr></thead>
      <tbody><tr><td colspan="5" style="text-align:center;padding:26px;color:var(--text-muted,#6b7280)">
        <?= $canRun ? 'Başlamak için "Bulanık Tara" deyin.' : 'Eşleştirme önerileri yetkili kullanıcılar tarafından oluşturulur.' ?>
      </td></tr></tbody>
    </table></div>
  </div>
</div>

<script>
(function () {
    'use strict';

    const CSRF_AM = <?= json_encode($csrf) ?>;
    const AI_VAR  = <?= $aiAvailable ? 'true' : 'false' ?>;
    // Güvenli bant: bunun altındakiler uygulanır ama uyarı ikonu alır
    const SAFE = <?= (int) $confidenceSafe ?>;
    const AM = { token: '', total: 0, rows: [], secili: new Set() };

    const el = (id) => document.getElementById(id);
    const esc = (s) => { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; };

    async function amPost(url, alanlar) {
        const body = new FormData();
        body.append('_csrf_token', CSRF_AM);
        Object.keys(alanlar).forEach((k) => body.append(k, alanlar[k]));

        const resp = await fetch(url, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        const metin = await resp.text();
        let veri;
        try { veri = JSON.parse(metin); }
        catch (e) {
            if (resp.status === 401) throw new Error('Oturumunuz sona ermiş — sayfayı yenileyin.');
            throw new Error('Sunucu beklenen yanıtı vermedi (HTTP ' + resp.status + ').');
        }
        if (!veri || veri.success !== true) throw new Error((veri && veri.message) || 'İşlem başarısız.');
        return veri.data || {};
    }

    function amMsg(tip, metin) {
        el('amMessages').innerHTML = `<div class="am-msg ${tip}">${esc(metin)}</div>`;
    }

    // ─── 1. Bulanık tarama ────────────────────────────────
    window.amStart = async function () {
        el('amStartBtn').disabled = true;
        el('amMessages').innerHTML = '';
        el('amProgressCard').style.display = 'block';
        el('amPhase').textContent = 'Bulanık eşleştirme taranıyor…';
        el('amBar').style.width = '0%';

        try {
            const bas = await amPost('/mapping/auto/start', {
                marketplace_id: el('amMp').value,
                kind: el('amKind').value
            });

            AM.token = bas.token;
            AM.total = bas.total;

            if (AM.total === 0) {
                amMsg('ok', 'Eşleşmemiş kayıt yok — hepsi zaten eşleştirilmiş.');
                el('amProgressCard').style.display = 'none';
                el('amStartBtn').disabled = false;
                return;
            }

            let offset = 0, bitti = false;
            while (!bitti) {
                const d = await amPost('/mapping/auto/chunk', { token: AM.token, offset: offset });
                offset = d.offset;
                bitti = d.done;
                el('amBar').style.width = Math.round((offset / Math.max(1, d.total)) * 100) + '%';
                amRenderStats(d.stats);
            }

            await amLoadTable();
            el('amPhase').textContent = 'Bulanık tarama tamamlandı.';
            el('amApplyBtn').disabled = false;
            el('amAiBtn').disabled = !AI_VAR;
            amKapsamUyarisi(bas.catalog_size);
        } catch (err) {
            amMsg('warn', err.message);
            el('amProgressCard').style.display = 'none';
        }
        el('amStartBtn').disabled = false;
    };

    function amKapsamUyarisi(katalogBoyu) {
        const esik = parseInt(el('amThreshold').value, 10);
        const gecen = AM.rows.filter((r) => r.score >= esik).length;
        const kapsama = AM.rows.length > 0 ? Math.round((gecen / AM.rows.length) * 100) : 0;

        if (kapsama < 50) {
            amMsg('warn', `Kapsama %${kapsama} — beklenenin altında. Pazaryeri katalogu ${katalogBoyu} kayıt içeriyor; ` +
                'eksikse Kategori/Marka Eşleştirme ekranından "Pazaryerinden Çek" ile katalogu yenileyin.');
        } else {
            amMsg('info', `Kapsama %${kapsama} — ${gecen}/${AM.rows.length} kayıt eşiği geçti.`);
        }
    }

    // ─── 2. Yapay zekâ aşaması ────────────────────────────
    window.amRunAi = async function () {
        if (!AM.token) return;
        el('amAiBtn').disabled = true;
        el('amPhase').textContent = 'Yapay zekâ ile eşleştiriliyor…';

        try {
            let offset = 0, bitti = false, tur = 0;
            while (!bitti && tur < 60) {
                tur++;
                const d = await amPost('/mapping/auto/ai', {
                    token: AM.token,
                    threshold: el('amThreshold').value,
                    offset: offset
                });

                if (d.stopped) { amMsg('warn', d.notice || 'Yapay zekâ durdu.'); break; }

                offset = d.offset;
                bitti = d.done;
                if (d.stats) amRenderStats(d.stats);
                if (d.credits !== undefined && el('amCredits')) el('amCredits').textContent = d.credits;
            }

            await amLoadTable();
            el('amPhase').textContent = 'Yapay zekâ aşaması tamamlandı.';
        } catch (err) {
            amMsg('warn', err.message);
        }
        el('amAiBtn').disabled = false;
    };

    // ─── 3. Uygula ────────────────────────────────────────
    window.amApply = async function () {
        if (!AM.token) return;
        el('amApplyBtn').disabled = true;

        try {
            const secili = Array.from(AM.secili);
            const d = await amPost('/mapping/auto/apply', {
                token: AM.token,
                threshold: el('amThreshold').value,
                only_ids: secili.length ? JSON.stringify(secili) : ''
            });

            amMsg(d.failed > 0 ? 'warn' : 'ok',
                `${d.applied} eşleştirme uygulandı${d.failed > 0 ? `, ${d.failed} hata` : ''}.` +
                (d.errors && d.errors.length ? ' İlk hata: ' + d.errors[0].error : ''));

            el('amUndoBtn').style.display = 'inline-flex';
            await amLoadTable();
        } catch (err) {
            amMsg('warn', err.message);
        }
        el('amApplyBtn').disabled = false;
    };

    window.amUndo = async function () {
        if (!AM.token || !confirm('Bu sihirbazda uygulanan eşleştirmeler geri alınacak. Onaylıyor musunuz?')) return;

        try {
            const d = await amPost('/mapping/auto/undo', { token: AM.token });
            amMsg('ok', `${d.removed} eşleştirme geri alındı.`);
            el('amUndoBtn').style.display = 'none';
            await amLoadTable();
        } catch (err) {
            amMsg('warn', err.message);
        }
    };

    // ─── Tablo ────────────────────────────────────────────
    async function amLoadTable() {
        const d = await amPost('/mapping/auto/proposals', { token: AM.token, page: 1 });
        AM.rows = d.rows || [];
        amRenderTable();
    }

    function amRenderTable() {
        const esik = parseInt(el('amThreshold').value, 10);
        const tbody = el('amTable').querySelector('tbody');

        if (AM.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:26px;color:#6b7280">Öneri yok.</td></tr>';
            return;
        }

        tbody.innerHTML = AM.rows.map((r) => {
            const gecer = r.mp_id !== '' && r.score >= esik;
            const guvenli = r.score >= SAFE;
            const rozet = guvenli ? 'am-hi' : (r.score >= esik ? 'am-mid' : 'am-no');
            const ikon = (!guvenli && r.score >= esik) ? '&#9888; ' : '';
            const yontem = { birebir: 'birebir', normalize: 'normalize', bulanik: 'bulanık', yz: 'yapay zekâ', yok: '—' }[r.method] || r.method;

            return `<tr>
                <td><input type="checkbox" class="amRow" value="${r.internal_id}" ${gecer ? 'checked' : ''}
                     onchange="amToggleRow(${r.internal_id}, this.checked)"></td>
                <td title="${esc(r.internal_path)}">${esc(r.internal_name)}</td>
                <td>${r.mp_id ? esc(r.mp_name) : '<span style="color:#b91c1c">eşleşme bulunamadı</span>'}</td>
                <td><span class="am-badge ${rozet}" title="${guvenli ? 'Güvenli eşleşme' : 'Otomatik eşleştirildi, kontrol edin'}">${ikon}${r.score}</span></td>
                <td style="font-size:11.5px;color:#6b7280">${esc(yontem)}</td>
            </tr>`;
        }).join('');

        AM.secili = new Set(AM.rows.filter((r) => r.mp_id !== '' && r.score >= esik).map((r) => r.internal_id));
    }

    function amRenderStats(s) {
        if (!s) return;
        el('amStats').innerHTML = `
            <div>Toplam<b>${s.total || 0}</b></div>
            <div style="color:#15803d">Güvenli<b>${s.high || 0}</b></div>
            <div style="color:#92400e">&#9888; Kontrol edilmeli<b>${s.review || 0}</b></div>
            <div style="color:#b91c1c">Eşleşmedi<b>${s.none || 0}</b></div>`;
    }

    window.amToggleAll = function (c) {
        document.querySelectorAll('.amRow').forEach((x) => {
            x.checked = c.checked;
            const id = parseInt(x.value, 10);
            if (c.checked) AM.secili.add(id); else AM.secili.delete(id);
        });
    };
    window.amToggleRow = function (id, secili) {
        if (secili) AM.secili.add(id); else AM.secili.delete(id);
    };

    el('amThreshold').addEventListener('change', () => { if (AM.rows.length) amRenderTable(); });

    if (typeof lucide !== 'undefined') lucide.createIcons();
})();
</script>
