<?php
/** Faz 8 — Merchant Abonelik Yükseltme (main layout). subscription_invoices = SaaS (GİB DEĞİL). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$sub = $subscription ?? false;
$packages = $packages ?? [];
$openInvoice = $openInvoice ?? false;
$fmtP = fn($v) => '₺' . number_format((float) $v, ((float) $v == (int) (float) $v) ? 0 : 2, ',', '.');
$blocked = ($openInvoice !== false && !empty($openInvoice));
?>
<div class="ui-page-head">
  <div>
    <h1>Paket Yükselt</h1>
    <p>Daha fazla pazaryeri ve modül için paketinizi yükseltin.</p>
  </div>
  <a href="<?= url('/subscription') ?>" class="ui-btn ui-btn-outline">
    <i data-lucide="arrow-left"></i> Aboneliğe Dön
  </a>
</div>

<?php if ($blocked): ?>
  <div class="rounded-2xl bg-warning/10 border border-warning/30 p-4 mb-5 flex items-start gap-3">
    <span class="grid place-items-center w-9 h-9 rounded-xl bg-warning/20 text-warning shrink-0"><i data-lucide="alert-triangle" class="w-[18px] h-[18px]"></i></span>
    <div>
      <p class="text-[14px] font-semibold text-slate-700 dark:text-slate-100">Açık ödemeniz var, önce onu tamamlayın</p>
      <p class="mt-0.5 text-[13px] text-slate-500">
        Açık fatura: <span class="font-mono"><?= $e($openInvoice['number'] ?? '—') ?></span> · <span class="font-semibold"><?= $e($fmtP($openInvoice['amount'] ?? 0)) ?></span>.
        Mevcut ödemeniz tamamlanana kadar paket yükseltemezsiniz.
      </p>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($packages)): ?>
  <div class="rounded-2xl bg-surface shadow-card p-8 text-center">
    <span class="grid place-items-center w-12 h-12 rounded-xl bg-success/10 text-success mx-auto"><i data-lucide="badge-check" class="w-6 h-6"></i></span>
    <p class="mt-4 text-[15px] font-semibold text-slate-700 dark:text-slate-100">Zaten en üst paketteniz</p>
    <p class="mt-1 text-[13px] text-slate-500">Şu an yükseltilebileceğiniz daha üst bir paket bulunmuyor.</p>
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <?php foreach ($packages as $idx => $pkg):
      $featured = (count($packages) === 3 && $idx === 1);
      $price = (float) ($pkg['price_monthly'] ?? 0);
      $yearly = (float) ($pkg['price_yearly'] ?? 0);
    ?>
    <section class="relative rounded-2xl bg-surface shadow-card p-6 flex flex-col <?= $featured ? 'ring-2 ring-brand' : '' ?>">
      <?php if ($featured): ?>
        <span class="absolute -top-2.5 left-1/2 -translate-x-1/2 inline-flex items-center px-2.5 py-0.5 rounded-full text-[10.5px] font-bold uppercase tracking-wide bg-brand text-white shadow-lift shadow-brand/25">Önerilen</span>
      <?php endif; ?>
      <div class="eyebrow text-slate-400"><?= $e($pkg['name'] ?? '—') ?></div>
      <?php if (!empty($pkg['description'])): ?>
        <p class="mt-1.5 text-[13px] text-slate-500 min-h-[2.5rem]"><?= $e($pkg['description']) ?></p>
      <?php endif; ?>
      <div class="mt-3 flex items-end gap-1.5">
        <span class="text-[30px] font-bold tracking-tight tnum"><?= $price > 0 ? $e($fmtP($price)) : 'Ücretsiz' ?></span>
        <?php if ($price > 0): ?><span class="text-[13px] text-slate-400 mb-1.5">/ ay</span><?php endif; ?>
      </div>
      <?php if ($yearly > 0): ?>
        <div class="mt-1 text-[12px] text-slate-400">Yıllık <span class="font-semibold text-slate-600 dark:text-slate-300 tnum"><?= $e($fmtP($yearly)) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($pkg['trial_days'])): ?>
        <div class="mt-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-success/10 text-success w-max"><i data-lucide="gift" class="w-3 h-3"></i><?= (int) $pkg['trial_days'] ?> gün deneme</div>
      <?php endif; ?>

      <?php
        $limits = [
          'max_products' => ['package', 'Ürün'],
          'max_orders'   => ['shopping-cart', 'Sipariş'],
          'max_users'    => ['users', 'Kullanıcı'],
        ];
        $hasLimits = false;
        foreach ($limits as $lk => $_) { if (array_key_exists($lk, $pkg)) { $hasLimits = true; break; } }
      ?>
      <?php if ($hasLimits): ?>
        <ul class="mt-4 space-y-2 text-[13px] text-slate-600 dark:text-slate-300">
          <?php foreach ($limits as $lk => [$ic, $lbl]): if (!array_key_exists($lk, $pkg)) continue;
            $v = $pkg[$lk];
            $disp = ($v === null || (int) $v === 0) ? 'Sınırsız' : number_format((int) $v, 0, ',', '.');
          ?>
          <li class="flex items-center gap-2"><i data-lucide="<?= $ic ?>" class="w-[15px] h-[15px] text-brand shrink-0"></i><span><?= $e($lbl) ?>: <span class="font-semibold"><?= $e($disp) ?></span></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" action="<?= url('/subscription/upgrade') ?>" class="mt-6 pt-2 mt-auto">
        <input type="hidden" name="_csrf_token" value="<?= $e($data['_csrf'] ?? ($_csrf ?? '')) ?>">
        <input type="hidden" name="package_id" value="<?= $e($pkg['id'] ?? '') ?>">
        <button type="submit" <?= $blocked ? 'disabled' : '' ?> class="t w-full h-11 rounded-xl text-[14px] font-semibold <?= $blocked ? 'bg-slate-200 dark:bg-white/10 text-slate-400 cursor-not-allowed' : ($featured ? 'bg-brand text-white hover:brightness-110 shadow-lift shadow-brand/25' : 'bg-brand/10 text-brand hover:bg-brand/15') ?>">
          Bu Pakete Geç
        </button>
      </form>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
