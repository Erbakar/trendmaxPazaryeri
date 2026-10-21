<?php
$feeds = $data['feeds'] ?? [];
$appUrl = $data['appUrl'] ?? '';
$feedToken = $data['feedToken'] ?? '';
$feedIpWhitelist = $data['feedIpWhitelist'] ?? '';
$feedPassword = $data['feedPassword'] ?? '';
$feedEnabled = $data['feedEnabled'] ?? false;
$feedCacheHours = $data['feedCacheHours'] ?? 4;
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>

<style>
.fd-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.fd-full{grid-column:1/-1}
.fd-card{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;overflow:hidden}
.fd-head{padding:12px 16px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border);background:var(--bg-sidebar)}
.fd-head [data-lucide]{width:14px;height:14px}
.fd-body{padding:16px}
.fd-row{display:grid;gap:10px;margin-bottom:10px}
.fd-r2{grid-template-columns:1fr 1fr}
.fd-f{display:flex;flex-direction:column;gap:3px}
.fd-f label{font-size:11px;font-weight:700;color:var(--text-secondary)}
.fd-f input,.fd-f select,.fd-f textarea{padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-sidebar);color:var(--text-primary);font-size:12px;font-family:inherit}
.fd-f input:focus,.fd-f textarea:focus{outline:none;border-color:var(--primary)}
.fd-f textarea{min-height:60px;resize:vertical;font-family:'JetBrains Mono',monospace;font-size:11px}
.fd-foot{padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fd-url{display:flex;gap:6px;align-items:center;margin:8px 0}
.fd-url-box{flex:1;padding:7px 10px;background:var(--bg-sidebar);border:1px solid var(--border);border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-secondary);word-break:break-all;cursor:text;user-select:all}
.fd-url-copy{padding:5px 10px;border:1px solid var(--border);border-radius:5px;background:none;color:var(--text-muted);font-size:10px;cursor:pointer;white-space:nowrap}
.fd-url-copy:hover{border-color:var(--primary);color:var(--primary)}
.fd-toggle{display:flex;align-items:center;gap:8px;cursor:pointer}
.fd-toggle input[type="checkbox"]{width:36px;height:20px;appearance:none;background:var(--border);border-radius:10px;position:relative;cursor:pointer;transition:all .2s}
.fd-toggle input[type="checkbox"]::after{content:'';width:16px;height:16px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;transition:all .2s}
.fd-toggle input[type="checkbox"]:checked{background:var(--primary)}
.fd-toggle input[type="checkbox"]:checked::after{left:18px}
.fd-toggle span{font-size:12px;font-weight:700;color:var(--text-secondary)}
.fd-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.fd-dot.on{background:#10b981;box-shadow:0 0 0 1px var(--border)}
.fd-dot.off{background:var(--text-muted)}
@media(max-width:768px){.fd-grid,.fd-r2{grid-template-columns:1fr}}
</style>
<?php $subnav = ['active' => '/feeds', 'items' => [
  ['u' => '/products', 'l' => 'Ürünler'],
  ['u' => '/categories', 'l' => 'Kategoriler'],
  ['u' => '/brands', 'l' => 'Markalar'],
  ['u' => '/feeds', 'l' => 'Feed'],
  ['u' => '/xml-profilleri', 'l' => 'XML Profilleri'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>


<div class="fd-grid">

    <!-- === CANLŞ XML FEED AYARLARI === -->
    <div class="fd-card">
        <div class="fd-head"><i data-lucide="rss"></i> Canlı XML Feed</div>
        <form method="POST" action="/feeds/save-settings">
            <input type="hidden" name="_csrf_token" value="<?=htmlspecialchars($data['_csrf']??'')?>">
            <div class="fd-body">
                <div class="fd-toggle" style="margin-bottom:12px">
                    <input type="checkbox" name="feed_enabled" value="1" <?=$feedEnabled?'checked':''?>>
                    <span>Feed Aktif</span>
                    <span class="fd-dot <?=$feedEnabled?'on':'off'?>" style="margin-left:4px"></span>
                </div>

                <div class="fd-f" style="margin-bottom:8px">
                    <label>Feed adresi</label>
                    <p style="font-size:12px;color:var(--text-muted);margin:0">Paylaşılabilir adresler yandaki <b>Feed listesinde</b>; her feed kendi anahtarlı adresiyle (<code>/feed/&lt;anahtar&gt;</code>) sunulur. Pazaryerine bu adresi verin.</p>
                </div>

                <div class="fd-row fd-r2">
                    <div class="fd-f">
                        <label>Feed Önbellek Süresi (Saat)</label>
                        <input type="number" name="feed_cache_hours" value="<?=(int)$feedCacheHours?>" min="1" max="168">
                    </div>
                    <div class="fd-f">
                        <label>Feed Şifresi (opsiyonel)</label>
                        <input type="text" name="feed_password" value="<?=htmlspecialchars($feedPassword)?>" placeholder="Boş = şifresiz">
                        <span style="font-size:10px;color:var(--text-muted)">URL'ye &pass=şifre eklenmelidir</span>
                    </div>
                </div>
                <div class="fd-f" style="margin-top:10px">
                    <label>IP Kısıtlama (opsiyonel)</label>
                    <textarea name="feed_ip_whitelist" placeholder="Satır başına bir IP&#10;Boş = tüm IP'ler"><?=htmlspecialchars($feedIpWhitelist)?></textarea>
                </div>
            </div>
            <div class="fd-foot">
                <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="save" style="width:14px;height:14px"></i> Kaydet</button>
                <label style="font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:4px;margin-left:auto">
                    <input type="checkbox" name="regenerate_token" value="1"> Token Yenile
                </label>
            </div>
        </form>
    </div>

    <!-- === FEED LİSTESİ === -->
    <div class="fd-card">
        <div class="fd-head">
            <i data-lucide="list"></i> Feed Listesi
            <div style="margin-left:auto;display:flex;gap:6px">
                <a href="/feeds/create" class="btn btn-success btn-sm" style="font-size:11px">+ Yeni Feed</a>
                <form method="POST" action="/feeds/regenerate-all" style="display:inline"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>"><button type="submit" class="btn btn-warning btn-sm" style="font-size:11px"><i data-lucide="refresh-cw" style="width:12px;height:12px"></i> Tümünü Üret</button></form>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Feed Adı</th><th>Tür</th><th>Ürün Sayısı</th><th>Son Üretim</th><th>Durum</th><th>Feed URL</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($feeds)): ?>
                    <tr><td colspan="7" class="empty-state"><div class="icon"><i data-lucide="rss" style="width:32px;height:32px;color:var(--text-muted)"></i></div><p>Feed bulunamadı</p></td></tr>
                <?php else: foreach ($feeds as $f): ?>
                <tr>
                    <td class="fw-bold"><?= htmlspecialchars($f['name'] ?? '') ?></td>
                    <td><span class="badge badge-cyan"><?= match($f['feed_type'] ?? 'generic') { 'google_shopping' => 'Google Shopping', 'facebook' => 'Facebook', 'xml' => 'XML', 'csv' => 'CSV', default => htmlspecialchars($f['feed_type'] ?? 'Genel') } ?></span></td>
                    <td class="text-center"><?= (int) ($f['generated_count'] ?? 0) ?></td>
                    <td class="text-muted text-nowrap" style="font-size:12px"><?= !empty($f['last_generated_at']) ? date('d.m.Y H:i', strtotime($f['last_generated_at'])) : '—' ?></td>
                    <td><?= (int) ($f['is_active'] ?? 0) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?></td>
                    <td><button class="btn btn-sm btn-secondary" data-copy="<?= htmlspecialchars($appUrl . '/feed/' . ($f['token'] ?? '')) ?>"><i data-lucide="copy" style="width:12px;height:12px"></i> Kopyala</button></td>
                    <td><form method="POST" action="/feeds/<?= $f['id'] ?>/regenerate" style="display:inline"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>"><button type="submit" class="btn btn-sm btn-primary"><i data-lucide="refresh-cw" style="width:12px;height:12px"></i></button></form></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
