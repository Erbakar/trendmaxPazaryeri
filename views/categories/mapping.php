<?php
$categories      = $data['categories'] ?? [];
$marketplaces    = $data['marketplaces'] ?? [];
$marketplaceId   = (int) ($data['marketplaceId'] ?? 0);
$marketplaceSlug = (string) ($data['marketplaceSlug'] ?? '');
$marketplaceName = (string) ($data['marketplaceName'] ?? '');
$unmappedCount   = (int) ($data['unmappedCount'] ?? 0);
$unsellableCount = (int) ($data['unsellableCount'] ?? 0);
$csrf            = (string) ($data['_csrf'] ?? '');
$catalogSize     = (int) ($data['catalogSize'] ?? 0);
$confidenceSafe  = (int) ($data['confidenceSafe'] ?? 75);
$can             = $data['_can'] ?? static fn(string $permission): bool => true;
$canEdit         = (bool) $can('categories.edit');
$isHb            = $marketplaceSlug === 'hepsiburada';
$esc             = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$jsonFlags       = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$unsellableLabels = [
    'kapali'     => 'Satışa kapalı',
    'pasif'      => 'Satışa kapalı (pasif)',
    'ara'        => 'Ara kategori',
    'bulunamadi' => 'Listede yok',
];
?>
<style>
.cmap-bar select:not([multiple]),.hba-field select:not([multiple]),.hba-table select:not([multiple]){appearance:none;-webkit-appearance:none;box-sizing:border-box;padding-right:44px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:16px 16px}
.cmap-bar select{min-width:min(100%,320px);width:100%}.cmap-bar form{max-width:100%}#cmapDrawer .cmap-btn{white-space:normal;overflow-wrap:anywhere;line-height:1.5}

.cmap-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.cmap-bar{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px}
.cmap-bar .btn [data-lucide]{width:14px;height:14px}
.cmap-bar form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px}
.cmap-alert{margin:0 0 16px;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.55;border:1px solid transparent}
.cmap-alert a{color:inherit;font-weight:600;text-decoration:underline}
.cmap-alert.is-warning{background:var(--warning-bg);border-color:rgba(245,158,11,.28);color:var(--text-primary)}
.cmap-alert.is-danger{background:var(--danger-bg);border-color:rgba(239,68,68,.28);color:var(--text-primary)}
.cmap-path{font-size:11px;color:var(--text-muted)}
.cmap-sub{display:block;margin-top:2px;font-size:11px;color:var(--text-muted)}
.cmap-actions{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:8px}
.cmap-actions form{display:inline}
.cmap-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);font:600 12px inherit;cursor:pointer;white-space:nowrap}
.cmap-btn:hover{border-color:var(--primary);color:var(--primary)}
.cmap-btn.is-danger:hover{border-color:var(--danger);color:var(--danger)}
.cmap-btn [data-lucide]{width:14px;height:14px}
.cmap-btn:focus-visible,.hba-btn:focus-visible,.hba-link:focus-visible,.hba-icon-btn:focus-visible,.hba-field input:focus-visible,.hba-field select:focus-visible,.hba-table select:focus-visible,.hba-table input:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
.cmap-empty{padding:48px 16px;text-align:center;color:var(--text-muted)}
.cmap-empty h4{margin:8px 0 4px;font-size:15px;color:var(--text-primary)}
.cmap-empty p{margin:0 auto 16px;max-width:420px;font-size:13px;line-height:1.55}
.cmap-empty [data-lucide]{width:32px;height:32px;opacity:.5}

.hba-backdrop{position:fixed;inset:0;z-index:9990;background:rgba(15,23,42,.45)}
.hba-drawer{position:fixed;top:0;right:0;bottom:0;z-index:9991;display:flex;flex-direction:column;width:min(1200px,100%);background:var(--bg-page,var(--bg-card));border-left:1px solid var(--border);transition:transform .2s ease}
.hba-drawer[hidden],.hba-backdrop[hidden]{display:none}
.hba-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:16px 24px;background:var(--bg-card);border-bottom:1px solid var(--border)}
.hba-eyebrow{margin:0 0 4px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted)}
.hba-head h2{margin:0;font-size:17px;font-weight:700;color:var(--text-primary)}
.hba-head p{margin:4px 0 0;font-size:12px;color:var(--text-muted)}
.hba-icon-btn{display:grid;place-items:center;width:36px;height:36px;flex-shrink:0;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer}
.hba-icon-btn:hover{color:var(--danger);border-color:var(--danger)}
.hba-icon-btn [data-lucide]{width:18px;height:18px}
.hba-toolbar{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 24px;background:var(--bg-card);border-bottom:1px solid var(--border)}
.hba-toolbar p{margin:0;max-width:640px;font-size:12.5px;line-height:1.6;color:var(--text-muted)}
.hba-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-card);color:var(--text-primary);font:600 12px inherit;cursor:pointer;white-space:nowrap}
.hba-btn:hover{border-color:var(--primary);color:var(--primary)}
.hba-btn:disabled{opacity:.5;cursor:not-allowed}
.hba-btn [data-lucide]{width:14px;height:14px}
.hba-status{min-height:0;padding:0 24px;font-size:12.5px;color:var(--text-muted)}
.hba-status:not(:empty){padding-top:8px}
.hba-body{flex:1;overflow-y:auto;padding:16px 24px 32px}
.hba-section{margin:0 0 24px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.hba-section-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border)}
.hba-section-head h3{margin:0;font-size:14px;font-weight:600;color:var(--text-primary)}
.hba-section-head span{font-size:12px;color:var(--text-muted)}
.hba-fields{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,360px),1fr));gap:16px;padding:16px}
.hba-field label{display:flex;align-items:center;gap:6px;margin:0 0 4px;font-size:12px;font-weight:600;color:var(--text-primary)}
.hba-field input,.hba-field select,.hba-table select,.hba-table input{width:100%;height:36px;padding:0 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-input,var(--bg-card));color:var(--text-primary);font-size:13px;font-family:inherit}
.hba-field small{display:block;margin-top:4px;font-size:11px;color:var(--text-muted)}
.hba-tag{display:inline-block;padding:0 6px;border-radius:6px;background:var(--bg-badge);color:var(--text-muted);font-size:10.5px;font-weight:600;line-height:18px}
.hba-table-wrap{overflow-x:auto}
.hba-table{width:100%;border-collapse:collapse;font-size:12.5px}
.hba-table th{padding:8px 12px;text-align:left;white-space:nowrap;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--text-muted);background:var(--bg-input,var(--bg-card));border-bottom:1px solid var(--border)}
.hba-table td{padding:8px 12px;vertical-align:top;border-bottom:1px solid var(--border-light,var(--border));color:var(--text-primary)}
.hba-table tr:last-child td{border-bottom:none}
.hba-field select:not([multiple]),.hba-table select:not([multiple]){padding-right:44px}
.hba-table td.hba-product{min-width:180px}
.hba-table td.hba-product small{display:block;color:var(--text-muted)}
.hba-table td[data-hba-cell]{min-width:320px}
.hba-badge{display:inline-block;margin-left:4px;padding:0 6px;border-radius:6px;font-size:10.5px;font-weight:600;line-height:18px;vertical-align:middle}
.hba-badge.is-product{background:var(--info-bg);color:var(--info)}
.hba-badge.is-default,.hba-badge.is-match{background:var(--bg-badge);color:var(--text-muted)}
.hba-badge.is-auto{background:var(--warning-bg);color:var(--warning)}
.hba-badge.is-none{background:var(--danger-bg);color:var(--danger)}
.hba-link{padding:0 4px;border:none;background:none;color:var(--primary);font:500 12px inherit;cursor:pointer}
.hba-link:hover{text-decoration:underline}
.hba-ok{color:var(--success);font-weight:600}
.hba-errors{margin:0;padding-left:16px;color:var(--danger)}
.hba-alert{margin:0 0 16px;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.55}
.hba-alert.is-danger{background:var(--danger-bg);color:var(--danger)}
.hba-alert.is-warning{background:var(--warning-bg);color:var(--text-primary)}
.hba-alert .hba-btn{margin-left:8px}
.hba-empty{padding:32px 16px;text-align:center;font-size:13px;color:var(--text-muted)}
.hba-empty a{color:var(--primary);font-weight:600}
.hba-skeleton{height:12px;margin:12px 0;border-radius:6px;background:var(--bg-badge);animation:hbaPulse 1.2s ease-in-out infinite}
.hba-skeleton:nth-child(even){width:70%}
@keyframes hbaPulse{50%{opacity:.45}}
@media (prefers-reduced-motion:reduce){.hba-drawer{transition:none}.hba-skeleton{animation:none}}
@media (max-width:640px){.hba-head,.hba-toolbar,.hba-body{padding-left:16px;padding-right:16px}.hba-status{padding:0 16px}.hba-fields{grid-template-columns:1fr}}
</style>

<div class="filter-bar cmap-bar">
    <form method="GET" action="/categories/mapping">
        <div class="form-group">
            <label class="form-label" for="cmapMarketplace">Pazaryeri</label>
            <select name="marketplace_id" id="cmapMarketplace" class="form-select" onchange="this.form.submit()">
                <?php foreach ($marketplaces as $mp): ?>
                    <option value="<?= (int) $mp['id'] ?>" <?= (int) $mp['id'] === $marketplaceId ? 'selected' : '' ?>><?= $esc($mp['display_name'] ?? $mp['slug'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <span class="badge badge-warning"><?= $unmappedCount ?> eşleştirilmemiş kategori</span>
        </div>
        <noscript><button type="submit" class="cmap-btn">Göster</button></noscript>
    </form>
    <?php if ($marketplaceId > 0): ?>
    <a class="btn btn-primary btn-sm"
       href="/mapping/auto?kind=category&amp;marketplace_id=<?= $marketplaceId ?>"
       title="Eşleşmemiş kayıtları otomatik eşleştir">
        <i data-lucide="wand-2" aria-hidden="true"></i> Akıllı Eşleştir
    </a>
    <?php endif; ?>
</div>

<?php if ($marketplaceId === 0): ?>
<div class="card">
    <div class="cmap-empty">
        <i data-lucide="plug-zap" aria-hidden="true"></i>
        <h4>Eşleştirme yapılabilecek pazaryeri yok</h4>
        <p>Kategori eşleştirmesi için önce paketinizdeki bir pazaryerini bağlayın ve etkinleştirin.</p>
        <a class="btn btn-primary btn-sm" href="/marketplaces">Pazaryerlerine git</a>
    </div>
</div>
<?php return; endif; ?>

<?php if ($catalogSize < 100): ?>
<div class="cmap-alert is-warning" role="status">
    <strong>Bu pazaryerinin kategori katalogu eksik görünüyor (<?= number_format($catalogSize) ?> kayıt).</strong>
    Eşleştirme yapabilmek için önce <em>Kategoriler &rsaquo; Pazaryerinden Çek</em> adımını çalıştırın.
    Katalog boşken hiçbir eşleştirme önerisi üretilemez.
</div>
<?php endif; ?>

<?php if ($unsellableCount > 0): ?>
<div class="cmap-alert is-danger" role="alert">
    <strong><?= $unsellableCount ?> eşleşme <?= $esc($marketplaceName) ?>'da satışa kapalı, ara ya da listede olmayan bir kategoriye bağlı.</strong>
    Bu kategorilerdeki ürünler gönderilemez. Eşleşmeyi <a href="/categories">Kategoriler</a> sayfasından değiştirin.
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrapper">
        <table class="data-table">
            <caption class="cmap-sr"><?= $esc($marketplaceName) ?> kategori eşleştirmeleri</caption>
            <thead>
                <tr>
                    <th scope="col">İç Kategori</th>
                    <th scope="col">Yol</th>
                    <th scope="col">Ürün</th>
                    <th scope="col">Pazaryeri Kategorisi</th>
                    <th scope="col">Durum</th>
                    <th scope="col"><span class="cmap-sr">İşlemler</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="6" class="empty-state"><p>Henüz iç kategori yok. Kategoriler sayfasından kategori oluşturun ya da pazaryerinden çekin.</p></td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $c):
                        $mappingId   = (int) ($c['mapping_id'] ?? 0);
                        $reason      = (string) ($c['unsellable_reason'] ?? '');
                        $invalidLeaf = $mappingId > 0 && isset($c['marketplace_category_is_leaf']) && (int) $c['marketplace_category_is_leaf'] === 0;
                        $conf        = $c['confidence'] ?? null;
                        $categoryName = (string) ($c['name'] ?? '');
                    ?>
                    <tr>
                        <td class="fw-bold"><?= $esc($categoryName) ?><?php if ((int)($c['override_count']??0)>0): ?><small class="cmap-sub"><?= (int)$c['override_count'] ?> ürüne özel kategori seçimi</small><?php endif; ?></td>
                        <td class="cmap-path"><?= $esc($c['full_path'] ?? '') ?></td>
                        <td class="text-center"><?= (int) ($c['product_count'] ?? 0) ?></td>
                        <td>
                            <?php if (!empty($c['marketplace_category_name'])): ?>
                                <?= $esc($c['marketplace_category_name']) ?>
                                <?php if ($reason !== '' && ($c['unsellable_path'] ?? '') !== ''): ?>
                                    <span class="cmap-sub"><?= $esc($c['unsellable_path']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($reason !== ''): ?>
                                <span class="badge badge-danger" title="<?= $esc(($c['unsellable_path'] ?? '') !== '' ? $c['unsellable_path'] : 'Eşleşmeyi değiştirin') ?>"><?= $esc($unsellableLabels[$reason] ?? 'Satışa kapalı') ?></span>
                            <?php elseif ($invalidLeaf): ?>
                                <span class="badge badge-warning">Ara kategori</span>
                            <?php elseif ($mappingId === 0): ?>
                                <span class="badge badge-secondary">Eşleşmemiş</span>
                            <?php elseif ($conf !== null && $conf < $confidenceSafe): ?>
                                <span class="badge badge-warning" title="Otomatik eşleştirildi (güven %<?= (int) $conf ?>). Doğruluğunu kontrol edin.">Kontrol edin</span>
                            <?php else: ?>
                                <span class="badge badge-success">Eşleşmiş</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cmap-actions">
                                <?php if ($marketplaceSlug === 'trendyol'): ?>
                                <a class="cmap-btn" href="/categories/<?= (int)$c['id'] ?>/product-mappings?marketplace_id=<?= $marketplaceId ?>">Ürünleri ayrı eşleştir</a>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                <?php /* Eşleşmemiş satırlarda HİÇ düğme çizilmiyordu: ekran
                                        "N eşleştirilmemiş kategori" diyor ama kullanıcı o satırda
                                        hiçbir şey yapamıyordu. Eşleştirme tek tek /categories
                                        ağacından aranmak zorundaydı. */ ?>
                                <button type="button" class="cmap-btn"
                                        data-map-open="<?= (int) ($c['id'] ?? 0) ?>"
                                        data-map-name="<?= $esc($categoryName) ?>"
                                        data-map-path="<?= $esc($c['full_path'] ?? '') ?>"
                                        aria-haspopup="dialog" aria-controls="cmapPick">
                                    <i data-lucide="<?= $mappingId > 0 ? 'repeat' : 'link' ?>" aria-hidden="true"></i>
                                    <?= $mappingId > 0 ? 'Değiştir' : 'Eşleştir' ?>
                                </button>
                                <?php endif; ?>
                            <?php if ($mappingId > 0): ?>
                                <?php if ($marketplaceSlug === 'trendyol'): ?>
                                <button type="button" class="cmap-btn" data-tya-open="<?= $mappingId ?>" aria-haspopup="dialog" aria-controls="tyaDialog">
                                    <i data-lucide="list-checks" aria-hidden="true"></i> Özellikler
                                </button>
                                <?php endif; ?>
                                <?php if ($isHb): ?>
                                <button type="button" class="cmap-btn" data-hba-open="<?= $mappingId ?>"
                                        aria-haspopup="dialog" aria-controls="hbaDrawer"
                                        aria-label="<?= $esc($categoryName) ?> için Hepsiburada kategori özellikleri">
                                    <i data-lucide="list-checks" aria-hidden="true"></i> Özellikler
                                </button>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                <form method="POST" action="/categories/mapping/<?= $mappingId ?>/delete"
                                      onsubmit="return confirm('Eşleştirmeyi silmek istediğinize emin misiniz?')">
                                    <input type="hidden" name="_csrf_token" value="<?= $esc($csrf) ?>">
                                    <button type="submit" class="cmap-btn is-danger" aria-label="<?= $esc($categoryName) ?> eşleştirmesini sil">
                                        <i data-lucide="trash-2" aria-hidden="true"></i> Sil
                                    </button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isHb): ?>
<div class="hba-backdrop" id="hbaBackdrop" hidden data-hba-close></div>
<aside class="hba-drawer" id="hbaDrawer" role="dialog" aria-modal="true" aria-labelledby="hbaTitle" aria-describedby="hbaDesc" hidden>
    <header class="hba-head">
        <div>
            <p class="hba-eyebrow">Hepsiburada · Kategori Özellikleri</p>
            <h2 id="hbaTitle" tabindex="-1">Kategori özellikleri</h2>
            <p id="hbaPath"></p>
        </div>
        <button type="button" class="hba-icon-btn" data-hba-close aria-label="Kapat">
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </header>
    <div class="hba-toolbar">
        <p id="hbaDesc">
            Hepsiburada her kategori için zorunlu özellik ister. Gönderimde değer sırası:
            <strong>ürüne özel değer</strong> → <strong>kategori varsayılanı</strong> → <strong>ürün adından otomatik</strong>.
            Hepsiburada'nın listesinde olmayan değer gönderilmez.
        </p>
        <button type="button" class="hba-btn" id="hbaRefresh">
            <i data-lucide="refresh-cw" aria-hidden="true"></i> Listeleri yenile
        </button>
    </div>
    <div class="hba-status" id="hbaStatus" role="status" aria-live="polite"></div>
    <div class="hba-body" id="hbaBody" aria-busy="false"></div>
    <datalist id="hbaBrands"></datalist>
</aside>

<script>
(function () {
    'use strict';

    const CONFIG = {
        csrf: <?= json_encode($csrf, $jsonFlags) ?>,
        canEdit: <?= $canEdit ? 'true' : 'false' ?>,
        selectLimit: 300,
    };
    const SOURCES = {
        urun: ['is-product', 'Ürüne özel'],
        varsayilan: ['is-default', 'Kategori'],
        otomatik: ['is-auto', 'Otomatik'],
        eslesme: ['is-match', 'Eşleşme'],
        '': ['is-none', 'Yok'],
    };
    const BRAND_KEY = 'Marka';

    const drawer = document.getElementById('hbaDrawer');
    const backdrop = document.getElementById('hbaBackdrop');
    const body = document.getElementById('hbaBody');
    const statusBox = document.getElementById('hbaStatus');
    const refreshBtn = document.getElementById('hbaRefresh');
    const titleEl = document.getElementById('hbaTitle');
    const pathEl = document.getElementById('hbaPath');
    const brandList = document.getElementById('hbaBrands');
    if (!drawer || !body) return;

    const state = { mappingId: 0, data: null, trigger: null, loading: false, reloadQueued: false, focusKey: '' };

    const esc = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return (meta && meta.getAttribute('content')) || CONFIG.csrf;
    }

    function notify(message, type) {
        statusBox.textContent = message || '';
        if (message && type && typeof Toast !== 'undefined' && typeof Toast.show === 'function') {
            Toast.show(message, type);
        }
    }

    function icons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    async function request(url, options) {
        try {
            const response = await fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}));
            const text = await response.text();
            let json;
            try { json = JSON.parse(text); } catch (e) { return { ok: false, message: 'Sunucudan beklenmeyen yanıt geldi.' }; }
            return { ok: !!json.success, message: json.message || '', data: json.data || {} };
        } catch (e) {
            return { ok: false, message: 'Bağlantı hatası — tekrar deneyin.' };
        }
    }

    function openDrawer(mappingId, trigger) {
        state.mappingId = mappingId;
        state.trigger = trigger || null;
        state.data = null;
        titleEl.textContent = 'Kategori özellikleri';
        pathEl.textContent = '';
        statusBox.textContent = '';
        drawer.hidden = false;
        backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
        titleEl.focus();
        load(false);
    }

    function closeDrawer() {
        drawer.hidden = true;
        backdrop.hidden = true;
        document.body.style.overflow = '';
        state.mappingId = 0;
        state.data = null;
        if (state.trigger && typeof state.trigger.focus === 'function') state.trigger.focus();
    }

    function skeleton() {
        let html = '<div class="hba-section"><div class="hba-fields">';
        for (let i = 0; i < 6; i++) html += '<div class="hba-skeleton"></div>';
        return html + '</div></div>';
    }

    async function load(refresh) {
        if (!state.mappingId) return;
        if (state.loading) { state.reloadQueued = true; return; }
        state.loading = true;
        const mappingId = state.mappingId;
        body.setAttribute('aria-busy', 'true');
        refreshBtn.disabled = true;
        if (!state.data) body.innerHTML = skeleton();
        if (refresh) notify('Listeler Hepsiburada\'dan yenileniyor…');

        const url = '/categories/mapping/' + encodeURIComponent(mappingId) + '/hb-attributes' + (refresh ? '?refresh=1' : '');
        const result = await request(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });

        state.loading = false;
        body.setAttribute('aria-busy', 'false');
        refreshBtn.disabled = false;

        if (state.reloadQueued || mappingId !== state.mappingId) {
            state.reloadQueued = false;
            if (state.mappingId) load(false);
            return;
        }

        if (!result.ok) {
            body.innerHTML = '<div class="hba-alert is-danger" role="alert">' + esc(result.message || 'Özellikler yüklenemedi.')
                + ' <button type="button" class="hba-btn" data-hba-retry>Tekrar dene</button></div>';
            return;
        }

        state.data = result.data;
        if (refresh) notify('Listeler yenilendi.', 'success');
        render();
    }

    function render() {
        const d = state.data || {};
        const mapping = d.mapping || {};
        titleEl.textContent = mapping.category_name || ('Kategori #' + (mapping.category_id || ''));
        pathEl.textContent = 'Hepsiburada: ' + (mapping.hb_category_path || mapping.hb_category_name || '') + (mapping.hb_category_id ? ' · #' + mapping.hb_category_id : '');
        brandList.innerHTML = (d.brands || []).map((name) => '<option value="' + esc(name) + '">').join('');

        let html = '';
        if (mapping.sellable === false) {
            html += '<div class="hba-alert is-warning" role="alert">Bu eşleşme Hepsiburada\'da satışa kapalı bir kategoriye bağlı; ürünler gönderilemez. Önce <a href="/categories">Kategoriler</a> sayfasından eşleşmeyi değiştirin.</div>';
        }
        if (d.error) {
            html += '<div class="hba-alert is-danger" role="alert">' + esc(d.error) + ' <button type="button" class="hba-btn" data-hba-retry>Tekrar dene</button></div>';
        }
        if (!CONFIG.canEdit) {
            html += '<div class="hba-alert is-warning">Değerleri yalnız görüntüleyebilirsiniz; değişiklik için kategori düzenleme yetkisi gerekir.</div>';
        }
        html += datalists(d.attributes || []);
        html += renderDefaults(d);
        if (!d.error) html += renderProducts(d);

        body.innerHTML = html;
        icons();
        restoreFocus();
    }

    function datalists(attributes) {
        return attributes
            .filter((a) => a.type === 'enum' && (a.values || []).length > CONFIG.selectLimit)
            .map((a) => '<datalist id="' + esc(listId(a.id)) + '">' + a.values.map((v) => '<option value="' + esc(v) + '">').join('') + '</datalist>')
            .join('');
    }

    function listId(attributeId) { return 'hba-dl-' + String(attributeId).replace(/[^A-Za-z0-9_-]/g, '_'); }

    function control(attribute, value, ctx) {
        const values = attribute.values || [];
        const common = ' data-hba-attr="' + esc(attribute.id) + '" data-hba-product="' + esc(ctx.productKey) + '"'
            + ' data-hba-focus="' + esc(ctx.productKey + '|' + attribute.id) + '"'
            + (ctx.id ? ' id="' + esc(ctx.id) + '"' : '')
            + (ctx.label ? ' aria-label="' + esc(ctx.label) + '"' : '')
            + (CONFIG.canEdit ? '' : ' disabled');

        if (attribute.type === 'enum' && values.length <= CONFIG.selectLimit) {
            let options = '<option value="">' + esc(ctx.emptyLabel) + '</option>';
            if (value && values.indexOf(value) === -1) {
                options += '<option value="' + esc(value) + '" selected>' + esc(value) + ' (listede yok)</option>';
            }
            values.forEach((v) => { options += '<option value="' + esc(v) + '"' + (v === value ? ' selected' : '') + '>' + esc(v) + '</option>'; });
            return '<select' + common + '>' + options + '</select>';
        }

        const list = attribute.type === 'enum' ? ' list="' + esc(listId(attribute.id)) + '"' : '';
        const placeholder = attribute.type === 'enum' ? 'Listeden yazarak seçin' : ctx.emptyLabel;
        return '<input type="text" maxlength="255" autocomplete="off"' + list + common
            + ' value="' + esc(value) + '" placeholder="' + esc(placeholder) + '">';
    }

    function renderDefaults(d) {
        const attributes = d.attributes || [];
        const defaults = d.defaults || {};
        let html = '<section class="hba-section" aria-labelledby="hbaDefaultsTitle"><div class="hba-section-head">'
            + '<h3 id="hbaDefaultsTitle">Kategori varsayılanları</h3>'
            + '<span>' + (attributes.length ? attributes.length + ' zorunlu özellik + Marka' : 'Kategoriye özel zorunlu özellik yok; yalnız Marka') + '</span>'
            + '</div><div class="hba-fields">';

        attributes.forEach((a) => {
            const id = 'hba-def-' + listId(a.id);
            html += '<div class="hba-field"><label for="' + esc(id) + '">' + esc(a.name)
                + ' <span class="hba-tag">' + (a.type === 'enum' ? 'liste' : 'serbest') + '</span></label>'
                + control(a, defaults[a.id] || '', { id: id, productKey: '', emptyLabel: 'Otomatik (ürün adından)' })
                + '<small>Boş bırakılırsa ürün adından bulunmaya çalışılır.</small></div>';
        });

        html += '<div class="hba-field"><label for="hbaDefBrand">Marka <span class="hba-tag">ürünün markası yoksa</span></label>'
            + '<input type="text" id="hbaDefBrand" maxlength="100" autocomplete="off" list="hbaBrands" data-hba-attr="' + BRAND_KEY + '" data-hba-product=""'
            + ' data-hba-focus="|' + BRAND_KEY + '" value="' + esc(defaults[BRAND_KEY] || '') + '" placeholder="Hepsiburada marka adı"' + (CONFIG.canEdit ? '' : ' disabled') + '>'
            + '<small>Ürün markası ya da marka eşleşmesi varsa o kullanılır.</small></div>';

        return html + '</div></section>';
    }

    function badge(source) {
        const item = SOURCES[source] || SOURCES[''];
        return '<span class="hba-badge ' + item[0] + '">' + item[1] + '</span>';
    }

    function renderProducts(d) {
        const attributes = d.attributes || [];
        const products = d.products || [];
        const ready = products.filter((p) => !(p.errors || []).length).length;
        let html = '<section class="hba-section" aria-labelledby="hbaProductsTitle"><div class="hba-section-head">'
            + '<h3 id="hbaProductsTitle">Ürünler</h3>'
            + '<span>' + products.length + ' ürün · ' + ready + ' hazır' + ((d.product_total || 0) > products.length ? ' · ilk ' + products.length + ' / ' + d.product_total + ' gösteriliyor' : '') + '</span></div>';

        if (!products.length) {
            return html + '<div class="hba-empty">Bu kategoride ürün yok. Ürünlere bu kategoriyi atadığınızda zorunlu özellikler burada ürün bazında çözülür.<br><a href="/products">Ürünlere git</a></div></section>';
        }

        html += '<div class="hba-table-wrap"><table class="hba-table"><caption class="cmap-sr">Ürün bazında çözülen Hepsiburada özellikleri</caption><thead><tr><th scope="col">Ürün</th>';
        attributes.forEach((a) => { html += '<th scope="col">' + esc(a.name) + '</th>'; });
        html += '<th scope="col">Marka</th><th scope="col">Durum</th></tr></thead><tbody>';
        products.forEach((p) => { html += productRow(p, attributes); });
        return html + '</tbody></table></div></section>';
    }

    function productRow(product, attributes) {
        let html = '<tr data-hba-row="' + esc(product.key) + '"><td class="hba-product"><strong>' + esc(product.name) + '</strong>'
            + (product.sku ? '<small>' + esc(product.sku) + '</small>' : '') + '</td>';
        attributes.forEach((a) => { html += '<td data-hba-cell="' + esc(a.id) + '">' + cellView(product, a) + '</td>'; });
        const brand = product.brand || {};
        html += '<td>' + (brand.value ? esc(brand.value) : '—') + badge(brand.value ? brand.source : '') + '</td>';
        const errors = product.errors || [];
        html += '<td>' + (errors.length
            ? '<ul class="hba-errors">' + errors.map((e) => '<li>' + esc(e) + '</li>').join('') + '</ul>'
            : '<span class="hba-ok">Hazır</span>') + '</td></tr>';
        return html;
    }

    function cellView(product, attribute) {
        const value = ((product.values || {})[attribute.id]) || { value: '', source: '' };
        let html = (value.value ? esc(value.value) : '—') + badge(value.value ? value.source : '');
        if (CONFIG.canEdit) {
            html += ' <button type="button" class="hba-link" data-hba-edit="' + esc(attribute.id) + '"'
                + ' data-hba-focus="' + esc('edit|' + product.key + '|' + attribute.id) + '"'
                + ' aria-label="' + esc(product.name + ' için ' + attribute.name + ' değerini değiştir') + '">Değiştir</button>';
        }
        return html;
    }

    function findProduct(key) { return ((state.data && state.data.products) || []).find((p) => p.key === key) || null; }
    function findAttribute(id) { return ((state.data && state.data.attributes) || []).find((a) => a.id === id) || null; }

    function startEdit(button) {
        const row = button.closest('[data-hba-row]');
        const cell = button.closest('[data-hba-cell]');
        const product = row ? findProduct(row.getAttribute('data-hba-row')) : null;
        const attribute = cell ? findAttribute(cell.getAttribute('data-hba-cell')) : null;
        if (!product || !attribute) return;
        const current = ((product.values || {})[attribute.id]) || {};
        const ownValue = current.source === 'urun' ? current.value : '';
        cell.innerHTML = control(attribute, ownValue, {
            productKey: product.key,
            emptyLabel: '— Kategori değerini kullan —',
            label: product.name + ' — ' + attribute.name,
        });
        const field = cell.querySelector('select,input');
        if (field) field.focus();
    }

    function cancelEdit(field) {
        const row = field.closest('[data-hba-row]');
        const cell = field.closest('[data-hba-cell]');
        const product = row ? findProduct(row.getAttribute('data-hba-row')) : null;
        const attribute = cell ? findAttribute(cell.getAttribute('data-hba-cell')) : null;
        if (!product || !attribute) return;
        cell.innerHTML = cellView(product, attribute);
        const button = cell.querySelector('[data-hba-edit]');
        if (button) button.focus();
    }

    async function save(field) {
        if (!CONFIG.canEdit || !state.mappingId) return;
        const attributeId = field.getAttribute('data-hba-attr');
        const productKey = field.getAttribute('data-hba-product') || '';
        const form = new FormData();
        form.append('attribute_id', attributeId);
        form.append('value', field.value.trim());
        form.append('product_key', productKey);
        form.append('_csrf_token', csrfToken());

        field.disabled = true;
        field.setAttribute('aria-busy', 'true');
        notify('Kaydediliyor…');
        const result = await request('/categories/mapping/' + encodeURIComponent(state.mappingId) + '/hb-attribute', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: form,
        });
        field.disabled = false;
        field.removeAttribute('aria-busy');

        if (!result.ok) {
            notify(result.message || 'Kaydedilemedi.', 'error');
            field.focus();
            return;
        }
        notify(result.message || 'Kaydedildi.', 'success');
        state.focusKey = productKey !== '' ? 'edit|' + productKey + '|' + attributeId : field.getAttribute('data-hba-focus');
        load(false);
    }

    function restoreFocus() {
        if (!state.focusKey) return;
        const target = Array.prototype.find.call(body.querySelectorAll('[data-hba-focus]'), (el) => el.getAttribute('data-hba-focus') === state.focusKey);
        state.focusKey = '';
        if (target) target.focus();
    }

    function trapFocus(event) {
        const focusable = Array.prototype.filter.call(
            drawer.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'),
            (el) => el.offsetParent !== null
        );
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-hba-open]');
        if (opener) { openDrawer(parseInt(opener.getAttribute('data-hba-open'), 10) || 0, opener); return; }
        if (drawer.hidden) return;
        if (event.target.closest('[data-hba-close]')) { closeDrawer(); return; }
        if (event.target.closest('[data-hba-retry]')) { load(false); return; }
        const edit = event.target.closest('[data-hba-edit]');
        if (edit) startEdit(edit);
    });

    body.addEventListener('change', (event) => {
        const field = event.target.closest('[data-hba-attr]');
        if (field) save(field);
    });

    drawer.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') { trapFocus(event); return; }
        if (event.key !== 'Escape') return;
        const field = event.target.closest('[data-hba-cell] [data-hba-attr]');
        event.preventDefault();
        if (field) cancelEdit(field); else closeDrawer();
    });

    refreshBtn.addEventListener('click', () => load(true));
})();
</script>
<?php endif; ?>

<?php /* ─── Kategori eşleştirme penceresi ──────────────────────────────────
     Eşleşmemiş satırlardaki "Eşleştir" düğmesi burayı açar: pazaryeri
     kategorisinde ara, tıkla, kaydet. Önceden bu ekranda eşleştirme yapmanın
     hiçbir yolu yoktu; kullanıcı /categories ağacında tek tek aramak zorundaydı. */ ?>
<div class="hba-backdrop" id="cmapBackdrop" hidden data-cmap-close></div>
<aside class="hba-drawer" id="cmapPick" role="dialog" aria-modal="true" aria-labelledby="cmapPickTitle" hidden
       style="width:min(1080px,100%)">
    <header class="hba-head">
        <div>
            <p class="hba-eyebrow"><?= $esc($marketplaceName) ?> · Kategori Eşleştirme</p>
            <h2 id="cmapPickTitle" tabindex="-1">Kategori seçin</h2>
            <p id="cmapPickPath"></p>
        </div>
        <button type="button" class="hba-icon-btn" data-cmap-close aria-label="Kapat">
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </header>
    <div class="hba-toolbar">
        <p>En az 2 harf yazın. Listeden bir kategoriye tıkladığınızda eşleştirme anında kaydedilir.</p>
    </div>
    <div style="padding:16px 24px">
        <input type="search" id="cmapQuery" class="form-input" autocomplete="off"
               placeholder="Pazaryeri kategorisinde ara…" style="width:100%">
    </div>
    <div class="hba-body" id="cmapResults" style="padding:0 24px 24px;overflow:auto;flex:1"></div>
    <form method="POST" action="/categories/mapping" id="cmapForm" hidden>
        <input type="hidden" name="_csrf_token" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="marketplace_id" value="<?= $marketplaceId ?>">
        <input type="hidden" name="category_id" id="cmapCategoryId" value="">
        <input type="hidden" name="marketplace_category_id" id="cmapMpId" value="">
        <input type="hidden" name="marketplace_category_name" id="cmapMpName" value="">
        <input type="hidden" name="marketplace_category_path" id="cmapMpPath" value="">
    </form>
</aside>

<script>
(function () {
    const drawer   = document.getElementById('cmapPick');
    const backdrop = document.getElementById('cmapBackdrop');
    const query    = document.getElementById('cmapQuery');
    const results  = document.getElementById('cmapResults');
    const form     = document.getElementById('cmapForm');
    if (!drawer || !form) return;

    const MP_ID = <?= (int) $marketplaceId ?>;
    let aktifKategori = 0;
    let zamanlayici = null;
    let sonIstek = 0;

    function ac(id, ad, yol) {
        aktifKategori = id;
        document.getElementById('cmapPickTitle').textContent = ad;
        document.getElementById('cmapPickPath').textContent = yol || '';
        query.value = ad || '';
        results.innerHTML = '';
        drawer.hidden = false;
        backdrop.hidden = false;
        query.focus();
        query.select();
        ara();
    }

    function kapat() {
        drawer.hidden = true;
        backdrop.hidden = true;
        aktifKategori = 0;
    }

    function yaz(html) { results.innerHTML = html; }

    async function ara() {
        const q = query.value.trim();
        if (q.length < 2) {
            yaz('<p class="text-muted" style="padding:12px 0;font-size:13px">En az 2 harf yazın.</p>');
            return;
        }
        const benimIstek = ++sonIstek;
        yaz('<p class="text-muted" style="padding:12px 0;font-size:13px">Aranıyor…</p>');
        try {
            const yanit = await fetch('/categories/mapping/search?marketplace_id=' + MP_ID + '&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const veri = await yanit.json();
            if (benimIstek !== sonIstek) return;
            const liste = (veri && veri.data && veri.data.categories) || (veri && veri.categories) || [];
            if (!liste.length) {
                yaz('<p class="text-muted" style="padding:12px 0;font-size:13px">Sonuç yok. Pazaryeri kategori listesi eksikse önce <b>Kategoriler &rsaquo; Pazaryerinden Çek</b> adımını çalıştırın.</p>');
                return;
            }
            yaz(liste.map(function (k) {
                const yol = (k.path || k.name || '').toString();
                return '<button type="button" class="cmap-btn" style="display:block;width:100%;text-align:left;height:auto;padding:10px 12px;margin-bottom:6px"'
                     + ' data-pick-id="' + String(k.id).replace(/"/g, '&quot;') + '"'
                     + ' data-pick-name="' + String(k.name || '').replace(/"/g, '&quot;') + '"'
                     + ' data-pick-path="' + yol.replace(/"/g, '&quot;') + '">'
                     + '<b>' + (k.name || '') + '</b>'
                     + (yol && yol !== k.name ? '<span class="cmap-sub">' + yol + '</span>' : '')
                     + '</button>';
            }).join(''));
        } catch (e) {
            if (benimIstek !== sonIstek) return;
            yaz('<p class="text-muted" style="padding:12px 0;font-size:13px">Arama başarısız: ' + e.message + '</p>');
        }
    }

    document.addEventListener('click', function (olay) {
        const ac1 = olay.target.closest('[data-map-open]');
        if (ac1) {
            olay.preventDefault();
            ac(parseInt(ac1.getAttribute('data-map-open'), 10) || 0,
               ac1.getAttribute('data-map-name') || '',
               ac1.getAttribute('data-map-path') || '');
            return;
        }
        if (olay.target.closest('[data-cmap-close]')) { kapat(); return; }

        const sec = olay.target.closest('[data-pick-id]');
        if (sec && aktifKategori > 0) {
            document.getElementById('cmapCategoryId').value = aktifKategori;
            document.getElementById('cmapMpId').value   = sec.getAttribute('data-pick-id');
            document.getElementById('cmapMpName').value = sec.getAttribute('data-pick-name');
            document.getElementById('cmapMpPath').value = sec.getAttribute('data-pick-path');
            form.submit();
        }
    });

    query.addEventListener('input', function () {
        clearTimeout(zamanlayici);
        zamanlayici = setTimeout(ara, 300);
    });

    document.addEventListener('keydown', function (olay) {
        if (olay.key === 'Escape' && !drawer.hidden) kapat();
    });
})();
</script>

<?php if ($marketplaceSlug === 'trendyol') require __DIR__ . '/trendyol_attributes.php'; ?>
