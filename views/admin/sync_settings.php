<?php
/**
 * Platform senkron ayarları — settings group='sync' (platform anahtarı + varsayılan aralık) ve HTTP cron anahtarı.
 * Mağaza ayarları (store_settings) mağazanın kendi /sync ekranındadır; burada yalnız platform katmanı.
 */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rows      = $rows ?? [];
$intervals = $intervals ?? [];
$cronToken = (string) ($cronToken ?? '');
$baseUrl   = (string) ($baseUrl ?? '');
$activeStores = (int) ($activeStores ?? 0);
$intervalLabel = static function (int $m): string {
    if ($m >= 60 && $m % 60 === 0) { return ($m / 60) . ' saat'; }
    return $m . ' dk';
};
boss_page_header('Senkron Ayarları', [['l' => 'Operasyon'], ['l' => 'Senkron Ayarları']]);
?>
<style>
.syncadm-token{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.syncadm-token input{max-width:420px}
.syncadm-url{font-size:12px;word-break:break-all}
@media (prefers-reduced-motion: reduce){.syncadm-token *{transition:none!important}}
</style>

<p class="text-muted fs-2 mb-3">
  Otomatik senkron bir mağazada ancak <b>platform anahtarı</b> (bu sayfa) <b>ve</b> mağazanın kendi
  <b>Senkronizasyon</b> ekranındaki anahtarı birlikte açıksa çalışır. Burada kapatılan tür hiçbir mağazada çalışmaz;
  mağazalar o satırı "Platformda durduruldu" olarak görür. Aralık, mağaza kendi sıklığını seçmediyse kullanılır.
</p>

<form method="post" action="<?= url('/admin/sync-settings') ?>">
  <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-refresh me-1"></i>Senkron türleri (platform geneli)</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th scope="col">Tür</th>
            <th scope="col">Platformda açık</th>
            <th scope="col">Varsayılan sıklık</th>
            <th scope="col">Mağazada kapalı</th>
            <th scope="col">HTTP cron ucu</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $type = (string) $r['type'];
            $id = 'sync-' . $type;
        ?>
          <tr>
            <td>
              <div class="fw-bold"><?= $e($r['label']) ?></div>
              <div class="fs-1 text-muted" id="<?= $e($id) ?>-desc"><?= $e($r['note']) ?></div>
            </td>
            <td>
              <label class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="<?= $e($id) ?>-on"
                       name="s[sync_<?= $e($type) ?>_enabled]" value="1" <?= !empty($r['enabled']) ? 'checked' : '' ?>
                       aria-describedby="<?= $e($id) ?>-desc">
                <span class="form-check-label fs-3"><span class="visually-hidden"><?= $e($r['label']) ?> </span>Açık</span>
              </label>
            </td>
            <td>
              <label class="visually-hidden" for="<?= $e($id) ?>-interval"><?= $e($r['label']) ?> varsayılan sıklığı</label>
              <select class="form-select form-select-sm" id="<?= $e($id) ?>-interval" name="s[sync_<?= $e($type) ?>_interval]" style="max-width:140px">
                <?php foreach ($intervals as $m): ?>
                  <option value="<?= (int) $m ?>" <?= (int) $r['interval'] === (int) $m ? 'selected' : '' ?>><?= $e($intervalLabel((int) $m)) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <?php if ((int) $r['store_off'] > 0): ?>
                <span class="badge bg-warning-lt"><?= (int) $r['store_off'] ?> / <?= $activeStores ?> mağaza</span>
              <?php else: ?>
                <span class="text-muted fs-2">—</span>
              <?php endif; ?>
            </td>
            <td class="syncadm-url text-muted"><code><?= $e($r['cron_path']) ?></code></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Kaydet</button>
    </div>
  </div>
</form>

<div class="card mb-4">
  <div class="card-header"><h3 class="card-title"><i class="ti ti-key me-1"></i>HTTP cron anahtarı</h3></div>
  <div class="card-body">
    <p class="fs-2 text-muted mb-2">
      Sunucuda PHP komut satırı cron'u yoksa senkron uçları bu anahtarla çağrılır; her çağrı tüm etkin mağazaları
      kendi anahtar ve aralıklarıyla işler. Anahtar mağazalara gösterilmez — paylaşmayın.
    </p>
    <?php if ($cronToken === ''): ?>
      <div class="alert alert-warning py-2 px-3 fs-2" role="alert">
        Anahtar henüz oluşturulmadı: HTTP cron uçları şu an <b>403</b> döner. Aşağıdan bir anahtar oluşturun.
      </div>
    <?php else: ?>
      <div class="syncadm-token mb-2">
        <label class="form-label m-0 me-1" for="syncadmToken">Anahtar</label>
        <input type="password" class="form-control font-monospace" id="syncadmToken" value="<?= $e($cronToken) ?>" readonly autocomplete="off">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="syncadmShow" aria-controls="syncadmToken" aria-pressed="false">Göster</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="syncadmCopy">Kopyala</button>
      </div>
      <div class="fs-2 text-muted">Örnek çağrı (her 5 dakikada bir):</div>
      <div class="syncadm-url mb-3"><code><?= $e($baseUrl . '/cron/sync-orders?token=') ?>&lt;anahtar&gt;</code></div>
    <?php endif; ?>
    <form method="post" action="<?= url('/admin/sync-settings/cron-token') ?>"
          onsubmit="return confirm('<?= $cronToken === '' ? 'Cron anahtarı oluşturulsun mu?' : 'Yeni anahtar üretilince eski anahtarla yapılan cron çağrıları çalışmaz. Devam edilsin mi?' ?>')">
      <input type="hidden" name="_csrf_token" value="<?= $e($_csrf ?? '') ?>">
      <button type="submit" class="btn btn-outline-primary"><i class="ti ti-refresh me-1"></i><?= $cronToken === '' ? 'Anahtar oluştur' : 'Yeni anahtar üret' ?></button>
    </form>
  </div>
</div>

<?php if ($cronToken !== ''): ?>
<script>
(function () {
  var input = document.getElementById('syncadmToken');
  var show = document.getElementById('syncadmShow');
  var copy = document.getElementById('syncadmCopy');
  if (!input || !show || !copy) return;
  show.addEventListener('click', function () {
    var visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    show.textContent = visible ? 'Göster' : 'Gizle';
    show.setAttribute('aria-pressed', visible ? 'false' : 'true');
  });
  copy.addEventListener('click', function () {
    var done = function () { copy.textContent = 'Kopyalandı'; setTimeout(function () { copy.textContent = 'Kopyala'; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(done, function () {});
    }
  });
})();
</script>
<?php endif; ?>
