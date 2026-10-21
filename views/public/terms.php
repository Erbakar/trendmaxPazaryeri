<?php
/** Kullanım Koşulları (site teması) — kurumsal güven sayfası. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$name = brand('site_name');
$legal = brand('legal_name', $name);
$cm = trim((string) brand('contact_email', ''));
$custom = trim((string) brand('legal_terms', '')); // admin düzenlenebilir; boşsa aşağıdaki varsayılan metin
?>
<section class="section" style="padding-top:128px">
<div class="container" style="max-width:820px">
  <div class="eyebrow eyebrow-purple"><i data-lucide="file-text"></i> Yasal</div>
  <h1 class="heading-lg" style="margin-top:6px">Kullanım Koşulları</h1>
  <p class="subtext" style="margin-top:12px;max-width:none">Son güncelleme: <?= date('d.m.Y') ?> · <?= $e($legal) ?></p>
  <?php if ($custom !== ''): ?>
  <div style="margin-top:32px;color:var(--gray-600);font-size:15px;line-height:1.8" class="legal-content"><?= $custom ?></div>
  <?php else: ?>
  <div style="margin-top:32px;color:var(--gray-600);font-size:15px;line-height:1.8">
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">1. Hizmetin Kapsamı</h3>
    <p><?= $e($name) ?>, pazaryeri entegrasyonu, sipariş/stok/fiyat senkronizasyonu, e-arşiv fatura ve kargo yönetimi sağlayan bir SaaS hizmetidir. Hizmet, aktif aboneliğiniz süresince sunulur.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">2. Hesap ve Sorumluluk</h3>
    <p>Hesap bilgilerinizin gizliliğinden ve hesabınız altında gerçekleşen işlemlerden siz sorumlusunuz. Pazaryeri API anahtarlarınızın doğruluğu ve geçerliliği kullanıcının sorumluluğundadır.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">3. Abonelik ve Ödeme</h3>
    <p>Hizmet, seçtiğiniz pakete göre aylık veya yıllık abonelik ile sunulur. Ödemeler havale/EFT veya tanımlı yöntemlerle alınır. Deneme süresi sonunda abonelik onayı gereklidir.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">4. Kullanım Kuralları</h3>
    <p>Hizmeti yasalara aykırı amaçlarla, sistem güvenliğini tehdit edecek şekilde veya yetkisiz erişim amacıyla kullanmak yasaktır. Yazılımın izinsiz kopyalanması, dağıtılması veya alt lisanslanması yasaktır.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">5. Hizmet Sürekliliği</h3>
    <p>%99.9 erişilebilirlik hedeflenir; planlı bakımlar önceden duyurulur. Pazaryeri API değişikliklerinden kaynaklı kesintilerde en kısa sürede uyum sağlanır.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">6. Değişiklikler</h3>
    <p>Bu koşullar zaman zaman güncellenebilir. Önemli değişiklikler hesabınıza bildirilir. Güncel sürüm bu sayfada yayımlanır.</p>
    <h3 style="font-size:18px;font-weight:700;color:var(--gray-900);margin:28px 0 10px">7. İletişim</h3>
    <p>Sorularınız için <?= $cm !== '' ? '<a href="mailto:' . $e($cm) . '" style="color:var(--blue)">' . $e($cm) . '</a>' : 'bizimle iletişime geçin' ?>.</p>
  </div>
  <?php endif; ?>
</div>
</section>
