<?php
/** Admin auth — sağ marka paneli (login + 2fa). Vurgu DİNAMİK (bg-brand). $e kapsamdan gelir. */
$e = $e ?? fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$authMps = ['trendyol' => 'Trendyol', 'hepsiburada' => 'Hepsiburada', 'amazon' => 'Amazon', 'n11' => 'n11', 'ciceksepeti' => 'ÇiçekSepeti', 'pazarama' => 'Pazarama'];
?>
<div class="hidden lg:block relative overflow-hidden bg-brand text-white">
  <div class="pointer-events-none absolute inset-0 opacity-[.55]" style="background:radial-gradient(120% 80% at 100% 0%, rgba(255,255,255,.18), transparent 55%), radial-gradient(90% 70% at 0% 100%, rgba(0,0,0,.22), transparent 50%)"></div>
  <div class="pointer-events-none absolute -top-28 -right-24 w-[420px] h-[420px] rounded-full border border-white/15"></div>
  <div class="pointer-events-none absolute -top-12 -right-8 w-[300px] h-[300px] rounded-full border border-white/10"></div>

  <button type="button" onclick="ozyAuthTheme()" class="t absolute top-6 right-6 z-10 grid place-items-center w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 text-white backdrop-blur-sm">
    <i data-lucide="moon" class="w-[18px] h-[18px] dark:hidden"></i>
    <i data-lucide="sun" class="w-[18px] h-[18px] hidden dark:block"></i>
  </button>

  <div class="relative h-full min-h-screen flex flex-col justify-center px-12 xl:px-16 py-14">
    <div class="inline-flex items-center gap-2 self-start px-3 py-1.5 rounded-full bg-white/[.12] backdrop-blur-sm text-[12px] font-semibold ring-1 ring-white/20">
      <i data-lucide="shield-check" class="w-4 h-4"></i> Güvenli Platform Erişimi
    </div>
    <h2 class="mt-8 text-[34px] xl:text-[40px] font-bold tracking-tight leading-[1.08]">Tüm pazaryerleri<br>tek panelde.</h2>
    <p class="mt-4 max-w-[430px] text-[14.5px] text-white/80 leading-relaxed">Operatör konsolu; mağazaları, yetkileri ve entegrasyonları tek merkezden yönetir. İki adımlı doğrulama ile korunur.</p>

    <div class="mt-10 space-y-3 max-w-[420px]">
      <div class="flex items-center gap-3 text-[13.5px] text-white/85"><span class="grid place-items-center w-8 h-8 rounded-lg bg-white/10 ring-1 ring-white/15"><i data-lucide="layout-dashboard" class="w-[17px] h-[17px]"></i></span> Mağaza, abonelik ve operasyon tek ekranda</div>
      <div class="flex items-center gap-3 text-[13.5px] text-white/85"><span class="grid place-items-center w-8 h-8 rounded-lg bg-white/10 ring-1 ring-white/15"><i data-lucide="lock" class="w-[17px] h-[17px]"></i></span> Rol bazlı erişim ve denetim kaydı</div>
      <div class="flex items-center gap-3 text-[13.5px] text-white/85"><span class="grid place-items-center w-8 h-8 rounded-lg bg-white/10 ring-1 ring-white/15"><i data-lucide="activity" class="w-[17px] h-[17px]"></i></span> Gerçek zamanlı sistem sağlığı</div>
    </div>

    <div class="mt-11">
      <div class="eyebrow text-white/60">Yönetilen Entegrasyonlar</div>
      <div class="mt-4 grid grid-cols-3 gap-3 max-w-[420px]">
        <?php foreach ($authMps as $slug => $name): ?>
        <span class="logo-chip justify-center h-14"><img class="mp-logo" src="<?= $e(asset('img/marketplaces/' . $slug . '.png')) ?>" alt="<?= $e($name) ?>" onerror="this.onerror=null;this.src='<?= $e(asset('img/marketplaces/' . $slug . '.jpg')) ?>'"></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
