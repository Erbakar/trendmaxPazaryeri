<?php
/** Güncellemeler — changelog (merchant, salt-okunur) */
$updates = $data['updates'] ?? [];
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$typeMap = [
    'feature'      => ['l' => 'Yeni Özellik', 'c' => '#10b981', 'bg' => '#ecfdf5', 'i' => 'sparkles'],
    'improvement'  => ['l' => 'İyileştirme', 'c' => 'var(--primary)', 'bg' => '#eef2ff', 'i' => 'trending-up'],
    'fix'          => ['l' => 'Düzeltme', 'c' => '#f59e0b', 'bg' => '#fffbeb', 'i' => 'wrench'],
    'announcement' => ['l' => 'Duyuru', 'c' => '#6366f1', 'bg' => '#eef2ff', 'i' => 'megaphone'],
];
$fmt = static function ($s) {
    if (!$s) return '';
    $t = strtotime((string) $s);
    return $t ? date('d.m.Y', $t) : (string) $s;
};
?>
<style>
.up-title{font-size:20px;font-weight:700;color:var(--text-primary);margin:0 0 4px;display:flex;align-items:center;gap:9px}
.up-sub{font-size:13px;color:var(--text-muted);margin:0 0 20px}
.up-list{display:flex;flex-direction:column;gap:14px;max-width:760px}
.up-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);padding:18px 20px}
.up-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.up-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 11px;border-radius:16px;font-size:11px;font-weight:700}
.up-badge i{width:12px;height:12px}
.up-ver{font-family:'Fira Code',monospace;font-size:12px;color:var(--text-muted);background:var(--bg-input);padding:2px 8px;border-radius:6px}
.up-date{margin-left:auto;font-size:12px;color:var(--text-muted)}
.up-h{font-size:15.5px;font-weight:700;color:var(--text-primary);margin:0 0 6px}
.up-body{font-size:13.5px;line-height:1.65;color:var(--text-secondary);white-space:pre-wrap;word-break:break-word}
.up-empty{text-align:center;padding:54px 20px;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);border-radius:12px;max-width:760px}
</style>

<h1 class="up-title"><i data-lucide="download-cloud" style="width:22px;height:22px;color:var(--primary)"></i> Güncellemeler</h1>
<p class="up-sub">Panele eklenen yenilikler, iyileştirmeler ve duyurular.</p>

<?php if (empty($updates)): ?>
<div class="up-empty">
    <i data-lucide="inbox" style="width:44px;height:44px;margin:0 auto 12px;display:block;color:var(--text-muted)"></i>
    Henüz yayınlanmış bir güncelleme yok.
</div>
<?php else: ?>
<div class="up-list">
    <?php foreach ($updates as $u): $t = $typeMap[$u['type']] ?? $typeMap['announcement']; ?>
    <div class="up-card">
        <div class="up-head">
            <span class="up-badge" style="color:<?= $t['c'] ?>;background:<?= $t['bg'] ?>"><i data-lucide="<?= $t['i'] ?>"></i> <?= $e($t['l']) ?></span>
            <?php if (!empty($u['version'])): ?><span class="up-ver">v<?= $e($u['version']) ?></span><?php endif; ?>
            <span class="up-date"><?= $e($fmt($u['published_at'] ?? $u['created_at'])) ?></span>
        </div>
        <h2 class="up-h"><?= $e($u['title']) ?></h2>
        <?php if (!empty($u['body'])): ?><div class="up-body"><?= $e($u['body']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
