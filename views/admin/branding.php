<?php
/** Site Ayarları (Marka & SEO) — iki sütun (sağlı-sollu), yoğun. Global Setting group='branding'. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = $values ?? [];
$val = fn(string $k) => $e($v[$k] ?? '');
boss_page_header('Site Ayarları', [['l' => 'Site Ayarları']]);
?>
<p class="text-muted fs-2 mb-3">Sistem adı, logo, firma bilgileri, sosyal medya, footer, SEO ve entegrasyonlar — tüm panel + kurumsal site buradan.</p>

<form method="post" action="<?= url('/admin/branding') ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="row g-3 align-items-start">
    <!-- ───── SOL: Kimlik + Entegrasyon ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-circle-check me-1 text-primary"></i>Kimlik</h3></div>
        <div class="card-body">
          <div class="ad-form-grid">
            <div class="c6"><label class="form-label">Sistem Adı</label><input type="text" name="s[site_name]" value="<?= $val('site_name') ?>" class="form-control"></div>
            <div class="c6"><label class="form-label">Firma Ünvanı</label><input type="text" name="s[legal_name]" value="<?= $val('legal_name') ?>" class="form-control"></div>
            <div class="full">
              <label class="form-label">Marka / Tema Rengi <span class="text-muted">(kurumsal site ana rengi)</span></label>
              <?php
                $bcRaw = trim((string) ($v['brand_color'] ?? ''));
                $bcHex = '#2563eb';
                if (preg_match('/^#?[0-9a-fA-F]{6}$/', $bcRaw)) { $bcHex = '#' . strtolower(ltrim($bcRaw, '#')); }
                elseif (preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/', $bcRaw, $m)) { $bcHex = sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])); }
              ?>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <input type="color" id="brandColorPick" value="<?= $e($bcHex) ?>" class="form-control form-control-color" style="width:48px;">
                <input type="text" id="brandColorHex" name="s[brand_color]" value="<?= $e($bcHex) ?>" placeholder="#2563eb" class="form-control font-monospace" style="max-width:140px;">
                <div class="d-flex align-items-center gap-1">
                  <?php foreach (['#2563eb', 'var(--primary)', '#059669', '#e11d48', '#d97706', '#0ea5e9'] as $preset): ?>
                  <button type="button" class="brandPreset border rounded-2" style="width:28px;height:28px;background:<?= $preset ?>" data-c="<?= $preset ?>" title="<?= $preset ?>"></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="fs-1 text-muted mt-1">Buton, gradyan ve vurgular bu renge döner. Boş bırakılırsa varsayılan mavi (#2563eb).</div>
            </div>
            <?php
            $uploads = [
              ['logo_file', 'logo_url', 'Logo', 'PNG/JPG/WEBP/SVG'],
              ['logo_dark_file', 'logo_dark_url', 'Koyu Tema Logo', 'PNG/JPG/WEBP/SVG'],
              ['favicon_file', 'favicon_url', 'Favicon', 'PNG/SVG/ICO'],
            ];
            foreach ($uploads as [$input, $key, $label, $hint]): $cur = $v[$key] ?? ''; ?>
            <div class="full">
              <div class="border rounded-3 p-3">
                <label class="form-label mb-2"><?= $e($label) ?> <span class="text-muted">(<?= $e($hint) ?>)</span></label>
                <?= boss_image_upload($input, (string) $cur, ['accept' => 'image/*,.ico', 'hint' => $hint]) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Entegrasyon & İzleme: Analytics · Reklam · Harita (sol sütunda boşluğu doldurur) -->
      <div class="card mb-0">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-chart-dots-3 me-1 text-primary"></i>Entegrasyon &amp; İzleme <span class="fw-normal text-muted fs-2">(Analytics · Reklam · Harita)</span></h3></div>
        <div class="card-body">
          <div class="ad-form-grid">
            <div class="full">
              <label class="form-label">Google Analytics Ölçüm Kimliği</label>
              <input type="text" name="s[google_analytics_id]" value="<?= $val('google_analytics_id') ?>" placeholder="G-XXXXXXXXXX" class="form-control font-monospace">
              <div class="fs-1 text-muted mt-1">GA4 (G-…), Google Ads (AW-…) veya GTM (GT-…). Önyüze otomatik <code>gtag</code> kodu eklenir.</div>
            </div>
            <div class="full">
              <label class="form-label">Google Maps Gömme Kodu</label>
              <textarea name="s[google_maps_embed]" rows="3" class="form-control font-monospace" placeholder='&lt;iframe src="https://www.google.com/maps/embed?..."&gt;&lt;/iframe&gt;'><?= $val('google_maps_embed') ?></textarea>
              <div class="fs-1 text-muted mt-1">Google Maps › <b>Paylaş › Haritayı yerleştir</b> → iframe kodunu yapıştırın. İletişim sayfasında gösterilir.</div>
            </div>
            <div class="full">
              <label class="form-label">Head Kodu <span class="text-muted">(reklam / analytics / doğrulama — &lt;head&gt; içine ham eklenir)</span></label>
              <textarea name="s[head_code]" rows="4" class="form-control font-monospace" placeholder="<!-- Google Ads / AdSense / Meta Pixel / Search Console doğrulama vb. -->"><?= $val('head_code') ?></textarea>
              <div class="fs-1 text-muted mt-1"><i class="ti ti-alert-triangle me-1 text-warning"></i>Buraya yazdığınız kod önyüzde <b>aynen</b> çalışır — yalnız güvendiğiniz kaynaklardan yapıştırın.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── SAĞ: İletişim · Sosyal · Footer/SEO ───── -->
    <div class="col-12 col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-address-book me-1 text-primary"></i>İletişim Bilgileri</h3></div>
        <div class="card-body">
          <div class="ad-form-grid">
            <div class="c6"><label class="form-label">E-posta</label><input type="email" name="s[contact_email]" value="<?= $val('contact_email') ?>" class="form-control"></div>
            <div class="c6"><label class="form-label">Telefon</label><input type="text" name="s[contact_phone]" value="<?= $val('contact_phone') ?>" class="form-control"></div>
            <div class="full"><label class="form-label">Adres</label><input type="text" name="s[contact_address]" value="<?= $val('contact_address') ?>" class="form-control"></div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-share me-1 text-primary"></i>Sosyal Medya</h3></div>
        <div class="card-body">
          <div class="ad-form-grid cols-auto">
            <?php foreach (['social_instagram' => 'Instagram', 'social_x' => 'X (Twitter)', 'social_linkedin' => 'LinkedIn', 'social_facebook' => 'Facebook', 'social_youtube' => 'YouTube'] as $k => $label): ?>
            <div><label class="form-label"><?= $e($label) ?></label><input type="text" name="s[<?= $k ?>]" value="<?= $val($k) ?>" placeholder="https://" class="form-control"></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card mb-0">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-search me-1 text-primary"></i>Footer &amp; SEO</h3></div>
        <div class="card-body">
          <div class="ad-form-grid">
            <div class="full"><label class="form-label">Footer HTML <span class="text-muted">(script/iframe otomatik temizlenir)</span></label><textarea name="s[footer_html]" rows="3" class="form-control font-monospace"><?= $val('footer_html') ?></textarea></div>
            <div class="full"><label class="form-label">Varsayılan SEO Başlık</label><input type="text" name="s[seo_default_title]" value="<?= $val('seo_default_title') ?>" class="form-control"></div>
            <div class="full"><label class="form-label">Varsayılan SEO Açıklama</label><input type="text" name="s[seo_default_description]" value="<?= $val('seo_default_description') ?>" class="form-control"></div>
            <div class="full"><label class="form-label">SEO Anahtar Kelimeler <span class="text-muted">(virgülle ayırın)</span></label><input type="text" name="s[seo_default_keywords]" value="<?= $val('seo_default_keywords') ?>" placeholder="pazaryeri entegrasyon, trendyol, hepsiburada, e-arşiv fatura…" class="form-control"></div>
            <div class="full"><label class="form-label">Varsayılan OG Görsel (URL)</label><input type="text" name="s[seo_default_og_image]" value="<?= $val('seo_default_og_image') ?>" class="form-control"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2 mt-3">
    <a href="<?= url('/admin/branding') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
  </div>
</form>

<script>
(function(){
  var pick=document.getElementById('brandColorPick'), hex=document.getElementById('brandColorHex');
  if(!pick||!hex) return;
  function norm(v){ v=(v||'').trim(); if(/^#?[0-9a-fA-F]{6}$/.test(v)) return '#'+v.replace('#','').toLowerCase(); return null; }
  pick.addEventListener('input', function(){ hex.value=pick.value; });
  hex.addEventListener('input', function(){ var n=norm(hex.value); if(n) pick.value=n; });
  document.querySelectorAll('.brandPreset').forEach(function(b){ b.addEventListener('click', function(){ hex.value=b.dataset.c; pick.value=b.dataset.c; }); });
})();
</script>
