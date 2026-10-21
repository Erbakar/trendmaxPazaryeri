<?php
/** Mağaza girişi — düz bölünmüş kabuk (site_auth + ozy-auth.css). Form sözleşmesi (action/name/_csrf/id) DEĞİŞMEZ. */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf    = $data['_csrf'] ?? ($_csrf ?? '');
$flash   = $data['_flash'] ?? ($_flash ?? []);
$error   = $flash['error'] ?? '';
$success = $flash['success'] ?? '';
$siteName = brand('site_name');
$logo    = trim((string) brand('logo_url', ''));
$shot    = asset('img/login.png');
?>
<div class="auth-shell">
  <section class="auth-panel">
    <div class="auth-inner">
      <a href="<?= url('/') ?>" class="auth-back"><i data-lucide="arrow-left"></i> Ana sayfaya dön</a>

      <a href="<?= url('/') ?>" class="auth-brand" aria-label="<?= $e($siteName) ?>">
        <?php if ($logo !== ''): ?>
          <img src="<?= $e($logo) ?>" alt="<?= $e($siteName) ?>">
        <?php else: ?>
          <span class="auth-brand-mark"><?= $e(mb_substr($siteName, 0, 1)) ?></span>
          <span class="auth-brand-name"><?= $e($siteName) ?></span>
        <?php endif; ?>
      </a>

      <h1 class="auth-title">Hesabınıza giriş yapın</h1>
      <p class="auth-sub">Sipariş, kargo, iade ve fatura akışını yönetmek için bilgilerinizle oturum açın.</p>

      <?php if ($success !== ''): ?>
      <div class="auth-alert is-ok" role="status"><i data-lucide="check-circle"></i><span><?= $e($success) ?></span></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
      <div class="auth-alert is-error" role="alert"><i data-lucide="alert-triangle"></i><span><?= $e($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" action="<?= url('/login') ?>" id="loginForm" class="auth-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">

        <div class="auth-field">
          <label class="auth-label" for="emailInput">E-posta adresi</label>
          <div class="auth-input-wrap">
            <i data-lucide="mail"></i>
            <input type="email" id="emailInput" name="email" class="auth-input" placeholder="ornek@firma.com" autocomplete="email" required autofocus>
          </div>
        </div>

        <div class="auth-field">
          <label class="auth-label" for="pwInput">Şifre</label>
          <div class="auth-input-wrap">
            <i data-lucide="lock"></i>
            <input type="password" id="pwInput" name="password" class="auth-input" placeholder="Şifrenizi girin" autocomplete="current-password" required>
            <button type="button" class="auth-toggle" id="pwToggle" aria-label="Şifreyi göster"><i data-lucide="eye" id="pwIcon"></i></button>
          </div>
        </div>

        <div class="auth-meta">
          <label class="auth-remember"><input type="checkbox" name="remember" value="1"><span>Beni hatırla</span></label>
          <a href="<?= url('/password/forgot') ?>">Şifremi unuttum</a>
        </div>

        <button type="submit" class="auth-submit" id="loginBtn"><i data-lucide="log-in"></i> Giriş Yap</button>
      </form>

      <p class="auth-foot">
        Hesabınız yok mu? <a href="<?= url('/magaza-olustur') ?>" class="auth-link">Mağaza oluşturun</a>
        <small>Güvenli oturum, oran sınırlama ve CSRF koruması ile korunmaktadır.</small>
      </p>
    </div>
  </section>

  <aside class="auth-aside" aria-hidden="true">
    <div class="auth-aside-inner">
      <div class="auth-shot">
        <div class="auth-shot-bar"><span></span><span></span><span></span></div>
        <img src="<?= $e($shot) ?>" alt="">
      </div>
      <h2>Tüm pazaryerleri tek panelde</h2>
      <p>Trendyol, Hepsiburada, Amazon, n11, Pazarama, PttAVM, ÇiçekSepeti ve İdefix akışını tek operasyon ekranında yönetin.</p>
      <ul class="auth-points">
        <li><i data-lucide="check-circle-2"></i><span><strong>Ürün ve stok</strong>Tek listeden tüm pazaryerlerine gönderin, stoğu otomatik eşitleyin.</span></li>
        <li><i data-lucide="check-circle-2"></i><span><strong>Sipariş ve kargo</strong>Siparişleri tek ekranda toplayın, kargo etiketini anında oluşturun.</span></li>
        <li><i data-lucide="check-circle-2"></i><span><strong>Fatura ve finans</strong>Faturayı otomatik kesin, hakedişi ve kârı raporlayın.</span></li>
      </ul>
      <div class="auth-pills">
        <span class="auth-pill"><i data-lucide="shield-check"></i> AES-256 şifreleme</span>
        <span class="auth-pill"><i data-lucide="zap"></i> %99.9 erişilebilirlik</span>
        <span class="auth-pill"><i data-lucide="refresh-cw"></i> 7/24 senkron</span>
      </div>
    </div>
  </aside>
</div>

<script>
(function(){
  var f = document.getElementById('loginForm'), btn = document.getElementById('loginBtn');
  var pw = document.getElementById('pwInput'), tg = document.getElementById('pwToggle'), ic = document.getElementById('pwIcon');
  if (tg) tg.addEventListener('click', function(){
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    tg.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster');
    ic.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
    if (window.lucide) lucide.createIcons();
  });
  if (f) f.addEventListener('submit', function(){
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-circle" class="auth-spin"></i> Giriş yapılıyor...';
    if (window.lucide) lucide.createIcons({ nodes: [btn] });
  });
})();
</script>
