<?php
/** Şifremi unuttum — düz bölünmüş kabuk (site_auth + ozy-auth.css). Form sözleşmesi (action/name/_csrf/id) DEĞİŞMEZ. */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf       = $data['_csrf'] ?? '';
$error      = $data['_flash']['error'] ?? $data['error'] ?? '';
$success    = $data['_flash']['success'] ?? '';
$siteName   = brand('site_name');
$logo       = trim((string) brand('logo_url', ''));
?>
<div class="auth-shell">
  <section class="auth-panel">
    <div class="auth-inner">
      <a href="<?= url('/login') ?>" class="auth-back"><i data-lucide="arrow-left"></i> Giriş sayfasına dön</a>

      <a href="<?= url('/login') ?>" class="auth-brand" aria-label="<?= $e($siteName) ?>">
        <?php if ($logo !== ''): ?>
          <img src="<?= $e($logo) ?>" alt="<?= $e($siteName) ?>">
        <?php else: ?>
          <span class="auth-brand-mark"><?= $e(mb_substr($siteName, 0, 1)) ?></span>
          <span class="auth-brand-name"><?= $e($siteName) ?></span>
        <?php endif; ?>
      </a>

      <h1 class="auth-title">Şifremi unuttum</h1>
      <p class="auth-sub">Hesabınıza kayıtlı e-posta adresini girin, size şifre sıfırlama kodu gönderelim.</p>

      <?php if ($error): ?>
      <div class="auth-alert is-error" role="alert"><i data-lucide="alert-triangle"></i><span><?= $e($error) ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div class="auth-alert is-ok" role="status"><i data-lucide="check-circle"></i><span><?= $e($success) ?></span></div>
      <?php endif; ?>

      <form method="POST" action="<?= url('/password/forgot') ?>" id="forgotForm" class="auth-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">

        <div class="auth-field">
          <label class="auth-label" for="emailInput">E-posta adresi</label>
          <div class="auth-input-wrap">
            <i data-lucide="mail"></i>
            <input type="email" id="emailInput" name="email" class="auth-input" placeholder="ornek@firma.com" autocomplete="email" required autofocus>
          </div>
        </div>

        <button type="submit" class="auth-submit" id="forgotBtn"><i data-lucide="send"></i> Sıfırlama kodu gönder</button>
      </form>


      <p class="auth-foot">
        Şifrenizi hatırladınız mı? <a href="<?= url('/login') ?>" class="auth-link">Giriş yapın</a>
        <small>Güvenli şifre sıfırlama, oran sınırlama ve token koruması ile hesabınızı kurtarabilirsiniz.</small>
      </p>
    </div>
  </section>

  <aside class="auth-aside" aria-hidden="true">
    <div class="auth-aside-inner">
      <h2>Güvenli şifre sıfırlama</h2>
      <p>SHA-256 token hash, süre sınırlı kodlar ve oran sınırlama ile hesabınız her zaman güvende.</p>
      <ul class="auth-points">
        <li><i data-lucide="check-circle-2"></i><span><strong>Süre sınırlı kod</strong>Sıfırlama bağlantısı süresi dolunca geçersiz olur.</span></li>
        <li><i data-lucide="check-circle-2"></i><span><strong>Tek kullanımlık</strong>Kod bir kez kullanıldığında yeniden geçerli olmaz.</span></li>
        <li><i data-lucide="check-circle-2"></i><span><strong>Oran sınırlama</strong>Ardışık denemeler otomatik olarak yavaşlatılır.</span></li>
      </ul>
      <div class="auth-pills">
        <span class="auth-pill"><i data-lucide="shield-check"></i> SHA-256 token</span>
        <span class="auth-pill"><i data-lucide="timer"></i> Süreli bağlantı</span>
        <span class="auth-pill"><i data-lucide="lock"></i> Tek kullanım</span>
      </div>
    </div>
  </aside>
</div>

<script>
(function(){
  var f = document.getElementById('forgotForm'), btn = document.getElementById('forgotBtn');
  if (f && btn) f.addEventListener('submit', function(){
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-circle" class="auth-spin"></i> Gönderiliyor...';
    if (window.lucide) lucide.createIcons({ nodes: [btn] });
  });
})();
</script>
