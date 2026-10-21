# Admin Liste/Tablo Standardı (bl-* sistemi)

Tüm admin **listeleme tabloları**, `odemeislemleri` referans modeline göre tek bir tasarım
sistemine (`boss-list.css` → `bl-*` sınıfları) dönüştürülür. Tema-uyumludur (light/dark,
`--bs-*` token'ları). **Ürünler modülü hariçtir** (bkz. envanter).

## Global altyapı (zaten yüklü — dokunma)
- `boss/modernize/boss-list.css` → `bl-*` sınıfları. `anasayfa.php` (shell) içinde global yüklü.
- `boss/inc/ui.php` → `boss_page_header($title, $crumbs, $actions='')`, `boss_switch()`, `boss_status_badge()`.
- Shell **Bootstrap 5** + **Tabler ikonları** (`ti ti-*`) + jQuery + toastr yükler. FontAwesome de yüklü ama hedef Tabler.
- Sayfalar `anasayfa.php` içine `require_once` ile gömülür → her sayfa **fragman**dır (kendi `<html>/<head>` YOK).

## Hedef yapı (her liste sayfası)
```php
<?php /* ---- PHP İŞ MANTIĞI AYNEN KORUNUR: sil/güncelle handler, sorgular ---- */ ?>

<?php boss_page_header('Sayfa Başlığı', array(array('l' => 'Breadcrumb'))); ?>

<div class="card">
    <!-- (opsiyonel) Filtre: <form method="GET"><div class="bl-filter">...</div></form> -->
    <!-- (opsiyonel) Özet: <div class="bl-summary"><div class="bl-summary-item">...</div></div> -->

    <div class="bl-toolbar">
        <span class="fw-semibold"><i class="ti ti-XXX text-primary me-1"></i> Liste Başlığı</span>
        <span class="bl-toolbar-count"><?php echo $toplamKayit; ?> kayıt bulundu</span>
        <!-- (varsa "ekle" eylemi) -->
        <a href="X-ekle" class="btn btn-success ms-2"><i class="ti ti-plus me-1"></i> Ekle</a>
    </div>

    <div class="table-responsive">
        <table class="bl-table">
            <thead><tr><th>...</th><th class="text-end">İşlem</th></tr></thead>
            <tbody>
                <?php foreach ($pr as $tr) { ?>
                <tr>
                    <td>...</td>
                    <td class="text-end">
                        <div class="bl-actions">
                            <a href="..." class="bl-action" data-bs-toggle="tooltip" title="Düzenle"><i class="ti ti-edit"></i></a>
                            <a href="...?temizle=<?php echo $tr['id']; ?>" onclick="return confirm('Silmek istediğinize emin misiniz ?')" class="bl-action danger" data-bs-toggle="tooltip" title="Sil"><i class="ti ti-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php } ?>
                <?php if ($toplamKayit == 0): ?>
                <tr><td colspan="N"><div class="bl-empty"><i class="ti ti-mood-empty"></i>Kayıt bulunamadı</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
```
Referans uygulamalar: `pages/yenisiparisler.php` (sipariş tipi), `pages/tummarkalar.php` (basit CRUD).

## bl-* sınıf sözlüğü
- Konteyner: `.card` (beyaz, radius, border, shadow — Modernize teması verir)
- `.bl-filter` (GET form), `.bl-f-item`, `.bl-f-grow`
- `.bl-summary` > `.bl-summary-item` > `.bl-summary-label` + `.bl-summary-value [blue|green|orange|red]`
- `.bl-toolbar` (başlık solda) + `.bl-toolbar-count` (sağa yaslı "X kayıt bulundu")
- `table.bl-table` > `thead th` / `tbody td`; başlık `<th class="text-end">` sağa
- Birincil hücre: `.bl-cell-title` (link/başlık) + `.bl-cell-sub` (alt/tarih)
- Rozet: `.bl-badge [green|gray]` (etiket); Durum: `.bl-status [success|warning|info|danger|purple|dark]`
- Para: `.bl-money [green|red|orange|muted]`, alt: `.bl-money-sub`
- Eylemler: `.bl-actions` > `.bl-action [danger|success|warning]` (36px sade ikon buton + tooltip)
- Görsel: `.bl-thumb` (44px), `.bl-avatar` (yuvarlak); `.bl-stars` (`.off` boş yıldız)
- Boş: `.bl-empty` (`<i class="ti ...">` + metin); Sayfalama: `.bl-pager` (`a`/`span`, `.active`, `.disabled`)

## DÖNÜŞÜM KURALLARI (kritik)
1. **İş mantığına dokunma.** Tüm PHP/SQL, `temizle()`, `$ozy->...`, sil/güncelle handler'ları, `$_GET/$_POST` isimleri, form `name`'leri, toastr `echo`'ları, `<meta refresh>`, e-posta gönderim kodu **AYNEN** kalır.
2. **Bağlantıları koru.** `href`, `?temizle=`, `siparis/duzenle/`, `marka-ekle` gibi slug'lar; `onclick="return confirm(...)"` metinleri; `target="_blank"` — değiştirme.
3. **Sadece sunumu değiştir:** sayfanın kendi `<style>...</style>` bloğunu ve `.wrapper > .container-fluid > .page-title-box` / `.mod-page` / `.odeme-page` sargısını KALDIR; yerine yukarıdaki `boss_page_header` + `.card` + `bl-*` yapısını koy.
4. **DataTables'ı kaldır:** `id="datatable"` ve `data-order='...'` özniteliklerini sil (yoksa DT, bl-table görünümünü ezer). Tablo düz `class="bl-table"` olur.
5. **BS5'e taşı:** `data-toggle`→`data-bs-toggle`, `data-target`→`data-bs-target`, `data-dismiss`→`data-bs-dismiss`, `data-original-title`→`title`. Modal kapat butonu `<button class="btn-close" data-bs-dismiss="modal">`. Modal kök sınıfı `.modal fade`, içerik `.modal-dialog>.modal-content>.modal-header/.modal-body`.
6. **İkonlar → Tabler** (`ti ti-*`). `<img src="assets/images/tel.png">` gibi görsel ikonları Tabler'a çevir.
7. **İçi formlu hücre** (ör. `<select onchange="this.form.submit()">` ile durum değişimi): formu hücrede koru, select'e `class="form-select form-select-sm"` ver.
8. **Toggle** (durum aç/kapa): `boss_switch('name', $checked)` veya BS5 `.form-check.form-switch`. `data-toggle="toggle"` (bootstrap4-toggle) BS5'te çalışmaz.
9. Kolon sayısı değişirse `bl-empty` satırındaki `colspan`'i güncelle.

## Eşleme (eski → bl-*)
| Eski (legacy / .mod-page / .odeme) | Yeni |
|---|---|
| `.page-title-box`+`<h4 class="page-title">`+breadcrumb | `boss_page_header(...)` |
| `.card.m-b-30 > .card-body` / `.s-card` / `.odeme-page` | `.card` (+ içerik) |
| `table.table.table-bordered` / `.m-table` / `.odeme-table` | `table.bl-table` |
| `badge badge-success` / `.b-ok` | `.bl-status success` (veya `.bl-badge green`) |
| `badge badge-danger` / `.b-no` | `.bl-status danger` |
| `.b-info` / `.b-warn` / `.b-purple` | `.bl-status info` / `warning` / `purple` |
| `.btn.btn-sm.btn-danger` (ikon) / `.act-btn.a-del` | `.bl-action danger` |
| `.btn.btn-sm.btn-success` (ikon) / `.act-btn.a-edit` | `.bl-action` (düzenle) |
| `.act-btn.a-view` / `.a-tel` / `.a-wa` | `.bl-action` / `.bl-action` / `.bl-action success` |
| `.link-blue` / `.odeme-siparis-no` | `.bl-cell-title` |
| `.empty-box` | `.bl-empty` |
| `.status-sel` | `.form-select form-select-sm` |
| `.stars` | `.bl-stars` |

## İkon eşlemesi (FA/themify → Tabler)
trash→`ti-trash` · edit/`fa-edit`→`ti-edit` · eye→`ti-eye` · print→`ti-printer` ·
phone/tel.png→`ti-phone` · whatsapp/whatsap.jpg→`ti-brand-whatsapp` · plus→`ti-plus` ·
search→`ti-search` · check→`ti-check` · times/x→`ti-x` · star→`ti-star` · reply→`ti-arrow-back-up` ·
lock→`ti-lock` · envelope→`ti-mail` · envelope-open→`ti-mail-opened` · store→`ti-building-store` ·
copy→`ti-copy` · external-link→`ti-external-link` · bell→`ti-bell` · ticket→`ti-ticket` ·
gift→`ti-gift` · truck→`ti-truck` · tag→`ti-tag` · file→`ti-file-text` (her zaman `class="ti ti-XXX"`).

## Envanter (durum)
**Bitti (bl-*):** yenisiparisler, tummarkalar, home(panel) · odemeislemleri (referans, taşınacak)
**Hariç (Ürünler modülü):** tumurunler, urunekle, tumanaurunler, anaurunekle, magazaurunler, katalogimport, onaykuyrugu, topluguncelleme
**Özel (tablo değil — DOKUNMA):** kategoriler (ağaç+form), arac-veri-merkezi (dashboard), raporlama (rapor paneli), kargoayarlari/site-sistem-tema ayarları (form)
**Dönüştürülecek liste sayfaları:** onaybekleyensiparisler, hazirlanansiparisler, kargolanansiparisler, teslimedilensiparisler, iptaledilensiparisler, iadeedilensiparisler, uyesiparisleri, tummagazalar, magazasiparisler, magazaodemeler, magazayorumlar, magazasorular, tumuyeler, tumadminler, tumkuponlar, tumkampanyalar, tumbloglar, blogyorumlari, tumsayfalar, tumsssler, tumbankalar, tumsliderler, tumkargolar, tumhediyecekleri, etiketler, tumgyorumlar, tumgyorumlarr, tumaracmarkalar, tummodeller, modeller, urunyorumlari, urunsorulari, destekmerkezi, gelenkutusu, onaybekleyensozlesmeler, sozlesmeler, sanalposlar, ebulten, toplueposta, sepet, xml, filtrelemeislemleri

## Lint
`& "C:/wamp64/bin/php/php8.2.30/php.exe" -l <dosya>` → her değişen dosyada "No syntax errors".
