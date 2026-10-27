<?php
/**
 * OzyConnect Panel — StockSyncService
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
use App\Models\Product;

final class StockSyncService
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
        private readonly \App\Services\XmlStockLinks $stockLinks,
    ) {}

    /**
     * Tüm aktif pazaryerlerine stok senkronizasyonu
     */
    public function syncAll(string $triggeredBy = 'cron', ?int $onlyMarketplaceId = null): array
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
            if ($onlyMarketplaceId !== null && (int)$mp['id'] !== $onlyMarketplaceId) continue;
            try {
                // Credential kontrolü - encrypted key yoksa atla
                if (!$this->hasRequiredCredentials($mp['slug'], $mp)) {
                    $this->logger->info("Stok sync atlandı - {$mp['slug']} credential eksik", 'StockSync');
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
     * Belirli pazaryerine stok senkronizasyonu (sadece değişenler)
     *
     * @return array{sent: int, skipped: int, failed: int, errors: array}
     */
    public function syncMarketplace(int $marketplaceId, string $slug, string $triggeredBy = 'manual'): array
    {
        $logId = $this->syncLogModel->start($marketplaceId, 'stock', $triggeredBy);

        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        try {
            // Re-read actual stock, including previously rejected async updates.
            $blocked = [];
            $linkedItems = [];
            if ($slug === 'trendyol') {
                $remote = $this->listingReconciler->run(true);
                $blocked = $remote['blocked_barcodes'];
                $this->stockLinks->refreshLegacyStock();
                $linkedItems = $this->stockLinks->items($marketplaceId);
            }

            // Stoğu değişen ürünleri al
            $changedProducts = $this->mpProductModel->getStockChanged($marketplaceId);

            if (empty($changedProducts) && empty($linkedItems)) {
                $this->syncLogModel->finish($logId, 'success', 0, 0);
                return $result;
            }

            // Batch halinde gönder
            $batchItems = [];
            $batchMap = []; // barcode → mpProduct id eşleştirmesi

            foreach ($changedProducts as $mp) {
                $mpProductId = (string) ($mp['marketplace_product_id'] ?? '');
                $barcode = (string) ($mp['marketplace_barcode'] ?? $mp['barcode'] ?? '');
                $sku = (string) ($mp['marketplace_sku'] ?? $mp['internal_sku'] ?? '');

                // Pazaryerleri ürünü farklı anahtarla tanır: n11 stok kodu (sku),
                // Hepsiburada HB SKU / satıcı stok kodu, Trendyol barkod. Bunlardan
                // en az biri varsa gönderilebilir.
                if ($barcode === '' && $sku === '' && $mpProductId === '') {
                    $result['skipped']++;
                    continue;
                }
                $mapKey = $this->batchKey($barcode, $sku, $mpProductId);

                $product = $this->productModel->find((int) $mp['product_id']);
                if ($product === false) {
                    $result['skipped']++;
                    continue;
                }

                $calculatedStock = max(0, (int) $mp['calculated_stock']);
                if ($slug === 'trendyol') {
                    $calculatedStock = \App\Services\Marketplace\TrendyolProductRules::quantity($calculatedStock);
                    if (($calculatedStock > 0 && isset($blocked[$barcode]))
                        || ($mp['last_stock_sent'] !== null && (int)$mp['last_stock_sent'] === $calculatedStock)) {
                        $result['skipped']++;
                        continue;
                    }
                }
                $price = $slug === 'trendyol' ? ['sale_price'=>0,'list_price'=>0] : $this->priceEngine->calculatePrice($product, $marketplaceId);

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
                    'stock' => $calculatedStock,
                    'price' => $price['sale_price'],
                ];
            }

            if ($slug === 'trendyol') {
                // A legacy barcode may have no local marketplace_products row.
                // The source link takes precedence over stale legacy-card stock.
                $byBarcode = array_column($batchItems, null, 'barcode');
                foreach ($linkedItems as $item) $byBarcode[(string)$item['barcode']] = $item;
                $batchItems = array_values($byBarcode);
            }
            if (empty($batchItems)) {
                $this->syncLogModel->finish($logId, 'success', count($changedProducts), 0);
                return $result;
            }

            // This is a delta, not the entire catalogue: every changed item can
            // legitimately be sold out. Do not block zero-only stock updates.

            // Pazaryerine gönder
            $apiResult = match ($slug) {
                'trendyol'    => $this->trendyolService->updateStock(array_map(
                    static fn(array $item): array => ['barcode'=>$item['barcode'], 'quantity'=>$item['quantity']],
                    $batchItems
                )),
                'n11'         => $this->n11Service->updateStock($batchItems),
                'hepsiburada' => $this->hepsiburadaService->updateStock($batchItems),
                'pazarama'    => $this->pazaramaService->updateStock($batchItems),
                'pttavm'      => $this->pttavmService->updateStock($batchItems),
                'ciceksepeti' => $this->ciceksepetiService->updateStock($batchItems),
                'idefix'      => $this->idefixService->updateStock($batchItems),
                'amazon'      => $this->amazonService->updateStock($batchItems),
                'oziygo'      => $this->oziygoService->updateStock($batchItems),
                'shopify'     => $this->shopifyService->updateStock($batchItems),
                'woocommerce' => $this->wooCommerceService->updateStock($batchItems),
                'opencart'    => $this->openCartService->updateStock($batchItems),
                default       => ['success' => 0, 'failed' => count($batchItems), 'errors' => [['error' => 'Desteklenmeyen pazaryeri']]],
            };

            $result['sent'] = $apiResult['success'];
            $result['failed'] = $apiResult['failed'];
            $result['errors'] = $apiResult['errors'];
            if ($slug === 'trendyol') {
                $result['pending'] = $apiResult['pending'] ?? 0;
                $result['batch_ids'] = $apiResult['batch_ids'] ?? [];
            }

            // SADECE gerçekten gönderilen ve pazaryerince kabul edilen kayıtlar
            // damgalanır. (Hatalı kaydı damgalamak, bir sonraki senkronda "değişiklik
            // yok" sayılmasına ve stoğun pazaryerinde sonsuza dek eski kalmasına
            // yol açıyordu.)
            $failedKeys = $this->extractFailedKeys($apiResult['errors'] ?? []);
            $stampAll = ($apiResult['failed'] ?? 0) === 0;

            // Trendyol only acknowledges a queued request. Its next catalogue read
            // confirms the applied stock; never stamp queue acceptance as completion.
            if ($slug !== 'trendyol' && ($apiResult['success'] ?? 0) > 0) {
                foreach ($batchMap as $key => $info) {
                    if (!$stampAll && ($failedKeys === [] || $this->keyIsFailed($key, $failedKeys))) {
                        continue;
                    }
                    // Fiyat bu istekte gönderilmedi — yalnız stok damgalanır.
                    $this->mpProductModel->updateLastSent($info['mp_id'], $info['stock'], null);
                }
            }

            foreach ($batchMap as $key => $info) {
                if ($failedKeys !== [] && $this->keyIsFailed($key, $failedKeys)) {
                    $this->mpProductModel->setSyncError(
                        $info['mp_id'],
                        mb_substr('Stok gönderilemedi: ' . $this->firstErrorFor($key, $apiResult['errors'] ?? []), 0, 500)
                    );
                }
            }

            $status = $result['failed'] > 0 ? 'partial' : 'success';
            $this->syncLogModel->finish($logId, $status, count($batchItems), $result['sent'], $result['failed'], $result['errors']);

            $this->logger->info(
                "Stok sync ({$slug}): {$result['sent']} gönderildi, {$result['failed']} hatalı",
                'StockSync'
            );

        } catch (\Throwable $e) {
            $this->syncLogModel->finish($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            $this->logger->error("Stok sync hatası ({$slug}): " . $e->getMessage(), 'StockSync');
            throw $e;
        }

        return $result;
    }

    /**
     * Batch eşleştirme anahtarı — barkod/sku/pazaryeri ürün id birlikte tutulur.
     */
    private function batchKey(string $barcode, string $sku, string $mpProductId): string
    {
        return implode('|', [$barcode, $sku, $mpProductId]);
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

}
