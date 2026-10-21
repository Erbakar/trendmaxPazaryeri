<?php
/** Ayarlar › Görünüm — tema/vurgu/yoğunluk (store_settings + localStorage tema). */
$sv    = $data['sv'] ?? [];
$csrf  = $data['_csrf'] ?? '';
$theme = $sv['appearance_theme'] ?? 'system';
$dens  = $sv['appearance_density'] ?? 'comfortable';
?>
<form method="POST" action="/settings/gorunum" class="space-y-5">
  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="active_tab" value="gorunum">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 class="text-[18px] font-bold tracking-tight leading-none">Görünüm</h2>
      <p class="mt-1.5 text-[12.5px] text-slate-400">Tema ve arayüz yoğunluğu tercihleri.</p>
    </div>
    <button type="submit" class="t flex items-center gap-2 h-10 px-4 rounded-xl bg-brand text-white text-[13.5px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25"><i data-lucide="check" class="w-4 h-4"></i> Değişiklikleri Kaydet</button>
  </div>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="palette" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Tema</h3><p class="mt-1 text-[12.5px] text-slate-400">Açık, koyu veya sistem temasını seç (anında uygulanır).</p></div>
    </div>
    <div class="grid grid-cols-3 gap-3 max-w-md">
      <?php
      $themes = ['light' => ['Açık', 'sun'], 'dark' => ['Koyu', 'moon'], 'system' => ['Sistem', 'monitor']];
      foreach ($themes as $val => [$lbl, $ico]):
          $on = $theme === $val;
      ?>
      <label class="t cursor-pointer rounded-xl border <?= $on ? 'border-brand ring-2 ring-brand/30' : 'border-slate-200/80 dark:border-white/10' ?> p-3 flex flex-col items-center gap-2 hover:border-brand/50">
        <input type="radio" name="s[appearance_theme]" value="<?= $val ?>" class="hidden gorunum-theme" <?= $on ? 'checked' : '' ?>>
        <i data-lucide="<?= $ico ?>" class="w-5 h-5 <?= $on ? 'text-brand' : 'text-slate-400' ?>"></i>
        <span class="text-[12.5px] font-semibold"><?= $lbl ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="layout-dashboard" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Arayüz Yoğunluğu</h3><p class="mt-1 text-[12.5px] text-slate-400">Tablo ve listelerde satır aralığı.</p></div>
    </div>
    <div class="relative max-w-xs">
      <select name="s[appearance_density]" class="t w-full h-10 pl-3 pr-9 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] font-medium text-slate-600 dark:text-slate-300 outline-none focus:ring-2 focus:ring-brand/40 appearance-none cursor-pointer">
        <option value="comfortable" <?= $dens === 'comfortable' ? 'selected' : '' ?>>Rahat (varsayılan)</option>
        <option value="compact" <?= $dens === 'compact' ? 'selected' : '' ?>>Sıkışık</option>
      </select>
      <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
    </div>
  </section>
</form>
<script>
/* Tema seçimi anında uygula (mevcut localStorage 'ozy-theme' köprüsü) */
document.querySelectorAll('#settingsContent .gorunum-theme').forEach(function(r){
  r.addEventListener('change', function(){
    try {
      var v = this.value;
      if (v === 'dark') { document.documentElement.classList.add('dark'); localStorage.setItem('ozy-theme','dark'); }
      else if (v === 'light') { document.documentElement.classList.remove('dark'); localStorage.setItem('ozy-theme','light'); }
      else { localStorage.removeItem('ozy-theme'); }
    } catch(e){}
  });
});
</script>
