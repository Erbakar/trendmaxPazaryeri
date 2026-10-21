<?php
/** Mağaza-oluştur teşekkür / e-posta doğrulama sonuç sayfası (site teması). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$thanksMode = $thanksMode ?? 'received';
$map = [
  'received'    => ['Başvurunuz Alındı!', 'Teşekkürler. Başvurunuzu inceleyip en kısa sürede sizinle iletişime geçeceğiz. Hesabınız hazırlandığında giriş bilgileriniz e-posta ile iletilecek.', 'check-circle-2', 'var(--green)', 'var(--green-light)'],
  'email'       => ['E-postanızı Kontrol Edin', 'Başvurunuzu tamamlamak için e-posta adresinize gönderdiğimiz doğrulama bağlantısına tıklayın. Bağlantıyı onayladıktan sonra başvurunuz işleme alınacak.', 'mail', '#2563eb', '#dbeafe'],
  'verified'    => ['E-posta Doğrulandı', 'Teşekkürler, e-posta adresiniz doğrulandı. Başvurunuz incelemeye alındı; hesabınız hazırlandığında bilgilendirileceksiniz.', 'check-circle-2', 'var(--green)', 'var(--green-light)'],
  'provisioned' => ['Hesabınız Hazır!', 'Mağazanız oluşturuldu. Şifre belirleme bağlantısı e-posta adresinize gönderildi — oradan şifrenizi belirleyip giriş yapabilirsiniz.', 'rocket', 'var(--green)', 'var(--green-light)'],
  'invalid'     => ['Bağlantı Geçersiz', 'Doğrulama bağlantısı geçersiz veya daha önce kullanılmış. Lütfen tekrar başvurun veya bizimle iletişime geçin.', 'alert-triangle', '#d97706', '#fef3c7'],
];
[$h1, $msg, $icon, $iconColor, $iconBg] = $map[$thanksMode] ?? $map['received'];
?>
<section class="section" style="padding-top:128px">
<div class="container" style="max-width:620px;text-align:center">
  <div style="width:72px;height:72px;border-radius:50%;background:<?= $iconBg ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 24px"><i data-lucide="<?= $e($icon) ?>" style="width:36px;height:36px;color:<?= $iconColor ?>"></i></div>
  <h1 class="heading-lg"><?= $e($h1) ?></h1>
  <p class="subtext mx-auto text-center" style="margin-top:14px"><?= $e($msg) ?></p>
  <div style="display:flex;gap:12px;justify-content:center;margin-top:32px;flex-wrap:wrap">
    <a href="<?= url('/') ?>" class="btn btn-primary btn-lg"><i data-lucide="home"></i> Ana Sayfaya Dön</a>
    <a href="<?= url('/login') ?>" class="btn btn-outline btn-lg"><i data-lucide="log-in"></i> Giriş Yap</a>
  </div>
</div>
</section>
