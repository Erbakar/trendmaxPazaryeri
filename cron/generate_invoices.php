<?php
/**
 * OzyConnect Panel — Cron: Otomatik Fatura Üretimi
 * Kullanım: php cron/generate_invoices.php
 * Crontab: 0 * * * *
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Models\Order;
use App\Models\Setting;
use App\Services\Invoice\InvoiceService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) { echo "[ERROR] Container yüklenemedi\n"; exit(1); }

$orderModel = $container->get(Order::class);
$invoiceService = $container->get(InvoiceService::class);
$settingModel = $container->get(Setting::class);
$logger = $container->get(\App\Core\Logger::class);

// Otomatik fatura ayarı kontrol
$autoInvoice = $settingModel->get('auto_invoice_enabled', '0');
if ($autoInvoice !== '1') {
    echo "[INFO] Otomatik fatura kapalı. Çıklıyor.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Otomatik fatura başlıyor (per-store)...\n";

// Faz 4 — PER-STORE: getPendingForInvoice tenant-scoped; her mağaza kendi bağlamında, izole hata.
$cronRunner = $container->get(\App\Services\TenantCronRunner::class);
$storeResults = $cronRunner->runForActiveStores(function (int $storeId) use ($orderModel, $invoiceService, $logger): array {
    $success = 0; $failed = 0;
    foreach ($orderModel->getPendingForInvoice() as $order) {
        try {
            $invoiceService->createForOrder((int) $order['id']);
            $success++;
        } catch (\Throwable $e) {
            $failed++;
            $logger->warning("Otomatik fatura [store#{$storeId}/Order #{$order['id']}]: " . $e->getMessage(), 'Cron');
        }
    }
    return ['success' => $success, 'failed' => $failed];
});

foreach ($storeResults as $storeId => $sr) {
    if (!($sr['ok'] ?? false)) {
        echo "  store#{$storeId}: [HATA] " . ($sr['error'] ?? '?') . "\n";
        continue;
    }
    echo "  store#{$storeId}: {$sr['result']['success']} fatura, {$sr['result']['failed']} hatalı\n";
}
echo "[OK] Otomatik fatura tamamlandı (" . count($storeResults) . " mağaza).\n";
