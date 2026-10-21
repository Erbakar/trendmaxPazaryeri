<?php
/** Yeni şifre belirle — düz bölünmüş kabuk (site_auth + ozy-auth.css). Form sözleşmesi (action/name/_csrf/token/id) DEĞİŞMEZ. */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf     = $data['_csrf'] ?? '';
$error    = $data['_flash']['error'] ?? $data['error'] ?? '';
$token    = $data['token'] ?? '';
$siteName = brand('site_name');
$logo     = trim((string) brand('logo_url', ''));
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

      <h1 class="auth-title">Yeni şifre belirleyin</h1>
      <p class="auth-sub">Güvenli yeni şifrenizi aşağıya girin. Şifreniz en az 6 karakter olmalıdır.</p>

      <?php if ($error): ?>
      <div class="auth-alert is-error" role="alert"><i data-lucide="alert-triangle"></i><span><?= $e($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" action="<?= url('/password/reset') ?>" id="resetForm" class="auth-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="token" value="<?= $e($token) ?>">

        <div class="auth-field">
          <label class="auth-label" for="pwInput">Yeni şifre</label>
          <div class="auth-input-wrap">
            <i data-lucide="lock"></i>
            <input type="password" id="pwInput" name="password" class="auth-input" placeholder="En az 6 karakter" autocomplete="new-password" minlength="6" required autofocus>
            <button type="button" class="auth-toggle" id="pwToggle1" aria-label="Şifreyi göster"><i data-lucide="eye" id="pwIcon1"></i></button>
          </div>
          <div class="auth-strength" id="pwStrength" aria-hidden="true"><span id="pwStrengthBar"></span></div>
          <span class="auth-strength-text" id="pwStrengthText" aria-live="polite"></span>
        </div>

        <div class="auth-field">
          <label class="auth-label" for="pwConfirmInput">Şifre tekrar</label>
          <div class="auth-input-wrap">
            <i data-lucide="lock-keyhole"></i>
            <input type="password" id="pwConfirmInput" name="password_confirmation" class="auth-input" placeholder="Şifrenizi tekrar girin" autocomplete="new-password" minlength="6" required>
            <button type="button" class="auth-toggle" id="pwToggle2" aria-label="Şifreyi göster"><i data-lucide="eye" id="pwIcon2"></i></button>
          </div>
          <span class="auth-match" id="pwMatchInfo" aria-live="polite"></span>
        </div>

        <button type="submit" class="auth-submit" id="resetBtn"><i data-lucide="check-circle"></i> Şifremi güncelle</button>
      </form>

      <p class="auth-foot">
        Şifrenizi güncelledikten sonra yeni şifrenizle giriş yapabilirsiniz.
        <small>Şifreler bcrypt ile özetlenerek saklanır; düz metin olarak tutulmaz.</small>
      </p>
    </div>
  </section>

  <aside class="auth-aside" aria-hidden="true">
    <div class="auth-aside-inner">
      <h2>Hesabınızı koruyun</h2>
      <p>Güçlü bir şifre seçerek hesabınızın güvenliğini artırın. Bcrypt özetleme ile verileriniz koruma altında.</p>
      <ul class="auth-points">
        <li><i data-lucide="check-circle-2"></i><span><strong>En az 10 karakter</strong>Uzun şifreler kaba kuvvet saldırılarına karşı çok daha dayanıklıdır.</span></li>
        <li><i data-lucide="check-circle-2"></i><span><strong>Büyük harf ve rakam</strong>Harf, rakam ve simge karışımı şifrenizi tahmin edilemez kılar.</span></li>
        <li><i data-lucide="check-circle-2"></i><span><strong>Benzersiz şifre</strong>Başka sitelerde kullandığınız şifreyi burada tekrar kullanmayın.</span></li>
      </ul>
      <div class="auth-pills">
        <span class="auth-pill"><i data-lucide="shield-check"></i> Bcrypt</span>
        <span class="auth-pill"><i data-lucide="key-round"></i> Tek kullanımlık token</span>
      </div>
    </div>
  </aside>
</div>

<script>
(function(){
  function setupToggle(inputId, toggleId, iconId) {
    var input = document.getElementById(inputId), toggle = document.getElementById(toggleId), icon = document.getElementById(iconId);
    if (!input || !toggle || !icon) return;
    toggle.addEventListener('click', function(){
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster');
      icon.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
      if (window.lucide) lucide.createIcons();
    });
  }
  setupToggle('pwInput', 'pwToggle1', 'pwIcon1');
  setupToggle('pwConfirmInput', 'pwToggle2', 'pwIcon2');

  var pwInput = document.getElementById('pwInput'), pwBar = document.getElementById('pwStrengthBar'), pwText = document.getElementById('pwStrengthText');
  var pwConfirm = document.getElementById('pwConfirmInput'), matchInfo = document.getElementById('pwMatchInfo');

  function checkStrength(password) {
    var score = 0;
    if (password.length >= 6) score++;
    if (password.length >= 10) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    return score;
  }
  var strengthMap = [
    { label: '', color: 'transparent', width: '0%' },
    { label: 'Çok zayıf', color: '#ef4444', width: '20%' },
    { label: 'Zayıf', color: '#f97316', width: '40%' },
    { label: 'Orta', color: '#eab308', width: '60%' },
    { label: 'Güçlü', color: '#22c55e', width: '80%' },
    { label: 'Çok güçlü', color: '#059669', width: '100%' }
  ];
  pwInput.addEventListener('input', function(){
    var s = strengthMap[checkStrength(this.value)];
    pwBar.style.width = s.width;
    pwBar.style.background = s.color;
    pwText.textContent = this.value.length > 0 ? s.label : '';
    pwText.style.color = s.color;
    checkMatch();
  });
  function checkMatch() {
    var pw1 = pwInput.value, pw2 = pwConfirm.value;
    if (pw2.length === 0) { matchInfo.textContent = ''; matchInfo.className = 'auth-match'; return; }
    if (pw1 === pw2) {
      matchInfo.innerHTML = '<i data-lucide="check-circle"></i> Şifreler eşleşiyor';
      matchInfo.className = 'auth-match is-match';
    } else {
      matchInfo.innerHTML = '<i data-lucide="x-circle"></i> Şifreler eşleşmiyor';
      matchInfo.className = 'auth-match is-mismatch';
    }
    if (window.lucide) lucide.createIcons({ nodes: [matchInfo] });
  }
  pwConfirm.addEventListener('input', checkMatch);

  var resetForm = document.getElementById('resetForm'), resetBtn = document.getElementById('resetBtn');
  resetForm.addEventListener('submit', function(e){
    if (pwInput.value !== pwConfirm.value) {
      e.preventDefault();
      matchInfo.innerHTML = '<i data-lucide="x-circle"></i> Şifreler eşleşmiyor!';
      matchInfo.className = 'auth-match is-mismatch';
      if (window.lucide) lucide.createIcons({ nodes: [matchInfo] });
      pwConfirm.focus();
      return;
    }
    resetBtn.disabled = true;
    resetBtn.innerHTML = '<i data-lucide="loader-circle" class="auth-spin"></i> Güncelleniyor...';
    if (window.lucide) lucide.createIcons({ nodes: [resetBtn] });
  });
})();
</script>
