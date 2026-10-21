<?php
/** AI Kredi Yönetimi — mağaza bakiyeleri + manuel kredi tanımlama. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
boss_page_header('AI Kredi Yönetimi', [['l' => 'AI Suite', 'u' => url('/admin/ai-suite')], ['l' => 'Kredi']]);
?>
<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title"><i class="ti ti-coin me-1 text-primary"></i>Kredi Tanımla</h3></div>
      <div class="card-body">
        <form method="post" action="<?= url('/admin/ai-suite/credits') ?>">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <div class="mb-2"><label class="form-label">Mağaza</label>
            <select name="store_id" class="form-select" required>
              <option value="">— seç —</option>
              <?php foreach (($stores ?? []) as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= $e($s['name']) ?> (#<?= (int) $s['id'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Tutar (+ ekle / − düş)</label>
            <input type="number" name="amount" class="form-control" placeholder="ör: 5000" required>
          </div>
          <div class="mb-3"><label class="form-label">Not <span class="text-muted">(opsiyonel)</span></label>
            <input type="text" name="note" class="form-control" placeholder="ör: Pro paket aylık tahsis">
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="ti ti-plus me-1"></i>İşle</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Mağaza Bakiyeleri</h3></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Mağaza</th><th class="text-end">Bakiye</th><th class="text-end">Alınan</th><th class="text-end">Kullanılan</th><th>Güncelleme</th></tr></thead>
            <tbody>
              <?php if (empty($rows)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">Henüz kredi hareketi yok.</td></tr>
              <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?= $e($r['store_name']) ?> <span class="text-muted">#<?= (int) $r['store_id'] ?></span></td>
                <td class="text-end fw-bold"><?= number_format((int) $r['balance'], 0, ',', '.') ?></td>
                <td class="text-end text-muted"><?= number_format((int) $r['total_purchased'], 0, ',', '.') ?></td>
                <td class="text-end text-muted"><?= number_format((int) $r['total_used'], 0, ',', '.') ?></td>
                <td class="text-muted fs-2"><?= $e($r['updated_at']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
