<?php
/** Faz 8 — Bildirim Merkezi (merchant main layout). Kalıcı sistem bildirimleri. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$notifications = $notifications ?? [];
$unread = (int) ($unread ?? 0);
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$meta = [
  'subscription' => ['credit-card', 'bg-brand/10 text-brand'],
  'payment'      => ['banknote', 'bg-success/10 text-success'],
  'support'      => ['life-buoy', 'bg-warning/10 text-warning'],
  'system'       => ['bell', 'bg-slate-500/10 text-slate-500'],
];
?>
<div class="ui-page-head">
  <div>
    <h1>Bildirimler<?php if ($unread > 0): ?> <span class="ml-1 align-middle inline-flex items-center px-2 py-0.5 rounded-full text-[12px] font-semibold bg-brand/10 text-brand"><?= $unread ?> yeni</span><?php endif; ?></h1>
    <p>Abonelik, ödeme ve destek bildirimleriniz.</p>
  </div>
  <?php if ($unread > 0): ?>
  <form method="post" action="<?= url('/notifications/read') ?>">
    <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
    <button type="submit" class="ui-btn ui-btn-outline"><i data-lucide="check-check"></i> Tümünü okundu işaretle</button>
  </form>
  <?php endif; ?>
</div>

<section class="rounded-2xl bg-surface shadow-card overflow-hidden">
  <?php if (empty($notifications)): ?>
    <div class="px-5 py-16 text-center">
      <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-4 text-slate-300 dark:text-slate-600"></i>
      <p class="text-[14px] text-slate-500">Henüz bildiriminiz yok.</p>
    </div>
  <?php else: ?>
  <ul class="divide-y divide-slate-100 dark:divide-white/5">
    <?php foreach ($notifications as $n): [$icon, $cls] = $meta[$n['type']] ?? $meta['system']; $unreadRow = (int) ($n['is_read'] ?? 0) === 0; ?>
    <li class="t flex items-start gap-3.5 px-5 py-4 <?= $unreadRow ? 'bg-brand/[.03]' : '' ?> hover:bg-slate-900/[.02] dark:hover:bg-white/[.03]">
      <span class="grid place-items-center w-10 h-10 rounded-xl shrink-0 <?= $cls ?>"><i data-lucide="<?= $icon ?>" class="w-5 h-5"></i></span>
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <span class="text-[14px] font-semibold leading-tight"><?= $e($n['title']) ?></span>
          <?php if ($unreadRow): ?><span class="w-2 h-2 rounded-full bg-brand shrink-0"></span><?php endif; ?>
        </div>
        <?php if (!empty($n['body'])): ?><p class="mt-1 text-[13px] text-slate-500 leading-relaxed"><?= $e($n['body']) ?></p><?php endif; ?>
        <div class="mt-1.5 flex items-center gap-3 text-[12px] text-slate-400">
          <span class="tnum"><?= $e($fmtDT($n['created_at'] ?? null)) ?></span>
          <?php if (!empty($n['link'])): ?><a href="<?= $e($n['link']) ?>" class="font-medium text-brand hover:underline">Görüntüle →</a><?php endif; ?>
        </div>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
