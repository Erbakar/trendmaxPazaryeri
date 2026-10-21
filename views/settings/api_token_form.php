<?php
/** @var array $data */
$permissions = $data['permissions'] ?? [];
?>

<style>
.atf-page{max-width:700px;margin:0 auto}
.atf-header{margin-bottom:24px}
.atf-header h1{font-size:20px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0}
.atf-header h1 i{width:22px;height:22px;color:var(--primary)}
.atf-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;overflow:hidden}
.atf-section{padding:20px 24px;border-bottom:1px solid #f1f5f9}
.atf-section:last-child{border-bottom:none}
.atf-section h3{font-size:13px;font-weight:600;color:var(--text-primary);margin:0 0 14px;display:flex;align-items:center;gap:6px}
.atf-section h3 i{width:15px;height:15px;color:var(--primary)}
.atf-field{margin-bottom:14px}
.atf-field:last-child{margin-bottom:0}
.atf-field label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:4px}
.atf-field input,.atf-field textarea{width:100%;padding:9px 12px;border:1px solid var(--border-color);border-radius:8px;font-size:13px;font-family:inherit;transition:border-color .15s}
.atf-field input:focus,.atf-field textarea:focus{outline:none;border-color:var(--primary)}
.atf-field .hint{font-size:11px;color:var(--text-muted);margin-top:3px}
.atf-perms{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.atf-perm{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border-color);border-radius:8px;cursor:pointer;transition:all .15s}
.atf-perm:hover{border-color:var(--primary);background:#f8f7ff}
.atf-perm input{accent-color:var(--primary)}
.atf-perm-info .name{font-size:12px;font-weight:600;color:var(--text-primary)}
.atf-perm-info .desc{font-size:10px;color:var(--text-muted)}
.atf-actions{padding:20px 24px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #f1f5f9}
</style>

<div class="atf-page">
    <div class="atf-header">
        <h1><i data-lucide="plus-circle"></i> Yeni API Anahtarı</h1>
    </div>

    <form method="post" action="/settings/api-tokens">
        <input type="hidden" name="_csrf_token" value="<?= $data['_csrf'] ?>">

        <div class="atf-card">
            <div class="atf-section">
                <h3><i data-lucide="tag"></i> Genel Bilgiler</h3>
                <div class="atf-field">
                    <label>Token Adı *</label>
                    <input type="text" name="name" required placeholder="Örn: Web Site Entegrasyonu" maxlength="200">
                </div>
                <div class="atf-field">
                    <label>Rate Limit (dakikada max istek)</label>
                    <input type="number" name="rate_limit" value="60" min="1" max="1000">
                    <div class="hint">Varsayılan: 60/dakika. Yüksek trafikli entegrasyonlar için artırabilirsiniz.</div>
                </div>
                <div class="atf-field">
                    <label>Son Kullanma Tarihi (opsiyonel)</label>
                    <input type="datetime-local" name="expires_at">
                    <div class="hint">Boş bırakırsanız süresiz olur.</div>
                </div>
            </div>

            <div class="atf-section">
                <h3><i data-lucide="shield-check"></i> İzinler</h3>
                <div class="atf-perms">
                    <?php foreach ($permissions as $key => $label): ?>
                    <label class="atf-perm">
                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>">
                        <div class="atf-perm-info">
                            <div class="name"><?= htmlspecialchars($key) ?></div>
                            <div class="desc"><?= htmlspecialchars($label) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="atf-section">
                <h3><i data-lucide="globe"></i> IP Kısıtlaması (opsiyonel)</h3>
                <div class="atf-field">
                    <label>İzin Verilen IP Adresleri</label>
                    <textarea name="ip_whitelist" rows="3" placeholder="Her satıra bir IP adresi yazın. Boş = tüm IP'ler"></textarea>
                    <div class="hint">Güvenlik için sadece belirli IP adreslerinden erişime izin verebilirsiniz.</div>
                </div>
            </div>

            <div class="atf-actions">
                <a href="/settings/api-tokens" class="btn btn-secondary">İptal</a>
                <button type="submit" class="btn btn-primary" style="display:flex;align-items:center;gap:6px">
                    <i data-lucide="key-round" style="width:15px;height:15px"></i> Oluştur
                </button>
            </div>
        </div>
    </form>
</div>
