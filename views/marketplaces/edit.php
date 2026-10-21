<?php 
$mp = $data['marketplace'] ?? []; 
$extraConfig = json_decode($mp['extra_config_json'] ?? '{}', true) ?: [];

$marketplaceInfo = [
    'trendyol' => ['name' => 'Trendyol', 'color' => '#f27a1a', 'gradient' => '#f27a1a', 'logo' => '/public/assets/img/marketplaces/trendyol.svg'],
    'hepsiburada' => ['name' => 'Hepsiburada', 'color' => '#ff6000', 'gradient' => '#ff6000', 'logo' => '/public/assets/img/marketplaces/hepsiburada.svg'],
    'n11' => ['name' => 'n11', 'color' => '#7b2d96', 'gradient' => '#7b2d96', 'logo' => '/public/assets/img/marketplaces/n11.svg'],
    'amazon' => ['name' => 'Amazon TR', 'color' => '#ff9900', 'gradient' => '#ff9900', 'logo' => '/public/assets/img/marketplaces/amazon.svg'],
    'pazarama' => ['name' => 'Pazarama', 'color' => '#00a8e1', 'gradient' => '#00a8e1', 'logo' => '/public/assets/img/marketplaces/pazarama.svg'],
    'pttavm' => ['name' => 'PttAVM', 'color' => '#ffc107', 'gradient' => '#ffc107', 'logo' => '/public/assets/img/marketplaces/pttavm.svg'],
    'ciceksepeti' => ['name' => 'ÇiçekSepeti', 'color' => '#e91e63', 'gradient' => '#e91e63', 'logo' => '/public/assets/img/marketplaces/ciceksepeti.svg'],
    'idefix' => ['name' => 'İdefix', 'color' => '#1a1a2e', 'gradient' => '#1a1a2e', 'logo' => '/public/assets/img/marketplaces/idefix.png'],
];

$marketplaceInfo['oziygo'] = ['name' => 'OziyGO', 'color' => 'var(--primary)', 'gradient' => 'var(--primary)', 'logo' => '/public/assets/img/marketplaces/oziygo.png'];
$marketplaceInfo['shopify'] = ['name' => 'Shopify', 'color' => '#95bf47', 'gradient' => '#95bf47', 'logo' => '/public/assets/img/marketplaces/shopify.svg'];
$marketplaceInfo['woocommerce'] = ['name' => 'WooCommerce', 'color' => '#7f54b3', 'gradient' => '#7f54b3', 'logo' => '/public/assets/img/marketplaces/woocommerce.png'];
$marketplaceInfo['opencart'] = ['name' => 'OpenCart', 'color' => '#1c8ed8', 'gradient' => '#1c8ed8', 'logo' => '/public/assets/img/marketplaces/opencart.png'];

$slug = $mp['slug'] ?? '';
$info = $marketplaceInfo[$slug] ?? [
    'name' => $mp['display_name'] ?? $slug,
    'color' => 'var(--primary)',
    'gradient' => 'var(--primary)',
    'logo' => '',
];

$savedPct = (float)($extraConfig['price_adjustment_percent'] ?? 0);
$direction = $savedPct < 0 ? 'decrease' : 'increase';
$absValue = abs($savedPct);
?>

<style>
.mp-toggle{position:relative;width:44px;height:24px;flex-shrink:0}
.mp-toggle input{opacity:0;width:0;height:0}
.mp-toggle .slider{position:absolute;inset:0;background:#cbd5e1;border-radius:24px;cursor:pointer;transition:all .25s}
.mp-toggle .slider::before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:all .25s;box-shadow:0 0 0 1px var(--border)}
.mp-toggle input:checked+.slider{background:#10b981}
.mp-toggle input:checked+.slider::before{transform:translateX(20px)}
.mp-header{background:#fff;padding:28px 32px;border-radius:12px;margin-bottom:24px;display:flex;align-items:center;gap:20px;box-shadow:0 0 0 1px var(--border);border:1px solid var(--border-color);position:relative;overflow:hidden}
.mp-header::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:<?= $info['gradient'] ?>}
.mp-header-logo{width:64px;height:64px;border-radius:12px;padding:12px;background:#fff;border:2px solid <?= $info['color'] ?>;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px var(--border)}
.mp-header-logo img{width:100%;height:100%;object-fit:contain}
.mp-header-info h1{margin:0 0 4px;font-size:22px;font-weight:600;color:<?= $info['color'] ?>}
.mp-header-info p{margin:0;font-size:13px;color:var(--text-secondary)}
.mp-grid{display:grid;grid-template-columns:2fr 1fr;gap:24px}
@media(max-width:1024px){.mp-grid{grid-template-columns:1fr}}
.mp-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;overflow:hidden;box-shadow:0 0 0 1px var(--border);margin-bottom:20px}
.mp-card-head{padding:16px 20px;border-bottom:1px solid var(--border-color);background:#f8fafc;display:flex;align-items:center;gap:10px}
.mp-card-head h3{margin:0;font-size:15px;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.mp-card-head h3 i{width:18px;height:18px;color:<?= $info['color'] ?>}
.mp-card-body{padding:20px}
.toggle-row{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg-secondary);border-radius:10px;cursor:pointer;border:1.5px solid var(--border-color);transition:border-color .25s}
.toggle-row.active{border-color:#10b981}
.toggle-row-text{flex:1}
.toggle-row-text .title{font-weight:600;font-size:13px;margin-bottom:2px;display:flex;align-items:center;gap:6px}
.toggle-row-text .title i{width:14px;height:14px}
.toggle-row-text .desc{font-size:12px;color:var(--text-secondary)}
.mp-help{background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;margin-bottom:16px}
.mp-help h4{margin:0 0 6px;font-size:13px;font-weight:600;color:#0284c7;display:flex;align-items:center;gap:6px}
.mp-help h4 i{width:14px;height:14px}
.mp-help p{margin:0;font-size:12px;color:#0c4a6e;line-height:1.6}
.mp-help ul{margin:6px 0 0;padding-left:18px;font-size:12px;color:#0c4a6e}
.mp-help li{margin:3px 0}
.mp-help-green{background:#f0fdf4;border-color:#bbf7d0}
.mp-help-green h4{color:#16a34a}
.mp-help-green p{color:#166534}
.mp-status-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9}
.mp-status-row:last-child{border-bottom:none}
.mp-status-label{font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:6px}
.mp-status-label i{width:14px;height:14px}
.mp-status-val{font-weight:600;font-size:13px}
.price-dir-group{display:flex;border-radius:8px;overflow:hidden;border:1.5px solid var(--border-color)}
.price-dir-btn{padding:8px 16px;border:none;cursor:pointer;font-weight:600;font-size:13px;transition:all .2s;display:flex;align-items:center;gap:5px;background:#f8fafc;color:#64748b}
.price-dir-btn i{width:14px;height:14px}
.price-dir-btn.active-inc{background:#10b981;color:#fff}
.price-dir-btn.active-dec{background:#ef4444;color:#fff}
.mp-card .form-group{margin-bottom:16px}
.mp-card .form-group:last-child{margin-bottom:0}
.mp-card .form-label{font-size:13px;font-weight:600;margin-bottom:6px;display:block;color:var(--text-primary)}
.mp-card .form-help{font-size:11px;color:var(--text-muted);margin-top:4px;display:block}
.mp-btn-group{display:flex;gap:10px}
.mp-btn-group .btn{display:flex;align-items:center;justify-content:center;gap:6px;font-size:13px;font-weight:600;padding:10px 20px}
.mp-btn-group .btn i{width:16px;height:16px}
</style>

<div style="margin-bottom:20px">
    <a href="/marketplaces" class="btn btn-sm btn-secondary" style="display:inline-flex;align-items:center;gap:5px">
        <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Pazaryerlerine Dön
    </a>
</div>

<div class="mp-header">
    <div class="mp-header-logo">
        <?php if (!empty($info['logo'])): ?>
            <img src="<?= $info['logo'] ?>" alt="<?= htmlspecialchars($info['name']) ?>">
        <?php else: ?>
            <i data-lucide="store" style="width:32px;height:32px;color:<?= $info['color'] ?>"></i>
        <?php endif; ?>
    </div>
    <div class="mp-header-info">
        <h1><?= htmlspecialchars($info['name']) ?> Ayarları</h1>
        <p>API bilgilerinizi girin ve pazaryeri entegrasyonunu aktif edin</p>
    </div>
</div>

<form method="POST" action="/marketplaces/<?= $mp['id'] ?>" id="settingsForm">
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>">

<div class="mp-grid">
    <div>
        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="settings"></i> Genel Ayarlar</h3></div>
            <div class="mp-card-body">
                <div class="form-group">
                    <label class="form-label">Görünen Ad</label>
                    <input type="text" name="display_name" class="form-input" value="<?= htmlspecialchars($mp['display_name'] ?? '') ?>" required>
                    <small class="form-help">Panelde görünecek pazaryeri adı</small>
                </div>
                <div class="toggle-row <?= (int)($mp['is_active'] ?? 0) ? 'active' : '' ?>" onclick="this.querySelector('input').click()">
                    <label class="mp-toggle" onclick="event.stopPropagation()">
                        <input type="checkbox" name="is_active" value="1" <?= (int)($mp['is_active'] ?? 0) ? 'checked' : '' ?> onchange="this.closest('.toggle-row').classList.toggle('active',this.checked)">
                        <span class="slider"></span>
                    </label>
                    <div class="toggle-row-text">
                        <div class="title"><i data-lucide="power"></i> Pazaryeri Aktif</div>
                        <div class="desc">Entegrasyonu etkinleştir ve senkronizasyona başla</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="key"></i> API Bilgileri</h3></div>
            <div class="mp-card-body">
                <?php if ($slug === 'trendyol'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> Trendyol API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>Trendyol Seller Center'a giriş yapın</li>
                        <li>Ayarlar → Entegrasyon → API bölümüne gidin</li>
                        <li>API Key ve API Secret bilgilerinizi kopyalayın</li>
                        <li>Supplier ID (Mağaza ID) bilginizi not edin</li>
                    </ul>
                </div>
                <?php elseif ($slug === 'n11'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> N11 API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>N11 Satıcı Paneline giriş yapın</li>
                        <li>Mağazam → Entegrasyon → API Bilgileri bölümüne gidin</li>
                        <li><strong>App Key</strong> ve <strong>App Secret</strong> bilgilerinizi kopyalayın</li>
                        <li>N11'de Supplier ID gerekmez, sadece App Key ve Secret yeterlidir</li>
                    </ul>
                </div>
                <?php elseif ($slug === 'hepsiburada'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> Hepsiburada API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>Hepsiburada Satıcı Paneline giriş yapın</li>
                        <li><strong>Bilgilerim → Entegrasyon → Entegratör Bilgileri</strong> bölümüne gidin</li>
                        <li>Entegratörünüzü bulup <strong>Servis Anahtarı</strong> butonuna tıklayın ve kopyalayın</li>
                        <li>Aynı sayfadaki <strong>Mağaza ID (Merchant ID)</strong> değerini kopyalayın (örn. a1b2c3d4-e5f6-7890-abcd-ef1234567890 biçiminde)</li>
                        <li><strong>Entegratör Kullanıcı Adı</strong>: Merchant Panel'de yetki vereceğiniz sabit entegratör adı <strong>oziyweb_dev</strong></li>
                        <li>Hepsiburada'da ayrı API Key yoktur — Servis Anahtarı ve Merchant ID yeterlidir</li>
                        <li><em>Not: Yeni entegratör eklendikten sonra yetkilendirmenin devreye girmesi 2 saate kadar sürebilir</em></li>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'amazon'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> Amazon SP-API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li><a href="https://sellercentral.amazon.com.tr" target="_blank"><strong>Amazon Seller Central</strong></a>'a giriş yapın</li>
                        <li><strong>Uygulamalar & Hizmetler → Uygulama Geliştir</strong> bölümünden uygulamanızı oluşturun</li>
                        <li><strong>LWA Client ID</strong> ve <strong>LWA Client Secret</strong> bilgilerinizi kopyalayın</li>
                        <li><strong>Seller ID</strong>: Seller Central → Hesap Bilgileri → İşletme Bilgileri'nden alabilirsiniz</li>
                        <li>OAuth yetkilendirme sonrası <strong>Refresh Token</strong> otomatik olarak alınır veya Amazon'dan test için verilen tokeni girebilirsiniz</li>
                        <li>Amazon SP-API, <strong>LWA (Login with Amazon) OAuth2</strong> protokolü kullanır</li>
                        <li>Detaylı bilgi: <a href="https://developer-docs.amazon.com/sp-api" target="_blank">developer-docs.amazon.com/sp-api</a></li>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'pazarama'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> Pazarama API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>Pazarama İş Ortağım paneline giriş yapın: <strong>isortagim.pazarama.com</strong></li>
                        <li><strong>Hesap Bilgileri</strong> bölümüne gidin</li>
                        <li><strong>Client ID</strong> ve <strong>Client Secret</strong> bilgilerinizi kopyalayın</li>
                        <li>Pazarama OAuth2 kullanır — Token otomatik olarak 1 saatlik alınır ve yenilenir</li>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'pttavm'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> PttAVM API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>PttAVM Satıcı Paneline giriş yapın</li>
                        <li><strong>Entegrasyon Ayarları</strong> bölümüne gidin</li>
                        <li>Entegratör olarak <strong>Entegratör Yazılımı</strong> seçin</li>
                        <li><strong>Access Token</strong> bilginizi kopyalayın ve aşağıdaki alana girin</li>
                        <li>PttAVM API Key entegratöre ait sabit bilgidir; panel otomatik kullanır</li>
                        <li>Detaylı bilgi: <a href="https://developers.pttavm.com/tr" target="_blank">developers.pttavm.com</a></li>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'ciceksepeti'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> ÇiçekSepeti API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>ÇiçekSepeti Satıcı Paneline giriş yapın</li>
                        <li><strong>Hesap Yönetimi > Entegrasyon Bilgilerim</strong> bölümüne gidin</li>
                        <li><strong>API Key</strong> bilginizi kopyalayın</li>
                        <li>ÇiçekSepeti REST API kullanır — x-api-key header ile kimlik doğrulaması yapılır</li>
                        <li>API key yoksa ÇiçekSepeti Destek Ekibi ile iletişime geçin</li>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'idefix'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> İdefix API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>İdefix Satıcı Paneline giriş yapın</li>
                        <li><strong>Hesap Bilgilerim → Entegrasyon Bilgileri</strong> bölümüne gidin</li>
                        <li><strong>Yeni API Oluştur</strong> butonuna tıklayın — Satıcı ID, Token ve Secret Key e-postanıza gönderilecektir</li>
                        <li><strong>Token</strong> → Token alanına, <strong>Secret</strong> → Secret Key alanına, <strong>Satıcı ID</strong> → Vendor ID alanına girin</li>
                        <li>Detaylı bilgi: <a href="https://developer.idefix.com" target="_blank">developer.idefix.com</a></li>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'oziygo'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> OziyGO Bridge Bilgileri</h4>
                    <ul>
                        <li>OziyGO admin panelinde OzyConnect Entegrasyon modulunu kurun</li>
                        <li>Kurulumun verdigi API URL ve API anahtarini buraya girin</li>
                        <li>Ornek API URL: https://site.com/eklentiler/ozyconnect/api.php</li>
                    </ul>
                </div>
                <div class="form-group">
                    <label class="form-label">API URL <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative">
                        <input type="url" name="api_url" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['api_url'] ?? '')) ?>" placeholder="https://site.com/eklentiler/ozyconnect/api.php" required>
                        <i data-lucide="link" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">OziyGO tarafindaki bridge api.php dosyasinin tam adresi</small>
                </div>
                <?php endif; ?>
                <?php if (!in_array($slug, ['hepsiburada', 'pttavm', 'shopify', 'woocommerce', 'opencart'], true)): ?>
                <div class="form-group">
                    <label class="form-label"><?= $slug === 'pazarama' ? 'API Key' : ($slug === 'amazon' ? 'LWA Client ID' : ($slug === 'idefix' ? 'Token' : 'API Key')) ?></label>
                    <div style="position:relative">
                        <input type="text" name="api_key" class="form-input" style="padding-left:36px" placeholder="<?= !empty($mp['api_key_encrypted']) ? '••••••••••••••• (değiştirmek için yeni girin)' : ($slug === 'pazarama' ? 'Pazarama API Key giriniz' : ($slug === 'amazon' ? 'amzn1.application-oa2-client.xxxxx' : ($slug === 'idefix' ? 'Örn: 23b5fb95-8838-4e77-88e8-xxxxxxxxxxxx' : 'API Key giriniz'))) ?>">
                        <i data-lucide="key-round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help"><?= $slug === 'amazon' ? "Amazon Developer Console'dan alınan LWA Client ID" : ($slug === 'idefix' ? 'İdefix tarafından e-posta ile gönderilen Token bilgisi' : 'Pazaryeri tarafından sağlanan API anahtarı') ?></small>
                </div>
                <?php endif; ?>
                <?php if (!in_array($slug, ['ciceksepeti', 'oziygo', 'shopify', 'woocommerce', 'opencart'], true)): ?>
                <div class="form-group">
                    <label class="form-label" for="apiSecretInput"><?= $slug === 'hepsiburada' ? 'Servis Anahtarı' : ($slug === 'pazarama' ? 'API Secret' : ($slug === 'pttavm' ? 'Access Token' : ($slug === 'amazon' ? 'LWA Client Secret' : ($slug === 'idefix' ? 'Secret Key' : 'API Secret')))) ?></label>
                    <div style="position:relative">
                        <input type="password" name="api_secret" id="apiSecretInput" class="form-input" style="padding-left:36px;padding-right:36px"<?= ($slug === 'hepsiburada' && empty($mp['api_secret_encrypted'])) ? ' required aria-required="true"' : '' ?> autocomplete="new-password" placeholder="<?= !empty($mp['api_secret_encrypted']) ? '••••••••••••••• (değiştirmek için yeni girin)' : ($slug === 'hepsiburada' ? 'Servis anahtarınızı giriniz' : ($slug === 'pazarama' ? 'Pazarama API Secret giriniz' : ($slug === 'pttavm' ? 'Access Token giriniz' : ($slug === 'amazon' ? 'amzn1.oa2-cs.v1.xxxxx' : 'API Secret giriniz')))) ?>">
                        <i data-lucide="lock" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                        <button type="button" onclick="toggleSecret()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px">
                            <i data-lucide="eye" id="secretEyeIcon" style="width:16px;height:16px;color:var(--text-muted)"></i>
                        </button>
                    </div>
                    <small class="form-help"><?= $slug === 'hepsiburada' ? 'Hepsiburada satıcı panelinden alınan servis anahtarınız' : ($slug === 'pazarama' ? 'Pazarama İş Ortağım panelinden alınan Client Secret' : ($slug === 'pttavm' ? 'PttAVM entegrasyon panelinden alınan Access Token' : ($slug === 'amazon' ? "Amazon Developer Console'dan alınan LWA Client Secret" : ($slug === 'idefix' ? 'İdefix tarafından e-posta ile gönderilen Secret Key bilgisi' : 'API anahtarınızın gizli şifresi')))) ?></small>
                </div>
                <?php endif; ?>
                <?php if ($slug === 'pazarama'): ?>
                <div class="form-group">
                    <label class="form-label">Satıcı ID</label>
                    <div style="position:relative">
                        <input type="text" name="seller_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars($extraConfig['seller_id'] ?? '') ?>" placeholder="Örn: a86b25dd-0f0e-41f2-0f69-08decdeba3fb">
                        <i data-lucide="hash" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Pazarama İş Ortağım panelinden alınan Satıcı ID (GUID)</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Panel Adresi <span style="color:var(--text-muted);font-weight:400">(isteğe bağlı)</span></label>
                    <div style="position:relative">
                        <input type="url" name="app_url" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars($extraConfig['app_url'] ?? '') ?>" placeholder="https://panel.alanadiniz.com">
                        <i data-lucide="link" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Pazarama'ya gönderilen fatura bağlantısında kullanılır; boşsa fatura linki gönderilmez</small>
                </div>
                <?php elseif ($slug === 'trendyol'): ?>
                <div class="form-group">
                    <label class="form-label">Supplier ID (Mağaza ID)</label>
                    <div style="position:relative">
                        <input type="text" name="supplier_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars($extraConfig['supplier_id'] ?? '') ?>" placeholder="Örn: 123456">
                        <i data-lucide="hash" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Trendyol Seller Center'dan alınan mağaza ID'niz</small>
                </div>
                <?php elseif ($slug === 'hepsiburada'): ?>
                <div class="form-group">
                    <label class="form-label">Entegratör Kullanıcı Adı</label>
                    <div style="position:relative">
                        <input type="text" class="form-input" style="padding-left:36px" value="oziyweb_dev" disabled>
                        <i data-lucide="user" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Hepsiburada Merchant Panel'de yetki vereceğiniz sabit entegratör adı: oziyweb_dev</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="hbMerchantId">Mağaza ID (Merchant ID)</label>
                    <div style="position:relative">
                        <input type="text" name="merchant_id" id="hbMerchantId" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars($extraConfig['merchant_id'] ?? '') ?>" placeholder="Örn: a1b2c3d4-e5f6-7890-abcd-ef1234567890" pattern="[0-9a-fA-F\-]{20,64}" title="Hepsiburada panelindeki Mağaza ID değeri (harf, rakam ve tire; 20-64 karakter)" autocomplete="off" spellcheck="false" aria-describedby="hbMerchantIdHelp"<?= empty($extraConfig['merchant_id']) ? ' required aria-required="true"' : '' ?>>
                        <i data-lucide="hash" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help" id="hbMerchantIdHelp">Hepsiburada satıcı paneli › Bilgilerim › Entegrasyon › Entegratör Bilgileri sayfasındaki Mağaza ID (Basic auth kullanıcı adı)</small>
                </div>
                <?php elseif ($slug === 'pttavm'): ?>
                <div class="form-group">
                    <label class="form-label">Entegratör</label>
                    <div style="position:relative">
                        <input type="text" class="form-input" style="padding-left:36px" value="Entegratör Yazılımı" disabled>
                        <i data-lucide="plug" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">PttAVM panelinde entegratör olarak Entegratör Yazılımı seçilmelidir; API Key otomatik kullanılır.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Shop ID (Mağaza ID)</label>
                    <div style="position:relative">
                        <input type="text" name="shop_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['shop_id'] ?? '')) ?>" placeholder="Varsa mağaza ID'nizi girin">
                        <i data-lucide="hash" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Stok/fiyat güncellemelerinde kullanılır (opsiyonel)</small>
                </div>

                <?php elseif ($slug === 'n11'): ?>
                <div class="form-group">
                    <label class="form-label">Entegratör Adı <span style="color:var(--text-muted);font-weight:400">(isteğe bağlı)</span></label>
                    <div style="position:relative">
                        <input type="text" name="integrator_username" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars($extraConfig['integrator_name'] ?? $extraConfig['integrator_username'] ?? '') ?>" placeholder="OzyConnect">
                        <i data-lucide="user-cog" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">n11'e gönderilen entegratör adı; boş bırakılırsa "OzyConnect" kullanılır</small>
                </div>
                <?php elseif ($slug === 'ciceksepeti'): ?>
                <div class="form-group">
                    <label class="form-label">Satıcı ID (Seller ID)</label>
                    <div style="position:relative">
                        <input type="text" name="seller_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['seller_id'] ?? '')) ?>" placeholder="Örn: 1500043252">
                        <i data-lucide="hash" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">ÇiçekSepeti satıcı panelindeki mağaza numaranız (User-Agent header'ında kullanılır)</small>
                </div>

                <?php elseif ($slug === 'idefix'): ?>
                <div class="form-group">
                    <label class="form-label">Vendor ID (Satıcı ID) <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative">
                        <input type="text" name="vendor_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['vendor_id'] ?? '')) ?>" placeholder="Örn: 21016" required>
                        <i data-lucide="hash" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">İdefix satıcı panelindeki benzersiz satıcı numaranız. Tüm API çağrılarında zorunludur.</small>
                </div>

                <?php elseif ($slug === 'amazon'): ?>
                <div class="form-group">
                    <label class="form-label">Refresh Token <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative">
                        <input type="password" name="refresh_token" id="amzRefreshToken" class="form-input" style="padding-left:36px;padding-right:36px" placeholder="<?= !empty($extraConfig['refresh_token']) ? '••••••••••••••• (değiştirmek için yeni girin)' : 'Amazon\'dan test için verilen token\'\u0131 buraya yap\u0131\u015ft\u0131r\u0131n' ?>">
                        <i data-lucide="refresh-cw" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                        <button type="button" onclick="toggleRefreshToken()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px">
                            <i data-lucide="eye" id="refreshTokenEyeIcon" style="width:16px;height:16px;color:var(--text-muted)"></i>
                        </button>
                    </div>
                    <small class="form-help">Amazon test uygulamasından verilen token. Süresiz geçerlidir.</small>
                    <?php if (!empty($extraConfig['refresh_token'])): ?>
                    <div style="margin-top:6px;display:flex;align-items:center;gap:6px">
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;background:#f0fdf4;border:1px solid #bbf7d0;font-size:11px;color:#16a34a;font-weight:600">
                            <i data-lucide="check-circle" style="width:12px;height:12px"></i> Refresh Token mevcut
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Seller ID <span style="font-size:11px;color:var(--text-muted);font-weight:400">(opsiyonel)</span></label>
                    <div style="position:relative">
                        <input type="text" name="seller_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['seller_id'] ?? '')) ?>" placeholder="Örn: A3XXXXXXXXXXXXXX">
                        <i data-lucide="store" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Seller Central → Hesap Bilgileri'nden alınır. Sipariş ve ürün işlemleri için gereklidir.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Application ID <span style="font-size:11px;color:var(--text-muted);font-weight:400">(opsiyonel)</span></label>
                    <div style="position:relative">
                        <input type="text" name="app_id" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['app_id'] ?? '')) ?>" placeholder="amzn1.sp.solution.xxxxx">
                        <i data-lucide="app-window" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">OAuth yetkilendirme URL'si oluşturmak için kullanılır. Test tokeniniz varsa gerekmez.</small>
                </div>

                <?php elseif ($slug === 'shopify'): ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> Shopify API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>Shopify admin → <strong>Settings → Apps and sales channels → Develop apps</strong></li>
                        <li><strong>Create an app</strong> → API scopes: <em>read/write_orders, products, inventory, fulfillments</em></li>
                        <li><strong>Install app</strong> → <strong>Admin API access token</strong>'ı kopyalayın (yalnızca bir kez gösterilir)</li>
                        <li>Mağaza alan adınız: <strong>magazaniz.myshopify.com</strong></li>
                    </ul>
                </div>
                <div class="form-group">
                    <label class="form-label">Mağaza Alan Adı <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative">
                        <input type="text" name="shop_domain" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['shop_domain'] ?? '')) ?>" placeholder="magazaniz.myshopify.com" required>
                        <i data-lucide="globe" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Shopify mağaza alan adınız (örn. magazaniz.myshopify.com)</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Admin API Access Token <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative">
                        <input type="password" name="api_secret" id="apiSecretInput" class="form-input" style="padding-left:36px;padding-right:36px" placeholder="<?= !empty($mp['api_secret_encrypted']) ? '••••••••••••••• (değiştirmek için yeni girin)' : 'shpat_xxxxxxxxxxxxxxxxxxxxxxxx' ?>">
                        <i data-lucide="lock" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                        <button type="button" onclick="toggleSecret()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px">
                            <i data-lucide="eye" id="secretEyeIcon" style="width:16px;height:16px;color:var(--text-muted)"></i>
                        </button>
                    </div>
                    <small class="form-help">Custom App'ten alınan Admin API access token (shpat_...)</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Webhook İmza Anahtarı <span style="font-size:11px;color:var(--text-muted);font-weight:400">(API secret key · shpss_… · anlık sipariş için)</span></label>
                    <div style="position:relative">
                        <input type="password" name="webhook_secret" class="form-input" style="padding-left:36px" placeholder="<?= !empty($extraConfig['webhook_secret']) ? '••••••••••••••• (kayıtlı)' : 'shpss_...' ?>">
                        <i data-lucide="shield" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Custom App → <b>API key and secret key</b> bölümündeki <b>API secret key</b>. Webhook imzalarını doğrulamak için.</small>
                </div>
                <?php if (!empty($mp['webhook_token'])): ?>
                <div class="form-group">
                    <label class="form-label">Webhook URL <span style="font-size:11px;color:var(--text-muted);font-weight:400">(Shopify → Settings → Notifications → Webhooks)</span></label>
                    <input type="text" readonly onclick="this.select()" value="https://ALAN-ADINIZ/webhooks/shopify/<?= htmlspecialchars((string)$mp['webhook_token']) ?>" class="form-input" style="font-family:monospace;font-size:12px">
                    <small class="form-help">"ALAN-ADINIZ" yerine canlı (HTTPS) alan adınızı yazıp Shopify'a ekleyin. Topic: <b>orders/create</b> (+ orders/updated), Format: JSON. <em>Localhost'ta webhook tetiklenmez.</em></small>
                </div>
                <?php else: ?>
                <small class="form-help" style="display:block;margin-top:-8px">Webhook URL'si ayarları kaydedince oluşturulur.</small>
                <?php endif; ?>

                <?php elseif ($slug === 'woocommerce'): ?>
                <?php
                $woo            = is_array($data['wooWebhook'] ?? null) ? $data['wooWebhook'] : [];
                $wooStoreUrl    = (string) ($extraConfig['store_url'] ?? '');
                $wooStoreIsHttp = stripos($wooStoreUrl, 'http://') === 0;
                $wooDeliveryUrl = (string) ($woo['delivery_url'] ?? '');
                $wooSecret      = (string) ($woo['secret'] ?? '');
                $wooSecretSaved = !empty($woo['secret_saved']);
                $wooConnected   = !empty($woo['connected']);
                $wooBaseLocal   = !empty($woo['base_is_local']);
                $wooHasKeys     = !empty($mp['api_key_encrypted']) && !empty($mp['api_secret_encrypted']);
                $wooCanEdit     = isset($data['_can']) && is_callable($data['_can']) ? (bool) ($data['_can'])('marketplaces.edit') : false;
                // Sır yalnız düzenleme yetkisi olana gelir (controller); salt-görüntülemede yalnız "kayıtlı" bilgisi
                $wooShowSecret  = $wooCanEdit && !empty($woo['can_edit']);
                $wooIconStyle   = 'position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)';
                ?>
                <style>
                .woo-field{display:flex;gap:8px;align-items:stretch}
                .woo-field-input{position:relative;flex:1;min-width:0}
                .woo-field-input .form-input{width:100%;padding-left:36px}
                .woo-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
                .woo-icon-btn{flex-shrink:0;width:40px;min-height:38px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border-color,#e2e8f0);border-radius:8px;background:var(--bg-secondary,#f8fafc);color:var(--text-secondary,#475569);cursor:pointer}
                .woo-icon-btn:hover{color:var(--text-primary,#0f172a)}
                .woo-icon-btn.is-done{color:#15803d;border-color:#86efac}
                .woo-icon-btn i,.woo-icon-btn svg{width:16px;height:16px}
                .woo-icon-btn [hidden]{display:none}
                .woo-icon-btn:focus-visible,.woo-auto .btn:focus-visible{outline:2px solid rgb(var(--brand,37 99 235));outline-offset:2px}
                .woo-note{display:flex;gap:8px;align-items:flex-start;margin-top:8px;padding:10px 12px;border-radius:8px;font-size:12px;line-height:1.5;border:1px solid #fde68a;background:#fffbeb;color:#92400e}
                .woo-note[hidden]{display:none}
                .woo-note i,.woo-note svg{width:14px;height:14px;flex-shrink:0;margin-top:2px}
                .woo-auto{padding:16px;border:1px solid var(--border-color,#e2e8f0);border-radius:10px;background:var(--bg-secondary,#f8fafc)}
                .woo-auto-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
                .woo-auto-title{font-size:13px;font-weight:600;color:var(--text-primary,#0f172a);margin:0 0 4px}
                .woo-auto-desc{font-size:12px;color:var(--text-secondary,#475569);margin:0;max-width:56ch;line-height:1.5}
                .woo-auto .btn{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600}
                .woo-auto .btn i,.woo-auto .btn svg{width:16px;height:16px}
                .woo-auto .btn[disabled]{opacity:.6;cursor:not-allowed}
                .woo-status{margin-top:12px;font-size:12px;line-height:1.5;color:var(--text-secondary,#475569)}
                .woo-status:empty{display:none}
                .woo-status[data-tone="ok"]{color:#15803d}
                .woo-status[data-tone="err"]{color:#b91c1c}
                .woo-status[data-tone="warn"]{color:#b45309}
                .woo-list{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:8px}
                .woo-list[hidden]{display:none}
                .woo-list li{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;padding:8px 12px;border-radius:8px;background:var(--bg-primary,#fff);border:1px solid var(--border-color,#e2e8f0)}
                .woo-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#dcfce7;color:#166534}
                .woo-badge.err{background:#fee2e2;color:#991b1b}
                .woo-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
                @media (prefers-reduced-motion:reduce){#wooSection *{transition:none!important;animation:none!important}}
                </style>
                <div id="wooSection">
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> WooCommerce API Bilgilerini Nereden Alırım?</h4>
                    <ul>
                        <li>WordPress yönetici → <strong>WooCommerce → Ayarlar (Settings) → Gelişmiş (Advanced) → REST API</strong></li>
                        <li><strong>Anahtar ekle (Add key)</strong> → İzin: <em>Okuma/Yazma (Read/Write)</em> → <strong>API anahtarı oluştur</strong></li>
                        <li>Oluşan <strong>Consumer key (ck_…)</strong> ve <strong>Consumer secret (cs_…)</strong> değerlerini aşağıya yapıştırın</li>
                        <li>Kaydedip <strong>Bağlantı Testi</strong> yapın, ardından <strong>Webhook'u otomatik kur</strong> ile anlık siparişi açın</li>
                    </ul>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wooStoreUrl">Mağaza Adresi (Store URL) <span style="color:var(--danger)" aria-hidden="true">*</span></label>
                    <div class="woo-field-input">
                        <input type="text" inputmode="url" name="store_url" id="wooStoreUrl" class="form-input" value="<?= htmlspecialchars($wooStoreUrl) ?>" placeholder="https://magazaniz.com" required aria-required="true" autocomplete="url" spellcheck="false" maxlength="500" aria-describedby="wooStoreUrlHelp<?= $wooStoreIsHttp ? ' wooHttpNote' : '' ?>">
                        <i data-lucide="globe" style="<?= $wooIconStyle ?>" aria-hidden="true"></i>
                    </div>
                    <small class="form-help" id="wooStoreUrlHelp">WooCommerce'in kurulu olduğu sitenin adresi; alt klasördeyse klasörüyle birlikte (örn. https://site.com/magaza). Sondaki / otomatik atılır.</small>
                    <div class="woo-note" id="wooHttpNote" role="note"<?= $wooStoreIsHttp ? '' : ' hidden' ?>>
                        <i data-lucide="shield-alert" aria-hidden="true"></i>
                        <span>HTTP bağlantısında imzalı (OAuth) yöntem kullanılır; Consumer Secret ağ üzerinden gönderilmez. Canlı mağazada <strong>https://</strong> adresi önerilir.</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wooConsumerKey">Consumer Key <span style="color:var(--danger)" aria-hidden="true">*</span></label>
                    <div class="woo-field-input">
                        <input type="text" name="api_key" id="wooConsumerKey" class="form-input woo-mono" value="" placeholder="<?= $wooHasKeys ? '••••••••••••••• (kayıtlı · değiştirmek için yenisini girin)' : 'ck_…' ?>" autocomplete="off" spellcheck="false" maxlength="100"<?= $wooHasKeys ? '' : ' required aria-required="true"' ?> aria-describedby="wooConsumerKeyHelp">
                        <i data-lucide="key" style="<?= $wooIconStyle ?>" aria-hidden="true"></i>
                    </div>
                    <small class="form-help" id="wooConsumerKeyHelp">REST API anahtarının Consumer key değeri ("ck_" ile başlar).</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wooConsumerSecret">Consumer Secret <span style="color:var(--danger)" aria-hidden="true">*</span></label>
                    <div class="woo-field">
                        <div class="woo-field-input">
                            <input type="password" name="api_secret" id="wooConsumerSecret" class="form-input woo-mono" placeholder="<?= $wooHasKeys ? '••••••••••••••• (kayıtlı · değiştirmek için yenisini girin)' : 'cs_…' ?>" autocomplete="new-password" spellcheck="false" maxlength="100"<?= $wooHasKeys ? '' : ' required aria-required="true"' ?> aria-describedby="wooConsumerSecretHelp">
                            <i data-lucide="lock" style="<?= $wooIconStyle ?>" aria-hidden="true"></i>
                        </div>
                        <button type="button" class="woo-icon-btn" data-woo-reveal="wooConsumerSecret" aria-pressed="false" aria-label="Consumer Secret'ı göster" data-label-show="Consumer Secret'ı göster" data-label-hide="Consumer Secret'ı gizle">
                            <span data-icon="show"><i data-lucide="eye" aria-hidden="true"></i></span><span data-icon="hide" hidden><i data-lucide="eye-off" aria-hidden="true"></i></span>
                        </button>
                    </div>
                    <small class="form-help" id="wooConsumerSecretHelp">REST API anahtarının Consumer secret değeri ("cs_" ile başlar). Key ile birlikte girilir.</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wooWebhookSecret">Webhook Gizli Anahtarı <span style="font-size:11px;color:var(--text-muted);font-weight:400">(sipariş bildirimlerinin imzası)</span></label>
                    <div class="woo-field">
                        <div class="woo-field-input">
                            <?php if ($wooShowSecret): ?>
                            <input type="password" name="webhook_secret" id="wooWebhookSecret" class="form-input woo-mono" value="<?= htmlspecialchars($wooSecret) ?>" placeholder="Boş bırakırsanız kaydederken otomatik üretilir" autocomplete="off" spellcheck="false" minlength="16" maxlength="200" aria-describedby="wooWebhookSecretHelp">
                            <?php else: ?>
                            <input type="password" id="wooWebhookSecret" class="form-input woo-mono" value="" placeholder="<?= $wooSecretSaved ? '••••••••••••••• (kayıtlı)' : 'Henüz oluşturulmadı' ?>" readonly aria-readonly="true" autocomplete="off" aria-describedby="wooWebhookSecretHelp">
                            <?php endif; ?>
                            <i data-lucide="shield" style="<?= $wooIconStyle ?>" aria-hidden="true"></i>
                        </div>
                        <?php if ($wooShowSecret): ?>
                        <button type="button" class="woo-icon-btn" data-woo-reveal="wooWebhookSecret" aria-pressed="false" aria-label="Webhook gizli anahtarını göster" data-label-show="Webhook gizli anahtarını göster" data-label-hide="Webhook gizli anahtarını gizle">
                            <span data-icon="show"><i data-lucide="eye" aria-hidden="true"></i></span><span data-icon="hide" hidden><i data-lucide="eye-off" aria-hidden="true"></i></span>
                        </button>
                        <button type="button" class="woo-icon-btn" data-woo-copy="wooWebhookSecret" aria-label="Webhook gizli anahtarını kopyala" data-copied="Webhook gizli anahtarı kopyalandı.">
                            <i data-lucide="copy" aria-hidden="true"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($wooShowSecret): ?>
                    <small class="form-help" id="wooWebhookSecretHelp">Otomatik kurulum bu anahtarı kullanır. Webhook'u WooCommerce'te elle kuruyorsanız <b>Secret</b> alanına aynı değeri yazın (en az 16 karakter, boşluksuz).</small>
                    <?php else: ?>
                    <small class="form-help" id="wooWebhookSecretHelp">Gizli anahtarı yalnız pazaryeri ayarlarını düzenleme yetkisi olan kullanıcılar görebilir.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="wooDeliveryUrl">Webhook Adresi <span style="font-size:11px;color:var(--text-muted);font-weight:400">(Delivery URL · yalnız bu mağazaya özel)</span></label>
                    <div class="woo-field">
                        <div class="woo-field-input">
                            <input type="text" id="wooDeliveryUrl" class="form-input woo-mono" readonly value="<?= htmlspecialchars($wooDeliveryUrl) ?>" placeholder="Ayarları kaydedince oluşturulur" aria-describedby="wooDeliveryUrlHelp">
                            <i data-lucide="link" style="<?= $wooIconStyle ?>" aria-hidden="true"></i>
                        </div>
                        <button type="button" class="woo-icon-btn" data-woo-copy="wooDeliveryUrl" aria-label="Webhook adresini kopyala" data-copied="Webhook adresi kopyalandı.">
                            <i data-lucide="copy" aria-hidden="true"></i>
                        </button>
                    </div>
                    <small class="form-help" id="wooDeliveryUrlHelp">Elle kurulumda: WooCommerce → Ayarlar → Gelişmiş → Webhook'lar → Konu <b>Sipariş oluşturuldu</b> ve <b>Sipariş güncellendi</b> için ayrı ayrı; Teslim adresi bu adres, Gizli anahtar yukarıdaki değer.</small>
                    <?php if ($wooBaseLocal): ?>
                    <div class="woo-note" role="note">
                        <i data-lucide="alert-triangle" aria-hidden="true"></i>
                        <span>Panel adresi yerel (localhost) görünüyor. İnternetteki bir mağaza bu adrese bildirim gönderemez; yalnız aynı bilgisayardaki test mağazasıyla çalışır.</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="woo-auto">
                    <div class="woo-auto-head">
                        <div>
                            <h4 class="woo-auto-title">Anlık sipariş bildirimi</h4>
                            <p class="woo-auto-desc">WooCommerce mağazanıza "Sipariş oluşturuldu" ve "Sipariş güncellendi" bildirimlerini bu adres ve gizli anahtarla kurar. Varsa günceller, kopya oluşturmaz. Kayıtlı bilgilerle çalışır.</p>
                        </div>
                        <?php if ($wooCanEdit): ?>
                        <button type="button" class="btn btn-secondary" id="btnWooWebhooks" data-url="/marketplaces/<?= (int) ($mp['id'] ?? 0) ?>/woo-webhooks" aria-describedby="wooAutoStatus"<?= $wooConnected ? '' : ' disabled' ?>>
                            <i data-lucide="webhook" aria-hidden="true"></i> Webhook'u otomatik kur
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="woo-status" id="wooAutoStatus" role="status" aria-live="polite"><?= $wooConnected ? '' : 'Önce mağaza adresini ve API anahtarlarını kaydedin.' ?></div>
                    <ul class="woo-list" id="wooAutoList" aria-label="Webhook kurulum sonucu" hidden></ul>
                </div>
                <span class="woo-sr" id="wooLive" role="status" aria-live="polite"></span>
                </div>
                <script>
                (function () {
                    var root = document.getElementById('wooSection');
                    if (!root) { return; }
                    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    var csrfField = document.querySelector('#settingsForm input[name="_csrf_token"]');
                    var csrf = (csrfMeta && csrfMeta.content) || (csrfField && csrfField.value) || '';
                    var live = document.getElementById('wooLive');

                    function announce(message) {
                        if (!live) { return; }
                        live.textContent = '';
                        window.setTimeout(function () { live.textContent = message; }, 30);
                    }

                    var storeUrl = document.getElementById('wooStoreUrl');
                    var httpNote = document.getElementById('wooHttpNote');
                    function syncHttpNote() {
                        if (!storeUrl || !httpNote) { return; }
                        var isHttp = /^\s*http:\/\//i.test(storeUrl.value);
                        httpNote.hidden = !isHttp;
                        storeUrl.setAttribute('aria-describedby', isHttp ? 'wooStoreUrlHelp wooHttpNote' : 'wooStoreUrlHelp');
                    }
                    if (storeUrl) { storeUrl.addEventListener('input', syncHttpNote); }

                    root.querySelectorAll('[data-woo-reveal]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            var input = document.getElementById(button.getAttribute('data-woo-reveal'));
                            if (!input) { return; }
                            var show = input.type === 'password';
                            input.type = show ? 'text' : 'password';
                            button.setAttribute('aria-pressed', show ? 'true' : 'false');
                            button.setAttribute('aria-label', show ? button.getAttribute('data-label-hide') : button.getAttribute('data-label-show'));
                            button.querySelectorAll('[data-icon]').forEach(function (icon) {
                                icon.hidden = (icon.getAttribute('data-icon') === 'show') === show;
                            });
                        });
                    });

                    function copyText(text) {
                        if (navigator.clipboard && window.isSecureContext) {
                            return navigator.clipboard.writeText(text);
                        }
                        return new Promise(function (resolve, reject) {
                            var area = document.createElement('textarea');
                            area.value = text;
                            area.setAttribute('readonly', '');
                            area.style.position = 'fixed';
                            area.style.opacity = '0';
                            document.body.appendChild(area);
                            area.select();
                            try { if (document.execCommand('copy')) { resolve(); } else { reject(new Error('copy')); } }
                            catch (error) { reject(error); }
                            finally { document.body.removeChild(area); }
                        });
                    }
                    root.querySelectorAll('[data-woo-copy]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            var input = document.getElementById(button.getAttribute('data-woo-copy'));
                            if (!input || input.value === '') { announce('Kopyalanacak değer henüz yok; önce ayarları kaydedin.'); return; }
                            copyText(input.value).then(function () {
                                announce(button.getAttribute('data-copied') || 'Kopyalandı.');
                                button.classList.add('is-done');
                                window.setTimeout(function () { button.classList.remove('is-done'); }, 1500);
                            }).catch(function () {
                                announce('Kopyalanamadı; değeri seçip Ctrl+C ile kopyalayın.');
                            });
                        });
                    });

                    var dirty = false;
                    ['wooStoreUrl', 'wooConsumerKey', 'wooConsumerSecret', 'wooWebhookSecret'].forEach(function (id) {
                        var field = document.getElementById(id);
                        if (field) { field.addEventListener('input', function () { dirty = true; }); }
                    });

                    var button = document.getElementById('btnWooWebhooks');
                    var status = document.getElementById('wooAutoStatus');
                    var list = document.getElementById('wooAutoList');

                    function setStatus(message, tone) {
                        if (!status) { return; }
                        status.textContent = message;
                        status.setAttribute('data-tone', tone || '');
                    }
                    function renderRows(rows) {
                        if (!list) { return; }
                        list.textContent = '';
                        (Array.isArray(rows) ? rows : []).forEach(function (row) {
                            if (!row || typeof row !== 'object') { return; }
                            var failed = !!row.error;
                            var item = document.createElement('li');
                            var badge = document.createElement('span');
                            badge.className = 'woo-badge' + (failed ? ' err' : '');
                            badge.textContent = failed ? 'Hata' : 'Etkin';
                            var text = document.createElement('span');
                            text.textContent = (row.label || row.topic || 'Webhook') + (row.id ? ' · #' + row.id : '') + (failed ? ' — ' + row.error : '');
                            item.appendChild(badge);
                            item.appendChild(text);
                            list.appendChild(item);
                        });
                        list.hidden = list.children.length === 0;
                    }

                    if (button) {
                        button.addEventListener('click', function () {
                            if (dirty) {
                                setStatus('Değişiklikleri önce "Ayarları Kaydet" ile kaydedin; otomatik kurulum kayıtlı bilgileri kullanır.', 'warn');
                                return;
                            }
                            button.disabled = true;
                            button.setAttribute('aria-busy', 'true');
                            setStatus('Webhook\'lar kuruluyor…', '');
                            fetch(button.getAttribute('data-url'), {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                            }).then(function (response) {
                                return response.json().catch(function () {
                                    return { success: false, message: 'Sunucudan beklenmeyen yanıt (' + response.status + ').' };
                                });
                            }).then(function (result) {
                                setStatus(result.message || (result.success ? 'Webhook\'lar kuruldu.' : 'Webhook kurulamadı.'), result.success ? 'ok' : 'err');
                                renderRows(result.webhooks);
                                var urlField = document.getElementById('wooDeliveryUrl');
                                if (result.delivery_url && urlField) { urlField.value = result.delivery_url; }
                            }).catch(function (error) {
                                setStatus('Bağlantı hatası: ' + error.message, 'err');
                            }).finally(function () {
                                button.disabled = false;
                                button.removeAttribute('aria-busy');
                            });
                        });
                    }
                })();
                </script>

                <?php elseif ($slug === 'opencart'): ?>
                <?php $ocToken = $data['opencartToken'] ?? ''; ?>
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> OpenCart Connector — Kurulum</h4>
                    <p>OpenCart'ta hazır REST API olmadığından, mağazanıza tek dosyalık <strong>token korumalı köprü</strong> (<code>ozyconnect.php</code>) kurarsınız. <strong>Uyumlu sürümler: 2.3.x · 3.0.x · 4.0.x · 4.1.x</strong> (PHP 7.2+, MySQLi).</p>
                    <ol style="margin:8px 0 0 18px;padding:0;font-size:13px;line-height:1.8">
                        <li>Connector paketini indirin; <code>ozyconnect.php</code>'yi OpenCart <strong>kök dizinine</strong> (config.php yanına) yükleyin.</li>
                        <li>Aşağıdaki <strong>Bağlantı Token'ı</strong>nı kopyalayıp dosyadaki <code>OZYCONNECT_TOKEN</code> satırına yapıştırın.</li>
                        <li><strong>Connector URL</strong>'ye dosyanın adresini yazın (örn. <code>https://magazaniz.com/ozyconnect.php</code>) → kaydedin → <strong>Test Et</strong>.</li>
                    </ol>
                    <div style="margin-top:14px">
                        <a href="/public/downloads/ozyconnect-opencart-connector-1.0.0.zip" download style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:var(--primary,var(--primary));color:#fff;font-weight:600;font-size:13px;text-decoration:none">
                            <i data-lucide="download" style="width:16px;height:16px"></i> Connector'ı İndir (v1.0.0 · .zip)
                        </a>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Connector URL <span style="color:var(--danger)">*</span></label>
                    <div style="position:relative">
                        <input type="text" name="connector_url" class="form-input" style="padding-left:36px" value="<?= htmlspecialchars((string)($extraConfig['connector_url'] ?? '')) ?>" placeholder="https://magazaniz.com/ozyconnect.php" required>
                        <i data-lucide="link" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted)"></i>
                    </div>
                    <small class="form-help">Mağazanıza yüklediğiniz <code>ozyconnect.php</code> dosyasının tam adresi.</small>
                </div>
                <?php if ($ocToken !== ''): ?>
                <div class="form-group">
                    <label class="form-label">Bağlantı Token'ı <span style="font-size:11px;color:var(--text-muted);font-weight:400">(ozyconnect.php içine yapıştırın)</span></label>
                    <input type="text" readonly onclick="this.select()" value="<?= htmlspecialchars($ocToken) ?>" class="form-input" style="font-family:monospace;font-size:13px">
                    <small class="form-help"><code>ozyconnect.php</code> içindeki <code>OZYCONNECT_TOKEN</code> değerini bununla değiştirin. Gizli tutun, yalnız HTTPS kullanın.</small>
                </div>
                <?php else: ?>
                <small class="form-help" style="display:block;margin-top:-8px">Bağlantı token'ı: Connector URL'yi girip <strong>kaydedince</strong> otomatik oluşturulur, ardından bu sayfada görünür.</small>
                <?php endif; ?>

                <hr style="border:0;border-top:1px solid var(--border);margin:18px 0">
                <div class="form-group">
                    <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="oc_instant" value="1" <?= !empty($extraConfig['oc_instant']) ? 'checked' : '' ?>>
                        Anlık sipariş bildirimini etkinleştir
                        <span style="font-size:11px;color:var(--text-muted);font-weight:400">(sipariş oluşunca panele saniyeler içinde düşer)</span>
                    </label>
                    <small class="form-help">OpenCart'ta webhook yoktur; bunun yerine küçük bir <b>event eklentisi</b> kurulur. İşaretleyip <b>kaydedince</b> event kaydı connector tarafından otomatik yapılır. İşaretsizken siparişler ~5 dk'da bir cron ile çekilir.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">OpenCart Sürümü</label>
                    <select name="oc_version" class="form-input" style="max-width:240px">
                        <option value="3" <?= (int)($extraConfig['oc_version'] ?? 3) < 4 ? 'selected' : '' ?>>OpenCart 2.3.x / 3.0.x</option>
                        <option value="4" <?= (int)($extraConfig['oc_version'] ?? 3) >= 4 ? 'selected' : '' ?>>OpenCart 4.0.x / 4.1.x</option>
                    </select>
                    <small class="form-help">Anlık bildirim event'inin doğru biçimde kurulması için mağaza sürümünüzü seçin.</small>
                </div>
                <div class="mp-help" style="margin-top:6px">
                    <h4><i data-lucide="bell"></i> Anlık bildirim için event dosyası (pakette <code>events/</code> klasörü)</h4>
                    <ul style="font-size:13px;line-height:1.8;margin:6px 0 0 18px">
                        <li><b>OC 2.3/3.x:</b> <code>events/opencart-3x/ozyconnect_event.php</code> → mağazada <code>catalog/controller/extension/ozyconnect_event.php</code></li>
                        <li><b>OC 4.x:</b> <code>events/opencart-4x/extension/ozyconnect/…</code> → mağazada <code>extension/ozyconnect/catalog/controller/event.php</code></li>
                    </ul>
                    <p style="margin-top:8px;font-size:13px">Dosyayı yükleyin, kutuyu işaretleyip <b>kaydedin</b> — event kaydı + webhook adresi otomatik yazılır. (Önce Connector URL girilip bağlantı kurulmuş olmalı.)</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="percent"></i> Fiyat Ayarları</h3></div>
            <div class="mp-card-body">
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> Fiyat Farkı Nasıl Çalışır?</h4>
                    <p>Bu ayar, havuzdaki (sistem) fiyat ile pazaryerine gönderilen fiyat arasındaki farkı belirler.</p>
                    <ul>
                        <li><strong>İndirim 10%</strong> → Sistem fiyatı 1.000₺ ise pazaryerine <strong>900₺</strong> gönderilir</li>
                        <li><strong>Artış 15%</strong> → Sistem fiyatı 1.000₺ ise pazaryerine <strong>1.150₺</strong> gönderilir</li>
                        <li><strong>0%</strong> → Değişiklik yapılmaz, sistem fiyatı doğrudan gönderilir</li>
                        <li>Ürün içe aktarılırken aynı oran <strong>ters yönde</strong> uygulanır</li>
                    </ul>
                </div>
                <div class="form-group">
                    <label class="form-label">Fiyat Farkı</label>
                    <input type="hidden" name="price_adjustment_percent" id="priceAdjHidden" value="<?= $savedPct ?>">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <div class="price-dir-group">
                            <button type="button" id="btnIncrease" class="price-dir-btn <?= $direction === 'increase' ? 'active-inc' : '' ?>" onclick="setPriceDirection('increase')">
                                <i data-lucide="trending-up"></i> Artış
                            </button>
                            <button type="button" id="btnDecrease" class="price-dir-btn <?= $direction === 'decrease' ? 'active-dec' : '' ?>" onclick="setPriceDirection('decrease')">
                                <i data-lucide="trending-down"></i> İndirim
                            </button>
                        </div>
                        <div style="display:flex;align-items:center;gap:5px">
                            <input type="number" id="priceAdjValue" class="form-input" value="<?= $absValue ?>" step="0.01" min="0" max="999" style="max-width:100px;font-size:18px;font-weight:600;text-align:center" oninput="updatePriceHidden()">
                            <span style="font-size:20px;font-weight:600;color:var(--text-muted)">%</span>
                        </div>
                        <div id="pricePreview" style="flex:1;min-width:180px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid var(--border-color)">
                            <div style="font-size:10px;color:var(--text-muted);margin-bottom:2px;text-transform:uppercase;letter-spacing:.5px">Önizleme</div>
                            <div style="font-size:13px;font-weight:600">Sistem: 1.000₺ → <?= htmlspecialchars($info['name']) ?>: <span id="previewAmount" style="color:#10b981">1.000₺</span></div>
                        </div>
                    </div>
                    <small class="form-help">İçe aktarmada ters uygulanır. Örn: İndirim %10 ayarlandıysa, Trendyol'dan gelen 900₺ sisteme 1.000₺ olarak kaydedilir.</small>
                </div>
            </div>
        </div>

        <?php if ($slug === 'trendyol'): ?>
        <!-- Kargo Ayarları: Otomatik olarak yönetiliyor, ayar gizli -->
        <input type="hidden" name="ty_cargo_payment" value="1">
        <input type="hidden" name="ty_auto_label" value="1">
        <?php endif; ?>

        <?php if ($slug === 'amazon'): ?>
        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="shield"></i> Amazon OAuth Yetkilendirme</h3></div>
            <div class="mp-card-body">
                <div class="mp-help">
                    <h4><i data-lucide="info"></i> OAuth Nasıl Çalışır?</h4>
                    <p>Önce Client ID, Client Secret ve App ID bilgilerini kaydedip, ardından aşağıdaki butona tıklayarak Amazon'ın yetkilendirme sayfasına gidin. Yetkilendirme tamamlandıktan sonra Refresh Token otomatik kaydedilir.</p>
                    <p style="margin-top:6px"><strong>Not:</strong> Eğer Amazon size test için doğrudan bir Refresh Token verdiyse, yukarıdaki alana yapıştırıp kaydetmeniz yeterlidir.</p>
                </div>
                <?php
                $appId = $extraConfig['app_id'] ?? '';
                if ($appId !== '' && !empty($mp['api_key_encrypted'])):
                    $oauthState = bin2hex(random_bytes(16));
                    $_SESSION['amazon_oauth_state'] = $oauthState;
                    $authUrl = 'https://sellercentral.amazon.com.tr/apps/authorize/consent?' . http_build_query([
                        'application_id' => $appId,
                        'state' => $oauthState,
                        'version' => 'beta',
                    ]);
                ?>
                <a href="<?= htmlspecialchars($authUrl) ?>" target="_blank" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;width:100%;justify-content:center;margin-top:12px">
                    <i data-lucide="external-link" style="width:16px;height:16px"></i>
                    Amazon'da Yetkilendirmeye Git
                </a>
                <?php else: ?>
                <div style="margin-top:12px;padding:12px 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px">
                    <i data-lucide="alert-triangle" style="width:16px;height:16px;flex-shrink:0"></i>
                    <span>Önce <strong>LWA Client ID</strong>, <strong>LWA Client Secret</strong> ve <strong>App ID</strong> bilgilerini kaydedip sayfayı yenileyin.</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="mp-card">
            <div class="mp-card-body mp-btn-group">
                <button type="submit" class="btn btn-primary" style="flex:1"><i data-lucide="save"></i> Ayarları Kaydet</button>
                <button type="button" class="btn btn-success" id="btnTestConn" data-test-connection="/marketplaces/<?= $mp['id'] ?>/test"><i data-lucide="plug-zap"></i> Bağlantı Testi</button>
            </div>
        </div>
    </div>

    <div>
        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="activity"></i> Durum Bilgisi</h3></div>
            <div class="mp-card-body">
                <div class="mp-status-row">
                    <span class="mp-status-label"><i data-lucide="power"></i> Durum</span>
                    <span class="mp-status-val">
                        <?php if ((int)($mp['is_active'] ?? 0)): ?>
                            <span style="color:var(--success);display:flex;align-items:center;gap:4px"><i data-lucide="check-circle" style="width:14px;height:14px"></i> Aktif</span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);display:flex;align-items:center;gap:4px"><i data-lucide="circle" style="width:14px;height:14px"></i> Pasif</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mp-status-row">
                    <span class="mp-status-label"><i data-lucide="key"></i> API Durumu</span>
                    <span class="mp-status-val">
                        <?php if (!empty($mp['api_key_encrypted'])): ?>
                            <span style="color:var(--success);display:flex;align-items:center;gap:4px"><i data-lucide="check-circle" style="width:14px;height:14px"></i> Tanımlı</span>
                        <?php else: ?>
                            <span style="color:var(--warning);display:flex;align-items:center;gap:4px"><i data-lucide="alert-triangle" style="width:14px;height:14px"></i> Tanımsız</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mp-status-row">
                    <span class="mp-status-label"><i data-lucide="clock"></i> Son Bağlantı</span>
                    <span class="mp-status-val">
                        <?php if (!empty($mp['last_connected_at'])): ?>
                            <?= date('d.m.Y H:i', strtotime($mp['last_connected_at'])) ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">Hiç</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($slug === 'amazon'): ?>
        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="key-round"></i> Amazon Token Durumu</h3></div>
            <div class="mp-card-body">
                <div class="mp-status-row">
                    <span class="mp-status-label"><i data-lucide="refresh-cw"></i> Refresh Token</span>
                    <span class="mp-status-val">
                        <?php if (!empty($extraConfig['refresh_token'])): ?>
                            <span style="color:var(--success);display:flex;align-items:center;gap:4px"><i data-lucide="check-circle" style="width:14px;height:14px"></i> Mevcut</span>
                        <?php else: ?>
                            <span style="color:var(--warning);display:flex;align-items:center;gap:4px"><i data-lucide="alert-triangle" style="width:14px;height:14px"></i> Yok</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mp-status-row">
                    <span class="mp-status-label"><i data-lucide="store"></i> Seller ID</span>
                    <span class="mp-status-val">
                        <?php if (!empty($extraConfig['seller_id'])): ?>
                            <span style="color:var(--success);display:flex;align-items:center;gap:4px"><i data-lucide="check-circle" style="width:14px;height:14px"></i> <?= htmlspecialchars(substr($extraConfig['seller_id'], 0, 6) . '...') ?></span>
                        <?php else: ?>
                            <span style="color:var(--warning);display:flex;align-items:center;gap:4px"><i data-lucide="alert-triangle" style="width:14px;height:14px"></i> Yok</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mp-status-row">
                    <span class="mp-status-label"><i data-lucide="globe"></i> Marketplace</span>
                    <span class="mp-status-val" style="font-size:12px">amazon.com.tr (A33AVAJ2PDY3EV)</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mp-card">
            <div class="mp-card-head"><h3><i data-lucide="shield-check"></i> Güvenlik</h3></div>
            <div class="mp-card-body">
                <div class="mp-help mp-help-green">
                    <h4><i data-lucide="lock"></i> API Bilgileriniz Güvende</h4>
                    <p>Tüm API anahtarları AES-256-CBC şifreleme ile veritabanında güvenli şekilde saklanır. Hiçbir zaman düz metin olarak görüntülenmez.</p>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
function toggleSecret(){
    const inp=document.getElementById('apiSecretInput');
    const icon=document.getElementById('secretEyeIcon');
    if(!inp)return;
    const show=inp.type==='password';
    inp.type=show?'text':'password';
    icon.setAttribute('data-lucide',show?'eye-off':'eye');
    lucide?.createIcons();
}

function toggleRefreshToken(){
    const inp=document.getElementById('amzRefreshToken');
    const icon=document.getElementById('refreshTokenEyeIcon');
    if(!inp)return;
    const show=inp.type==='password';
    inp.type=show?'text':'password';
    icon.setAttribute('data-lucide',show?'eye-off':'eye');
    lucide?.createIcons();
}

document.getElementById('btnTestConn')?.addEventListener('click',async function(){
    const url=this.dataset.testConnection;
    const orig=this.innerHTML;
    this.disabled=true;
    this.innerHTML='<i data-lucide="loader" style="width:16px;height:16px" class="spin"></i> Test ediliyor...';
    lucide?.createIcons();
    try{
        const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''}});
        const res=await r.json();
        showToast(res.success?'Bağlantı başarılı! API bilgileri doğru.':'Bağlantı hatası: '+(res.message||'Bilinmeyen hata'),res.success?'success':'error');
    }catch(e){showToast('Bağlantı hatası: '+e.message,'error');}
    finally{this.disabled=false;this.innerHTML=orig;lucide?.createIcons();}
});

function showToast(msg,type='info'){
    const t=document.createElement('div');
    t.className='flash-message flash-'+type;
    t.textContent=msg;
    t.style.cssText='position:fixed;top:20px;right:20px;z-index:9999;animation:slideInRight .3s ease';
    document.body.appendChild(t);
    setTimeout(()=>{t.style.animation='slideOutRight .3s ease';setTimeout(()=>t.remove(),300)},4000);
}

let priceDirection='<?= $direction ?>';
const priceVal=document.getElementById('priceAdjValue');
const priceHidden=document.getElementById('priceAdjHidden');
const preview=document.getElementById('previewAmount');
const btnInc=document.getElementById('btnIncrease');
const btnDec=document.getElementById('btnDecrease');

function setPriceDirection(dir){
    priceDirection=dir;
    btnInc.className='price-dir-btn'+(dir==='increase'?' active-inc':'');
    btnDec.className='price-dir-btn'+(dir==='decrease'?' active-dec':'');
    lucide?.createIcons();
    updatePriceHidden();
}

function updatePriceHidden(){
    const abs=parseFloat(priceVal?.value||'0')||0;
    const signed=priceDirection==='decrease'?-abs:abs;
    if(priceHidden)priceHidden.value=signed;
    const adjusted=1000*(1+signed/100);
    const fmt=adjusted.toLocaleString('tr-TR',{minimumFractionDigits:0,maximumFractionDigits:2})+'₺';
    if(preview){preview.textContent=fmt;preview.style.color=signed<0?'#ef4444':(signed>0?'#10b981':'var(--text-primary)');}
}
updatePriceHidden();
</script>
