<?php
use App\Models\Role;

$role = $data['role'] ?? null;
$permissionMap = $data['permissionMap'] ?? Role::PERMISSION_MAP;
$moduleLabels = $data['moduleLabels'] ?? Role::MODULE_LABELS;
$csrf = $data['_csrf'] ?? '';
$isEdit = is_array($role);
$selectedPermissions = $isEdit ? (json_decode((string) ($role['permissions_json'] ?? '[]'), true) ?: []) : [];

// Modülleri grupla
$moduleGroups = [
    'Temel' => ['dashboard', 'orders', 'returns', 'products'],
    'Katalog' => ['categories', 'brands', 'customers'],
    'Finansal' => ['invoices', 'finance'],
    'Sistem' => ['marketplaces', 'settings', 'users', 'roles', 'logs', 'sync'],
];
?>
<style>
.rf-wrap{max-width:960px}
.rf-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.rf-head h1{margin:0;font-size:22px;font-weight:600;letter-spacing:-.01em}
.rf-head p{margin:4px 0 0;color:var(--text-muted);font-size:12px}
.rf-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);text-decoration:none;font-size:13px;font-weight:700;transition:all .15s}
.rf-btn:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.rf-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;box-shadow:var(--shadow-sm)}
.rf-body{padding:24px}
.rf-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.rf-field{display:flex;flex-direction:column;gap:5px}
.rf-field label{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.rf-input,.rf-textarea{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-primary);font:500 13px inherit;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
.rf-textarea{min-height:70px;resize:vertical}
.rf-input:focus,.rf-textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgb(var(--brand) / .08)}

/* İzin Ağacı */
.pt-section{margin-top:24px}
.pt-section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.pt-section-title h3{margin:0;font-size:15px;font-weight:600;letter-spacing:-.01em}
.pt-toolbar{display:flex;gap:6px}
.pt-toolbar-btn{padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-xs);background:transparent;color:var(--text-muted);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s}
.pt-toolbar-btn:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}

.pt-group{margin-bottom:14px}
.pt-group-label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;padding-left:2px}

.pt-tree{border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;background:var(--bg-input)}
.pt-module{border-bottom:1px solid var(--border)}
.pt-module:last-child{border-bottom:none}

.pt-module-head{display:flex;align-items:center;gap:8px;padding:11px 14px;cursor:pointer;user-select:none;transition:background .12s}
.pt-module-head:hover{background:rgba(0,0,0,.02)}
.pt-arrow{display:inline-flex;width:14px;height:14px;align-items:center;justify-content:center;font-size:9px;color:var(--text-muted);transition:transform .2s ease;flex-shrink:0}
.pt-arrow.open{transform:rotate(90deg)}
.pt-module-cb{margin:0;accent-color:var(--primary);width:16px;height:16px;cursor:pointer}
.pt-module-name{font-size:13px;font-weight:700;color:var(--text-primary);flex:1}
.pt-module-count{font-size:10px;font-weight:700;color:var(--text-muted);background:var(--bg-card);padding:2px 8px;border-radius:var(--radius-xs);border:1px solid var(--border);transition:all .15s}

.pt-actions{display:none;padding:4px 14px 12px 44px}
.pt-actions.open{display:flex;flex-direction:column;gap:2px;animation:ptFadeIn .18s ease}
@keyframes ptFadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.pt-action{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:var(--radius-xs);cursor:pointer;transition:background .12s}
.pt-action:hover{background:rgb(var(--brand) / .04)}
.pt-action input{margin:0;accent-color:var(--primary);width:15px;height:15px;cursor:pointer}
.pt-action-label{font-size:12px;color:var(--text-primary);font-weight:500}
.pt-action-key{font-size:10px;color:var(--text-muted);margin-left:auto;font-family:monospace;opacity:.7}

.rf-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;flex-wrap:wrap}
.rf-submit{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;border:none;border-radius:var(--radius-sm);background:var(--primary);color:#fff;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;box-shadow:0 0 0 1px var(--border)}
.rf-submit:hover{background:#0F52D4;box-shadow:0 0 0 1px var(--border)}
@media(max-width:700px){.rf-row{grid-template-columns:1fr}}

/* Dark mode */
[data-theme="dark"] .pt-module-head:hover{background:rgba(255,255,255,.03)}
[data-theme="dark"] .pt-action:hover{background:rgba(255,255,255,.04)}
</style>

<div class="rf-wrap">
    <div class="rf-head">
        <div>
            <h1><?= $isEdit ? 'Rol Düzenle' : 'Yeni Rol' ?></h1>
            <p>Rolün erişebileceği modül ve işlemleri ağaç yapısından seçin.</p>
        </div>
        <a href="/roles" class="rf-btn"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Roller</a>
    </div>

    <form method="POST" action="<?= $isEdit ? '/roles/' . (int) ($role['id'] ?? 0) : '/roles' ?>" class="rf-card" id="roleForm">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf) ?>">
        <div class="rf-body">
            <div class="rf-row">
                <div class="rf-field">
                    <label for="roleName">Rol Adı</label>
                    <input id="roleName" type="text" name="name" class="rf-input" value="<?= htmlspecialchars((string) ($role['name'] ?? '')) ?>" <?= ($isEdit && (int) ($role['is_system'] ?? 0) === 1) ? 'readonly' : '' ?> required>
                </div>
                <div class="rf-field">
                    <label for="roleSlug">Kısa Ad (Slug)</label>
                    <input id="roleSlug" type="text" name="slug" class="rf-input" value="<?= htmlspecialchars((string) ($role['slug'] ?? '')) ?>" <?= ($isEdit && (int) ($role['is_system'] ?? 0) === 1) ? 'readonly' : '' ?>>
                </div>
            </div>

            <div class="rf-field" style="margin-top:14px">
                <label for="roleDescription">Açıklama</label>
                <textarea id="roleDescription" name="description" class="rf-textarea"><?= htmlspecialchars((string) ($role['description'] ?? '')) ?></textarea>
            </div>

            <!-- İzin Ağacı -->
            <div class="pt-section">
                <div class="pt-section-title">
                    <h3>İzinler</h3>
                    <div class="pt-toolbar">
                        <button type="button" class="pt-toolbar-btn" onclick="ptExpandAll()">Tümünü Aç</button>
                        <button type="button" class="pt-toolbar-btn" onclick="ptCollapseAll()">Tümünü Kapat</button>
                        <button type="button" class="pt-toolbar-btn" onclick="ptSelectAll()">Hepsini Seç</button>
                        <button type="button" class="pt-toolbar-btn" onclick="ptDeselectAll()">Hiçbirini Seçme</button>
                    </div>
                </div>

                <?php foreach ($moduleGroups as $groupName => $groupModules): ?>
                <div class="pt-group">
                    <div class="pt-group-label"><?= htmlspecialchars($groupName) ?></div>
                    <div class="pt-tree">
                        <?php foreach ($groupModules as $module): ?>
                        <?php
                        $actions = $permissionMap[$module] ?? [];
                        if (empty($actions)) continue;
                        $moduleKeys = array_map(static fn(string $a): string => $module . '.' . $a, array_keys($actions));
                        $selectedCount = count(array_intersect($moduleKeys, $selectedPermissions));
                        $allSelected = $selectedCount === count($actions);
                        $someSelected = $selectedCount > 0 && !$allSelected;
                        ?>
                        <div class="pt-module" data-module="<?= htmlspecialchars($module) ?>">
                            <div class="pt-module-head" onclick="ptToggleModule(this)">
                                <span class="pt-arrow"><i data-lucide="chevron-right" style="width:12px;height:12px"></i></span>
                                <input type="checkbox" class="pt-module-cb" onclick="event.stopPropagation();ptModuleCbChange(this)" <?= $allSelected ? 'checked' : '' ?> <?= $someSelected ? 'data-indeterminate="1"' : '' ?>>
                                <span class="pt-module-name"><?= htmlspecialchars((string) ($moduleLabels[$module] ?? ucfirst($module))) ?></span>
                                <span class="pt-module-count"><?= $selectedCount ?>/<?= count($actions) ?></span>
                            </div>
                            <div class="pt-actions">
                                <?php foreach ($actions as $action => $label): ?>
                                <?php $key = $module . '.' . $action; ?>
                                <label class="pt-action">
                                    <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $selectedPermissions, true) ? 'checked' : '' ?> onchange="ptActionChange(this)">
                                    <span class="pt-action-label"><?= htmlspecialchars((string) $label) ?></span>
                                    <span class="pt-action-key"><?= htmlspecialchars($key) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="rf-footer">
                <a href="/roles" class="rf-btn">Vazgeç</a>
                <button type="submit" class="rf-submit"><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
            </div>
        </div>
    </form>
</div>

<script>
// Indeterminate durumları başlat
document.querySelectorAll('.pt-module-cb[data-indeterminate="1"]').forEach(function(cb) {
    cb.indeterminate = true;
});

function ptToggleModule(head) {
    var arrow = head.querySelector('.pt-arrow');
    var actions = head.nextElementSibling;
    arrow.classList.toggle('open');
    actions.classList.toggle('open');
}

function ptModuleCbChange(cb) {
    var module = cb.closest('.pt-module');
    var checks = module.querySelectorAll('.pt-actions input[type="checkbox"]');
    checks.forEach(function(c) { c.checked = cb.checked; });
    cb.indeterminate = false;
    ptUpdateCount(module);
}

function ptActionChange(input) {
    var module = input.closest('.pt-module');
    var checks = module.querySelectorAll('.pt-actions input[type="checkbox"]');
    var moduleCb = module.querySelector('.pt-module-cb');
    var total = checks.length;
    var checked = 0;
    checks.forEach(function(c) { if (c.checked) checked++; });
    moduleCb.checked = checked === total;
    moduleCb.indeterminate = checked > 0 && checked < total;
    ptUpdateCount(module);
}

function ptUpdateCount(module) {
    var checks = module.querySelectorAll('.pt-actions input[type="checkbox"]');
    var total = checks.length;
    var checked = 0;
    checks.forEach(function(c) { if (c.checked) checked++; });
    var counter = module.querySelector('.pt-module-count');
    counter.textContent = checked + '/' + total;
}

function ptExpandAll() {
    document.querySelectorAll('.pt-module').forEach(function(m) {
        m.querySelector('.pt-arrow').classList.add('open');
        m.querySelector('.pt-actions').classList.add('open');
    });
}

function ptCollapseAll() {
    document.querySelectorAll('.pt-module').forEach(function(m) {
        m.querySelector('.pt-arrow').classList.remove('open');
        m.querySelector('.pt-actions').classList.remove('open');
    });
}

function ptSelectAll() {
    document.querySelectorAll('.pt-module').forEach(function(m) {
        m.querySelector('.pt-module-cb').checked = true;
        m.querySelector('.pt-module-cb').indeterminate = false;
        m.querySelectorAll('.pt-actions input[type="checkbox"]').forEach(function(c) { c.checked = true; });
        ptUpdateCount(m);
    });
}

function ptDeselectAll() {
    document.querySelectorAll('.pt-module').forEach(function(m) {
        m.querySelector('.pt-module-cb').checked = false;
        m.querySelector('.pt-module-cb').indeterminate = false;
        m.querySelectorAll('.pt-actions input[type="checkbox"]').forEach(function(c) { c.checked = false; });
        ptUpdateCount(m);
    });
}
</script>
