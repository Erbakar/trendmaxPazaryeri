<?php
use App\Models\Role;

$roles = $data['roles'] ?? [];
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$csrf = $data['_csrf'] ?? '';
$moduleLabels = Role::MODULE_LABELS;
?>
<style>
.rl-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.rl-head h1{margin:0;font-size:22px;font-weight:600;letter-spacing:-.01em}
.rl-head p{margin:4px 0 0;color:var(--text-muted);font-size:12px}
.rl-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);text-decoration:none;font-size:13px;font-weight:700;transition:all .15s}
.rl-btn:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.rl-btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 0 0 1px var(--border)}
.rl-btn-primary:hover{color:#fff;background:#0F52D4;box-shadow:0 0 0 1px var(--border)}
.rl-list{display:flex;flex-direction:column;gap:8px}
.rl-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;transition:all .2s ease;box-shadow:var(--shadow-sm)}
.rl-card:hover{border-color:color-mix(in srgb, var(--primary) 30%, var(--border));box-shadow:var(--shadow-md);transform:translateY(-1px)}
.rl-card-inner{display:flex;align-items:center;gap:16px;padding:16px 20px}
.rl-info{flex:1;min-width:0}
.rl-name{font-size:15px;font-weight:700;color:var(--text-primary)}
.rl-meta{margin-top:4px;font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rl-dot{width:3px;height:3px;border-radius:50%;background:var(--text-muted);flex-shrink:0}
.rl-modules{display:flex;gap:4px;flex-wrap:wrap;margin-top:8px}
.rl-module-tag{display:inline-flex;align-items:center;padding:3px 8px;border-radius:var(--radius-xs);font-size:10px;font-weight:600;background:var(--bg-input);color:var(--text-secondary);border:1px solid var(--border);transition:all .15s}
.rl-module-tag.active{background:#ecfdf5;color:#059669;border-color:#a7f3d0}
.rl-tag{display:inline-flex;padding:3px 8px;border-radius:var(--radius-xs);font-size:10px;font-weight:700}
.rl-tag-system{background:#eef2ff;color:#0F52D4}
.rl-tag-custom{background:var(--bg-input);color:var(--text-secondary);border:1px solid var(--border)}
.rl-actions{display:flex;gap:6px;flex-shrink:0}
.rl-act{display:inline-flex;align-items:center;gap:4px;padding:7px 12px;border-radius:var(--radius-xs);border:1px solid var(--border);text-decoration:none;background:transparent;color:var(--text-secondary);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s}
.rl-act:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.rl-act-danger{border-color:#fecaca;color:#dc2626}
.rl-act-danger:hover{background:#fef2f2;border-color:#dc2626}
.rl-empty{padding:48px 20px;text-align:center;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-sm)}
@media(max-width:700px){.rl-card-inner{flex-direction:column;align-items:flex-start}.rl-actions{width:100%;justify-content:flex-end}}

/* Dark mode */
[data-theme="dark"] .rl-module-tag.active{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.25)}
[data-theme="dark"] .rl-tag-system{background:rgba(79,70,229,.15);color:#818cf8}
</style>

<div class="rl-head">
    <div>
        <h1>Roller</h1>
        <p>Kullanıcılara atanacak rolleri ve izinleri yönetin.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/authorization" class="rl-btn"><i data-lucide="shield" style="width:14px;height:14px"></i> Yetkilendirme</a>
        <?php if ($can('roles.create')): ?>
        <a href="/roles/create" class="rl-btn rl-btn-primary">+ Yeni Rol</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($roles === []): ?>
<div class="rl-empty">Henüz rol bulunmuyor.</div>
<?php else: ?>
<div class="rl-list">
    <?php foreach ($roles as $role): ?>
    <?php
    $permissions = json_decode((string) ($role['permissions_json'] ?? '[]'), true) ?: [];
    // Modül bazında grupla
    $activeModules = [];
    foreach ($permissions as $p) {
        $parts = explode('.', $p, 2);
        if (isset($parts[0])) {
            $activeModules[$parts[0]] = ($activeModules[$parts[0]] ?? 0) + 1;
        }
    }
    ?>
    <div class="rl-card">
        <div class="rl-card-inner">
            <div class="rl-info">
                <div style="display:flex;align-items:center;gap:8px">
                    <span class="rl-name"><?= htmlspecialchars((string) ($role['name'] ?? '')) ?></span>
                    <span class="rl-tag <?= (int) ($role['is_system'] ?? 0) === 1 ? 'rl-tag-system' : 'rl-tag-custom' ?>">
                        <?= (int) ($role['is_system'] ?? 0) === 1 ? 'Sistem' : 'Özel' ?>
                    </span>
                </div>
                <div class="rl-meta">
                    <span><?= htmlspecialchars((string) ($role['slug'] ?? '')) ?></span>
                    <span class="rl-dot"></span>
                    <span><?= count($permissions) ?> izin</span>
                    <span class="rl-dot"></span>
                    <span><?= (int) ($role['user_count'] ?? 0) ?> kullanıcı</span>
                </div>
                <?php if (!empty($activeModules)): ?>
                <div class="rl-modules">
                    <?php foreach ($moduleLabels as $mod => $label): ?>
                    <?php if (isset($activeModules[$mod])): ?>
                    <span class="rl-module-tag active" title="<?= $activeModules[$mod] ?> izin"><?= htmlspecialchars($label) ?></span>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="rl-actions">
                <?php if ($can('roles.edit')): ?>
                <a href="/roles/<?= (int) ($role['id'] ?? 0) ?>/edit" class="rl-act">Düzenle</a>
                <?php endif; ?>
                <?php if ($can('roles.delete') && (int) ($role['is_system'] ?? 0) !== 1): ?>
                <form method="POST" action="/roles/<?= (int) ($role['id'] ?? 0) ?>/delete" onsubmit="return confirm('Bu rol silinsin mi?')" style="display:inline">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf) ?>">
                    <button type="submit" class="rl-act rl-act-danger">Sil</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
