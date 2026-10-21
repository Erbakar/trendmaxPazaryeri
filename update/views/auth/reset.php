<?php
$error    = $data['_flash']['error'] ?? $data['error'] ?? '';
$success  = $data['_flash']['success'] ?? '';
$token    = $data['token'] ?? '';
$branding = $data['_branding'] ?? [];
$brandLogo = (string) ($branding['logo_url'] ?? '');
$brandName = (string) ($branding['site_name'] ?? 'OzyConnect');
?>

<div class="auth-stage">
    <section class="auth-login">
        <div class="auth-login-inner">
            <?php if ($brandLogo !== ''): ?>
                <a href="/login" class="auth-brand is-image" aria-label="<?= htmlspecialchars($brandName) ?>">
                    <img src="<?= htmlspecialchars($brandLogo) ?>" class="auth-brand-image" alt="<?= htmlspecialchars($brandName) ?>">
                </a>
            <?php else: ?>
                <a href="/login" class="auth-brand" aria-label="OzyConnect">
                    <span class="auth-brand-mark">
                        <i data-lucide="boxes"></i>
                    </span>
                    <span>OzyConnect</span>
                </a>
            <?php endif; ?>

            <div class="auth-login-body">
                <h1 class="auth-login-title">Yeni &#350;ifre Belirle</h1>
                <p class="auth-login-copy">
                    G&#252;venli yeni &#351;ifrenizi a&#351;a&#287;&#305;ya girin. &#350;ifreniz en az 6 karakter olmal&#305;d&#305;r.
                </p>

                <?php if ($error): ?>
                <div class="auth-error">
                    <i data-lucide="triangle-alert" style="width:16px;height:16px;flex-shrink:0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="/password/reset" id="resetForm" class="auth-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="auth-field">
                        <label class="auth-label" for="pwInput">Yeni &#350;ifre</label>
                        <div class="auth-input-wrap">
                            <i data-lucide="lock"></i>
                            <input
                                type="password"
                                id="pwInput"
                                name="password"
                                class="auth-input"
                                placeholder="En az 6 karakter"
                                autocomplete="new-password"
                                minlength="6"
                                required
                                autofocus
                            >
                            <button type="button" class="auth-toggle" id="pwToggle1" aria-label="&#350;ifreyi g&#246;ster">
                                <i data-lucide="eye" style="width:16px;height:16px" id="pwIcon1"></i>
                            </button>
                        </div>
                        <div class="pw-strength" id="pwStrength">
                            <div class="pw-strength-bar" id="pwStrengthBar"></div>
                        </div>
                        <span class="pw-strength-text" id="pwStrengthText"></span>
                    </div>

                    <div class="auth-field">
                        <label class="auth-label" for="pwConfirmInput">&#350;ifre Tekrar</label>
                        <div class="auth-input-wrap">
                            <i data-lucide="lock-keyhole"></i>
                            <input
                                type="password"
                                id="pwConfirmInput"
                                name="password_confirmation"
                                class="auth-input"
                                placeholder="&#350;ifrenizi tekrar girin"
                                autocomplete="new-password"
                                minlength="6"
                                required
                            >
                            <button type="button" class="auth-toggle" id="pwToggle2" aria-label="&#350;ifreyi g&#246;ster">
                                <i data-lucide="eye" style="width:16px;height:16px" id="pwIcon2"></i>
                            </button>
                        </div>
                        <div class="pw-match-info" id="pwMatchInfo"></div>
                    </div>

                    <button type="submit" class="auth-btn" id="resetBtn">
                        <i data-lucide="check-circle" style="width:16px;height:16px"></i>
                        &#350;ifremi G&#252;ncelle
                    </button>
                </form>

                <div class="auth-back-link">
                    <a href="/login">
                        <i data-lucide="arrow-left" style="width:14px;height:14px"></i>
                        Giri&#351; sayfas&#305;na d&#246;n
                    </a>
                </div>
            </div>

            <p class="auth-footnote">
                &#350;ifrenizi g&#252;ncelledikten sonra yeni &#351;ifrenizle giri&#351; yapabilirsiniz.
            </p>
        </div>
    </section>

    <aside class="auth-visual" aria-hidden="true">
        <div class="auth-visual-inner">
            <div class="visual-stage">
                <div class="visual-orbit"></div>

                <div class="visual-shield">
                    <i data-lucide="lock-keyhole" style="width:48px;height:48px;color:#fff;stroke-width:1.5"></i>
                </div>

                <div class="visual-check-items">
                    <span class="visual-check-item" style="--delay:0s;">
                        <i data-lucide="check" style="width:16px;height:16px"></i>
                    </span>
                    <span class="visual-check-item" style="--delay:.4s;">
                        <i data-lucide="check" style="width:16px;height:16px"></i>
                    </span>
                    <span class="visual-check-item" style="--delay:.8s;">
                        <i data-lucide="check" style="width:16px;height:16px"></i>
                    </span>
                </div>
            </div>

            <h2 class="auth-visual-title">Hesab&#305;n&#305;z&#305; Koruyun</h2>
            <p class="auth-visual-copy">
                G&#252;&#231;l&#252; bir &#351;ifre se&#231;erek hesab&#305;n&#305;z&#305;n g&#252;venli&#287;ini art&#305;r&#305;n. Bcrypt &#351;ifreleme ile verileriniz koruma alt&#305;nda.
            </p>
            <div class="auth-visual-dots">
                <span></span>
                <span></span>
                <span class="is-active"></span>
            </div>
        </div>
    </aside>
</div>

<script>
// ─── Şifre Göster/Gizle ───────────────────────────────
function setupToggle(inputId, toggleId, iconId) {
    const input = document.getElementById(inputId);
    const toggle = document.getElementById(toggleId);
    const icon = document.getElementById(iconId);
    if (!input || !toggle || !icon) return;

    toggle.addEventListener('click', function () {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster');
        icon.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
        lucide.createIcons();
    });
}

setupToggle('pwInput', 'pwToggle1', 'pwIcon1');
setupToggle('pwConfirmInput', 'pwToggle2', 'pwIcon2');

// ─── Şifre Gücü Kontrolü ──────────────────────────────
const pwInput = document.getElementById('pwInput');
const pwBar = document.getElementById('pwStrengthBar');
const pwText = document.getElementById('pwStrengthText');
const pwConfirm = document.getElementById('pwConfirmInput');
const matchInfo = document.getElementById('pwMatchInfo');

function checkStrength(password) {
    let score = 0;
    if (password.length >= 6) score++;
    if (password.length >= 10) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    return score;
}

const strengthMap = [
    { label: '', color: 'transparent', width: '0%' },
    { label: 'Çok Zayıf', color: '#ef4444', width: '20%' },
    { label: 'Zayıf', color: '#f97316', width: '40%' },
    { label: 'Orta', color: '#eab308', width: '60%' },
    { label: 'Güçlü', color: '#22c55e', width: '80%' },
    { label: 'Çok Güçlü', color: '#059669', width: '100%' },
];

pwInput.addEventListener('input', function () {
    const score = checkStrength(this.value);
    const s = strengthMap[score];
    pwBar.style.width = s.width;
    pwBar.style.background = s.color;
    pwText.textContent = this.value.length > 0 ? s.label : '';
    pwText.style.color = s.color;
    checkMatch();
});

function checkMatch() {
    const pw1 = pwInput.value;
    const pw2 = pwConfirm.value;
    if (pw2.length === 0) {
        matchInfo.textContent = '';
        return;
    }
    if (pw1 === pw2) {
        matchInfo.innerHTML = '<i data-lucide="check-circle" style="width:12px;height:12px"></i> Şifreler eşleşiyor';
        matchInfo.className = 'pw-match-info is-match';
    } else {
        matchInfo.innerHTML = '<i data-lucide="x-circle" style="width:12px;height:12px"></i> Şifreler eşleşmiyor';
        matchInfo.className = 'pw-match-info is-mismatch';
    }
    lucide.createIcons({ nodes: [matchInfo] });
}

pwConfirm.addEventListener('input', checkMatch);

// ─── Form Submit ───────────────────────────────────────
const resetForm = document.getElementById('resetForm');
const resetBtn = document.getElementById('resetBtn');

resetForm.addEventListener('submit', function (e) {
    if (pwInput.value !== pwConfirm.value) {
        e.preventDefault();
        matchInfo.innerHTML = '<i data-lucide="x-circle" style="width:12px;height:12px"></i> Şifreler eşleşmiyor!';
        matchInfo.className = 'pw-match-info is-mismatch';
        lucide.createIcons({ nodes: [matchInfo] });
        pwConfirm.focus();
        return;
    }
    resetBtn.disabled = true;
    resetBtn.innerHTML = '<i data-lucide="loader-circle" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Güncelleniyor...';
    lucide.createIcons({ nodes: [resetBtn] });
});
</script>

<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ─── Password Strength ─────────────────────────────── */
.pw-strength {
    height: 4px;
    border-radius: 999px;
    background: #e8edf4;
    overflow: hidden;
    margin-top: 2px;
}

.pw-strength-bar {
    height: 100%;
    width: 0;
    border-radius: 999px;
    transition: width .35s ease, background .35s ease;
}

.pw-strength-text {
    display: block;
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
    min-height: 16px;
    transition: color .18s ease;
}

/* ─── Match Info ────────────────────────────────────── */
.pw-match-info {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 4px;
    min-height: 16px;
    transition: color .18s ease;
}

.pw-match-info.is-match {
    color: #059669;
}

.pw-match-info.is-mismatch {
    color: #ef4444;
}

/* ─── Back Link ─────────────────────────────────────── */
.auth-back-link {
    margin-top: 20px;
    text-align: center;
}

.auth-back-link a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #7c8798;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: color .18s ease;
}

.auth-back-link a:hover {
    color: #1967f2;
}

/* ─── Visual Shield ─────────────────────────────────── */
.visual-shield {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 110px;
    height: 110px;
    transform: translate(-50%, -50%);
    border-radius: 999px;
    background: linear-gradient(135deg, rgba(255,255,255,.22), rgba(255,255,255,.08));
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 5;
    box-shadow: 0 20px 50px rgba(4, 39, 97, .25);
    animation: shieldPulse 3s ease-in-out infinite;
}

@keyframes shieldPulse {
    0%, 100% { box-shadow: 0 20px 50px rgba(4, 39, 97, .25); transform: translate(-50%, -50%) scale(1); }
    50% { box-shadow: 0 24px 60px rgba(4, 39, 97, .35); transform: translate(-50%, -50%) scale(1.04); }
}

.visual-check-items {
    position: absolute;
    inset: 0;
    z-index: 4;
}

.visual-check-item {
    position: absolute;
    width: 38px;
    height: 38px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .95);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #22c55e;
    box-shadow: 0 10px 24px rgba(4, 39, 97, .18);
    animation: checkFloat 4s ease-in-out infinite;
    animation-delay: var(--delay);
}

.visual-check-item:nth-child(1) { top: 25%;  left: 18%; }
.visual-check-item:nth-child(2) { top: 15%;  right: 18%; }
.visual-check-item:nth-child(3) { bottom: 22%; left: 50%; transform: translateX(-50%); }

@keyframes checkFloat {
    0%, 100% { transform: translateY(0); opacity: .7; }
    50% { transform: translateY(-10px); opacity: 1; }
}
</style>
