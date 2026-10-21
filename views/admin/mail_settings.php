<?php
/** Admin — E-posta (SMTP) ayarları + test + toplu e-posta. (e-Arşiv ayrı sayfaya taşındı.) */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$values = $values ?? [];
$stores = $stores ?? [];
$v = fn(string $k) => $e($values[$k] ?? '');
$csrf = $e($_csrf ?? '');
$enc = (string) ($values['smtp_encryption'] ?? 'tls');
boss_page_header('E-posta Ayarları', [['l' => 'Ayarlar'], ['l' => 'E-posta Ayarları']]);
?>
<div class="row g-3">
  <!-- ───── SMTP ───── -->
  <div class="col-12 col-lg-6">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title"><i class="ti ti-mail me-1"></i>E-posta (SMTP)</h3></div>
      <div class="card-body">
        <form method="post" action="<?= url('/admin/mail-settings') ?>" class="ad-form-grid">
          <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="section" value="smtp">
          <div class="full"><label class="form-label">Sunucu (host)</label><input type="text" name="s[smtp_host]" value="<?= $v('smtp_host') ?>" placeholder="mail.ornek.com" class="form-control"></div>
          <div class="c6"><label class="form-label">Port</label><input type="number" name="s[smtp_port]" value="<?= $v('smtp_port') ?>" placeholder="587" class="form-control"></div>
          <div class="c6"><label class="form-label">Şifreleme</label>
            <select name="s[smtp_encryption]" class="form-select">
              <option value="tls" <?= $enc==='tls'?'selected':'' ?>>TLS (587)</option>
              <option value="ssl" <?= $enc==='ssl'?'selected':'' ?>>SSL (465)</option>
              <option value="none" <?= $enc==='none'?'selected':'' ?>>Yok</option>
            </select>
          </div>
          <div class="full"><label class="form-label">Kullanıcı</label><input type="text" name="s[smtp_user]" value="<?= $v('smtp_user') ?>" autocomplete="off" class="form-control"></div>
          <div class="full"><label class="form-label">Şifre</label><input type="password" name="s[smtp_pass]" value="" placeholder="<?= $values['smtp_pass'] ?? '' ? '•••••• (değiştirmek için doldurun)' : 'şifre' ?>" autocomplete="new-password" class="form-control"></div>
          <div class="c6"><label class="form-label">Gönderen e-posta</label><input type="email" name="s[smtp_from_email]" value="<?= $v('smtp_from_email') ?>" placeholder="no-reply@ornek.com" class="form-control"></div>
          <div class="c6"><label class="form-label">Gönderen ad</label><input type="text" name="s[smtp_from_name]" value="<?= $v('smtp_from_name') ?>" placeholder="Entegratör Yazılımı" class="form-control"></div>
          <div class="full"><button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>SMTP'yi Kaydet</button></div>
        </form>
        <hr>
        <form method="post" action="<?= url('/admin/mail-settings/test-smtp') ?>" class="d-flex align-items-end gap-2">
          <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
          <div class="flex-grow-1"><label class="form-label">Test maili gönder</label><input type="email" name="to" value="<?= $e($_adminEmail ?? '') ?>" placeholder="ornek@eposta.com" required class="form-control"></div>
          <button type="submit" class="btn btn-outline-secondary"><i class="ti ti-send me-1"></i>Gönder</button>
        </form>
        <div class="form-text mt-2">Kaydettikten sonra test edin. Mail PHPMailer ile gönderilir.</div>
      </div>
    </div>
  </div>

  <!-- ───── Toplu E-posta ───── -->
  <div class="col-12 col-lg-6">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title"><i class="ti ti-send me-1"></i>Toplu E-posta</h3></div>
      <div class="card-body">
        <form method="post" action="<?= url('/admin/mail-settings/bulk') ?>" onsubmit="return confirm('Seçili mağazalara e-posta gönderilsin mi?');">
          <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
          <div class="mb-2 d-flex align-items-center justify-content-between">
            <label class="form-label mb-0">Alıcı mağazalar</label>
            <label class="fs-1 text-muted d-flex align-items-center gap-1" style="cursor:pointer;"><input type="checkbox" id="bulkAll" class="form-check-input mt-0"> Tümünü seç</label>
          </div>
          <?php if ($stores === []): ?>
            <div class="form-text mb-3">Mağaza yok.</div>
          <?php else: ?>
          <div class="border rounded-3 mb-3" style="max-height:200px;overflow:auto;">
            <?php foreach ($stores as $s): $em = trim((string) ($s['owner_email'] ?? '')); ?>
            <label class="d-flex align-items-center gap-2 px-3 py-2 border-bottom mb-0" style="cursor:<?= $em !== '' ? 'pointer' : 'not-allowed' ?>;">
              <input type="checkbox" name="store_ids[]" value="<?= (int) $s['id'] ?>" class="form-check-input mt-0 bulkChk" <?= $em === '' ? 'disabled' : '' ?>>
              <span class="flex-grow-1 min-w-0">
                <span class="fw-medium"><?= $e($s['name'] ?? ('#' . $s['id'])) ?></span>
                <span class="fs-1 text-muted d-block"><?= $em !== '' ? $e($em) : 'sahip e-postası yok' ?></span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div class="mb-2"><label class="form-label">Konu</label><input type="text" name="subject" maxlength="200" required class="form-control" placeholder="Örn. Yeni özellik duyurusu"></div>
          <div class="mb-3"><label class="form-label">Mesaj</label><textarea name="body" rows="5" required class="form-control" placeholder="Mesajınız… (satır başları korunur, HTML kullanılabilir)"></textarea></div>
          <button type="submit" class="btn btn-primary w-100"><i class="ti ti-send me-1"></i>Seçili Mağazalara Gönder</button>
          <div class="form-text mt-2"><i class="ti ti-info-circle me-1"></i>SMTP ayarlı olmalı. E-postalar mağaza sahibinin adresine gönderilir.</div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var all = document.getElementById('bulkAll');
  if (all) {
    all.addEventListener('change', function(){
      document.querySelectorAll('.bulkChk:not(:disabled)').forEach(function(c){ c.checked = all.checked; });
    });
  }
})();
</script>
