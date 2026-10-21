<?php
$stats = $data['stats'] ?? [];
$products = $data['products'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$marketplaceId = $data['marketplaceId'] ?? 0;
$filters = $data['filters'] ?? [];
$csrf = $data['_csrf'] ?? '';
?>

<!-- T8.1 — Özet Kartları -->
<div class="filter-bar" style="justify-content:space-between">
    <form method="GET" action="/enrichment" style="display:flex;gap:8px;align-items:end">
        <div class="form-group">
            <label class="form-label">Pazaryeri</label>
            <select name="marketplace_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($marketplaces as $mp): ?>
                    <option value="<?= $mp['id'] ?>" <?= (int)$mp['id'] === $marketplaceId ? 'selected' : '' ?>><?= htmlspecialchars($mp['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div style="display:flex;gap:8px">
        <form method="POST" action="/enrichment/bulk" style="display:inline">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="marketplace_id" value="<?= $marketplaceId ?>">
            <button type="submit" class="btn btn-primary btn-sm">✨ Toplu Zenginleştir</button>
        </form>
        <form method="POST" action="/enrichment/retry-failed" style="display:inline">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="marketplace_id" value="<?= $marketplaceId ?>">
            <button type="submit" class="btn btn-warning btn-sm">🔄 Başarısızları Tekrarla</button>
        </form>
    </div>
</div>

<!-- Stat Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px">
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:var(--text-primary)"><?= $stats['total'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Toplam Ürün</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#22c55e"><?= $stats['complete'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Tamamlanan</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#f59e0b"><?= $stats['partial'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Kısmi</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#94a3b8"><?= $stats['pending'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Bekleyen</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#ef4444"><?= $stats['missing_category'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Eksik Kategori</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#ef4444"><?= $stats['missing_brand'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Eksik Marka</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#f59e0b"><?= $stats['missing_images'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Eksik Görsel</div>
    </div>
    <div class="card" style="padding:16px;text-align:center">
        <div style="font-size:24px;font-weight:700;color:#f59e0b"><?= $stats['missing_price'] ?? 0 ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Eksik Fiyat</div>
    </div>
</div>

<!-- T8.3 — Filtreler -->
<div class="filter-bar">
    <form method="GET" action="/enrichment" style="display:contents">
        <input type="hidden" name="marketplace_id" value="<?= $marketplaceId ?>">
        <div class="form-group">
            <label class="form-label">Durum</label>
            <select name="status" class="form-select">
                <option value="">Tümü</option>
                <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Bekleyen</option>
                <option value="partial" <?= ($filters['status'] ?? '') === 'partial' ? 'selected' : '' ?>>Kısmi</option>
                <option value="complete" <?= ($filters['status'] ?? '') === 'complete' ? 'selected' : '' ?>>Tamamlanan</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label"><input type="checkbox" name="missing_category" value="1" <?= !empty($filters['missing_category']) ? 'checked' : '' ?>> Eksik Kategori</label>
        </div>
        <div class="form-group">
            <label class="form-label"><input type="checkbox" name="missing_brand" value="1" <?= !empty($filters['missing_brand']) ? 'checked' : '' ?>> Eksik Marka</label>
        </div>
        <div class="form-group">
            <label class="form-label"><input type="checkbox" name="missing_images" value="1" <?= !empty($filters['missing_images']) ? 'checked' : '' ?>> Eksik Görsel</label>
        </div>
        <div class="form-group">
            <label class="form-label"><input type="checkbox" name="missing_price" value="1" <?= !empty($filters['missing_price']) ? 'checked' : '' ?>> Eksik Fiyat</label>
        </div>
        <div class="form-group" style="flex:0">
            <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="search" style="width:14px;height:14px;display:inline"></i> Filtrele</button>
        </div>
    </form>
</div>

<!-- T8.2 — Ürün Tablosu -->
<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Ürün</th>
                    <th>Kategori</th>
                    <th>Marka</th>
                    <th>Görsel</th>
                    <th class="text-right">Fiyat</th>
                    <th class="text-right">Stok</th>
                    <th>Durum</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="9" class="empty-state"><div class="icon">✨</div><p>Tüm ürünler zenginleştirilmiş veya filtre sonucu boş</p></td></tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($p['sku'] ?? '') ?></td>
                        <td><a href="/products/<?= $p['id'] ?>" class="fw-bold"><?= htmlspecialchars(mb_substr($p['name'] ?? '', 0, 40)) ?></a></td>
                        <td><?php
                            if (!empty($p['category_name'])) {
                                echo '<span class="badge badge-success" style="font-size:10px">✅</span> ' . htmlspecialchars(mb_substr($p['category_name'], 0, 20));
                            } elseif ((int)($p['needs_category_mapping'] ?? 0)) {
                                echo '<span class="badge badge-danger" style="font-size:10px">❌ Eksik</span>';
                            } else {
                                echo '<span class="badge badge-secondary" style="font-size:10px">—</span>';
                            }
                        ?></td>
                        <td><?php
                            if (!empty($p['brand_name'])) {
                                echo '<span class="badge badge-success" style="font-size:10px">✅</span> ' . htmlspecialchars(mb_substr($p['brand_name'], 0, 20));
                            } elseif ((int)($p['needs_brand_mapping'] ?? 0)) {
                                echo '<span class="badge badge-danger" style="font-size:10px">❌ Eksik</span>';
                            } else {
                                echo '<span class="badge badge-secondary" style="font-size:10px">—</span>';
                            }
                        ?></td>
                        <td><?= !empty($p['main_image']) ? '<span class="badge badge-success" style="font-size:10px">✅</span>' : '<span class="badge badge-warning" style="font-size:10px">❌</span>' ?></td>
                        <td class="text-right"><?php
                            $price = (float)($p['base_price'] ?? 0);
                            if ($price > 0) {
                                echo number_format($price, 2, ',', '.') . ' ₺';
                            } else {
                                echo '<span class="badge badge-danger" style="font-size:10px">Eksik</span>';
                            }
                        ?></td>
                        <td class="text-right"><?php
                            $stock = (int)($p['stock_qty'] ?? 0);
                            echo $stock > 0 ? $stock : '<span class="badge badge-warning" style="font-size:10px">0</span>';
                        ?></td>
                        <td><?php
                            $es = $p['enrichment_status'] ?? 'pending';
                            $esCls = match($es) { 'complete' => 'badge-success', 'partial' => 'badge-warning', default => 'badge-secondary' };
                            $esLabel = match($es) { 'complete' => 'Tamam', 'partial' => 'Kısmi', default => 'Bekliyor' };
                            echo "<span class=\"badge {$esCls}\" style=\"font-size:10px\">{$esLabel}</span>";
                        ?></td>
                        <td>
                            <form method="POST" action="/enrichment/product/<?= $p['id'] ?>" style="display:inline">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES) ?>">
                                <input type="hidden" name="marketplace_id" value="<?= $marketplaceId ?>">
                                <button type="submit" class="btn btn-sm" title="Zenginleştir" style="padding:4px 8px;font-size:11px"><i data-lucide="sparkles" style="width:12px;height:12px"></i></button>
                            </form>
                            <a href="/products/<?= $p['id'] ?>" class="btn btn-sm" style="padding:4px 8px;font-size:11px" title="Detay"><i data-lucide="eye" style="width:12px;height:12px"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
