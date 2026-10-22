<?php
$logs = $data['logs'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$lastSuccessful = $data['lastSuccessful'] ?? [];
$automaticByType = [];
foreach ($data['lastAutomatic'] ?? [] as $run) {
    $key = $run['sync_type'];
    if (!isset($automaticByType[$key]) || $run['finished_at'] > $automaticByType[$key]) $automaticByType[$key] = $run['finished_at'];
}
$syncConfig = $data['syncConfig'] ?? [];
$platformDisabled = $data['platformDisabled'] ?? [];
// Ayar ekranına gömülü eski veri kaynağı bu anahtarı vermeyebilir → kart yanlış durum göstermesin.
$hasAutoStock = array_key_exists('autoStockBroadcast', $data);
$autoStockOn = (bool) ($data['autoStockBroadcast'] ?? true);
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Son başarılı sync'leri türe göre grupla (en son olan)
$lastByType = [];
foreach ($lastSuccessful as $ls) {
    $key = $ls['sync_type'] ?? '';
    if (!isset($lastByType[$key]) || strtotime($ls['finished_at'] ?? '2000-01-01') > strtotime($lastByType[$key]['finished_at'] ?? '2000-01-01')) {
        $lastByType[$key] = $ls;
    }
}

// Türkçe durum haritası
$statusMap = [
    'success' => 'Başarılı',
    'failed'  => 'Başarısız',
    'partial' => 'Kısmi',
    'running' => 'Çalışıyor',
    'pending' => 'Bekliyor',
];
$typeMap = [
    'orders'        => 'Sipariş',
    'orders_pull'   => 'Sipariş',
    'products'      => 'Ürün',
    'products_pull' => 'Ürün',
    'stock'         => 'Stok',
    'stock_push'    => 'Stok',
    'price'         => 'Fiyat',
    'prices'        => 'Fiyat',
    'price_push'    => 'Fiyat',
    'returns'       => 'İade',
    'returns_pull'  => 'İade',
    'questions'     => 'Soru',
    'questions_pull'=> 'Soru',
    'import_delta'    => 'Delta İçe Aktarma',
    'initial_import'  => 'İlk İçe Aktarma',
];
$triggerMap = [
    'manual'         => 'Manuel',
    'cron'           => 'Otomatik',
    'api'            => 'API',
    'initial_import' => 'İlk Aktarım',
    'system'         => 'Sistem',
];

// Sync tipleri
$syncTypes = [
    ['key'=>'orders',    'label'=>'Siparişler', 'icon'=>'shopping-bag',   'color'=>'var(--primary)', 'bg'=>'rgb(var(--brand) / .08)', 'dir'=>'pull', 'desc'=>'Yeni siparişleri pazaryerlerinden çeker'],
    ['key'=>'returns',   'label'=>'İadeler',    'icon'=>'rotate-ccw',     'color'=>'#f43f5e',        'bg'=>'rgba(244,63,94,.08)',     'dir'=>'pull', 'desc'=>'İade taleplerini pazaryerlerinden çeker'],
    ['key'=>'questions', 'label'=>'Sorular',    'icon'=>'message-circle', 'color'=>'var(--primary)', 'bg'=>'rgb(var(--brand) / .08)', 'dir'=>'pull', 'desc'=>'Müşteri sorularını pazaryerlerinden çeker'],
    ['key'=>'stock',     'label'=>'Stok',       'icon'=>'package',        'color'=>'#10b981',        'bg'=>'rgba(16,185,129,.08)',    'dir'=>'push', 'desc'=>'Sistem stoklarını pazaryerlerine gönderir'],
    ['key'=>'price',     'label'=>'Fiyat',      'icon'=>'turkish-lira',   'color'=>'#f59e0b',        'bg'=>'rgba(245,158,11,.08)',    'dir'=>'push', 'desc'=>'Sistem fiyatlarını pazaryerlerine gönderir'],
    ['key'=>'products',  'label'=>'Ürünler',    'icon'=>'layers',         'color'=>'#0ea5e9',        'bg'=>'rgba(14,165,233,.08)',    'dir'=>'pull', 'desc'=>'Ürün verilerini pazaryerlerinden günceller'],
];

// Sıklık seçenekleri
$intervalOptions = [
    1 => '1 dk', 2 => '2 dk', 5 => '5 dk', 10 => '10 dk',
    15 => '15 dk', 30 => '30 dk', 60 => '1 saat', 120 => '2 saat', 360 => '6 saat',
];

// İstatistikler
$totalLogs = count($logs);
$successLogs = count(array_filter($logs, fn($l) => ($l['status'] ?? '') === 'success'));
$failedLogs = count(array_filter($logs, fn($l) => in_array($l['status'] ?? '', ['failed','partial'])));
$totalProcessed = array_sum(array_map(fn($l) => (int)($l['records_processed'] ?? 0), $logs));
?>

<style>
/* === SYNC PAGE — OzyConnect Premium v2 === */

/* === Overview Stats === */
.sc-overview{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.sc-ov{
    display:flex;align-items:center;gap:14px;padding:18px 20px;
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    position:relative;overflow:hidden;transition:all .2s;
}
.sc-ov:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border)}
.sc-ov-icon{
    width:44px;height:44px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.sc-ov-icon [data-lucide]{width:22px;height:22px}
.sc-ov-info{display:flex;flex-direction:column;gap:2px}
.sc-ov-val{font-size:24px;font-weight:700;line-height:1;letter-spacing:-.5px}
.sc-ov-label{font-size:11px;font-weight:500;color:var(--text-muted)}

/* === Marketplace Pills === */
.sc-mp-row{display:flex;gap:10px;margin-bottom:24px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.sc-mp-row>.sc-mp{flex:1 1 0;min-width:110px}
.sc-mp{
    display:flex;align-items:center;justify-content:center;gap:6px;padding:12px 8px;
    background:var(--bg-card);border:1px solid var(--border);border-radius:10px;
    transition:all .15s;position:relative;cursor:default;
}
.sc-mp:hover{border-color:var(--primary);box-shadow:0 0 0 1px var(--border)}
.sc-mp-logo{width:80px;height:28px;object-fit:contain;border-radius:4px;flex-shrink:0}
.sc-mp-name{font-size:12px;font-weight:700;color:var(--text-primary)}
.sc-mp-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.sc-mp-dot.on{background:#10b981;box-shadow:0 0 0 1px var(--border);animation:pulse-dot 2s infinite}
.sc-mp-dot.off{background:var(--text-muted)}
.sc-mp-empty{color:var(--text-muted);font-size:12px;font-weight:600}
.sc-mp-empty [data-lucide]{width:14px;height:14px;opacity:.5}
.sc-mp[title]:hover::after{
    content:attr(title);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);
    background:var(--text-primary);color:var(--bg-card);padding:4px 10px;border-radius:6px;
    font-size:11px;font-weight:600;white-space:nowrap;pointer-events:none;z-index:10;
}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.5}}
.sc-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

/* === Sync Automation Panel === */
.sc-auto{
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    overflow:hidden;margin-bottom:24px;
}
.sc-auto-h{
    padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input);
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
}
.sc-auto-h-icon{
    width:36px;height:36px;border-radius:10px;background:rgb(var(--brand) / .08);
    display:flex;align-items:center;justify-content:center;color:var(--primary);flex-shrink:0;
}
.sc-auto-h-icon [data-lucide]{width:18px;height:18px}
.sc-auto-h-text{flex:1;min-width:200px}
.sc-auto-h-title{font-size:15px;font-weight:700;color:var(--text-primary);margin:0}
.sc-auto-h-sub{font-size:11px;color:var(--text-muted);font-weight:500}
.sc-auto-note{
    display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:8px;
    border:1px solid var(--border);background:var(--bg-card);
    font-size:11px;font-weight:600;color:var(--text-muted);
}
.sc-auto-note [data-lucide]{width:13px;height:13px;color:#10b981}

.sc-auto-body{padding:0}
.sc-auto-row{
    display:grid;grid-template-columns:44px 1fr 120px 180px 180px 44px;
    align-items:center;gap:14px;padding:14px 20px;
    border-bottom:1px solid var(--border);transition:background .1s;
}
.sc-auto-row:last-child{border-bottom:none}
.sc-auto-row:hover{background:rgb(var(--brand) / .015)}
.sc-auto-row.disabled .sc-auto-icon,
.sc-auto-row.disabled .sc-auto-info,
.sc-auto-row.disabled .sc-auto-interval{opacity:.5}

/* Row icon */
.sc-auto-icon{
    width:40px;height:40px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.sc-auto-icon [data-lucide]{width:18px;height:18px}

/* Info column */
.sc-auto-info{display:flex;flex-direction:column;gap:2px;min-width:0}
.sc-auto-name{font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sc-auto-name .dir-badge{
    font-size:9px;font-weight:700;padding:2px 7px;border-radius:5px;
    text-transform:uppercase;letter-spacing:.3px;
}
.dir-badge.push{background:rgba(16,185,129,.08);color:#10b981}
.dir-badge.pull{background:rgb(var(--brand) / .08);color:var(--primary)}
.dir-badge.halt{background:rgba(245,158,11,.1);color:#b45309;text-transform:none;letter-spacing:0}
.sc-auto-desc{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-auto-desc.warn{color:#b45309;white-space:normal}

/* Interval selector */
.sc-auto-interval{position:relative}
.sc-auto-interval select{
    width:100%;padding:7px 28px 7px 10px;border:1px solid var(--border);
    border-radius:8px;font-size:12px;font-weight:600;color:var(--text-primary);
    background:var(--bg-card);cursor:pointer;appearance:none;
    font-family:inherit;transition:border-color .15s;
}
.sc-auto-interval select:hover{border-color:var(--primary)}
.sc-auto-interval select:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgb(var(--brand) / .1)}
.sc-auto-interval select:disabled{cursor:not-allowed}
.sc-auto-interval::after{
    content:'';position:absolute;right:10px;top:50%;transform:translateY(-50%);
    width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;
    border-top:5px solid var(--text-muted);pointer-events:none;
}

/* Last run info */
.sc-auto-last{display:flex;flex-direction:column;gap:2px;text-align:center}
.sc-auto-last-time{font-size:12px;font-weight:600;color:var(--text-primary)}
.sc-auto-last-status{font-size:10px;font-weight:700}
.sc-auto-last-status.ok{color:#10b981}
.sc-auto-last-status.err{color:#ef4444}
.sc-auto-last-status.na{color:var(--text-muted)}

/* Run button */
.sc-auto-run{
    width:36px;height:36px;border-radius:8px;border:1px solid var(--border);
    background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;
    color:var(--text-muted);transition:all .15s;flex-shrink:0;
}
.sc-auto-run:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.sc-auto-run:disabled{opacity:.3;cursor:not-allowed}
.sc-auto-run [data-lucide]{width:16px;height:16px}
.sc-auto-run .sc-spin{display:none;width:16px;height:16px;border:2px solid rgb(var(--brand) / .2);border-top-color:var(--primary);border-radius:50%;animation:spin .6s linear infinite}
.sc-auto-run.loading [data-lucide]{display:none}
.sc-auto-run.loading .sc-spin{display:block}

/* Toggle switch */
.sc-toggle{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0}
.sc-toggle input{opacity:0;width:0;height:0;position:absolute}
.sc-toggle-track{
    position:absolute;inset:0;border-radius:12px;cursor:pointer;
    background:var(--border);transition:all .2s;
}
.sc-toggle-track::after{
    content:'';position:absolute;left:3px;top:3px;
    width:18px;height:18px;border-radius:50%;background:#fff;
    transition:transform .2s;box-shadow:0 0 0 1px var(--border);
}
.sc-toggle input:checked + .sc-toggle-track{background:#10b981}
.sc-toggle input:checked + .sc-toggle-track::after{transform:translateX(18px)}
.sc-toggle input:disabled + .sc-toggle-track{cursor:wait;opacity:.6}

/* === Otomatik stok yayını === */
.sc-stockcast{
    display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 20px;margin-bottom:24px;
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
}
.sc-stockcast-icon{
    width:40px;height:40px;border-radius:10px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    background:rgba(16,185,129,.08);color:#10b981;
}
.sc-stockcast-icon [data-lucide]{width:18px;height:18px}
.sc-stockcast-info{flex:1;min-width:220px}
.sc-stockcast-title{font-size:14px;font-weight:700;color:var(--text-primary);margin:0 0 2px}
.sc-stockcast-desc{font-size:12px;line-height:1.5;color:var(--text-muted);margin:0}
.sc-stockcast-ctrl{display:flex;align-items:center;gap:10px}
.sc-stockcast-state{font-size:12px;font-weight:700;min-width:44px}
.sc-stockcast-state.on{color:#10b981}
.sc-stockcast-state.off{color:#b45309}
[data-theme="dark"] .dir-badge.halt,
[data-theme="dark"] .sc-auto-desc.warn,
[data-theme="dark"] .sc-stockcast-state.off{color:var(--warning,#f59e0b)}

/* === History Table === */
.sc-history{
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    overflow:hidden;
}
.sc-hist-h{
    padding:16px 20px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:10px;background:var(--bg-input);
}
.sc-hist-h-icon{
    width:32px;height:32px;border-radius:8px;background:rgb(var(--brand) / .08);
    display:flex;align-items:center;justify-content:center;color:var(--primary);
}
.sc-hist-h-icon [data-lucide]{width:16px;height:16px}
.sc-hist-h-title{font-size:14px;font-weight:700;color:var(--text-primary);flex:1;margin:0}
.sc-hist-filters{display:flex;gap:6px}
.sc-hist-f{
    padding:5px 12px;border:1px solid var(--border);border-radius:8px;
    font-size:11px;font-weight:700;cursor:pointer;background:none;
    color:var(--text-muted);transition:all .12s;font-family:inherit;
}
.sc-hist-f:hover,.sc-hist-f.active{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.sc-hist-body{overflow-x:auto}
.sc-hist-body table{width:100%;border-collapse:collapse}
.sc-hist-body thead th{
    padding:12px 16px;font-size:11px;font-weight:700;
    color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;
    text-align:left;border-bottom:1px solid var(--border);
    background:var(--bg-card);white-space:nowrap;
}
.sc-hist-body thead th.r{text-align:right}
.sc-hist-body tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.sc-hist-body tbody tr:last-child{border-bottom:none}
.sc-hist-body tbody tr:hover{background:rgb(var(--brand) / .02)}
.sc-hist-body tbody td{padding:12px 16px;font-size:13px;color:var(--text-primary);white-space:nowrap}
.sc-hist-body tbody td.r{text-align:right;font-weight:700}
.sc-hist-body tbody td.date{font-size:12px;font-weight:500}
.sc-hist-body tbody td.mp{font-weight:700}
.sc-hist-body tbody td.ok{color:#10b981}
.sc-hist-body tbody td.err{color:#ef4444}
.sc-hist-body tbody td.muted{color:var(--text-muted)}

/* === Badges === */
.sc-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;
}
.sc-badge [data-lucide]{width:12px;height:12px}
.sc-badge-success{background:rgba(16,185,129,.08);color:#10b981}
.sc-badge-danger{background:rgba(239,68,68,.08);color:#ef4444}
.sc-badge-warning{background:rgba(245,158,11,.08);color:#f59e0b}
.sc-badge-info{background:rgb(var(--brand) / .08);color:var(--primary)}
.sc-badge-muted{background:var(--bg-input);color:var(--text-muted)}
.sc-badge-type{background:rgb(var(--brand) / .06);color:var(--primary);font-weight:700}

.sc-trigger{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--text-muted)}
.sc-trigger [data-lucide]{width:12px;height:12px}

/* === Empty === */
.sc-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.sc-empty [data-lucide]{width:40px;height:40px;margin:0 auto 12px;display:block;opacity:.3}
.sc-empty p{font-size:13px;font-weight:500}

/* === Focus === */
.sc-toggle input:focus-visible + .sc-toggle-track,
.sc-auto-run:focus-visible,
.sc-hist-f:focus-visible{outline:2px solid var(--primary);outline-offset:2px}

/* === Animations === */
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.sc-ov{animation:fadeIn .3s ease both}
.sc-ov:nth-child(2){animation-delay:.05s}
.sc-ov:nth-child(3){animation-delay:.1s}
.sc-ov:nth-child(4){animation-delay:.15s}
.sc-auto-row{animation:fadeIn .3s ease both}
.sc-auto-row:nth-child(2){animation-delay:.05s}
.sc-auto-row:nth-child(3){animation-delay:.1s}
.sc-auto-row:nth-child(4){animation-delay:.15s}
.sc-auto-row:nth-child(5){animation-delay:.2s}
.sc-auto-row:nth-child(6){animation-delay:.25s}

@media (prefers-reduced-motion: reduce){
    .sc-ov,.sc-auto-row{animation:none}
    .sc-mp-dot.on{animation:none}
    .sc-ov,.sc-ov:hover{transform:none;transition:none}
    .sc-toggle-track,.sc-toggle-track::after{transition:none}
    .sc-auto-run .sc-spin{animation-duration:1.5s}
}

/* === Responsive === */
@media(max-width:1200px){
    .sc-overview{grid-template-columns:repeat(2,1fr)}
    .sc-auto-row{grid-template-columns:40px 1fr 100px 140px 140px 36px;gap:10px;padding:12px 16px}
}
@media(max-width:768px){
    .sc-overview{grid-template-columns:1fr}
    .sc-auto-row{grid-template-columns:1fr;gap:8px}
    .sc-auto-icon{display:none}
    .sc-auto-last{text-align:left}
    .sc-auto-desc{white-space:normal}
    .sc-hist-h{flex-wrap:wrap}
    .sc-hist-filters{flex-wrap:wrap}
}
</style>

<!-- === Genel Bakış === -->
<div class="sc-overview">
    <div class="sc-ov">
        <div class="sc-ov-icon" style="background:rgb(var(--brand) / .08);color:var(--primary)"><i data-lucide="activity"></i></div>
        <div class="sc-ov-info">
            <div class="sc-ov-val" style="color:var(--primary)"><?= $totalLogs ?></div>
            <div class="sc-ov-label">Toplam İşlem</div>
        </div>
        <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--primary);border-radius:0 0 14px 14px"></div>
    </div>
    <div class="sc-ov">
        <div class="sc-ov-icon" style="background:rgba(16,185,129,.08);color:#10b981"><i data-lucide="check-circle-2"></i></div>
        <div class="sc-ov-info">
            <div class="sc-ov-val" style="color:#10b981"><?= $successLogs ?></div>
            <div class="sc-ov-label">Başarılı</div>
        </div>
        <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:#10b981;border-radius:0 0 14px 14px"></div>
    </div>
    <div class="sc-ov">
        <div class="sc-ov-icon" style="background:rgba(239,68,68,.08);color:#ef4444"><i data-lucide="x-circle"></i></div>
        <div class="sc-ov-info">
            <div class="sc-ov-val" style="color:<?= $failedLogs > 0 ? '#ef4444' : 'var(--text-muted)' ?>"><?= $failedLogs ?></div>
            <div class="sc-ov-label">Başarısız</div>
        </div>
        <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:#ef4444;border-radius:0 0 14px 14px"></div>
    </div>
    <div class="sc-ov">
        <div class="sc-ov-icon" style="background:rgba(139,92,246,.08);color:var(--primary)"><i data-lucide="layers"></i></div>
        <div class="sc-ov-info">
            <div class="sc-ov-val" style="color:var(--primary)"><?= number_format($totalProcessed) ?></div>
            <div class="sc-ov-label">İşlenen Kayıt</div>
        </div>
        <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--primary);border-radius:0 0 14px 14px"></div>
    </div>
</div>

<!-- === Pazaryeri Durumları === -->
<div class="sc-mp-row" role="list" aria-label="Pazaryeri bağlantı durumları">
    <?php foreach ($marketplaces as $mp):
        $isOn = (bool)($mp['is_active'] ?? 0);
        $mpSlug = (string) ($mp['slug'] ?? '');
        $mpName = (string) ($mp['display_name'] ?? $mpSlug);
        $logoFile = '';
        if (preg_match('/^[a-z0-9_-]+$/i', $mpSlug)) {
            foreach (['png','jpg','svg'] as $ext) {
                if (file_exists(BASE_PATH . "/public/assets/img/marketplaces/{$mpSlug}.{$ext}")) {
                    $logoFile = "/assets/img/marketplaces/{$mpSlug}.{$ext}";
                    break;
                }
            }
        }
        $mpState = $isOn ? 'Bağlı' : 'Pasif';
    ?>
    <div class="sc-mp" role="listitem" title="<?= $e($mpName) ?> — <?= $mpState ?>">
        <?php if ($logoFile): ?>
        <img class="sc-mp-logo" src="<?= $e($logoFile) ?>" alt="<?= $e($mpName) ?>">
        <?php else: ?>
        <span class="sc-mp-name"><?= $e($mpName) ?></span>
        <?php endif; ?>
        <span class="sc-mp-dot <?= $isOn ? 'on' : 'off' ?>" aria-hidden="true"></span>
        <span class="sc-sr-only"><?= $mpState ?></span>
    </div>
    <?php endforeach; ?>
    <?php if (empty($marketplaces)): ?>
    <div class="sc-mp sc-mp-empty" role="listitem">
        <i data-lucide="alert-circle" aria-hidden="true"></i>
        <span>Henüz aktif pazaryeri yok</span>
    </div>
    <?php endif; ?>
</div>

<!-- === Otomatik Senkronizasyon Ayarları (mağazaya özel) === -->
<section class="sc-auto" aria-labelledby="scAutoTitle">
    <div class="sc-auto-h">
        <div class="sc-auto-h-icon" aria-hidden="true"><i data-lucide="refresh-cw"></i></div>
        <div class="sc-auto-h-text">
            <h2 class="sc-auto-h-title" id="scAutoTitle">Otomatik Senkronizasyon</h2>
            <div class="sc-auto-h-sub">Stok ve fiyat sistemden pazaryerine gönderilir. XML verisinin güncellenme sıklığı, XML profilinden ayrıca ayarlanır.</div>
        </div>
        <span class="sc-auto-note">
            <i data-lucide="info" aria-hidden="true"></i> Otomatik çalışma için sunucu zamanlayıcısı ve işleyici etkin olmalıdır
        </span>
    </div>
    <div class="sc-auto-body">
        <?php foreach ($syncTypes as $st):
            $typeKey = $st['key'];
            $enabledKey = "sync_{$typeKey}_enabled";
            $intervalKey = "sync_{$typeKey}_interval";
            $isEnabled = (bool)($syncConfig[$enabledKey] ?? true);
            $currentInterval = (int)($syncConfig[$intervalKey] ?? 15);
            $isPush = $st['dir'] === 'push';
            $isHalted = !empty($platformDisabled[$typeKey]);

            // Son çalışma bilgisi
            $last = $lastByType[$typeKey] ?? $lastByType[$typeKey.'_pull'] ?? $lastByType[$typeKey.'_push'] ?? ($typeKey === 'price' ? ($lastByType['prices'] ?? null) : null);
            $lastTime = $last ? date('d.m.Y H:i', strtotime($last['finished_at'] ?? 'now')) : '—';
            $lastStatusRaw = $last['status'] ?? '';
            $lastStatusTr = $statusMap[$lastStatusRaw] ?? '—';
            $isOk = $lastStatusRaw === 'success';
            $isFail = $lastStatusRaw === 'failed';
            $descId = 'scDesc-' . $typeKey;
        ?>
        <div class="sc-auto-row <?= $isEnabled ? '' : 'disabled' ?>" data-type="<?= $e($typeKey) ?>">
            <div class="sc-auto-icon" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>" aria-hidden="true">
                <i data-lucide="<?= $e($st['icon']) ?>"></i>
            </div>

            <div class="sc-auto-info">
                <div class="sc-auto-name">
                    <?= $e($st['label']) ?>
                    <span class="dir-badge <?= $isPush ? 'push' : 'pull' ?>"><?= $isPush ? '↑ Gönder' : '↓ Çek' ?></span>
                    <?php if ($isHalted): ?>
                    <span class="dir-badge halt">Platformda durduruldu</span>
                    <?php endif; ?>
                </div>
                <?php if ($isHalted): ?>
                <div class="sc-auto-desc warn" id="<?= $descId ?>">Bu senkron platform genelinde kapalı; yalnız platform yöneticisi açabilir. Ayarınız saklanır, açıldığında uygulanır.</div>
                <?php else: ?>
                <div class="sc-auto-desc" id="<?= $descId ?>"><?= $e($st['desc']) ?></div>
                <?php endif; ?>
            </div>

            <label class="sc-toggle">
                <input type="checkbox" role="switch" <?= $isEnabled ? 'checked' : '' ?>
                       aria-label="<?= $e($st['label']) ?> otomatik senkronizasyonu"
                       aria-describedby="<?= $descId ?>"
                       onchange="toggleSync('<?= $e($typeKey) ?>', this)">
                <span class="sc-toggle-track" aria-hidden="true"></span>
            </label>

            <div class="sc-auto-interval">
                <select aria-label="<?= $e($st['label']) ?> çalışma sıklığı"
                        onchange="changeInterval('<?= $e($typeKey) ?>', this)" <?= $isEnabled ? '' : 'disabled' ?>>
                    <?php foreach ($intervalOptions as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $currentInterval === $val ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sc-auto-last">
                <?php $autoTime = $automaticByType[$typeKey] ?? $automaticByType[$typeKey.'_pull'] ?? $automaticByType[$typeKey.'_push'] ?? ($typeKey === 'price' ? ($automaticByType['prices'] ?? null) : null); ?>
                <div class="sc-auto-last-status <?= $autoTime ? 'ok' : 'na' ?>"><?= $autoTime ? 'Son otomatik: '.$e(date('d.m.Y H:i', strtotime($autoTime))) : 'Otomatik çalışma doğrulanmadı' ?></div>
                <div class="sc-auto-last-time"><span class="sc-sr-only">Son çalışma: </span><?= $e($lastTime) ?></div>
                <div class="sc-auto-last-status <?= $isOk ? 'ok' : ($isFail ? 'err' : 'na') ?>"><?= $e($lastStatusTr) ?></div>
            </div>

            <button type="button" class="sc-auto-run" title="Şimdi çalıştır"
                    aria-label="<?= $e($st['label']) ?> senkronizasyonunu şimdi çalıştır"
                    onclick="runSync(this)"
                    data-url="/sync/<?= $e($typeKey) ?>">
                <i data-lucide="play" aria-hidden="true"></i>
                <span class="sc-spin" aria-hidden="true"></span>
            </button>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($hasAutoStock): ?>
<!-- === Otomatik stok yayını (sipariş/iade sonrası anlık gönderim) === -->
<section class="sc-stockcast" aria-labelledby="scStockcastTitle">
    <div class="sc-stockcast-icon" aria-hidden="true"><i data-lucide="send"></i></div>
    <div class="sc-stockcast-info">
        <h2 class="sc-stockcast-title" id="scStockcastTitle">Otomatik stok yayını (sipariş/iade sonrası)</h2>
        <p class="sc-stockcast-desc" id="scStockcastDesc">
            Açıkken sipariş, iade veya elle stok değişikliğinden hemen sonra güncel stok pazaryerlerine gönderilir.
            Kapatırsanız bu anlık gönderimler durur; yukarıdaki zamanlanmış "Stok" senkronu ayarına göre çalışmaya devam eder.
        </p>
    </div>
    <div class="sc-stockcast-ctrl">
        <label class="sc-toggle">
            <input type="checkbox" role="switch" id="scAutoStock" <?= $autoStockOn ? 'checked' : '' ?>
                   aria-labelledby="scStockcastTitle" aria-describedby="scStockcastDesc"
                   onchange="toggleAutoStock(this)">
            <span class="sc-toggle-track" aria-hidden="true"></span>
        </label>
        <span class="sc-stockcast-state <?= $autoStockOn ? 'on' : 'off' ?>" id="scAutoStockState" aria-live="polite"><?= $autoStockOn ? 'Açık' : 'Kapalı' ?></span>
    </div>
</section>
<?php endif; ?>

<!-- === Senkronizasyon Geçmişi === -->
<section class="sc-history" aria-labelledby="scHistTitle">
    <div class="sc-hist-h">
        <div class="sc-hist-h-icon" aria-hidden="true"><i data-lucide="history"></i></div>
        <h2 class="sc-hist-h-title" id="scHistTitle">Senkronizasyon Geçmişi</h2>
        <div class="sc-hist-filters" role="group" aria-label="Geçmişi filtrele">
            <button type="button" class="sc-hist-f active" aria-pressed="true" onclick="filterHistory('all',this)">Tümü</button>
            <button type="button" class="sc-hist-f" aria-pressed="false" onclick="filterHistory('success',this)">Başarılı</button>
            <button type="button" class="sc-hist-f" aria-pressed="false" onclick="filterHistory('failed',this)">Başarısız</button>
        </div>
    </div>
    <div class="sc-hist-body">
        <table>
            <thead>
                <tr>
                    <th scope="col">TARİH</th>
                    <th scope="col">PAZARYERİ</th>
                    <th scope="col">TÜR</th>
                    <th scope="col">DURUM</th>
                    <th scope="col" class="r">İŞLENEN</th>
                    <th scope="col" class="r">BAŞARILI</th>
                    <th scope="col" class="r">HATALI</th>
                    <th scope="col">TETİKLEME</th>
                </tr>
            </thead>
            <tbody id="syncHistBody">
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8">
                        <div class="sc-empty">
                            <i data-lucide="inbox" aria-hidden="true"></i>
                            <p>Henüz senkronizasyon geçmişi yok. Otomatik senkron çalıştıkça ya da yukarıdan elle başlattığınızda kayıtlar burada görünür.</p>
                        </div>
                    </td>
                </tr>
                <?php else: foreach ($logs as $log):
                    $s = (string) ($log['status'] ?? '');
                    $sTr = $statusMap[$s] ?? $s;
                    $sClass = match($s) { 'success'=>'sc-badge-success','failed'=>'sc-badge-danger','partial'=>'sc-badge-warning','running'=>'sc-badge-info',default=>'sc-badge-muted' };
                    $sIcon = match($s) { 'success'=>'check-circle-2','failed'=>'x-circle','partial'=>'alert-triangle','running'=>'loader',default=>'minus-circle' };
                    $tRaw = $log['sync_type'] ?? '';
                    $tTr = $typeMap[$tRaw] ?? $tRaw;
                    $trigRaw = $log['triggered_by'] ?? '';
                    $trigTr = $triggerMap[$trigRaw] ?? $trigRaw;
                    $trigIcon = match($trigRaw) { 'cron'=>'clock','manual'=>'user','system'=>'cpu',default=>'zap' };
                    $fetched = (int)($log['records_fetched'] ?? 0);
                    $processed = (int)($log['records_processed'] ?? 0);
                    $failed = (int)($log['records_failed'] ?? 0);
                ?>
                <tr data-status="<?= $e($s) ?>">
                    <td class="date"><?= date('d.m.Y H:i', strtotime($log['started_at'] ?? 'now')) ?></td>
                    <td class="mp"><?= $e($log['marketplace_name'] ?? '') ?></td>
                    <td><span class="sc-badge sc-badge-type"><?= $e($tTr) ?></span></td>
                    <td><span class="sc-badge <?= $sClass ?>"><i data-lucide="<?= $sIcon ?>" aria-hidden="true"></i> <?= $e($sTr) ?></span></td>
                    <td class="r"><?= $fetched ?></td>
                    <td class="r ok"><?= $processed ?></td>
                    <td class="r <?= $failed > 0 ? 'err' : 'muted' ?>"><?= $failed ?></td>
                    <td><span class="sc-trigger"><i data-lucide="<?= $trigIcon ?>" aria-hidden="true"></i> <?= $e($trigTr) ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function(){
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function notify(message, type, duration) {
        if (window.Toast) { Toast.show(message, type, duration); }
    }

    /**
     * Mağaza ayarını kaydet (AJAX). Başarıda true döner.
     */
    function saveSetting(key, value) {
        const body = {};
        body[key] = value;

        return fetch('/sync/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF
            },
            body: JSON.stringify(body),
        })
        .then(r => r.json().catch(() => ({ success: false })))
        .then(d => {
            if (d.success) {
                notify(d.message || 'Ayar güncellendi', 'success');
                return true;
            }
            notify(d.message || 'Ayar kaydedilemedi', 'error');
            return false;
        })
        .catch(() => {
            notify('Bağlantı hatası, ayar kaydedilemedi', 'error');
            return false;
        });
    }

    function applyRowState(row, enabled) {
        if (!row) return;
        row.classList.toggle('disabled', !enabled);
        const select = row.querySelector('select');
        if (select) select.disabled = !enabled;
    }

    /**
     * Otomatik senkron anahtarı (mağaza). Kaydedilemezse eski duruma döner.
     */
    window.toggleSync = function(type, input) {
        const row = input.closest('.sc-auto-row');
        const enabled = input.checked;
        applyRowState(row, enabled);
        input.disabled = true;

        saveSetting(`sync_${type}_enabled`, enabled ? 1 : 0).then(ok => {
            input.disabled = false;
            if (!ok) {
                input.checked = !enabled;
                applyRowState(row, !enabled);
            }
        });
    };

    /**
     * Sıklık değiştir. Kaydedilemezse önceki değere döner.
     */
    window.changeInterval = function(type, select) {
        const previous = select.dataset.current || select.querySelector('option[selected]')?.value || select.value;
        const value = parseInt(select.value, 10);
        saveSetting(`sync_${type}_interval`, value).then(ok => {
            if (ok) {
                select.dataset.current = String(value);
            } else {
                select.value = previous;
            }
        });
    };

    /**
     * Otomatik stok yayını anahtarı (sipariş/iade sonrası anlık stok gönderimi).
     */
    window.toggleAutoStock = function(input) {
        const state = document.getElementById('scAutoStockState');
        const enabled = input.checked;
        const render = (on) => {
            if (!state) return;
            state.textContent = on ? 'Açık' : 'Kapalı';
            state.classList.toggle('on', on);
            state.classList.toggle('off', !on);
        };
        render(enabled);
        input.disabled = true;

        saveSetting('auto_stock_broadcast', enabled ? 1 : 0).then(ok => {
            input.disabled = false;
            if (!ok) {
                input.checked = !enabled;
                render(!enabled);
            }
        });
    };

    /**
     * Elle senkron çalıştır (otomatik anahtar kapalı olsa da çalışır).
     */
    window.runSync = function(btn) {
        if (btn.classList.contains('loading')) return;
        btn.classList.add('loading');
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');

        // 5 dakikalık timeout — ürün import uzun sürebilir
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 300000);
        const finish = () => {
            btn.classList.remove('loading');
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        };

        fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF
            },
            signal: controller.signal,
        })
        .then(r => r.text().then(text => {
            clearTimeout(timeoutId);
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Sync yanıtı çözümlenemedi:', text.substring(0, 500));
            }
            if (!r.ok && !(data && data.message)) {
                throw new Error('Sunucu hatası (HTTP ' + r.status + ')');
            }
            if (!data) {
                throw new Error('Sunucu geçersiz yanıt döndü. Lütfen sayfayı yenileyip tekrar deneyin.');
            }
            return data;
        }))
        .then(d => {
            finish();
            if (d.success) {
                notify(d.message || 'Senkronizasyon tamamlandı', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                notify(d.message || 'Senkronizasyon hatası', 'error', 5000);
            }
        })
        .catch((err) => {
            clearTimeout(timeoutId);
            console.error('Sync error:', err);
            finish();
            if (err.name === 'AbortError') {
                notify('İstek zaman aşımına uğradı. Senkronizasyon arka planda devam ediyor olabilir.', 'warning', 8000);
            } else {
                notify(err.message || 'Bağlantı hatası', 'error', 5000);
            }
        });
    };

    /**
     * Geçmiş filtrele
     */
    window.filterHistory = function(status, btn) {
        document.querySelectorAll('.sc-hist-f').forEach(f => {
            f.classList.remove('active');
            f.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-pressed', 'true');
        document.querySelectorAll('#syncHistBody tr[data-status]').forEach(tr => {
            if (status === 'all') { tr.hidden = false; return; }
            const s = tr.dataset.status || '';
            tr.hidden = status === 'success' ? s !== 'success' : !(s === 'failed' || s === 'partial');
        });
    };

    if (typeof lucide !== 'undefined') lucide.createIcons();
})();
</script>
