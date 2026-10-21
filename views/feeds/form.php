<div style="margin-bottom:16px"><a href="/feeds" class="btn btn-sm btn-secondary">← Feedlere Dön</a></div>
<div class="card">
    <div class="card-header"><h3>+ Yeni Feed Oluştur</h3></div>
    <form method="POST" action="/feeds">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Feed Adı</label><input type="text" name="name" class="form-input" required placeholder="Google Shopping Feed"></div>
                <div class="form-group"><label class="form-label">Feed Türü</label>
                    <select name="feed_type" class="form-select"><option value="google_shopping">Google Shopping</option><option value="meta_catalog">Meta Catalog (Facebook/Instagram)</option><option value="generic">Genel XML</option></select>
                </div>
            </div>
            <div class="form-check"><input type="checkbox" name="is_active" value="1" checked> <label>Aktif</label></div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary">💾 Oluştur</button></div>
    </form>
</div>
