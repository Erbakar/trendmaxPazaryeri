<?php
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$groups=$data['groups']??[];$canApprove=$data['_can']('products.edit')&&$data['_can']('categories.edit');
$open=array_sum(array_column($groups,'unreviewed'));
?>
<style>
.ovr-box{background:var(--surface,#fff);border:1px solid var(--border,#e3e7ee);border-radius:12px;padding:20px;margin:16px 0}.ovr-table{width:100%;border-collapse:collapse}.ovr-table th,.ovr-table td{padding:12px 10px;text-align:left;vertical-align:top;border-bottom:1px solid var(--border,#e3e7ee)}.ovr-table small{display:block;color:var(--text-secondary,#65748a);margin-top:4px}.ovr-samples{margin:0;padding-left:18px;max-width:520px}.ovr-samples li{margin:2px 0}.ovr-result{display:block;margin-top:8px}.ovr-scroll{overflow-x:auto}.ovr-error{color:#b42318}
</style>
<div class="ui-page-head"><div><h1>Ürüne özel kategoriler</h1><p>Ürün adı ve açıklamasından kendi Trendyol kategorisi bulunan ürünler, hedef kategoriye göre gruplandı.</p></div><a class="ui-btn ui-btn-outline" href="/products/catalog-audit">Katalog Kontrolüne dön</a></div>
<?php if(isset($data['error'])): ?><div class="ovr-box ovr-error"><?= $e($data['error']) ?></div><?php endif; ?>
<div class="ovr-box">
<p>Bu ürünlerin panel kategorisi Trendyol'a eşlenmemiş olabilir; bu yüzden kategori ekranındaki toplu onaya girmezler. Her grubun örnek ürün adlarını okuyun: hepsi hedef kategoriye uygunsa onaylayın. Uygun olmayan ürün türü görürseniz (ör. yasal kısıtlı ürünler) o grubu onaylamayın.</p>
<p>Onay yalnız zorunlu Trendyol özellikleri tamamlanan, tedarikçi farkı veya içerik çelişkisi olmayan ürünlere yazılır. Gönderim otomatik katalog kontrolünün sonraki turlarında yapılır.</p>
<p><strong><?= number_format(count($groups),0,',','.') ?></strong> grup · <strong><?= number_format(array_sum(array_column($groups,'total')),0,',','.') ?></strong> ürün · <strong><?= number_format($open,0,',','.') ?></strong> ürün hiç incelenmedi</p>
<div class="ovr-scroll"><table class="ovr-table"><thead><tr><th>Trendyol kategorisi</th><th>Ürün</th><th>Örnek ürün adları</th><th></th></tr></thead><tbody>
<?php foreach($groups as$g): ?>
<tr><td><strong><?= $e($g['name']) ?></strong><small><?= $e($g['path']) ?></small><small>#<?= $e($g['target']) ?></small></td>
<td><?= number_format((int)$g['total'],0,',','.') ?><small><?= number_format((int)$g['unreviewed'],0,',','.') ?> incelenmedi</small></td>
<td><ul class="ovr-samples"><?php foreach($g['samples'] as$s): ?><li><?= $e($s) ?></li><?php endforeach; ?></ul></td>
<td><?php if($canApprove): ?><label><input type="checkbox" data-ovr-confirm> Örnekleri inceledim</label><br><button type="button" class="ui-btn ui-btn-primary" data-ovr-approve="<?= $e($g['target']) ?>" disabled>Grubu onayla</button><span class="ovr-result" role="status"></span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if(!$groups): ?><p>Ürüne özel kategori seçimi olan ürün yok.</p><?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-ovr-confirm]').forEach(box=>box.addEventListener('change',()=>{box.closest('td').querySelector('[data-ovr-approve]').disabled=!box.checked;}));
document.querySelectorAll('[data-ovr-approve]').forEach(button=>button.addEventListener('click',async()=>{
    const out=button.parentElement.querySelector('.ovr-result');button.disabled=true;
    const sum={reviewed:0,already:0,attribute_errors:0,individual:0};let after=0,done=0;
    try{
        for(;;){
            const body=new FormData();body.append('_csrf_token',document.querySelector('meta[name=csrf-token]').content);body.append('confirm','1');body.append('target',button.dataset.ovrApprove);body.append('after',String(after));
            const response=await fetch('/products/catalog-audit/overrides/approve',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}});
            let result=null;try{result=await response.json();}catch{throw new Error('Sunucu yanıtı okunamadı (HTTP '+response.status+').');}
            if(!response.ok||!result.success)throw new Error(result.message||'Onaylanamadı.');
            const d=result.data||{};for(const k in sum)sum[k]+=Number(d[k]||0);done+=Number(d.total||0);after=Number(d.last_id||0);
            out.textContent=done+' ürün işlendi…';
            if(!d.limited)break;
        }
        out.textContent=sum.reviewed+' onaylandı · '+sum.already+' zaten onaylı · '+sum.attribute_errors+' özellik eksik · '+sum.individual+' tek tek incelenmeli';
    }catch(err){out.textContent=err.message;out.classList.add('ovr-error');button.disabled=false;}
}));
</script>
