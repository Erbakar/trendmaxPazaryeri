<?php
/** Faz 7 — Audit Dashboard (admin layout fragment). Admin işlemleri, entitlement, login hataları, kritik olaylar. Lumina: KPI boss-stat-card + DataTables tabloları. */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/_helpers.php';
$rc = $resultCounts ?? [];
$ls = $loginStats ?? [];

/** Sonuç etiketi — rounded-pill badge. */
$resBadge = function (string $r) use ($e): string {
    $m = [
        'success' => 'bg-success-subtle text-success',
        'denied'  => 'bg-danger-subtle text-danger',
        'failed'  => 'bg-warning-subtle text-warning',
    ];
    $cls = $m[$r] ?? 'bg-secondary-subtle text-secondary';
    return '<span class="badge ' . $cls . ' rounded-pill">' . $e($r) . '</span>';
};

/** Audit kayıt tablosu — Lumina deseni (benzersiz id, DataTables otomatik biner). */
$auditTable = function (array $rows, string $tableId) use ($e, $resBadge): void {
    if (empty($rows)) {
        echo '<div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Kayıt yok.</div></div>';
        return;
    }
    echo '<div class="card-body"><div class="table-responsive">'
       . '<table id="' . $e($tableId) . '" class="table align-middle text-nowrap mb-0">'
       . '<thead class="fs-2 text-muted text-uppercase"><tr>'
       . '<th class="ps-4">Zaman</th><th>Aktör</th>'
       . '<th>Eylem</th><th>Hedef</th>'
       . '<th>Sonuç</th><th class="text-end pe-4">IP</th>'
       . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>'
           . '<td class="ps-4 text-muted" data-order="' . (int) strtotime((string) ($r['created_at'] ?? '')) . '">' . $e($r['created_at'] ?? '') . '</td>'
           . '<td><span class="fw-medium">' . $e($r['actor_email'] ?? '') . '</span> <span class="text-muted fs-2">(' . $e($r['actor_type'] ?? '') . ')</span></td>'
           . '<td><span class="font-monospace fs-2 text-body-secondary">' . $e($r['action'] ?? '') . '</span></td>'
           . '<td><span class="font-monospace fs-2 text-muted">' . $e($r['target'] ?? '') . '</span></td>'
           . '<td>' . $resBadge((string) ($r['result'] ?? '')) . '</td>'
           . '<td class="text-end pe-4 text-muted font-monospace fs-2">' . $e($r['ip_address'] ?? '') . '</td>'
           . '</tr>';
    }
    echo '</tbody></table></div></div>';
};

/** Liste kartı sarmalı — Lumina card-header + card-title. */
$section = function (string $title, string $icon, callable $body) use ($e): void {
    echo '<div class="card mb-3">'
       . '<div class="card-header"><h3 class="card-title mb-0"><i class="ti ti-' . $e($icon) . ' me-1 text-muted"></i>' . $e($title) . '</h3></div>';
    $body();
    echo '</div>';
};

$kpis = [
    ['l' => 'Başarılı (24s)',     'v' => (int) ($rc['success'] ?? 0), 'i' => 'circle-check',  'c' => 'success'],
    ['l' => 'Reddedilen (24s)',   'v' => (int) ($rc['denied'] ?? 0),  'i' => 'shield-x',      'c' => 'danger'],
    ['l' => 'Başarısız (24s)',    'v' => (int) ($rc['failed'] ?? 0),  'i' => 'circle-x',      'c' => 'warning'],
    ['l' => 'Login hatası (24s)', 'v' => (int) ($ls['failed'] ?? 0),  'i' => 'login',         'c' => 'info', 'sub' => (int) ($ls['success'] ?? 0) . ' başarılı'],
];

boss_page_header('Audit Dashboard', [['l' => 'Operasyon', 'u' => '/ops'], ['l' => 'Audit']], '');
?>

<!-- KPI'lar -->
<div class="row g-3 mb-4">
  <?php foreach ($kpis as $k): ?>
  <div class="col-6 col-xl-3">
    <div class="card mb-0 h-100 boss-stat-card"><div class="card-body d-flex align-items-start justify-content-between gap-2">
      <div class="min-w-0">
        <div class="boss-stat-label"><?= $e($k['l']) ?></div>
        <div class="boss-stat-value mt-2"><?= (int) $k['v'] ?></div>
        <?php if (!empty($k['sub'])): ?><div class="text-muted fs-2 mt-1"><?= $e($k['sub']) ?></div><?php endif; ?>
      </div>
      <span class="boss-stat-chip bg-<?= $k['c'] ?>-subtle text-<?= $k['c'] ?>"><i class="ti ti-<?= $e($k['i']) ?> fs-5"></i></span>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$section('Kritik Olaylar (reddedilen / başarısız)', 'alert-octagon', fn() => $auditTable($critical ?? [], 'auditCriticalTable'));
$section('Entitlement Değişiklikleri', 'key', fn() => $auditTable($entitlement ?? [], 'auditEntitlementTable'));
$section('Admin İşlemleri', 'user-cog', fn() => $auditTable($adminActions ?? [], 'auditAdminTable'));
?>

<div class="card mb-0">
  <div class="card-header">
    <h3 class="card-title mb-0"><i class="ti ti-login me-1 text-muted"></i>Login Başarısızlıkları</h3>
  </div>
  <?php if (!empty($ls['top_failing_ips'])): ?>
  <div class="card-body pb-0">
    <div class="text-muted fs-2 mb-2">En çok denenen IP'ler (24s):
      <?php foreach ($ls['top_failing_ips'] as $ip): ?><span class="badge bg-secondary-subtle text-secondary rounded-pill font-monospace ms-1"><?= $e($ip['ip_address'] ?? '?') ?> · <?= (int) $ip['n'] ?></span><?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <?php if (empty($loginFailures)): ?>
    <div class="card-body text-center py-5"><div class="text-muted"><i class="ti ti-inbox d-block mb-2" style="font-size:34px;opacity:.5;"></i>Kayıt yok.</div></div>
  <?php else: ?>
  <div class="card-body"><div class="table-responsive">
    <table id="auditLoginFailuresTable" class="table align-middle text-nowrap mb-0">
      <thead class="fs-2 text-muted text-uppercase">
        <tr><th class="ps-4">Zaman</th><th>E-posta</th><th>IP</th><th>Sebep</th><th class="pe-4">User-Agent</th></tr>
      </thead>
      <tbody>
        <?php foreach ($loginFailures as $f): ?>
        <tr>
          <td class="ps-4 text-muted" data-order="<?= (int) strtotime((string) ($f['created_at'] ?? '')) ?>"><?= $e($f['created_at'] ?? '') ?></td>
          <td class="fw-medium"><?= $e($f['email'] ?? '') ?></td>
          <td class="text-muted font-monospace fs-2"><?= $e($f['ip_address'] ?? '') ?></td>
          <td><span class="badge bg-danger-subtle text-danger rounded-pill"><?= $e($f['reason'] ?? '') ?></span></td>
          <td class="text-muted text-truncate pe-4" style="max-width:260px;" title="<?= $e($f['user_agent'] ?? '') ?>"><?= $e(mb_substr((string) ($f['user_agent'] ?? ''), 0, 80)) ?></td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div></div>
  <?php endif ?>
</div>
