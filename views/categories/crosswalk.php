<?php
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$canEdit = !empty($data['canEdit']);
$canApply = !empty($data['canApply']);
?>
<style>
.cw-box{background:var(--surface,#fff);border:1px solid var(--border,#e3e7ee);border-radius:12px;padding:18px;margin:16px 0}
.cw-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cw-bar input[type=search],.cw-bar select{padding:7px 10px;border:1px solid var(--border,#d8dde6);border-radius:8px;background:var(--surface,#fff);color:inherit}
.cw-stats{display:flex;flex-wrap:wrap;gap:18px;margin:10px 0 0;font-size:13px}
.cw-stats b{font-size:18px;display:block}
.cw-table{width:100%;border-collapse:collapse}
.cw-table th,.cw-table td{padding:10px 8px;text-align:left;vertical-align:top;border-bottom:1px solid var(--border,#e3e7ee);font-size:13px}
.cw-table small{display:block;color:var(--text-secondary,#65748a);margin-top:3px}
.cw-samples{margin:0;padding-left:16px;max-width:380px}
.cw-samples li{margin:1px 0}
.cw-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.cw-ok{background:#dcfce7;color:#15803d}.cw-wait{background:#fef3c7;color:#92400e}.cw-none{background:#e5e7eb;color:#374151}.cw-skip{background:#fee2e2;color:#b91c1c}
.cw-pick{margin-top:8px;border:1px solid var(--border,#e3e7ee);border-radius:8px;padding:8px;max-width:520px}
.cw-pick input{width:100%;padding:6px 8px;border:1px solid var(--border,#d8dde6);border-radius:6px;background:var(--surface,#fff);color:inherit}
.cw-opt{display:block;width:100%;text-align:left;border:0;background:none;padding:5px 6px;border-radius:6px;cursor:pointer;color:inherit;font-size:12.5px}
.cw-opt:hover{background:rgba(127,127,127,.12)}
.cw-opt small{display:inline;margin-left:6px}
.cw-msg{font-size:12px;margin-top:6px}.cw-err{color:#b42318}
.cw-scroll{overflow-x:auto}
.cw-actions{display:flex;flex-direction:column;gap:6px;min-width:150px}
textarea.cw-json{width:100%;min-height:140px;font-family:monospace;font-size:12px;border:1px solid var(--border,#d8dde6);border-radius:8px;padding:8px;background:var(--surface,#fff);color:inherit}
</style>

<div class="ui-page-head">
  <div>
    <h1>Trendyol'dan Hepsiburada'ya kategori aktarımı</h1>
    <p>Ürünler Trendyol'da kullandıkları kategoriye göre gruplandı. Her grup için Hepsiburada kategorisini onaylayın; onaylanan kategori o ürünlere Hepsiburada eşleştirmesi olarak yazılır.</p>
  </div>
  <a class="ui-btn ui-btn-outline" href="/categories/mapping?marketplace_id=<?= (int) ($data['targetId'] ?? 0) ?>">Hepsiburada eşleştirmelerine dön</a>
</div>

<div class="cw-box">
  <p style="margin-top:0">Kaynak, ürünün Trendyol'a gerçekten gittiği kategoridir: katalog kontrolünün ürüne özel seçtiği kategori varsa o, yoksa panel kategorisinin Trendyol eşleştirmesi. Böylece XML'deki yanlış kategoriler Hepsiburada'ya taşınmaz.
  Ürüne özel kategorisi olanlara Hepsiburada'da da ürüne özel kategori, diğerlerine panel kategorisi eşleştirmesi yazılır. Hepsiburada'da daha önce yaptığınız eşleştirmeler değiştirilmez.</p>
  <p>Onaylamadan önce örnek ürün adlarını okuyun. Hepsiburada'da karşılığı olmayan ya da gönderilmemesi gereken bir grup için <b>Gönderme</b> seçin.</p>
  <div class="cw-bar">
    <input type="search" id="cwSearch" placeholder="Kategori ya da ürün adı ara…">
    <select id="cwFilter">
      <option value="all">Tüm gruplar</option>
      <option value="wait">Onay bekleyen öneriler</option>
      <option value="none">Önerisi olmayan</option>
      <option value="ok">Onaylı</option>
      <option value="todo">Onaylı, uygulanmamış ürünü olan</option>
    </select>
    <?php if ($canEdit): ?>
    <button type="button" class="ui-btn ui-btn-outline" id="cwSuggest">Eksik önerileri hazırla</button>
    <button type="button" class="ui-btn ui-btn-outline" id="cwApproveShown">Görünen önerileri onayla</button>
    <button type="button" class="ui-btn ui-btn-outline" id="cwImportOpen">Öneri içeri al (JSON)</button>
    <?php endif; ?>
    <?php if ($canApply): ?>
    <button type="button" class="ui-btn ui-btn-primary" id="cwApplyAll">Onaylıların tümünü uygula</button>
    <?php endif; ?>
  </div>
  <div class="cw-stats" id="cwStats"></div>
  <div class="cw-msg" id="cwMsg" role="status"></div>
  <div id="cwImport" style="display:none;margin-top:10px">
    <textarea class="cw-json" id="cwImportText" placeholder='[{"source":"766","target":"60001234"},{"source":"4764","target":""}]'></textarea>
    <p style="font-size:12px;margin:4px 0">source = Trendyol kategori no, target = Hepsiburada kategori no (boş = gönderme). Gelenler öneri olarak yazılır; onaylı kategorilere dokunulmaz.</p>
    <button type="button" class="ui-btn ui-btn-primary" id="cwImportRun">İçeri al</button>
  </div>
</div>

<div class="cw-box">
  <div class="cw-scroll"><table class="cw-table">
    <thead><tr><th>Trendyol kategorisi</th><th>Ürün</th><th>Örnek ürünler</th><th>Hepsiburada kategorisi</th><th></th></tr></thead>
    <tbody id="cwRows"><tr><td colspan="5">Yükleniyor…</td></tr></tbody>
  </table></div>
</div>

<script>
(function () {
    const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    const CAN_APPLY = <?= $canApply ? 'true' : 'false' ?>;
    const TARGET_MP = <?= (int) ($data['targetId'] ?? 0) ?>;
    const el = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = (n) => Number(n || 0).toLocaleString('tr-TR');
    let groups = [];

    async function post(url, fields) {
        const body = new FormData();
        body.append('_csrf_token', document.querySelector('meta[name=csrf-token]').content);
        Object.keys(fields).forEach((k) => body.append(k, fields[k]));
        const r = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        let j = null;
        try { j = await r.json(); } catch { throw new Error('Sunucu yanıtı okunamadı (HTTP ' + r.status + ').'); }
        if (!r.ok || !j.success) throw new Error(j.message || 'İşlem başarısız');
        return j.data || {};
    }
    async function get(url) {
        const r = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        const j = await r.json();
        if (!j.success) throw new Error(j.message || 'Okunamadı');
        return j.data || {};
    }
    const msg = (t, err) => { el('cwMsg').textContent = t; el('cwMsg').className = 'cw-msg' + (err ? ' cw-err' : ''); };

    function state(g) {
        if (!g.target) return 'none';
        return g.target.confirmed ? 'ok' : 'wait';
    }

    function visible() {
        const q = el('cwSearch').value.trim().toLocaleLowerCase('tr');
        const f = el('cwFilter').value;
        return groups.filter((g) => {
            const s = state(g);
            if (f === 'wait' && s !== 'wait') return false;
            if (f === 'none' && s !== 'none') return false;
            if (f === 'ok' && s !== 'ok') return false;
            if (f === 'todo' && !(s === 'ok' && g.target.id && g.mapped < g.total)) return false;
            if (!q) return true;
            const hay = (g.source_name + ' ' + g.source_path + ' ' + (g.target ? g.target.path : '') + ' ' + g.samples.join(' ')).toLocaleLowerCase('tr');
            return hay.includes(q);
        });
    }

    function renderStats() {
        const sum = (f) => groups.reduce((t, g) => t + f(g), 0);
        el('cwStats').innerHTML = [
            ['Grup', groups.length],
            ['Ürün', sum((g) => g.total)],
            ["Trendyol'da satışta", sum((g) => g.listed)],
            ['Onaylı grup', sum((g) => state(g) === 'ok' ? 1 : 0)],
            ['Onay bekleyen', sum((g) => state(g) === 'wait' ? 1 : 0)],
            ["Hepsiburada kategorisi olan ürün", sum((g) => g.mapped)],
        ].map(([k, v]) => `<div>${esc(k)}<b>${fmt(v)}</b></div>`).join('');
    }

    function targetHtml(g) {
        const s = state(g);
        if (s === 'none') return '<span class="cw-badge cw-none">Öneri yok</span>';
        const t = g.target;
        const badge = s === 'ok' ? '<span class="cw-badge cw-ok">Onaylı</span>' : `<span class="cw-badge cw-wait">Öneri${t.score > 0 ? ' · ' + t.score : ''}</span>`;
        if (!t.id) return `${badge} <span class="cw-badge cw-skip">Gönderme</span>`;
        return `${badge}<br><b>${esc(t.name)}</b><small>${esc(t.path)} · #${esc(t.id)}</small>`;
    }

    function render() {
        renderStats();
        const rows = visible();
        if (!rows.length) { el('cwRows').innerHTML = '<tr><td colspan="5">Bu filtrede grup yok.</td></tr>'; return; }
        el('cwRows').innerHTML = rows.slice(0, 400).map((g) => {
            const s = state(g);
            const acts = [];
            if (CAN_EDIT && s === 'wait') acts.push(`<button type="button" class="ui-btn ui-btn-primary ui-btn-sm" data-act="confirm">Onayla</button>`);
            if (CAN_EDIT) acts.push(`<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-act="pick">${s === 'none' ? 'Kategori seç' : 'Değiştir'}</button>`);
            if (CAN_EDIT && !(g.target && !g.target.id && s === 'ok')) acts.push(`<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-act="skip">Gönderme</button>`);
            if (CAN_APPLY && s === 'ok' && g.target.id && g.mapped < g.total) acts.push(`<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-act="apply">Uygula</button>`);
            return `<tr data-src="${esc(g.source_id)}">
                <td><b>${esc(g.source_name)}</b><small>${esc(g.source_path)}</small><small>#${esc(g.source_id)}</small></td>
                <td>${fmt(g.total)}<small>${fmt(g.listed)} Trendyol'da satışta</small><small>${fmt(g.own)} ürüne özel</small><small>${fmt(g.mapped)} HB'de kategorili</small></td>
                <td><ul class="cw-samples">${g.samples.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></td>
                <td class="cw-target">${targetHtml(g)}<div class="cw-slot"></div></td>
                <td><div class="cw-actions">${acts.join('')}</div><div class="cw-msg" data-out></div></td>
            </tr>`;
        }).join('') + (rows.length > 400 ? `<tr><td colspan="5">İlk 400 grup gösteriliyor (toplam ${fmt(rows.length)}). Aramayı daraltın.</td></tr>` : '');
    }

    async function load() {
        try {
            const d = await get('/categories/crosswalk/groups');
            groups = d.groups || [];
            render();
        } catch (err) { el('cwRows').innerHTML = `<tr><td colspan="5" class="cw-err">${esc(err.message)}</td></tr>`; }
    }

    const find = (src) => groups.find((g) => g.source_id === src);

    async function saveTarget(src, target, confirmed) {
        const d = await post('/categories/crosswalk/save', { source: src, target: target, confirmed: confirmed ? '1' : '0' });
        const g = find(src);
        g.target = { id: d.target.id, name: d.target.name, path: d.target.path, confirmed: !!d.target.confirmed, method: 'elle', score: 100 };
    }

    async function applyGroup(src, out) {
        let after = 0, ov = 0, mp = 0;
        for (;;) {
            const d = await post('/categories/crosswalk/apply', { source: src, after: String(after) });
            ov += d.created_overrides || 0; mp += d.created_mappings || 0; after = d.last_id || 0;
            if (out) out.textContent = `${ov} ürüne özel, ${mp} panel eşleştirmesi yazıldı…`;
            if (!d.limited) break;
        }
        return { ov, mp };
    }

    async function openPicker(tr, g) {
        const slot = tr.querySelector('.cw-slot');
        if (slot.innerHTML) { slot.innerHTML = ''; return; }
        slot.innerHTML = `<div class="cw-pick"><input type="search" placeholder="Hepsiburada kategorisi ara…"><div class="cw-list"><div class="cw-msg">Adaylar yükleniyor…</div></div></div>`;
        const list = slot.querySelector('.cw-list');
        const show = (cats) => {
            list.innerHTML = cats.length ? cats.map((c) => `<button type="button" class="cw-opt" data-id="${esc(c.id)}"><b>${esc(c.name)}</b><small>${esc(c.path)}${c.score !== undefined ? ' · ' + c.score : ''}</small></button>`).join('') : '<div class="cw-msg">Sonuç yok — farklı bir kelime deneyin.</div>';
        };
        try { show((await get('/categories/crosswalk/candidates?source=' + encodeURIComponent(g.source_id))).candidates || []); }
        catch (err) { list.innerHTML = `<div class="cw-msg cw-err">${esc(err.message)}</div>`; }
        let timer = null;
        slot.querySelector('input').addEventListener('input', (ev) => {
            clearTimeout(timer);
            const q = ev.target.value.trim();
            if (q.length < 2) return;
            timer = setTimeout(async () => {
                try { show((await get('/categories/mapping/search?marketplace_id=' + TARGET_MP + '&q=' + encodeURIComponent(q))).categories || []); }
                catch (err) { list.innerHTML = `<div class="cw-msg cw-err">${esc(err.message)}</div>`; }
            }, 300);
        });
        list.addEventListener('click', async (ev) => {
            const opt = ev.target.closest('.cw-opt');
            if (!opt) return;
            list.innerHTML = '<div class="cw-msg">Kaydediliyor…</div>';
            try { await saveTarget(g.source_id, opt.dataset.id, true); render(); }
            catch (err) { list.innerHTML = `<div class="cw-msg cw-err">${esc(err.message)}</div>`; }
        });
    }

    el('cwRows').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-act]');
        if (!btn) return;
        const tr = btn.closest('tr');
        const g = find(tr.dataset.src);
        const out = tr.querySelector('[data-out]');
        out.className = 'cw-msg'; out.textContent = '';
        try {
            if (btn.dataset.act === 'pick') return openPicker(tr, g);
            btn.disabled = true;
            if (btn.dataset.act === 'confirm') { await saveTarget(g.source_id, g.target.id, true); render(); }
            if (btn.dataset.act === 'skip') {
                if (!confirm(`"${g.source_name}" grubundaki ${g.total} ürün Hepsiburada'ya kategori almayacak. Emin misiniz?`)) { btn.disabled = false; return; }
                await saveTarget(g.source_id, '', true); render();
            }
            if (btn.dataset.act === 'apply') {
                const r = await applyGroup(g.source_id, out);
                out.textContent = `${r.ov} ürüne özel, ${r.mp} panel eşleştirmesi yazıldı.`;
                await load();
            }
        } catch (err) { out.textContent = err.message; out.className = 'cw-msg cw-err'; btn.disabled = false; }
    });

    el('cwSearch').addEventListener('input', render);
    el('cwFilter').addEventListener('change', render);

    el('cwSuggest')?.addEventListener('click', async (ev) => {
        ev.target.disabled = true;
        try {
            let toplam = 0;
            for (;;) {
                const d = await post('/categories/crosswalk/suggest', {});
                toplam += d.suggested || 0;
                msg(`${toplam} gruba öneri yazıldı, ${d.remaining} kaldı…`);
                if (!d.suggested || !d.remaining) break;
            }
            msg(`${toplam} gruba öneri yazıldı. Önerileri okuyup onaylayın.`);
            await load();
        } catch (err) { msg(err.message, true); }
        ev.target.disabled = false;
    });

    el('cwApproveShown')?.addEventListener('click', async (ev) => {
        const bekleyen = visible().filter((g) => state(g) === 'wait');
        if (!bekleyen.length) { msg('Görünen listede onay bekleyen öneri yok.'); return; }
        if (!confirm(`Görünen ${bekleyen.length} öneri onaylanacak. Örnek ürünleri okudunuz mu?`)) return;
        ev.target.disabled = true;
        let n = 0;
        try {
            for (const g of bekleyen) { await saveTarget(g.source_id, g.target.id, true); n++; msg(`${n}/${bekleyen.length} onaylandı…`); }
            msg(`${n} öneri onaylandı.`);
        } catch (err) { msg(err.message, true); }
        render();
        ev.target.disabled = false;
    });

    el('cwImportOpen')?.addEventListener('click', () => { const b = el('cwImport'); b.style.display = b.style.display === 'none' ? 'block' : 'none'; });
    el('cwImportRun')?.addEventListener('click', async (ev) => {
        let rows;
        try { rows = JSON.parse(el('cwImportText').value || '[]'); } catch { msg('JSON okunamadı.', true); return; }
        ev.target.disabled = true;
        try {
            const d = await post('/categories/crosswalk/import', { rows: JSON.stringify(rows) });
            msg(`${d.imported} öneri içeri alındı, ${d.kept} onaylı kategoriye dokunulmadı.` + (d.errors && d.errors.length ? ' Hatalar: ' + d.errors.slice(0, 5).join(' · ') : ''), d.errors && d.errors.length);
            await load();
        } catch (err) { msg(err.message, true); }
        ev.target.disabled = false;
    });

    el('cwApplyAll')?.addEventListener('click', async (ev) => {
        const hedef = groups.filter((g) => state(g) === 'ok' && g.target.id && g.mapped < g.total);
        if (!hedef.length) { msg('Uygulanacak onaylı grup yok.'); return; }
        if (!confirm(`${hedef.length} onaylı grubun ürünlerine Hepsiburada kategorisi yazılacak. Devam edilsin mi?`)) return;
        ev.target.disabled = true;
        let ov = 0, mp = 0, i = 0;
        try {
            for (const g of hedef) {
                const r = await applyGroup(g.source_id, null);
                ov += r.ov; mp += r.mp; i++;
                msg(`${i}/${hedef.length} grup · ${fmt(ov)} ürüne özel, ${fmt(mp)} panel eşleştirmesi yazıldı…`);
            }
            msg(`Tamamlandı: ${fmt(ov)} ürüne özel kategori, ${fmt(mp)} panel eşleştirmesi yazıldı.`);
        } catch (err) { msg(err.message, true); }
        ev.target.disabled = false;
        await load();
    });

    load();
})();
</script>
