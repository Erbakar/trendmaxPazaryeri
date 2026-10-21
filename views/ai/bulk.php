<?php
/** Merchant — AI Toplu Ürün Doldurma. Layout: main. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$bal = (int) ($balance ?? 0);
$cost = (int) ($cost ?? 0);
$items = $items ?? [];
?>
<style>
.bf-wrap{max-width:1000px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.bf-head{background:var(--primary);border-radius:12px;padding:18px 22px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.bf-head h2{font-size:18px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
.bf-head .meta{font-size:12px;background:rgba(255,255,255,.18);border-radius:999px;padding:6px 12px;font-weight:600}
.bf-warn{background:#fff7ea;border:1px solid #f6e2bd;color:#92580a;border-radius:10px;padding:10px 14px;font-size:13px}
.bf-bar{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:sticky;top:8px;z-index:5}
.bf-bar .sp{flex:1}
.bf-bar{top:72px}
.bf-btn{border:0;border-radius:9px;padding:9px 16px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.bf-btn.primary{background:var(--primary);color:#fff}
.bf-btn.primary:disabled{opacity:.5;cursor:default}
.bf-btn.ghost{background:var(--bg-sidebar);color:var(--text-secondary);border:1px solid var(--border)}
.bf-search{flex:1;min-width:180px;border:1px solid var(--border);border-radius:9px;padding:8px 12px;font-size:13px;background:var(--bg-sidebar);color:var(--text-primary)}
.bf-search:focus{outline:none;border-color:var(--primary)}
.bf-count{font-size:12.5px;color:var(--text-secondary);font-weight:600;white-space:nowrap}
.bf-prog{height:8px;border-radius:999px;background:var(--bg-sidebar);overflow:hidden;display:none}
.bf-prog.on{display:block}
.bf-prog>i{display:block;height:100%;width:0;background:var(--primary);transition:width .3s}
.bf-list{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.bf-row{display:grid;grid-template-columns:34px 1fr 150px 150px;align-items:center;gap:10px;padding:10px 14px;border-top:1px solid var(--border);font-size:13px}
.bf-row:first-child{border-top:0}
.bf-row.head{background:var(--bg-sidebar);font-weight:700;color:var(--text-secondary);font-size:11.5px;text-transform:uppercase;letter-spacing:.03em}
.bf-row .nm{font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bf-row .sku{font-size:11.5px;color:var(--text-muted)}
.bf-badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.bf-badge.ok{background:#e8f6ee;color:#1a7f43}
.bf-badge.miss{background:#fdf0e3;color:#a85a07}
.bf-badge.full{background:var(--bg-sidebar);color:var(--text-muted)}
.bf-st{font-size:12px;font-weight:600;color:var(--text-muted)}
.bf-st.run{color:var(--primary)}
.bf-st.done{color:#1a7f43}
.bf-st.err{color:#b91c1c}
.bf-empty{padding:30px;text-align:center;color:var(--text-muted);font-size:13px}
.bf-cb{width:17px;height:17px;cursor:pointer;accent-color:var(--primary)}
@media(max-width:640px){.bf-row{grid-template-columns:30px 1fr 90px;}.bf-row .colspecs{display:none}}
</style>

<div class="bf-wrap">
  <?php $aiActive = '/ai/bulk'; include BASE_PATH . '/views/ai/_nav.php'; ?>
  <div class="bf-head">
    <h2><i data-lucide="wand-2" style="width:20px;height:20px"></i> AI Toplu Ürün Doldurma</h2>
    <span class="meta" id="bfMeta">Bakiye: <span id="bfBal"><?= number_format($bal, 0, ',', '.') ?></span> kredi · ürün başı <?= $cost ?> kredi</span>
  </div>

  <?php if (empty($enabled)): ?>
  <div class="bf-warn">AI Suite şu an kapalı. Yöneticinizle iletişime geçin.</div>
  <?php elseif (empty($available)): ?>
  <div class="bf-warn">Bu modül için yetkiniz yok ya da krediniz yetersiz. <a href="<?= url('/ai/credits') ?>">Kredi &amp; kullanım →</a></div>
  <?php endif; ?>

  <div class="bf-bar">
    <button type="button" class="bf-btn ghost" id="bfSelMissing"><i data-lucide="filter" style="width:14px;height:14px"></i> Eksik olanları seç</button>
    <input type="search" class="bf-search" id="bfSearch" placeholder="Ürün adı / SKU ara…">
    <span class="sp"></span>
    <span class="bf-count" id="bfCount">0 ürün seçili</span>
    <button type="button" class="bf-btn primary" id="bfRun" disabled><i data-lucide="sparkles" style="width:15px;height:15px"></i> Seçilenleri AI ile Doldur</button>
  </div>
  <div class="bf-prog" id="bfProg"><i></i></div>

  <div class="bf-list" id="bfList">
    <?php if ($items === []): ?>
      <div class="bf-empty">Henüz ürün yok. Önce ürün ekleyin.</div>
    <?php else: ?>
      <div class="bf-row head">
        <label style="display:grid;place-items:center"><input type="checkbox" class="bf-cb" id="bfAll"></label>
        <span>Ürün</span>
        <span class="colspecs">Durum</span>
        <span class="colspecs">İşlem</span>
      </div>
      <?php foreach ($items as $p):
        $id = (int) $p['id'];
        $hasAi = !empty($p['ai_generated_at']);
        $noDesc = (int) ($p['no_desc'] ?? 0) === 1;
        $noSeo = (int) ($p['no_seo'] ?? 0) === 1;
        $missing = (!$hasAi && ($noDesc || $noSeo)) ? 1 : 0;
        if ($hasAi) { $badge = 'ok'; $btxt = 'AI üretildi'; }
        elseif ($noDesc) { $badge = 'miss'; $btxt = 'Açıklama yok'; }
        elseif ($noSeo) { $badge = 'miss'; $btxt = 'SEO eksik'; }
        else { $badge = 'full'; $btxt = 'Dolu'; }
      ?>
      <div class="bf-row" data-row="<?= $id ?>" data-name="<?= $e(mb_strtolower((string) $p['name'])) ?>" data-sku="<?= $e(mb_strtolower((string) ($p['sku'] ?? ''))) ?>">
        <label style="display:grid;place-items:center"><input type="checkbox" class="bf-cb bf-item" value="<?= $id ?>" data-missing="<?= $missing ?>"></label>
        <span><span class="nm"><?= $e($p['name']) ?></span><?php if (!empty($p['sku'])): ?><br><span class="sku"><?= $e($p['sku']) ?></span><?php endif; ?></span>
        <span class="colspecs"><span class="bf-badge <?= $badge ?>"><?= $btxt ?></span></span>
        <span class="colspecs bf-st" id="bfSt<?= $id ?>">—</span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div style="font-size:11.5px;color:var(--text-muted);text-align:center">Her ürün için ürün adından SEO başlığı, açıklama, kısa açıklama ve teknik özellikler üretilir; mevcut dolu alanlar korunur. Ürün başına <?= $cost ?> kredi düşer.</div>
</div>

<script>
(function(){
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const COST=<?= $cost ?>, CHUNK=4;
  const list=document.getElementById('bfList');
  const all=document.getElementById('bfAll');
  const runBtn=document.getElementById('bfRun');
  const selMissing=document.getElementById('bfSelMissing');
  const search=document.getElementById('bfSearch');
  const countEl=document.getElementById('bfCount');
  const prog=document.getElementById('bfProg');
  const progBar=prog?prog.querySelector('i'):null;
  const balEl=document.getElementById('bfBal');
  if(!list||!runBtn) return;

  const items=()=>Array.from(document.querySelectorAll('.bf-item'));
  const selected=()=>items().filter(c=>c.checked && c.closest('.bf-row').style.display!=='none');

  function refresh(){
    const n=selected().length;
    countEl.textContent=n+' ürün seçili'+(n?(' · ~'+(n*COST).toLocaleString('tr-TR')+' kredi'):'');
    runBtn.disabled=(n===0);
    const vis=items().filter(c=>c.closest('.bf-row').style.display!=='none');
    all.checked=(vis.length>0 && vis.every(c=>c.checked));
  }

  all?.addEventListener('change',()=>{ items().forEach(c=>{ if(c.closest('.bf-row').style.display!=='none') c.checked=all.checked; }); refresh(); });
  list.addEventListener('change',e=>{ if(e.target.classList.contains('bf-item')) refresh(); });

  selMissing?.addEventListener('click',()=>{ items().forEach(c=>{ c.checked=(c.dataset.missing==='1' && c.closest('.bf-row').style.display!=='none'); }); refresh(); });

  search?.addEventListener('input',()=>{
    const q=search.value.trim().toLowerCase();
    document.querySelectorAll('.bf-row[data-row]').forEach(r=>{
      const hit=!q || (r.dataset.name||'').includes(q) || (r.dataset.sku||'').includes(q);
      r.style.display=hit?'':'none';
    });
    refresh();
  });

  function setSt(id,txt,cls){ const el=document.getElementById('bfSt'+id); if(el){ el.textContent=txt; el.className='colspecs bf-st'+(cls?' '+cls:''); } }
  function chunk(a,n){ const o=[]; for(let i=0;i<a.length;i+=n) o.push(a.slice(i,i+n)); return o; }

  runBtn.addEventListener('click',async()=>{
    const ids=selected().map(c=>c.value);
    if(!ids.length) return;
    if(!confirm(ids.length+' ürün için AI içerik üretilecek (~'+(ids.length*COST).toLocaleString('tr-TR')+' kredi). Devam edilsin mi?')) return;
    runBtn.disabled=true; selMissing.disabled=true; all.disabled=true;
    prog.classList.add('on'); progBar.style.width='0%';
    ids.forEach(id=>setSt(id,'sırada…',''));
    const batches=chunk(ids,CHUNK); let done=0, stopped=false, okCount=0;
    for(const batch of batches){
      if(stopped) break;
      batch.forEach(id=>setSt(id,'işleniyor…','run'));
      try{
        const fd=new FormData(); fd.append('ids',batch.join(','));
        const r=await fetch('/ai/bulk-fill',{method:'POST',headers:{'X-CSRF-Token':csrf},body:fd});
        const d=await r.json();
        if(d.success&&d.data){
          (d.data.results||[]).forEach(res=>{
            const id=res.id;
            if(res.status==='ok'){ setSt(id,'✓ dolduruldu','done'); okCount++; const cb=document.querySelector('.bf-item[value="'+id+'"]'); if(cb){cb.checked=false; cb.disabled=true;} }
            else if(res.status==='nocredit'){ setSt(id,'kredi yetersiz','err'); }
            else if(res.status==='noentitle'){ setSt(id,'yetki yok','err'); }
            else if(res.status==='skip'){ setSt(id,res.msg||'atlandı',''); }
            else { setSt(id,res.msg||'hata','err'); }
          });
          if(typeof d.data.balance!=='undefined' && balEl) balEl.textContent=Number(d.data.balance).toLocaleString('tr-TR');
          if(d.data.stopped){ stopped=true; }
        } else {
          batch.forEach(id=>setSt(id,(d.message||'hata'),'err'));
          if(r.status===402||r.status===403){ stopped=true; }
        }
      }catch(err){ batch.forEach(id=>setSt(id,'bağlantı hatası','err')); }
      done+=batch.length;
      progBar.style.width=Math.round(done/ids.length*100)+'%';
    }
    selMissing.disabled=false; all.disabled=false;
    refresh();
    if(window.Toast) Toast.show(stopped?('Durduruldu — '+okCount+' ürün dolduruldu (kredi/yetki yetersiz).'):(okCount+' ürün başarıyla dolduruldu.'), stopped?'warning':'success');
    if(window.lucide) lucide.createIcons();
  });

  refresh();
})();
</script>
