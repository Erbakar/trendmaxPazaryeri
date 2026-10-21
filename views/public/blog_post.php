<?php
/** Blog detay (site teması). Girdi: $post. body admin-yazımı HTML (kaydederken sanitize edilir). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$post = $post ?? [];
$fmtDate = fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '';
?>
<article class="section" style="padding-top:128px">
<div class="container" style="max-width:780px">
  <a href="<?= url('/blog') ?>" class="auth2-back"><i data-lucide="arrow-left"></i> Tüm yazılar</a>
  <div style="font-size:13px;color:var(--gray-400);margin-top:18px"><?= $e($fmtDate($post['published_at'] ?? '')) ?><?= !empty($post['author']) ? ' · ' . $e($post['author']) : '' ?></div>
  <h1 class="heading-lg" style="margin-top:8px"><?= $e($post['title'] ?? '') ?></h1>
  <?php if (!empty($post['cover_url'])): ?>
    <img src="<?= $e($post['cover_url']) ?>" alt="<?= $e($post['title'] ?? '') ?>" style="width:100%;border-radius:var(--radius-lg);margin-top:24px;box-shadow:var(--shadow)">
  <?php endif; ?>
  <div style="margin-top:28px;color:var(--gray-700);font-size:16px;line-height:1.85">
    <?= $post['body'] ?? '' /* admin-yazımı, kaydederken sanitize edildi */ ?>
  </div>
  <div style="margin-top:40px;padding-top:28px;border-top:1px solid var(--gray-100);text-align:center">
    <a href="<?= url('/magaza-olustur') ?>" class="btn btn-primary btn-lg"><i data-lucide="rocket"></i> Mağaza Oluştur</a>
  </div>
</div>
</article>
