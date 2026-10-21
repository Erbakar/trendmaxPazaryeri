<?php
/** Gizlilik Politikası (site teması) — kurumsal güven sayfası. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$name = brand('site_name');
$legal = brand('legal_name', $name);
$cm = trim((string) brand('contact_email', ''));
$custom = trim((string) brand('legal_privacy', '')); // admin düzenlenebilir; boşsa aşağıdaki varsayılan metin
?>
<section class="section" style="padding-top:128px">
<div class="container" style="max-width:820px">
  <div class="eyebrow eyebrow-blue"><i data-lucide="shield-check"></i> Yasal</div>
  <h1 class="heading-lg" style="margin-top:6px">Gizlilik Politikası</h1>
  <p class="subtext" style="margin-top:12px;max-width:none">Son güncelleme: <?= date('d.m.Y') ?> · <?= $e($legal) ?></p>
  <?php if ($custom !== ''): ?>
  <div style="margin-top:32px;color:var(--gray-600);font-size:15px;line-height:1.8" class="legal-content"><?= $custom ?></div>
  <?php else: ?>
  <div style="margin-top:32px;color:var(--gray-600);font-size:15px;line-height:1.8">
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">1. Veri Sorumlusu</h3>
    <p><?= $e($legal) ?> ("<?= $e($name) ?>") olarak, 6698 sayılı Kişisel Verilerin Korunması Kanunu (KVKK) kapsamında veri sorumlusu sıfatıyla, kişisel verilerinizin güvenliğine önem veriyoruz.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">2. Toplanan Veriler</h3>
    <p>Hizmetlerimizden yararlanırken; ad-soyad, e-posta, telefon, firma bilgileri ve hizmet kullanımına ilişkin teknik veriler (IP, log kayıtları) toplanabilir. Pazaryeri API anahtarlarınız AES-256 ile şifrelenerek saklanır.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">3. Verilerin İşlenme Amacı</h3>
    <p>Kişisel verileriniz; hizmetin sunulması, hesap yönetimi, faturalandırma, destek sağlanması ve yasal yükümlülüklerin yerine getirilmesi amacıyla işlenir. Verileriniz üçüncü taraflarla pazarlama amacıyla paylaşılmaz.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">4. Veri Güvenliği</h3>
    <p>Her mağaza verisi izole (çok-kiracılı mimari) tutulur; erişim rol/yetki ve iki adımlı doğrulama (2FA) ile korunur. Aktarımlar SSL/TLS ile şifrelenir, hassas alanlar AES-256 ile saklanır.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">5. Haklarınız</h3>
    <p>KVKK m.11 uyarınca; verilerinize erişme, düzeltme, silme ve işlenmesine itiraz etme haklarına sahipsiniz. Taleplerinizi <?= $cm !== '' ? '<a href="mailto:' . $e($cm) . '" style="color:var(--blue)">' . $e($cm) . '</a>' : 'iletişim kanallarımız' ?> üzerinden iletebilirsiniz.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">6. İletişim</h3>
    <p>Gizlilikle ilgili sorularınız için <?= $cm !== '' ? '<a href="mailto:' . $e($cm) . '" style="color:var(--blue)">' . $e($cm) . '</a>' : 'bizimle iletişime geçebilirsiniz' ?>.</p>
  </div>
  <?php endif; ?>
</div>
</section>
