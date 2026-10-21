<?php
/** Faz 3 — Admin yeni şifre (standalone, ortalı kart). POST /admin/reset · _csrf_token · token(hidden) · password(min 8). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_auth_head.php';
?>
<div class="min-h-screen grid place-items-center px-5 py-12 relative">
  <button type="button" onclick="ozyAuthTheme()" class="t absolute top-5 right-5 grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500">
    <i data-lucide="moon" class="w-[18px] h-[18px] dark:hidden"></i><i data-lucide="sun" class="w-[18px] h-[18px] hidden dark:block"></i>
  </button>
  <div class="w-full max-w-[420px]">
    <div class="flex items-center gap-2.5 justify-center mb-7">
      <?= admin_auth_logo(36, 11, 16) ?>
      <span class="font-bold tracking-tight text-[16px]"><?= $e(brand('site_name')) ?></span>
      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-brand/10 text-brand text-[11px] font-semibold"><i data-lucide="shield-check" class="w-3.5 h-3.5"></i> PLATFORM</span>
    </div>
    <div class="rounded-2xl bg-surface shadow-card p-7">
      <div class="grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand mb-4"><i data-lucide="lock" class="w-5 h-5"></i></div>
      <h1 class="text-[20px] font-bold tracking-tight leading-none">Yeni Şifre Belirle</h1>
      <p class="mt-2 text-[13px] text-slate-500 dark:text-slate-400">En az 8 karakterli yeni bir şifre girin.</p>

      <?php if (!empty($_flash['error'])): ?>
      <div class="mt-5 flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-[13px] text-rose-700 dark:text-rose-400"><i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= $e($_flash['error']) ?></span></div>
      <?php endif; ?>

      <form method="post" action="<?= url('/admin/reset') ?>" class="mt-6 space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <input type="hidden" name="token" value="<?= $e($token ?? '') ?>">
        <div>
          <label for="password" class="block text-[13px] font-semibold text-slate-700 dark:text-slate-200 mb-1.5">Yeni şifre</label>
          <div class="relative">
            <i data-lucide="lock" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-[18px] h-[18px] text-slate-400"></i>
            <input id="password" name="password" type="password" required minlength="8" placeholder="••••••••" class="t w-full h-11 pl-11 pr-11 rounded-xl bg-surface border border-slate-200 dark:border-white/10 text-[14px] placeholder:text-slate-400 outline-none focus:border-brand focus:ring-4 focus:ring-brand/15">
            <button type="button" onclick="ozyTogglePass(this)" class="t absolute right-2 top-1/2 -translate-y-1/2 grid place-items-center w-8 h-8 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-400"><i data-lucide="eye" class="w-[18px] h-[18px]"></i></button>
          </div>
        </div>
        <button type="submit" class="t w-full h-11 rounded-xl bg-brand text-white text-[14px] font-semibold hover:brightness-110 shadow-lift shadow-brand/30 flex items-center justify-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Şifreyi Güncelle</button>
      </form>
    </div>
    <p class="mt-5 text-center text-[12.5px]"><a href="<?= url('/admin/login') ?>" class="t font-medium text-slate-500 hover:text-brand">← Girişe dön</a></p>
  </div>
</div>
<script>
function ozyTogglePass(btn){
  var inp = document.getElementById('password'); var on = inp.type === 'password';
  inp.type = on ? 'text' : 'password';
  btn.innerHTML = on ? '<i data-lucide="eye-off" class="w-[18px] h-[18px]"></i>' : '<i data-lucide="eye" class="w-[18px] h-[18px]"></i>';
  if (window.lucide) lucide.createIcons();
}
</script>
<?php require __DIR__ . '/_auth_foot.php'; ?>
