# Destek paneli API'si (trendmaxdestek.com ↔ müşteri sitesi)

Her müşteri sitesi kendi durumunu bu uçlardan verir. Destek paneli sitenin veritabanına bağlanmaz,
veritabanı şifresi tutmaz; destek panelinin ele geçirilmesi müşteri veritabanlarını açığa çıkarmaz.

## Anahtar

1. Müşteri sitesinde **Admin → API Anahtarları → Yeni** (`/admin/api-tokens/create`).
2. Mağazayı seçin, ad örn. `trendmaxdestek.com`.
3. İzin: yalnız **support:read**. Eşleştirme şablonu uygulanacaksa ayrıca **support:mappings**.
4. IP beyaz listesine destek sunucusunun çıkış IP'sini yazın. Son geçerlilik tarihi önerilir.
5. Gösterilen `API Key` + `API Secret` destek panelinde o site için şifreli saklanır (secret bir daha gösterilmez).

Her istek iki başlık taşır: `X-API-Key`, `X-API-Secret`. Hız sınırı anahtarın dakikalık limitidir.
Anahtar bir mağazaya bağlıdır; yalnız o mağazanın verisi döner. İptal: aynı ekrandan pasifleştir / yenile.

## Uçlar

| Yöntem | Yol | İzin | Açıklama |
|---|---|---|---|
| GET | `/api/v1/support/status` | support:read | Paket süresi, pazaryeri bağlantıları, kuyruk, katalog kontrolü, zorunlu özellik özeti ve hazır uyarılar |
| GET | `/api/v1/support/attribute-issues?limit=500` | support:read | Kategori × özellik (× XML değeri) bazında eksikler, örnek stok kodları |
| POST | `/api/v1/support/attribute-issues/refresh` | support:read | Sitenin cron'una raporu birkaç dakika içinde yeniden hesaplatır |
| GET | `/api/v1/support/attribute-mappings` | support:read | Sitenin kategori özellik seçimlerini taşınabilir şablon olarak verir |
| POST | `/api/v1/support/attribute-mappings` | support:mappings | Şablonu siteye uygular (varsayılan önizleme) |

### `GET /api/v1/support/status`

```json
{"success":true,"data":{
  "generated_at":"2026-11-07T10:00:00+03:00","level":"warning",
  "app":{"name":"Trendmax","version":"1.0.0","php":"8.2"},
  "store":{"id":1,"name":"Prime Mağaza","status":"active"},
  "subscription":{"package":"Pro","status":"active","ends_at":"2026-12-01 00:00:00","days_left":23},
  "marketplaces":{"items":[{"slug":"trendyol","name":"Trendyol","connected":true,"last_success_at":"…","failed_syncs_24h":0}]},
  "jobs":{"pending":3,"failed_24h":0,"dead_7d":0,"oldest_pending_at":"…","failed_by_type":[]},
  "catalog_audit":{"enabled":true,"auto_publish":true,"counts":{"verified":5100,"needs_review":40},"rejections_7d":2,"top_rejection_reasons":[{"reason":"…","count":2}]},
  "attribute_issues":{"products_blocked":7,"categories_blocked":2,"refreshed_at":"2026-11-07 09:00:00"},
  "alerts":[{"level":"warning","code":"attribute_mapping_missing","message":"7 üründe zorunlu özellik eşleştirmesi bulunamadı (2 kategori)."}]
}}
```

`level`: `ok` | `warning` | `critical`. `alerts[].code` sabittir (ekranda gruplama için), `message` doğrudan gösterilebilir.
Kodlar: `subscription_expired`, `subscription_ending`, `subscription_inactive`, `marketplace_not_connected`,
`marketplace_sync_failed`, `jobs_dead`, `queue_stalled`, `attribute_mapping_missing`, `attribute_report_pending`,
`publish_rejected`, `catalog_audit_stalled`, `catalog_audit_failed`, `section_unavailable`.
Bir bölüm okunamazsa yalnız o bölüm `{"error":"…"}` döner; diğerleri etkilenmez.

### `GET /api/v1/support/attribute-issues`

`items[].issue_type`: `missing` (zorunlu özelliğin değeri yok), `unmatched` (XML değeri Trendyol seçeneğinde yok,
`value_text` alanında), `catalog_error` (kategorinin Trendyol özellikleri okunamadı). `mapping_key` müşteri
panelindeki eşleştirmedir (`/categories/mapping` → Özellikler). `is_varianter=1` renk/beden gibi varyant özelliğidir.

### Şablonlar ("tek kaynak")

Bir sitede düzeltilen kategori eşleştirmesi `GET …/attribute-mappings` ile alınır, diğer sitelere `POST` edilir:

```json
{"format":"trendmax.trendyol-attribute-template.v1","mode":"fill","dry_run":true,
 "templates":[{"trendyol_category_id":"2386","defaults":{"1192":"10617344"},"sources":{"348":"Renk"},
               "value_map":{"348":{"lacivertbeyaz":"1001"}}}]}
```

- Şablon Trendyol kategori kimliğiyle eşleşir; sitede bu Trendyol kategorisine eşlenmiş tüm yerel kategorilere uygulanır.
- `sources` yerel özellik **adı** taşır (kimlikler siteden siteye farklıdır); sitede olmayan ad uyarıyla atlanır.
- Tüm seçenek kimlikleri alıcı sitenin kendi Trendyol listesine göre yeniden doğrulanır; geçersiz şablon yazılmaz (`skipped`).
- `mode:"fill"` yalnız boş seçimleri doldurur, müşterinin yaptığı seçimi değiştirmez. `mode:"replace"` şablondaki özelliklerin üzerine yazar.
- `dry_run` verilmezse önizlemedir; yazmak için açıkça `false` gönderin. Ürüne özel seçimler taşınmaz.
- Değişiklik içerik onayını geçersiz kılar (ürünler yeniden onaylanır); değişmeyen şablon hiçbir şey yazmaz.
