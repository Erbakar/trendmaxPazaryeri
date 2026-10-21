<?php
/** Ekran Görüntüleri listesi — Modernize (Bootstrap). GLOBAL site_screenshots. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$screenshots = $screenshots ?? [];
$slotMeta = [
    'hero'    => ['Hero', 'bg-primary-subtle text-primary'],
    'big'     => ['Öne Çıkan', 'bg-info-subtle text-info'],
    'gallery' => ['Galeri', 'bg-secondary-subtle text-secondary'],
];
$img = fn($p) => function_exists('asset') ? asset((string) $p) : '/' . ltrim((string) $p, '/');
$action = '<a href="' . $e(url('/admin/screenshots/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni Görsel</a>';
boss_page_header('Site Resimleri', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Site Resimleri']], $action);
?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Görsel</th><th>Slot</th><th>Başlık</th><th class="text-center">Sıra</th><th class="text-center">Aktif</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($screenshots)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">Henüz görsel yok. "Yeni Görsel" ile başlayın.</td></tr>
        <?php else: foreach ($screenshots as $s): [$sl, $sc] = $slotMeta[$s['slot']] ?? ['—', 'bg-secondary-subtle text-secondary']; $active = (int) ($s['is_active'] ?? 0) === 1; ?>
          <tr>
            <td class="ps-4">
              <div class="d-grid bg-light border rounded-2 overflow-hidden" style="width:120px;height:68px;place-items:center;">
                <img src="<?= $e($img($s['image_path'] ?? '')) ?>" alt="<?= $e($s['title'] ?? '') ?>" style="max-width:100%;max-height:100%;object-fit:contain;" onerror="this.style.display='none'">
              </div>
            </td>
            <td><span class="badge <?= $sc ?> rounded-pill"><?= $e($sl) ?></span></td>
            <td>
              <div class="fw-semibold"><?= $s['title'] ? $e($s['title']) : '<span class="text-muted fw-normal">—</span>' ?></div>
              <?php if (!empty($s['caption'])): ?><div class="fs-1 text-muted"><?= $e($s['caption']) ?></div><?php endif; ?>
            </td>
            <td class="text-center"><?= (int) ($s['sort_order'] ?? 0) ?></td>
            <td class="text-center"><?php if ($active): ?><i class="ti ti-circle-check-filled text-success fs-5"></i><?php else: ?><i class="ti ti-circle text-muted fs-5"></i><?php endif; ?></td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/screenshots/' . (int) $s['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/screenshots/' . (int) $s['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Görsel silinsin mi? (soft-delete)')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
