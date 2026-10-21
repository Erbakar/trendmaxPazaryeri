<?php
/** Ayarlar › Mağaza — mağaza kimliği + logo (store_settings). */
$profile = $data['profile'] ?? [];
$csrf    = $data['_csrf'] ?? '';
$pv = static fn(string $k) => htmlspecialchars((string) ($profile[$k] ?? ''), ENT_QUOTES, 'UTF-8');

/* İl/ilçe cascade verisi */
$provinces       = $data['provinces'] ?? [];
$districtsByProv = $data['districtsByProv'] ?? [];
$selProv = (int) ($profile['province_id'] ?? 0);
$selDist = (int) ($profile['district_id'] ?? 0);
/* Eski serbest-metin il/ilçe için ada göre ön-seçim (province_id yoksa) */
if ($selProv === 0 && trim((string) ($profile['billing_city'] ?? '')) !== '') {
    foreach ($provinces as $p) {
        if (mb_strtolower((string) $p['name'], 'UTF-8') === mb_strtolower((string) $profile['billing_city'], 'UTF-8')) { $selProv = (int) $p['id']; break; }
    }
}
if ($selDist === 0 && $selProv > 0 && trim((string) ($profile['billing_district'] ?? '')) !== '') {
    foreach (($districtsByProv[$selProv] ?? []) as $d) {
        if (mb_strtolower((string) $d[1], 'UTF-8') === mb_strtolower((string) $profile['billing_district'], 'UTF-8')) { $selDist = (int) $d[0]; break; }
    }
}
$docVerified = (bool) ($profile['doc_verified'] ?? false);
$docPending  = (int) ($profile['doc_pending'] ?? 0);
?>
<form method="POST" action="/settings/magaza" enctype="multipart/form-data" class="space-y-5">
  <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="active_tab" value="magaza">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 class="text-[18px] font-bold tracking-tight leading-none">Mağaza Bilgileri</h2>
      <p class="mt-1.5 text-[12.5px] text-slate-400">Müşterilere ve pazaryerlerine görünen kimlik.</p>
    </div>
    <button type="submit" class="t flex items-center gap-2 h-10 px-4 rounded-xl bg-brand text-white text-[13.5px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25"><i data-lucide="check" class="w-4 h-4"></i> Değişiklikleri Kaydet</button>
  </div>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="store" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Mağaza Kimliği</h3><p class="mt-1 text-[12.5px] text-slate-400">Faturalarda ve pazaryeri eşleşmelerinde kullanılır.</p></div>
    </div>

    <div class="space-y-5">
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">Mağaza Adı</label>
        <input type="text" name="s[store_name]" maxlength="200" value="<?= $pv('store_name') ?>" placeholder="Mağazanızın adı" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>

      <div>
        <label class="block text-[13px] font-semibold mb-1.5">Mağaza Logosu</label>
        <div class="flex items-center gap-4">
          <span class="grid place-items-center w-16 h-16 rounded-2xl bg-slate-900/[.03] dark:bg-white/[.05] overflow-hidden shrink-0">
            <?php if (($profile['store_logo'] ?? '') !== ''): ?>
              <img src="<?= $pv('store_logo') ?>" alt="Logo" class="max-w-full max-h-full object-contain">
            <?php else: ?>
              <span class="grid place-items-center w-full h-full bg-gradient-to-br from-brand to-violet-500 text-white text-[18px] font-bold leading-none"><?= htmlspecialchars(mb_substr((string)($profile['store_name'] ?? 'M'), 0, 1)) ?></span>
            <?php endif; ?>
          </span>
          <label class="t flex-1 flex flex-col items-center justify-center gap-1.5 h-[88px] rounded-xl border border-dashed border-slate-300 dark:border-white/15 cursor-pointer hover:border-brand/50 hover:bg-brand/[.03]">
            <i data-lucide="upload-cloud" class="w-5 h-5 text-slate-400"></i>
            <span class="text-[12.5px] text-slate-500"><span class="font-semibold text-brand">Dosya seç</span> veya buraya sürükle</span>
            <span class="text-[11px] text-slate-400">PNG, JPG, SVG · maks. 5 MB · 512×512 px önerilir</span>
            <input type="file" name="store_logo" accept=".png,.jpg,.jpeg,.webp,.svg" class="hidden">
          </label>
        </div>
      </div>

      <div>
        <label class="block text-[13px] font-semibold mb-1.5">Mağaza Açıklaması</label>
        <textarea name="s[store_description]" rows="3" maxlength="280" placeholder="Mağazanızı kısaca tanıtın" class="t w-full px-3 py-2.5 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none resize-y focus:ring-2 focus:ring-brand/40"><?= $pv('store_description') ?></textarea>
      </div>
    </div>
  </section>

  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="contact" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">İletişim</h3><p class="mt-1 text-[12.5px] text-slate-400">Faturalar ve bildirimler için iletişim bilgileri.</p></div>
    </div>
    <div class="grid sm:grid-cols-2 gap-5">
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">E-posta</label>
        <input type="email" name="s[store_email]" maxlength="190" value="<?= $pv('store_email') ?>" placeholder="magaza@ornek.com" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">Telefon</label>
        <input type="text" name="s[store_phone]" maxlength="40" value="<?= $pv('store_phone') ?>" placeholder="0xxx xxx xx xx" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
    </div>
  </section>

  <!-- Vergi & Fatura Adresi (stores tablosu — admin "Mağaza Düzenle" ile ortak alanlar) -->
  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="file-text" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Vergi &amp; Fatura Adresi</h3><p class="mt-1 text-[12.5px] text-slate-400">Resmi fatura ve e-Arşiv için kullanılır. Admin panelindeki mağaza bilgileriyle ortaktır.</p></div>
    </div>
    <div class="grid sm:grid-cols-2 gap-5">
      <div class="sm:col-span-2">
        <label class="block text-[13px] font-semibold mb-1.5">Yasal Ünvan</label>
        <input type="text" name="b[legal_name]" maxlength="200" value="<?= $pv('legal_name') ?>" placeholder="Firma yasal ünvanı" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">VKN / TCKN</label>
        <input type="text" name="b[tax_id]" maxlength="20" value="<?= $pv('tax_id') ?>" placeholder="Vergi no veya TC kimlik no" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">Vergi Dairesi</label>
        <input type="text" name="b[tax_office]" maxlength="120" value="<?= $pv('tax_office') ?>" placeholder="Vergi dairesi" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-[13px] font-semibold mb-1.5">Adres</label>
        <textarea name="b[billing_address]" rows="2" maxlength="300" placeholder="Açık adres (mahalle, cadde, no)" class="t w-full px-3 py-2.5 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none resize-y focus:ring-2 focus:ring-brand/40"><?= $pv('billing_address') ?></textarea>
      </div>
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">İl</label>
        <select name="b[province_id]" data-target="mgIlce" class="js-il t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] outline-none focus:ring-2 focus:ring-brand/40">
          <option value="">İl seçin</option>
          <?php foreach ($provinces as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= $selProv === (int) $p['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">İlçe</label>
        <select id="mgIlce" name="b[district_id]" data-selected="<?= $selDist ?>" data-placeholder="İlçe seçin" class="js-ilce t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] outline-none focus:ring-2 focus:ring-brand/40">
          <option value="">Önce il seçin</option>
        </select>
      </div>
      <div>
        <label class="block text-[13px] font-semibold mb-1.5">Posta Kodu</label>
        <input type="text" name="b[billing_postcode]" maxlength="10" value="<?= $pv('billing_postcode') ?>" placeholder="34000" class="t w-full h-10 px-3 rounded-lg bg-slate-900/[.03] dark:bg-white/[.05] text-[13.5px] placeholder:text-slate-400 outline-none focus:ring-2 focus:ring-brand/40">
      </div>
    </div>
  </section>

  <!-- Belgeler & Doğrulama -->
  <section class="rounded-2xl bg-surface p-6 shadow-card">
    <div class="flex items-center gap-2.5 mb-5">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-brand/10 text-brand"><i data-lucide="shield-check" class="w-[18px] h-[18px]"></i></span>
      <div><h3 class="text-[15px] font-bold tracking-tight leading-none">Belgeler &amp; Doğrulama</h3><p class="mt-1 text-[12.5px] text-slate-400">Mağaza doğrulaması için gereken evraklar.</p></div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border <?= $docVerified ? 'border-emerald-200 bg-emerald-50 dark:bg-emerald-500/10' : 'border-amber-200 bg-amber-50 dark:bg-amber-500/10' ?> p-4">
      <div class="flex items-center gap-3">
        <span class="grid place-items-center w-10 h-10 rounded-full <?= $docVerified ? 'bg-emerald-500/15 text-emerald-600' : 'bg-amber-500/15 text-amber-600' ?>"><i data-lucide="<?= $docVerified ? 'badge-check' : 'clock' ?>" class="w-5 h-5"></i></span>
        <div>
          <div class="text-[14px] font-bold"><?= $docVerified ? 'Mağazanız doğrulandı' : 'Doğrulama bekleniyor' ?></div>
          <div class="text-[12.5px] text-slate-500"><?= $docVerified ? 'Tüm zorunlu belgeleriniz onaylandı.' : ($docPending > 0 ? $docPending . ' belge onay bekliyor veya reddedildi.' : 'Zorunlu belgelerinizi yükleyin.') ?></div>
        </div>
      </div>
      <a href="/documents" class="t inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-brand text-white text-[13px] font-semibold hover:brightness-110 shadow-lift shadow-brand/25"><i data-lucide="folder-open" class="w-4 h-4"></i> Belgeleri Yönet</a>
    </div>
    <?php if (!$docVerified): ?>
    <p class="mt-3 text-[12px] text-amber-600 flex items-center gap-1.5"><i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Zorunlu belgeleriniz onaylanana kadar panel kullanımı kısıtlanır.</p>
    <?php endif; ?>
  </section>
</form>
<script>
/* dosya seç → dosya adını göster */
document.querySelector('#settingsContent input[name="store_logo"]')?.addEventListener('change', function(){
  const lbl = this.closest('label')?.querySelector('span.text-\\[12\\.5px\\]');
  if (lbl && this.files[0]) lbl.innerHTML = '<span class="font-semibold text-brand">' + this.files[0].name + '</span> seçildi';
});
</script>
<script>
/* İl → İlçe cascade (sunucudan gömülü veri, ağ gerektirmez) */
(function(){
  var ILCE = <?= json_encode($districtsByProv, JSON_UNESCAPED_UNICODE) ?>;
  function fill(ilSel){
    var tgt = document.getElementById(ilSel.getAttribute('data-target'));
    if(!tgt) return;
    var cur = tgt.getAttribute('data-selected') || '';
    var ph  = tgt.getAttribute('data-placeholder') || 'İlçe seçin';
    var list = ILCE[ilSel.value] || [];
    tgt.innerHTML = '<option value="">' + ph + '</option>';
    list.forEach(function(d){
      var o = document.createElement('option'); o.value = d[0]; o.textContent = d[1];
      if (String(d[0]) === String(cur)) o.selected = true;
      tgt.appendChild(o);
    });
  }
  document.querySelectorAll('#settingsContent select.js-il').forEach(function(ilSel){
    fill(ilSel);
    ilSel.addEventListener('change', function(){
      var tgt = document.getElementById(ilSel.getAttribute('data-target'));
      if (tgt) tgt.setAttribute('data-selected', '');
      fill(ilSel);
    });
  });
})();
</script>
