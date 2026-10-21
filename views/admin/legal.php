<?php
/** Yasal Metinler — Gizlilik(KVKK) / Kullanım Koşulları / Çerez Politikası. Boş alan → sitede gömülü varsayılan metin. group='legal'. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = $values ?? [];
boss_page_header('Yasal Metinler', [['l' => 'Yasal Metinler']]);
$fields = [
    ['legal_privacy', 'Gizlilik Politikası (KVKK Aydınlatma)', '/gizlilik',           'shield-check'],
    ['legal_terms',   'Kullanım Koşulları',                    '/kullanim-kosullari', 'file-text'],
    ['legal_cookies', 'Çerez Politikası',                      '/cerez-politikasi',   'cookie'],
];
?>
<p class="text-muted fs-2 mb-3">Bu metinler kurumsal sitedeki yasal sayfalarda yayınlanır. <b>Boş bırakılan</b> alan için sistemdeki <b>varsayılan</b> metin gösterilir. Sayfa başlığı ve "son güncelleme" satırı otomatik eklenir — siz yalnız gövde içeriğini yazın. Biçimlendirme (başlık, kalın, liste, bağlantı) desteklenir.</p>

<form method="post" action="<?= url('/admin/legal') ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
  <?php foreach ($fields as [$key, $label, $path, $icon]): ?>
  <div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h3 class="card-title"><i class="ti ti-<?= $e($icon) ?> me-1 text-primary"></i><?= $e($label) ?></h3>
      <a href="<?= url($path) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="ti ti-external-link me-1"></i>Sayfayı Aç</a>
    </div>
    <div class="card-body">
      <textarea name="s[<?= $e($key) ?>]" class="form-control summernote" placeholder="Boş bırakılırsa varsayılan metin gösterilir."><?= $e($v[$key] ?? '') ?></textarea>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="mt-2 mb-4">
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
  </div>
</form>
