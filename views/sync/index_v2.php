<?php
$logs = $data['logs'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$lastSuccessful = $data['lastSuccessful'] ?? [];
$cronToken = $data['cronToken'] ?? '';
$syncConfig = $data['syncConfig'] ?? [];
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cronBase = $baseUrl . '/cron';

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
    'price_push'    => 'Fiyat',
    'returns'       => 'İade',
    'returns_pull'  => 'İade',
    'questions'     => 'Soru',
    'questions_pull'=> 'Soru',
    'import_delta'  => 'Delta İçe Aktarma',
];
$triggerMap = [
    'manual' => 'Manuel',
    'cron'   => 'Otomatik',
    'system' => 'Sistem',
];

// Sync tipleri — yeni tasarım
$syncTypes = [
    ['key'=>'orders',    'label'=>'Siparişler',  'icon'=>'shopping-bag', 'color'=>'var(--primary)', 'dir'=>'pull',  'desc'=>'Yeni siparişleri pazaryerlerinden çeker'],
    ['key'=>'returns',   'label'=>'İadeler',     'icon'=>'rotate-ccw',   'color'=>'#f43f5e', 'dir'=>'pull',  'desc'=>'İade taleplerini pazaryerlerinden çeker'],
    ['key'=>'questions', 'label'=>'Sorular',     'icon'=>'message-circle','color'=>'var(--primary)', 'dir'=>'pull',  'desc'=>'Müşteri sorularını pazaryerlerinden çeker'],
    ['key'=>'stock',     'label'=>'Stok',        'icon'=>'package',      'color'=>'#10b981', 'dir'=>'push',  'desc'=>'Sistem stoklarını pazaryerlerine gönderir'],
    ['key'=>'price',     'label'=>'Fiyat',       'icon'=>'turkish-lira', 'color'=>'#f59e0b', 'dir'=>'push',  'desc'=>'Sistem fiyatlarını pazaryerlerine gönderir'],
    ['key'=>'products',  'label'=>'Ürünler',     'icon'=>'layers',       'color'=>'#0ea5e9', 'dir'=>'pull',  'desc'=>'Ürün verilerini pazaryerlerinden günceller'],
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
.sc-mp-row{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.sc-mp{
    display:flex;align-items:center;gap:8px;padding:10px 16px;
    background:var(--bg-card);border:1px solid var(--border);border-radius:10px;
    font-size:13px;font-weight:700;color:var(--text-primary);transition:all .15s;
}
.sc-mp:hover{border-color:var(--primary);box-shadow:0 0 0 1px var(--border)}
.sc-mp-logo{width:22px;height:22px;object-fit:contain;border-radius:4px;flex-shrink:0}
.sc-mp-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.sc-mp-dot.on{background:#10b981;box-shadow:0 0 0 1px var(--border);animation:pulse-dot 2s infinite}
.sc-mp-dot.off{background:var(--text-muted)}
.sc-mp-status{font-size:10px;font-weight:500;padding:2px 8px;border-radius:6px}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.5}}

/* === Sync Automation Panel === */
.sc-auto{
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    overflow:hidden;margin-bottom:24px;
}
.sc-auto-h{
    padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input);
    display:flex;align-items:center;gap:10px;
}
.sc-auto-h-icon{
    width:36px;height:36px;border-radius:10px;background:rgb(var(--brand) / .08);
    display:flex;align-items:center;justify-content:center;color:var(--primary);
}
.sc-auto-h-icon [data-lucide]{width:18px;height:18px}
.sc-auto-h-title{font-size:15px;font-weight:700;color:var(--text-primary);flex:1}
.sc-auto-h-sub{font-size:11px;color:var(--text-muted);font-weight:500}

.sc-auto-body{padding:0}
.sc-auto-row{
    display:grid;grid-template-columns:44px 1fr 120px 180px 180px 44px;
    align-items:center;gap:14px;padding:14px 20px;
    border-bottom:1px solid var(--border);transition:background .1s;
}
.sc-auto-row:last-child{border-bottom:none}
.sc-auto-row:hover{background:rgb(var(--brand) / .015)}
.sc-auto-row.disabled{opacity:.45}

/* Row icon */
.sc-auto-icon{
    width:40px;height:40px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.sc-auto-icon [data-lucide]{width:18px;height:18px}

/* Info column */
.sc-auto-info{display:flex;flex-direction:column;gap:2px;min-width:0}
.sc-auto-name{font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.sc-auto-name .dir-badge{
    font-size:9px;font-weight:700;padding:2px 7px;border-radius:5px;
    text-transform:uppercase;letter-spacing:.3px;
}
.sc-auto-desc{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

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
.sc-toggle{position:relative;width:42px;height:24px;flex-shrink:0}
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
.sc-hist-h-title{font-size:14px;font-weight:700;color:var(--text-primary);flex:1}
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
.sc-hist-body tbody td.r{text-align:right}

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

/* === Cron Info (Collapsible) === */
.sc-cron-toggle{
    display:flex;align-items:center;gap:6px;
    font-size:11px;font-weight:600;color:var(--text-muted);
    cursor:pointer;padding:4px 10px;border:1px solid var(--border);
    border-radius:8px;background:none;transition:all .12s;font-family:inherit;
}
.sc-cron-toggle:hover{border-color:var(--primary);color:var(--primary)}
.sc-cron-toggle [data-lucide]{width:12px;height:12px}
.sc-cron-panel{
    margin-top:12px;padding:14px 20px;
    background:var(--bg-input);border:1px solid var(--border);border-radius:10px;
    display:none;
}
.sc-cron-panel.open{display:block}
.sc-cron-list{display:flex;flex-direction:column;gap:6px}
.sc-cron-item{
    display:flex;align-items:center;gap:10px;padding:8px 12px;
    background:var(--bg-card);border:1px solid var(--border);border-radius:8px;
    font-size:11px;
}
.sc-cron-label{font-weight:700;min-width:70px;color:var(--text-primary)}
.sc-cron-url{flex:1;font-family:'Fira Code',monospace;font-size:10px;color:var(--text-muted);word-break:break-all}
.sc-cron-copy{
    padding:3px 10px;border:1px solid var(--border);border-radius:6px;
    background:none;color:var(--text-muted);font-size:10px;font-weight:700;
    cursor:pointer;transition:all .12s;font-family:inherit;white-space:nowrap;
}
.sc-cron-copy:hover{border-color:var(--primary);color:var(--primary)}
.sc-cron-copy.copied{border-color:#10b981;color:#10b981}

/* === Empty === */
.sc-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.sc-empty [data-lucide]{width:40px;height:40px;margin:0 auto 12px;display:block;opacity:.3}
.sc-empty p{font-size:13px;font-weight:500}

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

/* === Responsive === */
@media(max-width:1200px){
    .sc-overview{grid-template-columns:repeat(2,1fr)}
    .sc-auto-row{grid-template-columns:40px 1fr 100px 140px 140px 36px;gap:10px;padding:12px 16px}
}
@media(max-width:768px){
    .sc-overview{grid-template-columns:1fr}
    .sc-auto-row{grid-template-columns:1fr;gap:8px}
    .sc-auto-icon{display:none}
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
<div class="sc-mp-row">
    <?php foreach ($marketplaces as $mp):
        $isOn = (bool)($mp['is_active'] ?? 0);
        $mpSlug = $mp['slug'] ?? '';
        $logoFile = '';
        foreach (['svg','png','jpg'] as $ext) {
            if (file_exists(BASE_PATH . "/public/assets/img/marketplaces/{$mpSlug}.{$ext}")) {
                $logoFile = "/assets/img/marketplaces/{$mpSlug}.{$ext}";
                break;
            }
        }
    ?>
    <div class="sc-mp">
        <?php if ($logoFile): ?>
        <img class="sc-mp-logo" src="<?= $logoFile ?>" alt="<?= htmlspecialchars($mp['display_name'] ?? '') ?>">
        <?php endif; ?>
        <div class="sc-mp-dot <?= $isOn ? 'on' : 'off' ?>"></div>
        <span><?= htmlspecialchars($mp['display_name'] ?? '') ?></span>
        <span class="sc-mp-status" style="background:<?= $isOn ? 'rgba(16,185,129,.08)' : 'var(--bg-input)' ?>;color:<?= $isOn ? '#10b981' : 'var(--text-muted)' ?>">
            <?= $isOn ? 'Bağlı' : 'Pasif' ?>
        </span>
    </div>
    <?php endforeach; ?>
    <?php if (empty($marketplaces)): ?>
    <div class="sc-mp" style="color:var(--text-muted)">
        <i data-lucide="alert-circle" style="width:14px;height:14px;opacity:.5"></i>
        <span>Henüz aktif pazaryeri yok</span>
    </div>
    <?php endif; ?>
</div>

<!-- === Otomatik Senkronizasyon Ayarları === -->
<div class="sc-auto">
    <div class="sc-auto-h">
        <div class="sc-auto-h-icon"><i data-lucide="refresh-cw"></i></div>
        <div>
            <span class="sc-auto-h-title">Otomatik Senkronizasyon</span>
            <div class="sc-auto-h-sub">Aktif/pasif ve çalışma sıklığını ayarlayın</div>
        </div>
        <button class="sc-cron-toggle" onclick="toggleCronPanel()">
            <i data-lucide="terminal"></i> Cron URL'leri
        </button>
    </div>
    <div class="sc-auto-body">
        <?php foreach ($syncTypes as $i => $st):
            $enabledKey = "sync_{$st['key']}_enabled";
            $intervalKey = "sync_{$st['key']}_interval";
            $isEnabled = (bool)($syncConfig[$enabledKey] ?? 1);
            $currentInterval = (int)($syncConfig[$intervalKey] ?? 15);
            $isPush = $st['dir'] === 'push';

            // Son çalışma bilgisi
            $last = $lastByType[$st['key']] ?? $lastByType[$st['key'].'_pull'] ?? $lastByType[$st['key'].'_push'] ?? null;
            $lastTime = $last ? date('d.m.Y H:i', strtotime($last['finished_at'] ?? 'now')) : '—';
            $lastStatusRaw = $last['status'] ?? '';
            $lastStatusTr = $statusMap[$lastStatusRaw] ?? '—';
            $isOk = $lastStatusRaw === 'success';
            $isFail = $lastStatusRaw === 'failed';

            // Buton URL'i (Stok+Fiyat → push, diğerleri → pull)
            $syncBtnUrl = "/sync/{$st['key']}";
        ?>
        <div class="sc-auto-row <?= $isEnabled ? '' : 'disabled' ?>" data-type="<?= $st['key'] ?>">
            <!-- Icon -->
            <div class="sc-auto-icon" style="background:<?= $st['color'] ?>10;color:<?= $st['color'] ?>">
                <i data-lucide="<?= $st['icon'] ?>"></i>
            </div>

            <!-- Info -->
            <div class="sc-auto-info">
                <div class="sc-auto-name">
                    <?= $st['label'] ?>
                    <span class="dir-badge" style="background:<?= $isPush ? 'rgba(16,185,129,.08);color:#10b981' : 'rgb(var(--brand) / .08);color:var(--primary)' ?>">
                        <?= $isPush ? '↑ Gönder' : '↓ Çek' ?>
                    </span>
                </div>
                <div class="sc-auto-desc"><?= $st['desc'] ?></div>
            </div>

            <!-- Toggle -->
            <label class="sc-toggle">
                <input type="checkbox" <?= $isEnabled ? 'checked' : '' ?>
                       onchange="toggleSync('<?= $st['key'] ?>', this.checked)">
                <div class="sc-toggle-track"></div>
            </label>

            <!-- Interval -->
            <div class="sc-auto-interval">
                <select onchange="changeInterval('<?= $st['key'] ?>', this.value)" <?= $isEnabled ? '' : 'disabled' ?>>
                    <?php foreach ($intervalOptions as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $currentInterval === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Last Run -->
            <div class="sc-auto-last">
                <div class="sc-auto-last-time"><?= $lastTime ?></div>
                <div class="sc-auto-last-status <?= $isOk ? 'ok' : ($isFail ? 'err' : 'na') ?>"><?= $lastStatusTr ?></div>
            </div>

            <!-- Manual Run -->
            <button class="sc-auto-run" title="Şimdi çalıştır"
                    onclick="runSync('<?= $st['key'] ?>', this)"
                    data-url="<?= $syncBtnUrl ?>"
                    <?= $isEnabled ? '' : 'disabled' ?>>
                <i data-lucide="play"></i>
                <div class="sc-spin"></div>
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Cron URL Panel (gizli) -->
    <div class="sc-cron-panel" id="cronPanel">
        <div class="sc-cron-list">
            <?php
            $cronTypes = [
                ['key'=>'sync-orders',   'label'=>'Sipariş'],
                ['key'=>'sync-returns',  'label'=>'İade'],
                ['key'=>'sync-stock',    'label'=>'Stok'],
                ['key'=>'sync-price',    'label'=>'Fiyat'],
                ['key'=>'sync-products', 'label'=>'Ürün'],
            ];
            foreach ($cronTypes as $ct):
                $url = $cronBase . '/' . $ct['key'] . '?token=' . urlencode($cronToken);
            ?>
            <div class="sc-cron-item">
                <span class="sc-cron-label"><?= $ct['label'] ?></span>
                <span class="sc-cron-url"><?= htmlspecialchars($url) ?></span>
                <button class="sc-cron-copy" onclick="copyUrl(this,'<?= htmlspecialchars($url, ENT_QUOTES) ?>')">Kopyala</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- === Senkronizasyon Geçmişi === -->
<div class="sc-history">
    <div class="sc-hist-h">
        <div class="sc-hist-h-icon"><i data-lucide="history"></i></div>
        <span class="sc-hist-h-title">Senkronizasyon Geçmişi</span>
        <div class="sc-hist-filters">
            <button class="sc-hist-f active" onclick="filterHistory('all',this)">Tümü</button>
            <button class="sc-hist-f" onclick="filterHistory('success',this)">Başarılı</button>
            <button class="sc-hist-f" onclick="filterHistory('failed',this)">Başarısız</button>
        </div>
    </div>
    <div class="sc-hist-body">
        <table>
            <thead>
                <tr>
                    <th>TARİH</th>
                    <th>PAZARYERİ</th>
                    <th>TÜR</th>
                    <th>DURUM</th>
                    <th class="r">İŞLENEN</th>
                    <th class="r">BAŞARILI</th>
                    <th class="r">HATALI</th>
                    <th>TETİKLEME</th>
                </tr>
            </thead>
            <tbody id="syncHistBody">
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="8">
                        <div class="sc-empty">
                            <i data-lucide="inbox"></i>
                            <p>Henüz senkronizasyon geçmişi yok</p>
                        </div>
                    </td>
                </tr>
                <?php else: foreach ($logs as $log):
                    $s = $log['status'] ?? '';
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
                <tr data-status="<?= $s ?>">
                    <td style="font-size:12px;font-weight:500"><?= date('d.m.Y H:i', strtotime($log['started_at'] ?? 'now')) ?></td>
                    <td style="font-weight:700"><?= htmlspecialchars($log['marketplace_name'] ?? '') ?></td>
                    <td><span class="sc-badge sc-badge-type"><?= htmlspecialchars($tTr) ?></span></td>
                    <td><span class="sc-badge <?= $sClass ?>"><i data-lucide="<?= $sIcon ?>"></i> <?= htmlspecialchars($sTr) ?></span></td>
                    <td class="r" style="font-weight:700"><?= $fetched ?></td>
                    <td class="r" style="font-weight:700;color:#10b981"><?= $processed ?></td>
                    <td class="r" style="font-weight:700;color:<?= $failed > 0 ? '#ef4444' : 'var(--text-muted)' ?>"><?= $failed ?></td>
                    <td><span class="sc-trigger"><i data-lucide="<?= $trigIcon ?>"></i> <?= htmlspecialchars($trigTr) ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function(){
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    /**
     * Toggle (Açık/Kapalı)
     */
    window.toggleSync = function(type, enabled) {
        const row = document.querySelector(`.sc-auto-row[data-type="${type}"]`);
        if (row) {
            row.classList.toggle('disabled', !enabled);
            const select = row.querySelector('select');
            const runBtn = row.querySelector('.sc-auto-run');
            if (select) select.disabled = !enabled;
            if (runBtn) runBtn.disabled = !enabled;
        }

        saveSetting(`sync_${type}_enabled`, enabled ? 1 : 0);
    };

    /**
     * Sıklık değiştir
     */
    window.changeInterval = function(type, value) {
        saveSetting(`sync_${type}_interval`, parseInt(value));
    };

    /**
     * Ayarı kaydet (AJAX)
     */
    function saveSetting(key, value) {
        const body = {};
        body[key] = value;

        fetch('/sync/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF
            },
            body: JSON.stringify(body),
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                Toast.show('Ayar güncellendi', 'success');
            } else {
                Toast.show(d.message || 'Ayar kaydedilemedi', 'error');
            }
        })
        .catch(() => Toast.show('Bağlantı hatası', 'error'));
    }

    /**
     * Manuel sync çalıştır
     */
    window.runSync = function(type, btn) {
        if (btn.classList.contains('loading')) return;
        btn.classList.add('loading');
        btn.disabled = true;

        fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF
            },
        })
        .then(r => r.json())
        .then(d => {
            btn.classList.remove('loading');
            btn.disabled = false;
            if (d.success) {
                Toast.show(d.message || 'Senkronizasyon tamamlandı', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                Toast.show(d.message || 'Senkronizasyon hatası', 'error');
            }
        })
        .catch(() => {
            btn.classList.remove('loading');
            btn.disabled = false;
            Toast.show('Bağlantı hatası', 'error');
        });
    };

    /**
     * Cron URL kopyala
     */
    window.copyUrl = function(btn, url) {
        navigator.clipboard.writeText(url).then(() => {
            btn.classList.add('copied');
            btn.textContent = 'Kopyalandı';
            setTimeout(() => {
                btn.classList.remove('copied');
                btn.textContent = 'Kopyala';
            }, 2000);
        });
    };

    /**
     * Cron panel aç/kapa
     */
    window.toggleCronPanel = function() {
        document.getElementById('cronPanel')?.classList.toggle('open');
    };

    /**
     * Geçmiş filtrele
     */
    window.filterHistory = function(status, btn) {
        document.querySelectorAll('.sc-hist-f').forEach(f => f.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('#syncHistBody tr').forEach(tr => {
            if (status === 'all') { tr.style.display = ''; return; }
            const s = tr.dataset.status || '';
            if (status === 'success') {
                tr.style.display = s === 'success' ? '' : 'none';
            } else {
                tr.style.display = (s === 'failed' || s === 'partial') ? '' : 'none';
            }
        });
    };

    if (typeof lucide !== 'undefined') lucide.createIcons();
})();
</script>
