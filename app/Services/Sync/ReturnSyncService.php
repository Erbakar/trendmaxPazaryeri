<?php
/**
 * OzyConnect Panel — ReturnSyncService
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
use App\Models\Marketplace;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\SyncLog;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\N11Service;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\PttavmService;
use App\Services\Marketplace\CiceksepetiService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Marketplace\IdefixService;

final class ReturnSyncService
{
    private ?array $marketplacesConfig = null;

    public function __construct(
        private readonly ReturnRequest $returnModel,
        private readonly Marketplace $marketplaceModel,
        private readonly Order $orderModel,
        private readonly SyncLog $syncLogModel,
        private readonly TrendyolService $trendyolService,
        private readonly N11Service $n11Service,
        private readonly PazaramaService $pazaramaService,
        private readonly PttavmService $pttavmService,
        private readonly CiceksepetiService $ciceksepetiService,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly IdefixService $idefixService,
        private readonly Logger $logger,
        private readonly Database $db,
        private readonly \App\Core\TenantContext $tenant,
    ) {}

    /**
     * Tüm aktif pazaryerlerinden iadeleri çek
     *
     * @param bool $fullSync true=tüm geçmiş iadeleri çek, false=son 30 gün
     * @param callable|null $onProgress fn(string $event, string $slug, string $displayName, array $result = [])
     */
    public function syncAll(string $triggeredBy = 'manual', bool $fullSync = false, ?callable $onProgress = null): array
    {
        // Faz 4 — FAIL-CLOSED + entitlement: TenantContext zorunlu, yalnız aktif satın alınan pazaryerleri.
        $storeId = $this->tenant->currentStoreId();
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
            $slug = $mp['slug'] ?? '';
            if (!in_array($slug, ['trendyol', 'n11', 'pazarama', 'ciceksepeti', 'hepsiburada', 'pttavm', 'idefix', 'oziygo'], true)) {
                continue;
            }

            try {
                // Credential kontrolü - encrypted key yoksa atla
                if (!$this->hasRequiredCredentials($slug, $mp)) {
                    $this->logger->info("İade sync atlandı - {$slug} credential eksik", 'ReturnSync');
                    if ($onProgress) {
                        $onProgress('skip', $slug, $mp['display_name'], ['reason' => 'credential_missing']);
                    }
                    continue;
                }

                if ($onProgress) {
                    $onProgress('start', $slug, $mp['display_name']);
                }
                
                $mpResult = $this->syncMarketplace((int) $mp['id'], $slug, $triggeredBy, $fullSync);
                $results['fetched']   += $mpResult['fetched'] ?? 0;
                $results['processed'] += $mpResult['processed'] ?? 0;
                $results['skipped']   += $mpResult['skipped'] ?? 0;
                $results['failed']    += $mpResult['failed'] ?? 0;
                $results['errors']     = array_merge($results['errors'], $mpResult['errors'] ?? []);

                if ($onProgress) {
                    $onProgress('done', $slug, $mp['display_name'], $mpResult);
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'marketplace' => $slug,
                    'error' => $e->getMessage(),
                ];
                if ($onProgress) {
                    $onProgress('error', $slug, $mp['display_name'], ['error' => $e->getMessage()]);
                }
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
            'idefix' => $hasApiKey,
            'oziygo' => $hasApiKey,
            default => false,
        };
    }

    /**
     * Belirli pazaryerinden iadeleri çek ve işle
     *
     * @return array{fetched: int, processed: int, skipped: int, failed: int, errors: array}
     */
    public function syncMarketplace(int $marketplaceId, string $slug, string $triggeredBy = 'manual', bool $fullSync = false, int $days = 0): array
    {
        $logId = $this->syncLogModel->start($marketplaceId, 'returns', $triggeredBy);

        $result = [
            'fetched'   => 0,
            'processed' => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        try {
            $rawReturns = match ($slug) {
                'n11'         => $this->fetchAllN11Returns($fullSync, $days),
                'trendyol'    => $this->fetchAllTrendyolReturns($fullSync, $days),
                'pazarama'    => $this->fetchAllPazaramaReturns($fullSync, $days),
                'ciceksepeti' => $this->ciceksepetiService->fetchReturns([
                    'startDate' => date('Y-m-d', strtotime('-' . ($fullSync ? $this->getReturnHistoryDays($slug) : max($days, 30)) . ' days')),
                    'endDate'   => date('Y-m-d'),
                ]),
                'hepsiburada' => $this->fetchAllHepsiburadaReturns($fullSync, $days),
                'idefix'      => $this->idefixService->fetchReturns([
                    'startDate' => date('Y-m-d', strtotime('-' . ($fullSync ? $this->getReturnHistoryDays($slug) : max($days, 30)) . ' days')),
                    'endDate'   => date('Y-m-d'),
                ]),
                'pttavm'      => $this->pttavmService->fetchReturns([
                    'startDate' => date('Y-m-d', strtotime('-' . ($fullSync ? $this->getReturnHistoryDays($slug) : max($days, 30)) . ' days')),
                    'endDate'   => date('Y-m-d'),
                ]),
                'oziygo'      => [],
                default    => [],
            };

            $result['fetched'] = count($rawReturns);

            // API çağrıları uzun sürebilir (özellikle Trendyol ~30sn),
            // MySQL bağlantısı timeout olabilir → reconnect
            $this->db->reconnect();

            foreach ($rawReturns as $returnData) {
                try {
                    $processed = $this->processReturn($marketplaceId, $slug, $returnData);
                    if ($processed) {
                        $result['processed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = [
                        'return_id' => $returnData['marketplace_return_id'] ?? 'unknown',
                        'error'     => $e->getMessage(),
                    ];
                    $this->logger->error("İade işleme hatası: " . $e->getMessage(), 'ReturnSync');
                }
            }

            $status = $result['failed'] > 0 ? 'partial' : 'success';
            $this->syncLogModel->finish($logId, $status, $result['fetched'], $result['processed'], $result['failed'], $result['errors']);

        } catch (\Throwable $e) {
            $this->syncLogModel->finish($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            $this->logger->error("İade sync hatası ({$slug}): " . $e->getMessage(), 'ReturnSync');
            throw $e;
        }

        return $result;
    }

    private function marketplaceConfig(string $slug): array
    {
        if ($this->marketplacesConfig === null) {
            $path = dirname(__DIR__, 3) . '/config/marketplaces.php';
            $this->marketplacesConfig = is_file($path) ? require $path : [];
        }

        $config = $this->marketplacesConfig[$slug] ?? [];
        return is_array($config) ? $config : [];
    }

    private function getReturnHistoryDays(string $slug): int
    {
        $api = $this->marketplaceConfig($slug)['api'] ?? [];
        $days = (int) ($api['return_history_days'] ?? 365);

        return $days > 0 ? $days : 365;
    }

    /**
     * Tek iade kaydını işle (upsert)
     *
     * @param array<string, mixed> $returnData Normalize edilmiş iade verisi
     * @return bool True = yeni/güncellendi, False = değişiklik yok (skip)
     */
    private function processReturn(int $marketplaceId, string $slug, array $returnData): bool
    {
        $mpReturnId = $returnData['marketplace_return_id'] ?? '';
        if ($mpReturnId === '') {
            return false;
        }

        // Yerel sipariş eşleştirmesi
        $orderId = $this->matchOrder($marketplaceId, $returnData);

        $data = $returnData;
        $data['order_id'] = $orderId;

        // Ürün SKU'su çıkar
        if (empty($data['product_sku'])) {
            $items = $data['items'] ?? [];
            if (!empty($items[0]['merchant_sku'])) {
                $data['product_sku'] = $items[0]['merchant_sku'];
            }
        }

        // Ürün adı yoksa items'dan çıkar
        if (empty($data['product_name'])) {
            $items = $data['items'] ?? [];
            if (!empty($items[0]['product_name'])) {
                $data['product_name'] = $items[0]['product_name'];
            }
        }

        $data = $this->enrichReturnProductData($marketplaceId, $data);

        $result = $this->returnModel->upsertFromApi($marketplaceId, $mpReturnId, $data);

        return true;
    }

    /**
     * Marketplace order ID'si ile yerel order eşleştir
     */
    /**
     * Some marketplaces return claim rows without product names. Fill them from
     * the local order or marketplace product mapping so existing rows stay useful.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function enrichReturnProductData(int $marketplaceId, array $data): array
    {
        $raw = is_array($data['raw_data'] ?? null) ? $data['raw_data'] : [];

        $skuCandidates = array_values(array_filter(array_unique(array_map('strval', [
            $data['product_sku'] ?? '',
            $raw['MerchantSku'] ?? '',
            $raw['merchantSku'] ?? '',
            $raw['sku'] ?? '',
            $raw['barcode'] ?? '',
        ])), fn(string $value): bool => trim($value) !== ''));

        if (empty($data['product_sku']) && $skuCandidates !== []) {
            $data['product_sku'] = $skuCandidates[0];
        }

        if (empty($data['reason'])) {
            $data['reason'] = (string) ($raw['Reason'] ?? $raw['reason'] ?? $raw['claimReason'] ?? $raw['explanation'] ?? '');
        }
        $data['reason'] = $this->hepsiburadaReasonLabel((string) $data['reason']);
        if (empty($data['reason'])) {
            $data['reason'] = $this->hepsiburadaClaimTypeLabel((string) ($raw['claimType'] ?? ''));
        }

        if (empty($data['cargo_company']) && !empty($raw['Deliveries'][0]['CargoCompany']['Name'])) {
            $data['cargo_company'] = (string) $raw['Deliveries'][0]['CargoCompany']['Name'];
        }
        if (empty($data['cargo_tracking_no']) && !empty($raw['Deliveries'][0]['TrackingNumber'])) {
            $data['cargo_tracking_no'] = (string) $raw['Deliveries'][0]['TrackingNumber'];
        }
        if (empty($data['image_url']) && !empty($raw['Reports'][0]['ImageUrl'])) {
            $data['image_url'] = (string) $raw['Reports'][0]['ImageUrl'];
        }

        if (empty($data['product_name']) && !empty($data['order_id'])) {
            $item = $this->db->fetchOne(
                'SELECT name, sku FROM order_items
                  WHERE order_id = :order_id AND store_id = :store_id
                  ORDER BY id ASC LIMIT 1',
                ['order_id' => (int) $data['order_id'], 'store_id' => $this->tenant->currentStoreId()]
            );
            if ($item !== false) {
                $data['product_name'] = (string) ($item['name'] ?? '');
                if (empty($data['product_sku'])) {
                    $data['product_sku'] = (string) ($item['sku'] ?? '');
                }
            }
        }

        if (empty($data['product_name']) && $skuCandidates !== []) {
            $product = $this->findReturnProductBySku($marketplaceId, $skuCandidates);
            if ($product !== []) {
                $data['product_name'] = (string) ($product['product_name'] ?? '');
                if (empty($data['product_sku'])) {
                    $data['product_sku'] = (string) ($product['sku'] ?? '');
                }
                if (empty($data['image_url'])) {
                    $data['image_url'] = (string) ($product['image_url'] ?? '');
                }
            }
        }

        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === [] || empty($items[0]['product_name'])) {
            $items = [[
                'claim_line_item_id' => (string) ($raw['lineItemId'] ?? ''),
                'product_name'       => (string) ($data['product_name'] ?? ''),
                'merchant_sku'       => (string) ($data['product_sku'] ?? ''),
                'barcode'            => (string) ($raw['sku'] ?? ''),
                'reason'             => (string) ($data['reason'] ?? ''),
                'status'             => (string) ($data['status'] ?? ''),
                'status_label'       => (string) ($data['status_raw'] ?? $data['status'] ?? ''),
                'customer_note'      => (string) ($raw['explanation'] ?? ''),
                'image_url'          => (string) ($data['image_url'] ?? ''),
            ]];
        }
        $data['items'] = $items;

        return $data;
    }

    /**
     * @param array<int, string> $skuCandidates
     * @return array<string, mixed>
     */
    private function findReturnProductBySku(int $marketplaceId, array $skuCandidates): array
    {
        $placeholders = [];
        $barcodePlaceholders = [];
        $params = ['marketplace_id' => $marketplaceId];
        foreach ($skuCandidates as $idx => $sku) {
            $key = 'sku_' . $idx;
            $barcodeKey = 'barcode_' . $idx;
            $placeholders[] = ':' . $key;
            $barcodePlaceholders[] = ':' . $barcodeKey;
            $params[$key] = $sku;
            $params[$barcodeKey] = $sku;
        }

        $in = implode(',', $placeholders);
        $barcodeIn = implode(',', $barcodePlaceholders);
        $row = $this->db->fetchOne(
            "SELECT COALESCE(NULLIF(p.name, ''), NULLIF(mp.product_name, '')) as product_name,
                    COALESCE(NULLIF(p.sku, ''), NULLIF(mp.marketplace_sku, '')) as sku,
                    COALESCE(NULLIF(p.main_image, ''), JSON_UNQUOTE(JSON_EXTRACT(p.images_json, '$[0].url')), '') as image_url
             FROM marketplace_products mp
             LEFT JOIN products p ON p.id = mp.product_id AND p.store_id = :store_id2
             WHERE mp.marketplace_id = :marketplace_id
               AND mp.store_id = :store_id1
               AND (mp.marketplace_sku IN ({$in}) OR mp.marketplace_barcode IN ({$barcodeIn}))
             LIMIT 1",
            $params + ['store_id1' => $this->tenant->currentStoreId(), 'store_id2' => $this->tenant->currentStoreId()]
        );
        if ($row !== false && !empty($row['product_name'])) {
            return $row;
        }

        unset($params['marketplace_id']);
        $row = $this->db->fetchOne(
            "SELECT name as product_name, sku,
                    COALESCE(NULLIF(main_image, ''), JSON_UNQUOTE(JSON_EXTRACT(images_json, '$[0].url')), '') as image_url
             FROM products
             WHERE store_id = :store_id
               AND (sku IN ({$in}) OR barcode IN ({$barcodeIn}))
             LIMIT 1",
            $params + ['store_id' => $this->tenant->currentStoreId()]
        );

        return $row !== false ? $row : [];
    }

    private function hepsiburadaClaimTypeLabel(string $claimType): string
    {
        return match ($claimType) {
            'RenewProduct' => 'Ürün değişim talebi',
            'MissingInvoice' => 'Fatura talebi',
            'ExtraProduct' => 'Fazla ürün bildirimi',
            'WrongProduct' => 'Yanlış ürün',
            'DamagedProduct' => 'Hasarlı ürün',
            'ReturnProduct' => 'İade talebi',
            default => $claimType,
        };
    }

    private function hepsiburadaReasonLabel(string $reason): string
    {
        return match ($reason) {
            'UndeliveredProduct' => 'Ürün teslim edilemedi',
            'MissingPart' => 'Eksik parça',
            'ExtraProduct' => 'Fazla ürün bildirimi',
            'MissingInvoice' => 'Fatura talebi',
            'RenewProduct' => 'Ürün değişim talebi',
            'DamagedProduct' => 'Hasarlı ürün',
            'WrongProduct' => 'Yanlış ürün',
            default => $reason,
        };
    }

    private function matchOrder(int $marketplaceId, array $returnData): ?int
    {
        $mpOrderId = $returnData['marketplace_order_id'] ?? '';
        if ($mpOrderId === '') {
            return null;
        }

        $order = $this->orderModel->findByMarketplaceOrderId($marketplaceId, $mpOrderId);
        if ($order !== false) {
            return (int) $order['id'];
        }

        return null;
    }

    // ══════════════════════════════════════════════════
    // N11 İADE ÇEKİCİ
    // ══════════════════════════════════════════════════

    /**
     * N11'den iadeleri sayfa sayfa çek
     *
     * @param bool $fullSync true=tarih filtresi olmadan tümünü çek, false=son 30 gün
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllN11Returns(bool $fullSync = false, int $overrideDays = 0): array
    {
        $allReturns = [];
        $page = 0;

        $days = $overrideDays > 0 ? $overrideDays : ($fullSync ? $this->getReturnHistoryDays('n11') : 30);
        $filters = ['status' => 'ALL', 'page' => $page];

        $filters['startDate'] = date('d/m/Y', strtotime("-{$days} days"));
        $filters['endDate'] = date('d/m/Y');

        while (true) {
            $filters['page'] = $page;
            $response = $this->n11Service->fetchReturns($filters);

            $returns = $response['returns'] ?? [];
            if ($returns === []) {
                break;
            }

            array_push($allReturns, ...$returns);

            $pageCount = (int) ($response['pageCount'] ?? 1);
            $page++;

            if ($page >= $pageCount) {
                break;
            }
        }

        $this->logger->info("N11 iade çekildi: " . count($allReturns) . " kayıt", 'ReturnSync');

        return $allReturns;
    }

    // ══════════════════════════════════════════════════
    // TRENDYOL İADE ÇEKİCİ
    // ══════════════════════════════════════════════════

    /**
     * Trendyol'dan iadeleri sayfa sayfa çek
     *
     * @param bool $fullSync true=son 1 yıl, false=son 30 gün
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllTrendyolReturns(bool $fullSync = false, int $overrideDays = 0): array
    {
        $allReturns = [];
        $page = 0;
        $pageSize = 100;

        $days = $overrideDays > 0 ? $overrideDays : ($fullSync ? $this->getReturnHistoryDays('trendyol') : 365);
        $startDate = strtotime("-{$days} days") * 1000;
        $endDate = time() * 1000;

        while (true) {
            $response = $this->trendyolService->fetchReturnsResponse([
                'page'      => $page,
                'size'      => $pageSize,
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ]);

            $content = $response['content'] ?? [];
            if (!is_array($content) || $content === []) {
                break;
            }

            foreach ($content as $claim) {
                if (!is_array($claim)) {
                    continue;
                }
                $allReturns[] = $this->normalizeTrendyolReturn($claim);
            }

            $totalPages = (int) ($response['totalPages'] ?? 1);
            $page++;

            if ($page >= $totalPages) {
                break;
            }
        }

        $this->logger->info("Trendyol iade çekildi: " . count($allReturns) . " kayıt", 'ReturnSync');

        return $allReturns;
    }

    /**
     * Trendyol claim verisini normalize et (DB'ye yazılabilir formata çevir)
     *
     * @param array<string, mixed> $claim Ham Trendyol claim verisi
     * @return array<string, mixed>
     */
    private function normalizeTrendyolReturn(array $claim): array
    {
        $claimId = (string) ($claim['claimId'] ?? $claim['id'] ?? '');
        $orderNumber = (string) ($claim['orderNumber'] ?? '');
        $customerName = trim((string) ($claim['customerFirstName'] ?? '') . ' ' . (string) ($claim['customerLastName'] ?? ''));

        $items = [];
        $primaryReason = '';
        $primaryReasonCode = '';
        $statusRaw = 'Created';
        $imageUrl = '';

        foreach ($claim['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $orderLine = is_array($item['orderLine'] ?? null) ? $item['orderLine'] : [];

            foreach ($item['claimItems'] ?? [] as $claimItem) {
                if (!is_array($claimItem)) {
                    continue;
                }

                $reason = $claimItem['trendyolClaimItemReason']['name']
                    ?? $claimItem['customerClaimItemReason']['name']
                    ?? '';
                $reasonCode = $claimItem['trendyolClaimItemReason']['code']
                    ?? $claimItem['customerClaimItemReason']['code']
                    ?? '';
                $status = (string) ($claimItem['claimItemStatus']['name'] ?? 'Created');

                if ($primaryReason === '' && $reason !== '') {
                    $primaryReason = (string) $reason;
                    $primaryReasonCode = (string) $reasonCode;
                }
                if ($statusRaw === 'Created' && $status !== '') {
                    $statusRaw = $status;
                }

                $itemImageUrl = $this->extractImageUrl($claimItem) ?: $this->extractImageUrl($orderLine);
                if ($imageUrl === '' && $itemImageUrl !== '') {
                    $imageUrl = $itemImageUrl;
                }

                $items[] = [
                    'claim_line_item_id' => (string) ($claimItem['id'] ?? ''),
                    'product_name'       => (string) ($orderLine['productName'] ?? ''),
                    'merchant_sku'       => (string) ($orderLine['merchantSku'] ?? ''),
                    'barcode'            => (string) ($orderLine['barcode'] ?? ''),
                    'reason'             => (string) $reason,
                    'reason_code'        => (string) $reasonCode,
                    'status'             => $status,
                    'status_label'       => $status,
                    'customer_note'      => (string) ($claimItem['customerNote'] ?? ''),
                    'image_url'          => $itemImageUrl,
                    'customer_images'    => [],
                ];
            }
        }

        $claimDate = $this->formatTimestampMs($claim['claimDate'] ?? null);

        $statusMap = [
            'Created'           => 'created',
            'WaitingInAction'   => 'waiting_in_action',
            'WaitingFraudCheck' => 'waiting_fraud_check',
            'Accepted'          => 'approved',
            'Rejected'          => 'rejected',
            'Cancelled'         => 'cancelled',
            'Unresolved'        => 'unresolved',
            'InAnalysis'        => 'in_analysis',
        ];

        return [
            'marketplace_return_id'  => $claimId,
            'marketplace_order_id'   => $orderNumber,
            'customer_name'          => $customerName,
            'product_name'           => $items[0]['product_name'] ?? '',
            'product_sku'            => $items[0]['merchant_sku'] ?? '',
            'quantity'               => count($items),
            'status'                 => $statusMap[$statusRaw] ?? strtolower($statusRaw),
            'status_raw'             => $statusRaw,
            'reason'                 => $primaryReason,
            'reason_code'            => $primaryReasonCode,
            'refund_amount'          => 0,
            'cargo_company'          => (string) ($claim['cargoProviderName'] ?? ''),
            'cargo_tracking_no'      => (string) ($claim['cargoTrackingNumber'] ?? ''),
            'image_url'              => $imageUrl,
            'action_required'        => $statusRaw === 'WaitingInAction' ? 1 : 0,
            'request_date'           => $claimDate,
            'approved_date'          => null,
            'denied_date'            => null,
            'cancelled_date'         => null,
            'items'                  => $items,
            'raw_data'               => $claim,
            'created_at'             => $claimDate,
        ];
    }

    /**
     * Object'ten ilk image URL'ini çıkar
     */
    private function extractImageUrl(array $data): string
    {
        foreach (['imageUrl', 'productImage', 'images'] as $key) {
            $val = $data[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            if (is_array($val)) {
                foreach ($val as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                    if (is_array($item)) {
                        $url = $item['url'] ?? $item['imageUrl'] ?? '';
                        if (is_string($url) && trim($url) !== '') {
                            return trim($url);
                        }
                    }
                }
            }
        }
        return '';
    }

    private function formatTimestampMs(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', intdiv((int) $value, 1000));
        }
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Son sync bilgilerini getir
     *
     * @return array{last_sync: ?string, status: ?string}
     */
    public function getSyncStatus(): array
    {
        $logs = $this->syncLogModel->getRecent(1);
        $returnLogs = array_filter($logs, fn($l) => ($l['sync_type'] ?? '') === 'returns');
        $lastLog = reset($returnLogs);

        return [
            'last_sync' => $lastLog['finished_at'] ?? null,
            'status'    => $lastLog['status'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PAZARAMA İADE ÇEKİCİ
    // ═══════════════════════════════════════════════════════

    /**
     * Pazarama'dan iadeleri çek
     */
    private function fetchAllPazaramaReturns(bool $fullSync = false, int $overrideDays = 0): array
    {
        $allReturns = [];
        $page = 1;

        $days = $overrideDays > 0 ? $overrideDays : ($fullSync ? $this->getReturnHistoryDays('pazarama') : 7);
        $filters = [];
        $filters['start_date'] = date('Y-m-d', strtotime("-{$days} days"));
        $filters['end_date']   = date('Y-m-d');

        do {
            $filters['page']      = $page;
            $filters['page_size'] = 100;

            $refunds = $this->pazaramaService->fetchReturns($filters);
            if (!is_array($refunds) || $refunds === []) {
                break;
            }

            foreach ($refunds as $refund) {
                if (!is_array($refund)) {
                    continue;
                }
                $allReturns[] = $this->normalizePazaramaReturn($refund);
            }

            $page++;
        } while (count($refunds) >= 100);

        $this->logger->info("Pazarama iade çekildi: " . count($allReturns) . " kayıt", 'ReturnSync');

        return $allReturns;
    }

    /**
     * Pazarama iade verisini normalize et
     */
    private function normalizePazaramaReturn(array $refund): array
    {
        $refundId = (string) ($refund['refundId'] ?? $refund['id'] ?? '');
        $orderNumber = (string) ($refund['orderNumber'] ?? '');
        $customerName = trim((string) ($refund['customerName'] ?? $refund['buyerName'] ?? ''));

        $statusRaw = (int) ($refund['refundStatus'] ?? $refund['status'] ?? 0);
        $statusMap = [
            1 => 'created',
            2 => 'approved',
            3 => 'rejected',
            4 => 'cancelled',
            5 => 'rejected',    // Backoffice Tarafından Reddedildi
            6 => 'approved',    // Backoffice Tarafından Onaylandı
        ];
        $status = $statusMap[$statusRaw] ?? 'created';

        $reason = (string) ($refund['refundType'] ?? $refund['reason'] ?? $refund['refundReason'] ?? '');
        
        // refundAmount obje olarak gelebilir: {value: 303.47} veya düz float
        $rawAmount = $refund['refundAmount'] ?? $refund['amount'] ?? 0;
        $amount = is_array($rawAmount) ? (float) ($rawAmount['value'] ?? 0) : (float) $rawAmount;

        $productName = (string) ($refund['productName'] ?? '');
        $sku = (string) ($refund['productStockCode'] ?? $refund['merchantSku'] ?? $refund['barcode'] ?? '');

        $requestDate = $refund['refundDate'] ?? $refund['createdDate'] ?? $refund['requestDate'] ?? null;
        if (is_string($requestDate)) {
            // Pazarama Türkçe tarih formatı: "14 Ocak 2025 17:32"
            $months = ['Ocak'=>'01','Şubat'=>'02','Mart'=>'03','Nisan'=>'04','Mayıs'=>'05','Haziran'=>'06',
                       'Temmuz'=>'07','Ağustos'=>'08','Eylül'=>'09','Ekim'=>'10','Kasım'=>'11','Aralık'=>'12'];
            $parsed = $requestDate;
            foreach ($months as $tr => $num) {
                $parsed = str_replace($tr, $num, $parsed);
            }
            $ts = strtotime($parsed);
            if ($ts === false) {
                $ts = strtotime($requestDate);
            }
            $requestDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }

        return [
            'marketplace_return_id' => $refundId,
            'marketplace_order_id'  => $orderNumber,
            'customer_name'         => $customerName,
            'customer_email'        => (string) ($refund['customerEmail'] ?? ''),
            'customer_phone'        => (string) ($refund['customerPhoneNumber'] ?? ''),
            'product_name'          => $productName,
            'product_sku'           => $sku,
            'quantity'              => (int) ($refund['quantity'] ?? 1),
            'status'                => $status,
            'status_raw'            => (string) $statusRaw,
            'reason'                => $reason,
            'reason_code'           => '',
            'refund_amount'         => $amount,
            'cargo_company'         => (string) ($refund['returnCargoCompanyName'] ?? $refund['shipmentCompanyName'] ?? $refund['cargoCompany'] ?? ''),
            'cargo_tracking_no'     => (string) ($refund['returnShipmentCode'] ?? $refund['shipmentCode'] ?? $refund['cargoTrackingNumber'] ?? ''),
            'image_url'             => '',
            'action_required'       => $statusRaw === 1 ? 1 : 0,
            'request_date'          => $requestDate,
            'approved_date'         => $statusRaw === 2 ? $requestDate : null,
            'denied_date'           => $statusRaw === 3 ? $requestDate : null,
            'cancelled_date'        => $statusRaw === 4 ? $requestDate : null,
            'items'                 => [],
            'raw_data'              => $refund,
            'created_at'            => $requestDate,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // HEPSİBURADA İADE ÇEKİCİ
    // ═══════════════════════════════════════════════════════

    /**
     * Hepsiburada'dan iadeleri (claims) çek
     */
    private function fetchAllHepsiburadaReturns(bool $fullSync = false, int $overrideDays = 0): array
    {
        $allReturns = [];

        try {
            $filters = [];

            // Tarih filtresi — fullSync değilse sadece son N günü çek
            if (!$fullSync) {
                $days = $overrideDays > 0 ? $overrideDays : 7;
                $filters['begin_date'] = date('Y-m-d', strtotime("-{$days} days"));
                $filters['end_date']   = date('Y-m-d');
            }

            $rawClaims = $this->hepsiburadaService->fetchReturns($filters);

            foreach ($rawClaims as $claim) {
                if (!is_array($claim)) continue;
                $allReturns[] = $this->normalizeHepsiburadaReturn($claim);
            }
        } catch (\Throwable $e) {
            $this->logger->error("HB iade çekme hatası: " . $e->getMessage(), 'ReturnSync');
        }

        $this->logger->info("Hepsiburada iade çekildi: " . count($allReturns) . " kayıt", 'ReturnSync');

        return $allReturns;
    }

    /**
     * Hepsiburada claim verisini normalize et
     */
    private function normalizeHepsiburadaReturn(array $claim): array
    {
        $claimNumber = (string) ($claim['claimNumber'] ?? $claim['claimId'] ?? $claim['number'] ?? $claim['id'] ?? '');
        $orderNumber = (string) ($claim['orderNumber'] ?? $claim['orderId'] ?? '');
        $customerName = trim(($claim['customerFirstName'] ?? '') . ' ' . ($claim['customerLastName'] ?? ''));
        if (trim($customerName) === '') {
            $customerName = (string) ($claim['customerName'] ?? '');
        }

        $statusRaw = (string) ($claim['claimStatus'] ?? $claim['status'] ?? 'Open');
        $statusMap = [
            'Open'       => 'created',
            'NewRequest' => 'requested',
            'Accepted'   => 'approved',
            'Approved'   => 'approved',
            'Rejected'   => 'rejected',
            'Cancelled'  => 'cancelled',
            'Refunded'   => 'refunded',
            'Closed'     => 'completed',
        ];
        $status = $statusMap[$statusRaw] ?? strtolower($statusRaw);

        $reason = $this->hepsiburadaReasonLabel((string) ($claim['claimReason'] ?? $claim['Reason'] ?? $claim['reason'] ?? $claim['explanation'] ?? ''));
        if ($reason === '') {
            $reason = $this->hepsiburadaClaimTypeLabel((string) ($claim['claimType'] ?? ''));
        }
        $refundAmount = (float) ($claim['refundAmount'] ?? $claim['amount'] ?? 0);

        $items = [];
        $productName = (string) ($claim['productName'] ?? $claim['ProductName'] ?? '');
        $sku = (string) ($claim['MerchantSku'] ?? $claim['merchantSku'] ?? $claim['sku'] ?? $claim['barcode'] ?? '');
        foreach ($claim['claimItems'] ?? $claim['items'] ?? [] as $item) {
            if (!is_array($item)) continue;
            $itemName = (string) ($item['productName'] ?? $item['name'] ?? '');
            $itemSku = (string) ($item['merchantSku'] ?? $item['sku'] ?? $item['barcode'] ?? '');
            if ($productName === '' && $itemName !== '') $productName = $itemName;
            if ($sku === '' && $itemSku !== '') $sku = $itemSku;

            $items[] = [
                'product_name'  => $itemName,
                'merchant_sku'  => $itemSku,
                'barcode'       => (string) ($item['barcode'] ?? ''),
                'reason'        => (string) ($item['reason'] ?? $reason),
                'status'        => $status,
                'status_label'  => $statusRaw,
                'customer_note' => (string) ($item['customerNote'] ?? ''),
                'image_url'     => (string) ($item['imageUrl'] ?? ''),
            ];
        }
        if ($items === []) {
            $items[] = [
                'claim_line_item_id' => (string) ($claim['lineItemId'] ?? ''),
                'product_name'       => $productName,
                'merchant_sku'       => $sku,
                'barcode'            => (string) ($claim['sku'] ?? ''),
                'reason'             => $reason,
                'status'             => $status,
                'status_label'       => $statusRaw,
                'customer_note'      => (string) ($claim['explanation'] ?? ''),
                'image_url'          => (string) ($claim['Reports'][0]['ImageUrl'] ?? ''),
            ];
        }

        $requestDate = $claim['claimDate'] ?? $claim['createdDate'] ?? $claim['createDate'] ?? null;
        if (is_numeric($requestDate)) {
            $requestDate = date('Y-m-d H:i:s', intdiv((int) $requestDate, 1000));
        } elseif (is_string($requestDate)) {
            $ts = strtotime($requestDate);
            $requestDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }

        return [
            'marketplace_return_id' => $claimNumber,
            'marketplace_order_id'  => $orderNumber,
            'customer_name'         => $customerName,
            'product_name'          => $productName,
            'product_sku'           => $sku,
            'quantity'              => max(count($items), 1),
            'status'                => $status,
            'status_raw'            => $statusRaw,
            'reason'                => $reason,
            'reason_code'           => '',
            'refund_amount'         => $refundAmount,
            'cargo_company'         => (string) ($claim['cargoCompany'] ?? $claim['cargoProviderName'] ?? $claim['Deliveries'][0]['CargoCompany']['Name'] ?? ''),
            'cargo_tracking_no'     => (string) ($claim['cargoTrackingNumber'] ?? $claim['Deliveries'][0]['TrackingNumber'] ?? ''),
            'image_url'             => (string) ($claim['Reports'][0]['ImageUrl'] ?? ''),
            'action_required'       => in_array($statusRaw, ['Open', 'NewRequest'], true) ? 1 : 0,
            'request_date'          => $requestDate,
            'approved_date'         => null,
            'denied_date'           => null,
            'cancelled_date'        => null,
            'items'                 => $items,
            'raw_data'              => $claim,
            'created_at'            => $requestDate,
        ];
    }
}
