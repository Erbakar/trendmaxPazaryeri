<?php
/** Blog yazısı oluştur/düzenle — Modernize (Bootstrap). Global blog_posts. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$post = $post ?? null;
$statuses = $statuses ?? ['draft' => 'Taslak', 'published' => 'Yayında'];
$isEdit = $post !== null;
$formAction = $isEdit ? url('/admin/blog/' . (int) $post['id']) : url('/admin/blog');
$v = fn(string $k, $d = '') => $e($post[$k] ?? $d);
$curStatus = $isEdit ? ($post['status'] ?? 'draft') : 'draft';
$pubDate = ($isEdit && !empty($post['published_at'])) ? date('Y-m-d', strtotime((string) $post['published_at'])) : '';
boss_page_header($isEdit ? 'Yazı Düzenle' : 'Yeni Yazı', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Blog', 'u' => '/admin/blog'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<form method="post" action="<?= $formAction ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title">İçerik</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="full"><label class="form-label">Başlık *</label><input type="text" name="title" value="<?= $v('title') ?>" required maxlength="200" class="form-control"></div>
        <div class="full"><label class="form-label">Slug <span class="text-muted">(boş = başlıktan üretilir)</span></label><input type="text" name="slug" value="<?= $v('slug') ?>" maxlength="180" placeholder="ornek-yazi-basligi" class="form-control"></div>
        <div class="full"><label class="form-label">Özet</label><textarea name="excerpt" rows="2" maxlength="400" class="form-control"><?= $v('excerpt') ?></textarea></div>
        <div class="full"><label class="form-label">İçerik</label><textarea name="body" rows="14" class="form-control summernote"><?= $v('body') ?></textarea></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Yayın &amp; Kapak</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div>
          <label class="form-label">Durum</label>
          <select name="status" class="form-select">
            <?php foreach ($statuses as $key => $label): ?><option value="<?= $e($key) ?>" <?= $curStatus === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label">Yayın Tarihi <span class="text-muted">(boş = şimdi)</span></label><input type="date" name="published_at" value="<?= $e($pubDate) ?>" class="form-control"></div>
        <div><label class="form-label">Yazar</label><input type="text" name="author" value="<?= $v('author') ?>" maxlength="120" class="form-control"></div>
        <div class="full"><label class="form-label mb-2">Kapak Görseli</label>
          <?= boss_image_upload('cover_file', (string) ($post['cover_url'] ?? '')) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/blog') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
  </div>
</form>
