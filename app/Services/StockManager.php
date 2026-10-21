<?php
/**
 * OzyConnect Panel — StockManager (Kritik Stok Yönetimi)
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

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Models\MarketplaceProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Marketplace;
use App\Models\StoreSetting;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\PttavmService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\IdefixService;
use App\Services\Marketplace\OziygoService;
use App\Services\Marketplace\WooCommerceService;
use RuntimeException;

final class StockManager
{
    /**
     * Mağaza ayarı (store_settings.skey): sipariş/iade/elle stok değişikliği sonrası pazaryerlerine
     * OTOMATİK stok yayını. '0' → KAPALI; kayıt yoksa ya da başka değer → AÇIK (varsayılan).
     * Zamanlanmış stok senkronu (StockSyncService / cron stock görevi) bu ayardan etkilenmez.
     */
    public const AUTO_BROADCAST_SETTING = 'auto_stock_broadcast';

    public function __construct(
        private readonly Database $db,
        private readonly Product $productModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly StockMovement $stockMovementModel,
        private readonly Order $orderModel,
        private readonly OrderItem $orderItemModel,
        private readonly Marketplace $marketplaceModel,
        private readonly PriceRuleEngine $priceEngine,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly PazaramaService $pazaramaService,
        private readonly PttavmService $pttavmService,
        private readonly CiceksepetiService $ciceksepetiService,
        private readonly IdefixService $idefixService,
        private readonly OziygoService $oziygoService,
        private readonly Logger $logger,
        private readonly StoreSetting $storeSettingModel,
        private readonly Container $container,
    ) {}

    /**
     * WooCommerce servisi (container tekili) yalnız WooCommerce'e stok yayını gerektiğinde çözülür.
     */
    private function wooCommerce(): WooCommerceService
    {
        return $this->container->get(WooCommerceService::class);
    }

    /**
     * Sipariş geldiğinde stoğu rezerve et (tam atomik)
     * Stok fiziksel olarak düşer ama hareket tipi 'reserve' olarak kaydedilir.
     * Kargoya verildiğinde 'sale' olarak onaylanır, iptal edilirse geri eklenir.
     *
     * @param array<int, string> $skipItems order_item_id => neden: bu kalemlerde stok düşülmez, yalnız
     *        iz kaydı ('none', 0 adet) yazılır (ör. pazaryerinde zaten iptal olan sipariş, ilan bağlanmadan
     *        önce verilmiş sipariş). İz kaydı sonradan gelen iptalin "hareket yok" uyarısını da önler.
     */
    public function reserveOrderStock(int $orderId, array $skipItems = []): void
    {
        $reservedProductIds = [];

        $this->db->transaction(function () use ($orderId, $skipItems, &$reservedProductIds): void {
            $order = $this->orderModel->findOrFail($orderId);
            $items = $this->orderItemModel->getByOrderId($orderId);

            foreach ($items as $item) {
                if ($item['product_id'] === null) {
                    $this->logger->warning(
                        "Sipariş #{$orderId}: Ürün eşleştirilemedi, stok düşülmedi",
                        'StockManager',
                        ['sku' => $item['sku'], 'name' => $item['name']]
                    );
                    continue;
                }

                $productId = (int) $item['product_id'];
                $qty = (int) $item['qty'];

                $skipReason = $skipItems[(int) ($item['id'] ?? 0)] ?? null;
                if ($skipReason !== null) {
                    $current = $this->productModel->find($productId);
                    $currentQty = $current !== false ? (int) $current['stock_qty'] : 0;
                    $this->stockMovementModel->record(
                        productId: $productId,
                        movementType: 'none',
                        source: $this->getMarketplaceSlug((int) $order['marketplace_id']),
                        qtyChange: 0,
                        qtyBefore: $currentQty,
                        qtyAfter: $currentQty,
                        referenceId: "order:{$orderId}",
                        note: "Stok düşülmedi ({$qty} adet) — Sipariş #{$order['marketplace_order_id']}: {$skipReason}"
                    );
                    $this->logger->info(
                        "Sipariş #{$orderId}: ürün #{$productId} stoğu düşülmedi — {$skipReason}",
                        'StockManager'
                    );
                    continue;
                }

                $reservedProductIds[$productId] = $productId;

                // SELECT FOR UPDATE — race condition önleme
                $product = $this->productModel->findForUpdate($productId);
                if ($product === false) {
                    $this->logger->error(
                        "Ürün bulunamadı (stok düşülemiyor): #{$productId}",
                        'StockManager'
                    );
                    continue;
                }

                $currentStock = (int) $product['stock_qty'];
                $newStock = $currentStock - $qty;

                // Negatif stok koruması
                if ($newStock < 0) {
                    $this->logger->error(
                        "Stok negatife düşüyor! Ürün #{$productId}: mevcut={$currentStock}, satış={$qty}",
                        'StockManager',
                        ['order_id' => $orderId]
                    );
                    $newStock = 0;
                }

                // Stoğu güncelle
                $this->productModel->updateStockAtomic($productId, $newStock);

                // Stok hareketi kaydet
                $marketplaceSlug = $this->getMarketplaceSlug((int) $order['marketplace_id']);
                $this->stockMovementModel->record(
                    productId: $productId,
                    movementType: 'reserve',
                    source: $marketplaceSlug,
                    qtyChange: -$qty,
                    qtyBefore: $currentStock,
                    qtyAfter: $newStock,
                    referenceId: "order:{$orderId}",
                    note: "Sipariş #{$order['marketplace_order_id']}"
                );

                // Stok sıfırlandıysa uyarı logu
                if ($newStock === 0) {
                    $this->logger->warning(
                        "Stok bitti! Ürün #{$productId} ({$product['name']})",
                        'StockManager'
                    );
                }
            }
        });

        // Transaction dışında: stoğu düşen ürünleri tüm pazaryerlerine yayınla
        foreach (array_values($reservedProductIds) as $productId) {
            try {
                // Child ürünse parent stokunu da güncelle
                $this->syncParentStock((int) $productId);

                $this->broadcastStock((int) $productId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Stok broadcast hatası (ürün #{$productId}): " . $e->getMessage(),
                    'StockManager'
                );
            }
        }
    }

    /**
     * Sipariş kargoya verildiğinde rezervasyonu onaylı satışa çevir (audit)
     */
    public function confirmOrderStock(int $orderId): void
    {
        $ref = "order:{$orderId}";
        $updated = $this->stockMovementModel->updateTypeByReference($ref, 'reserve', 'sale');

        if ($updated > 0) {
            $this->logger->info(
                "Stok rezervasyonu onaylandı: Sipariş #{$orderId} ({$updated} hareket)",
                'StockManager'
            );
        }
    }

    /**
     * Sipariş iptal edildiğinde stoğu geri ekle ve tüm pazaryerlerine yayınla
     */
    public function cancelOrderReservation(int $orderId): void
    {
        $ref = "order:{$orderId}";
        $movements = $this->stockMovementModel->getByReference($ref);

        if (empty($movements)) {
            $this->logger->warning(
                "İptal stok iadesi: Sipariş #{$orderId} için stok hareketi bulunamadı",
                'StockManager'
            );
            return;
        }

        // Sadece reserve veya sale tipindeki hareketleri geri al (cancel zaten geri alınmış demek)
        $movementsToReverse = array_filter($movements, fn($m) => in_array($m['movement_type'], ['reserve', 'sale']));
        if (empty($movementsToReverse)) {
            return;
        }

        $affectedProductIds = [];

        $this->db->transaction(function () use ($orderId, $movementsToReverse, &$affectedProductIds): void {
            $order = $this->orderModel->findOrFail($orderId);

            foreach ($movementsToReverse as $movement) {
                $productId = (int) $movement['product_id'];
                $restoreQty = abs((int) $movement['qty_change']);

                if ($restoreQty === 0) {
                    continue;
                }

                $product = $this->productModel->findForUpdate($productId);
                if ($product === false) {
                    $this->logger->error(
                        "İptal stok: Ürün bulunamadı #{$productId}",
                        'StockManager'
                    );
                    continue;
                }

                $currentStock = (int) $product['stock_qty'];
                $newStock = $currentStock + $restoreQty;

                $this->productModel->updateStockAtomic($productId, $newStock);

                $marketplaceSlug = $this->getMarketplaceSlug((int) $order['marketplace_id']);
                $this->stockMovementModel->record(
                    productId: $productId,
                    movementType: 'cancel',
                    source: $marketplaceSlug,
                    qtyChange: $restoreQty,
                    qtyBefore: $currentStock,
                    qtyAfter: $newStock,
                    referenceId: "order:{$orderId}",
                    note: "İptal — Sipariş #{$order['marketplace_order_id']}"
                );

                $affectedProductIds[] = $productId;
            }
        });

        // İptal sonrası tüm pazaryerlerine güncel stoğu yayınla
        $affectedProductIds = array_unique($affectedProductIds);
        foreach ($affectedProductIds as $productId) {
            try {
                // Child ürünse parent stokunu da güncelle
                $this->syncParentStock((int) $productId);

                $this->broadcastStock((int) $productId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "İptal stok broadcast hatası (ürün #{$productId}): " . $e->getMessage(),
                    'StockManager'
                );
            }
        }

        $this->logger->info(
            "Sipariş iptal stok iadesi tamamlandı: #{$orderId} (" . count($affectedProductIds) . " ürün)",
            'StockManager'
        );
    }

    /**
     * İade onaylanınca stoğu geri artır
     */
    public function processReturn(int $returnId): void
    {
        $this->db->transaction(function () use ($returnId): void {
            $returnData = $this->db->fetchOne(
                "SELECT * FROM `returns` WHERE id = :id FOR UPDATE",
                ['id' => $returnId]
            );

            if ($returnData === false) {
                throw new RuntimeException("İade kaydı bulunamadı: #{$returnId}");
            }

            $items = json_decode($returnData['items_json'] ?? '[]', true) ?: [];

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['qty'] ?? 1);

                if ($productId === 0) {
                    continue;
                }

                $product = $this->productModel->findForUpdate($productId);
                if ($product === false) {
                    continue;
                }

                $currentStock = (int) $product['stock_qty'];
                $newStock = $currentStock + $qty;

                $this->productModel->updateStockAtomic($productId, $newStock);

                $this->stockMovementModel->record(
                    productId: $productId,
                    movementType: 'return',
                    source: $this->getMarketplaceSlug((int) $returnData['marketplace_id']),
                    qtyChange: $qty,
                    qtyBefore: $currentStock,
                    qtyAfter: $newStock,
                    referenceId: "return:{$returnId}",
                    note: "İade #{$returnData['marketplace_return_id']}"
                );
            }
        });

        // İade sonrası tüm pazaryerlerine güncel stoğu yayınla
        $returnData = $this->db->fetchOne("SELECT * FROM `returns` WHERE id = :id", ['id' => $returnId]);
        if ($returnData !== false) {
            $items = json_decode($returnData['items_json'] ?? '[]', true) ?: [];
            $productIds = array_unique(array_filter(array_map(fn($i) => (int) ($i['product_id'] ?? 0), $items)));

            foreach ($productIds as $productId) {
                try {
                    // Child ürünse parent stokunu da güncelle
                    $this->syncParentStock($productId);

                    $this->broadcastStock($productId);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        "İade stok broadcast hatası (ürün #{$productId}): " . $e->getMessage(),
                        'StockManager'
                    );
                }
            }
        }
    }

    /**
     * Manuel stok ayarlama
     */
    public function manualAdjustment(int $productId, int $qty, string $note, int $userId): void
    {
        $this->db->transaction(function () use ($productId, $qty, $note, $userId): void {
            $product = $this->productModel->findForUpdate($productId);
            if ($product === false) {
                throw new RuntimeException("Ürün bulunamadı: #{$productId}");
            }

            $currentStock = (int) $product['stock_qty'];
            $newStock = $currentStock + $qty;

            if ($newStock < 0) {
                throw new RuntimeException(
                    "Stok negatife düşemez. Mevcut: {$currentStock}, İşlem: {$qty}"
                );
            }

            $this->productModel->updateStockAtomic($productId, $newStock);

            $movementType = $qty > 0 ? 'manual_in' : 'manual_out';  

            $this->stockMovementModel->record(
                productId: $productId,
                movementType: $movementType,
                source: 'manuel',
                qtyChange: $qty,
                qtyBefore: $currentStock,
                qtyAfter: $newStock,
                note: $note,
                userId: $userId
            );
        });

        // Stok yayınla
        try {
            // Child ürünse parent stokunu da güncelle
            $this->syncParentStock($productId);

            $this->broadcastStock($productId);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Manuel stok sonrası broadcast hatası: " . $e->getMessage(),
                'StockManager'
            );
        }
    }

    /**
     * Stok yayınlama açık mı? Toplu/geçmişe dönük aktarımlarda kapatılır.
     *
     * İlk kurulum sırasında geçmiş siparişler içe aktarılırken her sipariş için
     * tüm pazaryerlerine stok gönderiliyordu: 300 siparişte 600+ API çağrısı ve
     * 2 dakikadan uzun süre. O satışlar pazaryerinde ZATEN gerçekleşmiş olduğundan
     * geri bildirim anlamsızdır; stok, normal stok senkronuyla zaten hizalanır.
     */
    private bool $broadcastEnabled = true;

    public function setBroadcastEnabled(bool $enabled): void
    {
        $this->broadcastEnabled = $enabled;
    }

    /**
     * Aktif mağazanın otomatik stok yayını anahtarı açık mı (store_settings.auto_stock_broadcast).
     *
     * '0' → kapalı; kayıt yok / başka değer / okunamadı (mağaza bağlamı yok, tablo yok) → AÇIK.
     * Önbellek YOK: uzun ömürlü kuyruk/cron sürecinde mağaza değişse de, ayar panelden
     * değiştirilse de her çağrı güncel değeri okur.
     */
    public function isAutoBroadcastEnabled(): bool
    {
        try {
            $value = $this->storeSettingModel->getValue(self::AUTO_BROADCAST_SETTING);
        } catch (\Throwable) {
            return true;
        }

        return $value === null || trim($value) !== '0';
    }

    /**
     * Tüm aktif pazaryerlerine stok gönder (tek ürün)
     *
     * Çalışma anı bayrağı (setBroadcastEnabled) ya da mağazanın otomatik stok yayını
     * anahtarı kapalıysa pazaryerine hiçbir şey gönderilmez.
     *
     * @return array{success: int, failed: int, skipped?: bool}
     */
    public function broadcastStock(int $productId): array
    {
        if (!$this->broadcastEnabled) {
            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        if (!$this->isAutoBroadcastEnabled()) {
            return ['success' => 0, 'failed' => 0];
        }

        $marketplaces = $this->marketplaceModel->getActive();
        $success = 0;
        $failed = 0;

        foreach ($marketplaces as $mp) {
            try {
                if ($this->syncStockToMarketplace($productId, (int) $mp['id'])) {
                    $success++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error(
                    "Stok sync hatası (ürün #{$productId}, {$mp['slug']}): " . $e->getMessage(),
                    'StockManager'
                );
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * Toplu stok yayınlama — birden fazla ürünü pazaryeri bazında gruplayarak
     * tek batch API çağrısı ile gönderir. (N×7 yerine 7 çağrı)
     *
     * broadcastStock ile aynı korumalara uyar: çalışma anı bayrağı ya da mağazanın
     * otomatik stok yayını anahtarı kapalıysa pazaryerine hiçbir şey gönderilmez.
     *
     * @param array<int> $productIds
     * @return array{success: int, failed: int, skipped?: bool}
     */
    public function batchBroadcastStock(array $productIds): array
    {
        if (empty($productIds)) {
            return ['success' => 0, 'failed' => 0];
        }

        if (!$this->broadcastEnabled) {
            return ['success' => 0, 'failed' => 0, 'skipped' => true];
        }

        if (!$this->isAutoBroadcastEnabled()) {
            return ['success' => 0, 'failed' => 0];
        }

        $marketplaces = $this->marketplaceModel->getActive();
        $totalSuccess = 0;
        $totalFailed = 0;

        foreach ($marketplaces as $mp) {
            $marketplaceId = (int) $mp['id'];
            $slug = $mp['slug'];
            $batchItems = [];
            $batchMeta = []; // barcode → mp_product bilgisi

            foreach ($productIds as $productId) {
                $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
                if ($mpProduct === false || !(bool) $mpProduct['is_active']) {
                    continue;
                }

                $product = $this->productModel->find($productId);
                if ($product === false) {
                    continue;
                }

                $mpProductId = (string) ($mpProduct['marketplace_product_id'] ?? '');
                $barcode = $mpProduct['marketplace_barcode'] ?? $mpProduct['marketplace_sku'] ?? $product['barcode'] ?? '';
                $sku = (string) (($mpProduct['marketplace_sku'] ?? '') ?: ($product['sku'] ?? ''));
                if ($barcode === '' && !($slug === 'oziygo' && $mpProductId !== '')) {
                    continue;
                }
                $metaKey = $barcode !== '' ? $barcode : 'mp:' . $mpProductId;

                $calculatedStock = $this->calculateMarketplaceStock($productId, $marketplaceId);
                $price = $this->priceEngine->calculatePrice($product, $marketplaceId);

                $batchItems[] = [
                    'id'       => $mpProductId,
                    'marketplace_product_id' => $mpProductId,
                    'hbSku'    => $mpProductId,
                    'sku'      => $sku,
                    'barcode'   => $barcode,
                    'quantity'  => max(0, $calculatedStock),
                    'salePrice' => $price['sale_price'],
                    'listPrice' => $price['list_price'],
                ];

                $batchMeta[$metaKey] = [
                    'mp_id' => (int) $mpProduct['id'],
                    'stock' => $calculatedStock,
                    'price' => $price['sale_price'],
                ];
            }

            if (empty($batchItems)) {
                continue;
            }

            try {
                $result = match ($slug) {
                    'trendyol'    => $this->trendyolService->updateStock($batchItems),
                    'n11'         => $this->n11Service->updateStock($batchItems),
                    'hepsiburada' => $this->hepsiburadaService->updateStock($batchItems),
                    'pazarama'    => $this->pazaramaService->updateStock($batchItems),
                    'pttavm'      => $this->pttavmService->updateStock($batchItems),
                    'ciceksepeti' => $this->ciceksepetiService->updateStock($batchItems),
                    'idefix'      => $this->idefixService->updateStock($batchItems),
                    'oziygo'      => $this->oziygoService->updateStock($batchItems),
                    'woocommerce' => $this->wooCommerce()->updateStock($batchItems),
                    default       => ['success' => 0, 'failed' => count($batchItems)],
                };

                $totalSuccess += ($result['success'] ?? 0);
                $totalFailed += ($result['failed'] ?? 0);

                // Başarılıysa son gönderilen değerleri güncelle
                if (($result['failed'] ?? 0) === 0) {
                    foreach ($batchMeta as $info) {
                        $this->mpProductModel->updateLastSent($info['mp_id'], $info['stock'], $info['price']);
                    }
                }

                $this->logger->info(
                    "Batch stok broadcast ({$slug}): " . count($batchItems) . " ürün gönderildi",
                    'StockManager'
                );
            } catch (\Throwable $e) {
                $totalFailed += count($batchItems);
                $this->logger->error(
                    "Batch stok broadcast hatası ({$slug}): " . $e->getMessage(),
                    'StockManager'
                );
            }
        }

        return ['success' => $totalSuccess, 'failed' => $totalFailed];
    }

    /**
     * Belirli pazaryerine stok gönder (tüm 7 pazaryeri desteklenir)
     */
    public function syncStockToMarketplace(int $productId, int $marketplaceId): bool
    {
        $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
        if ($mpProduct === false || !(bool) $mpProduct['is_active']) {
            return false;
        }

        $product = $this->productModel->find($productId);
        if ($product === false) {
            return false;
        }

        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false) {
            return false;
        }

        $calculatedStock = $this->calculateMarketplaceStock($productId, $marketplaceId);
        $price = $this->priceEngine->calculatePrice($product, $marketplaceId);
        $slug = $marketplace['slug'];

        $mpProductId = (string) ($mpProduct['marketplace_product_id'] ?? '');
        $barcode = $mpProduct['marketplace_barcode'] ?? $mpProduct['marketplace_sku'] ?? $product['barcode'] ?? '';
        $sku = (string) (($mpProduct['marketplace_sku'] ?? '') ?: ($product['sku'] ?? ''));
        if ($barcode === '' && !($slug === 'oziygo' && $mpProductId !== '')) {
            $this->mpProductModel->setSyncError((int) $mpProduct['id'], 'Barkod bulunamadı');
            return false;
        }

        $items = [[
            'id'       => $mpProductId,
            'marketplace_product_id' => $mpProductId,
            'hbSku'    => $mpProductId,
            'sku'      => $sku,
            'barcode'   => $barcode,
            'quantity'  => max(0, $calculatedStock),
            'salePrice' => $price['sale_price'],
            'listPrice' => $price['list_price'],
        ]];

        $result = match ($slug) {
            'trendyol'    => $this->trendyolService->updateStock($items),
            'n11'         => $this->n11Service->updateStock($items),
            'hepsiburada' => $this->hepsiburadaService->updateStock($items),
            'pazarama'    => $this->pazaramaService->updateStock($items),
            'pttavm'      => $this->pttavmService->updateStock($items),
            'ciceksepeti' => $this->ciceksepetiService->updateStock($items),
            'idefix'      => $this->idefixService->updateStock($items),
            'oziygo'      => $this->oziygoService->updateStock($items),
            'woocommerce' => $this->wooCommerce()->updateStock($items),
            default       => ['success' => 0, 'failed' => 1, 'errors' => [['error' => "Desteklenmeyen pazaryeri: {$slug}"]]],
        };

        if (($result['failed'] ?? 0) === 0) {
            $this->mpProductModel->updateLastSent(
                (int) $mpProduct['id'],
                $calculatedStock,
                $price['sale_price']
            );
            return true;
        }

        $errorMsg = !empty($result['errors']) ? ($result['errors'][0]['error'] ?? 'Bilinmeyen hata') : 'Bilinmeyen hata';
        $this->mpProductModel->setSyncError((int) $mpProduct['id'], $errorMsg);
        return false;
    }

    /**
     * Pazaryerine gönderilecek stoğu hesapla (offset dahil)
     */
    public function calculateMarketplaceStock(int $productId, int $marketplaceId): int
    {
        $product = $this->productModel->find($productId);
        $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);

        if ($product === false || $mpProduct === false) {
            return 0;
        }

        $realStock = (int) $product['stock_qty'];
        $offset = (int) ($mpProduct['stock_offset'] ?? 0);

        return max(0, $realStock - $offset);
    }

    /**
     * Pazaryeri slug'ını getir
     */
    private function getMarketplaceSlug(int $marketplaceId): string
    {
        $mp = $this->marketplaceModel->find($marketplaceId);
        return $mp !== false ? $mp['slug'] : 'unknown';
    }

    /**
     * Child ürünün parent'a stok propagasyonu.
     * Bir varyantın stoğu değiştiğinde parent'ın toplam stokunu günceller.
     * Ayrıca parent'ın stokunu da tüm pazaryerlerine yayınlar.
     */
    private function syncParentStock(int $productId): void
    {
        $product = $this->productModel->find($productId);
        if ($product === false) {
            return;
        }

        // Bu ürün bir child mı? (parent_id var mı?)
        $parentId = $product['parent_id'] ?? null;
        if ($parentId === null) {
            return; // Parent ürün veya bağımsız ürün — propagasyon gerekmez
        }

        $parentId = (int) $parentId;
        $parent = $this->productModel->find($parentId);
        if ($parent === false) {
            return;
        }

        // Pazaryerinden içe aktarılmış grupta ana ürün de satılan bir varyanttır (kendi ilanı ve kendi
        // stoğu var): varyantların toplamı ona yazılıp yayınlanırsa pazaryerindeki ilanın stoğu bozulur.
        if ($this->productModel->parentKeepsOwnStock($parentId)) {
            return;
        }

        // Parent'ın toplam stokunu child'lardan hesapla
        $summary = $this->productModel->getVariantSummary($parentId);
        $newParentStock = $summary['total_stock'];
        $oldParentStock = (int) ($parent['stock_qty'] ?? 0);

        if ($newParentStock !== $oldParentStock) {
            $this->productModel->updateStockAtomic($parentId, $newParentStock);

            $this->logger->info(
                "Parent stok güncellendi: #{$parentId} ({$oldParentStock} → {$newParentStock})",
                'StockManager'
            );

            // Parent'ın stokunu da pazaryerlerine yayınla
            try {
                $this->broadcastStock($parentId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Parent stok broadcast hatası (#{$parentId}): " . $e->getMessage(),
                    'StockManager'
                );
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // TOPLU STOK İŞLEMLERİ (Phase 4)
    // ═══════════════════════════════════════════════════════

    /**
     * Toplu stok güncelleme (birden fazla ürün)
     * Her ürün bağımsız transaction'da işlenir — bir ürünün hatası diğerlerini etkilemez.
     * broadcastStock izole çalışır — hatası stok güncelleme sonucunu etkilemez.
     *
     * @param array<int, array{product_id: int, qty: int}> $items
     * @return array{success: int, failed: int, errors: array<int, array{product_id: int, error: string}>}
     */
    public function bulkAdjustment(array $items, string $note, int $userId): array
    {
        $success = 0;
        $failed = 0;
        $errors = [];
        $changedProductIds = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);

            if ($productId === 0) {
                $failed++;
                $errors[] = ['product_id' => 0, 'error' => 'Geçersiz ürün ID'];
                continue;
            }

            if ($qty === 0) {
                $failed++;
                $errors[] = ['product_id' => $productId, 'error' => 'Miktar sıfır olamaz'];
                continue;
            }

            try {
                $this->db->transaction(function () use ($productId, $qty, $note, $userId): void {
                    $product = $this->productModel->findForUpdate($productId);
                    if ($product === false) {
                        throw new RuntimeException("Ürün bulunamadı: #{$productId}");
                    }

                    $currentStock = (int) $product['stock_qty'];
                    $newStock = $currentStock + $qty;

                    if ($newStock < 0) {
                        throw new RuntimeException(
                            "Stok negatife düşemez. Mevcut: {$currentStock}, İşlem: {$qty}"
                        );
                    }

                    $this->productModel->updateStockAtomic($productId, $newStock);

                    $movementType = $qty > 0 ? 'bulk_in' : 'bulk_out';

                    $this->stockMovementModel->record(
                        productId: $productId,
                        movementType: $movementType,
                        source: 'toplu',
                        qtyChange: $qty,
                        qtyBefore: $currentStock,
                        qtyAfter: $newStock,
                        note: $note,
                        userId: $userId
                    );
                });

                $success++;
                $changedProductIds[] = $productId;

            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['product_id' => $productId, 'error' => $e->getMessage()];
                $this->logger->error(
                    "Toplu stok hatası (ürün #{$productId}): " . $e->getMessage(),
                    'BulkStock'
                );
            }
        }

        // broadcastStock — toplu batch olarak gönder (7N yerine 7 API çağrısı)
        try {
            $this->batchBroadcastStock($changedProductIds);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Toplu stok sonrası batch broadcast hatası: " . $e->getMessage(),
                'BulkStock'
            );
        }

        return ['success' => $success, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * CSV dosyasından toplu stok güncelleme
     * Beklenen CSV formatı: product_id;qty veya sku;qty (ilk satır başlık)
     *
     * @return array{success: int, failed: int, errors: array}
     */
    public function bulkAdjustmentFromCsv(string $filePath, string $delimiter, int $userId): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("CSV dosyası okunamıyor: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("CSV dosyası açılamıyor: {$filePath}");
        }

        $items = [];
        $lineNo = 0;
        $errors = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNo++;

            // İlk satır başlık — atla
            if ($lineNo === 1) {
                continue;
            }

            // Boş satır atla
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                continue;
            }

            if (count($row) < 2) {
                $errors[] = ['product_id' => 0, 'error' => "Satır {$lineNo}: Yetersiz kolon"];
                continue;
            }

            $identifier = trim($row[0]);
            $qty = (int) trim($row[1]);

            // Identifier: sayısal ise product_id, değilse SKU olarak ara
            $productId = 0;
            if (is_numeric($identifier)) {
                $productId = (int) $identifier;
            } else {
                $product = $this->productModel->findBySku($identifier);
                if ($product !== false) {
                    $productId = (int) $product['id'];
                } else {
                    $errors[] = ['product_id' => 0, 'error' => "Satır {$lineNo}: SKU bulunamadı: {$identifier}"];
                    continue;
                }
            }

            $items[] = ['product_id' => $productId, 'qty' => $qty];
        }

        fclose($handle);

        $result = $this->bulkAdjustment($items, 'CSV toplu stok güncelleme', $userId);

        // CSV parse hatalarını da ekle
        $result['errors'] = array_merge($errors, $result['errors']);
        $result['failed'] += count($errors);

        return $result;
    }
}
