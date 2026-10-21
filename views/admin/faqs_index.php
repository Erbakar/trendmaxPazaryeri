<?php
/** SSS listesi — Modernize (Bootstrap). Global faqs. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$faqs = $faqs ?? [];
$action = '<a href="' . $e(url('/admin/faqs/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni SSS</a>';
boss_page_header('SSS', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'SSS']], $action);
?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Soru</th><th>Kategori</th><th class="text-center">Sıra</th><th class="text-center">Aktif</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($faqs)): ?>
          <tr><td colspan="5" class="text-center text-muted py-5">Henüz SSS yok. "Yeni SSS" ile başlayın.</td></tr>
        <?php else: foreach ($faqs as $f): $active = (int) ($f['is_active'] ?? 0) === 1; ?>
          <tr>
            <td class="ps-4"><div class="fw-semibold text-wrap" style="max-width:560px;"><?= $e($f['question']) ?></div></td>
            <td><span class="badge bg-primary-subtle text-primary rounded-pill"><?= $e($f['category'] ?? 'genel') ?></span></td>
            <td class="text-center"><?= (int) ($f['sort_order'] ?? 0) ?></td>
            <td class="text-center"><?= boss_status_badge($active ? 1 : 0) ?></td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/faqs/' . (int) $f['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/faqs/' . (int) $f['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('SSS kaydı silinsin mi? (soft-delete)')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
