<?php
/**
 * OzyConnect — Kurumsal site layout (ozyconnect.com birebir port).
 * El-yazımı CSS (public/assets/css/marketing.css) + Inter + lucide. brand() ile dinamik marka.
 * $content echo edilir. Nav/footer/JS burada; sayfa view'ları yalnız <main> içeriği.
 */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$siteName = brand('site_name');
$legal    = brand('legal_name', $siteName);
$logo     = trim((string) brand('logo_url', ''));
$logoSrc  = $logo !== '' ? $logo : asset('img/logom2.png');
$brandColor = trim((string) brand('brand_color', ''));
$wa = preg_replace('/\D/', '', (string) brand('contact_phone', '')); // admin: İletişim Telefonu (sabit numara yok)
$waHref = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Merhaba, ' . $siteName . ' hakkında bilgi almak istiyorum.') : '';

$seo = $data['seo'] ?? [];
$pageTitle = trim((string) ($seo['title'] ?? ($data['pageTitle'] ?? '')));
$metaTitle = $pageTitle !== '' ? ($pageTitle . ' — ' . $siteName) : (brand('seo_default_title', $siteName . ' — Pazaryeri Entegrasyon Paneli'));
$metaDesc  = trim((string) ($seo['description'] ?? brand('seo_default_description', 'Trendyol, Hepsiburada, Amazon, n11, Pazarama, PttAVM, ÇiçekSepeti, İdefix, Shopify, WooCommerce, OpenCart — 11 pazaryeri ve e-ticaret platformunu tek panelden yönetin.')));
$ogImage   = trim((string) ($seo['og_image'] ?? brand('seo_default_og_image', '')));
$canonical = trim((string) ($seo['canonical'] ?? ''));
$metaKeywords = trim((string) ($seo['keywords'] ?? brand('seo_default_keywords', '')));
$home = url('/');
$social = array_filter([
    'instagram' => brand('social_instagram', ''),
    'linkedin'  => brand('social_linkedin', ''),
    'x'         => brand('social_x', ''),
    'facebook'  => brand('social_facebook', ''),
    'youtube'   => brand('social_youtube', ''),
], static fn($v) => trim((string) $v) !== '');
$socialIcon = ['instagram' => 'instagram', 'linkedin' => 'linkedin', 'x' => 'twitter', 'facebook' => 'facebook', 'youtube' => 'youtube'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $e($metaTitle) ?></title>
<meta name="description" content="<?= $e($metaDesc) ?>">
<?php if ($metaKeywords !== ''): ?><meta name="keywords" content="<?= $e($metaKeywords) ?>"><?php endif; ?>
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $e($canonical) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e($metaTitle) ?>">
<meta property="og:description" content="<?= $e($metaDesc) ?>">
<?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= $e($ogImage) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
<?php $fav = trim((string) brand('favicon_url', '')); if ($fav !== ''): ?><link rel="icon" href="<?= $e($fav) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="<?= $e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<link rel="stylesheet" href="<?= $e(asset('css/marketing.css')) ?>?v=20260618poppins">
<?php
$bcHex = strtolower(ltrim($brandColor, '#'));
if (preg_match('/^[0-9a-f]{6}$/', $bcHex)):
    $r = hexdec(substr($bcHex, 0, 2)); $g = hexdec(substr($bcHex, 2, 2)); $b = hexdec(substr($bcHex, 4, 2));
    $hx = static fn(int $c): string => str_pad(dechex(max(0, min(255, $c))), 2, '0', STR_PAD_LEFT);
    $mix = static fn(int $c, float $f): string => $hx((int) round($c * $f));
    $blueDark   = '#' . $mix($r, .82) . $mix($g, .82) . $mix($b, .82);
    $blueDarker = '#' . $mix($r, .42) . $mix($g, .42) . $mix($b, .42);
?>
<style>:root{--blue:#<?= $bcHex ?>;--blue-dark:<?= $blueDark ?>;--blue-darker:<?= $blueDarker ?>;--blue-rgb:<?= $r ?>,<?= $g ?>,<?= $b ?>}</style>
<?php endif; ?>
<?php
// ── Google Consent Mode v2 + GTM + GA4/Ads ──
// Consent Mode v2: Google etiketleri DAİMA yüklenir; çerez onayına kadar 'denied' (çerezsiz mod), onayla 'granted'.
// Meta/TikTok ise onaysız hiç yüklenmez (partials/tracking.php). KVKK/GDPR uyumlu.
$gaId  = trim((string) brand('google_analytics_id', ''));
$gaOk  = brand('google_enabled', '0') === '1' && $gaId !== '' && preg_match('/^(G|UA|GT|AW)-[A-Za-z0-9-]+$/', $gaId);
$gtmId = trim((string) brand('gtm_container_id', ''));
$gtmOk = brand('gtm_enabled', '0') === '1' && preg_match('/^GTM-[A-Z0-9]+$/i', $gtmId);
$adsId = trim((string) brand('google_ads_id', ''));
$adsOk = brand('google_enabled', '0') === '1' && $adsId !== '';
$hasGoogle = $gaOk || $gtmOk || $adsOk;
if ($hasGoogle): ?>
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
(function(){var c=(document.cookie.match(/(?:^|;\s*)ozy_consent=([^;]+)/)||[])[1],g=c==='all'?'granted':'denied';
gtag('consent','default',{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g,functionality_storage:'granted',security_storage:'granted',wait_for_update:500});
gtag('set','ads_data_redaction',c!=='all');gtag('set','url_passthrough',true);})();
</script>
<?php endif; ?>
<?php if ($gtmOk): ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',<?= json_encode($gtmId) ?>);</script>
<!-- End Google Tag Manager -->
<?php endif; ?>
<?php if ($gaOk || $adsOk): /* GA4 + Ads tek gtag.js ile yüklenir, config'ler ayrı (çift kütüphane yüklemesi yok) */ ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= $e($gaOk ? $gaId : $adsId) ?>"></script>
<script>gtag('js',new Date());<?php if ($gaOk): ?>gtag('config','<?= $e($gaId) ?>');<?php endif; ?><?php if ($adsOk): ?>gtag('config','<?= $e($adsId) ?>');<?php endif; ?></script>
<?php endif; ?>
<?php /* Admin tanımlı ham head kodu (reklam/analytics/doğrulama) — güvenli admin alanı, aynen basılır */ $headCode = trim((string) brand('head_code', '')); if ($headCode !== ''): ?>
<?= $headCode ?>
<?php endif; ?>
</head>
<body>
<?php if ($gtmOk): ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= $e($gtmId) ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php endif; ?>
<?php include __DIR__ . '/../partials/tracking.php'; ?>
<?php include __DIR__ . '/../partials/consent.php'; ?>

<!-- ═══ NAVBAR ═══ -->
<nav class="nav" id="nav">
<div class="container nav-inner">
  <a href="<?= $home ?>" class="nav-brand"><?php if ($logo !== ''): ?><img src="<?= $e($logoSrc) ?>" alt="<?= $e($siteName) ?>"><?php else: ?><span style="font-size:21px;font-weight:800;letter-spacing:-.02em;color:var(--blue,#2563eb)"><?= $e($siteName) ?></span><?php endif; ?></a>
  <div class="nav-links" id="navLinks">
    <a href="#features" class="nav-link">Özellikler</a>
    <a href="#marketplaces" class="nav-link">Pazaryerleri</a>
    <a href="#modules" class="nav-link">Modüller</a>
    <a href="#screenshots" class="nav-link">Ekranlar</a>
    <a href="#pricing" class="nav-link">Fiyatlandırma</a>
    <a href="#faq" class="nav-link">SSS</a>
    <a href="<?= url('/blog') ?>" class="nav-link">Blog</a>
    <a href="<?= url('/referanslar') ?>" class="nav-link">Referanslar</a>
    <a href="<?= url('/iletisim') ?>" class="nav-link">İletişim</a>
  </div>
  <div class="nav-actions">
    <a href="<?= url('/login') ?>" class="btn btn-outline btn-sm"><i data-lucide="log-in"></i> Giriş Yap</a>
    <a href="<?= url('/magaza-olustur') ?>" class="btn btn-primary btn-sm"><i data-lucide="rocket"></i> Mağaza Oluştur</a>
    <button class="nav-hamburger" id="navHamburger"><span></span><span></span><span></span></button>
  </div>
</div>
</nav>

<main>
<?= $content ?? '' ?>
</main>

<!-- Lightbox -->
<div class="lb" id="lb">
  <button class="lb-close" id="lbClose"><i data-lucide="x"></i></button>
  <img id="lbImg" src="" alt="">
</div>

<!-- WhatsApp Float — yalnız admin'de İletişim Telefonu tanımlıysa -->
<?php if ($waHref !== ''): ?>
<a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="wa-float" title="WhatsApp">
  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
</a>
<?php endif; ?>

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
<div class="container">
  <div class="footer-main">
    <div class="footer-brand">
      <?php if ($logo !== ''): ?><img src="<?= $e($logoSrc) ?>" alt="<?= $e($siteName) ?>"><?php else: ?><span style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:var(--gray-900,#0f172a)"><?= $e($siteName) ?></span><?php endif; ?>
      <p>Türkiye'nin önde gelen pazaryeri ve e-ticaret platformlarını tek panelden yönetin. Sipariş, stok, fiyat, e-arşiv fatura ve kargo otomasyonu.</p>
      <?php if ($social): ?>
      <div style="display:flex;gap:8px;margin-top:16px">
        <?php foreach ($social as $k => $u): ?><a href="<?= $e($u) ?>" target="_blank" rel="noopener" title="<?= $e(ucfirst($k)) ?>" style="width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:var(--gray-50);border:1px solid var(--gray-100);color:var(--gray-500);transition:color .15s,border-color .15s" onmouseover="this.style.color='var(--blue)';this.style.borderColor='var(--blue)'" onmouseout="this.style.color='var(--gray-500)';this.style.borderColor='var(--gray-100)'"><?= social_svg($k, 16) ?></a><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="footer-col">
      <h5>Ürün</h5>
      <a href="#features">Özellikler</a>
      <a href="#marketplaces">Pazaryerleri</a>
      <a href="#modules">Modüller</a>
      <a href="#pricing">Fiyatlandırma</a>
    </div>
    <div class="footer-col">
      <h5>Destek</h5>
      <a href="#faq">SSS</a>
      <a href="<?= url('/iletisim') ?>">İletişim</a>
      <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener">WhatsApp Destek</a>
      <a href="<?= url('/login') ?>">Panel Girişi</a>
    </div>
    <div class="footer-col">
      <h5>Kurumsal</h5>
      <a href="<?= url('/referanslar') ?>">Referanslar</a>
      <a href="<?= url('/blog') ?>">Blog</a>
      <a href="<?= url('/gizlilik') ?>">Gizlilik Politikası</a>
      <a href="<?= url('/kullanim-kosullari') ?>">Kullanım Koşulları</a>
      <a href="<?= url('/cerez-politikasi') ?>">Çerez Politikası</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>&copy; <?= date('Y') ?> <?= $e($legal) ?>. Tüm Hakları Saklıdır.</span>
    <span>Türkiye'nin E-Ticaret Entegrasyon Çözümü</span>
  </div>
</div>
</footer>

<script>
if (window.lucide) lucide.createIcons();

// Navbar scroll
var nav = document.getElementById('nav');
window.addEventListener('scroll', function(){ if(nav) nav.classList.toggle('scrolled', window.scrollY > 30); }, {passive:true});

// Hamburger
var hamburger = document.getElementById('navHamburger');
var navLinks = document.getElementById('navLinks');
if (hamburger && navLinks) {
  hamburger.addEventListener('click', function(){
    hamburger.classList.toggle('open');
    navLinks.classList.toggle('mobile-visible');
    nav.classList.toggle('mobile-open');
    document.body.style.overflow = nav.classList.contains('mobile-open') ? 'hidden' : '';
  });
  navLinks.querySelectorAll('.nav-link').forEach(function(l){ l.addEventListener('click', function(){
    hamburger.classList.remove('open'); navLinks.classList.remove('mobile-visible'); nav.classList.remove('mobile-open'); document.body.style.overflow='';
  }); });
}

// Smooth scroll — hedef bu sayfada varsa kaydır; yoksa ana sayfaya + hash git
document.querySelectorAll('a[href^="#"]').forEach(function(a){
  a.addEventListener('click', function(e){
    var id = a.getAttribute('href');
    if (!id || id === '#') return;
    var t = document.querySelector(id);
    if (t) { e.preventDefault(); t.scrollIntoView({behavior:'smooth'}); }
    else { e.preventDefault(); window.location.href = <?= json_encode($home) ?> + id; }
  });
});

// Reveal on scroll
var ro = new IntersectionObserver(function(entries){
  entries.forEach(function(en){ if (en.isIntersecting) { en.target.classList.add('vis'); ro.unobserve(en.target); } });
}, {threshold:.08, rootMargin:'0px 0px -50px 0px'});
document.querySelectorAll('.reveal, .reveal-left, .reveal-right').forEach(function(el){ ro.observe(el); });

// Feature tabs
var tabs = document.querySelectorAll('.ftab');
var panels = document.querySelectorAll('.fv-panel');
tabs.forEach(function(tab){
  tab.addEventListener('click', function(){
    tabs.forEach(function(t){ t.classList.remove('active'); });
    panels.forEach(function(p){ p.classList.remove('active'); });
    tab.classList.add('active');
    var p = document.getElementById('fv-' + tab.dataset.panel);
    if (p) p.classList.add('active');
    if (window.lucide) lucide.createIcons();
  });
});

// Hero slider (yalnız ana sayfada)
(function(){
  var slides = document.querySelectorAll('.hero-slide');
  var dots = document.querySelectorAll('.h-dot');
  var prev = document.getElementById('heroPrev'), next = document.getElementById('heroNext');
  if (!slides.length || !prev || !next) return;
  var cur = 0, timer;
  var go = function(i){ slides[cur].classList.remove('active'); dots[cur].classList.remove('active'); cur=(i+slides.length)%slides.length; slides[cur].classList.add('active'); dots[cur].classList.add('active'); };
  var start = function(){ timer = setInterval(function(){ go(cur+1); }, 4000); };
  var reset = function(){ clearInterval(timer); start(); };
  prev.addEventListener('click', function(){ go(cur-1); reset(); });
  next.addEventListener('click', function(){ go(cur+1); reset(); });
  dots.forEach(function(d){ d.addEventListener('click', function(){ go(+d.dataset.i); reset(); }); });
  start();
})();

// FAQ accordion
document.querySelectorAll('.faq-q').forEach(function(q){
  q.addEventListener('click', function(){
    var item = q.parentElement; var was = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(function(i){ i.classList.remove('open'); });
    if (!was) item.classList.add('open');
  });
});

// Lightbox
var lb = document.getElementById('lb'), lbImg = document.getElementById('lbImg'), lbClose = document.getElementById('lbClose');
if (lb && lbImg && lbClose) {
  document.querySelectorAll('.ss-big, .ss-card, .ss-mini').forEach(function(el){
    el.addEventListener('click', function(){ var img = el.querySelector('img'); if(!img) return; lbImg.src = img.src; lb.classList.add('open'); document.body.style.overflow='hidden'; });
  });
  lbClose.addEventListener('click', function(){ lb.classList.remove('open'); document.body.style.overflow=''; });
  lb.addEventListener('click', function(e){ if (e.target === lb) lbClose.click(); });
}

// Sync bar animation
var syncObs = new IntersectionObserver(function(entries){
  entries.forEach(function(en){ if (en.isIntersecting) {
    en.target.querySelectorAll('.sync-bar-fill').forEach(function(f,i){ var w=f.style.width; f.style.width='0'; f.style.transition='width 1.1s cubic-bezier(.16,1,.3,1) '+(i*.12)+'s'; requestAnimationFrame(function(){ f.style.width=w; }); });
    syncObs.unobserve(en.target);
  }});
}, {threshold:.3});
document.querySelectorAll('#fv-sync').forEach(function(el){ syncObs.observe(el); });

// Stats counter
(function(){
  var els = document.querySelectorAll('[data-count]');
  if (!els.length) return;
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(en){
      if (!en.isIntersecting) return;
      var el = en.target, target = +el.dataset.count, dur = 1800, t0 = performance.now();
      (function tick(now){ var p = Math.min((now-t0)/dur,1); var ease = 1-Math.pow(1-p,3); el.textContent = Math.round(ease*target); if (p<1) requestAnimationFrame(tick); })(t0);
      obs.unobserve(el);
    });
  }, {threshold:.5});
  els.forEach(function(el){ obs.observe(el); });
})();
</script>
</body>
</html>
