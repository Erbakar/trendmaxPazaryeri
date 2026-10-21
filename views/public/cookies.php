<?php
/** Çerez Politikası (site teması) — KVKK/GDPR çerez aydınlatma + yönetim. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$name = brand('site_name');
$legal = brand('legal_name', $name);
$cm = trim((string) brand('contact_email', ''));
$custom = trim((string) brand('legal_cookies', '')); // admin düzenlenebilir; boşsa aşağıdaki varsayılan metin
?>
<section class="section" style="padding-top:128px">
<div class="container" style="max-width:820px">
  <div class="eyebrow eyebrow-blue"><i data-lucide="cookie"></i> Yasal</div>
  <h1 class="heading-lg" style="margin-top:6px">Çerez Politikası</h1>
  <p class="subtext" style="margin-top:12px;max-width:none">Son güncelleme: <?= date('d.m.Y') ?> · <?= $e($legal) ?></p>
  <?php if ($custom !== ''): ?>
  <div style="margin-top:32px;color:var(--gray-600);font-size:15px;line-height:1.8" class="legal-content"><?= $custom ?></div>
  <?php else: ?>
  <div style="margin-top:32px;color:var(--gray-600);font-size:15px;line-height:1.8">
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">1. Çerez Nedir?</h3>
    <p>Çerezler (cookies), web sitemizi ziyaret ettiğinizde tarayıcınıza kaydedilen küçük metin dosyalarıdır. Sitenin düzgün çalışması, tercihlerinizin hatırlanması ve ziyaret istatistiklerinin ölçülmesi için kullanılır.</p>

    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">2. Kullandığımız Çerez Türleri</h3>
    <p><strong>Zorunlu çerezler:</strong> Oturum, güvenlik (CSRF) ve temel işlevler için gereklidir; onay gerektirmez, devre dışı bırakılamaz.</p>
    <p><strong>Analitik çerezler:</strong> Ziyaretçi sayısı ve sayfa etkileşimini anonim ölçmek için (Google Analytics 4). Yalnızca onayınızla çalışır.</p>
    <p><strong>Pazarlama / reklam çerezleri:</strong> Reklam performansını ölçmek ve ilgi alanına dayalı reklam göstermek için (Meta Pixel, Google Ads, TikTok Pixel). Yalnızca onayınızla çalışır.</p>

    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">3. Üçüncü Taraf Çerezleri</h3>
    <p>Analitik ve pazarlama çerezleri Meta (Facebook/Instagram), Google ve TikTok gibi sağlayıcılar tarafından yerleştirilebilir. Bu sağlayıcıların kendi gizlilik politikaları geçerlidir. Bu çerezler, siz <em>"Tümünü Kabul Et"</em> demediğiniz sürece <strong>çalışmaz</strong>.</p>

    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">4. Çerez Tercihlerinizi Yönetme</h3>
    <p>Siteye ilk girişte çıkan çerez bildiriminden <em>"Tümünü Kabul Et"</em> veya <em>"Yalnızca Gerekli"</em> seçebilirsiniz. Tercihinizi dilediğiniz zaman, tarayıcınızın çerezlerini temizleyerek sıfırlayabilir; ayrıca tarayıcı ayarlarından çerezleri tümüyle engelleyebilirsiniz (zorunlu çerezler engellenirse site düzgün çalışmayabilir).</p>

    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">5. Saklama Süresi</h3>
    <p>Çerez tercihiniz tarayıcınızda en fazla 12 ay saklanır; bu sürenin sonunda tekrar sorulur.</p>

    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">6. İletişim</h3>
    <p>Çerezler ve kişisel verilerinizle ilgili sorularınız için <?= $cm !== '' ? '<a href="mailto:' . $e($cm) . '" style="color:var(--blue)">' . $e($cm) . '</a>' : 'iletişim kanallarımız' ?> üzerinden bize ulaşabilir, <a href="<?= url('/gizlilik') ?>" style="color:var(--blue)">Gizlilik Politikası</a>'mızı inceleyebilirsiniz.</p>
  </div>
  <?php endif; ?>
</div>
</section>
