<?php $returns = $data['returns'] ?? []; $r = $data['return'] ?? []; ?>
<div style="margin-bottom:16px"><a href="/returns" class="btn btn-sm btn-secondary">← İadelere Dön</a></div>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>↩️ İade Bilgileri</h3></div>
        <div class="card-body">
            <table style="width:100%">
                <tr><td class="text-muted" style="padding:6px 0;width:140px">İade No:</td><td class="fw-bold">#<?= htmlspecialchars($r['marketplace_return_id'] ?? '') ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Sipariş:</td><td><a href="/orders/<?= $r['order_id'] ?? '' ?>">#<?= $r['order_id'] ?? '' ?></a></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Durum:</td><td><?php $s = $r['status'] ?? ''; echo "<span class=\"badge " . match($s) { 'pending','requested' => 'badge-warning', 'approved','completed' => 'badge-success', 'rejected' => 'badge-danger', 'refunded' => 'badge-purple', default => 'badge-secondary' } . "\">{$s}</span>"; ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Sebep:</td><td><?= htmlspecialchars($r['reason'] ?? '—') ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Tutar:</td><td class="fw-bold"><?= number_format((float) ($r['refund_amount'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Tarih:</td><td><?= date('d.m.Y H:i', strtotime($r['created_at'] ?? 'now')) ?></td></tr>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>📦 İade Edilen Ürünler</h3></div>
        <div class="card-body">
            <?php $items = json_decode($r['items_json'] ?? '[]', true) ?: []; ?>
            <?php if (empty($items)): ?>
                <p class="text-muted">Ürün detayı yok</p>
            <?php else: foreach ($items as $item): ?>
                <div style="padding:8px 0;border-bottom:1px solid var(--border)">
                    <span class="fw-bold"><?= htmlspecialchars($item['name'] ?? $item['sku'] ?? '') ?></span>
                    <span class="text-muted" style="float:right"><?= (int) ($item['qty'] ?? 1) ?> adet</span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
