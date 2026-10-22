<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
extract($data,EXTR_SKIP);
$mid=(int)$mp['id']; $cid=(int)$category['id'];
$canEdit=($data['_can'])('categories.edit');
$link=static fn($p)=>'?'.http_build_query(['marketplace_id'=>$mid,'q'=>$q,'page'=>$p]);
?>
<style>
.pc-mapping-controls label{display:flex;flex-direction:column;gap:6px;min-width:0}.pc-mapping-controls .pc-target{flex:1 1 680px;max-width:100%}.pc-mapping-controls #pcTarget{width:100%;min-width:0;max-width:none}
#pcTarget{appearance:none;-webkit-appearance:none;box-sizing:border-box;padding-right:44px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:16px 16px}
</style>
<div class="page-header"><h1><?= $esc($category['name']) ?> · Ürün kategori eşleştirmeleri</h1><a href="/categories/mapping?marketplace_id=<?= $mid ?>" class="btn btn-secondary">Kategorilere dön</a></div>
<p>Aynı XML kategorisindeki ürünleri farklı Trendyol kategorilerine atayabilirsiniz. Ürün seçimi kategori varsayılanından önceliklidir ve XML güncellemelerinde korunur. Kaydetmek ürünleri Trendyol’a göndermez. Mevcut Trendyol ilanlarında fiyat/stok güncellemesi, ilan kategorisini değiştirmez.</p>
<p>Kategori varsayılanı: <strong><?= $esc($default['marketplace_category_name'] ?? 'Eşleştirilmemiş') ?></strong></p>
<form method="get" style="display:flex;gap:12px;margin:16px 0"><input type="hidden" name="marketplace_id" value="<?= $mid ?>"><input name="q" value="<?= $esc($q) ?>" class="form-input" placeholder="Ürün adı veya stok kodu ara"><button class="btn btn-secondary">Ara</button></form>
<p><?= $total ?> sonuç · Sayfa <?= $page ?> / <?= max(1,(int)ceil($total/100)) ?> · Seçim yalnız bu sayfadaki ürünleri kapsar.</p>
<?php if($canEdit): ?>
<div class="card pc-mapping-controls" style="padding:16px;display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;align-items:flex-end">
<label>Trendyol kategorisi ara <input type="search" id="pcSearch" class="form-input" placeholder="Örn. termos, ayna"></label>
<label class="pc-target">Hedef kategori <select id="pcTarget" class="form-input"><option value="">Arama yapın ve kategori seçin</option></select></label>
<button type="button" id="pcSave" class="btn btn-primary">Seçilenlere kaydet</button><button type="button" id="pcReset" class="btn btn-secondary">Seçilenleri varsayılana döndür</button>
</div>
<?php endif; ?>
<p id="pcStatus" role="status" aria-live="polite"></p>
<div style="overflow:auto"><table class="table"><thead><tr><th><input type="checkbox" id="pcAll" aria-label="Bu sayfadaki tüm ürünleri seç"></th><th>Ürün</th><th>Geçerli Trendyol kategorisi</th><th>Özellikler</th></tr></thead><tbody>
<?php foreach($products as $p): ?>
<tr><td><input type="checkbox" class="pcItem" value="<?= (int)$p['id'] ?>" aria-label="<?= $esc($p['sku']) ?> seç"></td><td><a href="/products/<?= (int)$p['id'] ?>"><?= $esc($p['sku']) ?></a><br><?= $esc($p['name']) ?></td><td><?= $esc($p['marketplace_category_path'] ?: ($p['marketplace_category_name'] ?: ($default['marketplace_category_name'] ?? 'Eşleştirilmemiş'))) ?><br><small><?= $p['override_id']?'Ürüne özel seçim':'Kategori varsayılanı' ?></small></td><td>
<?php if($p['override_id']): ?><button type="button" class="btn btn-secondary" data-tya-open="product-<?= (int)$p['id'] ?>-<?= $mid ?>">Özellikler ve kontrol</button>
<?php elseif($default): ?><button type="button" class="btn btn-secondary" data-tya-open="<?= (int)$default['id'] ?>" data-tya-product="<?= (int)$p['id'] ?>">Özellikler ve kontrol</button><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div style="display:flex;gap:16px;margin:20px 0"><?php if($page>1): ?><a href="<?= $esc($link($page-1)) ?>">← Önceki</a><?php endif; ?><?php if($page*100<$total): ?><a href="<?= $esc($link($page+1)) ?>">Sonraki →</a><?php endif; ?></div>
<script>
(()=>{
 const status=document.getElementById('pcStatus'),items=[...document.querySelectorAll('.pcItem')],all=document.getElementById('pcAll');
 const selected=()=>items.filter(i=>i.checked).map(i=>Number(i.value));
 const count=()=>{status.textContent=selected().length+' ürün seçildi.';all.checked=items.length>0&&items.every(i=>i.checked);all.indeterminate=items.some(i=>i.checked)&&!all.checked;};
 all.addEventListener('change',()=>{items.forEach(i=>i.checked=all.checked);count();});items.forEach(i=>i.addEventListener('change',count));
 const search=document.getElementById('pcSearch'),target=document.getElementById('pcTarget');if(!search)return;
 let generation=0,timer;
 search.addEventListener('input',()=>{const seq=++generation;target.replaceChildren(new Option('Aranıyor…',''));clearTimeout(timer);timer=setTimeout(async()=>{try{const r=await fetch('/categories/mapping/search?'+new URLSearchParams({marketplace_id:<?= $mid ?>,q:search.value}),{headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json();if(seq!==generation)return;if(!r.ok||!j.success)throw Error(j.message||'Arama başarısız.');target.replaceChildren(new Option('— Kategori seçin —',''),...j.data.categories.map(c=>new Option(c.path||c.name,c.id)));if(!j.data.categories.length)status.textContent='Sonuç yok. En az iki harfle arayın.';}catch(e){if(seq===generation)status.textContent=e.message;}},300);});
 async function save(reset){const ids=selected();if(!ids.length){status.textContent='En az bir ürün seçin.';return;}if(!reset&&!target.value){status.textContent='Trendyol kategorisini seçin.';return;}
 const buttons=[document.getElementById('pcSave'),document.getElementById('pcReset')];buttons.forEach(b=>b.disabled=true);
 const body=new FormData();body.set('_csrf_token',document.querySelector('meta[name=csrf-token]').content);body.set('marketplace_id',<?= $mid ?>);body.set('product_ids',JSON.stringify(ids));body.set('target',target.value);body.set('reset',reset?'1':'0');
 try{const r=await fetch(location.pathname,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}}),j=await r.json();if(!r.ok||!j.success)throw Error(j.message||'Kaydedilemedi.');location.reload();}catch(e){status.textContent=e.message;buttons.forEach(b=>b.disabled=false);}}
 document.getElementById('pcSave').addEventListener('click',()=>save(false));document.getElementById('pcReset').addEventListener('click',()=>save(true));
})();
</script>
<?php require __DIR__.'/trendyol_attributes.php'; ?>
