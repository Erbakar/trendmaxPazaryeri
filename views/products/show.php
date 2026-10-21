<?php
$p = $data['product'] ?? [];
$mpProducts = $data['mpProducts'] ?? [];
$movements = $data['movements'] ?? [];
$attributes = $data['attributes'] ?? [];
$variants = $data['variants'] ?? [];
$marketplaceStatus = $data['marketplaceStatus'] ?? [];

// Parse images
$images = [];
if (!empty($p['images_json'])) {
    $decoded = json_decode($p['images_json'], true);
    if (is_array($decoded)) {
        $images = $decoded;
    }
}
$mainImage = $p['main_image'] ?? ($images[0]['url'] ?? ($images[0] ?? ''));
?>

<div style="margin-bottom:16px" class="d-flex gap-2">
    <a href="/products" class="btn btn-sm btn-secondary">← Ürünlere Dön</a>
    <a href="/products/<?= $p['id'] ?>/edit" class="btn btn-sm btn-primary">✏️ Düzenle</a>
</div>

<!-- === TOP SECTION: Image + Info === -->
<div class="grid-7-5" style="margin-bottom:20px">
    <div class="card">
        <div class="card-header">
            <h3>🏷️ Ürün Bilgileri</h3>
            <div>
                <?= (int) ($p['is_active'] ?? 0) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex gap-3">
                <!-- Image -->
                <div style="flex-shrink:0">
                    <?php if (!empty($mainImage)): ?>
                        <img src="<?= htmlspecialchars($mainImage) ?>" alt="<?= htmlspecialchars($p['name'] ?? '') ?>" class="product-thumb" style="width:100px;height:100px;border-radius:var(--radius-md)" onclick="openLightbox('<?= htmlspecialchars($mainImage) ?>')">
                    <?php else: ?>
                        <div class="product-thumb-empty" style="width:100px;height:100px;font-size:32px;border-radius:var(--radius-md)">📷</div>
                    <?php endif; ?>

                    <!-- Mini gallery -->
                    <?php if (count($images) > 1): ?>
                    <div class="d-flex gap-1" style="margin-top:6px;flex-wrap:wrap;max-width:100px">
                        <?php for ($i = 1; $i < min(count($images), 5); $i++):
                            $imgUrl = is_array($images[$i]) ? ($images[$i]['url'] ?? '') : $images[$i];
                            if (empty($imgUrl)) continue;
                        ?>
                        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="" class="product-thumb" style="width:22px;height:22px" onclick="openLightbox('<?= htmlspecialchars($imgUrl) ?>')">
                        <?php endfor; ?>
                        <?php if (count($images) > 5): ?>
                        <span class="badge badge-secondary" style="font-size:9px">+<?= count($images) - 5 ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div style="flex:1">
                    <h2 style="font-size:18px;font-weight:700;margin-bottom:12px;color:var(--text-primary)"><?= htmlspecialchars($p['name'] ?? '') ?></h2>
                    <table style="width:100%">
                        <tr><td class="text-muted" style="padding:4px 0;width:100px;font-size:12px">SKU:</td><td class="fw-bold" style="font-family:monospace;font-size:13px"><?= htmlspecialchars($p['sku'] ?? '') ?></td></tr>
                        <tr><td class="text-muted" style="padding:4px 0;font-size:12px">Barkod:</td><td style="font-size:13px"><?= htmlspecialchars($p['barcode'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted" style="padding:4px 0;font-size:12px">Kategori:</td><td style="font-size:13px"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted" style="padding:4px 0;font-size:12px">Marka:</td><td style="font-size:13px"><?= htmlspecialchars($p['brand_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted" style="padding:4px 0;font-size:12px">Birim:</td><td style="font-size:13px"><?= htmlspecialchars($p['unit'] ?? 'adet') ?></td></tr>
                        <?php if (!empty($p['weight'])): ?>
                        <tr><td class="text-muted" style="padding:4px 0;font-size:12px">Ağırlık:</td><td style="font-size:13px"><?= number_format((float)$p['weight'], 3) ?> kg</td></tr>
                        <?php endif; ?>
                        <?php if (!empty($p['desi'])): ?>
                        <tr><td class="text-muted" style="padding:4px 0;font-size:12px">Desi:</td><td style="font-size:13px"><?= number_format((float)$p['desi'], 2) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if (!empty($p['description'])): ?>
            <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--border)">
                <label class="form-label">Açıklama</label>
                <p style="font-size:13px;color:var(--text-secondary);line-height:1.6"><?= nl2br(htmlspecialchars(mb_substr($p['description'], 0, 300))) ?><?= mb_strlen($p['description']) > 300 ? '...' : '' ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i data-lucide="coins" style="width:18px;height:18px;display:inline"></i> Fiyat & Stok</h3></div>
        <div class="card-body">
            <table style="width:100%">
                <tr><td class="text-muted" style="padding:6px 0;width:130px">Satış Fiyatı:</td><td class="fw-bold" style="font-size:18px"><?= number_format((float) ($p['base_price'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Liste Fiyat:</td><td><?= number_format((float) ($p['list_price'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
                <tr><td class="text-muted" style="padding:6px 0">Maliyet:</td><td><?= number_format((float) ($p['cost_price'] ?? 0), 2, ',', '.') ?> ₺</td></tr>
                <?php
                $basePrice = (float)($p['base_price'] ?? 0);
                $costPrice = (float)($p['cost_price'] ?? 0);
                if ($costPrice > 0 && $basePrice > 0):
                    $profit = $basePrice - $costPrice;
                    $margin = ($profit / $basePrice) * 100;
                ?>
                <tr><td class="text-muted" style="padding:6px 0">Kâr Marjı:</td><td><span class="badge <?= $margin > 20 ? 'badge-success' : ($margin > 0 ? 'badge-warning' : 'badge-danger') ?>"><?= number_format($margin, 1) ?>%</span></td></tr>
                <?php endif; ?>
                <tr><td class="text-muted" style="padding:6px 0">Stok:</td><td><?php
                    $stock = (int) ($p['stock_qty'] ?? 0);
                    $cls = $stock <= 0 ? 'badge-danger' : ($stock <= ($p['min_stock_alert'] ?? 5) ? 'badge-warning' : 'badge-success');
                    echo "<span class=\"badge {$cls}\" style=\"font-size:14px\">{$stock}</span>";
                ?></td></tr>
                <tr><td class="text-muted" style="padding:6px 0">KDV:</td><td>%<?= (int) ($p['tax_rate'] ?? 20) ?></td></tr>
            </table>

            <!-- Stok güncelle -->
            <form method="POST" action="/products/<?= $p['id'] ?>/stock" style="margin-top:16px;display:flex;gap:8px;align-items:flex-end;border-top:1px solid var(--border);padding-top:12px">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['_csrf'] ?? '') ?>">
                <div class="form-group mb-0" style="flex:1"><input type="number" name="qty" class="form-input" placeholder="+10 veya -5" required></div>
                <div class="form-group mb-0" style="flex:2"><input type="text" name="note" class="form-input" placeholder="İşlem notu"></div>
                <button type="submit" class="btn btn-sm btn-primary">📦 Stok</button>
            </form>
        </div>
    </div>
</div>

<!-- === PRODUCT ATTRIBUTES === -->
<?php if (!empty($attributes)): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><h3><i data-lucide="list" style="width:18px;height:18px;display:inline"></i> Ürün Özellikleri</h3></div>
    <div class="card-body">
        <div class="grid-2">
            <?php foreach ($attributes as $attr): ?>
            <div class="attr-row">
                <span class="attr-name"><?= htmlspecialchars($attr['attribute_name'] ?? '') ?></span>
                <span class="attr-value" style="font-size:13px;color:var(--text-primary)">
                    <?php
                    $val = $attr['custom_value'] ?? $attr['attribute_value_text'] ?? '';
                    if (!empty($attr['color_hex'])) {
                        echo "<span style=\"display:inline-block;width:14px;height:14px;border-radius:50%;background:{$attr['color_hex']};vertical-align:middle;margin-right:6px;border:1px solid var(--border)\"></span>";
                    }
                    echo !empty($val) ? htmlspecialchars($val) : '<span class="text-muted">—</span>';
                    ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- === VARYANTLAR === -->
<?php if (!empty($variants)): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><i data-lucide="layers" style="width:18px;height:18px;display:inline"></i> Varyantlar</h3>
        <span class="badge badge-primary" style="background:var(--primary)"><?= count($variants) ?> varyant</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr>
                <th>Varyant Adı</th>
                <th>SKU</th>
                <th>Barkod</th>
                <th class="text-right">Fiyat</th>
                <th class="text-right">Stok</th>
                <th>Durum</th>
            </tr></thead>
            <tbody>
                <?php foreach ($variants as $v): ?>
                <tr>
                    <td style="font-weight:700;font-size:13px"><?= htmlspecialchars($v['name'] ?? '') ?></td>
                    <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($v['sku'] ?? '') ?></td>
                    <td style="font-size:12px"><?= htmlspecialchars($v['barcode'] ?? '—') ?></td>
                    <td class="text-right"><?= number_format((float) ($v['base_price'] ?? 0), 2, ',', '.') ?> ₺</td>
                    <td class="text-right"><?php
                        $vStock = (int) ($v['stock_qty'] ?? 0);
                        $vCls = $vStock <= 0 ? 'badge-danger' : 'badge-success';
                        echo "<span class=\"badge {$vCls}\">{$vStock}</span>";
                    ?></td>
                    <td><?= (int) ($v['is_active'] ?? 0) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- === MARKETPLACE EŞLEŞTIRMELER === -->
<?php if (!empty($mpProducts)): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><i data-lucide="shopping-cart" style="width:18px;height:18px;display:inline"></i> Pazaryeri Eşleştirmeleri</h3>
        <span class="badge badge-primary"><?= count($mpProducts) ?> pazaryeri</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Pazaryeri</th><th>SKU</th><th>Barkod</th><th class="text-right">Son Fiyat</th><th class="text-right">Son Stok</th><th>Son Sync</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($mpProducts as $mp): ?>
                <tr>
                    <td>
                        <span class="mp-indicator <?= htmlspecialchars($mp['slug'] ?? '') ?>">
                            <?= htmlspecialchars($mp['display_name'] ?? $mp['marketplace_name'] ?? '') ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($mp['marketplace_sku'] ?? '') ?></td>
                    <td style="font-size:12px"><?= htmlspecialchars($mp['marketplace_barcode'] ?? '') ?></td>
                    <td class="text-right"><?= number_format((float) ($mp['last_price_sent'] ?? 0), 2, ',', '.') ?> ₺</td>
                    <td class="text-right"><?php
                        $mpStock = $mp['last_stock_sent'] ?? null;
                        if ($mpStock !== null) {
                            $sCls = (int)$mpStock <= 0 ? 'badge-danger' : 'badge-success';
                            echo "<span class=\"badge {$sCls}\">{$mpStock}</span>";
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                    ?></td>
                    <td class="text-muted" style="font-size:11px"><?php
                        if (!empty($mp['last_synced_at'])) {
                            echo date('d.m.Y H:i', strtotime($mp['last_synced_at']));
                        } else {
                            echo '<span class="badge badge-secondary" style="font-size:9px">Hiç</span>';
                        }
                        if (!empty($mp['sync_error'])) {
                            echo '<br><span class="sync-badge error" title="' . htmlspecialchars($mp['sync_error']) . '"><span class="sync-dot"></span>' . htmlspecialchars(mb_substr($mp['sync_error'], 0, 30)) . '</span>';
                        }
                    ?></td>
                    <td><?= (int) ($mp['is_active'] ?? 0) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?></td>
                    <td>
                        <button class="btn-sync" onclick="syncSingleProduct(<?= $p['id'] ?>, <?= $mp['marketplace_id'] ?>)" title="Bu ürünü senkronize et">
                            <i data-lucide="refresh-cw" style="width:11px;height:11px"></i> Sync
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- === IMAGE GALLERY === -->
<?php if (!empty($images)): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3><i data-lucide="image" style="width:18px;height:18px;display:inline"></i> Görseller</h3>
        <span class="badge badge-secondary"><?= count($images) ?> görsel</span>
    </div>
    <div class="card-body">
        <div class="image-gallery">
            <?php foreach ($images as $idx => $img):
                $imgUrl = is_array($img) ? ($img['url'] ?? '') : $img;
                if (empty($imgUrl)) continue;
            ?>
            <div class="image-gallery-item" onclick="openLightbox('<?= htmlspecialchars($imgUrl) ?>')">
                <?php if ($idx === 0): ?><span class="img-badge">ANA</span><?php endif; ?>
                <img src="<?= htmlspecialchars($imgUrl) ?>" alt="Görsel <?= $idx+1 ?>" loading="lazy">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- === STOK HAREKETLERİ === -->
<?php
// Türkçe etiket haritaları
$movementTypeLabels = [
    'adjustment_in'  => ['label' => 'Stok Artışı',     'icon' => '📈', 'cls' => 'badge-success'],
    'adjustment_out' => ['label' => 'Stok Azalışı',    'icon' => '📉', 'cls' => 'badge-danger'],
    'sale'           => ['label' => 'Satış',            'icon' => '🛒', 'cls' => 'badge-warning'],
    'purchase'       => ['label' => 'Alım',             'icon' => '📦', 'cls' => 'badge-success'],
    'return'         => ['label' => 'İade',             'icon' => '↩️', 'cls' => 'badge-info'],
    'transfer_in'    => ['label' => 'Transfer Giriş',  'icon' => '⬆️', 'cls' => 'badge-success'],
    'transfer_out'   => ['label' => 'Transfer Çıkış',  'icon' => '⬇️', 'cls' => 'badge-danger'],
    'correction'     => ['label' => 'Düzeltme',        'icon' => '🛠️', 'cls' => 'badge-secondary'],
    'none'           => ['label' => 'Stok etkilenmedi', 'icon' => '➖', 'cls' => 'badge-secondary'],
];

$sourceLabels = [
    'inline_edit'   => 'Manuel Güncelleme',
    'manual'        => 'Manuel İşlem',
    'import'        => 'Ürün İçe Aktarma',
    'sync'          => 'Pazaryeri Senkronizasyon',
    'order'         => 'Sipariş',
    'bulk'          => 'Toplu İşlem',
    'api'           => 'API',
    'system'        => 'Sistem',
];
?>
<div class="card">
    <div class="card-header"><h3><i data-lucide="package" style="width:18px;height:18px;display:inline"></i> Stok Hareketleri</h3></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Tarih</th><th>İşlem Türü</th><th>Kaynak</th><th class="text-right">Değişim</th><th class="text-right">Sonrası</th><th>Not</th></tr></thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:20px">Hareket yok</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): 
                        $type = $m['movement_type'] ?? '';
                        $typeInfo = $movementTypeLabels[$type] ?? ['label' => $type, 'icon' => '📋', 'cls' => 'badge-secondary'];
                        $source = $m['source'] ?? '';
                        $sourceLabel = $sourceLabels[$source] ?? $source;
                        $qtyChange = (int)($m['qty_change'] ?? 0);
                    ?>
                    <tr>
                        <td class="text-muted text-nowrap" style="font-size:12px"><?= date('d.m.Y H:i', strtotime($m['created_at'] ?? 'now')) ?></td>
                        <td><span class="badge <?= $typeInfo['cls'] ?>"><?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?></span></td>
                        <td style="font-size:12px;color:var(--text-secondary)"><?= htmlspecialchars($sourceLabel) ?></td>
                        <td class="text-right fw-bold <?= $qtyChange < 0 ? 'text-danger' : 'text-success' ?>"><?= $qtyChange > 0 ? '+' : '' ?><?= $qtyChange ?></td>
                        <td class="text-right"><?= (int)($m['qty_after'] ?? 0) ?></td>
                        <td class="text-muted" style="font-size:12px"><?= htmlspecialchars($m['note'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- === IMAGE LIGHTBOX === -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    <img src="" alt="" class="lightbox-img" id="lightboxImg" onclick="event.stopPropagation()">
</div>

<script>
function openLightbox(url) {
    document.getElementById('lightboxImg').src = url;
    document.getElementById('lightbox').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('show');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

async function syncSingleProduct(productId, marketplaceId) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const btn = event.currentTarget;
    const origHTML = btn.innerHTML;
    btn.classList.add('syncing');
    btn.innerHTML = '<i data-lucide="loader" style="width:11px;height:11px"></i> ...';

    try {
        const res = await fetch('/products/manual-sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '' },
            body: JSON.stringify({ marketplace_id: marketplaceId, product_id: productId, sync_type: 'single' })
        });
        const data = await res.json();
        if (data.success) {
            Toast.show(data.message || 'Senkronize edildi', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            Toast.show(data.message || 'Hata', 'error');
        }
    } catch(err) {
        Toast.show('Hata: ' + err.message, 'error');
    } finally {
        btn.classList.remove('syncing');
        btn.innerHTML = origHTML;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

if (typeof lucide !== 'undefined') lucide.createIcons();
</script>
