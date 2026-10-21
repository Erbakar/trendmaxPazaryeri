<?php
// Per-store: değerler store_settings'ten düz key=>value haritası olarak gelir
$values = $data['values'] ?? [];
$csrf = $data['_csrf'] ?? '';
$v = static fn(string $k, string $d = ''): string => (string) ($values[$k] ?? $d);

$sections = [
    'GİB e-Arşiv Bağlantısı' => [
        'icon' => 'plug', 'keys' => ['gib_test_mode','gib_username','gib_password'],
    ],
    'Fatura Bilgileri' => [
        'icon' => 'file-text', 'keys' => ['invoice_series','default_tax_rate','invoice_auto_create','auto_return_invoice'],
    ],
    'Satıcı Bilgileri' => [
        'icon' => 'building', 'keys' => ['seller_company_name','seller_tax_no','seller_tax_office','seller_mersis_no','seller_address','seller_city','seller_district','seller_phone','seller_email'],
    ],
];

// Sabit alan tanımı (key => [etiket, tip]); getByGroup(label/type/value) bağımlılığını kaldırır
$fields = [
    'gib_test_mode'       => ['GİB Test Modu', 'bool'],
    'gib_username'        => ['GİB Kullanıcı Adı', 'text'],
    'gib_password'        => ['GİB Şifre', 'password'],
    'invoice_series'      => ['Fatura Serisi', 'text'],
    'default_tax_rate'    => ['Varsayılan KDV (%)', 'text'],
    'invoice_auto_create' => ['Otomatik Fatura Oluştur', 'bool'],
    'auto_return_invoice' => ['Otomatik İade Faturası', 'bool'],
    'seller_company_name' => ['Firma Ünvanı', 'text'],
    'seller_tax_no'       => ['Vergi No', 'text'],
    'seller_tax_office'   => ['Vergi Dairesi', 'text'],
    'seller_mersis_no'    => ['MERSİS No', 'text'],
    'seller_address'      => ['Adres', 'text'],
    'seller_city'         => ['İl', 'text'],
    'seller_district'     => ['İlçe', 'text'],
    'seller_phone'        => ['Telefon', 'text'],
    'seller_email'        => ['E-posta', 'text'],
];

$fieldIcons = [
    'gib_test_mode'=>'toggle-left','gib_username'=>'user','gib_password'=>'lock',
    'invoice_series'=>'hash','default_tax_rate'=>'percent','invoice_auto_create'=>'zap','auto_return_invoice'=>'undo-2',
    'seller_company_name'=>'building','seller_tax_no'=>'credit-card','seller_tax_office'=>'landmark',
    'seller_mersis_no'=>'file-digit','seller_address'=>'map-pin','seller_city'=>'map',
    'seller_district'=>'navigation','seller_phone'=>'phone','seller_email'=>'mail',
];
?>
<style>
.is-back{display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;font-size:13px;font-weight:500;margin-bottom:20px;transition:color .15s}
.is-back:hover{color:var(--primary)}
.is-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card);margin-bottom:20px}
.is-card-h{padding:16px 24px;border-bottom:1px solid var(--border);background:var(--bg-input);display:flex;align-items:center;justify-content:space-between}
.is-card-h h3{margin:0;font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.is-card-b{padding:24px}
.is-field{display:grid;grid-template-columns:200px 1fr;gap:16px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}
.is-field:last-child{border-bottom:none}
.is-label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:var(--text-secondary)}
.is-label i[data-lucide]{width:16px;height:16px;color:var(--text-muted)}
.is-input{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;background:var(--bg-input);color:var(--text-primary);font-family:inherit;transition:border-color .15s}
.is-input:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgb(var(--brand) / .1)}
.is-toggle{position:relative;width:44px;height:24px;cursor:pointer}
.is-toggle input{opacity:0;width:0;height:0}
.is-toggle-track{position:absolute;inset:0;background:var(--border);border-radius:12px;transition:background .2s}
.is-toggle-thumb{position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 0 0 1px var(--border)}
.is-toggle input:checked+.is-toggle-track{background:var(--primary)}
.is-toggle input:checked+.is-toggle-track+.is-toggle-thumb{transform:translateX(20px)}
.is-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg-input)}
.is-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border-radius:10px;font-size:14px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit}
.is-btn-primary{background:var(--primary);color:#fff;box-shadow:0 0 0 1px var(--border)}.is-btn-primary:hover{opacity:.9}
.is-btn-success{background:#10b981;color:#fff}.is-btn-success:hover{background:#059669}
.is-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-primary)}.is-btn-outline:hover{border-color:var(--primary);color:var(--primary)}
@media(max-width:768px){.is-field{grid-template-columns:1fr;gap:6px}}
</style>

<a href="/invoices" class="is-back"><i data-lucide="arrow-left" style="width:16px;height:16px"></i> Faturalar</a>

<form method="POST" action="/invoices/settings">
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">

<?php foreach ($sections as $title => $section): ?>
<div class="is-card">
    <div class="is-card-h">
        <h3><i data-lucide="<?= $section['icon'] ?>" style="width:18px;height:18px"></i> <?= $title ?></h3>
        <?php if ($title === 'GİB e-Arşiv Bağlantısı'): ?>
        <button type="button" class="is-btn is-btn-success" style="padding:6px 14px;font-size:12px" onclick="testGib(this)">
            <i data-lucide="plug" style="width:14px;height:14px"></i> Bağlantı Test Et
        </button>
        <?php endif; ?>
    </div>
    <div class="is-card-b">
        <?php foreach ($section['keys'] as $key):
            $f = $fields[$key] ?? [$key, 'text'];
            [$label, $type] = $f;
            $icon = $fieldIcons[$key] ?? 'settings';
            $isPw = $type === 'password';
            $val = $v($key);
        ?>
        <div class="is-field">
            <label class="is-label"><i data-lucide="<?= $icon ?>"></i> <?= htmlspecialchars($label) ?></label>
            <?php if ($type === 'bool'): ?>
            <label class="is-toggle">
                <input type="hidden" name="settings[<?= htmlspecialchars($key) ?>]" value="0">
                <input type="checkbox" name="settings[<?= htmlspecialchars($key) ?>]" value="1" <?= ($val === '1' || $val === 'on' || $val === 'true') ? 'checked' : '' ?>>
                <span class="is-toggle-track"></span>
                <span class="is-toggle-thumb"></span>
            </label>
            <?php else: ?>
            <input type="<?= $isPw ? 'password' : 'text' ?>" name="settings[<?= htmlspecialchars($key) ?>]" class="is-input" value="<?= htmlspecialchars($val) ?>" <?= $isPw ? 'autocomplete="off"' : '' ?>>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="is-card">
    <div class="is-footer" style="border-top:none">
        <a href="/invoices" class="is-btn is-btn-outline"><i data-lucide="arrow-left" style="width:16px;height:16px"></i> Geri</a>
        <button type="submit" class="is-btn is-btn-primary"><i data-lucide="save" style="width:16px;height:16px"></i> Kaydet</button>
    </div>
</div>
</form>

<script>
function testGib(btn) {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="loader" style="width:14px;height:14px;animation:spin 1s linear infinite"></i> Test ediliyor...';
    btn.disabled = true;
    lucide.createIcons({nodes: [btn]});

    const fd = new FormData();
    fd.append('_csrf_token', '<?= htmlspecialchars($csrf) ?>');

    fetch('/invoices/test-gib', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        Toast.show(d.success ? 'GİB bağlantısı başarılı!' : 'GİB hatası: ' + (d.message || ''), d.success ? 'success' : 'error', d.success ? 4000 : 8000);
    })
    .catch(() => Toast.show('Bağlantı hatası', 'error'))
    .finally(() => { btn.innerHTML = orig; btn.disabled = false; lucide.createIcons({nodes: [btn]}); });
}
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
