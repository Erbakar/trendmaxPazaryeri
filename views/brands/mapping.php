<?php
$brands = $data['brands'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$marketplaceId = $data['marketplaceId'] ?? 0;
$unmappedCount = $data['unmappedCount'] ?? 0;
$csrf = $data['_csrf'] ?? '';
$catalogSize    = (int) ($data['catalogSize'] ?? 0);
$confidenceSafe = (int) ($data['confidenceSafe'] ?? 75);
?>

<div class="filter-bar" style="justify-content:space-between">
    <form method="GET" action="/brands/mapping" style="display:flex;gap:8px;align-items:end">
        <div class="form-group">
            <label class="form-label">Pazaryeri</label>
            <select name="marketplace_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($marketplaces as $mp): ?>
                    <option value="<?= $mp['id'] ?>" <?= (int)$mp['id'] === $marketplaceId ? 'selected' : '' ?>><?= htmlspecialchars($mp['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <span class="badge badge-warning"><?= $unmappedCount ?> eşleştirilmemiş marka</span>
        </div>
    </form>
    <div style="display:flex;gap:8px;align-items:center">
        <button type="button" class="btn btn-secondary btn-sm" id="bmTopluAc"
                title="Eşleşmemiş tüm markaları tek bir pazaryeri markasına bağla">
            <i data-lucide="layers" style="width:14px;height:14px"></i> Toplu Marka Ata
        </button>
        <a class="btn btn-primary btn-sm"
           href="/mapping/auto?kind=brand&marketplace_id=<?= (int) $marketplaceId ?>"
           title="Eşleşmemiş kayıtları otomatik eşleştir">
            <i data-lucide="wand-2" style="width:14px;height:14px"></i> Akıllı Eşleştir
        </a>
    </div>
</div>

<?php if ($marketplaceId > 0 && $catalogSize < 50): ?>
<div class="alert alert-warning" style="margin-bottom:14px;padding:12px 14px;border-radius:10px;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:#92400e;font-size:12.5px">
    <strong>Bu pazaryerinin marka katalogu eksik görünüyor (<?= number_format($catalogSize) ?> kayıt).</strong>
    Önce <em>Pazaryerleri &rsaquo; Verileri Çek &rsaquo; Markalar</em> adımını çalıştırın.
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr><th>İç Marka</th><th>Ürün</th><th>Pazaryeri Markası</th><th>Durum</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($brands)): ?>
                    <tr><td colspan="5" class="empty-state"><p>Marka bulunamadı</p></td></tr>
                <?php else: ?>
                    <?php foreach ($brands as $b): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($b['name'] ?? '') ?></td>
                        <td class="text-center"><?= (int)($b['product_count'] ?? 0) ?></td>
                        <td><?= !empty($b['marketplace_brand_name']) ? htmlspecialchars($b['marketplace_brand_name']) : '<span class="text-muted">—</span>' ?></td>
                                                <?php
                        $conf = $b['confidence'] ?? null;
                        if (empty($b['mapping_id'])) {
                            $durum = '<span class="badge badge-secondary">Eşleşmemiş</span>';
                        } elseif ($conf !== null && $conf < $confidenceSafe) {
                            $durum = '<span class="badge badge-warning" title="Otomatik eşleştirildi (güven %' . (int) $conf
                                . '). Doğruluğunu kontrol edin.">&#9888; Kontrol edin</span>';
                        } else {
                            $durum = '<span class="badge badge-success">Eşleşmiş</span>';
                        }
                        ?>
                        <td><?= $durum ?></td>
                        <td>
                            <?php if (!empty($b['mapping_id'])): ?>
                                <form method="POST" action="/brands/mapping/<?= $b['mapping_id'] ?>/delete" style="display:inline">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Silmek istediğinize emin misiniz?')">✗</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Toplu marka atama -->
<div id="bmTopluKatman" role="dialog" aria-modal="true" aria-labelledby="bmTopluBaslik"
     style="display:none;position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.55);
            align-items:center;justify-content:center;padding:16px">
  <div style="background:var(--card-bg,#fff);color:var(--text,inherit);border:1px solid var(--border,#e5e7eb);
              border-radius:14px;width:100%;max-width:470px;padding:20px">
    <h3 id="bmTopluBaslik" style="margin:0 0 6px;font-size:16px;font-weight:700">Toplu Marka Ata</h3>
    <p style="margin:0 0 14px;font-size:12.5px;line-height:1.5;color:var(--text-muted,#6b7280)">
      Eşleşmemiş <strong id="bmTopluSayi">…</strong> markanın tamamı,
      seçtiğiniz tek bir pazaryeri markasına bağlanır. Zaten eşleşmiş olanlara dokunulmaz.
    </p>

    <label for="bmTopluArama" style="font-size:11px;font-weight:700;letter-spacing:.04em;
           display:block;margin-bottom:5px;color:var(--text-muted,#6b7280)">PAZARYERİ MARKASI ARA</label>
    <input type="text" id="bmTopluArama" class="form-control" autocomplete="off"
           placeholder="En az 2 harf — ör. Genel, Diğer, Muhtelif" style="width:100%">

    <div id="bmTopluSonuc" style="max-height:190px;overflow:auto;margin-top:8px;
         border:1px solid var(--border,#e5e7eb);border-radius:8px;display:none"></div>

    <div id="bmTopluSecim" style="display:none;font-size:12.5px;margin-top:10px;
         padding:9px 11px;border-radius:8px;background:rgba(16,185,129,.10);color:#047857"></div>

    <div id="bmTopluDurum" style="font-size:12.5px;margin-top:10px"></div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
      <button type="button" class="btn btn-secondary btn-sm" id="bmTopluKapat">Vazgeç</button>
      <button type="button" class="btn btn-primary btn-sm" id="bmTopluUygula" disabled>Tümünü Eşleştir</button>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    const MP   = <?= (int) $marketplaceId ?>;
    const CSRF = <?= json_encode((string) $csrf, JSON_UNESCAPED_UNICODE) ?>;

    const el = (id) => document.getElementById(id);
    const katman  = el('bmTopluKatman');
    const arama   = el('bmTopluArama');
    const sonuc   = el('bmTopluSonuc');
    const secimEl = el('bmTopluSecim');
    const durum   = el('bmTopluDurum');
    const uygula  = el('bmTopluUygula');

    let adaylar = [];
    let secim   = null;
    let zaman   = null;

    function mesaj(metin, renk) {
        durum.textContent = metin;
        durum.style.color = renk;
    }

    function ac() {
        adaylar = [];
        secim = null;
        arama.value = '';
        sonuc.innerHTML = '';
        sonuc.style.display = 'none';
        secimEl.style.display = 'none';
        mesaj('', '');
        uygula.disabled = true;
        uygula.textContent = 'Tümünü Eşleştir';
        katman.style.display = 'flex';
        arama.focus();

        // Sayfadaki rozet ÜRÜNÜ olan markaları sayar; toplu atama ise ürünsüz
        // markaları da bağlar. Pencere, gerçekten değiştireceği sayıyı gösterir.
        sayiGetir();
    }

    async function sayiGetir() {
        const kutu = el('bmTopluSayi');

        try {
            const yanit = await fetch('/brands/mapping/unmapped-count?marketplace_id=' + MP,
                { credentials: 'same-origin' });
            const veri = await yanit.json();
            kutu.textContent = String((veri.data && veri.data.count) || 0);
        } catch (e) {
            kutu.textContent = '?';
        }
    }

    function kapat() { katman.style.display = 'none'; }

    function ciz() {
        sonuc.innerHTML = '';
        sonuc.style.display = 'block';

        if (adaylar.length === 0) {
            const bos = document.createElement('div');
            bos.style.cssText = 'padding:16px;text-align:center;font-size:12.5px;color:#6b7280';
            bos.textContent = 'Sonuç yok. Katalog boşsa önce Pazaryerleri > Verileri Çek > Markalar adımını çalıştırın.';
            sonuc.appendChild(bos);
            return;
        }

        adaylar.forEach(function (aday, i) {
            const satir = document.createElement('button');
            satir.type = 'button';
            satir.dataset.sira = String(i);
            satir.style.cssText = 'display:block;width:100%;text-align:left;padding:9px 11px;'
                + 'background:none;border:0;border-bottom:1px solid var(--border,#e5e7eb);'
                + 'cursor:pointer;font-size:12.5px;color:inherit';
            satir.textContent = aday.name;
            sonuc.appendChild(satir);
        });
    }

    async function ara(q) {
        try {
            const yanit = await fetch('/brands/search-cache?q=' + encodeURIComponent(q)
                + '&marketplace_id=' + MP, { credentials: 'same-origin' });
            const veri = await yanit.json();
            adaylar = Array.isArray(veri.data) ? veri.data : [];
            ciz();
        } catch (e) {
            mesaj('Marka listesi alınamadı: ' + e.message, '#b91c1c');
        }
    }

    async function gonder() {
        if (secim === null) { return; }

        uygula.disabled = true;
        uygula.textContent = 'Eşleştiriliyor…';
        mesaj('', '');

        const veri = new FormData();
        veri.append('_csrf_token', CSRF);
        veri.append('marketplace_id', String(MP));
        veri.append('marketplace_brand_id', secim.mp_brand_id);
        veri.append('marketplace_brand_name', secim.name);
        veri.append('tumu', '1');

        try {
            const yanit = await fetch('/brands/mapping/bulk', {
                method: 'POST',
                body: veri,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const cevap = await yanit.json();

            if (!cevap.success) { throw new Error(cevap.message || 'İşlem başarısız'); }

            mesaj(cevap.message || 'Eşleştirildi', '#047857');
            setTimeout(function () { window.location.reload(); }, 900);
        } catch (e) {
            mesaj(e.message, '#b91c1c');
            uygula.disabled = false;
            uygula.textContent = 'Tümünü Eşleştir';
        }
    }

    el('bmTopluAc').addEventListener('click', ac);
    el('bmTopluKapat').addEventListener('click', kapat);
    uygula.addEventListener('click', gonder);

    katman.addEventListener('click', function (olay) {
        if (olay.target === katman) { kapat(); }
    });

    document.addEventListener('keydown', function (olay) {
        if (olay.key === 'Escape' && katman.style.display === 'flex') { kapat(); }
    });

    arama.addEventListener('input', function () {
        clearTimeout(zaman);
        const q = this.value.trim();

        if (q.length < 2) {
            sonuc.style.display = 'none';
            return;
        }

        zaman = setTimeout(function () { ara(q); }, 300);
    });

    sonuc.addEventListener('click', function (olay) {
        const dugme = olay.target.closest('[data-sira]');
        if (dugme === null) { return; }

        secim = adaylar[Number(dugme.dataset.sira)];
        secimEl.textContent = 'Seçilen: ' + secim.name;
        secimEl.style.display = 'block';
        uygula.disabled = false;
    });
})();
</script>
