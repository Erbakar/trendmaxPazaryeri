<?php
/** Faz 8 — Merchant PayTR Ödeme (main layout). subscription_invoices = SaaS (GİB DEĞİL). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$token = $token ?? '';
$amount = $amount ?? 0;
$fmtP = fn($v) => '₺' . number_format((float) $v, ((float) $v == (int) (float) $v) ? 0 : 2, ',', '.');
?>
<div class="ui-page-head">
  <div>
    <h1><?= $e($fmtP($amount)) ?> tutarında ödeme</h1>
    <p>Ödemenizi güvenli PayTR sayfasında tamamlayın.</p>
  </div>
  <a href="<?= url('/subscription') ?>" class="ui-btn ui-btn-outline">
    <i data-lucide="arrow-left"></i> Aboneliğe Dön
  </a>
</div>

<div class="rounded-2xl bg-warning/10 border border-warning/30 p-4 mb-5 flex items-start gap-3">
  <span class="grid place-items-center w-9 h-9 rounded-xl bg-warning/20 text-warning shrink-0"><i data-lucide="info" class="w-[18px] h-[18px]"></i></span>
  <div>
    <p class="text-[14px] font-semibold text-slate-700 dark:text-slate-100">Ödeme sonrası bu sayfayı kapatmayın</p>
    <p class="mt-0.5 text-[13px] text-slate-500">Ödemeniz onaylanana ve abonelik sayfasına yönlendirilene kadar lütfen bekleyin.</p>
  </div>
</div>

<section class="rounded-2xl bg-surface shadow-card p-4 sm:p-6">
  <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
  <iframe src="https://www.paytr.com/odeme/guvenli/<?= $e($token) ?>" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
  <script>iFrameResize({}, '#paytriframe');</script>
</section>
