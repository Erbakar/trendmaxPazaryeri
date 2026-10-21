<?php
/** Site Modülü oluştur/düzenle — Modernize (Bootstrap). GLOBAL site_modules. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$m = $module ?? null;
$isEdit = is_array($m) && isset($m['id']);
$icon = (string) preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($m['icon'] ?? 'box')));
if ($icon === '') { $icon = 'box'; }
$colorRaw = trim((string) ($m['color'] ?? '#2563eb'));
$color = preg_match('/^#[0-9a-fA-F]{6}$/', $colorRaw) ? strtolower($colorRaw) : '#2563eb';
$title = (string) ($m['title'] ?? '');
$desc = (string) ($m['description'] ?? '');
$sort = (int) ($m['sort_order'] ?? 0);
$active = $isEdit ? ((int) ($m['is_active'] ?? 0) === 1) : true;
$formAction = $isEdit ? url('/admin/site-modules/' . (int) $m['id']) : url('/admin/site-modules');
$heading = $isEdit ? 'Modül Düzenle' : 'Yeni Modül';
boss_page_header($heading, [['l' => 'Sayfalar', 'u' => '/admin/site-modules'], ['l' => 'Modüller', 'u' => '/admin/site-modules'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<form method="post" action="<?= $formAction ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title">Modül Bilgileri</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="full"><label class="form-label">Başlık</label><input type="text" name="title" value="<?= $e($title) ?>" maxlength="191" required class="form-control"></div>
        <div class="full"><label class="form-label">Açıklama <span class="text-muted">(opsiyonel · kısa metin)</span></label><textarea name="description" rows="3" maxlength="255" class="form-control" placeholder="Modülü tek cümleyle anlatan kısa açıklama"><?= $e($desc) ?></textarea></div>
        <div class="full">
          <label class="form-label">İkon</label>
          <?= boss_icon_picker('icon', $icon) ?>
          <div class="form-text mt-1">Izgaradan seçin ya da arayın; listede yoksa adını yazın. Tüm adlar <a href="https://lucide.dev/icons/" target="_blank" rel="noopener" class="text-primary">lucide.dev/icons</a>.</div>
        </div>
        <div class="full">
          <label class="form-label">Renk</label>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <input type="color" id="colorPick" value="<?= $e($color) ?>" class="form-control form-control-color" style="width:48px;">
            <input type="text" id="colorHex" name="color" value="<?= $e($color) ?>" placeholder="#2563eb" class="form-control font-monospace" style="max-width:140px;">
            <div class="d-flex align-items-center gap-1">
              <?php foreach (['#2563eb', 'var(--primary)', '#059669', '#e11d48', '#d97706', '#0ea5e9'] as $preset): ?>
              <button type="button" class="modPreset border rounded-2" style="width:28px;height:28px;background:<?= $preset ?>" data-c="<?= $preset ?>" title="<?= $preset ?>"></button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="c3"><label class="form-label">Sıra</label><input type="number" name="sort_order" value="<?= $sort ?>" min="0" step="1" class="form-control"></div>
        <div class="full"><?= boss_switch('is_active', $active, ['id' => 'act', 'title' => 'Sitede görünür']) ?></div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/site-modules') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
  </div>
</form>

<script>
(function(){
  var pick=document.getElementById('colorPick'), hex=document.getElementById('colorHex');
  function normColor(v){ v=(v||'').trim(); if(/^#?[0-9a-fA-F]{6}$/.test(v)) return '#'+v.replace('#','').toLowerCase(); return null; }
  if(pick&&hex){
    pick.addEventListener('input', function(){ hex.value=pick.value; });
    hex.addEventListener('input', function(){ var n=normColor(hex.value); if(n){ pick.value=n; } });
    document.querySelectorAll('.modPreset').forEach(function(b){ b.addEventListener('click', function(){ hex.value=b.dataset.c; pick.value=b.dataset.c; }); });
  }
})();
</script>
