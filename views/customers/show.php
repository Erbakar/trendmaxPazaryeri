<?php
$customer = $data['customer'] ?? [];
$orders   = $data['orders'] ?? [];
$csrf     = $data['_csrf'] ?? '';

$stMap = [
    'beklemede'=>['l'=>'Beklemede','c'=>'#94a3b8','bg'=>'#f1f5f9'],
    'yeni'=>['l'=>'Yeni','c'=>'#3b82f6','bg'=>'#eff6ff'],
    'hazirlaniyor'=>['l'=>'Hazırlanıyor','c'=>'#f59e0b','bg'=>'#fffbeb'],
    'faturalandi'=>['l'=>'Faturalandı','c'=>'#06b6d4','bg'=>'#ecfeff'],
    'kargoda'=>['l'=>'Kargoda','c'=>'var(--primary)','bg'=>'#f5f3ff'],
    'teslim_noktasinda'=>['l'=>'T. Noktasında','c'=>'var(--primary)','bg'=>'#eef2ff'],
    'teslim_edildi'=>['l'=>'Teslim Edildi','c'=>'#10b981','bg'=>'#f0fdf4'],
    'teslim_edilemedi'=>['l'=>'Teslim Edilemedi','c'=>'#f97316','bg'=>'#fff7ed'],
    'iptal'=>['l'=>'İptal','c'=>'#ef4444','bg'=>'#fef2f2'],
    'iade'=>['l'=>'İade','c'=>'#f97316','bg'=>'#fff7ed'],
];
?>
<style>
.cd-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;margin-bottom:20px;transition:color .15s}
.cd-back:hover{color:var(--primary)}
.cd-grid{display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start}
.cd-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card);margin-bottom:20px}
.cd-card-h{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg-input)}
.cd-card-h h3{margin:0;font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.cd-card-b{padding:20px}
/* Profile header */
.cd-profile-h{padding:32px;text-align:center;background:var(--primary)}
.cd-big-avatar{width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;color:#fff;margin:0 auto 14px;border:3px solid rgba(255,255,255,.3)}
.cd-profile-h h2{color:#fff;margin:0 0 4px;font-size:20px;font-weight:700}
.cd-profile-h p{color:rgba(255,255,255,.8);margin:0;font-size:13px}
/* Stats row */
.cd-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border-top:1px solid var(--border)}
.cd-stat{text-align:center;padding:16px;border-right:1px solid var(--border)}
.cd-stat:last-child{border-right:none}
.cd-stat-val{font-size:20px;font-weight:700;color:var(--primary)}
.cd-stat-lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
/* Info */
.cd-info{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);gap:12px}
.cd-info:last-child{border-bottom:none}
.cd-info-l{font-size:13px;color:var(--text-muted);font-weight:500;display:flex;align-items:center;gap:6px}
.cd-info-r{font-size:13px;color:var(--text-primary);font-weight:700;text-align:right}
/* Order row */
.cd-order{display:flex;align-items:center;padding:14px 20px;border-bottom:1px solid var(--border);gap:16px;text-decoration:none;color:inherit;transition:background .12s}
.cd-order:last-child{border-bottom:none}
.cd-order:hover{background:var(--bg-sidebar-hover)}
.cd-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;white-space:nowrap}
.cd-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;transition:all .12s;cursor:pointer;font-family:inherit}
.cd-btn:hover{border-color:var(--primary);color:var(--primary)}
@media(max-width:1024px){.cd-grid{grid-template-columns:1fr}}
</style>

<a href="/customers" class="cd-back"><i data-lucide="arrow-left" style="width:16px;height:16px"></i> Müşteriler</a>

<div class="cd-grid">
<!-- Sol: Profil -->
<div>
    <div class="cd-card">
        <div class="cd-profile-h">
            <div class="cd-big-avatar"><?= strtoupper(mb_substr($customer['name'] ?? 'M', 0, 1)) ?></div>
            <h2><?= htmlspecialchars($customer['name'] ?? '') ?></h2>
            <p><?= htmlspecialchars($customer['email'] ?? 'E-posta yok') ?></p>
        </div>
        <div class="cd-stats">
            <div class="cd-stat"><div class="cd-stat-val"><?= (int)($customer['total_orders'] ?? 0) ?></div><div class="cd-stat-lbl">Sipariş</div></div>
            <div class="cd-stat"><div class="cd-stat-val"><?= number_format((float)($customer['total_spent'] ?? 0), 0, ',', '.') ?>₺</div><div class="cd-stat-lbl">Harcama</div></div>
            <div class="cd-stat"><div class="cd-stat-val"><?= !empty($customer['first_order_at']) ? date('d.m.y', strtotime($customer['first_order_at'])) : '—' ?></div><div class="cd-stat-lbl">İlk Sipariş</div></div>
        </div>
        <div class="cd-card-b">
            <?php if (!empty($customer['phone'])): ?>
            <div class="cd-info"><span class="cd-info-l"><i data-lucide="phone" style="width:14px;height:14px"></i> Telefon</span><span class="cd-info-r"><?= htmlspecialchars($customer['phone']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($customer['id_no']) && $customer['id_no'] !== '11111111111'): ?>
            <div class="cd-info"><span class="cd-info-l"><i data-lucide="credit-card" style="width:14px;height:14px"></i> TC / Vergi No</span><span class="cd-info-r"><?= htmlspecialchars($customer['id_no']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($customer['tax_office'])): ?>
            <div class="cd-info"><span class="cd-info-l"><i data-lucide="building" style="width:14px;height:14px"></i> Vergi Dairesi</span><span class="cd-info-r"><?= htmlspecialchars($customer['tax_office']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($customer['city'])): ?>
            <div class="cd-info"><span class="cd-info-l"><i data-lucide="map-pin" style="width:14px;height:14px"></i> Şehir</span><span class="cd-info-r"><?= htmlspecialchars(($customer['district'] ?? '') . ' / ' . $customer['city']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($customer['address'])): ?>
            <div class="cd-info"><span class="cd-info-l"><i data-lucide="home" style="width:14px;height:14px"></i> Adres</span><span class="cd-info-r" style="font-size:12px;max-width:180px"><?= htmlspecialchars($customer['address']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($customer['notes'])): ?>
            <div class="cd-info"><span class="cd-info-l"><i data-lucide="message-square" style="width:14px;height:14px"></i> Not</span><span class="cd-info-r"><?= htmlspecialchars($customer['notes']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sağ: Siparişler -->
<div>
    <div class="cd-card">
        <div class="cd-card-h">
            <h3><i data-lucide="package" style="width:16px;height:16px"></i> Siparişler</h3>
            <span style="font-size:12px;color:var(--text-muted)"><?= count($orders) ?> sipariş</span>
        </div>
        <?php if (empty($orders)): ?>
        <div style="padding:40px;text-align:center;color:var(--text-muted)"><i data-lucide="package" style="width:40px;height:40px;margin:0 auto 8px;display:block"></i>Henüz sipariş yok</div>
        <?php else: foreach ($orders as $o):
            $st = $o['status'] ?? '';
            $sc = $stMap[$st] ?? ['l'=>$st,'c'=>'#64748b','bg'=>'#f1f5f9'];
        ?>
        <a href="/orders/<?= $o['id'] ?>" class="cd-order">
            <div style="flex:1">
                <div style="font-weight:700;font-family:monospace;font-size:13px;color:var(--primary)">#<?= htmlspecialchars($o['marketplace_order_id'] ?? '') ?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= date('d.m.Y H:i', strtotime($o['ordered_at'] ?? $o['created_at'] ?? 'now')) ?> · <?= htmlspecialchars($o['marketplace_name'] ?? '') ?></div>
            </div>
            <div style="font-weight:700;font-size:14px;min-width:90px;text-align:right"><?= number_format((float)($o['total_amount'] ?? 0), 2, ',', '.') ?> ₺</div>
            <span class="cd-badge" style="color:<?= $sc['c'] ?>;background:<?= $sc['bg'] ?>"><?= $sc['l'] ?></span>
        </a>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>
