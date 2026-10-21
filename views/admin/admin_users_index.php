<?php
/** Yönetim — Admin kullanıcılar listesi (DataTables). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$admins = $admins ?? [];
$selfId = (int) ($selfId ?? 0);
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$initOf = fn(string $s) => mb_strtoupper(mb_substr(trim($s) !== '' ? $s : '?', 0, 1, 'UTF-8'), 'UTF-8');
$action = '<a href="' . $e(url('/admin/admin-users/create')) . '" class="btn btn-primary btn-sm"><i class="ti ti-plus me-1"></i>Yeni Admin</a>';
boss_page_header('Admin Kullanıcılar', [['l' => 'Yönetim'], ['l' => 'Admin Kullanıcılar']], $action);
?>
<div class="card mb-0">
  <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-users me-1 text-muted"></i>Admin Kullanıcılar</h3></div>
  <?php if (empty($admins)): ?>
    <div class="card-body text-center py-5 text-muted">Admin kullanıcı yok.</div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="adminUsersTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Kullanıcı</th><th>Rol</th><th>2FA</th><th>Durum</th><th>Son Giriş</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $a):
          $aid = (int) $a['id'];
          $roleName = (string) ($a['role_name'] ?? '');
          $isSuper = empty($a['admin_role_id']);
          $active = (int) ($a['is_active'] ?? 0) === 1;
          $twofa = (int) ($a['totp_enabled'] ?? 0) === 1;
        ?>
        <tr>
          <td class="ps-4">
            <div class="d-flex align-items-center gap-2">
              <span class="d-inline-grid flex-shrink-0" style="place-items:center;width:34px;height:34px;border-radius:9px;background:var(--ad-surface-3);color:var(--ad-accent);font-weight:700;font-size:13px;"><?= $e($initOf((string) $a['name'])) ?></span>
              <div><div class="fw-semibold"><?= $e($a['name']) ?><?php if ($aid === $selfId): ?> <span class="badge bg-primary-subtle text-primary rounded-pill fs-1">Siz</span><?php endif; ?></div><div class="fs-1 text-muted"><?= $e($a['email']) ?></div></div>
            </div>
          </td>
          <td><?php if ($isSuper): ?><span class="badge bg-primary-subtle text-primary rounded-pill"><i class="ti ti-crown me-1"></i>Süper Admin</span><?php else: ?><span class="badge bg-info-subtle text-info rounded-pill"><?= $e($roleName !== '' ? $roleName : '—') ?></span><?php endif; ?></td>
          <td><?= $twofa ? '<span class="badge bg-success-subtle text-success rounded-pill">Aktif</span>' : '<span class="badge bg-secondary-subtle text-muted rounded-pill">Kapalı</span>' ?></td>
          <td><?= $active ? '<span class="badge bg-success-subtle text-success rounded-pill">Aktif</span>' : '<span class="badge bg-secondary-subtle text-muted rounded-pill">Pasif</span>' ?></td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($a['last_login_at'] ?? '')) ?>"><?= $e($fmtDT($a['last_login_at'] ?? null)) ?></td>
          <td class="text-end pe-4">
            <div class="d-inline-flex gap-2 justify-content-end">
              <a href="<?= url('/admin/admin-users/' . $aid . '/edit') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Düzenle"><i class="ti ti-pencil"></i></a>
              <?php if ($aid !== $selfId): ?>
              <form method="post" action="<?= url('/admin/admin-users/' . $aid . '/delete') ?>" class="d-inline" onsubmit="return confirm('“<?= $e($a['name']) ?>” silinsin mi?')">
                <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>
