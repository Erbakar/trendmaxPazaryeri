<?php
/** Referans oluştur/düzenle — yeni tasarım: card-header + .ad-form-grid. Global testimonials. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$testimonial = $testimonial ?? null;
$isEdit = $testimonial !== null;
$formAction = $isEdit ? url('/admin/testimonials/' . (int) $testimonial['id']) : url('/admin/testimonials');
$v = fn(string $k, $d = '') => $e($testimonial[$k] ?? $d);
$isActive = $isEdit ? ((int) ($testimonial['is_active'] ?? 0) === 1) : true;
$cur = (string) ($testimonial['logo_url'] ?? '');
$curUrl = $cur !== '' ? (function_exists('asset') ? asset($cur) : '/' . ltrim($cur, '/')) : '';
boss_page_header($isEdit ? 'Referans Düzenle' : 'Yeni Referans', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Referanslar', 'u' => '/admin/testimonials'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<form method="post" action="<?= $formAction ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title">Referans Bilgileri</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div><label class="form-label">Firma *</label><input type="text" name="company" value="<?= $v('company') ?>" required maxlength="150" class="form-control"></div>
        <div><label class="form-label">Kişi</label><input type="text" name="person" value="<?= $v('person') ?>" maxlength="120" class="form-control"></div>
        <div><label class="form-label">Görev <span class="text-muted">(role)</span></label><input type="text" name="role" value="<?= $v('role') ?>" maxlength="120" class="form-control"></div>
        <div class="c3"><label class="form-label">Sıra</label><input type="number" min="0" name="sort_order" value="<?= $v('sort_order', '0') ?>" class="form-control"></div>
        <div class="full"><label class="form-label">Referans Metni (alıntı) *</label><textarea name="quote" rows="5" class="form-control summernote"><?= $v('quote') ?></textarea></div>
        <div class="full">
          <div class="border rounded-3 p-3">
            <label class="form-label">Logo <span class="text-muted">(PNG/JPG/WEBP/SVG · en fazla 5 MB<?= $isEdit ? ' · boş = mevcut korunur' : '' ?>)</span></label>
            <?= boss_image_upload('logo_file', (string) $curUrl) ?>
          </div>
        </div>
        <div class="full"><?= boss_switch('is_active', $isActive, ['id' => 'act', 'title' => 'Sitede görünür']) ?></div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/testimonials') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
  </div>
</form>
