<?php
/** Paketler sayfası. Girdi: $packages, $faqs. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$faqs = $faqs ?? [];
$wa = preg_replace('/\D/', '', (string) brand('contact_phone', ''));
if ($wa === '') $wa = '';
$waHref = 'https://wa.me/' . $wa . '?text=' . rawurlencode('Merhaba, Entegratör Yazılımı hakkında bilgi almak istiyorum');
?>
<section class="hero-bg">
  <div class="container-x pt-20 pb-14 text-center">
    <span class="eyebrow"><i data-lucide="credit-card" class="w-4 h-4"></i> Paketler</span>
    <h1 class="h-display mt-5">Şeffaf, basit <span class="text-gradient">fiyatlandırma</span></h1>
    <p class="lead mt-5 max-w-2xl mx-auto">İhtiyacınıza göre seçin, ücretsiz deneme ile başlayın, dilediğiniz zaman yükseltin.</p>
    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
      <span class="stat-pill"><i data-lucide="shield-check" class="w-4 h-4 text-brand"></i> Gizli ücret yok</span>
      <span class="stat-pill"><i data-lucide="rocket" class="w-4 h-4 text-brand"></i> 5 dakikada kurulum</span>
      <span class="stat-pill"><i data-lucide="repeat" class="w-4 h-4 text-brand"></i> İstediğiniz an iptal</span>
    </div>
  </div>
</section>

<section class="container-x pb-20">
  <?php require __DIR__ . '/_pricing.php'; ?>
  <p class="mt-10 text-center text-[13px] text-slate-400">Tüm paketler havale/EFT ile ödenebilir. Aboneliğiniz ödeme onayından sonra aktifleşir.</p>
</section>

<?php if (!empty($faqs)): ?>
<section class="bg-slate-50 border-y border-slate-200/70 section">
  <div class="container-x">
    <div class="text-center max-w-2xl mx-auto mb-12">
      <span class="eyebrow"><i data-lucide="help-circle" class="w-4 h-4"></i> SSS</span>
      <h2 class="h-section mt-4">Paketler hakkında sık sorulanlar</h2>
      <p class="lead mt-4">Aradığınız yanıtı bulamadıysanız ekibimiz size yardımcı olmaktan memnuniyet duyar.</p>
    </div>
    <?php require __DIR__ . '/_faq.php'; ?>
  </div>
</section>
<?php endif; ?>

<section class="container-x section-tight">
  <div class="card relative overflow-hidden p-10 sm:p-14 text-center">
    <div class="hero-bg absolute inset-0 -z-10"></div>
    <h2 class="h-section">E-Ticaretinizi Bir Üst Seviyeye Taşıyın</h2>
    <p class="lead mt-4 max-w-2xl mx-auto">11 pazaryeri ve e-ticaret platformunu tek panelden yönetmeye bugün başlayın. Kredi kartı gerekmez.</p>
    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
      <a href="<?= url('/magaza-olustur') ?>" class="btn btn-primary btn-lg">
        Mağaza Oluştur
        <i data-lucide="arrow-right" class="w-4 h-4"></i>
      </a>
      <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-lg">
        <i data-lucide="message-circle" class="w-4 h-4 text-emerald-500"></i>
        WhatsApp ile Bilgi Al
      </a>
    </div>
  </div>
</section>
