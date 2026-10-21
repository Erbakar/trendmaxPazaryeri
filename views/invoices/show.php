<?php
$invoice = $data['invoice'] ?? [];
$invoiceHtml = $data['invoiceHtml'] ?? null;
$csrf = $data['_csrf'] ?? '';
$can = $data['_can'] ?? static fn(string $p): bool => true;
$canCancel = $can('invoices.cancel');

$invNo = htmlspecialchars(($invoice['series'] ?? '') . ($invoice['sequence_no'] ?? ''));
$stMap = [
    'draft'     => ['l' => 'Taslak', 'c' => '#f59e0b', 'bg' => '#fffbeb'],
    'sent'      => ['l' => 'Gönderildi', 'c' => '#10b981', 'bg' => '#f0fdf4'],
    'approved'  => ['l' => 'Onaylandı', 'c' => 'var(--primary)', 'bg' => '#eef2ff'],
    'cancelled' => ['l' => 'İptal', 'c' => '#ef4444', 'bg' => '#fef2f2'],
];
$st = $invoice['status'] ?? 'draft';
$sc = $stMap[$st] ?? $stMap['draft'];
?>
<style>
.fsd-back{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;color:var(--text-secondary);border:1px solid var(--border);background:var(--bg-card);transition:all .15s}
.fsd-back:hover{border-color:var(--primary);color:var(--primary)}
.fsd-grid{display:grid;grid-template-columns:340px 1fr;gap:20px;margin-top:16px}
.fsd-sidebar{display:flex;flex-direction:column;gap:16px}
.fsd-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card)}
.fsd-card-h{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.fsd-card-h h3{margin:0;font-size:14px;font-weight:700;color:var(--text-primary)}
.fsd-card-body{padding:18px}
.fsd-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)}
.fsd-row:last-child{border-bottom:none}
.fsd-label{font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px}
.fsd-value{font-size:13px;font-weight:600;color:var(--text-primary);text-align:right}
.fsd-value-lg{font-size:18px;font-weight:700;color:var(--primary)}
.fsd-uuid{font-family:'Fira Code',Consolas,monospace;font-size:11px;color:var(--text-muted);word-break:break-all;margin-top:4px}
.fsd-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700}
.fsd-actions{display:flex;gap:8px;flex-wrap:wrap}
.fsd-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .15s;text-decoration:none;font-family:inherit}
.fsd-btn-primary{background:var(--primary);color:#fff}.fsd-btn-primary:hover{opacity:.9}
.fsd-btn-cyan{background:#06b6d4;color:#fff}.fsd-btn-cyan:hover{background:#0891b2}
.fsd-btn-danger{background:#ef4444;color:#fff}.fsd-btn-danger:hover{background:#dc2626}
.fsd-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-secondary)}.fsd-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
.fsd-preview{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card);display:flex;flex-direction:column}
.fsd-preview-h{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.fsd-preview-h h3{margin:0;font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.fsd-iframe{width:100%;height:calc(100vh - 220px);border:none;background:#fff}
.fsd-no-preview{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;color:var(--text-muted);text-align:center;min-height:400px}
@media(max-width:900px){.fsd-grid{grid-template-columns:1fr}}
</style>

<a href="/invoices" class="fsd-back"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Faturalara Dön</a>

<div class="fsd-grid">
    <!-- Sol: Detay -->
    <div class="fsd-sidebar">
        <div class="fsd-card">
            <div class="fsd-card-h">
                <i data-lucide="file-text" style="width:16px;height:16px;color:var(--primary)"></i>
                <h3>Fatura #<?= $invNo ?></h3>
            </div>
            <div class="fsd-card-body">
                <div class="fsd-row">
                    <span class="fsd-label">Durum</span>
                    <span class="fsd-badge" style="color:<?= $sc['c'] ?>;background:<?= $sc['bg'] ?>"><?= $sc['l'] ?></span>
                </div>
                <div class="fsd-row">
                    <span class="fsd-label">Tür</span>
                    <span class="fsd-value"><span class="fsd-badge" style="color:<?= ($invoice['type'] ?? '') === 'e-fatura' ? 'var(--primary)' : '#06b6d4' ?>;background:<?= ($invoice['type'] ?? '') === 'e-fatura' ? '#eef2ff' : '#ecfeff' ?>"><?= htmlspecialchars($invoice['type'] ?? 'e-arsiv') ?></span></span>
                </div>
                <div class="fsd-row">
                    <span class="fsd-label">Net Tutar</span>
                    <span class="fsd-value"><?= number_format((float) ($invoice['net_total'] ?? $invoice['subtotal'] ?? 0), 2, ',', '.') ?> ₺</span>
                </div>
                <div class="fsd-row">
                    <span class="fsd-label">KDV</span>
                    <span class="fsd-value"><?= number_format((float) ($invoice['tax_total'] ?? 0), 2, ',', '.') ?> ₺</span>
                </div>
                <div class="fsd-row">
                    <span class="fsd-label">Toplam</span>
                    <span class="fsd-value fsd-value-lg"><?= number_format((float) ($invoice['total'] ?? 0), 2, ',', '.') ?> ₺</span>
                </div>
                <div class="fsd-row">
                    <span class="fsd-label">Tarih</span>
                    <span class="fsd-value"><?= date('d.m.Y H:i', strtotime($invoice['issued_at'] ?? 'now')) ?></span>
                </div>
                <div style="margin-top:8px">
                    <div class="fsd-label" style="margin-bottom:4px">UUID</div>
                    <div class="fsd-uuid"><?= htmlspecialchars($invoice['invoice_uuid'] ?? $invoice['uuid'] ?? '-') ?></div>
                </div>
            </div>
        </div>

        <div class="fsd-card">
            <div class="fsd-card-body">
                <div class="fsd-actions">
                    <?php if ($st === 'draft'): ?>
                    <button type="button" class="fsd-btn" style="background:#10b981;color:#fff" onclick="fsdSmsStart()" id="fsd-sms-btn"><i data-lucide="smartphone" style="width:14px;height:14px"></i> SMS ile Onayla</button>
                    <?php endif; ?>
                    <a href="/invoices/<?= $invoice['id'] ?>/html" target="_blank" class="fsd-btn fsd-btn-cyan"><i data-lucide="eye" style="width:14px;height:14px"></i> Görüntüle</a>
                    <a href="/invoices/<?= $invoice['id'] ?>/download" class="fsd-btn fsd-btn-primary"><i data-lucide="download" style="width:14px;height:14px"></i> İndir</a>
                    <?php if ($st !== 'cancelled' && $canCancel): ?>
                    <form method="POST" action="/invoices/<?= $invoice['id'] ?>/cancel" style="margin:0" onsubmit="return confirm('Bu faturayı iptal etmek istediğinize emin misiniz?')">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="fsd-btn fsd-btn-danger"><i data-lucide="x-circle" style="width:14px;height:14px"></i> İptal Et</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if ($st === 'draft'): ?>
                <div style="margin-top:10px;padding:10px 12px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;font-size:12px;color:#92400e;line-height:1.5">
                    Bu fatura GİB'de <b>taslak</b> durumda — resmileşmesi için onaylanması (imzalanması) gerekir.
                    "SMS ile Onayla" ile GİB'de kayıtlı telefona gelen kodu girerek panelden imzalayabilirsiniz.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sağ: Fatura Önizleme -->
    <div class="fsd-preview">
        <div class="fsd-preview-h">
            <h3><i data-lucide="file-text" style="width:16px;height:16px"></i> Fatura Önizleme</h3>
            <a href="/invoices/<?= $invoice['id'] ?>/html" target="_blank" class="fsd-btn fsd-btn-outline" style="padding:6px 12px;font-size:12px"><i data-lucide="external-link" style="width:12px;height:12px"></i> Yeni Sekmede Aç</a>
        </div>
        <?php if ($invoiceHtml): ?>
        <!-- sandbox: GİB'den gelen HTML panel origin'inde script çalıştıramasın -->
        <iframe class="fsd-iframe" sandbox="" srcdoc="<?= htmlspecialchars($invoiceHtml) ?>"></iframe>
        <?php else: ?>
        <div class="fsd-no-preview">
            <i data-lucide="file-x" style="width:48px;height:48px;margin-bottom:12px;opacity:.5"></i>
            <div style="font-size:15px;font-weight:600;margin-bottom:4px">Fatura önizlemesi mevcut değil</div>
            <div style="font-size:13px">GİB'den fatura HTML belgesi henüz alınamamış</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($st === 'draft'): ?>
<!-- SMS Onay Modalı -->
<div id="fsd-sms-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center">
    <div style="background:var(--bg-card);border-radius:16px;padding:26px;width:380px;max-width:92vw">
        <h3 style="margin:0 0 6px;font-size:16px;font-weight:700;color:var(--text-primary)">
            <i data-lucide="smartphone" style="width:16px;height:16px;vertical-align:-2px"></i> SMS ile Fatura Onayı
        </h3>
        <p id="fsd-sms-info" style="margin:0 0 14px;font-size:13px;color:var(--text-secondary);line-height:1.5">Kod gönderiliyor…</p>
        <div id="fsd-sms-code-area" style="display:none">
            <input type="text" id="fsd-sms-code" inputmode="numeric" maxlength="8" placeholder="SMS kodu"
                   style="width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:10px;font-size:18px;letter-spacing:4px;text-align:center;font-weight:700;background:var(--bg-body);color:var(--text-primary)">
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">Kodun geçerlilik süresi ~3 dakikadır.</div>
        </div>
        <div id="fsd-sms-msg" style="display:none;margin-top:12px;padding:10px 12px;border-radius:10px;font-size:12px;line-height:1.5"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
            <button type="button" class="fsd-btn fsd-btn-outline" onclick="fsdSmsClose()">Vazgeç</button>
            <button type="button" class="fsd-btn fsd-btn-primary" id="fsd-sms-verify-btn" style="display:none" onclick="fsdSmsVerify()">Onayla ve İmzala</button>
        </div>
    </div>
</div>
<script>
var fsdInvId = <?= (int) ($invoice['id'] ?? 0) ?>;
var fsdCsrf = '<?= htmlspecialchars($csrf) ?>';

function fsdSmsMsg(text, ok) {
    var el = document.getElementById('fsd-sms-msg');
    el.style.display = 'block';
    el.style.background = ok ? '#f0fdf4' : '#fef2f2';
    el.style.color = ok ? '#166534' : '#991b1b';
    el.textContent = text;
}

function fsdSmsClose() {
    document.getElementById('fsd-sms-modal').style.display = 'none';
}

function fsdSmsStart() {
    var modal = document.getElementById('fsd-sms-modal');
    modal.style.display = 'flex';
    document.getElementById('fsd-sms-info').textContent = 'GİB\'de kayıtlı telefona kod gönderiliyor…';
    document.getElementById('fsd-sms-code-area').style.display = 'none';
    document.getElementById('fsd-sms-verify-btn').style.display = 'none';
    document.getElementById('fsd-sms-msg').style.display = 'none';

    fetch('/invoices/' + fsdInvId + '/sign-sms-start', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: '_csrf_token=' + encodeURIComponent(fsdCsrf)
    }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.success) {
            document.getElementById('fsd-sms-info').textContent = j.message || 'Kod gönderildi. Gelen kodu girin.';
            document.getElementById('fsd-sms-code-area').style.display = 'block';
            document.getElementById('fsd-sms-verify-btn').style.display = 'inline-flex';
            document.getElementById('fsd-sms-code').value = '';
            document.getElementById('fsd-sms-code').focus();
        } else {
            document.getElementById('fsd-sms-info').textContent = '';
            fsdSmsMsg(j.message || 'SMS gönderilemedi', false);
        }
    }).catch(function () { fsdSmsMsg('Bağlantı hatası — tekrar deneyin', false); });
}

function fsdSmsVerify() {
    var code = document.getElementById('fsd-sms-code').value.trim();
    if (!code) { fsdSmsMsg('SMS kodunu girin', false); return; }
    var btn = document.getElementById('fsd-sms-verify-btn');
    btn.disabled = true;

    fetch('/invoices/' + fsdInvId + '/sign-sms-verify', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: '_csrf_token=' + encodeURIComponent(fsdCsrf) + '&code=' + encodeURIComponent(code)
    }).then(function (r) { return r.json(); }).then(function (j) {
        btn.disabled = false;
        fsdSmsMsg(j.message || (j.success ? 'İmzalandı' : 'Doğrulanamadı'), !!j.success);
        if (j.success) {
            setTimeout(function () { window.location.reload(); }, 1500);
        }
    }).catch(function () { btn.disabled = false; fsdSmsMsg('Bağlantı hatası — tekrar deneyin', false); });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && document.getElementById('fsd-sms-modal').style.display === 'flex') {
        var vb = document.getElementById('fsd-sms-verify-btn');
        if (vb.style.display !== 'none' && !vb.disabled) { fsdSmsVerify(); }
    }
});
</script>
<?php endif; ?>
