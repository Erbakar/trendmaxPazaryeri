<?php
/** Yönetim — Admin rolleri listesi (RBAC). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$roles = $roles ?? [];
$action = '<a href="' . $e(url('/admin/admin-roles/create')) . '" class="btn btn-primary btn-sm"><i class="ti ti-plus me-1"></i>Yeni Rol</a>';
boss_page_header('Roller', [['l' => 'Yönetim'], ['l' => 'Roller']], $action);
$permSummary = function (?string $json): string {
    $p = json_decode((string) $json, true);
    if (!is_array($p) || $p === []) { return 'Yetki yok'; }
    if (in_array('*', $p, true)) { return 'Tüm yetkiler'; }
    return count($p) . ' yetki';
};
?>
<div class="card mb-0">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-shield-lock me-1 text-muted"></i>Roller</h3></div>
  <?php if (empty($roles)): ?>
    <div class="card-body text-center py-5 text-muted">Rol yok.</div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="adminRolesTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Rol</th><th>Açıklama</th><th>Yetki</th><th class="text-center">Admin</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $r):
          $rid = (int) $r['id'];
          $sys = (int) ($r['is_system'] ?? 0) === 1;
          $cnt = (int) ($r['admin_count'] ?? 0);
        ?>
        <tr>
          <td class="ps-4">
            <div class="fw-semibold"><?= $e($r['name']) ?> <?php if ($sys): ?><span class="badge bg-warning-subtle text-warning rounded-pill fs-1"><i class="ti ti-lock me-1"></i>Sistem</span><?php endif; ?></div>
            <div class="fs-1 text-muted font-monospace"><?= $e($r['slug'] ?? '') ?></div>
          </td>
          <td class="text-muted" style="white-space:normal;max-width:360px;"><?= $e($r['description'] ?? '—') ?: '—' ?></td>
          <td><span class="badge bg-secondary-subtle text-muted rounded-pill"><?= $e($permSummary($r['permissions_json'] ?? null)) ?></span></td>
          <td class="text-center"><span class="badge bg-info-subtle text-info rounded-pill"><?= $cnt ?></span></td>
          <td class="text-end pe-4">
            <?php if ($sys): ?>
              <span class="text-muted fs-1"><i class="ti ti-lock"></i> korumalı</span>
            <?php else: ?>
            <div class="d-inline-flex gap-2 justify-content-end">
              <a href="<?= url('/admin/admin-roles/' . $rid . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <form method="post" action="<?= url('/admin/admin-roles/' . $rid . '/delete') ?>" class="d-inline" onsubmit="return confirm('“<?= $e($r['name']) ?>” rolü silinsin mi?')">
                <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"<?= $cnt > 0 ? ' disabled' : '' ?>><i class="ti ti-trash"></i></button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>
