<?php
$users = $data['users'] ?? [];
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$csrf = $data['_csrf'] ?? '';
?>
<style>
.us-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.us-head h1{margin:0;font-size:24px;font-weight:700}
.us-head p{margin:6px 0 0;color:var(--text-muted);font-size:13px}
.us-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;border:1px solid var(--border);text-decoration:none;font-size:13px;font-weight:700;background:var(--bg-card);color:var(--text-primary)}
.us-btn:hover{border-color:var(--primary);color:var(--primary)}
.us-btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.us-btn-primary:hover{color:#fff;opacity:.92}
.us-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);overflow:hidden}
.us-table{width:100%;border-collapse:collapse}
.us-table th,.us-table td{padding:14px 16px;border-bottom:1px solid var(--border);text-align:left}
.us-table th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);background:var(--bg-input)}
.us-table td{font-size:13px;color:var(--text-primary)}
.us-role{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:11px;font-weight:700}
.us-state{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700}
.us-state.active{background:#f0fdf4;color:#15803d}
.us-state.passive{background:#fef2f2;color:#dc2626}
.us-actions{display:flex;gap:8px;flex-wrap:wrap}
.us-link{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;border:1px solid var(--border);text-decoration:none;background:transparent;color:var(--text-secondary);font-size:12px;font-weight:700}
.us-link:hover{border-color:var(--primary);color:var(--primary)}
.us-danger{border-color:#fecaca;color:#dc2626}
.us-danger:hover{border-color:#dc2626;color:#dc2626;background:#fef2f2}
.us-empty{padding:38px 20px;text-align:center;color:var(--text-muted);font-size:13px}
</style>

<div class="us-head">
    <div>
        <h1>Kullanıcılar</h1>
        <p>Kullanıcı ekleyin, rol atayın ve aktiflik durumlarını yönetin.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/authorization" class="us-btn">Yetkilendirme</a>
        <?php if ($can('users.create')): ?>
        <a href="/users/create" class="us-btn us-btn-primary">Yeni Kullanıcı</a>
        <?php endif; ?>
    </div>
</div>

<div class="us-card">
    <table class="us-table">
        <thead>
        <tr>
            <th>Ad Soyad</th>
            <th>E-posta</th>
            <th>Rol</th>
            <th>Durum</th>
            <th>Son Giriş</th>
            <th>İşlemler</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($users === []): ?>
        <tr><td colspan="6" class="us-empty">Henüz kullanıcı bulunmuyor.</td></tr>
        <?php else: ?>
        <?php foreach ($users as $user): ?>
        <tr>
            <td><strong><?= htmlspecialchars((string) ($user['name'] ?? '')) ?></strong></td>
            <td><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></td>
            <td><span class="us-role"><?= htmlspecialchars((string) ($user['role_name'] ?? 'Rol yok')) ?></span></td>
            <td>
                <span class="us-state <?= (int) ($user['is_active'] ?? 0) === 1 ? 'active' : 'passive' ?>">
                    <?= (int) ($user['is_active'] ?? 0) === 1 ? 'Aktif' : 'Pasif' ?>
                </span>
            </td>
            <td><?= !empty($user['last_login_at']) ? date('d.m.Y H:i', strtotime((string) $user['last_login_at'])) : 'Henüz yok' ?></td>
            <td>
                <div class="us-actions">
                    <?php if ($can('users.edit')): ?>
                    <a href="/users/<?= (int) ($user['id'] ?? 0) ?>/edit" class="us-link">Düzenle</a>
                    <?php endif; ?>
                    <?php if ($can('users.delete')): ?>
                    <form method="POST" action="/users/<?= (int) ($user['id'] ?? 0) ?>/delete" onsubmit="return confirm('Kullanıcı silinsin mi?')" style="display:inline">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf) ?>">
                        <button type="submit" class="us-link us-danger">Sil</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
