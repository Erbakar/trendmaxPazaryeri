<?php
$invoices = $data['invoices'] ?? [];
$filters  = $data['filters'] ?? [];
$csrf     = $data['_csrf'] ?? '';
$rows     = $invoices['data'] ?? [];
$can = $data['_can'] ?? static fn(string $permission): bool => true;
$canViewCustomers = $can('customers.view');
$canCancelInvoice = $can('invoices.cancel');
$canManageInvoiceSettings = $can('invoices.create') || $can('settings.edit');

$stMap = [
    'draft'     => ['l' => 'Taslak', 'c' => '#f59e0b', 'bg' => '#fffbeb'],
    'sent'      => ['l' => 'Gönderildi', 'c' => '#10b981', 'bg' => '#f0fdf4'],
    'approved'  => ['l' => 'Onaylandı', 'c' => 'var(--primary)', 'bg' => '#eef2ff'],
    'cancelled' => ['l' => 'İptal', 'c' => '#ef4444', 'bg' => '#fef2f2'],
];

$flowMap = [
    'sale'   => ['l' => 'Satış', 'c' => '#2563eb', 'bg' => '#eff6ff'],
    'return' => ['l' => 'İade', 'c' => '#dc2626', 'bg' => '#fef2f2'],
];

$marketplaces    = $data['marketplaces'] ?? [];
$mpInvoiceCounts = $data['mpInvoiceCounts'] ?? [];
$totalInvoices   = $data['totalInvoices'] ?? 0;
$activeTab       = (string) ($data['activeTab'] ?? '');
$mpColors = ['trendyol'=>'#f27a1a','hepsiburada'=>'#ff6000','n11'=>'#7b2d96','amazon'=>'#ff9900','pazarama'=>'#00a8e1','pttavm'=>'#ffc107','ciceksepeti'=>'#e91e63'];
?>
<style>
.fi-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.fi-search{display:flex;align-items:center;gap:8px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 14px;width:240px;transition:border-color .15s}
.fi-search:focus-within{border-color:var(--primary)}
.fi-search input{border:none;outline:none;background:transparent;font-size:13px;width:100%;color:var(--text-primary);font-family:inherit}
.fi-search input::placeholder{color:var(--text-muted)}
.fi-sel{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);cursor:pointer;font-family:inherit;font-weight:500}
.fi-sel:focus{border-color:var(--primary);outline:none}
.fi-date{padding:8px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-card);color:var(--text-primary);font-family:inherit;width:140px}
.fi-date:focus{border-color:var(--primary);outline:none}
.fi-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.fi-btn-primary{background:var(--primary);color:#fff}.fi-btn-primary:hover{opacity:.9}
.fi-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.fi-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.fi-btn-sm{padding:6px 12px;font-size:12px;border-radius:8px}
.fi-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card)}
.fi-table{width:100%;border-collapse:collapse}
.fi-table thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.fi-table tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.fi-table tbody tr:last-child{border-bottom:none}
.fi-table tbody tr:hover{background:var(--bg-sidebar-hover)}
.fi-table td{padding:12px 16px;font-size:13px;vertical-align:middle}
.fi-no{font-weight:700;font-family:'Fira Code',monospace;color:var(--primary);font-size:13px}
.fi-cust{text-decoration:none;color:var(--text-primary);font-weight:700;font-size:12px;display:flex;align-items:center;gap:6px;transition:color .12s}
.fi-cust:hover{color:var(--primary)}
.fi-cust-tax{font-size:10px;color:var(--text-muted);font-family:monospace;margin-top:1px}
.fi-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;white-space:nowrap}
.fi-amount{font-weight:700;font-size:14px;color:var(--text-primary);white-space:nowrap}
.fi-ord{color:var(--primary);text-decoration:none;font-weight:700;font-size:12px;font-family:monospace}
.fi-ord:hover{text-decoration:underline}
.fi-actions{display:flex;gap:4px;flex-wrap:wrap}
.fi-ra{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);color:var(--text-secondary);background:transparent;cursor:pointer;transition:all .12s;white-space:nowrap;font-family:inherit}
.fi-ra:hover{border-color:var(--primary);color:var(--primary);background:var(--bg-sidebar-hover)}
.fi-ra-view{color:#06b6d4;border-color:#06b6d4}.fi-ra-view:hover{background:#ecfeff}
.fi-ra-dl{color:var(--primary);border-color:var(--primary)}.fi-ra-dl:hover{background:var(--bg-sidebar-hover)}
.fi-ra-cancel{color:#ef4444;border-color:#ef4444}.fi-ra-cancel:hover{background:#fef2f2}
.fi-pag{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)}
.fi-pag-links{display:flex;gap:3px}
.fi-pag-btn{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;color:var(--text-secondary);background:var(--bg-card);border:1px solid var(--border)}
.fi-pag-btn:hover{border-color:var(--primary);color:var(--primary)}
.fi-pag-btn.act{background:var(--primary);color:#fff;border-color:var(--primary)}
.fi-pag-btn.dis{opacity:.3;pointer-events:none}
.fi-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.fi-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center}
.fi-modal-bg.show{display:flex}
.fi-modal{background:var(--bg-card);border-radius:12px;width:90%;max-width:900px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 0 0 1px var(--border)}
.fi-modal-h{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border)}
.fi-modal-h h3{margin:0;font-size:15px;font-weight:700;color:var(--text-primary)}
.fi-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);padding:4px}
.fi-modal-close:hover{color:#ef4444}
.fi-modal-body{flex:1;overflow:auto;padding:0}
.fi-modal-body iframe{width:100%;height:70vh;border:none}
.fi-cancel-modal{width:420px}
.fi-cancel-modal .fi-modal-body{padding:20px}
.fi-cancel-ta{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;resize:vertical;min-height:60px;font-family:inherit;background:var(--bg-input);color:var(--text-primary)}
.fi-cancel-ta:focus{border-color:var(--primary);outline:none}
</style>

<form method="GET" action="/invoices" id="fiForm">

<!-- Pazaryeri Tabları -->
<div style="display:flex;gap:0;margin-bottom:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card)">
    <a href="/invoices?<?= http_build_query(array_merge($filters, ['marketplace_id' => '', 'page' => 1])) ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 24px;font-size:13px;font-weight:700;color:<?= $activeTab === '' ? 'var(--primary)' : 'var(--text-muted)' ?>;text-decoration:none;border-right:1px solid var(--border);white-space:nowrap;<?= $activeTab === '' ? 'background:var(--bg-sidebar-hover);box-shadow:inset 0 -3px 0 var(--primary)' : '' ?>">
        <i data-lucide="layers" style="width:16px;height:16px"></i> Tümü <span style="font-size:11px;background:<?= $activeTab === '' ? 'var(--primary)' : 'var(--bg-input)' ?>;color:<?= $activeTab === '' ? '#fff' : 'var(--text-muted)' ?>;padding:2px 8px;border-radius:10px;font-weight:700"><?= $totalInvoices ?></span>
    </a>
    <?php foreach ($marketplaces as $mp):
        $s = $mp['slug'] ?? '';
        $act = $activeTab === (string)$mp['id'];
        $tabColor = $mpColors[$s] ?? 'var(--primary)';
        $mpCnt = $mpInvoiceCounts[(int)$mp['id']] ?? 0;
        $isInactive = empty($mp['is_active']);
    ?>
    <a <?= $isInactive ? '' : 'href="/invoices?' . http_build_query(array_merge($filters, ['marketplace_id' => $mp['id'], 'page' => 1])) . '"' ?> style="display:flex;align-items:center;justify-content:center;padding:12px 24px;text-decoration:none;border-right:1px solid var(--border);min-width:80px;min-height:58px;<?= $act ? "background:var(--bg-sidebar-hover);box-shadow:inset 0 -3px 0 {$tabColor}" : '' ?>;transition:all .15s;position:relative;<?= $isInactive ? 'opacity:.4;pointer-events:none' : '' ?>" onmouseover="this.style.background='var(--bg-sidebar-hover)'" onmouseout="<?= $act ? '' : "this.style.background=''" ?>">
        <img src="/public/assets/img/marketplaces/<?= $s ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $s ?>.jpg'" style="height:28px;width:100px;object-fit:contain;opacity:<?= $act ? '1' : '.6' ?>;transition:opacity .15s">
        <?php if ($mpCnt > 0): ?>
        <span style="position:absolute;top:6px;right:8px;font-size:10px;background:<?= $tabColor ?>;color:#fff;padding:1px 6px;border-radius:8px;font-weight:700"><?= $mpCnt ?></span>
        <?php endif; ?>
        <?php if ($isInactive): ?>

        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="fi-toolbar">
    <div class="fi-search">
        <i data-lucide="search" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
        <input type="text" name="search" placeholder="Fatura no, müşteri..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
    </div>
    <select name="status" class="fi-sel" onchange="document.getElementById('fiForm').submit()">
        <option value="">Tüm Durumlar</option>
        <?php foreach ($stMap as $k => $c): ?>
        <option value="<?= $k ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= $c['l'] ?></option>
        <?php endforeach; ?>
    </select>
    <select name="invoice_flow" class="fi-sel" onchange="document.getElementById('fiForm').submit()">
        <option value="">Tüm Akışlar</option>
        <option value="sale" <?= ($filters['invoice_flow'] ?? '') === 'sale' ? 'selected' : '' ?>>Satış Faturası</option>
        <option value="return" <?= ($filters['invoice_flow'] ?? '') === 'return' ? 'selected' : '' ?>>İade Faturası</option>
    </select>
    <input type="date" name="date_from" class="fi-date" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" placeholder="Başlangıç">
    <input type="date" name="date_to" class="fi-date" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" placeholder="Bitiş">
    <button type="submit" class="fi-btn fi-btn-primary fi-btn-sm"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrele</button>
    <?php if (!empty(array_filter($filters))): ?>
    <a href="/invoices" class="fi-btn fi-btn-outline fi-btn-sm"><i data-lucide="x" style="width:14px;height:14px"></i> Temizle</a>
    <?php endif; ?>
    <?php if ($canManageInvoiceSettings): ?>
    <a href="/invoices/settings" class="fi-btn fi-btn-outline fi-btn-sm" style="margin-left:auto;background:#fff"><i data-lucide="settings" style="width:14px;height:14px"></i> Fatura Ayarları</a>
    <?php endif; ?>
</div>
</form>

<div class="fi-card">
<div style="overflow-x:auto">
<table class="fi-table">
<thead><tr>
    <th>Fatura No</th>
    <th>Müşteri</th>
    <th>Sipariş</th>
    <th>Pazaryeri</th>
    <th style="text-align:right">Tutar</th>
    <th>Durum</th>
    <th>Tarih</th>
    <th>İşlemler</th>
</tr></thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="8"><div class="fi-empty"><i data-lucide="file-text" style="width:48px;height:48px;margin:0 auto 12px;display:block"></i>Fatura bulunamadı</div></td></tr>
<?php else: foreach ($rows as $inv):
    $st = $inv['status'] ?? 'draft';
    $sc = $stMap[$st] ?? $stMap['draft'];
    $flow = (string) ($inv['invoice_flow'] ?? 'sale');
    $fc = $flowMap[$flow] ?? $flowMap['sale'];
    $invNo = (string) (($inv['series'] ?? '') . ($inv['sequence_no'] ?? ''));
?>
<tr>
    <td>
        <div style="display:flex;flex-direction:column;gap:6px">
            <span class="fi-no"><?= htmlspecialchars($invNo) ?></span>
            <span class="fi-badge" style="color:<?= $fc['c'] ?>;background:<?= $fc['bg'] ?>;width:max-content"><?= $fc['l'] ?></span>
            <?php if ($flow === 'return' && (!empty($inv['related_invoice_series']) || !empty($inv['related_invoice_seq']))): ?>
            <span style="font-size:11px;color:var(--text-muted)">İlgili satış: #<?= htmlspecialchars((string) ($inv['related_invoice_series'] ?? '') . (string) ($inv['related_invoice_seq'] ?? '')) ?></span>
            <?php endif; ?>
        </div>
    </td>
    <td>
        <div>
            <?php if ($canViewCustomers): ?>
            <a href="/customers/<?= $inv['order_customer_id'] ?? '' ?>" target="_blank" class="fi-cust">
                <i data-lucide="user" style="width:13px;height:13px"></i>
                <?= htmlspecialchars($inv['customer_name'] ?? '—') ?>
            </a>
            <?php else: ?>
            <div class="fi-cust" style="cursor:default"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></div>
            <?php endif; ?>
            <?php if (!empty($inv['customer_tax_no'])): ?>
            <div class="fi-cust-tax"><?= htmlspecialchars($inv['customer_tax_no']) ?></div>
            <?php endif; ?>
        </div>
    </td>
    <td><a href="/orders/<?= $inv['order_id'] ?? '' ?>" class="fi-ord">#<?= htmlspecialchars($inv['marketplace_order_id'] ?? (string) ($inv['order_id'] ?? '')) ?></a></td>
    <td style="text-align:center"><img src="/public/assets/img/marketplaces/<?= htmlspecialchars($inv['marketplace_slug'] ?? '') ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= htmlspecialchars($inv['marketplace_slug'] ?? '') ?>.jpg'" style="height:20px;width:80px;object-fit:contain;opacity:.8" title="<?= htmlspecialchars($inv['marketplace_name'] ?? '') ?>"></td>
    <td style="text-align:right"><span class="fi-amount">₺<?= number_format((float) ($inv['total'] ?? 0), 2, ',', '.') ?></span></td>
    <td><span class="fi-badge" style="color:<?= $sc['c'] ?>;background:<?= $sc['bg'] ?>"><?= $sc['l'] ?></span></td>
    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= date('d.m.Y H:i', strtotime($inv['issued_at'] ?? 'now')) ?></td>
    <td>
        <div class="fi-actions">
            <button type="button" class="fi-ra fi-ra-view" onclick="viewInv(<?= (int) $inv['id'] ?>,'<?= htmlspecialchars($invNo) ?>')"><i data-lucide="eye" style="width:13px;height:13px"></i> Görüntüle</button>
            <a href="/invoices/<?= (int) $inv['id'] ?>/download" class="fi-ra fi-ra-dl"><i data-lucide="download" style="width:13px;height:13px"></i> İndir</a>
            <?php if ($st !== 'cancelled' && $canCancelInvoice): ?>
            <button type="button" class="fi-ra fi-ra-cancel" onclick="cancelInv(<?= (int) $inv['id'] ?>,'<?= htmlspecialchars($invNo) ?>')"><i data-lucide="x-circle" style="width:13px;height:13px"></i> İptal</button>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<?php if (($invoices['totalPages'] ?? 1) > 1): ?>
<div class="fi-pag">
    <span>Toplam <?= number_format((int) ($invoices['total'] ?? 0)) ?> fatura · Sayfa <?= (int) ($invoices['page'] ?? 1) ?>/<?= (int) ($invoices['totalPages'] ?? 1) ?></span>
    <div class="fi-pag-links">
    <?php
    $cp = (int) ($invoices['page'] ?? 1);
    $tp = (int) ($invoices['totalPages'] ?? 1);
    $bu = '/invoices?' . http_build_query(array_merge($filters, ['page' => '']));
    echo "<a href='{$bu}" . ($cp - 1) . "' class='fi-pag-btn " . ($cp <= 1 ? 'dis' : '') . "'><i data-lucide='chevron-left' style='width:14px;height:14px'></i></a>";
    for ($i = max(1, $cp - 2); $i <= min($tp, $cp + 2); $i++) {
        echo "<a href='{$bu}{$i}' class='fi-pag-btn " . ($i === $cp ? 'act' : '') . "'>{$i}</a>";
    }
    echo "<a href='{$bu}" . ($cp + 1) . "' class='fi-pag-btn " . ($cp >= $tp ? 'dis' : '') . "'><i data-lucide='chevron-right' style='width:14px;height:14px'></i></a>";
    ?>
    </div>
</div>
<?php endif; ?>
</div>

<div class="fi-modal-bg" id="viewModal">
<div class="fi-modal">
    <div class="fi-modal-h">
        <h3 id="viewTitle"><i data-lucide="file-text" style="width:16px;height:16px"></i> Fatura</h3>
        <button type="button" class="fi-modal-close" onclick="closeView()"><i data-lucide="x" style="width:18px;height:18px"></i></button>
    </div>
    <div class="fi-modal-body"><iframe id="viewFrame" src="about:blank"></iframe></div>
</div>
</div>

<?php if ($canCancelInvoice): ?>
<div class="fi-modal-bg" id="cancelModal">
<div class="fi-modal fi-cancel-modal">
    <div class="fi-modal-h">
        <h3 id="cancelTitle"><i data-lucide="x-circle" style="width:16px;height:16px;color:#ef4444"></i> Fatura İptal</h3>
        <button type="button" class="fi-modal-close" onclick="closeCancel()"><i data-lucide="x" style="width:18px;height:18px"></i></button>
    </div>
    <div class="fi-modal-body">
        <form method="POST" id="cancelForm">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <p style="margin:0 0 12px;font-size:13px;color:var(--text-muted)">Bu faturayı iptal etmek istediğinize emin misiniz?</p>
            <textarea name="reason" class="fi-cancel-ta" placeholder="İptal sebebi (opsiyonel)..."></textarea>
            <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
                <button type="button" class="fi-btn fi-btn-outline fi-btn-sm" onclick="closeCancel()">Vazgeç</button>
                <button type="submit" class="fi-btn fi-btn-sm" style="background:#ef4444;color:#fff"><i data-lucide="x-circle" style="width:14px;height:14px"></i> İptal Et</button>
            </div>
        </form>
    </div>
</div>
</div>
<?php endif; ?>

<script>
function viewInv(id, no) {
    document.getElementById('viewTitle').innerHTML = '<i data-lucide="file-text" style="width:16px;height:16px"></i> Fatura #' + no;
    document.getElementById('viewFrame').src = '/invoices/' + id + '/html';
    document.getElementById('viewModal').classList.add('show');
    lucide.createIcons();
}

function closeView() {
    document.getElementById('viewModal').classList.remove('show');
    document.getElementById('viewFrame').src = 'about:blank';
}

<?php if ($canCancelInvoice): ?>
function cancelInv(id, no) {
    document.getElementById('cancelTitle').innerHTML = '<i data-lucide="x-circle" style="width:16px;height:16px;color:#ef4444"></i> Fatura İptal — #' + no;
    document.getElementById('cancelForm').action = '/invoices/' + id + '/cancel';
    document.getElementById('cancelModal').classList.add('show');
    lucide.createIcons();
}

function closeCancel() {
    document.getElementById('cancelModal').classList.remove('show');
}

document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancel();
});
<?php endif; ?>

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeView();
});
</script>
