<?php
/** Ayarlar › Bildirimler — bildirim sesleri (tarayıcı/localStorage, NotificationManager). */
?>
<style>
.snd-toggle{position:relative;width:44px;height:24px;cursor:pointer;flex-shrink:0;display:inline-block}
.snd-toggle input{opacity:0;width:0;height:0}
.snd-track{position:absolute;inset:0;background:rgb(203 213 225);border-radius:12px;transition:background .2s}
.dark .snd-track{background:rgba(255,255,255,.15)}
.snd-thumb{position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 0 0 1px var(--border)}
.snd-toggle input:checked+.snd-track{background:rgb(var(--brand))}
.snd-toggle input:checked+.snd-track+.snd-thumb{transform:translateX(20px)}
.snd-sel{height:40px;padding:0 12px;border-radius:10px;background:rgba(15,23,42,.03);font-size:13px;font-weight:500;outline:none}
.dark .snd-sel{background:rgba(255,255,255,.05)}
</style>

<div class="space-y-5">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 class="text-[18px] font-bold tracking-tight leading-none">Bildirimler</h2>
      <p class="mt-1.5 text-[12.5px] text-slate-400">Sipariş, iade ve soru bildirim sesleri.</p>
    </div>
  </div>

  <div class="rounded-xl bg-brand/[.06] border border-brand/15 px-4 py-3 flex items-center gap-2.5 text-[12.5px] text-slate-600 dark:text-slate-300">
    <i data-lucide="info" class="w-4 h-4 text-brand shrink-0"></i>
    Ses ayarları tarayıcınızda saklanır. Değişiklikler anında uygulanır.
  </div>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="bell" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Bildirim Sesleri</h3><p class="mt-1 text-[12.5px] text-slate-400">Genel ses ve seviye.</p></div>
    </div>

    <div class="flex items-center justify-between gap-4 py-2.5 border-b border-slate-100 dark:border-white/5">
      <label class="text-[13.5px] font-semibold flex items-center gap-2"><i data-lucide="volume-2" class="w-[18px] h-[18px] text-slate-400"></i> Bildirim Sesleri</label>
      <label class="snd-toggle"><input type="checkbox" id="sndEnabled"><span class="snd-track"></span><span class="snd-thumb"></span></label>
    </div>
    <div class="flex items-center justify-between gap-4 py-2.5">
      <label class="text-[13.5px] font-semibold flex items-center gap-2"><i data-lucide="gauge" class="w-[18px] h-[18px] text-slate-400"></i> Ses Seviyesi</label>
      <div class="flex items-center gap-3 flex-1 max-w-[260px]">
        <input type="range" id="sndVolume" min="10" max="100" step="5" class="flex-1" style="accent-color:rgb(var(--brand))">
        <span id="sndVolumeLabel" class="text-[13px] font-bold tnum min-w-[36px] text-right">70%</span>
      </div>
    </div>
  </section>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-success/10 text-success"><i data-lucide="bell-ring" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Olay Sesleri</h3><p class="mt-1 text-[12.5px] text-slate-400">Her olay için ayrı ses seç ve dene.</p></div>
    </div>
    <?php
    $soundOpts = ['ding' => 'Ding', 'chime' => 'Chime', 'bell' => 'Bell', 'cash' => 'Kasa Sesi', 'money' => 'Para Sesi 💰', 'notification' => 'Bildirim', 'alert' => 'Uyarı'];
    $events = [
        ['orders', 'Yeni Sipariş', 'package', 'text-success'],
        ['returns', 'Yeni İade', 'rotate-ccw', 'text-warning'],
        ['questions', 'Yeni Soru', 'message-circle-question', 'text-brand'],
    ];
    foreach ($events as [$key, $label, $icon, $color]):
        $ucKey = ucfirst($key);
    ?>
    <div class="flex flex-wrap items-center justify-between gap-3 py-2.5 border-b border-slate-100 dark:border-white/5 last:border-0">
      <label class="text-[13.5px] font-semibold flex items-center gap-2"><i data-lucide="<?= $icon ?>" class="w-[18px] h-[18px] <?= $color ?>"></i> <?= $label ?></label>
      <div class="flex items-center gap-2.5">
        <label class="snd-toggle"><input type="checkbox" id="snd<?= $ucKey ?>Enabled"><span class="snd-track"></span><span class="snd-thumb"></span></label>
        <select id="snd<?= $ucKey ?>Sound" class="snd-sel"><?php foreach ($soundOpts as $v => $l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select>
        <button type="button" class="t inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-brand text-white text-[12.5px] font-semibold hover:brightness-110" onclick="NotificationSounds.play(document.getElementById('snd<?= $ucKey ?>Sound').value, document.getElementById('sndVolume').value/100)"><i data-lucide="play" class="w-3.5 h-3.5"></i> Dene</button>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="pt-4 text-center">
      <button type="button" class="t inline-flex items-center gap-2 h-9 px-4 rounded-lg bg-slate-900/[.04] dark:bg-white/[.06] text-[13px] font-medium hover:bg-slate-900/[.07] dark:hover:bg-white/[.1]" onclick="['orders','returns','questions'].forEach((t,i)=>{setTimeout(()=>{const s=document.getElementById('snd'+t.charAt(0).toUpperCase()+t.slice(1)+'Sound');if(s)NotificationSounds.play(s.value,document.getElementById('sndVolume').value/100);},i*800);});"><i data-lucide="play-circle" class="w-4 h-4"></i> Tüm Sesleri Sırayla Çal</button>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const NM = window.NotificationManager;
    if (!NM) return;
    const defaults = NM.getDefaults();
    const s = NM.getSettings();
    const g = (k) => s[k] !== undefined ? s[k] : defaults[k];
    document.getElementById('sndEnabled').checked = g('enabled');
    document.getElementById('sndVolume').value = g('volume');
    document.getElementById('sndVolumeLabel').textContent = g('volume') + '%';
    document.getElementById('sndOrdersEnabled').checked = g('orders_enabled');
    document.getElementById('sndOrdersSound').value = g('orders_sound');
    document.getElementById('sndReturnsEnabled').checked = g('returns_enabled');
    document.getElementById('sndReturnsSound').value = g('returns_sound');
    document.getElementById('sndQuestionsEnabled').checked = g('questions_enabled');
    document.getElementById('sndQuestionsSound').value = g('questions_sound');
    function save() {
        NM.saveSettings({
            enabled: document.getElementById('sndEnabled').checked,
            volume: parseInt(document.getElementById('sndVolume').value),
            orders_enabled: document.getElementById('sndOrdersEnabled').checked,
            orders_sound: document.getElementById('sndOrdersSound').value,
            returns_enabled: document.getElementById('sndReturnsEnabled').checked,
            returns_sound: document.getElementById('sndReturnsSound').value,
            questions_enabled: document.getElementById('sndQuestionsEnabled').checked,
            questions_sound: document.getElementById('sndQuestionsSound').value,
        });
        NM.updateMuteIcon();
    }
    document.getElementById('sndEnabled').addEventListener('change', save);
    document.getElementById('sndVolume').addEventListener('input', function () { document.getElementById('sndVolumeLabel').textContent = this.value + '%'; save(); });
    document.getElementById('sndOrdersEnabled').addEventListener('change', save);
    document.getElementById('sndOrdersSound').addEventListener('change', save);
    document.getElementById('sndReturnsEnabled').addEventListener('change', save);
    document.getElementById('sndReturnsSound').addEventListener('change', save);
    document.getElementById('sndQuestionsEnabled').addEventListener('change', save);
    document.getElementById('sndQuestionsSound').addEventListener('change', save);
});
</script>
