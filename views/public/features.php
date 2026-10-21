<?php
/** Özellikler sayfası (site layout). Sunum fragmanı — DOCTYPE/head/header/footer layout'ta. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* Dinamik bölümler için guard (controller geçerse dolar, geçmezse partial'lar boş-durum basar). */
$packages = $packages ?? [];
$faqs     = $faqs ?? [];

/* WhatsApp — sözleşme: telefon yoksa 905424922500 */
$wa = preg_replace('/\D/', '', (string) brand('contact_phone', ''));
if ($wa === '') { $wa = ''; }
$waHref = 'https://wa.me/' . $wa . '?text=' . rawurlencode('Merhaba, Entegratör Yazılımı hakkında bilgi almak istiyorum');

/* ── Özellik kartları ── */
$features = [
    ['shopping-cart', 'Sipariş Yönetimi', 'Tüm pazaryeri siparişleri tek kuyrukta; durum, kargo ve faturayı tek ekrandan yönetin.'],
    ['file-text', 'E-Arşiv Fatura', 'GİB onaylı e-Arşiv entegrasyonu; her mağaza kendi hesabıyla siparişe tek tıkla fatura keser.'],
    ['truck', 'Kargo Etiketi', 'Trendyol, HB ve diğer kargolar için etiket ve takip numarası otomatik oluşur.'],
    ['refresh-cw', 'Stok & Fiyat Sync', 'Stok, fiyat ve sipariş durumu arka planda kuyrukla pazaryerlerine eşitlenir; çift veri girişi yok.'],
    ['rotate-ccw', 'İade & İptal', 'İade taleplerini onayla/reddet, müşteriyle yazış, tüm süreci merkezden izle.'],
    ['message-circle-question', 'Müşteri Soruları', 'Ürün sorularını tek gelen kutusunda topla, hazır yanıt şablonlarıyla hızlan.'],
    ['package', 'Ürün & Stok Kataloğu', 'Varyant, toplu düzenleme, kategori/marka eşleştirme ve XML feed ile katalog bir kez kurulur.'],
    ['shield-check', 'Çok-Kiracılı Güvenlik', 'Mağaza bazlı izolasyon, AES-256 şifreleme, rol/yetki ve 2FA korumalı yönetim.'],
];

/* ── Pazaryeri yetenek gridi ── */
$marketplaces = [
    ['trendyol', 'Trendyol', ['Sipariş', 'Stok', 'Fatura', 'Kargo', 'İade']],
    ['hepsiburada', 'Hepsiburada', ['Sipariş', 'Stok', 'Fatura', 'Kargo', 'İade']],
    ['amazon', 'Amazon TR', ['SP-API OAuth2', 'Sipariş', 'Stok', 'FBA']],
    ['n11', 'n11', ['Sipariş', 'Stok', 'Fatura', 'İade', 'Sorular']],
    ['pazarama', 'Pazarama', ['OAuth2', 'Sipariş', 'Stok', 'Fiyat', 'Kargo']],
    ['pttavm', 'PttAVM', ['Sipariş', 'Stok', 'Kargo', 'Fiyat', 'Etiket']],
    ['ciceksepeti', 'ÇiçekSepeti', ['Sipariş', 'Stok', 'Fatura', 'İade', 'Fiyat']],
    ['idefix', 'İdefix', ['Sipariş', 'Stok', 'Fiyat', 'Kargo', 'Katalog']],
    ['shopify', 'Shopify', ['Sipariş', 'Stok', 'Ürün', 'Kategori', 'Webhook']],
    ['woocommerce', 'WooCommerce', ['REST API', 'Sipariş', 'Stok', 'Fiyat', 'Kategori']],
    ['opencart', 'OpenCart', ['Connector', 'Sipariş', 'Stok', 'Fiyat', 'Anlık']],
];

/* ── Modüller ── */
$modules = [
    ['shopping-cart', 'Sipariş Yönetimi'],
    ['file-text', 'E-Arşiv Fatura'],
    ['truck', 'Kargo Etiketi'],
    ['refresh-cw', 'Stok Senkronizasyonu'],
    ['tag', 'Fiyat Yönetimi'],
    ['rotate-ccw', 'İade & İptal'],
    ['message-circle-question', 'Müşteri Soruları'],
    ['package', 'Ürün Kataloğu'],
    ['folder-tree', 'Kategori & Marka'],
    ['arrow-down-up', 'İçe & Dışa Aktarma'],
    ['rss', 'XML Feed'],
    ['bar-chart-3', 'Dashboard & Raporlar'],
    ['wallet', 'Finans'],
    ['shield', 'Rol & Yetkilendirme'],
];

/* ── Senkronizasyon durumları (mockup) ── */
$syncRows = [
    ['Stok Sync', 'done', 100, 'Tamamlandı'],
    ['Fiyat Sync', 'pct', 78, '%78'],
    ['Sipariş Sync', 'run', 45, 'Çalışıyor'],
    ['İade Sync', 'done', 100, 'Tamamlandı'],
    ['Soru Sync', 'pct', 62, '%62'],
];

/* ── Güven kartları + sebepler ── */
$trust = [
    ['activity', '%99.9', 'Uptime Garantisi'],
    ['lock', 'AES-256', 'Şifreleme'],
    ['timer', '<5dk', 'Kurulum Süresi'],
    ['refresh-cw', '7/24', 'Otomatik Sync'],
];
$reasons = [
    ['waypoints', 'Kesintisiz Veri Akışı', 'Sipariş, stok ve fiyat verisi kuyruk altyapısıyla arka planda, kesintisiz eşitlenir. Sayfa yenileme veya manuel müdahale gerekmez.'],
    ['shield-check', 'Çok-Kiracılı Güvenli Altyapı', 'Her mağaza izole; veriler şifreli saklanır, erişim rol/yetki ve 2FA ile korunur. Barındırılan SaaS ile bakım yükü sizde değil.'],
    ['blocks', 'Modüler Yapı', '30+ modül ihtiyacınıza göre devreye girer. Yalnızca kullandığınız yeteneklerle sade, hızlı bir panel.'],
];

/* ── Kurulum adımları ── */
$steps = [
    ['Mağaza Oluştur', 'Kısa başvuru formunu doldurun; hesabınız dakikalar içinde hazırlanır.'],
    ['Pazaryerlerini Bağla', 'API anahtarlarınızı girin; 11 pazaryerini tek tek veya toplu bağlayın.'],
    ['Ürün & Stok Senkronu', 'Kataloğunuzu içe aktarın; stok ve fiyat otomatik eşitlenmeye başlasın.'],
    ['Satışa Başla', 'Siparişler tek panele akar; fatura ve kargo otomatik ilerler.'],
];
?>
<!-- ───── HERO ───── -->
<section class="hero-bg">
  <div class="container-x pt-16 pb-16 lg:pt-24 lg:pb-20">
    <div class="grid lg:grid-cols-12 gap-12 items-center">
      <div class="lg:col-span-6 text-center lg:text-left">
        <span class="chip mx-auto lg:mx-0"><i data-lucide="sparkles" class="w-4 h-4"></i> YENİ · Shopify, WooCommerce & OpenCart eklendi · v2026</span>
        <h1 class="h-display mt-6">Tüm Pazaryerlerini <span class="text-gradient">Tek Panelden</span> Yönet</h1>
        <p class="lead mt-5 max-w-xl mx-auto lg:mx-0">Sipariş, stok, fiyat, e-arşiv fatura ve kargo — 11 pazaryeri otomatik senkronize.</p>
        <div class="mt-8 flex flex-wrap items-center justify-center lg:justify-start gap-3">
          <a href="<?= url('/magaza-olustur') ?>" class="btn btn-primary btn-lg">Mağaza Oluştur <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
          <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-lg"><i data-lucide="message-circle" class="w-4 h-4 text-emerald-600"></i> WhatsApp ile Bilgi Al</a>
          <a href="<?= url('/login') ?>" class="btn btn-ghost btn-lg">Demo İncele</a>
        </div>
      </div>
      <!-- Hero mockup: sipariş yönetimi tablosu -->
      <div class="lg:col-span-6">
        <div class="mock-window max-w-lg mx-auto">
          <div class="mock-bar"><span class="ml-3 text-[11.5px] font-medium text-slate-400">panel.entegrator.com · Siparişler</span></div>
          <div class="p-5">
            <div class="flex items-center gap-1.5 mb-4">
              <span class="px-3 py-1.5 rounded-lg text-[12px] font-semibold bg-brand text-white">Tümü</span>
              <span class="px-3 py-1.5 rounded-lg text-[12px] font-medium text-slate-500 hover:bg-slate-100">Yeni</span>
              <span class="px-3 py-1.5 rounded-lg text-[12px] font-medium text-slate-500 hover:bg-slate-100">Kargoda</span>
            </div>
            <div class="space-y-2">
              <?php
              $orders = [
                  ['Ahmet Yılmaz', 'Trendyol', 'Yeni', 'bg-brand/10 text-brand'],
                  ['Elif Demir', 'Hepsiburada', 'Kargoda', 'bg-amber-100 text-amber-700'],
                  ['Mehmet Kaya', 'n11', 'Tamamlandı', 'bg-emerald-100 text-emerald-700'],
              ];
              foreach ($orders as [$name, $mp, $st, $cls]): ?>
              <div class="flex items-center gap-3 p-3 rounded-xl border border-slate-200/70">
                <span class="grid place-items-center w-9 h-9 rounded-full bg-slate-100 text-slate-500 text-[12px] font-bold"><?= $e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                <div class="min-w-0 flex-1">
                  <div class="text-[13px] font-semibold text-slate-800 truncate"><?= $e($name) ?></div>
                  <div class="text-[11.5px] text-slate-400"><?= $e($mp) ?></div>
                </div>
                <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $cls ?>"><?= $e($st) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- istatistik şeridi -->
    <div class="mt-14 flex flex-col items-center gap-6">
      <span class="stat-pill"><i data-lucide="users" class="w-4 h-4 text-brand"></i> 150+ işletme Entegratör Yazılımı kullanıyor</span>
      <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-5 opacity-80">
        <?php foreach ($marketplaces as [$slug, $name, $caps]): ?>
        <span class="inline-flex items-center"><img src="<?= $e(asset('img/marketplaces/' . $slug . '.png')) ?>" alt="<?= $e($name) ?>" class="h-6 w-auto max-w-[110px] object-contain grayscale opacity-90" onerror="this.onerror=null;this.src='<?= $e(asset('img/marketplaces/' . $slug . '.jpg')) ?>'"></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ───── ÖZELLİKLER ───── -->
<section class="container-x section">
  <div class="text-center max-w-2xl mx-auto">
    <span class="eyebrow">Özellikler</span>
    <h2 class="h-section mt-3">Operasyonunuz Tam Otomatik</h2>
    <p class="lead mt-3">Tüm e-ticaret süreçlerinizi tek panelden. Her özellik iş yükünüzü azaltmak için tasarlandı.</p>
  </div>
  <div class="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
    <?php foreach ($features as [$icon, $title, $desc]): ?>
    <div class="card p-6 hover:shadow-lift hover:-translate-y-0.5 transition duration-200">
      <span class="grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand"><i data-lucide="<?= $e($icon) ?>" class="w-5 h-5"></i></span>
      <h3 class="mt-4 text-[16px] font-bold tracking-tight"><?= $e($title) ?></h3>
      <p class="mt-2 text-[13.5px] text-slate-500 leading-relaxed"><?= $e($desc) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ───── ÜRÜN MOCKUP'LARI ───── -->
<section class="bg-slate-50 border-y border-slate-200/70 section">
  <div class="container-x">
    <div class="text-center max-w-2xl mx-auto">
      <span class="eyebrow">Panel Önizleme</span>
      <h2 class="h-section mt-3">Her İş Akışı İçin Tasarlandı</h2>
      <p class="lead mt-3">Fatura, kargo ve senkronizasyon — operasyonun tamamı parmaklarınızın ucunda.</p>
    </div>
    <div class="mt-12 grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- (b) E-Arşiv Fatura -->
      <div class="mock-window">
        <div class="mock-bar"><span class="ml-3 text-[11.5px] font-medium text-slate-400">E-Arşiv Fatura</span></div>
        <div class="p-6">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-[13px] text-slate-400">Fatura No</div>
              <div class="text-[18px] font-bold tracking-tight">OZY-2026-004821</div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11.5px] font-semibold bg-emerald-100 text-emerald-700"><i data-lucide="badge-check" class="w-3.5 h-3.5"></i> GİB Onaylı</span>
          </div>
          <div class="mt-5 space-y-2.5 text-[13px]">
            <div class="flex justify-between"><span class="text-slate-400">Alıcı</span><span class="font-medium text-slate-700">Ahmet Yılmaz</span></div>
            <div class="flex justify-between"><span class="text-slate-400">Tutar</span><span class="font-semibold text-slate-800">1.249,90 ₺</span></div>
            <div class="flex justify-between"><span class="text-slate-400">KDV (%20)</span><span class="font-medium text-slate-700">208,32 ₺</span></div>
          </div>
          <div class="mt-6 flex items-center gap-2">
            <span class="btn btn-primary h-9 px-4 text-[13px]"><i data-lucide="download" class="w-4 h-4"></i> PDF İndir</span>
            <span class="btn btn-ghost h-9 px-4 text-[13px]"><i data-lucide="x-circle" class="w-4 h-4"></i> İptal Et</span>
          </div>
        </div>
      </div>
      <!-- (c) Kargo etiketleri -->
      <div class="mock-window">
        <div class="mock-bar"><span class="ml-3 text-[11.5px] font-medium text-slate-400">Kargo Etiketleri</span></div>
        <div class="p-6 space-y-3">
          <?php
          $labels = [
              ['Trendyol Kargo', 'TY1234567890', 'bg-orange-100 text-orange-700'],
              ['HB Kargo', 'HB9876543210', 'bg-orange-100 text-orange-700'],
          ];
          foreach ($labels as [$carrier, $track, $cls]): ?>
          <div class="flex items-center gap-3 p-3.5 rounded-xl border border-slate-200/70">
            <span class="grid place-items-center w-10 h-10 rounded-xl <?= $cls ?>"><i data-lucide="package-2" class="w-5 h-5"></i></span>
            <div class="min-w-0 flex-1">
              <div class="text-[13.5px] font-semibold text-slate-800"><?= $e($carrier) ?></div>
              <div class="text-[12px] text-slate-400">Takip No: <span class="font-mono"><?= $e($track) ?></span></div>
            </div>
            <i data-lucide="printer" class="w-4 h-4 text-slate-400 shrink-0"></i>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- (d) Senkronizasyon durumu -->
      <div class="mock-window lg:col-span-2">
        <div class="mock-bar"><span class="ml-3 text-[11.5px] font-medium text-slate-400">Senkronizasyon Durumu</span></div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
          <?php foreach ($syncRows as [$label, $kind, $pct, $stTxt]):
            $stCls = $kind === 'run' ? 'text-amber-600' : ($kind === 'done' ? 'text-emerald-600' : 'text-brand');
          ?>
          <div>
            <div class="flex items-center justify-between mb-1.5">
              <span class="text-[13px] font-semibold text-slate-700"><?= $e($label) ?></span>
              <span class="inline-flex items-center gap-1 text-[12px] font-semibold <?= $stCls ?>">
                <?php if ($kind === 'done'): ?><i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i><?php elseif ($kind === 'run'): ?><i data-lucide="loader" class="w-3.5 h-3.5"></i><?php endif; ?>
                <?= $e($stTxt) ?>
              </span>
            </div>
            <div class="progress"><i style="width:<?= (int) $pct ?>%"></i></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ───── PAZARYERLERİ ───── -->
<section id="pazaryerleri" class="container-x section scroll-mt-24">
  <div class="text-center max-w-2xl mx-auto">
    <span class="eyebrow">Pazaryerleri</span>
    <h2 class="h-section mt-3">11 Pazaryeri, Tek Entegrasyon</h2>
    <p class="lead mt-3">Her pazaryerinin sipariş, stok, fatura, kargo ve iade yetenekleri kutudan çıktığı gibi hazır.</p>
  </div>
  <div class="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
    <?php foreach ($marketplaces as [$slug, $name, $caps]): ?>
    <div class="mp-card flex flex-col">
      <div class="flex items-center gap-3">
        <span class="grid place-items-center w-11 h-11 rounded-xl bg-slate-50 border border-slate-200/70 shrink-0">
          <img src="<?= $e(asset('img/marketplaces/' . $slug . '.png')) ?>" alt="<?= $e($name) ?>" class="h-6 w-auto max-w-[34px] object-contain" onerror="this.onerror=null;this.src='<?= $e(asset('img/marketplaces/' . $slug . '.jpg')) ?>'">
        </span>
        <h3 class="text-[15px] font-bold tracking-tight"><?= $e($name) ?></h3>
      </div>
      <div class="mt-4 flex flex-wrap gap-1.5">
        <?php foreach ($caps as $cap): ?><span class="cap-pill"><?= $e($cap) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ───── MODÜLLER ───── -->
<section id="moduller" class="bg-slate-50 border-y border-slate-200/70 section scroll-mt-24">
  <div class="container-x">
    <div class="text-center max-w-2xl mx-auto">
      <span class="eyebrow">Modüller</span>
      <h2 class="h-section mt-3">30+ Modül, Tam Kontrol</h2>
      <p class="lead mt-3">İhtiyacınıza göre devreye giren modüler yapı; yalnızca kullandığınız yeteneklerle sade bir panel.</p>
    </div>
    <div class="mt-12 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-3">
      <?php foreach ($modules as [$icon, $name]): ?>
      <div class="mod-pill">
        <i data-lucide="<?= $e($icon) ?>" class="w-4 h-4 shrink-0"></i>
        <span class="truncate"><?= $e($name) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ───── FARKIMIZ NE ───── -->
<section class="container-x section">
  <div class="text-center max-w-2xl mx-auto">
    <span class="eyebrow">Farkımız Ne</span>
    <h2 class="h-section mt-3">Güvenilir, Hızlı, Ölçeklenebilir</h2>
  </div>
  <div class="mt-12 grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ($trust as [$icon, $big, $label]): ?>
    <div class="trust-card text-center">
      <span class="inline-grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand mx-auto"><i data-lucide="<?= $e($icon) ?>" class="w-5 h-5"></i></span>
      <div class="mt-3 text-[24px] font-extrabold tracking-tight text-slate-900"><?= $e($big) ?></div>
      <div class="text-[13px] text-slate-500"><?= $e($label) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-5">
    <?php foreach ($reasons as [$icon, $title, $desc]): ?>
    <div class="trust-card">
      <span class="grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand"><i data-lucide="<?= $e($icon) ?>" class="w-5 h-5"></i></span>
      <h3 class="mt-4 text-[16px] font-bold tracking-tight"><?= $e($title) ?></h3>
      <p class="mt-2 text-[13.5px] text-slate-500 leading-relaxed"><?= $e($desc) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ───── KURULUM 4 ADIM ───── -->
<section class="bg-slate-50 border-y border-slate-200/70 section">
  <div class="container-x">
    <div class="text-center max-w-2xl mx-auto">
      <span class="eyebrow">Kurulum</span>
      <h2 class="h-section mt-3">4 Adımda Satışa Başlayın</h2>
      <p class="lead mt-3">Self-servis kurulum; dakikalar içinde panelinizi açın, pazaryerlerinizi bağlayın.</p>
    </div>
    <div class="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
      <?php foreach ($steps as $i => [$title, $desc]): ?>
      <div class="card p-6">
        <span class="step-num"><?= $i + 1 ?></span>
        <h3 class="mt-4 text-[16px] font-bold tracking-tight"><?= $e($title) ?></h3>
        <p class="mt-2 text-[13.5px] text-slate-500 leading-relaxed"><?= $e($desc) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ───── FİYATLANDIRMA ───── -->
<section class="container-x section">
  <div class="text-center max-w-2xl mx-auto">
    <span class="eyebrow">Paketler</span>
    <h2 class="h-section mt-3">İşinize Uygun Planı Seçin</h2>
    <p class="lead mt-3">Ücretsiz deneme ile başlayın, istediğiniz zaman yükseltin.</p>
  </div>
  <div class="mt-12"><?php require __DIR__ . '/_pricing.php'; ?></div>
</section>

<!-- ───── SSS ───── -->
<?php if (!empty($faqs)): ?>
<section class="bg-slate-50 border-y border-slate-200/70 section">
  <div class="container-x">
    <div class="text-center max-w-2xl mx-auto mb-10"><span class="eyebrow">SSS</span><h2 class="h-section mt-3">Sık Sorulan Sorular</h2></div>
    <?php require __DIR__ . '/_faq.php'; ?>
  </div>
</section>
<?php endif; ?>

<!-- ───── SON CTA ───── -->
<section class="container-x py-20">
  <div class="rounded-3xl bg-brand text-white px-8 py-14 text-center shadow-glow">
    <h2 class="text-[28px] sm:text-[34px] font-bold tracking-tight">E-Ticaretinizi Bir Üst Seviyeye Taşıyın</h2>
    <p class="mt-3 text-white/85 text-[16px] max-w-xl mx-auto">11 pazaryerini tek panelden yönetin; sipariş, stok, fatura ve kargo otomasyonuyla zaman kazanın.</p>
    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
      <a href="<?= url('/magaza-olustur') ?>" class="btn btn-lg bg-white text-brand hover:brightness-95">Mağaza Oluştur <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
      <a href="<?= $e($waHref) ?>" target="_blank" rel="noopener" class="btn btn-lg bg-white/15 text-white hover:bg-white/25"><i data-lucide="message-circle" class="w-4 h-4"></i> WhatsApp ile Bilgi Al</a>
    </div>
  </div>
</section>
