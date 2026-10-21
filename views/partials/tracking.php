<?php
/**
 * OzyConnect — Kurumsal site reklam pikselleri (client-side).
 * site.php içinde <body> başında include edilir. Ayarlar: global Setting (group='marketing').
 * Meta Pixel + TikTok Pixel + Google Ads (gtag) base + PageView. ozyTrack() ile dönüşüm olayları.
 * Sunucu-taraflı (Conversions API) eşi: MarketingTrackingService — aynı event_id ile dedup.
 */
$_e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$js = static fn($s) => json_encode((string) $s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$mkMeta   = brand('meta_enabled', '0') === '1' && brand('meta_pixel_id', '') !== '';
$metaId   = brand('meta_pixel_id', '');
$mkTt     = brand('tiktok_enabled', '0') === '1' && brand('tiktok_pixel_id', '') !== '';
$ttId     = brand('tiktok_pixel_id', '');
// KVKK/GDPR: Meta & TikTok yalnızca çerez onayı verildiyse ('all') yüklenir (bu pikseller Consent Mode desteklemez).
// Google (GA4/Ads/GTM) ise Consent Mode v2 ile DAİMA site.php <head>'te TEK gtag.js olarak yüklenir → burada tekrar YOK.
$mkConsent = (($_COOKIE['ozy_consent'] ?? '') === 'all');

// Dönüşüm olayı (önceki istekte flash'lanmış): ['event'=>, 'event_id'=>, 'params'=>[]]
$mkEvent = $data['_flash']['mk_event'] ?? null;
if (!is_array($mkEvent) || empty($mkEvent['event'])) { $mkEvent = null; }

if (($mkConsent && ($mkMeta || $mkTt)) || $mkEvent):
?>
<!-- ═══ OzyConnect — Reklam Pikselleri ═══ -->
<?php if ($mkConsent && $mkMeta): ?>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', <?= $js($metaId) ?>);fbq('track','PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?= $_e(rawurlencode($metaId)) ?>&ev=PageView&noscript=1" alt=""></noscript>
<?php endif; ?>
<?php if ($mkConsent && $mkTt): ?>
<script>
!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=d.createElement("script");o.type="text/javascript";o.async=!0;o.src=i+"?sdkid="+e+"&lib="+t;var a=d.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
ttq.load(<?= $js($ttId) ?>);ttq.page();}(window,document,'ttq');
</script>
<?php endif; ?>
<script>
/* Tüm etkin pikseller için ortak dönüşüm tetikleyici (aynı event_id → platform dedup).
   gtag (Google) site.php <head>'te tanımlı; fbq/ttq yukarıda (onayla). Hepsi kuyruk desenli → güvenli. */
window.ozyTrack=function(name,params,eventId){
  params=params||{};
  try{if(window.fbq){fbq('track',name,params,eventId?{eventID:eventId}:undefined);}}catch(e){}
  try{if(window.gtag){var g={Lead:'generate_lead',Contact:'contact',CompleteRegistration:'sign_up',Purchase:'purchase',PageView:'page_view'};gtag('event',g[name]||name,params);}}catch(e){}
  try{if(window.ttq){var t={Lead:'SubmitForm',Contact:'Contact',CompleteRegistration:'CompleteRegistration',Purchase:'CompletePayment',PageView:'Pageview'};ttq.track(t[name]||name,params);}}catch(e){}
};
<?php if ($mkEvent): ?>
document.addEventListener('DOMContentLoaded',function(){window.ozyTrack(<?= $js((string) $mkEvent['event']) ?>, <?= json_encode((object) ($mkEvent['params'] ?? []), JSON_UNESCAPED_UNICODE) ?>, <?= $js((string) ($mkEvent['event_id'] ?? '')) ?>);});
<?php endif; ?>
</script>
<?php endif; ?>
