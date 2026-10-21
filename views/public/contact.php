<?php
/** İletişim sayfası — form POST /demo (type=contact). Form sözleşmesi DEĞİŞMEZ. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = $data['_csrf'] ?? ($_csrf ?? '');
$flash = $data['_flash'] ?? ($_flash ?? []);
$error = $flash['error'] ?? '';
$success = $flash['success'] ?? '';
$cm = trim((string) brand('contact_email', ''));
$cp = trim((string) brand('contact_phone', ''));
$ca = trim((string) brand('contact_address', ''));
$wa = preg_replace('/\D/', '', $cp !== '' ? $cp : ''); if ($wa === '') $wa = '';
$maps = trim((string) brand('google_maps_embed', ''));
$socials = [
    'instagram' => trim((string) brand('social_instagram', '')),
    'x'         => trim((string) brand('social_x', '')),
    'linkedin'  => trim((string) brand('social_linkedin', '')),
    'facebook'  => trim((string) brand('social_facebook', '')),
    'youtube'   => trim((string) brand('social_youtube', '')),
];
$lucideMap = ['instagram' => 'instagram', 'x' => 'twitter', 'linkedin' => 'linkedin', 'facebook' => 'facebook', 'youtube' => 'youtube'];
$hasSocial = array_filter($socials) !== [];
?>
<section class="section" style="padding-top:128px">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-blue mx-auto"><i data-lucide="mail"></i> İletişim</div>
    <h1 class="heading-lg">Bizimle İletişime Geçin</h1>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Sorularınız için formu doldurun ya da doğrudan ulaşın. En kısa sürede dönüş yapıyoruz.</p>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:48px;align-items:start;margin-top:48px" class="ct-grid">
    <!-- İletişim bilgileri -->
    <div class="reveal-left why-items">
      <?php if ($cm !== ''): ?><div class="why-item"><div class="why-item-icon" style="background:var(--blue-light)"><i data-lucide="mail" style="color:var(--blue)"></i></div><div><h3>E-posta</h3><p><a href="mailto:<?= $e($cm) ?>" style="color:var(--blue)"><?= $e($cm) ?></a></p></div></div><?php endif; ?>
      <?php if ($cp !== ''): ?><div class="why-item"><div class="why-item-icon" style="background:var(--green-light)"><i data-lucide="phone" style="color:var(--green)"></i></div><div><h3>Telefon</h3><p><?= $e($cp) ?></p></div></div><?php endif; ?>
      <div class="why-item"><div class="why-item-icon" style="background:#dcfce7"><i data-lucide="message-circle" style="color:#16a34a"></i></div><div><h3>WhatsApp</h3><p><a href="https://wa.me/<?= $e($wa) ?>" target="_blank" rel="noopener" style="color:var(--green)">Hemen yazın →</a></p></div></div>
      <?php if ($ca !== ''): ?><div class="why-item"><div class="why-item-icon" style="background:var(--purple-light)"><i data-lucide="map-pin" style="color:var(--purple)"></i></div><div><h3>Adres</h3><p><?= $e($ca) ?></p></div></div><?php endif; ?>
      <?php if ($hasSocial): ?>
      <div class="why-item"><div class="why-item-icon" style="background:var(--blue-light)"><i data-lucide="share-2" style="color:var(--blue)"></i></div>
        <div><h3>Sosyal Medya</h3>
          <div style="display:flex;gap:14px;margin-top:6px">
            <?php foreach ($socials as $ic => $href): if ($href === '') { continue; } ?>
            <a href="<?= $e($href) ?>" target="_blank" rel="noopener" aria-label="<?= $e($ic) ?>" style="color:var(--gray-500);transition:color .15s" onmouseover="this.style.color='var(--blue)'" onmouseout="this.style.color='var(--gray-500)'"><?= social_svg($ic, 20) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <!-- Form -->
    <div class="reveal-right" style="background:#fff;border:1px solid var(--gray-100);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:32px">
      <?php if ($success !== ''): ?><div class="auth2-alert auth2-alert-ok"><i data-lucide="check-circle"></i><span><?= $e($success) ?></span></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="auth2-alert auth2-alert-error"><i data-lucide="alert-triangle"></i><span><?= $e($error) ?></span></div><?php endif; ?>
      <form method="POST" action="<?= url('/demo') ?>" class="auth2-form" style="margin-top:0">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="type" value="contact">
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
        <div class="auth2-field"><label class="auth2-label" for="c-name">Ad Soyad *</label><div class="auth2-input-wrap"><i data-lucide="user"></i><input type="text" id="c-name" name="name" class="auth2-input" placeholder="Ad Soyad" required></div></div>
        <div class="auth2-field"><label class="auth2-label" for="c-email">E-posta *</label><div class="auth2-input-wrap"><i data-lucide="mail"></i><input type="email" id="c-email" name="email" class="auth2-input" placeholder="ornek@firma.com" required></div></div>
        <div class="auth2-field"><label class="auth2-label" for="c-phone">Telefon</label><div class="auth2-input-wrap"><i data-lucide="phone"></i><input type="tel" id="c-phone" name="phone" class="auth2-input" placeholder="5xx xxx xx xx"></div></div>
        <div class="auth2-field"><label class="auth2-label" for="c-company">Firma</label><div class="auth2-input-wrap"><i data-lucide="building-2"></i><input type="text" id="c-company" name="company" class="auth2-input" placeholder="Firma adı"></div></div>
        <div class="auth2-field"><label class="auth2-label" for="c-msg">Mesajınız *</label><textarea id="c-msg" name="message" class="auth2-input" style="height:auto;min-height:110px;padding:12px 14px;resize:vertical" placeholder="Size nasıl yardımcı olabiliriz?" required></textarea></div>
        <button type="submit" class="auth2-submit"><i data-lucide="send"></i> Mesajı Gönder</button>
      </form>
    </div>
  </div>

  <?php if ($maps !== ''): ?>
  <div class="ct-map" style="margin-top:44px;border-radius:var(--radius-xl);overflow:hidden;border:1px solid var(--gray-100);box-shadow:var(--shadow-lg)"><?= $maps ?></div>
  <?php endif; ?>
</div>
</section>
<style>
@media(max-width:900px){.ct-grid{grid-template-columns:1fr!important;gap:28px!important}}
.ct-map iframe{width:100%;height:380px;border:0;display:block}
</style>
