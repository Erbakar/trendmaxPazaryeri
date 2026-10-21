<?php
/** Ayarlar sol menüsü — bölümler gruplara ayrılır, aktif olan vurgulanır. */
$sections      = $data['sections'] ?? [];
$activeSection = $data['activeSection'] ?? 'magaza';
$groups = [];
foreach ($sections as $slug => $s) {
    $groups[$s['group']][$slug] = $s;
}
?>
<nav class="rounded-2xl bg-surface shadow-card p-2 lg:sticky lg:top-[84px]">
  <?php foreach ($groups as $gName => $items): ?>
  <div class="px-2.5 pt-3 pb-1 eyebrow text-slate-400"><?= htmlspecialchars((string) $gName) ?></div>
  <?php foreach ($items as $slug => $s): $isAct = ($slug === $activeSection); ?>
  <a href="/settings/<?= htmlspecialchars($slug) ?>" class="t flex items-center gap-2.5 px-2.5 h-10 rounded-lg text-[13.5px] font-medium <?= $isAct ? 'bg-brand/10 text-brand' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]' ?>">
    <i data-lucide="<?= htmlspecialchars((string) $s['icon']) ?>" class="w-[18px] h-[18px] <?= $isAct ? 'text-brand' : 'text-slate-400' ?>"></i>
    <?= htmlspecialchars((string) $s['label']) ?>
  </a>
  <?php endforeach; ?>
  <?php endforeach; ?>

  <!-- Hesap: tam-sayfa hesap ekranlarına linkler (settings kabuğunun dışına çıkar) -->
  <div class="px-2.5 pt-3 pb-1 eyebrow text-slate-400">Hesap</div>
  <a href="<?= function_exists('url') ? url('/subscription') : '/subscription' ?>" class="t flex items-center gap-2.5 px-2.5 h-10 rounded-lg text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]">
    <i data-lucide="credit-card" class="w-[18px] h-[18px] text-slate-400"></i>
    Abonelik &amp; Fatura
  </a>
  <a href="<?= function_exists('url') ? url('/documents') : '/documents' ?>" class="t flex items-center gap-2.5 px-2.5 h-10 rounded-lg text-[13.5px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-900/[.04] dark:hover:bg-white/[.06]">
    <i data-lucide="folder-check" class="w-[18px] h-[18px] text-slate-400"></i>
    Belgeler
  </a>
</nav>
