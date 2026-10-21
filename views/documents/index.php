<?php
/** Merchant — Belgeler & Doğrulama (KYC yükleme + durum). main layout (Tailwind/ozy.css). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$types   = $types ?? [];
$docs    = $docs ?? [];
$summary = $summary ?? ['verified' => false, 'required' => 0, 'approved_required' => 0, 'pending' => 0, 'rejected' => 0];
$csrf    = (string) ($data['_csrf'] ?? '');

// Tür başına en güncel belge (docs id DESC sıralı → ilk eşleşen en yeni).
$latestByType = [];
foreach ($docs as $d) {
    $tid = (int) ($d['doc_type_id'] ?? 0);
    if ($tid > 0 && !isset($latestByType[$tid])) { $latestByType[$tid] = $d; }
}
$stMeta = [
    'pending'  => ['Bekliyor', 'bg-amber-50 text-amber-700 border-amber-200', 'clock'],
    'approved' => ['Onaylandı', 'bg-emerald-50 text-emerald-700 border-emerald-200', 'check-circle-2'],
    'rejected' => ['Reddedildi', 'bg-rose-50 text-rose-700 border-rose-200', 'x-circle'],
];
$verified = !empty($summary['verified']);
?>
<div class="ui-page-head">
  <div>
  <h1>Belgeler & Doğrulama</h1>
  <p>Mağaza doğrulaması için gerekli resmi belgeleri güvenli şekilde yükleyin. Belgeler yalnızca yetkililer tarafından görüntülenir.</p>
  </div>
</div>

<!-- Doğrulama durumu -->
<div class="mb-6 rounded-2xl border <?= $verified ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' ?> p-4 flex items-start gap-3">
  <span class="grid place-items-center w-10 h-10 rounded-xl <?= $verified ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?> shrink-0">
    <i data-lucide="<?= $verified ? 'shield-check' : 'shield-alert' ?>" class="w-5 h-5"></i>
  </span>
  <div class="min-w-0">
    <div class="text-[14px] font-bold <?= $verified ? 'text-emerald-800' : 'text-amber-800' ?>">
      <?= $verified ? 'Mağazanız doğrulandı' : 'Doğrulama bekleniyor' ?>
    </div>
    <div class="text-[13px] <?= $verified ? 'text-emerald-700' : 'text-amber-700' ?> mt-0.5">
      <?php if ($verified): ?>
        Tüm zorunlu belgeleriniz onaylandı. Teşekkürler.
      <?php else: ?>
        <?= (int) $summary['approved_required'] ?>/<?= (int) $summary['required'] ?> zorunlu belge onaylandı.
        <?php if ((int) $summary['pending'] > 0): ?> · <?= (int) $summary['pending'] ?> belge incelemede.<?php endif; ?>
        <?php if ((int) $summary['rejected'] > 0): ?> · <span class="font-semibold">Reddedilen belgeleri yeniden yükleyin.</span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (empty($types)): ?>
  <div class="rounded-2xl bg-surface shadow-card p-8 text-center text-slate-400 text-[13.5px]">Şu an talep edilen belge yok.</div>
<?php else: ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <?php foreach ($types as $t): $tid = (int) $t['id']; $cur = $latestByType[$tid] ?? null;
    $req = (int) ($t['is_required'] ?? 0) === 1;
    $accept  = \App\Services\StoreDocumentService::acceptAttr($t['allowed_types'] ?? '');
    $fmtHint = implode(', ', \App\Services\StoreDocumentService::formatLabels($t['allowed_types'] ?? '')); ?>
  <div class="rounded-2xl bg-surface shadow-card border border-slate-200/70 dark:border-white/[.06] p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <h3 class="text-[15px] font-bold tracking-tight"><?= $e($t['label']) ?></h3>
          <?php if ($req): ?><span class="text-[10.5px] font-bold px-1.5 py-0.5 rounded-md bg-rose-50 text-rose-600 border border-rose-200">Zorunlu</span><?php endif; ?>
        </div>
        <?php if (!empty($t['description'])): ?><p class="mt-1 text-[12.5px] text-slate-500 leading-relaxed"><?= $e($t['description']) ?></p><?php endif; ?>
      </div>
      <?php if ($cur): [$sl, $scls, $sico] = $stMeta[$cur['status']] ?? ['—', 'bg-slate-50 text-slate-600 border-slate-200', 'file']; ?>
      <span class="shrink-0 inline-flex items-center gap-1.5 text-[11.5px] font-semibold px-2.5 py-1 rounded-full border <?= $scls ?>">
        <i data-lucide="<?= $sico ?>" class="w-3.5 h-3.5"></i><?= $e($sl) ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($cur): ?>
    <div class="mt-3 flex items-center gap-2 text-[12px] text-slate-500 bg-slate-50 dark:bg-white/[.03] rounded-xl px-3 py-2">
      <i data-lucide="paperclip" class="w-3.5 h-3.5 shrink-0"></i>
      <span class="truncate flex-1"><?= $e($cur['original_name'] ?? 'belge') ?></span>
      <a href="/documents/<?= (int) $cur['id'] ?>/download" class="t text-brand font-semibold hover:underline shrink-0">İndir</a>
    </div>
    <?php if (($cur['status'] ?? '') === 'rejected' && !empty($cur['note'])): ?>
    <div class="mt-2 text-[12px] text-rose-600"><i data-lucide="info" class="w-3.5 h-3.5 inline -mt-0.5"></i> Red nedeni: <?= $e($cur['note']) ?></div>
    <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="/documents/upload" enctype="multipart/form-data" class="mt-3 flex items-center gap-2">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="doc_type_id" value="<?= $tid ?>">
      <input type="file" name="document" required accept="<?= $e($accept) ?>"
             class="flex-1 min-w-0 text-[12.5px] text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[12px] file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 file:cursor-pointer">
      <button type="submit" class="t shrink-0 inline-flex items-center gap-1.5 h-9 px-3.5 rounded-xl bg-brand text-white text-[12.5px] font-semibold hover:brightness-110">
        <i data-lucide="upload" class="w-4 h-4"></i><?= $cur ? 'Yenile' : 'Yükle' ?>
      </button>
    </form>
    <p class="mt-2 text-[11px] text-slate-400"><?= $e($fmtHint) ?> · en fazla 8 MB</p>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
