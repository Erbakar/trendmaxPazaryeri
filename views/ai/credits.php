<?php
/** Merchant — AI Kredi & Kullanım. Layout: main. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$bal = (int) ($balance ?? 0);
$labels = [
    'ai_content' => 'İçerik Asistanı', 'ai_bulk' => 'Toplu Doldurma', 'ai_vision' => 'Görsel Analizi',
    'ai_optimize' => 'Pazaryeri Optimizasyonu', 'ai_competitor' => 'Rakip Analizi',
    'ai_advisor' => 'Satış Danışmanı', 'ai_trends' => 'Trend Merkezi',
];
$txLabels = ['purchase' => 'Satın Alma', 'grant' => 'Tanımlama', 'usage' => 'Kullanım', 'refund' => 'İade', 'adjust' => 'Düzeltme'];
$packages = $packages ?? [];
?>
<style>
.aic-wrap{display:flex;flex-direction:column;gap:16px}
.aic-hero{background:var(--primary);border-radius:12px;padding:22px 26px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.aic-hero .bal{font-size:34px;font-weight:600;letter-spacing:-.02em;line-height:1}
.aic-hero .lbl{font-size:12px;opacity:.85;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.aic-hero .st{font-size:12px;font-weight:600;background:rgba(255,255,255,.18);border-radius:999px;padding:6px 12px}
.aic-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.aic-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.aic-card h3{font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;padding:12px 16px;border-bottom:1px solid var(--border);margin:0;background:var(--bg-sidebar)}
.aic-tbl{width:100%;border-collapse:collapse;font-size:13px}
.aic-tbl th,.aic-tbl td{padding:8px 16px;text-align:left;border-bottom:1px solid var(--border);color:var(--text-primary)}
.aic-tbl th{font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase}
.aic-tbl td.r,.aic-tbl th.r{text-align:right}
.aic-pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px}
.aic-pos{color:#136c34;background:#e8f7ee}.aic-neg{color:#9b2222;background:#fdeeee}
.aic-muted{color:var(--text-muted)}
.aic-pk{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.aic-pkc{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:18px;text-align:center;display:flex;flex-direction:column;gap:6px}
.aic-pkc .cr{font-size:26px;font-weight:600;color:var(--text-primary);letter-spacing:-.02em}
.aic-pkc .cr small{font-size:13px;font-weight:600;color:var(--text-muted)}
.aic-pkc .pr{font-size:15px;font-weight:700;color:var(--primary)}
.aic-pkc button{margin-top:6px;border:0;background:var(--primary);color:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:700;cursor:pointer}
.aic-pkc button:hover{opacity:.92}
@media(max-width:820px){.aic-grid{grid-template-columns:1fr}.aic-pk{grid-template-columns:1fr}}
</style>

<div class="aic-wrap">
  <?php $aiActive = '/ai/credits'; include BASE_PATH . '/views/ai/_nav.php'; ?>
  <div class="aic-hero">
    <div><div class="lbl">AI Kredi Bakiyesi</div><div class="bal"><?= number_format($bal, 0, ',', '.') ?> <span style="font-size:16px;font-weight:600;opacity:.8">kredi</span></div></div>
    <div class="st"><?= !empty($enabled) ? '● AI Suite aktif' : '○ AI Suite kapalı' ?></div>
  </div>

  <?php if (!empty($packages)): ?>
  <!-- Kredi paketi satın al -->
  <div class="aic-card">
    <h3>Kredi Paketi Satın Al</h3>
    <div class="aic-pk" style="padding:16px">
      <?php foreach ($packages as $pk): ?>
      <div class="aic-pkc">
        <div class="cr"><?= number_format((int) $pk['credits'], 0, ',', '.') ?> <small>kredi</small></div>
        <div class="pr"><?= number_format((float) $pk['price'], 2, ',', '.') ?> ₺</div>
        <form method="post" action="<?= url('/subscription/buy-credits') ?>" onsubmit="return confirm('<?= $e($pk['label']) ?> paketi için fatura oluşturulacak. Devam edilsin mi?');">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <input type="hidden" name="package" value="<?= $e($pk['key']) ?>">
          <button type="submit">Satın Al</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="padding:0 16px 14px;font-size:11.5px;color:var(--text-muted)">Satın alımda fatura oluşturulur; ödeme (havale/kart) onaylanınca krediler hesabınıza eklenir. Fiyatlar yöneticiniz tarafından belirlenir.</div>
  </div>
  <?php endif; ?>

  <div class="aic-grid">
    <!-- İşlem maliyetleri -->
    <div class="aic-card">
      <h3>İşlem Başı Kredi</h3>
      <table class="aic-tbl">
        <tbody>
          <?php foreach ($labels as $k => $l): if (!isset($costs[$k])) continue; ?>
          <tr><td><?= $e($l) ?></td><td class="r fw-bold"><?= (int) $costs[$k] ?> kredi</td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Kredi hareketleri -->
    <div class="aic-card">
      <h3>Kredi Hareketleri</h3>
      <table class="aic-tbl">
        <thead><tr><th>Tür</th><th>İşlem</th><th class="r">Tutar</th><th class="r">Bakiye</th><th>Tarih</th></tr></thead>
        <tbody>
          <?php if (empty($transactions)): ?>
          <tr><td colspan="5" class="aic-muted" style="text-align:center;padding:20px">Henüz hareket yok.</td></tr>
          <?php else: foreach ($transactions as $t): $amt = (int) $t['amount']; ?>
          <tr>
            <td><?= $e($txLabels[$t['type']] ?? $t['type']) ?></td>
            <td class="aic-muted fs-2"><?= $e($labels[$t['action'] ?? ''] ?? ($t['note'] ?? '—')) ?></td>
            <td class="r"><span class="aic-pill <?= $amt >= 0 ? 'aic-pos' : 'aic-neg' ?>"><?= $amt >= 0 ? '+' : '' ?><?= number_format($amt, 0, ',', '.') ?></span></td>
            <td class="r aic-muted"><?= number_format((int) $t['balance_after'], 0, ',', '.') ?></td>
            <td class="aic-muted fs-2"><?= $e($t['created_at']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Kullanım geçmişi -->
  <div class="aic-card">
    <h3>Son AI Kullanımları</h3>
    <table class="aic-tbl">
      <thead><tr><th>Modül</th><th>Model</th><th class="r">Token (g/ç)</th><th class="r">Kredi</th><th>Durum</th><th>Tarih</th></tr></thead>
      <tbody>
        <?php if (empty($usage)): ?>
        <tr><td colspan="6" class="aic-muted" style="text-align:center;padding:20px">Henüz AI kullanımı yok.</td></tr>
        <?php else: foreach ($usage as $u): ?>
        <tr>
          <td><?= $e($labels[$u['module_key']] ?? $u['module_key']) ?></td>
          <td class="aic-muted fs-2"><?= $e($u['provider']) ?>/<?= $e($u['model']) ?></td>
          <td class="r aic-muted"><?= (int) $u['input_tokens'] ?>/<?= (int) $u['output_tokens'] ?></td>
          <td class="r"><?= (int) $u['credits'] ?></td>
          <td><span class="aic-pill <?= ($u['status'] ?? '') === 'success' ? 'aic-pos' : 'aic-neg' ?>"><?= $e($u['status']) ?></span></td>
          <td class="aic-muted fs-2"><?= $e($u['created_at']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
