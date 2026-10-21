<?php
/** Kurumsal Ana Sayfa — ozyconnect.com birebir port (site layout). Girdi: $packages, $testimonials, $faqs. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$faqs = $faqs ?? [];
$wa = preg_replace('/\D/', '', (string) brand('contact_phone', ''));
if ($wa === '') { $wa = ''; }
$waHref = 'https://wa.me/' . $wa . '?text=' . rawurlencode('Merhaba, ' . brand('site_name') . ' hakkında bilgi almak istiyorum.');
$mp = fn($slug) => asset('img/marketplaces/' . $slug . '.png');
$ss = fn($name) => asset('img/screenshots/' . $name . '.png');
// Faz 9 — DB içerik (boşsa view-içi fallback). asset() hem 'img/...' hem 'uploads/...' yollarını çözer.
$siteModules  = $siteModules ?? [];
// Modül sayısı dinamik: admin "Site Modülleri"nden gelen aktif kayıt sayısı (5'e yuvarlanır, min 30) → sayı asla bayatlamaz.
$modCount = !empty($siteModules) ? count($siteModules) : 22;
$modRound = max(30, (int) floor($modCount / 5) * 5);
$shotsHero    = $shotsHero ?? [];
$shotsBig     = $shotsBig ?? [];
$shotsGallery = $shotsGallery ?? [];
$img = fn($p) => str_contains((string) $p, '/') ? asset($p) : $ss($p); // tam yol → asset; çıplak ad → eski yardımcı
?>
<!-- ═══ HERO ═══ -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-dots"></div>
  <div class="hero-blob hero-blob-1"></div>
  <div class="hero-blob hero-blob-2"></div>
  <div class="hero-blob hero-blob-3"></div>
  <div class="container">
    <div class="hero-content">
      <div class="hero-badge">
        <span class="hero-badge-pill">YENİ</span>
        <span>Shopify, WooCommerce & OpenCart eklendi · v2026</span>
      </div>
      <h1>Tüm Pazaryerlerini<br><span>Tek Panelden Yönet</span></h1>
      <p class="hero-sub">Sipariş, stok, fiyat, e-arşiv fatura ve kargo — 11 pazaryeri otomatik senkronize.</p>
      <div class="hero-actions">
        <a href="<?= url('/magaza-olustur') ?>" class="btn btn-primary btn-xl"><i data-lucide="rocket"></i> Mağaza Oluştur</a>
        <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-xl"><i data-lucide="message-circle"></i> WhatsApp ile Bilgi Al</a>
      </div>
      <div class="hero-proof">
        <div class="avatars"><span>AY</span><span>MK</span><span>ED</span><span>BT</span></div>
        <div class="proof-text"><strong>150+</strong> işletme <?= $e(brand('site_name')) ?> kullanıyor</div>
      </div>
    </div>

    <style>
    /* A düzeni: yan kolonlar base CSS'te (ol-1..8 = 12/37/62/87 → 4 sol + 4 sağ). Alt 3 logo (ol-9..11) ortada eşit sıra.
       Alt sıra için ayrı float: base orbitFloat translateY(-50%) içerdiği için bottom-bağlı logoları yarım boy yukarı kaçırıyordu;
       orbitFloatB translateX(-50%) ile yatay ortalar + sade dikey salınım yapar, hepsi aynı tabana oturur. */
    @keyframes orbitFloatB{0%,100%{transform:translate(-50%,0)}50%{transform:translate(-50%,-12px)}}
    .hero-orbit .ol-9,.hero-orbit .ol-10,.hero-orbit .ol-11{top:auto;right:auto;bottom:-125px;animation:orbitFloatB 3.8s ease-in-out infinite}
    .hero-orbit .ol-9{left:30%;animation-delay:.3s}
    .hero-orbit .ol-10{left:50%;animation-delay:.9s}
    .hero-orbit .ol-11{left:70%;animation-delay:1.5s}
    @media(min-width:1101px){.hero-orbit{margin-bottom:150px}}
    </style>
    <div class="hero-orbit reveal">
      <div class="orbit-logo ol-1"><div class="orbit-logo-circle"><img src="<?= $e($mp('trendyol')) ?>" alt="Trendyol"></div><span>Trendyol</span></div>
      <div class="orbit-logo ol-2"><div class="orbit-logo-circle"><img src="<?= $e($mp('amazon')) ?>" alt="Amazon TR"></div><span>Amazon TR</span></div>
      <div class="orbit-logo ol-3"><div class="orbit-logo-circle"><img src="<?= $e($mp('hepsiburada')) ?>" alt="Hepsiburada"></div><span>Hepsiburada</span></div>
      <div class="orbit-logo ol-4"><div class="orbit-logo-circle"><img src="<?= $e($mp('n11')) ?>" alt="n11"></div><span>n11</span></div>
      <div class="orbit-logo ol-5"><div class="orbit-logo-circle"><img src="<?= $e($mp('pazarama')) ?>" alt="Pazarama"></div><span>Pazarama</span></div>
      <div class="orbit-logo ol-6"><div class="orbit-logo-circle"><img src="<?= $e($mp('pttavm')) ?>" alt="PttAVM"></div><span>PttAVM</span></div>
      <div class="orbit-logo ol-7"><div class="orbit-logo-circle"><img src="<?= $e($mp('ciceksepeti')) ?>" alt="ÇiçekSepeti"></div><span>ÇiçekSepeti</span></div>
      <div class="orbit-logo ol-8"><div class="orbit-logo-circle"><img src="<?= $e($mp('idefix')) ?>" alt="İdefix"></div><span>İdefix</span></div>
      <div class="orbit-logo ol-9"><div class="orbit-logo-circle"><img src="<?= $e($mp('shopify')) ?>" alt="Shopify"></div><span>Shopify</span></div>
      <div class="orbit-logo ol-10"><div class="orbit-logo-circle"><img src="<?= $e($mp('woocommerce')) ?>" alt="WooCommerce"></div><span>WooCommerce</span></div>
      <div class="orbit-logo ol-11"><div class="orbit-logo-circle"><img src="<?= $e($mp('opencart')) ?>" alt="OpenCart"></div><span>OpenCart</span></div>
      <div class="browser">
        <div class="browser-bar">
          <span class="b-dot r"></span><span class="b-dot y"></span><span class="b-dot g"></span>
          <div class="b-url"><i data-lucide="lock"></i> panel.<?= $e(strtolower(preg_replace('/\s+/', '', brand('site_name')))) ?>.com</div>
          <div class="browser-nav">
            <button class="b-nav-btn" id="heroPrev"><i data-lucide="chevron-left"></i></button>
            <button class="b-nav-btn" id="heroNext"><i data-lucide="chevron-right"></i></button>
          </div>
        </div>
        <?php
        $heroSlides = !empty($shotsHero) ? $shotsHero : [
          ['image_path'=>'img/screenshots/orders.png','title'=>'Siparişler'],
          ['image_path'=>'img/screenshots/product.png','title'=>'Ürünler'],
          ['image_path'=>'img/screenshots/sync.png','title'=>'Senkronizasyon'],
          ['image_path'=>'img/screenshots/pazaryerleri.png','title'=>'Pazaryerleri'],
          ['image_path'=>'img/screenshots/returns.png','title'=>'İadeler'],
        ];
        ?>
        <div class="hero-slider" id="heroSlider">
          <?php foreach ($heroSlides as $i => $hs): ?>
          <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>"><img src="<?= $e($img($hs['image_path'])) ?>" alt="<?= $e($hs['title'] ?? '') ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="hero-dots-nav" id="heroDots">
          <?php foreach ($heroSlides as $i => $hs): ?><span class="h-dot<?= $i === 0 ? ' active' : '' ?>" data-i="<?= $i ?>"></span><?php endforeach; ?>
        </div>
      </div>
    </div><!-- /.hero-orbit -->
  </div>
</section>

<!-- ═══ LOGOS STRIP ═══ -->
<div class="logos-strip">
<div class="container">
  <div class="logos-label">Entegre Edilen Pazaryerleri</div>
  <div class="logos-overflow">
    <div class="logos-track">
      <?php
      $logos = [['trendyol','Trendyol'],['hepsiburada','Hepsiburada'],['amazon','Amazon TR'],['n11','n11'],['pazarama','Pazarama'],['pttavm','PttAVM'],['ciceksepeti','ÇiçekSepeti'],['idefix','İdefix'],['shopify','Shopify'],['woocommerce','WooCommerce'],['opencart','OpenCart']];
      foreach ([0, 1] as $dup):
        foreach ($logos as [$slug, $name]): ?>
        <div class="logo-item"<?= $dup ? ' aria-hidden="true"' : '' ?>><img src="<?= $e($mp($slug)) ?>" alt="<?= $dup ? '' : $e($name) ?>"><span><?= $e($name) ?></span></div>
        <?php endforeach;
      endforeach; ?>
    </div>
  </div>
</div>
</div>

<!-- ═══ STATS ═══ -->
<div class="stats">
<div class="container">
  <div class="stats-grid">
    <div class="stat-item reveal d1">
      <div class="stat-icon-wrap" style="background:var(--blue-light)"><i data-lucide="store" style="color:var(--blue)"></i></div>
      <div class="stat-num"><span data-count="11">11</span><span class="stat-suffix">+</span></div>
      <div class="stat-label">Pazaryeri Entegrasyonu</div>
    </div>
    <div class="stat-item reveal d2">
      <div class="stat-icon-wrap" style="background:var(--purple-light)"><i data-lucide="layout-grid" style="color:var(--purple)"></i></div>
      <div class="stat-num"><span data-count="<?= $modRound ?>"><?= $modRound ?></span><span class="stat-suffix">+</span></div>
      <div class="stat-label">Entegre Modül</div>
    </div>
    <div class="stat-item reveal d3">
      <div class="stat-icon-wrap" style="background:var(--green-light)"><i data-lucide="refresh-cw" style="color:var(--green)"></i></div>
      <div class="stat-num">7<span class="stat-suffix">/24</span></div>
      <div class="stat-label">Otomatik Senkronizasyon</div>
    </div>
    <div class="stat-item reveal d4">
      <div class="stat-icon-wrap" style="background:var(--orange-light)"><i data-lucide="shield-check" style="color:var(--orange)"></i></div>
      <div class="stat-num">AES<span class="stat-suffix">-256</span></div>
      <div class="stat-label">Veri Güvenliği</div>
    </div>
  </div>
</div>
</div>

<!-- ═══ FEATURES ═══ -->
<section class="features section" id="features">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-blue mx-auto"><i data-lucide="layers"></i> Temel Özellikler</div>
    <h2 class="heading-lg">Operasyonunuz Tam Otomatik</h2>
    <p class="subtext text-center mx-auto" style="margin-top:12px">Tüm e-ticaret süreçlerinizi tek panelden. Her özellik iş yükünüzü azaltmak için tasarlandı.</p>
  </div>
  <div class="features-grid">
    <div class="feat-tabs">
      <div class="ftab active" data-panel="orders">
        <div class="ftab-head">
          <div class="ftab-icon" style="background:var(--blue-light)"><i data-lucide="package" style="color:var(--blue)"></i></div>
          <h3>Sipariş Yönetimi</h3>
          <div class="ftab-chevron"><i data-lucide="chevron-right"></i></div>
        </div>
        <div class="ftab-body">11 pazaryerinden gelen tüm siparişleri tek ekranda görün. Otomatik durum güncelleme, toplu kargolama, paket bölme.</div>
      </div>
      <div class="ftab" data-panel="invoice">
        <div class="ftab-head">
          <div class="ftab-icon" style="background:var(--green-light)"><i data-lucide="file-check" style="color:var(--green)"></i></div>
          <h3>E-Arşiv Fatura</h3>
          <div class="ftab-chevron"><i data-lucide="chevron-right"></i></div>
        </div>
        <div class="ftab-body">GİB uyumlu e-Arşiv fatura belgelerini doğrudan panelden oluşturun. Toplu faturalama, iptal ve PDF gönderim.</div>
      </div>
      <div class="ftab" data-panel="cargo">
        <div class="ftab-head">
          <div class="ftab-icon" style="background:var(--orange-light)"><i data-lucide="truck" style="color:var(--orange)"></i></div>
          <h3>Kargo Etiketi</h3>
          <div class="ftab-chevron"><i data-lucide="chevron-right"></i></div>
        </div>
        <div class="ftab-body">Otomatik kargo etiketi oluşturma ve toplu yazdırma. Her pazaryerinin kargo firmasına uyumlu barkod.</div>
      </div>
      <div class="ftab" data-panel="sync">
        <div class="ftab-head">
          <div class="ftab-icon" style="background:var(--purple-light)"><i data-lucide="refresh-cw" style="color:var(--purple)"></i></div>
          <h3>Stok & Fiyat Sync</h3>
          <div class="ftab-chevron"><i data-lucide="chevron-right"></i></div>
        </div>
        <div class="ftab-body">Stok ve fiyat değişikliklerini 11 pazaryerine eş zamanlı yansıtın. 7/24 otomatik senkronizasyon.</div>
      </div>
    </div>

    <div class="feat-visual reveal-right">
      <!-- Orders Panel -->
      <div class="fv-panel active" id="fv-orders">
        <div class="fv-header">
          <div class="fv-header-dot" style="background:var(--blue)"></div>
          <span>Sipariş Yönetimi</span>
        </div>
        <div class="fv-tabs-row">
          <span class="fv-tab active">Tümü (148)</span>
          <span class="fv-tab">Yeni (5)</span>
          <span class="fv-tab">Kargoda (42)</span>
        </div>
        <div class="fv-body">
          <div class="order-row"><div class="o-av" style="background:linear-gradient(135deg,#bfdbfe,#60a5fa)"></div><div class="o-info"><div class="o-name">Ahmet Yılmaz</div><div class="o-id">#TY-48291038</div></div><img class="o-mp" src="<?= $e($mp('trendyol')) ?>" alt=""><span class="badge badge-green">Kargoda</span><span class="o-price">₺1.249</span></div>
          <div class="order-row"><div class="o-av" style="background:linear-gradient(135deg,#e9d5ff,#a78bfa)"></div><div class="o-info"><div class="o-name">Elif Demir</div><div class="o-id">#HB-77210456</div></div><img class="o-mp" src="<?= $e($mp('hepsiburada')) ?>" alt=""><span class="badge badge-orange">Hazırlanıyor</span><span class="o-price">₺3.890</span></div>
          <div class="order-row"><div class="o-av" style="background:linear-gradient(135deg,#fde68a,#f59e0b)"></div><div class="o-info"><div class="o-name">Mehmet Kaya</div><div class="o-id">#AZ-91028374</div></div><img class="o-mp" src="<?= $e($mp('amazon')) ?>" alt=""><span class="badge badge-blue">Yeni</span><span class="o-price">₺749</span></div>
          <div class="order-row"><div class="o-av" style="background:linear-gradient(135deg,#a7f3d0,#34d399)"></div><div class="o-info"><div class="o-name">Zeynep Arslan</div><div class="o-id">#N11-5820194</div></div><img class="o-mp" src="<?= $e($mp('n11')) ?>" alt=""><span class="badge badge-green">Kargoda</span><span class="o-price">₺2.150</span></div>
          <div class="order-row"><div class="o-av" style="background:linear-gradient(135deg,#fecaca,#f87171)"></div><div class="o-info"><div class="o-name">Can Yıldırım</div><div class="o-id">#CS-3921047</div></div><img class="o-mp" src="<?= $e($mp('ciceksepeti')) ?>" alt=""><span class="badge badge-red">İptal</span><span class="o-price">₺580</span></div>
        </div>
      </div>
      <!-- Invoice Panel -->
      <div class="fv-panel" id="fv-invoice">
        <div class="fv-header"><div class="fv-header-dot" style="background:var(--green)"></div><span>E-Arşiv Fatura</span></div>
        <div class="fv-tabs-row"><span class="fv-tab active">Tümü</span><span class="fv-tab">E-Arşiv</span><span class="fv-tab">İptal</span></div>
        <div class="fv-body" style="display:flex;flex-direction:column;gap:8px">
          <div class="inv-card"><div class="inv-head"><span class="inv-logo">E-ARŞİV FATURA</span><span class="inv-tag">GİB Onaylı ✓</span></div><div class="inv-row"><span>Fatura No</span><span>OZY2026041500024</span></div><div class="inv-row"><span>Müşteri</span><span>Ahmet Yılmaz</span></div><div class="inv-row"><span>KDV Dahil</span><span>₺1.498,80</span></div><div class="inv-divider"></div><div class="inv-total">₺1.498,80</div><div class="inv-actions"><span class="inv-btn inv-btn-primary">PDF İndir</span><span class="inv-btn inv-btn-ghost">İptal Et</span></div></div>
          <div class="inv-card"><div class="inv-head"><span class="inv-logo">E-ARŞİV FATURA</span><span class="inv-tag">GİB Onaylı ✓</span></div><div class="inv-row"><span>Fatura No</span><span>OZY2026041500025</span></div><div class="inv-row"><span>Müşteri</span><span>Elif Demir</span></div><div class="inv-row"><span>KDV Dahil</span><span>₺4.668,00</span></div><div class="inv-divider"></div><div class="inv-total">₺4.668,00</div><div class="inv-actions"><span class="inv-btn inv-btn-primary">PDF İndir</span><span class="inv-btn inv-btn-ghost">İptal Et</span></div></div>
        </div>
      </div>
      <!-- Cargo Panel -->
      <div class="fv-panel" id="fv-cargo">
        <div class="fv-header"><div class="fv-header-dot" style="background:var(--orange)"></div><span>Kargo Etiketleri</span></div>
        <div class="fv-tabs-row"><span class="fv-tab active">Oluşturulan (12)</span><span class="fv-tab">Yazdırılan (8)</span></div>
        <div class="fv-body" style="display:flex;flex-direction:column;gap:8px">
          <div class="cargo-card"><div class="cargo-top"><div class="cargo-mp"><img src="<?= $e($mp('trendyol')) ?>" alt="">Trendyol Kargo</div><span class="cargo-status">● Hazır</span></div><div class="barcode"><span style="height:100%"></span><span style="height:70%"></span><span style="height:100%"></span><span style="height:50%"></span><span style="height:100%"></span><span style="height:100%"></span><span style="height:60%"></span><span style="height:100%"></span><span style="height:40%"></span><span style="height:100%"></span><span style="height:80%"></span><span style="height:100%"></span><span style="height:50%"></span><span style="height:100%"></span><span style="height:70%"></span><span style="height:100%"></span></div><div class="cargo-info"><strong>Alıcı:</strong> Ahmet Yılmaz — İstanbul/Kadıköy<br><strong>Takip No:</strong> TY48291038KRG</div></div>
          <div class="cargo-card"><div class="cargo-top"><div class="cargo-mp"><img src="<?= $e($mp('hepsiburada')) ?>" alt="">HB Kargo</div><span class="cargo-status">● Hazır</span></div><div class="barcode"><span style="height:100%"></span><span style="height:55%"></span><span style="height:100%"></span><span style="height:75%"></span><span style="height:100%"></span><span style="height:40%"></span><span style="height:100%"></span><span style="height:100%"></span><span style="height:65%"></span><span style="height:100%"></span><span style="height:90%"></span><span style="height:100%"></span></div><div class="cargo-info"><strong>Alıcı:</strong> Elif Demir — Ankara/Çankaya<br><strong>Takip No:</strong> HB77210456KRG</div></div>
        </div>
      </div>
      <!-- Sync Panel -->
      <div class="fv-panel" id="fv-sync">
        <div class="fv-header"><div class="fv-header-dot" style="background:var(--purple)"></div><span>Senkronizasyon Durumu</span></div>
        <div class="fv-tabs-row"><span class="fv-tab active">Genel Bakış</span><span class="fv-tab">Loglar</span></div>
        <div class="fv-body">
          <div class="sync-row"><span class="sync-dot" style="background:var(--green)"></span><span class="sync-name">Stok Sync</span><div class="sync-bar-wrap"><div class="sync-bar-fill" style="width:100%;background:var(--green)"></div></div><span class="sync-pct" style="color:var(--green)">Tamamlandı</span></div>
          <div class="sync-row"><span class="sync-dot" style="background:var(--blue)"></span><span class="sync-name">Fiyat Sync</span><div class="sync-bar-wrap"><div class="sync-bar-fill" style="width:78%;background:var(--blue)"></div></div><span class="sync-pct" style="color:var(--blue)">%78</span></div>
          <div class="sync-row"><span class="sync-dot" style="background:var(--orange)"></span><span class="sync-name">Sipariş Sync</span><div class="sync-bar-wrap"><div class="sync-bar-fill" style="width:45%;background:var(--orange)"></div></div><span class="sync-pct" style="color:var(--orange)">Çalışıyor</span></div>
          <div class="sync-row"><span class="sync-dot" style="background:var(--green)"></span><span class="sync-name">İade Sync</span><div class="sync-bar-wrap"><div class="sync-bar-fill" style="width:100%;background:var(--green)"></div></div><span class="sync-pct" style="color:var(--green)">Tamamlandı</span></div>
          <div class="sync-row"><span class="sync-dot" style="background:var(--purple)"></span><span class="sync-name">Soru Sync</span><div class="sync-bar-wrap"><div class="sync-bar-fill" style="width:62%;background:var(--purple)"></div></div><span class="sync-pct" style="color:var(--purple)">%62</span></div>
        </div>
      </div>
    </div>
  </div>
</div>
</section>

<!-- ═══ MARKETPLACES ═══ -->
<section class="marketplaces section" id="marketplaces">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-purple mx-auto"><i data-lucide="store"></i> Pazaryerleri</div>
    <h2 class="heading-lg">11 Pazaryeri, Tek Entegrasyon</h2>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Türkiye'nin en büyük pazaryerlerinin tamamıyla sipariş, stok, fiyat ve kargo entegrasyonu.</p>
  </div>
  <div class="mp-grid">
    <?php
    $mpCards = [
      ['trendyol','Trendyol','#F27A1A',['Sipariş','Stok','Fatura','Kargo','İade']],
      ['hepsiburada','Hepsiburada','#FF6000',['Sipariş','Stok','Fatura','Kargo','İade']],
      ['amazon','Amazon TR','#FF9900',['SP-API','OAuth2','Sipariş','Stok','FBA']],
      ['n11','n11','#7C3AED',['Sipariş','Stok','Fatura','İade','Sorular']],
      ['pazarama','Pazarama','#5B21B6',['OAuth2','Sipariş','Stok','Fiyat','Kargo']],
      ['pttavm','PttAVM','#009B77',['Sipariş','Stok','Kargo','Fiyat','Etiket']],
      ['ciceksepeti','ÇiçekSepeti','#E8470A',['Sipariş','Stok','Fatura','İade','Fiyat']],
      ['idefix','İdefix','#E31E24',['Sipariş','Stok','Fiyat','Kargo','Katalog']],
      ['shopify','Shopify','#95BF47',['Sipariş','Stok','Ürün','Kategori','Webhook']],
      ['woocommerce','WooCommerce','#7F54B3',['REST API','Sipariş','Stok','Fiyat','Kategori']],
      ['opencart','OpenCart','#1C8ED8',['Connector','Sipariş','Stok','Fiyat','Anlık']],
    ];
    foreach ($mpCards as $i => [$slug, $name, $color, $chips]): ?>
    <div class="mp-card reveal<?= $i > 0 ? ' d' . min($i, 7) : '' ?>" style="--mp-color:<?= $color ?>">
      <div class="mp-card-logo-wrap"><img src="<?= $e($mp($slug)) ?>" alt="<?= $e($name) ?>"></div>
      <h4><?= $e($name) ?></h4>
      <div class="mp-badge-aktif"><span class="mp-badge-dot"></span>Aktif Entegrasyon</div>
      <div class="mp-chips">
        <?php foreach ($chips as $c): ?><span class="mp-chip"><?= $e($c) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</section>

<!-- ═══ MODULES ═══ -->
<section class="modules section" id="modules">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-blue mx-auto"><i data-lucide="grid-3x3"></i> Modüller</div>
    <h2 class="heading-lg"><?= $modRound ?>+ Modül, Tam Kontrol</h2>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Her e-ticaret süreciniz için özel bir modül. İhtiyacınız olan her şey burada.</p>
  </div>
  <div class="mod-grid">
    <?php
    $mods = [
      ['package','#2563eb','var(--blue-light)','Sipariş Yönetimi','Toplu kargolama, durum güncelleme.'],
      ['file-check','#059669','var(--green-light)','E-Arşiv Fatura','GİB entegrasyonu, toplu faturalama.'],
      ['truck','#d97706','var(--orange-light)','Kargo Etiketi','Otomatik etiket, toplu yazdırma.'],
      ['refresh-cw','#7c3aed','var(--purple-light)','Stok Senkronizasyonu','11 pazaryerine anlık stok güncelleme.'],
      ['tag','#2563eb','var(--blue-light)','Fiyat Yönetimi','Dinamik kurallar, toplu güncelleme.'],
      ['rotate-ccw','#dc2626','var(--red-light)','İade & İptal','Merkezi iade yönetimi, kargo takibi.'],
      ['message-circle','#7c3aed','#fdf4ff','Müşteri Soruları','Tüm kanallardan tek panelde yanıt.'],
      ['box','#2563eb','var(--blue-light)','Ürün Kataloğu','Varyant, resim, toplu düzenleme.'],
      ['git-branch','#059669','var(--green-light)','Kategori & Marka','Otomatik eşleştirme, senkronizasyon.'],
      ['upload','#d97706','var(--orange-light)','İçe & Dışa Aktarma','CSV/XML import, Excel export.'],
      ['rss','#2563eb','var(--blue-light)','XML Feed','Dinamik ürün feedleri, token koruma.'],
      ['bar-chart-2','#7c3aed','var(--purple-light)','Dashboard & Raporlar','Ciro grafiği, pazaryeri dağılımı.'],
      ['wallet','#059669','var(--green-light)','Finans','Günlük finans verileri, raporlama.'],
      ['shield','#d97706','var(--orange-light)','Rol & Yetkilendirme','RBAC, modül izinleri, çoklu kullanıcı.'],
      ['database-backup','#2563eb','var(--blue-light)','Otomatik Yedekleme','DB + dosya, cron, ZIP arşiv.'],
      ['lock','#dc2626','var(--red-light)','AES-256 Güvenlik','Şifreli API anahtarları, CSRF koruması.'],
      ['volume-2','#d97706','#fef9c3','Sesli Bildirimler','Yeni sipariş, iade, soru — her olay için farklı ses.'],
      ['bell-ring','#2563eb','var(--blue-light)','Anlık Bildirimler','Gerçek zamanlı zil bildirimleri, toast popup.'],
      ['sparkles','#7c3aed','var(--purple-light)','Ürün Enrichment','Eksik ürün verilerini otomatik zenginleştirme.'],
      ['download-cloud','#059669','var(--green-light)','Otomatik Güncelleme','Tek tıkla panel güncellemesi, versiyon takibi.'],
      ['key-round','#2563eb','var(--blue-light)','API Token Yönetimi','Dış sisteme güvenli REST API erişimi.'],
      ['palette','#dc2626','var(--red-light)','White-Label','Logo, site adı ve marka kimliği özelleştirme.'],
    ];
    // DB doluysa (seed sayesinde dolu) admin-yönetilen modüller kullanılır; değilse yukarıdaki fallback.
    if (!empty($siteModules)) {
      $mods = array_map(static function ($m) {
        $col = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $m['color']) ? $m['color'] : '#2563eb';
        return [$m['icon'], $col, 'color-mix(in srgb,' . $col . ' 13%,#fff)', $m['title'], (string) ($m['description'] ?? '')];
      }, $siteModules);
    }
    foreach ($mods as $i => [$icon, $color, $bg, $title, $desc]): ?>
    <div class="mod-card reveal<?= ($i % 8) ? ' d' . ($i % 8) : '' ?>" style="--mod-color:<?= $e($color) ?>"><div class="mod-icon" style="background:<?= $bg ?>"><i data-lucide="<?= $e($icon) ?>" style="color:<?= $e($color) ?>"></i></div><h4><?= $e($title) ?></h4><p><?= $e($desc) ?></p></div>
    <?php endforeach; ?>
  </div>
</div>
</section>

<!-- ═══ SCREENSHOTS ═══ -->
<section class="screenshots section" id="screenshots">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-purple mx-auto"><i data-lucide="monitor"></i> Ekran Görüntüleri</div>
    <h2 class="heading-lg">Paneli Yakından İnceleyin</h2>
    <p class="subtext mx-auto text-center" style="margin-top:12px"><?= $e(brand('site_name')) ?> gerçek panel ekranları. Tüm özellikler tek tıkla erişilebilir.</p>
  </div>
  <div class="ss-main">
    <?php
    $big = $shotsBig[0] ?? ['image_path' => 'img/screenshots/orders.png', 'title' => 'Sipariş Yönetimi'];
    $gal = !empty($shotsGallery) ? $shotsGallery : [
      ['image_path'=>'img/screenshots/sync.png','title'=>'Stok & Fiyat Sync','caption'=>'11 pazaryerine anlık güncelleme'],
      ['image_path'=>'img/screenshots/pazaryerleri.png','title'=>'Pazaryeri Yönetimi','caption'=>'Tüm entegrasyonlar tek ekranda'],
      ['image_path'=>'img/screenshots/product.png','title'=>'Ürün Kataloğu','caption'=>''],
      ['image_path'=>'img/screenshots/category.png','title'=>'Kategori & Marka','caption'=>''],
      ['image_path'=>'img/screenshots/returns.png','title'=>'İade & İptal','caption'=>''],
      ['image_path'=>'img/screenshots/customer.png','title'=>'Müşteriler','caption'=>''],
      ['image_path'=>'img/screenshots/auth.png','title'=>'Yetkilendirme','caption'=>''],
      ['image_path'=>'img/screenshots/login.png','title'=>'Giriş Ekranı','caption'=>''],
    ];
    $first2 = array_slice($gal, 0, 2);
    $rest = array_slice($gal, 2);
    ?>
    <div class="ss-big reveal" data-cap="<?= $e($big['title'] ?? '') ?>">
      <div class="ss-big-bar"><span class="b-dot r"></span><span class="b-dot y"></span><span class="b-dot g"></span><span class="ss-big-title"><?= $e($big['title'] ?? '') ?></span></div>
      <img src="<?= $e($img($big['image_path'])) ?>" alt="<?= $e($big['title'] ?? '') ?>">
    </div>
    <?php if ($first2): ?>
    <div class="ss-2col">
      <?php foreach ($first2 as $k => $g): ?>
      <div class="ss-card <?= $k === 0 ? 'reveal-left' : 'reveal-right' ?>" data-cap="<?= $e($g['title'] ?? '') ?>">
        <div class="ss-card-bar"><span class="b-dot r"></span><span class="b-dot y"></span><span class="b-dot g"></span><span><?= $e($g['title'] ?? '') ?></span></div>
        <img src="<?= $e($img($g['image_path'])) ?>" alt="<?= $e($g['title'] ?? '') ?>">
        <?php if (!empty($g['title']) || !empty($g['caption'])): ?><div class="ss-card-info"><h4><?= $e($g['title'] ?? '') ?></h4><p><?= $e($g['caption'] ?? '') ?></p></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($rest): ?>
    <div class="ss-3col">
      <?php foreach ($rest as $j => $g): ?>
      <div class="ss-mini reveal d<?= ($j % 6) + 1 ?>" data-cap="<?= $e($g['title'] ?? '') ?>"><div class="ss-mini-bar"><span class="b-dot r"></span><span class="b-dot y"></span><span class="b-dot g"></span></div><img src="<?= $e($img($g['image_path'])) ?>" alt="<?= $e($g['title'] ?? '') ?>"><h5><?= $e($g['title'] ?? '') ?></h5></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</section>

<!-- ═══ WHY ═══ -->
<section class="why section">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-green mx-auto"><i data-lucide="award"></i> Neden <?= $e(brand('site_name')) ?>?</div>
    <h2 class="heading-lg">Farkımız Ne?</h2>
  </div>
  <div class="why-grid">
    <div class="why-visual reveal-left">
      <div class="why-stats">
        <div class="why-stat"><div class="why-stat-icon" style="background:var(--blue-light)"><i data-lucide="zap" style="color:var(--blue)"></i></div><div class="why-stat-val" style="color:var(--blue)">%99.9</div><div class="why-stat-label">Uptime Garantisi</div></div>
        <div class="why-stat"><div class="why-stat-icon" style="background:var(--green-light)"><i data-lucide="shield-check" style="color:var(--green)"></i></div><div class="why-stat-val" style="color:var(--green)">AES-256</div><div class="why-stat-label">Şifreleme</div></div>
        <div class="why-stat"><div class="why-stat-icon" style="background:var(--purple-light)"><i data-lucide="clock" style="color:var(--purple)"></i></div><div class="why-stat-val" style="color:var(--purple)">&lt;5dk</div><div class="why-stat-label">Kurulum Süresi</div></div>
        <div class="why-stat"><div class="why-stat-icon" style="background:var(--orange-light)"><i data-lucide="refresh-cw" style="color:var(--orange)"></i></div><div class="why-stat-val" style="color:var(--orange)">7/24</div><div class="why-stat-label">Otomatik Sync</div></div>
      </div>
    </div>
    <div class="why-items reveal-right">
      <div class="why-item"><div class="why-item-icon" style="background:var(--blue-light)"><i data-lucide="database" style="color:var(--blue)"></i></div><div><h3>Kesintisiz Veri Akışı</h3><p>Anlık senkronizasyon ile tüm kanallarınız arasında kesintisiz veri akışı. SSL şifrelemeli güvenli API bağlantıları.</p></div></div>
      <div class="why-item"><div class="why-item-icon" style="background:var(--green-light)"><i data-lucide="shield-check" style="color:var(--green)"></i></div><div><h3>Çok-Kiracılı Güvenli Altyapı</h3><p>Her mağaza izole; veriler AES-256 ile şifreli saklanır, erişim rol/yetki ve 2FA ile korunur. Barındırılan SaaS ile bakım yükü sizde değil.</p></div></div>
      <div class="why-item"><div class="why-item-icon" style="background:var(--purple-light)"><i data-lucide="puzzle" style="color:var(--purple)"></i></div><div><h3>Modüler Yapı</h3><p>İhtiyacınız olan modülleri aktif edin, kullanmadıklarınızı kapatın. Esnek, genişletilebilir mimari.</p></div></div>
    </div>
  </div>
</div>
</section>

<!-- ═══ HOW IT WORKS ═══ -->
<section class="how section">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-green mx-auto"><i data-lucide="list-ordered"></i> Kurulum</div>
    <h2 class="heading-lg">4 Adımda Hazır</h2>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Kurulum basit, entegrasyon hızlı. Dakikalar içinde pazaryerlerinizi bağlayın.</p>
  </div>
  <div class="how-grid">
    <div class="how-card reveal"><div class="how-num">1</div><h3>Mağaza Oluştur</h3><p>Kısa başvuru formunu doldurun; hesabınız dakikalar içinde hazırlanır.</p></div>
    <div class="how-card reveal d1"><div class="how-num">2</div><h3>Pazaryerlerini Bağla</h3><p>API anahtarlarınızı girin, bağlantıyı test edin.</p></div>
    <div class="how-card reveal d2"><div class="how-num">3</div><h3>Ürün & Stok Senkronu</h3><p>Kataloğunuzu içe aktarın; stok ve fiyat otomatik eşitlensin.</p></div>
    <div class="how-card reveal d3"><div class="how-num">4</div><h3>Satışa Başla</h3><p>Siparişler, fatura ve kargo otomatik ilerlemeye başlar.</p></div>
  </div>
</div>
</section>

<!-- ═══ PRICING ═══ -->
<section class="pricing section" id="pricing">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-orange mx-auto"><i data-lucide="tag"></i> Fiyatlandırma</div>
    <h2 class="heading-lg">İşletmenize Uygun Plan</h2>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Yeni nesil sistemimizle tek bir noktadan tüm pazaryerlerini kontrol edebilirsiniz.</p>
  </div>
  <?php require __DIR__ . '/_pricing.php'; ?>
</div>
</section>

<!-- ═══ FAQ ═══ -->
<section class="faq section" id="faq">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-blue mx-auto"><i data-lucide="help-circle"></i> SSS</div>
    <h2 class="heading-lg">Sıkça Sorulan Sorular</h2>
  </div>
  <?php require __DIR__ . '/_faq.php'; ?>
</div>
</section>

<!-- ═══ CTA ═══ -->
<section class="cta-section">
<div class="container cta-inner">
  <h2>E-Ticaretinizi Bir Üst Seviyeye Taşıyın</h2>
  <p>11 pazaryerini tek panelden yönetin. Sipariş, stok, fatura, kargo — her şey otomatik.</p>
  <div class="cta-actions">
    <a href="<?= url('/magaza-olustur') ?>" class="cta-btn-white"><i data-lucide="rocket"></i> Mağaza Oluştur</a>
    <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="cta-btn-ghost"><i data-lucide="message-circle"></i> WhatsApp ile Bilgi Al</a>
  </div>
</div>
</section>
