# Dark Mode Uyum Düzeltmesi — sabit renk → tema token'ı

Bazı "bespoke" sayfaların inline `<style>` bloklarında SABİT açık renkler var
(`#fff`, `#f8fafc`, `#1e293b` vb.) → dark mode'da beyaz kutu/okunmaz yazı.
Bunları Bootstrap 5 tema token'larına çevir; böylece hem light hem dark'ta doğru görünür.

## KESİN KURALLAR
1. **SADECE `<style>` bloğu içindeki renk DEĞERLERİNİ** değiştir. HTML yapısı, sınıf adları, PHP, JS — DOKUNMA.
2. **E-posta şablonlarına DOKUNMA.** PHP `$mail->Body = '...'` içindeki tüm inline `style="...color:#555559;background:white..."` HTML'i AYNEN kalır (e-postalar light olmalı). Bunlar genelde `<table class="main contenttable">`, `border-collapse` içeren bloklardır — atla.
3. Vurgu/marka renklerini (mavi `#1456b8`/`#4f46e5`, turuncu `#f27a1a`, yeşil, kırmızı) KORU — bunlar iki temada da çalışır. Sadece **nötr** (beyaz/gri/siyah) zemin-yazı-kenar renklerini token yap.
4. Değişiklik sonrası `& "C:/wamp64/bin/php/php8.2.30/php.exe" -l <dosya>` → "No syntax errors".

## EŞLEME (sabit → token)
| Sabit renk (nötr) | Token |
|---|---|
| `#fff` / `#ffffff` / `white` (KART/panel zemini) | `var(--bs-card-bg)` |
| `#fff` (genel sayfa zemini) | `var(--bs-body-bg)` |
| `#f8fafc` `#f1f5f9` `#f9f9f9` `#fafafa` `#f5f5f5` `#f0f0f0` `#fafbfc` (açık gri zemin) | `var(--bs-secondary-bg)` |
| `linear-gradient(...,#f8fafc,#f1f5f9)` (açık başlık gradyanı) | `var(--bs-secondary-bg)` (düz) |
| `#1e293b` `#1f2937` `#333` `#212529` `#0f172a` (koyu başlık/metin) | `var(--bs-body-color)` |
| `#374151` `#475569` (gövde metni) | `var(--bs-body-color)` |
| `#64748b` `#6b7280` `#94a3b8` `#999` `#9ca3af` (soluk/ikincil metin) | `var(--bs-secondary-color)` |
| `#e5e7eb` `#e5e5e5` `#e2e8f0` `#d1d5db` `#ebeef0` (kenar/çizgi) | `var(--bs-border-color)` |
| `#0,0,0,0.04` gölgeler | aynen bırakılabilir (gölge temada sorun değil) |
| Açık tint ikon zeminleri (`#eff6ff` mavi, `#ecfdf5` yeşil, `#fef3c7` sarı, `#fef2f2` kırmızı, `#f3e8ff` mor) | sırasıyla `var(--bs-primary-bg-subtle)`, `var(--bs-success-bg-subtle)`, `var(--bs-warning-bg-subtle)`, `var(--bs-danger-bg-subtle)`, `var(--bs-info-bg-subtle)` (uygunsa) — değilse bırak |

## Hedef sayfalar (bespoke `<style>` — sayfa UI, e-posta DEĞİL)
raporlama, katalogimport, xmlyukle, tumurunler, urunekle, onaykuyrugu, topluguncelleme,
kategoriler, magazam, magazaurunler, aracverimerkezi. (sepet/hediyecekiekle/toplueposta/
urunyorumlari/mesajduzenle/epostaduzenle/magazayorumlar/blogyorumlari'deki sabit renkler
çoğunlukla E-POSTA şablonundadır → onları atla; sayfa UI'da sabit renk varsa düzelt.)
