<?php
/** SSS sayfası. Girdi: $faqs. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$faqs = $faqs ?? [];

$wa = preg_replace('/\D/', '', (string) brand('contact_phone', ''));
if ($wa === '') {
    $wa = '';
}
$waHref = 'https://wa.me/' . $wa . '?text=' . rawurlencode('Merhaba, Entegratör Yazılımı hakkında bilgi almak istiyorum');

/* Kurumsal yardım kategorileri — soruların üstünde hızlı yönlendirme. */
$topics = [
    ['plug', 'Entegrasyon', 'Pazaryeri API anahtarları, OAuth2 ve bağlantı süreci.'],
    ['refresh-cw', 'Senkronizasyon', 'Stok, fiyat, sipariş ve iade akışları nasıl eşitlenir.'],
    ['file-text', 'Fatura & Kargo', 'E-Arşiv fatura, GİB onayı ve kargo etiketi süreçleri.'],
    ['shield-check', 'Güvenlik & Paketler', 'Veri güvenliği, paketler ve faturalandırma.'],
];
?>
<!-- HERO -->
<section class="hero-bg">
  <div class="container-x pt-16 pb-12 lg:pt-24 lg:pb-16 text-center">
    <span class="chip mx-auto"><i data-lucide="life-buoy" class="w-4 h-4"></i> Yardım Merkezi</span>
    <h1 class="h-display mt-6 max-w-3xl mx-auto">Sık Sorulan <span class="text-gradient">Sorular</span></h1>
    <p class="lead mt-5 max-w-2xl mx-auto">Pazaryeri entegrasyonu, senkronizasyon, fatura ve güvenlik hakkında en çok merak edilenler. Aradığınızı bulamadıysanız ekibimiz bir mesaj uzağınızda.</p>
    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
      <a href="<?= url('/iletisim') ?>" class="btn btn-primary">İletişime Geçin <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
      <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="btn btn-outline"><i data-lucide="message-circle" class="w-4 h-4"></i> WhatsApp ile Bilgi Al</a>
    </div>
  </div>
</section>

<!-- YARDIM KATEGORİLERİ -->
<section class="container-x section-tight">
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ($topics as [$icon, $title, $desc]): ?>
    <div class="trust-card p-5">
      <span class="grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand"><i data-lucide="<?= $e($icon) ?>" class="w-5 h-5"></i></span>
      <h3 class="mt-4 text-[15px] font-bold tracking-tight text-slate-800"><?= $e($title) ?></h3>
      <p class="mt-1.5 text-[13px] text-slate-500 leading-relaxed"><?= $e($desc) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- SSS AKORDEON -->
<section class="container-x section pt-0 lg:pt-0">
  <div class="text-center max-w-2xl mx-auto mb-10">
    <span class="eyebrow">SSS</span>
    <h2 class="h-section mt-3">Tüm Sorularınızın Yanıtı</h2>
    <p class="lead mt-3">Başlığa tıklayarak yanıtı görüntüleyin.</p>
  </div>
  <?php require __DIR__ . '/_faq.php'; ?>
</section>

<!-- DESTEK CTA -->
<section class="container-x pb-16 lg:pb-24">
  <div class="rounded-3xl bg-brand text-white px-8 py-12 text-center shadow-glow">
    <h2 class="text-[26px] sm:text-[32px] font-bold tracking-tight">Hâlâ Yanıt Bulamadınız mı?</h2>
    <p class="mt-3 text-white/85 text-[15.5px] max-w-xl mx-auto">Destek ekibimiz pazaryeri entegrasyonunuzun her adımında yanınızda. Hızlıca yanıt alın.</p>
    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
      <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="btn btn-lg bg-white text-brand hover:brightness-95"><i data-lucide="message-circle" class="w-4 h-4"></i> WhatsApp ile Bilgi Al</a>
      <a href="<?= url('/iletisim') ?>" class="btn btn-lg bg-white/15 text-white hover:bg-white/25">İletişim Formu</a>
    </div>
  </div>
</section>
