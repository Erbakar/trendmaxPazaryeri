<?php
$today = $data['todayStats'] ?? [];
$week = $data['weekStats'] ?? [];
$stock = $data['stockSummary'] ?? [];
$chart = $data['dailyChart'] ?? [];
$mpStats = $data['marketplaceStats'] ?? [];
$recentOrders = $data['recentOrders'] ?? [];
$lowStock = $data['lowStockProducts'] ?? [];
$lastSyncs = $data['lastSyncs'] ?? [];
$appUpdates = $data['appUpdates'] ?? [];
$pendingInvoices = (int)($data['pendingInvoices'] ?? 0);
$pendingReturns = (int)($data['pendingReturns'] ?? 0);

$chartLabels = array_column($chart, 'date');
$chartOrders = array_column($chart, 'count');
$chartRevenue = array_column($chart, 'revenue');

$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','ciceksepeti'=>'#e91e63','pttavm'=>'#ffc107','shopify'=>'#7ab55c','woocommerce'=>'#7f54b3','opencart'=>'#1c8ed8'];
$mpMax = 1;
foreach ($mpStats as $m) { $mpMax = max($mpMax, (int)($m['order_count'] ?? 0)); }

$stBadge = [
    'beklemede'=>['l'=>'Beklemede','c'=>'#94a3b8','bg'=>'#f1f5f9'],
    'yeni'=>['l'=>'Yeni','c'=>'#3b82f6','bg'=>'#eff6ff'],
    'hazirlaniyor'=>['l'=>'Hazırlanıyor','c'=>'#f59e0b','bg'=>'#fffbeb'],
    'faturalandi'=>['l'=>'Faturalandı','c'=>'#06b6d4','bg'=>'#ecfeff'],
    'kargoda'=>['l'=>'Kargoda','c'=>'var(--primary)','bg'=>'#f5f3ff'],
    'teslim_edildi'=>['l'=>'Teslim Edildi','c'=>'#10b981','bg'=>'#f0fdf4'],
    'iptal'=>['l'=>'İptal','c'=>'#ef4444','bg'=>'#fef2f2'],
    'iade'=>['l'=>'İade','c'=>'#f97316','bg'=>'#fff7ed'],
];
$upTypeMap = [
    'feature'      => ['rocket',      '#f0fdf4', '#10b981'],
    'improvement'  => ['trending-up', '#eff6ff', '#3b82f6'],
    'fix'          => ['wrench',      '#fef3c7', '#f59e0b'],
    'announcement' => ['megaphone',   '#f5f3ff', '#6366f1'],
];
$upFmt = static function ($s) { if (!$s) return ''; $t = strtotime((string) $s); return $t ? date('d.m.Y', $t) : (string) $s; };
$trDate = str_replace(
    ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday','January','February','March','April','May','June','July','August','September','October','November','December'],
    ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'],
    date('d F Y, l')
);
?>

<style>
/* ════ Komuta Merkezi — renkli & animasyonlu dashboard ════ */
.db-page{display:flex;flex-direction:column;gap:16px}

/* Entrance + micro animasyonlar */
@keyframes dbIn{to{opacity:1;transform:none}}
@keyframes dbBar{from{width:0}}
@keyframes dbPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.92)}}
@keyframes dbFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.db-anim{opacity:0;transform:translateY(14px);animation:dbIn .55s cubic-bezier(.2,.7,.3,1) forwards}
.db-bar-fill{width:var(--w);animation:dbBar 1.1s cubic-bezier(.2,.8,.25,1) .15s both}
@media (prefers-reduced-motion: reduce){.db-anim{animation:none;opacity:1;transform:none}.db-bar-fill{animation:none}}

/* Sayfa başlığı — referans: başlık + alt başlık, sağda tek birincil düğme */
.db-pagehead{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:2px 0 4px}
.db-pagehead h1{font-size:22px;font-weight:600;margin:0 0 3px;letter-spacing:-.01em;color:var(--text-primary)}
.db-pagehead p{margin:0;font-size:13px;color:var(--text-muted)}
.db-pagehead-actions{display:flex;gap:8px;flex-shrink:0}
.db-btn{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 15px;border-radius:var(--radius-sm,8px);font-size:13px;font-weight:600;border:1px solid transparent;cursor:pointer;transition:background .15s,border-color .15s;font-family:inherit;text-decoration:none}
.db-btn i{width:15px;height:15px}
.db-btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}.db-btn-primary:hover{filter:brightness(.94)}
.db-btn-outline{background:var(--bg-card);color:var(--text-primary);border-color:var(--border)}.db-btn-outline:hover{background:var(--bg-sidebar-hover)}

/* KPI strip */
.db-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
@media(max-width:1100px){.db-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.db-stats{grid-template-columns:1fr}}
/* İstatistik kartı — referans: solda pastel ikon karesi, sağda etiket / büyük sayı / alt yazı */
.db-stat{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md,12px);padding:16px 18px;display:flex;align-items:center;gap:14px;transition:border-color .15s}
.db-stat:hover{border-color:var(--border-strong,#d1d5db)}
.db-stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.db-stat-icon i{width:22px;height:22px}
.db-stat-info{min-width:0}
.db-stat-info h4{margin:0;font-size:12px;color:var(--text-secondary);font-weight:500;letter-spacing:0;text-transform:none}
.db-stat-val{font-size:26px;font-weight:700;margin:3px 0 1px;line-height:1.05;letter-spacing:-.02em;font-variant-numeric:tabular-nums;color:var(--text-primary)}
.db-stat-sub{font-size:11.5px;color:var(--text-muted)}
.db-stat-sub strong{color:var(--text-primary)}

/* Grids */
.db-grid-73{display:grid;grid-template-columns:7fr 5fr;gap:16px}
.db-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:1024px){.db-grid-73,.db-grid-2{grid-template-columns:1fr}}

/* Card */
.db-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md,12px);overflow:hidden}
.db-card-head{padding:14px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between}
.db-card-head h3{margin:0;font-size:15px;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.db-card-head h3 i{width:16px;height:16px}
.db-card-body{padding:18px}

/* Marketplace ranking (renkli + animasyonlu çubuk) */
.db-mp-row{display:flex;align-items:center;gap:11px;padding:9px 0}
.db-mp-logo{width:26px;height:26px;object-fit:contain;flex-shrink:0}
.db-mp-fallback{width:26px;height:26px;border-radius:7px;display:grid;place-items:center;font-size:11px;font-weight:600;color:#fff;flex-shrink:0}
.db-mp-mid{flex:1;min-width:0}
.db-mp-name{font-size:12.5px;font-weight:600;margin-bottom:5px}
.db-mp-track{height:7px;background:var(--bg-badge);border-radius:9px;overflow:hidden}
.db-mp-bar{height:100%;border-radius:9px}
.db-mp-count{font-size:15px;font-weight:600;font-variant-numeric:tabular-nums}
.db-mp-rev{font-size:10.5px;color:var(--text-muted);text-align:right}

/* Quick actions */
.db-quick{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.db-quick-btn{display:flex;flex-direction:column;align-items:center;gap:6px;padding:13px 8px;border-radius:var(--radius-sm,8px);border:1px solid var(--border);background:#fff;cursor:pointer;transition:all .18s;font-family:inherit;text-decoration:none;color:var(--text-primary)}
.db-quick-btn:hover{border-color:var(--primary)}
.db-quick-btn.q1:hover{background:#eaf1fe}.db-quick-btn.q2:hover{background:#eafaf0}.db-quick-btn.q3:hover{background:#fdf6e7}
.db-quick-btn i{width:20px;height:20px}
.db-quick-btn span{font-size:11px;font-weight:600;text-align:center}

/* Orders */
.db-order-row{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--border-light);font-size:12px;transition:background .15s;border-radius:8px}
.db-order-row:last-child{border-bottom:none}
.db-order-row:hover{background:#f8faff}
.db-order-id{font-weight:700;color:var(--primary);font-family:monospace;font-size:11px;min-width:78px}
.db-order-customer{flex:1;color:var(--text-secondary);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.db-order-amount{font-weight:700;min-width:74px;text-align:right;font-variant-numeric:tabular-nums}
.db-order-badge{display:inline-flex;padding:3px 9px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap}

/* Low stock */
.db-low-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-light);font-size:12px}
.db-low-row:last-child{border-bottom:none}
.db-low-sku{font-family:monospace;font-size:11px;color:var(--text-muted);min-width:78px}
.db-low-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.db-low-qty{font-weight:600;color:#ef4444;background:#fef2f2;padding:2px 10px;border-radius:8px;font-size:12px}

/* Sync */
.db-sync-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border-light)}
.db-sync-row:last-child{border-bottom:none}
.db-sync-icon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.db-sync-icon i{width:16px;height:16px}
.db-sync-info{flex:1}
.db-sync-info .name{font-size:13px;font-weight:600}
.db-sync-info .time{font-size:11px;color:var(--text-muted)}
.db-sync-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:8px}

/* Güncellemeler */
.db-announce{list-style:none;padding:0;margin:0}
.db-announce li{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border-light)}
.db-announce li:last-child{border-bottom:none}
.db-announce-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.db-announce-icon i{width:18px;height:18px}
.db-announce-text{flex:1;min-width:0}
.db-announce-text .title{font-size:13px;font-weight:700;margin-bottom:2px;color:var(--text-primary)}
.db-announce-text .desc{font-size:12px;color:var(--text-muted);line-height:1.5}
.db-announce-text .date{font-size:10px;color:var(--text-muted);margin-top:4px}
.db-badge-new{font-size:9.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#047857;background:rgba(16,185,129,.12);padding:3px 9px;border-radius:99px}

/* Chart toggle */
.db-chart-toggle{display:flex;gap:4px}
.db-chart-btn{padding:4px 12px;border-radius:7px;font-size:11px;font-weight:600;border:1px solid var(--border-color);background:#fff;color:var(--text-muted);cursor:pointer;transition:all .15s;font-family:inherit}
.db-chart-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
</style>

<div class="db-page">

<!-- Sayfa başlığı -->
<div class="db-pagehead db-anim">
    <div>
        <h1>Hoş geldiniz, <?= htmlspecialchars((string) ($data['_user']['name'] ?? 'Mağazanız')) ?></h1>
        <p><?= $trDate ?> — tüm pazaryerlerinizi tek panelden yönetin</p>
    </div>
    <div class="db-pagehead-actions">
        <a href="/orders" class="db-btn db-btn-outline"><i data-lucide="shopping-bag"></i> Siparişler</a>
        <a href="/products" class="db-btn db-btn-primary"><i data-lucide="package"></i> Ürünler</a>
    </div>
</div>

<!-- KPI strip -->
<div class="db-stats">
    <div class="db-stat db-anim" style="animation-delay:.04s">
        <div class="db-stat-icon" style="background:var(--primary-bg);color:var(--primary)"><i data-lucide="shopping-cart"></i></div>
        <div class="db-stat-info">
            <h4>Bugünkü Siparişler</h4>
            <div class="db-stat-val" data-count="<?= (int)($today['count'] ?? 0) ?>">0</div>
            <div class="db-stat-sub"><strong><?= number_format((float)($today['revenue'] ?? 0), 2, ',', '.') ?> ₺</strong> ciro</div>
        </div>
    </div>
    <div class="db-stat db-anim" style="--accent:#10b981;animation-delay:.10s">
        <div class="db-stat-icon" style="background:#eafaf0;color:#10b981"><i data-lucide="trending-up"></i></div>
        <div class="db-stat-info">
            <h4>Haftalık Ciro</h4>
            <div class="db-stat-val" data-count="<?= (float)($week['revenue'] ?? 0) ?>" data-suffix=" ₺">0 ₺</div>
            <div class="db-stat-sub"><strong><?= number_format((int)($week['count'] ?? 0)) ?></strong> sipariş</div>
        </div>
    </div>
    <div class="db-stat db-anim" style="--accent:#f59e0b;animation-delay:.16s">
        <div class="db-stat-icon" style="background:#fdf6e7;color:#f59e0b"><i data-lucide="package"></i></div>
        <div class="db-stat-info">
            <h4>Yerel Katalog Ürünleri</h4>
            <div class="db-stat-val" data-count="<?= (int)($stock['total'] ?? 0) ?>"><?= (int)($stock['total'] ?? 0) ?></div>
            <div class="db-stat-sub"><strong><?= (int)($stock['low_stock'] ?? 0) ?></strong> düşük stok</div>
        </div>
    </div>
    <div class="db-stat db-anim" style="--accent:#ef4444;animation-delay:.22s">
        <div class="db-stat-icon" style="background:#fdeeee;color:#ef4444"><i data-lucide="file-warning"></i></div>
        <div class="db-stat-info">
            <h4>Bekleyen İşlemler</h4>
            <div class="db-stat-val" data-count="<?= $pendingInvoices ?>">0</div>
            <div class="db-stat-sub"><strong><?= $pendingReturns ?></strong> iade bekliyor</div>
        </div>
    </div>
</div>

<!-- Chart + Marketplace ranking -->
<div class="db-grid-73">
    <div class="db-card db-anim" style="animation-delay:.28s">
        <div class="db-card-head">
            <h3><i data-lucide="bar-chart-3" style="color:var(--primary)"></i> Sipariş & Ciro Grafiği</h3>
            <div class="db-chart-toggle">
                <button class="db-chart-btn active" onclick="setChartMode('both',this)">Her İkisi</button>
                <button class="db-chart-btn" onclick="setChartMode('orders',this)">Sipariş</button>
                <button class="db-chart-btn" onclick="setChartMode('revenue',this)">Ciro</button>
            </div>
        </div>
        <div class="db-card-body" style="padding:16px;height:280px"><canvas id="orderChart"></canvas></div>
    </div>

    <div class="db-card db-anim" style="animation-delay:.34s">
        <div class="db-card-head"><h3><i data-lucide="store" style="color:var(--primary)"></i> Pazaryeri Dağılımı</h3></div>
        <div class="db-card-body" style="padding:14px 18px">
            <?php if (empty($mpStats)): ?>
                <div style="text-align:center;padding:26px;color:var(--text-muted)">
                    <i data-lucide="store" style="width:38px;height:38px;display:block;margin:0 auto 8px;opacity:.5"></i>
                    <p style="margin:0;font-size:13px">Henüz pazaryeri verisi yok</p>
                </div>
            <?php else: foreach ($mpStats as $mp):
                $slug = $mp['slug'] ?? '';
                $color = $mpColors[$slug] ?? '#2563eb';
                $cnt = (int)($mp['order_count'] ?? 0);
                $w = $mpMax > 0 ? round($cnt / $mpMax * 100) : 0;
            ?>
                <div class="db-mp-row">
                    <img src="/public/assets/img/marketplaces/<?= $slug ?>.png" onerror="this.onerror=null;this.outerHTML='<span class=\'db-mp-fallback\' style=\'background:<?= $color ?>\'><?= htmlspecialchars(mb_strtoupper(mb_substr($mp['display_name'] ?? $slug, 0, 1))) ?></span>'" class="db-mp-logo" alt="">
                    <div class="db-mp-mid">
                        <div class="db-mp-name"><?= htmlspecialchars($mp['display_name'] ?? $slug) ?></div>
                        <div class="db-mp-track"><div class="db-mp-bar db-bar-fill" style="--w:<?= max(4,$w) ?>%;background:<?= $color ?>"></div></div>
                    </div>
                    <div>
                        <div class="db-mp-count"><?= number_format($cnt) ?></div>
                        <div class="db-mp-rev"><?= number_format((float)($mp['total_revenue'] ?? 0), 0, ',', '.') ?> ₺</div>
                    </div>
                </div>
            <?php endforeach; endif; ?>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9">
                <div class="db-quick">
                    <a href="/orders" class="db-quick-btn q1"><i data-lucide="refresh-cw" style="color:var(--primary)"></i><span>Sipariş Çek</span></a>
                    <a href="/products" class="db-quick-btn q2"><i data-lucide="upload" style="color:#10b981"></i><span>Stok Gönder</span></a>
                    <a href="/products" class="db-quick-btn q3"><i data-lucide="dollar-sign" style="color:#f59e0b"></i><span>Fiyat Gönder</span></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent orders + Güncellemeler -->
<div class="db-grid-73">
    <div class="db-card db-anim" style="animation-delay:.40s">
        <div class="db-card-head">
            <h3><i data-lucide="shopping-bag" style="color:var(--primary)"></i> Son Siparişler</h3>
            <a href="/orders" style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:4px">Tümünü Gör <i data-lucide="arrow-right" style="width:14px;height:14px"></i></a>
        </div>
        <div class="db-card-body" style="padding:10px 18px">
            <?php if (empty($recentOrders)): ?>
                <div style="text-align:center;padding:28px;color:var(--text-muted)"><i data-lucide="inbox" style="width:38px;height:38px;display:block;margin:0 auto 8px;opacity:.5"></i><p style="margin:0;font-size:13px">Henüz sipariş yok</p></div>
            <?php else: foreach ($recentOrders as $order):
                $st = $order['status'] ?? '';
                $badge = $stBadge[$st] ?? ['l'=>$st,'c'=>'#64748b','bg'=>'#f1f5f9'];
            ?>
                <a href="/orders/<?= $order['id'] ?? '' ?>" class="db-order-row" style="text-decoration:none;color:inherit">
                    <span class="db-order-id">#<?= htmlspecialchars($order['marketplace_order_id'] ?? '') ?></span>
                    <span class="db-order-customer"><?= htmlspecialchars($order['customer_name'] ?? '—') ?></span>
                    <span class="db-order-amount"><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', '.') ?> ₺</span>
                    <span class="db-order-badge" style="color:<?= $badge['c'] ?>;background:<?= $badge['bg'] ?>"><?= $badge['l'] ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="db-card db-anim" style="animation-delay:.46s">
        <div class="db-card-head">
            <h3><i data-lucide="megaphone" style="color:var(--primary)"></i> Güncellemeler & Duyurular</h3>
            <?php if (!empty($appUpdates)): ?><span class="db-badge-new">YENİ</span><?php endif; ?>
        </div>
        <div class="db-card-body" style="padding:8px 18px">
            <?php if (empty($appUpdates)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted)"><i data-lucide="megaphone" style="width:34px;height:34px;display:block;margin:0 auto 8px;opacity:.5"></i><p style="margin:0;font-size:13px">Henüz yayınlanmış güncelleme yok.</p></div>
            <?php else: ?>
            <ul class="db-announce">
                <?php foreach ($appUpdates as $u): $tm = $upTypeMap[$u['type']] ?? $upTypeMap['announcement']; ?>
                <li>
                    <div class="db-announce-icon" style="background:<?= $tm[1] ?>;color:<?= $tm[2] ?>"><i data-lucide="<?= $tm[0] ?>"></i></div>
                    <div class="db-announce-text">
                        <div class="title"><?= htmlspecialchars($u['title']) ?><?php if (!empty($u['version'])): ?> <span style="font-size:11px;font-weight:600;color:var(--text-muted)">v<?= htmlspecialchars($u['version']) ?></span><?php endif; ?></div>
                        <?php if (!empty($u['body'])): ?><div class="desc"><?= htmlspecialchars(mb_strimwidth((string) $u['body'], 0, 140, '…')) ?></div><?php endif; ?>
                        <div class="date"><?= htmlspecialchars($upFmt($u['published_at'] ?? $u['created_at'] ?? '')) ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Low stock + Sync status -->
<div class="db-grid-2">
    <div class="db-card db-anim" style="animation-delay:.52s">
        <div class="db-card-head">
            <h3><i data-lucide="alert-triangle" style="color:#f59e0b"></i> Düşük Stok Uyarıları</h3>
            <a href="/products?stock_status=low" style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:4px">Tümü <i data-lucide="arrow-right" style="width:14px;height:14px"></i></a>
        </div>
        <div class="db-card-body" style="padding:10px 18px">
            <?php if (empty($lowStock)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted)"><i data-lucide="check-circle" style="width:36px;height:36px;display:block;margin:0 auto 8px;color:#10b981"></i><p style="margin:0;font-size:13px">Stok uyarısı yok, her şey yolunda!</p></div>
            <?php else: foreach ($lowStock as $p): ?>
                <div class="db-low-row">
                    <span class="db-low-sku"><?= htmlspecialchars($p['sku'] ?? '') ?></span>
                    <span class="db-low-name"><?= htmlspecialchars(mb_substr($p['name'] ?? '', 0, 40)) ?></span>
                    <span class="db-low-qty"><?= (int)($p['stock_qty'] ?? 0) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="db-card db-anim" style="animation-delay:.58s">
        <div class="db-card-head"><h3><i data-lucide="refresh-cw" style="color:#10b981"></i> Senkronizasyon Durumu</h3></div>
        <div class="db-card-body" style="padding:10px 18px">
            <?php
            $syncTypes = [
                'orders' => ['label'=>'Sipariş Çek','icon'=>'shopping-cart','color'=>'var(--primary)','bg'=>'var(--primary-bg)'],
                'stock'  => ['label'=>'Stok Gönder','icon'=>'package','color'=>'#10b981','bg'=>'#eafaf0'],
                'prices' => ['label'=>'Fiyat Gönder','icon'=>'dollar-sign','color'=>'#f59e0b','bg'=>'#fdf6e7'],
                'import' => ['label'=>'İçe Aktarma','icon'=>'download','color'=>'#3b82f6','bg'=>'#eff6ff'],
            ];
            $syncsMap = [];
            foreach ($lastSyncs as $s) { $syncsMap[$s['sync_type'] ?? ''] = $s; }
            foreach ($syncTypes as $type => $meta):
                $sync = $syncsMap[$type] ?? null;
                $time = !empty($sync['completed_at']) ? date('d.m H:i', strtotime($sync['completed_at'])) : '—';
                $ok = !empty($sync) && ($sync['status'] ?? '') === 'success';
            ?>
            <div class="db-sync-row">
                <div class="db-sync-icon" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>"><i data-lucide="<?= $meta['icon'] ?>"></i></div>
                <div class="db-sync-info"><div class="name"><?= $meta['label'] ?></div><div class="time"><?= $time ?></div></div>
                <?php if ($sync): ?>
                <span class="db-sync-status" style="background:<?= $ok ? '#f0fdf4' : '#fef2f2' ?>;color:<?= $ok ? '#10b981' : '#ef4444' ?>"><?= $ok ? 'Başarılı' : 'Hata' ?></span>
                <?php else: ?>
                <span class="db-sync-status" style="background:#f1f5f9;color:#94a3b8">Bekliyor</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</div>

<script>
// ── Chart.js ──
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartOrders = <?= json_encode($chartOrders) ?>;
const chartRevenue = <?= json_encode($chartRevenue) ?>;
const fmtLabels = chartLabels.map(d => { const p = d.split('-'); return p.length >= 3 ? p[2]+'.'+p[1] : d; });

const ctx = document.getElementById('orderChart')?.getContext('2d');
let orderChart;
if (ctx) {
    orderChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: fmtLabels,
            datasets: [
                { label: 'Sipariş', data: chartOrders, backgroundColor: 'rgba(37,99,235,.75)', borderRadius: 6, borderSkipped: false, yAxisID: 'y', order: 2 },
                { label: 'Ciro (₺)', data: chartRevenue, type: 'line', borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.08)', pointBackgroundColor: '#10b981', pointRadius: 4, pointHoverRadius: 6, tension: .4, fill: true, yAxisID: 'y1', order: 1 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            animation: { duration: 900, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: true, position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 11, weight: '600' } } },
                tooltip: { backgroundColor: '#1e293b', padding: 12, titleFont: { size: 12, weight: '600' }, bodyFont: { size: 12 }, cornerRadius: 8,
                    callbacks: { label: function(c) { if (c.dataset.yAxisID === 'y1') return 'Ciro: ' + c.raw.toLocaleString('tr-TR') + ' ₺'; return 'Sipariş: ' + c.raw; } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11, weight: '600' } } },
                y: { position: 'left', beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, stepSize: 1 }, title: { display: true, text: 'Sipariş', font: { size: 11 } } },
                y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { font: { size: 11 }, callback: v => v.toLocaleString('tr-TR') + ' ₺' }, title: { display: true, text: 'Ciro', font: { size: 11 } } }
            }
        }
    });
}

function setChartMode(mode, btn) {
    if (!orderChart) return;
    document.querySelectorAll('.db-chart-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    orderChart.data.datasets[0].hidden = mode === 'revenue';
    orderChart.data.datasets[1].hidden = mode === 'orders';
    orderChart.update();
}

// ── KPI sayaç animasyonu (count-up) ──
(function(){
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('.db-stat-val[data-count]').forEach(function(el){
        const target = parseFloat(el.dataset.count || '0') || 0;
        const dec = (el.dataset.count || '').indexOf('.') > -1 ? 0 : 0; // tam sayı göster
        const suffix = el.dataset.suffix || '';
        const fmt = n => Math.round(n).toLocaleString('tr-TR') + suffix;
        if (reduce || target === 0) { el.textContent = fmt(target); return; }
        const dur = 950, t0 = performance.now();
        (function tick(now){
            const p = Math.min(1, (now - t0) / dur);
            const e = 1 - Math.pow(1 - p, 3);
            el.textContent = fmt(target * e);
            if (p < 1) requestAnimationFrame(tick); else el.textContent = fmt(target);
        })(t0);
    });
})();

// ── Hızlı işlemler ──
function quickAction(type) {
    const urls = { sync_orders: '/orders/sync', sync_stock: '/sync/stock', sync_prices: '/sync/price' };
    const url = urls[type];
    if (!url) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const fd = new FormData();
    fd.append('_csrf_token', csrf);
    fetch(url, { method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r => r.json())
    .then(d => { if (d.success) Toast.show(d.message || 'İşlem tamamlandı!', 'success'); else Toast.show('Hata: ' + (d.message || 'Bilinmeyen hata'), 'error', 5000); })
    .catch(() => Toast.show('Bağlantı hatası!', 'error'));
}
</script>
