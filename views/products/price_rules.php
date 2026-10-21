<?php
$rules = $data['rules'] ?? [];
$marketplaces = $data['marketplaces'] ?? [];
$marketplaceId = $data['marketplaceId'] ?? 0;
$categories = $data['categories'] ?? [];
$csrf = $data['_csrf'] ?? '';
?>

<div class="filter-bar" style="justify-content:space-between">
    <form method="GET" action="/price-rules" style="display:flex;gap:8px;align-items:end">
        <div class="form-group">
            <label class="form-label">Pazaryeri</label>
            <select name="marketplace_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($marketplaces as $mp): ?>
                    <option value="<?= $mp['id'] ?>" <?= (int)$mp['id'] === $marketplaceId ? 'selected' : '' ?>><?= htmlspecialchars($mp['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr><th>Kural Adı</th><th>Tip</th><th>Yön</th><th>Değer</th><th>Min</th><th>Max</th><th>Kapsam</th><th>Öncelik</th><th>Durum</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($rules)): ?>
                    <tr><td colspan="10" class="empty-state"><p>Fiyat kuralı bulunamadı</p></td></tr>
                <?php else: ?>
                    <?php foreach ($rules as $r): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($r['name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['rule_type'] ?? '') ?></td>
                        <td><?= ($r['direction'] ?? '') === 'increase' ? '↑ Artış' : '↓ Azalış' ?></td>
                        <td class="fw-bold"><?= $r['value'] ?? 0 ?></td>
                        <td><?= $r['min_price'] !== null ? number_format((float)$r['min_price'], 2, ',', '.') . ' ₺' : '—' ?></td>
                        <td><?= $r['max_price'] !== null ? number_format((float)$r['max_price'], 2, ',', '.') . ' ₺' : '—' ?></td>
                        <td><span class="badge badge-secondary" style="font-size:10px"><?= htmlspecialchars($r['apply_to'] ?? 'all') ?></span></td>
                        <td class="text-center"><?= (int)($r['priority'] ?? 10) ?></td>
                        <td><?= (int)($r['is_active'] ?? 0) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?></td>
                        <td>
                            <form method="POST" action="/price-rules/<?= $r['id'] ?>/delete" style="display:inline">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px;font-size:11px" onclick="return confirm('Silmek istediğinize emin misiniz?')">✗</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
