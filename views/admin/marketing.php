<?php
/** Pazarlama & Reklam Pikselleri — Meta / Google / TikTok (client pixel + server-side Conversions API). Global Setting group='marketing'. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = $values ?? [];
$t = fn(string $k) => $e($v[$k] ?? '');
$b = fn(string $k) => !empty($v[$k]) ? 'checked' : '';
boss_page_header('Pazarlama & Reklam Pikselleri', [['l' => 'Pazarlama']]);
?>
<p class="text-muted fs-2 mb-3">Kurumsal sitede çalışan reklam pikselleri ve sunucu-taraflı dönüşüm API'leri. Sayfa görüntüleme otomatik; form gönderimleri (iletişim/demo) <b>Lead</b>, üyelik <b>CompleteRegistration</b>, otomatik abonelik <b>Purchase</b> olayı tetikler. Server-side (Conversions API) iOS/adblock kaynaklı veri kaybını telafi eder.</p>

<form method="post" action="<?= url('/admin/marketing') ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
  <div class="row g-3 align-items-start">

    <!-- ───── META (Facebook / Instagram) ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h3 class="card-title"><i class="ti ti-brand-meta me-1" style="color:#0866ff"></i>Meta Pixel (Facebook / Instagram)</h3>
          <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" name="s[meta_enabled]" value="1" <?= $b('meta_enabled') ?>>
            <span class="form-check-label fs-3">Etkin</span>
          </label>
        </div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">Pixel ID</label>
            <input type="text" name="s[meta_pixel_id]" value="<?= $t('meta_pixel_id') ?>" class="form-control font-monospace" placeholder="1234567890123456">
            <div class="fs-1 text-muted mt-1">Events Manager → Veri Kaynakları → Pixel.</div>
          </div>
          <div class="mb-2"><label class="form-label">Conversions API Erişim Token'ı <span class="text-muted">(sunucu-taraflı)</span></label>
            <input type="password" name="s[meta_capi_token]" value="<?= $t('meta_capi_token') ?>" class="form-control font-monospace" placeholder="EAAB... (uzun token)" autocomplete="off">
            <div class="fs-1 text-muted mt-1">Events Manager → Ayarlar → Conversions API → Erişim Token'ı Oluştur.</div>
          </div>
          <div class="mb-1"><label class="form-label">Test Event Code <span class="text-muted">(opsiyonel)</span></label>
            <input type="text" name="s[meta_test_event_code]" value="<?= $t('meta_test_event_code') ?>" class="form-control font-monospace" placeholder="TEST12345">
            <div class="fs-1 text-muted mt-1">Test Events ekranındaki kod; canlıya alırken boşaltın.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── GOOGLE (GA4 + Ads) ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h3 class="card-title"><i class="ti ti-brand-google me-1" style="color:#ea4335"></i>Google (Analytics 4 + Ads)</h3>
          <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" name="s[google_enabled]" value="1" <?= $b('google_enabled') ?>>
            <span class="form-check-label fs-3">Etkin</span>
          </label>
        </div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">Ölçüm Kimliği <span class="text-muted">(GA4 G- / Ads AW- / GTM GT-)</span></label>
            <input type="text" name="s[google_analytics_id]" value="<?= $t('google_analytics_id') ?>" class="form-control font-monospace" placeholder="G-XXXXXXX">
            <div class="fs-1 text-muted mt-1">gtag Consent Mode v2 ile otomatik yüklenir. <b>Etkin</b> kapalıyken GA4 ve Ads çalışmaz. (Bu alan Site Ayarları ile ortaktır.)</div>
          </div>
          <div class="mb-2"><label class="form-label">Google Ads Dönüşüm Kimliği <span class="text-muted">(opsiyonel)</span></label>
            <input type="text" name="s[google_ads_id]" value="<?= $t('google_ads_id') ?>" class="form-control font-monospace" placeholder="AW-XXXXXXXXX">
            <div class="fs-1 text-muted mt-1">Ads dönüşümlerini ayrıca saymak için.</div>
          </div>
          <div class="mb-1"><label class="form-label">Measurement Protocol API Secret <span class="text-muted">(sunucu-taraflı GA4)</span></label>
            <input type="password" name="s[ga4_api_secret]" value="<?= $t('ga4_api_secret') ?>" class="form-control font-monospace" placeholder="xxxxxxxx" autocomplete="off">
            <div class="fs-1 text-muted mt-1">GA4 → Yönetici → Veri Akışları → Measurement Protocol API gizli anahtarları.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── GOOGLE TAG MANAGER + CONSENT MODE v2 ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h3 class="card-title"><i class="ti ti-tag me-1" style="color:#4285f4"></i>Google Tag Manager + Consent Mode v2</h3>
          <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" name="s[gtm_enabled]" value="1" <?= $b('gtm_enabled') ?>>
            <span class="form-check-label fs-3">Etkin</span>
          </label>
        </div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">GTM Container ID</label>
            <input type="text" name="s[gtm_container_id]" value="<?= $t('gtm_container_id') ?>" class="form-control font-monospace" placeholder="GTM-XXXXXXX">
            <div class="fs-1 text-muted mt-1">Tek konteyner; tüm etiketleri GTM'den yönetin. <code>&lt;head&gt;</code> betiği + <code>&lt;noscript&gt;</code> otomatik basılır.</div>
          </div>
          <div class="alert alert-info py-2 px-3 fs-2 mb-0" role="alert" style="line-height:1.6">
            <i class="ti ti-shield-check me-1"></i><b>Consent Mode v2</b> otomatik etkin: Google etiketleri (GTM/GA4/Ads) <b>her zaman</b> yüklenir ama çerez onayı verilene kadar <code>denied</code> (çerezsiz mod) çalışır; ziyaretçi <b>Tümünü Kabul Et</b> deyince <code>granted</code>'a yükselir. Meta &amp; TikTok ise onay olmadan <b>hiç</b> yüklenmez.
          </div>
        </div>
      </div>
    </div>

    <!-- ───── TIKTOK ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h3 class="card-title"><i class="ti ti-brand-tiktok me-1"></i>TikTok Pixel</h3>
          <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" name="s[tiktok_enabled]" value="1" <?= $b('tiktok_enabled') ?>>
            <span class="form-check-label fs-3">Etkin</span>
          </label>
        </div>
        <div class="card-body">
          <div class="mb-2"><label class="form-label">Pixel ID</label>
            <input type="text" name="s[tiktok_pixel_id]" value="<?= $t('tiktok_pixel_id') ?>" class="form-control font-monospace" placeholder="CXXXXXXXXXXXXXXXXX">
            <div class="fs-1 text-muted mt-1">TikTok Events Manager → Web Events → Pixel.</div>
          </div>
          <div class="mb-1"><label class="form-label">Events API Erişim Token'ı <span class="text-muted">(sunucu-taraflı)</span></label>
            <input type="password" name="s[tiktok_events_token]" value="<?= $t('tiktok_events_token') ?>" class="form-control font-monospace" placeholder="(uzun token)" autocomplete="off">
            <div class="fs-1 text-muted mt-1">Events Manager → Ayarlar → Events API → Token oluştur.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── BİLGİ ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-info-circle me-1 text-primary"></i>Nasıl Çalışır?</h3></div>
        <div class="card-body fs-3">
          <ul class="mb-0 ps-3" style="line-height:1.9">
            <li><b>Sayfa görüntüleme</b> — etkin tüm pikseller otomatik <code>PageView</code> gönderir.</li>
            <li><b>İletişim / Demo formu</b> → <code>Lead</code> (hem tarayıcı hem sunucu).</li>
            <li><b>Üyelik (e-posta doğrulama)</b> → <code>CompleteRegistration</code>.</li>
            <li><b>Otomatik abonelik açılışı</b> → <code>Purchase</code>.</li>
            <li>Tarayıcı ve sunucu olayları <b>aynı event_id</b> ile gönderilir → platform çift saymaz.</li>
            <li>Yalnızca <b>Etkin</b> ve ID/Token dolu platformlar çalışır.</li>
          </ul>
        </div>
      </div>
    </div>

  </div>

  <div class="mt-2 mb-4">
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
  </div>
</form>
