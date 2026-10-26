<?php
$product = $data['product'] ?? null;
$categories = $data['categories'] ?? [];
$brands = $data['brands'] ?? [];
$attributes = $data['attributes'] ?? [];
$productAttributes = $data['productAttributes'] ?? [];
$isEdit = $product !== null;
$action = $isEdit ? "/products/{$product['id']}" : '/products';

$images = [];
if ($isEdit && !empty($product['images_json'])) {
    $decoded = json_decode($product['images_json'], true);
    if (is_array($decoded)) $images = $decoded;
}
$mainImage = $product['main_image'] ?? ($images[0]['url'] ?? ($images[0] ?? ''));
?>

<style>
/* === PRODUCT FORM === */
.pf-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.pf-top h2 { font-size:18px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px; margin:0; }
.pf-top h2 [data-lucide] { width:20px; height:20px; color:var(--primary); }
.pf-actions { display:flex; gap:8px; align-items:center; }
.pf-toggle { position:relative; width:40px; height:22px; flex-shrink:0; display:inline-block; }
.pf-toggle input { opacity:0; width:0; height:0; }
.pf-toggle-slider { position:absolute; inset:0; background:#cbd5e1; border-radius:22px; cursor:pointer; transition:all .25s; }
.pf-toggle-slider::before { content:''; position:absolute; width:16px; height:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:all .25s; box-shadow:0 0 0 1px var(--border); }
.pf-toggle input:checked + .pf-toggle-slider { background:#10b981; }
.pf-toggle input:checked + .pf-toggle-slider::before { transform:translateX(18px); }

/* Grid Layout */
.pf-grid { display:grid; grid-template-columns:1fr 340px; gap:16px; }
.pf-main { display:flex; flex-direction:column; gap:16px; }
.pf-side { display:flex; flex-direction:column; gap:16px; }

/* Section Card */
.pf-sec { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; overflow:hidden; }
.pf-sec-head { padding:12px 16px; font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; display:flex; align-items:center; gap:6px; border-bottom:1px solid var(--border); background:var(--bg-sidebar); }
.pf-sec-head [data-lucide] { width:14px; height:14px; }
.pf-sec-body { padding:16px; }

/* Fields */
.pf-row { display:grid; gap:12px; margin-bottom:12px; }
.pf-row:last-child { margin-bottom:0; }
.pf-r2 { grid-template-columns:1fr 1fr; }
.pf-r3 { grid-template-columns:1fr 1fr 1fr; }
.pf-r4 { grid-template-columns:1fr 1fr 1fr 1fr; }
.pf-f { display:flex; flex-direction:column; gap:3px; }
.pf-f label { font-size:11px; font-weight:700; color:var(--text-secondary); }
.pf-f input, .pf-f select, .pf-f textarea { padding:7px 10px; border:1px solid var(--border); border-radius:6px; background:var(--bg-sidebar); color:var(--text-primary); font-size:13px; font-family:inherit; transition:border-color .15s; }
.pf-f input:focus, .pf-f select:focus, .pf-f textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 2px rgb(var(--brand) / .08); }
.pf-f textarea { min-height:70px; resize:vertical; }

/* Images */
.pf-imgs { display:flex; flex-wrap:wrap; gap:8px; }
.pf-img { width:72px; height:72px; border-radius:8px; border:2px solid var(--border); position:relative; overflow:hidden; cursor:pointer; transition:all .15s; }
.pf-img:hover { border-color:var(--primary); transform:translateY(-1px); }
.pf-img img { width:100%; height:100%; object-fit:cover; }
.pf-img .badge { position:absolute; top:2px; left:2px; background:var(--primary); color:#fff; font-size:7px; font-weight:700; padding:1px 4px; border-radius:3px; }
.pf-img .acts { position:absolute; inset:0; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; gap:4px; opacity:0; transition:opacity .15s; }
.pf-img:hover .acts { opacity:1; }
.pf-img .acts button { width:22px; height:22px; border-radius:4px; border:none; cursor:pointer; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; }
.pf-img .acts .del { background:rgba(239,68,68,.9); }
.pf-img .acts .star { background:rgb(var(--brand) / .9); }
.pf-img-add { width:72px; height:72px; border-radius:8px; border:2px dashed var(--border); display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px; cursor:pointer; color:var(--text-muted); font-size:9px; transition:all .15s; }
.pf-img-add:hover { border-color:var(--primary); color:var(--primary); }
.pf-img-add [data-lucide] { width:16px; height:16px; }
.pf-drop { border:1px dashed var(--border); border-radius:6px; padding:10px; text-align:center; cursor:pointer; transition:all .15s; margin-top:8px; font-size:11px; color:var(--text-muted); }
.pf-drop:hover,.pf-drop.dragover { border-color:var(--primary); color:var(--primary); }

/* Variants */
.vr-row { display:grid; grid-template-columns:2fr 1.5fr 1.5fr 1fr 1fr 40px; gap:8px; align-items:end; padding:8px 0; border-bottom:1px solid var(--border); }
.vr-row:last-child { border-bottom:none; }
.vr-header { font-size:10px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; padding-bottom:4px; border-bottom:1px solid var(--border); }
.vr-row input { padding:6px 8px; border:1px solid var(--border); border-radius:5px; background:var(--bg-sidebar); color:var(--text-primary); font-size:12px; font-family:inherit; width:100%; }
.vr-row input:focus { outline:none; border-color:var(--primary); }
.vr-del { width:28px; height:28px; border-radius:5px; border:none; background:none; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .1s; }
.vr-del:hover { background:rgba(239,68,68,.1); color:var(--danger); }
.vr-add { display:inline-flex; align-items:center; gap:4px; margin-top:8px; padding:5px 10px; border:1px dashed var(--border); border-radius:6px; background:none; color:var(--text-muted); font-size:11px; cursor:pointer; transition:all .15s; }
.vr-add:hover { border-color:var(--primary); color:var(--primary); }
.vr-add [data-lucide] { width:12px; height:12px; }

@media (max-width:900px) {
    .pf-grid { grid-template-columns:1fr; }
    .pf-r3,.pf-r4 { grid-template-columns:1fr 1fr; }
}
</style>

<form method="POST" action="<?= $action ?>" id="productForm" enctype="multipart/form-data">
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>">
<input type="hidden" name="images_json" id="imagesJsonInput" value='<?= htmlspecialchars($product['images_json'] ?? '[]') ?>'>
<input type="hidden" name="main_image" id="mainImageInput" value="<?= htmlspecialchars($mainImage) ?>">
<input type="hidden" name="seo_title" value="<?= htmlspecialchars($product['seo_title'] ?? '') ?>">
<input type="hidden" name="seo_description" value="<?= htmlspecialchars($product['seo_description'] ?? '') ?>">
<input type="hidden" name="technical_specs" id="technical_specs" value="<?= htmlspecialchars($product['technical_specs'] ?? '') ?>">

<!-- Top Bar -->
<div class="pf-top">
    <h2>
        <i data-lucide="<?= $isEdit ? 'edit-3' : 'plus' ?>"></i>
        <?= $isEdit ? 'Ürün Düzenle' : 'Yeni Ürün Ekle' ?>
    </h2>
    <div class="pf-actions">
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--text-primary)">
            <span class="pf-toggle">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" <?= ($product['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span class="pf-toggle-slider"></span>
            </span>
            Aktif
        </label>
        <button type="submit" class="btn btn-primary btn-sm">
            <i data-lucide="<?= $isEdit ? 'save' : 'plus' ?>" style="width:14px;height:14px"></i>
            <?= $isEdit ? 'Kaydet' : 'Oluştur' ?>
        </button>
        <a href="/products" class="btn btn-secondary btn-sm">İptal</a>
    </div>
</div>

<!-- === GRID LAYOUT === -->
<div class="pf-grid">

    <!-- LEFT: Ana İçerik -->
    <div class="pf-main">

        <!-- Temel Bilgiler -->
        <div class="pf-sec">
            <div class="pf-sec-head"><i data-lucide="box"></i> Temel Bilgiler
                <button type="button" id="aiGenBtn" onclick="aiGenerateContent()" data-pid="<?= $isEdit ? (int) $product['id'] : 0 ?>"
                    title="Ürün adından SEO, açıklama ve teknik özellik üretir (kredi harcar)"
                    style="margin-left:auto;display:inline-flex;align-items:center;gap:5px;border:1px solid var(--primary);color:var(--primary);background:rgb(var(--brand) / .06);border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;text-transform:none;letter-spacing:0">
                    <i data-lucide="sparkles" style="width:13px;height:13px"></i> AI ile Üret
                </button>
            </div>
            <div class="pf-sec-body">
                <div class="pf-row pf-r3">
                    <div class="pf-f">
                        <label>Ürün Adı *</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($product['name'] ?? '') ?>" required placeholder="Ürün adı">
                    </div>
                    <div class="pf-f">
                        <label>SKU *</label>
                        <input type="text" name="sku" value="<?= htmlspecialchars($product['sku'] ?? '') ?>" required <?= $isEdit ? 'readonly style="opacity:.6"' : '' ?> placeholder="Stok kodu">
                    </div>
                    <div class="pf-f">
                        <label>Barkod</label>
                        <input type="text" name="barcode" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>" placeholder="EAN13 / UPC">
                    </div>
                </div>
                <div class="pf-row pf-r2">
                    <div class="pf-f">
                        <label>Kategori</label>
                        <select name="category_id">
                            <option value="">— Seçiniz —</option>
                            <?php foreach($categories as $c): ?>
                            <option value="<?=$c['id']?>" <?=($product['category_id']??'')==$c['id']?'selected':''?>><?=htmlspecialchars($c['name']??'')?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pf-f">
                        <label>Marka</label>
                        <select name="brand_id">
                            <option value="">— Seçiniz —</option>
                            <?php foreach($brands as $b): ?>
                            <option value="<?=$b['id']?>" <?=($product['brand_id']??'')==$b['id']?'selected':''?>><?=htmlspecialchars($b['name']??'')?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="pf-row pf-r2">
                    <div class="pf-f">
                        <label>Açıklama</label>
                        <textarea name="description" placeholder="Opsiyonel"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                    </div>
                    <div class="pf-f">
                        <label>Kısa Açıklama</label>
                        <textarea name="short_description" placeholder="Opsiyonel" style="min-height:70px"><?= htmlspecialchars($product['short_description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fiyat & Stok -->
        <div class="pf-sec">
            <div class="pf-sec-head"><i data-lucide="tag"></i> Fiyat & Stok</div>
            <div class="pf-sec-body">
                <div class="pf-row pf-r4">
                    <div class="pf-f">
                        <label>Satış Fiyatı (₺) *</label>
                        <input type="number" name="base_price" step="0.01" value="<?=$product['base_price']??''?>" required placeholder="0.00">
                    </div>
                    <div class="pf-f">
                        <label>Liste Fiyatı (₺)</label>
                        <input type="number" name="list_price" step="0.01" value="<?=$product['list_price']??''?>" placeholder="0.00">
                    </div>
                    <div class="pf-f">
                        <label>Maliyet (₺)</label>
                        <input type="number" name="cost_price" step="0.01" value="<?=$product['cost_price']??''?>" placeholder="0.00">
                    </div>
                    <div class="pf-f">
                        <label>KDV %</label>
                        <select name="tax_rate">
                            <option value="0" <?=($product['tax_rate']??20)==0?'selected':''?>>%0</option>
                            <option value="1" <?=($product['tax_rate']??20)==1?'selected':''?>>%1</option>
                            <option value="10" <?=($product['tax_rate']??20)==10?'selected':''?>>%10</option>
                            <option value="20" <?=($product['tax_rate']??20)==20?'selected':''?>>%20</option>
                        </select>
                    </div>
                </div>
                <div class="pf-row pf-r4">
                    <div class="pf-f">
                        <label>Stok Adedi</label>
                        <input type="number" name="stock_qty" value="<?=$product['stock_qty']??0?>" <?=$isEdit?'readonly style="opacity:.6"':''?>>
                    </div>
                    <div class="pf-f">
                        <label>Min. Stok Uyarı</label>
                        <input type="number" name="min_stock_alert" value="<?=$product['min_stock_alert']??5?>">
                    </div>
                    <div class="pf-f">
                        <label>Ağırlık (kg)</label>
                        <input type="number" name="weight" step="0.001" value="<?=$product['weight']??''?>" placeholder="—">
                    </div>
                    <div class="pf-f">
                        <label>Desi</label>
                        <input type="number" name="desi" step="0.01" value="<?=$product['desi']??''?>" placeholder="—">
                    </div>
                </div>
            </div>
        </div>

        <!-- Varyantlar -->
        <div class="pf-sec">
            <div class="pf-sec-head"><i data-lucide="layers"></i> Varyantlar <span style="margin-left:auto;font-size:10px;font-weight:400;color:var(--text-muted)">Beden, renk gibi farklı seçenekler</span></div>
            <div class="pf-sec-body">
                <div id="variantsList">
                    <div class="vr-row vr-header">
                        <div>Varyant Adı</div>
                        <div>SKU</div>
                        <div>Barkod</div>
                        <div>Fiyat (₺)</div>
                        <div>Stok</div>
                        <div></div>
                    </div>
                    <!-- Mevcut varyantlar -->
                    <?php if (!empty($data['variants'] ?? [])): ?>
                        <?php foreach ($data['variants'] as $vi => $v): ?>
                        <div class="vr-row">
                            <input type="text" name="variants[<?=$vi?>][name]" value="<?=htmlspecialchars($v['variant_label']??$v['name']??'')?>" placeholder="Kırmızı - XL">
                            <input type="text" name="variants[<?=$vi?>][sku]" value="<?=htmlspecialchars($v['sku']??'')?>" placeholder="SKU">
                            <input type="text" name="variants[<?=$vi?>][barcode]" value="<?=htmlspecialchars($v['barcode']??'')?>" placeholder="Barkod">
                            <input type="number" name="variants[<?=$vi?>][price]" step="0.01" value="<?=$v['base_price']??''?>" placeholder="0.00">
                            <input type="number" name="variants[<?=$vi?>][stock]" value="<?=$v['stock_qty']??0?>">
                            <button type="button" class="vr-del" onclick="this.closest('.vr-row').remove()"><i data-lucide="x" style="width:14px;height:14px"></i></button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="vr-add" onclick="addVariant()">
                    <i data-lucide="plus"></i> Varyant Ekle
                </button>
            </div>
        </div>

    </div>

    <!-- RIGHT: Sidebar -->
    <div class="pf-side">

        <!-- Görseller -->
        <div class="pf-sec">
            <div class="pf-sec-head"><i data-lucide="image"></i> Görseller</div>
            <div class="pf-sec-body">
                <div class="pf-imgs" id="imageGallery">
                    <?php foreach ($images as $idx => $img):
                        $imgUrl = is_array($img) ? ($img['url'] ?? '') : $img;
                        if (empty($imgUrl)) continue;
                    ?>
                    <div class="pf-img" data-url="<?=htmlspecialchars($imgUrl)?>">
                        <?php if($idx===0):?><span class="badge">ANA</span><?php endif;?>
                        <img src="<?=htmlspecialchars($imgUrl)?>" alt="" loading="lazy" onclick="openLightbox('<?=htmlspecialchars($imgUrl)?>')">
                        <div class="acts">
                            <button type="button" class="del" onclick="removeImage(this)">✖</button>
                            <?php if($idx>0):?><button type="button" class="star" onclick="makeMainImage(this)">★</button><?php endif;?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="pf-img-add" onclick="document.getElementById('fileUploadInput').click()">
                        <i data-lucide="plus"></i>
                        Ekle
                    </div>
                </div>
                <input type="file" id="fileUploadInput" multiple accept="image/*" style="display:none" onchange="handleFileUpload(this.files)">
                <div class="pf-drop" id="uploadZone" onclick="document.getElementById('fileUploadInput').click()">
                    Sürükle-bırak veya tıkla · Max 5MB
                </div>
                <div style="margin-top:8px;display:flex;gap:6px">
                    <input type="text" id="newImageUrl" placeholder="https://..." style="flex:1;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:var(--bg-sidebar);color:var(--text-primary)">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="addImageFromUrl()">Ekle</button>
                </div>
                <button type="button" id="aiVisionBtn" onclick="aiVisionAnalyze()"
                        style="margin-top:8px;width:100%;display:inline-flex;align-items:center;justify-content:center;gap:6px;background:var(--primary);color:#fff;border:0;border-radius:7px;padding:8px 10px;font-size:12.5px;font-weight:700;cursor:pointer">
                    <i data-lucide="scan-eye" style="width:14px;height:14px"></i> Görselden Doldur (AI)
                </button>
                <div id="aiVisionResult" style="display:none;margin-top:8px;border:1px solid var(--border);border-radius:8px;padding:10px;font-size:12px;background:var(--bg-sidebar)"></div>
            </div>
        </div>

        <?php if ($isEdit): ?>
        <!-- AI Pazaryeri Optimizasyonu (Modül #4) -->
        <div class="pf-sec">
            <div class="pf-sec-head"><i data-lucide="gauge"></i> AI Pazaryeri Optimizasyonu</div>
            <div class="pf-sec-body">
                <button type="button" id="aiOptBtn" onclick="aiOptimize()" data-pid="<?= (int) $product['id'] ?>"
                        style="width:100%;display:inline-flex;align-items:center;justify-content:center;gap:6px;background:var(--primary);color:#fff;border:0;border-radius:7px;padding:8px 10px;font-size:12.5px;font-weight:700;cursor:pointer">
                    <i data-lucide="sparkles" style="width:14px;height:14px"></i> Liste Kalitesini Analiz Et
                </button>
                <div id="aiOptResult" style="display:none;margin-top:8px;font-size:12px"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Birim & Durum -->
        <div class="pf-sec">
            <div class="pf-sec-head"><i data-lucide="settings"></i> Diğer</div>
            <div class="pf-sec-body">
                <div class="pf-f" style="margin-bottom:10px">
                    <label>Birim</label>
                    <select name="unit">
                        <?php $units=['adet'=>'Adet','kg'=>'KG','lt'=>'LT','mt'=>'MT','paket'=>'Paket'];
                        foreach($units as $v=>$l): ?>
                        <option value="<?=$v?>" <?=($product['unit']??'adet')===$v?'selected':''?>><?=$l?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($attributes)): ?>
                <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:4px">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px">Özellikler</div>
                    <?php foreach ($attributes as $attr):
                        $currentValue = '';
                        foreach ($productAttributes as $pav) {
                            if ((int)$pav['attribute_id'] === (int)$attr['id']) {
                                $currentValue = $pav['custom_value'] ?? $pav['attribute_value_text'] ?? '';
                                break;
                            }
                        }
                    ?>
                    <div class="pf-f" style="margin-bottom:8px">
                        <label><?=htmlspecialchars($attr['name'])?><?php if((int)($attr['is_required']??0)):?> *<?php endif;?></label>
                        <?php if(in_array($attr['input_type'],['select','multi_select'])):
                            $attrValues = $attr['values'] ?? [];
                        ?>
                        <select name="attrs[<?=$attr['id']?>]">
                            <option value="">—</option>
                            <?php foreach($attrValues as $av):?>
                            <option value="<?=$av['id']?>" <?=($currentValue==$av['value']||($pav['attribute_value_id']??'')==$av['id'])?'selected':''?>><?=htmlspecialchars($av['value'])?></option>
                            <?php endforeach;?>
                        </select>
                        <?php elseif($attr['input_type']==='boolean'):?>
                        <select name="attrs[<?=$attr['id']?>]">
                            <option value="">—</option>
                            <option value="Evet" <?=$currentValue==='Evet'?'selected':''?>>Evet</option>
                            <option value="Hayır" <?=$currentValue==='Hayır'?'selected':''?>>Hayır</option>
                        </select>
                        <?php elseif($attr['input_type']==='number'):?>
                        <input type="number" name="attrs[<?=$attr['id']?>]" step="0.01" value="<?=htmlspecialchars($currentValue)?>">
                        <?php else:?>
                        <input type="text" name="attrs[<?=$attr['id']?>]" value="<?=htmlspecialchars($currentValue)?>">
                        <?php endif;?>
                    </div>
                    <?php endforeach;?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
</form>

<!-- Lightbox -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    <img src="" alt="" class="lightbox-img" id="lightboxImg" onclick="event.stopPropagation()">
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// === IMAGES ===
function getImagesFromGallery() { return [...document.querySelectorAll('.pf-img')].map(el=>({url:el.dataset.url})); }
function updateImagesInput() {
    const imgs = getImagesFromGallery();
    document.getElementById('imagesJsonInput').value = JSON.stringify(imgs);
    document.getElementById('mainImageInput').value = imgs[0]?.url || '';
}
function addImageToGallery(url) {
    const g = document.getElementById('imageGallery'), add = g.querySelector('.pf-img-add'), n = g.querySelectorAll('.pf-img').length;
    const d = document.createElement('div'); d.className='pf-img'; d.dataset.url=url;
    d.innerHTML = `${n===0?'<span class="badge">ANA</span>':''}<img src="${url}" alt="" loading="lazy" onclick="openLightbox('${url}')"><div class="acts"><button type="button" class="del" onclick="removeImage(this)">✖</button>${n>0?'<button type="button" class="star" onclick="makeMainImage(this)">★</button>':''}</div>`;
    g.insertBefore(d, add); updateImagesInput();
}
function addImageFromUrl() {
    const i = document.getElementById('newImageUrl'), u = i.value.trim();
    if (!u||!u.match(/^https?:\/\//i)){Toast.show('Geçerli URL girin','warning');return;}
    addImageToGallery(u); i.value=''; Toast.show('Eklendi','success',1500);
}
function handleFileUpload(files) {
    if(!files)return;
    Array.from(files).forEach(f=>{
        if(!f.type.startsWith('image/'))return;
        if(f.size>5*1024*1024){Toast.show(f.name+' çok büyük','warning');return;}
        const fd=new FormData(); fd.append('image',f);
        fetch('/products/upload-image',{method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd})
        .then(r=>r.json()).then(d=>{
            if(d.success&&d.data?.url){addImageToGallery(d.data.url);Toast.show('Yüklendi','success',1500);}
            else Toast.show(d.message||'Hata','error');
        }).catch(e=>Toast.show('Hata','error'));
    });
    document.getElementById('fileUploadInput').value='';
}
const dz=document.getElementById('uploadZone');
if(dz){['dragenter','dragover'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('dragover')}));['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('dragover')}));dz.addEventListener('drop',ev=>{ev.preventDefault();handleFileUpload(ev.dataTransfer.files)});}

function removeImage(b){b.closest('.pf-img').remove();updateImagesInput();refreshBadges();Toast.show('Kaldırıldı','success',1500);}
function makeMainImage(b){const i=b.closest('.pf-img'),g=document.getElementById('imageGallery');g.insertBefore(i,g.firstChild);refreshBadges();updateImagesInput();}
function refreshBadges(){document.querySelectorAll('.pf-img').forEach((el,i)=>{el.querySelector('.badge')?.remove();if(i===0){const b=document.createElement('span');b.className='badge';b.textContent='ANA';el.prepend(b);}});}

// === VARIANTS ===
let vrCount = <?= count($data['variants'] ?? []) ?>;
function addVariant() {
    vrCount++;
    const row = document.createElement('div');
    row.className = 'vr-row';
    row.innerHTML = `
        <input type="text" name="variants[${vrCount}][name]" placeholder="Kırmızı - XL">
        <input type="text" name="variants[${vrCount}][sku]" placeholder="SKU">
        <input type="text" name="variants[${vrCount}][barcode]" placeholder="Barkod">
        <input type="number" name="variants[${vrCount}][price]" step="0.01" placeholder="0.00">
        <input type="number" name="variants[${vrCount}][stock]" value="0">
        <button type="button" class="vr-del" onclick="this.closest('.vr-row').remove()"><i data-lucide="x" style="width:14px;height:14px"></i></button>
    `;
    document.getElementById('variantsList').appendChild(row);
    if (typeof lucide !== 'undefined') lucide.createIcons();
    row.querySelector('input').focus();
}

// === LIGHTBOX ===
function openLightbox(url){document.getElementById('lightboxImg').src=url;document.getElementById('lightbox').classList.add('show');document.body.style.overflow='hidden';}
function closeLightbox(){document.getElementById('lightbox').classList.remove('show');document.body.style.overflow='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLightbox();});
// === AI İÇERİK ASİSTANI (Modül #1) ===
function aiGenerateContent(){
    const form=document.getElementById('productForm');
    const btn=document.getElementById('aiGenBtn');
    const name=(form.querySelector('[name="name"]')?.value||'').trim();
    if(!name){Toast.show('Önce ürün adını girin','warning');return;}
    const catSel=form.querySelector('[name="category_id"]');
    const brSel=form.querySelector('[name="brand_id"]');
    const cat=(catSel&&catSel.value)?catSel.options[catSel.selectedIndex].text:'';
    const br=(brSel&&brSel.value)?brSel.options[brSel.selectedIndex].text:'';
    const fd=new FormData();
    fd.append('_csrf_token', form.querySelector('[name="_csrf_token"]')?.value||'');
    fd.append('name',name); fd.append('category',cat); fd.append('brand',br); fd.append('product_id', btn?.dataset.pid||'0');
    const orig=btn.innerHTML; btn.disabled=true; btn.style.opacity=.6;
    btn.innerHTML='<i data-lucide="loader-2" style="width:13px;height:13px"></i> Üretiliyor…';
    if(typeof lucide!=='undefined')lucide.createIcons();
    fetch('/ai/content',{method:'POST',body:fd})
        .then(r=>r.json().then(d=>({ok:r.ok,d})))
        .then(({ok,d})=>{
            if(d.success&&d.data&&d.data.content){
                const c=d.data.content;
                const set=(sel,val)=>{const el=form.querySelector(sel); if(el&&val){el.value=val;}};
                set('[name="description"]',c.description);
                set('[name="short_description"]',c.short_description);
                set('[name="seo_title"]',c.seo_title);
                set('[name="seo_description"]',c.seo_description);
                set('[name="technical_specs"]',c.technical_specs);
                let msg='İçerik üretildi. Gözden geçirip Kaydet\'e basın.';
                if(typeof d.data.balance!=='undefined') msg+=' (Kalan kredi: '+d.data.balance+')';
                Toast.show(msg,'success',5000);
                if(c.keywords) Toast.show('Anahtar kelimeler: '+c.keywords,'info',7000);
            } else {
                Toast.show(d.message||'AI üretimi başarısız','error',6000);
            }
        })
        .catch(()=>Toast.show('Bağlantı hatası','error'))
        .finally(()=>{btn.disabled=false; btn.style.opacity=1; btn.innerHTML=orig; if(typeof lucide!=='undefined')lucide.createIcons();});
}

// === AI GÖRSEL ANALİZİ (Modül #3) ===
function aiVisionAnalyze(){
    const form=document.getElementById('productForm');
    const btn=document.getElementById('aiVisionBtn');
    const box=document.getElementById('aiVisionResult');
    const pid=document.getElementById('aiGenBtn')?.dataset.pid||'0';
    let img=(document.getElementById('mainImageInput')?.value||'').trim();
    if(!img){ const first=document.querySelector('#imageGallery .pf-img'); if(first) img=first.dataset.url||''; }
    if(!img){ Toast.show('Önce bir ürün görseli ekleyin','warning'); return; }
    const fd=new FormData();
    fd.append('_csrf_token', form.querySelector('[name="_csrf_token"]')?.value||'');
    fd.append('image_url',img); fd.append('product_id',pid);
    const orig=btn.innerHTML; btn.disabled=true; btn.style.opacity=.6;
    btn.innerHTML='<i data-lucide="loader-2" style="width:14px;height:14px"></i> Analiz ediliyor…';
    if(typeof lucide!=='undefined')lucide.createIcons();
    fetch('/ai/vision',{method:'POST',body:fd})
        .then(r=>r.json().then(d=>({ok:r.ok,d})))
        .then(({ok,d})=>{
            if(d.success&&d.data&&d.data.analysis){
                const a=d.data.analysis; window.__aiVision=a;
                const esc=s=>(s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
                const row=(k,v)=>v?('<div style="margin:2px 0"><b>'+k+':</b> '+esc(v)+'</div>'):'';
                let h='<div style="font-weight:700;margin-bottom:6px;color:var(--primary)">AI Görsel Önerileri</div>';
                h+=row('Tür',a.product_type)+row('Kategori',a.category)+row('Marka',a.brand)+row('Renk',a.color)+row('Malzeme',a.material);
                if(a.key_features&&a.key_features.length) h+='<div style="margin:2px 0"><b>Özellikler:</b> '+esc(a.key_features.join(', '))+'</div>';
                h+=row('Başlık',a.suggested_title);
                h+='<button type="button" onclick="aiVisionApply()" style="margin-top:8px;width:100%;background:var(--primary);color:#fff;border:0;border-radius:6px;padding:7px;font-size:12px;font-weight:700;cursor:pointer">Boş alanlara uygula</button>';
                box.innerHTML=h; box.style.display='block';
                let msg='Görsel analiz edildi.'; if(typeof d.data.balance!=='undefined') msg+=' (Kalan kredi: '+d.data.balance+')';
                Toast.show(msg,'success',4000);
            } else { Toast.show(d.message||'Görsel analizi başarısız','error',6000); }
        })
        .catch(()=>Toast.show('Bağlantı hatası','error'))
        .finally(()=>{btn.disabled=false; btn.style.opacity=1; btn.innerHTML=orig; if(typeof lucide!=='undefined')lucide.createIcons();});
}
function aiVisionApply(){
    const a=window.__aiVision; if(!a) return;
    const form=document.getElementById('productForm');
    const setIfEmpty=(sel,val)=>{const el=form.querySelector(sel); if(el&&val&&!el.value.trim()){el.value=val;}};
    setIfEmpty('[name="name"]',a.suggested_title);
    setIfEmpty('[name="short_description"]',a.suggested_description);
    const desc=form.querySelector('[name="description"]');
    if(desc&&!desc.value.trim()&&a.suggested_description){
        let d=a.suggested_description;
        if(a.key_features&&a.key_features.length) d+='\n\nÖne çıkan özellikler:\n- '+a.key_features.join('\n- ');
        desc.value=d;
    }
    Toast.show('Boş alanlar dolduruldu. Kategori/marka önerilerini elle seçin.','success',5000);
}

// === AI PAZARYERİ OPTİMİZASYONU (Modül #4) ===
function aiOptimize(){
    const btn=document.getElementById('aiOptBtn');
    const box=document.getElementById('aiOptResult');
    const form=document.getElementById('productForm');
    const pid=btn?.dataset.pid||'0';
    const fd=new FormData();
    fd.append('_csrf_token', form.querySelector('[name="_csrf_token"]')?.value||'');
    fd.append('product_id',pid);
    const orig=btn.innerHTML; btn.disabled=true; btn.style.opacity=.6;
    btn.innerHTML='<i data-lucide="loader-2" style="width:14px;height:14px"></i> Analiz ediliyor…';
    if(typeof lucide!=='undefined')lucide.createIcons();
    fetch('/ai/optimize',{method:'POST',body:fd})
        .then(r=>r.json().then(d=>({ok:r.ok,d})))
        .then(({ok,d})=>{
            if(d.success&&d.data&&d.data.optimization){
                const o=d.data.optimization;
                const esc=s=>(s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
                const sc=o.score|0;
                const col=sc>=75?'#1a7f43':(sc>=45?'#a85a07':'#b91c1c');
                let h='<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">'
                    +'<div style="position:relative;width:54px;height:54px;flex:none;border-radius:50%;background:var(--border)">'
                    +'<div style="position:absolute;inset:5px;border-radius:50%;background:var(--bg-card);display:grid;place-items:center;font-weight:800;font-size:14px;color:'+col+'">'+sc+'</div></div>'
                    +'<div style="font-size:12px;color:var(--text-secondary)">Liste kalite puanı<br><b style="color:'+col+'">'+(sc>=75?'İyi':(sc>=45?'Geliştirilebilir':'Zayıf'))+'</b></div></div>';
                if(o.summary) h+='<div style="margin-bottom:8px;line-height:1.5">'+esc(o.summary)+'</div>';
                if(o.issues&&o.issues.length){
                    h+='<div style="font-weight:700;margin:6px 0 4px">Sorunlar & Öneriler</div>';
                    o.issues.forEach(it=>{
                        const sv=it.severity==='yüksek'?'#b91c1c':(it.severity==='düşük'?'#6b7280':'#a85a07');
                        h+='<div style="border-left:3px solid '+sv+';padding:4px 8px;margin:5px 0;background:var(--bg-sidebar);border-radius:4px">'
                          +'<div style="font-weight:600">'+esc(it.title)+' <span style="font-size:10px;color:'+sv+'">('+esc(it.severity)+')</span></div>'
                          +'<div style="color:var(--text-secondary);font-size:11.5px">'+esc(it.suggestion)+'</div></div>';
                    });
                }
                if(o.quick_wins&&o.quick_wins.length){
                    h+='<div style="font-weight:700;margin:8px 0 4px">Hızlı Kazanımlar</div><ul style="margin:0;padding-left:18px">';
                    o.quick_wins.forEach(w=>h+='<li style="margin:2px 0">'+esc(w)+'</li>');
                    h+='</ul>';
                }
                box.innerHTML=h; box.style.display='block';
                let msg='Analiz tamam.'; if(typeof d.data.balance!=='undefined') msg+=' (Kalan kredi: '+d.data.balance+')';
                Toast.show(msg,'success',4000);
            } else { Toast.show(d.message||'Optimizasyon başarısız','error',6000); }
        })
        .catch(()=>Toast.show('Bağlantı hatası','error'))
        .finally(()=>{btn.disabled=false; btn.style.opacity=1; btn.innerHTML=orig; if(typeof lucide!=='undefined')lucide.createIcons();});
}

document.getElementById('productForm')?.addEventListener('submit',()=>updateImagesInput());
if(typeof lucide!=='undefined')lucide.createIcons();
</script>
