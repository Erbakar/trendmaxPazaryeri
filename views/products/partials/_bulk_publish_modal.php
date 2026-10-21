<?php
/**
 * Ürünler › Toplu Pazaryerine Gönderim sihirbazı (4 adım).
 *
 * views/products/index.php içinden include edilir. Seçim `selectedIds` Set'inde
 * tarayıcı belleğinde tutulduğu için ayrı sayfa yerine MODAL kullanılır.
 *
 * Beklenen değişkenler: $marketplaces (aktif pazaryerleri), $csrf
 */
?>
<style>
.bp-ovl{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;z-index:1200;padding:32px 16px;overflow-y:auto}
.bp-ovl.show{display:flex}
.bp-modal{background:var(--bg-card,#fff);border-radius:12px;width:100%;max-width:980px;box-shadow:0 0 0 1px var(--border);overflow:hidden}
.bp-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border,#e5e7eb)}
.bp-head h3{margin:0;font-size:16px;font-weight:700;flex:1}
.bp-x{background:none;border:0;cursor:pointer;color:var(--text-muted,#6b7280);padding:4px}
.bp-steps{display:flex;gap:4px;padding:12px 20px;border-bottom:1px solid var(--border,#e5e7eb);flex-wrap:wrap}
.bp-step{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:16px;font-size:12px;font-weight:700;color:var(--text-muted,#6b7280);background:rgba(127,127,127,.08)}
.bp-step.active{background:var(--primary,var(--primary));color:#fff}
.bp-step.done{background:#dcfce7;color:#15803d}
.bp-body{padding:20px;max-height:60vh;overflow-y:auto}
.bp-foot{display:flex;gap:8px;align-items:center;padding:14px 20px;border-top:1px solid var(--border,#e5e7eb)}
.bp-foot .bp-spacer{flex:1}
.bp-mp{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.bp-mp label{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--border,#e5e7eb);border-radius:10px;cursor:pointer;font-size:13px;font-weight:600}
.bp-mp label.disabled{opacity:.5;cursor:not-allowed}
.bp-mp input{width:16px;height:16px}
.bp-rap{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:12px 14px;margin-bottom:10px}
.bp-rap h4{margin:0 0 6px;font-size:13.5px;font-weight:700}
.bp-rap ul{margin:6px 0 0;padding-left:18px;font-size:12.5px;color:var(--text-secondary,#475569)}
.bp-rap .bp-note{margin:8px 0 0;font-size:12px;line-height:1.5;color:var(--text-muted,#6b7280)}
.bp-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.bp-tbl th,.bp-tbl td{padding:6px 8px;border-bottom:1px solid var(--border,#e5e7eb);text-align:left;white-space:nowrap}
.bp-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted,#6b7280)}
.bp-tbl tr.loss td{background:rgba(239,68,68,.07)}
.bp-tbl tr.low td{background:rgba(245,158,11,.07)}
.bp-num{text-align:right !important;font-variant-numeric:tabular-nums}
.bp-sum{display:flex;gap:18px;flex-wrap:wrap;padding:10px 12px;background:rgba(127,127,127,.06);border-radius:10px;margin:10px 0;font-size:12.5px}
.bp-sum b{display:block;font-size:15px}
.bp-bar{height:10px;background:rgba(127,127,127,.15);border-radius:6px;overflow:hidden;margin:6px 0}
.bp-bar span{display:block;height:100%;background:var(--primary,var(--primary));width:0;transition:width .3s}
.bp-warn{padding:10px 12px;border-radius:8px;background:rgba(239,68,68,.08);color:#b91c1c;font-size:12.5px;margin:10px 0}
.bp-info{padding:10px 12px;border-radius:8px;background:rgba(59,130,246,.08);color:#1d4ed8;font-size:12.5px;margin:10px 0}
.bp-fld{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
.bp-fld label{display:flex;flex-direction:column;gap:3px;font-size:11.5px;font-weight:700;color:var(--text-secondary,#475569)}
.bp-fld input,.bp-fld select{padding:6px 9px;border:1px solid var(--border,#e5e7eb);border-radius:7px;font-size:13px;min-width:110px}
@media (max-width:768px){.bp-mp{grid-template-columns:1fr}}
</style>

<div class="bp-ovl" id="bpOverlay">
  <div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="bpTitle">
    <div class="bp-head">
      <i data-lucide="send" style="width:18px;height:18px;color:var(--primary,var(--primary))"></i>
      <h3 id="bpTitle">Pazaryerine Gönder</h3>
      <button class="bp-x" onclick="bpClose()" aria-label="Kapat"><i data-lucide="x" style="width:18px;height:18px"></i></button>
    </div>

    <div class="bp-steps">
      <div class="bp-step active" id="bpStepTab1">1 · Pazaryeri</div>
      <div class="bp-step" id="bpStepTab2">2 · Hazırlık</div>
      <div class="bp-step" id="bpStepTab3">3 · Fiyat &amp; Kâr</div>
      <div class="bp-step" id="bpStepTab4">4 · Gönderim</div>
    </div>

    <div class="bp-body">
      <!-- 1. Pazaryeri seçimi -->
      <div id="bpStep1">
        <p style="margin:0 0 12px;font-size:13px;color:var(--text-secondary,#475569)">
          <strong id="bpCount">0</strong> ürün gönderilecek. Hangi pazaryerlerine?
        </p>
        <div class="bp-mp" id="bpMpList"></div>
      </div>

      <!-- 2. Hazırlık raporu -->
      <div id="bpStep2" style="display:none">
        <div id="bpReady"></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin-top:10px">
          <input type="checkbox" id="bpSkipBlocked" checked> Engelli ürünleri atlayıp devam et
        </label>
      </div>

      <!-- 3. Fiyat & kâr -->
      <div id="bpStep3" style="display:none">
        <div class="bp-fld">
          <label>Pazaryeri
            <select id="bpMarginMp" onchange="bpPreview()"></select>
          </label>
          <label>Komisyon %
            <input type="number" id="bpCommission" value="18" min="0" max="100" step="0.5">
          </label>
          <label>Kargo ₺
            <input type="number" id="bpShipping" value="0" min="0" step="0.5">
          </label>
          <label>Paketleme ₺
            <input type="number" id="bpPackaging" value="0" min="0" step="0.5">
          </label>
          <label>Fiyat kuralı
            <select id="bpRuleType">
              <option value="">Mevcut kural</option>
              <option value="percent_increase">% Artış</option>
              <option value="percent_decrease">% İndirim</option>
              <option value="fixed_increase">₺ Ekle</option>
              <option value="fixed_decrease">₺ Düş</option>
            </select>
          </label>
          <label>Değer
            <input type="number" id="bpRuleValue" value="0" step="0.5">
          </label>
          <button class="o-btn o-btn-primary o-btn-sm" onclick="bpPreview()" type="button">
            <i data-lucide="calculator" style="width:14px;height:14px"></i> Önizle
          </button>
        </div>
        <p style="margin:0 0 8px;font-size:11.5px;color:var(--text-muted,#6b7280)">
          Komisyon oranı tahminidir; pazaryeri sözleşmenize göre girin. Kâr = KDV hariç tutar − maliyet − komisyon − kargo − paketleme.
        </p>
        <div id="bpXmlKuralNot" class="bp-info" style="display:none"></div>
        <div id="bpSummary"></div>
        <div style="overflow-x:auto"><table class="bp-tbl" id="bpMarginTable"></table></div>
        <div id="bpLossWarn"></div>
      </div>

      <!-- 4. Gönderim -->
      <div id="bpStep4" style="display:none">
        <div id="bpProgress"></div>
        <div id="bpResult"></div>
      </div>
    </div>

    <div class="bp-foot">
      <button class="o-btn o-btn-outline o-btn-sm" id="bpBackBtn" onclick="bpBack()" style="display:none" type="button">Geri</button>
      <div class="bp-spacer"></div>
      <span id="bpFootNote" style="font-size:12px;color:var(--text-muted,#6b7280)"></span>
      <button class="o-btn o-btn-outline o-btn-sm" id="bpStopBtn" onclick="bpStop()" style="display:none" type="button">Durdur</button>
      <button class="o-btn o-btn-primary o-btn-sm" id="bpNextBtn" onclick="bpNext()" type="button">Devam</button>
    </div>
  </div>
</div>

<script>
/* Toplu pazaryerine gönderim — tüm fonksiyonlar 'bp' önekli (2.600 satırlık
   dosyada isim çakışmasını önlemek için). */
(function () {
    'use strict';

    const BP = window._bp = {
        step: 1,
        ids: [],            // seçili ürün id'leri (mode=ids)
        useFilters: false,  // "filtreye uyan tümünü seç" seçildiyse
        filters: {},
        token: '',
        marketplaces: [],   // hazırlık raporu
        selectedMp: [],
        aborted: false,
        running: false,
    };

    const AKTIF_MP = <?= json_encode(array_values(array_map(
        static fn(array $m): array => [
            'id'   => (int) $m['id'],
            'slug' => (string) $m['slug'],
            'name' => (string) ($m['display_name'] ?? $m['slug']),
            'connected' => !empty($m['api_key_encrypted']) || !empty($m['api_secret_encrypted']),
        ],
        array_filter($marketplaces ?? [], static fn(array $m): bool => !empty($m['is_active']))
    )), JSON_UNESCAPED_UNICODE) ?>;

    const el = (id) => document.getElementById(id);

    async function bpPost(url, alanlar) {
        const body = new FormData();
        body.append('_csrf_token', CSRF);
        Object.keys(alanlar).forEach((k) => body.append(k, alanlar[k]));

        const resp = await fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });

        const metin = await resp.text();
        let veri;
        try {
            veri = JSON.parse(metin);
        } catch (e) {
            if (resp.status === 401 || /login|giriş yap/i.test(metin.slice(0, 300))) {
                throw new Error('Oturumunuz sona ermiş — sayfayı yenileyip tekrar giriş yapın.');
            }
            throw new Error('Sunucu beklenen yanıtı vermedi (HTTP ' + resp.status + ').');
        }
        if (!veri || veri.success !== true) {
            throw new Error((veri && veri.message) || 'İşlem başarısız.');
        }
        return veri.data || {};
    }

    // ─── Açılış ───────────────────────────────────────────
    window.bpOpen = function () {
        if (selectedIds.size === 0 && !BP.useFilters) {
            alert('Önce ürün seçin.');
            return;
        }

        BP.step = 1;
        BP.xmlKural = null;
        BP.xmlKuralUygulandi = false;
        BP.token = '';
        BP.aborted = false;
        BP.running = false;
        BP.ids = Array.from(selectedIds);
        BP.filters = bpCurrentFilters();

        el('bpCount').textContent = BP.useFilters ? (BP.matchingCount || BP.ids.length) : BP.ids.length;

        // Pazaryeri kutuları
        el('bpMpList').innerHTML = AKTIF_MP.map((m) => `
            <label class="${m.connected ? '' : 'disabled'}">
                <input type="checkbox" value="${m.id}" ${m.connected ? '' : 'disabled'}>
                <span>${esc(m.name)}${m.connected ? '' : ' <small style="font-weight:400">(bağlı değil)</small>'}</span>
            </label>`).join('');

        bpShowStep(1);
        el('bpOverlay').classList.add('show');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.bpClose = function () {
        if (BP.running && !confirm('Gönderim sürüyor. Kapatmak istediğinize emin misiniz?')) return;
        el('bpOverlay').classList.remove('show');
    };

    function bpCurrentFilters() {
        return {
            search: el('dtSearch') ? el('dtSearch').value : '',
            category_id: el('dtCategory') ? el('dtCategory').value : '',
            stock_status: el('dtStock') ? el('dtStock').value : '',
            is_active: el('dtActive') ? el('dtActive').value : '',
            source: el('dtSource') ? el('dtSource').value : '',
            marketplace_id: (typeof currentMp !== 'undefined' && currentMp) ? currentMp : '',
            show_archived: (typeof showArchived !== 'undefined' && showArchived) ? '1' : ''
        };
    }

    function bpShowStep(n) {
        BP.step = n;
        [1, 2, 3, 4].forEach((i) => {
            el('bpStep' + i).style.display = (i === n) ? 'block' : 'none';
            const tab = el('bpStepTab' + i);
            tab.className = 'bp-step' + (i === n ? ' active' : (i < n ? ' done' : ''));
        });
        el('bpBackBtn').style.display = (n > 1 && n < 4) ? 'inline-flex' : 'none';
        el('bpNextBtn').style.display = (n < 4) ? 'inline-flex' : 'none';
        el('bpNextBtn').textContent = (n === 3) ? 'Gönderimi Başlat' : 'Devam';
        el('bpStopBtn').style.display = (n === 4 && BP.running) ? 'inline-flex' : 'none';
    }

    window.bpBack = function () { if (BP.step > 1) bpShowStep(BP.step - 1); };

    // XML profilinin kâr kuralını fiyat adımına ön-doldur. Seçimin tamamı aynı
    // profilden gelmiyorsa sunucu kural döndürmez. Kullanıcı elle değer girdiyse
    // ona dokunulmaz — ön-doldurma bir öneridir, dayatma değil.
    function bpXmlKuralUygula() {
        const kural = BP.xmlKural;
        const not   = el('bpXmlKuralNot');

        if (!kural || BP.xmlKuralUygulandi) { return; }

        const tip = el('bpRuleType');
        const deg = el('bpRuleValue');

        if (tip.value === '' && parseFloat(deg.value || '0') === 0) {
            tip.value = kural.type;
            deg.value = kural.value;

            if (not) {
                not.textContent = 'Bu ürünlerin XML profilindeki kâr kuralı uygulandı: '
                    + (kural.type === 'fixed_increase' ? kural.value + ' ₺ ekle' : '%' + kural.value + ' artış')
                    + ' — dilerseniz değiştirin.';
                not.style.display = 'block';
            }
        }

        BP.xmlKuralUygulandi = true;
    }

    // ─── İleri ────────────────────────────────────────────
    window.bpNext = async function () {
        try {
            if (BP.step === 1) {
                BP.selectedMp = Array.from(el('bpMpList').querySelectorAll('input:checked')).map((c) => parseInt(c.value, 10));
                if (BP.selectedMp.length === 0) { alert('En az bir pazaryeri seçin.'); return; }

                el('bpNextBtn').disabled = true;
                el('bpFootNote').textContent = 'Hazırlık raporu çıkarılıyor…';

                const veri = await bpPost('/products/publish/bulk/prepare', {
                    product_ids: BP.useFilters ? '' : JSON.stringify(BP.ids),
                    marketplace_ids: JSON.stringify(BP.selectedMp),
                    filters: JSON.stringify(BP.filters)
                });

                BP.token = veri.token;
                BP.marketplaces = veri.marketplaces || [];
                BP.xmlKural = veri.xml_profit_rule || null;
                bpRenderReady(veri);
                bpFillMarginMp();
                el('bpNextBtn').disabled = false;
                el('bpFootNote').textContent = '';
                bpShowStep(2);
                return;
            }

            if (BP.step === 2) { bpShowStep(3); bpXmlKuralUygula(); bpPreview(); return; }

            if (BP.step === 3) {
                const zarar = parseInt(el('bpLossWarn').dataset.loss || '0', 10);
                if (zarar > 0 && !el('bpLossOk')?.checked) {
                    alert(zarar + ' ürün zararına gidecek. Devam etmek için onay kutusunu işaretleyin.');
                    return;
                }
                bpShowStep(4);
                bpRun();
                return;
            }
        } catch (err) {
            el('bpNextBtn').disabled = false;
            el('bpFootNote').textContent = '';
            alert(err.message);
        }
    };

    // ─── 2. adım: hazırlık raporu ─────────────────────────
    function bpRenderReady(veri) {
        const etiket = {
            no_category_mapping: 'kategori eşleştirmesi yok',
            no_brand_mapping: 'marka eşleştirmesi yok',
            no_barcode: 'barkodu yok',
            no_image: 'görseli yok',
            no_price: 'fiyatı yok'
        };

        let html = '';
        if (veri.truncated) {
            const esl = veri.matching || 0;
            html += `<div class="bp-info"><b>Dikkat:</b> filtreye ${esl.toLocaleString('tr-TR')} ürün uyuyor, `
                 + `bu gönderimde yalnız ilk ${veri.total.toLocaleString('tr-TR')} ürün işlenecek. `
                 + `Kalan ${(esl - veri.total).toLocaleString('tr-TR')} ürün için işlem bitince tekrar gönderin.</div>`;
        }

        (veri.marketplaces || []).forEach((m) => {
            const engeller = Object.keys(etiket)
                .filter((k) => (m.blockers[k] || 0) > 0)
                .map((k) => `<li>${m.blockers[k]} ürün — ${etiket[k]}` +
                    (k === 'no_category_mapping' ? ' <a href="/categories/mapping?marketplace_id=' + m.id + '" target="_blank">eşleştir</a>' : '') +
                    (k === 'no_brand_mapping' ? ' <a href="/brands/mapping?marketplace_id=' + m.id + '" target="_blank">eşleştir</a>' : '') +
                    '</li>')
                .join('');

            // WooCommerce gibi kendi mağazalarında eşleştirme isteğe bağlıdır
            const istegeBagli = m.optional_mapping
                ? `<p class="bp-note">Barkod, kategori ve marka eşleştirmesi zorunlu değil. Aynı SKU mağazada varsa ürün güncellenir, yoksa oluşturulur; varyantlı ürünler değişken ürün (varyasyonlu) olarak gönderilir.</p>`
                : '';

            html += `<div class="bp-rap">
                <h4>${esc(m.name)} — <span style="color:#15803d">${m.ready} hazır</span>${m.blocked > 0 ? ` · <span style="color:#b91c1c">${m.blocked} engelli</span>` : ''}</h4>
                ${engeller ? '<ul>' + engeller + '</ul>' : '<div style="font-size:12.5px;color:#15803d">Tüm ürünler gönderime hazır.</div>'}
                ${istegeBagli}
            </div>`;
        });

        (veri.blocked_marketplaces || []).forEach((m) => {
            html += `<div class="bp-warn">${esc(m.name)}: ${esc(m.reason)}</div>`;
        });

        el('bpReady').innerHTML = html;
    }

    function bpFillMarginMp() {
        el('bpMarginMp').innerHTML = BP.marketplaces
            .map((m) => `<option value="${m.id}">${esc(m.name)}</option>`).join('');
    }

    // ─── 3. adım: kâr önizleme ────────────────────────────
    window.bpPreview = async function () {
        const mpId = parseInt(el('bpMarginMp').value || '0', 10);
        if (!mpId) return;

        el('bpSummary').innerHTML = '<div class="bp-info">Hesaplanıyor…</div>';

        try {
            const veri = await bpPost('/products/publish/bulk/price-preview', {
                product_ids: JSON.stringify(BP.ids.slice(0, 500)),
                marketplace_id: mpId,
                commission_pct: el('bpCommission').value,
                shipping_cost: el('bpShipping').value,
                packaging_cost: el('bpPackaging').value,
                price_rule_type: el('bpRuleType').value,
                price_rule_value: el('bpRuleValue').value,
                page: 1,
                per_page: 50
            });

            const s = veri.summary || {};
            el('bpSummary').innerHTML = `<div class="bp-sum">
                <div>Ürün<b>${s.count || 0}</b></div>
                <div>Toplam ciro<b>${bpTL(s.revenue)}</b></div>
                <div>Tahmini kâr<b style="color:${(s.profit || 0) >= 0 ? '#15803d' : '#b91c1c'}">${bpTL(s.profit)}</b></div>
                <div>Ortalama marj<b>%${s.avg_margin_pct || 0}</b></div>
            </div>`;

            const satirlar = veri.rows || [];
            el('bpMarginTable').innerHTML =
                `<thead><tr><th>Ürün</th><th class="bp-num">Baz</th><th class="bp-num">Satış</th>
                 <th class="bp-num">Maliyet</th><th class="bp-num">Kâr</th><th class="bp-num">Marj</th></tr></thead><tbody>` +
                satirlar.map((r) => `<tr class="${r.warning === 'zarar' ? 'loss' : (r.warning === 'dusuk' ? 'low' : '')}">
                    <td title="${esc(r.sku)}">${esc((r.name || '').substring(0, 40))}</td>
                    <td class="bp-num">${bpTL(r.base_price)}</td>
                    <td class="bp-num">${bpTL(r.sale_price)}</td>
                    <td class="bp-num">${r.cost_price > 0 ? bpTL(r.cost_price) : '—'}</td>
                    <td class="bp-num" style="color:${r.profit >= 0 ? '#15803d' : '#b91c1c'}">${bpTL(r.profit)}</td>
                    <td class="bp-num">${r.cost_price > 0 ? '%' + r.margin_pct : '—'}</td>
                </tr>`).join('') + '</tbody>';

            const zarar = s.loss_count || 0;
            const maliyetsiz = s.no_cost_count || 0;
            let uyari = '';
            if (zarar > 0) {
                uyari += `<div class="bp-warn"><strong>${zarar} ürün zararına gidecek.</strong>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:6px;font-weight:600">
                      <input type="checkbox" id="bpLossOk"> Anladım, yine de devam et
                    </label></div>`;
            }
            if (maliyetsiz > 0) {
                uyari += `<div class="bp-info">${maliyetsiz} üründe maliyet girilmemiş — kâr hesaplanamadı.</div>`;
            }
            el('bpLossWarn').innerHTML = uyari;
            el('bpLossWarn').dataset.loss = String(zarar);

            if (BP.ids.length > 500) {
                el('bpFootNote').textContent = 'Önizleme ilk 500 üründe hesaplandı.';
            }
        } catch (err) {
            el('bpSummary').innerHTML = `<div class="bp-warn">${esc(err.message)}</div>`;
        }
    };

    function bpTL(v) {
        return (parseFloat(v || 0)).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
    }

    // ─── 4. adım: gönderim döngüsü ────────────────────────
    async function bpRun() {
        BP.running = true;
        BP.aborted = false;
        el('bpStopBtn').style.display = 'inline-flex';
        el('bpResult').innerHTML = '';

        while (!BP.aborted) {
            let veri;
            try {
                veri = await bpPost('/products/publish/bulk/chunk', { token: BP.token });
            } catch (err) {
                el('bpResult').innerHTML = `<div class="bp-warn">${esc(err.message)}</div>`;
                break;
            }

            bpRenderProgress(veri);

            if (veri.finished || veri.cancelled) {
                bpRenderResult(veri);
                break;
            }
        }

        BP.running = false;
        el('bpStopBtn').style.display = 'none';
        el('bpFootNote').textContent = 'Tamamlandı.';
    }

    function bpRenderProgress(veri) {
        const t = veri.totals || {};
        const c = veri.current || {};
        el('bpProgress').innerHTML = `
            <div style="font-size:13px;font-weight:700;margin-bottom:4px">${esc(c.marketplace || '')} — ${c.done || 0} / ${c.total || 0}</div>
            <div class="bp-bar"><span style="width:${veri.overall_pct || 0}%"></span></div>
            <div style="display:flex;gap:16px;font-size:12.5px">
                <span style="color:#15803d"><strong>${t.success || 0}</strong> başarılı</span>
                <span style="color:#b91c1c"><strong>${t.failed || 0}</strong> hatalı</span>
                <span><strong>${t.skipped || 0}</strong> atlandı</span>
                <span style="color:#b45309"><strong>${t.processing || 0}</strong> işleniyor</span>
            </div>`;
    }

    function bpRenderResult(veri) {
        const t = veri.totals || {};
        const hatalar = (veri.chunk && veri.chunk.errors) || [];
        let html = `<div class="${(t.failed || 0) > 0 ? 'bp-warn' : 'bp-info'}" style="margin-top:12px">
            Gönderim ${veri.cancelled ? 'durduruldu' : 'tamamlandı'}:
            ${t.success || 0} onaylandı, ${t.failed || 0} reddedildi, ${t.skipped || 0} atlandı
            ${(t.processing || 0) > 0 ? ', ' + t.processing + ' işleniyor' : ''}.</div>`;

        if ((t.processing || 0) > 0) {
            html += `<div class="bp-info" style="margin-top:8px">
                ${t.processing} ürün pazaryeri onayı bekliyor. Tekrar göndermeniz gerekmez —
                sonuç birkaç dakika içinde netleşir; ürünler o zaman "onaylandı" olur.</div>`;
        }

        if (hatalar.length) {
            html += '<div style="font-size:12px;margin-top:8px"><strong>Son hatalar:</strong><ul style="margin:6px 0 0;padding-left:18px">' +
                hatalar.slice(0, 5).map((h) => `<li>${esc((h.product_id ? '#' + h.product_id + ' — ' : '') + (h.error || ''))}</li>`).join('') +
                '</ul></div>';
        }

        el('bpResult').innerHTML = html;
    }

    window.bpStop = async function () {
        BP.aborted = true;
        try { await bpPost('/products/publish/bulk/cancel', { token: BP.token }); } catch (e) { /* yoksay */ }
    };

    // ─── "Filtreye uyan tümünü seç" ───────────────────────
    window.bpSelectAllMatching = function () {
        BP.useFilters = true;
        BP.matchingCount = window._bpMatchingTotal || 0;
        el('bpMatchingNote').style.display = 'inline';
        el('bpMatchingNote').textContent = `— filtreye uyan ${BP.matchingCount} ürünün tümü seçildi`;
        el('bpSelectAllMatching').style.display = 'none';
    };

    // Seçim değiştikçe "tümünü seç" bandını güncelle
    window.bpRefreshSelectionBand = function (sayfadakiSecili, toplamKayit) {
        const btn = el('bpSelectAllMatching');
        if (!btn) return;

        if (!BP.useFilters && sayfadakiSecili > 0 && toplamKayit > sayfadakiSecili) {
            window._bpMatchingTotal = toplamKayit;
            btn.style.display = 'inline';
            btn.textContent = `Filtreye uyan ${toplamKayit} ürünün tümünü seç`;
        } else {
            btn.style.display = 'none';
        }

        if (sayfadakiSecili === 0) {
            BP.useFilters = false;
            el('bpMatchingNote').style.display = 'none';
        }
    };
})();
</script>
