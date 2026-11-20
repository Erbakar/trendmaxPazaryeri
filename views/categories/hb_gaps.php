<?php
$canEdit = !empty($data['canEdit']);
?>
<style>
.hg-box{background:var(--surface,#fff);border:1px solid var(--border,#e3e7ee);border-radius:12px;padding:18px;margin:16px 0}
.hg-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.hg-bar input,.hg-bar select{padding:7px 10px;border:1px solid var(--border,#d8dde6);border-radius:8px;background:var(--surface,#fff);color:inherit}
.hg-table{width:100%;border-collapse:collapse}
.hg-table th,.hg-table td{padding:10px 8px;text-align:left;vertical-align:top;border-bottom:1px solid var(--border,#e3e7ee);font-size:13px}
.hg-table small{display:block;color:var(--text-secondary,#65748a);margin-top:3px}
.hg-samples{margin:0;padding-left:16px;max-width:360px}
.hg-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.hg-ok{background:#dcfce7;color:#15803d}.hg-measure{background:#fef3c7;color:#92400e}
.hg-edit{display:flex;flex-direction:column;gap:6px;min-width:220px;max-width:320px}
.hg-edit input,.hg-edit select{padding:6px 8px;border:1px solid var(--border,#d8dde6);border-radius:6px;background:var(--surface,#fff);color:inherit;width:100%}
.hg-opt{display:block;width:100%;text-align:left;border:0;background:none;padding:5px 6px;border-radius:6px;cursor:pointer;color:inherit;font-size:12.5px}
.hg-opt:hover{background:rgba(127,127,127,.12)}
.hg-msg{font-size:12px;margin-top:4px}.hg-err{color:#b42318}
.hg-scroll{overflow-x:auto}
</style>

<div class="ui-page-head">
  <div>
    <h1>Hepsiburada eksik özellikler</h1>
    <p>Son gönderimde zorunlu bir özelliği bulunamadığı ya da kategorisi olmadığı için gönderilmeyen ürünler. Kategori başına bir değer girdiğinizde, o kategoriye giden ve değeri ürün adından çıkarılamayan her ürün bu değerle gider.</p>
  </div>
  <a class="ui-btn ui-btn-outline" href="/categories/crosswalk">Kategori aktarımına dön</a>
</div>

<div class="hg-box">
  <p style="margin-top:0"><b>Değeri bütün kategori için doğru olanları girin</b> (ör. peluş oyuncakta Malzeme = Peluş). <span class="hg-badge hg-measure">ölçü</span> işaretli özellikler (boy, en, hacim…) ürüne göre değişir; varsayılan girmek yanlış bilgiyle satışa çıkarır, bunları ürünün kendisine girin.</p>
  <p>Değerleri girdikten sonra ürünleri yeniden gönderin: Ürünler &gt; Toplu gönder &gt; Hepsiburada.</p>
  <div class="hg-bar">
    <input type="search" id="hgSearch" placeholder="Kategori, özellik ya da ürün ara…">
    <select id="hgFilter">
      <option value="todo">Varsayılanı olmayanlar</option>
      <option value="all">Tümü</option>
      <option value="set">Varsayılan girilenler</option>
    </select>
    <span id="hgStats" style="font-size:13px"></span>
  </div>
  <div class="hg-msg" id="hgMsg" role="status"></div>
</div>

<div class="hg-box" id="hgCatBox" style="display:none">
  <h3 style="margin-top:0">Hepsiburada kategorisi olmayan panel kategorileri</h3>
  <div class="hg-scroll"><table class="hg-table">
    <thead><tr><th>Panel kategorisi</th><th>Ürün</th><th>Örnek ürünler</th><th>Hepsiburada kategorisi</th></tr></thead>
    <tbody id="hgCats"></tbody>
  </table></div>
</div>

<div class="hg-box">
  <h3 style="margin-top:0">Çözülemeyen zorunlu özellikler</h3>
  <div class="hg-scroll"><table class="hg-table">
    <thead><tr><th>Hepsiburada kategorisi</th><th>Özellik</th><th>Ürün</th><th>Örnek ürünler</th><th>Varsayılan değer</th></tr></thead>
    <tbody id="hgRows"><tr><td colspan="5">Yükleniyor…</td></tr></tbody>
  </table></div>
</div>

<script>
(function () {
    const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    const TARGET_MP = <?= (int) ($data['targetId'] ?? 0) ?>;
    const el = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = (n) => Number(n || 0).toLocaleString('tr-TR');
    let data = { attributes: [], categories: [], products: 0 };

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
    const msg = (t, err) => { el('hgMsg').textContent = t; el('hgMsg').className = 'hg-msg' + (err ? ' hg-err' : ''); };
    const samples = (list) => `<ul class="hg-samples">${(list || []).map((s) => `<li>${esc(s)}</li>`).join('')}</ul>`;
    const key = (a) => a.category_id + '|' + a.attribute_id;

    function visible() {
        const q = el('hgSearch').value.trim().toLocaleLowerCase('tr-TR');
        const f = el('hgFilter').value;
        return data.attributes.filter((a) => {
            if (f === 'todo' && a.default) return false;
            if (f === 'set' && !a.default) return false;
            if (!q) return true;
            return [a.category, a.attribute, ...(a.samples || [])].join(' ').toLocaleLowerCase('tr-TR').includes(q);
        });
    }

    function render() {
        const done = data.attributes.filter((a) => a.default);
        el('hgStats').textContent = `${fmt(data.products)} ürün durdu · ${fmt(data.attributes.length)} kategori/özellik çifti · ${fmt(done.length)} varsayılan girildi`;
        const rows = visible();
        el('hgRows').innerHTML = rows.length ? rows.map((a) => `
            <tr data-key="${esc(key(a))}">
              <td>${esc(a.category.split(' > ').slice(-2).join(' > '))}<small>${esc(a.category)} · ${esc(a.category_id)}</small></td>
              <td><b>${esc(a.attribute)}</b> ${a.measure ? '<span class="hg-badge hg-measure">ölçü</span>' : ''}<small>${esc(a.type || '')}</small></td>
              <td>${fmt(a.count)}</td>
              <td>${samples(a.samples)}</td>
              <td><div class="hg-edit">
                ${a.default ? `<span class="hg-badge hg-ok">${esc(a.default)}</span>` : ''}
                ${CAN_EDIT && a.attribute_id ? `<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-act="edit">${a.default ? 'Değiştir' : 'Değer seç'}</button>` : ''}
                ${!a.attribute_id ? '<small>Özellik HB şemasında bulunamadı</small>' : ''}
                <div class="hg-slot"></div><div class="hg-msg" data-out></div>
              </div></td>
            </tr>`).join('') : '<tr><td colspan="5">Gösterilecek satır yok.</td></tr>';

        el('hgCatBox').style.display = data.categories.length ? '' : 'none';
        el('hgCats').innerHTML = data.categories.map((c) => `
            <tr data-cat="${c.category_id}">
              <td><b>${esc(c.category || '(kategorisiz)')}</b><small>Panel kategori no ${c.category_id}</small></td>
              <td>${fmt(c.count)}</td>
              <td>${samples(c.samples)}</td>
              <td><div class="hg-edit">
                ${c.current ? `<small>Şu an: ${esc(c.current)} (satışa kapalı)</small>` : '<small>Eşleştirme yok</small>'}
                ${CAN_EDIT && c.category_id > 0 ? '<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-act="map">Kategori seç</button>' : ''}
                <div class="hg-slot"></div><div class="hg-msg" data-out></div>
              </div></td>
            </tr>`).join('');
    }

    async function load() {
        msg('Son gönderimin hataları okunuyor…');
        try { data = await get('/categories/hb-gaps/list'); msg(''); render(); }
        catch (err) { msg(err.message, true); }
    }

    async function openValue(tr, a) {
        const slot = tr.querySelector('.hg-slot');
        if (slot.innerHTML) { slot.innerHTML = ''; return; }
        slot.innerHTML = '<div class="hg-msg">Değerler yükleniyor…</div>';
        let v;
        try { v = await get('/categories/hb-gaps/values?category=' + encodeURIComponent(a.category_id) + '&attribute=' + encodeURIComponent(a.attribute_id)); }
        catch (err) { slot.innerHTML = `<div class="hg-msg hg-err">${esc(err.message)}</div>`; return; }
        const values = v.values || [];
        slot.innerHTML = `<input type="search" placeholder="${values.length ? values.length + ' seçenek içinde ara…' : 'Değer yazın'}" value="${esc(a.default || '')}">
            <div class="hg-list"></div>
            ${values.length ? '' : '<button type="button" class="ui-btn ui-btn-primary ui-btn-sm" data-free>Kaydet</button>'}
            ${a.default ? '<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-clear>Varsayılanı sil</button>' : ''}`;
        const input = slot.querySelector('input');
        const list = slot.querySelector('.hg-list');
        const show = () => {
            const q = input.value.trim().toLocaleLowerCase('tr-TR');
            const hit = values.filter((x) => !q || x.toLocaleLowerCase('tr-TR').includes(q)).slice(0, 30);
            list.innerHTML = hit.map((x) => `<button type="button" class="hg-opt" data-v="${esc(x)}">${esc(x)}</button>`).join('');
        };
        if (values.length) { show(); input.addEventListener('input', show); }
        const save = async (value) => {
            const out = tr.querySelector('[data-out]');
            out.className = 'hg-msg'; out.textContent = 'Kaydediliyor…';
            try {
                const r = await post('/categories/hb-gaps/save', { category: a.category_id, attribute: a.attribute_id, value });
                data.attributes.filter((x) => key(x) === key(a)).forEach((x) => { x.default = r.value; });
                render();
            } catch (err) { out.className = 'hg-msg hg-err'; out.textContent = err.message; }
        };
        slot.addEventListener('click', (ev) => {
            const opt = ev.target.closest('.hg-opt');
            if (opt) return save(opt.dataset.v);
            if (ev.target.closest('[data-free]')) return save(input.value.trim());
            if (ev.target.closest('[data-clear]')) return save('');
        });
    }

    async function openCategory(tr, c) {
        const slot = tr.querySelector('.hg-slot');
        if (slot.innerHTML) { slot.innerHTML = ''; return; }
        slot.innerHTML = '<input type="search" placeholder="Hepsiburada kategorisi ara…"><div class="hg-list"></div>';
        const list = slot.querySelector('.hg-list');
        let timer = null;
        slot.querySelector('input').addEventListener('input', (ev) => {
            clearTimeout(timer);
            const q = ev.target.value.trim();
            if (q.length < 2) return;
            timer = setTimeout(async () => {
                try {
                    const cats = (await get('/categories/mapping/search?marketplace_id=' + TARGET_MP + '&q=' + encodeURIComponent(q))).categories || [];
                    list.innerHTML = cats.length ? cats.map((x) => `<button type="button" class="hg-opt" data-id="${esc(x.id)}"><b>${esc(x.name)}</b><small style="display:inline;margin-left:6px">${esc(x.path)}</small></button>`).join('') : '<div class="hg-msg">Sonuç yok.</div>';
                } catch (err) { list.innerHTML = `<div class="hg-msg hg-err">${esc(err.message)}</div>`; }
            }, 300);
        });
        list.addEventListener('click', async (ev) => {
            const opt = ev.target.closest('.hg-opt');
            if (!opt) return;
            const out = tr.querySelector('[data-out]');
            out.className = 'hg-msg'; out.textContent = 'Kaydediliyor…';
            try {
                const r = await post('/categories/hb-gaps/map', { category_id: c.category_id, target: opt.dataset.id });
                data.categories = data.categories.filter((x) => x.category_id !== c.category_id);
                render();
                msg(`"${c.category}" → ${r.path}. Bu kategorinin ${fmt(c.count)} ürünü yeniden gönderilebilir.`);
            } catch (err) { out.className = 'hg-msg hg-err'; out.textContent = err.message; }
        });
    }

    el('hgRows').addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-act=edit]');
        if (!btn) return;
        const tr = btn.closest('tr');
        const a = data.attributes.find((x) => key(x) === tr.dataset.key);
        if (a) openValue(tr, a);
    });
    el('hgCats').addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-act=map]');
        if (!btn) return;
        const tr = btn.closest('tr');
        const c = data.categories.find((x) => String(x.category_id) === tr.dataset.cat);
        if (c) openCategory(tr, c);
    });
    el('hgSearch').addEventListener('input', render);
    el('hgFilter').addEventListener('change', render);
    load();
})();
</script>
