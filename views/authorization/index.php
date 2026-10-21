<?php
use App\Models\Role;

$roles = $data['roles'] ?? [];
$users = $data['users'] ?? [];
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$csrf = $data['_csrf'] ?? '';
$permissionMap = Role::PERMISSION_MAP;
$moduleLabels = Role::MODULE_LABELS;

$roleCount = count($roles);
$userCount = count($users);
$activeUserCount = count(array_filter($users, static fn(array $user): bool => (int) ($user['is_active'] ?? 0) === 1));
$totalPermissions = array_sum(array_map('count', $permissionMap));

// Modül grupları
$moduleGroups = [
    'Temel' => ['dashboard', 'orders', 'returns', 'products'],
    'Katalog' => ['categories', 'brands', 'customers'],
    'Finansal' => ['invoices', 'finance'],
    'Sistem' => ['marketplaces', 'settings', 'users', 'roles', 'sync'],
];

// Her rol için izin verilerini hazırla
$rolesData = [];
foreach ($roles as $role) {
    $perms = json_decode((string) ($role['permissions_json'] ?? '[]'), true) ?: [];
    $rolesData[] = [
        'id' => (int) ($role['id'] ?? 0),
        'name' => $role['name'] ?? '',
        'slug' => $role['slug'] ?? '',
        'is_system' => (int) ($role['is_system'] ?? 0),
        'user_count' => (int) ($role['user_count'] ?? 0),
        'permissions' => $perms,
        'perm_count' => count($perms),
    ];
}
?>
<style>
/* === Stats === */
.az-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px}
.az-stat{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm);transition:all .2s ease}
.az-stat:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.az-stat-icon{width:44px;height:44px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.az-stat-val{font-size:22px;font-weight:600;color:var(--text-primary);line-height:1;letter-spacing:-.02em}
.az-stat-lbl{font-size:11px;color:var(--text-muted);margin-top:3px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}

/* === Tabs === */
.az-tabs{display:inline-flex;gap:4px;margin-bottom:20px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:4px;box-shadow:var(--shadow-sm)}
.az-tab{display:flex;align-items:center;gap:7px;padding:9px 18px;font-size:13px;font-weight:600;color:var(--text-muted);text-decoration:none;white-space:nowrap;cursor:pointer;transition:all .18s ease;user-select:none;border-radius:var(--radius-xs);border:1px solid transparent}
.az-tab:hover{color:var(--text-primary);background:var(--bg-card)}
.az-tab.active{color:var(--primary);background:var(--bg-card);border-color:var(--border);box-shadow:var(--shadow-sm);font-weight:700}
.az-tab-count{font-size:10px;background:var(--border);color:var(--text-muted);padding:2px 7px;border-radius:10px;font-weight:700;min-width:20px;text-align:center;transition:all .18s}
.az-tab.active .az-tab-count{background:var(--primary);color:#fff}

/* === Panel === */
.az-panel{display:none}
.az-panel.active{display:block;animation:azFadeIn .2s ease}
@keyframes azFadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

/* === Toolbar === */
.az-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.az-toolbar-search{display:flex;align-items:center;gap:8px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 14px;flex:1;min-width:200px;max-width:360px;transition:border-color .15s}
.az-toolbar-search:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px rgb(var(--brand) / .08)}
.az-toolbar-search input{border:none;outline:none;background:transparent;font-size:13px;width:100%;font-family:inherit;color:var(--text-primary)}
.az-toolbar-btn{padding:8px 16px;border-radius:var(--radius-sm);font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .15s;text-decoration:none}
.az-toolbar-btn:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.az-toolbar-btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 0 0 1px var(--border)}
.az-toolbar-btn.primary:hover{background:#0F52D4;box-shadow:0 0 0 1px var(--border)}

/* === Tree Table === */
.az-table{width:100%;border-collapse:separate;border-spacing:0;background:var(--bg-card);border-radius:var(--radius-md);border:1px solid var(--border);overflow:hidden;box-shadow:var(--shadow-sm)}
.az-table th{background:var(--bg-input);padding:11px 14px;font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.az-table td{padding:12px 14px;font-size:13px;border-bottom:1px solid var(--border-light,#f1f5f9);vertical-align:middle}
.az-table tr:last-child td{border-bottom:none}
.az-table tbody tr:hover{background:var(--bg-table-hover,#f8fafc)}
.az-table .indent{display:inline-flex;align-items:center;gap:6px}
.az-table .indent-line{width:16px;border-left:2px solid var(--border-light,#e2e8f0);height:20px;margin-left:4px}
.az-tree-toggle{cursor:pointer;width:24px;height:24px;border:none;background:transparent;padding:0;display:inline-flex;align-items:center;justify-content:center;color:var(--text-muted);transition:transform .2s ease;border-radius:var(--radius-xs)}
.az-tree-toggle:hover{background:var(--bg-input);color:var(--text-primary)}
.az-tree-toggle.open{transform:rotate(90deg)}

/* === Badges === */
.az-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:600;letter-spacing:.01em}
.az-badge-full{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}
.az-badge-partial{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe}
.az-badge-none{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.az-badge-system{background:#eef2ff;color:#0F52D4;border:1px solid #c7d2fe}
.az-badge-custom{background:var(--bg-input);color:var(--text-secondary);border:1px solid var(--border)}
.az-badge-active{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}
.az-badge-inactive{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}

/* === Permission dots === */
.az-perm-dot{display:inline-flex;width:8px;height:8px;border-radius:50%;flex-shrink:0;transition:transform .15s}
.az-perm-dot:hover{transform:scale(1.4)}
.az-perm-granted{background:#10b981;box-shadow:0 0 0 2px rgba(16,185,129,.15)}
.az-perm-denied{background:var(--border,#e2e8f0)}

/* === Row actions === */
.az-row-actions{display:flex;gap:4px}
.az-row-actions a,.az-row-actions button{width:30px;height:30px;border-radius:var(--radius-xs);border:1px solid var(--border);background:var(--bg-card);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;color:var(--text-muted);text-decoration:none}
.az-row-actions a:hover,.az-row-actions button:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .06)}
.az-row-actions .danger:hover{border-color:#ef4444;color:#ef4444;background:#fef2f2}

/* === Child rows === */
.az-child-row{display:none}
.az-child-row.open{display:table-row;animation:azRowIn .18s ease}
@keyframes azRowIn{from{opacity:0}to{opacity:1}}

/* === Empty state === */
.az-empty{text-align:center;padding:48px 20px;color:var(--text-muted)}
.az-empty i{opacity:.3;display:block;margin:0 auto 10px}

/* === Responsive === */
@media(max-width:768px){.az-stats{grid-template-columns:1fr 1fr}.az-tabs{width:100%;overflow-x:auto}.az-table{font-size:12px}}
@media(max-width:480px){.az-stats{grid-template-columns:1fr}.az-tabs{flex-wrap:nowrap}}

/* === Dark mode support === */
[data-theme="dark"] .az-table th{background:var(--bg-input)}
[data-theme="dark"] .az-table tbody tr:hover{background:var(--bg-input)}
[data-theme="dark"] .az-table td{border-bottom-color:var(--border)}
[data-theme="dark"] .az-perm-denied{background:var(--border)}
[data-theme="dark"] .az-badge-full{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.25)}
[data-theme="dark"] .az-badge-partial{background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.25)}
[data-theme="dark"] .az-badge-none{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.25)}
[data-theme="dark"] .az-stat{border-color:var(--border)}
[data-theme="dark"] .az-stat-icon{opacity:.85}
</style>

<!-- === Stats === -->
<div class="az-stats">
    <div class="az-stat">
        <div class="az-stat-icon" style="background:#eef2ff"><i data-lucide="shield" style="width:22px;height:22px;color:var(--primary)"></i></div>
        <div><div class="az-stat-val"><?= $roleCount ?></div><div class="az-stat-lbl">Toplam Rol</div></div>
    </div>
    <div class="az-stat">
        <div class="az-stat-icon" style="background:#ecfdf5"><i data-lucide="users" style="width:22px;height:22px;color:#10b981"></i></div>
        <div><div class="az-stat-val"><?= $userCount ?></div><div class="az-stat-lbl">Toplam Kullanıcı</div></div>
    </div>
    <div class="az-stat">
        <div class="az-stat-icon" style="background:#f0fdf4"><i data-lucide="user-check" style="width:22px;height:22px;color:#059669"></i></div>
        <div><div class="az-stat-val"><?= $activeUserCount ?></div><div class="az-stat-lbl">Aktif Kullanıcı</div></div>
    </div>
    <div class="az-stat">
        <div class="az-stat-icon" style="background:#fffbeb"><i data-lucide="key" style="width:22px;height:22px;color:#f59e0b"></i></div>
        <div><div class="az-stat-val"><?= $totalPermissions ?></div><div class="az-stat-lbl">İzin Tanımı</div></div>
    </div>
</div>

<!-- === Tabs === -->
<div class="az-tabs">
    <a class="az-tab active" data-tab="roles" onclick="azSwitchTab('roles')">
        <i data-lucide="shield" style="width:16px;height:16px"></i> Roller
        <span class="az-tab-count"><?= $roleCount ?></span>
    </a>
    <a class="az-tab" data-tab="users" onclick="azSwitchTab('users')">
        <i data-lucide="users" style="width:16px;height:16px"></i> Kullanıcılar
        <span class="az-tab-count"><?= $userCount ?></span>
    </a>
    <a class="az-tab" data-tab="matrix" onclick="azSwitchTab('matrix')">
        <i data-lucide="grid-3x3" style="width:16px;height:16px"></i> İzin Matrisi
    </a>
</div>

<!-- === ROLLER TAB === -->
<div class="az-panel active" id="azPanel-roles">
    <div class="az-toolbar">
        <div class="az-toolbar-search">
            <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted)"></i>
            <input type="text" id="azRoleSearch" placeholder="Rol ara..." oninput="azFilterRoles()">
        </div>
        <?php if ($can('roles.create')): ?>
        <a href="/roles/create" class="az-toolbar-btn primary"><i data-lucide="plus" style="width:14px;height:14px"></i> Yeni Rol</a>
        <?php endif; ?>
        <a href="/roles" class="az-toolbar-btn"><i data-lucide="list" style="width:14px;height:14px"></i> Tüm Roller</a>
    </div>

    <table class="az-table" id="azRolesTable">
        <thead>
            <tr>
                <th style="width:40px"></th>
                <th>Rol</th>
                <th>Tür</th>
                <th>İzinler</th>
                <th>Kullanıcı</th>
                <th>Durum</th>
                <th>İşlemler</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rolesData)): ?>
            <tr><td colspan="7" class="az-empty">
                <i data-lucide="shield-off" style="width:28px;height:28px"></i>Henüz rol tanımlanmamış.
            </td></tr>
            <?php else: ?>
            <?php foreach ($rolesData as $rd): ?>
            <?php
            // Modül bazında izin dağılımı
            $modulePerms = [];
            foreach ($permissionMap as $mod => $acts) {
                $total = count($acts);
                $granted = 0;
                foreach (array_keys($acts) as $act) {
                    if (in_array($mod . '.' . $act, $rd['permissions'], true) || in_array('*', $rd['permissions'], true)) {
                        $granted++;
                    }
                }
                $modulePerms[$mod] = ['total' => $total, 'granted' => $granted];
            }
            $totalGranted = array_sum(array_column($modulePerms, 'granted'));
            $hasWildcard = in_array('*', $rd['permissions'], true);
            ?>
            <!-- Rol satırı -->
            <tr class="az-role-row" data-role-id="<?= $rd['id'] ?>" data-name="<?= htmlspecialchars(strtolower($rd['name'] . ' ' . $rd['slug'])) ?>">
                <td>
                    <button class="az-tree-toggle" onclick="azToggleRole(<?= $rd['id'] ?>,this)" title="Detayları göster">
                        <i data-lucide="chevron-right" style="width:14px;height:14px"></i>
                    </button>
                </td>
                <td>
                    <span class="indent">
                        <i data-lucide="shield" style="width:14px;height:14px;color:<?= $rd['is_system'] ? 'var(--primary)' : '#94a3b8' ?>"></i>
                        <strong><?= htmlspecialchars($rd['name']) ?></strong>
                        <span style="font-size:11px;color:var(--text-muted);margin-left:4px"><?= htmlspecialchars($rd['slug']) ?></span>
                    </span>
                </td>
                <td>
                    <span class="az-badge <?= $rd['is_system'] ? 'az-badge-system' : 'az-badge-custom' ?>">
                        <?= $rd['is_system'] ? 'Sistem' : 'Özel' ?>
                    </span>
                </td>
                <td>
                    <?php if ($hasWildcard): ?>
                    <span class="az-badge az-badge-full"><i data-lucide="check-circle" style="width:12px;height:12px"></i> Tam Yetki</span>
                    <?php elseif ($totalGranted === $totalPermissions): ?>
                    <span class="az-badge az-badge-full"><i data-lucide="check-circle" style="width:12px;height:12px"></i> <?= $totalGranted ?>/<?= $totalPermissions ?></span>
                    <?php elseif ($totalGranted > 0): ?>
                    <span class="az-badge az-badge-partial"><?= $totalGranted ?>/<?= $totalPermissions ?></span>
                    <?php else: ?>
                    <span class="az-badge az-badge-none"><i data-lucide="alert-circle" style="width:12px;height:12px"></i> İzin yok</span>
                    <?php endif; ?>
                </td>
                <td><strong><?= $rd['user_count'] ?></strong></td>
                <td>
                    <?php
                    $grantedModules = 0;
                    foreach ($modulePerms as $mp) { if ($mp['granted'] > 0) $grantedModules++; }
                    ?>
                    <span style="font-size:11px;color:var(--text-muted)"><?= $grantedModules ?>/<?= count($permissionMap) ?> modül</span>
                </td>
                <td>
                    <div class="az-row-actions">
                        <?php if ($can('roles.edit')): ?>
                        <a href="/roles/<?= $rd['id'] ?>/edit" title="Düzenle"><i data-lucide="pencil" style="width:12px;height:12px"></i></a>
                        <?php endif; ?>
                        <?php if ($can('roles.delete') && !$rd['is_system']): ?>
                        <form method="POST" action="/roles/<?= $rd['id'] ?>/delete" onsubmit="return confirm('Bu rol silinsin mi?')" style="display:inline">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf) ?>">
                            <button type="submit" class="danger" title="Sil"><i data-lucide="trash-2" style="width:12px;height:12px"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <!-- Modül grup alt satırları -->
            <?php foreach ($moduleGroups as $groupName => $groupModules): ?>
            <?php
            $groupTotal = 0; $groupGranted = 0;
            foreach ($groupModules as $gm) {
                $groupTotal += $modulePerms[$gm]['total'] ?? 0;
                $groupGranted += $modulePerms[$gm]['granted'] ?? 0;
            }
            ?>
            <tr class="az-child-row" data-parent-role="<?= $rd['id'] ?>" data-group="<?= htmlspecialchars($groupName) ?>">
                <td></td>
                <td colspan="2">
                    <span class="indent">
                        <span class="indent-line"></span>
                        <button class="az-tree-toggle" onclick="azToggleGroup(<?= $rd['id'] ?>,'<?= htmlspecialchars($groupName) ?>',this)">
                            <i data-lucide="chevron-right" style="width:14px;height:14px"></i>
                        </button>
                        <i data-lucide="folder" style="width:14px;height:14px;color:var(--primary)"></i>
                        <strong style="font-size:12px"><?= htmlspecialchars($groupName) ?></strong>
                    </span>
                </td>
                <td>
                    <?php if ($groupGranted === $groupTotal): ?>
                    <span class="az-badge az-badge-full"><?= $groupGranted ?>/<?= $groupTotal ?></span>
                    <?php elseif ($groupGranted > 0): ?>
                    <span class="az-badge az-badge-partial"><?= $groupGranted ?>/<?= $groupTotal ?></span>
                    <?php else: ?>
                    <span class="az-badge az-badge-none">0/<?= $groupTotal ?></span>
                    <?php endif; ?>
                </td>
                <td colspan="3"></td>
            </tr>
            <!-- Modül alt satırları -->
            <?php foreach ($groupModules as $mod): ?>
            <?php
            $actions = $permissionMap[$mod] ?? [];
            if (empty($actions)) continue;
            $mp = $modulePerms[$mod];
            ?>
            <tr class="az-child-row" data-parent-role="<?= $rd['id'] ?>" data-parent-group="<?= htmlspecialchars($groupName) ?>" data-module="<?= $mod ?>">
                <td></td>
                <td colspan="2">
                    <span class="indent">
                        <span class="indent-line"></span><span class="indent-line"></span>
                        <i data-lucide="<?= $mp['granted'] > 0 ? 'folder-check' : 'folder-x' ?>" style="width:14px;height:14px;color:<?= $mp['granted'] > 0 ? '#10b981' : '#94a3b8' ?>"></i>
                        <span style="font-size:12px;font-weight:700"><?= htmlspecialchars($moduleLabels[$mod] ?? ucfirst($mod)) ?></span>
                    </span>
                </td>
                <td>
                    <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                        <?php foreach ($actions as $act => $label): ?>
                        <?php $granted = in_array($mod . '.' . $act, $rd['permissions'], true) || $hasWildcard; ?>
                        <span class="az-perm-dot <?= $granted ? 'az-perm-granted' : 'az-perm-denied' ?>" title="<?= htmlspecialchars($label) ?> (<?= $mod . '.' . $act ?>)"></span>
                        <?php endforeach; ?>
                        <span style="font-size:10px;color:var(--text-muted);margin-left:4px"><?= $mp['granted'] ?>/<?= $mp['total'] ?></span>
                    </div>
                </td>
                <td colspan="3">
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php foreach ($actions as $act => $label): ?>
                        <?php $granted = in_array($mod . '.' . $act, $rd['permissions'], true) || $hasWildcard; ?>
                        <span style="font-size:10px;padding:2px 6px;border-radius:4px;<?= $granted ? 'background:#f0fdf4;color:#059669' : 'background:#f8fafc;color:#cbd5e1;text-decoration:line-through' ?>"><?= htmlspecialchars($label) ?></span>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- === KULLANICILAR TAB === -->
<div class="az-panel" id="azPanel-users">
    <div class="az-toolbar">
        <div class="az-toolbar-search">
            <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted)"></i>
            <input type="text" id="azUserSearch" placeholder="Kullanıcı ara..." oninput="azFilterUsers()">
        </div>
        <?php if ($can('users.create')): ?>
        <a href="/users/create" class="az-toolbar-btn primary"><i data-lucide="plus" style="width:14px;height:14px"></i> Yeni Kullanıcı</a>
        <?php endif; ?>
        <a href="/users" class="az-toolbar-btn"><i data-lucide="list" style="width:14px;height:14px"></i> Tüm Kullanıcılar</a>
    </div>

    <table class="az-table" id="azUsersTable">
        <thead>
            <tr>
                <th>Ad Soyad</th>
                <th>E-posta</th>
                <th>Rol</th>
                <th>Durum</th>
                <th>İşlemler</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="5" class="az-empty">
                <i data-lucide="user-x" style="width:28px;height:28px"></i>Henüz kullanıcı yok.
            </td></tr>
            <?php else: ?>
            <?php foreach ($users as $user): ?>
            <tr class="az-user-row" data-name="<?= htmlspecialchars(strtolower(($user['name'] ?? '') . ' ' . ($user['email'] ?? ''))) ?>">
                <td>
                    <span class="indent">
                        <i data-lucide="user" style="width:14px;height:14px;color:var(--primary)"></i>
                        <strong><?= htmlspecialchars((string) ($user['name'] ?? '')) ?></strong>
                    </span>
                </td>
                <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></td>
                <td>
                    <span class="az-badge az-badge-system"><?= htmlspecialchars((string) ($user['role_name'] ?? 'Rol yok')) ?></span>
                </td>
                <td>
                    <span class="az-badge <?= (int) ($user['is_active'] ?? 0) === 1 ? 'az-badge-active' : 'az-badge-inactive' ?>">
                        <i data-lucide="<?= (int) ($user['is_active'] ?? 0) === 1 ? 'check-circle' : 'x-circle' ?>" style="width:12px;height:12px"></i>
                        <?= (int) ($user['is_active'] ?? 0) === 1 ? 'Aktif' : 'Pasif' ?>
                    </span>
                </td>
                <td>
                    <div class="az-row-actions">
                        <?php if ($can('users.edit')): ?>
                        <a href="/users/<?= (int) ($user['id'] ?? 0) ?>/edit" title="Düzenle"><i data-lucide="pencil" style="width:12px;height:12px"></i></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- === İZİN MATRİSİ TAB === -->
<div class="az-panel" id="azPanel-matrix">
    <div class="az-toolbar">
        <div class="az-toolbar-search">
            <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted)"></i>
            <input type="text" id="azMatrixSearch" placeholder="Modül ara..." oninput="azFilterMatrix()">
        </div>
        <button type="button" class="az-toolbar-btn" onclick="azExpandAllMatrix()"><i data-lucide="chevrons-down" style="width:14px;height:14px"></i> Tümünü Aç</button>
        <button type="button" class="az-toolbar-btn" onclick="azCollapseAllMatrix()"><i data-lucide="chevrons-up" style="width:14px;height:14px"></i> Tümünü Kapat</button>
    </div>

    <table class="az-table" id="azMatrixTable">
        <thead>
            <tr>
                <th style="width:40px"></th>
                <th>Modül / İzin</th>
                <?php foreach ($rolesData as $rd): ?>
                <th style="text-align:center;min-width:80px">
                    <div style="font-size:10px"><?= htmlspecialchars($rd['name']) ?></div>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($moduleGroups as $groupName => $groupModules): ?>
            <!-- Grup başlığı -->
            <tr class="az-matrix-group" data-group="<?= htmlspecialchars($groupName) ?>" style="background:#f8fafc">
                <td>
                    <button class="az-tree-toggle" onclick="azToggleMatrixGroup('<?= htmlspecialchars($groupName) ?>',this)">
                        <i data-lucide="chevron-right" style="width:14px;height:14px"></i>
                    </button>
                </td>
                <td>
                    <span class="indent">
                        <i data-lucide="folder" style="width:14px;height:14px;color:var(--primary)"></i>
                        <strong style="font-size:12px;text-transform:uppercase;letter-spacing:.04em"><?= htmlspecialchars($groupName) ?></strong>
                    </span>
                </td>
                <?php foreach ($rolesData as $rd): ?>
                <?php
                $gTotal = 0; $gGranted = 0;
                $hw = in_array('*', $rd['permissions'], true);
                foreach ($groupModules as $gm) {
                    foreach (array_keys($permissionMap[$gm] ?? []) as $a) {
                        $gTotal++;
                        if ($hw || in_array($gm . '.' . $a, $rd['permissions'], true)) $gGranted++;
                    }
                }
                ?>
                <td style="text-align:center">
                    <?php if ($gGranted === $gTotal && $gTotal > 0): ?>
                    <span class="az-badge az-badge-full" style="font-size:9px;padding:2px 6px"><?= $gGranted ?>/<?= $gTotal ?></span>
                    <?php elseif ($gGranted > 0): ?>
                    <span class="az-badge az-badge-partial" style="font-size:9px;padding:2px 6px"><?= $gGranted ?>/<?= $gTotal ?></span>
                    <?php else: ?>
                    <span class="az-badge az-badge-none" style="font-size:9px;padding:2px 6px">0/<?= $gTotal ?></span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <!-- Modül satırları -->
            <?php foreach ($groupModules as $mod): ?>
            <?php
            $actions = $permissionMap[$mod] ?? [];
            if (empty($actions)) continue;
            ?>
            <tr class="az-child-row az-matrix-module" data-matrix-group="<?= htmlspecialchars($groupName) ?>" data-matrix-module="<?= $mod ?>">
                <td>
                    <button class="az-tree-toggle" onclick="azToggleMatrixModule('<?= htmlspecialchars($groupName) ?>','<?= $mod ?>',this)">
                        <i data-lucide="chevron-right" style="width:14px;height:14px"></i>
                    </button>
                </td>
                <td>
                    <span class="indent">
                        <span class="indent-line"></span>
                        <i data-lucide="file-text" style="width:14px;height:14px;color:#94a3b8"></i>
                        <span style="font-size:12px;font-weight:700"><?= htmlspecialchars($moduleLabels[$mod] ?? ucfirst($mod)) ?></span>
                    </span>
                </td>
                <?php foreach ($rolesData as $rd): ?>
                <?php
                $hw = in_array('*', $rd['permissions'], true);
                $mTotal = count($actions); $mGranted = 0;
                foreach (array_keys($actions) as $a) {
                    if ($hw || in_array($mod . '.' . $a, $rd['permissions'], true)) $mGranted++;
                }
                ?>
                <td style="text-align:center">
                    <div style="display:flex;gap:3px;justify-content:center">
                        <?php foreach ($actions as $act => $label): ?>
                        <?php $g = $hw || in_array($mod . '.' . $act, $rd['permissions'], true); ?>
                        <span class="az-perm-dot <?= $g ? 'az-perm-granted' : 'az-perm-denied' ?>" title="<?= htmlspecialchars($label) ?>"></span>
                        <?php endforeach; ?>
                    </div>
                </td>
                <?php endforeach; ?>
            </tr>
            <!-- İzin detay satırları -->
            <?php foreach ($actions as $act => $label): ?>
            <tr class="az-child-row az-matrix-perm" data-matrix-group="<?= htmlspecialchars($groupName) ?>" data-matrix-module="<?= $mod ?>">
                <td></td>
                <td>
                    <span class="indent">
                        <span class="indent-line"></span><span class="indent-line"></span>
                        <span style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars($label) ?></span>
                        <span style="font-size:9px;color:var(--text-muted);font-family:monospace;margin-left:4px"><?= $mod ?>.<?= $act ?></span>
                    </span>
                </td>
                <?php foreach ($rolesData as $rd): ?>
                <?php $g = in_array('*', $rd['permissions'], true) || in_array($mod . '.' . $act, $rd['permissions'], true); ?>
                <td style="text-align:center">
                    <?php if ($g): ?>
                    <i data-lucide="check" style="width:14px;height:14px;color:#10b981"></i>
                    <?php else: ?>
                    <i data-lucide="minus" style="width:14px;height:14px;color:#e2e8f0"></i>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
// === Tab Switching ===
function azSwitchTab(tabId) {
    document.querySelectorAll('.az-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.az-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelector('.az-tab[data-tab="' + tabId + '"]').classList.add('active');
    document.getElementById('azPanel-' + tabId).classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// === Roles Tree Toggle ===
function azToggleRole(roleId, btn) {
    btn.classList.toggle('open');
    var rows = document.querySelectorAll('tr[data-parent-role="' + roleId + '"]:not([data-parent-group])');
    rows.forEach(function(r) { r.classList.toggle('open'); });
    // Kapat alt grupları
    if (!btn.classList.contains('open')) {
        document.querySelectorAll('tr[data-parent-role="' + roleId + '"]').forEach(function(r) {
            if (r.dataset.parentGroup) r.classList.remove('open');
            var childToggle = r.querySelector('.az-tree-toggle');
            if (childToggle) childToggle.classList.remove('open');
        });
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function azToggleGroup(roleId, groupName, btn) {
    btn.classList.toggle('open');
    var rows = document.querySelectorAll('tr[data-parent-role="' + roleId + '"][data-parent-group="' + groupName + '"]');
    rows.forEach(function(r) { r.classList.toggle('open'); });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// === Matrix Tree Toggle ===
function azToggleMatrixGroup(groupName, btn) {
    btn.classList.toggle('open');
    var modules = document.querySelectorAll('tr.az-matrix-module[data-matrix-group="' + groupName + '"]');
    modules.forEach(function(r) { r.classList.toggle('open'); });
    // Kapat alt izinleri
    if (!btn.classList.contains('open')) {
        document.querySelectorAll('tr.az-matrix-perm[data-matrix-group="' + groupName + '"]').forEach(function(r) {
            r.classList.remove('open');
        });
        modules.forEach(function(r) {
            var t = r.querySelector('.az-tree-toggle');
            if (t) t.classList.remove('open');
        });
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function azToggleMatrixModule(groupName, moduleName, btn) {
    btn.classList.toggle('open');
    var perms = document.querySelectorAll('tr.az-matrix-perm[data-matrix-group="' + groupName + '"][data-matrix-module="' + moduleName + '"]');
    perms.forEach(function(r) { r.classList.toggle('open'); });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function azExpandAllMatrix() {
    document.querySelectorAll('#azMatrixTable .az-tree-toggle').forEach(function(b) { b.classList.add('open'); });
    document.querySelectorAll('#azMatrixTable .az-child-row').forEach(function(r) { r.classList.add('open'); });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function azCollapseAllMatrix() {
    document.querySelectorAll('#azMatrixTable .az-tree-toggle').forEach(function(b) { b.classList.remove('open'); });
    document.querySelectorAll('#azMatrixTable .az-child-row').forEach(function(r) { r.classList.remove('open'); });
}

// === Filters ===
function azFilterRoles() {
    var q = document.getElementById('azRoleSearch').value.toLowerCase();
    document.querySelectorAll('.az-role-row').forEach(function(tr) {
        var match = (tr.dataset.name || '').includes(q);
        tr.style.display = match ? '' : 'none';
        var roleId = tr.dataset.roleId;
        document.querySelectorAll('tr[data-parent-role="' + roleId + '"]').forEach(function(r) {
            if (!match) r.classList.remove('open');
        });
    });
}

function azFilterUsers() {
    var q = document.getElementById('azUserSearch').value.toLowerCase();
    document.querySelectorAll('.az-user-row').forEach(function(tr) {
        tr.style.display = (tr.dataset.name || '').includes(q) ? '' : 'none';
    });
}

function azFilterMatrix() {
    var q = document.getElementById('azMatrixSearch').value.toLowerCase();
    if (!q) {
        document.querySelectorAll('#azMatrixTable tbody tr').forEach(function(r) { r.style.display = ''; });
        document.querySelectorAll('#azMatrixTable .az-child-row').forEach(function(r) { r.classList.remove('open'); });
        document.querySelectorAll('#azMatrixTable .az-tree-toggle').forEach(function(b) { b.classList.remove('open'); });
        return;
    }
    document.querySelectorAll('#azMatrixTable tbody tr').forEach(function(r) {
        var text = r.textContent.toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
        if (text.includes(q) && r.classList.contains('az-child-row')) r.classList.add('open');
    });
}
</script>
