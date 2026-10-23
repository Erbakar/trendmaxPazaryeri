<?php
$marketplaces = $data['marketplaces'] ?? [];
$csrf = $data['_csrf'] ?? '';

$mpInfo = [
    'trendyol'    => ['name'=>'Trendyol',     'color'=>'#f27a1a', 'gradient'=>'#f27a1a', 'desc'=>'Türkiye\'nin en büyük e-ticaret platformu'],
    'hepsiburada' => ['name'=>'Hepsiburada',  'color'=>'#ff6000', 'gradient'=>'#ff6000', 'desc'=>'Teknoloji ve elektronik odaklı platform'],
    'n11'         => ['name'=>'n11',           'color'=>'#7b2d96', 'gradient'=>'#7b2d96', 'desc'=>'Geniş ürün yelpazesi ile hizmet veren platform'],
    'amazon'      => ['name'=>'Amazon TR',     'color'=>'#ff9900', 'gradient'=>'#ff9900', 'desc'=>'Dünya çapında lider e-ticaret platformu'],
    'pazarama'    => ['name'=>'Pazarama',      'color'=>'#00a8e1', 'gradient'=>'#00a8e1', 'desc'=>'PTT\'nin e-ticaret platformu'],
    'ciceksepeti' => ['name'=>'ÇiçekSepeti',  'color'=>'#e91e63', 'gradient'=>'#e91e63', 'desc'=>'Çiçek ve hediye odaklı e-ticaret platformu'],
    'pttavm'      => ['name'=>'PttAVM',        'color'=>'#ffc107', 'gradient'=>'#ffc107', 'desc'=>'PTT Alışveriş Merkezi'],
    'idefix'      => ['name'=>'İdefix',        'color'=>'#1a1a2e', 'gradient'=>'#1a1a2e', 'desc'=>'E-ticaret platformu'],
    'shopify'     => ['name'=>'Shopify',       'color'=>'#95bf47', 'gradient'=>'#95bf47', 'desc'=>'Global e-ticaret platformu'],
    'woocommerce' => ['name'=>'WooCommerce',   'color'=>'#7f54b3', 'gradient'=>'#7f54b3', 'desc'=>'WordPress e-ticaret platformu'],
    'opencart'    => ['name'=>'OpenCart',       'color'=>'#1c8ed8', 'gradient'=>'#1c8ed8', 'desc'=>'Self-hosted e-ticaret platformu'],
];

// Bölünmüş tasarım — YALNIZ mağazanın YETKİLİ (entitled) olduğu pazaryerleri bağlanabilir; yetkisizler KİLİTLİ.
$isEnt     = static fn($m) => !empty($m['entitled']);
// HB İlan Eşleştirme: ürün görüntüleme izni + 'products' modülü olan kullanıcıya, bağlı HB kartında
$canPermission = $data['_can'] ?? static fn(string $permission): bool => false;
$canModule     = $data['_canModule'] ?? static fn(string $module): bool => false;
$canListingMatch = $canPermission('products.view') && $canModule('products');
$connected = array_values(array_filter($marketplaces, static fn($m) => $isEnt($m) && !empty($m['is_active'])));
$available = array_values(array_filter($marketplaces, static fn($m) => $isEnt($m) && empty($m['is_active'])));
$locked    = array_values(array_filter($marketplaces, static fn($m) => !$isEnt($m)));
?>
<style>
/* ─── Pazaryeri kart grid — "Aksan Çizgili" (self-contained; Tailwind purge'den bağımsız) ─── */
.mk-section{margin-bottom:26px}
.mk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.mk-card{position:relative;overflow:hidden;background:#fff;background:rgb(var(--surface,255 255 255));border:1px solid var(--border-light,#f1f5f9);border-radius:18px;padding:16px 16px 16px 19px;box-shadow:0 0 0 1px var(--border);transition:transform .18s ease,box-shadow .18s ease}
.mk-card:hover{transform:translateY(-2px);box-shadow:0 0 0 1px var(--border)}
.mk-card--col{display:flex;flex-direction:column}
.mk-accent{position:absolute;left:0;top:0;bottom:0;width:4px}
.mk-accent--mute{background:var(--border,#e2e8f0)}
.mk-head{display:flex;align-items:center;gap:11px}
.mk-logo{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:none;overflow:hidden;background:#fff;border:1px solid var(--border-light,#f1f5f9);box-shadow:0 0 0 1px var(--border)}
.mk-logo img{max-width:100%;max-height:24px;width:auto;object-fit:contain}
.mk-meta{min-width:0;flex:1}
.mk-name{font-size:14px;font-weight:600;letter-spacing:-.01em;line-height:1.25;color:var(--text-primary,#0f172a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mk-name--muted{color:var(--text-secondary,#475569)}
.mk-desc{font-size:11px;color:var(--text-muted,#94a3b8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.mk-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;flex:none}
.mk-pill svg,.mk-pill i{width:12px;height:12px}
.mk-pill-on{background:rgba(16,185,129,.12);color:#059669}
.mk-pill-off{background:rgba(100,116,139,.12);color:var(--text-muted,#64748b)}
.mk-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex:none}
.mk-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:15px}
.mk-chip{border-radius:10px;padding:11px 6px;text-align:center}
.mk-chip-val{font-size:16px;font-weight:700;line-height:1;font-variant-numeric:tabular-nums}
.mk-chip-lbl{font-size:9px;letter-spacing:.04em;text-transform:uppercase;color:var(--text-muted,#94a3b8);margin-top:4px;font-weight:600}
.mk-conn{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted,#94a3b8);margin-top:12px}
.mk-conn svg,.mk-conn i{width:13px;height:13px;flex:none}
.mk-note{font-size:11.5px;color:var(--text-muted,#94a3b8);line-height:1.5;margin-top:10px}
.mk-actions{display:flex;gap:8px;margin-top:15px;align-items:stretch}
.mk-btn-danger{background:rgba(239,68,68,.10);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
.mk-btn-danger:hover{background:rgba(239,68,68,.18)}
.mpp-warn{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 14px;font-size:12.5px;color:#b91c1c;margin-bottom:14px}
.mpp-tbl{width:100%;border-collapse:collapse;font-size:13px}
.mpp-tbl td{padding:7px 4px;border-bottom:1px solid var(--border,#e5e7eb)}
.mpp-tbl td:last-child{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
.mpp-keep{background:rgba(16,185,129,.09);border-radius:10px;padding:11px 13px;font-size:12.5px;color:#047857;margin-top:12px}
.mpp-bar{height:9px;background:rgba(127,127,127,.15);border-radius:6px;overflow:hidden;margin:12px 0}
.mpp-bar span{display:block;height:100%;background:#dc2626;width:0;transition:width .3s}
.mk-actions--bottom{margin-top:auto;padding-top:14px}
.mk-actions--extra{margin-top:8px}
.mk-btn:focus-visible{outline:2px solid rgb(var(--brand));outline-offset:2px}
.mk-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;height:38px;border-radius:10px;font-size:12px;font-weight:600;border:none;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s ease;white-space:nowrap}
.mk-btn svg,.mk-btn i{width:15px;height:15px;flex:none}
.mk-btn-ghost{background:var(--bg-input,#f4f6f8);color:var(--text-secondary,#475569);border:1px solid var(--border,#e2e8f0)}
.mk-btn-ghost:hover{border-color:var(--primary);border-color:rgb(var(--brand));color:var(--primary);color:rgb(var(--brand))}
.mk-btn-brand{background:var(--primary);background:rgb(var(--brand));color:#fff}
.mk-btn-brand:hover{filter:brightness(1.08)}
.mk-btn-icon{flex:0 0 auto;width:42px}
.mk-sectit{display:flex;align-items:center;gap:9px;margin:0 0 13px}
.mk-sectit svg,.mk-sectit i{width:16px;height:16px}
.mk-eyebrow{font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted,#94a3b8)}
.mk-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;font-size:11px;font-weight:700;font-variant-numeric:tabular-nums}
.mk-count-on{background:rgba(16,185,129,.12);color:#059669}
.mk-count-off{background:rgba(100,116,139,.12);color:var(--text-muted,#64748b)}
[data-theme="dark"] .mk-logo{background:var(--bg-input)}
@media(max-width:640px){.mk-grid{grid-template-columns:1fr}}
</style>

<!-- HEADER -->
<div class="ui-page-head">
    <div>
        <h1>Pazaryerleri</h1>
        <p>Tüm pazaryeri entegrasyonlarınızı buradan yönetin.</p>
    </div>
</div>
<div class="ui-stats">
    <div class="ui-stat"><span class="ui-stat-icon is-green"><i data-lucide="plug-zap"></i></span><div class="ui-stat-body"><p class="ui-stat-label">Bağlı</p><div class="ui-stat-value"><?= count($connected) ?></div></div></div>
    <div class="ui-stat"><span class="ui-stat-icon is-slate"><i data-lucide="power-off"></i></span><div class="ui-stat-body"><p class="ui-stat-label">Pasif</p><div class="ui-stat-value"><?= count($available) ?></div></div></div>
    <div class="ui-stat"><span class="ui-stat-icon"><i data-lucide="store"></i></span><div class="ui-stat-body"><p class="ui-stat-label">Toplam</p><div class="ui-stat-value"><?= count($connected) + count($available) ?></div></div></div>
</div>

<?php
$mpResolve = static function (array $mp) use ($mpInfo): array {
    $slug = $mp['slug'] ?? '';
    $info = $mpInfo[$slug] ?? ['name' => $mp['display_name'] ?? $slug, 'color' => 'var(--primary)', 'desc' => 'E-ticaret platformu'];
    return [$slug, $info];
};
?>

<!-- AKTİF ENTEGRASYONLAR -->
<?php if (!empty($connected)): ?>
<div class="mk-section">
  <div class="mk-sectit"><i data-lucide="plug-zap" class="text-success"></i><span class="mk-eyebrow">Aktif Entegrasyonlar</span><span class="mk-count mk-count-on"><?= count($connected) ?></span></div>
  <div class="mk-grid">
    <?php foreach ($connected as $mp): [$slug, $info] = $mpResolve($mp); ?>
    <div data-mp-card class="mk-card">
      <span class="mk-accent" style="background:<?= $info['color'] ?>"></span>
      <div class="mk-head">
        <span class="mk-logo"><img src="/public/assets/img/marketplaces/<?= $slug ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $slug ?>.svg'" alt="<?= htmlspecialchars($info['name']) ?>"></span>
        <div class="mk-meta">
          <div class="mk-name"><?= htmlspecialchars($info['name']) ?></div>
          <div class="mk-desc"><?= htmlspecialchars($info['desc']) ?></div>
        </div>
        <span class="mk-pill mk-pill-on"><span class="mk-dot"></span>Aktif</span>
      </div>
      <div class="mk-stats">
        <div class="mk-chip" style="background:rgba(91,92,235,.10)"><div class="mk-chip-val" style="color:var(--primary)"><?= number_format($mp['active_products'] ?? 0) ?></div><div class="mk-chip-lbl">Ürün</div></div>
        <div class="mk-chip" style="background:rgba(14,165,233,.12)"><div class="mk-chip-val" style="color:#0ea5e9"><?= number_format($mp['monthly_orders'] ?? 0) ?></div><div class="mk-chip-lbl">Bu Ay</div></div>
        <div class="mk-chip" style="background:rgba(16,185,129,.12)"><div class="mk-chip-val" style="color:#059669"><?= number_format($mp['total_orders'] ?? 0) ?></div><div class="mk-chip-lbl">Sipariş</div></div>
      </div>
      <div data-mp-conn class="mk-conn">
        <?php if (!empty($mp['last_connected_at'])): ?>
        <i data-lucide="check-circle-2" class="text-success"></i> Son başarılı test: <?= date('d.m.Y H:i', strtotime($mp['last_connected_at'])) ?>
        <?php else: ?>
        <i data-lucide="info"></i> Bağlantı testi henüz yapılmadı
        <?php endif; ?>
      </div>
      <div class="mk-actions">
        <a href="/marketplaces/<?= $mp['id'] ?>/edit" class="mk-btn mk-btn-ghost"><i data-lucide="settings"></i> Ayarlar</a>
        <button type="button" class="mk-btn mk-btn-ghost" onclick="testConn(<?= $mp['id'] ?>,this)"><i data-lucide="plug-zap"></i> Test</button>
        <button type="button" class="mk-btn mk-btn-brand mk-btn-icon" title="Sistemle Eşleştir" onclick="openOnboardingWizard(<?= $mp['id'] ?>,'<?= $slug ?>','<?= htmlspecialchars($info['name'], ENT_QUOTES) ?>')"><i data-lucide="refresh-cw"></i></button>
        <button type="button" class="mk-btn mk-btn-danger mk-btn-icon" title="Bu pazaryerinin verilerini sil" onclick="mpPurgeOpen(<?= $mp['id'] ?>,'<?= htmlspecialchars($info['name'], ENT_QUOTES) ?>')"><i data-lucide="trash-2"></i></button>
      </div>
      <?php if ($slug === 'hepsiburada' && $canListingMatch && !empty($mp['store_connected'])): ?>
      <div class="mk-actions mk-actions--extra">
        <a href="/marketplaces/<?= (int) $mp['id'] ?>/listing-match" class="mk-btn mk-btn-ghost"><i data-lucide="link-2" aria-hidden="true"></i> İlan Eşleştirme</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- BAĞLANABİLİR -->
<?php if (!empty($available)): ?>
<div class="mk-section">
  <div class="mk-sectit"><i data-lucide="circle-dashed" class="text-slate-400"></i><span class="mk-eyebrow">Bağlanabilir</span><span class="mk-count mk-count-off"><?= count($available) ?></span></div>
  <div class="mk-grid">
    <?php foreach ($available as $mp): [$slug, $info] = $mpResolve($mp); ?>
    <div class="mk-card mk-card--col">
      <span class="mk-accent mk-accent--mute"></span>
      <div class="mk-head">
        <span class="mk-logo" style="filter:grayscale(.5);opacity:.9"><img src="/public/assets/img/marketplaces/<?= $slug ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $slug ?>.svg'" alt="<?= htmlspecialchars($info['name']) ?>"></span>
        <div class="mk-meta">
          <div class="mk-name mk-name--muted"><?= htmlspecialchars($info['name']) ?></div>
          <div class="mk-desc"><?= htmlspecialchars($info['desc']) ?></div>
        </div>
        <span class="mk-pill mk-pill-off">Pasif</span>
      </div>
      <p class="mk-note">API bilgilerini girerek bağlanın; ürün, sipariş ve stok senkronizasyonu açılır.</p>
      <div class="mk-actions mk-actions--bottom">
        <a href="/marketplaces/<?= $mp['id'] ?>/edit" class="mk-btn mk-btn-brand"><i data-lucide="plug"></i> Bağlan</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- PAKETE DAHİL DEĞİL (kilitli) -->
<?php if (!empty($locked)): ?>
<div class="mk-section">
  <div class="mk-sectit"><i data-lucide="lock" class="text-slate-400"></i><span class="mk-eyebrow">Pakete Dahil Değil</span><span class="mk-count mk-count-off"><?= count($locked) ?></span></div>
  <div class="mk-grid">
    <?php foreach ($locked as $mp): [$slug, $info] = $mpResolve($mp); ?>
    <div class="mk-card mk-card--col">
      <span class="mk-accent mk-accent--mute"></span>
      <div class="mk-head">
        <span class="mk-logo" style="filter:grayscale(1);opacity:.65"><img src="/public/assets/img/marketplaces/<?= $slug ?>.png" onerror="this.onerror=null;this.src='/public/assets/img/marketplaces/<?= $slug ?>.svg'" alt="<?= htmlspecialchars($info['name']) ?>"></span>
        <div class="mk-meta">
          <div class="mk-name mk-name--muted"><?= htmlspecialchars($info['name']) ?></div>
          <div class="mk-desc">Pakette yok</div>
        </div>
        <span class="mk-pill mk-pill-off"><i data-lucide="lock"></i></span>
      </div>
      <p class="mk-note">Bu pazaryeri paketinizde yok. Bağlantı ve senkron için eklenti olarak ekleyin.</p>
      <div class="mk-actions mk-actions--bottom">
        <a href="/subscription/modules" class="mk-btn mk-btn-brand"><i data-lucide="plus"></i> Ekle</a>
        <a href="/subscription" class="mk-btn mk-btn-ghost mk-btn-icon" title="Aboneliğim"><i data-lucide="credit-card"></i></a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (empty($connected) && empty($available) && empty($locked)): ?>
<div class="mk-card" style="padding:40px;text-align:center;color:var(--text-muted,#94a3b8)">Pazaryeri bulunamadı.</div>
<?php endif; ?>



<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';

function testConn(id, btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader" style="width:15px;height:15px;animation:spin 1s linear infinite"></i> Test ediliyor...';
    btn.disabled = true;
    lucide.createIcons({nodes: [btn]});

    const fd = new FormData();
    fd.append('_csrf_token', CSRF);

    fetch('/marketplaces/' + id + '/test', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        btn.innerHTML = orig; btn.disabled = false; lucide.createIcons({nodes: [btn]});
        if (d.success) {
            Toast.show('Bağlantı başarılı!', 'success');

            // Kartı canlı güncelle — bağlantı satırına son bağlantı zamanını yaz
            const card = btn.closest('[data-mp-card]');
            if (card) {
                const connEl = card.querySelector('[data-mp-conn]');
                if (connEl) {
                    const now = new Date();
                    const dateStr = now.toLocaleDateString('tr-TR', {day:'2-digit',month:'2-digit',year:'numeric'})
                                  + ' ' + now.toLocaleTimeString('tr-TR', {hour:'2-digit',minute:'2-digit'});
                    connEl.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-success"></i> Son başarılı test: ' + dateStr;
                    lucide.createIcons({nodes: [connEl]});
                }
            }
        } else {
            Toast.show('Hata: ' + (d.message || 'Bilinmeyen hata'), 'error', 6000);
        }
    })
    .catch(() => {
        btn.innerHTML = orig; btn.disabled = false; lucide.createIcons({nodes: [btn]});
        Toast.show('Bağlantı hatası', 'error');
    });
}

/* ══════════════════════════════════════════
   ONBOARDING WIZARD
   ══════════════════════════════════════════ */
// ─── Pazaryeri verisi silme ────────────────────────────────
// Geri alınamaz bir işlem: önce sayımlı özet gösterilir, onaydan sonra
// parça parça silinir (9.000 ürünlük pazaryeri tek istekte silinemez).
async function mpPurgeOpen(mpId, mpName) {
    const old = document.getElementById('owiz-overlay');
    if (old) old.remove();

    const overlay = document.createElement('div');
    overlay.id = 'owiz-overlay';
    overlay.innerHTML = `
      <div class="owiz-modal" style="max-width:520px">
        <button class="owiz-close" onclick="document.getElementById('owiz-overlay').remove()" title="Kapat"><i data-lucide="x"></i></button>
        <div class="owiz-header">
            <div class="owiz-title">${mpName} verilerini sil</div>
            <div class="owiz-sub">Bu pazaryerinden gelen veriler kalıcı olarak silinecek.</div>
        </div>
        <div class="owiz-scroll-area" id="mpp-body" style="padding:20px 24px">
            <div style="text-align:center;padding:24px;color:#6b7280">Hesaplanıyor…</div>
        </div>
        <div class="owiz-footer" id="mpp-foot"></div>
      </div>`;

    document.body.appendChild(overlay);
    if (typeof lucide !== 'undefined') lucide.createIcons();

    const fd = new FormData();
    fd.append('_csrf_token', CSRF);

    let d;
    try {
        const resp = await owizPostJson(`/marketplaces/${mpId}/purge-preview`, fd);
        if (!resp.success) throw new Error(resp.message || 'Önizleme alınamadı');
        d = resp.data;
    } catch (err) {
        document.getElementById('mpp-body').innerHTML =
            `<div class="mpp-warn">${mppEsc(err.message)}</div>`;
        return;
    }

    const satir = (etiket, deger) => deger > 0
        ? `<tr><td>${etiket}</td><td>${deger.toLocaleString('tr-TR')}</td></tr>` : '';

    const toplam = d.products_deleted + d.orders + d.returns + d.questions
        + d.finance + d.invoices + d.category_mapping + d.brand_mapping;

    document.getElementById('mpp-body').innerHTML = `
        <div class="mpp-warn"><strong>Bu işlem geri alınamaz.</strong> Aşağıdaki kayıtlar kalıcı olarak silinecek.</div>
        ${toplam === 0 ? '<div style="padding:8px 0;color:#6b7280">Silinecek kayıt bulunamadı.</div>' : `
        <table class="mpp-tbl">
            ${satir('Ürün', d.products_deleted)}
            ${satir('Sipariş', d.orders)}
            ${satir('Fatura', d.invoices)}
            ${satir('İade', d.returns)}
            ${satir('Soru-cevap', d.questions)}
            ${satir('Finans hareketi', d.finance)}
            ${satir('Kategori eşleştirmesi', d.category_mapping)}
            ${satir('Marka eşleştirmesi', d.brand_mapping)}
        </table>`}
        ${d.products_kept > 0 ? `<div class="mpp-keep"><strong>${d.products_kept.toLocaleString('tr-TR')} ürün korunacak</strong> — başka pazaryerlerinde de satıldıkları için yalnız ${mppEsc(d.display_name)} bağlantıları kaldırılacak.</div>` : ''}
        <div class="mpp-keep" style="background:rgba(59,130,246,.08);color:#1d4ed8">API bilgileriniz <strong>silinmez</strong>; pazaryeri yalnızca pasife alınır. İsterseniz sonra tekrar bağlayabilirsiniz.</div>
        <div class="mpp-bar" id="mpp-bar" style="display:none"><span></span></div>
        <div id="mpp-status" style="font-size:12.5px;color:#6b7280"></div>`;

    document.getElementById('mpp-foot').innerHTML = `
        <button class="owiz-btn owiz-btn-close" onclick="document.getElementById('owiz-overlay').remove()">Vazgeç</button>
        <button class="owiz-btn owiz-btn-all" id="mpp-go" style="background:#dc2626"
                onclick="mpPurgeRun(${mpId})"><i data-lucide="trash-2"></i> Evet, sil</button>`;

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function mppEsc(v) {
    const d = document.createElement('div');
    d.textContent = v == null ? '' : String(v);
    return d.innerHTML;
}

async function mpPurgeRun(mpId) {
    const btn = document.getElementById('mpp-go');
    const bar = document.getElementById('mpp-bar');
    const durum = document.getElementById('mpp-status');

    btn.disabled = true;
    btn.innerHTML = 'Siliniyor…';
    bar.style.display = 'block';

    let tur = 0;
    try {
        while (tur < 200) {
            tur++;
            const fd = new FormData();
            fd.append('_csrf_token', CSRF);

            const resp = await owizPostJson(`/marketplaces/${mpId}/purge`, fd);
            if (!resp.success) throw new Error(resp.message || 'Silme başarısız');

            const d = resp.data;
            durum.textContent = `${(d.total || 0).toLocaleString('tr-TR')} kayıt silindi…`;
            bar.querySelector('span').style.width = (d.done ? 100 : Math.min(90, tur * 12)) + '%';

            if (d.done) {
                durum.textContent = `Tamamlandı — ${(d.total || 0).toLocaleString('tr-TR')} kayıt silindi. Sayfa yenileniyor…`;
                setTimeout(() => window.location.reload(), 1200);
                return;
            }
        }
        throw new Error('Silme beklenenden uzun sürdü — sayfayı yenileyip tekrar deneyin.');
    } catch (err) {
        durum.innerHTML = `<span style="color:#b91c1c">${mppEsc(err.message)}</span>`;
        btn.disabled = false;
        btn.innerHTML = 'Tekrar dene';
    }
}

function openOnboardingWizard(mpId, slug, mpName) {
    const old = document.getElementById('owiz-overlay');
    if (old) old.remove();

    const ALL_STEPS = [
        { key:'brands',     icon:'tag',          label:'Markalar',    desc:'Pazaryeri markaları çekiliyor ve sistem markalarıyla eşleştiriliyor', hasDays: false },
        { key:'categories', icon:'folder-tree',   label:'Kategoriler', desc:'Kategori ağacı çekiliyor ve mevcut kategorilerle eşleştiriliyor', hasDays: false },
        { key:'products',   icon:'package',       label:'Ürünler',     desc:'Ürünler sayfa sayfa çekiliyor, mevcut havuzla SKU/barkod eşleştirmesi yapılıyor', hasDays: false },
        { key:'orders',     icon:'shopping-cart',  label:'Siparişler',  desc:'Siparişler ve müşteri bilgileri çekiliyor', hasDays: true, defaultDays: 60 },
        { key:'returns',    icon:'rotate-ccw',     label:'İadeler',     desc:'İade talepleri çekiliyor ve siparişlerle eşleştiriliyor', hasDays: true, defaultDays: 365, slugs:['trendyol','n11','pazarama','hepsiburada','idefix'] },
        { key:'finance',    icon:'wallet',         label:'Finans',      desc:'Finansal işlemler ve hesap hareketleri çekiliyor', hasDays: true, defaultDays: 15, slugs:['trendyol','hepsiburada'] },
        { key:'questions',  icon:'message-circle', label:'Soru & Cevap',desc:'Müşteri soruları çekiliyor', hasDays: true, defaultDays: 14, slugs:['trendyol','hepsiburada'] },
    ];
    const STEPS = ALL_STEPS.filter(s => !s.slugs || s.slugs.includes(slug));

    const overlay = document.createElement('div');
    overlay.id = 'owiz-overlay';
    overlay.innerHTML = `
    <div class="owiz-modal">
        <button class="owiz-close" onclick="closeWizard()" title="Kapat"><i data-lucide="x"></i></button>
        <div class="owiz-header">
            <img src="/public/assets/img/marketplaces/${slug}.png" alt="${mpName}" class="owiz-mp-logo" onerror="this.style.display='none'">
            <p>Pazaryeri verilerinizi sisteme aktarın. Tek tek veya hepsini birden çekebilirsiniz.</p>
        </div>

        <div class="owiz-scroll-area">
        <div class="owiz-steps" id="owiz-steps">
            ${STEPS.map(s => `
            <div class="owiz-step" id="owiz-step-${s.key}" data-status="waiting">
                <div class="owiz-step-icon" id="owiz-icon-${s.key}">
                    <i data-lucide="${s.icon}"></i>
                </div>
                <div class="owiz-step-body">
                    <div class="owiz-step-top">
                        <span class="owiz-step-label">${s.label}</span>
                        <div class="owiz-step-actions">
                            ${s.hasDays ? `<div class="owiz-days-box"><i data-lucide="calendar-days"></i><span>Son</span><input type="number" id="owiz-days-${s.key}" value="${s.defaultDays}" min="1" max="730" class="owiz-days-input"><span>gün</span></div>` : ''}
                            <button class="owiz-step-btn" id="owiz-btn-${s.key}" onclick="runOneStep('${s.key}')">
                                <i data-lucide="download"></i> Verileri Çek
                            </button>
                        </div>
                    </div>
                    <div class="owiz-step-desc">${s.desc}</div>
                    <div class="owiz-step-bar-wrap">
                        <div class="owiz-step-bar" id="owiz-bar-${s.key}"></div>
                    </div>
                    <div class="owiz-step-counter" id="owiz-counter-${s.key}"></div>
                </div>
            </div>
            `).join('')}
        </div>

        <div class="owiz-summary" id="owiz-summary" style="display:none">
            <div class="owiz-summary-title"><i data-lucide="party-popper"></i> Aktarım Tamamlandı!</div>
            <div class="owiz-summary-grid" id="owiz-summary-grid"></div>
        </div>
        </div>

        <div class="owiz-footer">
            <button class="owiz-btn owiz-btn-all" id="owiz-all-btn" onclick="runAllSteps()">
                <i data-lucide="download-cloud"></i> Hepsini Çek
            </button>
            <button class="owiz-btn owiz-btn-close" onclick="closeWizard()">
                <i data-lucide="x"></i> Kapat
            </button>
        </div>
    </div>`;
    document.body.appendChild(overlay);
    lucide.createIcons();

    window._owiz = { mpId, slug, mpName, STEPS, stats: {}, running: false };
}

function closeWizard() {
    if (window._owiz?.running) {
        if (!confirm('İşlem devam ediyor. Kapatmak istediğinize emin misiniz?')) return;
    }
    const el = document.getElementById('owiz-overlay');
    if (el) el.remove();
    // Refresh if any step was completed
    if (window._owiz && Object.keys(window._owiz.stats).length > 0) {
        location.reload();
    }
}

/* Run a single step on button click */
async function runOneStep(stepKey) {
    if (window._owiz.running) return;
    const { mpId } = window._owiz;

    window._owiz.running = true;
    disableAllStepBtns(true);
    setStepStatus(stepKey, 'running');

    try {
        if (stepKey === 'products') {
            await runProductPages(mpId, stepKey);
        } else if (stepKey === 'brands') {
            await runBrandPages(mpId, stepKey);
        } else if (stepKey === 'finance') {
            await runFinancePages(mpId, stepKey);
        } else if (stepKey === 'orders') {
            await runOrderBlocks(mpId, stepKey);
        } else {
            await runSingleStep(mpId, stepKey);
        }
        setStepStatus(stepKey, 'done');
    } catch (err) {
        setStepStatus(stepKey, 'error', err.message);
    }

    window._owiz.running = false;
    disableAllStepBtns(false);
}

/* Run all steps sequentially */
async function runAllSteps() {
    if (window._owiz.running) return;
    const { mpId, STEPS } = window._owiz;

    window._owiz.running = true;
    disableAllStepBtns(true);
    const allBtn = document.getElementById('owiz-all-btn');
    allBtn.disabled = true;
    allBtn.innerHTML = '<div class="owiz-spinner" style="width:16px;height:16px"></div> Çekiliyor...';

    for (const s of STEPS) {
        // Skip already completed steps
        if (document.getElementById('owiz-step-' + s.key)?.dataset.status === 'done') continue;

        setStepStatus(s.key, 'running');
        try {
            if (s.key === 'products') {
                await runProductPages(mpId, s.key);
            } else if (s.key === 'brands') {
                await runBrandPages(mpId, s.key);
            } else if (s.key === 'finance') {
                await runFinancePages(mpId, s.key);
            } else {
                await runSingleStep(mpId, s.key);
            }
            setStepStatus(s.key, 'done');
        } catch (err) {
            setStepStatus(s.key, 'error', err.message);
        }
    }

    window._owiz.running = false;
    allBtn.innerHTML = '<i data-lucide="check"></i> Tamamlandı';
    allBtn.disabled = true;
    lucide.createIcons({nodes: [allBtn]});
    showSummary();
}

function disableAllStepBtns(disabled) {
    const { STEPS } = window._owiz;
    for (const s of STEPS) {
        const btn = document.getElementById('owiz-btn-' + s.key);
        if (btn) btn.disabled = disabled;
    }
}

/**
 * POST + JSON. Sunucu JSON yerine HTML dönerse (PHP ölümcül hatası, hosting'in
 * 500 sayfası, oturum bitince login'e yönlenme) kullanıcıya "Unexpected token '<'"
 * yerine gerçek sebebi gösterir.
 */
async function owizPostJson(url, fd) {
    let resp;
    try {
        resp = await fetch(url, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
            credentials: 'same-origin',
            body: fd
        });
    } catch (netErr) {
        throw new Error('Sunucuya ulaşılamadı: ' + netErr.message);
    }

    const text = await resp.text();

    try {
        return JSON.parse(text);
    } catch (parseErr) {
        const looksHtml = /^\s*(<!doctype|<html|<br)/i.test(text);

        if (resp.status === 401 || /login|giriş yap/i.test(text.slice(0, 400))) {
            throw new Error('Oturumunuz sona ermiş — sayfayı yenileyip tekrar giriş yapın.');
        }
        if (resp.status >= 500 || resp.status === 0) {
            throw new Error(
                'Sunucu bu adımı tamamlayamadı (HTTP ' + resp.status + '). '
                + 'İşlem büyük olasılıkla zaman/bellek sınırına takıldı. '
                + 'Tarih aralığını daraltıp tekrar deneyin; sürerse storage/logs kaydına bakılmalı.'
            );
        }
        if (looksHtml) {
            throw new Error('Sunucu beklenen yanıtı vermedi (HTTP ' + resp.status + '). Yanıt JSON değil, HTML.');
        }
        throw new Error('Sunucu yanıtı çözümlenemedi (HTTP ' + resp.status + '): ' + text.slice(0, 160));
    }
}

async function runSingleStep(mpId, stepKey) {
    const fd = new FormData();
    fd.append('_csrf_token', CSRF);
    fd.append('step', stepKey);
    fd.append('page', '0');

    // Gün sayısı inputu varsa gönder
    const daysInput = document.getElementById('owiz-days-' + stepKey);
    if (daysInput) {
        fd.append('days', daysInput.value);
    }

    const d = await owizPostJson(`/marketplaces/${mpId}/initial-import-step`, fd);
    if (!d.success) throw new Error(d.message || 'Bilinmeyen hata');

    // Parse counts
    const stats = { count: 0, matched: 0, extra: '' };
    if (d.count !== undefined) {
        stats.count = d.count;
    } else if (d.data) {
        stats.count = d.data.processed || d.data.fetched || d.data.created || 0;
        stats.matched = d.data.matched || d.data.skipped || 0;
        if (d.data.supported === false) {
            stats.extra = 'Bu pazaryerinde desteklenmiyor';
            stats.count = 0;
        }
        if (d.data.skippedReason === 'not_supported') {
            stats.extra = 'Bu pazaryerinde desteklenmiyor';
        }
    }

    window._owiz.stats[stepKey] = stats;
    updateCounter(stepKey, stats);
    setBarProgress(stepKey, 100);
}

/**
 * Siparişleri BLOK BLOK çeker.
 *
 * Tek istekte 60 günlük çekim sunucuda zaman/bellek sınırına takılıp HTTP 500
 * veriyordu. Artık her istek yalnız bir tarih bloğunu işler; en yeni blok önce gelir.
 */
async function runOrderBlocks(mpId, stepKey) {
    const daysInput = document.getElementById('owiz-days-' + stepKey);
    const days = daysInput ? daysInput.value : '';

    let block = 0, totalBlocks = 1;
    let fetched = 0, processed = 0, skipped = 0, failed = 0;
    let firstError = '';
    const MAX_BLOCKS = 40;

    while (block < MAX_BLOCKS) {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('step', 'orders');
        fd.append('page', block.toString());
        if (days) fd.append('days', days);

        const d = await owizPostJson(`/marketplaces/${mpId}/initial-import-step`, fd);
        if (!d.success) throw new Error(d.message || 'Sipariş import hatası');

        fetched   += (d.fetched   || 0);
        processed += (d.processed || 0);
        skipped   += (d.skipped   || 0);
        failed    += (d.failed    || 0);
        totalBlocks = d.totalPages || 1;

        if (!firstError && Array.isArray(d.errors) && d.errors.length > 0) {
            const e0 = d.errors[0];
            firstError = (e0.order_id ? e0.order_id + ': ' : '') + (e0.error || '');
        }

        const pct = Math.round(((block + 1) / Math.min(totalBlocks, MAX_BLOCKS)) * 100);
        setBarProgress(stepKey, Math.min(100, pct));

        const araTxt = (d.rangeStart && d.rangeEnd) ? ` (${d.rangeStart}–${d.rangeEnd})` : '';
        const hataTxt = failed > 0 ? `, ${failed} hata` : '';
        const stats = {
            count: processed,
            matched: skipped,
            extra: `Dönem ${block + 1}/${Math.min(totalBlocks, MAX_BLOCKS)}${araTxt} — ${processed} işlendi, ${skipped} atlandı${hataTxt}`
        };
        window._owiz.stats[stepKey] = stats;
        updateCounter(stepKey, stats);

        if (d.done) break;
        block++;
    }

    // Hiç sipariş işlenemediyse ve hata varsa adımı hatalı say — sebep görünür olsun
    if (failed > 0 && processed === 0) {
        throw new Error(`${failed} sipariş aktarılamadı. ${firstError}`);
    }

    // Kısmi hata: adım tamamlansın ama sebep kartta görünsün
    if (failed > 0 && firstError) {
        const counterEl = document.getElementById('owiz-counter-' + stepKey);
        if (counterEl) { counterEl.title = firstError; }
    }

    setBarProgress(stepKey, 100);
}

async function runProductPages(mpId, stepKey) {
    let page = 0, totalCreated = 0, totalUpdated = 0, totalFailed = 0, totalPages = 1;
    let firstError = '';
    const MAX_PAGES = 50; // Maksimum 50 sayfa (5000 ürün) çek, sonra durdur
    // Shopify gibi imleçli (since_id) servislerde "page" bir sayaç değil imleçtir;
    // döngü sınırı ayrı sayaçla tutulmalı (aksi halde ikinci sayfada duruyordu).
    let iteration = 0;

    while (iteration < MAX_PAGES) {
        iteration++;
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('step', 'products');
        fd.append('page', page.toString());

        const d = await owizPostJson(`/marketplaces/${mpId}/initial-import-step`, fd);
        if (!d.success) throw new Error(d.message || 'Ürün import hatası');

        totalCreated += (d.created || 0);
        totalUpdated += (d.updated || 0);
        totalFailed += (d.failed || 0);
        totalPages = d.totalPages || 1;

        if (!firstError && Array.isArray(d.errors) && d.errors.length > 0) {
            const e0 = d.errors[0];
            firstError = (e0.row ? e0.row + ': ' : '') + (e0.error || '');
        }

        const pct = totalPages > 0 ? Math.round((iteration / Math.min(totalPages, MAX_PAGES)) * 100) : 100;
        setBarProgress(stepKey, Math.min(100, pct));

        const failTxt = totalFailed > 0 ? `, ${totalFailed} hata` : '';
        const stats = {
            count: totalCreated + totalUpdated,
            matched: totalUpdated,
            extra: `Sayfa ${iteration}/${Math.min(totalPages, MAX_PAGES)} — ${totalCreated} yeni, ${totalUpdated} eşleşti${failTxt}`
        };

        // Pazaryeri kotası (ör. ÇiçekSepeti 10 dk/istek): alınan sayfalar kaydedildi,
        // kalanlar sonraki senkronda — hata değil bilgi olarak göster
        if (d.rateLimited) {
            stats.extra = `${totalCreated} yeni, ${totalUpdated} eşleşti — ${d.notice || 'Kalan sayfalar sonraki senkronda alınacak.'}`;
            window._owiz.stats[stepKey] = stats;
            updateCounter(stepKey, stats);
            setBarProgress(stepKey, 100);
            break;
        }

        window._owiz.stats[stepKey] = stats;
        updateCounter(stepKey, stats);

        if (d.done || iteration >= MAX_PAGES) break;
        page = (d.nextPage !== undefined && d.nextPage !== null) ? d.nextPage : (page + 1);
    }

    // Hiç kayıt işlenemediyse ve hata varsa adımı hatalı say — sebep görünür olsun
    if (totalFailed > 0 && totalCreated + totalUpdated === 0) {
        throw new Error(`${totalFailed} ürün aktarılamadı. ${firstError}`);
    }

    // Kısmi hata: adım tamamlansın ama sebep kartta görünsün
    if (totalFailed > 0 && firstError) {
        const counterEl = document.getElementById('owiz-counter-' + stepKey);
        if (counterEl) {
            counterEl.title = firstError;
        }
    }
}

/* Markalar: sayfa sayfa çek (Trendyol için ürünlerden marka derleme) */
async function runBrandPages(mpId, stepKey) {
    let page = 0, totalProcessed = 0, totalPages = 1;
    const MAX_PAGES = 100; // 20k ürüne kadar tarar
    let iteration = 0;

    while (iteration < MAX_PAGES) {
        iteration++;
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('step', 'brands');
        fd.append('page', page.toString());

        const d = await owizPostJson(`/marketplaces/${mpId}/initial-import-step`, fd);
        if (!d.success) throw new Error(d.message || 'Marka import hatası');

        totalPages = d.totalPages || 1;
        totalProcessed = d.totalProcessed ?? totalProcessed;

        const pct = totalPages > 0 ? Math.round((iteration / Math.min(totalPages, MAX_PAGES)) * 100) : 100;
        setBarProgress(stepKey, Math.min(100, pct));

        const stats = {
            count: totalProcessed,
            matched: 0,
            extra: `Sayfa ${iteration}/${Math.min(totalPages, MAX_PAGES)} — ${totalProcessed} marka`
        };
        window._owiz.stats[stepKey] = stats;
        updateCounter(stepKey, stats);

        if (d.done || iteration >= MAX_PAGES) break;
        page = (d.nextPage !== undefined && d.nextPage !== null) ? d.nextPage : (page + 1);
    }
}

/* Finans: gün gün çek (mevcut /finance/sync-day endpoint'ini kullanır) */
async function runFinancePages(mpId, stepKey) {
    const { slug } = window._owiz;
    const totalDays = parseInt(document.getElementById('owiz-days-finance')?.value || '15', 10);
    let totalCreated = 0, totalSkipped = 0;
    let ilkFinansHatasi = '', finansHataliGun = 0;

    // Desteklenmeyen pazaryeri kontrolü
    if (!['trendyol', 'pazarama', 'hepsiburada'].includes(slug)) {
        const stats = { count: 0, extra: 'Bu pazaryerinde desteklenmiyor' };
        window._owiz.stats[stepKey] = stats;
        updateCounter(stepKey, stats);
        setBarProgress(stepKey, 100);
        return;
    }

    for (let day = 0; day < totalDays; day++) {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF);
        fd.append('day', day.toString());
        fd.append('marketplace', slug);

        const d = await owizPostJson('/finance/sync-day', fd);
        if (!d.success && d.message) {
            // Bazı günler boş olabilir → döngü devam eder; ancak sebep artık
            // sessizce yutulmuyor, ilk hata kartın üzerinde gösteriliyor.
            if (!ilkFinansHatasi) { ilkFinansHatasi = d.message; }
            finansHataliGun++;
        }

        totalCreated += (d.data?.created || 0);
        totalSkipped += (d.data?.skipped || 0);

        const pct = Math.round(((day + 1) / totalDays) * 100);
        setBarProgress(stepKey, pct);

        const stats = {
            count: totalCreated + totalSkipped,
            matched: totalSkipped,
            extra: `Gün ${day + 1}/${totalDays} — ${totalCreated} yeni, ${totalSkipped} mevcut`
        };
        window._owiz.stats[stepKey] = stats;
        updateCounter(stepKey, stats);
    }

    // Hiçbir gün alınamadıysa sebebi göster (eskiden tamamı sessizce yutuluyordu)
    if (ilkFinansHatasi && totalCreated + totalSkipped === 0) {
        throw new Error(`Finans kaydı alınamadı (${finansHataliGun} gün). ${ilkFinansHatasi}`);
    }
    if (ilkFinansHatasi) {
        const counterEl = document.getElementById('owiz-counter-' + stepKey);
        if (counterEl) { counterEl.title = `${finansHataliGun} günde hata: ${ilkFinansHatasi}`; }
    }
}

function setStepStatus(key, status, errMsg) {
    const el = document.getElementById('owiz-step-' + key);
    const btnEl = document.getElementById('owiz-btn-' + key);
    const iconEl = document.getElementById('owiz-icon-' + key);

    el.dataset.status = status;

    if (status === 'running') {
        btnEl.innerHTML = '<div class="owiz-spinner" style="width:14px;height:14px"></div> Çekiliyor...';
        btnEl.className = 'owiz-step-btn running';
        btnEl.disabled = true;
        iconEl.innerHTML = '<div class="owiz-spinner"></div>';
    } else if (status === 'done') {
        const st = window._owiz.stats[key];
        const countTxt = st ? st.count : 0;
        btnEl.innerHTML = '<i data-lucide="check" style="width:13px;height:13px"></i> ' + countTxt + ' kayıt';
        btnEl.className = 'owiz-step-btn done';
        btnEl.disabled = true;
        iconEl.innerHTML = '<i data-lucide="check-circle-2" style="color:#10b981"></i>';
        lucide.createIcons({nodes: [iconEl, btnEl]});
    } else if (status === 'error') {
        btnEl.innerHTML = '<i data-lucide="alert-triangle" style="width:13px;height:13px"></i> Hata';
        btnEl.className = 'owiz-step-btn error';
        btnEl.disabled = false;
        iconEl.innerHTML = '<i data-lucide="alert-triangle" style="color:#ef4444"></i>';
        lucide.createIcons({nodes: [iconEl, btnEl]});

        // Hata mesajını adım kartında göster — kullanıcı nedeni görebilsin
        const counterEl = document.getElementById('owiz-counter-' + key);
        if (counterEl && errMsg) {
            counterEl.textContent = errMsg;
            counterEl.title = errMsg;
            counterEl.style.color = '#ef4444';
        }
    }
}

function setBarProgress(key, pct) {
    const bar = document.getElementById('owiz-bar-' + key);
    if (bar) bar.style.width = Math.min(pct, 100) + '%';
}

function updateCounter(key, stats) {
    const el = document.getElementById('owiz-counter-' + key);
    if (!el) return;
    if (stats.extra) {
        el.textContent = stats.extra;
    } else if (stats.count > 0) {
        let txt = stats.count + ' kayıt çekildi';
        if (stats.matched > 0) txt += ', ' + stats.matched + ' eşleştirildi';
        el.textContent = txt;
    } else {
        el.textContent = '';
    }
}

function showSummary() {
    const { STEPS, stats } = window._owiz;
    const grid = document.getElementById('owiz-summary-grid');
    const summaryDiv = document.getElementById('owiz-summary');
    const doneBtn = document.getElementById('owiz-done-btn');

    let html = '';
    for (const s of STEPS) {
        const st = stats[s.key] || { count: 0, matched: 0, extra: '' };
        const isUnsupported = st.extra && st.extra.includes('desteklenmiyor');
        html += `<div class="owiz-summary-item${isUnsupported ? ' unsupported' : ''}">
            <div class="owiz-summary-icon"><i data-lucide="${s.icon}"></i></div>
            <div class="owiz-summary-label">${s.label}</div>
            <div class="owiz-summary-val">${isUnsupported ? '—' : st.count}</div>
            ${st.matched > 0 ? `<div class="owiz-summary-match">${st.matched} eşleşti</div>` : ''}
            ${isUnsupported ? `<div class="owiz-summary-match">Desteklenmiyor</div>` : ''}
        </div>`;
    }
    grid.innerHTML = html;
    summaryDiv.style.display = '';
    doneBtn.style.display = '';
    lucide.createIcons();
}
</script>

<style>
@keyframes spin{to{transform:rotate(360deg)}}

/* Wizard Overlay */
#owiz-overlay{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;animation:owizFadeIn .3s ease}
@keyframes owizFadeIn{from{opacity:0}to{opacity:1}}

.owiz-modal{background:var(--bg-card);border-radius:16px;width:95%;max-width:600px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 0 0 1px var(--border);animation:owizSlideUp .4s ease;position:relative}
@keyframes owizSlideUp{from{transform:translateY(40px);opacity:0}to{transform:translateY(0);opacity:1}}

/* Close */
.owiz-close{position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:10px;border:1px solid var(--border);background:var(--bg-card);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-muted);transition:all .2s;z-index:10;flex-shrink:0}
.owiz-close:hover{background:var(--bg-input);color:var(--text-primary);border-color:var(--border)}
.owiz-close i{width:18px;height:18px}

/* Header */
.owiz-header{text-align:center;padding:32px 32px 24px;border-bottom:1px solid var(--border-light);flex-shrink:0}
.owiz-header h2{font-size:22px;font-weight:700;margin:0 0 8px;color:var(--text-primary)}
.owiz-header p{font-size:14px;color:var(--text-muted);margin:0;line-height:1.5}

.owiz-mp-logo{width:auto;height:40px;object-fit:contain;margin:0 auto 14px;display:block}

/* Scroll Area */
.owiz-scroll-area{overflow-y:auto;flex:1;min-height:0}

/* Steps */
.owiz-steps{padding:16px 24px}

.owiz-step{display:flex;gap:14px;padding:14px 12px;border-radius:12px;transition:background .2s;margin-bottom:2px}
.owiz-step[data-status="running"]{background:rgba(16,185,129,.08)}
.owiz-step[data-status="done"]{opacity:.75}
.owiz-step[data-status="error"]{background:rgba(239,68,68,.08)}

.owiz-step-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--bg-input);border:1px solid var(--border);color:var(--text-muted)}
.owiz-step-icon i{width:18px;height:18px}
.owiz-step[data-status="running"] .owiz-step-icon{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3);color:#10b981}
.owiz-step[data-status="done"] .owiz-step-icon{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3)}
.owiz-step[data-status="error"] .owiz-step-icon{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3)}

.owiz-step-body{flex:1;min-width:0}
.owiz-step-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:2px}
.owiz-step-label{font-size:14px;font-weight:600;color:var(--text-primary)}
.owiz-step-desc{font-size:11px;color:var(--text-muted);margin-bottom:6px;line-height:1.4}

/* Step Button */
.owiz-step-actions{display:flex;align-items:center;gap:8px}
.owiz-days-box{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:var(--bg-input);border:1.5px solid var(--border);border-radius:10px;font-size:13px;color:var(--text-muted);font-weight:500;white-space:nowrap;transition:all .2s}
.owiz-days-box:focus-within{border-color:#10b981;background:rgba(16,185,129,.05);box-shadow:0 0 0 3px rgba(16,185,129,.1)}
.owiz-days-box i{width:15px;height:15px;color:var(--text-muted);flex-shrink:0}
.owiz-days-box:focus-within i{color:#10b981}
.owiz-days-input{width:52px;padding:4px 6px;border:none;border-radius:6px;font-size:14px;font-weight:700;color:var(--text-primary);text-align:center;background:var(--bg-card);font-family:inherit;box-shadow:inset 0 1px 2px rgba(0,0,0,.06);transition:all .2s}
.owiz-days-input:focus{outline:none;background:var(--bg-card)}
.owiz-step-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:8px;font-size:12px;font-weight:600;border:1.5px solid var(--border);background:var(--bg-card);color:var(--text-secondary);cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap}
.owiz-step-btn i{width:13px;height:13px}
.owiz-step-btn:hover:not(:disabled){border-color:#10b981;color:#10b981;background:rgba(16,185,129,.05)}
.owiz-step-btn:disabled{cursor:default;opacity:.8}
.owiz-step-btn.running{border-color:rgba(37,99,235,.3);background:rgba(37,99,235,.08);color:#60a5fa}
.owiz-step-btn.done{border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.08);color:#34d399}
.owiz-step-btn.error{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);color:#f87171}

.owiz-step-bar-wrap{height:4px;background:var(--bg-input);border-radius:2px;overflow:hidden;margin-top:4px}
.owiz-step-bar{height:100%;width:0;background:#10b981;border-radius:2px;transition:width .4s ease}

.owiz-step-counter{font-size:11px;color:var(--text-muted);margin-top:4px;min-height:16px;font-weight:500}

/* Spinner */
.owiz-spinner{width:18px;height:18px;border:2.5px solid rgba(16,185,129,.2);border-top-color:#10b981;border-radius:50%;animation:spin .8s linear infinite}

/* Summary */
.owiz-summary{padding:20px 24px;border-top:1px solid var(--border-light);animation:owizFadeIn .4s ease}
.owiz-summary-title{text-align:center;font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:16px;display:flex;align-items:center;justify-content:center;gap:8px}
.owiz-summary-title i{width:22px;height:22px;color:#f59e0b}
.owiz-summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.owiz-summary-item{text-align:center;padding:14px 8px;background:var(--bg-input);border-radius:12px;border:1px solid var(--border)}
.owiz-summary-item.unsupported{opacity:.5}
.owiz-summary-icon{margin-bottom:6px;color:var(--text-muted)}
.owiz-summary-icon i{width:20px;height:20px}
.owiz-summary-label{font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.owiz-summary-val{font-size:22px;font-weight:600;color:var(--text-primary);margin:4px 0 2px}
.owiz-summary-match{font-size:10px;color:#10b981;font-weight:600}

/* Footer */
.owiz-footer{padding:16px 24px 24px;display:flex;justify-content:center;gap:12px;flex-shrink:0;border-top:1px solid var(--border-light)}
.owiz-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 32px;border-radius:12px;font-size:15px;font-weight:600;border:none;cursor:pointer;font-family:inherit;transition:all .2s}
.owiz-btn:disabled{opacity:.6;cursor:default}
.owiz-btn i{width:18px;height:18px}
.owiz-btn-all{background:#10b981;color:#fff;box-shadow:0 0 0 1px var(--border)}
.owiz-btn-all:hover:not(:disabled){box-shadow:0 0 0 1px var(--border);transform:translateY(-1px)}
.owiz-btn-close{background:var(--bg-card);border:1.5px solid var(--border);color:var(--text-muted)}
.owiz-btn-close:hover{border-color:var(--border);color:var(--text-primary);background:var(--bg-input)}

/* Dark mode wizard enhancements */
[data-theme="dark"] .owiz-modal{box-shadow:0 0 0 1px var(--border)}
[data-theme="dark"] .owiz-days-input{box-shadow:inset 0 1px 2px rgba(0,0,0,.2)}

@media(max-width:640px){
    .owiz-modal{width:100%;max-width:100%;border-radius:12px 16px 0 0;max-height:95vh;margin-top:auto}
    .owiz-summary-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
