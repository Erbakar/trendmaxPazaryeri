<?php
/** Faz 3/4 — Admin 2FA kurulum (standalone, ortalı kart). POST /admin/2fa/setup · _csrf_token · code. $secret/$otpauthUri gösterilir. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_auth_head.php';
$secret = (string) ($secret ?? '');
$otpauthUri = (string) ($otpauthUri ?? '');
?>
<div class="min-h-screen grid place-items-center px-5 py-12 relative">
  <button type="button" onclick="ozyAuthTheme()" class="t absolute top-5 right-5 grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500">
    <i data-lucide="moon" class="w-[18px] h-[18px] dark:hidden"></i><i data-lucide="sun" class="w-[18px] h-[18px] hidden dark:block"></i>
  </button>
  <div class="w-full max-w-[460px]">
    <div class="flex items-center gap-2.5 justify-center mb-7">
      <?= admin_auth_logo(36, 11, 16) ?>
      <span class="font-bold tracking-tight text-[16px]"><?= $e(brand('site_name')) ?></span>
      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-brand/10 text-brand text-[11px] font-semibold"><i data-lucide="shield-check" class="w-3.5 h-3.5"></i> PLATFORM</span>
    </div>
    <div class="rounded-2xl bg-surface shadow-card p-7">
      <div class="grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand mb-4"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
      <h1 class="text-[20px] font-bold tracking-tight leading-none">2FA Kurulumu</h1>
      <ol class="mt-3 space-y-1.5 text-[13px] text-slate-500 dark:text-slate-400 list-decimal list-inside">
        <li>Authenticator uygulamanıza (Google Authenticator, Authy vb.) aşağıdaki gizli anahtarı ekleyin.</li>
        <li>Uygulamanın ürettiği 6 haneli kodu girin.</li>
      </ol>

      <?php if (!empty($_flash['error'])): ?>
      <div class="mt-5 flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-[13px] text-rose-700 dark:text-rose-400"><i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= $e($_flash['error']) ?></span></div>
      <?php endif; ?>

      <!-- gizli anahtar -->
      <div class="mt-5">
        <div class="eyebrow text-slate-400 mb-1.5">Gizli Anahtar</div>
        <div class="flex items-center gap-2">
          <code id="secretVal" class="flex-1 px-3 py-2.5 rounded-xl bg-slate-900/[.04] dark:bg-white/[.06] font-mono text-[14px] tracking-[2px] break-all"><?= $e($secret) ?></code>
          <button type="button" onclick="ozyCopy('<?= $e($secret) ?>', this)" class="t grid place-items-center w-10 h-10 rounded-xl border border-slate-200/80 dark:border-white/10 text-slate-400 hover:text-brand hover:shadow-card shrink-0" title="Kopyala"><i data-lucide="copy" class="w-4 h-4"></i></button>
        </div>
        <?php if ($otpauthUri !== ''): ?>
        <p class="mt-2 text-[11px] text-slate-400 break-all"><span class="font-semibold">otpauth URI:</span> <?= $e($otpauthUri) ?></p>
        <?php endif; ?>
      </div>

      <form method="post" action="<?= url('/admin/2fa/setup') ?>" class="mt-5 space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <div>
          <label for="code" class="block text-[13px] font-semibold text-slate-700 dark:text-slate-200 mb-1.5">6 haneli kod</label>
          <input id="code" name="code" type="text" inputmode="numeric" required placeholder="000000" class="t w-full h-12 px-4 rounded-xl bg-surface border border-slate-200 dark:border-white/10 text-[18px] font-mono tracking-[6px] text-center placeholder:text-slate-300 outline-none focus:border-brand focus:ring-4 focus:ring-brand/15">
        </div>
        <button type="submit" class="t w-full h-11 rounded-xl bg-brand text-white text-[14px] font-semibold hover:brightness-110 shadow-lift shadow-brand/30 flex items-center justify-center gap-2"><i data-lucide="shield-check" class="w-4 h-4"></i> Doğrula ve Etkinleştir</button>
      </form>
    </div>
    <p class="mt-5 text-center text-[12.5px]"><a href="<?= url('/admin') ?>" class="t font-medium text-slate-500 hover:text-brand">← Panele dön</a></p>
  </div>
</div>
<script>
function ozyCopy(text, btn){
  if (navigator.clipboard) navigator.clipboard.writeText(text);
  btn.innerHTML = '<i data-lucide="check" class="w-4 h-4 text-success"></i>';
  if (window.lucide) lucide.createIcons();
  setTimeout(function(){ btn.innerHTML = '<i data-lucide="copy" class="w-4 h-4"></i>'; if (window.lucide) lucide.createIcons(); }, 1500);
}
</script>
<?php require __DIR__ . '/_auth_foot.php'; ?>
