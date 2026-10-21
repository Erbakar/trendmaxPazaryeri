# Admin FORM (ekle/düzenle) Standardı

Tüm ekleme/düzenleme **formlarını** tek bir modern, minimal, profesyonel, tema-uyumlu
(light/dark) kart-form düzenine getir. Sadece **sunum** değişir; **iş mantığı (PHP/SQL/
dosya yükleme/POST isimleri/koşullu JS) AYNEN korunur.**

## Altyapı (yüklü)
- `boss_page_header($title,$crumbs,$actions='')`, `boss_switch($name,$checked,$opts)` → `boss/inc/ui.php`.
- Shell: Bootstrap 5 + Tabler (`ti ti-*`) + jQuery + toastr (artık yüklü) + summernote/quill/select2.
- Sayfa = fragman (kendi `<html>/<head>` YOK). Shell içeriği `.body-wrapper .container-fluid` ile sarar.

## Hedef yapı
```php
<?php /* ---- TÜM PHP İŞ MANTIĞI AYNEN KORUNUR (handlers/insert/update/upload) ---- */ ?>

<?php boss_page_header($_GET['duzenle'] ? 'X Düzenle' : 'X Ekle',
      array(array('l'=>'X Listesi','u'=>'tum-xler'), array('l'=>$_GET['duzenle']?'Düzenle':'Ekle'))); ?>

<form action="" method="POST" enctype="multipart/form-data">
  <div class="row">
    <!-- Ana içerik -->
    <div class="col-lg-8">
      <div class="card"><div class="card-body">
        <div class="mb-3">
          <label class="form-label">Alan Adı</label>
          <input type="text" name="adi" class="form-control" value="<?php echo $sayfam['adi']; ?>" required>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Kısa Alan</label>
            <input type="text" name="link" class="form-control" value="<?php echo $sayfam['link']; ?>">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Sıra <span class="text-muted small">(yüksek = başa)</span></label>
            <input type="text" name="sira" class="form-control" value="<?php echo $sayfam['sira']; ?>">
          </div>
        </div>
        <!-- editör alanı varsa: <textarea id="summernote"/id="quill" name="aciklama"> ... -->
      </div></div>
    </div>
    <!-- Yan panel: görsel + durum -->
    <div class="col-lg-4">
      <div class="card"><div class="card-body">
        <label class="form-label d-block">Görsel</label>
        <img id="imgPreview" src="<?php echo $_GET['duzenle'] ? '../resimler/xler/'.$sayfam['resim'] : 'assets/images/resimyok.jpg'; ?>"
             class="img-fluid rounded border mb-2" style="max-height:170px;object-fit:contain;width:100%;background:var(--bs-secondary-bg);">
        <input type="file" name="resim" accept="image/png,image/jpeg"
               class="form-control" onchange="if(this.files[0])document.getElementById('imgPreview').src=window.URL.createObjectURL(this.files[0]);">
        <hr>
        <?php echo boss_switch('durum', (!$_GET['duzenle'] || $sayfam['durum']=='1'), array('label'=>'Aktif')); ?>
      </div></div>
    </div>
  </div>

  <div class="mb-4">
    <?php if($_GET['duzenle']): ?>
    <button type="submit" name="guncelle" class="btn btn-warning"><i class="ti ti-device-floppy me-1"></i> Güncelle</button>
    <?php else: ?>
    <button type="submit" name="kaydet" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i> Kaydet</button>
    <?php endif; ?>
    <a href="tum-xler" class="btn btn-light">İptal</a>
  </div>
</form>
```
Resmi olmayan formlarda yan paneli atla → tek sütun (`.col-lg-8` yerine `.col-12` veya `.col-lg-8 mx-auto`). Çok alanı olan formda alanları `.row > .col-md-6` ile ikişerli grupla.

## DÖNÜŞÜM KURALLARI (kritik)
1. **PHP'ye dokunma.** Üstteki tüm handler'lar (`if($_GET['duzenle'])`, `if(isset($_POST['kaydet'/'guncelle']))`, dosya `move_uploaded_file`, `INSERT/UPDATE`, `$ozy`, `temizle()`, `seo()`, `md5()`, e-posta), tüm `$_POST`/`$_FILES` **isimleri**, `<meta http-equiv="refresh">`, `toastr[...]` echo'ları — AYNEN.
2. `.wrapper > .container-fluid > .page-title-box` (+ eski `.breadcrumb float-right`) → `boss_page_header(...)`. Başlık metnini ve ekle/düzenle koşulunu koru.
3. `.form-horizontal` + `.form-group.row` + `<label class="col-sm-2 col-form-label">` + `.col-sm-10` (etiket-solda) → modern **`.mb-3` > `.form-label` (üstte) + `.form-control`/`.form-select`**. Inputların `name`, `value`, `required`, `id`, `class`, `maxlength` vb. özniteliklerini DEĞİŞTİRME.
4. **Toggle:** `<input type="checkbox" ... data-toggle="toggle" ... name="durum">` (bootstrap4-toggle, BS5'te ölü) → `<?php echo boss_switch('durum', <checked-ifade>, array('label'=>'Aktif')); ?>` — `name`/`value`/checked mantığını koru.
5. **Dosya yükleme:** jasny `data-provides="fileupload"` widget'ı → temiz `<input type="file" name="resim" class="form-control" accept="...">` + üstte `<img id="imgPreview">` önizleme (düzenlemede mevcut resmi göster) + `onchange` ile anlık önizleme. `name="resim"` ve düzenleme-modu resim yolunu koru.
6. **Butonlar:** `btn-lg btn-block waves-effect` → `btn btn-primary`/`btn btn-warning` + `ti ti-device-floppy` ikon. `name="kaydet"`/`name="guncelle"` AYNEN.
7. **İkonlar → Tabler** (`ti ti-*`). `data-toggle`→`data-bs-toggle` (varsa tab/modal/tooltip).
8. **KOŞULLU JS formları (ör. uyeekle):** göster/gizle hedef `id`'leri (`#bireysel #kurumsal #kurumsal2 #kurumsal3 #sifre1`), radio `name`/`value`/`class`/`id` ve `onclick`/`onchange`'leri **birebir** koru — global JS bunlara bağlı. Bunları yeni `.mb-3` sarmalına taşırken öznitelikleri aynen bırak.
9. **Sekmeli formlar (blogekle/kategoriler):** eski büyük buton-sekmeleri BS5 `nav nav-pills`/`nav-tabs` + `tab-content`'e çevir (`data-bs-toggle="tab"`), panel id'lerini koru. Editör textarea'sının `id="summernote"`/`id="quill"` ve `name`'ini koru.

## Lint
`& "C:/wamp64/bin/php/php8.2.30/php.exe" -l <dosya>` → "No syntax errors".

## Form envanteri (dönüştürülecek)
markaekle, bankaekle, kargoekle, sssekle, sayfaekle, kuponekle, kampanyaekle, adminekle,
hediyecekiekle, sliderekle, gyorumekle, gyorumeklee, magazaekle, markamodelduzenle,
mesajduzenle, epostaduzenle, uyeekle (koşullu), blogekle (sekme+editör), anaurunekle.
**Özel/sonra:** kategoriler (zaten modern), siparisduzenle/magazam/destekduzenle (detay), ayar sayfaları, ürün formları.
