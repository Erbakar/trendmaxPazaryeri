<?php
/**
 * OzyConnect Panel — TrendyolService (Tam Trendyol API Entegrasyonu)
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

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class TrendyolService extends AbstractMarketplace
{
    private const SLUG = 'trendyol';

    private string $apiKey = '';
    private string $apiSecret = '';
    private string $supplierId = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
        private readonly \App\Services\XmlStockLinks $stockLinks,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $this->baseUrl             = $this->config['base_url'] ?? 'https://api.trendyol.com/sapigw';
        $this->timeout             = $this->config['api']['timeout_seconds'] ?? 60;
        $this->maxRetries          = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs    = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond  = $this->config['api']['rate_limit_per_second'] ?? 10;
    }

    protected function beforeHttpRequest(string $method, string $url): void
    {
        (new TrendyolRequestLimiter())->wait($this->supplierId, $method, $url);
    }

    /**
     * API bilgilerini veritabanından yükle
     */
    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('Trendyol pazaryeri kaydı bulunamadı');
        }

        if (empty($marketplace['api_key_encrypted']) || empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('Trendyol API bilgileri henüz girilmemiş');
        }

        $this->apiKey    = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->apiSecret = $this->crypto->decrypt($marketplace['api_secret_encrypted']);

        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
        $this->supplierId = (string) ($this->extraConfig['supplier_id'] ?? '');

        if ($this->supplierId === '') {
            throw new RuntimeException('Trendyol Supplier ID (Mağaza ID) girilmemiş');
        }
    }

    /**
     * Auth header'ı oluştur (Basic Auth)
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        $token = base64_encode($this->apiKey . ':' . $this->apiSecret);
        return [
            "Authorization: Basic {$token}",
            "User-Agent: {$this->supplierId} - SelfIntegration",
            "x-agentname: OzyConnect",
            "x-executor-user: oziyweb@hotmail.com",
        ];
    }

    /**
     * Endpoint URL'ini oluştur
     */
    private function buildUrl(string $endpoint, bool $isGlobal = false): string
    {
        if ($isGlobal) {
            return $this->baseUrl . $endpoint;
        }

        $path = str_replace('{supplierId}', $this->supplierId, $endpoint);
        return $this->baseUrl . $path;
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();

            $url = $this->buildUrl(
                $this->config['endpoints']['orders'] ?? "/order/sellers/{$this->supplierId}/v2/orders"
            );
            $url .= '?page=0&size=1';

            $response = $this->httpGet($url, $this->authHeaders());

            return [
                'success' => true,
                'message' => 'Trendyol bağlantısı başarılı!',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $e->getMessage(),
            ];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $allOrders = [];
        $page = 0;
        $size = max(1, min(200, (int) ($this->config['api']['page_size'] ?? 200)));

        $queryParams = [
            'orderByField'     => $filters['orderByField'] ?? 'PackageLastModifiedDate',
            'orderByDirection' => $filters['orderByDirection'] ?? 'DESC',
        ];

        if (!empty($filters['status'])) {
            $queryParams['status'] = $filters['status'];
        }

        if (!empty($filters['startDate'])) {
            $queryParams['startDate'] = $filters['startDate'];
        }

        if (!empty($filters['endDate'])) {
            $queryParams['endDate'] = $filters['endDate'];
        }

        $this->startPaging('ty.orders');

        do {
            $queryParams['page'] = $page;
            $queryParams['size'] = $size;

            $url = $this->buildUrl(
                $this->config['endpoints']['orders'] ?? "/order/sellers/{$this->supplierId}/v2/orders"
            );
            $url .= '?' . http_build_query($queryParams);

            $response = $this->httpGet($url, $this->authHeaders());

            if (!isset($response['content']) || !is_array($response['content'])) {
                throw new RuntimeException('Trendyol sipariş yanıtı doğrulanamadı');
            }
            $orders = $response['content'];
            $totalPages = $response['totalPages'] ?? 1;

            foreach ($orders as $order) {
                $allOrders[] = $this->normalizeOrder($order);
            }

            $page++;
            if ($page < $totalPages && !$this->keepPaging('ty.orders', $page, $orders)) {
                throw new RuntimeException('Trendyol sipariş taraması tamamlanamadı; tekrar denenecek');
            }
        } while ($page < $totalPages);

        $this->logger->info(
            "Trendyol'dan {$page} sayfa, " . count($allOrders) . " sipariş çekildi",
            'TrendyolService'
        );

        return $allOrders;
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();

        $endpoint = str_replace(
            '{shipmentPackageId}',
            $orderId,
            $this->config['endpoints']['shipment_packages'] ?? "/suppliers/{$this->supplierId}/shipment-packages/{$orderId}"
        );

        $body = array_merge(['status' => $status], $extra);

        $url = $this->buildUrl($endpoint);
        $this->httpPut($url, $body, $this->authHeaders());

        return true;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        return $this->updateOrderStatus($orderId, 'Shipped', [
            'trackingNumber' => $trackingNo,
            'cargoCompany'   => $cargoCompany,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ (Panel → Trendyol)
    // ═══════════════════════════════════════════════════════

    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['products']
                ?? "/product/sellers/{$this->supplierId}/products/approved"
        );
        // Ürün V2: onaylı/onaysız ayrı uç noktalar — "approved" parametresi kaldırıldı.
        $url .= '?' . http_build_query($this->productQuery($page, $size));

        $response = $this->httpGet($url, $this->authHeaders());
        $this->writePageToken($page, $size, $response['nextPageToken'] ?? null);

        return $response;
    }

    /** API totals are content counts for approved products, barcode counts for drafts. */
    public function fetchCatalogStatusCounts(): array
    {
        $this->loadCredentials();
        $counts = [];
        foreach (['approved' => ['approved', null], 'on_sale' => ['approved', 'onSale'],
            'not_on_sale' => ['approved', 'notOnSale'], 'pending' => ['unapproved', 'pendingApproval'],
            'rejected' => ['unapproved', 'rejected']] as $key => [$endpoint, $status]) {
            $query = ['page' => 0, 'size' => 1];
            if ($status !== null) $query['status'] = $status;
            $url = $this->buildUrl("/product/sellers/{$this->supplierId}/products/{$endpoint}");
            $response = $this->httpGet($url . '?' . http_build_query($query), $this->authHeaders());
            $total = $response['totalElements'] ?? null;
            if (!is_numeric($total) || (int)$total < 0) {
                throw new RuntimeException('Trendyol ürün sayısı yanıtı doğrulanamadı');
            }
            $counts[$key] = (int)$total;
        }
        return $counts;
    }

    public function updateStock(array $items): array
    {
        $lock = $this->stockLinks->acquire();
        try { return $this->updateStockLocked($items); }
        finally { $this->stockLinks->release($lock); }
    }

    private function updateStockLocked(array $items): array
    {
        $this->loadCredentials();
        $mid = (int)$this->marketplaceModel->findBySlug(self::SLUG)['id'];
        $items = $this->stockLinks->guardPayload($items, $mid);

        $batchLimit = $this->config['api']['batch_limit'] ?? 1000;
        $chunks = array_chunk($items, $batchLimit);

        $totalSuccess = 0;
        $totalFailed = 0;
        $totalPending = 0;
        $batchIds = [];
        $errors = [];

        foreach ($chunks as $chunk) {
            try {
                $url = $this->buildUrl(
                    $this->config['endpoints']['price_and_inventory']
                        ?? "/suppliers/{$this->supplierId}/products/price-and-inventory"
                );

                $chunk = $this->stockLinks->guardPayload($chunk, $mid);
                foreach ($chunk as &$item) {
                    if (array_key_exists('quantity', $item)) $item['quantity'] = TrendyolProductRules::quantity((int)$item['quantity']);
                }
                unset($item);
                $body = ['items' => $chunk];
                $response = $this->httpPost($url, $body, $this->authHeaders());
                $stockOnly = count(array_filter($chunk, static fn(array $i): bool => array_key_exists('quantity',$i)
                    && !array_key_exists('salePrice',$i) && !array_key_exists('listPrice',$i))) === count($chunk);
                if ($stockOnly) {
                    $batch = (string)($response['batchRequestId'] ?? '');
                    if ($batch === '') throw new RuntimeException('Trendyol stok işlem kimliği dönmedi.');
                    $batchIds[] = $batch;
                    $this->stockLinks->receipt($batch, $chunk);
                    // A queued request is not a completed inventory change.
                    try {
                        $receipt = $this->stockLinks->recordBatch($batch, $this->getBatchStatus($batch));
                        $totalSuccess += $receipt['success'];
                        $totalFailed += $receipt['failed'];
                        array_push($errors, ...$receipt['errors']);
                        $totalPending += max(0,count($chunk)-$receipt['success']-$receipt['failed']);
                    } catch (\Throwable $e) {
                        $totalPending += count($chunk);
                        $this->logger->warning('Stok işlem sonucu henüz doğrulanamadı: '.$e->getMessage(), 'TrendyolService');
                    }
                } else {
                    // Keep the existing price-only transport contract.
                    $totalSuccess += count($chunk);
                }
            } catch (\Throwable $e) {
                $totalFailed += count($chunk);
                $errors[] = ['chunk_size' => count($chunk), 'error' => $e->getMessage()];

                $this->logger->error(
                    'Trendyol stok güncelleme hatası: ' . $e->getMessage(),
                    'TrendyolService',
                    ['chunk_size' => count($chunk)]
                );
            }
        }

        return [
            'success' => $totalSuccess,
            'failed'  => $totalFailed,
            'errors'  => $errors,
            'pending' => $totalPending,
            'batch_ids' => $batchIds,
        ];
    }

    public function updatePrice(array $items): array
    {
        // Trendyol'da fiyat ve stok aynı endpoint'ten güncellenir
        return $this->updateStock($items);
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İÇE AKTARMA (Trendyol → Panel)
    // ═══════════════════════════════════════════════════════

    public function importProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['products']
                ?? "/product/sellers/{$this->supplierId}/products/approved"
        );
        // Ürün V2: onaylı/onaysız ayrı uç noktalar — "approved" parametresi kaldırıldı.
        $url .= '?' . http_build_query($this->productQuery($page, $size));

        $response = $this->httpGet($url, $this->authHeaders());
        $this->writePageToken($page, $size, $response['nextPageToken'] ?? null);

        // V2'de bir içerik birden çok varyant taşır — her varyant ayrı ürün olur
        $products = [];
        foreach ($response['content'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($this->flattenImportContent($item) as $urun) {
                $products[] = $urun;
            }
        }

        return [
            'products'      => $products,
            'totalPages'    => $response['totalPages'] ?? 1,
            'totalElements' => $response['totalElements'] ?? 0,
            'nextPageToken' => $response['nextPageToken'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ & MARKA
    // ═══════════════════════════════════════════════════════

    /** Complete raw catalogue used to verify expired publish receipts, without importing products. */
    public function readProductCatalog(bool $approved, bool $identityOnly = false): array
    {
        $this->loadCredentials();
        $endpoint=$approved ? 'products' : 'products_unapproved';
        $all=[]; $seen=[]; $total=null; $pages=1; $token=null; $size=$approved ? 100 : 1000;
        for ($page=0; $page<$pages; $page++) {
            if ($page>=1000) throw new RuntimeException('Trendyol katalog sayfalama sınırı aşıldı.');
            $query=['size'=>$size];
            if ($page*$size>=10000) {
                if (!$token) throw new RuntimeException('Trendyol devam anahtarı eksik.');
                $query['nextPageToken']=$token;
            } else $query['page']=$page;
            $response=$this->httpGet($this->buildUrl($this->config['endpoints'][$endpoint]).'?'.http_build_query($query),$this->authHeaders());
            if (!isset($response['totalElements'],$response['totalPages']) || !is_array($response['content']??null)) throw new RuntimeException('Eksik Trendyol katalog yanıtı.');
            $current=(int)$response['totalElements'];
            if ($total!==null && $total!==$current) throw new RuntimeException('Trendyol kataloğu okunurken değişti; sonraki kontrolde tekrar denenecek.');
            $total=$current; $pages=max(1,(int)$response['totalPages']);
            if ($total>0 && !$response['content']) throw new RuntimeException('Trendyol katalog sayfası boş; sonuç doğrulanamadı.');
            foreach ($response['content'] as $content) {
                if (!is_array($content)) throw new RuntimeException('Geçersiz Trendyol katalog kaydı.');
                $fingerprint=hash('sha256',json_encode($content,JSON_THROW_ON_ERROR));
                if (isset($seen[$fingerprint])) throw new RuntimeException('Trendyol katalog sayfası tekrarladı; sonuç doğrulanamadı.');
                $seen[$fingerprint]=true;
                if ($identityOnly) {
                    // Receipt reconciliation does not need descriptions/images; keep large
                    // seller catalogues bounded in memory without losing variant identities.
                    $content=array_intersect_key($content,array_flip(['id','contentId','productMainId','status','rejectReasonDetails','barcode','stockCode','variants']));
                    if (isset($content['variants'])) $content['variants']=array_map(static fn($v)=>array_intersect_key($v,array_flip(['barcode','stockCode'])), $content['variants']);
                }
                $all[]=$content;
            }
            $token=$response['nextPageToken']??null;
        }
        if (count($all)!==$total) throw new RuntimeException('Trendyol kataloğu tamamlanamadı; eşleştirme yapılmadı.');
        return $all;
    }

    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['categories'] ?? '/product-categories',
            isGlobal: true
        );

        $response = $this->httpGet($url, $this->authHeaders());

        return $response['categories'] ?? $response;
    }

    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();

        if ($search !== '') {
            $url = $this->buildUrl(
                $this->config['endpoints']['brands_by_name'] ?? '/brands/by-name',
                isGlobal: true
            );
            $url .= '?' . http_build_query(['name' => $search]);
            
            $response = $this->httpGet($url, $this->authHeaders());
            return $response['brands'] ?? $response;
        }
        
        // Tüm markaları pagination ile çek — bellek optimizeli
        // NOT: Trendyol katalogu 250k+ marka içerir; varsayılan limit pratik değer.
        $allBrands = [];
        $seenIds = []; // Duplicate ID kontrolü
        $page = 0;
        $size = 2000; // Trendyol API 1000-2000 arası destekliyor
        $emptyOrDuplicatePages = 0;
        $maxConsecutiveEmpty = 2;
        // Trendyol katalogu 250k+ marka içerir — sayfa/süre bütçesi olmadan bu döngü
        // isteği (ve paylaşımlı hosting'de hesabı) kilitler.
        $this->startPaging('ty.brands', 80, 60.0);

        do {
            $url = $this->buildUrl(
                $this->config['endpoints']['brands'] ?? '/brands',
                isGlobal: true
            );
            $url .= '?' . http_build_query(['page' => $page, 'size' => $size]);

            $response = $this->httpGet($url, $this->authHeaders());

            $brands = $response['brands'] ?? [];
            unset($response); // API yanıtını bellekten serbest bırak

            if (!is_array($brands) || $brands === []) {
                break; // Boş sayfa — tüm markalar çekildi
            }

            $brandCount = count($brands);
            $newOnPage = 0;

            // Yeni markaları ekle (duplicate ID'leri atla)
            foreach ($brands as $brand) {
                $bid = (string)($brand['id'] ?? '');
                if ($bid === '' || isset($seenIds[$bid])) continue;
                $seenIds[$bid] = true;
                $allBrands[] = $brand;
                $newOnPage++;
            }
            unset($brands); // Sayfa verisini bellekten serbest bırak

            $page++;

            $memMB = round(memory_get_usage(true) / 1024 / 1024, 1);
            $this->logger->info(
                "Trendyol markalar çekiliyor: Sayfa {$page}, Yeni: {$newOnPage}, Toplam: " . count($allBrands) . " (Bellek: {$memMB}MB)",
                'TrendyolService'
            );

            // Bu sayfada hiç yeni marka yoksa sayacı artır, varsa sıfırla
            if ($newOnPage === 0) {
                $emptyOrDuplicatePages++;
                if ($emptyOrDuplicatePages >= $maxConsecutiveEmpty) {
                    $this->logger->info(
                        "Trendyol fetchBrands: {$maxConsecutiveEmpty} sayfa üst üste yeni marka yok, erken çıkılıyor",
                        'TrendyolService'
                    );
                    break;
                }
            } else {
                $emptyOrDuplicatePages = 0;
            }

            // Sayfa dolmadıysa son sayfaya gelmiş demektir
            if ($brandCount < $size) {
                break;
            }
        } while ($this->keepPaging('ty.brands', $page));

        unset($seenIds);

        $this->logger->info(
            "Trendyol'dan toplam " . count($allBrands) . " marka çekildi ({$page} sayfa)",
            'TrendyolService'
        );

        return $allBrands;
    }

    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        $this->loadCredentials();

        $endpoint = str_replace(
            '{categoryId}',
            $marketplaceCategoryId,
            $this->config['endpoints']['category_attributes']
                ?? '/product/categories/{categoryId}/attributes'
        );

        $url = $this->buildUrl($endpoint, isGlobal: true);
        $response = $this->httpGet($url, $this->authHeaders());

        if (!isset($response['categoryAttributes']) || !is_array($response['categoryAttributes'])) {
            throw new RuntimeException('Trendyol kategori özellikleri yanıtı geçersiz; liste alınamadı.');
        }
        $attributes = [];
        foreach ($response['categoryAttributes'] as $attr) {
            $values = [];
            foreach ($attr['attributeValues'] ?? [] as $val) {
                $values[] = [
                    'id'   => $val['id'] ?? 0,
                    'name' => $val['name'] ?? '',
                ];
            }

            $attributes[] = [
                'id'          => $attr['attribute']['id'] ?? 0,
                'name'        => $attr['attribute']['name'] ?? '',
                'required'    => $attr['required'] ?? false,
                'allowCustom' => $attr['allowCustom'] ?? false,
                'allowMultipleAttributeValues' => $attr['allowMultipleAttributeValues'] ?? false,
                'varianter' => $attr['varianter'] ?? false,
                'slicer' => $attr['slicer'] ?? false,
                'values'      => $values,
            ];
        }

        return $attributes;
    }

    /** V2 exposes enum options in a separate paginated endpoint. */
    public function fetchCategoryAttributeValues(string $categoryId, string $attributeId): array
    {
        $this->loadCredentials();
        $path = strtr($this->config['endpoints']['category_attribute_values']
            ?? '/product/categories/{categoryId}/attributes/{attributeId}/values',
            ['{categoryId}'=>rawurlencode($categoryId), '{attributeId}'=>rawurlencode($attributeId)]);
        $values = [];
        $seen = [];
        for ($page=0; $page<=1000; $page++) {
            $response = $this->httpGet($this->buildUrl($path, true) . '?' . http_build_query(['page'=>$page,'size'=>1000]), $this->authHeaders());
            $content = $response['content'] ?? null;
            if (!is_array($content)) throw new RuntimeException('Trendyol özellik seçenekleri yanıtı geçersiz.');
            foreach ($content as $row) {
                $id = (string)($row['attributeValueId'] ?? $row['id'] ?? '');
                if ($id !== '') $values[$id] = ['id'=>$id,'name'=>(string)($row['attributeValue'] ?? $row['name'] ?? '')];
            }
            $totalPages = (int)($response['totalPages'] ?? 1);
            if ($page+1 >= $totalPages) return array_values($values);
            $signature = hash('sha256', json_encode($content));
            if ($content === [] || isset($seen[$signature])) throw new RuntimeException('Trendyol özellik seçenekleri eksik alındı; lütfen tekrar deneyin.');
            $seen[$signature] = true;
        }
        throw new RuntimeException('Trendyol özellik seçeneklerinin tamamı alınamadı.');
    }

    // ═══════════════════════════════════════════════════════
    // İADE İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchReturns(array $filters = []): array
    {
        $response = $this->fetchReturnsResponse($filters);

        return $response['content'] ?? [];
    }

    public function approveReturn(string $returnId): bool
    {
        return $this->approveClaimLineItems($returnId);
    }

    public function rejectReturn(string $returnId, string $reason): bool
    {
        $reasonId = ctype_digit($reason) ? (int) $reason : 451;
        $description = ctype_digit($reason) ? '' : $reason;

        return $this->createClaimIssue($returnId, $reasonId, [], $description);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function fetchReturnsResponse(array $filters = []): array
    {
        $this->loadCredentials();

        $queryParams = [
            'page' => max(0, (int) ($filters['page'] ?? 0)),
            'size' => max(1, min(200, (int) ($filters['size'] ?? 100))),
        ];

        if (!empty($filters['claimIds'])) {
            $queryParams['claimIds'] = is_array($filters['claimIds'])
                ? implode(',', array_map('strval', $filters['claimIds']))
                : (string) $filters['claimIds'];
        }

        $claimItemStatus = $filters['claimItemStatus'] ?? $filters['status'] ?? '';
        if ($claimItemStatus !== '') {
            $queryParams['claimItemStatus'] = (string) $claimItemStatus;
        }

        if (!empty($filters['orderNumber'])) {
            $queryParams['orderNumber'] = (string) $filters['orderNumber'];
        }

        $startDate = $this->toTimestampMs($filters['startDate'] ?? $filters['date_from'] ?? null, '00:00:00');
        if ($startDate !== null) {
            $queryParams['startDate'] = $startDate;
        }

        $endDate = $this->toTimestampMs($filters['endDate'] ?? $filters['date_to'] ?? null, '23:59:59');
        if ($endDate !== null) {
            $queryParams['endDate'] = $endDate;
        }

        $url = $this->buildUrl(
            $this->config['endpoints']['claims'] ?? "/order/sellers/{$this->supplierId}/claims"
        );
        $url .= '?' . http_build_query($queryParams);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * @param array<int, string> $claimLineItemIds
     */
    public function approveClaimLineItems(string $returnId, array $claimLineItemIds = []): bool
    {
        $this->loadCredentials();

        $endpoint = str_replace(
            '{claimId}',
            $returnId,
            $this->config['endpoints']['claim_approve']
                ?? "/order/sellers/{$this->supplierId}/claims/{$returnId}/items/approve"
        );

        $url = $this->buildUrl($endpoint);
        $this->httpPut($url, [
            'claimLineItemIdList' => $this->resolveClaimLineItemIds($returnId, $claimLineItemIds),
            'params'              => new \stdClass(),
        ], $this->authHeaders());

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchClaimIssueReasons(): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            $this->config['endpoints']['claim_issue_reasons'] ?? '/order/claim-issue-reasons'
        );

        $response = $this->httpGet($url, $this->authHeaders());

        return array_values(array_filter(
            is_array($response) ? $response : [],
            static fn($row) => is_array($row)
        ));
    }

    /**
     * @param array<int, string> $claimLineItemIds
     */
    public function createClaimIssue(
        string $returnId,
        int $claimIssueReasonId,
        array $claimLineItemIds = [],
        string $description = '',
        ?string $attachmentPath = null
    ): bool {
        $this->loadCredentials();

        $endpoint = str_replace(
            '{claimId}',
            $returnId,
            $this->config['endpoints']['claim_issue']
                ?? "/order/sellers/{$this->supplierId}/claims/{$returnId}/issue"
        );

        $query = [
            'claimIssueReasonId=' . rawurlencode((string) $claimIssueReasonId),
        ];

        if ($description !== '') {
            $query[] = 'description=' . rawurlencode($description);
        }

        foreach ($this->resolveClaimLineItemIds($returnId, $claimLineItemIds) as $claimLineItemId) {
            $query[] = 'claimItemIdList=' . rawurlencode($claimLineItemId);
        }

        $url = $this->buildUrl($endpoint) . '?' . implode('&', $query);

        if ($attachmentPath !== null && is_file($attachmentPath)) {
            $result = $this->postMultipart($url, [
                'file' => new \CURLFile($attachmentPath),
            ]);

            if (!$result['success']) {
                throw new RuntimeException($result['message']);
            }

            return true;
        }

        $this->httpPost($url, null, $this->authHeaders());

        return true;
    }

    // ═══════════════════════════════════════════════════════
    // KARGO ETİKET İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Kargo etiketi oluşturma isteği gönder (ZPL formatında)
     * POST /sellers/{sellerId}/common-label/{cargoTrackingNumber}
     * Sadece Trendyol Ödemeli (TY Pays) + TEX/Aras Kargo için geçerli
     */
    public function createCommonLabel(string $cargoTrackingNumber): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl("/sellers/{$this->supplierId}/common-label/{$cargoTrackingNumber}");
        try {
            $this->httpPost($url, [
                'format' => 'ZPL',
            ], $this->authHeaders());

            return ['success' => true, 'message' => 'Etiket oluşturma isteği gönderildi'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Oluşturulan kargo etiketini al (ZPL formatında)
     * GET /sellers/{sellerId}/common-label/{cargoTrackingNumber}
     */
    public function getCommonLabel(string $cargoTrackingNumber): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl("/sellers/{$this->supplierId}/common-label/{$cargoTrackingNumber}");
        try {
            $response = $this->httpGet($url, $this->authHeaders());
            $labels = $response['data'] ?? [];

            return ['success' => true, 'labels' => $labels];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'labels' => []];
        }
    }

    // ═══════════════════════════════════════════════════════
    // FATURA İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Trendyol'a fatura linki gönder
     */
    public function sendInvoiceLink(int $shipmentPackageId, string $invoiceLink): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl("/sellers/{$this->supplierId}/seller-invoice-links");
        try {
            $this->httpPost($url, [
                'invoiceLink'       => $invoiceLink,
                'shipmentPackageId' => $shipmentPackageId,
            ], $this->authHeaders());

            return ['success' => true, 'message' => 'Fatura linki gönderildi'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Trendyol'a fatura dosyası yükle (PDF/JPEG/PNG, max 10MB)
     */
    public function sendInvoiceFile(int $shipmentPackageId, string $filePath): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl("/sellers/{$this->supplierId}/seller-invoice-file");

        $ch = curl_init();
        $postData = [
            'shipmentPackageId' => (string) $shipmentPackageId,
            'file'              => new \CURLFile($filePath),
        ];

        $authHeaders = $this->authHeaders();
        $headers = array_filter($authHeaders, fn($h) => !str_starts_with($h, 'Content-Type'));

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => 'Fatura dosyası yüklendi'];
        }

        return ['success' => false, 'message' => "HTTP {$httpCode}: " . ($response ?: 'Bilinmeyen hata')];
    }

    // ═══════════════════════════════════════════════════════
    // VERİ NORMALİZASYONU
    // ═══════════════════════════════════════════════════════

    /**
     * Trendyol sipariş verisini normalize et
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $postData
     * @return array{success: bool, message: string}
     */
    private function postMultipart(string $url, array $postData): array
    {
        $ch = curl_init();

        $headers = array_values(array_filter(
            $this->authHeaders(),
            static fn(string $header) => !str_starts_with($header, 'Content-Type')
        ));
        $headers[] = 'Accept: application/json';

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'cURL hatasi: ' . $error];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => 'OK'];
        }

        return [
            'success' => false,
            'message' => "HTTP {$httpCode}: " . ((string) $response !== '' ? (string) $response : 'Bilinmeyen hata'),
        ];
    }

    /**
     * @param array<int, string> $claimLineItemIds
     * @return array<int, string>
     */
    private function resolveClaimLineItemIds(string $claimId, array $claimLineItemIds): array
    {
        $claimLineItemIds = array_values(array_filter(array_map('strval', $claimLineItemIds)));
        if ($claimLineItemIds !== []) {
            return array_values(array_unique($claimLineItemIds));
        }

        $claim = $this->fetchClaimById($claimId);
        $resolved = [];

        foreach ($claim['items'] ?? [] as $item) {
            foreach ($item['claimItems'] ?? [] as $claimItem) {
                $claimItemId = (string) ($claimItem['id'] ?? '');
                if ($claimItemId !== '') {
                    $resolved[] = $claimItemId;
                }
            }
        }

        if ($resolved === []) {
            throw new RuntimeException('Iade kalemi bulunamadi');
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchClaimById(string $claimId): array
    {
        $response = $this->fetchReturnsResponse([
            'claimIds' => $claimId,
            'page'     => 0,
            'size'     => 1,
        ]);

        $claim = $response['content'][0] ?? null;
        if (!is_array($claim)) {
            throw new RuntimeException('Iade talebi bulunamadi');
        }

        return $claim;
    }

    private function toTimestampMs(mixed $value, string $timeSuffix = '00:00:00'): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        $input = (string) $value;
        if (!str_contains($input, ':')) {
            $input .= " {$timeSuffix}";
        }

        $timestamp = strtotime($input);
        if ($timestamp === false) {
            return null;
        }

        return $timestamp * 1000;
    }

    private function normalizeOrder(array $raw): array
    {
        $statusMapping = $this->config['status_mapping'] ?? [];

        $lines = $raw['lines'] ?? [];
        $items = [];
        $subtotal = 0;
        $discount = 0;
        $taxTotal = 0;

        foreach ($lines as $line) {
            $lineTotal    = (float) ($line['amount'] ?? 0);
            $lineDiscount = (float) ($line['discount'] ?? $line['lineTotalDiscount'] ?? 0);

            $vatRate = (float) ($line['vatRate'] ?? $line['vatBaseAmount'] ?? 20);
            $lineTax = ($lineTotal > 0 && $vatRate > 0)
                ? round($lineTotal * $vatRate / (100 + $vatRate), 2)
                : 0;

            $items[] = [
                'marketplace_item_id' => (string) ($line['id'] ?? $line['lineId'] ?? ''),
                'sku'                 => $line['merchantSku'] ?? ($line['stockCode'] ?? ''),
                'barcode'             => $line['barcode'] ?? '',
                'name'                => $line['productName'] ?? '',
                'qty'                 => (int) ($line['quantity'] ?? 1),
                'unit_price'          => (float) ($line['price'] ?? $line['lineUnitPrice'] ?? 0),
                'discount'            => $lineDiscount,
                'tax_rate'            => $vatRate,
                'line_total'          => $lineTotal,
            ];

            $subtotal += ($lineTotal - $lineTax);
            $taxTotal += $lineTax;
            $discount += $lineDiscount;
        }

        // Statü belirleme — packageHistories'den en güncel statüyü al
        $trendyolStatus = 'Created';
        $histories = $raw['packageHistories'] ?? [];
        if (!empty($histories)) {
            // En son tarihe göre sırala
            usort($histories, fn($a, $b) => ($b['createdDate'] ?? 0) <=> ($a['createdDate'] ?? 0));
            $trendyolStatus = $histories[0]['status'] ?? $trendyolStatus;
        }
        // packageHistories yoksa shipmentPackageStatus veya status kullan
        if (empty($histories)) {
            $trendyolStatus = $raw['shipmentPackageStatus'] ?? $raw['status'] ?? 'Created';
        }

        // Müşteri bilgileri
        $shipAddr = $raw['shipmentAddress'] ?? [];
        $invAddr  = $raw['invoiceAddress'] ?? [];

        $customerName = trim(($shipAddr['firstName'] ?? '') . ' ' . ($shipAddr['lastName'] ?? ''));
        if (empty(trim($customerName))) {
            $customerName = $shipAddr['fullName'] ?? '';
        }
        if (empty(trim($customerName))) {
            $customerName = trim(($raw['customerFirstName'] ?? '') . ' ' . ($raw['customerLastName'] ?? ''));
        }

        $phone = $shipAddr['phone'] ?? $invAddr['phone'] ?? '';
        $email = $raw['customerEmail'] ?? '';

        // TC / Vergi no — maskelenmiş değerleri yoksay
        $idNo = $raw['identityNumber'] ?? $raw['taxNumber'] ?? '';
        if (in_array($idNo, ['11111111111', '1111111111', '00000000000', ''], true)) {
            $idNo = '';
        }

        $taxOffice    = $invAddr['taxOffice'] ?? '';
        $taxNumber    = $invAddr['taxNumber'] ?? '';
        $isCommercial = (bool) ($raw['commercial'] ?? false);

        $shippingAddress = [
            'name'         => $shipAddr['fullName'] ?? $customerName,
            'address'      => $shipAddr['fullAddress'] ?? $shipAddr['address1'] ?? '',
            'city'         => $shipAddr['city'] ?? '',
            'district'     => $shipAddr['district'] ?? '',
            'postal_code'  => $shipAddr['postalCode'] ?? '',
            'phone'        => $phone,
            'neighborhood' => $shipAddr['neighborhood'] ?? '',
        ];

        $billingAddress = [
            'name'        => $invAddr['fullName'] ?? $customerName,
            'address'     => $invAddr['fullAddress'] ?? $invAddr['address1'] ?? '',
            'city'        => $invAddr['city'] ?? '',
            'district'    => $invAddr['district'] ?? '',
            'postal_code' => $invAddr['postalCode'] ?? '',
            'company'     => $invAddr['company'] ?? '',
            'tax_office'  => $taxOffice,
            'tax_number'  => $taxNumber,
        ];

        return [
            'marketplace_order_id' => (string) ($raw['orderNumber'] ?? $raw['id'] ?? ''),
            'status'               => $statusMapping[$trendyolStatus] ?? $trendyolStatus,
            'customer_name'        => $customerName,
            'customer_email'       => $email,
            'customer_phone'       => $phone,
            'customer_id_no'       => $isCommercial ? $taxNumber : $idNo,
            'customer_tax_office'  => $isCommercial ? $taxOffice : '',
            'notes'                => $raw['customerNote'] ?? $raw['giftNote'] ?? '',
            'billing_address'      => $billingAddress,
            'shipping_address'     => $shippingAddress,
            'subtotal'             => round($subtotal, 2),
            'tax_total'            => round($taxTotal, 2),
            'discount_total'       => (float) ($raw['totalDiscount'] ?? $raw['packageTotalDiscount'] ?? $discount),
            'total_amount'         => (float) ($raw['totalPrice'] ?? $raw['packageTotalPrice'] ?? round($subtotal + $taxTotal, 2)),
            'cargo_company'        => $raw['cargoProviderName'] ?? '',
            'cargo_tracking_no'    => (string) ($raw['cargoTrackingNumber'] ?? ''),
            'cargo_tracking_link'  => $raw['cargoTrackingLink'] ?? '',
            'cargo_sent_at'        => isset($raw['originShipmentDate'])
                ? date('Y-m-d H:i:s', intdiv((int) $raw['originShipmentDate'], 1000))
                : null,
            'ordered_at'           => isset($raw['orderDate'])
                // Trendyol orderDate already encodes GMT+3 wall time; do not add it twice.
                ? gmdate('Y-m-d H:i:s', intdiv((int) $raw['orderDate'], 1000))
                : date('Y-m-d H:i:s'),
            'items'                => $items,
            'raw_data'             => $raw,
        ];
    }

    /**
     * Trendyol'a ürün gönder (Product Create API)
     * POST /product/sellers/{supplierId}/products
     *
     * @param array<int, array<string, mixed>> $items Trendyol ürün formatında ürün listesi
     * @return array{batchRequestId: string|null, success: bool, errors: array}
     */
    public function createProducts(array $items): array
    {
        $lock = $this->stockLinks->acquire();
        try { return $this->createProductsLocked($items); }
        finally { $this->stockLinks->release($lock); }
    }

    private function createProductsLocked(array $items): array
    {
        $this->loadCredentials();
        $mid = (int)$this->marketplaceModel->findBySlug(self::SLUG)['id'];
        $items = $this->stockLinks->guardPayload($items, $mid);

        $url = $this->buildUrl(
            $this->config['endpoints']['create_products']
                ?? "/product/sellers/{$this->supplierId}/v2/products"
        );

        $payload = ['items' => $items];

        try {
            $response = $this->httpPost($url, $payload, $this->authHeaders());
            $batchId = $response['batchRequestId'] ?? null;

            return [
                'batchRequestId' => $batchId !== null ? (string) $batchId : null,
                'success'        => true,
                'errors'         => [],
            ];
        } catch (\Throwable $e) {
            // Trendyol "recurring.product.create.not.allowed": satıcı bazında
            // art arda create isteği koruması. İki sebebi olur:
            //  1) Ürün(ler) daha önce gönderilmiş → CREATE değil UPDATE gerekir.
            //  2) Önceki batch hâlâ işleniyor / kısa sürede çok istek atıldı →
            //     geçici blok; beklemek gerekir.
            if (str_contains($e->getMessage(), 'recurring.product.create.not.allowed')) {
                $firstBarcode = trim((string) ($items[0]['barcode'] ?? ''));

                if ($firstBarcode !== '' && $this->barcodeExistsOnTrendyol($firstBarcode)) {
                    $this->logger->info(
                        'Trendyol: ürün(ler) zaten gönderilmiş, güncelleme servisine geçiliyor',
                        'TrendyolAPI'
                    );
                    return $this->updateProducts($items);
                }

                return [
                    'batchRequestId' => null,
                    'success'        => false,
                    'errors'         => [[
                        'error' => 'Trendyol art arda ürün oluşturma isteklerini geçici olarak '
                            . 'engelledi (önceki gönderim işleniyor olabilir). Lütfen birkaç '
                            . 'dakika bekleyip Yayınla\'ya tekrar basın — bekleme süresinde '
                            . 'tekrar denemek engeli uzatır.',
                    ]],
                ];
            }

            return [
                'batchRequestId' => null,
                'success'        => false,
                'errors'         => [['error' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Mevcut ürünleri güncelle (barcode ile eşleşir) — Trendyol'a daha önce
     * gönderilmiş ürünlerde createProducts otomatik olarak buraya düşer.
     *
     * PUT /product/sellers/{sellerId}/v2/products
     *
     * @param array<int, array<string, mixed>> $items createProducts ile aynı şema
     * @return array{batchRequestId: ?string, success: bool, errors: array}
     */
    /** Existing listings retain their approved content; price/stock use the dedicated endpoint. */
    public function updateListingProducts(array $items): array
    {
        $lock = $this->stockLinks->acquire();
        try { return $this->updateListingProductsLocked($items); }
        finally { $this->stockLinks->release($lock); }
    }

    private function updateListingProductsLocked(array $items): array
    {
        $this->loadCredentials();
        $mid = (int)$this->marketplaceModel->findBySlug(self::SLUG)['id'];
        $items = $this->stockLinks->guardPayload($items, $mid);
        $payload = [];
        foreach ($items as $item) {
            $row = ['barcode'=>(string)$item['barcode']];
            if (array_key_exists('salePrice', $item) && array_key_exists('listPrice', $item)) {
                $row['salePrice'] = (float)$item['salePrice'];
                $row['listPrice'] = (float)$item['listPrice'];
            }
            if (!array_key_exists('quantity', $item) && !isset($row['salePrice'])) {
                throw new \InvalidArgumentException('Stok veya fiyat değeri gerekli.');
            }
            // Omit stock for price-only updates; absence must never become zero.
            if (array_key_exists('quantity', $item)) {
                $row['quantity'] = TrendyolProductRules::quantity((int)$item['quantity']);
            }
            $payload[] = $row;
        }
        try {
            $url = $this->buildUrl($this->config['endpoints']['price_and_inventory']);
            $response = $this->httpPost($url, ['items'=>$payload], $this->authHeaders());
            if (empty($response['batchRequestId'])) throw new RuntimeException('Trendyol fiyat/stok işlem kimliği dönmedi.');
            return ['success'=>true,'batchRequestId'=>(string)$response['batchRequestId'],'errors'=>[]];
        } catch (\Throwable $e) {
            return ['success'=>false,'batchRequestId'=>null,'errors'=>[['error'=>$e->getMessage()]]];
        }
    }

    public function updateProducts(array $items): array
    {
        $lock = $this->stockLinks->acquire();
        try { return $this->updateProductsLocked($items); }
        finally { $this->stockLinks->release($lock); }
    }

    private function updateProductsLocked(array $items): array
    {
        $this->loadCredentials();
        $mid = (int)$this->marketplaceModel->findBySlug(self::SLUG)['id'];
        $items = $this->stockLinks->guardPayload($items, $mid);

        $url = $this->buildUrl(
            $this->config['endpoints']['create_products']
                ?? "/product/sellers/{$this->supplierId}/v2/products"
        );

        try {
            $response = $this->httpPut($url, ['items' => $items], $this->authHeaders());
            $batchId = $response['batchRequestId'] ?? null;

            return [
                'batchRequestId' => $batchId !== null ? (string) $batchId : null,
                'success'        => true,
                'errors'         => [],
            ];
        } catch (\Throwable $e) {
            return [
                'batchRequestId' => null,
                'success'        => false,
                'errors'         => [[
                    'error' => 'Ürün Trendyol\'a daha önce gönderilmiş; onay sürecindeyse '
                        . 'güncellenemez, onayın tamamlanmasını bekleyin. Güncelleme denemesi '
                        . 'hatası: ' . $e->getMessage(),
                ]],
            ];
        }
    }

    /**
     * Barkod Trendyol'da (onaylı veya onaysız) kayıtlı mı?
     */
    private function barcodeExistsOnTrendyol(string $barcode): bool
    {
        try {
            $found = $this->findProductsByBarcode($barcode);
            $approved = $found['approved']['content'] ?? [];
            $unapproved = $found['unapproved']['content'] ?? [];
            return !empty($approved) || !empty($unapproved);
        } catch (\Throwable $e) {
            $this->logger->warning('Trendyol barkod kontrolü yapılamadı: ' . $e->getMessage(), 'TrendyolAPI');
            return false;
        }
    }

    /**
     * Trendyol asenkron ürün/stok/fiyat işlem sonucunu sorgula.
     *
     * GET /product/sellers/{supplierId}/products/batch-requests/{batchRequestId}
     *
     * @return array<string, mixed>
     */
    public function getBatchStatus(string $batchRequestId): array
    {
        $this->loadCredentials();

        $url = $this->buildUrl(
            "/product/sellers/{$this->supplierId}/products/batch-requests/" . rawurlencode($batchRequestId)
        );

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Barkoda göre Trendyol'da ürün gerçekten var mı kontrol et.
     *
     * @return array{approved: array<string, mixed>, unapproved: array<string, mixed>}
     */
    public function findProductsByBarcode(string $barcode): array
    {
        $this->loadCredentials();

        return [
            'approved' => $this->fetchProductsByBarcode($barcode, true),
            'unapproved' => $this->fetchProductsByBarcode($barcode, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProductsByBarcode(string $barcode, bool $approved): array
    {
        // Ürün V2: onay durumu artık parametre değil, ayrı uç nokta.
        $anahtar = $approved ? 'products' : 'products_unapproved';
        $varsayilan = $approved
            ? "/product/sellers/{$this->supplierId}/products/approved"
            : "/product/sellers/{$this->supplierId}/products/unapproved";

        $url = $this->buildUrl($this->config['endpoints'][$anahtar] ?? $varsayilan);
        $url .= '?' . http_build_query([
            'page'    => 0,
            'size'    => 50,
            'barcode' => $barcode,
        ]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Trendyol'a varyantlı ürün grubu gönder
     * Aynı productMainId ile gönderilen items otomatik varyant olarak gruplanır.
     *
     * @param array{items: array, groupId: string, slug: string} $variantGroup
     * @return array{success: bool, batchRequestId: ?string, errors: array}
     */
    public function createVariantProducts(array $variantGroup): array
    {
        $this->loadCredentials();

        $items = [];
        foreach ($variantGroup['items'] ?? [] as $item) {
            $items[] = [
                'barcode'           => $item['barcode'],
                'title'             => $item['title'],
                'productMainId'     => $variantGroup['groupId'],
                'brandId'           => $item['brandId'],
                'categoryId'        => $item['categoryId'],
                'quantity'          => $item['quantity'],
                'stockCode'         => $item['stockCode'],
                'dimensionalWeight' => $item['dimensionalWeight'] ?? 1,
                'description'       => $item['description'],
                'currencyType'      => 'TRY',
                'listPrice'         => $item['listPrice'],
                'salePrice'         => $item['salePrice'],
                'vatRate'           => $item['vatRate'] ?? 20,
                'images'            => $item['images'] ?? [],
                'attributes'        => $item['attributes'] ?? [],
            ];
        }

        $url = $this->buildUrl(
            $this->config['endpoints']['create_products']
                ?? "/product/sellers/{$this->supplierId}/v2/products"
        );

        try {
            $response = $this->httpPost($url, ['items' => $items], $this->authHeaders());
            $this->logger->info(
                'Trendyol varyant grubu gönderildi: ' . ($variantGroup['groupId'] ?? '') . ' (' . count($items) . ' varyant)',
                'TrendyolService'
            );
            return [
                'success'        => true,
                'batchRequestId' => $response['batchRequestId'] ?? null,
                'errors'         => [],
            ];
        } catch (\Throwable $e) {
            return [
                'success'        => false,
                'batchRequestId' => null,
                'errors'         => [['error' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Trendyol ürün verisini import formatına normalize et
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    /**
     * Sayfa jetonu önbelleğinin yolu (satıcı + sayfa boyu bazında).
     *
     * Ürün V2'de "sayfa × boyut" 10.000'i aşamaz; ötesi yalnız nextPageToken ile
     * gezilebilir. İçe aktarma istemci tarafında sayfa sayfa (ayrı HTTP isteği)
     * ilerlediği için jeton istekler arasında saklanmalıdır.
     */
    private function pageTokenCachePath(int $size): string
    {
        if (!defined('BASE_PATH') || $this->supplierId === '') {
            return '';
        }

        $dizin = BASE_PATH . '/storage/cache';
        if (!is_dir($dizin)) {
            @mkdir($dizin, 0755, true);
        }
        if (!is_dir($dizin) || !is_writable($dizin)) {
            return '';
        }

        return $dizin . '/ty_pagetoken_' . substr(sha1($this->supplierId . '|' . $size), 0, 16) . '.json';
    }

    /**
     * Bu sayfa için saklanmış jetonu oku (yoksa null).
     */
    private function readPageToken(int $page, int $size): ?string
    {
        $yol = $this->pageTokenCachePath($size);
        if ($yol === '' || !is_file($yol)) {
            return null;
        }

        $veri = json_decode((string) @file_get_contents($yol), true);
        if (!is_array($veri)) {
            return null;
        }

        // Yarım kalmış eski bir gezinti olmasın diye 1 saatlik ömür
        if ((int) ($veri['t'] ?? 0) < time() - 3600) {
            return null;
        }

        $jeton = $veri['tokens'][(string) $page] ?? null;

        return is_string($jeton) && $jeton !== '' ? $jeton : null;
    }

    /**
     * Bir sonraki sayfanın jetonunu sakla.
     */
    private function writePageToken(int $page, int $size, ?string $token): void
    {
        $yol = $this->pageTokenCachePath($size);
        if ($yol === '' || $token === null || $token === '') {
            return;
        }

        $veri = is_file($yol) ? json_decode((string) @file_get_contents($yol), true) : null;
        if (!is_array($veri) || (int) ($veri['t'] ?? 0) < time() - 3600) {
            $veri = ['t' => time(), 'tokens' => []];
        }

        $veri['t'] = time();
        $veri['tokens'][(string) ($page + 1)] = $token;

        // Sınırsız büyümesin
        if (count($veri['tokens']) > 500) {
            $veri['tokens'] = array_slice($veri['tokens'], -200, null, true);
        }

        @file_put_contents($yol, json_encode($veri), LOCK_EX);
    }

    /**
     * Ürün listeleme sorgu dizesi — 10.000 sınırından sonra jetona geçer.
     *
     * @return array<string, mixed>
     */
    private function productQuery(int $page, int $size): array
    {
        $sorgu = ['size' => $size];

        if ($page * $size >= 10000) {
            $jeton = $this->readPageToken($page, $size);
            if ($jeton !== null) {
                $sorgu['nextPageToken'] = $jeton;
                return $sorgu;
            }

            $this->logger->warning(
                "Trendyol ürün listeleme: sayfa {$page} için sayfa jetonu yok; 10.000 kaydı aşan "
                . 'sayfalar ancak sıralı gezinti sırasında alınabilir.',
                'TrendyolAPI'
            );
        }

        $sorgu['page'] = $page;

        return $sorgu;
    }

    /**
     * Ürün V2 yanıtını panelin ürün şemasına çevir.
     *
     * V2'de ONAYLI ürünler İÇERİK düzeyinde döner: başlık/açıklama/marka/kategori/görsel
     * içerikte, barkod/stok/fiyat/KDV ise variants[] içinde. ONAYSIZ uç noktası ise
     * hâlâ düz (barkod satır düzeyinde). Bu metot iki biçimi de kabul eder.
     *
     * @param array<string, mixed> $content İçerik satırı
     * @param array<string, mixed>|null $variant Varyant satırı (düz biçimde null)
     * @return array<string, mixed>
     */
    private function normalizeImportProduct(array $content, ?array $variant = null): array
    {
        $v = $variant ?? $content;

        $images = [];
        foreach ($content['images'] ?? [] as $i => $img) {
            $url = is_array($img) ? (string) ($img['url'] ?? '') : (string) $img;
            if ($url === '') {
                continue;
            }
            $images[] = [
                'url'      => $url,
                'is_cover' => $images === [],
                'sort'     => $i + 1,
            ];
        }

        // Nitelikler: içerik geneli + varyanta özel (beden/renk gibi)
        $attributes = [];
        foreach (array_merge($content['attributes'] ?? [], $v['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $ad = (string) ($attr['attributeName'] ?? '');
            if ($ad === '') {
                continue;
            }
            $attributes[$ad] = [
                'name'  => $ad,
                'value' => (string) ($attr['attributeValue'] ?? ''),
            ];
        }

        // Marka/kategori V2'de nesne, V1'de düz metindi — ikisini de karşıla
        $marka    = $content['brand'] ?? null;
        $kategori = $content['category'] ?? null;

        $markaAdi = is_array($marka) ? (string) ($marka['name'] ?? '') : (string) ($marka ?? '');
        $markaId  = is_array($marka) ? (string) ($marka['id'] ?? '') : (string) ($content['brandId'] ?? '');
        $katAdi   = is_array($kategori) ? (string) ($kategori['name'] ?? '') : (string) ($content['categoryName'] ?? '');
        $katId    = is_array($kategori) ? (string) ($kategori['id'] ?? '') : (string) ($content['categoryId'] ?? '');

        // Fiyat ve stok V2'de iç nesnede, V1'de düz alandaydı
        $fiyat = $v['price'] ?? null;
        $stok  = $v['stock'] ?? null;

        $satisFiyati = is_array($fiyat)
            ? (float) ($fiyat['salePrice'] ?? 0)
            : (float) ($v['salePrice'] ?? 0);
        $listeFiyati = is_array($fiyat)
            ? (float) ($fiyat['listPrice'] ?? 0)
            : (float) ($v['listPrice'] ?? 0);

        $normalized = [
            'marketplace_sku'        => (string) ($v['stockCode'] ?? ''),
            'marketplace_product_id' => (string) ($content['productMainId'] ?? $content['contentId'] ?? $content['id'] ?? ''),
            'barcode'                => (string) ($v['barcode'] ?? ''),
            'name'                   => (string) ($content['title'] ?? ''),
            'description'            => (string) ($content['description'] ?? ''),
            'category_name'          => $katAdi,
            'category_id'            => $katId,
            'brand_name'             => $markaAdi,
            'brand_id'               => $markaId,
            'price'                  => $satisFiyati,
            'list_price'             => $listeFiyati,
            'tax_rate'               => (float) ($v['vatRate'] ?? 0),
            'group_id'               => (string) ($content['productMainId'] ?? ''),
            'attributes'             => array_values($attributes),
            'images'                 => $images,
            'raw'                    => $variant === null ? $content : ['content' => $content, 'variant' => $variant],
        ];

        if (is_array($stok) && array_key_exists('quantity', $stok)) {
            $normalized['stock'] = (int) $stok['quantity'];
        } elseif (array_key_exists('quantity', $v)) {
            $normalized['stock'] = (int) $v['quantity'];
        }

        return $normalized;
    }

    /**
     * Bir V2 içerik satırını, varyant başına bir ürün olacak şekilde düzleştir.
     *
     * @param array<string, mixed> $content
     * @return array<int, array<string, mixed>>
     */
    private function flattenImportContent(array $content): array
    {
        $variants = $content['variants'] ?? null;

        // Onaysız uç noktası hâlâ düz biçimde dönüyor
        if (!is_array($variants) || $variants === []) {
            return [$this->normalizeImportProduct($content)];
        }

        $urunler = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            // Arşivlenmiş varyantlar panele çekilmez
            if (!empty($variant['archived'])) {
                continue;
            }
            $urunler[] = $this->normalizeImportProduct($content, $variant);
        }

        return $urunler;
    }
    // ═══════════════════════════════════════════════════════
    // EK YARDIMCI METOTLAR
    // ═══════════════════════════════════════════════════════

    /**
     * Trendyol kategori ağacını düz listeye çevir
     * fetchCategories()'den dönen ağaç yapısını flat array'e çevirir
     *
     * @param array<int, array<string, mixed>> $categories
     * @param array<int, array<string, mixed>> &$flat
     */
    public function flattenCategoryTree(array $categories, array &$flat, ?int $parentId = null): void
    {
        foreach ($categories as $cat) {
            $flat[] = [
                'id'        => (int)($cat['id'] ?? 0),
                'name'      => $cat['name'] ?? '',
                'parent_id' => $parentId,
            ];

            if (!empty($cat['subCategories'])) {
                $this->flattenCategoryTree($cat['subCategories'], $flat, (int)($cat['id'] ?? 0));
            }
        }
    }

    /**
     * Trendyol'da marka adıyla ara ve tam eşleşme bul
     */
    public function searchBrandByName(string $name): ?array
    {
        $brands = $this->fetchBrands($name);
        foreach ($brands as $b) {
            if (mb_strtolower($b['name'] ?? '') === mb_strtolower($name)) {
                return $b;
            }
        }
        return $brands[0] ?? null;
    }

    // ═══════════════════════════════════════════════════════
    // FİNANS / CARİ HESAP
    // ═══════════════════════════════════════════════════════

    /**
     * Trendyol Settlements (Mutabakat) — satış, iade, komisyon detayları
     */
    public function fetchSettlements(int $startDate, int $endDate, int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        // Finance API'leri için retry'ı 1'e düşür (500 hatalarında uzun bekleme olmasın)
        $origRetries = $this->maxRetries;
        $this->maxRetries = 1;

        try {
            $baseUrl = 'https://apigw.trendyol.com/integration/finance/che/sellers/' . $this->supplierId . '/settlements';
            $url = $baseUrl . '?' . http_build_query([
                'startDate' => $startDate,
                'endDate'   => $endDate,
                'page'      => $page,
                'size'      => $size,
            ]);

            $headers = array_merge($this->authHeaders(), ['Accept: application/json']);
            $response = $this->httpGet($url, $headers);

            return [
                'content'       => $response['content'] ?? [],
                'totalPages'    => $response['totalPages'] ?? 1,
                'totalElements' => $response['totalElements'] ?? 0,
                'page'          => $response['page'] ?? $page,
            ];
        } finally {
            $this->maxRetries = $origRetries;
        }
    }

    /**
     * Trendyol Other Financials — hakediş, stopaj, virman, fatura vs.
     */
    public function fetchOtherFinancials(int $startDate, int $endDate, string $transactionType = 'PaymentOrder'): array
    {
        $this->loadCredentials();

        $origRetries = $this->maxRetries;
        $this->maxRetries = 1;

        try {
            $baseUrl = 'https://apigw.trendyol.com/integration/finance/che/sellers/' . $this->supplierId . '/otherfinancials';
            $url = $baseUrl . '?' . http_build_query([
                'startDate'        => $startDate,
                'endDate'          => $endDate,
                'transactionType'  => $transactionType,
            ]);

            $headers = array_merge($this->authHeaders(), ['Accept: application/json']);
            $response = $this->httpGet($url, $headers);

            return $response['content'] ?? $response ?? [];
        } finally {
            $this->maxRetries = $origRetries;
        }
    }

    /**
     * Normalize settlement item to standard financial transaction format
     */
    public function normalizeSettlement(array $item): array
    {
        $commissionAmount = abs((float)($item['commissionAmount'] ?? $item['commission'] ?? 0));
        // Trendyol debt/credit kullanıyor, amount alanı yok
        $debt   = (float)($item['debt'] ?? 0);
        $credit = (float)($item['credit'] ?? 0);
        $grossAmount = (float)($item['amount'] ?? $item['salesAmount'] ?? ($debt > 0 ? $debt : $credit));
        $netAmount = $credit > 0 ? $credit : ($grossAmount - $commissionAmount);

        $type = 'sale';
        if (($item['transactionType'] ?? '') === 'Return' || ($item['type'] ?? '') === 'Return') {
            $type = 'refund';
        } elseif (($item['transactionType'] ?? '') === 'Discount') {
            $type = 'discount';
        } elseif (($item['transactionType'] ?? '') === 'CouponDiscount') {
            $type = 'coupon';
        }

        return [
            'transaction_type' => $type,
            'transaction_date' => isset($item['transactionDate'])
                ? date('Y-m-d H:i:s', (int)($item['transactionDate'] / 1000))
                : date('Y-m-d H:i:s'),
            'order_number'     => (string)($item['orderNumber'] ?? $item['packageId'] ?? ''),
            'description'      => $item['productName'] ?? $item['description'] ?? '',
            'gross_amount'     => $grossAmount,
            'commission_amount'=> $commissionAmount,
            'net_amount'       => $netAmount,
            'currency'         => 'TRY',
            'status'           => 'completed',
            'external_id'      => (string)($item['id'] ?? md5(json_encode($item))),
            'extra_data_json'  => json_encode($item),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // SORU & CEVAP (Q&A)
    // ═══════════════════════════════════════════════════════

    /**
     * Trendyol'dan soruları çek
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQuestions(int $page = 0, int $size = 50, string $status = '', ?int $startDate = null, ?int $endDate = null): array
    {
        $this->loadCredentials();

        $params = [
            'page' => $page,
            'size' => max(1,min($size, 50)), // API max 50
            'supplierId' => $this->supplierId,
            'orderByField' => 'LastModifiedDate',
            'orderByDirection' => 'DESC',
        ];
        if ($status !== '') {
            $params['status'] = $status;
        }
        if ($startDate !== null) {
            $params['startDate'] = $startDate;
        }
        if ($endDate !== null) {
            $params['endDate'] = $endDate;
        }

        $query = http_build_query($params);
        // Doğru endpoint: /qna/sellers/{sellerId}/questions/filter
        $url = $this->buildUrl("/qna/sellers/{$this->supplierId}/questions/filter") . "?{$query}";

        $response = $this->httpGet($url, $this->authHeaders());

        if (!isset($response['content']) || !is_array($response['content'])) {
            throw new RuntimeException('Trendyol soru yanıtı doğrulanamadı');
        }
        $questions = $response['content'];
        $totalPages = $response['totalPages'] ?? 1;

        // Tüm sayfaları çek
        if ($totalPages > 1 && $page === 0) {
            for ($p = 1; $p < $totalPages; $p++) {
                $params['page'] = $p;
                $q2 = http_build_query($params);
                $url2 = $this->buildUrl("/qna/sellers/{$this->supplierId}/questions/filter") . "?{$q2}";
                $resp2 = $this->httpGet($url2, $this->authHeaders());
                if (!isset($resp2['content']) || !is_array($resp2['content'])) throw new RuntimeException('Trendyol soru sayfası doğrulanamadı');
                $questions = array_merge($questions, $resp2['content']);
            }
        }

        return $questions;
    }

    /**
     * Trendyol'da soruya cevap gönder
     */
    public function answerQuestion(string $questionId, string $answerText): array
    {
        $this->loadCredentials();
        // Doğru endpoint: /qna/sellers/{sellerId}/questions/{id}/answers
        $url = "https://apigw.trendyol.com/integration/qna/sellers/{$this->supplierId}/questions/{$questionId}/answers";

        $response = $this->httpPost($url, [
            'text' => $answerText,
        ], $this->authHeaders());

        return $response;
    }
}
