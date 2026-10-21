<?php
/** Yönetim — Admin kullanıcı ekle/düzenle. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$admin = $admin ?? null;
$roles = $roles ?? [];
$isEdit = $admin !== null;
$aid = $isEdit ? (int) $admin['id'] : 0;
$action = $isEdit ? url('/admin/admin-users/' . $aid) : url('/admin/admin-users');
$curRole = $isEdit ? (int) ($admin['admin_role_id'] ?? 0) : 0;
$active = $isEdit ? ((int) ($admin['is_active'] ?? 0) === 1) : true;
boss_page_header($isEdit ? 'Admin Düzenle' : 'Yeni Admin', [['l' => 'Yönetim'], ['l' => 'Admin Kullanıcılar', 'u' => '/admin/admin-users'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<div class="row g-3">
  <div class="col-12 col-lg-7">
    <form method="post" action="<?= $e($action) ?>" autocomplete="off">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <div class="card mb-0">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-user me-1 text-primary"></i><?= $isEdit ? 'Admin Bilgileri' : 'Yeni Admin' ?></h3></div>
        <div class="card-body">
          <div class="ad-form-grid">
            <div class="c6"><label class="form-label">Ad Soyad *</label><input type="text" name="name" value="<?= $e($admin['name'] ?? '') ?>" required maxlength="150" class="form-control"></div>
            <div class="c6"><label class="form-label">E-posta *</label><input type="email" name="email" value="<?= $e($admin['email'] ?? '') ?>" required maxlength="200" class="form-control"></div>
            <div class="c6">
              <label class="form-label">Parola <?= $isEdit ? '<span class="text-muted">(boş = değişmez)</span>' : '*' ?></label>
              <input type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="8" placeholder="<?= $isEdit ? '••••••' : 'en az 8 karakter' ?>" autocomplete="new-password" class="form-control">
            </div>
            <div class="c6">
              <label class="form-label">Rol</label>
              <select name="admin_role_id" class="form-select">
                <option value="">Süper Admin (rolsüz — tüm yetkiler)</option>
                <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>" <?= $curRole === (int) $r['id'] ? 'selected' : '' ?>><?= $e($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Rol seçilmezse tüm yetkilere sahip olur.</div>
            </div>
            <div class="full">
              <label class="form-label d-block">Durum</label>
              <input type="hidden" name="is_active" value="0">
              <?= boss_switch('is_active', $active, ['id' => 'au_active', 'value' => '1', 'on' => 'Aktif', 'off' => 'Pasif']) ?>
            </div>
          </div>
        </div>
      </div>
      <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="<?= url('/admin/admin-users') ?>" class="btn btn-outline-secondary">Vazgeç</a>
        <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Güncelle' : 'Oluştur' ?></button>
      </div>
    </form>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title"><i class="ti ti-info-circle me-1 text-muted"></i>Bilgi</h3></div>
      <div class="card-body fs-2 text-muted">
        <p>• <b>Rol</b> seçerek yetkileri sınırlayabilirsiniz; rolleri <a href="<?= url('/admin/admin-roles') ?>">Roller</a> ekranından yönetin.</p>
        <p>• <b>Süper Admin</b> (rolsüz) tüm panele erişir.</p>
        <p>• <b>2FA</b> (iki adımlı doğrulama) admin ilk girişinde kurulur.</p>
        <p class="mb-0">• Kendi hesabınızı pasifleştiremez veya silemezsiniz (kilitlenme koruması).</p>
      </div>
    </div>
  </div>
</div>
