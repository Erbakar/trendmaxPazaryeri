<style>
#tyaDialog{position:fixed;inset:0;margin:auto;box-sizing:border-box;overflow:auto;width:min(1400px,95vw);max-height:90vh;padding:0;border:1px solid var(--border);border-radius:14px;background:var(--bg-card,#fff);color:var(--text-primary);box-shadow:0 20px 80px #0003}
#tyaDialog::backdrop{background:#0f172a88}.tya-head,.tya-toolbar,.tya-footer{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap}.tya-head{justify-content:space-between}.tya-head h2{margin:0;font-size:19px}.tya-body{padding:16px 20px}.tya-body p{font-size:13px;color:var(--text-muted)}.tya-table{width:100%;border-collapse:collapse;font-size:13px}.tya-table th,.tya-table td{padding:10px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}.tya-table select,.tya-table input{width:100%;min-width:150px;padding:8px;border:1px solid var(--border);border-radius:7px;background:var(--bg-input,#fff);color:inherit}.tya-required{color:#c2410c;font-size:11px;display:block;margin-top:4px}.tya-row[hidden]{display:none}.tya-status{white-space:pre-wrap;font-size:13px;padding:12px 20px;line-height:1.6}.tya-status:empty{display:none}.tya-status.is-error{color:#b91c1c;background:#fef2f2}.tya-status.is-ok{color:#166534;background:#f0fdf4}.tya-footer{position:sticky;bottom:0;background:var(--bg-card,#fff);border-top:1px solid var(--border);border-bottom:0}.tya-preview{margin:0 20px 16px;padding:12px;background:var(--bg-input);border-radius:8px;max-height:300px;overflow:auto;white-space:pre-wrap;font-size:12px}.tya-table-wrap{overflow:auto}#tyaProducts{width:min(760px,100%);max-width:100%}.tya-toolbar label{min-width:0;max-width:100%}.tya-table{min-width:1000px;table-layout:fixed}.tya-table th:first-child{width:24%}.tya-table th:nth-child(2),.tya-table th:nth-child(3){width:38%}
.tya-table select:not([multiple]),#tyaScope,#tyaProducts{appearance:none;-webkit-appearance:none;box-sizing:border-box;padding-right:44px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:16px 16px}
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
    <div class="tya-body tya-table-wrap"><table class="tya-table"><thead><tr><th>Trendyol özelliği</th><th>Üründeki kaynak alan</th><th>Sabit / varsayılan değer</th></tr></thead><tbody id="tyaRows"></tbody></table></div>
    <details id="tyaPreviewBox" hidden><summary style="padding:12px 20px">Gönderilecek değerler ve kontrol sonucu</summary><pre id="tyaPreview" class="tya-preview"></pre></details>
    <div class="tya-footer"><button type="button" class="btn btn-primary" id="tyaSave">Eşleştirmeleri Kaydet</button><button type="button" class="btn btn-secondary" id="tyaCheck">Gönderimi Kontrol Et</button><small>Kontrol işlemi Trendyol’a ürün göndermez.</small></div>
</dialog>
<script>
(function(){
    const dialog=document.getElementById('tyaDialog'), rows=document.getElementById('tyaRows'), status=document.getElementById('tyaStatus');
    const products=document.getElementById('tyaProducts'), scope=document.getElementById('tyaScope'), search=document.getElementById('tyaSearch');
    const save=document.getElementById('tyaSave'), check=document.getElementById('tyaCheck');
    let mappingId=0, data=null, generation=0, busy=false, dirty=false;
    const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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
            return '<tr class="tya-row" data-required="'+(a.required?'1':'0')+'"><td><b>'+esc(a.name)+'</b>'+(a.required?'<span class="tya-required">Zorunlu</span>':'<small>İsteğe bağlı</small>')+'</td><td>'+source+'</td><td>'+field+'</td></tr>';
        }).join('');
        applyFilter(); save.disabled=!data.can_edit || (productMode&&!products.value); dirty=false;
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
    rows.addEventListener('change',()=>{dirty=true;document.getElementById('tyaPreviewBox').hidden=true;});
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
        const body=new FormData();body.append('_csrf_token',document.querySelector('meta[name=csrf-token]').content);body.append('category_target',data.mapping.target);body.append('values',JSON.stringify(values));body.append('sources',JSON.stringify(sources));body.append('product_id',scope.value==='product'?products.value:'0');
        try{await request('/categories/mapping/'+mappingId+'/trendyol-attributes',{method:'POST',body});dirty=false;message('Eşleştirmeler kaydedildi. Şimdi seçili ürünün gönderimini kontrol edebilirsiniz.');}
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
})();
</script>
