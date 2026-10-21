<?php
/** Site Modülleri listesi — Modernize (Bootstrap). GLOBAL site_modules. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$modules = $modules ?? [];
$ic = fn($s) => (string) preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $s)) ?: 'box';
$cl = function ($s) { $s = trim((string) $s); return preg_match('/^#[0-9a-fA-F]{6}$/', $s) ? $s : '#059669'; };
$action = '<a href="' . $e(url('/admin/site-modules/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni Modül</a>';
boss_page_header('Site Modülleri', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Modüller']], $action);
?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Modül</th><th>İkon</th><th>Renk</th><th class="text-center">Sıra</th><th class="text-center">Durum</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($modules)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">Henüz modül yok. "Yeni Modül" ile başlayın.</td></tr>
        <?php else: foreach ($modules as $m):
            $active = (int) ($m['is_active'] ?? 0) === 1;
            $icon = $ic($m['icon'] ?? '');
            $color = $cl($m['color'] ?? '');
            $desc = trim((string) ($m['description'] ?? ''));
        ?>
          <tr>
            <td class="ps-4">
              <div class="d-flex align-items-center gap-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:38px;height:38px;background:<?= $e($color) ?>1a;color:<?= $e($color) ?>"><i data-lucide="<?= $e($icon) ?>" style="width:18px;height:18px;"></i></span>
                <div class="min-w-0">
                  <div class="fw-semibold text-truncate"><?= $e($m['title'] ?? '') ?></div>
                  <?php if ($desc !== ''): ?><div class="fs-1 text-muted text-truncate" style="max-width:360px;"><?= $e($desc) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td><code class="fs-1"><?= $e($icon) ?></code></td>
            <td><span class="d-inline-block rounded border me-1 align-middle" style="width:16px;height:16px;background:<?= $e($color) ?>"></span><code class="fs-1 text-muted"><?= $e($color) ?></code></td>
            <td class="text-center text-muted"><?= (int) ($m['sort_order'] ?? 0) ?></td>
            <td class="text-center"><?= boss_status_badge($active ? 1 : 0) ?></td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/site-modules/' . (int) $m['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/site-modules/' . (int) $m['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Modül silinsin mi? (soft-delete)')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
