<?php
/** Ayarlar › Güvenlik — oturum & uyarı tercihleri (store_settings). */
$sv      = $data['sv'] ?? [];
$csrf    = $data['_csrf'] ?? '';
$timeout = $sv['security_session_timeout'] ?? '120';
$alerts  = ($sv['security_login_alerts'] ?? '1') === '1';
?>
<form method="POST" action="/settings/guvenlik" class="space-y-5">
  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="active_tab" value="guvenlik">
  <input type="hidden" name="s[security_login_alerts]" id="secAlertsHidden" value="<?= $alerts ? '1' : '0' ?>">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 class="text-[18px] font-bold tracking-tight leading-none">Güvenlik</h2>
      <p class="mt-1.5 text-[12.5px] text-slate-400">Oturum ve giriş güvenliği tercihleri.</p>
    </div>
    <button type="submit" class="t flex items-center gap-2 h-10 px-4 rounded-xl bg-brand text-white text-[13.5px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25"><i data-lucide="check" class="w-4 h-4"></i> Değişiklikleri Kaydet</button>
  </div>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="lock" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Oturum</h3><p class="mt-1 text-[12.5px] text-slate-400">Oturum süresi ve giriş bildirimleri.</p></div>
    </div>
    <div class="space-y-5">
      <div class="max-w-xs">
        <label class="block text-[13px] font-semibold mb-1.5">Oturum Zaman Aşımı</label>
        <div class="relative"><select name="s[security_session_timeout]" class="t w-full h-10 pl-3 pr-9 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] font-medium text-slate-600 dark:text-slate-300 outline-none focus:ring-2 focus:ring-brand/40 appearance-none cursor-pointer">
          <?php foreach (['30' => '30 dakika', '60' => '1 saat', '120' => '2 saat', '480' => '8 saat', '1440' => '24 saat'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= (string)$timeout === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select><i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i></div>
        <p class="mt-1.5 text-[12px] text-slate-400">Hareketsizlik sonrası otomatik çıkış süresi.</p>
      </div>

      <div class="flex items-center justify-between gap-4 py-1">
        <div>
          <div class="text-[13.5px] font-semibold">Giriş Uyarıları</div>
          <div class="text-[12px] text-slate-400">Yeni bir cihazdan giriş yapıldığında e-posta ile bildirim al.</div>
        </div>
        <button type="button" id="secAlertsToggle" onclick="secToggleAlerts()" role="switch" aria-checked="<?= $alerts ? 'true' : 'false' ?>" class="t relative w-11 h-6 rounded-full shrink-0 <?= $alerts ? 'bg-brand' : 'bg-slate-300 dark:bg-white/15' ?>">
          <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform <?= $alerts ? 'translate-x-5' : '' ?>"></span>
        </button>
      </div>
    </div>
  </section>

  </section>
</form>

<!-- Şifre değiştirme: ayrı form (iç içe form olamaz) — aynı /settings/guvenlik rotasına gider -->
<form method="POST" action="/settings/guvenlik" class="mt-5" autocomplete="off">
  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="active_tab" value="guvenlik">
  <input type="hidden" name="sifre_degistir" value="1">

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-slate-500/10 text-slate-500"><i data-lucide="key-round" class="w-[18px] h-[18px]"></i></span>
      <div>
        <h3 class="text-[15px] font-bold tracking-tight leading-none">Şifre Değiştir</h3>
        <p class="mt-1 text-[12.5px] text-slate-400">Kendi hesabınızın şifresini buradan güncelleyebilirsiniz.</p>
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3 max-w-3xl">
      <div>
        <label for="pwCurrent" class="block text-[13px] font-semibold mb-1.5">Mevcut Şifre</label>
        <input type="password" id="pwCurrent" name="mevcut_sifre" required autocomplete="current-password"
               class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div>
        <label for="pwNew" class="block text-[13px] font-semibold mb-1.5">Yeni Şifre</label>
        <input type="password" id="pwNew" name="yeni_sifre" required minlength="8" autocomplete="new-password"
               aria-describedby="pwHint"
               class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div>
        <label for="pwRepeat" class="block text-[13px] font-semibold mb-1.5">Yeni Şifre (tekrar)</label>
        <input type="password" id="pwRepeat" name="yeni_sifre_tekrar" required minlength="8" autocomplete="new-password"
               class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] outline-none focus:ring-2 focus:ring-brand/40">
      </div>
    </div>

    <p id="pwHint" class="mt-2 text-[12px] text-slate-400">En az 8 karakter. Değişiklik sonrası oturumunuz açık kalır.</p>

    <button type="submit" class="t inline-flex items-center gap-2 mt-4 h-10 px-4 rounded-xl bg-brand text-white text-[13.5px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25">
      <i data-lucide="key-round" class="w-4 h-4"></i> Şifreyi Güncelle
    </button>
  </section>
</form>
<script>
function secToggleAlerts(){
  var btn = document.getElementById('secAlertsToggle');
  var hidden = document.getElementById('secAlertsHidden');
  var on = btn.getAttribute('aria-checked') !== 'true';
  btn.setAttribute('aria-checked', on ? 'true' : 'false');
  btn.classList.toggle('bg-brand', on);
  btn.classList.toggle('bg-slate-300', !on);
  btn.classList.toggle('dark:bg-white/15', !on);
  btn.querySelector('span').classList.toggle('translate-x-5', on);
  hidden.value = on ? '1' : '0';
}
</script>
