<?php
$movements = $data['movements'] ?? [];
$filters = $data['filters'] ?? [];
$total = $data['total'] ?? 0;
$page = $data['page'] ?? 1;
$totalPages = $data['totalPages'] ?? 1;
$csrf = $data['_csrf'] ?? '';
?>

<div class="filter-bar">
    <form method="GET" action="/products/stock-movements" style="display:contents">
        <div class="form-group">
            <label class="form-label">Hareket Tipi</label>
            <select name="movement_type" class="form-select">
                <option value="">Tümü</option>
                <option value="sale" <?= ($filters['movement_type'] ?? '') === 'sale' ? 'selected' : '' ?>>Satış</option>
                <option value="return" <?= ($filters['movement_type'] ?? '') === 'return' ? 'selected' : '' ?>>İade</option>
                <option value="manual_in" <?= ($filters['movement_type'] ?? '') === 'manual_in' ? 'selected' : '' ?>>Manuel Giriş</option>
                <option value="manual_out" <?= ($filters['movement_type'] ?? '') === 'manual_out' ? 'selected' : '' ?>>Manuel Çıkış</option>
                <option value="bulk_in" <?= ($filters['movement_type'] ?? '') === 'bulk_in' ? 'selected' : '' ?>>Toplu Giriş</option>
                <option value="bulk_out" <?= ($filters['movement_type'] ?? '') === 'bulk_out' ? 'selected' : '' ?>>Toplu Çıkış</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Başlangıç</label>
            <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Bitiş</label>
            <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
        </div>
        <div class="form-group" style="flex:0">
            <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrele</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr><th>Tarih</th><th>Ürün</th><th>Tip</th><th>Kaynak</th><th class="text-right">Değişim</th><th class="text-right">Önce</th><th class="text-right">Sonra</th><th>Not</th></tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="8" class="empty-state"><p>Stok hareketi bulunamadı</p></td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                    <tr>
                        <td style="font-size:12px;white-space:nowrap"><?= htmlspecialchars($m['created_at'] ?? '') ?></td>
                        <td><a href="/products/<?= $m['product_id'] ?? '' ?>"><?= htmlspecialchars(mb_substr($m['product_name'] ?? $m['sku'] ?? '', 0, 30)) ?></a></td>
                        <td><span class="badge badge-<?= str_contains($m['movement_type'] ?? '', 'in') || ($m['movement_type'] ?? '') === 'return' ? 'success' : 'danger' ?>" style="font-size:10px"><?= htmlspecialchars($m['movement_type'] ?? '') ?></span></td>
                        <td style="font-size:12px"><?= htmlspecialchars($m['source'] ?? '') ?></td>
                        <td class="text-right fw-bold" style="color:<?= (int)($m['qty_change'] ?? 0) >= 0 ? '#22c55e' : '#ef4444' ?>"><?= (int)($m['qty_change'] ?? 0) > 0 ? '+' : '' ?><?= (int)($m['qty_change'] ?? 0) ?></td>
                        <td class="text-right"><?= (int)($m['qty_before'] ?? 0) ?></td>
                        <td class="text-right"><?= (int)($m['qty_after'] ?? 0) ?></td>
                        <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(mb_substr($m['note'] ?? '', 0, 50)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="padding:12px;display:flex;justify-content:center;gap:4px">
        <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
            <a href="?page=<?= $i ?>&<?= http_build_query(array_filter($filters)) ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : '' ?>" style="padding:4px 10px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
