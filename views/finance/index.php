<?php
$transactions = $data['transactions'] ?? [];
$summary = $data['summary'] ?? [];
$breakdown = $data['breakdown'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$filters = $data['filters'] ?? [];
$totalCount = $data['totalCount'] ?? 0;
$currentPage = $data['currentPage'] ?? 1;
$totalPages = $data['totalPages'] ?? 1;

$fmt = fn($v) => number_format((float)($v ?? 0), 2, ',', '.');

$typeLabels = [
    'sale'=>'Satış', 'refund'=>'İade', 'commission'=>'Komisyon',
    'payment'=>'Hakediş', 'stoppage'=>'Stopaj', 'wire_transfer'=>'Virman',
    'discount'=>'İndirim', 'coupon'=>'Kupon', 'invoice'=>'Fatura', 'other'=>'Diğer',
];
$typeBadgeColors = [
    'sale'=>['c'=>'#10b981','bg'=>'rgba(16,185,129,.08)'],
    'refund'=>['c'=>'#ef4444','bg'=>'rgba(239,68,68,.08)'],
    'commission'=>['c'=>'#f59e0b','bg'=>'rgba(245,158,11,.08)'],
    'payment'=>['c'=>'var(--primary)','bg'=>'rgb(var(--brand) / .08)'],
    'stoppage'=>['c'=>'var(--primary)','bg'=>'rgba(139,92,246,.08)'],
    'wire_transfer'=>['c'=>'#06b6d4','bg'=>'rgba(6,182,212,.08)'],
    'discount'=>['c'=>'#ec4899','bg'=>'rgba(236,72,153,.08)'],
    'coupon'=>['c'=>'#14b8a6','bg'=>'rgba(20,184,166,.08)'],
    'other'=>['c'=>'#64748b','bg'=>'rgba(100,116,139,.08)'],
];

$totalGross = (float)($summary['total_gross'] ?? 0);
$totalSales = (float)($summary['total_sales'] ?? 0);
$totalRefunds = (float)($summary['total_refunds'] ?? 0);
$totalCommission = (float)($summary['total_commission'] ?? 0);
$totalStoppage = (float)($summary['total_stoppage'] ?? 0);
$totalNet = (float)($summary['total_net'] ?? 0);
$totalPayments = (float)($summary['total_payments'] ?? 0);
$totalTxCount = (int)($summary['total_count'] ?? 0);

$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63'];
$mpBgs    = ['trendyol'=>'#fff7ed','hepsiburada'=>'#fff3ed','n11'=>'#faf5ff','amazon'=>'#fffbeb','pazarama'=>'#f0f9ff','pttavm'=>'#fffde7','ciceksepeti'=>'#fdf2f8'];
$financeSupported = ['trendyol', 'hepsiburada'];
$activeTab = $filters['marketplace_id'] ?? '';
$canMarketplace = $data['_canMarketplace'] ?? static fn(int $id): bool => true;

$mpBreakdownMap = [];
foreach ($breakdown as $b) {
    $mpBreakdownMap[(int)($b['marketplace_id'] ?? 0)] = $b;
}

?>

<style>
/* === FINANCE PAGE — Premium Design === */

/* === Hero Stats === */
.fi-hero{display:grid;grid-template-columns:1.3fr repeat(4,1fr);gap:14px;margin-bottom:24px}
.fi-hero-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:22px 24px;position:relative;overflow:hidden;transition:all .2s}
.fi-hero-card:hover{transform:translateY(-3px);box-shadow:0 0 0 1px var(--border)}
.fi-hero-bar{position:absolute;top:0;left:0;right:0;height:4px;border-radius:12px 16px 0 0}
.fi-hero-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.fi-hero-icon [data-lucide]{width:22px;height:22px}
.fi-hero-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.fi-hero-val{font-size:26px;font-weight:600;line-height:1;letter-spacing:-.5px}
.fi-hero-card:first-child .fi-hero-val{font-size:30px}
.fi-hero-sub{font-size:11px;color:var(--text-muted);margin-top:6px;display:flex;align-items:center;gap:4px}
.fi-hero-sub [data-lucide]{width:12px;height:12px}

/* === Marketplace Breakdown === */
.fi-mp-section{margin-bottom:24px}
.fi-mp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.fi-mp-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;transition:all .15s}
.fi-mp-card:hover{border-color:var(--primary);box-shadow:0 0 0 1px var(--border)}
.fi-mp-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--bg-input)}
.fi-mp-dot{width:10px;height:10px;border-radius:50%;background:var(--primary);flex-shrink:0}
.fi-mp-name{font-size:14px;font-weight:600;color:var(--text-primary)}
.fi-mp-count{margin-left:auto;font-size:10px;padding:2px 8px;border-radius:8px;background:rgb(var(--brand) / .08);color:var(--primary);font-weight:600}
.fi-mp-body{padding:12px 18px}
.fi-mp-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)}
.fi-mp-row:last-child{border-bottom:none}
.fi-mp-row-label{font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:6px}
.fi-mp-row-label [data-lucide]{width:14px;height:14px;opacity:.4}
.fi-mp-row-val{font-size:13px;font-weight:600;font-family:'Fira Code',monospace}

/* === Marketplace Tile Grid === */
.fi-mp-grid-tiles{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto;overflow-y:hidden;padding-bottom:2px}.fi-mp-grid-tiles>.fi-mp-tile{flex:1 1 0;min-width:110px}
.fi-mp-tile{
    position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:6px;padding:16px 8px 14px;border-radius:12px;cursor:pointer;
    border:1.5px solid var(--border);background:var(--bg-card);
    transition:all .18s ease;min-height:80px;overflow:hidden;text-decoration:none;
}
.fi-mp-tile:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border);border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card))}
.fi-mp-tile:active{transform:translateY(0);box-shadow:0 0 0 1px var(--border)}
.fi-mp-tile.active{border-color:var(--mp-color,var(--primary));background:var(--mp-bg,var(--bg-card));box-shadow:0 0 0 1px var(--border)}
.fi-mp-tile-logo{height:24px;width:auto;max-width:90px;object-fit:contain;opacity:.7;transition:opacity .15s}
.fi-mp-tile:hover .fi-mp-tile-logo,.fi-mp-tile.active .fi-mp-tile-logo{opacity:1}
.fi-mp-tile-count{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:22px;height:20px;border-radius:10px;font-size:11px;font-weight:600;
    color:#fff;background:var(--mp-color,var(--primary));
    padding:0 6px;line-height:1;
}
.fi-mp-tile-count.zero{background:var(--border);color:var(--text-muted);font-weight:600}
.fi-mp-tile-unsupported{
    opacity:.5;cursor:default;
}
.fi-mp-tile-unsupported:hover{transform:none;box-shadow:none;border-color:var(--border);background:var(--bg-card)}
.fi-mp-tile-unsupported .fi-mp-tile-logo{filter:grayscale(.6);opacity:.5}
.fi-mp-tile-badge-no{
    font-size:9px;font-weight:700;color:#ef4444;background:rgba(239,68,68,.08);
    padding:2px 8px;border-radius:8px;letter-spacing:.3px;white-space:nowrap;
}

/* === Üst Filtre Paneli (pazaryeri tab'leri + araç çubuğu — tek beyaz bütün) === */
.o-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-card);margin-bottom:20px;overflow:hidden}
.mp-seg{display:flex;align-items:center;gap:3px;padding:9px 10px;overflow-x:auto;border-bottom:1px solid var(--border)}
.mp-seg-tab{flex:none;display:inline-flex;align-items:center;gap:8px;height:46px;padding:0 15px;border:0;border-radius:10px;background:transparent;font-family:inherit;font-size:13px;font-weight:600;color:var(--text-muted);cursor:pointer;white-space:nowrap;transition:all .15s}
.mp-seg-tab:hover:not(.is-active):not(.is-disabled){color:var(--text-primary);background:var(--bg-input)}
.mp-seg-tab img{height:26px;width:auto;max-width:120px;object-fit:contain;display:block}
.mp-seg-ico{width:18px;height:18px;flex-shrink:0}
.mp-seg-count{display:inline-grid;place-items:center;min-width:20px;height:18px;padding:0 6px;border-radius:6px;background:rgba(15,23,42,.06);color:var(--text-muted);font-size:11px;font-weight:700;font-variant-numeric:tabular-nums}
.mp-seg-tab.is-active{background:var(--primary-bg);color:var(--primary)}
.mp-seg-tab.is-active .mp-seg-count{background:var(--primary);color:#fff}
.mp-seg-tab.is-disabled{color:var(--text-muted);cursor:not-allowed;opacity:.5}
.mp-seg-tab.is-disabled img{filter:grayscale(1);opacity:.5}
.mp-seg-lock{width:13px;height:13px;opacity:.6}

/* === N11 Warning === */
.fi-n11-warning{display:none;margin-bottom:20px;padding:18px 24px;background:rgba(123,45,150,.04);border:1px solid rgba(123,45,150,.18);border-radius:12px}
.fi-n11-warning.show{display:flex;align-items:flex-start;gap:14px}
.fi-n11-warning-icon{width:44px;height:44px;border-radius:12px;background:rgba(123,45,150,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#7b2d96}
.fi-n11-warning-icon [data-lucide]{width:22px;height:22px}
.fi-n11-warning-title{font-size:14px;font-weight:700;color:#7b2d96;margin-bottom:4px}
.fi-n11-warning-text{font-size:12px;color:var(--text-secondary);line-height:1.5}

/* === Filter Bar === */
.fi-filter{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:20px;padding:16px 20px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px}
.fi-fg{display:flex;flex-direction:column;gap:4px}
.fi-fg label{font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px}
.fi-fg input,.fi-fg select{padding:9px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit;transition:border-color .15s}
.fi-fg input:focus,.fi-fg select:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgb(var(--brand) / .08)}
.fi-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.fi-btn-primary{background:var(--primary);color:#fff;box-shadow:0 0 0 1px var(--border)}
.fi-btn-primary:hover{opacity:.9}
.fi-btn-sync{background:transparent;border:1px solid var(--primary);color:var(--primary);margin-left:auto}
.fi-btn-sync:hover{background:var(--primary);color:#fff}
.fi-btn-sync.loading{opacity:.6;pointer-events:none}
.fi-btn [data-lucide]{width:14px;height:14px}

/* === Sync Progress === */
.fi-progress{display:none;margin-bottom:20px;padding:16px 20px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px}
.fi-progress.show{display:block}
.fi-prog-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.fi-prog-label{font-size:13px;font-weight:600;color:var(--text-primary)}
.fi-prog-pct{font-size:14px;font-weight:600;color:var(--primary)}
.fi-prog-bar-wrap{height:8px;background:rgb(var(--brand) / .08);border-radius:8px;overflow:hidden}
.fi-prog-bar{height:100%;background:var(--primary);border-radius:8px;transition:width .3s ease;width:0}
.fi-prog-log{margin-top:10px;max-height:140px;overflow-y:auto;display:flex;flex-direction:column;gap:2px}
.fi-prog-log div{display:flex;justify-content:space-between;padding:4px 8px;font-size:11px;border-radius:6px;font-family:'Fira Code',monospace}
.fi-prog-log .ok{background:rgba(16,185,129,.05);color:#10b981}
.fi-prog-log .err{background:rgba(239,68,68,.05);color:#ef4444}
.fi-prog-log .skip{background:var(--bg-input);color:var(--text-muted)}

/* === Transaction List === */
.fi-txlist{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.fi-txlist-h{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:var(--bg-input)}
.fi-txlist-h-icon{width:32px;height:32px;border-radius:8px;background:rgb(var(--brand) / .08);display:flex;align-items:center;justify-content:center;color:var(--primary)}
.fi-txlist-h-icon [data-lucide]{width:16px;height:16px}
.fi-txlist-h-title{font-size:14px;font-weight:600;color:var(--text-primary);flex:1}
.fi-txlist-h-count{font-size:11px;color:var(--text-muted);font-weight:500}
.fi-pager{display:flex;align-items:center;gap:4px}
.fi-pager-btn{padding:5px 10px;border:1px solid var(--border);border-radius:8px;font-size:11px;font-weight:600;text-decoration:none;color:var(--text-muted);transition:all .12s;background:none;cursor:pointer;font-family:inherit}
.fi-pager-btn:hover{border-color:var(--primary);color:var(--primary)}
.fi-pager-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.fi-pager-info{font-size:11px;color:var(--text-muted);padding:0 6px}
.fi-txlist-body{overflow-x:auto}
.fi-txlist-body table{width:100%;border-collapse:collapse}
.fi-txlist-body thead th{padding:12px 16px;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
.fi-txlist-body thead th.r{text-align:right}
.fi-txlist-body tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.fi-txlist-body tbody tr:last-child{border-bottom:none}
.fi-txlist-body tbody tr:hover{background:rgb(var(--brand) / .02)}
.fi-txlist-body tbody td{padding:12px 16px;font-size:13px;color:var(--text-primary);white-space:nowrap}
.fi-txlist-body tbody td.r{text-align:right}

/* === Type Badge === */
.fi-type-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:600}
.fi-type-badge [data-lucide]{width:12px;height:12px}

/* === Amount === */
.fi-amount{font-family:'Fira Code',monospace;font-size:12px;font-weight:600}
.fi-amount.pos{color:#10b981}
.fi-amount.neg{color:#ef4444}
.fi-amount.zero{color:var(--text-muted)}

/* === Description === */
.fi-desc{font-size:11px;color:var(--text-muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:default}
.fi-desc:hover{white-space:normal;overflow:visible}

/* === Empty State === */
.fi-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.fi-empty [data-lucide]{width:48px;height:48px;margin:0 auto 14px;display:block;opacity:.2}
.fi-empty p{font-size:14px;font-weight:500;margin:0 0 4px}
.fi-empty small{font-size:12px;color:var(--text-muted)}

/* === Animations === */
@keyframes fadeSlide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}
.fi-hero-card{animation:fadeSlide .3s ease both}
.fi-hero-card:nth-child(2){animation-delay:.05s}
.fi-hero-card:nth-child(3){animation-delay:.1s}
.fi-hero-card:nth-child(4){animation-delay:.15s}
.fi-hero-card:nth-child(5){animation-delay:.2s}

/* === Responsive === */
@media(max-width:1200px){.fi-hero{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.fi-hero{grid-template-columns:repeat(2,1fr)}.fi-mp-grid{grid-template-columns:1fr}}
@media(max-width:600px){.fi-hero{grid-template-columns:1fr}.fi-filter{flex-direction:column}.fi-btn-sync{margin-left:0;width:100%;justify-content:center}.fi-mp-grid-tiles{grid-template-columns:repeat(2,1fr);gap:8px}}

/* === Marketplace Overlay Window === */
.fmp-overlay{
    position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;
    display:none;flex-direction:column;
    background:rgba(0,0,0,.45);
    animation:fmpOverlayIn .2s ease;
}
.fmp-overlay.active{display:flex}
@keyframes fmpOverlayIn{from{opacity:0}to{opacity:1}}
.fmp-window{
    position:absolute;top:20px;left:20px;right:20px;bottom:20px;
    background:var(--bg-page,#f8fafc);border-radius:12px;
    box-shadow:0 0 0 1px var(--border);
    display:flex;flex-direction:column;overflow:hidden;
    animation:fmpWindowIn .25s ease;
}
@keyframes fmpWindowIn{from{transform:scale(.96) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.fmp-header{
    display:flex;align-items:center;gap:16px;padding:16px 24px;
    border-bottom:2px solid var(--fmp-color,var(--border));
    background:var(--bg-card);flex-shrink:0;
}
.fmp-logo{height:32px;width:auto;max-width:160px;object-fit:contain}
.fmp-title{font-size:18px;font-weight:600;color:var(--text-primary);flex:1}
.fmp-stats{display:flex;gap:14px;align-items:center}
.fmp-stat{display:flex;flex-direction:column;align-items:center;padding:6px 14px;border-radius:10px;background:var(--bg-input)}
.fmp-stat-val{font-size:16px;font-weight:600;color:var(--text-primary)}
.fmp-stat-lbl{font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase}
.fmp-close{
    width:40px;height:40px;border-radius:10px;border:1px solid var(--border);
    background:transparent;cursor:pointer;display:flex;align-items:center;
    justify-content:center;color:var(--text-muted);transition:all .15s;
}
.fmp-close:hover{background:#fef2f2;color:#ef4444;border-color:#ef4444}
.fmp-toolbar{
    display:flex;align-items:center;gap:10px;padding:12px 24px;
    background:var(--bg-card);border-bottom:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;
}
.fmp-body{flex:1;overflow-y:auto;padding:20px 24px}
.fmp-body .fi-txlist{border-radius:12px}
.fmp-body .fi-hero{margin-bottom:20px}
.fmp-pager{display:flex;align-items:center;gap:4px;margin-top:14px;justify-content:center}
.fmp-pager-btn{padding:6px 12px;border:1px solid var(--border);border-radius:8px;font-size:12px;font-weight:600;color:var(--text-muted);background:var(--bg-card);cursor:pointer;transition:all .12s;font-family:inherit;border:1px solid var(--border)}
.fmp-pager-btn:hover{border-color:var(--primary);color:var(--primary)}
.fmp-pager-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.fmp-pager-btn:disabled{opacity:.3;pointer-events:none}
.fmp-pager-info{font-size:12px;color:var(--text-muted);padding:0 8px}
@media(max-width:768px){.fmp-window{top:10px;left:10px;right:10px;bottom:10px}.fmp-stats{display:none}}
</style>

<!-- İkincil sekme şeridi: Finans / Faturalar (Finans grubu alt-nav) -->
<?php $subnav = ['active' => '/finance', 'items' => [
    ['u' => '/finance', 'l' => 'Finans'],
    ['u' => '/invoices', 'l' => 'Faturalar'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>

<!-- ================================================================
     Üst Filtre Paneli: pazaryeri seçici (logo-only segmented pills) + filtre araç çubuğu — tek beyaz bütün
     ================================================================ -->
<!-- Tek birleşik görünüm — tüm pazaryerleri (per-pazaryeri tab yok) + tarih filtresi -->
<div class="fi-filter">
  <div style="display:inline-flex;align-items:center;gap:11px;margin-right:auto">
    <span style="display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:rgb(var(--brand) / .08);color:var(--primary)"><i data-lucide="layers"></i></span>
    <div>
      <div style="font-size:15px;font-weight:700;color:var(--text-primary)">Tüm Pazaryerleri — Birleşik Finans</div>
      <div style="font-size:11.5px;color:var(--text-muted)">Özet, seçili tarihteki <b>tüm siparişlerden</b> hesaplanır (finans API'si olmayan pazaryerleri dahil).</div>
    </div>
  </div>
  <form method="get" action="/finance" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap">
    <div class="fi-fg"><label>Başlangıç</label><input type="date" name="from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"></div>
    <div class="fi-fg"><label>Bitiş</label><input type="date" name="to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"></div>
    <button type="submit" class="fi-btn fi-btn-primary"><i data-lucide="filter"></i> Filtrele</button>
  </form>
</div>

<!-- === Hero Stats === -->
<div class="fi-hero">
    <div class="fi-hero-card">
        <div class="fi-hero-bar" style="background:var(--primary)"></div>
        <div class="fi-hero-icon" style="background:rgb(var(--brand) / .08);color:var(--primary)"><i data-lucide="wallet"></i></div>
        <div class="fi-hero-label">Brüt Toplam</div>
        <div class="fi-hero-val" style="color:var(--primary)">₺<?= $fmt($totalGross) ?></div>
        <div class="fi-hero-sub"><i data-lucide="hash"></i> <?= $totalTxCount ?> işlem</div>
    </div>
    <div class="fi-hero-card">
        <div class="fi-hero-bar" style="background:#10b981"></div>
        <div class="fi-hero-icon" style="background:rgba(16,185,129,.08);color:#10b981"><i data-lucide="trending-up"></i></div>
        <div class="fi-hero-label">Satışlar</div>
        <div class="fi-hero-val" style="color:#10b981">₺<?= $fmt($totalSales) ?></div>
    </div>
    <div class="fi-hero-card">
        <div class="fi-hero-bar" style="background:#ef4444"></div>
        <div class="fi-hero-icon" style="background:rgba(239,68,68,.08);color:#ef4444"><i data-lucide="trending-down"></i></div>
        <div class="fi-hero-label">İadeler</div>
        <div class="fi-hero-val" style="color:<?= $totalRefunds > 0 ? '#ef4444' : 'var(--text-muted)' ?>">₺<?= $fmt($totalRefunds) ?></div>
    </div>
    <div class="fi-hero-card">
        <div class="fi-hero-bar" style="background:#f59e0b"></div>
        <div class="fi-hero-icon" style="background:rgba(245,158,11,.08);color:#f59e0b"><i data-lucide="percent"></i></div>
        <div class="fi-hero-label">Komisyon</div>
        <div class="fi-hero-val" style="color:<?= $totalCommission > 0 ? '#f59e0b' : 'var(--text-muted)' ?>">₺<?= $fmt($totalCommission) ?></div>
    </div>
    <div class="fi-hero-card">
        <div class="fi-hero-bar" style="background:#06b6d4"></div>
        <div class="fi-hero-icon" style="background:rgba(6,182,212,.08);color:#06b6d4"><i data-lucide="banknote"></i></div>
        <div class="fi-hero-label">Net Hakediş</div>
        <div class="fi-hero-val" style="color:<?= $totalNet >= 0 ? '#06b6d4' : '#ef4444' ?>">₺<?= $fmt($totalNet) ?></div>
    </div>
</div>

<?php $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); ?>
<!-- === Birleşik finansal hareketler === -->
<div class="fi-txlist">
  <div class="fi-txlist-h">
    <div class="fi-txlist-h-icon"><i data-lucide="scroll-text"></i></div>
    <span class="fi-txlist-h-title">Finansal Hareketler</span>
    <span class="fi-txlist-h-count"><?= number_format((int) $totalCount) ?> kayıt</span>
  </div>
  <?php if (empty($transactions)): ?>
    <div class="fi-empty"><i data-lucide="wallet"></i><p>Bu tarih aralığında finans hareketi yok</p><small>Üstteki özet siparişlerden hesaplanır. Detaylı hareket dökümü, finans API'si bağlı pazaryerlerinden (Trendyol, Hepsiburada) senkronize edildikçe burada listelenir.</small></div>
  <?php else: ?>
  <div class="fi-txlist-body">
    <table>
      <thead><tr><th>TARİH</th><th>TÜR</th><th>SİPARİŞ</th><th>AÇIKLAMA</th><th class="r">BRÜT</th><th class="r">NET</th></tr></thead>
      <tbody>
        <?php foreach ($transactions as $tx):
          $tt  = $tx['transaction_type'] ?? 'other';
          $bc  = $typeBadgeColors[$tt] ?? $typeBadgeColors['other'];
          $lbl = $typeLabels[$tt] ?? $tt;
          $isNeg = in_array($tt, ['refund','commission','stoppage','discount','coupon'], true);
          $g = (float) ($tx['gross_amount'] ?? 0);
          $n = (float) ($tx['net_amount'] ?? 0);
          $d = !empty($tx['transaction_date']) ? date('d.m.Y H:i', strtotime((string) $tx['transaction_date'])) : '';
        ?>
        <tr>
          <td style="font-size:12px;font-weight:500"><?= $e($d) ?></td>
          <td><span class="fi-type-badge" style="background:<?= $bc['bg'] ?>;color:<?= $bc['c'] ?>"><?= $e($lbl) ?></span></td>
          <td style="font-size:12px;color:var(--text-secondary);font-family:'Fira Code',monospace"><?= $e($tx['order_number'] ?? '') ?></td>
          <td><div class="fi-desc" title="<?= $e($tx['description'] ?? '') ?>"><?= $e($tx['description'] ?? '') ?></div></td>
          <td class="r fi-amount <?= $isNeg ? 'neg' : ($g > 0 ? 'pos' : 'zero') ?>"><?= $isNeg ? '-' : '' ?>₺<?= $fmt(abs($g)) ?></td>
          <td class="r fi-amount <?= $n > 0 ? 'pos' : ($n < 0 ? 'neg' : 'zero') ?>"><?= $n < 0 ? '-' : '' ?>₺<?= $fmt(abs($n)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ((int) $totalPages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;padding:14px;flex-wrap:wrap">
    <?php for ($i = max(1, (int) $currentPage - 2); $i <= min((int) $totalPages, (int) $currentPage + 2); $i++): ?>
      <a class="fi-pager-btn<?= $i === (int) $currentPage ? ' active' : '' ?>" href="/finance?from=<?= urlencode((string) ($filters['date_from'] ?? '')) ?>&amp;to=<?= urlencode((string) ($filters['date_to'] ?? '')) ?>&amp;page=<?= $i ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ================================================================
     MARKETPLACE FINANCE OVERLAY POPUP
     ================================================================ -->
<div class="fmp-overlay" id="fmpOverlay">
    <div class="fmp-window">
        <div class="fmp-header" id="fmpHeader">
            <img class="fmp-logo" id="fmpLogo" src="" alt="">
            <div class="fmp-title">Finansal Hareketler</div>
            <div class="fmp-stats" id="fmpStats"></div>
            <button class="fmp-close" onclick="closeFinancePopup()" title="Kapat (ESC)">
                <i data-lucide="x" style="width:20px;height:20px"></i>
            </button>
        </div>
        <div class="fmp-toolbar">
            <div class="fi-fg">
                <label>İşlem Türü</label>
                <select id="fmpType" class="fi-fg select" style="padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit">
                    <option value="">Tümü</option>
                    <?php foreach ($typeLabels as $k => $v): ?>
                    <option value="<?= $k ?>"><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fi-fg">
                <label>Başlangıç</label>
                <input type="date" id="fmpDateFrom" style="padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit;width:140px" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
            </div>
            <div class="fi-fg">
                <label>Bitiş</label>
                <input type="date" id="fmpDateTo" style="padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit;width:140px" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
            </div>
            <button type="button" class="fi-btn fi-btn-primary" style="align-self:flex-end" onclick="loadFinancePopupData(1)"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
            <div id="fmpSyncArea" style="margin-left:auto;display:flex;gap:8px;align-items:flex-end"></div>
        </div>
        <div class="fmp-body" id="fmpBody">
            <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
                <div style="width:20px;height:20px;border:2px solid rgb(var(--brand) / .2);border-top-color:var(--primary);border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 14px"></div>
                Yükleniyor...
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const TOTAL_DAYS = 15;
    const MP_COLORS = <?= json_encode($mpColors) ?>;
    const FINANCE_SUPPORTED = <?= json_encode($financeSupported) ?>;
    const MARKETPLACES = <?= json_encode(array_map(fn($m) => ['id'=>$m['id'],'slug'=>$m['slug'],'name'=>$m['display_name']], $marketplaces)) ?>;

    window.startSync = async function(slug, mpName) {
        slug = slug || 'trendyol';
        mpName = mpName || 'Trendyol';

        const btn = document.querySelector(`.fi-btn-sync[data-slug="${slug}"]`);
        const body = document.getElementById('fmpBody');

        if (btn && btn.classList.contains('loading')) return;
        if (btn) {
            btn.classList.add('loading');
            btn.innerHTML = `<i data-lucide="loader" style="width:14px;height:14px;animation:spin .6s linear infinite"></i> Senkronize ediliyor...`;
        }

        // Progress bar'ı popup body'ye ekle
        body.innerHTML = `<div style="padding:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span id="syncLabel" style="font-size:13px;font-weight:600;color:var(--text-primary)">Hazırlanıyor...</span>
                <span id="syncPercent" style="font-size:14px;font-weight:600;color:var(--primary)">0%</span>
            </div>
            <div style="height:8px;background:rgba(37,99,235,.08);border-radius:8px;overflow:hidden;margin-bottom:12px">
                <div id="syncBar" style="height:100%;background:#2563eb;border-radius:8px;transition:width .3s ease;width:0"></div>
            </div>
            <div id="syncLog" style="max-height:300px;overflow-y:auto;display:flex;flex-direction:column;gap:2px"></div>
        </div>`;

        const bar = document.getElementById('syncBar');
        const label = document.getElementById('syncLabel');
        const percent = document.getElementById('syncPercent');
        const log = document.getElementById('syncLog');

        let totalCreated = 0, totalSkipped = 0, totalErrors = 0;

        for (let day = TOTAL_DAYS - 1; day >= 0; day--) {
            const dayNum = TOTAL_DAYS - day;
            const pct = Math.round((dayNum / TOTAL_DAYS) * 100);
            bar.style.width = pct + '%';
            percent.textContent = pct + '%';
            label.textContent = `${mpName} — Gün ${dayNum}/${TOTAL_DAYS} işleniyor...`;

            try {
                const fd = new FormData();
                fd.append('day', day);
                fd.append('marketplace', slug);
                fd.append('_csrf_token', CSRF);

                const res = await fetch('/finance/sync-day', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });
                const d = await res.json();

                if (d.success) {
                    const data = d.data || {};
                    totalCreated += data.created || 0;
                    totalSkipped += data.skipped || 0;

                    let cls, info;
                    if (data.created > 0) {
                        cls = 'ok'; info = `+${data.created} yeni` + (data.skipped > 0 ? `, ${data.skipped} mevcut` : '');
                    } else if (data.skipped > 0) {
                        cls = 'skip'; info = `${data.skipped} mevcut`;
                    } else {
                        cls = 'skip'; info = 'Kayıt yok';
                    }
                    log.innerHTML += `<div style="display:flex;justify-content:space-between;padding:4px 8px;font-size:11px;border-radius:6px;font-family:'Fira Code',monospace;${cls==='ok'?'background:rgba(16,185,129,.05);color:#10b981':cls==='err'?'background:rgba(239,68,68,.05);color:#ef4444':'background:var(--bg-input);color:var(--text-muted)'}"><span>${data.date || 'Gün ' + dayNum}</span><span>${info}</span></div>`;
                } else {
                    totalErrors++;
                    log.innerHTML += `<div style="display:flex;justify-content:space-between;padding:4px 8px;font-size:11px;border-radius:6px;font-family:'Fira Code',monospace;background:rgba(239,68,68,.05);color:#ef4444"><span>Gün ${dayNum}</span><span>${d.message || 'Hata'}</span></div>`;
                }
            } catch (e) {
                totalErrors++;
                log.innerHTML += `<div style="display:flex;justify-content:space-between;padding:4px 8px;font-size:11px;border-radius:6px;font-family:'Fira Code',monospace;background:rgba(239,68,68,.05);color:#ef4444"><span>Gün ${dayNum}</span><span>Bağlantı hatası</span></div>`;
            }

            log.scrollTop = log.scrollHeight;
        }

        bar.style.width = '100%';
        percent.textContent = '100%';
        label.textContent = `${mpName} — Tamamlandı! ${totalCreated} yeni, ${totalSkipped} mevcut` + (totalErrors > 0 ? `, ${totalErrors} hatalı` : '');
        if (btn) {
            btn.classList.remove('loading');
            const c = MP_COLORS[slug] || '#2563eb';
            btn.innerHTML = `<i data-lucide="refresh-cw"></i> Sync (15 Gün)`;
            btn.style.borderColor = c;
            btn.style.color = c;
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();

        if (totalCreated > 0) {
            if (typeof Toast !== 'undefined') Toast.show(`${mpName}: ${totalCreated} yeni kayıt eklendi`, 'success');
            setTimeout(() => loadFinancePopupData(1), 2000);
        } else {
            if (typeof Toast !== 'undefined') Toast.show(`${mpName}: Yeni kayıt bulunamadı`, 'info');
        }
    };

    if (typeof lucide !== 'undefined') lucide.createIcons();

    /* ================================================================
       MARKETPLACE FINANCE POPUP
       ================================================================ */
    const TYPE_LABELS = <?= json_encode($typeLabels) ?>;
    const TYPE_BADGE_COLORS = <?= json_encode($typeBadgeColors) ?>;
    const TYPE_ICONS = {
        sale:'trending-up', refund:'trending-down', commission:'percent',
        payment:'banknote', stoppage:'landmark', discount:'ticket',
        coupon:'ticket', wire_transfer:'arrow-right-left', other:'circle-dot'
    };
    let popupMpId = null;
    let popupSlug = null;
    let popupMpName = null;
    let popupColor = null;

    /* Pazaryeri seçici (segmented pill) — aktif tab'ı işaretler ve mevcut mekanizmayla
       (finans popup'ı / AJAX) ilgili pazaryeri görünümünü yükler. Aktif tab marketplace_id
       URL parametresinden ($activeTab) geldiği için seçim istek değişince korunur. */
    window.setMpFilter = function(mpId, el, slug, name, color){
        mpId = mpId || '';
        // aynı (Tümü dışı) aktif taba tekrar tıkla → seçim kalkar, Tümü'ne dön
        if (el.classList.contains('is-active') && mpId !== '') {
            return window.setMpFilter('', document.querySelector('#mpSeg [data-mp-id=""]'));
        }
        document.querySelectorAll('#mpSeg .mp-seg-tab').forEach(t => t.classList.remove('is-active'));
        el.classList.add('is-active');
        if (mpId === '') {
            // Tümü → varsayılan toplu görünüm; açık popup varsa kapat
            if (typeof window.closeFinancePopup === 'function') window.closeFinancePopup();
            return;
        }
        // Pazaryeri seçildi → mevcut mekanizma: finans popup'ını AJAX ile yükle
        window.openFinancePopup(mpId, slug, name, color);
    };

    window.openFinancePopup = function(mpId, slug, name, color) {
        popupMpId = mpId;
        popupSlug = slug;
        popupMpName = name;
        popupColor = color;

        const overlay = document.getElementById('fmpOverlay');
        document.getElementById('fmpHeader').style.borderBottomColor = color;
        document.getElementById('fmpLogo').src = '/public/assets/img/marketplaces/' + slug + '.png';
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Sync butonu popup içinde
        const syncArea = document.getElementById('fmpSyncArea');
        if (FINANCE_SUPPORTED.includes(slug)) {
            syncArea.innerHTML = `<button type="button" class="fi-btn fi-btn-sync" data-slug="${slug}" onclick="startSync('${slug}','${name}')" style="border-color:${color};color:${color}">
                <i data-lucide="refresh-cw"></i> Sync (15 Gün)
            </button>`;
        } else {
            syncArea.innerHTML = '';
        }

        loadFinancePopupData(1);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.closeFinancePopup = function() {
        document.getElementById('fmpOverlay').classList.remove('active');
        document.body.style.overflow = '';
        popupMpId = null;
        // Popup kapanınca pazaryeri seçicisini Tümü'ne döndür (UI tutarlılığı)
        const seg = document.getElementById('mpSeg');
        if (seg) {
            seg.querySelectorAll('.mp-seg-tab').forEach(t => t.classList.remove('is-active'));
            const allTab = seg.querySelector('[data-mp-id=""]');
            if (allTab) allTab.classList.add('is-active');
        }
    };

    // ESC tuşu ile kapatma
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.getElementById('fmpOverlay').classList.contains('active')) {
            closeFinancePopup();
        }
    });
    // Overlay'a tıklayınca kapatma
    document.getElementById('fmpOverlay').addEventListener('click', (e) => {
        if (e.target === document.getElementById('fmpOverlay')) closeFinancePopup();
    });

    window.loadFinancePopupData = async function(page) {
        if (!popupMpId) return;
        const body = document.getElementById('fmpBody');
        body.innerHTML = `<div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
            <div style="width:20px;height:20px;border:2px solid rgba(37,99,235,.2);border-top-color:#2563eb;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 14px"></div>
            Yükleniyor...
        </div>`;

        const type = document.getElementById('fmpType').value;
        const from = document.getElementById('fmpDateFrom').value;
        const to   = document.getElementById('fmpDateTo').value;

        const params = new URLSearchParams({
            marketplace_id: popupMpId,
            type: type,
            from: from,
            to: to,
            page: page,
        });

        try {
            const res = await fetch('/finance/ajax?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const json = await res.json();

            if (!json.success) {
                body.innerHTML = `<div class="fi-empty"><i data-lucide="alert-triangle"></i><p>${json.message || 'Hata oluştu'}</p></div>`;
                if (typeof lucide !== 'undefined') lucide.createIcons();
                return;
            }

            const d = json.data;
            const txs = d.transactions || [];
            const summary = d.summary || {};
            const totalCount = d.totalCount || 0;
            const currentPage = d.currentPage || 1;
            const totalPages = d.totalPages || 1;

            const fmt = (v) => {
                const n = parseFloat(v || 0);
                return n.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
            };

            const gross = parseFloat(summary.total_gross || 0);
            const sales = parseFloat(summary.total_sales || 0);
            const refunds = parseFloat(summary.total_refunds || 0);
            const commission = parseFloat(summary.total_commission || 0);
            const stoppage = parseFloat(summary.total_stoppage || 0);
            const net = parseFloat(summary.total_net || 0);

            // Stats
            document.getElementById('fmpStats').innerHTML = `
                <div class="fmp-stat"><div class="fmp-stat-val" style="color:#2563eb">₺${fmt(gross)}</div><div class="fmp-stat-lbl">Brüt</div></div>
                <div class="fmp-stat"><div class="fmp-stat-val" style="color:#10b981">₺${fmt(net)}</div><div class="fmp-stat-lbl">Net</div></div>
                <div class="fmp-stat"><div class="fmp-stat-val">${totalCount}</div><div class="fmp-stat-lbl">İşlem</div></div>
            `;

            // Hero cards
            let html = `<div class="fi-hero" style="grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px">
                <div class="fi-hero-card"><div class="fi-hero-bar" style="background:${popupColor}"></div><div class="fi-hero-label">Brüt Toplam</div><div class="fi-hero-val" style="color:${popupColor};font-size:22px">₺${fmt(gross)}</div><div class="fi-hero-sub"><i data-lucide="hash"></i> ${totalCount} işlem</div></div>
                <div class="fi-hero-card"><div class="fi-hero-bar" style="background:#10b981"></div><div class="fi-hero-label">Satışlar</div><div class="fi-hero-val" style="color:#10b981;font-size:22px">₺${fmt(sales)}</div></div>
                <div class="fi-hero-card"><div class="fi-hero-bar" style="background:#ef4444"></div><div class="fi-hero-label">İadeler</div><div class="fi-hero-val" style="color:${refunds > 0 ? '#ef4444' : 'var(--text-muted)'};font-size:22px">₺${fmt(refunds)}</div></div>
                <div class="fi-hero-card"><div class="fi-hero-bar" style="background:#f59e0b"></div><div class="fi-hero-label">Komisyon</div><div class="fi-hero-val" style="color:${commission > 0 ? '#f59e0b' : 'var(--text-muted)'};font-size:22px">₺${fmt(commission)}</div></div>
                <div class="fi-hero-card"><div class="fi-hero-bar" style="background:#06b6d4"></div><div class="fi-hero-label">Net Hakediş</div><div class="fi-hero-val" style="color:${net >= 0 ? '#06b6d4' : '#ef4444'};font-size:22px">₺${fmt(net)}</div></div>
            </div>`;

            // Transaction list
            if (txs.length === 0) {
                html += `<div class="fi-txlist"><div class="fi-empty" style="padding:60px 20px"><i data-lucide="wallet"></i><p>Bu filtrelerle kayıt bulunamadı</p></div></div>`;
            } else {
                html += `<div class="fi-txlist">
                    <div class="fi-txlist-h">
                        <div class="fi-txlist-h-icon"><i data-lucide="scroll-text"></i></div>
                        <span class="fi-txlist-h-title">Cari Hesap Hareketleri</span>
                        <span class="fi-txlist-h-count">${totalCount} kayıt</span>
                    </div>
                    <div class="fi-txlist-body"><table><thead><tr>
                        <th>TARİH</th><th>TÜR</th><th>SİPARİŞ</th><th>AÇIKLAMA</th><th class="r">BRÜT</th><th class="r">NET</th>
                    </tr></thead><tbody>`;

                txs.forEach(tx => {
                    const txType = tx.transaction_type || 'other';
                    const bc = TYPE_BADGE_COLORS[txType] || TYPE_BADGE_COLORS['other'] || {c:'#64748b',bg:'rgba(100,116,139,.08)'};
                    const icon = TYPE_ICONS[txType] || 'circle-dot';
                    const label = TYPE_LABELS[txType] || txType;
                    const isNeg = ['refund','commission','stoppage','discount','coupon'].includes(txType);
                    const txGross = parseFloat(tx.gross_amount || 0);
                    const txNet = parseFloat(tx.net_amount || 0);
                    const date = tx.transaction_date ? new Date(tx.transaction_date).toLocaleString('tr-TR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';

                    html += `<tr>
                        <td style="font-size:12px;font-weight:500">${date}</td>
                        <td><span class="fi-type-badge" style="background:${bc.bg};color:${bc.c}"><i data-lucide="${icon}"></i> ${label}</span></td>
                        <td style="font-size:12px;color:var(--text-secondary);font-family:'Fira Code',monospace">${tx.order_number || ''}</td>
                        <td><div class="fi-desc" title="${(tx.description||'').replace(/"/g,'&quot;')}">${tx.description || ''}</div></td>
                        <td class="r fi-amount ${isNeg ? 'neg' : (txGross > 0 ? 'pos' : 'zero')}">${isNeg ? '-' : ''}₺${fmt(Math.abs(txGross))}</td>
                        <td class="r fi-amount ${txNet > 0 ? 'pos' : (txNet < 0 ? 'neg' : 'zero')}">${txNet < 0 ? '-' : ''}₺${fmt(Math.abs(txNet))}</td>
                    </tr>`;
                });

                html += '</tbody></table></div></div>';
            }

            // Pagination
            if (totalPages > 1) {
                html += '<div class="fmp-pager">';
                if (currentPage > 1) html += `<button class="fmp-pager-btn" onclick="loadFinancePopupData(${currentPage-1})"><i data-lucide="chevron-left" style="width:12px;height:12px"></i></button>`;
                const start = Math.max(1, currentPage - 2);
                const end = Math.min(totalPages, currentPage + 2);
                for (let i = start; i <= end; i++) {
                    html += `<button class="fmp-pager-btn ${i === currentPage ? 'active' : ''}" onclick="loadFinancePopupData(${i})">${i}</button>`;
                }
                html += `<span class="fmp-pager-info">/ ${totalPages}</span>`;
                if (currentPage < totalPages) html += `<button class="fmp-pager-btn" onclick="loadFinancePopupData(${currentPage+1})"><i data-lucide="chevron-right" style="width:12px;height:12px"></i></button>`;
                html += '</div>';
            }

            body.innerHTML = html;
            if (typeof lucide !== 'undefined') lucide.createIcons();

        } catch (e) {
            body.innerHTML = `<div class="fi-empty"><i data-lucide="wifi-off"></i><p>Bağlantı hatası</p><small>${e.message}</small></div>`;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    };

})();
</script>
