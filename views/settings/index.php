<?php
/**
 * Ayarlar kabuğu (settings.html mockup) — sol bölüm menüsü + sağ içerik.
 * İçerik ya bir store_settings bölüm partial'ı (views/settings/sections/{slug}.php)
 * ya da gömülen mevcut bir özellik görünümü (sectionView) olarak render edilir.
 */
$sections      = $data['sections'] ?? [];
$activeSection = $data['activeSection'] ?? 'magaza';
$sectionView   = $data['sectionView'] ?? null;
$active        = $sections[$activeSection] ?? ['label' => 'Ayarlar', 'icon' => 'settings'];
?>
<!-- Sayfa başlığı + breadcrumb -->
<div class="ui-page-head">
  <div>
    <div class="ui-eyebrow">Ayarlar / <?= htmlspecialchars((string) $active['label']) ?></div>
    <h1>Ayarlar</h1>
    <p>Mağaza, görünüm, güvenlik ve entegrasyon ayarlarınız.</p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
  <!-- SOL: bölüm menüsü -->
  <aside class="lg:col-span-3">
    <?php include BASE_PATH . '/views/settings/_sidebar.php'; ?>
  </aside>

  <!-- SAĞ: aktif bölüm içeriği -->
  <div class="lg:col-span-9 min-w-0" id="settingsContent">
    <?php
    if ($sectionView) {
        $vf = BASE_PATH . '/views/' . str_replace('.', '/', (string) $sectionView) . '.php';
        if (is_file($vf)) {
            include $vf;
        }
    } else {
        $sf = BASE_PATH . '/views/settings/sections/' . $activeSection . '.php';
        if (is_file($sf)) {
            include $sf;
        }
    }
    ?>
  </div>
</div>
