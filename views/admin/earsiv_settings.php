<?php
/** Admin — Platform e-Arşiv (GİB) ayarları + bağlantı testi. (E-posta ayarlarından ayrıldı; Faturalar menüsünde.) */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$values = $values ?? [];
$v = fn(string $k) => $e($values[$k] ?? '');
$csrf = $e($_csrf ?? '');
$gibTest = (string) ($values['platform_gib_test_mode'] ?? '1') === '1';
boss_page_header('e-Arşiv (GİB) Ayarları', [['l' => 'Faturalar', 'u' => '/admin/invoices'], ['l' => 'e-Arşiv Ayarları']]);
?>
<div class="row g-3">
  <div class="col-12">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title"><i class="ti ti-file-invoice me-1"></i>e-Arşiv (GİB) — Platform</h3></div>
      <div class="card-body">
        <form method="post" action="<?= url('/admin/mail-settings') ?>" class="ad-form-grid">
          <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="section" value="earsiv">
          <div class="full"><label class="form-label">GİB Kullanıcı Adı</label><input type="text" name="s[platform_gib_username]" value="<?= $v('platform_gib_username') ?>" autocomplete="off" class="form-control"></div>
          <div class="full"><label class="form-label">GİB Şifre</label><input type="password" name="s[platform_gib_password]" value="" placeholder="<?= $values['platform_gib_password'] ?? '' ? '•••••• (değiştirmek için doldurun)' : 'şifre' ?>" autocomplete="new-password" class="form-control"></div>
          <div class="c6"><label class="form-label">Fatura Serisi</label><input type="text" name="s[invoice_series]" value="<?= $v('invoice_series') ?>" placeholder="OZY" maxlength="10" class="form-control"></div>
          <div class="c6">
            <label class="form-label d-block">Ortam</label>
            <div class="d-flex align-items-center" style="min-height:36px;"><?= boss_switch('s[platform_gib_test_mode]', $gibTest, ['id' => 'gib_test_mode', 'on' => 'TEST', 'off' => 'CANLI']) ?></div>
          </div>
          <div class="c6"><label class="form-label">KDV Oranı (%)</label><input type="number" name="s[invoice_vat_rate]" value="<?= $v('invoice_vat_rate') ?>" min="0" max="100" class="form-control"></div>
          <div class="c6"><label class="form-label">KDV Modu</label>
            <select name="s[invoice_vat_mode]" class="form-select">
              <option value="included" <?= ($values['invoice_vat_mode'] ?? '')==='included'?'selected':'' ?>>Fiyata dahil</option>
              <option value="excluded" <?= ($values['invoice_vat_mode'] ?? '')==='excluded'?'selected':'' ?>>Fiyata hariç (+KDV)</option>
              <option value="exempt"   <?= ($values['invoice_vat_mode'] ?? '')==='exempt'?'selected':'' ?>>İstisna / Yok</option>
            </select>
          </div>
          <div class="full"><div class="form-text"><b>Fiyata dahil</b> → fatura toplamı tahsil edilen tutara eşittir (önerilir). <b>Hariç</b> seçilirse fatura toplamı tahsilattan büyük olur (tahsilat da KDV'li olmalı).</div></div>
          <div class="full"><button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>e-Arşiv'i Kaydet</button></div>
        </form>
        <hr>
        <form method="post" action="<?= url('/admin/mail-settings/test-earsiv') ?>">
          <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
          <button type="submit" class="btn btn-outline-secondary"><i class="ti ti-plug-connected me-1"></i>GİB Bağlantısını Test Et</button>
        </form>
        <div class="form-text mt-2">Platformun KENDİ GİB e-Arşiv hesabıdır (mağazaların kendi hesaplarından ayrı). Ödeme onaylandığında abonelik faturası bu hesapla <b>otomatik</b> kesilir; elle kesim/iptal için <a href="<?= url('/admin/invoices') ?>">Faturalar</a> ekranını kullanın.</div>
      </div>
    </div>
  </div>
</div>
