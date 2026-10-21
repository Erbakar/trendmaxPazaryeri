<?php
$logs = $data['logs'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$lastSuccessful = $data['lastSuccessful'] ?? [];
$cronToken = $data['cronToken'] ?? '';
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cronBase = $baseUrl . '/cron';

// Son başarılı sync'leri türe göre grupla
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
    'import_delta'  => 'Delta İçe Aktarma',
];
$triggerMap = [
    'manual' => 'Manuel',
    'cron'   => 'Otomatik',
    'system' => 'Sistem',
];

// Sync tipleri
$syncTypes = [
    ['key'=>'orders',   'label'=>'Sipariş',  'icon'=>'shopping-bag',   'desc'=>'Pazaryerlerinden yeni siparişleri çek',    'color'=>'var(--primary)', 'direction'=>'Çek',    'dirIcon'=>'download'],
    ['key'=>'products', 'label'=>'Ürün',     'icon'=>'package',        'desc'=>'Pazaryerlerinden ürün verilerini güncelle', 'color'=>'var(--primary)', 'direction'=>'Çek',    'dirIcon'=>'download'],
    ['key'=>'stock',    'label'=>'Stok',     'icon'=>'database',       'desc'=>'Sistem stoklarını pazaryerlerine gönder',   'color'=>'#10b981', 'direction'=>'Gönder', 'dirIcon'=>'upload'],
    ['key'=>'price',    'label'=>'Fiyat',    'icon'=>'turkish-lira',   'desc'=>'Sistem fiyatlarını pazaryerlerine gönder',  'color'=>'#f59e0b', 'direction'=>'Gönder', 'dirIcon'=>'upload'],
];

// İstatistikler
$totalLogs = count($logs);
$successLogs = count(array_filter($logs, fn($l) => ($l['status'] ?? '') === 'success'));
$failedLogs = count(array_filter($logs, fn($l) => in_array($l['status'] ?? '', ['failed','partial'])));
$totalProcessed = array_sum(array_map(fn($l) => (int)($l['records_processed'] ?? 0), $logs));
?>

<style>
/* === SYNC PAGE — Premium Design === */

/* === Overview Stats === */
.sc-overview{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.sc-ov{
    display:flex;align-items:center;gap:14px;padding:18px 20px;
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    position:relative;overflow:hidden;transition:all .2s;
}
.sc-ov:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border)}
.sc-ov::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px}
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

/* === Sync Cards === */
.sc-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.sc-card{
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    padding:0;display:flex;flex-direction:column;transition:all .2s;
    position:relative;overflow:hidden;
}
.sc-card:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:0 0 0 1px var(--border)}
.sc-card-bar{height:3px;width:100%;flex-shrink:0}
.sc-card-body{padding:20px;flex:1;display:flex;flex-direction:column;gap:12px}
.sc-card-top{display:flex;align-items:center;gap:10px}
.sc-card-icon{
    width:42px;height:42px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.sc-card-icon [data-lucide]{width:20px;height:20px}
.sc-card-title-wrap{flex:1}
.sc-card-title{font-size:15px;font-weight:700;color:var(--text-primary)}
.sc-card-dir{font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;display:inline-flex;align-items:center;gap:3px}
.sc-card-dir [data-lucide]{width:10px;height:10px}
.sc-card-desc{font-size:12px;color:var(--text-muted);line-height:1.5}
.sc-card-meta{
    display:grid;grid-template-columns:1fr 1fr;gap:6px;
    padding:12px;background:var(--bg-input);border-radius:10px;
}
.sc-card-m{display:flex;flex-direction:column;gap:1px}
.sc-card-m-label{font-size:10px;color:var(--text-muted);font-weight:500}
.sc-card-m-val{font-size:12px;font-weight:700;color:var(--text-primary)}
.sc-card-m-val.ok{color:#10b981}
.sc-card-m-val.err{color:#ef4444}
.sc-card-footer{padding:0 20px 20px}
.sc-card-btn{
    width:100%;padding:10px;border:none;border-radius:10px;
    font-size:13px;font-weight:700;cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:6px;
    color:#fff;transition:all .15s;font-family:inherit;
}
.sc-card-btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
.sc-card-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.sc-card-btn [data-lucide]{width:16px;height:16px}
.sc-card-btn .sc-spin{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite}
.sc-card-btn.loading .sc-spin{display:inline-block}
.sc-card-btn.loading .sc-btn-icon{display:none}

/* === Cron Section === */
.sc-cron{
    background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
    overflow:hidden;margin-bottom:24px;
}
.sc-cron-h{
    padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input);
    display:flex;align-items:center;gap:8px;
}
.sc-cron-h-icon{
    width:32px;height:32px;border-radius:8px;background:rgb(var(--brand) / .08);
    display:flex;align-items:center;justify-content:center;color:var(--primary);
}
.sc-cron-h-icon [data-lucide]{width:16px;height:16px}
.sc-cron-h-title{font-size:14px;font-weight:700;color:var(--text-primary)}
.sc-cron-h-badge{margin-left:auto;font-size:10px;padding:3px 10px;border-radius:8px;background:rgba(16,185,129,.08);color:#10b981;font-weight:700}
.sc-cron-body{padding:16px 20px}
.sc-cron-list{display:flex;flex-direction:column;gap:8px}
.sc-cron-item{
    display:flex;align-items:center;gap:10px;padding:10px 14px;
    background:var(--bg-input);border:1px solid var(--border);border-radius:10px;
    transition:all .12s;
}
.sc-cron-item:hover{border-color:var(--primary)}
.sc-cron-label{
    font-size:12px;font-weight:700;color:var(--text-primary);
    min-width:80px;display:flex;align-items:center;gap:6px;
}
.sc-cron-label [data-lucide]{width:14px;height:14px;opacity:.5}
.sc-cron-url{
    flex:1;font-family:'Fira Code',monospace;font-size:11px;color:var(--text-muted);
    word-break:break-all;line-height:1.4;
}
.sc-cron-copy{
    padding:5px 12px;border:1px solid var(--border);border-radius:8px;
    background:none;color:var(--text-muted);font-size:11px;font-weight:700;
    cursor:pointer;transition:all .12s;white-space:nowrap;font-family:inherit;
    display:flex;align-items:center;gap:4px;
}
.sc-cron-copy [data-lucide]{width:12px;height:12px}
.sc-cron-copy:hover{border-color:var(--primary);color:var(--primary);background:rgb(var(--brand) / .04)}
.sc-cron-copy.copied{border-color:#10b981;color:#10b981;background:rgba(16,185,129,.04)}
.sc-cron-hint{
    margin-top:14px;padding:12px 16px;background:rgb(var(--brand) / .04);
    border:1px solid rgb(var(--brand) / .1);border-radius:10px;
    font-size:11px;color:var(--text-muted);line-height:1.7;
}
.sc-cron-hint code{
    background:rgb(var(--brand) / .08);padding:2px 6px;border-radius:4px;
    font-size:10px;font-family:'Fira Code',monospace;color:var(--text-secondary);
}

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
.sc-card{animation:fadeIn .35s ease both}
.sc-card:nth-child(2){animation-delay:.08s}
.sc-card:nth-child(3){animation-delay:.16s}
.sc-card:nth-child(4){animation-delay:.24s}

/* === Responsive === */
@media(max-width:1200px){
    .sc-overview{grid-template-columns:repeat(2,1fr)}
    .sc-cards{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:768px){
    .sc-overview{grid-template-columns:1fr}
    .sc-cards{grid-template-columns:1fr}
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

<!-- === Sync Kartları === -->
<div class="sc-cards">
    <?php foreach ($syncTypes as $st):
        $last = $lastByType[$st['key']] ?? $lastByType[$st['key'].'_pull'] ?? $lastByType[$st['key'].'_push'] ?? null;
        $lastTime = $last ? date('d.m.Y H:i', strtotime($last['finished_at'] ?? 'now')) : '—';
        $lastStatusRaw = $last['status'] ?? '';
        $lastStatusTr = $statusMap[$lastStatusRaw] ?? '—';
        $lastProcessed = (int)($last['records_processed'] ?? 0);
        $lastFailed = (int)($last['records_failed'] ?? 0);
        $isOk = $lastStatusRaw === 'success';
    ?>
    <div class="sc-card">
        <div class="sc-card-bar" style="background:<?= $st['color'] ?>"></div>
        <div class="sc-card-body">
            <div class="sc-card-top">
                <div class="sc-card-icon" style="background:<?= $st['color'] ?>12;color:<?= $st['color'] ?>">
                    <i data-lucide="<?= $st['icon'] ?>"></i>
                </div>
                <div class="sc-card-title-wrap">
                    <div class="sc-card-title"><?= $st['label'] ?></div>
                </div>
                <span class="sc-card-dir" style="background:<?= $st['color'] ?>10;color:<?= $st['color'] ?>">
                    <i data-lucide="<?= $st['dirIcon'] ?>"></i>
                    <?= $st['direction'] ?>
                </span>
            </div>
            <div class="sc-card-desc"><?= $st['desc'] ?></div>
            <div class="sc-card-meta">
                <div class="sc-card-m">
                    <span class="sc-card-m-label">Son Çalışma</span>
                    <span class="sc-card-m-val"><?= $lastTime ?></span>
                </div>
                <div class="sc-card-m">
                    <span class="sc-card-m-label">Durum</span>
                    <span class="sc-card-m-val <?= $isOk ? 'ok' : ($lastStatusRaw === 'failed' ? 'err' : '') ?>"><?= $lastStatusTr ?></span>
                </div>
                <div class="sc-card-m">
                    <span class="sc-card-m-label">Başarılı</span>
                    <span class="sc-card-m-val ok"><?= $lastProcessed ?></span>
                </div>
                <div class="sc-card-m">
                    <span class="sc-card-m-label">Hatalı</span>
                    <span class="sc-card-m-val <?= $lastFailed > 0 ? 'err' : '' ?>"><?= $lastFailed ?></span>
                </div>
            </div>
        </div>
        <div class="sc-card-footer">
            <button class="sc-card-btn" style="background:<?= $st['color'] ?>"
                    onclick="runSync('<?= $st['key'] ?>',this)"
                    data-url="/sync/<?= $st['key'] ?>">
                <i data-lucide="<?= $st['dirIcon'] ?>" class="sc-btn-icon"></i>
                <div class="sc-spin"></div>
                <?= $st['direction'] === 'Çek' ? 'Şimdi Çek' : 'Şimdi Gönder' ?>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- === Cron URL'leri === -->
<div class="sc-cron">
    <div class="sc-cron-h">
        <div class="sc-cron-h-icon"><i data-lucide="clock"></i></div>
        <span class="sc-cron-h-title">Otomatik Senkronizasyon (Cron)</span>
        <span class="sc-cron-h-badge">4 Endpoint</span>
    </div>
    <div class="sc-cron-body">
        <div class="sc-cron-list">
            <?php
            $cronTypes = [
                ['key'=>'sync-orders',   'label'=>'Sipariş',  'icon'=>'shopping-bag'],
                ['key'=>'sync-products', 'label'=>'Ürün',     'icon'=>'package'],
                ['key'=>'sync-stock',    'label'=>'Stok',     'icon'=>'database'],
                ['key'=>'sync-price',    'label'=>'Fiyat',    'icon'=>'tag'],
            ];
            foreach ($cronTypes as $ct):
                $url = $cronBase . '/' . $ct['key'] . '?token=' . urlencode($cronToken);
            ?>
            <div class="sc-cron-item">
                <span class="sc-cron-label"><i data-lucide="<?= $ct['icon'] ?>"></i> <?= $ct['label'] ?></span>
                <span class="sc-cron-url"><?= htmlspecialchars($url) ?></span>
                <button class="sc-cron-copy" onclick="copyUrl(this,'<?= htmlspecialchars($url, ENT_QUOTES) ?>')">
                    <i data-lucide="copy"></i> Kopyala
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="sc-cron-hint">
            <strong>Kullanım:</strong> Bu URL'leri sunucunuzun cron görevlerine ekleyin.<br>
            <code>*/5 * * * * curl -s "<?= htmlspecialchars($cronBase) ?>/sync-orders?token=<?= urlencode($cronToken) ?>"</code> ↔ Her 5 dk sipariş çek<br>
            <code>*/15 * * * * curl -s "<?= htmlspecialchars($cronBase) ?>/sync-stock?token=<?= urlencode($cronToken) ?>"</code> ↔ Her 15 dk stok gönder<br>
            Token değişirse tüm cron görevlerini güncellemeniz gerekir.
        </div>
    </div>
</div>

<!-- === Sync Geçmişi === -->
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

    window.copyUrl = function(btn, url) {
        navigator.clipboard.writeText(url).then(() => {
            const icon = btn.querySelector('[data-lucide]');
            btn.classList.add('copied');
            btn.innerHTML = '<i data-lucide="check"></i> Kopyalandı';
            if (typeof lucide !== 'undefined') lucide.createIcons();
            setTimeout(() => {
                btn.classList.remove('copied');
                btn.innerHTML = '<i data-lucide="copy"></i> Kopyala';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }, 2000);
        });
    };

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
