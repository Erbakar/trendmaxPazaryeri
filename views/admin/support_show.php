<?php
/** Admin — destek talebi detayı: sohbet (chat) görünümü + temiz yanıt kutusu + durum. Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$ticket   = $ticket ?? [];
$messages = $messages ?? [];
$stMeta = [
    'open'     => ['Açık', 'bg-warning-subtle text-warning'],
    'answered' => ['Yanıtlandı', 'bg-success-subtle text-success'],
    'closed'   => ['Kapalı', 'bg-secondary-subtle text-secondary'],
];
$prMap = ['low' => 'Düşük', 'normal' => 'Normal', 'high' => 'Yüksek', 'critical' => 'Kritik'];
[$stLbl, $stCls] = $stMeta[$ticket['status'] ?? 'open'] ?? $stMeta['open'];
$fmt = fn($s) => $s ? date('d.m.Y H:i', strtotime((string) $s)) : '';
$tid = (int) ($ticket['id'] ?? 0);
$status = (string) ($ticket['status'] ?? 'open');
$storeName = (string) ($ticket['store_name'] ?? ('Mağaza #' . ($ticket['store_id'] ?? '?')));
$initOf = fn(string $s) => mb_strtoupper(mb_substr(trim($s) !== '' ? $s : '?', 0, 1, 'UTF-8'), 'UTF-8');
boss_page_header('Destek Talebi #' . $tid, [['l' => 'İletişim Merkezi', 'u' => '/admin/support'], ['l' => 'Destek Talepleri', 'u' => '/admin/support'], ['l' => '#' . $tid]]);
?>
<style>
.tk-wrap{ width:100%; }
.tk-thread{ display:flex; flex-direction:column; gap:20px; padding:26px 28px; background:var(--ad-surface-2); max-height:62vh; overflow-y:auto; }
.tk-msg{ display:flex; gap:12px; max-width:72%; }
.tk-msg.admin{ align-self:flex-end; flex-direction:row-reverse; }
.tk-msg.merchant{ align-self:flex-start; }
.tk-ava{ flex-shrink:0; width:40px; height:40px; border-radius:50%; display:grid; place-items:center; font-weight:700; font-size:15px; }
.tk-msg.merchant .tk-ava{ background:var(--ad-surface-3); color:var(--ad-accent); }
.tk-msg.admin .tk-ava{ background:var(--ad-accent); color:#fff; }
.tk-meta{ font-size:12.5px; color:var(--ad-muted); margin-bottom:5px; font-weight:500; }
.tk-msg.admin .tk-meta{ text-align:right; }
.tk-bubble{ padding:13px 17px; border-radius:12px; white-space:pre-wrap; word-break:break-word; font-size:15px; line-height:1.6; }
.tk-msg.merchant .tk-bubble{ background:var(--ad-surface); border:1px solid var(--ad-border); border-top-left-radius:5px; color:var(--ad-text); }
.tk-msg.admin .tk-bubble{ background:var(--ad-accent); color:#fff; border-top-right-radius:5px; }
.tk-empty{ text-align:center; color:var(--ad-muted); padding:36px 0; }
#tkReply textarea{ font-size:15px; line-height:1.6; min-height:96px; }
.tk-wrap .card-title{ font-size:19px; }
</style>

<div class="tk-wrap">
  <div class="card mb-0">
    <!-- Başlık -->
    <div class="card-header d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div class="min-w-0">
        <h3 class="card-title mb-1"><?= $e($ticket['subject'] ?? '') ?></h3>
        <div class="d-flex flex-wrap align-items-center gap-3 fs-1 text-muted">
          <span class="font-monospace">#<?= $tid ?></span>
          <span><i class="ti ti-building-store me-1"></i><b class="text-body"><?= $e($storeName) ?></b></span>
          <span><i class="ti ti-flag me-1"></i><?= $e($prMap[$ticket['priority'] ?? 'normal'] ?? 'Normal') ?> öncelik</span>
          <span><i class="ti ti-calendar me-1"></i><?= $e($fmt($ticket['created_at'] ?? '')) ?></span>
        </div>
      </div>
      <span class="badge <?= $stCls ?> rounded-pill"><?= $e($stLbl) ?></span>
    </div>

    <!-- Sohbet -->
    <div class="tk-thread" id="tkThread">
      <?php if ($messages === []): ?>
        <div class="tk-empty"><i class="ti ti-message-off d-block mb-2" style="font-size:30px;opacity:.5;"></i>Henüz mesaj yok.</div>
      <?php else: foreach ($messages as $m): $isAdmin = ($m['sender_type'] ?? '') === 'admin';
        $who = $isAdmin ? ('Destek' . (!empty($m['sender_name']) ? ' · ' . $e($m['sender_name']) : '')) : ('Mağaza' . (!empty($m['sender_name']) ? ' · ' . $e($m['sender_name']) : ''));
        $ava = $isAdmin ? 'S' : $initOf($storeName);
      ?>
      <div class="tk-msg <?= $isAdmin ? 'admin' : 'merchant' ?>">
        <div class="tk-ava"><?= $e($ava) ?></div>
        <div class="min-w-0">
          <div class="tk-meta"><?= $who ?> · <?= $e($fmt($m['created_at'] ?? '')) ?></div>
          <div class="tk-bubble"><?= $e($m['body'] ?? '') ?></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Yanıt kutusu -->
    <div class="card-body border-top">
      <form id="tkReply" method="POST" action="<?= url('/admin/support/' . $tid . '/reply') ?>">
        <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
        <textarea name="body" placeholder="Yanıtınızı yazın…" required rows="3" class="form-control" style="resize:vertical;"></textarea>
      </form>
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
        <form method="POST" action="<?= url('/admin/support/' . $tid . '/status') ?>" class="d-flex align-items-center gap-2">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <label class="form-label mb-0 fs-2 text-muted">Durum</label>
          <select name="status" class="form-select form-select-sm" style="width:auto;">
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Açık</option>
            <option value="answered" <?= $status === 'answered' ? 'selected' : '' ?>>Yanıtlandı</option>
            <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Kapalı</option>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-refresh me-1"></i>Güncelle</button>
        </form>
        <button type="submit" form="tkReply" class="btn btn-primary"><i class="ti ti-send me-1"></i>Yanıtla</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // En son mesaja kaydır
  var t = document.getElementById('tkThread');
  if (t) { t.scrollTop = t.scrollHeight; }
})();
</script>
