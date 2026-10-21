<?php
/** Destek — talep detayı + yanıt (merchant, tenant-scoped) */
$ticket   = $data['ticket'] ?? [];
$messages = $data['messages'] ?? [];
$csrf     = $data['_csrf'] ?? '';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$stMap = [
    'open'     => ['l' => 'Açık', 'c' => '#f59e0b', 'bg' => '#fffbeb'],
    'answered' => ['l' => 'Yanıtlandı', 'c' => '#10b981', 'bg' => '#f0fdf4'],
    'closed'   => ['l' => 'Kapalı', 'c' => '#94a3b8', 'bg' => '#f1f5f9'],
];
$prMap = ['low' => 'Düşük', 'normal' => 'Normal', 'high' => 'Yüksek'];
$st = $stMap[$ticket['status'] ?? 'open'] ?? $stMap['open'];
$isClosed = ($ticket['status'] ?? '') === 'closed';
$fmt = static function ($s) {
    if (!$s) return '';
    $t = strtotime((string) $s);
    return $t ? date('d.m.Y H:i', $t) : (string) $s;
};
?>
<style>
.sup-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted);text-decoration:none;margin-bottom:14px}
.sup-back:hover{color:var(--primary)}
.sup-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);overflow:hidden}
.sup-thead{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.sup-tsubj{font-size:17px;font-weight:700;color:var(--text-primary);margin:0 0 6px}
.sup-tmeta{font-size:12px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap}
.sup-badge{display:inline-flex;align-items:center;padding:4px 12px;border-radius:16px;font-size:12px;font-weight:700;white-space:nowrap}
.sup-thread{padding:20px;display:flex;flex-direction:column;gap:14px;background:var(--bg-input)}
.sup-msg{max-width:78%;padding:12px 15px;border-radius:12px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word}
.sup-msg-meta{font-size:11px;margin-bottom:5px;font-weight:700;opacity:.75}
.sup-msg.me{align-self:flex-end;background:var(--primary);color:#fff;border-bottom-right-radius:4px}
.sup-msg.them{align-self:flex-start;background:var(--bg-card);border:1px solid var(--border);color:var(--text-primary);border-bottom-left-radius:4px}
.sup-reply{padding:16px 20px;border-top:1px solid var(--border)}
.sup-textarea{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit;box-sizing:border-box;min-height:90px;resize:vertical}
.sup-textarea:focus{border-color:var(--primary);outline:none}
.sup-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap}
.sup-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:var(--primary);color:#fff;font-family:inherit}
.sup-btn:hover{filter:brightness(1.07)}
.sup-btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text-secondary)}
.sup-closed-note{padding:16px 20px;border-top:1px solid var(--border);text-align:center;color:var(--text-muted);font-size:13px}
</style>

<a href="/support" class="sup-back"><i data-lucide="arrow-left" style="width:15px;height:15px"></i> Destek taleplerine dön</a>

<div class="sup-card">
    <div class="sup-thead">
        <div>
            <h1 class="sup-tsubj"><?= $e($ticket['subject'] ?? '') ?></h1>
            <div class="sup-tmeta">
                <span>#<?= (int) ($ticket['id'] ?? 0) ?></span>
                <span><i data-lucide="flag" style="width:12px;height:12px;vertical-align:-2px"></i> <?= $e($prMap[$ticket['priority'] ?? 'normal'] ?? 'Normal') ?> öncelik</span>
                <span><i data-lucide="calendar" style="width:12px;height:12px;vertical-align:-2px"></i> <?= $e($fmt($ticket['created_at'] ?? '')) ?></span>
            </div>
        </div>
        <span class="sup-badge" style="color:<?= $st['c'] ?>;background:<?= $st['bg'] ?>"><?= $e($st['l']) ?></span>
    </div>

    <div class="sup-thread">
        <?php foreach ($messages as $m): $me = ($m['sender_type'] ?? '') === 'merchant'; ?>
        <div class="sup-msg <?= $me ? 'me' : 'them' ?>">
            <div class="sup-msg-meta"><?= $me ? 'Siz' : 'Destek Ekibi' ?> · <?= $e($fmt($m['created_at'] ?? '')) ?></div>
            <?= $e($m['body'] ?? '') ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$isClosed): ?>
    <div class="sup-reply">
        <form id="supReplyForm" method="POST" action="/support/<?= (int) $ticket['id'] ?>/reply">
            <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
            <textarea name="body" class="sup-textarea" placeholder="Yanıtınızı yazın..." required></textarea>
        </form>
        <div class="sup-actions">
            <form method="POST" action="/support/<?= (int) $ticket['id'] ?>/close" onsubmit="return confirm('Talebi kapatmak istediğinize emin misiniz?')" style="margin:0">
                <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="sup-btn sup-btn-ghost"><i data-lucide="check-circle" style="width:15px;height:15px"></i> Talebi Kapat</button>
            </form>
            <button type="submit" form="supReplyForm" class="sup-btn"><i data-lucide="send" style="width:15px;height:15px"></i> Gönder</button>
        </div>
    </div>
    <?php else: ?>
    <div class="sup-closed-note"><i data-lucide="lock" style="width:15px;height:15px;vertical-align:-3px"></i> Bu talep kapatıldı.</div>
    <?php endif; ?>
</div>
