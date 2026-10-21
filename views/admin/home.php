<?php
/** Admin paneli ana sayfa — modern SaaS dashboard (gerçek veri: KPI + ciro grafiği + abonelik + sağlık + son etkinlik). */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$totpOn = !empty($totpEnabled);
$kpis           = $kpis ?? [];
$revenue        = $revenue ?? [];
$subStatus      = $subStatus ?? [];
$conversion     = $conversion ?? [];
$storesSeries   = $stores ?? [];
$health         = $health ?? null;
$recentSignups  = $recentSignups ?? [];
$recentPayments = $recentPayments ?? [];

$fmtTL   = fn($v) => '₺' . number_format((float) $v, ($v == (int) $v) ? 0 : 2, ',', '.');
$fmtTLk  = function ($v) { $v = (float) $v; return $v >= 1000 ? '₺' . rtrim(rtrim(number_format($v / 1000, 1, ',', '.'), '0'), ',') . 'b' : '₺' . number_format($v, 0, ',', '.'); };
$fmtDate = function ($s) { $t = $s ? strtotime((string) $s) : 0; return $t ? date('d.m.Y', $t) : '—'; };

$kpiCards = [
    ['Aktif Mağaza',       (string) ($kpis['active_stores'] ?? 0),    'store',         'primary', '/stores',                           null],
    ['Trial Mağaza',       (string) ($kpis['trial'] ?? 0),            'hourglass',     'warning', '/admin/subscriptions?status=trial', null],
    ['Bu Ay Tahsilat',     $fmtTL($kpis['month_total'] ?? 0),         'banknote',      'success', '/admin/payments?status=confirmed',  $kpis['month_delta'] ?? null],
    ['Bekleyen Ödeme',     (string) ($kpis['pending_payments'] ?? 0), 'clock',         'info',    '/admin/payments?status=pending',    null],
    ['Açık Kritik Ticket', (string) ($kpis['open_critical'] ?? 0),    'alert-octagon', 'danger',  '/admin/support?status=open',        null],
    ['Yeni Başvuru',       (string) ($kpis['new_signups'] ?? 0),      'user-plus',     'success', '/admin/signups',                    null],
];

$sections = [
    ['/stores',           'store',           'Mağazalar & Yetkiler', 'Mağaza listesi, pazaryeri & modül entitlement yönetimi'],
    ['/ops',              'settings-2',      'Operasyon Paneli',     'Queue, worker, sistem sağlığı ve audit konsolu'],
    ['/admin/support',    'life-buoy',       'Destek Talepleri',     'Mağaza destek ticket\'ları — yanıt & durum'],
    ['/admin/updates',    'megaphone',       'Güncellemeler',        'Changelog / duyuru yayını'],
    ['/admin/api-tokens', 'key-round',       'API Anahtarları',      'Tüm mağazalar için API token yönetimi'],
    ['/backups',          'database-backup', 'Sistem Yedekleri',     'Yedek oluştur / geri yükle / indir'],
];

// ── Ciro grafiği geometrisi (SVG, gerçek seri) ──
$revMax = 0.0;
foreach ($revenue as $p) { $revMax = max($revMax, (float) $p['total']); }
$revMaxSafe = $revMax > 0 ? $revMax : 1;
$revTotal = 0.0;
foreach ($revenue as $p) { $revTotal += (float) $p['total']; }
$W = 620; $H = 210; $padL = 10; $padR = 14; $padT = 18; $padB = 30;
$n = count($revenue);
$plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
$pts = [];
foreach (array_values($revenue) as $i => $p) {
    $x = $n > 1 ? $padL + $i * ($plotW / ($n - 1)) : $padL + $plotW / 2;
    $y = $padT + $plotH - ((float) $p['total'] / $revMaxSafe) * $plotH;
    $pts[] = ['x' => round($x, 1), 'y' => round($y, 1), 'p' => $p];
}
$lineStr = implode(' ', array_map(fn($pt) => $pt['x'] . ',' . $pt['y'], $pts));
$areaStr = $pts ? ('M' . $pts[0]['x'] . ',' . ($padT + $plotH) . ' L' . str_replace(' ', ' L', $lineStr) . ' L' . end($pts)['x'] . ',' . ($padT + $plotH) . ' Z') : '';

// ── Abonelik durumları ──
$subDefs = [
    ['active',    'Aktif',        '#16a34a'],
    ['trial',     'Deneme',       'var(--ad-accent)'],
    ['passive',   'Pasif',        '#94a3b8'],
    ['suspended', 'Askıda',       '#f59e0b'],
    ['expired',   'Süresi Doldu', '#0ea5e9'],
    ['cancelled', 'İptal',        '#dc2626'],
];
$subAll = (int) ($subStatus['all'] ?? 0);

// ── Sağlık seviye yardımcıları ──
$lvlMeta = ['ok' => ['Sağlıklı', '#16a34a', 'bg-success-subtle'], 'degraded' => ['Uyarı', '#f59e0b', 'bg-warning-subtle'], 'down' => ['Kritik', '#dc2626', 'bg-danger-subtle']];
$lvlOf = fn($l) => $lvlMeta[$l] ?? ['Bilinmiyor', '#94a3b8', 'bg-secondary-subtle'];
$ageTxt = function ($s) {
    if ($s === null) { return '—'; }
    $s = (int) $s;
    if ($s < 60) { return $s . ' sn önce'; }
    if ($s < 3600) { return floor($s / 60) . ' dk önce'; }
    if ($s < 86400) { return floor($s / 3600) . ' sa önce'; }
    return floor($s / 86400) . ' gün önce';
};
$payBadge = [
    'confirmed' => ['Onaylı', 'bg-success-subtle'], 'pending' => ['Bekliyor', 'bg-warning-subtle'],
    'rejected'  => ['Reddedildi', 'bg-danger-subtle'], 'failed' => ['Başarısız', 'bg-danger-subtle'],
];

$action = '<a href="' . $e(url('/ops')) . '" class="btn btn-primary d-inline-flex align-items-center gap-1"><i class="ti ti-terminal-2"></i> Operasyon Konsolu</a>';
boss_page_header('Genel Bakış', [['l' => 'Genel Bakış']], $action);
?>

<p class="text-muted fs-2 mb-4">Oturum: <span class="fw-semibold text-body"><?= $e($_adminEmail ?? '') ?></span></p>

<!-- ===== KPI ===== -->
<div class="row g-3 mb-1">
  <?php foreach ($kpiCards as [$label, $val, $icon, $color, $link, $delta]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= $e(url($link)) ?>" class="card h-100 mb-0 text-decoration-none boss-stat-card">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div class="min-w-0">
            <div class="boss-stat-label"><?= $e($label) ?></div>
            <div class="boss-stat-value mt-2"><?= $e($val) ?></div>
          </div>
          <span class="boss-stat-chip bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="ti ti-<?= $e(bti($icon)) ?>"></i></span>
        </div>
        <?php if (is_array($delta)): ?>
        <div class="boss-stat-trend">
          <span class="boss-stat-delta <?= !empty($delta['up']) ? 'up' : 'down' ?>"><i class="ti ti-trending-<?= !empty($delta['up']) ? 'up' : 'down' ?>"></i><?= $e($delta['v']) ?></span>
          <span class="boss-stat-trend-note">vs geçen ay</span>
        </div>
        <?php endif; ?>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!$totpOn): ?>
<!-- 2FA uyarısı -->
<div class="card mt-3 mb-1">
  <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <span class="ad-ico-badge bg-warning-subtle text-warning" style="width:46px;height:46px;border-radius:13px;"><i class="ti ti-shield-x fs-5"></i></span>
      <div>
        <h6 class="fw-semibold mb-1">İki Adımlı Doğrulama kurulu değil</h6>
        <p class="text-muted fs-2 mb-0">Platform hesabınızı korumak için 2FA kurulumunu tamamlayın.</p>
      </div>
    </div>
    <a href="<?= $e(url('/admin/2fa/setup')) ?>" class="btn btn-warning d-inline-flex align-items-center gap-1"><i class="ti ti-shield-check"></i> Şimdi kur</a>
  </div>
</div>
<?php endif; ?>

<!-- ===== ANA GRİD: Ciro grafiği + Abonelik ===== -->
<div class="row g-3 mt-1">
  <!-- Ciro trendi -->
  <div class="col-12 col-xl-8">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <div>
          <h3 class="card-title">Tahsilat Trendi</h3>
          <div class="card-subtitle">Son 6 ay — onaylanan ödemeler</div>
        </div>
        <div class="text-end">
          <div class="fw-bold" style="font-size:18px;letter-spacing:-.02em;"><?= $e($fmtTL($revTotal)) ?></div>
          <div class="fs-1 text-muted">6 ay toplam</div>
        </div>
      </div>
      <div class="card-body">
        <?php if ($revMax <= 0): ?>
          <div class="text-center text-muted py-5"><i class="ti ti-chart-line" style="font-size:30px;opacity:.4"></i><div class="mt-2 fs-2">Henüz onaylanmış tahsilat yok.</div></div>
        <?php else: ?>
          <svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;height:auto;display:block;overflow:visible" role="img" aria-label="Aylık tahsilat trendi">
            <!-- yatay kılavuzlar -->
            <?php for ($g = 0; $g <= 3; $g++): $gy = $padT + $plotH - ($g / 3) * $plotH; ?>
            <line x1="<?= $padL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $W - $padR ?>" y2="<?= round($gy, 1) ?>" stroke="var(--ad-border)" stroke-width="1"></line>
            <text x="<?= $W - $padR + 2 ?>" y="<?= round($gy + 3, 1) ?>" fill="var(--ad-muted)" font-size="9" text-anchor="start"><?= $e($fmtTLk(($g / 3) * $revMaxSafe)) ?></text>
            <?php endfor; ?>
            <!-- alan + çizgi -->
            <path d="<?= $e($areaStr) ?>" style="fill:rgba(var(--ad-accent-rgb),.10)"></path>
            <polyline fill="none" style="stroke:var(--ad-accent)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="<?= $e($lineStr) ?>"></polyline>
            <!-- noktalar + ay etiketleri -->
            <?php foreach ($pts as $i => $pt): $isLast = ($i === count($pts) - 1); ?>
            <circle cx="<?= $pt['x'] ?>" cy="<?= $pt['y'] ?>" r="<?= $isLast ? 4.5 : 3.2 ?>" fill="#fff" style="stroke:var(--ad-accent)" stroke-width="<?= $isLast ? 3 : 2 ?>"></circle>
            <text x="<?= $pt['x'] ?>" y="<?= $H - 10 ?>" fill="<?= $isLast ? 'var(--ad-accent)' : 'var(--ad-muted)' ?>" font-size="10" font-weight="<?= $isLast ? 700 : 500 ?>" text-anchor="middle"><?= $e($pt['p']['label']) ?></text>
            <?php endforeach; ?>
            <!-- son ay değeri etiketi -->
            <?php if ($pts): $last = end($pts); ?>
            <text x="<?= $last['x'] ?>" y="<?= max(12, $last['y'] - 10) ?>" fill="var(--ad-accent)" font-size="11" font-weight="700" text-anchor="end"><?= $e($fmtTL($last['p']['total'])) ?></text>
            <?php endif; ?>
          </svg>
        <?php endif; ?>

        <?php // Yeni mağaza (6 ay) mini barlar
        $stMax = 0; foreach ($storesSeries as $s) { $stMax = max($stMax, (int) $s['count']); }
        if ($storesSeries && $stMax > 0): ?>
        <div class="mt-4 pt-3" style="border-top:1px solid var(--ad-border)">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="boss-stat-label" style="letter-spacing:.04em">Yeni Mağaza (6 ay)</span>
            <span class="fs-1 text-muted"><?= (int) array_sum(array_map(fn($s) => (int) $s['count'], $storesSeries)) ?> toplam</span>
          </div>
          <div class="d-flex align-items-end gap-2" style="height:60px;">
            <?php foreach ($storesSeries as $s): $h = max(6, round((int) $s['count'] / max(1, $stMax) * 52)); ?>
            <div class="flex-fill d-flex flex-column align-items-center gap-1" title="<?= $e($s['label']) ?>: <?= (int) $s['count'] ?>">
              <span class="fs-1 fw-semibold text-body"><?= (int) $s['count'] ?></span>
              <div style="width:100%;max-width:34px;height:<?= $h ?>px;border-radius:6px 6px 0 0;background:rgba(var(--ad-accent-rgb),.22)"></div>
              <span class="fs-1 text-muted"><?= $e($s['label']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Abonelik dağılımı -->
  <div class="col-12 col-xl-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <div>
          <h3 class="card-title">Abonelikler</h3>
          <div class="card-subtitle">Toplam <?= $subAll ?> abonelik</div>
        </div>
        <a href="<?= $e(url('/admin/subscriptions')) ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-external-link"></i></a>
      </div>
      <div class="card-body">
        <?php if ($subAll <= 0): ?>
          <div class="text-center text-muted py-4 fs-2">Henüz abonelik yok.</div>
        <?php else: ?>
          <?php foreach ($subDefs as [$key, $lbl, $clr]): $cnt = (int) ($subStatus[$key] ?? 0); if ($cnt <= 0) { continue; } $pct = round($cnt / max(1, $subAll) * 100); ?>
          <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="d-inline-flex align-items-center gap-2 fs-2 fw-semibold text-body"><span style="width:9px;height:9px;border-radius:50%;background:<?= $clr ?>"></span><?= $e($lbl) ?></span>
              <span class="fs-2 text-muted"><?= $cnt ?> <span class="ms-1" style="opacity:.7">%<?= $pct ?></span></span>
            </div>
            <div style="height:7px;border-radius:99px;background:var(--ad-surface-3);overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;border-radius:99px;background:<?= $clr ?>"></div></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="mt-4 pt-3 d-flex align-items-center justify-content-between" style="border-top:1px solid var(--ad-border)">
          <div>
            <div class="boss-stat-label" style="letter-spacing:.04em">Dönüşüm Oranı</div>
            <div class="fs-1 text-muted mt-1"><?= (int) ($conversion['active_paid'] ?? 0) ?> ücretli / <?= (int) ($conversion['trial'] ?? 0) ?> deneme</div>
          </div>
          <div class="fw-bold text-success" style="font-size:24px;letter-spacing:-.03em">%<?= $e((string) ($conversion['rate'] ?? 0)) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== 2. GRİD: Başvurular + Ödemeler + Sağlık ===== -->
<div class="row g-3 mt-1">
  <!-- Bekleyen başvurular -->
  <div class="col-12 col-lg-6 col-xl-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h3 class="card-title">Bekleyen Başvurular</h3>
        <a href="<?= $e(url('/admin/signups')) ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-external-link"></i> Tümü</a>
      </div>
      <div class="card-body p-0">
        <?php if (!$recentSignups): ?>
          <div class="text-center text-muted py-5 fs-2"><i class="ti ti-inbox d-block mb-2" style="font-size:26px;opacity:.4"></i>Bekleyen başvuru yok.</div>
        <?php else: foreach ($recentSignups as $r):
            $name = (string) ($r['company'] ?? $r['store_name'] ?? $r['name'] ?? $r['email'] ?? '—'); ?>
        <a href="<?= $e(url('/admin/signups')) ?>" class="ad-listrow">
          <span class="ad-listrow-ico"><i class="ti ti-building-store"></i></span>
          <span class="ad-listrow-main">
            <span class="ad-listrow-title text-truncate"><?= $e($name) ?></span>
            <span class="ad-listrow-sub text-truncate"><?= $e($r['package_name'] ?? 'Paket seçilmedi') ?> · <?= $e($fmtDate($r['created_at'] ?? '')) ?></span>
          </span>
          <span class="badge bg-warning-subtle">Bekliyor</span>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Son ödemeler -->
  <div class="col-12 col-lg-6 col-xl-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h3 class="card-title">Son Ödemeler</h3>
        <a href="<?= $e(url('/admin/payments')) ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-external-link"></i> Tümü</a>
      </div>
      <div class="card-body p-0">
        <?php if (!$recentPayments): ?>
          <div class="text-center text-muted py-5 fs-2"><i class="ti ti-receipt d-block mb-2" style="font-size:26px;opacity:.4"></i>Henüz ödeme kaydı yok.</div>
        <?php else: foreach ($recentPayments as $p):
            [$pl, $pc] = $payBadge[$p['status'] ?? ''] ?? [($p['status'] ?? '—'), 'bg-secondary-subtle']; ?>
        <a href="<?= $e(url('/admin/payments')) ?>" class="ad-listrow">
          <span class="ad-listrow-ico"><i class="ti ti-cash"></i></span>
          <span class="ad-listrow-main">
            <span class="ad-listrow-title text-truncate"><?= $e($p['store_name'] ?? ('#' . ($p['store_id'] ?? '?'))) ?></span>
            <span class="ad-listrow-sub"><?= $e($fmtDate($p['created_at'] ?? $p['confirmed_at'] ?? '')) ?></span>
          </span>
          <span class="text-end">
            <span class="d-block fw-bold text-body" style="font-variant-numeric:tabular-nums"><?= $e($fmtTL($p['amount'] ?? 0)) ?></span>
            <span class="badge <?= $pc ?> mt-1"><?= $e($pl) ?></span>
          </span>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Sistem sağlığı -->
  <div class="col-12 col-xl-4">
    <div class="card h-100 mb-0">
      <div class="card-header">
        <h3 class="card-title">Sistem Sağlığı</h3>
        <?php if ($health): [$ol, $oc] = $lvlOf($health['overall'] ?? ''); ?>
          <span class="badge <?= $oc[2] ?? 'bg-secondary-subtle' ?>"><?= $e($ol) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (!$health): ?>
          <div class="text-center text-muted py-5 fs-2">Sağlık verisi alınamadı.</div>
        <?php else:
          $rows = [
            ['Veritabanı', $health['db']['level'] ?? 'ok',      (($health['db']['connected'] ?? false) ? 'Bağlı' : 'Bağlantı yok')],
            ['Kuyruk',     $health['queue']['level'] ?? 'ok',   (($health['queue']['pending_due_now'] ?? 0) . ' bekleyen · ' . ($health['queue']['dead_letter'] ?? 0) . ' ölü')],
            ['Worker',     $health['workers']['level'] ?? 'ok', (($health['workers']['active_count'] ?? 0) . ' aktif · ' . ($health['workers']['pending_jobs'] ?? 0) . ' iş')],
            ['Cron',       $health['cron']['level'] ?? 'ok',    $ageTxt($health['cron']['last_activity_sec'] ?? null)],
            ['API',        $health['api']['level'] ?? 'ok',     ('%' . ($health['api']['error_rate_pct'] ?? 0) . ' hata · ' . ($health['api']['avg_response_ms'] ?? 0) . 'ms')],
          ];
          foreach ($rows as [$lbl, $lvl, $metric]): [$lt, $lc] = $lvlOf($lvl); ?>
        <div class="ad-listrow">
          <span style="width:9px;height:9px;border-radius:50%;background:<?= $lc ?>;flex:0 0 auto"></span>
          <span class="ad-listrow-main">
            <span class="ad-listrow-title"><?= $e($lbl) ?></span>
            <span class="ad-listrow-sub"><?= $e($metric) ?></span>
          </span>
          <span class="fs-1 fw-semibold" style="color:<?= $lc ?>"><?= $e($lt) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <?php if ($health): ?>
      <div class="card-footer text-center py-2"><a href="<?= $e(url('/ops/health')) ?>" class="fs-2 fw-semibold text-primary">Sağlık detayı <i class="ti ti-arrow-right"></i></a></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===== YÖNETİM kısayolları ===== -->
<div class="boss-stat-label mb-3 mt-4" style="letter-spacing:.07em;">Yönetim</div>
<div class="row g-3">
  <?php foreach ($sections as [$u, $i, $t, $d]): ?>
  <div class="col-12 col-md-6 col-xl-4">
    <a href="<?= $e(url($u)) ?>" class="card h-100 mb-0 text-decoration-none boss-stat-card">
      <div class="card-body d-flex align-items-start gap-3">
        <span class="ad-ico-badge bg-primary-subtle text-primary flex-shrink-0" style="width:46px;height:46px;border-radius:13px;"><i class="ti ti-<?= $e(bti($i)) ?> fs-5"></i></span>
        <div class="min-w-0">
          <h6 class="fw-semibold mb-1 d-flex align-items-center gap-1"><?= $e($t) ?> <i class="ti ti-arrow-up-right fs-3 text-muted"></i></h6>
          <p class="text-muted fs-2 mb-0"><?= $e($d) ?></p>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
