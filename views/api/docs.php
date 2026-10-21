<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entegratör Yazılımı API Dokümantasyonu</title>
<script src="/public/assets/vendor/lucide/lucide.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--card:#fff;--border:#e2e8f0;--primary:var(--primary);--primary-soft:#f5f3ff;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--green:#10b981;--green-bg:#f0fdf4;--blue:#3b82f6;--blue-bg:#eff6ff;--orange:#f59e0b;--orange-bg:#fffbeb;--red:#ef4444;--red-bg:#fef2f2;--purple:var(--primary);--purple-bg:#f5f3ff;--dark:#1e293b;--code-bg:#0f172a;--radius:12px}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);line-height:1.6;font-size:14px}
a{color:var(--primary);text-decoration:none}

/* Layout */
.docs{display:flex;min-height:100vh}
.docs-sidebar{width:280px;background:var(--card);border-right:1px solid var(--border);position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:10}
.docs-main{flex:1;margin-left:280px;padding:32px 40px;max-width:960px}

/* Sidebar */
.sb-logo{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.sb-logo .name{font-size:16px;font-weight:600;color:var(--text)}
.sb-logo .ver{font-size:10px;font-weight:600;background:var(--primary);color:#fff;padding:2px 8px;border-radius:6px}
.sb-section{padding:12px 0}
.sb-title{padding:0 24px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text3);font-weight:700;margin-bottom:4px}
.sb-link{display:flex;align-items:center;gap:8px;padding:7px 24px;font-size:13px;color:var(--text2);transition:all .15s;cursor:pointer;border-left:3px solid transparent}
.sb-link:hover{background:var(--primary-soft);color:var(--primary)}
.sb-link.active{color:var(--primary);font-weight:600;border-left-color:var(--primary);background:var(--primary-soft)}
.sb-link i{width:14px;height:14px}
.sb-method{font-size:10px;font-weight:700;width:36px;text-align:center;padding:2px 0;border-radius:4px;flex-shrink:0}
.sb-get{background:var(--blue-bg);color:var(--blue)}
.sb-post{background:var(--green-bg);color:var(--green)}
.sb-put{background:var(--orange-bg);color:var(--orange)}
.sb-patch{background:var(--purple-bg);color:var(--purple)}
.sb-delete{background:var(--red-bg);color:var(--red)}

/* Try It Panel */
.try-panel{position:fixed;top:0;right:-420px;width:420px;height:100vh;background:var(--card);border-left:1px solid var(--border);z-index:100;transition:right .3s ease;overflow-y:auto;box-shadow:0 0 0 1px var(--border)}
.try-panel.open{right:0}
.try-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.try-head h3{font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px}
.try-close{background:none;border:none;cursor:pointer;color:var(--text3);padding:4px}
.try-body{padding:20px}
.try-field{margin-bottom:14px}
.try-field label{display:block;font-size:11px;font-weight:600;color:var(--text2);margin-bottom:4px;text-transform:uppercase}
.try-field input,.try-field textarea{width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-family:'Fira Code',monospace;font-size:12px}
.try-field input:focus,.try-field textarea:focus{outline:none;border-color:var(--primary)}
.try-btn{width:100%;padding:10px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px}
.try-btn:hover{opacity:.9}
.try-result{margin-top:16px}
.try-result-head{display:flex;align-items:center;justify-content:space-between;font-size:12px;font-weight:600;margin-bottom:6px}
.try-status{padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700}

/* Content */
.doc-hero{margin-bottom:40px}
.doc-hero h1{font-size:28px;font-weight:600;margin-bottom:8px}
.doc-hero p{color:var(--text2);font-size:15px;max-width:600px}
.doc-section{margin-bottom:48px}
.doc-section h2{font-size:20px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;padding-bottom:10px;border-bottom:2px solid var(--border)}
.doc-section h2 i{width:20px;height:20px;color:var(--primary)}

/* Endpoint Card */
.ep{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px;overflow:hidden}
.ep-head{padding:14px 20px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:background .15s}
.ep-head:hover{background:#fafbfc}
.ep-method{font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;text-transform:uppercase}
.ep-method-get{background:var(--blue-bg);color:var(--blue)}
.ep-method-post{background:var(--green-bg);color:var(--green)}
.ep-method-put{background:var(--orange-bg);color:var(--orange)}
.ep-method-patch{background:var(--purple-bg);color:var(--purple)}
.ep-method-delete{background:var(--red-bg);color:var(--red)}
.ep-path{font-family:'Fira Code',monospace;font-size:13px;font-weight:600;color:var(--text);flex:1}
.ep-desc{font-size:12px;color:var(--text3)}
.ep-try{padding:5px 12px;border:1px solid var(--border);border-radius:6px;font-size:11px;font-weight:600;background:var(--card);color:var(--text2);cursor:pointer;transition:all .15s}
.ep-try:hover{border-color:var(--primary);color:var(--primary)}
.ep-body{display:none;border-top:1px solid var(--border)}
.ep-body.open{display:block}
.ep-detail{padding:16px 20px}
.ep-detail h4{font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
.ep-params{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px}
.ep-params th{text-align:left;padding:6px 10px;background:#f8fafc;font-weight:600;color:var(--text3);font-size:10px;text-transform:uppercase}
.ep-params td{padding:6px 10px;border-bottom:1px solid #f1f5f9}
.ep-params .param-name{font-family:monospace;font-weight:600;color:var(--primary)}
.ep-params .param-type{font-size:10px;color:var(--text3);background:#f1f5f9;padding:2px 6px;border-radius:4px}
.ep-params .param-req{font-size:10px;color:var(--red);font-weight:600}

/* Code Block */
.code-tabs{display:flex;gap:2px;margin-bottom:0}
.code-tab{padding:6px 14px;font-size:11px;font-weight:600;background:#334155;color:#94a3b8;border:none;cursor:pointer;border-radius:6px 6px 0 0;font-family:inherit}
.code-tab.active{background:var(--code-bg);color:#e2e8f0}
.code-block{background:var(--code-bg);border-radius:0 6px 6px 6px;padding:16px;overflow-x:auto;position:relative}
.code-block pre{color:#e2e8f0;font-family:'Fira Code',monospace;font-size:12px;line-height:1.7;white-space:pre;margin:0}
.code-block .copy-btn{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.1);border:none;color:#94a3b8;cursor:pointer;padding:4px 8px;border-radius:4px;font-size:10px;display:flex;align-items:center;gap:4px}
.code-block .copy-btn:hover{background:rgba(255,255,255,.2);color:#fff}

/* Auth Box */
.auth-box{background:var(--primary-soft);border:1.5px solid var(--primary);border-radius:var(--radius);padding:20px;margin-bottom:24px}
.auth-box h3{font-size:14px;font-weight:700;color:var(--primary);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.auth-code{background:var(--code-bg);color:#e2e8f0;padding:12px 16px;border-radius:8px;font-family:'Fira Code',monospace;font-size:12px;line-height:1.7;overflow-x:auto}

/* Error Codes */
.err-table{width:100%;border-collapse:collapse;font-size:13px}
.err-table th{text-align:left;padding:8px 12px;background:#f8fafc;font-weight:600;border-bottom:1px solid var(--border)}
.err-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9}
.err-code{font-family:monospace;font-weight:700;font-size:12px}

/* Rate Limit */
.rl-box{background:var(--orange-bg);border:1px solid var(--orange);border-radius:var(--radius);padding:16px 20px;margin-bottom:24px}
.rl-box h4{font-size:13px;font-weight:700;color:#92400e;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.rl-box p{font-size:12px;color:#78350f;line-height:1.6}

/* Responsive */
@media(max-width:768px){
    .docs-sidebar{display:none}
    .docs-main{margin-left:0;padding:20px}
    .try-panel{width:100%}
}
</style>
</head>
<body>

<div class="docs">
<!-- Sidebar -->
<aside class="docs-sidebar">
    <div class="sb-logo">
        <div class="name">Entegratör Yazılımı API</div>
        <span class="ver">v1</span>
    </div>

    <div class="sb-section">
        <div class="sb-title">Başlangıç</div>
        <a href="#giris" class="sb-link active"><i data-lucide="book-open"></i> Giriş</a>
        <a href="#auth" class="sb-link"><i data-lucide="lock"></i> Kimlik Doğrulama</a>
        <a href="#rate-limit" class="sb-link"><i data-lucide="gauge"></i> Rate Limiting</a>
        <a href="#errors" class="sb-link"><i data-lucide="alert-circle"></i> Hata Kodları</a>
    </div>

    <div class="sb-section">
        <div class="sb-title">Ürünler</div>
        <a href="#ep-products-list" class="sb-link"><span class="sb-method sb-get">GET</span> /products</a>
        <a href="#ep-products-show" class="sb-link"><span class="sb-method sb-get">GET</span> /products/{id}</a>
        <a href="#ep-products-sku" class="sb-link"><span class="sb-method sb-get">GET</span> /products/sku/{sku}</a>
        <a href="#ep-products-create" class="sb-link"><span class="sb-method sb-post">POST</span> /products</a>
        <a href="#ep-products-update" class="sb-link"><span class="sb-method sb-put">PUT</span> /products/{id}</a>
        <a href="#ep-products-stock" class="sb-link"><span class="sb-method sb-put">PUT</span> /products/{id}/stock</a>
        <a href="#ep-products-price" class="sb-link"><span class="sb-method sb-put">PUT</span> /products/{id}/price</a>
    </div>

    <div class="sb-section">
        <div class="sb-title">Siparişler</div>
        <a href="#ep-orders-list" class="sb-link"><span class="sb-method sb-get">GET</span> /orders</a>
        <a href="#ep-orders-show" class="sb-link"><span class="sb-method sb-get">GET</span> /orders/{id}</a>
        <a href="#ep-orders-create" class="sb-link"><span class="sb-method sb-post">POST</span> /orders</a>
        <a href="#ep-orders-status" class="sb-link"><span class="sb-method sb-put">PUT</span> /orders/{id}/status</a>
        <a href="#ep-orders-statuses" class="sb-link"><span class="sb-method sb-get">GET</span> /orders/statuses</a>
    </div>

    <div class="sb-section">
        <div class="sb-title">Kategoriler</div>
        <a href="#ep-categories-list" class="sb-link"><span class="sb-method sb-get">GET</span> /categories</a>
        <a href="#ep-categories-tree" class="sb-link"><span class="sb-method sb-get">GET</span> /categories/tree</a>
        <a href="#ep-categories-show" class="sb-link"><span class="sb-method sb-get">GET</span> /categories/{id}</a>
    </div>

    <div class="sb-section">
        <div class="sb-title">Markalar</div>
        <a href="#ep-brands-list" class="sb-link"><span class="sb-method sb-get">GET</span> /brands</a>
        <a href="#ep-brands-show" class="sb-link"><span class="sb-method sb-get">GET</span> /brands/{id}</a>
    </div>

    <div class="sb-section">
        <div class="sb-title">Stok</div>
        <a href="#ep-stock-summary" class="sb-link"><span class="sb-method sb-get">GET</span> /stock/summary</a>
        <a href="#ep-stock-bulk" class="sb-link"><span class="sb-method sb-post">POST</span> /stock/bulk-update</a>
    </div>
</aside>

<!-- Main Content -->
<main class="docs-main">
    <!-- Hero -->
    <div class="doc-hero" id="giris">
        <h1>Entegratör Yazılımı REST API</h1>
        <p>Entegratör Yazılımı API ile ürünlerinizi, siparişlerinizi, stok ve fiyat bilgilerinizi programatik olarak yönetin. Kendi e-ticaret siteniz veya ERP yazılımınız ile senkronize edin.</p>
    </div>

    <!-- Base URL -->
    <div class="auth-box" style="border-color:var(--border);background:#f8fafc">
        <h3 style="color:var(--text)"><i data-lucide="globe" style="width:16px;height:16px"></i> Base URL</h3>
        <div class="auth-code"><?= htmlspecialchars($baseUrl) ?>/api/v1</div>
    </div>

    <!-- Auth -->
    <div class="doc-section" id="auth">
        <h2><i data-lucide="lock"></i> Kimlik Doğrulama</h2>
        <p style="margin-bottom:16px">Tüm API istekleri <code>X-API-Key</code> ve <code>X-API-Secret</code> header'ları gerektirir. Anahtarlarınızı Entegratör Yazılımı panelinden <strong>Ayarlar → API Anahtarları</strong> menüsünden oluşturabilirsiniz.</p>

        <div class="auth-box">
            <h3><i data-lucide="key-round" style="width:16px;height:16px"></i> Header'lar</h3>
            <div class="auth-code">X-API-Key: YOUR_API_KEY
X-API-Secret: YOUR_API_SECRET
Content-Type: application/json</div>
        </div>

        <div style="margin-bottom:16px">
            <div class="code-tabs">
                <button class="code-tab active" onclick="showTab(this,'curl-auth')">cURL</button>
                <button class="code-tab" onclick="showTab(this,'php-auth')">PHP</button>
                <button class="code-tab" onclick="showTab(this,'js-auth')">JavaScript</button>
            </div>
            <div class="code-block" id="curl-auth">
                <button class="copy-btn" onclick="copyCode(this)"><i data-lucide="copy" style="width:12px;height:12px"></i> Kopyala</button>
                <pre>curl -X GET "<?= htmlspecialchars($baseUrl) ?>/api/v1/products" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "X-API-Secret: YOUR_API_SECRET" \
  -H "Content-Type: application/json"</pre>
            </div>
            <div class="code-block" id="php-auth" style="display:none">
                <button class="copy-btn" onclick="copyCode(this)"><i data-lucide="copy" style="width:12px;height:12px"></i> Kopyala</button>
                <pre>&lt;?php
$ch = curl_init('<?= htmlspecialchars($baseUrl) ?>/api/v1/products');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER =&gt; [
        'X-API-Key: YOUR_API_KEY',
        'X-API-Secret: YOUR_API_SECRET',
        'Content-Type: application/json',
    ],
]);
$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);</pre>
            </div>
            <div class="code-block" id="js-auth" style="display:none">
                <button class="copy-btn" onclick="copyCode(this)"><i data-lucide="copy" style="width:12px;height:12px"></i> Kopyala</button>
                <pre>const response = await fetch('<?= htmlspecialchars($baseUrl) ?>/api/v1/products', {
  headers: {
    'X-API-Key': 'YOUR_API_KEY',
    'X-API-Secret': 'YOUR_API_SECRET',
    'Content-Type': 'application/json',
  },
});
const data = await response.json();</pre>
            </div>
        </div>
    </div>

    <!-- Rate Limiting -->
    <div class="doc-section" id="rate-limit">
        <h2><i data-lucide="gauge"></i> Rate Limiting</h2>
        <div class="rl-box">
            <h4><i data-lucide="alert-triangle" style="width:14px;height:14px"></i> İstek Limiti</h4>
            <p>Her API anahtarı dakikada belirli sayıda istek yapabilir (varsayılan: 60/dk). Limit aşıldığında <code>429 Too Many Requests</code> hatası döner. Response header'larından kalan hakkınızı takip edebilirsiniz:</p>
        </div>
        <div class="code-block">
            <pre>X-RateLimit-Limit: 60        <span style="color:#64748b">// Dakikadaki max istek</span>
X-RateLimit-Remaining: 45    <span style="color:#64748b">// Kalan istek hakkı</span>
Retry-After: 60              <span style="color:#64748b">// Limit aşıldığında bekleme süresi (sn)</span></pre>
        </div>
    </div>

    <!-- Error Codes -->
    <div class="doc-section" id="errors">
        <h2><i data-lucide="alert-circle"></i> Hata Kodları</h2>
        <table class="err-table">
            <thead><tr><th>HTTP</th><th>Kod</th><th>Açıklama</th></tr></thead>
            <tbody>
                <tr><td><span class="err-code" style="color:var(--orange)">400</span></td><td>VALIDATION_ERROR</td><td>Geçersiz veya eksik parametre</td></tr>
                <tr><td><span class="err-code" style="color:var(--red)">401</span></td><td>MISSING_CREDENTIALS</td><td>API Key/Secret header eksik</td></tr>
                <tr><td><span class="err-code" style="color:var(--red)">401</span></td><td>INVALID_CREDENTIALS</td><td>Geçersiz veya süresi dolmuş anahtar</td></tr>
                <tr><td><span class="err-code" style="color:var(--red)">403</span></td><td>FORBIDDEN</td><td>Bu işlem için yetki yok</td></tr>
                <tr><td><span class="err-code" style="color:var(--red)">403</span></td><td>IP_NOT_ALLOWED</td><td>IP adresi whitelist'te değil</td></tr>
                <tr><td><span class="err-code" style="color:var(--orange)">404</span></td><td>NOT_FOUND</td><td>Kayıt bulunamadı</td></tr>
                <tr><td><span class="err-code" style="color:var(--orange)">409</span></td><td>DUPLICATE_SKU</td><td>SKU zaten mevcut</td></tr>
                <tr><td><span class="err-code" style="color:var(--orange)">422</span></td><td>VALIDATION_ERROR</td><td>Doğrulama hatası (detaylar response'da)</td></tr>
                <tr><td><span class="err-code" style="color:var(--red)">429</span></td><td>RATE_LIMIT_EXCEEDED</td><td>İstek limiti aşıldı</td></tr>
            </tbody>
        </table>
        <div style="margin-top:16px">
            <div class="code-block">
                <pre><span style="color:#64748b">// Hata yanıtı formatı</span>
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Doğrulama hatası",
    "details": {
      "sku": "SKU zorunlu",
      "name": "Ürün adı zorunlu"
    }
  }
}</pre>
            </div>
        </div>
    </div>

    <!-- ═══ PRODUCTS ═══ -->
    <div class="doc-section" id="products-section">
        <h2><i data-lucide="package"></i> Ürünler</h2>

        <!-- GET /products -->
        <div class="ep" id="ep-products-list">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/products</span>
                <span class="ep-desc">Ürün listesi</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/products')">Try it</button>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Query Parametreleri</h4>
                    <table class="ep-params">
                        <thead><tr><th>Parametre</th><th>Tip</th><th>Zorunlu</th><th>Açıklama</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">page</td><td><span class="param-type">int</span></td><td>—</td><td>Sayfa no (varsayılan: 1)</td></tr>
                            <tr><td class="param-name">per_page</td><td><span class="param-type">int</span></td><td>—</td><td>Sayfa başı kayıt (1-100, varsayılan: 25)</td></tr>
                            <tr><td class="param-name">search</td><td><span class="param-type">string</span></td><td>—</td><td>İsim, SKU veya barkod arama</td></tr>
                            <tr><td class="param-name">category_id</td><td><span class="param-type">int</span></td><td>—</td><td>Kategori filtresi</td></tr>
                            <tr><td class="param-name">brand_id</td><td><span class="param-type">int</span></td><td>—</td><td>Marka filtresi</td></tr>
                            <tr><td class="param-name">is_active</td><td><span class="param-type">0|1</span></td><td>—</td><td>Aktiflik filtresi</td></tr>
                            <tr><td class="param-name">stock_status</td><td><span class="param-type">string</span></td><td>—</td><td>low | out</td></tr>
                        </tbody>
                    </table>
                    <h4>Yanıt</h4>
                    <div class="code-block">
                        <pre>{
  "success": true,
  "data": [
    {
      "id": 1,
      "sku": "TST-001",
      "barcode": "8680001234567",
      "name": "Örnek Ürün",
      "category_id": 5,
      "brand_id": 3,
      "price": 149.90,
      "stock_qty": 25,
      "weight": 0.5,
      "is_active": true,
      "image_url": null,
      "created_at": "2026-01-15 10:30:00",
      "updated_at": "2026-03-20 14:15:00"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 25,
    "total": 150,
    "total_pages": 6,
    "has_next": true,
    "has_prev": false
  }
}</pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- GET /products/{id} -->
        <div class="ep" id="ep-products-show">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/products/{id}</span>
                <span class="ep-desc">Ürün detayı</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/products/1')">Try it</button>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Path Parametreleri</h4>
                    <table class="ep-params">
                        <thead><tr><th>Parametre</th><th>Tip</th><th>Zorunlu</th><th>Açıklama</th></tr></thead>
                        <tbody><tr><td class="param-name">id</td><td><span class="param-type">int</span></td><td class="param-req">Evet</td><td>Ürün ID</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- GET /products/sku/{sku} -->
        <div class="ep" id="ep-products-sku">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/products/sku/{sku}</span>
                <span class="ep-desc">SKU ile ürün getir</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/products/sku/TST-001')">Try it</button>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Path Parametreleri</h4>
                    <table class="ep-params">
                        <thead><tr><th>Parametre</th><th>Tip</th><th>Zorunlu</th><th>Açıklama</th></tr></thead>
                        <tbody><tr><td class="param-name">sku</td><td><span class="param-type">string</span></td><td class="param-req">Evet</td><td>Ürün SKU kodu</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- POST /products -->
        <div class="ep" id="ep-products-create">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-post">POST</span>
                <span class="ep-path">/api/v1/products</span>
                <span class="ep-desc">Yeni ürün oluştur</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('POST','<?= $baseUrl ?>/api/v1/products',JSON.stringify({sku:'NEW-001',name:'Yeni Ürün',price:99.90,stock_qty:10},null,2))">Try it</button>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Body Parametreleri (JSON)</h4>
                    <table class="ep-params">
                        <thead><tr><th>Parametre</th><th>Tip</th><th>Zorunlu</th><th>Açıklama</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">sku</td><td><span class="param-type">string</span></td><td class="param-req">Evet</td><td>Benzersiz stok kodu</td></tr>
                            <tr><td class="param-name">name</td><td><span class="param-type">string</span></td><td class="param-req">Evet</td><td>Ürün adı</td></tr>
                            <tr><td class="param-name">barcode</td><td><span class="param-type">string</span></td><td>—</td><td>Barkod</td></tr>
                            <tr><td class="param-name">price</td><td><span class="param-type">float</span></td><td>—</td><td>Fiyat (₺)</td></tr>
                            <tr><td class="param-name">stock_qty</td><td><span class="param-type">int</span></td><td>—</td><td>Stok miktarı</td></tr>
                            <tr><td class="param-name">category_id</td><td><span class="param-type">int</span></td><td>—</td><td>Kategori ID</td></tr>
                            <tr><td class="param-name">brand_id</td><td><span class="param-type">int</span></td><td>—</td><td>Marka ID</td></tr>
                            <tr><td class="param-name">weight</td><td><span class="param-type">float</span></td><td>—</td><td>Ağırlık (kg)</td></tr>
                            <tr><td class="param-name">description</td><td><span class="param-type">string</span></td><td>—</td><td>Açıklama</td></tr>
                            <tr><td class="param-name">is_active</td><td><span class="param-type">0|1</span></td><td>—</td><td>Aktiflik (varsayılan: 1)</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PUT /products/{id} -->
        <div class="ep" id="ep-products-update">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-put">PUT</span>
                <span class="ep-path">/api/v1/products/{id}</span>
                <span class="ep-desc">Ürün güncelle</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <p style="margin-bottom:12px;color:var(--text2);font-size:12px">Sadece gönderilen alanlar güncellenir (partial update).</p>
                    <h4>Body Parametreleri</h4>
                    <table class="ep-params">
                        <thead><tr><th>Parametre</th><th>Tip</th><th>Açıklama</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">name</td><td><span class="param-type">string</span></td><td>Ürün adı</td></tr>
                            <tr><td class="param-name">price</td><td><span class="param-type">float</span></td><td>Fiyat</td></tr>
                            <tr><td class="param-name">stock_qty</td><td><span class="param-type">int</span></td><td>Stok</td></tr>
                            <tr><td class="param-name">is_active</td><td><span class="param-type">0|1</span></td><td>Aktiflik</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PUT /products/{id}/stock -->
        <div class="ep" id="ep-products-stock">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-put">PUT</span>
                <span class="ep-path">/api/v1/products/{id}/stock</span>
                <span class="ep-desc">Stok güncelle</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <div class="code-block"><pre>{ "stock_qty": 50 }</pre></div>
                </div>
            </div>
        </div>

        <!-- PUT /products/{id}/price -->
        <div class="ep" id="ep-products-price">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-put">PUT</span>
                <span class="ep-path">/api/v1/products/{id}/price</span>
                <span class="ep-desc">Fiyat güncelle</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <div class="code-block"><pre>{ "price": 199.90 }</pre></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ ORDERS ═══ -->
    <div class="doc-section" id="orders-section">
        <h2><i data-lucide="shopping-bag"></i> Siparişler</h2>

        <div class="ep" id="ep-orders-list">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/orders</span>
                <span class="ep-desc">Sipariş listesi</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/orders')">Try it</button>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Query Parametreleri</h4>
                    <table class="ep-params">
                        <thead><tr><th>Parametre</th><th>Tip</th><th>Açıklama</th></tr></thead>
                        <tbody>
                            <tr><td class="param-name">status</td><td><span class="param-type">string</span></td><td>Durum filtresi</td></tr>
                            <tr><td class="param-name">marketplace_id</td><td><span class="param-type">int</span></td><td>Pazaryeri filtresi</td></tr>
                            <tr><td class="param-name">date_from</td><td><span class="param-type">YYYY-MM-DD</span></td><td>Başlangıç tarihi</td></tr>
                            <tr><td class="param-name">date_to</td><td><span class="param-type">YYYY-MM-DD</span></td><td>Bitiş tarihi</td></tr>
                            <tr><td class="param-name">search</td><td><span class="param-type">string</span></td><td>Sipariş no veya müşteri arama</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ep" id="ep-orders-show">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/orders/{id}</span>
                <span class="ep-desc">Sipariş detayı (kalemler dahil)</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Yanıt</h4>
                    <div class="code-block">
                        <pre>{
  "success": true,
  "data": {
    "id": 42,
    "marketplace_order_id": "TY-123456",
    "customer_name": "Ahmet Yılmaz",
    "total_amount": 299.80,
    "status": "hazirlaniyor",
    "items": [
      {
        "sku": "TST-001",
        "product_name": "Örnek Ürün",
        "quantity": 2,
        "unit_price": 149.90,
        "total_price": 299.80
      }
    ]
  }
}</pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="ep" id="ep-orders-create">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-post">POST</span>
                <span class="ep-path">/api/v1/orders</span>
                <span class="ep-desc">Yeni sipariş oluştur</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Body (JSON)</h4>
                    <div class="code-block">
                        <pre>{
  "marketplace_id": 1,
  "marketplace_order_id": "WEB-78901",
  "customer_name": "Mehmet Kaya",
  "customer_email": "mehmet@ornek.com",
  "customer_phone": "05551234567",
  "customer_address": "İstanbul, Kadıköy",
  "items": [
    { "sku": "TST-001", "product_name": "Ürün A", "quantity": 1, "unit_price": 149.90 },
    { "sku": "TST-002", "product_name": "Ürün B", "quantity": 2, "unit_price": 79.90 }
  ]
}</pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="ep" id="ep-orders-status">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-put">PUT</span>
                <span class="ep-path">/api/v1/orders/{id}/status</span>
                <span class="ep-desc">Sipariş durumu güncelle</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <div class="code-block"><pre>{ "status": "kargoda", "tracking_number": "1Z999AA10123456784", "shipping_company": "Yurtiçi Kargo" }</pre></div>
                </div>
            </div>
        </div>

        <div class="ep" id="ep-orders-statuses">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/orders/statuses</span>
                <span class="ep-desc">Geçerli sipariş durumları</span>
            </div>
        </div>
    </div>

    <!-- ═══ CATEGORIES ═══ -->
    <div class="doc-section" id="categories-section">
        <h2><i data-lucide="folder-tree"></i> Kategoriler</h2>

        <div class="ep" id="ep-categories-list">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/categories</span>
                <span class="ep-desc">Düz kategori listesi</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/categories')">Try it</button>
            </div>
        </div>

        <div class="ep" id="ep-categories-tree">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/categories/tree</span>
                <span class="ep-desc">Hiyerarşik kategori ağacı</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/categories/tree')">Try it</button>
            </div>
        </div>

        <div class="ep" id="ep-categories-show">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/categories/{id}</span>
                <span class="ep-desc">Kategori detayı + alt kategoriler</span>
            </div>
        </div>
    </div>

    <!-- ═══ BRANDS ═══ -->
    <div class="doc-section" id="brands-section">
        <h2><i data-lucide="tag"></i> Markalar</h2>

        <div class="ep" id="ep-brands-list">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/brands</span>
                <span class="ep-desc">Marka listesi</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/brands')">Try it</button>
            </div>
        </div>

        <div class="ep" id="ep-brands-show">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/brands/{id}</span>
                <span class="ep-desc">Marka detayı</span>
            </div>
        </div>
    </div>

    <!-- ═══ STOCK ═══ -->
    <div class="doc-section" id="stock-section">
        <h2><i data-lucide="warehouse"></i> Stok</h2>

        <div class="ep" id="ep-stock-summary">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-get">GET</span>
                <span class="ep-path">/api/v1/stock/summary</span>
                <span class="ep-desc">Stok özeti</span>
                <button class="ep-try" onclick="event.stopPropagation();openTry('GET','<?= $baseUrl ?>/api/v1/stock/summary')">Try it</button>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <div class="code-block">
                        <pre>{
  "success": true,
  "data": {
    "total_products": 1250,
    "in_stock": 980,
    "low_stock": 45,
    "out_of_stock": 225
  }
}</pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="ep" id="ep-stock-bulk">
            <div class="ep-head" onclick="toggleEp(this)">
                <span class="ep-method ep-method-post">POST</span>
                <span class="ep-path">/api/v1/stock/bulk-update</span>
                <span class="ep-desc">Toplu stok güncelleme (max 500)</span>
            </div>
            <div class="ep-body">
                <div class="ep-detail">
                    <h4>Body (JSON)</h4>
                    <div class="code-block">
                        <pre>{
  "items": [
    { "sku": "TST-001", "stock_qty": 50 },
    { "sku": "TST-002", "stock_qty": 0 },
    { "sku": "TST-003", "stock_qty": 120 }
  ]
}</pre>
                    </div>
                    <h4 style="margin-top:16px">Yanıt</h4>
                    <div class="code-block">
                        <pre>{
  "success": true,
  "message": "Toplu stok güncelleme: 3 güncellendi",
  "data": {
    "updated": 3,
    "not_found": 0,
    "errors": []
  }
}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="text-align:center;padding:40px 0;color:var(--text3);font-size:12px">
        <p>Entegratör Yazılımı API v1 — © <?= date('Y') ?> OziyWeb. Tüm hakları saklıdır.</p>
    </div>
</main>
</div>

<!-- Try It Panel -->
<div class="try-panel" id="tryPanel">
    <div class="try-head">
        <h3><i data-lucide="zap" style="width:16px;height:16px"></i> API Test</h3>
        <button class="try-close" onclick="closeTry()"><i data-lucide="x" style="width:18px;height:18px"></i></button>
    </div>
    <div class="try-body">
        <div class="try-field">
            <label>API Key</label>
            <input type="text" id="tryKey" placeholder="X-API-Key">
        </div>
        <div class="try-field">
            <label>API Secret</label>
            <input type="password" id="trySecret" placeholder="X-API-Secret">
        </div>
        <div class="try-field">
            <label>Method</label>
            <input type="text" id="tryMethod" readonly>
        </div>
        <div class="try-field">
            <label>URL</label>
            <input type="text" id="tryUrl">
        </div>
        <div class="try-field" id="tryBodyField" style="display:none">
            <label>Body (JSON)</label>
            <textarea id="tryBody" rows="6" placeholder='{"key": "value"}'></textarea>
        </div>
        <button class="try-btn" onclick="sendTry()">
            <i data-lucide="send" style="width:14px;height:14px"></i> Gönder
        </button>
        <div class="try-result" id="tryResult" style="display:none">
            <div class="try-result-head">
                <span>Yanıt</span>
                <span class="try-status" id="tryStatus"></span>
            </div>
            <div class="code-block"><pre id="tryResponse" style="max-height:300px;overflow-y:auto"></pre></div>
        </div>
    </div>
</div>

<script>
lucide.createIcons();

// Sidebar active state
document.querySelectorAll('.sb-link').forEach(link => {
    link.addEventListener('click', () => {
        document.querySelectorAll('.sb-link').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
    });
});

// Endpoint toggle
function toggleEp(head) {
    const body = head.nextElementSibling;
    if (body && body.classList.contains('ep-body')) {
        body.classList.toggle('open');
    }
}

// Code tabs
function showTab(btn, id) {
    const parent = btn.closest('.doc-section, .doc-hero, div');
    const tabs = btn.parentElement;
    tabs.querySelectorAll('.code-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    let sibling = tabs.nextElementSibling;
    while (sibling && sibling.classList.contains('code-block')) {
        sibling.style.display = 'none';
        sibling = sibling.nextElementSibling;
    }
    const target = document.getElementById(id);
    if (target) {
        target.style.display = 'block';
        target.style.borderRadius = '0 6px 6px 6px';
    }
}

// Copy code
function copyCode(btn) {
    const pre = btn.parentElement.querySelector('pre');
    navigator.clipboard.writeText(pre.textContent).then(() => {
        btn.innerHTML = '<i data-lucide="check" style="width:12px;height:12px"></i> Kopyalandı';
        lucide.createIcons();
        setTimeout(() => {
            btn.innerHTML = '<i data-lucide="copy" style="width:12px;height:12px"></i> Kopyala';
            lucide.createIcons();
        }, 2000);
    });
}

// Try It
function openTry(method, url, body) {
    document.getElementById('tryMethod').value = method;
    document.getElementById('tryUrl').value = url;
    document.getElementById('tryBody').value = body || '';
    document.getElementById('tryBodyField').style.display = (method === 'POST' || method === 'PUT' || method === 'PATCH') ? 'block' : 'none';
    document.getElementById('tryResult').style.display = 'none';
    document.getElementById('tryPanel').classList.add('open');
}

function closeTry() {
    document.getElementById('tryPanel').classList.remove('open');
}

async function sendTry() {
    const key = document.getElementById('tryKey').value;
    const secret = document.getElementById('trySecret').value;
    const method = document.getElementById('tryMethod').value;
    const url = document.getElementById('tryUrl').value;
    const body = document.getElementById('tryBody').value;

    if (!key || !secret) {
        alert('API Key ve Secret gerekli!');
        return;
    }

    const opts = {
        method,
        headers: {
            'X-API-Key': key,
            'X-API-Secret': secret,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };
    if (body && method !== 'GET') {
        opts.body = body;
    }

    try {
        const res = await fetch(url, opts);
        const data = await res.json();
        const statusEl = document.getElementById('tryStatus');
        statusEl.textContent = res.status + ' ' + res.statusText;
        statusEl.style.background = res.ok ? 'var(--green-bg)' : 'var(--red-bg)';
        statusEl.style.color = res.ok ? 'var(--green)' : 'var(--red)';
        document.getElementById('tryResponse').textContent = JSON.stringify(data, null, 2);
        document.getElementById('tryResult').style.display = 'block';
    } catch (err) {
        document.getElementById('tryResponse').textContent = 'Bağlantı hatası: ' + err.message;
        document.getElementById('tryResult').style.display = 'block';
        const statusEl = document.getElementById('tryStatus');
        statusEl.textContent = 'Hata';
        statusEl.style.background = 'var(--red-bg)';
        statusEl.style.color = 'var(--red)';
    }
}

// Close try panel on ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTry(); });
</script>
</body>
</html>
