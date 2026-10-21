<?php
/** Demo Talebi — form POST /demo (type=demo). Form sözleşmesi DEĞİŞMEZ. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = $data['_csrf'] ?? ($_csrf ?? '');
$flash = $data['_flash'] ?? ($_flash ?? []);
$error = $flash['error'] ?? '';
$success = $flash['success'] ?? '';
?>
<section class="section" style="padding-top:128px">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-green mx-auto"><i data-lucide="play-circle"></i> Demo Talebi</div>
    <h1 class="heading-lg">Paneli Canlı Görün</h1>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Bilgilerinizi bırakın; size özel bir demo planlayalım ve tüm özellikleri birlikte inceleyelim.</p>
  </div>
  <div style="max-width:560px;margin:44px auto 0;background:#fff;border:1px solid var(--gray-100);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:32px" class="reveal">
    <?php if ($success !== ''): ?><div class="auth2-alert auth2-alert-ok"><i data-lucide="check-circle"></i><span><?= $e($success) ?></span></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="auth2-alert auth2-alert-error"><i data-lucide="alert-triangle"></i><span><?= $e($error) ?></span></div><?php endif; ?>
    <form method="POST" action="<?= url('/demo') ?>" class="auth2-form" style="margin-top:0">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="type" value="demo">
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <div class="auth2-field"><label class="auth2-label" for="d-name">Ad Soyad *</label><div class="auth2-input-wrap"><i data-lucide="user"></i><input type="text" id="d-name" name="name" class="auth2-input" placeholder="Ad Soyad" required></div></div>
      <div class="auth2-field"><label class="auth2-label" for="d-email">E-posta *</label><div class="auth2-input-wrap"><i data-lucide="mail"></i><input type="email" id="d-email" name="email" class="auth2-input" placeholder="ornek@firma.com" required></div></div>
      <div class="auth2-field"><label class="auth2-label" for="d-phone">Telefon</label><div class="auth2-input-wrap"><i data-lucide="phone"></i><input type="tel" id="d-phone" name="phone" class="auth2-input" placeholder="5xx xxx xx xx"></div></div>
      <div class="auth2-field"><label class="auth2-label" for="d-company">Firma</label><div class="auth2-input-wrap"><i data-lucide="building-2"></i><input type="text" id="d-company" name="company" class="auth2-input" placeholder="Firma adı"></div></div>
      <div class="auth2-field"><label class="auth2-label" for="d-msg">Mesajınız</label><textarea id="d-msg" name="message" class="auth2-input" style="height:auto;min-height:90px;padding:12px 14px;resize:vertical" placeholder="Hangi pazaryerlerini kullanıyorsunuz? (opsiyonel)"></textarea></div>
      <button type="submit" class="auth2-submit"><i data-lucide="calendar-check"></i> Demo Talep Et</button>
    </form>
  </div>
</div>
</section>
