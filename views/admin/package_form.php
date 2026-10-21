<?php
/** Paket oluştur/düzenle — yeni tasarım: Kompakt + .ad-form-grid (auto-fill, genişliği doldurur). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pkg = $pkg ?? null;
$limits = $limits ?? [];
$allMarketplaces = $allMarketplaces ?? [];
$selectedMp = array_map('intval', $selectedMp ?? []);
$moduleLabels = $moduleLabels ?? [];
$selectedMod = $selectedMod ?? [];
$isEdit = $pkg !== null;
$internal = $isEdit && (int) ($pkg['is_internal'] ?? 0) === 1;
$formAction = $isEdit ? url('/admin/packages/' . (int) $pkg['id']) : url('/admin/packages');
$v = fn(string $k, $d = '') => $e($pkg[$k] ?? $d);
$lv = fn(string $k) => isset($limits[$k]) && $limits[$k] !== null ? $e($limits[$k]) : '';
boss_page_header($isEdit ? 'Paket Düzenle' : 'Yeni Paket', [['l' => 'Siparişler', 'u' => '/admin/packages'], ['l' => 'Paketler', 'u' => '/admin/packages'], ['l' => $isEdit ? 'Düzenle' : 'Yeni']]);
?>
<?php if ($internal): ?>
<div class="alert alert-warning"><i class="ti ti-shield-x me-1"></i><strong>Internal paket (store#1 kalkanı).</strong> Pazaryeri/modül/limit içeriği KORUNUR (boş kalmalı) — yalnız temel alanlar düzenlenir.</div>
<?php endif; ?>

<form method="post" action="<?= $formAction ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title">Genel</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div><label class="form-label">Paket Adı *</label><input type="text" name="name" value="<?= $v('name') ?>" required class="form-control"></div>
        <div><label class="form-label">Slug <span class="text-muted">(boş = otomatik)</span></label><input type="text" name="slug" value="<?= $v('slug') ?>" class="form-control font-monospace"></div>
        <div><label class="form-label">Durum</label>
          <select name="status" class="form-select"><?php foreach (['active' => 'Aktif', 'draft' => 'Taslak', 'archived' => 'Arşiv'] as $sk => $sl): ?><option value="<?= $sk ?>" <?= ($pkg['status'] ?? 'active') === $sk ? 'selected' : '' ?>><?= $e($sl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="full"><label class="form-label">Açıklama</label><input type="text" name="description" value="<?= $v('description') ?>" class="form-control"></div>
        <div class="c3"><label class="form-label">Aylık Fiyat (₺)</label><input type="number" step="0.01" min="0" name="price_monthly" value="<?= $v('price_monthly', '0') ?>" class="form-control"></div>
        <div class="c3"><label class="form-label">Yıllık Fiyat (₺)</label><input type="number" step="0.01" min="0" name="price_yearly" value="<?= $v('price_yearly', '0') ?>" class="form-control"></div>
        <div class="c3"><label class="form-label">Deneme (gün)</label><input type="number" min="0" name="trial_days" value="<?= $v('trial_days', '0') ?>" class="form-control"></div>
        <div class="c3"><label class="form-label">Sıra</label><input type="number" min="0" name="sort_order" value="<?= $v('sort_order', '0') ?>" class="form-control"></div>
        <div class="full"><?= boss_switch('is_active', (bool) ($pkg['is_active'] ?? 1), ['id' => 'act', 'title' => 'Public\'te görünür']) ?></div>
      </div>
    </div>
  </div>

  <?php if (!$internal): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Limitler <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0;">(boş = sınırsız)</span></h3></div>
    <div class="card-body">
      <div class="ad-form-grid cols-auto">
        <div><label class="form-label">Ürün</label><input type="number" min="0" name="max_products" value="<?= $lv('max_products') ?>" placeholder="∞" class="form-control"></div>
        <div><label class="form-label">Sipariş/ay</label><input type="number" min="0" name="max_orders_month" value="<?= $lv('max_orders_month') ?>" placeholder="∞" class="form-control"></div>
        <div><label class="form-label">Kullanıcı</label><input type="number" min="0" name="max_users" value="<?= $lv('max_users') ?>" placeholder="∞" class="form-control"></div>
        <div><label class="form-label">API Token</label><input type="number" min="0" name="max_api_tokens" value="<?= $lv('max_api_tokens') ?>" placeholder="∞" class="form-control"></div>
        <div><label class="form-label">API/dk</label><input type="number" min="0" name="api_rate_per_min" value="<?= $lv('api_rate_per_min') ?>" placeholder="∞" class="form-control"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Dahil Pazaryerleri</h3></div>
    <div class="card-body">
      <div class="ad-form-grid cols-auto">
        <?php foreach ($allMarketplaces as $m): $mid = (int) $m['id']; $slug = $e($m['slug'] ?? ''); ?>
        <label class="d-flex align-items-center justify-content-center position-relative border rounded-3 px-3 py-2 mb-0" style="cursor:pointer; min-height:60px;">
          <input type="checkbox" class="form-check-input" style="position:absolute; left:8px; top:8px; z-index:10; width:18px; height:18px; cursor:pointer;" name="marketplaces[]" value="<?= $mid ?>" <?= in_array($mid, $selectedMp, true) ? 'checked' : '' ?>>
          <img src="/assets/img/marketplaces/<?= $slug ?>.png" alt="<?= $slug ?>" style="height:32px; max-width:90%; object-fit:contain; pointer-events:none;" title="<?= $e($m['display_name'] ?? '') ?>" onerror="this.style.display='none';">
        </label>
        <?php endforeach; ?>
        <?php if (empty($allMarketplaces)): ?><div class="text-muted full" style="font-size:13px;">Pazaryeri tanımı yok.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Dahil Modüller</h3></div>
    <div class="card-body">
      <div class="ad-form-grid cols-auto">
        <?php foreach ($moduleLabels as $key => $label): ?>
        <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2 mb-0" style="cursor:pointer;"><input type="checkbox" class="form-check-input mt-0" name="modules[]" value="<?= $e($key) ?>" <?= in_array($key, $selectedMod, true) ? 'checked' : '' ?>><span style="font-size:13px;"><?= $e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/packages') ?>" class="btn btn-outline-secondary">Vazgeç</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i><?= $isEdit ? 'Kaydet' : 'Oluştur' ?></button>
  </div>
</form>
