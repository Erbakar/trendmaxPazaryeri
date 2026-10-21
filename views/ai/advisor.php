<?php
/** Merchant — AI Satış Danışmanı (sohbet). Layout: main. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$bal = (int) ($balance ?? 0);
$cost = (int) ($cost ?? 0);
$suggested = [
    'Bu ay satışlarım neden düştü?',
    'Hangi ürünleri öne çıkarmalıyım?',
    'Stok riski olan ürünler hangileri?',
    'Hangi pazaryeri bana daha çok kazandırıyor?',
];
?>
<style>
.adv-wrap{max-width:860px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.adv-head{background:var(--primary);border-radius:12px;padding:18px 22px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.adv-head h2{font-size:18px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
.adv-head .meta{font-size:12px;background:rgba(255,255,255,.18);border-radius:999px;padding:6px 12px;font-weight:600}
.adv-sugg{display:flex;flex-wrap:wrap;gap:8px}
.adv-chip{border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);border-radius:999px;padding:7px 14px;font-size:12.5px;cursor:pointer;transition:all .15s}
.adv-chip:hover{border-color:var(--primary);color:var(--primary)}
.adv-chat{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:240px;display:flex;flex-direction:column;gap:12px}
.adv-msg{display:flex;gap:10px;max-width:92%}
.adv-msg.u{align-self:flex-end;flex-direction:row-reverse}
.adv-av{width:30px;height:30px;border-radius:8px;flex:none;display:grid;place-items:center;font-size:13px;font-weight:700}
.adv-msg.a .adv-av{background:var(--primary);color:#fff}
.adv-msg.u .adv-av{background:var(--bg-sidebar);color:var(--text-secondary);border:1px solid var(--border)}
.adv-bubble{border-radius:12px;padding:11px 14px;font-size:13.5px;line-height:1.6;color:var(--text-primary)}
.adv-msg.a .adv-bubble{background:var(--bg-sidebar);border:1px solid var(--border)}
.adv-msg.u .adv-bubble{background:rgba(91,92,224,.10);border:1px solid rgba(91,92,224,.2)}
.adv-bubble strong{font-weight:700}
.adv-empty{color:var(--text-muted);text-align:center;font-size:13px;padding:24px 0}
.adv-input{display:flex;gap:8px}
.adv-input textarea{flex:1;border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:13.5px;font-family:inherit;background:var(--bg-sidebar);color:var(--text-primary);resize:vertical;min-height:46px}
.adv-input textarea:focus{outline:none;border-color:var(--primary)}
.adv-send{border:0;background:var(--primary);color:#fff;border-radius:10px;padding:0 18px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.adv-send:disabled{opacity:.55;cursor:default}
.adv-note{font-size:11.5px;color:var(--text-muted);text-align:center}
.adv-warn{background:#fff7ea;border:1px solid #f6e2bd;color:#92580a;border-radius:10px;padding:10px 14px;font-size:13px}
</style>

<div class="adv-wrap">
  <?php $aiActive = '/ai/advisor'; include BASE_PATH . '/views/ai/_nav.php'; ?>
  <div class="adv-head">
    <h2><i data-lucide="sparkles" style="width:20px;height:20px"></i> AI Satış Danışmanı</h2>
    <span class="meta">Bakiye: <?= number_format($bal, 0, ',', '.') ?> kredi · soru başı <?= $cost ?> kredi</span>
  </div>

  <?php if (empty($enabled)): ?>
  <div class="adv-warn">AI Suite şu an kapalı. Yöneticinizle iletişime geçin.</div>
  <?php elseif (empty($available)): ?>
  <div class="adv-warn">Bu modül için yetkiniz yok ya da krediniz yetersiz. <a href="<?= url('/ai/credits') ?>">Kredi & kullanım →</a></div>
  <?php endif; ?>

  <div class="adv-sugg">
    <?php foreach ($suggested as $s): ?>
    <button type="button" class="adv-chip" onclick="advAsk(this.textContent)"><?= $e($s) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="adv-chat" id="advChat">
    <div class="adv-empty" id="advEmpty">Mağaza verilerinize göre yanıt veririm. Yukarıdaki sorulardan birini seçin ya da kendi sorunuzu yazın.</div>
  </div>

  <div class="adv-input">
    <textarea id="advInput" placeholder="Sorunuzu yazın… (ör. en çok hangi üründe iade var?)" rows="1"></textarea>
    <button type="button" class="adv-send" id="advBtn" onclick="advAsk()"><i data-lucide="send" style="width:15px;height:15px"></i> Sor</button>
  </div>
  <div class="adv-note">Yanıtlar yalnız mağazanızın anonim metriklerine dayanır; müşteri kişisel verisi paylaşılmaz.</div>
</div>

<script>
(function(){
  const chat=document.getElementById('advChat'), input=document.getElementById('advInput'), btn=document.getElementById('advBtn');
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  function esc(s){return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
  function fmt(s){return esc(s).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>');}
  function bubble(role,html){
    document.getElementById('advEmpty')?.remove();
    const w=document.createElement('div'); w.className='adv-msg '+(role==='u'?'u':'a');
    w.innerHTML='<div class="adv-av">'+(role==='u'?'S':'AI')+'</div><div class="adv-bubble">'+html+'</div>';
    chat.appendChild(w); chat.scrollTop=chat.scrollHeight; return w;
  }
  window.advAsk=function(preset){
    const q=(preset||input.value||'').trim();
    if(!q){input.focus();return;}
    if(!preset) input.value='';
    bubble('u',esc(q));
    const thinking=bubble('a','<span style="opacity:.6">düşünüyor…</span>');
    btn.disabled=true; input.disabled=true;
    const fd=new FormData(); fd.append('question',q);
    fetch('/ai/advisor/ask',{method:'POST',headers:{'X-CSRF-Token':csrf},body:fd})
      .then(r=>r.json().then(d=>({ok:r.ok,d})))
      .then(({ok,d})=>{
        if(d.success&&d.data&&typeof d.data.answer!=='undefined'){
          thinking.querySelector('.adv-bubble').innerHTML=fmt(d.data.answer);
          if(typeof d.data.balance!=='undefined'){const m=document.querySelector('.adv-head .meta'); if(m) m.firstChild&&(m.innerHTML='Bakiye: '+Number(d.data.balance).toLocaleString('tr-TR')+' kredi · soru başı <?= $cost ?> kredi');}
        } else {
          thinking.querySelector('.adv-bubble').innerHTML='<span style="color:#b91c1c">'+esc(d.message||'Yanıt alınamadı')+'</span>';
        }
      })
      .catch(()=>{thinking.querySelector('.adv-bubble').innerHTML='<span style="color:#b91c1c">Bağlantı hatası</span>';})
      .finally(()=>{btn.disabled=false; input.disabled=false; input.focus(); if(window.lucide)lucide.createIcons();});
  };
  input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();window.advAsk();}});
})();
</script>
