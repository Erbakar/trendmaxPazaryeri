<?php
$profiles = $data['profiles'] ?? [];
$rates    = $data['rates'] ?? [];
$appUrl   = rtrim((string) ($data['appUrl'] ?? ''), '/');
$csrf     = $data['_csrf'] ?? '';
// RBAC (AuthorizationService): profil oluşturma/çalıştırma/silme = ürün oluşturma VEYA düzenleme yetkisi
$can       = $data['_can'] ?? static fn(string $permission): bool => true;
$canManage = (bool) ($can('products.create') || $can('products.edit'));

$periodLabel = static function (int $h): string {
    if ($h <= 0) { return 'Hiçbir Zaman'; }
    if ($h >= 24 && $h % 24 === 0) { $g = intdiv($h, 24); return $g === 1 ? '24 Saatte Bir' : $g . ' Günde Bir'; }
    return $h . ' Saatte Bir';
};
?>
<style>
.o-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.o-actions{margin-left:auto;display:flex;gap:8px}
.o-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.o-btn-primary{background:var(--primary);color:#fff}.o-btn-primary:hover{opacity:.9}
.o-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.o-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.o-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card)}
.o-table{width:100%;border-collapse:collapse}
.o-table thead th{padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
.o-table tbody tr{border-bottom:1px solid var(--border)}
.o-table tbody tr:last-child{border-bottom:none}
.o-table tbody tr:hover{background:var(--bg-sidebar-hover)}
.o-table td{padding:12px 14px;font-size:13px;vertical-align:middle}
.o-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;white-space:nowrap}
.o-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.o-empty i{display:block;margin-bottom:8px}

/* Profil işlem butonları (ekran düzeni: Profili Sil / Stokları Sıfırla / Ürünleri Sil / Kayıtlar) */
.xp-actions{display:flex;gap:4px;flex-wrap:wrap}
.xp-act{display:inline-flex;align-items:center;gap:4px;padding:5px 9px;border-radius:7px;font-size:11px;font-weight:700;text-decoration:none;border:none;cursor:pointer;transition:opacity .12s;white-space:nowrap;font-family:inherit;color:#fff}
.xp-act:hover{opacity:.85}
.xp-act [data-lucide]{width:12px;height:12px}
.xp-del{background:#ef4444}
.xp-stok{background:#f59e0b}
.xp-urun{background:#ef4444}
.xp-kayit{background:transparent;border:1px solid var(--border);color:var(--text-secondary)}
.xp-kayit:hover{border-color:var(--primary);color:var(--primary);opacity:1}
.xp-run{background:#10b981}
.xp-edit{background:transparent;border:1px solid var(--border);color:var(--text-secondary)}
.xp-edit:hover{border-color:var(--primary);color:var(--primary);opacity:1}

/* Tür rozetleri */
.xp-tur-disa{background:#dcfce7;color:#166534}
.xp-tur-ice{background:#fee2e2;color:#991b1b}
.xp-durum-acik{background:#dcfce7;color:#166534}
.xp-durum-kapali{background:#f1f5f9;color:#64748b}

/* XML URL kutusu */
.xp-url{display:inline-flex;align-items:center;gap:6px;max-width:420px}
.xp-url-box{padding:4px 10px;background:#0ea5e9;color:#fff;border-radius:6px;font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:340px;cursor:pointer}
.xp-url-plain{font-size:12px;color:var(--text-secondary);word-break:break-all;max-width:380px;display:inline-block}
.xp-copy{padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:none;color:var(--text-muted);font-size:10px;cursor:pointer;white-space:nowrap}
.xp-copy:hover{border-color:var(--primary);color:var(--primary)}

/* TCMB kur çipleri */
.xp-rates{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.xp-rate{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:10px;background:var(--bg-card);border:1px solid var(--border);font-size:12px;font-weight:700;color:var(--text-primary)}
.xp-rate small{font-weight:500;color:var(--text-muted)}
/* Çalıştır geri bildirimi */
.xp-busy{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:12px 14px;border-radius:10px;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.35);color:var(--text-primary);font-size:13px;font-weight:600}
.xp-busy[hidden]{display:none}
.xp-busy svg,.xp-act.is-busy svg{animation:spin 1s linear infinite}
.xp-busy svg{width:16px;height:16px;flex:none}
.xp-act[disabled]{opacity:.55;cursor:not-allowed}
.xp-calisiyor{background:rgba(37,99,235,.12);color:#2563eb;margin-left:6px;font-weight:700}
.xp-next{margin-top:14px;padding:14px 16px;border-radius:10px;background:var(--bg-card);border:1px solid var(--border);font-size:13px;color:var(--text-secondary);line-height:1.6}
.xp-next strong{color:var(--text-primary)}
.xp-next-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
</style>
<?php $subnav = ['active' => '/xml-profilleri', 'items' => [
  ['u' => '/products', 'l' => 'Ürünler'],
  ['u' => '/categories', 'l' => 'Kategoriler'],
  ['u' => '/categories/mapping', 'l' => 'Kategori Eşleştirme'],
  ['u' => '/brands', 'l' => 'Markalar'],
  ['u' => '/feeds', 'l' => 'Feed'],
  ['u' => '/xml-profilleri', 'l' => 'XML Profilleri'],
]]; include BASE_PATH . '/views/layouts/_subnav.php'; ?>


<div class="xp-busy" id="xpBusy" role="status" aria-live="polite" hidden>
    <i data-lucide="loader"></i>
    <span id="xpBusyText"></span>
</div>

<div class="o-toolbar">
    <div class="xp-rates">
        <?php foreach ($rates as $r): $rv = (float) $r['rate']; ?>
        <span class="xp-rate" title="TCMB satış kuru<?= !empty($r['fetched_at']) ? ' · ' . date('d.m.Y H:i', strtotime($r['fetched_at'])) : '' ?>">
            <?= htmlspecialchars((string) $r['currency_code']) ?>: <?= $rv > 0 ? number_format($rv, 4, ',', '.') : '—' ?> ₺
            <?php if (!empty($r['fetched_at'])): ?><small><?= date('d.m H:i', strtotime((string) $r['fetched_at'])) ?></small><?php endif; ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php if ($canManage): ?>
    <div class="o-actions">
        <a href="/xml-profilleri/yeni" class="o-btn o-btn-primary"><i data-lucide="plus" style="width:15px;height:15px"></i> Yeni XML Profili</a>
    </div>
    <?php endif; ?>
</div>

<div class="o-card">
    <table class="o-table">
        <thead>
        <tr>
            <th>İşlemler</th>
            <th>#</th>
            <th>Durum</th>
            <th>Profil Adı</th>
            <th>XML Tür</th>
            <th>XML Tipi</th>
            <th>XML Periyodu</th>
            <th>Ürün</th>
            <th>Güncelleme/İndirme Tarihi</th>
            <th>XML URL</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($profiles)): ?>
            <tr><td colspan="10" class="o-empty">
                <i data-lucide="file-code" style="width:32px;height:32px;color:var(--text-muted)"></i>
                <p>Henüz XML profili yok.<?php if ($canManage): ?> <a href="/xml-profilleri/yeni">İlk profili oluşturun</a> — tedarikçi XML'inden ürünleri içe aktarın veya kendi XML çıktınızı paylaşın.<?php else: ?> Profil oluşturma yetkiniz yok; yöneticinizden isteyin.<?php endif; ?></p>
            </td></tr>
        <?php else: $i = 0; foreach ($profiles as $p): $i++;
            $pid = (int) $p['id'];
            $isImport = ($p['direction'] ?? 'import') === 'import';
            $exportUrl = !$isImport && !empty($p['token']) ? $appUrl . '/xml-islemleri/xml-ver-x?profil=' . $p['token'] : '';
        ?>
        <tr>
            <td>
                <div class="xp-actions">
                    <?php if ($canManage): ?>
                    <form method="POST" action="/xml-profilleri/<?= $pid ?>/sil" style="display:inline" onsubmit="return confirm('Bu profili silmek istediğinize emin misiniz? (Ürünler silinmez)')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="xp-act xp-del"><i data-lucide="trash-2"></i> Profili Sil</button>
                    </form>
                    <?php if ($isImport): ?>
                    <form method="POST" action="/xml-profilleri/<?= $pid ?>/stok-sifirla" style="display:inline" onsubmit="return confirm('Bu profilin TÜM ürünlerinin stoğu 0 yapılacak. Devam edilsin mi?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="xp-act xp-stok"><i data-lucide="rotate-ccw"></i> Stokları Sıfırla</button>
                    </form>
                    <form method="POST" action="/xml-profilleri/<?= $pid ?>/urunleri-sil" style="display:inline" onsubmit="return confirm('DİKKAT: Bu profille aktarılan TÜM ürünler kalıcı olarak silinecek! Devam edilsin mi?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="xp-act xp-urun"><i data-lucide="x-circle"></i> Ürünleri Sil</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                    <a href="/xml-profilleri/<?= $pid ?>/kayitlar" class="xp-act xp-kayit"><i data-lucide="list"></i> Kayıtlar</a>
                    <?php if ($canManage): ?>
                    <form method="POST" action="/xml-profilleri/<?= $pid ?>/calistir" style="display:inline" class="xp-run-form" data-import="<?= $isImport ? '1' : '0' ?>">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="xp-act xp-run" title="<?= $isImport ? 'Şimdi senkronize et' : 'XML çıktısını yeniden üret' ?>"><i data-lucide="refresh-cw"></i> Çalıştır</button>
                    </form>
                    <a href="/xml-profilleri/<?= $pid ?>/duzenle" class="xp-act xp-edit" title="Düzenle" aria-label="Düzenle"><i data-lucide="pencil"></i></a>
                    <?php endif; ?>
                </div>
            </td>
            <td><?= $i ?></td>
            <td>
                <?= (int) $p['is_active'] === 1
                    ? '<span class="o-badge xp-durum-acik">Açık</span>'
                    : '<span class="o-badge xp-durum-kapali">Kapalı</span>' ?>
            </td>
            <td style="font-weight:700"><?= htmlspecialchars((string) $p['name']) ?>
                <?php if (!empty($p['running_since'])): ?>
                    <span class="o-badge xp-calisiyor" title="Sayfayı yenileyerek durumu izleyebilirsiniz">Çalışıyor · <?= date('H:i', strtotime((string) $p['running_since'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($p['last_run_status']) && $p['last_run_status'] !== 'success'): ?>
                    <i data-lucide="alert-triangle" style="width:13px;height:13px;color:#f59e0b;vertical-align:-2px" title="<?= htmlspecialchars((string) $p['last_run_summary']) ?>"></i>
                <?php endif; ?>
            </td>
            <td>
                <?= $isImport
                    ? '<span class="o-badge xp-tur-ice">İçe Aktar</span>'
                    : '<span class="o-badge xp-tur-disa">Dışa Aktar</span>' ?>
            </td>
            <td><?= ($p['source_type'] ?? 'url') === 'file' ? 'Dosya' : 'URL' ?></td>
            <td><?= htmlspecialchars($periodLabel((int) ($p['period_hours'] ?? 0))) ?></td>
            <td style="text-align:center;font-weight:700"><?= (int) ($p['product_count'] ?? 0) ?></td>
            <td class="text-nowrap" style="font-size:12px;color:var(--text-secondary)">
                <?= !empty($p['last_run_at']) ? date('d-m-Y H:i:s', strtotime((string) $p['last_run_at'])) : '—' ?>
            </td>
            <td>
                <?php if ($exportUrl !== ''): ?>
                    <span class="xp-url">
                        <span class="xp-url-box" onclick="window.open('<?= htmlspecialchars($exportUrl) ?>','_blank')" title="<?= htmlspecialchars($exportUrl) ?>"><?= htmlspecialchars($exportUrl) ?></span>
                        <button type="button" class="xp-copy" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($exportUrl) ?>').then(()=>{if(window.Toast)Toast.show('URL kopyalandı','success',1500)})">Kopyala</button>
                    </span>
                <?php elseif (($p['source_type'] ?? 'url') === 'url'): ?>
                    <span class="xp-url-plain" title="<?= htmlspecialchars((string) $p['source_url']) ?>"><?= htmlspecialchars((string) $p['source_url']) ?></span>
                <?php else: ?>
                    <span class="xp-url-plain"><?= htmlspecialchars(basename((string) ($p['source_url'] ?? ''))) ?: '—' ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:12px;font-size:12px;color:var(--text-muted);display:flex;gap:16px;flex-wrap:wrap">
    <span><i data-lucide="info" style="width:13px;height:13px;vertical-align:-2px"></i> Döviz (USD/EUR/GBP) bazlı ürünlerin TL fiyatları TCMB satış kuruyla otomatik hesaplanır ve kur cron'u ile güncel tutulur.</span>
    <span><i data-lucide="clock" style="width:13px;height:13px;vertical-align:-2px"></i> Periyotlu profiller sunucu cron'u ile otomatik senkronize edilir (<code>cron/sync_xml_profiles.php</code>).</span>
</div>

<?php
$hasImport = false;
foreach ($profiles as $pp) { if (($pp['direction'] ?? 'import') === 'import') { $hasImport = true; break; } }
if ($hasImport): ?>
<div class="xp-next">
    <strong>Sonraki adım — ürünleri pazaryerine gönderme.</strong>
    "Çalıştır" yalnızca tedarikçi XML'indeki ürünleri bu panele alır; Trendyol'a kendiliğinden göndermez.
    Göndermek için kategoriler ve zorunlu Trendyol özellikleri eşleşmiş olmalıdır.
    Ürünler sayfasındaki "Pazaryerinden Çek" butonu ters yönde çalışır: pazaryerindeki ilanları panele getirir.
    <div class="xp-next-links">
        <a href="/products/catalog-audit" class="o-btn o-btn-primary o-btn-sm">Katalog Kontrolü (otomatik gönderim)</a>
        <a href="/categories/mapping" class="o-btn o-btn-outline o-btn-sm">Kategori Eşleştirme</a>
        <a href="/products" class="o-btn o-btn-outline o-btn-sm">Ürünler › seç › Pazaryerine Gönder</a>
    </div>
</div>
<?php endif; ?>

<script>
// "Çalıştır" dakikalarca süren tek bir istektir; basıldığı an görünür geri bildirim ver
// ve ikinci kez basılmasını engelle.
document.querySelectorAll('.xp-run-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (form.dataset.sent === '1') { e.preventDefault(); return; }
        form.dataset.sent = '1';
        var btn = form.querySelector('button[type=submit]');
        document.querySelectorAll('.xp-run-form button[type=submit]').forEach(function (b) {
            // disabled butonun formu yine gönderilir; yalnız tekrar tıklamayı keser
            setTimeout(function () { b.disabled = true; }, 0);
        });
        if (btn) {
            btn.classList.add('is-busy');
            btn.lastChild.textContent = ' Çalışıyor…';
        }
        var busy = document.getElementById('xpBusy');
        if (busy) {
            document.getElementById('xpBusyText').textContent = form.dataset.import === '1'
                ? 'Senkron başladı. Ürün sayısına göre birkaç dakika sürebilir; bitince sonuç bu sayfada görünür. Lütfen sayfayı kapatmayın.'
                : 'XML çıktısı yeniden üretiliyor…';
            busy.hidden = false;
        }
    });
});
// Geri tuşuyla (bfcache) dönülürse butonlar kilitli kalmasın
window.addEventListener('pageshow', function (e) { if (e.persisted) { location.reload(); } });
</script>
