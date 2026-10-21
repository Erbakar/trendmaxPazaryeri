<?php
/** Mail şablonları listesi — Modernize (Bootstrap). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$templates = $templates ?? [];
$labels = $labels ?? [];
boss_page_header('Mail Şablonları', [['l' => 'İletişim Merkezi', 'u' => '/admin/support'], ['l' => 'Mail Şablonları']]);
?>
<p class="text-muted fs-2 mb-3">Sistem e-postalarının konu ve gövdesi. <code>{degisken}</code> alanları gönderimde doldurulur.</p>
<div class="card mb-0">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Şablon</th><th>Anahtar</th><th>Konu</th><th>Durum</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($templates)): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">Şablon yok.</td></tr>
        <?php else: foreach ($templates as $t): $active = (int) ($t['is_active'] ?? 0) === 1; ?>
          <tr>
            <td class="ps-4 fw-semibold"><?= $e($labels[$t['key']] ?? $t['key']) ?></td>
            <td><code class="fs-1"><?= $e($t['key']) ?></code></td>
            <td class="text-muted text-truncate" style="max-width:280px;"><?= $e($t['subject']) ?></td>
            <td><?= boss_status_badge($active ? 1 : 0) ?></td>
            <td class="text-end pe-4"><a href="<?= url('/admin/mail-templates/' . rawurlencode((string) $t['key'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-pencil me-1"></i>Düzenle</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
