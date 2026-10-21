<?php
/** Mağaza listesi (Super Admin) — Lumina · 2026 referans tablo: satır-içi aksiyon + hızlı-bak modal.
 * stores (enriched): id,name,slug,status,verified_at,plan,created_at,package_name,sub_status,owner_email,user_count. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$stores = $stores ?? [];
$csrf   = $e($_csrf ?? '');
$total  = count($stores);
$activeN = count(array_filter($stores, fn($s) => ($s['status'] ?? '') === 'active'));
$suspN   = count(array_filter($stores, fn($s) => ($s['status'] ?? '') === 'suspended'));

$months = [1=>'Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$fd  = function (?string $dt) use ($months): string { if (!$dt) { return '—'; } $ts = strtotime($dt); return $ts ? date('d', $ts).' '.($months[(int)date('n',$ts)]??'').' '.date('Y',$ts) : '—'; };
$badge = fn(string $t, string $c) => '<span class="badge '.$c.' fw-medium">'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'</span>';
$stMeta = ['active'=>['Aktif','bg-success-subtle text-success'],'suspended'=>['Askıda','bg-warning-subtle text-warning'],'archived'=>['Arşiv','bg-secondary-subtle text-muted']];

$action = '<a href="'.$e(url('/admin/stores/create')).'" class="btn btn-primary btn-sm"><i class="ti ti-plus me-1"></i>Mağaza Ekle</a>';
boss_page_header('Mağazalar', [['l'=>'Mağazalar']], $action);
?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-3 fs-2 text-muted">
  <span><i class="ti ti-building-store me-1"></i><b class="text-body"><?= $total ?></b> mağaza</span>
  <span><span class="d-inline-block rounded-circle bg-success" style="width:8px;height:8px;"></span> <b class="text-body"><?= $activeN ?></b> aktif</span>
  <?php if ($suspN): ?><span><span class="d-inline-block rounded-circle bg-warning" style="width:8px;height:8px;"></span> <b class="text-body"><?= $suspN ?></b> askıda</span><?php endif; ?>
</div>

<div class="card mb-0">
  <div class="card-header"><h3 class="card-title">Tüm Mağazalar</h3></div>
  <?php if (empty($stores)): ?>
    <div class="card-body text-center text-muted py-5">Henüz mağaza yok.</div>
  <?php else: ?>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Mağaza</th><th>Paket</th><th>Durum</th><th>Doğrulama</th><th>Oluşturma</th><th class="text-end pe-4">İşlem</th></tr>
      </thead>
      <tbody>
        <?php foreach ($stores as $s):
          $sid = (int) $s['id'];
          $st  = (string) ($s['status'] ?? 'active');
          [$stLbl, $stCls] = $stMeta[$st] ?? ['—','bg-secondary-subtle text-muted'];
          $verified = !empty($s['verified_at']);
          $name = (string) ($s['name'] ?? '');
          $init = mb_strtoupper(mb_substr(trim($name) !== '' ? $name : 'M', 0, 1, 'UTF-8'), 'UTF-8');
          $pkg  = (string) ($s['package_name'] ?? ($s['plan'] ?? ''));
        ?>
        <tr>
          <td class="ps-4">
            <div class="d-flex align-items-center gap-2">
              <span class="d-inline-grid flex-shrink-0" style="place-items:center;width:34px;height:34px;border-radius:9px;background:var(--ad-surface-3);color:var(--ad-accent);font-weight:700;font-size:13px;"><?= $e($init) ?></span>
              <div class="min-w-0">
                <div class="fw-semibold"><?= $e($name) ?></div>
                <div class="fs-1 text-muted font-monospace"><?= $e($s['slug'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td><?= $pkg !== '' ? $badge($pkg, 'bg-primary-subtle text-primary') : '<span class="text-muted">—</span>' ?></td>
          <td><?= $badge($stLbl, $stCls) ?></td>
          <td><?= $verified ? $badge('Doğrulandı','bg-success-subtle text-success') : $badge('Bekliyor','bg-warning-subtle text-warning') ?></td>
          <td class="text-muted" data-order="<?= (int) strtotime((string) ($s['created_at'] ?? '')) ?>"><?= $e($fd($s['created_at'] ?? null)) ?></td>
          <td class="text-end pe-4 text-nowrap">
            <div class="ad-row-actions">
            <button type="button" class="btn btn-outline-secondary border-0" title="Hızlı Bak"
                    data-bs-toggle="modal" data-bs-target="#storeQuick"
                    data-id="<?= $sid ?>" data-name="<?= $e($name) ?>" data-slug="<?= $e($s['slug'] ?? '') ?>"
                    data-status="<?= $e($stLbl) ?>" data-package="<?= $e($pkg ?: '—') ?>"
                    data-verified="<?= $verified ? $e($fd($s['verified_at'])) : 'Bekliyor' ?>"
                    data-created="<?= $e($fd($s['created_at'] ?? null)) ?>"
                    data-owner="<?= $e($s['owner_email'] ?? '—') ?>" data-users="<?= (int) ($s['user_count'] ?? 0) ?>"><i class="ti ti-eye"></i></button>
            <a href="<?= url('/stores/'.$sid) ?>" class="btn btn-outline-secondary border-0" title="Yönet"><i class="ti ti-settings"></i></a>
            <a href="<?= url('/stores/'.$sid.'/entitlements') ?>" class="btn btn-outline-secondary border-0" title="Yetkiler"><i class="ti ti-adjustments-horizontal"></i></a>
            <?php if ($st === 'active'): ?>
            <form method="post" action="<?= url('/stores/'.$sid.'/status') ?>" onsubmit="return confirm('“<?= $e($name) ?>” askıya alınsın mı?');"><input type="hidden" name="_csrf_token" value="<?= $csrf ?>"><input type="hidden" name="status" value="suspended"><button type="submit" class="btn btn-outline-warning border-0" title="Askıya Al"><i class="ti ti-player-pause"></i></button></form>
            <?php else: ?>
            <form method="post" action="<?= url('/stores/'.$sid.'/status') ?>"><input type="hidden" name="_csrf_token" value="<?= $csrf ?>"><input type="hidden" name="status" value="active"><button type="submit" class="btn btn-outline-success border-0" title="Aktifleştir"><i class="ti ti-player-play"></i></button></form>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>
</div>

<!-- Hızlı Bak modal (popup — detaya gitmeden özet) -->
<div class="modal fade" id="storeQuick" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sqName">Mağaza</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0 fs-2">
          <dt class="col-5 text-muted fw-normal">Slug</dt><dd class="col-7 font-monospace mb-2" id="sqSlug">—</dd>
          <dt class="col-5 text-muted fw-normal">Paket</dt><dd class="col-7 mb-2" id="sqPackage">—</dd>
          <dt class="col-5 text-muted fw-normal">Durum</dt><dd class="col-7 mb-2" id="sqStatus">—</dd>
          <dt class="col-5 text-muted fw-normal">Doğrulama</dt><dd class="col-7 mb-2" id="sqVerified">—</dd>
          <dt class="col-5 text-muted fw-normal">Sahip</dt><dd class="col-7 mb-2" id="sqOwner">—</dd>
          <dt class="col-5 text-muted fw-normal">Kullanıcı</dt><dd class="col-7 mb-2" id="sqUsers">—</dd>
          <dt class="col-5 text-muted fw-normal">Oluşturma</dt><dd class="col-7 mb-0" id="sqCreated">—</dd>
        </dl>
      </div>
      <div class="modal-footer">
        <a href="#" id="sqDetail" class="btn btn-primary btn-sm"><i class="ti ti-settings me-1"></i>Yönet</a>
        <a href="#" id="sqEnt" class="btn btn-outline-secondary btn-sm"><i class="ti ti-adjustments-horizontal me-1"></i>Yetkiler</a>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('storeQuick');
  if (!modal) { return; }
  modal.addEventListener('show.bs.modal', function(ev){
    var b = ev.relatedTarget; if (!b) { return; }
    var d = b.dataset;
    var set = function(id, v){ var el = document.getElementById(id); if (el) { el.textContent = v || '—'; } };
    set('sqName', d.name); set('sqSlug', d.slug); set('sqPackage', d.package);
    set('sqStatus', d.status); set('sqVerified', d.verified); set('sqOwner', d.owner);
    set('sqUsers', d.users); set('sqCreated', d.created);
    var det = document.getElementById('sqDetail'), ent = document.getElementById('sqEnt');
    if (det) { det.href = '<?= url('/stores') ?>/' + d.id; }
    if (ent) { ent.href = '<?= url('/stores') ?>/' + d.id + '/entitlements'; }
  });
})();
</script>
