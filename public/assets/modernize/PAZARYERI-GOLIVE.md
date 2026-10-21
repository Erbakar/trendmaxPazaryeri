# Pazaryeri — Go-Live Kılavuzu

Bu belge, pazaryeri (escrow + komisyon + stopaj + alt-üye) sistemini **canlıya almak** için yapılacakları içerir.
Sistemin çekirdeği kuruldu ve test edildi; aşağıdakiler yalnızca **canlı PSP anahtarlarınız** olduğunda yapılır.

> ⚠️ **Mevcut durum güvenli:** `pazaryeri_ayar.escrow_aktif=0` olduğu için canlı mağaza/ödeme **birebir eskisi gibi** çalışıyor. Aşağıdaki adımlar tamamlanana kadar escrow'u AÇMAYIN.

---

## 1. İyzico / PayTR "Pazaryeri (Marketplace)" hesabı
- İyzico panelinizde **Pazaryeri (alt-üye/submerchant)** özelliğini aktifleştirin (normal sanal POS anahtarı YETMEZ — "Bu servis sadece pazaryeri müşterilerine açıktır" hatası bunu gösterir).
- Sandbox + üretim **API Key / Secret** alın. PayTR için **Pazaryeri / alt-bayi** onayı + API uçları.
- Anahtarları **Sistem → Sanal Pos Ayarları**'na girin (İyzico API/Secret, PayTR ID/KEY/SALT).

## 2. Pazaryeri Ayarları (Pazaryeri Finans → Pazaryeri Ayarları)
- **Aktif PSP**: İyzico veya PayTR.
- **Test modu**: önce AÇIK (sandbox). Üretimde KAPALI.
- **Stopaj**: oran (vars. %1) + "Stopajı satıcı netinden KES" — **mali müşavir onayıyla** açın (bkz. §6).
- **Oto-onay**: gün sayısı (örn. 3) + aç. **İtiraz süresi**: örn. 14 gün.
- **Komisyon**: varsayılan oran + KDV oranı + kargo dahil mi.
- **Cron anahtarı**: boş bırakırsanız otomatik üretilir (§5'te kullanılır).

## 3. Satıcıları alt üye yap (Pazaryeri Finans → Alt Üye Yönetimi)
- Her mağazanın **IBAN + vergi (şahıs: TCKN / şirket: VKN+vergi dairesi+ünvan)** bilgisi tam olmalı (eksikse "Bilgi Tamamla" ile mağaza profilinden girin).
- "Alt Üye Oluştur" → durum **Onaylı** olmalı. Onaylı olmayan satıcı escrow'lu sipariş alamaz.

## 4. Checkout submerchant kırılımı (TEK kalan kod yaması — sandbox'ta test edilerek uygulanır)
`default/alisverisitamamla.php` **minified** (tek satır) ve canlı ödeme dosyası olduğu için
körlemesine düzenlenmedi. Aşağıdaki yama, **sandbox anahtarlarıyla test edilerek** uygulanmalı.

**Amaç:** İyzico checkout'unda her `BasketItem`'a `setSubMerchantKey` + `setSubMerchantPrice` vermek
(ve `setId` = ssiparis.id). Hesaplama hazır: `classes/CheckoutSplit::satirlar($ozy,$siparisno,'iyzico')`.

`alisverisitamamla.php` içinde İyzico `BasketItem` oluşturulan döngüde, ssiparis satırları yazıldıktan SONRA:
```php
// escrow_aktif ise alt üye kırılımı
$__pa = $ozy->query("SELECT escrow_aktif FROM pazaryeri_ayar WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$__split = array();
if (!empty($__pa['escrow_aktif']) && file_exists(__DIR__."/classes/CheckoutSplit.php")) {
    require_once __DIR__."/classes/CheckoutSplit.php";
    foreach (CheckoutSplit::satirlar($ozy, $siparisno, 'iyzico') as $__s) {
        $__split[$__s['ssiparis_id']] = $__s;   // ssiparis_id => [sub_merchant_key, sub_merchant_price, price]
    }
}
```
Her `BasketItem` kurulurken (o satırın ssiparis_id'si ile):
```php
$basketItem->setId($ssiparisId);                 // önemli: itemId = ssiparis.id (callback eşlemesi için)
if (!empty($__split[$ssiparisId]['sub_merchant_key'])) {
    $basketItem->setSubMerchantKey($__split[$ssiparisId]['sub_merchant_key']);
    $basketItem->setSubMerchantPrice($__split[$ssiparisId]['sub_merchant_price']);
}
```
> Callback (`iyzicosonuc.php`) zaten `itemTransactions`'tan `itemId → paymentTransactionId` eşlemesini
> okuyup `hakedis.psp_txn_id`'ye yazıyor (escrow serbest bırakma bununla yapılır). Bu yüzden `setId($ssiparisId)` ŞART.

**Test:** sandbox'ta çok-satıcılı sipariş ver → İyzico panelinde alt-üye bazında bloke tutar görünmeli;
`hakedis` satırlarında `psp_txn_id` dolu olmalı (Hakedişler & Escrow sayfası).

## 5. Cron (oto-serbest bırakma) kurulumu
Teslim sonrası süresi dolan hakedişleri otomatik serbest bırakır.
- **Windows Task Scheduler** (WAMP): günde 1 kez
  `C:\wamp64\bin\php\php8.2.30\php.exe C:\wamp64\www\yedekparca\cron\escrow_release.php`
- **veya hit-based** (uptime servisi / harici cron): `https://SITE/cron/escrow_release.php?key=CRON_KEY`
  (CRON_KEY = Pazaryeri Ayarları'ndaki cron anahtarı; yanlış anahtar 403 döner).
- Log: `cron/escrow.log` (web'den erişilemez).
- İdempotent: aynı anda/birden çok çalışsa bile çift ödeme yapmaz.

**Ek günlük cron'lar (Task Scheduler'a ekleyin):**
- `php cron/yedek_al.php` — günlük DB yedeği (`C:/wamp64/yedek_yparca/oto`, son 14 yedek). Web: `/cron/yedek_al.php?key=CRON_KEY`.
- `php cron/mutabakat.php` — PSP mutabakatı (defter ödemeleri vs PSP settlement). PSP settlement çekimi için `pspSettlementTutar()` go-live'da entegratör API'siyle doldurulmalı.

## 6. Mali müşavir + GİB ile TEYİT (kod değil, ayar)
1. **Stopaj** matrahı (brüt mü, brüt−komisyon mu) ve **PSP/banka zaten kesiyor mu** (çift kesinti riski → varsayılan KAPALI).
2. Komisyon faturasında **KDV tevkifatı**.
3. **Fatura** sorumlulukları (satıcı→alıcı satış; pazaryeri→satıcı komisyon; e-fatura/e-arşiv).
4. **BTRANS** kesin alan/format/periyot (Pazaryeri Finans → BTRANS Raporu CSV'si örnektir, GİB kılavuzuyla doğrulayın).

## 7. Havale/EFT & Kapıda Ödeme (opsiyonel — artık finans paneline entegre)
- Bu yöntemlerde para PSP'ye uğramaz; **pazaryeri (siz) tahsil edersiniz**. Yine de `escrow_aktif` iken
  admin ödemeyi **"Sipariş Onaylandı"** yapınca defter kaydı oluşur (komisyon + stopaj hesaplı) ve
  **Hakedişler & Escrow**'da `psp='none'` → **"Havale/Manuel"** rozetiyle görünür.
- Serbest bırakma PSP çağırmaz: satıcıya **kendi banka havalenizle** ödersiniz, sonra panelden
  **"Serbest Bırak"** ile işaretlersiniz (defter/komisyon/stopaj kapanır). Kapıda da aynı mantık.
- Tamamen kapatmak isterseniz **Sanal Pos Ayarları**'ndan Havale/EFT ve Kapıda toggle'larını KAPATIN →
  kart (PSP escrow) tek yol olur. (Shopier zaten tamamen kaldırıldı.)
- Hook: `boss/pages/siparisduzenle.php` (ana-durum "Sipariş Onaylandı" + PSP-dışı yöntem → `HakedisServis::escrowKaydet(...,'none',...)`, idempotent).

## 8. Go-Live checklist
- [ ] İyzico/PayTR Pazaryeri anahtarları girildi (Sanal Pos).
- [ ] Pazaryeri Ayarları yapıldı (PSP, stopaj, oto-onay, komisyon, cron key).
- [ ] Tüm satıcılar alt-üye **Onaylı**.
- [ ] §4 checkout yaması uygulandı + **sandbox'ta çok-satıcılı sipariş test edildi** (bloke + psp_txn_id).
- [ ] Cron kuruldu (Task Scheduler) + log akıyor.
- [ ] Mali müşavir onayı (stopaj/KDV/fatura/BTRANS).
- [ ] `escrow_aktif=1` + `test_mod=0` + Havale/Kapıda kapatıldı.
- [ ] Küçük pilot sipariş + iade/serbest testi; PSP paneli ile mutabakat.

## Admin Rolleri (hazır)
`boss/inc/roller.php` — Süper Admin / Muhasebeci / Satış Danışmanı / İçerik Editörü.
Yeni admin'e **Admin Ekle**'den rol atayın; menü + sayfa erişimi otomatik filtrelenir (URL elle yazılsa bile 403).
Yeni rol/erişim eklemek için yalnızca `roller.php` düzenlenir.

## Test betikleri (web-korumalı `migrations/`)
- `faz_simulasyon.php` — finans motoru uçtan uca (escrow/iade/clawback/idempotans) — 30/30.
- `faz_cron_test.php` — oto-serbest + idempotans.
- `faz_rol_test.php` — rol erişim matrisi — 22/22.
- `faz1_sandbox_test.php` — İyzico bağlantı + para matematiği.
Çalıştırma: `php migrations/<dosya>.php`
