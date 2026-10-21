<?php
/** Merchant — AI Rakip Analizi (manuel giriş). Layout: main. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$bal = (int) ($balance ?? 0);
$cost = (int) ($cost ?? 0);
$products = $products ?? [];
?>
<style>
.cp-wrap{max-width:880px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.cp-head{background:var(--primary);border-radius:12px;padding:18px 22px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.cp-head h2{font-size:18px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
.cp-head .meta{font-size:12px;background:rgba(255,255,255,.18);border-radius:999px;padding:6px 12px;font-weight:600}
.cp-warn{background:#fff7ea;border:1px solid #f6e2bd;color:#92580a;border-radius:10px;padding:10px 14px;font-size:13px}
.cp-note{background:#eef2ff;border:1px solid #dbe1ff;color:#3730a3;border-radius:10px;padding:10px 14px;font-size:12.5px;line-height:1.5}
.cp-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px}
.cp-lbl{font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:5px;display:block}
.cp-in{width:100%;border:1px solid var(--border);border-radius:8px;padding:9px 11px;font-size:13px;background:var(--bg-sidebar);color:var(--text-primary)}
.cp-in:focus{outline:none;border-color:var(--primary)}
.cp-row{display:grid;grid-template-columns:1fr 130px 34px;gap:8px;margin-bottom:8px;align-items:center}
.cp-del{border:0;background:#fde8e8;color:#dc2626;border-radius:7px;height:38px;cursor:pointer;font-size:15px;font-weight:700}
.cp-add{border:1px dashed var(--border);background:transparent;color:var(--primary);border-radius:8px;padding:8px;font-size:12.5px;font-weight:600;cursor:pointer;width:100%}
.cp-go{border:0;background:var(--primary);color:#fff;border-radius:9px;padding:11px 16px;font-weight:700;font-size:13.5px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;width:100%;justify-content:center}
.cp-go:disabled{opacity:.55;cursor:default}
.cp-res{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;display:none;font-size:13.5px;line-height:1.6}
.cp-res h3{font-size:13px;font-weight:600;color:var(--primary);margin:12px 0 4px;display:flex;align-items:center;gap:6px}
.cp-res h3:first-child{margin-top:0}
.cp-pill{display:inline-block;background:var(--bg-sidebar);border:1px solid var(--border);border-radius:8px;padding:8px 12px;margin:4px 0;font-weight:700}
.cp-res ul{margin:4px 0;padding-left:18px}
.cp-res li{margin:3px 0}
</style>

<div class="cp-wrap">
  <?php $aiActive = '/ai/competitor'; include BASE_PATH . '/views/ai/_nav.php'; ?>
  <div class="cp-head">
    <h2><i data-lucide="swords" style="width:20px;height:20px"></i> AI Rakip Analizi</h2>
    <span class="meta">Bakiye: <span id="cpBal"><?= number_format($bal, 0, ',', '.') ?></span> kredi · analiz başı <?= $cost ?> kredi</span>
  </div>

  <?php if (empty($enabled)): ?>
  <div class="cp-warn">AI Suite şu an kapalı. Yöneticinizle iletişime geçin.</div>
  <?php elseif (empty($available)): ?>
  <div class="cp-warn">Bu modül için yetkiniz yok ya da krediniz yetersiz. <a href="<?= url('/ai/credits') ?>">Kredi &amp; kullanım →</a></div>
  <?php endif; ?>

  <div class="cp-note"><b>Nasıl çalışır?</b> Kendi ürününüzü seçin, rakiplerin başlık ve fiyatlarını girin. AI fiyat konumlandırması, başlık ve strateji önerisi üretir. Rakip verisini siz girersiniz — pazaryeri canlı verisi kullanılmaz, böylece her ürün için çalışır.</div>

  <div class="cp-card">
    <label class="cp-lbl">Kendi Ürününüz</label>
    <select id="cpProduct" class="cp-in" style="margin-bottom:8px">
      <option value="">— Üründen seç (otomatik doldur) —</option>
      <?php foreach ($products as $p): ?>
      <option value="<?= (int) $p['id'] ?>" data-name="<?= $e($p['name']) ?>" data-price="<?= $e(number_format((float) $p['price'], 2, '.', '')) ?>"><?= $e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="cp-row" style="grid-template-columns:1fr 130px">
      <input type="text" id="cpOwnName" class="cp-in" placeholder="Ürün başlığınız">
      <input type="number" step="0.01" id="cpOwnPrice" class="cp-in" placeholder="Fiyat (TL)">
    </div>
    <input type="text" id="cpOwnFeatures" class="cp-in" placeholder="Öne çıkan özellikler (opsiyonel)" style="margin-bottom:14px">

    <label class="cp-lbl">Rakipler (başlık + fiyat)</label>
    <div id="cpRows">
      <div class="cp-row">
        <input type="text" class="cp-in cp-ct" placeholder="Rakip ürün başlığı">
        <input type="number" step="0.01" class="cp-in cp-cp" placeholder="Fiyat (TL)">
        <button type="button" class="cp-del" onclick="cpDel(this)">✖</button>
      </div>
      <div class="cp-row">
        <input type="text" class="cp-in cp-ct" placeholder="Rakip ürün başlığı">
        <input type="number" step="0.01" class="cp-in cp-cp" placeholder="Fiyat (TL)">
        <button type="button" class="cp-del" onclick="cpDel(this)">✖</button>
      </div>
    </div>
    <button type="button" class="cp-add" onclick="cpAddRow()" style="margin-bottom:14px">+ Rakip ekle</button>

    <button type="button" class="cp-go" id="cpGo" onclick="cpAnalyze()"><i data-lucide="sparkles" style="width:16px;height:16px"></i> Rakip Analizi Yap (<?= $cost ?> kredi)</button>
  </div>

  <div class="cp-res" id="cpRes"></div>
</div>

<script>
(function(){
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const sel=document.getElementById('cpProduct');
  sel?.addEventListener('change',()=>{
    const o=sel.options[sel.selectedIndex];
    if(o&&o.value){ document.getElementById('cpOwnName').value=o.dataset.name||''; document.getElementById('cpOwnPrice').value=o.dataset.price||''; }
  });
  window.cpDel=function(b){ const rows=document.querySelectorAll('#cpRows .cp-row'); if(rows.length<=1){ b.closest('.cp-row').querySelectorAll('input').forEach(i=>i.value=''); return; } b.closest('.cp-row').remove(); };
  window.cpAddRow=function(){
    if(document.querySelectorAll('#cpRows .cp-row').length>=6) return;
    const d=document.createElement('div'); d.className='cp-row';
    d.innerHTML='<input type="text" class="cp-in cp-ct" placeholder="Rakip ürün başlığı"><input type="number" step="0.01" class="cp-in cp-cp" placeholder="Fiyat (TL)"><button type="button" class="cp-del" onclick="cpDel(this)">✖</button>';
    document.getElementById('cpRows').appendChild(d);
  };
  window.cpAnalyze=function(){
    const name=(document.getElementById('cpOwnName').value||'').trim();
    if(!name){ Toast.show('Önce kendi ürün başlığınızı girin','warning'); return; }
    const titles=[...document.querySelectorAll('#cpRows .cp-ct')].map(i=>i.value.trim());
    const prices=[...document.querySelectorAll('#cpRows .cp-cp')].map(i=>i.value.trim());
    if(!titles.some(t=>t)){ Toast.show('En az bir rakip başlığı girin','warning'); return; }
    const btn=document.getElementById('cpGo'), box=document.getElementById('cpRes');
    const fd=new FormData();
    fd.append('_csrf_token',csrf);
    fd.append('product_id',sel?.value||'0');
    fd.append('own_name',name);
    fd.append('own_price',document.getElementById('cpOwnPrice').value||'0');
    fd.append('own_features',document.getElementById('cpOwnFeatures').value||'');
    titles.forEach(t=>fd.append('comp_title[]',t));
    prices.forEach(p=>fd.append('comp_price[]',p));
    const orig=btn.innerHTML; btn.disabled=true; btn.innerHTML='<i data-lucide="loader-2" style="width:16px;height:16px"></i> Analiz ediliyor…';
    if(window.lucide)lucide.createIcons();
    fetch('/ai/competitor-analyze',{method:'POST',headers:{'X-CSRF-Token':csrf},body:fd})
      .then(r=>r.json().then(d=>({ok:r.ok,d})))
      .then(({ok,d})=>{
        if(d.success&&d.data&&d.data.analysis){
          const a=d.data.analysis;
          const esc=s=>(s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
          let h='<h3><i data-lucide="crosshair" style="width:15px;height:15px"></i> Konumlandırma</h3><div>'+esc(a.positioning)+'</div>';
          h+='<h3><i data-lucide="tag" style="width:15px;height:15px"></i> Fiyat Analizi</h3><div>'+esc(a.price_analysis)+'</div>';
          if(a.suggested_price) h+='<h3><i data-lucide="badge-dollar-sign" style="width:15px;height:15px"></i> Önerilen Fiyat</h3><div class="cp-pill">'+esc(a.suggested_price)+'</div>';
          if(a.title_suggestions&&a.title_suggestions.length){ h+='<h3><i data-lucide="type" style="width:15px;height:15px"></i> Başlık Önerileri</h3><ul>'; a.title_suggestions.forEach(t=>h+='<li>'+esc(t)+'</li>'); h+='</ul>'; }
          if(a.recommendations&&a.recommendations.length){ h+='<h3><i data-lucide="lightbulb" style="width:15px;height:15px"></i> Öneriler</h3><ul>'; a.recommendations.forEach(t=>h+='<li>'+esc(t)+'</li>'); h+='</ul>'; }
          box.innerHTML=h; box.style.display='block'; box.scrollIntoView({behavior:'smooth',block:'nearest'});
          if(typeof d.data.balance!=='undefined') document.getElementById('cpBal').textContent=Number(d.data.balance).toLocaleString('tr-TR');
          if(window.lucide)lucide.createIcons();
        } else { Toast.show(d.message||'Analiz başarısız','error',6000); }
      })
      .catch(()=>Toast.show('Bağlantı hatası','error'))
      .finally(()=>{btn.disabled=false; btn.innerHTML=orig; if(window.lucide)lucide.createIcons();});
  };
})();
</script>
