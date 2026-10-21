<?php
/** Mağaza Oluştur başvuru formu (site teması). Form sözleşmesi DEĞİŞMEZ: POST /magaza-olustur. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = $data['_csrf'] ?? ($_csrf ?? '');
$flash = $data['_flash'] ?? ($_flash ?? []);
$error = $flash['error'] ?? '';
$packages = $packages ?? [];
$selectedSlug = $selectedSlug ?? '';
?>
<section class="section" style="padding-top:128px">
<div class="container">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:start" class="sc-grid">
    <!-- Sol: değer önerisi -->
    <div class="reveal-left">
      <div class="eyebrow eyebrow-blue"><i data-lucide="rocket"></i> Mağaza Oluştur</div>
      <h1 class="heading-lg" style="margin-top:6px">Dakikalar içinde<br>panelinizi açın</h1>
      <p class="subtext" style="margin-top:14px">Kısa başvuru formunu doldurun; ekibimiz hesabınızı hazırlayıp giriş bilgilerinizi e-posta ile iletsin. Kurulum ve ilk entegrasyonda yanınızdayız.</p>
      <div class="why-items" style="margin-top:28px">
        <div class="why-item"><div class="why-item-icon" style="background:var(--blue-light)"><i data-lucide="zap" style="color:var(--blue)"></i></div><div><h3>Hızlı Kurulum</h3><p>Onay sonrası hesabınız dakikalar içinde aktif olur.</p></div></div>
        <div class="why-item"><div class="why-item-icon" style="background:var(--green-light)"><i data-lucide="headset" style="color:var(--green)"></i></div><div><h3>Kurulum Desteği</h3><p>Pazaryeri API bağlantılarında teknik ekip yardımcı olur.</p></div></div>
        <div class="why-item"><div class="why-item-icon" style="background:var(--purple-light)"><i data-lucide="shield-check" style="color:var(--purple)"></i></div><div><h3>Güvenli & İzole</h3><p>Verileriniz AES-256 ile şifreli, mağazanız tamamen izole.</p></div></div>
      </div>
      <div class="reveal" style="margin-top:26px;border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--gray-100);box-shadow:var(--shadow-lg);max-width:460px">
        <div style="display:flex;align-items:center;gap:6px;padding:10px 14px;background:var(--gray-50);border-bottom:1px solid var(--gray-100)"><span class="b-dot r"></span><span class="b-dot y"></span><span class="b-dot g"></span><span style="margin-left:6px;font-size:11px;color:var(--gray-400);font-weight:600">panel önizleme</span></div>
        <img src="<?= $e(asset('img/screenshots/orders.png')) ?>" alt="Panel önizleme" style="width:100%;display:block">
      </div>
    </div>

    <!-- Sağ: form -->
    <div class="reveal-right" style="background: #d8d8d817;
  border: 1px solid var(--gray-100);border-radius:var(--radius-xl);padding:32px">
      <?php if ($error !== ''): ?>
      <div class="auth2-alert auth2-alert-error"><i data-lucide="alert-triangle"></i><span><?= $e($error) ?></span></div>
      <?php endif; ?>
      <form method="POST" action="<?= url('/magaza-olustur') ?>" class="auth2-form" style="margin-top:0">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

        <div class="auth2-field">
          <label class="auth2-label" for="f-company">Firma adı *</label>
          <div class="auth2-input-wrap"><i data-lucide="building-2"></i><input type="text" id="f-company" name="company" class="auth2-input" placeholder="Örnek Ticaret A.Ş." required></div>
        </div>
        <div class="auth2-field">
          <label class="auth2-label" for="f-contact">Yetkili kişi *</label>
          <div class="auth2-input-wrap"><i data-lucide="user"></i><input type="text" id="f-contact" name="contact_name" class="auth2-input" placeholder="Ad Soyad" required></div>
        </div>
        <div class="auth2-field">
          <label class="auth2-label" for="f-email">E-posta *</label>
          <div class="auth2-input-wrap"><i data-lucide="mail"></i><input type="email" id="f-email" name="email" class="auth2-input" placeholder="ornek@firma.com" required></div>
        </div>
        <div class="auth2-field">
          <label class="auth2-label" for="f-phone">Telefon</label>
          <div class="auth2-input-wrap"><i data-lucide="phone"></i><input type="tel" id="f-phone" name="phone" class="auth2-input" placeholder="5xx xxx xx xx"></div>
        </div>
        <div class="auth2-field">
          <label class="auth2-label" for="f-password">Şifre *</label>
          <div class="auth2-input-wrap"><i data-lucide="lock"></i><input type="password" id="f-password" name="password" class="auth2-input" placeholder="En az 8 karakter" minlength="8" autocomplete="new-password" required></div>
          <small style="display:block;margin-top:6px;color:var(--muted,#64748b);font-size:12px;line-height:1.5">
            Panele bu şifreyle gireceksiniz. E-posta beklemenize gerek kalmaz.
          </small>
        </div>
        <div class="auth2-field">
          <label class="auth2-label" for="f-password2">Şifre (tekrar) *</label>
          <div class="auth2-input-wrap"><i data-lucide="lock"></i><input type="password" id="f-password2" name="password_confirmation" class="auth2-input" placeholder="Şifreyi tekrar yazın" minlength="8" autocomplete="new-password" required></div>
        </div>
        <?php if (!empty($packages)): ?>
        <div class="auth2-field">
          <label class="auth2-label" for="f-pkg">Paket</label>
          <div class="auth2-input-wrap"><i data-lucide="package"></i>
            <select id="f-pkg" name="requested_package_id" class="auth2-input">
              <option value="">Seçiniz (opsiyonel)</option>
              <?php foreach ($packages as $p): ?>
              <option value="<?= (int) $p['id'] ?>"<?= ($selectedSlug !== '' && (string) $p['slug'] === $selectedSlug) ? ' selected' : '' ?>><?= $e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="auth2-field">
          <label class="auth2-label" for="f-cycle">Faturalandırma</label>
          <div class="auth2-input-wrap"><i data-lucide="calendar"></i>
            <select id="f-cycle" name="billing_cycle" class="auth2-input">
              <option value="monthly">Aylık</option>
              <option value="yearly">Yıllık</option>
            </select>
          </div>
        </div>
        <?php endif; ?>
        <div class="auth2-field">
          <label class="auth2-label" for="f-msg">Mesajınız</label>
          <textarea id="f-msg" name="message" class="auth2-input" style="height:auto;min-height:84px;padding:12px 14px;resize:vertical" placeholder="İhtiyaçlarınızı kısaca yazın (opsiyonel)"></textarea>
        </div>
        <div class="sc-agree" style="margin:4px 0 16px;display:flex;flex-direction:column;gap:11px">
          <label style="display:flex;gap:9px;align-items:flex-start;font-size:13px;color:var(--gray-600);line-height:1.5;cursor:pointer">
            <input type="checkbox" name="agree_terms" value="1" required style="margin-top:2px;width:16px;height:16px;flex:none;accent-color:var(--blue,#2563eb)">
            <span><a href="<?= url('/kullanim-kosullari') ?>" target="_blank" style="color:var(--blue,#2563eb)">Kullanım Koşulları</a> ve <a href="<?= url('/gizlilik') ?>" target="_blank" style="color:var(--blue,#2563eb)">Gizlilik Politikası</a>'nı okudum ve kabul ediyorum.</span>
          </label>
          <label style="display:flex;gap:9px;align-items:flex-start;font-size:13px;color:var(--gray-600);line-height:1.5;cursor:pointer">
            <input type="checkbox" name="agree_kvkk" value="1" required style="margin-top:2px;width:16px;height:16px;flex:none;accent-color:var(--blue,#2563eb)">
            <span><a href="<?= url('/gizlilik') ?>" target="_blank" style="color:var(--blue,#2563eb)">KVKK Aydınlatma Metni</a>'ni okudum; kişisel verilerimin işlenmesini kabul ediyorum.</span>
          </label>
          <label style="display:flex;gap:9px;align-items:flex-start;font-size:13px;color:var(--gray-600);line-height:1.5;cursor:pointer">
            <input type="checkbox" name="agree_marketing" value="1" style="margin-top:2px;width:16px;height:16px;flex:none;accent-color:var(--blue,#2563eb)">
            <span>Kampanya, fırsat ve bilgilendirmeler için ticari elektronik ileti almak istiyorum. <span style="color:#94a3b8">(opsiyonel)</span></span>
          </label>
        </div>
        <button type="submit" class="auth2-submit"><i data-lucide="rocket"></i> Başvuruyu Gönder</button>
      </form>
    </div>
  </div>
</div>
</section>
<style>@media(max-width:900px){.sc-grid{grid-template-columns:1fr!important;gap:32px!important}}</style>
