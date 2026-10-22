<?php
$profile = $data['profile'] ?? null;
$csrf    = $data['_csrf'] ?? '';
$isEdit  = is_array($profile);

$v = static fn(string $key, mixed $default = '') => htmlspecialchars((string) ($profile[$key] ?? $default), ENT_QUOTES, 'UTF-8');
$checked = static fn(string $key, int $default = 1) => ((int) ($profile[$key] ?? $default)) === 1 ? 'checked' : '';

$direction  = (string) ($profile['direction'] ?? 'import');
$sourceType = (string) ($profile['source_type'] ?? 'url');
$format     = (string) ($profile['format'] ?? 'auto');
$period     = (int) ($profile['period_hours'] ?? 0);
$action     = $isEdit ? '/xml-profilleri/' . (int) $profile['id'] : '/xml-profilleri';

// Profile ÖZEL içe aktarım ayarları (store_settings; şema değişmez)
$ayar       = $data['settings'] ?? [];
$brands     = $data['brands'] ?? [];
$categories = $data['categories'] ?? [];
$karTipi    = (string) ($ayar['kar_tipi'] ?? 'yuzde');
?>
<style>
.xpf-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card);margin-bottom:16px;max-width:1200px}
.xpf-head{padding:12px 16px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border);background:var(--bg-sidebar)}
.xpf-head [data-lucide]{width:14px;height:14px}
.xpf-body{padding:16px}
.xpf-row{display:grid;gap:12px;margin-bottom:12px}
.xpf-r2{grid-template-columns:1fr 1fr}
.xpf-f{min-width:0;display:flex;flex-direction:column;gap:4px}
.xpf-f label{font-size:11px;font-weight:700;color:var(--text-secondary)}
.xpf-f input[type=text],.xpf-f input[type=url],.xpf-f input[type=number],.xpf-f select,.xpf-f textarea{padding:8px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg-sidebar);color:var(--text-primary);font-size:13px;font-family:inherit}
.xpf-f input:focus,.xpf-f select:focus,.xpf-f textarea:focus{outline:none;border-color:var(--primary)}
.xpf-f textarea{min-height:90px;resize:vertical;font-family:'JetBrains Mono',monospace;font-size:12px}
.xpf-f .hint{font-size:11px;color:var(--text-muted);font-weight:400}
.xpf-radio{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.xpf-radio label{display:block;border:1px solid var(--border);border-radius:10px;padding:12px 14px;cursor:pointer;transition:border-color .15s;background:var(--bg-sidebar)}
.xpf-radio label:hover{border-color:var(--primary)}
.xpf-radio label.on{border-color:var(--primary);background:var(--bg-card)}
.xpf-radio input{position:absolute;opacity:0;width:0;height:0}
.xpf-radio .rt{display:block;font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:2px}
.xpf-radio .rd{display:block;font-size:11px;color:var(--text-muted);line-height:1.4}
.xpf-toggle{display:flex;align-items:center;gap:10px;padding:8px 0}
.xpf-toggle input[type=checkbox]{width:36px;height:20px;appearance:none;background:var(--border);border-radius:10px;position:relative;cursor:pointer;transition:all .2s;flex:0 0 auto}
.xpf-toggle input[type=checkbox]::after{content:'';width:16px;height:16px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;transition:all .2s}
.xpf-toggle input[type=checkbox]:checked{background:var(--primary)}
.xpf-toggle input[type=checkbox]:checked::after{left:18px}
.xpf-toggle .tl{font-size:13px;font-weight:600;color:var(--text-primary)}
.xpf-toggle .tl small{display:block;font-weight:400;font-size:11px;color:var(--text-muted)}
.xpf-foot{display:flex;gap:10px;align-items:center;max-width:860px;margin-bottom:30px}
.o-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.o-btn-primary{background:var(--primary);color:#fff}.o-btn-primary:hover{opacity:.9}
.o-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.o-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.xpf-analiz{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;background:var(--primary);color:#fff;font-size:12px;font-weight:700;border:none;cursor:pointer;white-space:nowrap;font-family:inherit}
.xpf-analiz:hover{opacity:.9}
.xpf-analiz-sonuc{display:none;margin-top:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:9px 12px;font-size:12px;color:#166534}
@media(max-width:768px){.xpf-r2,.xpf-radio{grid-template-columns:1fr}}
</style>

<form method="POST" action="<?= htmlspecialchars($action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <!-- 1 · Profil -->
    <div class="xpf-card">
        <div class="xpf-head"><i data-lucide="file-code"></i> 1 · Profil Bilgileri</div>
        <div class="xpf-body">
            <div class="xpf-row xpf-r2">
                <div class="xpf-f">
                    <label>Profil Adı *</label>
                    <input type="text" name="name" value="<?= $v('name') ?>" placeholder="Örn: merterelektronik" required>
                </div>
                <div class="xpf-f">
                    <label>XML Periyodu <span class="hint">otomatik senkron sıklığı</span></label>
                    <select name="period_hours">
                        <?php foreach ([0 => 'Hiçbir Zaman', 1 => '1 Saatte Bir', 3 => '3 Saatte Bir', 6 => '6 Saatte Bir', 12 => '12 Saatte Bir', 24 => '24 Saatte Bir', 48 => '2 Günde Bir', 168 => '7 Günde Bir'] as $ph => $pl): ?>
                        <option value="<?= $ph ?>" <?= $period === $ph ? 'selected' : '' ?>><?= $pl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!$isEdit): ?>
            <div class="xpf-f" style="margin-bottom:4px">
                <label>XML Tür</label>
                <div class="xpf-radio" id="xpfDirection">
                    <label class="<?= $direction === 'import' ? 'on' : '' ?>">
                        <input type="radio" name="direction" value="import" <?= $direction === 'import' ? 'checked' : '' ?>>
                        <span class="rt">İçe Aktar</span>
                        <span class="rd">Tedarikçi XML'inden ürünleri sisteme aktar ve senkron tut (Ticimax / dbentegrasyon uyumlu)</span>
                    </label>
                    <label class="<?= $direction === 'export' ? 'on' : '' ?>">
                        <input type="radio" name="direction" value="export" <?= $direction === 'export' ? 'checked' : '' ?>>
                        <span class="rt">Dışa Aktar</span>
                        <span class="rd">Ürünlerinizi Ticimax formatında XML olarak paylaşın (bayilere link verin)</span>
                    </label>
                </div>
            </div>
            <?php else: ?>
            <div class="xpf-f">
                <label>XML Tür</label>
                <div style="font-size:13px;font-weight:700;color:var(--text-primary)"><?= $direction === 'import' ? 'İçe Aktar' : 'Dışa Aktar' ?> <span class="hint">(değiştirilemez)</span></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2 · Kaynak (yalnız içe aktar) -->
    <div class="xpf-card" id="xpfSourceCard" style="<?= $direction === 'export' && !$isEdit ? 'display:none' : ($direction === 'export' ? 'display:none' : '') ?>">
        <div class="xpf-head"><i data-lucide="link"></i> 2 · XML Kaynağı</div>
        <div class="xpf-body">
            <div class="xpf-f" style="margin-bottom:12px">
                <label>XML Tipi</label>
                <div class="xpf-radio" id="xpfSourceType">
                    <label class="<?= $sourceType === 'url' ? 'on' : '' ?>">
                        <input type="radio" name="source_type" value="url" <?= $sourceType === 'url' ? 'checked' : '' ?>>
                        <span class="rt">URL</span>
                        <span class="rd">XML bir web adresinden indirilir — periyotlu senkron için önerilir</span>
                    </label>
                    <label class="<?= $sourceType === 'file' ? 'on' : '' ?>">
                        <input type="radio" name="source_type" value="file" <?= $sourceType === 'file' ? 'checked' : '' ?>>
                        <span class="rt">Dosya</span>
                        <span class="rd">XML dosyası bilgisayarınızdan yüklenir</span>
                    </label>
                </div>
            </div>

            <div class="xpf-f" id="xpfUrlRow" style="<?= $sourceType === 'file' ? 'display:none' : '' ?>">
                <label>XML URL Adresi</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="text" name="source_url" id="xpfSourceUrl" value="<?= $v('source_url') ?>" placeholder="https://xml.dbentegrasyon.com/xml_goster/..." style="flex:1;min-width:280px">
                    <button type="button" class="xpf-analiz" id="xpfAnalizBtn"><i data-lucide="wand-2" style="width:13px;height:13px"></i> Analiz Et</button>
                </div>
            </div>

            <div class="xpf-f" id="xpfFileRow" style="<?= $sourceType === 'file' ? '' : 'display:none' ?>">
                <label>XML Dosyası <?= $isEdit ? '<span class="hint">boş bırakılırsa mevcut dosya korunur</span>' : '' ?></label>
                <input type="file" name="xml_file" accept=".xml,text/xml,application/xml">
                <button type="button" class="xpf-analiz" id="xpfFileAnalizBtn">Dosyayı Analiz Et</button>
            </div>

            <div class="xpf-analiz-sonuc" id="xpfAnalizSonuc" role="status"></div>
            <div class="xpf-row xpf-r2" style="margin-top:12px">
                <div class="xpf-f">
                    <label>XML Formatı</label>
                    <select name="format" id="xpfFormat">
                        <option value="auto" <?= $format === 'auto' ? 'selected' : '' ?>>Otomatik Algıla (önerilen)</option>
                        <option value="ticimax" <?= $format === 'ticimax' ? 'selected' : '' ?>>Ticimax V2 (UrunAdi/StokKodu/KUR_Deger)</option>
                        <option value="generic" <?= $format === 'generic' ? 'selected' : '' ?>>Genel (elle alan eşleme)</option>
                    </select>
                    <span class="hint">Ticimax formatında KUR_Deger'den döviz (USD/EUR) otomatik çözülür; TL fiyat TCMB satış kuruyla hesaplanır.</span>
                </div>
            </div>

            <div class="xpf-f" id="xpfMappingRow" style="<?= $format === 'generic' ? '' : 'display:none' ?>">
                <label>Alan Eşleştirme</label>
                <?php /* Önceden burada yalnız ham JSON kutusu vardı; kullanıcı eşleştirmeyi
                        elle JSON yazarak yapmak zorundaydı. Artık "XML'i Analiz Et"ten sonra
                        etiketler açılır listelerde gelir, JSON arka planda kurulur. */ ?>
                <div class="xpf-map-bos" id="xpfMapBos">
                    Önce yukarıdan <b>XML'i Analiz Et</b> deyin; feed'deki etiketler burada listelenir
                    ve sistem alanlarıyla eşleştirebilirsiniz.
                </div>
                <div class="xpf-map-wrap"><table class="xpf-map" id="xpfMapTable" hidden>
                    <thead><tr><th>Sistem Alanı</th><th>XML Etiketi</th><th>Örnek Değer</th></tr></thead>
                    <tbody id="xpfMapBody"></tbody>
                </table></div>
                <div class="xpf-map-onizleme" id="xpfMapOnizleme" hidden></div>
                <details id="xpfMapJsonKutu" style="margin-top:10px">
                    <summary class="hint" style="cursor:pointer">Gelişmiş: alan eşlemesini JSON olarak düzenle</summary>
                    <textarea name="field_mapping_json" id="xpfMapJson" style="margin-top:8px"
                              placeholder='{"item_tag":"Urun","sku":"StokKodu","name":"UrunAdi"}'><?= $v('field_mapping_json') ?></textarea>
                    <span class="hint">item_tag, sku ve name zorunludur. category_sep: kategori ağacı ayracı.</span>
                </details>
            </div>
        </div>
    </div>

    <!-- 3 · Senkron Seçenekleri (yalnız içe aktar) -->
    <div class="xpf-card" id="xpfOptionsCard" style="<?= $direction === 'export' ? 'display:none' : '' ?>">
        <div class="xpf-head"><i data-lucide="settings-2"></i> 3 · Senkron Seçenekleri</div>
        <div class="xpf-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px">
            <label class="xpf-toggle"><input type="checkbox" name="update_existing" value="1" <?= $checked('update_existing') ?>><span class="tl">Mevcut Ürünleri Güncelle<small>Kapalıysa yalnız yeni ürünler eklenir</small></span></label>
            <label class="xpf-toggle"><input type="checkbox" name="update_prices" value="1" <?= $checked('update_prices') ?>><span class="tl">Fiyatları Güncelle<small>Satış/alış fiyatı ve döviz bilgisi senkronlanır</small></span></label>
            <label class="xpf-toggle"><input type="checkbox" name="update_stock" value="1" <?= $checked('update_stock') ?>><span class="tl">Stokları Güncelle<small>Stok adedi feed'deki değere eşitlenir</small></span></label>
            <label class="xpf-toggle"><input type="checkbox" name="create_categories" value="1" <?= $checked('create_categories') ?>><span class="tl">Kategorileri Oluştur<small>KategoriTree'den hiyerarşik kategori açılır</small></span></label>
            <label class="xpf-toggle"><input type="checkbox" name="create_brands" value="1" <?= $checked('create_brands') ?>><span class="tl">Markaları Oluştur<small>Feed'deki marka yoksa otomatik eklenir</small></span></label>
            <label class="xpf-toggle"><input type="checkbox" name="import_images" value="1" <?= $checked('import_images') ?>><span class="tl">Resimleri Aktar<small>Resim URL'leri ürüne bağlanır</small></span></label>
        </div>
    </div>

    <!-- 4 · Ürün varsayılanları (yalnız içe aktar) -->
    <div class="xpf-card" id="xpfDefaultsCard" style="<?= $direction === 'export' ? 'display:none' : '' ?>">
        <div class="xpf-head"><i data-lucide="sliders-horizontal"></i> 4 · Ürün Varsayılanları</div>
        <div class="xpf-body">
            <p style="margin:0 0 14px;font-size:12px;color:var(--text-muted);line-height:1.5">
                Bu ayarlar YALNIZ bu XML profiline uygulanır. Her tedarikçinin kâr oranı, barkod ön eki ve
                kritik stok eşiği farklı olabilir; burada girilen değerler bu profilden gelen ürünlere işlenir.
            </p>

            <div class="xpf-row xpf-r2">
                <div class="xpf-f">
                    <label>Kâr Tipi</label>
                    <select name="kar_tipi">
                        <option value="yuzde" <?= $karTipi === 'yuzde' ? 'selected' : '' ?>>Yüzde (%)</option>
                        <option value="tutar" <?= $karTipi === 'tutar' ? 'selected' : '' ?>>Sabit Tutar (₺)</option>
                    </select>
                    <span class="hint">Toplu pazaryeri gönderiminde fiyat adımına ön-doldurulur.</span>
                </div>
                <div class="xpf-f">
                    <label>Kâr Değeri</label>
                    <input type="number" step="0.01" min="0" name="kar_degeri"
                           value="<?= htmlspecialchars((string) ($ayar['kar_degeri'] ?? 0)) ?>" placeholder="0">
                    <span class="hint">0 ise kâr kuralı ön-doldurulmaz.</span>
                </div>
            </div>

            <div class="xpf-row xpf-r2">
                <div class="xpf-f">
                    <label>Barkod Ön Eki</label>
                    <input type="text" name="barkod_onek" maxlength="20"
                           value="<?= htmlspecialchars((string) ($ayar['barkod_onek'] ?? '')) ?>" placeholder="Örn: OZK">
                    <span class="hint">İçe aktarımda barkodun başına eklenir (OZK8697…). Farklı tedarikçilerin aynı barkodu çakışmaz.</span>
                </div>
                <div class="xpf-f">
                    <label>Kritik Stok</label>
                    <input type="number" min="0" step="1" name="kritik_stok"
                           value="<?= (int) ($ayar['kritik_stok'] ?? 0) ?>" placeholder="0">
                    <span class="hint">Ürünün stok uyarı eşiği (min_stock_alert) bu değerle doldurulur.</span>
                </div>
            </div>

            <div class="xpf-row xpf-r2">
                <div class="xpf-f">
                    <label>Varsayılan Marka</label>
                    <select name="varsayilan_marka_id">
                        <option value="0">— Yok —</option>
                        <?php foreach ($brands as $mrk): ?>
                        <option value="<?= (int) $mrk['id'] ?>" <?= (int) ($ayar['varsayilan_marka_id'] ?? 0) === (int) $mrk['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($mrk['name'] ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Feed'de marka yoksa bu marka yazılır.</span>
                </div>
                <div class="xpf-f">
                    <label>Varsayılan Kategori</label>
                    <select name="varsayilan_kategori_id">
                        <option value="0">— Yok —</option>
                        <?php foreach ($categories as $kat): ?>
                        <option value="<?= (int) $kat['id'] ?>" <?= (int) ($ayar['varsayilan_kategori_id'] ?? 0) === (int) $kat['id'] ? 'selected' : '' ?>>
                            <?= str_repeat('— ', (int) ($kat['depth'] ?? 0)) . htmlspecialchars((string) ($kat['name'] ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Feed'de kategori yoksa bu kategori yazılır.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 5 · Durum -->
    <div class="xpf-card">
        <div class="xpf-head"><i data-lucide="toggle-right"></i> <?= $direction === 'export' ? '2' : '5' ?> · Durum</div>
        <div class="xpf-body">
            <label class="xpf-toggle"><input type="checkbox" name="is_active" value="1" <?= $checked('is_active') ?>><span class="tl">Profil Açık<small>Kapalı profiller otomatik senkronda ve public URL'de çalışmaz</small></span></label>
        </div>
    </div>

    <div class="xpf-foot">
        <button type="submit" class="o-btn o-btn-primary"><i data-lucide="save" style="width:15px;height:15px"></i> <?= $isEdit ? 'Güncelle' : 'Profili Oluştur' ?></button>
        <a href="/xml-profilleri" class="o-btn o-btn-outline">Vazgeç</a>
    </div>
</form>

<script>
(function(){
    function bindRadio(groupId, onChange){
        var g = document.getElementById(groupId);
        if (!g) return;
        g.querySelectorAll('input[type=radio]').forEach(function(r){
            r.addEventListener('change', function(){
                g.querySelectorAll('label').forEach(function(l){ l.classList.remove('on'); });
                r.closest('label').classList.add('on');
                onChange(r.value);
            });
        });
    }

    bindRadio('xpfDirection', function(val){
        var isExport = val === 'export';
        var src = document.getElementById('xpfSourceCard');
        var opt = document.getElementById('xpfOptionsCard');
        var def = document.getElementById('xpfDefaultsCard');
        if (src) src.style.display = isExport ? 'none' : '';
        if (opt) opt.style.display = isExport ? 'none' : '';
        if (def) def.style.display = isExport ? 'none' : '';
    });

    bindRadio('xpfSourceType', function(val){
        document.getElementById('xpfUrlRow').style.display  = val === 'file' ? 'none' : '';
        document.getElementById('xpfFileRow').style.display = val === 'file' ? '' : 'none';
    });

    var fmt = document.getElementById('xpfFormat');
    if (fmt) fmt.addEventListener('change', function(){
        document.getElementById('xpfMappingRow').style.display = fmt.value === 'generic' ? '' : 'none';
    });

    function xpfEscape(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function xpfAnalyze(event) {
        var btn = event.currentTarget;
        var form = btn.closest('form');
        var out = document.getElementById('xpfAnalizSonuc');
        var body = new FormData();
        var sourceType = form.querySelector('[name=source_type]:checked').value;
        body.append('source_type', sourceType);
        body.append('profile_id', <?= (int)($profile['id'] ?? 0) ?>);
        body.append('_csrf_token', (document.querySelector('meta[name=csrf-token]') || {}).content || '');
        if (sourceType === 'file') {
            var file = form.querySelector('[name=xml_file]').files[0];
            if (file) body.append('xml_file', file);
        } else body.append('source_url', document.getElementById('xpfSourceUrl').value.trim());
        btn.disabled = true;
        var old = btn.innerHTML;
        btn.textContent = 'Analiz ediliyor…';
        fetch('/xml-profilleri/analiz', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:body})
        .then(function(response){ return response.json(); }).then(function(result){
            var d = result.data || {};
            if (!result.success) throw new Error(result.message || 'Analiz başarısız');
            out.style.display = 'block'; out.style.background = ''; out.style.color = ''; out.style.borderColor = '';
            out.textContent = '✓ ' + d.count + ' ürün bulundu (<' + d.item_tag + '>). Alanları aşağıdaki listelerden kontrol edip değiştirebilirsiniz.';
            xpfMapKur(d.tags || [], d.item_tag || '', d.samples || {}, d.suggested_mapping || {});
            if (!d.is_ticimax || fmt.value === 'generic') fmt.value = 'generic';
            document.getElementById('xpfMappingRow').style.display = fmt.value === 'generic' ? '' : 'none';
        }).catch(function(error){
            out.style.display = 'block'; out.style.background = '#fef2f2'; out.style.color = '#991b1b';
            out.textContent = error.message;
        }).finally(function(){ btn.disabled = false; btn.innerHTML = old; });
    }
    ['xpfAnalizBtn','xpfFileAnalizBtn'].forEach(function(id){document.getElementById(id).addEventListener('click',xpfAnalyze);});

    // ─── Alan eşleştirme tablosu ──────────────────────────────────────────
    // Sistem alanı → XML etiketi. Seçimler JSON kutusuna yazılır; kaydedilen
    // biçim değişmediği için içe aktarma motoru aynen çalışır.
    var XPF_ALANLAR = [
        ['sku',          'SKU / Stok Kodu', true],
        ['name',         'Ürün Adı', true],
        ['barcode',      'Barkod', false],
        ['description',  'Açıklama', false],
        ['price',        'Satış Fiyatı', false],
        ['list_price',   'Liste Fiyatı', false],
        ['cost',         'Alış / Maliyet', false],
        ['stock',        'Stok', false],
        ['tax',          'KDV %', false],
        ['weight',       'Ağırlık (kg)', false],
        ['desi',         'Desi', false],
        ['image',        'Ana Görsel', false],
        ['image_prefix', 'Görsel Serisi Ön Eki (Image, Resim)', false],
        ['brand',        'Marka (ad)', false],
        ['category',     'Kategori — 1. seviye', false],
        ['category2',    'Kategori — 2. seviye', false],
        ['category3',    'Kategori — 3. seviye', false],
        ['category4',    'Kategori — 4. seviye', false],
        ['category5',    'Kategori — 5. seviye', false],
        ['currency',     'Para Birimi', false],
        ['attribute_color', 'Özellik · Renk', false],
        ['attribute_web_color', 'Özellik · Web Color (ana renk)', false],
        ['attribute_origin', 'Özellik · Menşei', false],
        ['attribute_size', 'Özellik · Beden', false],
        ['attribute_material', 'Özellik · Materyal', false],
        ['attribute_gender', 'Özellik · Cinsiyet', false],
        ['attribute_age_group', 'Özellik · Yaş Grubu', false]
    ];

    function xpfMevcutEsleme() {
        var kutu = document.getElementById('xpfMapJson');
        if (!kutu || !kutu.value.trim()) return {};
        try { return JSON.parse(kutu.value) || {}; } catch (e) { return {}; }
    }

    function xpfJsonYaz() {
        var kutu = document.getElementById('xpfMapJson');
        if (!kutu) return;
        var onceki = xpfMevcutEsleme();
        var yeni = Object.assign({}, onceki);
        if (onceki.item_tag) yeni.item_tag = onceki.item_tag;
        if (onceki.category_sep) yeni.category_sep = onceki.category_sep;
        var it = document.getElementById('xpfMapItemTag');
        if (it && it.value) yeni.item_tag = it.value;
        XPF_ALANLAR.forEach(function (a) {
            var s = document.getElementById('xpfMap_' + a[0]);
            if (s && s.value) yeni[a[0]] = s.value;
            else delete yeni[a[0]];
        });
        kutu.value = JSON.stringify(yeni, null, 2);
    }

    function xpfMapKur(etiketler, itemTag, samples, suggestions) {
        var body = document.getElementById('xpfMapBody');
        var saved = xpfMevcutEsleme();
        samples = samples || saved._samples || {};
        etiketler = Array.from(new Set(etiketler.concat(saved._tags || [])));
        suggestions = suggestions || {};
        if (!etiketler.length && !saved.item_tag) return;
        saved._tags = etiketler;
        saved._samples = samples;
        document.getElementById('xpfMapJson').value = JSON.stringify(saved);
        function options(values, selected) {
            return '<option value="">— Eşleşme yok —</option>' + Array.from(new Set(values.concat(selected ? [selected] : []))).map(function(t){
                return '<option value="'+xpfEscape(t)+'"'+(t===selected?' selected':'')+'>'+xpfEscape(t)+'</option>';
            }).join('');
        }
        var selectedItem = saved.item_tag || itemTag;
        var html = '<tr><td><b>Ürün Düğümü</b> (zorunlu)</td><td><select id="xpfMapItemTag">'
            + options([itemTag || selectedItem], selectedItem) + '</select></td><td>Her ürün için tekrarlanan düğüm</td></tr>';
        XPF_ALANLAR.forEach(function(a){
            var selected = Object.prototype.hasOwnProperty.call(saved,a[0]) ? saved[a[0]] : (suggestions[a[0]] || '');
            var choices = etiketler.slice();
            if(a[0]==='image_prefix') choices = choices.map(function(t){return t.replace(/_?\d+$/,'');});
            html += '<tr><td>'+a[1]+(a[2]?' (zorunlu)':'')+'</td><td><select aria-label="'+xpfEscape(a[1])+' XML etiketi" id="xpfMap_'+a[0]+'">'
                +options(choices,selected)+'</select></td><td data-xpf-sample="'+a[0]+'">'+xpfEscape(samples[selected] || '')+'</td></tr>';
        });
        body.innerHTML = html;
        document.getElementById('xpfMapTable').hidden = false;
        document.getElementById('xpfMapBos').style.display = 'none';
        body.querySelectorAll('select').forEach(function(field){field.addEventListener('change',function(){
            var key = field.id.replace('xpfMap_','');
            var cell = body.querySelector('[data-xpf-sample="'+key+'"]');
            if(cell) cell.textContent = samples[field.value] || '';
            xpfJsonYaz();
        });});
        xpfJsonYaz();
    }
    var initial = xpfMevcutEsleme();
    if (initial.item_tag) xpfMapKur(initial._tags || Object.values(initial).filter(function(v){return typeof v==='string';}),initial.item_tag,initial._samples || {},{});
    document.getElementById('xpfMapJson').addEventListener('change',function(){var map=xpfMevcutEsleme();xpfMapKur(map._tags || [],map.item_tag || '',map._samples || {},{});});
    document.getElementById('xpfMapJson').closest('form').addEventListener('submit',function(event){
        var direction = this.querySelector('[name=direction]:checked');
        if(direction && direction.value==='export') return;
        if(fmt.value!=='generic') return;
        xpfJsonYaz();
        var map=xpfMevcutEsleme();
        if(!map.item_tag || !map.sku || !map.name) {
            event.preventDefault();
            document.getElementById('xpfMappingRow').style.display='';
            document.getElementById('xpfMapBos').scrollIntoView({block:'center'});
            if(window.Toast) Toast.show('Ürün düğümü, SKU ve ürün adı eşlemelerini seçin.','warning');
        }
    });

    if (window.lucide) lucide.createIcons();
})();
</script>
<style>
/* Alan eşleştirme tablosu — eski "Ayarlar > İçe/Dışa Aktarma" ekranının karşılığı */
.xpf-map-bos{padding:14px 16px;border:1px dashed var(--border);border-radius:8px;font-size:12.5px;line-height:1.6;color:var(--text-muted);background:var(--bg-input)}
.xpf-map-wrap{max-width:100%;overflow-x:auto}
.xpf-map{width:100%;min-width:900px;table-layout:fixed;border-collapse:collapse;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.xpf-map thead th{padding:9px 12px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border)}
.xpf-map tbody td{padding:7px 12px;border-bottom:1px solid var(--border);font-size:12.5px;vertical-align:middle}
.xpf-map tbody tr:last-child td{border-bottom:0}
.xpf-map tbody td:first-child{width:26%;color:var(--text-primary)}
.xpf-map select{width:100%;padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:12.5px;background:var(--bg-card);color:var(--text-primary);font-family:inherit}
.xpf-map th:first-child{width:26%}.xpf-map th:nth-child(2){width:48%}.xpf-map th:last-child{width:26%}.xpf-map td{overflow-wrap:anywhere}
.xpf-f select:not([multiple]){appearance:none;-webkit-appearance:none;box-sizing:border-box;padding-right:44px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:16px 16px}
.xpf-map select{min-width:320px}
.xpf-map select:focus{outline:none;border-color:var(--primary)}
</style>
