<?php
// OzyConnect Panel — Cron: TCMB Döviz Kuru Senkronu
// 1. TCMB'den güncel USD/EUR/GBP satış kurlarını çeker (exchange_rates tablosu — global)
// 2. Her mağazada döviz-cinsli ürünlerin (currency_code != TRY) TL fiyatlarını
//    güncel kurla yeniden hesaplar (base/list/cost price)
// Kullanım: php cron/sync_exchange_rates.php
// Crontab : Saatte bir → 30 * * * * php cron/sync_exchange_rates.php
//           (TCMB kurları iş günü ~15:30'da güncellenir; saatlik koşum yeterlidir)
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\ExchangeRateService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) { echo "[ERROR] Container yüklenemedi\n"; exit(1); }

$rateService = $container->get(ExchangeRateService::class);
$cronRunner  = $container->get(\App\Services\TenantCronRunner::class);
$logger      = $container->get(\App\Core\Logger::class);

echo "[" . date('Y-m-d H:i:s') . "] TCMB kur senkronu başlıyor...\n";

// 1. Kurları çek (global — tek sefer)
$fetch = $rateService->fetchTcmb();
if (!$fetch['success']) {
    echo "  [UYARI] {$fetch['message']} — mevcut kurlarla devam ediliyor.\n";
    $logger->warning('TCMB kur çekimi başarısız: ' . $fetch['message'], 'Cron');
} else {
    foreach ($fetch['rates'] as $code => $rate) {
        echo "  {$code}: " . number_format($rate, 4, ',', '.') . " ₺\n";
    }
}

// 2. Her mağazada döviz-cinsli ürün fiyatlarını yeniden hesapla
$storeResults = $cronRunner->runForActiveStores(static function (int $storeId) use ($rateService): array {
    return ['updated' => $rateService->recalcProductPrices()];
});

$totalUpdated = 0;
foreach ($storeResults as $storeId => $sr) {
    if (!($sr['ok'] ?? false)) {
        echo "  store#{$storeId}: [HATA] " . ($sr['error'] ?? '?') . "\n";
        continue;
    }
    $n = (int) ($sr['result']['updated'] ?? 0);
    $totalUpdated += $n;
    if ($n > 0) {
        echo "  store#{$storeId}: {$n} ürünün TL fiyatı güncellendi\n";
    }
}
echo "[OK] Kur senkronu tamamlandı — toplam {$totalUpdated} ürün fiyatı yenilendi.\n";
