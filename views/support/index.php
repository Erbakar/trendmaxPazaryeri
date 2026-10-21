<?php
/** Destek — talep listesi + yeni talep (merchant, tenant-scoped) */
$tickets = $data['tickets'] ?? [];
$counts  = $data['counts'] ?? ['all' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
$current = $data['current'] ?? '';
$csrf    = $data['_csrf'] ?? '';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$stMap = [
    'open'     => ['l' => 'Açık', 'c' => '#f59e0b', 'bg' => '#fffbeb'],
    'answered' => ['l' => 'Yanıtlandı', 'c' => '#10b981', 'bg' => '#f0fdf4'],
    'closed'   => ['l' => 'Kapalı', 'c' => '#94a3b8', 'bg' => '#f1f5f9'],
];
$prMap = ['low' => 'Düşük', 'normal' => 'Normal', 'high' => 'Yüksek'];
$fmt = static function ($s) {
    if (!$s) return '—';
    $t = strtotime((string) $s);
    return $t ? date('d.m.Y H:i', $t) : (string) $s;
};
$tabs = ['' => 'Tümü', 'open' => 'Açık', 'answered' => 'Yanıtlandı', 'closed' => 'Kapalı'];
?>
<style>
.sup-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.sup-title{font-size:20px;font-weight:700;color:var(--text-primary);margin:0;display:flex;align-items:center;gap:9px}
.sup-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:var(--primary);color:#fff;font-family:inherit;transition:.15s}
.sup-btn:hover{filter:brightness(1.07)}
.sup-btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text-secondary)}
.sup-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);overflow:hidden}
.sup-tabs{display:flex;gap:4px;padding:10px 12px;border-bottom:1px solid var(--border);overflow-x:auto}
.sup-tab{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:9px;font-size:13px;font-weight:600;color:var(--text-muted);text-decoration:none;white-space:nowrap;transition:.15s}
.sup-tab:hover{background:var(--bg-input);color:var(--text-primary)}
.sup-tab.act{background:var(--primary-bg);color:var(--primary)}
.sup-tab .cnt{display:inline-grid;place-items:center;min-width:20px;height:18px;padding:0 6px;border-radius:6px;background:rgba(15,23,42,.06);font-size:11px;font-weight:700}
.sup-tab.act .cnt{background:var(--primary);color:#fff}
.sup-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:background .1s}
.sup-row:last-child{border-bottom:none}
.sup-row:hover{background:var(--bg-sidebar-hover)}
.sup-row-main{flex:1;min-width:0}
.sup-row-subj{font-size:14px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sup-row-meta{font-size:12px;color:var(--text-muted);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap}
.sup-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;white-space:nowrap}
.sup-id{font-family:'Fira Code',monospace;font-size:12px;color:var(--text-muted)}
.sup-empty{text-align:center;padding:54px 20px;color:var(--text-muted)}
.sup-form-wrap{display:none;border-bottom:1px solid var(--border);padding:18px;background:var(--bg-input)}
.sup-form-wrap.open{display:block}
.sup-field{margin-bottom:12px}
.sup-field label{display:block;font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:5px}
.sup-input,.sup-textarea,.sup-select{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);font-family:inherit;box-sizing:border-box}
.sup-input:focus,.sup-textarea:focus,.sup-select:focus{border-color:var(--primary);outline:none}
.sup-textarea{min-height:110px;resize:vertical}
.sup-form-row{display:flex;gap:12px;flex-wrap:wrap}
.sup-form-row>div{flex:1;min-width:160px}
</style>

<div class="sup-head">
    <h1 class="sup-title"><i data-lucide="life-buoy" style="width:22px;height:22px;color:var(--primary)"></i> Destek</h1>
    <button type="button" class="sup-btn" onclick="document.getElementById('supForm').classList.toggle('open')">
        <i data-lucide="plus" style="width:16px;height:16px"></i> Yeni Talep
    </button>
</div>

<div class="sup-panel">
    <!-- Yeni talep formu -->
    <div class="sup-form-wrap" id="supForm">
        <form method="POST" action="/support">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <div class="sup-form-row">
                <div class="sup-field" style="flex:2">
                    <label>Konu</label>
                    <input type="text" name="subject" class="sup-input" maxlength="200" placeholder="Talebinizin konusu" required>
                </div>
                <div class="sup-field">
                    <label>Öncelik</label>
                    <select name="priority" class="sup-select">
                        <option value="normal">Normal</option>
                        <option value="low">Düşük</option>
                        <option value="high">Yüksek</option>
                    </select>
                </div>
            </div>
            <div class="sup-field">
                <label>Mesaj</label>
                <textarea name="body" class="sup-textarea" placeholder="Sorununuzu veya talebinizi detaylı yazın..." required></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="sup-btn sup-btn-ghost" onclick="document.getElementById('supForm').classList.remove('open')">Vazgeç</button>
                <button type="submit" class="sup-btn"><i data-lucide="send" style="width:15px;height:15px"></i> Gönder</button>
            </div>
        </form>
    </div>

    <!-- Durum sekmeleri -->
    <div class="sup-tabs">
        <?php foreach ($tabs as $key => $label):
            $cnt = $key === '' ? ($counts['all'] ?? 0) : ($counts[$key] ?? 0); ?>
        <a href="/support<?= $key !== '' ? '?status=' . $key : '' ?>" class="sup-tab <?= $current === $key ? 'act' : '' ?>">
            <?= $e($label) ?> <span class="cnt"><?= (int) $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Liste -->
    <?php if (empty($tickets)): ?>
    <div class="sup-empty">
        <i data-lucide="inbox" style="width:44px;height:44px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>
        Henüz destek talebiniz yok. "Yeni Talep" ile oluşturabilirsiniz.
    </div>
    <?php else: ?>
        <?php foreach ($tickets as $t): $st = $stMap[$t['status']] ?? $stMap['open']; ?>
        <a href="/support/<?= (int) $t['id'] ?>" class="sup-row">
            <span class="sup-id">#<?= (int) $t['id'] ?></span>
            <div class="sup-row-main">
                <div class="sup-row-subj"><?= $e($t['subject']) ?></div>
                <div class="sup-row-meta">
                    <span><i data-lucide="flag" style="width:12px;height:12px;vertical-align:-2px"></i> <?= $e($prMap[$t['priority']] ?? 'Normal') ?></span>
                    <span><i data-lucide="clock" style="width:12px;height:12px;vertical-align:-2px"></i> <?= $e($fmt($t['last_reply_at'] ?? $t['created_at'])) ?></span>
                    <?php if (($t['last_reply_by'] ?? '') === 'admin'): ?><span style="color:var(--success);font-weight:600">Destek yanıtladı</span><?php endif; ?>
                </div>
            </div>
            <span class="sup-badge" style="color:<?= $st['c'] ?>;background:<?= $st['bg'] ?>"><?= $e($st['l']) ?></span>
            <i data-lucide="chevron-right" style="width:18px;height:18px;color:var(--text-muted)"></i>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
