<?php
/** Paketler listesi — Modernize (Bootstrap) card+table. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$fmt = fn($v) => '₺' . number_format((float) $v, ((float) $v == (int) (float) $v) ? 0 : 2, ',', '.');
$statusMeta = [
    'active'   => ['Aktif',  'bg-success-subtle text-success'],
    'draft'    => ['Taslak', 'bg-warning-subtle text-warning'],
    'archived' => ['Arşiv',  'bg-secondary-subtle text-secondary'],
];
$action = '<a href="' . $e(url('/admin/packages/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni Paket</a>';
boss_page_header('Paketler', [['l' => 'Siparişler', 'u' => '/admin/packages'], ['l' => 'Paketler']], $action);
?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr>
            <th class="ps-4">Paket</th><th>Aylık</th><th>Yıllık</th><th class="text-center">Deneme</th>
            <th>Durum</th><th class="text-center">Aktif</th><th class="text-end pe-4">İşlem</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($packages)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">Henüz paket yok. "Yeni Paket" ile başlayın.</td></tr>
        <?php else: foreach ($packages as $p): [$sl, $sc] = $statusMeta[$p['status']] ?? ['—', 'bg-secondary-subtle text-secondary']; $internal = (int) ($p['is_internal'] ?? 0) === 1; $on = (int) ($p['is_active'] ?? 0) === 1; ?>
          <tr>
            <td class="ps-4">
              <div class="fw-semibold d-flex align-items-center gap-2"><?= $e($p['name']) ?><?php if ($internal): ?><span class="badge bg-secondary-subtle text-secondary">INTERNAL</span><?php endif; ?></div>
              <div class="fs-1 text-muted font-monospace"><?= $e($p['slug']) ?></div>
            </td>
            <td class="fw-medium"><?= $e($fmt($p['price_monthly'] ?? 0)) ?></td>
            <td class="text-muted"><?= $e($fmt($p['price_yearly'] ?? 0)) ?></td>
            <td class="text-center"><?= (int) ($p['trial_days'] ?? 0) ?> gün</td>
            <td><span class="badge <?= $sc ?> rounded-pill"><?= $e($sl) ?></span></td>
            <td class="text-center">
              <form method="post" action="<?= url('/admin/packages/' . (int) $p['id'] . '/toggle') ?>" class="d-inline"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                <button type="submit" class="btn btn-link p-0 <?= $on ? 'text-success' : 'text-muted' ?>" title="<?= $on ? 'Pasifleştir' : 'Aktifleştir' ?>"><i class="ti ti-<?= $on ? 'toggle-right' : 'toggle-left' ?> fs-6"></i></button>
              </form>
            </td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/packages/' . (int) $p['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/packages/' . (int) $p['id'] . '/copy') ?>" class="d-inline"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Kopyala"><i class="ti ti-copy"></i></button></form>
              <?php if (!$internal): ?>
              <form method="post" action="<?= url('/admin/packages/' . (int) $p['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Paket silinsin mi? (soft-delete)')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
