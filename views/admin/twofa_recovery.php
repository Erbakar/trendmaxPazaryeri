<?php
/** Faz 3/4 — Admin kurtarma kodları (standalone, ortalı kart, tek seferlik gösterim). POST /admin/2fa/recovery (yeniden üret). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_auth_head.php';
$codes = $codes ?? [];
$allCodes = implode("\n", array_map('strval', $codes));
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
      <div class="grid place-items-center w-11 h-11 rounded-xl bg-success/10 text-success mb-4"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
      <h1 class="text-[20px] font-bold tracking-tight leading-none">Kurtarma Kodları</h1>
      <div class="mt-4 flex items-start gap-2.5 rounded-xl border border-warning/30 bg-warning/[.06] px-4 py-3 text-[12.5px] text-amber-700 dark:text-amber-400">
        <i data-lucide="alert-triangle" class="w-4 h-4 mt-0.5 shrink-0"></i>
        <span>Bu kodlar <strong>yalnız bir kez</strong> gösterilir. Güvenli bir yere kaydedin. Her kod <strong>tek kullanımlıktır</strong> ve authenticator erişiminizi kaybederseniz giriş için kullanılır.</span>
      </div>

      <div class="mt-4 rounded-xl bg-slate-900/[.04] dark:bg-white/[.06] p-4">
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 font-mono text-[14px] tracking-wider">
          <?php foreach ($codes as $code): ?><div><?= $e($code) ?></div><?php endforeach; ?>
        </div>
      </div>
      <button type="button" onclick="ozyCopyCodes(this)" class="t mt-3 w-full h-10 rounded-xl border border-slate-200/80 dark:border-white/10 text-[13px] font-semibold hover:shadow-card flex items-center justify-center gap-2"><i data-lucide="copy" class="w-4 h-4 text-slate-400"></i> Tümünü kopyala</button>

      <form method="post" action="<?= url('/admin/2fa/recovery') ?>" class="mt-4" onsubmit="return confirm('Yeni kodlar üretilsin mi? Eski kodlar iptal edilir.')">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <button type="submit" class="t w-full h-11 rounded-xl bg-brand text-white text-[14px] font-semibold hover:brightness-110 shadow-lift shadow-brand/30 flex items-center justify-center gap-2"><i data-lucide="refresh-cw" class="w-4 h-4"></i> Yeni kodlar üret (eskiler iptal)</button>
      </form>
    </div>
    <p class="mt-5 text-center text-[12.5px]"><a href="<?= url('/admin') ?>" class="t font-medium text-slate-500 hover:text-brand">← Panele dön</a></p>
  </div>
</div>
<script>
function ozyCopyCodes(btn){
  if (navigator.clipboard) navigator.clipboard.writeText(<?= json_encode($allCodes, JSON_UNESCAPED_UNICODE) ?>);
  var html = btn.innerHTML;
  btn.innerHTML = '<i data-lucide="check" class="w-4 h-4 text-success"></i> Kopyalandı';
  if (window.lucide) lucide.createIcons();
  setTimeout(function(){ btn.innerHTML = html; if (window.lucide) lucide.createIcons(); }, 1500);
}
</script>
<?php require __DIR__ . '/_auth_foot.php'; ?>
