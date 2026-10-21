<?php
/** Admin'den direkt mağaza oluşturma — Modernize/Lumina (Bootstrap). Provizyon: StoreProvisioningService.
 *  Bölümler: Mağaza & Yetkili · Paket & Abonelik · Fatura/Adres · Belgeler (opsiyonel, KYC). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$packages = $packages ?? [];
$docTypes = $docTypes ?? [];
$fmtTL = fn($v) => '₺' . number_format((float) $v, ($v == (int) $v) ? 0 : 2, ',', '.');
boss_page_header('Mağaza Oluştur', [['l' => 'Mağazalar', 'u' => '/stores'], ['l' => 'Yeni Mağaza']]);
?>
<form method="post" action="<?= $e(url('/admin/stores')) ?>" autocomplete="off" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <div class="card mb-0 h-100">
        <div class="card-header"><h3 class="card-title">Mağaza & Yetkili Bilgileri</h3></div>
        <div class="card-body">
          <div class="ad-form-grid">
            <div class="c6"><label class="form-label">Firma / Mağaza Adı *</label><input type="text" name="company" required maxlength="200" placeholder="Örn. Moda Dünyası" class="form-control"></div>
            <div class="c6"><label class="form-label">Yetkili Kişi *</label><input type="text" name="contact_name" required maxlength="150" placeholder="Ad Soyad" class="form-control"></div>
            <div class="c6"><label class="form-label">E-posta *</label><input type="email" name="email" required maxlength="200" placeholder="ornek@firma.com" class="form-control"></div>
            <div class="c6"><label class="form-label">Telefon</label><input type="text" name="phone" maxlength="40" placeholder="05xx xxx xx xx" class="form-control"></div>
            <div class="c6">
              <label class="form-label">Şifre</label>
              <input type="text" name="password" minlength="8" maxlength="72" autocomplete="off"
                     placeholder="Boş bırakırsanız e-posta ile belirlenir" class="form-control">
              <small class="form-text">
                Doldurursanız firma bu şifreyle hemen giriş yapar; şifre belirleme
                e-postası gönderilmez. Şifreyi firmaya siz iletirsiniz.
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card mb-0 h-100">
        <div class="card-header"><h3 class="card-title">Paket & Abonelik</h3></div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Paket *</label>
            <select name="package_id" required class="form-select">
              <option value="">— Paket seçin —</option>
              <?php foreach ($packages as $p): $pid = (int) ($p['id'] ?? 0); if ($pid <= 0) { continue; }
                $st = (string) ($p['status'] ?? 'active');
                $suffix = $st !== 'active' ? ' · ' . $e(ucfirst($st)) : ''; ?>
              <option value="<?= $pid ?>"><?= $e($p['name'] ?? ('Paket #' . $pid)) ?> — <?= $e($fmtTL($p['price_monthly'] ?? 0)) ?>/ay<?= $suffix ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Mağazaya bu paketin pazaryeri + modül yetkileri otomatik tanımlanır.</div>
          </div>
          <div class="mb-1">
            <label class="form-label d-block">Faturalama Döngüsü</label>
            <?= boss_switch('billing_cycle', false, ['id' => 'billing_cycle', 'value' => 'yearly', 'on' => 'Yıllık', 'off' => 'Aylık', 'title' => 'Döngü']) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-0 mt-3">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-file-invoice me-1"></i>Fatura / Adres Bilgileri <span class="fw-normal text-muted fs-2">(opsiyonel — e-Arşiv ve sözleşme için)</span></h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="full"><label class="form-label">Yasal Ünvan</label><input type="text" name="legal_name" maxlength="200" placeholder="Örn. Moda Dünyası Tekstil Ltd. Şti." class="form-control"></div>
        <div class="c6"><label class="form-label">VKN / TCKN</label><input type="text" name="tax_id" maxlength="20" placeholder="10 veya 11 haneli" class="form-control"></div>
        <div class="c6"><label class="form-label">Vergi Dairesi</label><input type="text" name="tax_office" maxlength="120" class="form-control"></div>
        <div class="full"><label class="form-label">Adres</label><input type="text" name="billing_address" maxlength="300" placeholder="Cadde / sokak, no, mahalle" class="form-control"></div>
        <div class="c6">
          <label class="form-label">İl</label>
          <select name="province_id" data-target="scIlce" class="form-select js-il">
            <option value="">İl seçin</option>
            <?php foreach (($provinces ?? []) as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= $e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="c6">
          <label class="form-label">İlçe</label>
          <select id="scIlce" name="district_id" data-selected="0" data-placeholder="İlçe seçin" class="form-select js-ilce">
            <option value="">Önce il seçin</option>
          </select>
        </div>
        <div class="c6"><label class="form-label">Posta Kodu</label><input type="text" name="billing_postcode" maxlength="10" placeholder="34000" class="form-control"></div>
      </div>
    </div>
  </div>

  <?php if (!empty($docTypes)): ?>
  <div class="card mb-0 mt-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h3 class="card-title mb-0"><i class="ti ti-files me-1"></i>Belgeler <span class="fw-normal text-muted fs-2">(opsiyonel — sonradan da yüklenebilir)</span></h3>
      <span class="badge bg-secondary-subtle text-muted fw-normal">En fazla 8 MB / dosya</span>
    </div>
    <div class="card-body">
      <div class="ad-form-grid">
        <?php foreach ($docTypes as $t):
          $tid = (int) ($t['id'] ?? 0); if ($tid <= 0) { continue; }
          $accept = \App\Services\StoreDocumentService::acceptAttr($t['allowed_types'] ?? '');
          $labels = implode(' · ', \App\Services\StoreDocumentService::formatLabels($t['allowed_types'] ?? ''));
        ?>
        <div class="c6">
          <label class="form-label"><?= $e($t['label'] ?? ('Belge #' . $tid)) ?></label>
          <input type="file" name="doc_<?= $tid ?>" accept="<?= $e($accept) ?>" class="form-control">
          <div class="form-text"><?php if (!empty($t['description'])): ?><?= $e($t['description']) ?> · <?php endif; ?>Kabul edilen: <?= $e($labels) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="form-text mt-2"><i class="ti ti-info-circle me-1"></i>Belge yüklemek zorunlu değildir — dilerseniz şimdi ekleyin, dilerseniz mağaza oluştuktan sonra detay sayfasından yükleyin. Yüklenen belgeler incelemeye alınır.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="alert alert-info mt-3">
    <i class="ti ti-info-circle"></i>
    <div class="alert-body">
      <div class="alert-title">Ne olacak?</div>
      <div>Mağaza + sahip kullanıcı oluşturulur, paketin <strong>deneme aboneliği</strong> başlatılır ve sahibe <strong>şifre-belirleme bağlantısı</strong> e-posta ile gönderilir. (SMTP kapalıysa bağlantı token'ı ekranda gösterilir.) Yüklenen belgeler incelemeye alınır.</div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= $e(url('/stores')) ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-building-store me-1"></i> Mağazayı Oluştur</button>
  </div>
</form>
<script>
/* İl → İlçe cascade */
(function(){
  var ILCE = <?= json_encode($districtsByProv ?? [], JSON_UNESCAPED_UNICODE) ?>;
  function fill(s){
    var t = document.getElementById(s.getAttribute('data-target')); if(!t) return;
    var cur = t.getAttribute('data-selected') || '', ph = t.getAttribute('data-placeholder') || 'İlçe seçin';
    var l = ILCE[s.value] || [];
    t.innerHTML = '<option value="">' + ph + '</option>';
    l.forEach(function(d){ var o=document.createElement('option'); o.value=d[0]; o.textContent=d[1]; if(String(d[0])===String(cur)) o.selected=true; t.appendChild(o); });
  }
  document.querySelectorAll('select.js-il').forEach(function(s){
    fill(s);
    s.addEventListener('change', function(){ var t=document.getElementById(s.getAttribute('data-target')); if(t) t.setAttribute('data-selected',''); fill(s); });
  });
})();
</script>
