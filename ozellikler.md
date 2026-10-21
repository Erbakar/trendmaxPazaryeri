# OzyConnect Panel — Sistem Özellikleri (A'dan Z'ye)

> **Versiyon:** 1.0.0 | **Teknoloji:** PHP 8.x, MySQL, jQuery, DataTables, Chart.js, Lucide Icons
> **Geliştirici:** OziyWeb | **Analiz Tarihi:** 01.05.2026

---

## 1. Desteklenen Pazaryerleri (7 Adet)

| # | Pazaryeri | Slug | Renk | Ürün | Sipariş | İade | Finans | Soru-Cevap | Stok Sync | Fiyat Sync |
|---|-----------|------|------|------|---------|------|--------|------------|-----------|------------|
| 1 | Trendyol | `trendyol` | #f27a1a | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 2 | Hepsiburada | `hepsiburada` | #ff6000 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 3 | n11 | `n11` | #7b2d96 | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ |
| 4 | Amazon TR | `amazon` | #ff9900 | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ |
| 5 | Pazarama | `pazarama` | #00a8e1 | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| 6 | ÇiçekSepeti | `ciceksepeti` | #e91e63 | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ |
| 7 | İdefix | `idefix` | #1a1a2e | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ |

---

## 2. Stok Yönetimi (StockManager) — Kritik Akış

### 2.1 Sipariş Geldiğinde Stok Düşme Akışı

```
Pazaryerinden sipariş geldi (ör: Trendyol)
    │
    ▼
OrderSyncService → Siparişi normalize et → DB'ye kaydet
    │
    ▼
StockManager::reserveOrderStock()
    │
    ├── 1. Transaction başlat (atomik)
    │   ├── SKU/Barkod ile ürünü bul (order_items.product_id)
    │   ├── SELECT FOR UPDATE → race condition önleme
    │   ├── products.stock_qty -= sipariş adedi
    │   ├── Negatif stok koruması (min: 0)
    │   └── stock_movements tablosuna kaydet (tip: 'reserve')
    │
    ├── 2. Transaction bitti
    │
    └── 3. broadcastStock() → TÜM aktif pazaryerlerine güncel stoğu gönder
        ├── Trendyol API → updateStock()
        ├── Hepsiburada API → updateStock()
        ├── n11 API → updateStock()
        ├── Pazarama API → updateStock()
        ├── ÇiçekSepeti API → updateStock()
        ├── İdefix API → updateStock()
        └── Amazon API → updateStock()
```

> [!IMPORTANT]
> **Ana Havuz Mantığı:** Stok `products.stock_qty` alanında tutulur. Herhangi bir pazaryerinden sipariş geldiğinde bu merkezi stok düşer ve güncel değer **tüm pazaryerlerine** otomatik yayınlanır.

### 2.2 Stok Hareket Tipleri

| Tip | Açıklama | Stok Etkisi |
|-----|----------|-------------|
| `reserve` | Sipariş geldiğinde stok rezerve | ➖ Düşer |
| `sale` | Kargoya verilince rezerv → satışa dönüşür | Değişmez (zaten düşmüş) |
| `cancel` | Sipariş iptalinde stok geri eklenir | ➕ Artar |
| `return` | İade onaylandığında stok geri eklenir | ➕ Artar |
| `manual_in` | Manuel stok girişi | ➕ Artar |
| `manual_out` | Manuel stok çıkışı | ➖ Düşer |
| `bulk_in` | CSV/toplu stok girişi | ➕ Artar |
| `bulk_out` | CSV/toplu stok çıkışı | ➖ Düşer |

### 2.3 Pazaryeri Bazlı Stok Offset

Her ürünün her pazaryeri için ayrı bir `stock_offset` değeri olabilir.

```
Gönderilen Stok = max(0, products.stock_qty - marketplace_products.stock_offset)
```

Örnek: Ana stok 100 ise ve Trendyol offset=10 ise → Trendyol'a 90 gönderilir.

### 2.4 Stok Broadcast (Yayınlama)

Stok değiştiğinde `broadcastStock()` çağrılır:
- Tüm **aktif** pazaryerlerini tarar
- Her pazaryerine `marketplace_products` tablosundan barkod/SKU eşleşmesi yapar
- `PriceRuleEngine` ile fiyatı hesaplar
- API üzerinden stok + fiyat günceller
- Başarı/hata durumu `marketplace_products.last_sent_at` alanına kaydedilir

---

## 3. Fiyat Yönetimi (PriceRuleEngine)

### 3.1 Fiyat Hesaplama Hiyerarşisi

```
1. marketplace_products.price_rule_type (ürün bazlı override — en yüksek öncelik)
   ↓ yoksa
2. price_rules: apply_to='product' (ürüne özel kural)
   ↓ yoksa
3. price_rules: apply_to='category' (kategoriye özel kural)
   ↓ yoksa
4. price_rules: apply_to='all' (genel kural)
   ↓ yoksa
5. base_price direkt gönderilir
```

### 3.2 Fiyat Kuralı Tipleri (PriceRuleType Enum)

- `percent_increase` — Yüzde artır (ör: %10 artır)
- `percent_decrease` — Yüzde azalt
- `fixed_increase` — Sabit tutar artır (ör: +50₺)
- `fixed_decrease` — Sabit tutar azalt

### 3.3 Güvenlik Sınırları

- `min_price` / `max_price` → Genel kurallar için alt/üst sınır
- **Maliyet koruması:** Hesaplanan fiyat `cost_price` altına düşerse maliyet fiyatına sabitlenir
- `list_price` her zaman `sale_price`'tan büyük veya eşit

---

## 4. Sipariş Yönetimi

### 4.1 Sipariş Senkronizasyonu

- **Cron:** `cron/sync_orders.php`
- Tüm aktif pazaryerlerinden siparişler çekilir
- Her pazaryerinin kendi `fetchOrders()` ve `normalizeOrder()` metodu var
- Siparişler normalize edildikten sonra `orders` + `order_items` tablolarına kaydedilir
- SKU/Barkod eşleşmesiyle `order_items.product_id` otomatik doldurulur
- Sipariş kaydedildikten sonra `StockManager::reserveOrderStock()` çağrılır

### 4.2 Sipariş Durumları (OrderStatus Enum)

| Durum | Açıklama |
|-------|----------|
| `yeni` | Yeni sipariş |
| `hazirlaniyor` | Hazırlanıyor |
| `faturalandi` | Fatura kesildi |
| `kargoda` | Kargoya verildi |
| `teslim_edildi` | Teslim edildi |
| `teslim_edilemedi` | Teslim edilemedi |
| `iptal` | İptal edildi |
| `bolundu` | Bölündü |

### 4.3 Sipariş Kargoya Verilince

```
Kullanıcı "Kargoya Ver" butonuna tıklar
    │
    ├── Pazaryeri API'sine kargo bilgisi gönderilir
    ├── StockManager::confirmOrderStock() → reserve → sale dönüşümü
    └── Fatura otomatik oluşturulabilir
```

### 4.4 Sipariş İptal Edilince

```
StockManager::cancelOrderReservation()
    │
    ├── stock_movements'ten reserve/sale kayıtlarını bul
    ├── Her ürünün stoğunu geri artır
    ├── Yeni hareket kaydet (tip: 'cancel')
    └── broadcastStock() → tüm pazaryerlerine güncel stok
```

---

## 5. İade Yönetimi

### 5.1 Desteklenen Pazaryerleri

Trendyol, n11, Pazarama, Hepsiburada, İdefix

### 5.2 İade Akışı

```
Pazaryerinden iade talebi gelir → ReturnSyncService
    │
    ├── İade normalize edilir → returns tablosuna kaydedilir
    ├── Sipariş ile eşleştirilir (marketplace_order_id)
    │
    ▼
Kullanıcı iade talep yönetimi:
    ├── Onayla → Pazaryeri API'sine onay gönderilir
    │            → StockManager::processReturn() → stok geri artar
    │            → broadcastStock() → tüm pazaryerlerine güncelleme
    ├── Reddet → Pazaryeri API'sine ret gönderilir (sebep zorunlu)
    └── Ertele (sadece n11) → Pazaryeri API'sine erteleme gönderilir
```

---

## 6. Ürün Yönetimi

### 6.1 Ürün Havuzu

- **Ana Havuz:** `products` tablosu — merkezi ürün deposu
- **Pazaryeri Ürünleri:** `marketplace_products` tablosu — her ürünün her pazaryerindeki durumu
- Ürün resmi: `products.main_image` + `marketplace_products.main_image`
- Sipariş detayında resim COALESCE ile eşleştirilir (önce ana ürün, yoksa pazaryeri ürünü)

### 6.2 Ürün Import Akışı

```
Pazaryerleri > İdefix > "Sistemle Eşleştir" butonuna tıkla
    │
    ▼
Onboarding Wizard açılır → adım adım:
    1. Markalar çekilir
    2. Kategoriler çekilir
    3. Ürünler sayfa sayfa çekilir (max 50 sayfa, 5000 ürün)
       ├── SKU/Barkod eşleşmesi yapılır
       ├── Eşleşen → marketplace_products güncellenir
       └── Yeni → products + marketplace_products oluşturulur
    4. Siparişler çekilir (gün bazlı)
    5. İadeler çekilir
    6. Finans çekilir (sadece Trendyol, Hepsiburada)
    7. Soru-Cevap çekilir (sadece Trendyol, Hepsiburada)
```

### 6.3 CSV Import

`CsvImportService` ile toplu ürün/stok yükleme desteklenir.

### 6.4 Ürün Zenginleştirme (Enrichment)

`ProductEnrichmentService` ile ürün bilgileri otomatik zenginleştirilebilir.

---

## 7. Finans Modülü

### 7.1 Desteklenen Pazaryerleri

- **Trendyol:** Settlements + OtherFinancials endpoint'leri
- **Hepsiburada:** Finans API

### 7.2 Veri Çekme

- Gün bazlı çekim (`syncDay` metodu)
- Her gün ayrı API sorgusu → `financial_transactions` tablosuna kaydedilir
- `external_id` ile duplicate kontrolü (aynı işlem tekrar eklenmez)
- Varsayılan filtre: Son 30 gün

---

## 8. Müşteri Yönetimi

- Siparişler senkronize edildiğinde müşteriler **otomatik** oluşturulur
- `customers` tablosunda: ad, e-posta, telefon, TC, vergi no, adres bilgileri
- Pazaryeri bazlı filtreleme
- Müşteri düzenleme (TC, VKN, adres vb.)
- Toplam sipariş ve harcama istatistikleri

---

## 9. Marka & Kategori Yönetimi

### 9.1 Marka Eşleştirme

- **Sistem Markaları:** `brands` tablosu (ana havuz)
- **Pazaryeri Markaları:** `marketplace_brands` tablosu
- `brand_marketplace_mappings` ile eşleştirme
- `brand_aliases` ile alternatif isim desteği

### 9.2 Kategori Eşleştirme

- **Sistem Kategorileri:** `categories` tablosu (ağaç yapısı)
- **Pazaryeri Kategorileri:** `marketplace_categories` tablosu
- `category_marketplace_mappings` ile eşleştirme

### 9.3 Trendyol Marka Çekme

- API: `/brands` endpoint'i, `size=2000` (API max)
- Pagination ile tüm markalar çekilir (on binlerce marka)
- Arama: `/brands/by-name` endpoint'i

---

## 10. Fatura Sistemi

### 10.1 E-Arşiv Fatura

- `GibEarsivProvider` — GİB e-arşiv entegrasyonu
- `UblTrBuilder` — UBL-TR XML formatı oluşturma
- Otomatik veya manuel fatura oluşturma
- Fatura durumları: taslak, gönderildi, onaylandı, iptal

### 10.2 Cron

- `cron/generate_invoices.php` → Bekleyen siparişler için otomatik fatura

---

## 11. Soru-Cevap Modülü

### 11.1 Desteklenen Pazaryerleri

- **Trendyol** ✅ (çekme + cevaplama)
- **Hepsiburada** ✅ (çekme + cevaplama)
- **Pazarama** ✅ (çekme + cevaplama)
- **İdefix** ❌ (API desteği yok — devre dışı)

### 11.2 Akış

```
Pazaryerinden sorular çekilir → questions tablosuna kaydedilir
    │
    ▼
Kullanıcı cevap yazar → Pazaryeri API'sine cevap gönderilir
    │
    └── questions.status = 'answered'
```

---

## 12. Senkronizasyon Sistemi

### 12.1 Manuel Senkronizasyon

Sync sayfasından (views/sync) tüm veri tipleri için tek tıkla:
- Siparişler, Stok, Fiyat, İadeler, Sorular

### 12.2 Otomatik Senkronizasyon (Cron Jobs)

| Cron Dosyası | İşlev |
|---|---|
| `sync_orders.php` | Siparişleri çek |
| `sync_stock.php` | Stok güncelle |
| `sync_prices.php` | Fiyat güncelle |
| `sync_returns.php` | İadeleri çek |
| `sync_questions.php` | Soruları çek |
| `sync_import_delta.php` | Delta ürün import |
| `generate_invoices.php` | Otomatik fatura |
| `generate_feeds.php` | XML feed oluştur |
| `generate_backups.php` | Veritabanı yedekleme |

### 12.3 Sync Log

Her senkronizasyon `sync_logs` tablosuna kaydedilir:
- Başlangıç/bitiş zamanı
- Başarılı/hatalı kayıt sayısı
- Hata mesajları

---

## 13. XML Feed Sistemi

### 13.1 Feed Tipleri

- **Google Shopping Feed** (`GoogleShoppingFeed`)
- **Meta (Facebook) Catalog Feed** (`MetaCatalogFeed`)
- **Genel XML Feed** (`GenericFeed`)

### 13.2 Akış

```
Ürünler → XmlFeedService → XML oluştur → /feeds/{id}.xml URL'i ile erişim
```

---

## 14. Dashboard

- **Bugün:** Sipariş sayısı, ciro, ortalama sepet
- **Hafta:** Haftalık karşılaştırma
- **Stok Özeti:** Toplam ürün, düşük stok, stoksuz
- **7 Günlük Grafik:** Chart.js ile günlük sipariş/ciro
- **Pazaryeri Dağılımı:** Her pazaryerinin sipariş/ciro payı
- **Son Siparişler:** En son 10 sipariş
- **Düşük Stok Uyarıları:** Stoğu kritik seviyede olan ürünler
- **Bekleyen İadeler / Faturalar:** Sayısal uyarılar
- **Son Senkronizasyonlar:** Her pazaryerinin son başarılı sync'i

---

## 15. API Sistemi

### 15.1 Endpoint'ler

| Controller | Yetki | İşlev |
|---|---|---|
| `ApiProductController` | `products:read/write` | Ürün CRUD |
| `ApiOrderController` | `orders:read/write` | Sipariş CRUD |
| `ApiStockController` | `stock:write` | Stok güncelleme |
| `ApiBrandController` | `brands:read` | Marka listeleme |
| `ApiCategoryController` | `categories:read` | Kategori listeleme |

### 15.2 Kimlik Doğrulama

- `ApiAuthMiddleware` → Bearer Token (api_tokens tablosu)
- `ApiRateLimitMiddleware` → Rate limiting

### 15.3 API Dokümantasyonu

- `/api/docs` → Swagger benzeri interaktif dökümantasyon sayfası

---

## 16. Güvenlik

### 16.1 Middleware Katmanı

| Middleware | İşlev |
|---|---|
| `AuthMiddleware` | Oturum kontrolü |
| `CsrfMiddleware` | CSRF token doğrulama |
| `RateLimitMiddleware` | İstek hız sınırlama |
| `ApiAuthMiddleware` | API token doğrulama |
| `ApiRateLimitMiddleware` | API rate limiting |

### 16.2 Şifreleme

- `CryptoService` → API key/secret AES şifreleme (veritabanında şifreli saklanır)

### 16.3 Rol Tabanlı Yetkilendirme

- `AuthorizationService` → Rol bazlı izin kontrolü
- Her modül için ayrı okuma/yazma yetkileri
- Roller: `roles` tablosunda tanımlı

---

## 17. Yedekleme Sistemi

- `BackupService` → Veritabanı yedeği (SQL dump)
- Manuel veya cron ile otomatik
- Yedek dosyaları storage klasöründe saklanır

---

## 18. Kargo Yönetimi

- `CargoLabelService` → Kargo etiketi oluşturma
- Kargo firması + takip numarası ile pazaryeri API'sine bildirim
- Desteklenen kargo firmaları: Yurtiçi, Aras, MNG, PTT, Sürat, UPS, Sendeo, Horoz, Tex

---

## 19. Bildirim Sistemi

- `NotificationService` → Sistem içi bildirimler
- Dashboard'da bildirim sayacı

---

## 20. Teknik Altyapı

### 20.1 Core Bileşenler

| Bileşen | Dosya | İşlev |
|---|---|---|
| Container | `Core/Container.php` | DI Container (Dependency Injection) |
| Database | `Core/Database.php` | PDO wrapper, transaction, reconnect |
| Router | `Core/Router.php` | URL routing |
| Request | `Core/Request.php` | HTTP request parsing |
| Response | `Core/Response.php` | HTTP response (JSON, redirect) |
| Session | `Core/Session.php` | Oturum yönetimi |
| Logger | `Core/Logger.php` | Dosya bazlı loglama |

### 20.2 Veritabanı Tabloları (Ana)

| Tablo | İşlev |
|---|---|
| `products` | Ana ürün havuzu |
| `marketplace_products` | Pazaryeri-ürün eşleşmeleri |
| `orders` | Siparişler |
| `order_items` | Sipariş kalemleri |
| `customers` | Müşteriler |
| `brands` | Markalar |
| `marketplace_brands` | Pazaryeri markaları |
| `categories` | Kategoriler |
| `marketplace_categories` | Pazaryeri kategorileri |
| `returns` | İade talepleri |
| `questions` | Müşteri soruları |
| `financial_transactions` | Finansal işlemler |
| `invoices` | Faturalar |
| `stock_movements` | Stok hareketleri |
| `price_rules` | Fiyat kuralları |
| `sync_logs` | Senkronizasyon logları |
| `api_tokens` | API anahtarları |
| `users` | Kullanıcılar |
| `roles` | Roller |
| `settings` | Sistem ayarları |

### 20.3 Kurulum

- `installer.php` → Web tabanlı kurulum sihirbazı
- `ozyconnect.sql` → Başlangıç veritabanı şeması
- `.env` → Ortam değişkenleri (DB bağlantısı, şifreleme anahtarı)

---

## 21. Frontend Teknolojileri

| Kütüphane | Versiyon | Kullanım |
|---|---|---|
| jQuery | 3.7.1 | DOM, AJAX |
| DataTables | - | Tablolar (server-side) |
| Chart.js | UMD | Dashboard grafikleri |
| Lucide | - | İkon seti |
| Quill.js | - | Zengin metin editörü (ürün açıklamaları) |

> [!NOTE]
> jQuery, DataTables, Chart.js ve Lucide **layout'tan** tek seferde yüklenir. View dosyalarında duplicate yükleme yapılmamalıdır.
