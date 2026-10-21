<?php
/** Blog listesi — Modernize (Bootstrap). Global blog_posts. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$posts = $posts ?? [];
$fmtDate = fn($d) => $d ? date('d.m.Y', strtotime((string) $d)) : '—';
$action = '<a href="' . $e(url('/admin/blog/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni Yazı</a>';
boss_page_header('Blog', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Blog']], $action);
?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Yazı</th><th class="text-center">Durum</th><th>Yayın Tarihi</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (empty($posts)): ?>
          <tr><td colspan="4" class="text-center text-muted py-5">Henüz yazı yok. "Yeni Yazı" ile başlayın.</td></tr>
        <?php else: foreach ($posts as $p): $published = ($p['status'] ?? 'draft') === 'published'; ?>
          <tr>
            <td class="ps-4">
              <div class="d-flex align-items-center gap-3">
                <?php if (!empty($p['cover_url'])): ?>
                  <img src="<?= $e($p['cover_url']) ?>" alt="" style="width:56px;height:40px;object-fit:cover;border-radius:8px;">
                <?php else: ?>
                  <span class="d-inline-flex align-items-center justify-content-center rounded-2 bg-secondary-subtle text-muted" style="width:56px;height:40px;"><i class="ti ti-photo"></i></span>
                <?php endif; ?>
                <div class="min-w-0">
                  <div class="fw-semibold text-truncate" style="max-width:420px;"><?= $e($p['title'] ?? '') ?></div>
                  <div class="fs-1 text-muted text-truncate" style="max-width:420px;">/<?= $e($p['slug'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td class="text-center"><span class="badge <?= $published ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?> rounded-pill"><?= $published ? 'Yayında' : 'Taslak' ?></span></td>
            <td class="text-muted"><?= $e($fmtDate($p['published_at'] ?? '')) ?></td>
            <td class="text-end pe-4">
              <a href="<?= url('/admin/blog/' . (int) $p['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/blog/' . (int) $p['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Yazı silinsin mi? (soft-delete)')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
