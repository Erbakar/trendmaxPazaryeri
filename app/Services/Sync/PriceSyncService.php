<?php
/**
 * OzyConnect Panel — PriceSyncService
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\Logger;
use App\Models\Marketplace;
use App\Models\MarketplaceProduct;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\PttavmService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\AmazonService;
use App\Services\Marketplace\IdefixService;
use App\Services\Marketplace\OziygoService;
use App\Services\Marketplace\ShopifyService;
use App\Services\Marketplace\WooCommerceService;
use App\Services\Marketplace\OpenCartService;
use App\Services\PriceRuleEngine;

final class PriceSyncService
{
    public function __construct(
        private readonly Marketplace $marketplaceModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly Product $productModel,
        private readonly SyncLog $syncLogModel,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly PazaramaService $pazaramaService,
        private readonly PttavmService $pttavmService,
        private readonly CiceksepetiService $ciceksepetiService,
        private readonly AmazonService $amazonService,
        private readonly IdefixService $idefixService,
        private readonly OziygoService $oziygoService,
        private readonly ShopifyService $shopifyService,
        private readonly WooCommerceService $wooCommerceService,
        private readonly OpenCartService $openCartService,
        private readonly PriceRuleEngine $priceEngine,
        private readonly Logger $logger,
        private readonly \App\Core\TenantContext $tenant,
        private readonly \App\Services\TrendyolListingReconciler $listingReconciler,
    ) {}

    /**
     * Tüm aktif pazaryerlerine fiyat senkronizasyonu
     */
    public function syncAll(string $triggeredBy = 'cron'): array
    {
        // Faz 4 — FAIL-CLOSED + entitlement (per-store).
        $storeId = $this->tenant->currentStoreId();
        $marketplaces = $this->marketplaceModel->getActiveForStore($storeId);
        
        if (empty($marketplaces)) {
            return [
                'message' => 'Aktif pazaryeri bulunamadı',
                'marketplaces' => [],
            ];
        }
        
        $results = [];

        foreach ($marketplaces as $mp) {
            try {
                // Credential kontrolü - encrypted key yoksa atla
                if (!$this->hasRequiredCredentials($mp['slug'], $mp)) {
                    $this->logger->info("Fiyat sync atlandı - {$mp['slug']} credential eksik", 'PriceSync');
                    continue;
                }
                
                $results[$mp['slug']] = $this->syncMarketplace((int) $mp['id'], $mp['slug'], $triggeredBy);
            } catch (\Throwable $e) {
                $results[$mp['slug']] = [
                    'sent' => 0,
                    'skipped' => 0,
                    'failed' => 1,
                    'errors' => [['error' => $e->getMessage()]],
                ];
            }
        }

        return $results;
    }

    /**
     * Pazaryerinin gerekli credential'larının olup olmadığını kontrol et
     */
    private function hasRequiredCredentials(string $slug, array $mp): bool
    {
        $hasApiKey = !empty($mp['api_key_encrypted']);
        $hasApiSecret = !empty($mp['api_secret_encrypted']);

        return match ($slug) {
            'trendyol' => $hasApiKey && $hasApiSecret,
            'n11' => $hasApiKey && $hasApiSecret,
            'hepsiburada' => $hasApiSecret,
            'pazarama' => $hasApiKey && $hasApiSecret,
            'pttavm' => $hasApiSecret,
            'ciceksepeti' => $hasApiKey,
            'amazon' => $hasApiKey,
            'idefix' => $hasApiKey,
            'oziygo' => $hasApiKey,
            'shopify' => $hasApiSecret,
            'woocommerce' => $hasApiKey && $hasApiSecret,
            'opencart' => $hasApiSecret,
            default => false,
        };
    }

    /**
     * Belirli pazaryerine fiyat senkronizasyonu
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function syncMarketplace(int $marketplaceId, string $slug, string $triggeredBy = 'manual'): array
    {
        $logId = $this->syncLogModel->start($marketplaceId, 'price', $triggeredBy);
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        try {
            // Newly approved listings must be matched before selecting sync targets.
            // This also reads the real remote price so rejected or externally changed
            // prices are retried rather than skipped using an HTTP acceptance stamp.
            if ($slug === 'trendyol') {
                $this->listingReconciler->run(true);
            }
            $mpProducts = $this->mpProductModel->getActiveForMarketplace($marketplaceId);

            $batchItems = [];
            $batchMap = [];

            foreach ($mpProducts as $mp) {
                $product = $this->productModel->find((int) $mp['product_id']);
                if ($product === false) {
                    $result['skipped']++;
                    continue;
                }

                $price = $this->priceEngine->calculatePrice($product, $marketplaceId);
                $lastSent = (float) ($mp['last_price_sent'] ?? 0);

                // Fiyat değişmemişse atla
                if (abs($price['sale_price'] - $lastSent) < 0.01) {
                    $result['skipped']++;
                    continue;
                }

                $mpProductId = (string) ($mp['marketplace_product_id'] ?? '');
                $barcode = (string) ($mp['marketplace_barcode'] ?? $product['barcode'] ?? '');
                $sku = (string) ($mp['marketplace_sku'] ?? $mp['internal_sku'] ?? '');

                // Pazaryerleri ürünü farklı anahtarla tanır (n11 stok kodu, HB SKU,
                // Trendyol barkod). En az biri varsa gönderim yapılabilir.
                if ($barcode === '' && $sku === '' && $mpProductId === '') {
                    $result['skipped']++;
                    continue;
                }
                $mapKey = implode('|', [$barcode, $sku, $mpProductId]);

                $calculatedStock = max(0, (int) $product['stock_qty'] - (int) $mp['stock_offset']);

                $batchItems[] = [
                    'id'        => $mpProductId,
                    'marketplace_product_id' => $mpProductId,
                    'hbSku'     => $mpProductId,
                    'sku'       => $sku,
                    'barcode'   => $barcode,
                    'quantity'  => $calculatedStock,
                    'salePrice' => $price['sale_price'],
                    'listPrice' => $price['list_price'],
                ];

                $batchMap[$mapKey] = [
                    'mp_id' => (int) $mp['id'],
                    'price' => $price['sale_price'],
                    'stock' => $calculatedStock,
                ];
            }

            if (!empty($batchItems)) {
                if ($this->isSuspiciousMassZeroStock($batchItems)) {
                    $message = 'Fiyat sync engellendi: payload icinde cok sayida urun 0 stok tasiyor. Once yerel stoklari kontrol edin.';
                    $result['failed'] = count($batchItems);
                    $result['errors'][] = ['error' => $message];
                    $this->syncLogModel->finish($logId, 'failed', count($mpProducts), 0, $result['failed'], $result['errors']);
                    $this->logger->error("Fiyat sync korumasi ({$slug}): {$message}", 'PriceSync');
                    return $result;
                }

                $apiResult = match ($slug) {
                    'trendyol'    => $this->trendyolService->updatePrice($batchItems),
                    'n11'         => $this->n11Service->updatePrice($batchItems),
                    'hepsiburada' => $this->hepsiburadaService->updatePrice($batchItems),
                    'pazarama'    => $this->pazaramaService->updatePrice($batchItems),
                    'pttavm'      => $this->pttavmService->updatePrice($batchItems),
                    'ciceksepeti' => $this->ciceksepetiService->updatePrice($batchItems),
                    'idefix'      => $this->idefixService->updatePrice($batchItems),
                    'amazon'      => $this->amazonService->updatePrice($batchItems),
                    'oziygo'      => $this->oziygoService->updatePrice($batchItems),
                    'shopify'     => $this->shopifyService->updatePrice($batchItems),
                    'woocommerce' => $this->wooCommerceService->updatePrice($batchItems),
                    'opencart'    => $this->openCartService->updatePrice($batchItems),
                    default       => ['success' => 0, 'failed' => count($batchItems), 'errors' => []],
                };

                $result['sent'] = $apiResult['success'];
                $result['failed'] = $apiResult['failed'];
                $result['errors'] = $apiResult['errors'] ?? [];

                // KRİTİK: Bu istekte SADECE FİYAT gönderildi. Eskiden burada
                // last_stock_sent de damgalanıyordu; stok senkronu ardından
                // "değişiklik yok" görüp hiçbir şey göndermiyor, pazaryerindeki
                // stok güncellenmiyordu. Artık yalnız fiyat damgalanır.
                $failedKeys = $this->extractFailedKeys($apiResult['errors'] ?? []);
                $stampAll = ($apiResult['failed'] ?? 0) === 0;

                if (($apiResult['success'] ?? 0) > 0) {
                    foreach ($batchMap as $key => $info) {
                        if (!$stampAll && ($failedKeys === [] || $this->keyIsFailed($key, $failedKeys))) {
                            continue;
                        }
                        $this->mpProductModel->updateLastSent($info['mp_id'], null, $info['price']);
                    }
                }

                foreach ($batchMap as $key => $info) {
                    if ($failedKeys !== [] && $this->keyIsFailed($key, $failedKeys)) {
                        $this->mpProductModel->setSyncError(
                            $info['mp_id'],
                            mb_substr('Fiyat gönderilemedi: ' . $this->firstErrorFor($key, $apiResult['errors'] ?? []), 0, 500)
                        );
                    }
                }
            }

            $status = $result['failed'] > 0 ? 'partial' : 'success';
            $this->syncLogModel->finish($logId, $status, count($mpProducts), $result['sent'], $result['failed']);

        } catch (\Throwable $e) {
            $this->syncLogModel->finish($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            $this->logger->error("Fiyat sync hatası ({$slug}): " . $e->getMessage(), 'PriceSync');
            throw $e;
        }

        return $result;
    }

    /**
     * API hatalarından SKU/barkod listesi çıkar.
     *
     * @param array<int, mixed> $errors
     * @return array<int, string>
     */
    private function extractFailedKeys(array $errors): array
    {
        $keys = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            foreach (['sku', 'barcode', 'stockCode', 'merchantSku'] as $field) {
                $value = trim((string) ($error[$field] ?? ''));
                if ($value !== '') {
                    $keys[] = $value;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, string> $failedKeys
     */
    private function keyIsFailed(string $batchKey, array $failedKeys): bool
    {
        $parts = array_filter(explode('|', $batchKey));
        foreach ($failedKeys as $failed) {
            foreach ($parts as $part) {
                if (strcasecmp($part, $failed) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $errors
     */
    private function firstErrorFor(string $batchKey, array $errors): string
    {
        $parts = array_filter(explode('|', $batchKey));
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            foreach (['sku', 'barcode', 'stockCode', 'merchantSku'] as $field) {
                $value = trim((string) ($error[$field] ?? ''));
                if ($value === '') {
                    continue;
                }
                foreach ($parts as $part) {
                    if (strcasecmp($part, $value) === 0) {
                        return (string) ($error['error'] ?? 'Bilinmeyen hata');
                    }
                }
            }
        }

        return 'Bilinmeyen hata';
    }

    /**
     * Trendyol gibi fiyat ve stok ayni endpointten giden pazaryerlerinde
     * toplu sifir stok kazasini durdurur.
     */
    private function isSuspiciousMassZeroStock(array $items): bool
    {
        $total = count($items);
        if ($total < 10) {
            return false;
        }

        $zero = 0;
        foreach ($items as $item) {
            if ((int) ($item['quantity'] ?? 0) <= 0) {
                $zero++;
            }
        }

        return $zero === $total || ($zero / $total) >= 0.90;
    }
}
