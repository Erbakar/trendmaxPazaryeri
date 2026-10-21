<?php
$order  = $data['order'] ?? [];
$items  = $data['items'] ?? [];
$customerOrders = $data['customerOrders'] ?? [];
$csrf   = $data['_csrf'] ?? '';
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canViewInvoices = $can('invoices.view');
$canCreateInvoice = $can('invoices.create');
$canSendInvoice = $can('invoices.send');
$canShipAction = $can('orders.ship');

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

$st    = $order['status'] ?? '';
$sc    = $stMap[$st] ?? ['l'=>$st,'c'=>'#64748b','bg'=>'#f1f5f9'];
$slug  = $order['marketplace_slug'] ?? '';
$raw   = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];
$ship  = json_decode($order['shipping_address_json'] ?? '{}', true) ?: [];
$bill  = json_decode($order['billing_address_json'] ?? '{}', true) ?: [];
$tyInv = $raw['invoiceLink'] ?? '';
$pkgId = $raw['shipmentPackageId'] ?? $raw['id'] ?? '';
$tLink = $raw['cargoTrackingLink'] ?? '';
$hasInv = !empty($order['invoice_id']);
$hasCust = !empty($order['customer_db_id']);
$shippingConfig = $data['shippingConfig'] ?? [];
$defaultCargo = $shippingConfig['default_cargo'] ?? '';
$tyCargoPayment = !empty($shippingConfig['ty_cargo_payment']);
$autoLabelEnabled = !empty($shippingConfig['auto_label']);
?>
<style>
.od-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;margin-bottom:20px;transition:color .15s}
.od-back:hover{color:var(--primary)}
/* Header */
.od-header{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;box-shadow:var(--shadow-card)}
.od-h-left{display:flex;align-items:center;gap:16px}
.od-h-id{font-size:22px;font-weight:700;font-family:'Fira Code',monospace;color:var(--text-primary)}
.od-h-meta{font-size:12px;color:var(--text-muted);margin-top:2px}
.od-h-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.od-h-total{text-align:right}
.od-h-total-val{font-size:24px;font-weight:700;color:var(--text-primary)}
.od-h-total-lbl{font-size:11px;color:var(--text-muted)}
.od-badge{display:inline-flex;align-items:center;padding:6px 14px;border-radius:16px;font-size:12px;font-weight:700}
.od-mp{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;border:1px solid var(--border)}
.od-mp img{width:16px;height:16px;object-fit:contain}
/* Grid */
.od-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
/* Card */
.od-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card);margin-bottom:20px}
.od-card-h{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg-input)}
.od-card-h h3{margin:0;font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.od-card-b{padding:20px}
/* Table */
.od-items{width:100%;border-collapse:collapse}
.od-items th{padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);text-align:left;border-bottom:1px solid var(--border)}
.od-items td{padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle}
.od-items tr:last-child td{border-bottom:none}
.od-items tfoot td{border-top:2px solid var(--border);font-weight:700;padding:12px 16px}
/* Info rows */
.od-info{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;gap:12px}
.od-info-l{font-size:13px;color:var(--text-muted);font-weight:500}
.od-info-r{font-size:13px;color:var(--text-primary);font-weight:700;text-align:right}
/* Customer */
.od-cust-profile{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.od-cust-avatar{width:48px;height:48px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0}
.od-cust-name{font-size:15px;font-weight:700;color:var(--text-primary)}
.od-cust-email{font-size:12px;color:var(--text-muted)}
.od-cust-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.od-cstat{background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center}
.od-cstat-val{font-size:18px;font-weight:700;color:var(--primary)}
.od-cstat-lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
/* Buttons */
.od-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.od-btn-primary{background:var(--primary);color:#fff}.od-btn-primary:hover{opacity:.9}
.od-btn-success{background:#10b981;color:#fff}.od-btn-success:hover{background:#059669}
.od-btn-warning{background:#f59e0b;color:#fff}.od-btn-warning:hover{background:#d97706}
.od-btn-danger{background:#ef4444;color:#fff}.od-btn-danger:hover{background:#dc2626}
.od-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.od-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.od-btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}
/* Address */
.od-addr{font-size:13px;line-height:1.7;color:var(--text-primary)}
.od-addr strong{display:block;margin-bottom:4px}
@media(max-width:1024px){.od-grid{grid-template-columns:1fr}}
</style>

<a href="/orders" class="od-back"><i data-lucide="arrow-left" style="width:16px;height:16px"></i> Siparişler</a>

<!-- Header -->
<div class="od-header">
    <div class="od-h-left">
        <div>
            <div class="od-h-id">#<?= htmlspecialchars($order['marketplace_order_id'] ?? '') ?></div>
            <div class="od-h-meta"><?= date('d.m.Y H:i', strtotime($order['ordered_at'] ?? $order['created_at'] ?? 'now')) ?></div>
        </div>
        <span class="od-mp" style="padding:6px 10px;background:#fff">
            <img src="/public/assets/img/marketplaces/<?= $slug ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $slug ?>.jpg'" style="height:20px;width:80px;object-fit:contain">
        </span>
        <span class="od-badge" style="color:<?= $sc['c'] ?>;background:<?= $sc['bg'] ?>"><?= $sc['l'] ?></span>
    </div>
    <div class="od-h-right">
        <div class="od-h-total">
            <div class="od-h-total-val"><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', '.') ?> ₺</div>
            <div class="od-h-total-lbl">Toplam Tutar</div>
        </div>
        <?php if (!$hasInv && $canCreateInvoice): ?>
        <form method="POST" action="/orders/<?= $order['id'] ?>/invoice" style="display:inline">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="od-btn od-btn-warning" onclick="return confirm('e-Arşiv fatura kesilecek. Onaylıyor musunuz?')"><i data-lucide="file-plus" style="width:16px;height:16px"></i> Fatura Kes</button>
        </form>
        <?php elseif ($hasInv && $canViewInvoices): ?>
        <a href="/invoices/<?= $order['invoice_id'] ?>/html" target="_blank" class="od-btn od-btn-success"><i data-lucide="file-text" style="width:16px;height:16px"></i> Görüntüle</a>
        <a href="/invoices/<?= $order['invoice_id'] ?>/download" class="od-btn od-btn-outline od-btn-sm"><i data-lucide="download" style="width:14px;height:14px"></i> İndir</a>
        <?php if ($slug === 'trendyol' && !$tyInv && !empty($pkgId) && $canSendInvoice): ?>
        <form method="POST" action="/orders/<?= $order['id'] ?>/send-invoice-to-marketplace" style="display:inline">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="od-btn od-btn-primary od-btn-sm" onclick="return confirm('Fatura Trendyol\'a gönderilecek. Emin misiniz?')"><i data-lucide="send" style="width:14px;height:14px"></i> Trendyol'a Gönder</button>
        </form>
        <?php elseif ($tyInv): ?>
        <a href="<?= htmlspecialchars($tyInv) ?>" target="_blank" class="od-btn od-btn-outline od-btn-sm"><i data-lucide="check-circle" style="width:14px;height:14px"></i> Trendyol'da Mevcut</a>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        // Sipariş iptali: kargolanmamış ve zaten iptal/iade olmayan siparişlerde göster
        // (RBAC: iptal orders.ship ister — AuthorizationService OrderController::cancelOrder)
        $odCancellable = $canShipAction
            && !in_array((string) ($order['status'] ?? ''), ['iptal', 'iade', 'kargoda', 'teslim_noktasinda', 'teslim_edildi'], true);
        ?>
        <?php if ($odCancellable): ?>
        <button type="button" class="od-btn od-btn-danger od-btn-sm" onclick="odCancelOrder()">
            <i data-lucide="x-circle" style="width:14px;height:14px"></i> Siparişi İptal Et
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="od-grid">
<!-- Sol Kolon -->
<div>
    <!-- Sipariş Kalemleri -->
    <div class="od-card">
        <div class="od-card-h">
            <h3><i data-lucide="shopping-bag" style="width:16px;height:16px"></i> Sipariş Kalemleri</h3>
            <span style="font-size:12px;color:var(--text-muted)"><?= count($items) ?> ürün</span>
        </div>
        <div style="overflow-x:auto">
        <table class="od-items">
        <thead><tr><th style="width:62px">Görsel</th><th>SKU</th><th>Ürün</th><th style="text-align:center">Adet</th><th style="text-align:right">Birim Fiyat</th><th style="text-align:right">İndirim</th><th style="text-align:right">Toplam</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
            <td>
                <?php if (!empty($item['item_image'])): ?>
                    <img
                        src="<?= htmlspecialchars((string) $item['item_image']) ?>"
                        alt="Ürün"
                        style="width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border)"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';"
                    >
                    <span style="display:none;width:44px;height:44px;border-radius:8px;border:1px solid var(--border);align-items:center;justify-content:center;color:var(--text-muted);background:var(--bg-input)">
                        <i data-lucide="image" style="width:16px;height:16px"></i>
                    </span>
                <?php else: ?>
                    <span style="display:inline-flex;width:44px;height:44px;border-radius:8px;border:1px solid var(--border);align-items:center;justify-content:center;color:var(--text-muted);background:var(--bg-input)">
                        <i data-lucide="image" style="width:16px;height:16px"></i>
                    </span>
                <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
            <td style="font-weight:700"><?= htmlspecialchars($item['name'] ?? '') ?></td>
            <td style="text-align:center"><span style="background:var(--bg-input);padding:3px 10px;border-radius:6px;font-weight:700"><?= (int)($item['qty'] ?? 1) ?></span></td>
            <td style="text-align:right"><?= number_format((float)($item['unit_price'] ?? 0), 2, ',', '.') ?> ₺</td>
            <td style="text-align:right;color:#ef4444"><?= (float)($item['discount'] ?? 0) > 0 ? '-'.number_format((float)$item['discount'], 2, ',', '.').' ₺' : '—' ?></td>
            <td style="text-align:right;font-weight:700"><?= number_format((float)($item['line_total'] ?? 0), 2, ',', '.') ?> ₺</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr><td colspan="6" style="text-align:right;color:var(--text-muted)">Ara Toplam</td><td style="text-align:right"><?= number_format((float)($order['subtotal'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
        <tr><td colspan="6" style="text-align:right;color:var(--text-muted)">KDV</td><td style="text-align:right"><?= number_format((float)($order['tax_total'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
        <tr><td colspan="6" style="text-align:right;font-size:15px">TOPLAM</td><td style="text-align:right;font-size:17px;color:var(--primary)"><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
        </tfoot>
        </table>
        </div>
    </div>

    <!-- Kargo & Teslimat -->
    <div class="od-card">
        <div class="od-card-h">
            <h3><i data-lucide="truck" style="width:16px;height:16px"></i> Kargo & Teslimat</h3>
            <?php
            $canShip = $canShipAction && in_array($st, ['yeni','beklemede','hazirlaniyor','faturalandi']);
            $isShipped = in_array($st, ['kargoda','teslim_noktasinda','teslim_edildi']);
            $cargoProvider = $raw['cargoProviderName'] ?? $order['cargo_company'] ?? '';
            ?>
            <?php if ($canShip && $slug === 'trendyol'): ?>
            <button class="od-btn od-btn-success od-btn-sm" onclick="openShipModal()">
                <i data-lucide="send" style="width:14px;height:14px"></i> Kargola
            </button>
            <?php elseif ($canShip && $slug === 'n11'): ?>
            <button class="od-btn od-btn-success od-btn-sm" onclick="openShipModal()">
                <i data-lucide="check-circle" style="width:14px;height:14px"></i> Onayla
            </button>
            <?php elseif ($canShip && $slug === 'pttavm'): ?>
            <button class="od-btn od-btn-success od-btn-sm" onclick="openShipModal()">
                <i data-lucide="send" style="width:14px;height:14px"></i> Kargola
            </button>
            <?php endif; ?>
        </div>
        <div class="od-card-b">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:10px">Teslimat Adresi</div>
                    <?php if (!empty($ship)): ?>
                    <div class="od-addr">
                        <strong><?= htmlspecialchars($ship['name'] ?? $order['customer_name'] ?? '') ?></strong>
                        <?= htmlspecialchars($ship['address'] ?? '') ?><br>
                        <?= htmlspecialchars(($ship['district'] ?? '') . ' / ' . ($ship['city'] ?? '')) ?>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:13px">Adres bilgisi yok</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:10px">Kargo Bilgisi</div>
                    <?php if (!empty($order['cargo_company']) || $isShipped): ?>
                    <div class="od-info"><span class="od-info-l">Firma</span><span class="od-info-r"><?= htmlspecialchars($order['cargo_company'] ?: $cargoProvider) ?></span></div>
                    <?php if (!empty($order['cargo_tracking_no'])): ?>
                    <div class="od-info"><span class="od-info-l">Takip No</span><span class="od-info-r" style="font-family:monospace;font-size:12px"><?= htmlspecialchars($order['cargo_tracking_no']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($order['cargo_sent_at'])): ?>
                    <div class="od-info"><span class="od-info-l">Gönderim</span><span class="od-info-r"><?= date('d.m.Y H:i', strtotime($order['cargo_sent_at'])) ?></span></div>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                        <?php if (!empty($tLink)): ?>
                        <a href="<?= htmlspecialchars($tLink) ?>" target="_blank" class="od-btn od-btn-primary od-btn-sm"><i data-lucide="external-link" style="width:14px;height:14px"></i> Kargo Takip</a>
                        <?php endif; ?>
                        <?php if ($tyCargoPayment || $slug === 'n11' || $slug === 'pazarama' || $slug === 'pttavm'): ?>
                        <a href="/orders/<?= $order['id'] ?>/cargo-label" class="od-btn od-btn-outline od-btn-sm"><i data-lucide="tag" style="width:14px;height:14px"></i> Etiket İndir</a>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($canShip): ?>
                    <div style="text-align:center;padding:20px 0">
                        <i data-lucide="package" style="width:32px;height:32px;color:var(--text-muted);opacity:.4;margin-bottom:8px"></i>
                        <p style="font-size:13px;color:var(--text-muted);margin:0">Henüz kargoya verilmedi</p>
                        <?php if ($slug === 'trendyol'): ?>
                        <button class="od-btn od-btn-success" style="margin-top:12px" onclick="openShipModal()">
                            <i data-lucide="send" style="width:14px;height:14px"></i> Şimdi Kargola
                        </button>
                        <?php elseif ($slug === 'n11'): ?>
                        <button class="od-btn od-btn-success" style="margin-top:12px" onclick="openShipModal()">
                            <i data-lucide="check-circle" style="width:14px;height:14px"></i> Siparişi Onayla
                        </button>
                        <?php elseif ($slug === 'pttavm'): ?>
                        <button class="od-btn od-btn-success" style="margin-top:12px" onclick="openShipModal()">
                            <i data-lucide="send" style="width:14px;height:14px"></i> PttAVM Kargola
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:13px">Kargo bilgisi yok</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sağ Kolon -->
<div>
    <!-- Müşteri -->
    <div class="od-card">
        <div class="od-card-h">
            <h3><i data-lucide="user" style="width:16px;height:16px"></i> Müşteri</h3>
            <?php if ($hasCust): ?>
            <span style="background:#f0fdf4;color:#16a34a;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700">Kayıtlı</span>
            <?php endif; ?>
        </div>
        <div class="od-card-b">
            <div class="od-cust-profile">
                <div class="od-cust-avatar"><?= strtoupper(mb_substr($order['customer_name'] ?? 'M', 0, 1)) ?></div>
                <div>
                    <div class="od-cust-name"><?= htmlspecialchars($order['customer_name'] ?? '—') ?></div>
                    <div class="od-cust-email"><?= htmlspecialchars($order['customer_email'] ?? '') ?></div>
                    <?php if (!empty($order['customer_phone'])): ?>
                    <div class="od-cust-email"><?= htmlspecialchars($order['customer_phone']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($hasCust): ?>
            <div class="od-cust-stats">
                <div class="od-cstat">
                    <div class="od-cstat-val"><?= (int)($order['customer_total_orders'] ?? 0) ?></div>
                    <div class="od-cstat-lbl">Toplam Sipariş</div>
                </div>
                <div class="od-cstat">
                    <div class="od-cstat-val"><?= number_format((float)($order['customer_total_spent'] ?? 0), 0, ',', '.') ?>₺</div>
                    <div class="od-cstat-lbl">Toplam Harcama</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($order['customer_id_no'])): ?>
            <div class="od-info"><span class="od-info-l">TC / Vergi No</span><span class="od-info-r"><?= htmlspecialchars($order['customer_id_no']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sipariş Özeti -->
    <div class="od-card">
        <div class="od-card-h"><h3><i data-lucide="clipboard-list" style="width:16px;height:16px"></i> Sipariş Özeti</h3></div>
        <div class="od-card-b">
            <div class="od-info"><span class="od-info-l">Sipariş No</span><span class="od-info-r" style="font-family:monospace">#<?= htmlspecialchars($order['marketplace_order_id'] ?? '') ?></span></div>
            <div class="od-info"><span class="od-info-l">Pazaryeri</span><span class="od-info-r"><img src="/public/assets/img/marketplaces/<?= $slug ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $slug ?>.jpg'" style="height:18px;width:70px;object-fit:contain"></span></div>
            <div class="od-info"><span class="od-info-l">Tarih</span><span class="od-info-r"><?= date('d.m.Y H:i', strtotime($order['ordered_at'] ?? $order['created_at'] ?? 'now')) ?></span></div>
            <div class="od-info"><span class="od-info-l">Durum</span><span class="od-info-r"><span class="od-badge" style="color:<?= $sc['c'] ?>;background:<?= $sc['bg'] ?>;padding:3px 10px;font-size:11px"><?= $sc['l'] ?></span></span></div>
            <div class="od-info"><span class="od-info-l">Fatura</span><span class="od-info-r"><?= $hasInv ? '<span style="color:#10b981">✅ Kesildi</span>' : '<span style="color:var(--text-muted)">Henüz yok</span>' ?></span></div>
            <?php if ($pkgId): ?>
            <div class="od-info"><span class="od-info-l">Paket ID</span><span class="od-info-r" style="font-family:monospace;font-size:11px"><?= htmlspecialchars((string)$pkgId) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Önceki Siparişler -->
    <?php if ($hasCust && count($customerOrders) > 1): ?>
    <div class="od-card">
        <div class="od-card-h">
            <h3><i data-lucide="history" style="width:16px;height:16px"></i> Önceki Siparişler</h3>
            <span style="font-size:12px;color:var(--text-muted)"><?= count($customerOrders) - 1 ?> sipariş</span>
        </div>
        <div class="od-card-b" style="padding:12px">
            <?php foreach (array_slice($customerOrders, 0, 5) as $co):
                if ((int)$co['id'] === (int)$order['id']) continue;
                $coSt = $stMap[$co['status'] ?? ''] ?? ['c'=>'#64748b','l'=>$co['status']??''];
            ?>
            <a href="/orders/<?= $co['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-radius:8px;text-decoration:none;color:inherit;border:1px solid var(--border);margin-bottom:6px;transition:all .12s">
                <div>
                    <div style="font-weight:700;font-size:12px;font-family:monospace;color:var(--primary)">#<?= htmlspecialchars($co['marketplace_order_id'] ?? '') ?></div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= date('d.m.Y', strtotime($co['ordered_at'] ?? $co['created_at'] ?? 'now')) ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-weight:700;font-size:13px"><?= number_format((float)($co['total_amount'] ?? 0), 2, ',', '.') ?> ₺</div>
                    <div style="font-size:10px;color:<?= $coSt['c'] ?>;margin-top:2px"><?= $coSt['l'] ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
/* Siparişi iptal et — pazaryeri BAĞIMSIZ blok (Trendyol-özel script bloğuna koyma!)
   Destekleyen pazaryerine iptal bildirir, yerel durumu + stoğu günceller */
async function odCancelOrder() {
    if (!confirm('Sipariş iptal edilecek. Pazaryeri destekliyorsa iptal pazaryerine de bildirilir ve stok iade edilir. Emin misiniz?')) return;

    const btn = event.target.closest('button');
    if (btn) { btn.disabled = true; btn.textContent = 'İptal ediliyor...'; }

    try {
        const fd = new FormData();
        fd.append('_csrf_token', '<?= htmlspecialchars($csrf) ?>');

        const res = await fetch('/orders/<?= (int) $order['id'] ?>/cancel', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const d = await res.json();

        if (d.success) {
            Toast.show(d.message || 'Sipariş iptal edildi', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            Toast.show(d.message || 'İptal başarısız', 'error', 8000);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="x-circle" style="width:14px;height:14px"></i> Siparişi İptal Et';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        }
    } catch (e) {
        Toast.show('Bağlantı hatası: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Siparişi İptal Et'; }
    }
}
</script>

<?php if ($canShip && $slug === 'trendyol'): ?>
<!-- Kargola Modal -->
<div class="ship-overlay" id="shipOverlay" onclick="if(event.target===this)closeShipModal()">
<div class="ship-modal">
    <div class="ship-modal-h">
        <h3><i data-lucide="truck" style="width:18px;height:18px"></i> Siparişi Kargola</h3>
        <button onclick="closeShipModal()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px">&times;</button>
    </div>
    <div class="ship-modal-b">
        <!-- Form -->
        <div id="shipForm">
            <div class="ship-info">
                <span>Sipariş</span>
                <strong>#<?= htmlspecialchars($order['marketplace_order_id'] ?? '') ?></strong>
            </div>
            <div class="ship-info">
                <span>Müşteri</span>
                <strong><?= htmlspecialchars($order['customer_name'] ?? '') ?></strong>
            </div>
            <div class="ship-info">
                <span>Tutar</span>
                <strong><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', '.') ?> ₺</strong>
            </div>
            <?php if ($cargoProvider): ?>
            <div class="ship-info" style="background:#f0fdf4;border-color:#bbf7d0">
                <span style="color:#166534">Trendyol Kargo</span>
                <strong style="color:#166534"><?= htmlspecialchars($cargoProvider) ?></strong>
            </div>
            <?php endif; ?>

            <div style="margin-top:16px">
                <label style="display:block;font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:4px">Kargo Firması *</label>
                <select id="shipCargo" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary)">
                    <option value="">Seçin...</option>
                    <option value="TEXKARGO" <?= stripos($cargoProvider, 'tex') !== false ? 'selected' : '' ?>>TEX Kargo</option>
                    <option value="ARASKARGO" <?= stripos($cargoProvider, 'aras') !== false ? 'selected' : '' ?>>Aras Kargo</option>
                    <option value="YKARGO" <?= stripos($cargoProvider, 'yurtiçi') !== false || stripos($cargoProvider, 'yurtici') !== false ? 'selected' : '' ?>>Yurtiçi Kargo</option>
                    <option value="SURATCARGO" <?= stripos($cargoProvider, 'sürat') !== false ? 'selected' : '' ?>>Sürat Kargo</option>
                    <option value="MNGKARGO" <?= stripos($cargoProvider, 'mng') !== false ? 'selected' : '' ?>>MNG Kargo</option>
                    <option value="PTTKARGO" <?= stripos($cargoProvider, 'ptt') !== false ? 'selected' : '' ?>>PTT Kargo</option>
                    <option value="HOROZ" <?= stripos($cargoProvider, 'horoz') !== false ? 'selected' : '' ?>>Horoz Lojistik</option>
                    <option value="CEVA" <?= stripos($cargoProvider, 'ceva') !== false ? 'selected' : '' ?>>CEVA Lojistik</option>
                    <option value="SENDEO">Sendeo</option>
                </select>
            </div>

            <div style="margin-top:12px">
                <label style="display:block;font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:4px">Kargo Takip No *</label>
                <input type="text" id="shipTrackingNo" placeholder="Takip numarasını girin" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;font-family:monospace;background:var(--bg-input);color:var(--text-primary);box-sizing:border-box">
            </div>

            <button class="od-btn od-btn-success" style="width:100%;justify-content:center;margin-top:16px;padding:12px" onclick="executeShip()">
                <i data-lucide="send" style="width:16px;height:16px"></i> Kargola & Trendyol'a Bildir
            </button>
        </div>

        <!-- Progress -->
        <div id="shipProgress" style="display:none">
            <div class="ship-steps" id="shipSteps"></div>
            <div id="shipResult" style="display:none;margin-top:16px;text-align:center"></div>
        </div>
    </div>
</div>
</div>

<style>
.ship-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center}
.ship-overlay.show{display:flex}
.ship-modal{background:var(--bg-card);border-radius:12px;width:480px;max-width:95vw;box-shadow:0 0 0 1px var(--border);overflow:hidden;animation:slideUp .25s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.ship-modal-h{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input)}
.ship-modal-h h3{margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--text-primary)}
.ship-modal-b{padding:20px}
.ship-info{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;font-size:13px}
.ship-info span{color:var(--text-muted)}
.ship-info strong{color:var(--text-primary)}
.ship-steps{display:flex;flex-direction:column;gap:8px}
.ship-step{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;font-weight:700;transition:all .3s}
.ship-step.pending{color:var(--text-muted);border-style:dashed}
.ship-step.active{color:var(--primary);border-color:var(--primary);background:rgb(var(--brand) / .05)}
.ship-step.done{color:#10b981;border-color:#bbf7d0;background:#f0fdf4}
.ship-step.fail{color:#ef4444;border-color:#fecaca;background:#fef2f2}
.ship-step-icon{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.ship-step.pending .ship-step-icon{background:#f1f5f9;color:#94a3b8}
.ship-step.active .ship-step-icon{background:var(--primary);color:#fff}
.ship-step.done .ship-step-icon{background:#10b981;color:#fff}
.ship-step.fail .ship-step-icon{background:#ef4444;color:#fff}
</style>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const ORDER_ID = <?= (int)$order['id'] ?>;
const DEFAULT_CARGO = '<?= htmlspecialchars((string) $defaultCargo, ENT_QUOTES) ?>';
const EXISTING_TRACKING = '<?= htmlspecialchars((string) ($order['cargo_tracking_no'] ?? ''), ENT_QUOTES) ?>';
const TY_CARGO_PAYMENT = <?= $tyCargoPayment ? 'true' : 'false' ?>;
const AUTO_LABEL_ENABLED = <?= $autoLabelEnabled ? 'true' : 'false' ?>;

function openShipModal() {
    document.getElementById('shipOverlay').classList.add('show');
    document.getElementById('shipForm').style.display = '';
    document.getElementById('shipProgress').style.display = 'none';
    const cargoSelect = document.getElementById('shipCargo');
    const trackingInput = document.getElementById('shipTrackingNo');
    if (cargoSelect && !cargoSelect.value && DEFAULT_CARGO) cargoSelect.value = DEFAULT_CARGO;
    if (trackingInput && !trackingInput.value && EXISTING_TRACKING) trackingInput.value = EXISTING_TRACKING;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeShipModal() {
    document.getElementById('shipOverlay').classList.remove('show');
}

async function executeShip() {
    const cargo = document.getElementById('shipCargo').value;
    const tracking = document.getElementById('shipTrackingNo').value.trim();

    if (!cargo) { Toast.show('Kargo firması seçin', 'error'); return; }
    if (!tracking) { Toast.show('Takip numarası girin', 'error'); return; }

    document.getElementById('shipForm').style.display = 'none';
    const progress = document.getElementById('shipProgress');
    progress.style.display = '';

    const steps = [
        { id: 'picking',  label: 'Sipariş toplama (Picking)' },
        { id: 'invoiced', label: 'Faturalandı (Invoiced)' },
        { id: 'shipped',  label: 'Kargoya verildi (Shipped)' },
        { id: 'label',    label: 'Kargo etiketi oluştur' },
    ];

    if (!TY_CARGO_PAYMENT) {
        steps.pop();
    }
    if (steps[0]) steps[0].label = 'Sipariş hazırlanıyor';
    if (steps[1]) steps[1].label = 'Faturalandı';
    if (steps[2]) steps[2].label = 'Kargoya verildi';
    if (steps[3]) steps[3].label = 'Kargo etiketi oluştur';

    const stepsEl = document.getElementById('shipSteps');
    stepsEl.innerHTML = steps.map((s, i) => `
        <div class="ship-step pending" id="step_${s.id}">
            <div class="ship-step-icon">${i + 1}</div>
            <span>${s.label}</span>
        </div>
    `).join('');

    // Animate steps
    for (const s of steps) {
        document.getElementById('step_' + s.id).className = 'ship-step active';
        await new Promise(r => setTimeout(r, 300));
    }

    // Reset all to pending
    steps.forEach(s => document.getElementById('step_' + s.id).className = 'ship-step pending');
    document.getElementById('step_picking').className = 'ship-step active';

    try {
        const fd = new FormData();
        fd.append('tracking_no', tracking);
        fd.append('cargo_company', cargo);
        fd.append('_csrf_token', CSRF);

        const res = await fetch(`/orders/${ORDER_ID}/ship`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const d = await res.json();

        if (d.success) {
            const data = d.data || {};
            const apiSteps = data.steps || [];

            // Map API results to UI steps
            const stepMap = { 'Picking': 'picking', 'Invoiced': 'invoiced', 'Shipped': 'shipped' };
            apiSteps.forEach(s => {
                const elId = stepMap[s.step];
                if (elId) {
                    const el = document.getElementById('step_' + elId);
                    el.className = 'ship-step ' + (s.ok ? 'done' : 'fail');
                    el.querySelector('.ship-step-icon').innerHTML = s.ok ? '✅' : '❌';
                }
            });

            // Label step
            const labelEl = document.getElementById('step_label');
            if (labelEl && data.labelAvailable) {
                labelEl.className = 'ship-step done';
                labelEl.querySelector('.ship-step-icon').innerHTML = '✅';
            } else if (labelEl) {
                labelEl.className = 'ship-step done';
                labelEl.querySelector('.ship-step-icon').innerHTML = '✅';
                labelEl.querySelector('span').innerHTML = 'Etiket hazır (kendi etiketiniz)';
            }

            if (labelEl && !data.labelAvailable) {
                labelEl.querySelector('span').innerHTML = TY_CARGO_PAYMENT
                    ? 'Etiket oluşmadı, özel etiket kullanın'
                    : 'TY ödemeli kapalı, özel etiket kullanın';
            }

            // Result
            const result = document.getElementById('shipResult');
            result.style.display = '';
            result.innerHTML = `
                <div style="margin-bottom:16px">
                    <i data-lucide="check-circle" style="width:40px;height:40px;color:#10b981"></i>
                    <p style="font-weight:700;font-size:15px;margin:8px 0 0;color:var(--text-primary)">Kargolama Tamamlandı!</p>
                    <p style="font-size:12px;color:var(--text-muted)">Takip No: ${data.trackingNo || tracking}</p>
                </div>
                <div style="display:flex;gap:8px;justify-content:center">
                    ${TY_CARGO_PAYMENT && data.labelAvailable ? `
                    <a href="/orders/${ORDER_ID}/cargo-label" class="od-btn od-btn-primary od-btn-sm">
                        <i data-lucide="tag" style="width:14px;height:14px"></i> Etiket İndir
                    </a>` : ''}
                    <button class="od-btn od-btn-outline od-btn-sm" onclick="location.reload()">
                        <i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Yenile
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
            if (data.labelAvailable && data.autoDownloadLabel) {
                window.open(`/orders/${ORDER_ID}/cargo-label`, '_blank');
            }
            Toast.show('Sipariş başarıyla kargoya verildi!', 'success');

        } else {
            Toast.show(d.message || 'Kargolama hatası', 'error');
            document.getElementById('shipForm').style.display = '';
            progress.style.display = 'none';
        }
    } catch (e) {
        Toast.show('Bağlantı hatası', 'error');
        document.getElementById('shipForm').style.display = '';
        progress.style.display = 'none';
    }
}
</script>
<?php endif; ?>

<?php if ($canShip && $slug === 'pttavm'): ?>
<!-- PttAVM Kargola Modal -->
<div class="ship-overlay" id="shipOverlay" onclick="if(event.target===this)closeShipModal()">
<div class="ship-modal">
    <div class="ship-modal-h">
        <h3><i data-lucide="truck" style="width:18px;height:18px"></i> PttAVM Kargola</h3>
        <button onclick="closeShipModal()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px">&times;</button>
    </div>
    <div class="ship-modal-b">
        <div id="shipForm">
            <div class="ship-info">
                <span>Sipariş</span>
                <strong>#<?= htmlspecialchars($order['marketplace_order_id'] ?? '') ?></strong>
            </div>
            <div class="ship-info">
                <span>Müşteri</span>
                <strong><?= htmlspecialchars($order['customer_name'] ?? '') ?></strong>
            </div>
            <div class="ship-info">
                <span>Tutar</span>
                <strong><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', '.') ?> ₺</strong>
            </div>

            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px;margin-top:16px">
                <div style="font-size:12px;font-weight:700;color:#c2410c;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                    <i data-lucide="info" style="width:14px;height:14px"></i> PttAVM Kargo Barkod
                </div>
                <div style="font-size:12px;color:#9a3412;line-height:1.5">
                    Bu işlem PttAVM API üzerinden kargo barkodu oluşturur ve siparişi kargoya hazır duruma alır. Barkod etiketini yazdırabilirsiniz.
                </div>
            </div>

            <button class="od-btn od-btn-success" style="width:100%;justify-content:center;margin-top:16px;padding:12px" onclick="executePttavmShip()">
                <i data-lucide="send" style="width:16px;height:16px"></i> Barkod Oluştur & Kargola
            </button>
        </div>

        <div id="shipProgress" style="display:none">
            <div class="ship-steps" id="shipSteps"></div>
            <div id="shipResult" style="display:none;margin-top:16px;text-align:center"></div>
        </div>
    </div>
</div>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const ORDER_ID = <?= (int)$order['id'] ?>;

function openShipModal() {
    document.getElementById('shipOverlay').classList.add('show');
    document.getElementById('shipForm').style.display = '';
    document.getElementById('shipProgress').style.display = 'none';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeShipModal() {
    document.getElementById('shipOverlay').classList.remove('show');
}

async function executePttavmShip() {
    document.getElementById('shipForm').style.display = 'none';
    const progress = document.getElementById('shipProgress');
    progress.style.display = '';

    const steps = [
        { id: 'create',  label: 'Kargo barkod oluşturuluyor...' },
        { id: 'check',   label: 'Barkod kontrol ediliyor...' },
        { id: 'done',    label: 'Tamamlandı' },
    ];

    const stepsEl = document.getElementById('shipSteps');
    stepsEl.innerHTML = steps.map((s, i) => `
        <div class="ship-step pending" id="step_${s.id}">
            <div class="ship-step-icon">${i + 1}</div>
            <span>${s.label}</span>
        </div>
    `).join('');

    document.getElementById('step_create').className = 'ship-step active';

    try {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);

        const res = await fetch(`/orders/${ORDER_ID}/ship`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const d = await res.json();

        if (d.success) {
            const data = d.data || {};
            const apiSteps = data.steps || [];

            apiSteps.forEach(s => {
                const map = { 'CreateBarcode': 'create', 'CheckBarcode': 'check' };
                const elId = map[s.step];
                if (elId) {
                    const el = document.getElementById('step_' + elId);
                    el.className = 'ship-step ' + (s.ok ? 'done' : 'fail');
                    el.querySelector('.ship-step-icon').innerHTML = s.ok ? '✅' : '❌';
                }
            });

            document.getElementById('step_done').className = 'ship-step done';
            document.getElementById('step_done').querySelector('.ship-step-icon').innerHTML = '✅';

            const result = document.getElementById('shipResult');
            result.style.display = '';
            result.innerHTML = `
                <div style="margin-bottom:16px">
                    <i data-lucide="check-circle" style="width:40px;height:40px;color:#10b981"></i>
                    <p style="font-weight:700;font-size:15px;margin:8px 0 0;color:var(--text-primary)">PttAVM Kargolama Tamamlandı!</p>
                    <p style="font-size:12px;color:var(--text-muted)">Kargo Barkod: ${data.trackingNo || '-'}</p>
                </div>
                <div style="display:flex;gap:8px;justify-content:center">
                    ${data.labelAvailable ? `
                    <a href="/orders/${ORDER_ID}/cargo-label" class="od-btn od-btn-primary od-btn-sm" target="_blank">
                        <i data-lucide="tag" style="width:14px;height:14px"></i> Etiket İndir
                    </a>` : ''}
                    <button class="od-btn od-btn-outline od-btn-sm" onclick="location.reload()">
                        <i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Yenile
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
            Toast.show('Kargo barkodu başarıyla oluşturuldu!', 'success');
        } else {
            Toast.show(d.message || 'PttAVM kargolama hatası', 'error');
            document.getElementById('shipForm').style.display = '';
            progress.style.display = 'none';
        }
    } catch (e) {
        Toast.show('Bağlantı hatası', 'error');
        document.getElementById('shipForm').style.display = '';
        progress.style.display = 'none';
    }
}
</script>
<?php endif; ?>

<?php if ($canShip && $slug === 'n11'): ?>
<!-- N11 Onaylama Modal -->
<?php
$n11CargoProvider = $raw['cargoProviderName'] ?? $order['cargo_company'] ?? '';
$n11TrackingNo = $raw['cargoTrackingNumber'] ?? $order['cargo_tracking_no'] ?? '';
$n11TrackingLink = $raw['cargoTrackingLink'] ?? '';
$n11LineCount = count($raw['lines'] ?? []);
?>
<div class="ship-overlay" id="shipOverlay" onclick="if(event.target===this)closeShipModal()">
<div class="ship-modal">
    <div class="ship-modal-h">
        <h3><i data-lucide="check-circle" style="width:18px;height:18px"></i> N11 Sipariş Onayla</h3>
        <button onclick="closeShipModal()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px">&times;</button>
    </div>
    <div class="ship-modal-b">
        <!-- Form -->
        <div id="shipForm">
            <div class="ship-info">
                <span>Sipariş</span>
                <strong>#<?= htmlspecialchars($order['marketplace_order_id'] ?? '') ?></strong>
            </div>
            <div class="ship-info">
                <span>Müşteri</span>
                <strong><?= htmlspecialchars($order['customer_name'] ?? '') ?></strong>
            </div>
            <div class="ship-info">
                <span>Tutar</span>
                <strong><?= number_format((float)($order['total_amount'] ?? 0), 2, ',', '.') ?> ₺</strong>
            </div>
            <div class="ship-info">
                <span>Ürün Kalemi</span>
                <strong><?= $n11LineCount ?> adet</strong>
            </div>
            <?php if ($n11CargoProvider): ?>
            <div class="ship-info" style="background:#f5f3ff;border-color:#c4b5fd">
                <span style="color:#5b21b6">N11 Kargo</span>
                <strong style="color:#5b21b6"><?= htmlspecialchars($n11CargoProvider) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($n11TrackingNo): ?>
            <div class="ship-info" style="background:#f0fdf4;border-color:#bbf7d0">
                <span style="color:#166534">Kargo Barkod</span>
                <strong style="color:#166534;font-family:monospace;font-size:12px"><?= htmlspecialchars($n11TrackingNo) ?></strong>
            </div>
            <?php endif; ?>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px;margin-top:16px">
                <div style="font-size:12px;font-weight:700;color:#1d4ed8;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                    <i data-lucide="info" style="width:14px;height:14px"></i> N11 Sipariş Onaylama
                </div>
                <div style="font-size:12px;color:#1e40af;line-height:1.5">
                    Bu işlem siparişi <strong>Picking</strong> (hazırlanıyor) durumuna alır. N11 kargolama işlemini otomatik yönetir — kargo bilgisi N11 tarafından sağlanır.
                </div>
            </div>

            <button class="od-btn od-btn-success" style="width:100%;justify-content:center;margin-top:16px;padding:12px" onclick="executeN11Ship()">
                <i data-lucide="check-circle" style="width:16px;height:16px"></i> Siparişi Onayla (Picking)
            </button>
        </div>

        <!-- Progress -->
        <div id="shipProgress" style="display:none">
            <div class="ship-steps" id="shipSteps"></div>
            <div id="shipResult" style="display:none;margin-top:16px;text-align:center"></div>
        </div>
    </div>
</div>
</div>

<style>
.ship-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center}
.ship-overlay.show{display:flex}
.ship-modal{background:var(--bg-card);border-radius:12px;width:480px;max-width:95vw;box-shadow:0 0 0 1px var(--border);overflow:hidden;animation:slideUp .25s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.ship-modal-h{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg-input)}
.ship-modal-h h3{margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--text-primary)}
.ship-modal-b{padding:20px}
.ship-info{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;font-size:13px}
.ship-info span{color:var(--text-muted)}
.ship-info strong{color:var(--text-primary)}
.ship-steps{display:flex;flex-direction:column;gap:8px}
.ship-step{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;font-size:13px;font-weight:700;transition:all .3s}
.ship-step.pending{color:var(--text-muted);border-style:dashed}
.ship-step.active{color:var(--primary);border-color:var(--primary);background:rgb(var(--brand) / .05)}
.ship-step.done{color:#10b981;border-color:#bbf7d0;background:#f0fdf4}
.ship-step.fail{color:#ef4444;border-color:#fecaca;background:#fef2f2}
.ship-step-icon{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.ship-step.pending .ship-step-icon{background:#f1f5f9;color:#94a3b8}
.ship-step.active .ship-step-icon{background:var(--primary);color:#fff}
.ship-step.done .ship-step-icon{background:#10b981;color:#fff}
.ship-step.fail .ship-step-icon{background:#ef4444;color:#fff}
</style>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const ORDER_ID = <?= (int)$order['id'] ?>;

function openShipModal() {
    document.getElementById('shipOverlay').classList.add('show');
    document.getElementById('shipForm').style.display = '';
    document.getElementById('shipProgress').style.display = 'none';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeShipModal() {
    document.getElementById('shipOverlay').classList.remove('show');
}

async function executeN11Ship() {
    document.getElementById('shipForm').style.display = 'none';
    const progress = document.getElementById('shipProgress');
    progress.style.display = '';

    const steps = [
        { id: 'picking',   label: 'Sipariş onaylama (Picking)' },
        { id: 'cargo',     label: 'Kargo bilgisi kaydet' },
    ];

    const stepsEl = document.getElementById('shipSteps');
    stepsEl.innerHTML = steps.map((s, i) => `
        <div class="ship-step pending" id="step_${s.id}">
            <div class="ship-step-icon">${i + 1}</div>
            <span>${s.label}</span>
        </div>
    `).join('');

    document.getElementById('step_picking').className = 'ship-step active';

    try {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);

        const res = await fetch(`/orders/${ORDER_ID}/ship`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const d = await res.json();

        if (d.success) {
            const data = d.data || {};
            const apiSteps = data.steps || [];

            // Map API results
            const stepMap = { 'Picking': 'picking', 'CargoInfo': 'cargo' };
            apiSteps.forEach(s => {
                const elId = stepMap[s.step];
                if (elId) {
                    const el = document.getElementById('step_' + elId);
                    if (el) {
                        el.className = 'ship-step ' + (s.ok ? 'done' : 'fail');
                        el.querySelector('.ship-step-icon').innerHTML = s.ok ? '✓' : '✗';
                        if (s.msg) {
                            el.querySelector('span').innerHTML += ` <small style="font-weight:400;color:var(--text-muted)">(${s.msg})</small>`;
                        }
                    }
                }
            });

            // Result
            const result = document.getElementById('shipResult');
            result.style.display = '';
            result.innerHTML = `
                <div style="margin-bottom:16px">
                    <i data-lucide="check-circle" style="width:40px;height:40px;color:#10b981"></i>
                    <p style="font-weight:700;font-size:15px;margin:8px 0 0;color:var(--text-primary)">Sipariş Onaylandı!</p>
                    ${data.trackingNo ? `<p style="font-size:12px;color:var(--text-muted)">Kargo Barkod: ${data.trackingNo}</p>` : ''}
                </div>
                <div style="display:flex;gap:8px;justify-content:center">
                    ${data.labelAvailable ? `
                    <a href="/orders/${ORDER_ID}/cargo-label" class="od-btn od-btn-primary od-btn-sm">
                        <i data-lucide="tag" style="width:14px;height:14px"></i> Etiket İndir
                    </a>` : ''}
                    <button class="od-btn od-btn-outline od-btn-sm" onclick="location.reload()">
                        <i data-lucide="refresh-cw" style="width:14px;height:14px"></i> Yenile
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
            if (data.labelAvailable && data.autoDownloadLabel) {
                window.open(`/orders/${ORDER_ID}/cargo-label`, '_blank');
            }
            Toast.show('N11 sipariş onaylandı!', 'success');
        } else {
            Toast.show(d.message || 'Onaylama hatası', 'error');
            document.getElementById('shipForm').style.display = '';
            progress.style.display = 'none';
        }
    } catch (e) {
        Toast.show('Bağlantı hatası', 'error');
        document.getElementById('shipForm').style.display = '';
        progress.style.display = 'none';
    }
}
</script>
<?php endif; ?>
