<?php
/** İletişim — Bildirim Gönder: mağazalara anlık in-app bildirim + son gönderilenler. Lumina. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$stores = $stores ?? [];
$recent = $recent ?? [];
$csrf = $e($_csrf ?? '');
$fmtDT = fn($d) => $d ? date('d.m.Y H:i', strtotime((string) $d)) : '—';
$typeMeta = [
    'system'       => ['Sistem', 'bg-primary-subtle text-primary'],
    'subscription' => ['Abonelik', 'bg-info-subtle text-info'],
    'payment'      => ['Ödeme', 'bg-success-subtle text-success'],
    'support'      => ['Destek', 'bg-warning-subtle text-warning'],
];
boss_page_header('Bildirim Gönder', [['l' => 'İletişim Merkezi', 'u' => '/admin/support'], ['l' => 'Bildirim Gönder']]);
?>
<div class="row g-3 align-items-start">
  <!-- Gönderim formu -->
  <div class="col-12 col-lg-7">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-bell-ringing me-1 text-primary"></i>Anlık Bildirim Gönder</h3></div>
      <div class="card-body">
        <form method="POST" action="<?= url('/admin/broadcast/send') ?>" onsubmit="return confirm('Seçili mağazalara anlık bildirim gönderilsin mi?');">
          <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
          <div class="mb-2 d-flex align-items-center justify-content-between">
            <label class="form-label mb-0">Alıcı mağazalar</label>
            <label class="fs-1 text-muted d-flex align-items-center gap-1" style="cursor:pointer;"><input type="checkbox" id="bcAll" class="form-check-input mt-0"> Tümünü seç</label>
          </div>
          <?php if ($stores === []): ?>
            <div class="form-text mb-3">Mağaza yok.</div>
          <?php else: ?>
          <div class="border rounded-3 mb-3" style="max-height:210px;overflow:auto;">
            <?php foreach ($stores as $s): ?>
            <label class="d-flex align-items-center gap-2 px-3 py-2 border-bottom mb-0" style="cursor:pointer;">
              <input type="checkbox" name="store_ids[]" value="<?= (int) $s['id'] ?>" class="form-check-input mt-0 bcChk">
              <span class="flex-grow-1"><span class="fw-medium"><?= $e($s['name']) ?></span> <span class="fs-1 text-muted">#<?= (int) $s['id'] ?></span></span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="mb-2"><label class="form-label">Başlık *</label><input type="text" name="title" maxlength="200" required class="form-control" placeholder="Örn. Planlı bakım duyurusu"></div>
          <div class="mb-2"><label class="form-label">Mesaj</label><textarea name="body" rows="3" maxlength="500" class="form-control" placeholder="Bildirim metni…"></textarea></div>
          <div class="mb-3"><label class="form-label">Bağlantı <span class="text-muted">(opsiyonel)</span></label><input type="text" name="link" maxlength="255" class="form-control" placeholder="/subscription · /documents · https://…"></div>
          <button type="submit" class="btn btn-primary"><i class="ti ti-send me-1"></i>Bildirimi Gönder</button>
          <div class="form-text mt-2"><i class="ti ti-info-circle me-1"></i>Seçili mağazaların panelindeki bildirim ziline anında düşer.</div>
        </form>
      </div>
    </div>
  </div>

  <!-- Son gönderilenler -->
  <div class="col-12 col-lg-5">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-history me-1 text-muted"></i>Son Gönderilenler</h3></div>
      <?php if (empty($recent)): ?>
        <div class="card-body text-center py-5 text-muted">Henüz bildirim yok.</div>
      <?php else: ?>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table align-middle text-nowrap mb-0 no-dt">
            <thead class="fs-2 text-muted text-uppercase"><tr><th class="ps-4">Başlık</th><th>Mağaza</th><th class="text-end pe-4">Tarih</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $n): [$tl, $tc] = $typeMeta[$n['type']] ?? ['—', 'bg-secondary-subtle text-muted']; ?>
              <tr>
                <td class="ps-4"><span class="badge <?= $tc ?> rounded-pill me-1"><?= $e($tl) ?></span><?= $e($n['title']) ?></td>
                <td class="text-muted"><?= $e($n['store_name'] ?? ('#' . ($n['store_id'] ?? '?'))) ?></td>
                <td class="text-end pe-4 text-muted fs-1"><?= $e($fmtDT($n['created_at'] ?? null)) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  var all = document.getElementById('bcAll');
  if (all) { all.addEventListener('change', function(){ document.querySelectorAll('.bcChk').forEach(function(c){ c.checked = all.checked; }); }); }
})();
</script>
