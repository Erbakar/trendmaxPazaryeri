<?php
/**
 * OzyConnect Panel — OrderSyncService
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

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Helpers\DateHelper;
use App\Models\Customer;
use App\Models\Marketplace;
use App\Models\MarketplaceProduct;
use App\Models\Order;
use App\Models\OrderItem;
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
use App\Services\StockManager;

final class OrderSyncService
{
    /** @var array<string, mixed>|null */
    private ?array $marketplacesConfig = null;

    public function __construct(
        private readonly Database $db,
        private readonly Order $orderModel,
        private readonly OrderItem $orderItemModel,
        private readonly Product $productModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly Marketplace $marketplaceModel,
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
        private readonly StockManager $stockManager,
        private readonly Customer $customerModel,
        private readonly Logger $logger,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Tüm aktif pazaryerlerinden siparişleri çek
     *
     * @param callable|null $onProgress fn(string $event, string $slug, string $displayName, array $result = [])
     */
    public function syncAll(string $triggeredBy = 'manual', ?callable $onProgress = null): array
    {
        // Faz 4 — FAIL-CLOSED: TenantContext kurulmadan sync BAŞLAMAZ.
        $storeId = $this->tenant->currentStoreId();
        // Yalnız mağazanın ENTITLEMENT'i olan (aktif) pazaryerleri senkronize edilir.
        $marketplaces = $this->marketplaceModel->getActiveForStore($storeId);

        if (empty($marketplaces)) {
            return [
                'fetched'   => 0,
                'processed' => 0,
                'skipped'   => 0,
                'failed'    => 0,
                'errors'    => [],
                'message'   => 'Aktif pazaryeri bulunamadı',
            ];
        }
        
        $results = [
            'fetched'   => 0,
            'processed' => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        foreach ($marketplaces as $mp) {
            try {
                // Credential kontrolü - encrypted key yoksa atla
                if (!$this->hasRequiredCredentials($mp['slug'], $mp)) {
                    $this->logger->info("Sipariş sync atlandı - {$mp['slug']} credential eksik", 'OrderSync');
                    if ($onProgress) {
                        $onProgress('skip', $mp['slug'], $mp['display_name'], ['reason' => 'credential_missing']);
                    }
                    continue;
                }

                if ($onProgress) {
                    $onProgress('start', $mp['slug'], $mp['display_name']);
                }
                
                $mpResult = $this->syncMarketplace((int) $mp['id'], $mp['slug'], $triggeredBy);
                $results['fetched']   += $mpResult['fetched'] ?? 0;
                $results['processed'] += $mpResult['processed'] ?? 0;
                $results['skipped']   += $mpResult['skipped'] ?? 0;
                $results['failed']    += $mpResult['failed'] ?? 0;
                $results['errors']     = array_merge($results['errors'], $mpResult['errors'] ?? []);

                if ($onProgress) {
                    $onProgress('done', $mp['slug'], $mp['display_name'], $mpResult);
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'marketplace' => $mp['slug'],
                    'error'       => $e->getMessage(),
                ];
                $this->logger->warning(
                    "Sync devam ediyor — {$mp['slug']} hatası: " . $e->getMessage(),
                    'OrderSync'
                );
                if ($onProgress) {
                    $onProgress('error', $mp['slug'], $mp['display_name'], ['error' => $e->getMessage()]);
                }
            }
        }

        return $results;
    }

    /**
     * Pazaryerinin gerekli credential'larının olup olmadığını kontrol et
     * Not: Tabloda credentials_json yok — api_key_encrypted, api_secret_encrypted
     * ve extra_config_json kullanılır. Decrypt işlemi servis tarafında yapılır.
     */
    private function hasRequiredCredentials(string $slug, array $mp): bool
    {
        $hasApiKey = !empty($mp['api_key_encrypted']);
        $hasApiSecret = !empty($mp['api_secret_encrypted']);

        return match ($slug) {
            'trendyol' => $hasApiKey && $hasApiSecret,
            'n11' => $hasApiKey && $hasApiSecret,
            'hepsiburada' => $hasApiSecret, // servis anahtari api_secret_encrypted alaninda tutulur
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
     * Tüm siparişleri silip baştan çek (force resync)
     */
    public function forceResyncAll(string $triggeredBy = 'manual'): array
    {
        // TENANT-GÜVENLİ: global TRUNCATE YOK — yalnızca AKTİF mağazanın verisi silinir.
        // (Eski kod tüm mağazaların orders/order_items/customers tablosunu TRUNCATE ediyordu;
        //  çok-kiracılı DB'de bu TÜM tenant'ları silerdi — D7 kritik düzeltme.)
        $storeId = $this->tenant->currentStoreId();
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0", []);
        $this->db->query("DELETE FROM order_items WHERE store_id = :sid", ['sid' => $storeId]);
        $this->db->query("DELETE FROM orders WHERE store_id = :sid", ['sid' => $storeId]);
        $this->db->query("DELETE FROM customers WHERE store_id = :sid", ['sid' => $storeId]);
        $this->db->query("DELETE FROM sync_logs WHERE sync_type = 'orders' AND store_id = :sid", ['sid' => $storeId]);
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1", []);

        $this->logger->info("Force resync: Mağaza #{$storeId} verileri temizlendi (tenant-scoped)", 'OrderSync');

        // Full resync uses each marketplace's configured history window.
        // Faz 4 — yalnız mağazanın entitlement'i olan pazaryerleri.
        $marketplaces = $this->marketplaceModel->getActiveForStore($storeId);
        $results = [
            'fetched'   => 0,
            'processed' => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        foreach ($marketplaces as $mp) {
            $mpResult = $this->syncMarketplaceFull(
                (int) $mp['id'],
                $mp['slug'],
                $triggeredBy,
                $this->getOrderHistoryDays($mp['slug'])
            );
            $results['fetched']   += $mpResult['fetched'] ?? 0;
            $results['processed'] += $mpResult['processed'] ?? 0;
            $results['skipped']   += $mpResult['skipped'] ?? 0;
            $results['failed']    += $mpResult['failed'] ?? 0;
            $results['errors']     = array_merge($results['errors'], $mpResult['errors'] ?? []);
        }

        return $results;
    }

    /**
     * Belirli pazaryerinden ayarlanan geçmiş siparişleri çek (force)
     */
    /**
     * Belirli bir tarih aralığı için ham siparişleri çek.
     *
     * Bu blok syncMarketplaceFull() içinden çıkarıldı ki hem tam koşum hem de
     * BLOK BLOK koşum (sihirbazın parçalı sipariş adımı) aynı mantığı kullansın.
     *
     * @return array<int, array<string, mixed>>
     */
    /** Parçalı sipariş çekiminde bir bloğun kaç gün olduğu */
    private const BLOCK_DAYS = 15;

    /**
     * Siparişleri BLOK BLOK çek — sihirbazın parçalı "Siparişler" adımı için.
     *
     * Tek istekte 60 günlük çekim zaman/bellek sınırına takılıp HTTP 500 veriyordu.
     * Bu metot yalnız TEK bir tarih bloğunu işler; istemci blokları sırayla çağırır,
     * böylece her istek kısa sürer ve ilerleme çubuğu gösterilebilir.
     *
     * @return array{blockIndex: int, totalBlocks: int, done: bool, rangeStart: string, rangeEnd: string, fetched: int, processed: int, skipped: int, failed: int, errors: array<int, array<string, string>>}
     */
    public function syncMarketplaceBlock(int $marketplaceId, string $slug, int $days, int $blockIndex): array
    {
        $days = $days > 0 ? $days : $this->getOrderHistoryDays($slug);
        $totalBlocks = max(1, (int) ceil($days / self::BLOCK_DAYS));
        $blockIndex  = max(0, $blockIndex);

        $result = [
            'blockIndex'  => $blockIndex,
            'totalBlocks' => $totalBlocks,
            'done'        => $blockIndex >= $totalBlocks - 1,
            'rangeStart'  => '',
            'rangeEnd'    => '',
            'fetched'     => 0,
            'processed'   => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'errors'      => [],
        ];

        if ($blockIndex >= $totalBlocks) {
            $result['done'] = true;
            return $result;
        }

        // En YENİ bloktan başla — kullanıcı önce güncel siparişleri görsün
        $now     = time();
        $endTs   = $now - ($blockIndex * self::BLOCK_DAYS * 86400);
        $startTs = max($now - ($days * 86400), $endTs - (self::BLOCK_DAYS * 86400));

        $result['rangeStart'] = date('d.m.Y', $startTs);
        $result['rangeEnd']   = date('d.m.Y', $endTs);

        // Geçmiş siparişler için pazaryerlerine stok GERİ BİLDİRİLMEZ
        $this->stockManager->setBroadcastEnabled(false);

        try {
            $rawOrders = $this->fetchOrdersForRange($slug, $startTs, $endTs, $days, $marketplaceId);
            $rawOrders = $this->deduplicateOrders($rawOrders);
            $result['fetched'] = count($rawOrders);

            foreach ($rawOrders as $orderData) {
                try {
                    if ($this->processOrder($marketplaceId, $orderData)) {
                        $result['processed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = [
                            'order_id' => (string) ($orderData['marketplace_order_id'] ?? 'bilinmiyor'),
                            'error'    => mb_substr($e->getMessage(), 0, 200),
                        ];
                    }
                }
            }
        } finally {
            $this->stockManager->setBroadcastEnabled(true);
        }

        $this->logger->info(
            sprintf(
                'Sipariş bloğu %d/%d (%s–%s): %d çekildi, %d işlendi',
                $blockIndex + 1,
                $totalBlocks,
                $result['rangeStart'],
                $result['rangeEnd'],
                $result['fetched'],
                $result['processed']
            ),
            'OrderSync'
        );

        return $result;
    }

    /**
     * Aynı siparişin farklı statülerle gelen kopyalarını teke indir —
     * en ileri statüyü koru.
     *
     * @param array<int, array<string, mixed>> $rawOrders
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateOrders(array $rawOrders): array
    {
        $statusPriority = [
            'beklemede' => 0, 'yeni' => 1, 'hazirlaniyor' => 2,
            'faturalandi' => 3, 'kargoda' => 4, 'teslim_noktasinda' => 5,
            'teslim_edildi' => 6, 'teslim_edilemedi' => 7,
            'iptal' => 8, 'iade' => 9, 'bolundu' => 10,
        ];

        $unique = [];
        foreach ($rawOrders as $order) {
            $key = (string) ($order['marketplace_order_id'] ?? '');
            if (!isset($unique[$key])) {
                $unique[$key] = $order;
                continue;
            }

            $mevcut = $statusPriority[$unique[$key]['status'] ?? ''] ?? -1;
            $yeni   = $statusPriority[$order['status'] ?? ''] ?? -1;
            if ($yeni > $mevcut) {
                $unique[$key] = $order;
            }
        }

        return array_values($unique);
    }

    /**
     * @param int $marketplaceId Yerel pazaryeri kimliği — HB'de yerelde kayıtlı sipariş numaralarını
     *                           bildirmek için (0 ise bildirilmez).
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrdersForRange(string $slug, int $startTs, int $endTs, int $days, int $marketplaceId = 0): array
    {
        $rawOrders = [];

        if ($slug === 'n11') {
            // N11: statü filtresi olmadan tarih bazlı tek geçişte çek (performans)
            // Eski yöntem: 7 statü × 5 blok = 35+ API çağrısı → ÇOK YAVAŞ
            // Yeni yöntem: tarih bloğu başına tek istek, tüm statüler dahil
            
            // 1. Aktif siparişler: tarih filtresi olmadan (tüm aktif olanlar)
            $blockOrders = $this->n11Service->fetchOrders([]);
            $rawOrders = array_merge($rawOrders, $blockOrders);
            
            // 2. Tarihe göre geçmiş siparişler: 14 günlük bloklar, statü filtresi YOK
            $blockStart = $startTs;
            while ($blockStart < $endTs) {
                $blockEnd = min($blockStart + (14 * 86400), $endTs);
                $blockOrders = $this->n11Service->fetchOrders([
                    'startDate' => $blockStart * 1000,
                    'endDate'   => $blockEnd * 1000,
                ]);
                $rawOrders = array_merge($rawOrders, $blockOrders);
                $blockStart = $blockEnd + 1;
            }
        } elseif ($slug === 'pazarama') {
            // Pazarama: max 30 günlük aralık kısıtlaması var, dilimlere böl
            $blockStart = $startTs;
            while ($blockStart < $endTs) {
                $blockEnd = min($blockStart + (29 * 86400), $endTs);
                $blockOrders = $this->pazaramaService->fetchOrders([
                    'startDate' => date('Y-m-d', $blockStart),
                    'endDate'   => date('Y-m-d', $blockEnd),
                ]);
                $rawOrders = array_merge($rawOrders, $blockOrders);
                $blockStart = $blockEnd + 86400;
            }
        } elseif ($slug === 'hepsiburada') {
            // Hepsiburada: tek sorguda çekilir. Uzun aralıkta (blok/tam koşum) servis durum
            // kapsamlarını tarihsiz çeker; yerelde olan siparişler bildirilir ki durum
            // güncellemesi için sipariş detayı yeniden istenmesin.
            $rawOrders = $this->hepsiburadaService->fetchOrders([
                'begin_date'      => date('Y-m-d H:i:s', $startTs),
                'end_date'        => date('Y-m-d H:i:s', $endTs),
                'known_order_ids' => $marketplaceId > 0 ? $this->getKnownMarketplaceOrderIds($marketplaceId) : [],
            ]);
        } elseif ($slug === 'pttavm') {
            // PttAVM: SOAP ile tek sorguda çekilir
            $blockStart = $startTs;
            $windowDays = $this->getOrderQueryWindowDays($slug, 39);
            while ($blockStart < $endTs) {
                $blockEnd = min($blockStart + ($windowDays * 86400), $endTs);
                $blockOrders = $this->pttavmService->fetchOrders([
                    'start_date' => date('Y-m-d', $blockStart),
                    'end_date'   => date('Y-m-d', $blockEnd),
                ]);
                $rawOrders = array_merge($rawOrders, $blockOrders);
                $blockStart = $blockEnd + 86400;
            }
        } elseif ($slug === 'ciceksepeti') {
            // ÇiçekSepeti: max 14 gün aralık — fetchOrders zaten dilimleme yapıyor
            $rawOrders = $this->ciceksepetiService->fetchOrders([
                'startDate' => date('Y-m-d', $startTs),
                'endDate'   => date('Y-m-d', $endTs),
            ]);
        } elseif ($slug === 'idefix') {
            // İdefix: OMS API üzerinden siparişler
            $rawOrders = $this->idefixService->fetchOrders([
                'startDate' => date('Y-m-d', $startTs),
                'endDate'   => date('Y-m-d', $endTs),
            ]);
        } elseif ($slug === 'amazon') {
            // Amazon SP-API: CreatedAfter ile tek sorguda çekilir
            $rawOrders = $this->amazonService->fetchOrders([
                'start_date' => gmdate('Y-m-d\TH:i:s\Z', $startTs),
            ]);
        } elseif ($slug === 'oziygo') {
            // OziyGO bridge yerel e-ticaret gecmisini tasir; ilk aktarimda
            // eski siparisleri kacirmamak icin tarih filtresi uygulama.
            $rawOrders = $this->oziygoService->fetchOrders([
                'limit' => 200,
            ]);
        } elseif ($slug === 'shopify') {
            $rawOrders = $this->shopifyService->fetchOrders(['days' => $days > 0 ? $days : 60]);
        } elseif ($slug === 'woocommerce') {
            $rawOrders = $this->wooCommerceService->fetchOrders(['days' => $days > 0 ? $days : 60]);
        } elseif ($slug === 'opencart') {
            $rawOrders = $this->openCartService->fetchOrders(['days' => $days > 0 ? $days : 60]);
        } else {
            // Trendyol: STATÜ FİLTRESİZ tek geçiş (n11'de kullanılan desen).
            // Eskiden 12 statü × 5 tarih bloğu = en az 60 API isteği tek HTTP
            // isteğinde yapılıyordu; 60 günlük çekim zaman/bellek sınırına takılıp
            // HTTP 500 veriyordu. Statü bilgisi zaten yanıtın içinde geliyor.
            $windowDays = $this->getOrderQueryWindowDays($slug, 14);

            $blockStart = $startTs;
            while ($blockStart < $endTs) {
                $blockEnd = min($blockStart + ($windowDays * 86400), $endTs);
                $filters = [
                    'startDate' => $blockStart * 1000,
                    'endDate'   => $blockEnd * 1000,
                ];
                $blockOrders = match ($slug) {
                    'trendyol' => $this->trendyolService->fetchOrders($filters),
                    default    => [],
                };
                $rawOrders = array_merge($rawOrders, $blockOrders);
                $blockStart = $blockEnd + 1;
            }
        }

        return $rawOrders;
    }

    public function syncMarketplaceFull(int $marketplaceId, string $slug, string $triggeredBy = 'manual', int $days = 0): array
    {
        $logId = $this->syncLogModel->start($marketplaceId, 'orders', $triggeredBy);

        // İlk kurulum: geçmiş siparişler için pazaryerlerine stok GERİ BİLDİRİLMEZ.
        // (O satışlar zaten pazaryerinde gerçekleşti; her sipariş için tüm
        //  pazaryerlerine stok göndermek ilk aktarımı dakikalarca sürdürüyordu:
        //  300 siparişte 600+ API çağrısı / 142 sn.)
        if ($triggeredBy === 'initial_import') {
            $this->stockManager->setBroadcastEnabled(false);
        }

        $result = [
            'fetched'   => 0,
            'processed' => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        try {
            $rawOrders = [];
            $now = time();
            $days = $days > 0 ? $days : $this->getOrderHistoryDays($slug);
            $startTs = $now - ($days * 86400);
            $endTs   = $now;

            $rawOrders = $this->fetchOrdersForRange($slug, $startTs, $endTs, $days, $marketplaceId);

            // Duplicate'ları kaldır — statü önceliğine göre en güncel olanı tut
            $statusPriority = [
                'beklemede' => 0, 'yeni' => 1, 'hazirlaniyor' => 2,
                'faturalandi' => 3, 'kargoda' => 4, 'teslim_noktasinda' => 5,
                'teslim_edildi' => 6, 'teslim_edilemedi' => 7,
                'iptal' => 8, 'iade' => 9, 'bolundu' => 10,
            ];
            $unique = [];
            foreach ($rawOrders as $order) {
                $key = $order['marketplace_order_id'] ?? '';
                if (!isset($unique[$key])) {
                    $unique[$key] = $order;
                } else {
                    // Daha ileri statüyü tut
                    $existingPrio = $statusPriority[$unique[$key]['status'] ?? ''] ?? -1;
                    $newPrio      = $statusPriority[$order['status'] ?? ''] ?? -1;
                    if ($newPrio > $existingPrio) {
                        $unique[$key] = $order;
                    }
                }
            }
            $rawOrders = array_values($unique);

            $result['fetched'] = count($rawOrders);

            foreach ($rawOrders as $orderData) {
                try {
                    $processed = $this->processOrder($marketplaceId, $orderData);
                    if ($processed) {
                        $result['processed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'order_id' => $orderData['marketplace_order_id'] ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ];
                    $this->logger->error("Sipariş hatası: " . $e->getMessage(), 'OrderSync');
                }
            }

            $status = $result['failed'] > 0 ? 'partial' : 'success';
            $this->syncLogModel->finish($logId, $status, $result['fetched'], $result['processed'], $result['failed'], $result['errors']);

        } catch (\Throwable $e) {
            $this->syncLogModel->finish($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            $this->logger->error("Force sync hatası ({$slug}): " . $e->getMessage(), 'OrderSync');
            throw $e;
        } finally {
            // Bayrağı her hâlükârda geri aç — aynı istekteki sonraki işlemler etkilenmesin
            $this->stockManager->setBroadcastEnabled(true);
        }

        return $result;
    }

    /**
     * Belirli pazaryerinden siparişleri çek ve işle
     *
     * @return array{fetched: int, processed: int, skipped: int, failed: int, errors: array}
     */
    public function syncMarketplace(int $marketplaceId, string $slug, string $triggeredBy = 'manual'): array
    {
        $logId = $this->syncLogModel->start($marketplaceId, 'orders', $triggeredBy);

        $result = [
            'fetched'   => 0,
            'processed' => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        try {
            // Son sync zamanını al
            $lastSync = $this->getLastSyncTime($marketplaceId);

            // Pazaryerinden TÜM statülerdeki siparişleri çek
            $rawOrders = [];
            $now = time();

            if ($lastSync !== null) {
                $startTs = max(0, (int)strtotime($lastSync) - 300);
                // N11 için: son sync'ten en az 15 gün öncesinden başla
                // (N11 API max 15 günlük veri döndürür)
                if ($slug === 'n11') {
                    $startTs = min($startTs, $now - (15 * 86400));
                }
                // Pazarama: max 1 aylık aralık, son 7 gün yeterli (performans)
                if ($slug === 'pazarama') {
                    $startTs = min($startTs, $now - (7 * 86400));
                }
                if ($slug === 'hepsiburada' && $triggeredBy === 'manual') {
                    $startTs = min($startTs, $now - (30 * 86400));
                }
            } else {
                $startTs = $now - ($this->getOrderHistoryDays($slug) * 86400);
            }
            $endTs = $now;


            // N11: statü filtresi olmadan tarih bazlı tek geçişte çek (performans)
            // Hepsiburada & Pazarama: kümeleme yok, tek sorguda çekilir
            if ($slug === 'n11') {
                // Aktif siparişler (tarih filtresi yok)
                $blockOrders = $this->n11Service->fetchOrders([]);
                $rawOrders = array_merge($rawOrders, $blockOrders);
                
                // Tarihe göre geçmiş siparişler: 14 günlük bloklar
                $blockStart = $startTs;
                while ($blockStart < $endTs) {
                    $blockEnd = min($blockStart + (14 * 86400), $endTs);
                    $blockOrders = $this->n11Service->fetchOrders([
                        'startDate' => $blockStart * 1000,
                        'endDate'   => $blockEnd * 1000,
                    ]);
                    $rawOrders = array_merge($rawOrders, $blockOrders);
                    $blockStart = $blockEnd + 1;
                }
            } elseif ($slug === 'hepsiburada') {
                // Saatli pencere: HB durum kapsamları kısa pencerede (cron) tarihli sorgulanır.
                // Yerelde olan siparişler bildirilir → durum güncellemesi için sipariş detayı yeniden çekilmez.
                $rawOrders = $this->hepsiburadaService->fetchOrders([
                    'begin_date'      => date('Y-m-d H:i:s', $startTs),
                    'end_date'        => date('Y-m-d H:i:s', $endTs),
                    'known_order_ids' => $this->getKnownMarketplaceOrderIds($marketplaceId),
                ]);
            } elseif ($slug === 'pazarama') {
                // Pazarama: max 30 günlük aralık kısıtlaması var, dilimlere böl
                $blockStart = $startTs;
                while ($blockStart < $endTs) {
                    $blockEnd = min($blockStart + (29 * 86400), $endTs);
                    $blockOrders = $this->pazaramaService->fetchOrders([
                        'startDate' => date('Y-m-d', $blockStart),
                        'endDate'   => date('Y-m-d', $blockEnd),
                    ]);
                    $rawOrders = array_merge($rawOrders, $blockOrders);
                    $blockStart = $blockEnd + 86400;
                }
            } elseif ($slug === 'pttavm') {
                $blockStart = $startTs;
                $windowDays = $this->getOrderQueryWindowDays($slug, 39);
                while ($blockStart < $endTs) {
                    $blockEnd = min($blockStart + ($windowDays * 86400), $endTs);
                    $blockOrders = $this->pttavmService->fetchOrders([
                        'start_date' => date('Y-m-d', $blockStart),
                        'end_date'   => date('Y-m-d', $blockEnd),
                    ]);
                    $rawOrders = array_merge($rawOrders, $blockOrders);
                    $blockStart = $blockEnd + 86400;
                }
            } elseif ($slug === 'ciceksepeti') {
                // ÇiçekSepeti: max 14 gün aralık — fetchOrders zaten dilimleme yapıyor
                $rawOrders = $this->ciceksepetiService->fetchOrders([
                    'startDate' => date('Y-m-d', $startTs),
                    'endDate'   => date('Y-m-d', $endTs),
                ]);
            } elseif ($slug === 'idefix') {
                // İdefix: OMS API üzerinden siparişler
                $rawOrders = $this->idefixService->fetchOrders([
                    'startDate' => date('Y-m-d', $startTs),
                    'endDate'   => date('Y-m-d', $endTs),
                ]);
            } elseif ($slug === 'amazon') {
                // Amazon SP-API: CreatedAfter ile tek sorguda çekilir
                $rawOrders = $this->amazonService->fetchOrders([
                    'start_date' => gmdate('Y-m-d\TH:i:s\Z', $startTs),
                ]);
            } elseif ($slug === 'oziygo') {
                // OziyGO siparisleri yerel DB'den gelir; tarih filtresi eski
                // kurulumlarda 2024 gibi gecmis siparisleri bos dondurur.
                $rawOrders = $this->oziygoService->fetchOrders([
                    'limit' => 200,
                ]);
            } elseif ($slug === 'shopify') {
                $rawOrders = $this->shopifyService->fetchOrders(['days' => 60]);
            } elseif ($slug === 'woocommerce') {
                $rawOrders = $this->wooCommerceService->fetchOrders(['days' => 60]);
            } elseif ($slug === 'opencart') {
                $rawOrders = $this->openCartService->fetchOrders(['days' => 60]);
            }

            // A status filter is optional: one pass includes every package status.
            if ($slug === 'trendyol') {
                $rawOrders = $this->fetchOrdersForRange($slug, $startTs, $endTs, 0, $marketplaceId);
            }

            // Duplicate'ları kaldır — statü önceliğine göre en güncel olanı tut
            $statusPriority = [
                'beklemede' => 0, 'yeni' => 1, 'hazirlaniyor' => 2,
                'faturalandi' => 3, 'kargoda' => 4, 'teslim_noktasinda' => 5,
                'teslim_edildi' => 6, 'teslim_edilemedi' => 7,
                'iptal' => 8, 'iade' => 9, 'bolundu' => 10,
            ];
            $unique = [];
            foreach ($rawOrders as $order) {
                $key = $order['marketplace_order_id'] ?? '';
                if (!isset($unique[$key])) {
                    $unique[$key] = $order;
                } else {
                    $existingPrio = $statusPriority[$unique[$key]['status'] ?? ''] ?? -1;
                    $newPrio      = $statusPriority[$order['status'] ?? ''] ?? -1;
                    if ($newPrio > $existingPrio) {
                        $unique[$key] = $order;
                    }
                }
            }
            $rawOrders = array_values($unique);

            $result['fetched'] = count($rawOrders);

            // API çağrıları uzun sürebilir — MySQL bağlantısı timeout olabilir → reconnect
            $this->db->reconnect();

            // Her siparişi işle
            foreach ($rawOrders as $orderData) {
                try {
                    $processed = $this->processOrder($marketplaceId, $orderData);
                    if ($processed) {
                        $result['processed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'order_id' => $orderData['marketplace_order_id'] ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ];

                    $this->logger->error(
                        "Sipariş işleme hatası: " . $e->getMessage(),
                        'OrderSync',
                        ['order_data' => $orderData['marketplace_order_id'] ?? '']
                    );
                }
            }

            $status = $result['failed'] > 0 ? 'partial' : 'success';
            $this->syncLogModel->finish($logId, $status, $result['fetched'], $result['processed'], $result['failed'], $result['errors']);

        } catch (\Throwable $e) {
            $this->syncLogModel->finish($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            $this->logger->error("Sipariş sync hatası ({$slug}): " . $e->getMessage(), 'OrderSync');
            throw $e;
        }

        return $result;
    }

    /**
     * Tek siparişi işle
     *
     * @param array<string, mixed> $orderData Normalize edilmiş sipariş verisi
     * @return bool True = yeni sipariş işlendi, False = zaten vardı (skip)
     */
    /**
     * Webhook'tan gelen TEK normalize siparişi işle (processOrder public sarmalayıcı).
     * Tenant (store) çağrıdan ÖNCE set edilmiş olmalı. Stok düşümü + çapraz-pazaryeri senkron processOrder içinde.
     */
    public function ingestWebhookOrder(int $marketplaceId, array $normalizedOrder): bool
    {
        return $this->processOrder($marketplaceId, $normalizedOrder);
    }

    private function processOrder(int $marketplaceId, array $orderData): bool
    {
        $mpOrderId = $orderData['marketplace_order_id'] ?? '';

        // Zaten var mı?
        $existing = $this->orderModel->findByMarketplaceOrderId($marketplaceId, $mpOrderId);
        if ($existing !== false) {
            // Do not regress a package when an older webhook/page arrives late.
            $isTrendyol = $this->marketplaceSlug($marketplaceId) === 'trendyol';
            if ($isTrendyol && !TrendyolOrderSnapshot::isCurrent($existing, $orderData)) return false;
            $updateData = $isTrendyol ? TrendyolOrderSnapshot::fields($orderData) : [];

            $orderData['status'] = $this->guardStatusRegression($marketplaceId, (string) $existing['status'], (string) ($orderData['status'] ?? ''));

            if ($existing['status'] !== $orderData['status']) {
                $updateData['status'] = $orderData['status'];
            }

            // Kargo bilgisi güncelle (yoksa)
            if (!empty($orderData['cargo_company']) && empty($existing['cargo_company'])) {
                $updateData['cargo_company']     = $orderData['cargo_company'];
                $updateData['cargo_tracking_no'] = $orderData['cargo_tracking_no'] ?? '';
                $updateData['cargo_sent_at']     = $orderData['cargo_sent_at'];
            }

            $updateData = array_replace(
                $updateData,
                $this->buildExistingOrderRepairData($existing, $orderData)
            );

            // Müşteri bağlantısı yoksa oluştur
            $orderDataWithMp = $orderData;
            $orderDataWithMp['marketplace_id'] = $marketplaceId;

            if (empty($existing['customer_id'])) {
                try {
                    $customerId = $this->customerModel->findOrCreateFromOrder($orderDataWithMp);
                    $updateData['customer_id'] = $customerId;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        "Müşteri eşleştirme hatası (sipariş #{$mpOrderId}): " . $e->getMessage(),
                        'OrderSync'
                    );
                }
            } else {
                $this->customerModel->repairFromOrder((int) $existing['customer_id'], $orderDataWithMp);
            }

            if (!empty($updateData)) {
                $this->orderModel->update((int) $existing['id'], $updateData);
            }

            $this->repairExistingOrderItems((int) $existing['id'], $marketplaceId, $orderData);

            // Status geçiş hookları — stok reserve/confirm/cancel
            $oldStatus = $existing['status'];
            $newStatus = $orderData['status'];
            if ($oldStatus !== $newStatus) {
                $localOrderId = (int) $existing['id'];

                // Kargoya verildi → reserve→sale (audit)
                if ($newStatus === 'kargoda' && $oldStatus !== 'kargoda') {
                    try {
                        $this->stockManager->confirmOrderStock($localOrderId);
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            "Stok onay hatası (sipariş #{$mpOrderId}): " . $e->getMessage(),
                            'OrderSync'
                        );
                    }
                }

                // İptal → stoğu geri ekle + tüm pazaryerlerine broadcast
                if ($newStatus === 'iptal' && $oldStatus !== 'iptal') {
                    try {
                        $this->stockManager->cancelOrderReservation($localOrderId);
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            "İptal stok iade hatası (sipariş #{$mpOrderId}): " . $e->getMessage(),
                            'OrderSync'
                        );
                    }
                }
            }

            return false;
        }

        // Yeni sipariş — atomik kaydet
        return $this->db->transaction(function () use ($marketplaceId, $orderData, $mpOrderId): bool {
            // Müşteri bul veya oluştur
            $customerId = null;
            try {
                $orderDataWithMp = $orderData;
                $orderDataWithMp['marketplace_id'] = $marketplaceId;
                $customerId = $this->customerModel->findOrCreateFromOrder($orderDataWithMp);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Müşteri oluşturma hatası (sipariş #{$mpOrderId}): " . $e->getMessage(),
                    'OrderSync'
                );
            }

            // Order kaydet
            $orderId = $this->orderModel->create([
                'marketplace_id'        => $marketplaceId,
                'marketplace_order_id'  => $mpOrderId,
                'customer_id'           => $customerId,
                'status'                => $orderData['status'],
                'customer_name'         => $orderData['customer_name'] ?? '',
                'customer_email'        => $orderData['customer_email'] ?? '',
                'customer_phone'        => $orderData['customer_phone'] ?? '',
                'customer_id_no'        => $orderData['customer_id_no'] ?? '',
                'customer_tax_office'   => $orderData['customer_tax_office'] ?? '',
                'billing_address_json'  => json_encode($orderData['billing_address'] ?? null, JSON_UNESCAPED_UNICODE),
                'shipping_address_json' => json_encode($orderData['shipping_address'] ?? null, JSON_UNESCAPED_UNICODE),
                'subtotal'              => $orderData['subtotal'] ?? 0,
                'tax_total'             => $orderData['tax_total'] ?? 0,
                'discount_total'        => $orderData['discount_total'] ?? 0,
                'total_amount'          => $orderData['total_amount'] ?? 0,
                'cargo_company'         => $orderData['cargo_company'] ?? '',
                'cargo_tracking_no'     => $orderData['cargo_tracking_no'] ?? '',
                'cargo_sent_at'         => $orderData['cargo_sent_at'] ?? null,
                'notes'                 => $orderData['notes'] ?? '',
                'ordered_at'            => $orderData['ordered_at'] ?? date('Y-m-d H:i:s'),
                'raw_data_json'         => json_encode($orderData['raw_data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);

            // Order items kaydet + ürün eşleştirme
            $stockSkips = []; // order_item_id => stok düşülmeme nedeni
            foreach ($orderData['items'] ?? [] as $item) {
                $productId = $this->matchProduct($marketplaceId, $item);

                $orderItemId = $this->orderItemModel->create([
                    'order_id'               => $orderId,
                    'product_id'             => $productId,
                    'marketplace_item_id'    => $item['marketplace_item_id'] ?? null,
                    'sku'                    => $item['sku'] ?? '',
                    'name'                   => $item['name'] ?? '',
                    'qty'                    => $item['qty'] ?? 1,
                    'unit_price'             => $item['unit_price'] ?? 0,
                    'discount'               => $item['discount'] ?? 0,
                    'tax_rate'               => $item['tax_rate'] ?? 20,
                    'line_total'             => $item['line_total'] ?? 0,
                ]);

                $skipReason = $this->stockSkipReason($marketplaceId, $orderData, $productId);
                if ($skipReason !== null) {
                    $stockSkips[(int) $orderItemId] = $skipReason;
                }
            }

            // Stok rezerve et (pazaryerinde zaten iptal/iade olan ya da ilan bağlanmadan önce verilmiş kalemler hariç)
            $this->stockManager->reserveOrderStock($orderId, $stockSkips);

            $this->logger->info(
                "Yeni sipariş işlendi: #{$mpOrderId} → #{$orderId}",
                'OrderSync'
            );

            return true;
        });
    }

    /** Pazaryerinde stoğun zaten geri alındığı (satış gerçekleşmemiş) sipariş durumları. */
    private const NO_STOCK_STATUSES = ['iptal', 'iade'];

    /** @var array<int, string> marketplace_id => slug (süreç içi) */
    private array $slugCache = [];

    /** DB saati − PHP saati (sn, 15 dk'ya yuvarlı); null = henüz ölçülmedi. */
    private ?int $dbClockOffset = null;

    /**
     * Yeni siparişin bir kaleminde stok DÜŞÜLMEMELİ mi? Neden metni ya da null.
     *  - Sipariş pazaryerinde zaten iptal/iade: pazaryeri stoğu geri almıştır; durum değişmeyeceği için
     *    yerel iade hiç tetiklenmez → düşülürse stok kalıcı kaybolur (ve otomatik yayınla pazaryerine gider).
     *  - Ürünün bu pazaryerindeki ilanı siparişten SONRA bağlandı (içe aktarma / ilk eşleştirme): içe
     *    aktarılan stok bu satışı zaten içerir; ilk senkronda geçmiş siparişler stoğu ikinci kez düşürürdü.
     *
     * @param array<string, mixed> $orderData
     */
    private function stockSkipReason(int $marketplaceId, array $orderData, ?int $productId): ?string
    {
        if (in_array((string) ($orderData['status'] ?? ''), self::NO_STOCK_STATUSES, true)) {
            return 'Sipariş pazaryerinde zaten iptal/iade durumunda';
        }
        if ($productId === null) {
            return null;
        }

        $orderedAt = strtotime((string) ($orderData['ordered_at'] ?? ''));
        if ($orderedAt === false) {
            return null;
        }

        $link = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
        $linkedAt = $link !== false ? strtotime((string) ($link['created_at'] ?? '')) : false;
        if ($linkedAt === false) {
            return null;
        }

        // created_at DB saatiyle yazılır; sipariş zamanı PHP saat dilimindedir → aynı saate çevir
        if ($orderedAt + $this->dbClockOffset() < $linkedAt) {
            return 'Sipariş, ürünün pazaryeri ilanı bağlanmadan önce verilmiş (içe aktarılan stok bu satışı içeriyor)';
        }

        return null;
    }

    /** DB sunucu saati ile PHP saati arasındaki fark (saat dilimi farkı; sn). */
    private function dbClockOffset(): int
    {
        if ($this->dbClockOffset === null) {
            $offset = 0;
            try {
                $row = $this->db->fetchOne('SELECT NOW() AS db_now');
                $dbNow = strtotime((string) ($row['db_now'] ?? ''));
                if ($dbNow !== false) {
                    $offset = (int) (round(($dbNow - time()) / 900) * 900);
                }
            } catch (\Throwable) {
                $offset = 0;
            }
            $this->dbClockOffset = $offset;
        }

        return $this->dbClockOffset;
    }

    /**
     * Kendi mağaza platformlarında geri adım koruması. WooCommerce'te teslim durumu yoktur: panelden
     * "Kargoya ver" siparişi WooCommerce'te 'completed' yapar ve hemen gelen order.updated (ya da sonraki
     * senkron) 'completed' → 'teslim_edildi' eşlemesiyle yerel 'kargoda'yı 'teslim edildi'ye çevirirdi.
     * Yerel sipariş kargodayken gelen 'teslim_edildi' bu platformda 'kargoda' kalır (teslim panelden işaretlenir).
     */
    private function guardStatusRegression(int $marketplaceId, string $currentStatus, string $incomingStatus): string
    {
        if ($currentStatus === 'kargoda' && $incomingStatus === 'teslim_edildi'
            && $this->marketplaceSlug($marketplaceId) === 'woocommerce'
        ) {
            return $currentStatus;
        }

        return $incomingStatus;
    }

    private function marketplaceSlug(int $marketplaceId): string
    {
        if (!isset($this->slugCache[$marketplaceId])) {
            $row = $this->db->fetchOne('SELECT slug FROM marketplaces WHERE id = :id LIMIT 1', ['id' => $marketplaceId]);
            $this->slugCache[$marketplaceId] = $row !== false ? (string) ($row['slug'] ?? '') : '';
        }

        return $this->slugCache[$marketplaceId];
    }

    /**
     * Sipariş kalemindeki ürünü PIM'de eşleştir
     *
     * @param array<string, mixed> $item
     */
    /**
     * Existing HB orders created from package summary may miss customer, address,
     * amount and raw detail. Fill only empty/zero local fields with richer API data.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $orderData
     * @return array<string, mixed>
     */
    private function buildExistingOrderRepairData(array $existing, array $orderData): array
    {
        $update = [];

        foreach (['customer_name', 'customer_email', 'customer_phone', 'customer_id_no', 'customer_tax_office'] as $field) {
            if ($this->isBlankValue($existing[$field] ?? null) && !$this->isBlankValue($orderData[$field] ?? null)) {
                $update[$field] = $orderData[$field];
            }
        }

        foreach (['subtotal', 'tax_total', 'discount_total', 'total_amount'] as $field) {
            if ((float) ($existing[$field] ?? 0) <= 0 && (float) ($orderData[$field] ?? 0) > 0) {
                $update[$field] = $orderData[$field];
            }
        }

        if ($this->isBlankAddressJson($existing['billing_address_json'] ?? null) && $this->hasAddressData($orderData['billing_address'] ?? [])) {
            $update['billing_address_json'] = json_encode($orderData['billing_address'], JSON_UNESCAPED_UNICODE);
        }

        if ($this->isBlankAddressJson($existing['shipping_address_json'] ?? null) && $this->hasAddressData($orderData['shipping_address'] ?? [])) {
            $update['shipping_address_json'] = json_encode($orderData['shipping_address'], JSON_UNESCAPED_UNICODE);
        }

        if ((empty($existing['raw_data_json']) || (float) ($existing['total_amount'] ?? 0) <= 0) && !empty($orderData['raw_data'])) {
            $update['raw_data_json'] = json_encode($orderData['raw_data'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $update;
    }

    /**
     * @param array<string, mixed> $orderData
     */
    private function repairExistingOrderItems(int $orderId, int $marketplaceId, array $orderData): void
    {
        $items = $orderData['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            return;
        }

        // Yalnız ad + tutar taşıyan (kullanılabilir) gelen kalemler onarımda kullanılır. Durum
        // özetinden gelen boş kalemlerle mevcut kalemler SİLİNMEZ.
        $usableIncoming = array_values(array_filter(
            $items,
            fn(mixed $item): bool => is_array($item) && $this->isUsableItem($item)
        ));
        if ($usableIncoming === []) {
            return;
        }

        $existingItems = $this->orderItemModel->getByOrderId($orderId);
        $hasUsableItem = false;
        $existingLineIds = [];
        $existingByLineId = [];
        foreach ($existingItems as $existingItem) {
            if ($this->isUsableItem($existingItem)) {
                $hasUsableItem = true;
            }
            $lineId = trim((string) ($existingItem['marketplace_item_id'] ?? ''));
            if ($lineId !== '') {
                $existingLineIds[$lineId] = true;
                $existingByLineId[$lineId] = $existingItem;
            }
        }

        if ($hasUsableItem) {
            // Eksik kalem tamamlama: önceki sürümler çok kalemli siparişin yalnız bir kalemini
            // yazıyordu. Kalem kimliği bilinen ve yerelde olmayan kalemler eklenir. Mevcut
            // kalemlerin hiçbirinde kalem kimliği yoksa eşleme güvenilmez → ekleme yapılmaz.
            // Stok rezervi bu onarımda yapılmaz (sipariş daha önce rezerve edildi).
            if ($existingLineIds === []) {
                return;
            }

            $added = 0;
            foreach ($usableIncoming as $item) {
                $lineId = trim((string) ($item['marketplace_item_id'] ?? ''));
                if ($lineId === '') continue;
                if (isset($existingLineIds[$lineId])) {
                    if ($this->marketplaceSlug($marketplaceId) === 'trendyol') {
                        $fields = array_intersect_key($item, array_flip(['sku','name','qty','unit_price','discount','tax_rate','line_total']));
                        $this->orderItemModel->update((int)$existingByLineId[$lineId]['id'], $fields);
                    }
                    continue;
                }
                $this->createOrderItem($orderId, $marketplaceId, $item);
                $existingLineIds[$lineId] = true;
                $added++;
            }
            if ($added > 0) {
                $this->logger->info("Siparişe eksik {$added} kalem eklendi: #{$orderId}", 'OrderSync');
            }
            return;
        }

        $this->db->query('DELETE FROM order_items WHERE order_id = :order_id', ['order_id' => $orderId]);

        foreach ($usableIncoming as $item) {
            $this->createOrderItem($orderId, $marketplaceId, $item);
        }
    }

    /**
     * Kalem onarımda kullanılabilir mi (adı var ve tutarı sıfırdan büyük).
     *
     * @param array<string, mixed> $item
     */
    private function isUsableItem(array $item): bool
    {
        return !$this->isBlankValue($item['name'] ?? null) && (float) ($item['line_total'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function createOrderItem(int $orderId, int $marketplaceId, array $item): void
    {
        $this->orderItemModel->create([
            'order_id'            => $orderId,
            'product_id'          => $this->matchProduct($marketplaceId, $item),
            'marketplace_item_id' => $item['marketplace_item_id'] ?? null,
            'sku'                 => $item['sku'] ?? '',
            'name'                => $item['name'] ?? '',
            'qty'                 => $item['qty'] ?? 1,
            'unit_price'          => $item['unit_price'] ?? 0,
            'discount'            => $item['discount'] ?? 0,
            'tax_rate'            => $item['tax_rate'] ?? 20,
            'line_total'          => $item['line_total'] ?? 0,
        ]);
    }

    /**
     * Pazaryerinin AKTİF MAĞAZADA kayıtlı sipariş numaraları (HB durum güncellemesinde
     * gereksiz detay çağrısını önler). Okunamazsa boş liste — senkron bundan etkilenmez.
     *
     * @return array<int, string>
     */
    private function getKnownMarketplaceOrderIds(int $marketplaceId): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT marketplace_order_id FROM orders WHERE store_id = :sid AND marketplace_id = :mid',
                ['sid' => $this->tenant->currentStoreId(), 'mid' => $marketplaceId]
            );
        } catch (\Throwable) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['marketplace_order_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    private function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        $value = trim((string) $value);

        return $value === '' || $value === '-';
    }

    private function isBlankAddressJson(mixed $value): bool
    {
        if ($this->isBlankValue($value)) {
            return true;
        }

        $decoded = json_decode((string) $value, true);

        return !is_array($decoded) || !$this->hasAddressData($decoded);
    }

    private function hasAddressData(mixed $address): bool
    {
        if (!is_array($address)) {
            return false;
        }

        foreach (['name', 'address', 'city', 'district', 'postal_code', 'phone'] as $field) {
            if (!$this->isBlankValue($address[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function matchProduct(int $marketplaceId, array $item): ?int
    {
        $barcode = $item['barcode'] ?? '';
        $sku = $item['sku'] ?? '';

        // 1. Barkod ile eşleştir
        if ($barcode !== '') {
            $product = $this->productModel->findByBarcode($barcode);
            if ($product !== false) {
                return (int) $product['id'];
            }
        }

        // 2. Marketplace SKU ile eşleştir
        if ($sku !== '') {
            $mpProduct = $this->mpProductModel->findByMarketplaceSku($marketplaceId, $sku);
            if ($mpProduct !== false) {
                return (int) $mpProduct['product_id'];
            }
        }

        // 3. İç SKU ile eşleştir
        if ($sku !== '') {
            $product = $this->productModel->findBySku($sku);
            if ($product !== false) {
                return (int) $product['id'];
            }
        }

        $this->logger->warning(
            "Ürün eşleştirilemedi: barcode={$barcode}, sku={$sku}",
            'OrderSync'
        );

        return null;
    }

    /**
     * Son başarılı sync zamanını al
     */
    private function getLastSyncTime(int $marketplaceId): ?string
    {
        $log = $this->db->fetchOne(
            "SELECT started_at AS finished_at FROM sync_logs
             WHERE marketplace_id = :mid AND store_id = :sid AND sync_type = 'orders' AND status = 'success'
             ORDER BY started_at DESC LIMIT 1",
            ['mid' => $marketplaceId, 'sid' => $this->tenant->currentStoreId()]
        );

        return $log !== false ? $log['finished_at'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function marketplaceConfig(string $slug): array
    {
        if ($this->marketplacesConfig === null) {
            $this->marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        }

        $config = $this->marketplacesConfig[$slug] ?? [];
        return is_array($config) ? $config : [];
    }

    private function getOrderHistoryDays(string $slug): int
    {
        $config = $this->marketplaceConfig($slug);
        $days = (int) ($config['api']['order_history_days'] ?? match ($slug) {
            'trendyol' => 90,
            default => 365,
        });

        return max(1, $days);
    }

    private function getOrderQueryWindowDays(string $slug, int $fallback): int
    {
        $config = $this->marketplaceConfig($slug);
        $days = (int) ($config['api']['order_query_window_days'] ?? $fallback);

        return max(1, $days);
    }

    /**
     * @return array<int, string>
     */
    private function getTrendyolOrderStatuses(): array
    {
        return [
            'Awaiting',
            'Created',
            'Picking',
            'Invoiced',
            'Shipped',
            'AtCollectionPoint',
            'Delivered',
            'Cancelled',
            'UnDelivered',
            'Returned',
            'UnPacked',
            'UnSupplied',
        ];
    }
}
