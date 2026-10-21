<?php
/** @var array $data */
$token = $data['token'] ?? [];
$stats = $data['stats'] ?? [];
$recentLogs = $data['recentLogs'] ?? [];
$allPerms = $data['allPermissions'] ?? [];
$showSecret = $data['showSecret'] ?? null;
$showKey = $data['showKey'] ?? null;
$permsList = $token['permissions_list'] ?? [];
$ipList = $token['ip_whitelist_list'] ?? [];
?>

<style>
.atd-page{display:flex;flex-direction:column;gap:20px;max-width:900px;margin:0 auto}
.atd-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.atd-header h1{font-size:20px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0}
.atd-header h1 i{width:22px;height:22px;color:var(--primary)}
.atd-alert{padding:16px 20px;border-radius:12px;border:1.5px solid;display:flex;align-items:flex-start;gap:12px}
.atd-alert i{width:20px;height:20px;flex-shrink:0;margin-top:2px}
.atd-alert-warn{background:#fffbeb;border-color:#f59e0b;color:#92400e}
.atd-alert-text{flex:1}
.atd-alert-text .title{font-weight:700;font-size:13px;margin-bottom:4px}
.atd-alert-text .desc{font-size:12px;line-height:1.6}
.atd-secret{font-family:'Fira Code',monospace;font-size:12px;background:#1e293b;color:#a5f3fc;padding:10px 14px;border-radius:8px;margin-top:8px;display:flex;align-items:center;justify-content:space-between;word-break:break-all}
.atd-secret button{background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px}
.atd-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;overflow:hidden}
.atd-card-head{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.atd-card-head h3{margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px}
.atd-card-head h3 i{width:15px;height:15px;color:var(--primary)}
.atd-card-body{padding:20px}
.atd-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:768px){.atd-grid-2{grid-template-columns:1fr}}
.atd-info{margin-bottom:14px}
.atd-info .lbl{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px}
.atd-info .val{font-size:14px;font-weight:600;color:var(--text-primary)}
.atd-info .mono{font-family:'Fira Code',monospace;font-size:12px;color:#475569;background:#f8fafc;padding:4px 8px;border-radius:6px;display:inline-block}
.atd-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.atd-stat{text-align:center;padding:14px;background:#f8fafc;border-radius:10px}
.atd-stat .val{font-size:22px;font-weight:700}
.atd-stat .lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;margin-top:2px}
.atd-perms{display:flex;flex-wrap:wrap;gap:6px}
.atd-perm{font-size:11px;font-weight:600;padding:3px 10px;border-radius:8px}
.atd-perm-on{background:#f0fdf4;color:#10b981}
.atd-perm-off{background:#f8fafc;color:#cbd5e1;text-decoration:line-through}
.atd-log-table{width:100%;border-collapse:collapse;font-size:12px}
.atd-log-table th{text-align:left;padding:8px 12px;background:#f8fafc;font-weight:600;color:var(--text-muted);text-transform:uppercase;font-size:10px}
.atd-log-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9}
.atd-log-method{font-weight:700;font-size:11px;padding:2px 8px;border-radius:4px;display:inline-block}
.atd-log-get{background:#eff6ff;color:#3b82f6}
.atd-log-post{background:#f0fdf4;color:#10b981}
.atd-log-put{background:#fffbeb;color:#f59e0b}
.atd-log-delete{background:#fef2f2;color:#ef4444}
.atd-log-status{font-weight:700;font-size:11px}
.atd-actions{display:flex;gap:8px;flex-wrap:wrap}
.atd-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;border:1px solid var(--border-color);background:#fff;cursor:pointer;font-family:inherit;text-decoration:none;color:var(--text-primary);transition:all .15s}
.atd-btn:hover{border-color:var(--primary);color:var(--primary)}
.atd-btn i{width:14px;height:14px}
.atd-btn-danger{color:#ef4444;border-color:#fecaca}.atd-btn-danger:hover{background:#fef2f2;border-color:#ef4444}
</style>

<div class="atd-page">
    <div class="atd-header">
        <h1><i data-lucide="key-round"></i> <?= htmlspecialchars($token['name'] ?? '') ?></h1>
        <a href="/settings/api-tokens" class="atd-btn"><i data-lucide="arrow-left"></i> Geri</a>
    </div>

    <?php if ($showSecret): ?>
    <div class="atd-alert atd-alert-warn">
        <i data-lucide="alert-triangle"></i>
        <div class="atd-alert-text">
            <div class="title">API Secret — Bu bilgiyi kaydedin!</div>
            <div class="desc">Secret bir daha gösterilmeyecek. Güvenli bir yere kaydedin.</div>
            <div class="atd-secret">
                <span>API Key: <?= htmlspecialchars($showKey ?? '') ?></span>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($showKey ?? '') ?>')" title="Key Kopyala"><i data-lucide="copy" style="width:14px;height:14px"></i></button>
            </div>
            <div class="atd-secret" style="margin-top:6px">
                <span>API Secret: <?= htmlspecialchars($showSecret) ?></span>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($showSecret) ?>')" title="Secret Kopyala"><i data-lucide="copy" style="width:14px;height:14px"></i></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="atd-stats">
        <div class="atd-stat">
            <div class="val" style="color:var(--primary)"><?= number_format($stats['total_requests'] ?? 0) ?></div>
            <div class="lbl">Toplam İstek</div>
        </div>
        <div class="atd-stat">
            <div class="val" style="color:#10b981"><?= number_format($stats['today_requests'] ?? 0) ?></div>
            <div class="lbl">Bugün</div>
        </div>
        <div class="atd-stat">
            <div class="val" style="color:#f59e0b"><?= ($stats['avg_response_ms'] ?? 0) ?> ms</div>
            <div class="lbl">Ort. Yanıt Süresi</div>
        </div>
    </div>

    <div class="atd-grid-2">
        <!-- Token Bilgileri -->
        <div class="atd-card">
            <div class="atd-card-head">
                <h3><i data-lucide="info"></i> Token Bilgileri</h3>
                <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:8px;<?= $token['is_active'] ? 'background:#f0fdf4;color:#10b981' : 'background:#fef2f2;color:#ef4444' ?>">
                    <?= $token['is_active'] ? 'Aktif' : 'Pasif' ?>
                </span>
            </div>
            <div class="atd-card-body">
                <div class="atd-info">
                    <div class="lbl">API Key</div>
                    <div class="val"><span class="mono"><?= htmlspecialchars($token['api_key'] ?? '') ?></span></div>
                </div>
                <div class="atd-info">
                    <div class="lbl">Rate Limit</div>
                    <div class="val"><?= (int)($token['rate_limit'] ?? 60) ?>/dakika</div>
                </div>
                <div class="atd-info">
                    <div class="lbl">Son Kullanım</div>
                    <div class="val"><?= $token['last_used_at'] ? date('d.m.Y H:i', strtotime($token['last_used_at'])) : 'Hiç kullanılmadı' ?></div>
                </div>
                <div class="atd-info">
                    <div class="lbl">Oluşturulma</div>
                    <div class="val"><?= date('d.m.Y H:i', strtotime($token['created_at'])) ?></div>
                </div>
                <div class="atd-info">
                    <div class="lbl">Son Kullanma</div>
                    <div class="val"><?= $token['expires_at'] ? date('d.m.Y H:i', strtotime($token['expires_at'])) : 'Süresiz' ?></div>
                </div>
                <?php if (!empty($ipList)): ?>
                <div class="atd-info">
                    <div class="lbl">IP Whitelist</div>
                    <div class="val"><?= implode(', ', array_map('htmlspecialchars', $ipList)) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- İzinler -->
        <div class="atd-card">
            <div class="atd-card-head">
                <h3><i data-lucide="shield-check"></i> İzinler</h3>
            </div>
            <div class="atd-card-body">
                <div class="atd-perms">
                    <?php foreach ($allPerms as $key => $label): ?>
                    <span class="atd-perm <?= in_array($key, $permsList, true) ? 'atd-perm-on' : 'atd-perm-off' ?>">
                        <?= htmlspecialchars($key) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- İşlemler -->
    <div class="atd-actions">
        <form method="post" action="/settings/api-tokens/<?= $token['id'] ?>/toggle" style="display:inline">
            <input type="hidden" name="_csrf_token" value="<?= $data['_csrf'] ?>">
            <button type="submit" class="atd-btn">
                <i data-lucide="<?= $token['is_active'] ? 'pause' : 'play' ?>"></i>
                <?= $token['is_active'] ? 'Devre Dışı Bırak' : 'Aktifleştir' ?>
            </button>
        </form>
        <form method="post" action="/settings/api-tokens/<?= $token['id'] ?>/regenerate" style="display:inline"
              onsubmit="return confirm('Yeni secret oluşturulacak. Eski secret artık çalışmayacak. Emin misiniz?')">
            <input type="hidden" name="_csrf_token" value="<?= $data['_csrf'] ?>">
            <button type="submit" class="atd-btn">
                <i data-lucide="refresh-cw"></i> Secret Yenile
            </button>
        </form>
        <form method="post" action="/settings/api-tokens/<?= $token['id'] ?>/delete" style="display:inline"
              onsubmit="return confirm('API anahtarı kalıcı olarak silinecek. Emin misiniz?')">
            <input type="hidden" name="_csrf_token" value="<?= $data['_csrf'] ?>">
            <button type="submit" class="atd-btn atd-btn-danger">
                <i data-lucide="trash-2"></i> Sil
            </button>
        </form>
    </div>

    <!-- Son İstekler -->
    <div class="atd-card">
        <div class="atd-card-head">
            <h3><i data-lucide="activity"></i> Son API İstekleri</h3>
        </div>
        <div class="atd-card-body" style="padding:0;overflow-x:auto">
            <?php if (empty($recentLogs)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
                <i data-lucide="inbox" style="width:32px;height:32px;display:block;margin:0 auto 8px"></i>
                Henüz API isteği yok
            </div>
            <?php else: ?>
            <table class="atd-log-table">
                <thead>
                    <tr><th>Tarih</th><th>Method</th><th>Endpoint</th><th>Durum</th><th>Süre</th><th>IP</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?= date('d.m H:i:s', strtotime($log['created_at'])) ?></td>
                        <td><span class="atd-log-method atd-log-<?= strtolower($log['method']) ?>"><?= $log['method'] ?></span></td>
                        <td style="font-family:monospace;font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($log['endpoint']) ?></td>
                        <td><span class="atd-log-status" style="color:<?= $log['status_code'] < 400 ? '#10b981' : '#ef4444' ?>"><?= $log['status_code'] ?></span></td>
                        <td><?= $log['response_time_ms'] ?> ms</td>
                        <td style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($log['ip_address']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
