<?php
/** Ekran Görüntüsü oluştur/düzenle — Modernize (Bootstrap). GLOBAL site_screenshots. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$shot = $shot ?? null;
$slots = $slots ?? ['hero' => 'Hero (üst slider)', 'big' => 'Öne Çıkan (tek)', 'gallery' => 'Galeri'];
$isEdit = $shot !== null;
$formAction = $isEdit ? url('/admin/screenshots/' . (int) $shot['id']) : url('/admin/screenshots');
$v = fn(string $k, $d = '') => $e($shot[$k] ?? $d);
$cur = (string) ($shot['image_path'] ?? '');
$curUrl = $cur !== '' ? (function_exists('asset') ? asset($cur) : '/' . ltrim($cur, '/')) : '';
boss_page_header($isEdit ? 'Site Resmi Düzenle' : 'Yeni Site Resmi', [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Site Resimleri', 'u' => '/admin/screenshots'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<form method="post" action="<?= $formAction ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title">Görsel</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="full">
          <div class="border rounded-3 p-3">
            <label class="form-label">Ekran Görüntüsü <span class="text-muted">(PNG/JPG/WEBP/SVG · en fazla 5 MB<?= $isEdit ? ' · boş = mevcut korunur' : '' ?>)</span></label>
            <?= boss_image_upload('image_file', (string) $curUrl, ['required' => !$isEdit]) ?>
          </div>
        </div>
        <div>
          <label class="form-label">Slot *</label>
          <select name="slot" class="form-select">
            <?php foreach ($slots as $sk => $slabel): ?><option value="<?= $e($sk) ?>" <?= (($shot['slot'] ?? 'gallery') === $sk) ? 'selected' : '' ?>><?= $e($slabel) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="c3"><label class="form-label">Sıra</label><input type="number" min="0" name="sort_order" value="<?= $v('sort_order', '0') ?>" class="form-control"></div>
        <div><label class="form-label">Başlık</label><input type="text" name="title" value="<?= $v('title') ?>" maxlength="120" class="form-control"></div>
        <div class="full"><label class="form-label">Alt Yazı <span class="text-muted">(caption)</span></label><input type="text" name="caption" value="<?= $v('caption') ?>" maxlength="200" class="form-control"></div>
        <div class="full"><?= boss_switch('is_active', (!$isEdit || (int) ($shot['is_active'] ?? 1) === 1), ['id' => 'act', 'title' => 'Sitede görünür']) ?></div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/screenshots') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
  </div>
</form>
