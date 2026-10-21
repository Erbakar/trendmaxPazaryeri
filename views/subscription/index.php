<?php
/** Faz 8 — Merchant Abonelik & Fatura (main layout). subscription_invoices = SaaS (GİB DEĞİL). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$sub = $subscription ?? false;
$usage = $usage ?? [];
$invoices = $invoices ?? [];
$payments = $payments ?? [];
$openInvoice = $openInvoice ?? false;
$mpNames = $mpNames ?? [];
$moduleKeys = $moduleKeys ?? [];
$moduleLabels = $moduleLabels ?? [];
$mpLabels = $mpLabels ?? [];
$addons = $addons ?? [];
$paytrEnabled = $paytrEnabled ?? false;
$upgradeCount = $upgradeCount ?? 0;
$buyableCount = $buyableCount ?? 0;
$fmtP = fn($v) => '₺' . number_format((float) $v, ((float) $v == (int) (float) $v) ? 0 : 2, ',', '.');
$kindLbl = fn($k) => ['subscription' => 'Abonelik', 'upgrade' => 'Yükseltme', 'addon' => 'Eklenti'][$k] ?? 'Abonelik';
$fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '—';
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$subMeta = [
  'trial'=>['Deneme','bg-warning/10 text-warning','bg-warning'], 'active'=>['Aktif','bg-success/10 text-success','bg-success'],
  'passive'=>['Pasif','bg-slate-500/10 text-slate-500','bg-slate-400'], 'suspended'=>['Askıda','bg-danger/10 text-danger','bg-danger'],
  'expired'=>['Süresi Doldu','bg-danger/10 text-danger','bg-danger'], 'cancelled'=>['İptal','bg-slate-500/10 text-slate-500','bg-slate-400'],
];
$invMeta = ['draft'=>['Taslak','bg-slate-500/10 text-slate-500'],'sent'=>['Bekliyor','bg-warning/10 text-warning'],'paid'=>['Ödendi','bg-success/10 text-success'],'overdue'=>['Gecikmiş','bg-danger/10 text-danger'],'void'=>['İptal','bg-slate-500/10 text-slate-500']];
$payMeta = ['pending'=>['Onay Bekliyor','bg-warning/10 text-warning'],'confirmed'=>['Onaylandı','bg-success/10 text-success'],'rejected'=>['Reddedildi','bg-danger/10 text-danger']];
?>
<div class="ui-page-head">
  <div>
  <h1>Abonelik &amp; Fatura</h1>
    <p>Paketiniz, ödemeleriniz ve faturalarınız.</p>
  </div>
</div>

<?php if ($sub === false): ?>
  <div class="rounded-2xl bg-surface shadow-card p-8 text-center">
    <span class="grid place-items-center w-12 h-12 rounded-xl bg-brand/10 text-brand mx-auto"><i data-lucide="credit-card" class="w-6 h-6"></i></span>
    <p class="mt-4 text-[14px] text-slate-500">Aktif aboneliğiniz bulunmuyor.</p>
  </div>
<?php else: [$sl,$sc,$sd] = $subMeta[$sub['status']] ?? ['—','bg-slate-500/10 text-slate-500','bg-slate-400']; ?>

<div class="flex flex-wrap gap-2 mb-5">
  <a href="<?= url('/subscription/upgrade') ?>" class="t inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-brand text-white text-[13px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25"><i data-lucide="arrow-up-circle" class="w-4 h-4"></i> Paketi Yükselt<?= $upgradeCount > 0 ? ' (' . (int) $upgradeCount . ')' : '' ?></a>
  <a href="<?= url('/subscription/modules') ?>" class="t inline-flex items-center gap-2 h-10 px-4 rounded-xl border border-slate-200 dark:border-white/10 text-[13px] font-semibold hover:shadow-card"><i data-lucide="layers" class="w-4 h-4 text-brand"></i> Eklenti Ekle<?= $buyableCount > 0 ? ' (' . (int) $buyableCount . ')' : '' ?></a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <!-- Aboneliğim -->
  <section class="lg:col-span-2 rounded-2xl bg-surface shadow-card p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="eyebrow text-slate-400">Paketim</div>
        <div class="mt-1.5 flex items-center gap-2.5">
          <h2 class="text-[20px] font-bold tracking-tight"><?= $e($sub['package_name'] ?? '—') ?></h2>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $sc ?>"><span class="w-1.5 h-1.5 rounded-full <?= $sd ?>"></span><?= $e($sl) ?></span>
        </div>
        <div class="mt-2 text-[13px] text-slate-400">
          <?= $sub['billing_cycle'] === 'yearly' ? 'Yıllık' : 'Aylık' ?> ·
          <?= (float) ($sub['price_monthly'] ?? 0) > 0 ? $e($fmtP($sub['billing_cycle'] === 'yearly' ? ($sub['price_yearly'] ?? 0) : ($sub['price_monthly'] ?? 0))) : 'Ücretsiz' ?>
        </div>
      </div>
      <div class="text-right text-[12.5px] text-slate-400">
        <?php if (!empty($sub['trial_ends_at']) && $sub['status'] === 'trial'): ?>
          <div>Deneme bitişi</div><div class="font-semibold text-slate-700 dark:text-slate-200 tnum"><?= $e($fmtD($sub['trial_ends_at'])) ?></div>
        <?php elseif (!empty($sub['current_period_end'])): ?>
          <div>Dönem bitişi</div><div class="font-semibold text-slate-700 dark:text-slate-200 tnum"><?= $e($fmtD($sub['current_period_end'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Kullanım -->
    <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
      <?php foreach ($usage as $u): $warn = !$u['unlimited'] && $u['pct'] >= 80; ?>
      <div>
        <div class="flex items-center justify-between text-[12px] mb-1.5"><span class="text-slate-400"><?= $e($u['label']) ?></span><span class="font-semibold tnum <?= $warn ? 'text-warning' : 'text-slate-600 dark:text-slate-300' ?>"><?= (int) $u['used'] ?><?= $u['unlimited'] ? '' : ' / ' . (int) $u['limit'] ?></span></div>
        <div class="h-2 rounded-full bg-slate-900/[.06] dark:bg-white/10 overflow-hidden">
          <?php if ($u['unlimited']): ?><div class="h-full w-full bg-success/40"></div><?php else: ?><div class="h-full rounded-full <?= $u['pct'] >= 100 ? 'bg-danger' : ($warn ? 'bg-warning' : 'bg-brand') ?>" style="width: <?= (int) $u['pct'] ?>%"></div><?php endif; ?>
        </div>
        <div class="mt-1 text-[10.5px] text-slate-400"><?= $u['unlimited'] ? 'Sınırsız' : '%' . (int) $u['pct'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Aktif Yetkilerim (gerçek — paket + admin-eklenen ekstralar; durum/süre ile) -->
  <?php $mpRows = $mpRows ?? []; $modRows = $modRows ?? [];
    $entLbl = static fn(string $st): string => $st === 'active' ? '' : ' (' . ($st === 'expired' ? 'süresi doldu' : 'pasif') . ')'; ?>
  <section class="rounded-2xl bg-surface shadow-card p-6">
    <div class="eyebrow text-slate-400 mb-3">Aktif Yetkilerim</div>
    <div class="text-[12px] font-semibold text-slate-500 mb-1.5">Pazaryerleri</div>
    <div class="flex flex-wrap gap-1.5 mb-4">
      <?php if ($mpRows): foreach ($mpRows as $r): $st = (string) ($r['status'] ?? 'active');
        $cls = $st === 'active' ? 'bg-brand/10 text-brand' : ($st === 'expired' ? 'bg-rose-500/10 text-rose-600' : 'bg-slate-900/[.05] text-slate-500');
        $exp = !empty($r['expires_at']) ? 'bitiş: ' . date('d.m.Y', (int) strtotime((string) $r['expires_at'])) : '';
      ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $cls ?>" title="<?= $e($exp) ?>"><?= $e($r['display_name'] ?? $r['slug'] ?? '—') ?><?= $entLbl($st) ?></span><?php endforeach; else: ?><span class="text-[12px] text-slate-400">—</span><?php endif; ?>
    </div>
    <div class="text-[12px] font-semibold text-slate-500 mb-1.5">Modüller</div>
    <div class="flex flex-wrap gap-1.5">
      <?php if ($modRows): foreach ($modRows as $r): $st = (string) ($r['status'] ?? 'active');
        $cls = $st === 'active' ? 'bg-slate-900/[.05] dark:bg-white/10 text-slate-600 dark:text-slate-300' : ($st === 'expired' ? 'bg-rose-500/10 text-rose-600' : 'bg-slate-900/[.04] text-slate-400');
        $exp = !empty($r['expires_at']) ? 'bitiş: ' . date('d.m.Y', (int) strtotime((string) $r['expires_at'])) : '';
      ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $cls ?>" title="<?= $e($exp) ?>"><?= $e($moduleLabels[$r['module_key']] ?? $r['module_key']) ?><?= $entLbl($st) ?></span><?php endforeach; else: ?><span class="text-[12px] text-slate-400">—</span><?php endif; ?>
    </div>
    <?php $activeAddons = array_filter($addons, static fn($a) => ($a['status'] ?? '') === 'active'); if ($activeAddons): ?>
    <div class="text-[12px] font-semibold text-slate-500 mt-4 mb-1.5">Eklentilerim (Add-on)</div>
    <div class="flex flex-wrap gap-1.5">
      <?php foreach ($activeAddons as $a):
        $aIsMp = (($a['addon_type'] ?? 'module') === 'marketplace') || !empty($a['marketplace_id']);
        $aLbl  = $aIsMp ? ($mpLabels[(int) ($a['marketplace_id'] ?? 0)] ?? 'Pazaryeri') : ($moduleLabels[$a['module_key'] ?? ''] ?? ($a['module_key'] ?? '—'));
      ?><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-600"><i data-lucide="<?= $aIsMp ? 'store' : 'plus-circle' ?>" class="w-3 h-3"></i><?= $e($aLbl) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<!-- Ödeme Bildir -->
<section class="rounded-2xl bg-surface shadow-card p-6 mt-5">
  <div class="flex items-center gap-2.5 mb-4"><span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="banknote" class="w-[18px] h-[18px]"></i></span><h2 class="text-[15px] font-bold tracking-tight">Ödeme Bildir (Havale / EFT)</h2></div>
  <?php if ($openInvoice !== false): ?>
    <p class="text-[13px] text-slate-500 mb-4">Açık fatura: <span class="font-mono"><?= $e($openInvoice['number']) ?></span> · <span class="font-semibold"><?= $e($fmtP($openInvoice['amount'])) ?></span></p>
  <?php else: ?>
    <p class="text-[13px] text-slate-500 mb-4">Aboneliğinizi etkinleştirmek için ödeme bildiriminizi ve dekontunuzu iletin.</p>
  <?php endif; ?>
  <form method="post" action="<?= url('/subscription/pay') ?>" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
    <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
    <div>
      <label class="block text-[12px] font-semibold text-slate-500 mb-1.5">Yöntem</label>
      <select name="method" class="w-full h-10 px-3 rounded-xl border border-slate-200 dark:border-white/10 bg-surface text-[13.5px] outline-none focus:border-brand"><option value="havale">Havale</option><option value="eft">EFT</option><option value="manual">Diğer</option></select>
    </div>
    <div>
      <label class="block text-[12px] font-semibold text-slate-500 mb-1.5">Dekont No / Referans</label>
      <input type="text" name="bank_ref" class="w-full h-10 px-3 rounded-xl border border-slate-200 dark:border-white/10 bg-surface text-[13.5px] outline-none focus:border-brand">
    </div>
    <div>
      <label class="block text-[12px] font-semibold text-slate-500 mb-1.5">Dekont (PDF/Resim)</label>
      <input type="file" name="receipt" accept=".pdf,image/*" class="block w-full text-[12px] text-slate-500 file:mr-3 file:h-10 file:px-3 file:rounded-lg file:border-0 file:bg-brand/10 file:text-brand file:text-[12px] file:font-semibold file:cursor-pointer">
    </div>
    <div>
      <button type="submit" class="t w-full h-10 rounded-xl bg-brand text-white text-[13.5px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25">Bildir</button>
    </div>
    <div class="sm:col-span-2 lg:col-span-4"><input type="text" name="note" placeholder="Not (opsiyonel)" class="w-full h-10 px-3 rounded-xl border border-slate-200 dark:border-white/10 bg-surface text-[13.5px] outline-none focus:border-brand"></div>
  </form>
  <?php if ($paytrEnabled): ?>
  <div class="mt-4 pt-4 border-t border-slate-100 dark:border-white/5 flex items-center gap-3 flex-wrap">
    <span class="text-[13px] text-slate-500">Anında ödeme:</span>
    <form method="post" action="<?= url('/subscription/pay-card') ?>"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="t inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-slate-900 dark:bg-white dark:text-slate-900 text-white text-[13px] font-semibold hover:brightness-125"><i data-lucide="credit-card" class="w-4 h-4"></i> Kart ile Öde (PayTR)</button></form>
  </div>
  <?php endif; ?>
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
  <!-- Faturalarım -->
  <section class="rounded-2xl bg-surface shadow-card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-white/5 flex items-center gap-2.5"><i data-lucide="file-text" class="w-[18px] h-[18px] text-slate-400"></i><h2 class="text-[14px] font-bold tracking-tight">Faturalarım</h2></div>
    <?php if (empty($invoices)): ?><div class="px-5 py-8 text-center text-[13px] text-slate-400">Fatura yok.</div><?php else: ?>
    <table class="w-full text-[13px]">
      <thead><tr class="text-left text-slate-400 bg-slate-900/[.02] dark:bg-white/[.03]"><th class="eyebrow font-medium px-5 py-2.5">No</th><th class="eyebrow font-medium px-3 py-2.5">Tür</th><th class="eyebrow font-medium px-3 py-2.5">Tutar</th><th class="eyebrow font-medium px-3 py-2.5">Durum</th><th class="eyebrow font-medium px-5 py-2.5 text-right">Tarih</th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-white/5">
        <?php foreach ($invoices as $i): [$il,$ic] = $invMeta[$i['status']] ?? ['—','bg-slate-500/10 text-slate-500']; ?>
        <tr class="t hover:bg-slate-900/[.02] dark:hover:bg-white/[.03]"><td class="px-5 py-3 font-mono text-[12px]"><?= $e($i['number']) ?></td><td class="px-3 py-3 text-[12px] text-slate-500"><?= $e($kindLbl($i['kind'] ?? 'subscription')) ?></td><td class="px-3 py-3 tnum"><?= $e($fmtP($i['amount'])) ?></td><td class="px-3 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $ic ?>"><?= $e($il) ?></span></td><td class="px-5 py-3 text-right text-slate-400 tnum"><?= $e($fmtD($i['period_start'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <!-- Ödemelerim -->
  <section class="rounded-2xl bg-surface shadow-card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-white/5 flex items-center gap-2.5"><i data-lucide="receipt" class="w-[18px] h-[18px] text-slate-400"></i><h2 class="text-[14px] font-bold tracking-tight">Ödemelerim</h2></div>
    <?php if (empty($payments)): ?><div class="px-5 py-8 text-center text-[13px] text-slate-400">Ödeme yok.</div><?php else: ?>
    <table class="w-full text-[13px]">
      <thead><tr class="text-left text-slate-400 bg-slate-900/[.02] dark:bg-white/[.03]"><th class="eyebrow font-medium px-5 py-2.5">Yöntem</th><th class="eyebrow font-medium px-3 py-2.5">Tutar</th><th class="eyebrow font-medium px-3 py-2.5">Durum</th><th class="eyebrow font-medium px-5 py-2.5 text-right">Tarih</th></tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-white/5">
        <?php foreach ($payments as $p): [$pl,$pc] = $payMeta[$p['status']] ?? ['—','bg-slate-500/10 text-slate-500']; ?>
        <tr class="t hover:bg-slate-900/[.02] dark:hover:bg-white/[.03]"><td class="px-5 py-3 capitalize"><?= $e($p['method']) ?></td><td class="px-3 py-3 tnum"><?= $e($fmtP($p['amount'])) ?></td><td class="px-3 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $pc ?>"><?= $e($pl) ?></span></td><td class="px-5 py-3 text-right text-slate-400 tnum"><?= $e($fmtDT($p['created_at'] ?? null)) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>

<?php endif; ?>
