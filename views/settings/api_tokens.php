<?php
/** @var array $data */
$tokens = $data['tokens'] ?? [];
?>

<style>
.at-page{display:flex;flex-direction:column;gap:20px}
.at-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.at-header h1{font-size:20px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0}
.at-header h1 i{width:22px;height:22px;color:var(--primary)}
.at-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:16px}
.at-card{background:#fff;border:1px solid var(--border-color);border-radius:12px;overflow:hidden;transition:all .2s}
.at-card:hover{box-shadow:0 0 0 1px var(--border)}
.at-card-head{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.at-card-name{font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px}
.at-card-name i{width:16px;height:16px;color:var(--primary)}
.at-badge{display:inline-flex;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:600}
.at-badge-active{background:#f0fdf4;color:#10b981}
.at-badge-inactive{background:#fef2f2;color:#ef4444}
.at-card-body{padding:16px 20px}
.at-key{font-family:'Fira Code',monospace;font-size:12px;background:#f8fafc;padding:6px 10px;border-radius:8px;border:1px solid #e2e8f0;color:#475569;display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.at-key code{user-select:all}
.at-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.at-stat{text-align:center;padding:8px;background:#f8fafc;border-radius:8px}
.at-stat .val{font-size:16px;font-weight:700;color:var(--text-primary)}
.at-stat .lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase}
.at-perms{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px}
.at-perm{font-size:10px;font-weight:600;padding:2px 8px;border-radius:6px;background:#f5f3ff;color:var(--primary)}
.at-card-foot{padding:12px 20px;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.at-meta{font-size:11px;color:var(--text-muted)}
.at-actions{display:flex;gap:6px}
.at-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;font-size:11px;font-weight:600;border:1px solid var(--border-color);background:#fff;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none;color:var(--text-primary)}
.at-btn:hover{border-color:var(--primary);color:var(--primary)}
.at-btn i{width:13px;height:13px}
.at-btn-danger{color:#ef4444;border-color:#fecaca}.at-btn-danger:hover{background:#fef2f2;border-color:#ef4444}
.at-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.at-empty i{width:48px;height:48px;display:block;margin:0 auto 12px;color:#cbd5e1}
.at-empty p{margin:0 0 16px;font-size:14px}
</style>

<div class="at-page">
    <div class="at-header">
        <h1><i data-lucide="key-round"></i> API Anahtarları</h1>
        <span class="at-meta" style="display:inline-flex;align-items:center;gap:6px">
            <i data-lucide="shield" style="width:15px;height:15px"></i> Anahtarlar platform yöneticisi tarafından yönetilir
        </span>
    </div>

    <?php if (empty($tokens)): ?>
    <div class="at-empty">
        <i data-lucide="key-round"></i>
        <p>Henüz API anahtarı tanımlanmamış</p>
        <p style="font-size:12px">API anahtarı talebi için platform yöneticinizle iletişime geçin.</p>
    </div>
    <?php else: ?>
    <div class="at-grid">
        <?php foreach ($tokens as $t): ?>
        <div class="at-card">
            <div class="at-card-head">
                <div class="at-card-name">
                    <i data-lucide="key-round"></i>
                    <?= htmlspecialchars($t['name']) ?>
                </div>
                <span class="at-badge <?= $t['is_active'] ? 'at-badge-active' : 'at-badge-inactive' ?>">
                    <?= $t['is_active'] ? 'Aktif' : 'Pasif' ?>
                </span>
            </div>
            <div class="at-card-body">
                <div class="at-key">
                    <code><?= htmlspecialchars($t['api_key']) ?></code>
                    <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($t['api_key']) ?>')" title="Kopyala" style="background:none;border:none;cursor:pointer;padding:2px">
                        <i data-lucide="copy" style="width:14px;height:14px;color:#94a3b8"></i>
                    </button>
                </div>
                <div class="at-stats">
                    <div class="at-stat">
                        <div class="val"><?= number_format($t['stats']['total_requests'] ?? 0) ?></div>
                        <div class="lbl">Toplam İstek</div>
                    </div>
                    <div class="at-stat">
                        <div class="val"><?= number_format($t['stats']['today_requests'] ?? 0) ?></div>
                        <div class="lbl">Bugün</div>
                    </div>
                    <div class="at-stat">
                        <div class="val"><?= ($t['stats']['avg_response_ms'] ?? 0) ?> ms</div>
                        <div class="lbl">Ort. Yanıt</div>
                    </div>
                </div>
                <div class="at-perms">
                    <?php foreach (($t['permissions_list'] ?? []) as $perm): ?>
                    <span class="at-perm"><?= htmlspecialchars($perm) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($t['permissions_list'])): ?>
                    <span class="at-perm" style="background:#fef2f2;color:#ef4444">İzin yok</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="at-card-foot">
                <div class="at-meta">
                    <?php if ($t['last_used_at']): ?>
                        Son: <?= date('d.m.Y H:i', strtotime($t['last_used_at'])) ?>
                    <?php else: ?>
                        Hiç kullanılmadı
                    <?php endif; ?>
                    <?php if ($t['creator_name']): ?>
                        · <?= htmlspecialchars($t['creator_name']) ?>
                    <?php endif; ?>
                </div>
                <span class="at-meta" style="font-size:10px"><?= $t['is_active'] ? 'Aktif' : 'Pasif' ?> · yönetici kontrolünde</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
