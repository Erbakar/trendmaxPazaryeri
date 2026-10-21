<?php
/** Admin: yeni API anahtarı (mağaza seçimli) — yeni tasarım: .ad-form-grid. */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$stores = $stores ?? [];
$permissions = $permissions ?? [];
boss_page_header('Yeni API Anahtarı', [['l' => 'Api Yönetimi', 'u' => '/admin/api-tokens'], ['l' => 'Yeni']]);
?>
<form method="post" action="<?= url('/admin/api-tokens') ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">

  <div class="card">
    <div class="card-header"><h3 class="card-title">Genel</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div>
          <label for="store_id" class="form-label">Mağaza</label>
          <select name="store_id" id="store_id" required class="form-select">
            <option value="">— Mağaza seçin —</option>
            <?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>"><?= $e($s['name'] ?? ('#' . $s['id'])) ?></option><?php endforeach ?>
          </select>
        </div>
        <div>
          <label for="name" class="form-label">Anahtar Adı</label>
          <input type="text" name="name" id="name" maxlength="200" placeholder="Örn. Entegrasyon anahtarı" required class="form-control">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">İzinler</h3></div>
    <div class="card-body">
      <div class="ad-form-grid cols-auto">
        <?php foreach ($permissions as $key => $lbl): ?>
        <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2 mb-0" style="cursor:pointer;"><input type="checkbox" class="form-check-input mt-0" name="permissions[]" value="<?= $e($key) ?>"><span style="font-size:13px;"><?= $e($lbl) ?></span></label>
        <?php endforeach ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Erişim Kuralları</h3></div>
    <div class="card-body">
      <div class="ad-form-grid">
        <div class="c6"><label for="rate_limit" class="form-label">Dakikalık İstek Limiti</label><input type="number" name="rate_limit" id="rate_limit" value="60" min="1" max="1000" class="form-control"></div>
        <div class="c6"><label for="expires_at" class="form-label">Son Geçerlilik <span class="text-muted">(boş = süresiz)</span></label><input type="datetime-local" name="expires_at" id="expires_at" class="form-control"></div>
        <div class="full">
          <label for="ip_whitelist" class="form-label">IP Beyaz Listesi <span class="text-muted">(her satıra bir IP — boş = tüm IP'ler)</span></label>
          <textarea name="ip_whitelist" id="ip_whitelist" rows="3" placeholder="203.0.113.10&#10;198.51.100.0" class="form-control font-monospace"></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-2">
    <a href="<?= url('/admin/api-tokens') ?>" class="btn btn-outline-secondary">İptal</a>
    <button type="submit" class="btn btn-primary"><i class="ti ti-key me-1"></i>Anahtarı Oluştur</button>
  </div>
</form>
