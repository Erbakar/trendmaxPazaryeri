<?php
/** Merchant — AI Trend Merkezi (önceden hesaplı, salt okunur). Layout: main. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$trends = $trends ?? [];
$rising = $trends['rising'] ?? ['items' => [], 'advice' => '', 'summary' => ''];
$falling = $trends['falling'] ?? ['items' => [], 'advice' => ''];
$opp = $trends['opportunity'] ?? ['items' => [], 'advice' => ''];
$summary = $rising['summary'] ?? '';
$hasData = !empty($date) && (!empty($rising['items']) || !empty($falling['items']) || !empty($opp['items']));
?>
<style>
.tr-wrap{max-width:1080px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.tr-head{background:var(--primary);border-radius:12px;padding:18px 22px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.tr-head h2{font-size:18px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
.tr-head .meta{font-size:12px;background:rgba(255,255,255,.18);border-radius:999px;padding:6px 12px;font-weight:600}
.tr-warn{background:#fff7ea;border:1px solid #f6e2bd;color:#92580a;border-radius:10px;padding:10px 14px;font-size:13px}
.tr-summary{background:var(--bg-card);border:1px solid var(--border);border-left:4px solid var(--primary);border-radius:10px;padding:13px 16px;font-size:13.5px;line-height:1.6;color:var(--text-primary)}
.tr-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:860px){.tr-grid{grid-template-columns:1fr}}
.tr-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.tr-card-h{padding:12px 15px;font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;color:#fff}
.tr-card.rising .tr-card-h{background:#16a34a}
.tr-card.falling .tr-card-h{background:#dc2626}
.tr-card.opp .tr-card-h{background:#d97706}
.tr-advice{padding:10px 15px;font-size:12.5px;color:var(--text-secondary);line-height:1.55;border-bottom:1px solid var(--border);background:var(--bg-sidebar)}
.tr-item{padding:9px 15px;border-top:1px solid var(--border);font-size:13px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.tr-item:first-child{border-top:0}
.tr-item .nm{font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tr-item .sub{font-size:11px;color:var(--text-muted)}
.tr-tag{font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;white-space:nowrap;flex:none}
.tr-tag.up{background:#e8f6ee;color:#16a34a}
.tr-tag.down{background:#fde8e8;color:#dc2626}
.tr-tag.over{background:#fef3e2;color:#d97706}
.tr-tag.re{background:#eef2ff;color:#4f46e5}
.tr-empty{padding:18px;text-align:center;color:var(--text-muted);font-size:12.5px}
</style>

<div class="tr-wrap">
  <?php $aiActive = '/ai/trends'; include BASE_PATH . '/views/ai/_nav.php'; ?>
  <div class="tr-head">
    <h2><i data-lucide="trending-up" style="width:20px;height:20px"></i> AI Trend Merkezi</h2>
    <span class="meta"><?= $hasData ? ('Son güncelleme: ' . $e(date('d.m.Y', strtotime((string) $date)))) : 'Henüz hesaplanmadı' ?></span>
  </div>

  <?php if (empty($enabled)): ?>
  <div class="tr-warn">AI Suite şu an kapalı. Yöneticinizle iletişime geçin.</div>
  <?php elseif (empty($available)): ?>
  <div class="tr-warn">Bu modül için yetkiniz yok. Abonelik sayfasından AI Trend Merkezi'ni ekleyebilirsiniz.</div>
  <?php endif; ?>

  <?php if (!$hasData): ?>
  <div class="tr-warn">Trendler her gün otomatik olarak hesaplanır. Yeterli satış verisi biriktiğinde yükselen, düşen ve fırsat ürünleri burada görünür. (Bu işlem kredi harcamaz.)</div>
  <?php else: ?>

  <?php if ($summary !== ''): ?>
  <div class="tr-summary"><?= $e($summary) ?></div>
  <?php endif; ?>

  <div class="tr-grid">
    <!-- Yükselen -->
    <div class="tr-card rising">
      <div class="tr-card-h"><i data-lucide="arrow-up-right" style="width:16px;height:16px"></i> Yükselen Ürünler</div>
      <?php if (!empty($rising['advice'])): ?><div class="tr-advice"><?= $e($rising['advice']) ?></div><?php endif; ?>
      <?php if (empty($rising['items'])): ?><div class="tr-empty">Yükselen ürün yok.</div><?php else: foreach ($rising['items'] as $it): ?>
      <div class="tr-item"><span class="nm" title="<?= $e($it['name']) ?>"><?= $e($it['name']) ?><br><span class="sub"><?= (int) $it['prior'] ?> → <?= (int) $it['recent'] ?> adet</span></span><span class="tr-tag up">▲ +<?= (int) $it['delta'] ?></span></div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Düşen -->
    <div class="tr-card falling">
      <div class="tr-card-h"><i data-lucide="arrow-down-right" style="width:16px;height:16px"></i> Düşen Ürünler</div>
      <?php if (!empty($falling['advice'])): ?><div class="tr-advice"><?= $e($falling['advice']) ?></div><?php endif; ?>
      <?php if (empty($falling['items'])): ?><div class="tr-empty">Düşen ürün yok.</div><?php else: foreach ($falling['items'] as $it): ?>
      <div class="tr-item"><span class="nm" title="<?= $e($it['name']) ?>"><?= $e($it['name']) ?><br><span class="sub"><?= (int) $it['prior'] ?> → <?= (int) $it['recent'] ?> adet</span></span><span class="tr-tag down">▼ -<?= (int) $it['drop'] ?></span></div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Fırsat -->
    <div class="tr-card opp">
      <div class="tr-card-h"><i data-lucide="lightbulb" style="width:16px;height:16px"></i> Fırsat Ürünleri</div>
      <?php if (!empty($opp['advice'])): ?><div class="tr-advice"><?= $e($opp['advice']) ?></div><?php endif; ?>
      <?php if (empty($opp['items'])): ?><div class="tr-empty">Fırsat ürünü yok.</div><?php else: foreach ($opp['items'] as $it): ?>
      <div class="tr-item"><span class="nm" title="<?= $e($it['name']) ?>"><?= $e($it['name']) ?><br><span class="sub"><?= $e($it['detail'] ?? '') ?></span></span><span class="tr-tag <?= ($it['type'] ?? '') === 'restock' ? 're' : 'over' ?>"><?= ($it['type'] ?? '') === 'restock' ? 'Restok' : 'Fazla stok' ?></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div style="font-size:11.5px;color:var(--text-muted);text-align:center">Trendler son 14 günü önceki 14 günle karşılaştırır; her gün otomatik güncellenir ve kredi harcamaz.</div>
  <?php endif; ?>
</div>
