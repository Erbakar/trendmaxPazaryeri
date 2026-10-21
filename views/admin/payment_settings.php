<?php
/** Ödeme Ayarları — PayTR + Havale/EFT — Modernize (Bootstrap). Global Setting group='payment'. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = $values ?? [];
$val = fn(string $k) => $e($v[$k] ?? '');
$testMode = (string) ($v['paytr_test_mode'] ?? '1') !== '0';
$havaleOn = (string) ($v['havale_enabled'] ?? '1') !== '0';
boss_page_header('Ödeme Ayarları', [['l' => 'Siparişler', 'u' => '/admin/packages'], ['l' => 'Ödeme Ayarları']]);
?>
<p class="text-muted fs-2 mb-3">PayTR sanal POS bilgileri ve Havale/EFT seçeneği — ödeme adımı buradan yönetilir.</p>

<form method="post" action="<?= url('/admin/payment-settings') ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-credit-card me-1 text-primary"></i>PayTR</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="full"><label class="form-label">Merchant ID</label><input type="text" name="s[paytr_merchant_id]" value="<?= $val('paytr_merchant_id') ?>" autocomplete="off" class="form-control"></div>
        <div><label class="form-label">Merchant Key <span class="text-muted">(boş = değişme)</span></label><input type="password" name="s[paytr_merchant_key]" value="" placeholder="değiştirmemek için boş bırakın" autocomplete="new-password" class="form-control"></div>
        <div><label class="form-label">Merchant Salt <span class="text-muted">(boş = değişme)</span></label><input type="password" name="s[paytr_merchant_salt]" value="" placeholder="değiştirmemek için boş bırakın" autocomplete="new-password" class="form-control"></div>
        <div>
          <label class="form-label">Test Modu</label>
          <select name="s[paytr_test_mode]" class="form-select">
            <option value="1" <?= $testMode ? 'selected' : '' ?>>Evet (test)</option>
            <option value="0" <?= !$testMode ? 'selected' : '' ?>>Hayır (canlı)</option>
          </select>
          <div class="form-text">Test modunda gerçek tahsilat yapılmaz; canlıya almadan önce kapatın.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-cash me-1 text-primary"></i>Havale / EFT</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="full"><?= boss_switch('s[havale_enabled]', $havaleOn, ['id' => 'havaleSw', 'title' => 'Havale/EFT ile ödeme']) ?></div>
        <div class="full">
          <label class="form-label">Havale Bilgileri <span class="text-muted">(IBAN, alıcı adı, açıklama — müşteriye gösterilir)</span></label>
          <textarea name="s[havale_info]" rows="5" placeholder="Örn. Banka: ...&#10;Alıcı: ...&#10;IBAN: TR..&#10;Açıklama: Sipariş numaranızı açıklamaya yazınız." class="form-control"><?= $val('havale_info') ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
  </div>
</form>
