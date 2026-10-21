<?php
/** Admin — Mağaza yönetim/detay (sekmeli): Genel·Abonelik·Faturalar·Siparişler·Belgeler·Kullanıcılar. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$store      = $store ?? [];
$users      = $users ?? [];
$sub        = $sub ?? false;
$subHistory = $subHistory ?? [];
$invoices   = $invoices ?? [];
$orders     = $orders ?? [];
$documents  = $documents ?? [];
$packages   = $packages ?? [];
$sid        = (int) ($store['id'] ?? 0);
$csrf       = $e($_csrf ?? '');

$months = [1=>'Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$fd  = function (?string $dt) use ($months): string { if (!$dt) { return '—'; } $ts = strtotime($dt); return $ts ? date('d', $ts).' '.($months[(int)date('n',$ts)]??'').' '.date('Y',$ts) : '—'; };
$fdt = function (?string $dt) use ($months): string { if (!$dt) { return '—'; } $ts = strtotime($dt); return $ts ? date('d', $ts).' '.($months[(int)date('n',$ts)]??'').' '.date('Y H:i',$ts) : '—'; };
$money = function ($amount, ?string $cur = 'TRY'): string { $n = number_format((float)$amount, 2, ',', '.'); return ($cur === 'TRY' || $cur === null || $cur === '') ? '₺'.$n : $n.' '.htmlspecialchars((string)$cur, ENT_QUOTES, 'UTF-8'); };
$badge = fn(string $txt, string $cls) => '<span class="badge '.$cls.' fw-medium">'.htmlspecialchars($txt, ENT_QUOTES, 'UTF-8').'</span>';

$storeStatusMeta = ['active'=>['Aktif','bg-success-subtle text-success'],'suspended'=>['Askıda','bg-warning-subtle text-warning'],'archived'=>['Arşiv','bg-secondary-subtle text-muted']];
$subStatusMeta   = ['trial'=>['Deneme','bg-info-subtle text-info'],'active'=>['Aktif','bg-success-subtle text-success'],'passive'=>['Pasif','bg-secondary-subtle text-muted'],'suspended'=>['Askıda','bg-warning-subtle text-warning'],'expired'=>['Süresi doldu','bg-danger-subtle text-danger'],'cancelled'=>['İptal','bg-danger-subtle text-danger']];
$invStatusMeta   = ['draft'=>['Taslak','bg-secondary-subtle text-muted'],'sent'=>['Gönderildi','bg-info-subtle text-info'],'paid'=>['Ödendi','bg-success-subtle text-success'],'overdue'=>['Gecikmiş','bg-danger-subtle text-danger'],'void'=>['İptal','bg-secondary-subtle text-muted']];
$docStatusMeta   = ['pending'=>['Bekliyor','bg-warning-subtle text-warning'],'approved'=>['Onaylı','bg-success-subtle text-success'],'rejected'=>['Reddedildi','bg-danger-subtle text-danger']];
$earsivMeta      = ['issued'=>['Kesildi','bg-success-subtle text-success'],'cancelled'=>['İptal','bg-secondary-subtle text-muted'],'failed'=>['Başarısız','bg-danger-subtle text-danger'],'missing_info'=>['Bilgi eksik','bg-warning-subtle text-warning'],'no_creds'=>['GİB ayarsız','bg-warning-subtle text-warning']];

$st       = (string) ($store['status'] ?? 'active');
$verified = !empty($store['verified_at']);
$ownerEmail = $users[0]['email'] ?? null;

// Başlık aksiyonları
$actions  = '<div class="d-flex gap-2 flex-wrap">';
$actions .= '<a href="'.$e(url('/stores/'.$sid.'/entitlements')).'" class="btn btn-sm btn-outline-secondary"><i class="ti ti-adjustments-horizontal me-1"></i>Yetkiler</a>';
$actions .= '<form method="post" action="'.$e(url('/stores/'.$sid.'/verify')).'" class="d-inline"><input type="hidden" name="_csrf_token" value="'.$csrf.'"><input type="hidden" name="action" value="'.($verified?'unverify':'verify').'"><button class="btn btn-sm '.($verified?'btn-outline-secondary':'btn-outline-success').'"><i class="ti ti-'.($verified?'shield-off':'shield-check').' me-1"></i>'.($verified?'Doğrulamayı Kaldır':'Doğrula').'</button></form>';
if ($st === 'active') {
    $actions .= '<form method="post" action="'.$e(url('/stores/'.$sid.'/status')).'" class="d-inline" onsubmit="return confirm(\'Mağaza askıya alınsın mı?\');"><input type="hidden" name="_csrf_token" value="'.$csrf.'"><input type="hidden" name="status" value="suspended"><button class="btn btn-sm btn-outline-warning"><i class="ti ti-player-pause me-1"></i>Askıya Al</button></form>';
} else {
    $actions .= '<form method="post" action="'.$e(url('/stores/'.$sid.'/status')).'" class="d-inline"><input type="hidden" name="_csrf_token" value="'.$csrf.'"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-success"><i class="ti ti-player-play me-1"></i>Aktifleştir</button></form>';
}
$actions .= '<div class="dropdown d-inline"><button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-download me-1"></i>Dışa Aktar</button><div class="dropdown-menu dropdown-menu-end"><a class="dropdown-item" href="'.$e(url('/stores/'.$sid.'/data-export')).'"><i class="ti ti-database-export me-2"></i>Mağaza Verisi (.sql)</a><a class="dropdown-item" href="'.$e(url('/stores/'.$sid.'/audit-log')).'"><i class="ti ti-file-text me-2"></i>İşlem Kaydı (.csv)</a></div></div>';
$actions .= '</div>';

boss_page_header((string) ($store['name'] ?? 'Mağaza'), [['l'=>'Mağazalar','u'=>'/stores'], ['l'=>(string)($store['name'] ?? '')]], $actions);
[$stLbl, $stCls] = $storeStatusMeta[$st] ?? ['—','bg-secondary-subtle text-muted'];
?>

<!-- Özet şerit -->
<div class="d-flex flex-wrap align-items-center gap-3 mb-3 fs-2 text-muted">
  <span><i class="ti ti-hash"></i> #<?= $sid ?></span>
  <span class="font-monospace"><?= $e($store['slug'] ?? '') ?></span>
  <?= $badge($stLbl, $stCls) ?>
  <?= $verified ? $badge('Doğrulandı','bg-success-subtle text-success') : $badge('Doğrulanmadı','bg-warning-subtle text-warning') ?>
  <?php if ($sub !== false): [$sl,$sc] = $subStatusMeta[$sub['status']] ?? ['—','bg-secondary-subtle text-muted']; ?>
    <span><i class="ti ti-package"></i> <?= $e($sub['package_name'] ?? '—') ?> <?= $badge($sl,$sc) ?></span>
  <?php endif; ?>
  <?php if ($ownerEmail): ?><span><i class="ti ti-mail"></i> <?= $e($ownerEmail) ?></span><?php endif; ?>
</div>

<div style="overflow-x:auto;">
  <ul class="nav nav-pills" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-genel" type="button"><i class="ti ti-info-circle"></i> Genel</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-abonelik" type="button"><i class="ti ti-refresh"></i> Abonelik</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-faturalar" type="button"><i class="ti ti-file-invoice"></i> Faturalar <span class="badge bg-secondary-subtle text-muted ms-1"><?= count($invoices) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-siparisler" type="button"><i class="ti ti-shopping-cart"></i> Siparişler <span class="badge bg-secondary-subtle text-muted ms-1"><?= (int)($ordersCount ?? 0) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-belgeler" type="button"><i class="ti ti-files"></i> Belgeler <span class="badge bg-secondary-subtle text-muted ms-1"><?= count($documents) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-kullanicilar" type="button"><i class="ti ti-users"></i> Kullanıcılar <span class="badge bg-secondary-subtle text-muted ms-1"><?= count($users) ?></span></button></li>
  </ul>
</div>

<div class="tab-content">
  <!-- GENEL -->
  <div class="tab-pane active" id="tab-genel">
    <div class="row g-3">
      <div class="col-12 col-lg-7">
        <div class="card mb-0">
          <div class="card-header"><h3 class="card-title">Mağaza Bilgileri</h3></div>
          <div class="card-body">
            <form method="post" action="<?= url('/stores/'.$sid) ?>" class="ad-form-grid">
              <input type="hidden" name="_csrf_token" value="<?= $csrf ?>">
              <div class="full"><label class="form-label">Mağaza Adı</label><input type="text" name="name" value="<?= $e($store['name'] ?? '') ?>" maxlength="200" required class="form-control"></div>
              <div class="c6"><label class="form-label">Durum</label>
                <select name="status" class="form-select">
                  <option value="active" <?= $st==='active'?'selected':'' ?>>Aktif</option>
                  <option value="suspended" <?= $st==='suspended'?'selected':'' ?>>Askıda</option>
                  <option value="archived" <?= $st==='archived'?'selected':'' ?>>Arşiv</option>
                </select>
              </div>
              <div class="c6"><label class="form-label">Plan etiketi <span class="text-muted">(ops.)</span></label><input type="text" name="plan" value="<?= $e($store['plan'] ?? '') ?>" maxlength="50" list="planlist" class="form-control">
                <datalist id="planlist"><?php foreach ($packages as $p): ?><option value="<?= $e($p['slug'] ?? '') ?>"></option><?php endforeach; ?></datalist>
              </div>
              <div class="full"><hr class="my-1"><div class="fw-semibold fs-2 text-muted"><i class="ti ti-file-invoice me-1"></i>Fatura Bilgileri <span class="fw-normal">(e-Arşiv faturası için)</span></div></div>
              <div class="full"><label class="form-label">Yasal Ünvan</label><input type="text" name="legal_name" value="<?= $e($store['legal_name'] ?? '') ?>" maxlength="200" placeholder="Örn. Demo Moda Tekstil Ltd. Şti." class="form-control"></div>
              <div class="c6"><label class="form-label">VKN / TCKN</label><input type="text" name="tax_id" value="<?= $e($store['tax_id'] ?? '') ?>" maxlength="20" class="form-control"></div>
              <div class="c6"><label class="form-label">Vergi Dairesi</label><input type="text" name="tax_office" value="<?= $e($store['tax_office'] ?? '') ?>" maxlength="120" class="form-control"></div>
              <div class="full"><label class="form-label">Adres</label><input type="text" name="billing_address" value="<?= $e($store['billing_address'] ?? '') ?>" maxlength="300" class="form-control"></div>
              <?php
                $provinces = $provinces ?? []; $districtsByProv = $districtsByProv ?? [];
                $selProv = (int) ($store['province_id'] ?? 0);
                $selDist = (int) ($store['district_id'] ?? 0);
                if ($selProv === 0 && trim((string) ($store['billing_city'] ?? '')) !== '') {
                    foreach ($provinces as $p) { if (mb_strtolower((string) $p['name'], 'UTF-8') === mb_strtolower((string) $store['billing_city'], 'UTF-8')) { $selProv = (int) $p['id']; break; } }
                }
                if ($selDist === 0 && $selProv > 0 && trim((string) ($store['billing_district'] ?? '')) !== '') {
                    foreach (($districtsByProv[$selProv] ?? []) as $d) { if (mb_strtolower((string) $d[1], 'UTF-8') === mb_strtolower((string) $store['billing_district'], 'UTF-8')) { $selDist = (int) $d[0]; break; } }
                }
              ?>
              <div class="c6">
                <label class="form-label">İl</label>
                <select name="province_id" data-target="sdIlce" class="form-select js-il">
                  <option value="">İl seçin</option>
                  <?php foreach ($provinces as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"<?= $selProv === (int) $p['id'] ? ' selected' : '' ?>><?= $e($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="c6">
                <label class="form-label">İlçe</label>
                <select id="sdIlce" name="district_id" data-selected="<?= $selDist ?>" data-placeholder="İlçe seçin" class="form-select js-ilce">
                  <option value="">Önce il seçin</option>
                </select>
              </div>
              <div class="c6"><label class="form-label">Posta Kodu</label><input type="text" name="billing_postcode" value="<?= $e($store['billing_postcode'] ?? '') ?>" maxlength="10" placeholder="34000" class="form-control"></div>
              <div class="full"><button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button></div>
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
              document.querySelectorAll('#tab-genel select.js-il').forEach(function(s){
                fill(s);
                s.addEventListener('change', function(){ var t=document.getElementById(s.getAttribute('data-target')); if(t) t.setAttribute('data-selected',''); fill(s); });
              });
            })();
            </script>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-5">
        <div class="card mb-0">
          <div class="card-header"><h3 class="card-title">Künye</h3></div>
          <div class="card-body">
            <dl class="row mb-0 fs-2">
              <dt class="col-5 text-muted fw-normal">Slug</dt><dd class="col-7 font-monospace mb-2"><?= $e($store['slug'] ?? '') ?></dd>
              <dt class="col-5 text-muted fw-normal">Durum</dt><dd class="col-7 mb-2"><?= $badge($stLbl,$stCls) ?></dd>
              <dt class="col-5 text-muted fw-normal">Doğrulama</dt><dd class="col-7 mb-2"><?= $verified ? $fdt($store['verified_at']) : '<span class="text-warning">Bekliyor</span>' ?></dd>
              <dt class="col-5 text-muted fw-normal">Oluşturma</dt><dd class="col-7 mb-2"><?= $fd($store['created_at'] ?? null) ?></dd>
              <dt class="col-5 text-muted fw-normal">Pazaryeri yetkisi</dt><dd class="col-7 mb-2"><?= (int)($mpCount ?? 0) ?></dd>
              <dt class="col-5 text-muted fw-normal">Modül yetkisi</dt><dd class="col-7 mb-2"><?= (int)($modCount ?? 0) ?></dd>
              <dt class="col-5 text-muted fw-normal">Kullanıcı</dt><dd class="col-7 mb-0"><?= count($users) ?></dd>
            </dl>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ABONELİK -->
  <div class="tab-pane" id="tab-abonelik">
    <div class="card mb-3">
      <div class="card-header"><h3 class="card-title">Güncel Abonelik</h3></div>
      <div class="card-body">
        <?php if ($sub === false): ?>
          <div class="text-muted">Bu mağazaya ait abonelik kaydı yok.</div>
        <?php else: [$sl,$sc] = $subStatusMeta[$sub['status']] ?? ['—','bg-secondary-subtle text-muted']; ?>
          <dl class="row mb-0 fs-2">
            <dt class="col-4 col-md-3 text-muted fw-normal">Paket</dt><dd class="col-8 col-md-9 mb-2 fw-semibold"><?= $e($sub['package_name'] ?? '—') ?></dd>
            <dt class="col-4 col-md-3 text-muted fw-normal">Durum</dt><dd class="col-8 col-md-9 mb-2"><?= $badge($sl,$sc) ?></dd>
            <dt class="col-4 col-md-3 text-muted fw-normal">Döngü</dt><dd class="col-8 col-md-9 mb-2"><?= $sub['billing_cycle']==='yearly'?'Yıllık':'Aylık' ?></dd>
            <dt class="col-4 col-md-3 text-muted fw-normal">Deneme bitişi</dt><dd class="col-8 col-md-9 mb-2"><?= $fdt($sub['trial_ends_at'] ?? null) ?></dd>
            <dt class="col-4 col-md-3 text-muted fw-normal">Dönem</dt><dd class="col-8 col-md-9 mb-0"><?= $fd($sub['current_period_start'] ?? null) ?> – <?= $fd($sub['current_period_end'] ?? null) ?></dd>
          </dl>
        <?php endif; ?>
      </div>
    </div>
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title">Abonelik Geçmişi</h3></div>
      <?php if (empty($subHistory)): ?>
        <div class="card-body text-muted">Kayıt yok.</div>
      <?php else: ?>
      <div class="card-body p-0"><div class="table-responsive"><table class="table no-dt align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase"><tr><th class="ps-4">Tarih</th><th>İşlem</th><th>Durum</th><th>Paket</th><th>Sebep</th></tr></thead>
        <tbody>
          <?php foreach ($subHistory as $h): ?>
          <tr>
            <td class="ps-4 text-muted"><?= $fdt($h['created_at'] ?? null) ?></td>
            <td><?= $e($h['action'] ?? '') ?></td>
            <td class="text-muted"><?= $e(($h['old_status'] ?? '') !== '' ? $h['old_status'].' → ' : '') ?><?= $e($h['new_status'] ?? '') ?></td>
            <td class="text-muted"><?= $e($h['new_pkg'] ?? ($h['old_pkg'] ?? '—')) ?></td>
            <td class="text-muted"><?= $e($h['reason'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- FATURALAR -->
  <div class="tab-pane" id="tab-faturalar">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title">Abonelik Faturaları</h3></div>
      <?php if (empty($invoices)): ?>
        <div class="card-body text-muted">Fatura yok.</div>
      <?php else: ?>
      <div class="card-body p-0"><div class="table-responsive"><table class="table no-dt align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase"><tr><th class="ps-4">No</th><th>Tür</th><th>Dönem</th><th class="text-end">Tutar</th><th>Durum</th><th>Vade</th><th>Ödendi</th><th class="text-end pe-4">e-Arşiv</th></tr></thead>
        <tbody>
          <?php foreach ($invoices as $inv): [$il,$ic] = $invStatusMeta[$inv['status']] ?? ['—','bg-secondary-subtle text-muted']; ?>
          <tr>
            <td class="ps-4 font-monospace"><?= $e($inv['number'] ?? '') ?></td>
            <td class="text-muted"><?= $e($inv['kind'] ?? '') ?></td>
            <td class="text-muted"><?= $fd($inv['period_start'] ?? null) ?> – <?= $fd($inv['period_end'] ?? null) ?></td>
            <td class="text-end fw-semibold"><?= $money($inv['amount'] ?? 0, $inv['currency'] ?? 'TRY') ?></td>
            <td><?= $badge($il,$ic) ?></td>
            <td class="text-muted"><?= $fd($inv['due_at'] ?? null) ?></td>
            <td class="text-muted"><?= $fd($inv['paid_at'] ?? null) ?></td>
            <td class="text-end pe-4 text-nowrap">
              <?php $es = (string) ($inv['earsiv_status'] ?? ''); if ($es === 'issued'): ?>
                <?= $badge('Kesildi','bg-success-subtle text-success') ?>
                <form method="post" action="<?= url('/stores/'.$sid.'/invoices/'.(int)$inv['id'].'/cancel-earsiv') ?>" class="d-inline" onsubmit="return confirm('e-Arşiv faturası GİB üzerinden iptal edilsin mi?');"><input type="hidden" name="_csrf_token" value="<?= $csrf ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="İptal"><i class="ti ti-file-x"></i></button></form>
              <?php else: ?>
                <?php if ($es !== ''): [$el,$ec] = $earsivMeta[$es] ?? ['—','bg-secondary-subtle text-muted']; echo $badge($el,$ec); endif; ?>
                <form method="post" action="<?= url('/stores/'.$sid.'/invoices/'.(int)$inv['id'].'/issue-earsiv') ?>" class="d-inline"><input type="hidden" name="_csrf_token" value="<?= $csrf ?>"><button type="submit" class="btn btn-sm btn-outline-primary border-0 px-2" title="e-Arşiv Kes"><i class="ti ti-file-invoice"></i> Kes</button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- SİPARİŞLER -->
  <div class="tab-pane" id="tab-siparisler">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title">Siparişler <span class="text-muted fs-2">(son <?= count($orders) ?> / toplam <?= (int)($ordersCount ?? 0) ?>)</span></h3></div>
      <?php if (empty($orders)): ?>
        <div class="card-body text-muted">Sipariş yok.</div>
      <?php else: ?>
      <div class="card-body p-0"><div class="table-responsive"><table class="table no-dt align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase"><tr><th class="ps-4">Sipariş No</th><th>Müşteri</th><th>Durum</th><th class="text-end">Tutar</th><th>Tarih</th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td class="ps-4 font-monospace"><?= $e($o['marketplace_order_id'] ?? ('#'.$o['id'])) ?></td>
            <td><?= $e($o['customer_name'] ?? '—') ?></td>
            <td><?= $badge((string)($o['status'] ?? '—'),'bg-secondary-subtle text-muted') ?></td>
            <td class="text-end fw-semibold"><?= $money($o['total_amount'] ?? 0, $o['currency'] ?? 'TRY') ?></td>
            <td class="text-muted"><?= $fdt($o['ordered_at'] ?? ($o['created_at'] ?? null)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- BELGELER -->
  <div class="tab-pane" id="tab-belgeler">
    <div class="card mb-0">
      <div class="card-header d-flex align-items-center justify-content-between"><h3 class="card-title mb-0">Belgeler</h3><a href="<?= url('/admin/documents') ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-external-link me-1"></i>İnceleme kuyruğu</a></div>
      <?php if (empty($documents)): ?>
        <div class="card-body text-muted">Yüklenmiş belge yok.</div>
      <?php else: ?>
      <div class="card-body p-0"><div class="table-responsive"><table class="table no-dt align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase"><tr><th class="ps-4">Tür</th><th>Dosya</th><th>Durum</th><th>Yüklenme</th><th class="text-end pe-4">İşlem</th></tr></thead>
        <tbody>
          <?php foreach ($documents as $d): [$dl,$dc] = $docStatusMeta[$d['status']] ?? ['—','bg-secondary-subtle text-muted']; ?>
          <tr>
            <td class="ps-4"><?= $e($d['type_label'] ?? '—') ?></td>
            <td class="text-muted"><?= $e($d['original_name'] ?? '') ?></td>
            <td><?= $badge($dl,$dc) ?></td>
            <td class="text-muted"><?= $fdt($d['created_at'] ?? null) ?></td>
            <td class="text-end pe-4"><a href="<?= url('/admin/documents/'.(int)$d['id'].'/download') ?>" class="btn btn-sm btn-outline-secondary border-0 px-2" title="İndir"><i class="ti ti-download"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- KULLANICILAR -->
  <div class="tab-pane" id="tab-kullanicilar">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title">Kullanıcılar</h3></div>
      <?php if (empty($users)): ?>
        <div class="card-body text-muted">Kullanıcı yok.</div>
      <?php else: ?>
      <div class="card-body p-0"><div class="table-responsive"><table class="table no-dt align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase"><tr><th class="ps-4">Ad</th><th>E-posta</th><th>Rol</th><th>Durum</th><th>Son giriş</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td class="ps-4 fw-semibold"><?= $e($u['name'] ?? '') ?></td>
            <td class="text-muted"><?= $e($u['email'] ?? '') ?></td>
            <td><?= $badge($u['role']==='admin'?'Yönetici':'Personel', $u['role']==='admin'?'bg-primary-subtle text-primary':'bg-secondary-subtle text-muted') ?></td>
            <td><?= (int)($u['is_active'] ?? 0)===1 ? $badge('Aktif','bg-success-subtle text-success') : $badge('Pasif','bg-danger-subtle text-danger') ?></td>
            <td class="text-muted"><?= $fdt($u['last_login_at'] ?? null) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div></div>
      <?php endif; ?>
    </div>
  </div>
</div>
