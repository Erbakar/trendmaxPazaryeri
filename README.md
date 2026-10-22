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
   Bu sürümün yeni şema dosyaları phase14/056–058'dir.
6. Uygulamayı yeni sürüme yönlendirin; kullanıcı girişi, kendi mağaza görünürlüğü, XML analizi,
   kategori/özellik seçimleri, fiyat önizlemesi ve sınırlı gerçek ürünün batch sonucunu doğrulayın.
   Batch kabulü, içerik onayı ve satışa açılma ayrı aşamalardır.
7. Kontrolden sonra sunucunun gerçek PHP yolu ile zamanlanmış işleri açın. XML içe aktarma için
   `cron/sync_xml_profiles.php`, gönderim sonuçları için `cron/sync_publish_batches.php` gereklidir.
   Diğer periyodik işler `cron/queue_enqueue.php` ve `cron/queue_worker.php` üzerinden yürür.
   Aynı görev için eski doğrudan cron ile kuyruk sürümünü birlikte çalıştırmayın. İşlerin log,
   hata, tekrar deneme ve kuyruk durumunu ilk çalışmada izleyin.

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
