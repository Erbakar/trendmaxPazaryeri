<?php
/** Admin giriş (standalone, 2-panel). POST /admin/login · _csrf_token · email · password. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_auth_head.php';
?>
<div class="min-h-screen lg:grid lg:grid-cols-[1fr_1.05fr]">
  <!-- SOL: form -->
  <div class="relative flex flex-col min-h-screen lg:min-h-0">
    <button type="button" onclick="ozyAuthTheme()" class="t lg:hidden absolute top-5 right-5 z-10 grid place-items-center w-9 h-9 rounded-xl hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500">
      <i data-lucide="moon" class="w-[18px] h-[18px] dark:hidden"></i><i data-lucide="sun" class="w-[18px] h-[18px] hidden dark:block"></i>
    </button>
    <div class="flex-1 grid place-items-center px-6 py-12">
      <div class="w-full max-w-[392px]">
        <div class="flex items-center gap-2.5">
          <?= admin_auth_logo(40, 12, 18) ?>
          <div class="leading-none">
            <div class="font-bold tracking-tight text-[16px]"><?= $e(brand('site_name')) ?></div>
            <div class="mt-1 text-[10.5px] font-bold uppercase tracking-[.14em] text-brand">Platform</div>
          </div>
        </div>

        <div class="mt-10">
          <h1 class="text-[26px] font-bold tracking-tight leading-tight">Tekrar hoş geldiniz</h1>
          <p class="mt-2 text-[13.5px] text-slate-500 dark:text-slate-400">Operatör hesabınızla devam edin. Erişim iki adımlı doğrulama ile korunur.</p>
        </div>

        <?php if (!empty($_flash['error'])): ?>
        <div class="mt-6 flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-[13px] text-rose-700 dark:text-rose-400"><i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= $e($_flash['error']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($_flash['success'])): ?>
        <div class="mt-6 flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 px-4 py-3 text-[13px] text-emerald-700 dark:text-emerald-400"><i data-lucide="check-circle-2" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= $e($_flash['success']) ?></span></div>
        <?php endif; ?>

        <form method="post" action="<?= url('/admin/login') ?>" class="mt-8 space-y-4">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <div>
            <label for="email" class="block text-[13px] font-semibold text-slate-700 dark:text-slate-200 mb-1.5">E-posta</label>
            <div class="relative">
              <i data-lucide="mail" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-[18px] h-[18px] text-slate-400"></i>
              <input id="email" name="email" type="email" autocomplete="username" required placeholder="ad@entegrator.com" class="t w-full h-12 pl-11 pr-3.5 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] border border-transparent text-[14px] placeholder:text-slate-400 outline-none focus:bg-surface focus:border-brand focus:ring-4 focus:ring-brand/15">
            </div>
          </div>
          <div>
            <div class="flex items-center justify-between mb-1.5">
              <label for="pass" class="block text-[13px] font-semibold text-slate-700 dark:text-slate-200">Şifre</label>
              <a href="<?= url('/admin/forgot') ?>" class="t text-[12.5px] font-medium text-brand hover:underline">Şifremi unuttum</a>
            </div>
            <div class="relative">
              <i data-lucide="lock" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-[18px] h-[18px] text-slate-400"></i>
              <input id="pass" name="password" type="password" autocomplete="current-password" required placeholder="••••••••" class="t w-full h-12 pl-11 pr-11 rounded-xl bg-slate-900/[.035] dark:bg-white/[.05] border border-transparent text-[14px] placeholder:text-slate-400 outline-none focus:bg-surface focus:border-brand focus:ring-4 focus:ring-brand/15">
              <button type="button" onclick="ozyTogglePass(this)" class="t absolute right-2 top-1/2 -translate-y-1/2 grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-400"><i data-lucide="eye" class="w-[18px] h-[18px]"></i></button>
            </div>
          </div>
          <button type="submit" class="t w-full h-12 rounded-xl bg-brand text-white text-[14px] font-semibold hover:brightness-110 shadow-lift shadow-brand/30 flex items-center justify-center gap-2">Giriş Yap <i data-lucide="arrow-right" class="w-4 h-4"></i></button>
        </form>

        <p class="mt-8 text-center text-[11px] text-slate-400 flex items-center justify-center gap-1.5"><i data-lucide="shield" class="w-3 h-3"></i> Bu alan yalnızca yetkili platform operatörleri içindir.</p>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/_auth_brand.php'; ?>
</div>

<script>
function ozyTogglePass(btn){
  var inp = document.getElementById('pass'); var on = inp.type === 'password';
  inp.type = on ? 'text' : 'password';
  btn.innerHTML = on ? '<i data-lucide="eye-off" class="w-[18px] h-[18px]"></i>' : '<i data-lucide="eye" class="w-[18px] h-[18px]"></i>';
  if (window.lucide) lucide.createIcons();
}
</script>
<?php require __DIR__ . '/_auth_foot.php'; ?>
