<?php
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$labels=['ready'=>'Gönderime hazır','verified'=>'Doğrulandı','needs_review'=>'Eksik / inceleme gerekli','unavailable'=>'XML stoğu kapalı','close_required'=>'Stok kapatma bekliyor','sync_required'=>'Stok / fiyat farkı','pending_approval'=>'Trendyol onayında','rejected'=>'Trendyol reddetti','awaiting_result'=>'Gönderim sonucu bekleniyor','check_failed'=>'Kontrol hatası','not_observed'=>'Bu turda bulunamadı'];
$report=$data['report']??[];$config=$data['config']??[];$run=$report['run']??null;$status=$data['status']??'';$search=$data['search']??'';
?>
<style>
.catalog-box{background:var(--surface,#fff);border:1px solid var(--border,#e3e7ee);border-radius:12px;padding:20px;margin:16px 0}.catalog-controls{display:flex;gap:20px;align-items:center;flex-wrap:wrap}.catalog-summary{display:flex;gap:10px;flex-wrap:wrap}.catalog-summary a{padding:10px 14px;border:1px solid var(--border,#e3e7ee);border-radius:8px;text-decoration:none;color:inherit}.catalog-summary strong{display:block;font-size:20px}.catalog-table{width:100%;border-collapse:collapse}.catalog-table th,.catalog-table td{padding:14px 10px;text-align:left;vertical-align:top;border-bottom:1px solid var(--border,#e3e7ee)}.catalog-table td{min-width:120px}.catalog-table small{display:block;color:var(--text-secondary,#65748a);margin:5px 0}.catalog-photos{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.catalog-photos img{width:100px;height:100px;object-fit:contain;border:1px solid var(--border,#e3e7ee);border-radius:8px;background:#fff}.catalog-note{color:var(--text-secondary,#65748a);line-height:1.6}.catalog-error{color:#b42318}.catalog-table details{min-width:260px;max-width:600px}.catalog-table li{margin:5px 0}.catalog-scroll{overflow-x:auto}
</style>
<div class="ui-page-head"><div><h1>Katalog Kontrolü</h1><p>XML, panel ve eski Trendyol ilanları için tek kontrol raporu.</p></div><a class="ui-btn ui-btn-outline" href="/products">Ürünlere dön</a></div>
<?php if(isset($data['error'])): ?><div class="catalog-box catalog-error"><?= $e($data['error']) ?></div><?php return; endif; ?>
<div class="catalog-box">
<?php if($data['_can']('products.edit')): ?>
<form action="/products/catalog-audit/configure" method="post" class="catalog-controls">
<input type="hidden" name="_csrf_token" value="<?= $e($data['_csrf']) ?>">
<label><input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled'])?'checked':'' ?>> Tüm kataloğu otomatik kontrol et</label>
<label><input type="checkbox" name="publish" value="1" <?= !empty($config['publish'])?'checked':'' ?>> Doğrulananları otomatik gönder / eşitle</label>
<button class="ui-btn ui-btn-primary" type="submit">Kaydet ve kontrol et</button>
</form>
<?php endif; ?>
<p class="catalog-note">Kontrol sunucuda devam eder; bu sayfayı açık tutmanız gerekmez. Her tam turdan 15 dakika sonra yeni kontrol başlar. Fiyatlar mağaza ve XML profilinizde kayıtlı kâr ve kargo kurallarından hesaplanır. Kategori veya görsel için tahmini bilgi kullanılmaz. İçerik değişirse önceki inceleme onayı geçersiz olur.</p>
<p>Son sunucu çalışması: <strong><?= $e($config['heartbeat']??'Henüz çalışmadı') ?></strong>
<?php if(!empty($config['enabled'])&&(!$config['heartbeat']||strtotime($config['heartbeat'])<time()-180)): ?><span class="catalog-error"> · Zamanlanmış işin çalışması henüz doğrulanmadı.</span><?php endif; ?></p>
<?php if($run): ?><p>Son tur: <strong><?= $e(['preparing'=>'Kaynaklar okunuyor','running'=>'Kontrol sürüyor','complete'=>'Tamamlandı','failed'=>'Kaynak okuma hatası'][$run['status']]??$run['status']) ?></strong> · <?= number_format((int)$run['cursor_pos'],0,',','.') ?> / <?= number_format((int)$run['total'],0,',','.') ?> kayıt · <?= $e($run['updated_at']) ?></p><?php if($run['error_text']): ?><p class="catalog-error"><?= $e($run['error_text']) ?></p><?php endif; endif; ?>
<p class="catalog-note">Aşağıdaki sayılar son kontrolü bulunan kayıtları gösterir. Devam eden turda bazı kayıtların kontrol tarihi daha eski olabilir. “Gönderim sonucu bekleniyor” ve “Trendyol onayında”, satışa açıldığı anlamına gelmez. Stok ve fiyat doğrulaması satırdaki kontrol zamanına aittir.</p>
</div>
<div class="catalog-summary"><a href="/products/catalog-audit"><strong><?= number_format(array_sum($report['counts']),0,',','.') ?></strong>Tümü</a><?php foreach($report['counts'] as$key=>$count): ?><a href="?status=<?= urlencode($key) ?>"><strong><?= number_format((int)$count,0,',','.') ?></strong><?= $e($labels[$key]??$key) ?></a><?php endforeach; ?></div>
<div class="catalog-box"><form method="get" class="catalog-controls"><input class="form-control" style="max-width:360px" name="q" value="<?= $e($search) ?>" placeholder="Ürün, stok kodu veya eksik bilgi"><select class="form-control" style="max-width:300px" name="status"><option value="">Tüm durumlar</option><?php foreach($labels as$key=>$label): ?><option value="<?= $e($key) ?>" <?= $status===$key?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select><button class="ui-btn ui-btn-outline">Filtrele</button><a href="/products/catalog-audit">Yenile</a></form></div>
<div class="catalog-box catalog-scroll"><table class="catalog-table"><thead><tr><th>Ürün / ilan</th><th>Durum</th><th>Beklenen / Trendyol</th><th>Kontrol ve eksikler</th></tr></thead><tbody>
<?php foreach($report['items'] as$row): $checks=json_decode($row['checks_json'],true)?:[];$expected=json_decode($row['expected_json']??'null',true);$remote=json_decode($row['remote_json']??'null',true); ?>
<tr><td><strong><?= $e($row['name']) ?></strong><small><?= $e($row['sku']) ?> · <?= $e($row['barcode']) ?></small><?php if($row['product_id']): ?><a href="/products/<?= (int)$row['product_id'] ?>/edit" target="_blank" rel="noopener">Ürünü düzenle</a><?php endif; ?></td>
<td><?= $e($labels[$row['status']]??$row['status']) ?><small>Kontrol: <?= $e($row['checked_at']) ?></small></td>
<td><?php if($expected): ?>Stok: <?= $e($expected['quantity']??'—') ?> / <?= $e($remote['quantity']??'—') ?><br>Fiyat: <?= isset($expected['salePrice'])?number_format($expected['salePrice'],2,',','.').' TL':'—' ?> / <?= isset($remote['salePrice'])?number_format($remote['salePrice'],2,',','.').' TL':'—' ?><?php else: ?>Doğrulanamadı<?php endif; ?></td>
<td><details><summary><?= count($checks['errors']??[]) ?> eksik / uyarı · Ayrıntıları göster</summary>
<p>Kategori: <?= $e($checks['category_name']??'') ?> (<?= $e($checks['category_id']??'Eşleşme yok') ?>)</p>
<?php if($remote): ?><p>Trendyol satış durumu: <?= $remote['onSale']?'Satışta':'Satışta değil' ?></p><?php endif; ?>
<p>İçerik incelemesi: <?= !empty($checks['reviewed'])?'Kayıtlı onay var':'Onay gerekli' ?></p>
<small>XML okuması: <?= $e($checks['xml_at']??'—') ?> · Trendyol okuması: <?= $e($checks['remote_at']??'—') ?></small>
<?php if(isset($checks['stock_reason'])): ?><p><?= $e($checks['stock_reason']) ?></p><?php endif; ?>
<ul><?php foreach(array_merge($checks['errors']??[],$checks['differences']??[])as$error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
<?php if(!empty($remote['reasons'])): ?><pre style="white-space:pre-wrap"><?= $e(json_encode($remote['reasons'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre><?php endif; ?>
<div class="catalog-photos"><?php foreach($checks['images']??[]as$url): if(!preg_match('~^https?://~i',$url))continue; ?><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><img loading="lazy" referrerpolicy="no-referrer" src="<?= $e($url) ?>" alt="Ürün görseli"></a><?php endforeach; ?></div>
<?php if(empty($checks['reviewed'])&&!empty($checks['category_id'])&&$row['product_id']&&$data['_can']('products.edit')): ?>
<form action="/products/catalog-audit/approve" method="post"><input type="hidden" name="_csrf_token" value="<?= $e($data['_csrf']) ?>"><input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="fingerprint" value="<?= $e($row['reviewed_fingerprint']) ?>"><label><input type="checkbox" required> Ürün düzenleme ekranındaki kategori ve özellikleri, yukarıdaki görselleri inceledim; ürüne uygun.</label><p><button class="ui-btn ui-btn-outline" type="submit">İçerik incelemesini kaydet</button></p></form>
<?php endif; ?></details></td></tr>
<?php endforeach; ?></tbody></table>
<?php if(!$report['items']): ?><p>Bu filtrede henüz kontrol kaydı yok.</p><?php endif; ?>
<div class="catalog-controls" style="margin-top:20px"><span><?= number_format((int)$report['total'],0,',','.') ?> kayıt · Sayfa <?= (int)$report['page'] ?></span><?php foreach([-1=>'Önceki',1=>'Sonraki']as$delta=>$text): $next=$report['page']+$delta;if($next<1||($next-1)*50>=$report['total'])continue; ?><a class="ui-btn ui-btn-outline" href="?<?= $e(http_build_query(['status'=>$status,'q'=>$search,'page'=>$next])) ?>"><?= $text ?></a><?php endforeach; ?></div>
</div>
