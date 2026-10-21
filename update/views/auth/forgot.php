<?php
$error      = $data['_flash']['error'] ?? $data['error'] ?? '';
$success    = $data['_flash']['success'] ?? '';
$resetToken = $data['_flash']['reset_token'] ?? '';
$resetEmail = $data['_flash']['reset_email'] ?? '';
$branding   = $data['_branding'] ?? [];
$brandLogo  = (string) ($branding['logo_url'] ?? '');
$brandName  = (string) ($branding['site_name'] ?? 'OzyConnect');
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
                <h1 class="auth-login-title">&#350;ifremi Unuttum</h1>
                <p class="auth-login-copy">
                    Hesab&#305;n&#305;za kay&#305;tl&#305; e-posta adresini girin, size &#351;ifre s&#305;f&#305;rlama kodu g&#246;nderelim.
                </p>

                <?php if ($error): ?>
                <div class="auth-error">
                    <i data-lucide="triangle-alert" style="width:16px;height:16px;flex-shrink:0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($success && !$resetToken): ?>
                <div class="auth-success">
                    <i data-lucide="check-circle" style="width:16px;height:16px;flex-shrink:0"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($resetToken): ?>
                <!-- SMTP yapılandırılmamış — Token ekranda gösteriliyor -->
                <div class="reset-token-card">
                    <div class="reset-token-header">
                        <i data-lucide="key-round" style="width:20px;height:20px"></i>
                        <span>&#350;ifre S&#305;f&#305;rlama Kodu</span>
                    </div>
                    <p class="reset-token-desc">
                        SMTP yap&#305;land&#305;r&#305;lmad&#305;&#287;&#305; i&#231;in kod e-posta ile g&#246;nderilemedi.<br>
                        A&#351;a&#287;&#305;daki kodu kullanarak &#351;ifrenizi s&#305;f&#305;rlayabilirsiniz.
                    </p>
                    <div class="reset-token-value" id="tokenValue"><?= htmlspecialchars($resetToken) ?></div>
                    <div class="reset-token-actions">
                        <button type="button" class="reset-token-copy" id="copyTokenBtn" onclick="copyToken()">
                            <i data-lucide="copy" style="width:14px;height:14px"></i>
                            Kodu Kopyala
                        </button>
                        <a href="/password/reset?token=<?= urlencode($resetToken) ?>" class="reset-token-go">
                            <i data-lucide="arrow-right" style="width:14px;height:14px"></i>
                            &#350;ifremi S&#305;f&#305;rla
                        </a>
                    </div>
                    <p class="reset-token-timer">
                        <i data-lucide="clock" style="width:12px;height:12px"></i>
                        Bu kod <strong>15 dakika</strong> ge&#231;erlidir.
                    </p>
                </div>
                <?php else: ?>

                <form method="POST" action="/password/forgot" id="forgotForm" class="auth-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>">

                    <div class="auth-field">
                        <label class="auth-label" for="emailInput">E-posta adresi</label>
                        <div class="auth-input-wrap">
                            <i data-lucide="mail"></i>
                            <input
                                type="email"
                                id="emailInput"
                                name="email"
                                class="auth-input"
                                placeholder="ornek@firma.com"
                                autocomplete="email"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <button type="submit" class="auth-btn" id="forgotBtn">
                        <i data-lucide="send" style="width:16px;height:16px"></i>
                        S&#305;f&#305;rlama Kodu G&#246;nder
                    </button>
                </form>
                <?php endif; ?>

                <div class="auth-back-link">
                    <a href="/login">
                        <i data-lucide="arrow-left" style="width:14px;height:14px"></i>
                        Giri&#351; sayfas&#305;na d&#246;n
                    </a>
                </div>
            </div>

            <p class="auth-footnote">
                G&#252;venli &#351;ifre s&#305;f&#305;rlama, oran s&#305;n&#305;rlama ve token korumas&#305; ile hesab&#305;n&#305;z&#305; kurtarabilirsiniz.
            </p>
        </div>
    </section>

    <aside class="auth-visual" aria-hidden="true">
        <div class="auth-visual-inner">
            <div class="visual-stage">
                <div class="visual-orbit"></div>

                <div class="visual-shield">
                    <i data-lucide="shield-check" style="width:52px;height:52px;color:#fff;stroke-width:1.5"></i>
                </div>

                <div class="visual-lock-items">
                    <span class="visual-lock-item" style="--delay:0s;">
                        <i data-lucide="lock" style="width:18px;height:18px"></i>
                    </span>
                    <span class="visual-lock-item" style="--delay:.3s;">
                        <i data-lucide="fingerprint" style="width:18px;height:18px"></i>
                    </span>
                    <span class="visual-lock-item" style="--delay:.6s;">
                        <i data-lucide="key-round" style="width:18px;height:18px"></i>
                    </span>
                    <span class="visual-lock-item" style="--delay:.9s;">
                        <i data-lucide="scan-eye" style="width:18px;height:18px"></i>
                    </span>
                </div>
            </div>

            <h2 class="auth-visual-title">G&#252;venli &#350;ifre S&#305;f&#305;rlama</h2>
            <p class="auth-visual-copy">
                SHA-256 token hash, s&#252;re s&#305;n&#305;rl&#305; kodlar ve oran s&#305;n&#305;rlama ile hesab&#305;n&#305;z her zaman g&#252;vende.
            </p>
            <div class="auth-visual-dots">
                <span></span>
                <span class="is-active"></span>
                <span></span>
            </div>
        </div>
    </aside>
</div>

<script>
// Form submit animasyonu
const forgotForm = document.getElementById('forgotForm');
const forgotBtn = document.getElementById('forgotBtn');

if (forgotForm && forgotBtn) {
    forgotForm.addEventListener('submit', function () {
        forgotBtn.disabled = true;
        forgotBtn.innerHTML = '<i data-lucide="loader-circle" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Gönderiliyor...';
        lucide.createIcons({ nodes: [forgotBtn] });
    });
}

// Token kopyalama
function copyToken() {
    const tokenEl = document.getElementById('tokenValue');
    const btn = document.getElementById('copyTokenBtn');
    if (!tokenEl) return;

    navigator.clipboard.writeText(tokenEl.textContent.trim()).then(() => {
        btn.innerHTML = '<i data-lucide="check" style="width:14px;height:14px"></i> Kopyalandı!';
        btn.classList.add('is-copied');
        lucide.createIcons({ nodes: [btn] });
        setTimeout(() => {
            btn.innerHTML = '<i data-lucide="copy" style="width:14px;height:14px"></i> Kodu Kopyala';
            btn.classList.remove('is-copied');
            lucide.createIcons({ nodes: [btn] });
        }, 2000);
    });
}
</script>

<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ─── Success Alert ─────────────────────────────────── */
.auth-success {
    margin-top: 18px;
    padding: 13px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-radius: 14px;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
    font-size: 12px;
    line-height: 1.55;
}

/* ─── Token Card ────────────────────────────────────── */
.reset-token-card {
    margin-top: 22px;
    padding: 22px;
    border-radius: 16px;
    background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
    border: 1px solid rgba(25, 103, 242, .15);
}

.reset-token-header {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #1967f2;
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 10px;
}

.reset-token-desc {
    margin: 0 0 16px;
    color: #6b7a8d;
    font-size: 12px;
    line-height: 1.7;
}

.reset-token-value {
    padding: 14px 16px;
    background: #ffffff;
    border: 1px solid #dde4f0;
    border-radius: 12px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 11px;
    color: #1a1a2e;
    word-break: break-all;
    line-height: 1.6;
    letter-spacing: .02em;
    user-select: all;
}

.reset-token-actions {
    display: flex;
    gap: 10px;
    margin-top: 14px;
}

.reset-token-copy,
.reset-token-go {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .18s ease;
    font-family: inherit;
}

.reset-token-copy {
    background: #ffffff;
    border: 1px solid #dde4f0;
    color: #4b5563;
}

.reset-token-copy:hover {
    background: #f5f7fb;
    border-color: #c5ceda;
}

.reset-token-copy.is-copied {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #047857;
}

.reset-token-go {
    background: #1967f2;
    border: 1px solid transparent;
    color: #ffffff;
}

.reset-token-go:hover {
    background: #1355cb;
}

.reset-token-timer {
    margin: 14px 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
    color: #94a3b8;
    font-size: 11px;
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

.visual-lock-items {
    position: absolute;
    inset: 0;
    z-index: 4;
}

.visual-lock-item {
    position: absolute;
    width: 44px;
    height: 44px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .95);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #1967f2;
    box-shadow: 0 12px 28px rgba(4, 39, 97, .2);
    animation: lockOrbit 8s linear infinite;
    animation-delay: var(--delay);
}

.visual-lock-item:nth-child(1) { top: 20%;  left: 10%; }
.visual-lock-item:nth-child(2) { top: 10%;  right: 15%; }
.visual-lock-item:nth-child(3) { bottom: 20%; left: 15%; }
.visual-lock-item:nth-child(4) { bottom: 15%; right: 10%; }

@keyframes lockOrbit {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-12px); }
}
</style>
