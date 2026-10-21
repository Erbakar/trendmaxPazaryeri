<?php
/** Faz 8/11 — Merchant Eklenti Satın Alma: Modül + Pazaryeri (main layout). subscription_invoices = SaaS (GİB DEĞİL). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$buyable = $buyable ?? [];
$buyableMarkets = $buyableMarkets ?? [];
$addons = $addons ?? [];
$moduleLabels = $moduleLabels ?? [];
$mpLabels = $mpLabels ?? [];
$openInvoice = $openInvoice ?? false;
$fmtP = fn($v) => '₺' . number_format((float) $v, ((float) $v == (int) (float) $v) ? 0 : 2, ',', '.');
$blocked = ($openInvoice !== false && !empty($openInvoice));
$isMp = fn($a) => (($a['addon_type'] ?? 'module') === 'marketplace') || !empty($a['marketplace_id']);
$addonLabel = function ($a) use ($moduleLabels, $mpLabels, $isMp) {
    if ($isMp($a)) {
        return $mpLabels[(int) ($a['marketplace_id'] ?? 0)] ?? ('Pazaryeri #' . (int) ($a['marketplace_id'] ?? 0));
    }
    return $moduleLabels[$a['module_key'] ?? ''] ?? ($a['module_key'] ?? '—');
};
$activeAddons = array_values(array_filter($addons, fn($a) => ($a['status'] ?? '') === 'active'));
?>
<div class="ui-page-head">
  <div>
    <h1>Eklentiler</h1>
    <p>Paketinize ek modül veya pazaryeri ekleyin — aylık ücretlendirilir, yenileme faturanıza eklenir.</p>
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
        Mevcut ödemeniz tamamlanana kadar yeni eklenti satın alamazsınız.
      </p>
    </div>
  </div>
<?php endif; ?>

<!-- ═══ Modüller ═══ -->
<div class="flex items-center gap-2.5 mb-3">
  <span class="grid place-items-center w-7 h-7 rounded-lg bg-brand/10 text-brand"><i data-lucide="puzzle" class="w-4 h-4"></i></span>
  <h2 class="text-[16px] font-bold tracking-tight">Modüller</h2>
</div>
<?php if (empty($buyable)): ?>
  <div class="rounded-2xl bg-surface shadow-card p-6 text-center mb-8">
    <p class="text-[13.5px] font-semibold text-slate-600 dark:text-slate-200">Tüm modüllere sahipsiniz</p>
    <p class="mt-1 text-[12.5px] text-slate-400">Satın alabileceğiniz başka modül bulunmuyor.</p>
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
    <?php foreach ($buyable as $m): $price = (float) ($m['price_monthly'] ?? 0); ?>
    <section class="rounded-2xl bg-surface shadow-card p-6 flex flex-col">
      <div class="flex items-center gap-2.5">
        <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand/10 text-brand shrink-0"><i data-lucide="puzzle" class="w-[18px] h-[18px]"></i></span>
        <h3 class="text-[15px] font-bold tracking-tight"><?= $e($m['label'] ?? $m['module_key'] ?? '—') ?></h3>
      </div>
      <?php if (!empty($m['description'])): ?>
        <p class="mt-3 text-[13px] text-slate-500 flex-1"><?= $e($m['description']) ?></p>
      <?php else: ?>
        <div class="flex-1"></div>
      <?php endif; ?>
      <div class="mt-4 flex items-end gap-1.5">
        <span class="text-[22px] font-bold tracking-tight tnum"><?= $e($fmtP($price)) ?></span>
        <span class="text-[12.5px] text-slate-400 mb-1">/ ay</span>
      </div>
      <form method="post" action="<?= url('/subscription/buy-module') ?>" class="mt-5">
        <input type="hidden" name="_csrf_token" value="<?= $e($data['_csrf'] ?? ($_csrf ?? '')) ?>">
        <input type="hidden" name="module_key" value="<?= $e($m['module_key'] ?? '') ?>">
        <button type="submit" <?= $blocked ? 'disabled' : '' ?> class="t w-full h-10 rounded-xl text-[13.5px] font-semibold <?= $blocked ? 'bg-slate-200 dark:bg-white/10 text-slate-400 cursor-not-allowed' : 'bg-brand text-white hover:brightness-110 shadow-lift shadow-brand/25' ?>">
          Satın Al
        </button>
      </form>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ═══ Pazaryerleri ═══ -->
<div class="flex items-center gap-2.5 mb-3">
  <span class="grid place-items-center w-7 h-7 rounded-lg bg-brand/10 text-brand"><i data-lucide="store" class="w-4 h-4"></i></span>
  <h2 class="text-[16px] font-bold tracking-tight">Pazaryerleri</h2>
</div>
<?php if (empty($buyableMarkets)): ?>
  <div class="rounded-2xl bg-surface shadow-card p-6 text-center mb-8">
    <p class="text-[13.5px] font-semibold text-slate-600 dark:text-slate-200">Eklenebilecek pazaryeri yok</p>
    <p class="mt-1 text-[12.5px] text-slate-400">Tüm satılabilir pazaryerleri zaten paketinizde veya eklentinizde. Yeni pazaryeri açılınca burada görünür.</p>
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
    <?php foreach ($buyableMarkets as $m): $price = (float) ($m['price_monthly'] ?? 0); ?>
    <section class="rounded-2xl bg-surface shadow-card p-6 flex flex-col">
      <div class="flex items-center gap-2.5">
        <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand/10 text-brand shrink-0"><i data-lucide="store" class="w-[18px] h-[18px]"></i></span>
        <h3 class="text-[15px] font-bold tracking-tight"><?= $e($m['display_name'] ?? $m['slug'] ?? '—') ?></h3>
      </div>
      <p class="mt-3 text-[13px] text-slate-500 flex-1">Bu pazaryeri için sipariş, ürün ve stok entegrasyonunu açar.</p>
      <div class="mt-4 flex items-end gap-1.5">
        <span class="text-[22px] font-bold tracking-tight tnum"><?= $e($fmtP($price)) ?></span>
        <span class="text-[12.5px] text-slate-400 mb-1">/ ay</span>
      </div>
      <form method="post" action="<?= url('/subscription/buy-marketplace') ?>" class="mt-5">
        <input type="hidden" name="_csrf_token" value="<?= $e($data['_csrf'] ?? ($_csrf ?? '')) ?>">
        <input type="hidden" name="marketplace_id" value="<?= (int) ($m['id'] ?? 0) ?>">
        <button type="submit" <?= $blocked ? 'disabled' : '' ?> class="t w-full h-10 rounded-xl text-[13.5px] font-semibold <?= $blocked ? 'bg-slate-200 dark:bg-white/10 text-slate-400 cursor-not-allowed' : 'bg-brand text-white hover:brightness-110 shadow-lift shadow-brand/25' ?>">
          Satın Al
        </button>
      </form>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ═══ Aktif Eklentilerim ═══ -->
<section class="rounded-2xl bg-surface shadow-card overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-100 dark:border-white/5 flex items-center gap-2.5"><i data-lucide="layers" class="w-[18px] h-[18px] text-slate-400"></i><h2 class="text-[14px] font-bold tracking-tight">Aktif Eklentilerim</h2></div>
  <?php if (empty($activeAddons)): ?>
    <div class="px-5 py-8 text-center text-[13px] text-slate-400">Henüz aktif eklentiniz yok.</div>
  <?php else: ?>
    <table class="w-full text-[13px]">
      <thead><tr class="text-left text-slate-400 bg-slate-900/[.02] dark:bg-white/[.03]"><th class="eyebrow font-medium px-5 py-2.5">Eklenti</th><th class="eyebrow font-medium px-3 py-2.5">Tür</th><th class="eyebrow font-medium px-3 py-2.5">Durum</th><th class="eyebrow font-medium px-5 py-2.5 text-right">Aylık</th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-white/5">
        <?php foreach ($activeAddons as $a): ?>
        <tr class="t hover:bg-slate-900/[.02] dark:hover:bg-white/[.03]">
          <td class="px-5 py-3 font-semibold"><?= $e($addonLabel($a)) ?></td>
          <td class="px-3 py-3"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $isMp($a) ? 'bg-brand/10 text-brand' : 'bg-slate-900/[.05] dark:bg-white/10 text-slate-500' ?>"><i data-lucide="<?= $isMp($a) ? 'store' : 'puzzle' ?>" class="w-3 h-3"></i><?= $isMp($a) ? 'Pazaryeri' : 'Modül' ?></span></td>
          <td class="px-3 py-3"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-success/10 text-success"><span class="w-1.5 h-1.5 rounded-full bg-success"></span>Aktif</span></td>
          <td class="px-5 py-3 text-right tnum"><?= $e($fmtP($a['price_monthly'] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
