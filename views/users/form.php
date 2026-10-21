<?php
$userRow = $data['userRow'] ?? null;
$roles = $data['roles'] ?? [];
$csrf = $data['_csrf'] ?? '';
$isEdit = is_array($userRow);
?>
<style>
.uf-wrap{max-width:920px}
.uf-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.uf-head h1{margin:0;font-size:24px;font-weight:700}
.uf-head p{margin:6px 0 0;color:var(--text-muted);font-size:13px}
.uf-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);text-decoration:none;font-size:13px;font-weight:700}
.uf-btn:hover{border-color:var(--primary);color:var(--primary)}
.uf-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card)}
.uf-body{padding:22px}
.uf-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.uf-field{display:flex;flex-direction:column;gap:7px}
.uf-field label{font-size:12px;font-weight:700;color:var(--text-muted)}
.uf-input,.uf-select{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:var(--bg-input);color:var(--text-primary);font:500 14px inherit;box-sizing:border-box}
.uf-input:focus,.uf-select:focus{outline:none;border-color:var(--primary)}
.uf-check{display:flex;align-items:center;gap:10px;margin-top:8px;font-size:13px;color:var(--text-primary)}
.uf-pw-wrap{position:relative}
.uf-pw-wrap .uf-input{padding-right:42px}
.uf-pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px;display:flex;align-items:center}
.uf-pw-toggle:hover{color:var(--primary)}
.uf-toggle{position:relative;width:44px;height:24px;flex-shrink:0}
.uf-toggle input{opacity:0;width:0;height:0;position:absolute}
.uf-toggle-track{position:absolute;inset:0;background:#cbd5e1;border-radius:12px;cursor:pointer;transition:background .2s}
.uf-toggle-track::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 0 0 1px var(--border)}
.uf-toggle input:checked+.uf-toggle-track{background:var(--primary)}
.uf-toggle input:checked+.uf-toggle-track::after{transform:translateX(20px)}
.uf-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;flex-wrap:wrap}
.uf-submit{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border:none;border-radius:12px;background:var(--primary);color:#fff;font-size:14px;font-weight:700;cursor:pointer}
.uf-submit:hover{opacity:.92}
@media(max-width:860px){.uf-grid{grid-template-columns:1fr}}
</style>

<div class="uf-wrap">
    <div class="uf-head">
        <div>
            <h1><?= $isEdit ? 'Kullanıcı Düzenle' : 'Yeni Kullanıcı' ?></h1>
            <p><?= $isEdit ? 'Rol, durum ve giriş bilgilerini güncelleyin.' : 'Yeni kullanıcı oluşturup yetkili rolünü belirleyin.' ?></p>
        </div>
        <a href="/users" class="uf-btn">Kullanıcılara Dön</a>
    </div>

    <form method="POST" action="<?= $isEdit ? '/users/' . (int) ($userRow['id'] ?? 0) : '/users' ?>" class="uf-card">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf) ?>">
        <div class="uf-body">
            <div class="uf-grid">
                <div class="uf-field">
                    <label for="userName">Ad Soyad</label>
                    <input id="userName" type="text" name="name" class="uf-input" value="<?= htmlspecialchars((string) ($userRow['name'] ?? '')) ?>" required>
                </div>
                <div class="uf-field">
                    <label for="userEmail">E-posta</label>
                    <input id="userEmail" type="email" name="email" class="uf-input" value="<?= htmlspecialchars((string) ($userRow['email'] ?? '')) ?>" required>
                </div>
                <div class="uf-field">
                    <label for="roleId">Rol</label>
                    <select id="roleId" name="role_id" class="uf-select" required>
                        <option value="">Rol seçin</option>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) ($role['id'] ?? 0) ?>" <?= (int) ($userRow['role_id'] ?? 0) === (int) ($role['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($role['name'] ?? '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="uf-field">
                    <label for="userPassword"><?= $isEdit ? 'Yeni Şifre' : 'Şifre' ?></label>
                    <div class="uf-pw-wrap">
                        <input id="userPassword" type="password" name="password" class="uf-input" <?= $isEdit ? '' : 'required' ?>>
                        <button type="button" class="uf-pw-toggle" onclick="togglePw('userPassword',this)" title="Şifreyi göster/gizle"><i data-lucide="eye" style="width:16px;height:16px"></i></button>
                    </div>
                </div>
                <div class="uf-field">
                    <label for="userPasswordConfirm"><?= $isEdit ? 'Yeni Şifre Tekrar' : 'Şifre Tekrar' ?></label>
                    <div class="uf-pw-wrap">
                        <input id="userPasswordConfirm" type="password" name="password_confirm" class="uf-input" <?= $isEdit ? '' : 'required' ?>>
                        <button type="button" class="uf-pw-toggle" onclick="togglePw('userPasswordConfirm',this)" title="Şifreyi göster/gizle"><i data-lucide="eye" style="width:16px;height:16px"></i></button>
                    </div>
                </div>
            </div>

            <div class="uf-check">
                <input type="hidden" name="is_active" value="0">
                <label class="uf-toggle">
                    <input type="checkbox" name="is_active" value="1" <?= ((int) ($userRow['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                    <span class="uf-toggle-track"></span>
                </label>
                <span>Kullanıcı aktif olsun</span>
            </div>

            <div class="uf-actions">
                <a href="/users" class="uf-btn">Vazgeç</a>
                <button type="submit" class="uf-submit"><?= $isEdit ? 'Kaydet' : 'Kullanıcı Oluştur' ?></button>
            </div>
        </div>
    </form>
</div>
<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isPassword = inp.type === 'password';
    inp.type = isPassword ? 'text' : 'password';
    const icon = btn.querySelector('i');
    icon.setAttribute('data-lucide', isPassword ? 'eye-off' : 'eye');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>
