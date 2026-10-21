<?php
/** Blog liste (site teması). Girdi: $posts. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$posts = $posts ?? [];
$fmtDate = fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '';
?>
<section class="section" style="padding-top:128px">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-blue mx-auto"><i data-lucide="newspaper"></i> Blog</div>
    <h1 class="heading-lg">Güncel Yazılar</h1>
    <p class="subtext mx-auto text-center" style="margin-top:12px">E-ticaret, pazaryeri entegrasyonu ve operasyon ipuçları.</p>
  </div>
  <?php if (empty($posts)): ?>
    <div style="max-width:520px;margin:48px auto 0;text-align:center;color:var(--gray-400);background:#fff;border:1px solid var(--gray-100);border-radius:var(--radius-lg);padding:40px;box-shadow:var(--shadow-sm)">
      <i data-lucide="newspaper" style="width:32px;height:32px;color:var(--gray-300);margin:0 auto 12px"></i>
      <p>Yakında ilk yazılarımızı paylaşacağız.</p>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:48px" class="blog-grid">
      <?php foreach ($posts as $p): ?>
      <a href="<?= url('/blog/' . rawurlencode((string) $p['slug'])) ?>" class="reveal" style="display:block;background:#fff;border:1px solid var(--gray-100);border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-sm);transition:box-shadow .25s,transform .25s">
        <?php if (!empty($p['cover_url'])): ?>
          <img src="<?= $e($p['cover_url']) ?>" alt="<?= $e($p['title']) ?>" style="width:100%;height:180px;object-fit:cover;display:block">
        <?php else: ?>
          <div style="height:180px;background:linear-gradient(135deg,var(--blue-light),var(--blue-mid));display:flex;align-items:center;justify-content:center"><i data-lucide="file-text" style="width:32px;height:32px;color:var(--blue)"></i></div>
        <?php endif; ?>
        <div style="padding:20px">
          <div style="font-size:12px;color:var(--gray-400)"><?= $e($fmtDate($p['published_at'] ?? '')) ?></div>
          <h3 style="font-size:17px;font-weight:700;color:var(--gray-900);margin-top:6px;line-height:1.35"><?= $e($p['title']) ?></h3>
          <?php if (!empty($p['excerpt'])): ?><p style="font-size:13.5px;color:var(--gray-500);line-height:1.6;margin-top:8px"><?= $e($p['excerpt']) ?></p><?php endif; ?>
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:var(--blue);margin-top:14px">Devamını oku <i data-lucide="arrow-right" style="width:14px;height:14px"></i></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</section>
<style>@media(max-width:900px){.blog-grid{grid-template-columns:1fr!important}}</style>
