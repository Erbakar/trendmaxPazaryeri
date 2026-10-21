<?php
/** Admin: tüm mağazaların API anahtarları — Modernize (Bootstrap). */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tokens = $tokens ?? [];
$stores = $stores ?? [];
$currentStore = (int) ($currentStore ?? 0);
$q = (string) ($q ?? '');
$action = '<a href="' . $e(url('/admin/api-tokens/create')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-plus"></i> Yeni Anahtar</a>';
boss_page_header('API Anahtarları', [['l' => 'Api Yönetimi']], $action);
?>
<?php if (!empty($showSecret)): ?>
<div class="alert alert-warning">
  <div class="fw-bold mb-2"><i class="ti ti-alert-triangle me-1"></i>Bu bilgileri kaydedin — secret bir daha gösterilmeyecek!</div>
  <div class="font-monospace fs-2 d-flex flex-column gap-2">
    <div><span class="text-muted me-2" style="display:inline-block;width:80px;">API Key</span><span class="px-2 py-1 rounded bg-dark text-info"><?= $e($showKey ?? '') ?></span></div>
    <div><span class="text-muted me-2" style="display:inline-block;width:80px;">API Secret</span><span class="px-2 py-1 rounded bg-dark text-info"><?= $e($showSecret) ?></span></div>
  </div>
</div>
<?php endif ?>

<div class="card">
  <form method="get" action="<?= url('/admin/api-tokens') ?>" class="p-3 border-bottom d-flex flex-wrap align-items-center gap-2">
    <select name="store" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto;">
      <option value="0">Tüm Mağazalar</option>
      <?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $currentStore === (int) $s['id'] ? 'selected' : '' ?>><?= $e($s['name'] ?? ('#' . $s['id'])) ?></option><?php endforeach ?>
    </select>
    <input type="text" name="q" value="<?= $e($q) ?>" placeholder="Ad veya anahtar ara…" class="form-control form-control-sm" style="width:240px;">
    <button type="submit" class="btn btn-sm btn-outline-secondary">Filtrele</button>
  </form>
  <?php if (empty($tokens)): ?>
    <div class="text-center text-muted py-5">Kayıt bulunamadı.</div>
  <?php else: ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle text-nowrap mb-0">
        <thead class="fs-2 text-muted text-uppercase">
          <tr><th class="ps-4">Mağaza</th><th>Ad</th><th>API Key</th><th>İzinler</th><th>Durum</th><th class="text-end">Kullanım</th><th class="text-end pe-4">İşlem</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $t): $active = (int) $t['is_active'] === 1; ?>
          <tr>
            <td class="ps-4"><?= $e($t['store_name'] ?? ('#' . $t['store_id'])) ?></td>
            <td class="fw-semibold"><?= $e($t['name']) ?></td>
            <td><code class="fs-1"><?= $e($t['api_key']) ?></code></td>
            <td>
              <?php foreach (($t['permissions_list'] ?? []) as $p): ?><span class="badge bg-primary-subtle text-primary rounded-pill me-1"><?= $e($p) ?></span><?php endforeach ?>
              <?php if (empty($t['permissions_list'])): ?><span class="text-muted">—</span><?php endif ?>
            </td>
            <td><?= boss_status_badge($active ? 1 : 0) ?></td>
            <td class="text-end text-muted"><?= number_format($t['stats']['total_requests'] ?? 0) ?> istek<br><span class="fs-1"><?= number_format($t['stats']['today_requests'] ?? 0) ?> bugün</span></td>
            <td class="text-end pe-4">
              <form method="post" action="<?= url('/admin/api-tokens/' . $t['id'] . '/toggle') ?>" class="d-inline"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-secondary border-0 px-2 <?= $active ? 'text-success' : '' ?>" title="<?= $active ? 'Pasifleştir' : 'Aktifleştir' ?>"><i class="ti ti-<?= $active ? 'toggle-right' : 'toggle-left' ?>"></i></button></form>
              <form method="post" action="<?= url('/admin/api-tokens/' . $t['id'] . '/regenerate') ?>" class="d-inline" onsubmit="return confirm('Secret yenilensin mi? Eski secret geçersiz olacak.')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-secondary border-0 px-2" title="Secret Yenile"><i class="ti ti-refresh"></i></button></form>
              <form method="post" action="<?= url('/admin/api-tokens/' . $t['id'] . '/delete') ?>" class="d-inline" onsubmit="return confirm('Bu anahtar kalıcı olarak silinsin mi?')"><input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>"><button type="submit" class="btn btn-sm btn-outline-danger border-0 px-2" title="Sil"><i class="ti ti-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif ?>
</div>
