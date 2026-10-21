<?php
/** Mail şablonu düzenleme — yeni tasarım: card-header + .ad-form-grid. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tpl = $tpl ?? [];
$label = $label ?? ($tpl['key'] ?? '');
boss_page_header((string) $label, [['l' => 'İletişim Merkezi', 'u' => '/admin/support'], ['l' => 'Mail Şablonları', 'u' => '/admin/mail-templates'], ['l' => (string) $label]]);
?>
<form method="post" action="<?= url('/admin/mail-templates/' . rawurlencode((string) ($tpl['key'] ?? ''))) ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Şablon İçeriği</h3></div>
    <div class="card-body">
      <?php if (!empty($tpl['vars_hint'])): ?>
      <div class="alert alert-info"><i class="ti ti-info-circle"></i><span>Kullanılabilir değişkenler: <span class="font-monospace fs-2"><?= $e($tpl['vars_hint']) ?></span></span></div>
      <?php endif; ?>
      <div class="ad-form-grid">
        <div class="full"><label class="form-label">Konu</label><input type="text" name="subject" value="<?= $e($tpl['subject'] ?? '') ?>" maxlength="255" required class="form-control"></div>
        <div class="full"><label class="form-label">Gövde</label><textarea name="body_html" rows="12" class="form-control summernote"><?= $e($tpl['body_html'] ?? '') ?></textarea></div>
      </div>
    </div>
  </div>
  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/mail-templates') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
  </div>
</form>
