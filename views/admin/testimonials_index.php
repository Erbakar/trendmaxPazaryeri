<?php
/** Referanslar listesi — Modernize (Bootstrap). Global testimonials. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$testimonials = $testimonials ?? [];
$action = '<a href="' . $e(url('/admin/testimonials/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni Referans</a>';
boss_page_header('Referanslar', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Referanslar']], $action);
?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Logo</th><th>Kişi / Firma</th><th>Görev</th><th class="text-center">Sıra</th><th class="text-center">Aktif</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($testimonials)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">Henüz referans yok. "Yeni Referans" ile başlayın.</td></tr>
        <?php else: foreach ($testimonials as $t):
            $active  = (int) ($t['is_active'] ?? 0) === 1;
            $logo    = (string) ($t['logo_url'] ?? '');
            $logoUrl = $logo !== '' ? (function_exists('asset') ? asset($logo) : '/' . ltrim($logo, '/')) : '';
            $person  = trim((string) ($t['person'] ?? ''));
            $company = trim((string) ($t['company'] ?? ''));
        ?>
          <tr>
            <td class="ps-4">
              <?php if ($logoUrl !== ''): ?>
                <img src="<?= $e($logoUrl) ?>" alt="<?= $e($company) ?>" style="height:32px;max-width:120px;object-fit:contain;" onerror="this.style.display='none'">
              <?php else: ?>
                <span class="d-inline-flex align-items-center justify-content-center rounded-2 bg-secondary-subtle text-muted" style="width:32px;height:32px;"><i class="ti ti-photo"></i></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($person !== ''): ?>
                <div class="fw-semibold"><?= $e($person) ?></div><div class="fs-1 text-muted"><?= $e($company) ?></div>
              <?php else: ?>
                <div class="fw-semibold"><?= $e($company) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= $t['role'] ? $e($t['role']) : '—' ?></td>
            <td class="text-center"><?= (int) ($t['sort_order'] ?? 0) ?></td>
            <td class="text-center"><?= boss_status_badge($active ? 1 : 0) ?></td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/testimonials/' . (int) $t['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/testimonials/' . (int) $t['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Referans silinsin mi? (soft-delete)')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
