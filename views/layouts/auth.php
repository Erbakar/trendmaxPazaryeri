<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="app-base-path" content="<?= htmlspecialchars(function_exists('app_base_path') ? app_base_path() : '', ENT_QUOTES) ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($data['_csrf'] ?? '', ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($data['pageTitle'] ?? 'Giriş') ?> - <?= htmlspecialchars(brand('site_name')) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('css/app.css')) ?>?v=20260501">
    <script src="<?= htmlspecialchars(asset('vendor/lucide/lucide.min.js')) ?>"></script>
    <style>
    :root {
        --auth-bg: #dfe4ec;
        --auth-card-bg: #ffffff;
        --auth-card-border: rgba(15, 23, 42, .08);
        --auth-shadow: 0 32px 90px rgba(15, 23, 42, .16);
        --auth-text: #101826;
        --auth-muted: #7c8798;
        --auth-line: #e8edf4;
        --auth-input-border: #e3e8f0;
        --auth-input-bg: #fbfcfe;
        --auth-blue: #1967f2;
        --auth-blue-dark: #1355cb;
        --auth-blue-soft: #edf4ff;
        --auth-visual-bg: linear-gradient(180deg, #0f63ea 0%, #1466ee 45%, #1b6ef7 100%);
        --auth-white-soft: rgba(255, 255, 255, .78);
    }

    * { box-sizing: border-box; }

    html,
    body {
        min-height: 100%;
    }

    body {
        margin: 0;
        font-family: 'Gilroy', sans-serif;
        color: var(--auth-text);
        background: linear-gradient(180deg, #e6eaf1 0%, #d8dde7 100%);
    }

    .auth-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 28px;
    }

    .auth-stage {
        width: min(1040px, 100%);
        min-height: 650px;
        display: grid;
        grid-template-columns: minmax(360px, 46%) minmax(360px, 54%);
        background: var(--auth-card-bg);
        border: 1px solid var(--auth-card-border);
        border-radius: 18px;
        overflow: hidden;
        box-shadow: var(--auth-shadow);
    }

    .auth-login,
    .auth-visual {
        min-width: 0;
        position: relative;
    }

    .auth-login {
        padding: 38px 42px 30px;
        background: #ffffff;
        display: flex;
        align-items: stretch;
    }

    .auth-login-inner {
        width: 100%;
        max-width: 336px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        min-height: 100%;
    }

    .auth-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        gap: 10px;
        align-self: center;
        color: #172033;
        text-decoration: none;
        font-size: 18px;
        font-weight:700;
        letter-spacing: -.02em;
    }
    .auth-brand.is-image {
        min-height: 64px;
    }
    .auth-brand-image {
        max-width: min(100%, 280px);
        max-height: 58px;
        width: auto;
        height: auto;
        object-fit: contain;
        display: block;
    }

    .auth-brand-mark {
        width: 22px;
        height: 22px;
        border-radius: 7px;
        background: linear-gradient(180deg, var(--auth-blue) 0%, var(--auth-blue-dark) 100%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 18px rgba(25, 103, 242, .22);
    }

    .auth-brand-mark i[data-lucide] {
        width: 12px;
        height: 12px;
        color: #ffffff;
        stroke-width: 2.5;
    }

    .auth-login-body {
        width: 100%;
        margin: 30px 0 0;
    }

    .auth-login-title {
        margin: 0;
        font-size: 36px;
        line-height: 1.02;
        letter-spacing: -.05em;
        font-weight:700;
        color: #101826;
    }

    .auth-login-copy {
        margin: 12px 0 0;
        color: var(--auth-muted);
        font-size: 13px;
        line-height: 1.7;
    }

    .auth-error {
        margin-top: 18px;
        padding: 13px 14px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border-radius: 14px;
        background: #fff1f2;
        border: 1px solid #fecdd3;
        color: #be123c;
        font-size: 12px;
        line-height: 1.55;
    }

    .auth-form {
        margin-top: 24px;
        display: grid;
        gap: 14px;
    }

    .auth-field {
        display: grid;
        gap: 7px;
    }

    .auth-label {
        color: #6f7a8d;
        font-size: 12px;
        font-weight:700;
    }

    .auth-input-wrap {
        min-height: 52px;
        border: 1px solid var(--auth-input-border);
        border-radius: 14px;
        background: var(--auth-input-bg);
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0 16px;
        transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }

    .auth-input-wrap:focus-within {
        border-color: rgba(25, 103, 242, .42);
        box-shadow: 0 0 0 4px rgba(25, 103, 242, .11);
        transform: translateY(-1px);
    }

    .auth-input-wrap i[data-lucide] {
        width: 17px;
        height: 17px;
        color: #8b95a6;
        flex-shrink: 0;
    }

    .auth-input {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        color: #111827;
        font-family: inherit;
        font-size: 14px;
        font-weight:700;
    }

    .auth-input::placeholder {
        color: #a0a9b9;
        font-weight: 500;
    }

    /* Tarayıcı autofill sarı arka planını beyaza zorla */
    .auth-input:-webkit-autofill,
    .auth-input:-webkit-autofill:hover,
    .auth-input:-webkit-autofill:focus,
    .auth-input:-webkit-autofill:active {
        -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
        box-shadow: 0 0 0 1000px #ffffff inset !important;
        -webkit-text-fill-color: #111827 !important;
        background-color: #ffffff !important;
        transition: background-color 5000s ease-in-out 0s;
    }

    .auth-toggle {
        width: 28px;
        height: 28px;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: #8b95a6;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background-color .18s ease, color .18s ease;
    }

    .auth-toggle:hover {
        background: #eef3fa;
        color: #172033;
    }

    .auth-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        margin-top: 2px;
        color: #758093;
        font-size: 12px;
    }

    .auth-remember {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        font-weight:700;
        cursor: pointer;
        user-select: none;
    }

    .auth-remember input {
        width: 15px;
        height: 15px;
        accent-color: var(--auth-blue);
    }

    .auth-meta a {
        color: var(--auth-blue);
        text-decoration: none;
        font-weight:700;
    }

    .auth-meta a:hover {
        text-decoration: underline;
    }

    .auth-btn {
        min-height: 48px;
        margin-top: 2px;
        border: 0;
        border-radius: 8px;
        background: var(--auth-blue);
        color: #ffffff;
        font-family: inherit;
        font-size: 14px;
        font-weight:700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: background-color .18s ease, opacity .18s ease;
    }

    .auth-btn:hover {
        background: var(--auth-blue-dark);
    }

    .auth-btn:disabled {
        opacity: .82;
        cursor: wait;
    }

    .auth-footnote {
        margin: auto 0 0;
        padding-top: 28px;
        color: #98a1b2;
        font-size: 12px;
        line-height: 1.65;
    }

    .auth-visual {
        background: var(--auth-visual-bg);
        color: #ffffff;
        overflow: hidden;
        isolation: isolate;
    }

    .auth-visual::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at 30% 26%, rgba(255, 255, 255, .16), transparent 28%),
            radial-gradient(circle at 70% 78%, rgba(255, 255, 255, .10), transparent 26%);
        z-index: -2;
    }

    .auth-visual::after {
        content: '';
        position: absolute;
        inset: 0;
        background:
            linear-gradient(90deg, rgba(255,255,255,.10) 0 2px, transparent 2px 100%),
            linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,0));
        background-size: 112px 100%, 100% 100%;
        opacity: .22;
        z-index: -1;
    }

    .auth-visual-inner {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 34px 42px;
        text-align: center;
    }

    .visual-stage {
        position: relative;
        width: min(100%, 398px);
        height: 350px;
        margin-bottom: 8px;
    }

    .visual-orbit {
        position: absolute;
        inset: 30px auto auto 50%;
        width: 256px;
        height: 256px;
        transform: translateX(-50%);
        border-radius: 999px;
        background: radial-gradient(circle at 30% 30%, rgba(62, 171, 255, .54) 0%, rgba(46, 124, 255, .24) 55%, rgba(46, 124, 255, 0) 78%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .18);
    }

    .visual-orbit::before {
        content: '';
        position: absolute;
        inset: 14px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, .09);
    }

    .visual-link {
        position: absolute;
        top: 142px;
        left: 70px;
        right: 70px;
        height: 16px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .18);
    }

    .visual-link::before,
    .visual-link::after {
        content: '';
        position: absolute;
        top: -58px;
        width: 12px;
        height: 132px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .13);
    }

    .visual-link::before {
        left: 72px;
    }

    .visual-link::after {
        right: 72px;
    }

    .visual-card {
        position: absolute;
        top: 92px;
        left: 50%;
        width: 182px;
        padding: 16px 14px 14px;
        border-radius: 16px;
        background: rgba(255, 255, 255, .96);
        color: #243047;
        box-shadow: 0 24px 40px rgba(7, 45, 110, .24);
        transform: translateX(-50%);
        z-index: 3;
    }

    .visual-card-top {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 14px;
    }

    .visual-card-top span {
        width: 7px;
        height: 7px;
        border-radius: 999px;
    }

    .visual-card-top span:nth-child(1) { background: #ff7b7b; }
    .visual-card-top span:nth-child(2) { background: #ffd25d; }
    .visual-card-top span:nth-child(3) { background: #49d287; }

    .visual-item {
        display: grid;
        grid-template-columns: 28px 1fr;
        gap: 10px;
        align-items: center;
    }

    .visual-item + .visual-item {
        margin-top: 12px;
    }

    .visual-avatar {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        background: linear-gradient(180deg, #d9ebff 0%, #b7d7ff 100%);
        position: relative;
        overflow: hidden;
    }

    .visual-avatar::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 999px;
        background: #7eaef4;
    }

    .visual-avatar::after {
        content: '';
        position: absolute;
        left: 4px;
        right: 4px;
        bottom: 3px;
        height: 12px;
        border-radius: 10px 10px 6px 6px;
        background: #8fc1ff;
    }

    .visual-lines {
        display: grid;
        gap: 5px;
    }

    .visual-line {
        height: 7px;
        border-radius: 999px;
        background: #d8e6f7;
    }

    .visual-line.is-short {
        width: 66%;
    }

    .market-badge {
        position: absolute;
        left: 50%;
        top: 158px;
        width: 68px;
        height: 68px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .98);
        border: 1px solid rgba(255, 255, 255, .35);
        box-shadow: 0 20px 34px rgba(4, 39, 97, .24);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        z-index: 4;
    }

    .market-badge img {
        width: 40px;
        height: 28px;
        object-fit: contain;
    }

    .market-badge.is-trendyol {
        transform: translate(-50%, -50%) rotate(318deg) translateY(-144px) rotate(-318deg);
    }

    .market-badge.is-amazon {
        transform: translate(-50%, -50%) rotate(270deg) translateY(-144px) rotate(-270deg);
    }

    .market-badge.is-hepsiburada {
        transform: translate(-50%, -50%) rotate(222deg) translateY(-144px) rotate(-222deg);
    }

    .market-badge.is-n11 {
        transform: translate(-50%, -50%) rotate(0deg) translateY(-144px) rotate(0deg);
    }

    .market-badge.is-pazarama {
        transform: translate(-50%, -50%) rotate(42deg) translateY(-144px) rotate(-42deg);
    }

    .market-badge.is-pttavm {
        transform: translate(-50%, -50%) rotate(100deg) translateY(-144px) rotate(-100deg);
    }

    .market-badge.is-ciceksepeti {
        transform: translate(-50%, -50%) rotate(180deg) translateY(-144px) rotate(-180deg);
    }

    .auth-visual-title {
        margin: 0;
        font-size: 28px;
        line-height: 1.08;
        letter-spacing: -.04em;
        font-weight:700;
        color: #ffffff;
        max-width: 390px;
    }

    .auth-visual-copy {
        margin: 12px 0 0;
        color: rgba(255, 255, 255, .76);
        font-size: 13px;
        line-height: 1.7;
        max-width: 390px;
    }

    .auth-visual-dots {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        margin-top: 14px;
    }

    .auth-visual-dots span {
        width: 6px;
        height: 6px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .38);
    }

    .auth-visual-dots span.is-active {
        background: #ffffff;
    }

    @media (max-width: 900px) {
        .auth-page {
            padding: 0;
        }

        .auth-stage {
            grid-template-columns: 1fr;
            min-height: 100vh;
        }

        .auth-visual {
            display: none;
        }

        .auth-login {
            padding: 28px 24px 24px;
        }

        .auth-login-inner {
            justify-content: center;
        }

        .auth-login-body {
            margin-top: 34px;
        }
    }
    </style>
</head>
<body>
<div class="auth-page">
    <?= $content ?? '' ?>
</div>
<script>
lucide.createIcons();
</script>
</body>
</html>
