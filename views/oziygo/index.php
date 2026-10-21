<?php
/**
 * OzyConnect — Kendi E-Ticaret Siten (OziyGO) — Bağlantı Ayarları
 */
$apiUrl       = $data['apiUrl'] ?? '';
$apiKey       = $data['apiKey'] ?? '';
$isConfigured = $data['isConfigured'] ?? false;
$csrf         = $data['_csrf'] ?? '';
?>
<style>
.oz-wrap{max-width:720px;margin:0 auto}
.oz-card{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:28px 32px;margin-bottom:20px}
.oz-card h2{font-size:18px;font-weight:800;color:var(--text-primary);margin:0 0 6px;display:flex;align-items:center;gap:10px}
.oz-card h2 i{color:var(--primary)}
.oz-card p{font-size:13px;color:var(--text-muted);margin:0 0 18px;line-height:1.6}
.oz-form-group{margin-bottom:16px}
.oz-form-group label{display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.oz-form-group input{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg-page);color:var(--text-primary);font-size:13px;font-family:monospace;transition:border-color .15s}
.oz-form-group input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(78,115,223,.12)}
.oz-form-group .hint{font-size:10px;color:var(--text-muted);margin-top:3px}
.oz-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;border:none;cursor:pointer;transition:all .15s}
.oz-btn-primary{background:var(--primary);color:#fff}
.oz-btn-primary:hover{opacity:.9}
.oz-btn-outline{background:transparent;color:var(--text-primary);border:1px solid var(--border)}
.oz-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.oz-actions{display:flex;gap:8px;margin-top:20px}
.oz-result{margin-top:14px;padding:12px 16px;border-radius:10px;font-size:12px;display:none}
.oz-result.ok{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);color:#10b981}
.oz-result.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#ef4444}
.oz-info{background:var(--primary-bg);border:1px solid rgba(78,115,223,.15);border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:12px;color:var(--text-secondary);line-height:1.8}
.oz-info strong{color:var(--primary)}
.oz-status-bar{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:700}
.oz-status-bar.ok{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.15);color:#10b981}
.oz-status-bar.no{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);color:#ef4444}
.oz-stats{display:flex;gap:10px;margin-top:10px}
.oz-stat{text-align:center;flex:1}
.oz-stat-num{font-size:22px;font-weight:800;color:var(--text-primary)}
.oz-stat-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px}
.oz-tip{background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:14px 18px;margin-top:20px;font-size:12px;color:var(--text-secondary);line-height:1.8}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<div class="oz-wrap">
    <!-- Bilgi -->
    <div class="oz-info">
        🚀 <strong>Nasıl Çalışır?</strong><br>
        1. E-ticaret sitenizin admin panelinde <strong>OzyConnect</strong> menüsüne tıklayıp kurulumu yapın.<br>
        2. Kurulumdan aldığınız <strong>API URL</strong> ve <strong>API Anahtarı</strong> bilgilerini aşağıya girin.<br>
        3. <strong>Bağlantıyı Test Et</strong> ile kontrol edin.<br>
        4. Başarılıysa <strong>Senkronizasyon</strong> sayfasından ürün/sipariş çekmeye başlayın.
    </div>

    <?php if ($isConfigured): ?>
    <!-- Durum -->
    <div class="oz-status-bar ok" id="statusBar">
        <i data-lucide="check-circle" style="width:18px;height:18px"></i>
        <span>OziyGO bağlantısı yapılandırılmış</span>
    </div>
    <?php else: ?>
    <div class="oz-status-bar no">
        <i data-lucide="alert-circle" style="width:18px;height:18px"></i>
        <span>OziyGO bağlantısı henüz yapılandırılmamış</span>
    </div>
    <?php endif; ?>

    <!-- Ayar Kartı -->
    <div class="oz-card">
        <h2><i data-lucide="key" style="width:20px;height:20px"></i> Bağlantı Bilgileri</h2>
        <p>E-ticaret sitenizden aldığınız Bridge API bilgilerini girin.</p>

        <form method="POST" action="/oziygo/save">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="oz-form-group">
                <label>API URL</label>
                <input type="url" name="api_url" placeholder="https://siteniz.com/eklentiler/ozyconnect/api.php" value="<?= htmlspecialchars($apiUrl) ?>" required>
                <div class="hint">E-ticaret kurulumundan aldığınız API URL adresi</div>
            </div>
            <div class="oz-form-group">
                <label>API Anahtarı</label>
                <input type="text" name="api_key" placeholder="64 karakter hex token" value="<?= htmlspecialchars($apiKey) ?>" required>
                <div class="hint">E-ticaret kurulumundan aldığınız güvenlik anahtarı</div>
            </div>
            <div class="oz-actions">
                <button type="submit" class="oz-btn oz-btn-primary"><i data-lucide="save" style="width:14px;height:14px"></i> Kaydet</button>
                <?php if ($isConfigured): ?>
                <button type="button" class="oz-btn oz-btn-outline" id="btnTest"><i data-lucide="wifi" style="width:14px;height:14px"></i> Bağlantıyı Test Et</button>
                <?php endif; ?>
            </div>
        </form>
        <div class="oz-result" id="testResult"></div>
    </div>

    <?php if ($isConfigured): ?>
    <!-- Yönlendirme İpuçları -->
    <div class="oz-tip">
        💡 <strong>Ürünler & Siparişler:</strong>
        Bağlantı başarılıysa, <a href="/sync" style="color:var(--primary);font-weight:700">Senkronizasyon</a> sayfasına gidin ve:<br>
        • <strong>Ürün Senkronize Et</strong> → E-ticaret sitenizdeki ürünler OzyConnect'e aktarılır<br>
        • <strong>Sipariş Senkronize Et</strong> → Yeni siparişler otomatik çekilir<br>
        • <strong>Stok Senkronize Et</strong> → Stok değişiklikleri e-ticaret sitenize geri yazılır<br>
        OziyGO, diğer pazaryerleri (Trendyol, Hepsiburada vb.) ile aynı şekilde senkronize olur.
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    var btnTest = document.getElementById('btnTest');
    if (btnTest) {
        btnTest.addEventListener('click', function() {
            var result = document.getElementById('testResult');
            btnTest.disabled = true;
            btnTest.innerHTML = '<i data-lucide="loader" style="width:14px;height:14px;animation:spin 1s linear infinite"></i> Test ediliyor...';
            lucide.createIcons();

            fetch('/oziygo/test', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    var s = d.stats || {};
                    result.className = 'oz-result ok';
                    result.innerHTML = '✅ Bağlantı başarılı!'
                        + '<div class="oz-stats">'
                        + '<div class="oz-stat"><div class="oz-stat-num">' + (s.products||0) + '</div><div class="oz-stat-label">Ürün</div></div>'
                        + '<div class="oz-stat"><div class="oz-stat-num">' + (s.orders||0) + '</div><div class="oz-stat-label">Sipariş</div></div>'
                        + '<div class="oz-stat"><div class="oz-stat-num">' + (s.categories||0) + '</div><div class="oz-stat-label">Kategori</div></div>'
                        + '</div>';
                } else {
                    result.className = 'oz-result err';
                    result.innerHTML = '❌ ' + (d.message || 'Bağlantı başarısız');
                }
                result.style.display = 'block';
            })
            .catch(function(e) {
                result.className = 'oz-result err';
                result.innerHTML = '❌ ' + e.message;
                result.style.display = 'block';
            })
            .finally(function() {
                btnTest.disabled = false;
                btnTest.innerHTML = '<i data-lucide="wifi" style="width:14px;height:14px"></i> Bağlantıyı Test Et';
                lucide.createIcons();
            });
        });
    }
});
</script>
