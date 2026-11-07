<style>
#tyaDialog{position:fixed;inset:0;margin:auto;box-sizing:border-box;overflow:auto;width:min(1400px,95vw);max-height:90vh;padding:0;border:1px solid var(--border);border-radius:14px;background:var(--bg-card,#fff);color:var(--text-primary);box-shadow:0 20px 80px #0003}
#tyaDialog::backdrop{background:#0f172a88}.tya-head,.tya-toolbar,.tya-footer{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap}.tya-head{justify-content:space-between}.tya-head h2{margin:0;font-size:19px}.tya-body{padding:16px 20px}.tya-body p{font-size:13px;color:var(--text-muted)}.tya-table{width:100%;border-collapse:collapse;font-size:13px}.tya-table th,.tya-table td{padding:10px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}.tya-table select,.tya-table input{width:100%;min-width:150px;padding:8px;border:1px solid var(--border);border-radius:7px;background:var(--bg-input,#fff);color:inherit}.tya-required{color:#c2410c;font-size:11px;display:block;margin-top:4px}.tya-row[hidden]{display:none}.tya-status{white-space:pre-wrap;font-size:13px;padding:12px 20px;line-height:1.6}.tya-status:empty{display:none}.tya-status.is-error{color:#b91c1c;background:#fef2f2}.tya-status.is-ok{color:#166534;background:#f0fdf4}.tya-footer{position:sticky;bottom:0;background:var(--bg-card,#fff);border-top:1px solid var(--border);border-bottom:0}.tya-preview{margin:0 20px 16px;padding:12px;background:var(--bg-input);border-radius:8px;max-height:300px;overflow:auto;white-space:pre-wrap;font-size:12px}.tya-table-wrap{overflow:auto}#tyaProducts{width:min(760px,100%);max-width:100%}.tya-toolbar label{min-width:0;max-width:100%}.tya-table{min-width:1000px;table-layout:fixed}.tya-table th:first-child{width:24%}.tya-table th:nth-child(2),.tya-table th:nth-child(3){width:38%}
.tya-table select:not([multiple]),#tyaScope,#tyaProducts{appearance:none;-webkit-appearance:none;box-sizing:border-box;padding-right:44px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:16px 16px}
#tyaIssues{margin:0 20px;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.6;background:var(--warning-bg,#fffbeb);border:1px solid rgba(245,158,11,.3)}#tyaIssues[hidden]{display:none}#tyaIssues.is-ok{background:#f0fdf4;border-color:#bbf7d0}#tyaIssues ul{margin:6px 0 0 18px;padding:0}
.tya-values{margin-top:8px}.tya-values summary{cursor:pointer;font-size:12px;color:var(--primary)}.tya-values table{width:100%;margin-top:6px;border-collapse:collapse;font-size:12px}.tya-values td{padding:4px 6px;border-bottom:1px dashed var(--border)}.tya-values td:nth-child(2){width:70px;color:var(--text-muted)}.tya-values select{min-width:0!important;padding:4px 8px!important}.tya-badge{display:inline-block;margin-left:6px;padding:0 6px;border-radius:6px;font-size:10.5px;line-height:18px;background:#fee2e2;color:#991b1b}.tya-variant{display:block;margin-top:4px;font-size:11px;color:#92400e}
.tya-review{display:flex;flex-wrap:wrap;align-items:center;gap:10px;width:100%;padding-top:10px;border-top:1px dashed var(--border);font-size:12.5px}.tya-review[hidden]{display:none}
</style>
<dialog id="tyaDialog" aria-labelledby="tyaTitle">
    <div class="tya-head"><div><h2 id="tyaTitle">Trendyol · Özellik Eşleştirme</h2><small id="tyaCategory"></small></div><button type="button" class="btn btn-secondary" id="tyaClose">Kapat</button></div>
    <div class="tya-body"><p>Trendyol özelliğinin hangi ürün alanından alınacağını veya hangi sabit değerin gönderileceğini seçin. Zorunlu alanlar tamamlanmadan ürün gönderilmez. Ürün değeri, eşlenen ürün alanı ve kategori varsayılanı sırasıyla kullanılır.</p></div>
    <div class="tya-toolbar">
        <label>Kayıt kapsamı <select id="tyaScope" class="form-input"><option value="category">Bu kategorideki tüm ürünler</option><option value="product">Yalnız seçili ürün</option></select></label>
        <label>Ürün ara <input type="search" id="tyaSearch" class="form-input" placeholder="SKU veya ürün adı"></label>
        <label>Kontrol edilecek ürün <select id="tyaProducts" class="form-input"></select></label>
        <label><input type="checkbox" id="tyaRequired" checked> Yalnız zorunlu özellikler</label>
        <button type="button" class="btn btn-secondary" id="tyaRefresh">Trendyol listesini yenile</button>
    </div>
    <div id="tyaStatus" class="tya-status" role="status" aria-live="polite"></div>
    <div id="tyaIssues" hidden></div>
    <div class="tya-body tya-table-wrap"><table class="tya-table"><thead><tr><th>Trendyol özelliği</th><th>Üründeki kaynak alan</th><th>Sabit / varsayılan değer</th></tr></thead><tbody id="tyaRows"></tbody></table></div>
    <details id="tyaPreviewBox" hidden><summary style="padding:12px 20px">Gönderilecek değerler ve kontrol sonucu</summary><pre id="tyaPreview" class="tya-preview"></pre></details>
    <div class="tya-footer"><button type="button" class="btn btn-primary" id="tyaSave">Eşleştirmeleri Kaydet</button><button type="button" class="btn btn-secondary" id="tyaCheck">Gönderimi Kontrol Et</button><small>Kontrol işlemi Trendyol’a ürün göndermez.</small>
        <div class="tya-review" id="tyaReviewBox" hidden><label><input type="checkbox" id="tyaReviewConfirm"> Bu kategorinin Trendyol kategorisini ve kaydedilen özellik değerlerini inceledim; kategorideki ürünlere uygundur.</label><button type="button" class="btn btn-secondary" id="tyaReview">Kategorideki ürünlerin içeriğini onayla</button><small>Otomatik katalog kontrolü açıksa özellikleri tamamlanan ürünler bir sonraki turda gönderilir. Görsel, stok ve fiyat kontrolleri yine yapılır.</small></div>
    </div>
</dialog>
<script>
(function(){
    const dialog=document.getElementById('tyaDialog'), rows=document.getElementById('tyaRows'), status=document.getElementById('tyaStatus');
    const products=document.getElementById('tyaProducts'), scope=document.getElementById('tyaScope'), search=document.getElementById('tyaSearch');
    const save=document.getElementById('tyaSave'), check=document.getElementById('tyaCheck');
    let mappingId=0, data=null, generation=0, busy=false, dirty=false;
    const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const norm=v=>String(v==null?'':v).trim().toLowerCase().replace(/i̇/g,'i').replace(/[ıöüşçğ]/g,c=>({'ı':'i','ö':'o','ü':'u','ş':'s','ç':'c','ğ':'g'}[c])).replace(/[^a-z0-9]/g,'');
    function localFor(a){const chosen=rows.querySelector('[data-tya-source="'+a.id+'"]');const id=chosen&&chosen.value?chosen.value:String(data.sources[a.id]||'');if(id)return id;const same=data.local_attributes.find(p=>norm(p.name)===norm(a.name));return same?String(same.id):'';}
    function valuesHtml(a){
        if(a.allowCustom||scope.value==='product')return '';
        const local=localFor(a);const list=(data.local_values||{})[local]||[];if(!list.length)return '';
        const options=(a.values||[]).map(v=>({id:String(v.id),name:v.name}));const names=new Set(options.map(v=>norm(v.name)));
        const map=(data.value_map||{})[a.id]||{};
        const rowsHtml=list.filter(v=>!names.has(norm(v.value))||map[norm(v.value)]).map(v=>{const key=norm(v.value),sel=map[key]||'';return '<tr><td>'+esc(v.value)+'</td><td>'+v.products+' ürün</td><td><select data-tya-map="'+a.id+'" data-tya-raw="'+esc(v.value)+'" aria-label="'+esc(v.value)+' için Trendyol seçeneği"><option value="">— Eşleştirilmedi —</option>'+options.map(o=>'<option value="'+esc(o.id)+'" '+(o.id===String(sel)?'selected':'')+'>'+esc(o.name)+'</option>').join('')+'</select></td></tr>';}).join('');
        if(!rowsHtml)return '<small class="tya-sub">Ürünlerdeki '+list.length+' farklı değerin tamamı Trendyol seçenekleriyle eşleşiyor.</small>';
        const open=list.some(v=>!names.has(norm(v.value))&&!map[norm(v.value)]);
        return '<details class="tya-values" '+(open?'open':'')+'><summary>XML değerlerini eşleştir ('+list.filter(v=>!names.has(norm(v.value))&&!map[norm(v.value)]).length+' eşleşmedi)</summary><table>'+rowsHtml+'</table></details>';
    }
    function issuesHtml(){
        const box=document.getElementById('tyaIssues'),i=data.issues;
        if(!i||scope.value==='product'||String(mappingId).startsWith('product-')&&!i.refreshed_at){box.hidden=true;return;}
        box.hidden=false;
        if(!i.refreshed_at){box.className='';box.innerHTML='Bu kategori için zorunlu özellik raporu henüz hesaplanmadı. Eşleştirmeleri kaydettiğinizde hesaplanır.';return;}
        if(!i.blocked&&!i.errors.length){box.className='is-ok';box.innerHTML='Bu kategoride Trendyol’a gönderilmeyi bekleyen '+i.checked+' ürünün zorunlu özellikleri tamam. <small>('+esc(i.refreshed_at)+')</small>';return;}
        box.className='';
        box.innerHTML='<b>'+i.blocked+' / '+i.checked+' ürün</b> zorunlu özellik nedeniyle gönderilemiyor. <small>('+esc(i.refreshed_at)+')</small><ul>'
            +i.errors.map(e=>'<li>'+esc(e.message)+'</li>').join('')
            +i.missing.map(m=>'<li>'+esc(m.attribute_name)+': '+m.product_count+' üründe değer yok'+(m.is_varianter?' · varyant özelliği':'')+'</li>').join('')
            +i.unmatched.map(u=>'<li>'+esc(u.attribute_name)+' = “'+esc(u.value_text)+'”: '+u.product_count+' üründe Trendyol seçeneği eşleşmedi</li>').join('')+'</ul>';
    }
    function message(text,error=false){status.textContent=text;status.className='tya-status '+(error?'is-error':'is-ok');}
    async function request(url,options={}){const response=await fetch(url,{...options,headers:{'X-Requested-With':'XMLHttpRequest',...(options.headers||{})}});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'İşlem tamamlanamadı.');return result.data;}
    function applyFilter(){rows.querySelectorAll('tr').forEach(row=>row.hidden=document.getElementById('tyaRequired').checked && row.dataset.required!=='1');}
    function render(){
        const productMode=scope.value==='product', own=String(mappingId).startsWith('product-');
        const values=productMode ? data.product_values : data.defaults;
        rows.innerHTML=data.attributes.map(a=>{
            const selected=values[a.id]||'';
            const options=(a.values||[]).map(v=>({id:String(v.id),name:v.name}));
            let field='';
            if(a.allowCustom) field='<input data-tya-value="'+a.id+'" aria-label="'+esc(a.name)+' değeri" maxlength="1000" value="'+esc(selected)+'" placeholder="'+(productMode&&!own?'Kategori değerini kullan':'Değer girin')+'">';
            else {
                let ids=a.allowMultipleAttributeValues ? (()=>{try{const x=JSON.parse(selected);return Array.isArray(x)?x:[selected];}catch{return [selected];}})() : [selected];
                ids=ids.map(String);
                field='<select data-tya-value="'+a.id+'" aria-label="'+esc(a.name)+' değeri" '+(a.allowMultipleAttributeValues?'multiple size="4"':'')+'><option value="">'+(productMode&&!own?'Kategori değerini kullan':'— Seçiniz —')+'</option>'+options.map(v=>'<option value="'+esc(v.id)+'" '+(ids.includes(v.id)?'selected':'')+'>'+esc(v.name)+'</option>').join('')+'</select>';
                if(selected && !a.allowMultipleAttributeValues && !options.some(v=>v.id===String(selected))) field+='<small class="tya-required">Kayıtlı değer güncel listede yok; yeniden seçin.</small>';
            }
            const source='<select data-tya-source="'+a.id+'" aria-label="'+esc(a.name)+' kaynak alanı" '+(productMode&&!own?'disabled':'')+'><option value="">Sabit değer / aynı adlı ürün özelliği</option>'+data.local_attributes.map(p=>'<option value="'+p.id+'" '+(String(data.sources[a.id]||'')===String(p.id)?'selected':'')+'>'+esc(p.name)+'</option>').join('')+'</select>';
            const issue=(data.issues&&!productMode)?((data.issues.missing||[]).filter(m=>String(m.attribute_id)===String(a.id)).reduce((n,m)=>n+m.product_count,0)+(data.issues.unmatched||[]).filter(u=>String(u.attribute_id)===String(a.id)).reduce((n,u)=>n+u.product_count,0)):0;
            const variant=(a.varianter||a.slicer)&&!productMode?'<span class="tya-variant">Varyant özelliği: sabit değer kategorideki tüm ürünlere aynı '+esc(a.name)+' yazar. Ürün alanından eşleştirmeniz önerilir.</span>':'';
            return '<tr class="tya-row" data-required="'+(a.required?'1':'0')+'"><td><b>'+esc(a.name)+'</b>'+(issue?'<span class="tya-badge">'+issue+' ürün</span>':'')+(a.required?'<span class="tya-required">Zorunlu</span>':'<small>İsteğe bağlı</small>')+variant+'</td><td>'+source+'<div data-tya-values="'+a.id+'">'+valuesHtml(a)+'</div></td><td>'+field+'</td></tr>';
        }).join('');
        applyFilter(); issuesHtml(); save.disabled=!data.can_edit || (productMode&&!products.value); dirty=false;
        document.getElementById('tyaReviewBox').hidden=!data.can_review||productMode;document.getElementById('tyaReviewConfirm').checked=false;
    }
    async function load(refresh=false){
        const current=++generation;rows.innerHTML='';save.disabled=true;check.disabled=true;busy=true;
        document.getElementById('tyaPreviewBox').hidden=true; message('Trendyol özellikleri ve seçenekleri alınıyor…');
        const selected=products.value;
        try {
            const params=new URLSearchParams({q:search.value});
            if(refresh)params.set('refresh','1');
            if(scope.value==='product'&&selected)params.set('product_id',selected);
            const result=await request('/categories/mapping/'+mappingId+'/trendyol-attributes?'+params);
            if(current!==generation)return;
            data=result;document.getElementById('tyaCategory').textContent=result.mapping.name+(result.source==='fixture'?' · Yerel test verisi — canlı Trendyol listesi değildir':'');
            products.innerHTML=result.products.map(p=>'<option value="'+p.id+'">'+esc(p.sku+' · '+p.name)+'</option>').join('');
            if(selected && result.products.some(p=>String(p.id)===selected))products.value=selected;
            if(scope.value==='product' && products.value && products.value!==selected){busy=false;await load();return;}
            render(); check.disabled=!products.value;
            message((result.source==='fixture'?'Yerel test seçenekleri. ':'')+result.attributes.filter(a=>a.required).length+' zorunlu özellik. '+(result.more_products?'İlk 100 ürün gösteriliyor; arama yaparak daraltın.':''));
        }catch(error){if(current===generation)message(error.message,true);}finally{if(current===generation)busy=false;}
    }
    document.addEventListener('click',event=>{const button=event.target.closest('[data-tya-open]');if(!button)return;mappingId=button.dataset.tyaOpen;const own=String(mappingId).startsWith('product-');const pid=own?String(mappingId).split('-')[1]:(button.dataset.tyaProduct||'');scope.value=pid?'product':'category';scope.disabled=own;search.disabled=own;search.value='';products.innerHTML=pid?'<option value="'+pid+'">Seçili ürün</option>':'';dialog.showModal();load();});
    document.getElementById('tyaClose').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{generation++;data=null;dirty=false;});
    document.getElementById('tyaRequired').addEventListener('change',applyFilter);
    rows.addEventListener('change',event=>{dirty=true;document.getElementById('tyaPreviewBox').hidden=true;
        const src=event.target.closest('[data-tya-source]');if(src&&data){const a=data.attributes.find(x=>String(x.id)===src.dataset.tyaSource);const box=rows.querySelector('[data-tya-values="'+src.dataset.tyaSource+'"]');if(a&&box)box.innerHTML=valuesHtml(a);}});
    function discard(){return !dirty || window.confirm('Kaydedilmemiş eşleştirmeler var. Değişiklikleri bırakmak istiyor musunuz?');}
    scope.addEventListener('change',()=>{if(discard())load();else scope.value=scope.value==='category'?'product':'category';});
    products.addEventListener('change',()=>{if(scope.value==='product'){if(discard())load();}else document.getElementById('tyaPreviewBox').hidden=true;});
    let timer;search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{if(!dirty)load();else message('Aramadan önce eşleştirmeleri kaydedin.',true);},350);});
    document.getElementById('tyaRefresh').addEventListener('click',()=>{if(discard())load(true);});
    save.addEventListener('click',async()=>{
        if(busy||!data)return;busy=true;save.disabled=true;check.disabled=true;
        const values={},sources={};
        rows.querySelectorAll('[data-tya-value]').forEach(field=>{let value=field.multiple?JSON.stringify(Array.from(field.selectedOptions).map(o=>o.value).filter(Boolean)):field.value.trim();if(value && value!=='[]')values[field.dataset.tyaValue]=value;});
        rows.querySelectorAll('[data-tya-source]').forEach(field=>{if(field.value)sources[field.dataset.tyaSource]=field.value;});
        // Keep mappings for values not shown now (e.g. another source field) and replace the visible ones.
        const valueMap=JSON.parse(JSON.stringify(data.value_map||{}));
        rows.querySelectorAll('[data-tya-map]').forEach(field=>{const aid=field.dataset.tyaMap,key=norm(field.dataset.tyaRaw);valueMap[aid]=valueMap[aid]||{};if(field.value)valueMap[aid][key]=field.value;else delete valueMap[aid][key];});
        Object.keys(valueMap).forEach(aid=>{if(!Object.keys(valueMap[aid]).length)delete valueMap[aid];});
        const body=new FormData();body.append('_csrf_token',document.querySelector('meta[name=csrf-token]').content);body.append('category_target',data.mapping.target);body.append('values',JSON.stringify(values));body.append('sources',JSON.stringify(sources));if(scope.value!=='product')body.append('value_map',JSON.stringify(valueMap));body.append('product_id',scope.value==='product'?products.value:'0');
        try{const saved=await request('/categories/mapping/'+mappingId+'/trendyol-attributes',{method:'POST',body});dirty=false;data.value_map=scope.value!=='product'?valueMap:data.value_map;if(saved&&saved.issues){data.issues=saved.issues;issuesHtml();}message('Eşleştirmeler kaydedildi. Şimdi seçili ürünün gönderimini kontrol edebilirsiniz.');}
        catch(error){message(error.message,true);}finally{busy=false;save.disabled=false;check.disabled=!products.value;}
    });
    check.addEventListener('click',async()=>{
        if(busy||!products.value)return;if(dirty){message('Kontrol etmeden önce eşleştirmeleri kaydedin.',true);return;}busy=true;check.disabled=true;
        try{
            const result=await request('/categories/mapping/'+mappingId+'/trendyol-preview?product_id='+encodeURIComponent(products.value));
            const errors=result.preview.errors||[];
            message(errors.length?'Gönderim engelleri:\n'+errors.join('\n'):'Gönderim verisi hazır. Trendyol’a henüz gönderilmedi.',errors.length>0);
            const box=document.getElementById('tyaPreviewBox');box.hidden=false;box.open=true;
            document.getElementById('tyaPreview').textContent=JSON.stringify({kategori:result.category,ozellik_eslemeleri:result.resolution.details,gonderilecek_urun:result.preview.item},null,2);
        }catch(error){message(error.message,true);}finally{busy=false;check.disabled=false;}
    });
    document.getElementById('tyaReview').addEventListener('click',async()=>{
        if(busy||!data)return;if(dirty){message('Onaylamadan önce eşleştirmeleri kaydedin.',true);return;}
        if(!document.getElementById('tyaReviewConfirm').checked){message('Onay kutusunu işaretleyin.',true);return;}
        busy=true;const button=document.getElementById('tyaReview');button.disabled=true;message('Kategorideki ürünler kontrol ediliyor…');
        const body=new FormData();body.append('_csrf_token',document.querySelector('meta[name=csrf-token]').content);body.append('confirm','1');
        try{const response=await fetch('/categories/mapping/'+mappingId+'/trendyol-review',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Onaylanamadı.');message(result.message);}
        catch(error){message(error.message,true);}finally{busy=false;button.disabled=false;document.getElementById('tyaReviewConfirm').checked=false;}
    });
})();
</script>
