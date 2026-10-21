<?php
/** Admin — changelog yönetimi (yayınla/düzenle/taslak/sil). Form üstte tam genişlik (editör için), liste altta. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$updates  = $updates ?? [];
$editItem = $editItem ?? null;
$typeLabels = ['feature' => 'Yeni Özellik', 'improvement' => 'İyileştirme', 'fix' => 'Düzeltme', 'announcement' => 'Duyuru'];
$typeCls = ['feature' => 'bg-primary-subtle text-primary', 'improvement' => 'bg-success-subtle text-success', 'fix' => 'bg-warning-subtle text-warning', 'announcement' => 'bg-secondary-subtle text-secondary'];
$fmt = fn($s) => $s ? date('d.m.Y H:i', strtotime((string) $s)) : '—';
$isEdit = $editItem !== null;
$formAction = $isEdit ? url('/admin/updates/' . (int) $editItem['id']) : url('/admin/updates');
boss_page_header('Güncellemeler', [['l' => 'Bakım', 'u' => '/admin/updates'], ['l' => 'Güncellemeler']]);
?>
<!-- Form: tam genişlik (Summernote araç çubuğu tek satıra sığsın) -->
<div class="card mb-3">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h3 class="card-title mb-0"><i class="ti ti-<?= $isEdit ? 'pencil' : 'plus' ?> me-1 text-primary"></i><?= $isEdit ? 'Güncelleme Düzenle #' . (int) $editItem['id'] : 'Yeni Güncelleme' ?></h3>
    <?php if ($isEdit): ?><a href="<?= url('/admin/updates') ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-plus me-1"></i>Yeni</a><?php endif; ?>
  </div>
  <div class="card-body">
    <form method="POST" action="<?= $formAction ?>">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <div class="row g-3">
        <div class="col-12 col-md-6"><label class="form-label">Başlık *</label><input type="text" name="title" maxlength="200" value="<?= $e($editItem['title'] ?? '') ?>" required class="form-control"></div>
        <div class="col-6 col-md-3"><label class="form-label">Versiyon</label><input type="text" name="version" maxlength="40" placeholder="1.1.0" value="<?= $e($editItem['version'] ?? '') ?>" class="form-control"></div>
        <div class="col-6 col-md-3"><label class="form-label">Tür</label>
          <select name="type" class="form-select">
            <?php foreach ($typeLabels as $k => $l): ?><option value="<?= $k ?>" <?= ($editItem['type'] ?? 'announcement') === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">İçerik (changelog)</label>
          <textarea name="body" rows="6" placeholder="Neler değişti? Satır satır yazabilirsiniz." class="form-control summernote"><?= $e($editItem['body'] ?? '') ?></textarea>
        </div>
        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div><?php if (!$isEdit): ?><?= boss_switch('is_published', true, ['id' => 'isPub', 'title' => 'Yayın durumu', 'on' => 'Yayında', 'off' => 'Taslak']) ?><?php endif; ?></div>
          <div class="d-flex align-items-center gap-2">
            <?php if ($isEdit): ?><a href="<?= url('/admin/updates') ?>" class="btn btn-outline-secondary">Vazgeç</a><?php endif; ?>
            <button type="submit" class="btn btn-primary"><i class="ti ti-<?= $isEdit ? 'device-floppy' : 'plus' ?> me-1"></i><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Liste -->
<div class="card mb-0">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-list me-1 text-muted"></i>Yayınlar</h3></div>
  <?php if (empty($updates)): ?>
    <div class="card-body text-center py-5 text-muted">Henüz güncelleme yok.</div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="updatesTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">#</th><th>Tür</th><th>Versiyon</th><th>Başlık</th><th>Durum</th><th>Tarih</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($updates as $u): $pub = (int) $u['is_published'] === 1; $uid = (int) $u['id']; ?>
        <tr>
          <td class="ps-4 text-muted">#<?= $uid ?></td>
          <td><span class="badge <?= $typeCls[$u['type']] ?? 'bg-secondary-subtle text-secondary' ?> rounded-pill"><?= $e($typeLabels[$u['type']] ?? $u['type']) ?></span></td>
          <td class="text-muted"><?= !empty($u['version']) ? 'v' . $e($u['version']) : '—' ?></td>
          <td class="fw-medium"><?= $e($u['title']) ?></td>
          <td><span class="badge <?= $pub ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> rounded-pill"><?= $pub ? 'Yayında' : 'Taslak' ?></span></td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($u['published_at'] ?? $u['created_at'] ?? '')) ?>"><?= $e($fmt($u['published_at'] ?? $u['created_at'])) ?></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-2 justify-content-end">
              <a href="<?= url('/admin/updates?edit=' . $uid) ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="POST" action="<?= url('/admin/updates/' . $uid . '/toggle') ?>" class="d-inline"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-secondary border-0 px-2" title="<?= $pub ? 'Taslağa al' : 'Yayınla' ?>"><i class="ti ti-<?= $pub ? 'eye-off' : 'send' ?>"></i></button></form>
              <form method="POST" action="<?= url('/admin/updates/' . $uid . '/delete') ?>" class="d-inline" onsubmit="return confirm('Silinsin mi?')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>
