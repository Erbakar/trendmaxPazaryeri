<?php $feed = $data['feed'] ?? []; $appUrl = $data['appUrl'] ?? ''; $feedUrl = $appUrl . '/feed/' . ($feed['token'] ?? ''); ?>
<div style="margin-bottom:16px"><a href="/feeds" class="btn btn-sm btn-secondary">← Feedlere Dön</a></div>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>📡 Feed Bilgileri</h3></div>
        <div class="card-body">
            <table style="width:100%">
                <tr><td class="text-muted" style="padding:6px 0;width:130px">Ad:</td><td class="fw-bold"><?= htmlspecialchars($feed['name'] ?? '') ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Tür:</td><td><span class="badge badge-cyan"><?= htmlspecialchars($feed['feed_type'] ?? '') ?></span></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Ürün Sayısı:</td><td><?= (int) ($feed['generated_count'] ?? 0) ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Son Üretim:</td><td><?= !empty($feed['last_generated_at']) ? date('d.m.Y H:i', strtotime($feed['last_generated_at'])) : '—' ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Durum:</td><td><?= (int) ($feed['is_active'] ?? 0) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?></td></tr>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>🔗 Feed URL</h3></div>
        <div class="card-body">
            <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:12px;font-family:monospace;font-size:12px;word-break:break-all;margin-bottom:12px;color:#22d3ee"><?= htmlspecialchars($feedUrl) ?></div>
            <div class="btn-group">
                <button class="btn btn-sm btn-primary" data-copy="<?= htmlspecialchars($feedUrl) ?>">📋 URL Kopyala</button>
                <a href="<?= htmlspecialchars($feedUrl) ?>" target="_blank" class="btn btn-sm btn-secondary">🌐 Aç</a>
                <form method="POST" action="/feeds/<?= $feed['id'] ?>/regenerate" style="display:inline"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>"><button type="submit" class="btn btn-sm btn-warning">🔄 Yeniden Üret</button></form>
            </div>
        </div>
    </div>
</div>
