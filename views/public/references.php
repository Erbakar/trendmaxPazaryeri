<?php
/** Referanslar sayfası (site teması). Girdi: $testimonials. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$testimonials = $testimonials ?? [];
?>
<section class="section" style="padding-top:128px">
<div class="container">
  <div class="text-center">
    <div class="eyebrow eyebrow-green mx-auto"><i data-lucide="quote"></i> Referanslar</div>
    <h1 class="heading-lg">Bizi Tercih Edenler</h1>
    <p class="subtext mx-auto text-center" style="margin-top:12px">Yüzlerce işletme operasyonunu <?= $e(brand('site_name')) ?> ile tek panelden yönetiyor.</p>
  </div>
  <?php if (empty($testimonials)): ?>
    <div style="max-width:520px;margin:48px auto 0;text-align:center;color:var(--gray-400);background:#fff;border:1px solid var(--gray-100);border-radius:var(--radius-lg);padding:40px;box-shadow:var(--shadow-sm)">
      <i data-lucide="message-square-quote" style="width:32px;height:32px;color:var(--gray-300);margin:0 auto 12px"></i>
      <p>Referanslar yakında burada yer alacak.</p>
    </div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:48px" class="ref-grid">
      <?php foreach ($testimonials as $t): $who = trim((string) ($t['person'] ?? '')) ?: trim((string) ($t['company'] ?? '')); ?>
      <div class="reveal" style="background:#fff;border:1px solid var(--gray-100);border-radius:var(--radius-lg);padding:26px;box-shadow:var(--shadow-sm)">
        <i data-lucide="quote" style="width:26px;height:26px;color:var(--blue);opacity:.35"></i>
        <p style="font-size:15px;color:var(--gray-700);line-height:1.7;margin-top:12px">"<?= $e($t['quote'] ?? '') ?>"</p>
        <div style="display:flex;align-items:center;gap:12px;margin-top:20px">
          <span style="width:42px;height:42px;border-radius:50%;background:var(--blue-light);color:var(--blue);display:flex;align-items:center;justify-content:center;font-weight:800"><?= $e(mb_strtoupper(mb_substr($who ?: 'O', 0, 1))) ?></span>
          <div>
            <div style="font-size:14px;font-weight:700;color:var(--gray-900)"><?= $e($who) ?></div>
            <div style="font-size:12.5px;color:var(--gray-400)"><?= $e(trim(((string) ($t['role'] ?? '')) . ((!empty($t['role']) && !empty($t['company'])) ? ' · ' : '') . ((string) ($t['company'] ?? '')))) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="text-center" style="margin-top:48px">
    <a href="<?= url('/magaza-olustur') ?>" class="btn btn-primary btn-lg"><i data-lucide="rocket"></i> Siz de Başlayın</a>
  </div>
</div>
</section>
<style>@media(max-width:900px){.ref-grid{grid-template-columns:1fr!important}}</style>
