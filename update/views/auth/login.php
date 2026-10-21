<?php
$error = $data['_flash']['error'] ?? $data['error'] ?? '';
$success = $data['_flash']['success'] ?? '';
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
                <h1 class="auth-login-title">Hesab&#305;n&#305;za Giri&#351; Yap&#305;n</h1>
                <p class="auth-login-copy">
                    Sipari&#351;, kargo, iade ve fatura ak&#305;&#351;&#305;n&#305; y&#246;netmek i&#231;in bilgilerinizle oturum a&#231;&#305;n.
                </p>

                <?php if ($success): ?>
                <div class="auth-success" style="margin-top:18px;padding:13px 14px;display:flex;align-items:flex-start;gap:10px;border-radius:14px;background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;font-size:12px;line-height:1.55;">
                    <i data-lucide="check-circle" style="width:16px;height:16px;flex-shrink:0"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="auth-error">
                    <i data-lucide="triangle-alert" style="width:16px;height:16px;flex-shrink:0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="/login" id="loginForm" class="auth-form">
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

                    <div class="auth-field">
                        <label class="auth-label" for="pwInput">&#350;ifre</label>
                        <div class="auth-input-wrap">
                            <i data-lucide="lock"></i>
                            <input
                                type="password"
                                id="pwInput"
                                name="password"
                                class="auth-input"
                                placeholder="&#350;ifrenizi girin"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" class="auth-toggle" id="pwToggle" aria-label="&#350;ifreyi g&#246;ster">
                                <i data-lucide="eye" style="width:16px;height:16px" id="pwIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="auth-meta">
                        <label class="auth-remember">
                            <input type="checkbox" name="remember" value="1">
                            <span>Beni hat&#305;rla</span>
                        </label>
                        <a href="/password/forgot">&#350;ifremi unuttum</a>
                    </div>

                    <button type="submit" class="auth-btn" id="loginBtn">
                        <i data-lucide="log-in" style="width:16px;height:16px"></i>
                        Giri&#351; Yap
                    </button>
                </form>
            </div>

            <p class="auth-footnote">
                G&#252;venli oturum, oran s&#305;n&#305;rlama ve CSRF korumas&#305; ile pazaryeri y&#246;netim ekran&#305;na eri&#351;irsiniz.
            </p>
        </div>
    </section>

    <aside class="auth-visual" aria-hidden="true">
        <div class="auth-visual-inner">
            <div class="visual-stage">
                <div class="visual-orbit"></div>
                <div class="visual-link"></div>

                <span class="market-badge is-trendyol">
                    <img src="/public/assets/img/marketplaces/trendyol.png" alt="Trendyol">
                </span>
                <span class="market-badge is-amazon">
                    <img src="/public/assets/img/marketplaces/amazon.png" alt="Amazon">
                </span>
                <span class="market-badge is-hepsiburada">
                    <img src="/public/assets/img/marketplaces/hepsiburada.png" alt="Hepsiburada">
                </span>
                <span class="market-badge is-n11">
                    <img src="/public/assets/img/marketplaces/n11.png" alt="n11">
                </span>
                <span class="market-badge is-pazarama">
                    <img src="/public/assets/img/marketplaces/pazarama.png" alt="Pazarama">
                </span>
                <span class="market-badge is-pttavm">
                    <img src="/public/assets/img/marketplaces/pttavm.jpg" alt="PttAVM">
                </span>
                <span class="market-badge is-ciceksepeti">
                    <img src="/public/assets/img/marketplaces/ciceksepeti.png" alt="&#199;i&#231;ekSepeti">
                </span>

                <div class="visual-card">
                    <div class="visual-card-top">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>

                    <div class="visual-item">
                        <span class="visual-avatar"></span>
                        <span class="visual-lines">
                            <span class="visual-line"></span>
                            <span class="visual-line is-short"></span>
                        </span>
                    </div>

                    <div class="visual-item">
                        <span class="visual-avatar"></span>
                        <span class="visual-lines">
                            <span class="visual-line"></span>
                            <span class="visual-line is-short"></span>
                        </span>
                    </div>

                    <div class="visual-item">
                        <span class="visual-avatar"></span>
                        <span class="visual-lines">
                            <span class="visual-line"></span>
                            <span class="visual-line is-short"></span>
                        </span>
                    </div>
                </div>
            </div>

            <h2 class="auth-visual-title">T&#252;m Pazaryerleri Tek Panelde</h2>
            <p class="auth-visual-copy">
                Trendyol, Amazon, Hepsiburada, n11, Pazarama, PttAVM ve &#199;i&#231;ekSepeti ak&#305;&#351;&#305;n&#305; tek operasyon ekran&#305;nda izleyin.
            </p>
            <div class="auth-visual-dots">
                <span class="is-active"></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </aside>
</div>

<script>
const loginForm = document.getElementById('loginForm');
const loginBtn = document.getElementById('loginBtn');
const pwInput = document.getElementById('pwInput');
const pwToggle = document.getElementById('pwToggle');
const pwIcon = document.getElementById('pwIcon');

pwToggle.addEventListener('click', function () {
    const showPassword = pwInput.type === 'password';
    pwInput.type = showPassword ? 'text' : 'password';
    pwToggle.setAttribute('aria-label', showPassword ? 'Şifreyi gizle' : 'Şifreyi göster');
    pwIcon.setAttribute('data-lucide', showPassword ? 'eye-off' : 'eye');
    lucide.createIcons();
});

loginForm.addEventListener('submit', function () {
    loginBtn.disabled = true;
    loginBtn.innerHTML = '<i data-lucide="loader-circle" style="width:16px;height:16px;animation:spin 1s linear infinite"></i> Giriş yapılıyor...';
    lucide.createIcons({ nodes: [loginBtn] });
});
</script>

<style>
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
