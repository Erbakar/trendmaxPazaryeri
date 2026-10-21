<?php
/**
 * OzyConnect — Çerez izin banner'ı (KVKK/GDPR). site.php <body> içinde include edilir.
 * Tercih (ozy_consent çerezi) verilene kadar gösterilir. "Tümünü Kabul Et" → reklam/analitik
 * pikselleri yüklensin diye sayfayı yeniler. "Yalnızca Gerekli" → pikseller yüklenmez.
 */
$consent = (string) ($_COOKIE['ozy_consent'] ?? '');
if ($consent !== '') { return; } // tercih zaten verilmiş
$cookieUrl = function_exists('url') ? url('/cerez-politikasi') : '/cerez-politikasi';
?>
<div id="ozyConsent" role="dialog" aria-label="Çerez bildirimi" style="position:fixed;left:16px;right:16px;bottom:16px;z-index:9990;max-width:880px;margin:0 auto;background:#fff;border:1px solid #e6e9f0;border-radius:16px;box-shadow:0 18px 50px -18px rgba(16,24,40,.4);padding:18px 20px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;font-family:inherit">
  <div style="flex:1;min-width:240px;color:#475569;font-size:13.5px;line-height:1.6">
    <strong style="color:#0f172a;display:block;margin-bottom:2px">🍪 Çerezleri kullanıyoruz</strong>
    Deneyimi iyileştirmek, trafiği ölçmek ve reklamları kişiselleştirmek için çerezler kullanırız. Zorunlu çerezler her zaman aktiftir.
    <a href="<?= htmlspecialchars($cookieUrl, ENT_QUOTES) ?>" style="color:var(--blue,#2563eb);font-weight:600;text-decoration:none">Çerez Politikası</a>
  </div>
  <div style="display:flex;gap:9px;flex-wrap:wrap">
    <button type="button" id="ozyConsentNec" style="height:40px;padding:0 16px;border-radius:10px;border:1px solid #e6e9f0;background:#fff;color:#475569;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">Yalnızca Gerekli</button>
    <button type="button" id="ozyConsentAll" style="height:40px;padding:0 20px;border-radius:10px;border:0;background:var(--blue,#2563eb);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit">Tümünü Kabul Et</button>
  </div>
</div>
<script>
(function(){
  function setConsent(v){document.cookie='ozy_consent='+v+';path=/;max-age=31536000;SameSite=Lax';var el=document.getElementById('ozyConsent');if(el){el.parentNode.removeChild(el);}}
  /* Google Consent Mode v2 sinyalini güncelle (GTM/GA4/Ads anında devreye girer; aynı dataLayer'a yazar) */
  function updateConsent(g){window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('consent','update',{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g});gtag('set','ads_data_redaction',g!=='granted');}
  var all=document.getElementById('ozyConsentAll'),nec=document.getElementById('ozyConsentNec');
  if(all)all.addEventListener('click',function(){setConsent('all');updateConsent('granted');location.reload();}); /* Consent Mode yükselt + Meta/TikTok için yenile */
  if(nec)nec.addEventListener('click',function(){setConsent('necessary');updateConsent('denied');});
})();
</script>
