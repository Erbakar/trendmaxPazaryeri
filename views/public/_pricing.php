<?php
/** Partial — paket fiyat kartları (ozyconnect price-card tasarımı, dinamik $packages). */
$e = $e ?? fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$fmtPrice = function ($v) {
    $v = (float) $v;
    return $v <= 0 ? 'Ücretsiz' : number_format($v, ($v == (int) $v) ? 0 : 2, ',', '.');
};
$lim = fn($v, $unit) => $v === null ? 'Sınırsız ' . $unit : number_format((int) $v, 0, ',', '.') . ' ' . $unit;
$icons = ['rocket', 'crown', 'building-2'];
$iconBg = ['var(--blue-light)', 'var(--purple-light)', 'var(--orange-light)'];
$iconColor = ['var(--blue)', 'var(--purple)', 'var(--orange)'];
?>
<div class="price-grid">
<?php if (empty($packages)): ?>
  <!-- Referans planlar (panelde paket tanımlanınca buradan beslenir) -->
  <div class="price-card reveal">
    <div class="price-icon-wrap" style="background:var(--blue-light)"><i data-lucide="rocket" style="color:var(--blue)"></i></div>
    <div class="price-name">Başlangıç</div>
    <div class="price-desc">Küçük işletmeler için ideal</div>
    <div class="price-amount"><span class="price-cur">₺</span><span class="price-val">19.900</span></div>
    <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Yıllık · 1 yıl destek</div>
    <div class="price-divider"></div>
    <ul class="price-features">
      <li><i data-lucide="check"></i><strong>3 Pazaryeri</strong></li>
      <li><i data-lucide="check"></i>Sınırsız Sipariş</li>
      <li><i data-lucide="check"></i>Stok & Fiyat Sync</li>
      <li><i data-lucide="check"></i>E-Arşiv Fatura</li>
      <li><i data-lucide="check"></i>Kargo Etiketi</li>
      <li><i data-lucide="check"></i>1 Kullanıcı</li>
    </ul>
    <a href="<?= url('/magaza-olustur') ?>" class="price-btn price-btn-outline"><i data-lucide="rocket"></i> Mağaza Oluştur</a>
  </div>
  <div class="price-card featured reveal d1">
    <div class="price-popular">EN POPÜLER</div>
    <div class="price-icon-wrap" style="background:var(--purple-light)"><i data-lucide="crown" style="color:var(--purple)"></i></div>
    <div class="price-name">Profesyonel</div>
    <div class="price-desc">Büyüyen mağazalar için</div>
    <div class="price-amount"><span class="price-cur">₺</span><span class="price-val">39.900</span></div>
    <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Yıllık · 1 yıl destek</div>
    <div class="price-divider"></div>
    <ul class="price-features">
      <li><i data-lucide="check"></i><strong>11 Pazaryeri (Tümü)</strong></li>
      <li><i data-lucide="check"></i>Sınırsız Sipariş</li>
      <li><i data-lucide="check"></i>E-Arşiv Fatura</li>
      <li><i data-lucide="check"></i>Kargo + Toplu Yazdırma</li>
      <li><i data-lucide="check"></i>İade & Soru Yönetimi</li>
      <li><i data-lucide="check"></i>5 Kullanıcı + Roller</li>
    </ul>
    <a href="<?= url('/magaza-olustur') ?>" class="price-btn price-btn-primary"><i data-lucide="rocket"></i> Mağaza Oluştur</a>
  </div>
  <div class="price-card reveal d2">
    <div class="price-icon-wrap" style="background:var(--orange-light)"><i data-lucide="building-2" style="color:var(--orange)"></i></div>
    <div class="price-name">Kurumsal</div>
    <div class="price-desc">Özel çözümler & yüksek hacim</div>
    <div class="price-amount" style="margin-top:18px"><span class="price-val" style="font-size:30px">Fiyat Alınız</span></div>
    <div style="font-size:11px;color:var(--gray-400);margin-top:4px">Projenize özel fiyatlandırma</div>
    <div class="price-divider"></div>
    <ul class="price-features">
      <li><i data-lucide="check"></i><strong>11 Pazaryeri (Tümü)</strong></li>
      <li><i data-lucide="check"></i>Sınırsız Kullanıcı</li>
      <li><i data-lucide="check"></i>Tüm Modüller</li>
      <li><i data-lucide="check"></i>Özel API Erişimi</li>
      <li><i data-lucide="check"></i>7/24 Öncelikli Destek</li>
    </ul>
    <?php $wa = preg_replace('/\D/', '', (string) brand('contact_phone', '')); if ($wa === '') $wa = ''; ?>
    <a href="https://wa.me/<?= $e($wa) ?>" target="_blank" rel="noopener" class="price-btn price-btn-outline"><i data-lucide="message-circle"></i> Bilgi Al</a>
  </div>
<?php else:
  $n = count($packages);
  $featured = $n >= 3 ? 1 : ($n === 2 ? 1 : 0);
  foreach ($packages as $i => $p):
    $hot = $i === $featured;
    $k = $i % 3;
    $monthly = (float) ($p['price_monthly'] ?? 0);
    $yearly = (float) ($p['price_yearly'] ?? 0);
?>
  <div class="price-card<?= $hot ? ' featured' : '' ?> reveal<?= $i > 0 ? ' d' . $i : '' ?>">
    <?php if ($hot): ?><div class="price-popular">EN POPÜLER</div><?php endif; ?>
    <div class="price-icon-wrap" style="background:<?= $iconBg[$k] ?>"><i data-lucide="<?= $icons[$k] ?>" style="color:<?= $iconColor[$k] ?>"></i></div>
    <div class="price-name"><?= $e($p['name']) ?></div>
    <?php if (!empty($p['description'])): ?><div class="price-desc"><?= $e($p['description']) ?></div><?php endif; ?>
    <div class="price-amount">
      <?php if ($monthly > 0): ?><span class="price-cur">₺</span><span class="price-val"><?= $e($fmtPrice($monthly)) ?></span><span class="price-period">/ay</span>
      <?php else: ?><span class="price-val" style="font-size:34px">Ücretsiz</span><?php endif; ?>
    </div>
    <?php if ($yearly > 0): ?><div style="font-size:11px;color:var(--gray-400);margin-top:4px">Yıllık: ₺<?= $e($fmtPrice($yearly)) ?></div><?php endif; ?>
    <div class="price-divider"></div>
    <ul class="price-features">
      <li><i data-lucide="check"></i><?= $e($lim($p['max_products'] ?? null, 'ürün')) ?></li>
      <li><i data-lucide="check"></i><?= $e($lim($p['max_orders_month'] ?? null, 'sipariş/ay')) ?></li>
      <li><i data-lucide="check"></i><?= $e($lim($p['max_users'] ?? null, 'kullanıcı')) ?></li>
      <?php if ((int) ($p['trial_days'] ?? 0) > 0): ?><li><i data-lucide="check"></i><strong><?= (int) $p['trial_days'] ?> gün ücretsiz deneme</strong></li><?php endif; ?>
      <li><i data-lucide="check"></i>Otomatik Güncelleme</li>
    </ul>
    <a href="<?= url('/magaza-olustur?paket=' . urlencode((string) $p['slug'])) ?>" class="price-btn <?= $hot ? 'price-btn-primary' : 'price-btn-outline' ?>"><i data-lucide="rocket"></i> Hemen Başla</a>
  </div>
<?php endforeach; endif; ?>
</div>
