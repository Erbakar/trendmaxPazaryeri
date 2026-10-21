<?php
/** Yönetim — Admin rolü ekle/düzenle (yetki seçimi: PERMISSION_MAP). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$role = $role ?? null;
$current = $current ?? [];
$isEdit = $role !== null;
$rid = $isEdit ? (int) $role['id'] : 0;
$action = $isEdit ? url('/admin/admin-roles/' . $rid) : url('/admin/admin-roles');
$isAll = in_array('*', $current, true);
$map = \App\Models\AdminRole::PERMISSION_MAP;
$labels = \App\Models\AdminRole::MODULE_LABELS;
$has = fn(string $key) => $isAll || in_array($key, $current, true);
boss_page_header($isEdit ? 'Rol Düzenle' : 'Yeni Rol', [['l' => 'Yönetim'], ['l' => 'Roller', 'u' => '/admin/admin-roles'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<style>
.perm-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
.perm-mod{ border:1px solid var(--ad-border); border-radius:10px; padding:12px 14px; }
.perm-mod h4{ font-size:13px; font-weight:700; margin:0 0 8px; color:var(--ad-text); }
.perm-mod label{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--ad-text-soft); padding:3px 0; cursor:pointer; margin:0; }
</style>

<form method="post" action="<?= $e($action) ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-shield-lock me-1 text-primary"></i>Rol Bilgileri</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="c6"><label class="form-label">Rol Adı *</label><input type="text" name="name" value="<?= $e($role['name'] ?? '') ?>" required maxlength="100" placeholder="Örn. Destek Ekibi" class="form-control"></div>
        <div class="c6"><label class="form-label">Açıklama</label><input type="text" name="description" value="<?= $e($role['description'] ?? '') ?>" maxlength="500" placeholder="Bu rol ne yapar?" class="form-control"></div>
      </div>
    </div>
  </div>

  <div class="card mb-0">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h3 class="card-title mb-0"><i class="ti ti-key me-1 text-primary"></i>Yetkiler</h3>
      <label class="d-flex align-items-center gap-2 mb-0 fw-semibold" style="cursor:pointer;">
        <input type="checkbox" name="all_perms" value="1" id="allPerms" class="form-check-input mt-0" <?= $isAll ? 'checked' : '' ?>>
        Tüm yetkiler (Süper)
      </label>
    </div>
    <div class="card-body">
      <div class="perm-grid" id="permGrid">
        <?php foreach ($map as $mod => $acts): ?>
        <div class="perm-mod">
          <h4><?= $e($labels[$mod] ?? $mod) ?></h4>
          <?php foreach ($acts as $act => $lbl): $key = $mod . '.' . $act; ?>
          <label><input type="checkbox" name="perms[]" value="<?= $e($key) ?>" class="form-check-input mt-0 permChk" <?= $has($key) ? 'checked' : '' ?>> <?= $e($lbl) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mt-3 mb-2">
    <a href="<?= url('/admin/admin-roles') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Güncelle' : 'Oluştur' ?></button>
  </div>
</form>

<script>
(function(){
  var all = document.getElementById('allPerms');
  var chks = function(){ return document.querySelectorAll('.permChk'); };
  function sync(){
    var on = all.checked;
    chks().forEach(function(c){ c.disabled = on; if (on) { c.checked = true; } });
  }
  if (all) { all.addEventListener('change', sync); sync(); }
})();
</script>
