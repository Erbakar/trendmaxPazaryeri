<?php
/** SSS oluştur/düzenle — Modernize (Bootstrap). Global faqs. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$faq = $faq ?? null;
$isEdit = $faq !== null;
$formAction = $isEdit ? url('/admin/faqs/' . (int) $faq['id']) : url('/admin/faqs');
$v = fn(string $k, $d = '') => $e($faq[$k] ?? $d);
$isActive = $isEdit ? ((int) ($faq['is_active'] ?? 0) === 1) : true;
boss_page_header($isEdit ? 'SSS Düzenle' : 'Yeni SSS', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'SSS', 'u' => '/admin/faqs'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<form method="post" action="<?= $formAction ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Soru &amp; Cevap</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="c6"><label class="form-label">Kategori <span class="text-muted">(boş = genel)</span></label><input type="text" name="category" value="<?= $v('category', 'genel') ?>" placeholder="genel" class="form-control"></div>
        <div class="c6"><label class="form-label">Sıra</label><input type="number" min="0" name="sort_order" value="<?= $v('sort_order', '0') ?>" class="form-control"></div>
        <div class="full"><label class="form-label">Soru *</label><input type="text" name="question" value="<?= $v('question') ?>" required maxlength="255" class="form-control"></div>
        <div class="full"><label class="form-label">Cevap *</label><textarea name="answer" rows="6" class="form-control summernote"><?= $v('answer') ?></textarea></div>
        <div class="full"><?= boss_switch('is_active', $isActive, ['id' => 'act', 'title' => 'Sitede görünür']) ?></div>
      </div>
    </div>
  </div>
  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/faqs') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
  </div>
</form>
