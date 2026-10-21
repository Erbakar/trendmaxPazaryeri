<?php
/** Faz 3 — Admin 2FA (standalone, 2-panel). POST /admin/2fa · _csrf_token · code (6 kutu→tek alan) · recovery=1 (kurtarma modu).
 * KRİTİK: controller `code`'u tek alan olarak okur; 6 kutu submit'te #codeField'a birleşir. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_auth_head.php';
?>
<div class="min-h-screen lg:grid lg:grid-cols-2">
  <!-- SOL: 2FA -->
  <div class="relative flex flex-col min-h-screen lg:min-h-0">
    <button type="button" onclick="ozyAuthTheme()" class="t lg:hidden absolute top-5 right-5 z-10 grid place-items-center w-9 h-9 rounded-lg hover:bg-slate-900/[.05] dark:hover:bg-white/[.07] text-slate-500">
      <i data-lucide="moon" class="w-[18px] h-[18px] dark:hidden"></i><i data-lucide="sun" class="w-[18px] h-[18px] hidden dark:block"></i>
    </button>
    <div class="flex-1 grid place-items-center px-6 py-12">
      <div class="w-full max-w-[400px]">
        <div class="flex items-center gap-2.5">
          <?= admin_auth_logo(36, 11, 16) ?>
          <span class="font-bold tracking-tight text-[16px]"><?= $e(brand('site_name')) ?></span>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-brand/10 text-brand text-[11px] font-semibold"><i data-lucide="shield-check" class="w-3.5 h-3.5"></i> PLATFORM</span>
        </div>

        <div class="mt-9 flex items-center gap-3">
          <span class="grid place-items-center w-11 h-11 rounded-xl bg-brand/10 text-brand shrink-0"><i data-lucide="key-round" class="w-5 h-5"></i></span>
          <div>
            <div class="eyebrow text-slate-400">2FA Doğrulama</div>
            <h1 class="mt-1 text-[22px] font-bold tracking-tight leading-none">İki Adımlı Doğrulama</h1>
          </div>
        </div>
        <p class="mt-3 text-[13.5px] text-slate-500 dark:text-slate-400" id="twofaHint">Authenticator uygulamanızdaki 6 haneli kodu girin.</p>

        <?php if (!empty($_flash['error'])): ?>
        <div class="mt-5 flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-[13px] text-rose-700 dark:text-rose-400"><i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0"></i><span><?= $e($_flash['error']) ?></span></div>
        <?php endif; ?>

        <form method="post" action="<?= url('/admin/2fa') ?>" class="mt-7" id="twofaForm" onsubmit="return prep2fa()">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <input type="hidden" name="code" id="codeField">
          <input type="hidden" name="recovery" id="recoveryFlag" value="1" disabled>

          <div id="otpMode" class="flex items-center justify-between gap-2">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input class="otp t" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Hane <?= $i + 1 ?>" placeholder="">
            <?php endfor; ?>
          </div>

          <div id="recoveryMode" hidden>
            <input id="recoveryInput" type="text" autocomplete="off" placeholder="Kurtarma kodu" class="t w-full h-12 px-4 rounded-xl bg-surface border border-slate-200 dark:border-white/10 text-[15px] font-mono tracking-wider placeholder:text-slate-400 outline-none focus:border-brand focus:ring-4 focus:ring-brand/15">
          </div>

          <button type="submit" class="t mt-6 w-full h-11 rounded-xl bg-brand text-white text-[14px] font-semibold hover:brightness-110 shadow-lift shadow-brand/30 flex items-center justify-center gap-2"><i data-lucide="shield-check" class="w-4 h-4"></i> Doğrula</button>

          <div class="mt-4 flex items-center justify-center">
            <button type="button" onclick="toggleRecovery()" class="t inline-flex items-center gap-1.5 text-[12.5px] font-medium text-slate-500 hover:text-brand"><i data-lucide="life-buoy" class="w-3.5 h-3.5"></i> <span id="recoveryToggleText">Kurtarma kodu kullan</span></button>
          </div>
        </form>

        <p class="mt-7 text-center text-[12px] text-slate-400"><a href="<?= url('/admin/login') ?>" class="t font-medium text-slate-500 hover:text-brand">← Girişe dön</a></p>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/_auth_brand.php'; ?>
</div>

<script>
(function(){
  var boxes = Array.prototype.slice.call(document.querySelectorAll('#otpMode .otp'));
  boxes.forEach(function(box, i){
    box.addEventListener('input', function(){ box.value = box.value.replace(/\D/g,'').slice(0,1); if (box.value && i < boxes.length - 1) boxes[i+1].focus(); });
    box.addEventListener('keydown', function(ev){ if (ev.key === 'Backspace' && !box.value && i > 0) boxes[i-1].focus(); });
    box.addEventListener('paste', function(ev){ ev.preventDefault(); var d = (ev.clipboardData.getData('text') || '').replace(/\D/g,'').slice(0, boxes.length); d.split('').forEach(function(ch, k){ if (boxes[k]) boxes[k].value = ch; }); (boxes[Math.min(d.length, boxes.length - 1)] || box).focus(); });
  });
  if (boxes[0]) boxes[0].focus();
  window.__otpBoxes = boxes;
})();
var recoveryOn = false;
function toggleRecovery(){
  recoveryOn = !recoveryOn;
  document.getElementById('otpMode').hidden = recoveryOn;
  document.getElementById('recoveryMode').hidden = !recoveryOn;
  document.getElementById('recoveryFlag').disabled = !recoveryOn;
  document.getElementById('recoveryToggleText').textContent = recoveryOn ? 'Authenticator koduna dön' : 'Kurtarma kodu kullan';
  document.getElementById('twofaHint').textContent = recoveryOn ? 'Daha önce kaydettiğiniz tek kullanımlık kurtarma kodunu girin.' : 'Authenticator uygulamanızdaki 6 haneli kodu girin.';
  if (recoveryOn) document.getElementById('recoveryInput').focus(); else if (window.__otpBoxes && window.__otpBoxes[0]) window.__otpBoxes[0].focus();
}
function prep2fa(){
  var field = document.getElementById('codeField');
  field.value = recoveryOn ? (document.getElementById('recoveryInput').value || '').trim() : (window.__otpBoxes || []).map(function(b){ return b.value; }).join('');
  return true;
}
</script>
<?php require __DIR__ . '/_auth_foot.php'; ?>
