<?php
/** Paket geçmişi — Modernize (Bootstrap). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rows = $rows ?? [];
$sub = $sub ?? false;
$storeId = (int) ($storeId ?? 0);
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$actMeta = ['provision' => 'Provizyon', 'upgrade' => 'Yükseltme', 'downgrade' => 'Düşürme', 'renew' => 'Yenileme', 'suspend' => 'Askıya alma', 'cancel' => 'İptal', 'reactivate' => 'Yeniden etkinleştirme', 'expire' => 'Süre dolumu'];
boss_page_header('Paket Geçmişi', [['l' => 'Siparişler', 'u' => '/admin/packages'], ['l' => 'Abonelikler', 'u' => '/admin/subscriptions'], ['l' => 'store#' . $storeId . ' geçmiş']]);
?>
<?php if ($sub !== false): ?>
<p class="text-muted fs-2 mb-3">Güncel: <span class="fw-semibold text-body"><?= $e($sub['package_name'] ?? '—') ?></span> · <?= $e($sub['status']) ?></p>
<?php endif; ?>
<div class="card">
  <?php if (empty($rows)): ?>
    <div class="text-center text-muted py-5">Geçmiş kaydı yok.</div>
  <?php else: ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Tarih</th><th>İşlem</th><th>Paket</th><th>Durum</th><th class="pe-4">Sebep</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="ps-4 text-muted"><?= $e($fmtDT($r['created_at'] ?? null)) ?></td>
            <td><span class="badge bg-primary-subtle text-primary rounded-pill"><?= $e($actMeta[$r['action']] ?? $r['action']) ?></span></td>
            <td><?= $e(($r['old_package'] ?? '') !== '' && ($r['old_package'] ?? null) !== ($r['new_package'] ?? null) ? (($r['old_package'] ?? '—') . ' → ' . ($r['new_package'] ?? '—')) : ($r['new_package'] ?? '—')) ?></td>
            <td class="text-muted"><?= $e(trim((string) ($r['old_status'] ?? '') . (($r['old_status'] ?? '') ? ' → ' : '') . (string) ($r['new_status'] ?? ''))) ?: '—' ?></td>
            <td class="pe-4 text-muted"><?= $e($r['reason'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
