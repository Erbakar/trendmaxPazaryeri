# Trendmax

PHP 8.2 ve MySQL/MariaDB tabanlı, mağaza bazında pazaryeri ve XML ürün yönetimi.
Web uygulaması ve Electron istemcisi ayrı dağıtılır. Aşağıdaki paket yalnız web uygulamasını içerir.

## Web dağıtımını hazırlama

Gerekenler: PHP 8.2+, PDO MySQL, cURL, OpenSSL, mbstring, SimpleXML, XML, ZIP; Composer 2, Python 3 ve Git.

```sh
composer validate --no-check-publish
composer audit --locked --no-dev
python3 tests/test_release.py
python3 tools/build-release.py
```

Composer PHAR kullanılıyorsa `--composer /guvenilir/konum/composer.phar` verilebilir.
Paket ve dosya SHA-256 listesi `storage/local/releases/` altında üretilir ve Git'e girmez.
Builder kaynakları ayrı bir geçici dizine alır; kilit dosyasından `composer install --no-dev`
çalıştırır ve PHP gereksinimlerini kontrol eder. Çalışma dizinindeki vendor değişmez.
Windows yardımcıları da aynı builder'ı çağırır.

`.env`, yerel çalışma verileri, yedekler, müşteri XML'leri, yüklenen dosyalar,
iş günlükleri, testler, Electron ve geliştirme bağımlılıkları pakete alınmaz.
Yalnız kasıtlı OpenCart bağlayıcı indirmesi ZIP kısıtlamasından muaftır.
Git checkout'unu doğrudan web köküne kopyalamak yerine bu paket kullanılmalıdır.

## Mevcut sunucuyu güncelleme

1. Önce zamanlanmış işleri/worker'ları durdurun ve kısa bakım penceresi açın.
2. Veritabanının tutarlı yedeğini, mevcut uygulama sürümünü, `.env` ve yüklenen dosyaları
   web kökü dışına yedekleyin. Yedek erişimini sınırlandırın. Şifreleme anahtarını koruyun;
   değiştirilmesi kayıtlı bağlantı bilgilerinin çözülememesine neden olur.
3. Paketi yeni sürüm dizinine çıkarın. Sunucuya ait `.env`, storage ve uploads verilerini
   koruyun. Mevcut kurulumun `installer_done.lock` dosyasını kaldırmayın.
   Üretimde `APP_ENV=production`, `APP_DEBUG=false`, doğru HTTPS `APP_URL` kullanın.
4. Apache 2.4'te `mod_rewrite` ve `.htaccess` için `AllowOverride All` etkin olmalıdır.
   Nginx `.htaccess` okumaz; eşdeğer özel dizin/dosya engelleri ayrıca tanımlanmadan
   yayın yapılmamalıdır. TLS ve güvenli oturum çerezi gerçek sunucuda doğrulanmalıdır.
5. Önce `php database/migrate.php --dry-run` ile bekleyen güncellemeleri inceleyin.
   Yedekten sonra `php database/migrate.php --allow-live` ile uygulayın; tekrar dry-run'da
   bekleyen işlem kalmamalı. Mevcut bilinmeyen bir şemaya gelişigüzel `--baseline` uygulamayın.
   Bu sürümün phase14 güncellemeleri 059–062'yi de içerir; katalog kontrolü, görsel önbelleği, eşleştirme geçmişi ve tedarikçi sayfa kontrolleri için yedi yeni tablo gerekir.
6. Uygulamayı yeni sürüme yönlendirin; kullanıcı girişi, kendi mağaza görünürlüğü, XML analizi,
   kategori/özellik seçimleri, fiyat önizlemesi ve sınırlı gerçek ürünün batch sonucunu doğrulayın.
   Batch kabulü, içerik onayı ve satışa açılma ayrı aşamalardır.
7. Kontrolden sonra sunucunun gerçek PHP yolu ile zamanlanmış işleri açın. XML içe aktarma için
   `cron/sync_xml_profiles.php`, gönderim sonuçları için `cron/sync_publish_batches.php` gereklidir.
   Diğer periyodik işler `cron/queue_enqueue.php` ve `cron/queue_worker.php` üzerinden yürür.
   Aynı görev için eski doğrudan cron ile kuyruk sürümünü birlikte çalıştırmayın. İşlerin log,
   hata, tekrar deneme ve kuyruk durumunu ilk çalışmada izleyin.

Sunucuda kuyruk işleyicisi henüz kurulmadıysa, aşağıdaki cron örneğinde `/PROJE` ve PHP yolunu gerçek sunucu yollarıyla değiştirin. Kuyruk sipariş, iade, soru, stok ve fiyat görevlerini mağazanın açık/kapalı ve süre ayarlarına göre üretir. XML kaynağı ayrı yenilenir; XML maliyetine uygulanan %115 kuralı fiyat gönderiminde hesaplanır. Ürün içe aktarma anahtarını açmak XML fiyatlarını Trendyol fiyatlarıyla değiştirmek için kullanılmamalıdır.

```cron
* * * * * /usr/bin/php /PROJE/cron/queue_enqueue.php >> /PROJE/storage/logs/queue-enqueue.log 2>&1
* * * * * /usr/bin/php /PROJE/cron/queue_worker.php --max-jobs=50 --max-seconds=50 >> /PROJE/storage/logs/queue-worker.log 2>&1
* * * * * /usr/bin/php /PROJE/cron/sync_xml_profiles.php >> /PROJE/storage/logs/xml-cron.log 2>&1
* * * * * /usr/bin/php /PROJE/cron/sync_publish_batches.php >> /PROJE/storage/logs/publish-batches.log 2>&1
* * * * * /usr/bin/php /PROJE/cron/catalog_audit.php >> /PROJE/storage/logs/catalog-audit.log 2>&1
* * * * * /usr/bin/php /PROJE/cron/supplier_catalog.php >> /PROJE/storage/logs/supplier-catalog.log 2>&1
```

Aynı görevlerin doğrudan `sync_orders.php` vb. cron tanımlarını kuyrukla birlikte çalıştırmayın. Ürün gönderimi için ilgili veritabanı migrasyonları, kuyruk için de `sync_jobs` şeması tamamlanmış olmalıdır. `/sync` ekranında yalnız yeşil anahtar veya elle başarılı çalışma otomatik çalışmanın kanıtı değildir; her satırdaki **Son otomatik** tarihinin seçilen aralıkta ilerlediğini kontrol edin. Gönderim isteklerinin API kabulü, ürünün satışa açılması anlamına gelmez; batch sonucu ve Trendyol durum sayaçları ayrıca doğrulanmalıdır.

Ürünler → **Katalog Kontrolü** (`/products/catalog-audit`) tüm XML kaynaklarını, panel ürünlerini ve eski Trendyol ilanlarını tek raporda toplar. Kontrol ve otomatik gönderim mağaza bazında ayrı açılır; varsayılan olarak kapalıdır. Sunucu işi kaldığı yerden sürdürür, tamamlanan turdan 15 dakika sonra yeniden başlar. Mağaza/pazaryeri yetkisi ve ürün düzenleme izni gerekir. Mevcut sipariş, XML, fiyat ve stok görevleri çalışmaya devam eder.
Gönderim yanıtı satış onayı sayılmaz: toplu işlem retleri otomatik okunur ve aynı içerik yeniden gönderilmez. Barkodlar [Trendyol Ürün Yaratma v2 kurallarına](https://developers.trendyol.com/docs/%C3%BCr%C3%BCn-yaratma-v2) göre kontrol edilir; geçersiz kimlikler otomatik değiştirilmez.


Yeni gönderimde güncel XML ile panel stoğu, fiyat tabanı ve görseller uyuşmalı; kategori/özellik doğrulaması ve kayıtlı içerik incelemesi bulunmalıdır. Görsel kontrol sonuçları mağazaya özel veritabanı önbelleğinde tutulur; görsel başına dosya oluşturulmaz ve kullanılmayan kayıtlar yedi gün sonra temizlenir. Görsel adresleri güvenli genel IP'lere sabitlenerek okunur; teknik erişim, kategori ve görselin ürüne uygun olduğuna dair incelemenin yerine geçmez. İçerik, kategori veya özellik değişikliği onayı geçersiz kılar. Kâr/kargo için mevcut fiyat motoru kullanılır. Eski ilanlarda yalnız kanıtlı XML bağlantıları kullanılır; stok bağlantısı fiyat/marka/görsel sahipliğini değiştirmez. Eksik veya bozuk XML, sıfır stok kabul edilmez. Yeni ürün isteğinin sonucu belirsizse otomatik mükerrer gönderim yapılmaz; doğrulama/ret sonucu raporda tutulur. Kaynak ve rapor dosyaları özel `storage/catalog-audit/` alanındadır ve dağıtım paketine alınmaz.

Geri dönüşte önce worker/cron'u durdurun; önceki uygulama sürümüne dönün. Şema/veri geri dönüşü
gerekiyorsa bakım penceresinde alınmış veritabanı ve dosya yedeğini birlikte geri yükleyin.
Bu sürümün phase14 güncellemelerinin otomatik down migration'ı yoktur. Yedek sonrasında
oluşmuş sipariş/değişiklikler varsa tam geri yüklemeden önce korunmalıdır.

## Yeni XML kullanıcısının akışı

XML Profilleri → Yeni → URL veya Dosya → Analiz Et → alan seçimlerini kontrol et → profili kaydet → Çalıştır.
Genel XML yolunda renk, ana renk, menşei, beden, materyal, cinsiyet ve yaş grubu eşleştirilebilir.
Profil çalıştırılması ürünleri içeri alır; kendi başına pazaryerinde yayınlamaz.

Pazaryeri bağlantısı, marka ve kategori eşleştirmeleri tamamlanmalı; kategoriye özgü zorunlu
özellikler seçilmeli; ardından önizleme ve gönderim yapılmalıdır. Aynı XML kategorisi karışık
ürünler içeriyorsa ürün bazında ayrı pazaryeri kategorisi seçilebilir. XML'de bulunmayan gerçek
ürün bilgileri tahmin edilmez; gerekli bilgi yoksa ilgili ürün engellenir, uygun ürünler gönderilebilir.

Yeni profillerin kâr değeri varsayılan olarak sıfırdır. Maliyete %115 eklemek için profilin
yüzde değeri 115 girilmelidir (100 TL → 215 TL); diğer fiyat kuralları ve önizleme de kontrol edilmelidir.
Her yeni XML için otomatik fotoğraf inceleme veya karma kategorileri anlamsal olarak ayırma yoktur.

## Yerel kontroller

Veritabanı kullanan testler yalnız `trendmax_local` ve loopback veritabanında çalışır.
HTTP taşıma testlerinin gerçek pazaryerine gitmemesi için fixture servisi gereklidir:

```sh
php -S 127.0.0.1:8091 tests/fixtures/trendyol-api.php
# Ayrı terminalde, hazırlanmış yerel test veritabanıyla:
TRENDMAX_LOCAL_INTEGRATION=1 TRENDMAX_ARCHIVE_TEST=1 php vendor/bin/phpunit
```

`tools/smoke-local.py` yerel panelde yalnız okuma/giriş kontrolleri yapar; parola
`LOCAL_TEST_PASSWORD` ortam değişkeninden alınır. Katalogda veya canlı pazaryerinde değişiklik yapmaz.

## 22 Ekim 2026 yayın öncesi sonucu

- 32 PHP testi / 157 kontrol; atlanan test yok. 50 yerel HTTP/giriş kontrolü başarılı.
- İki paket sınır testi başarılı; paket içeriğinde müşteri/çalışma verisi ve geliştirme bağımlılığı yok.
- 523 PHP dosyasında sözdizimi kontrolü başarılı; Electron JavaScript dosyaları da sözdiziminden geçti.
- Apache üzerinde sentetik dizinde özel dosya engelleri ve izinli statik dosyalar doğrulandı.
- Phase14 güncellemeleri ayrı veritabanında uygulandı; ikinci çalışmada bekleyen işlem kalmadı.
- Temiz kurulum SQL'i ve kurulum migration fonksiyonları ayrı veritabanında çalıştı;
  803 SQL ifadesi ve 12 migration başarılı, ikinci migration çalışması sıfır işlem yaptı.
- Yerel tam SQL yedeği ayrı veritabanına geri yüklendi; dört kritik tablonun sayıları eşleşti.
  Mağaza arşiv geri yüklemesinin atomiklik ve mağaza ayrımı testleri de geçti.
- Composer taramasında bildirilen açık yok. Electron araç zincirinde `sprintf-js` zincirinden
  kaynaklanan 8 orta seviye npm bulgusu var; güvenli otomatik düzeltme yok. Masaüstü uygulaması
  bu web paketine dahil değildir; masaüstü yayını için bu bulgular ayrıca giderilmelidir.

**Canlı yayın için kalan dış kontroller:** gerçek sunucunun TLS/PHP/yetki/cron ayarları ve güncelleme
sonrası sınırlı gerçek ürün kabulü bu yerel kontrolde çalıştırılmadı; sunucuya dağıtım yapılmadı.
Depo herkese açıktır ve eski commit'te çalışma yedekleri ile sabit entegratör anahtarı bulunmuştur.
Güncel sürüm bunları takipten çıkarır/ortam değişkenine taşır; eski Git nesneleri temizlenmiş sayılmaz.
Anahtarları yenileme ve geçmişteki hassas içerikleri kaldırma ayrı yayın ön koşuludur.
Geçmiş yeniden yazılmadı ve zorla push yapılmadı. Yerel raporlar/yedekler Git'e gönderilmez.

### Şifre sıfırlama güvenliği — ek kontrol

Oturumsuz şifre sıfırlama yanıtından SMTP hata/eksiklik durumundaki açık token dönüşü kaldırıldı.
Bağlantı yalnız e-postayla iletilir; teslim başarısızsa token iptal edilir. Bilinmeyen, pasif
ve bilinen hesaplar aynı genel yanıtı alır. Controller ve görünüm tokenı oturuma/HTML'e taşımaz;
eski update yükü de aynı düzeltmeyi içerir. Yerel SMTP yok/hatalı senaryosu testi eklendi.
Canlı sunucuya bu düzeltme ayrıca dağıtılmalıdır.

### Trendyol ilan eşleştirmelerini onarma

`php tools/reconcile-trendyol-listings.php --store=ID` onaylı Trendyol katalog sayfalarını okuyup mevcut yerel eşleştirmelerle kesin SKU/barkod eşleşmelerini raporlar. `--apply` yalnız bu eşleştirmelerin yayın durumunu ve uzak ilan kimliklerini günceller. Çelişkili veya çoklu eşleşmeler atlanır; ürün oluşturulmaz, XML maliyeti, stok kaynağı, kategori ve görseller değiştirilmez. Tüm sayfalar başarıyla okunmadan veritabanına yazılmaz. Uygulama öncesi mağaza yedeği alınmalıdır. Sonraki stok/fiyat görevi XML fiyat kuralını kullanır; API kabulü sonucun Trendyol'a yansıdığını tek başına kanıtlamaz.

### Fiyata kargo bedeli ekleme

Pazaryerleri → Ayarlar → Fiyat Ayarları bölümündeki “Fiyata eklenecek kargo bedeli (TL)” mağazaya ve pazaryerine özeldir. Kâr kuralı ve mevcut yuvarlama uygulandıktan sonra satış fiyatına bir kez eklenir; liste fiyatı satış fiyatının altında kalmaz. Örnek: 168,75 TL maliyet, %115 artış ile 363 TL; 50 TL kargo bedeliyle 413 TL. XML maliyeti değiştirilmez. Ayar mevcut ilanların fiyat senkronunda, yeni ürün yayınında ve önizlemede kullanılır. 0 girilmesi ek bedeli kaldırır.

## Kaynak bilgileriyle katalog tamamlama

Ürünler → Katalog Kontrolü ekranındaki “Kaynak bilgileriyle eksik eşleştirmeleri tamamla”
ayarı mağaza bazında açılır; diğer mağazalarda varsayılan olarak kapalıdır. Güncel XML
ile panelin adı, açıklaması, stok, fiyat tabanı ve görselleri uyuşuyorsa açık ürün
adından alt kategori ve metindeki belirli özellikler çıkarılır. Belirsiz ürün
türleri, çelişkili bilgiler ve kaynaksız zorunlu alanlar raporda bekler. Trendyol'un
resmi “Belirtilmemiş” seçeneği dışındaki değerler varsayılan olarak uydurulmaz.
Kullanıcının alan kaynağı eşleştirmeleri korunur; onaylı ilanın farklı bir kategoriye
taşınması veya mevcut görselinin değiştirilmesi otomatik yapılmaz. Değişikliklerin
önceki/sonraki hali ve dayanağı `catalog_enrichment_events` tablosunda tutulur.
Etiketli iki boyutlu ürün ölçüleri (örneğin `Ürün Ölçüleri; 60*42 Cm`) yalnız
Trendyol'un aynı ölçü seçeneğine eşlenir. Ambalaj ölçüleri, üç boyutlu, birimsiz,
çelişkili veya mevcut seçeneklerden farklı ölçüler tahmin edilerek tamamlanmaz.

Kategori, gerekli özellikler, güncel stok/fiyat ve görsel erişim kontrolleri geçen
ürünler, otomatik gönderim de açıksa gönderilir. Kayıtlı içerik çelişkisi otomatik
onayla aşılamaz. Bu doğrulama tedarikçinin verdiği fiziksel ürün bilgilerinin
bağımsız teyidi değildir; Trendyol kabulü ve satışa açılma ayrıca izlenir.

Trendyol bağlantı ayarlarındaki “XML’e bağlı eski ilanların fiyatını da XML
kaynağından hesapla” seçeneği, kanıtlanmış stok bağlantılarını fiyat kaynağı
olarak kullanmayı ayrıca açar. XML profilinin kâr/kargo ayarı uygulanır; bu fiyat
işlemi stok miktarı veya ürün içeriği göndermez. Ayar kapalıyken eski fiyat
sahipliği korunur.

### Tedarikçi sitesinden ek ürün bilgisi

XML profili düzenleme ekranındaki **Eksik Ürün Bilgilerini Tamamla** seçeneği,
Toptanarabul ürün sayfalarının arka planda kontrolünü açar. Bu seçim varsayılan
olarak kapalıdır ve yalnız ilgili mağaza/profili etkiler. `supplier_catalog.php`
görevi, katalog raporunda eksikleri bulunan ürünleri kalıcı önbellekle kontrol eder;
müşterinin her ürün veya yeni XML yüklemesinde komut çalıştırması gerekmez.

Tam stok kodu ve normalleştirilmiş ürün adı eşleşmelidir. Tedarikçi kategorisi,
fiyatı, stoğu ve görselleri kopyalanmaz. Ek açıklama ve açık özellik alanlarındaki
doğrulanabilen bilgiler, boş Trendyol özelliklerini tamamlamak için kullanılır;
XML/panel stok-fiyat-görsel kontrolleri ve mevcut kullanıcı seçimleri korunur.
Tamamlanan değerler sonraki XML içe aktarımlarında silinmeyen ürün eşleştirmelerine
yazılır; tedarikçinin sunucusundaki XML dosyası değiştirilmez.

Aynı açıklama ek bilgi sayılmaz. Sayfalar günde bir yenilenir; ürün adı, açıklaması
veya görseli değişirse yeniden kontrol edilir. XML'den gelen özellik alanları
değiştiğinde eski ek kaynak değeriyle otomatik gönderime devam edilmez; yeni inceleme gerekir. Daha önce kullanılan ek bilgi
değiştiğinde yeni gönderim inceleme bekler. Hata veya istek sınırında tekrar deneme
uygulanır; site genelinde istekler arasında en az beş saniye bırakılır. Kontrol,
ek metin ve gerçekte kullanılan bilgi sayıları Katalog Kontrolü ekranında ayrıdır.
