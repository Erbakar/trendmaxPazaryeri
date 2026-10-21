<?php
/** Admin — Belge türü kataloğu (ekle/düzenle/sil/zorunlu-toggle + kabul edilen dosya tipleri). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$types   = $types ?? [];
$formats = $formats ?? \App\Services\StoreDocumentService::formatOptions();
$defaultAllowed = ['pdf', 'jpg', 'png'];
boss_page_header('Belge Türleri', [['l' => 'Mağazalar', 'u' => '/stores'], ['l' => 'Belgeler', 'u' => '/admin/documents'], ['l' => 'Türler']]);
?>
<div class="row g-3">
  <!-- Liste -->
  <div class="col-12 col-lg-7">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title">Tanımlı Türler</h3></div>
      <?php if (empty($types)): ?>
        <div class="card-body text-center text-muted py-5">Henüz tür yok.</div>
      <?php else: ?>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table no-dt align-middle text-nowrap mb-0">
            <thead class="fs-2 text-muted text-uppercase">
              <tr>
                <th class="ps-4">Belge</th>
                <th>Zorunlu</th>
                <th class="text-center">Sıra</th>
                <th class="text-center">Durum</th>
                <th class="text-end pe-4">İşlem</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($types as $t):
                $active  = (int) ($t['is_active'] ?? 0) === 1;
                $req     = (int) ($t['is_required'] ?? 0) === 1;
                $tid     = (int) $t['id'];
                $allowed = implode(',', \App\Services\StoreDocumentService::normalizeKeys($t['allowed_types'] ?? ''));
                $fmtLbls = \App\Services\StoreDocumentService::formatLabels($t['allowed_types'] ?? '');
              ?>
              <tr>
                <td class="ps-4">
                  <div class="fw-semibold"><?= $e($t['label']) ?></div>
                  <?php if (!empty($t['description'])): ?><div class="fs-1 text-muted text-wrap" style="max-width:420px;"><?= $e($t['description']) ?></div><?php endif; ?>
                  <div class="fs-1 text-muted"><i class="ti ti-paperclip me-1"></i><?= $e(implode(' · ', $fmtLbls)) ?></div>
                </td>
                <td>
                  <form method="post" action="<?= url('/admin/document-types/' . $tid . '/toggle-required') ?>" class="d-inline js-req-form" data-label="<?= $e($t['label']) ?>" data-required="<?= $req ? 1 : 0 ?>">
                    <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                    <button type="submit" class="badge border-0 <?= $req ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-muted' ?>" style="cursor:pointer" title="<?= $req ? 'Opsiyonel yap' : 'Zorunlu yap' ?>">
                      <i class="ti ti-<?= $req ? 'asterisk' : 'minus' ?> me-1"></i><?= $req ? 'Zorunlu' : 'Opsiyonel' ?>
                    </button>
                  </form>
                </td>
                <td class="text-center text-muted"><?= (int) ($t['sort_order'] ?? 0) ?></td>
                <td class="text-center"><?= boss_status_badge($active ? 1 : 0) ?></td>
                <td class="text-end pe-4 text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-secondary border-0 px-2 js-edit-type"
                          title="Düzenle"
                          data-id="<?= $tid ?>"
                          data-label="<?= $e($t['label']) ?>"
                          data-description="<?= $e($t['description'] ?? '') ?>"
                          data-sort="<?= (int) ($t['sort_order'] ?? 0) ?>"
                          data-required="<?= $req ? 1 : 0 ?>"
                          data-active="<?= $active ? 1 : 0 ?>"
                          data-allowed="<?= $e($allowed) ?>"><i class="ti ti-pencil"></i></button>
                  <form method="post" action="<?= url('/admin/document-types/' . $tid . '/toggle') ?>" class="d-inline">
                    <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary border-0 px-2" title="<?= $active ? 'Pasifleştir' : 'Aktifleştir' ?>"><i class="ti ti-<?= $active ? 'toggle-right text-success' : 'toggle-left' ?>"></i></button>
                  </form>
                  <form method="post" action="<?= url('/admin/document-types/' . $tid . '/delete') ?>" class="d-inline js-del-form" data-label="<?= $e($t['label']) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ekle / Düzenle -->
  <div class="col-12 col-lg-5">
    <div class="card mb-0">
      <div class="card-header"><h3 class="card-title" id="type_form_title">Yeni Belge Türü</h3></div>
      <div class="card-body">
        <form method="post" action="<?= url('/admin/document-types') ?>" class="ad-form-grid" id="typeForm">
          <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
          <input type="hidden" name="id" id="type_id" value="">
          <div class="full"><label class="form-label">Belge Adı *</label><input type="text" name="label" id="type_label" required maxlength="150" placeholder="Örn. Banka Dekontu" class="form-control"></div>
          <div class="full"><label class="form-label">Açıklama</label><textarea name="description" id="type_description" rows="2" maxlength="500" class="form-control" placeholder="Mağazaya gösterilecek kısa açıklama"></textarea></div>
          <div class="full">
            <label class="form-label">Kabul edilen dosya tipleri</label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($formats as $fkey => $flabel): ?>
              <label class="form-check d-inline-flex align-items-center gap-1 mb-0" style="cursor:pointer">
                <input type="checkbox" name="allowed[]" value="<?= $e($fkey) ?>" class="form-check-input mt-0 js-allowed"<?= in_array($fkey, $defaultAllowed, true) ? ' checked' : '' ?>>
                <span class="form-check-label"><?= $e($flabel) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <div class="form-text">Müşterinin bu belge için yükleyebileceği dosya biçimleri (en az bir tane).</div>
          </div>
          <div class="c4"><label class="form-label">Sıra</label><input type="number" name="sort_order" id="type_sort" value="0" min="0" class="form-control"></div>
          <div class="c4">
            <label class="form-label d-block">Zorunlu</label>
            <div class="d-flex align-items-center" style="min-height:36px;"><?= boss_switch('is_required', true, ['id' => 'is_required', 'on' => 'Evet', 'off' => 'Hayır']) ?></div>
          </div>
          <div class="c4">
            <label class="form-label d-block">Durum</label>
            <div class="d-flex align-items-center" style="min-height:36px;"><?= boss_switch('is_active', true, ['id' => 'is_active', 'on' => 'Aktif', 'off' => 'Pasif']) ?></div>
          </div>
          <div class="full d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1" id="type_submit_icon"></i><span id="type_submit_label">Tür Ekle</span></button>
            <button type="button" class="btn btn-outline-secondary d-none" id="type_cancel">Vazgeç</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var form    = document.getElementById('typeForm');
  if (!form) { return; }
  var idEl    = document.getElementById('type_id');
  var labelEl = document.getElementById('type_label');
  var descEl  = document.getElementById('type_description');
  var sortEl  = document.getElementById('type_sort');
  var reqEl   = document.getElementById('is_required');
  var actEl   = document.getElementById('is_active');
  var titleEl = document.getElementById('type_form_title');
  var subLbl  = document.getElementById('type_submit_label');
  var subIcon = document.getElementById('type_submit_icon');
  var cancel  = document.getElementById('type_cancel');
  var DEFAULT_ALLOWED = ['pdf', 'jpg', 'png'];

  function setSwitchEl(el, on) {
    if (!el) { return; }
    el.checked = on;
    var field = el.closest('.ozy-switch-field');
    var state = field ? field.querySelector('.ozy-switch-state') : null;
    if (state) {
      state.textContent = on ? (el.dataset.on || '') : (el.dataset.off || '');
      state.classList.toggle('on', on);
    }
  }

  function setAllowed(list) {
    document.querySelectorAll('.js-allowed').forEach(function (cb) {
      cb.checked = list.indexOf(cb.value) !== -1;
    });
  }

  function resetForm() {
    idEl.value = '';
    titleEl.textContent = 'Yeni Belge Türü';
    subLbl.textContent  = 'Tür Ekle';
    if (subIcon) { subIcon.className = 'ti ti-plus me-1'; }
    cancel.classList.add('d-none');
    form.reset();
    setSwitchEl(reqEl, true);
    setSwitchEl(actEl, true);
    setAllowed(DEFAULT_ALLOWED);
  }

  document.querySelectorAll('.js-edit-type').forEach(function (btn) {
    btn.addEventListener('click', function () {
      idEl.value    = btn.dataset.id || '';
      labelEl.value = btn.dataset.label || '';
      descEl.value  = btn.dataset.description || '';
      sortEl.value  = btn.dataset.sort || '0';
      setSwitchEl(reqEl, btn.dataset.required === '1');
      setSwitchEl(actEl, btn.dataset.active === '1');
      setAllowed((btn.dataset.allowed || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean));
      titleEl.textContent = 'Belge Türünü Düzenle';
      subLbl.textContent  = 'Güncelle';
      if (subIcon) { subIcon.className = 'ti ti-device-floppy me-1'; }
      cancel.classList.remove('d-none');
      labelEl.focus();
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  cancel.addEventListener('click', resetForm);

  // En az bir dosya tipi seçili olmalı.
  form.addEventListener('submit', function (e) {
    var any = Array.prototype.some.call(document.querySelectorAll('.js-allowed'), function (cb) { return cb.checked; });
    if (!any) { e.preventDefault(); alert('Lütfen en az bir kabul edilen dosya tipi seçin.'); }
  });

  // Apostrof-güvenli onay (etiket değer olarak okunur, koda gömülmez).
  document.querySelectorAll('.js-del-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm('“' + (f.dataset.label || 'Tür') + '” türü silinsin mi? Bu türle yüklenmiş belgeler türsüz kalır.')) { e.preventDefault(); }
    });
  });
  document.querySelectorAll('.js-req-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var makeReq = f.dataset.required === '0';
      var msg = makeReq
        ? '“' + (f.dataset.label || 'Tür') + '” ZORUNLU yapılacak. Bu belgeyi onaylı yüklememiş doğrulanmış mağazaların doğrulaması düşebilir. Devam edilsin mi?'
        : '“' + (f.dataset.label || 'Tür') + '” OPSİYONEL yapılacak. Mağaza doğrulama durumları yeniden hesaplanacak. Devam edilsin mi?';
      if (!confirm(msg)) { e.preventDefault(); }
    });
  });
})();
</script>
