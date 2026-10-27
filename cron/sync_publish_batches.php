<?php
// PazarConnect — Cron: Pazaryeri gönderim sonuçlarını (batch) çöz
// Kullanım: php cron/sync_publish_batches.php
// Crontab: Her 10 dakikada bir.
//
// NEDEN VAR: Ürün gönderiminde pazaryeri (özellikle Trendyol) toplu isteği hemen
// işlemiyor; sonuç birkaç dakika sürüyor. Gönderim anında yalnız ~4,5 saniye
// bekleniyordu, bu yüzden ürünler "Batch kontrol bekliyor" durumunda asılı kalıyor
// ve REDDETME SEBEBİ hiç öğrenilmiyordu. Panel "gönderildi" derken pazaryerinde
// ürün açılmamış oluyordu. Bu iş, bekleyen gönderimlerin gerçek sonucunu sorar:
// onaylananı "yayında" işaretler, reddedileni sebebiyle birlikte kaydeder.
//
// JobRunner'ın tipli senkron ayarlarına BAĞLANMAZ (yeni ayar/sütun gerektirmesin
// diye); doğrudan aktif mağazaları dolaşır ve her mağazayı izole çalıştırır.
declare(strict_types=1);

// Prevent overlapping minute jobs from multiplying expired batch queries.
$processLock=fopen(sys_get_temp_dir().'/trendmax-publish-batches-'.sha1(dirname(__DIR__)).'.lock','c');
if (!$processLock || !flock($processLock,LOCK_EX|LOCK_NB)) exit(0);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\TenantCronRunner;
use App\Services\ProductPublishService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$publish = $container->get(ProductPublishService::class);
$runner  = $container->get(TenantCronRunner::class);

echo '[' . date('Y-m-d H:i:s') . "] Gönderim sonuçları kontrol ediliyor...\n";

$sonuclar = $runner->runForActiveStores(
    static function (int $storeId) use ($publish): array {
        $r = $publish->resolvePendingBatches(0, 500);
        if ($r['checked'] > 0 || $r['errors'] !== []) {
            echo sprintf(
                "  mağaza #%d — sorulan: %d · onaylandı: %d · reddedildi: %d · hâlâ işleniyor: %d · süresi dolmuş: %d\n",
                $storeId,
                $r['checked'],
                $r['approved'],
                $r['rejected'],
                $r['pending'],
                $r['expired'] ?? 0
            );
            foreach (array_slice($r['errors'], 0, 5) as $hata) {
                echo '  ! ' . $hata . "\n";
            }
        }
        return $r;
    }
);

$hataliMagaza = 0;
$toplam = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'expired' => 0];
foreach ($sonuclar as $storeId => $s) {
    if (!($s['ok'] ?? false)) {
        $hataliMagaza++;
        echo "  [HATA] mağaza #{$storeId}: " . ($s['error'] ?? 'bilinmeyen') . "\n";
        continue;
    }
    foreach (array_keys($toplam) as $k) {
        $toplam[$k] += (int) ($s['result'][$k] ?? 0);
    }
}

echo sprintf(
    "[%s] Bitti — sorulan: %d · onaylandı: %d · reddedildi: %d · işleniyor: %d · süresi dolmuş: %d · hatalı mağaza: %d\n",
    date('Y-m-d H:i:s'),
    $toplam['checked'],
    $toplam['approved'],
    $toplam['rejected'],
    $toplam['pending'],
    $toplam['expired'],
    $hataliMagaza
);

exit($hataliMagaza > 0 ? 1 : 0);
