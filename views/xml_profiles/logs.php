<?php
$profile = $data['profile'] ?? [];
$logs    = $data['logs'] ?? [];

$statusBadge = static function (string $s): string {
    return match ($s) {
        'success' => '<span class="o-badge" style="background:#dcfce7;color:#166534">Başarılı</span>',
        'partial' => '<span class="o-badge" style="background:#fef9c3;color:#854d0e">Kısmi</span>',
        'failed'  => '<span class="o-badge" style="background:#fee2e2;color:#991b1b">Hatalı</span>',
        default   => '<span class="o-badge" style="background:#e0f2fe;color:#075985">Çalışıyor</span>',
    };
};
$actionLabel = static function (string $a): string {
    return match ($a) {
        'sync'          => 'Senkron',
        'stok_sifirla'  => 'Stok Sıfırlama',
        'urunleri_sil'  => 'Ürün Silme',
        'export'        => 'Dışa Aktarım',
        default         => $a,
    };
};
?>
<style>
.o-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.o-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:1px solid var(--border);cursor:pointer;font-family:inherit;text-decoration:none;background:transparent;color:var(--text-primary)}
.o-btn:hover{border-color:var(--primary);color:var(--primary)}
.o-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-card)}
.o-table{width:100%;border-collapse:collapse}
.o-table thead th{padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:var(--bg-input);border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
.o-table tbody tr{border-bottom:1px solid var(--border)}
.o-table tbody tr:hover{background:var(--bg-sidebar-hover)}
.o-table td{padding:11px 14px;font-size:13px;vertical-align:middle}
.o-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:16px;font-size:11px;font-weight:700;white-space:nowrap}
.o-empty{text-align:center;padding:60px 20px;color:var(--text-muted)}
.xpl-num{display:inline-block;min-width:34px;text-align:center;padding:2px 6px;border-radius:6px;font-size:11px;font-weight:700}
.xpl-err{cursor:pointer;color:#991b1b;text-decoration:underline dotted;font-size:11px}
.xpl-err-detail{display:none;background:#fef2f2;border-top:1px solid #fecaca;padding:10px 14px;font-size:11px;color:#7f1d1d;font-family:'JetBrains Mono',monospace;white-space:pre-wrap;word-break:break-all;max-height:240px;overflow:auto}
</style>

<div class="o-toolbar">
    <div>
        <div style="font-size:15px;font-weight:700;color:var(--text-primary)"><?= htmlspecialchars((string) ($profile['name'] ?? '')) ?> — Kayıtlar</div>
        <div style="font-size:12px;color:var(--text-muted)">Son 100 çalışma kaydı · <?= ($profile['direction'] ?? '') === 'import' ? 'İçe Aktar' : 'Dışa Aktar' ?> profili</div>
    </div>
    <div style="margin-left:auto">
        <a href="/xml-profilleri" class="o-btn"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> XML Profilleri</a>
    </div>
</div>

<div class="o-card">
    <table class="o-table">
        <thead>
        <tr>
            <th>#</th>
            <th>Başlangıç</th>
            <th>İşlem</th>
            <th>Durum</th>
            <th>Toplam</th>
            <th>Yeni</th>
            <th>Güncellenen</th>
            <th>Atlanan</th>
            <th>Hatalı</th>
            <th>Süre</th>
            <th>Tetikleyen</th>
            <th>Mesaj</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
            <tr><td colspan="12" class="o-empty"><i data-lucide="clipboard-list" style="width:32px;height:32px;color:var(--text-muted);display:block;margin:0 auto 8px"></i>Henüz kayıt yok — profili "Çalıştır" ile tetikleyin.</td></tr>
        <?php else: foreach ($logs as $log): $lid = (int) $log['id']; ?>
        <tr>
            <td><?= $lid ?></td>
            <td class="text-nowrap" style="font-size:12px"><?= !empty($log['started_at']) ? date('d-m-Y H:i:s', strtotime((string) $log['started_at'])) : '—' ?></td>
            <td><?= htmlspecialchars($actionLabel((string) $log['action'])) ?></td>
            <td><?= $statusBadge((string) $log['status']) ?></td>
            <td><span class="xpl-num" style="background:var(--bg-input);color:var(--text-primary)"><?= (int) $log['total_rows'] ?></span></td>
            <td><span class="xpl-num" style="background:#dcfce7;color:#166534"><?= (int) $log['created_rows'] ?></span></td>
            <td><span class="xpl-num" style="background:#e0f2fe;color:#075985"><?= (int) $log['updated_rows'] ?></span></td>
            <td><span class="xpl-num" style="background:#f1f5f9;color:#64748b"><?= (int) $log['skipped_rows'] ?></span></td>
            <td><span class="xpl-num" style="background:<?= (int) $log['failed_rows'] > 0 ? '#fee2e2;color:#991b1b' : '#f1f5f9;color:#64748b' ?>"><?= (int) $log['failed_rows'] ?></span></td>
            <td class="text-nowrap" style="font-size:12px;color:var(--text-muted)"><?= (int) $log['duration_ms'] > 0 ? number_format($log['duration_ms'] / 1000, 1, ',', '.') . ' sn' : '—' ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= $log['triggered_by'] === 'cron' ? 'Otomatik' : 'Manuel' ?></td>
            <td style="font-size:12px;max-width:320px">
                <?= htmlspecialchars((string) $log['message']) ?>
                <?php if (!empty($log['error_details'])): ?>
                    <br><span class="xpl-err" onclick="var d=document.getElementById('xplErr<?= $lid ?>');d.style.display=d.style.display==='block'?'none':'block'">hata detaylarını göster</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php if (!empty($log['error_details'])): ?>
        <tr><td colspan="12" style="padding:0">
            <div class="xpl-err-detail" id="xplErr<?= $lid ?>"><?php
                $errs = json_decode((string) $log['error_details'], true) ?: [];
                foreach ($errs as $e) {
                    echo htmlspecialchars(($e['sku'] !== '' ? '[' . $e['sku'] . '] ' : '') . ($e['error'] ?? '')) . "\n";
                }
            ?></div>
        </td></tr>
        <?php endif; ?>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
