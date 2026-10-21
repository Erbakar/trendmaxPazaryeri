<?php
/**
 * OzyConnect Panel - OziyGO Bridge integration.
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Models\Marketplace;
use App\Services\CryptoService;
use RuntimeException;

final class OziygoService extends AbstractMarketplace
{
    private const SLUG = 'oziygo';

    private string $apiKey = '';
    private string $apiUrl = '';
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, mixed> */
    private array $extraConfig = [];

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        $api = $this->config['api'] ?? [];
        $this->timeout = (int) ($api['timeout_seconds'] ?? 30);
        $this->maxRetries = (int) ($api['max_retries'] ?? 2);
        $this->retryBaseDelayMs = (int) ($api['retry_base_delay_ms'] ?? 500);
        $this->rateLimitPerSecond = (int) ($api['rate_limit_per_second'] ?? 5);
    }

    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('OziyGO pazaryeri kaydi bulunamadi.');
        }

        if (empty($marketplace['api_key_encrypted'])) {
            throw new RuntimeException('OziyGO API anahtari girilmemis.');
        }

        $this->apiKey = $this->crypto->decrypt($marketplace['api_key_encrypted']);
        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
        $this->apiUrl = rtrim((string) ($this->extraConfig['api_url'] ?? $this->config['base_url'] ?? ''), '/');

        if ($this->apiUrl === '') {
            throw new RuntimeException('OziyGO API URL girilmemis. Ornek: https://site.com/eklentiler/ozyconnect/api.php');
        }
    }

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();
            $result = $this->get('test');

            return [
                'success' => (bool) ($result['success'] ?? false),
                'message' => (string) ($result['message'] ?? 'OziyGO baglanti testi tamamlandi.'),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OziyGO baglanti testi hatasi: ' . $e->getMessage(), 'OziygoAPI');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $explicitPage = array_key_exists('page', $filters);
        $params = [
            'page' => (int) ($filters['page'] ?? 1),
            'limit' => (int) ($filters['limit'] ?? 100),
        ];

        if (!empty($filters['status'])) {
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['start_date'])) {
            $params['date_from'] = (string) $filters['start_date'];
        } elseif (!empty($filters['date_from'])) {
            $params['date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['end_date'])) {
            $params['date_to'] = (string) $filters['end_date'];
        } elseif (!empty($filters['date_to'])) {
            $params['date_to'] = (string) $filters['date_to'];
        }

        $orders = [];
        $this->startPaging('oziygo.orders');

        do {
            $response = $this->get('orders', $params);
            $pageOrders = $response['orders'] ?? [];
            if (is_array($pageOrders)) {
                $orders = array_merge($orders, $pageOrders);
            }

            if ($explicitPage) {
                break;
            }

            $params['page']++;
            $totalPages = (int) ($response['total_pages'] ?? 1);
        } while ($params['page'] <= $totalPages
            && $this->keepPaging('oziygo.orders', (int) $params['page'], $pageOrders));

        return array_map(fn(array $order): array => $this->normalizeOrder($order), array_filter($orders, 'is_array'));
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();

        $payload = [
            'order_id' => $orderId,
            'status' => $this->toBridgeStatus($status),
        ];

        if (!empty($extra['tracking_number'])) {
            $payload['tracking_number'] = (string) $extra['tracking_number'];
        }
        if (!empty($extra['cargo_company'])) {
            $payload['cargo_company'] = (string) $extra['cargo_company'];
        }

        $result = $this->post('update_order_status', $payload);
        return (bool) ($result['success'] ?? false);
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        return $this->updateOrderStatus($orderId, 'shipped', [
            'tracking_number' => $trackingNo,
            'cargo_company' => $cargoCompany,
        ]);
    }

    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();
        return $this->get('products', ['page' => $page + 1, 'limit' => $size]);
    }

    public function updateStock(array $items): array
    {
        $this->loadCredentials();

        $payload = ['items' => array_map(function (array $item): array {
            return [
                'id' => $this->resolveBridgeId($item),
                'sku' => (string) ($item['sku'] ?? $item['stockCode'] ?? ''),
                'barcode' => (string) ($item['barcode'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
            ];
        }, $items)];

        return $this->normalizeWriteResult($this->post('update_stock', $payload), count($items));
    }

    public function updatePrice(array $items): array
    {
        $this->loadCredentials();

        $payload = ['items' => array_map(function (array $item): array {
            $salePrice = (float) ($item['salePrice'] ?? 0);
            $basePrice = (float) ($item['listPrice'] ?? $salePrice);
            if ($basePrice < $salePrice) {
                $basePrice = $salePrice;
            }

            return [
                'id' => $this->resolveBridgeId($item),
                'sku' => (string) ($item['sku'] ?? $item['stockCode'] ?? ''),
                'barcode' => (string) ($item['barcode'] ?? ''),
                'base_price' => $basePrice,
                'sale_price' => $salePrice,
            ];
        }, $items)];

        return $this->normalizeWriteResult($this->post('update_price', $payload), count($items));
    }

    public function createProducts(array $items): array
    {
        $this->loadCredentials();

        $payload = ['products' => array_map(function (array $item): array {
            $images = [];
            foreach (($item['images'] ?? []) as $image) {
                if (is_array($image)) {
                    $url = trim((string) ($image['url'] ?? $image['src'] ?? ''));
                } else {
                    $url = trim((string) $image);
                }
                if ($url !== '') {
                    $images[] = $url;
                }
            }

            $salePrice = (float) ($item['salePrice'] ?? $item['price'] ?? 0);
            $listPrice = (float) ($item['listPrice'] ?? $salePrice);
            if ($listPrice < $salePrice) {
                $listPrice = $salePrice;
            }

            return [
                'sku' => (string) ($item['sku'] ?? $item['stockCode'] ?? $item['productMainId'] ?? ''),
                'barcode' => (string) ($item['barcode'] ?? ''),
                'name' => (string) ($item['name'] ?? $item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'short_description' => (string) ($item['short_description'] ?? ''),
                'category_id' => (string) ($item['categoryId'] ?? ''),
                'brand_id' => (string) ($item['brandId'] ?? ''),
                'stock' => (int) ($item['quantity'] ?? $item['stock'] ?? 0),
                'base_price' => $listPrice,
                'sale_price' => $salePrice,
                'vat_rate' => (float) ($item['vatRate'] ?? 20),
                'main_image' => $images[0] ?? '',
                'images' => $images,
            ];
        }, $items)];

        $result = $this->post('create_products', $payload);
        return $this->normalizeCreateResult($result, count($items));
    }

    public function importProducts(int $page = 0, int $size = 100): array
    {
        $response = $this->fetchProducts($page, $size);
        $products = $response['products'] ?? [];

        return [
            'products' => array_map(fn(array $product): array => $this->normalizeProduct($product), array_filter($products, 'is_array')),
            'totalPages' => (int) ($response['total_pages'] ?? 1),
            'totalElements' => (int) ($response['total'] ?? count($products)),
        ];
    }

    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();
        $response = $this->get('categories');
        $categories = $response['categories'] ?? [];

        return array_map(static fn(array $category): array => [
            'id' => (string) ($category['id'] ?? ''),
            'name' => (string) ($category['name'] ?? ''),
            'parentId' => (string) ($category['parent_id'] ?? ''),
            'paths' => [(string) ($category['name'] ?? '')],
            'leaf' => true,
        ], array_filter($categories, 'is_array'));
    }

    public function fetchBrands(string $search = ''): array
    {
        $this->loadCredentials();
        $response = $this->get('brands');
        $brands = $response['brands'] ?? [];

        $normalized = array_map(static fn(array $brand): array => [
            'id' => (string) ($brand['id'] ?? ''),
            'name' => (string) ($brand['name'] ?? ''),
        ], array_filter($brands, 'is_array'));

        if ($search === '') {
            return $normalized;
        }

        $needle = mb_strtolower($search);
        return array_values(array_filter($normalized, static fn(array $brand): bool => str_contains(mb_strtolower($brand['name']), $needle)));
    }

    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        return [];
    }

    public function fetchReturns(array $filters = []): array
    {
        return [];
    }

    public function approveReturn(string $returnId): bool
    {
        return false;
    }

    public function rejectReturn(string $returnId, string $reason): bool
    {
        return false;
    }

    /** @param array<string, string|int|float|bool|null> $params */
    private function get(string $action, array $params = []): array
    {
        $params['action'] = $action;
        return $this->httpGet($this->buildUrl($params), $this->headers());
    }

    /** @param array<string, mixed> $payload */
    private function post(string $action, array $payload): array
    {
        return $this->httpPost($this->buildUrl(['action' => $action]), $payload, $this->headers());
    }

    /** @param array<string, string|int|float|bool|null> $params */
    private function buildUrl(array $params): string
    {
        $separator = str_contains($this->apiUrl, '?') ? '&' : '?';
        return $this->apiUrl . $separator . http_build_query($params);
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        return ['X-Api-Key: ' . $this->apiKey];
    }

    /** @param array<string, mixed> $product */
    private function normalizeProduct(array $product): array
    {
        $images = [];
        foreach (($product['images'] ?? []) as $image) {
            if (is_string($image) && $image !== '') {
                $images[] = ['url' => $image, 'order' => count($images) + 1];
            }
        }

        if (!empty($product['main_image'])) {
            array_unshift($images, ['url' => (string) $product['main_image'], 'order' => 0]);
        }

        $sku = $this->firstString($product, [
            'sku',
            'urunkodu',
            'urun_kodu',
            'product_code',
            'productCode',
            'stock_code',
            'stockCode',
            'barcode',
            'barkod',
        ]);
        $barcode = $this->firstString($product, [
            'barcode',
            'barkod',
            'urunkodu',
            'urun_kodu',
            'product_code',
            'productCode',
            'stock_code',
            'stockCode',
            'sku',
        ]);
        $id = (string) ($product['id'] ?? '');
        $variants = $this->normalizeVariants(is_array($product['variants'] ?? null) ? $product['variants'] : []);

        $normalized = [
            'marketplace_product_id' => $id,
            'marketplace_sku' => $sku !== '' ? $sku : 'ozygo-' . $id,
            'barcode' => $barcode,
            'name' => (string) ($product['name'] ?? ''),
            'description' => (string) ($product['description'] ?? ''),
            'long_description' => (string) ($product['description'] ?? ''),
            'price' => (float) ($product['sale_price'] ?? $product['base_price'] ?? 0),
            'list_price' => (float) ($product['base_price'] ?? $product['sale_price'] ?? 0),
            'vat_rate' => (float) ($product['vat_rate'] ?? 0),
            'is_active' => (bool) ($product['is_active'] ?? true),
            'images' => $images,
            'category_id' => (string) (($product['category_ids'][0] ?? '') ?: ''),
            'category_name' => '',
            'brand_name' => '',
            'brand_id' => (string) ($product['brand_id'] ?? ''),
            'attributes' => [],
            'has_variants' => !empty($variants),
            'variants' => $variants,
            'raw' => $product,
        ];

        if (array_key_exists('stock_qty', $product)) {
            $normalized['stock'] = (int) $product['stock_qty'];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $groups
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariants(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $values = [];
            foreach (($group['values'] ?? []) as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $name = $this->firstString($value, ['name', 'value', 'adi', 'deger_adi']);
                if ($name === '') {
                    continue;
                }

                $option = [
                    'id' => (string) ($value['id'] ?? $value['deger_id'] ?? ''),
                    'name' => $name,
                    'price' => (float) ($value['price'] ?? $value['fiyat'] ?? 0),
                ];

                if (array_key_exists('stock', $value)) {
                    $option['stock'] = (int) $value['stock'];
                } elseif (array_key_exists('stok', $value)) {
                    $option['stock'] = (int) $value['stok'];
                }

                $values[] = $option;
            }

            if ($values === []) {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($group['id'] ?? $group['ozellik_id'] ?? ''),
                'name' => $this->firstString($group, ['name', 'ozellik_adi', 'adi']) ?: 'Secenek',
                'type' => (string) ($group['type'] ?? $group['ozellik_tip'] ?? ''),
                'values' => $values,
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $order */
    private function normalizeOrder(array $order): array
    {
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $items = [];

        foreach (($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $sku = $this->firstString($item, [
                'sku',
                'merchantSku',
                'stockCode',
                'product_code',
                'productCode',
                'urunkodu',
                'urun_kodu',
                'barcode',
                'barkod',
            ]);
            $barcode = $this->firstString($item, [
                'barcode',
                'barkod',
                'product_barcode',
                'productBarcode',
                'urunkodu',
                'urun_kodu',
                'product_code',
                'productCode',
                'stockCode',
                'sku',
            ]);
            $name = $this->firstString($item, [
                'product_name',
                'productName',
                'name',
                'urun_adi',
                'urunadi',
                'adi',
                'title',
            ]);
            if ($name === '') {
                $name = $this->firstString($product, ['name', 'product_name', 'productName', 'adi', 'title']);
            }
            $qty = (int) ($item['quantity'] ?? $item['qty'] ?? $item['adet'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $items[] = [
                'marketplace_item_id' => (string) ($item['product_id'] ?? $item['id'] ?? ''),
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount' => 0,
                'tax_rate' => 20,
                'line_total' => (float) ($item['total_price'] ?? ($unitPrice * $qty)),
            ];
        }

        $address = [
            'name' => (string) ($customer['name'] ?? ''),
            'address' => (string) ($customer['address'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'district' => (string) ($customer['district'] ?? ''),
            'postal_code' => '',
            'phone' => (string) ($customer['phone'] ?? ''),
        ];

        return [
            'marketplace_order_id' => (string) ($order['order_number'] ?? $order['id'] ?? ''),
            'status' => $this->fromBridgeStatus((string) ($order['status'] ?? 'unknown')),
            'status_raw' => (string) ($order['status_raw'] ?? $order['status'] ?? ''),
            'customer_name' => (string) ($customer['name'] ?? ''),
            'customer_email' => (string) ($customer['email'] ?? ''),
            'customer_phone' => (string) ($customer['phone'] ?? ''),
            'customer_id_no' => '',
            'customer_tax_office' => '',
            'billing_address' => $address,
            'shipping_address' => $address,
            'subtotal' => (float) ($order['subtotal'] ?? 0),
            'tax_total' => (float) ($order['tax_amount'] ?? 0),
            'discount_total' => 0,
            'shipping_total' => (float) ($order['shipping_cost'] ?? 0),
            'total_amount' => (float) ($order['total_amount'] ?? 0),
            'currency' => 'TRY',
            'payment_method' => (string) ($order['payment_type'] ?? ''),
            'cargo_company' => (string) ($order['cargo_company'] ?? ''),
            'cargo_tracking_no' => (string) ($order['tracking_number'] ?? ''),
            'cargo_tracking_link' => '',
            'cargo_sent_at' => null,
            'ordered_at' => $this->normalizeDate((string) ($order['created_at'] ?? '')),
            'items' => $items,
            'raw_data' => $order,
        ];
    }

    private function fromBridgeStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'beklemede',
            'confirmed' => 'yeni',
            'processing' => 'hazirlaniyor',
            'shipped' => 'kargoda',
            'delivered' => 'teslim_edildi',
            'cancelled' => 'iptal',
            'returned' => 'iade',
            default => 'yeni',
        };
    }

    private function toBridgeStatus(string $status): string
    {
        return match ($status) {
            'beklemede' => 'pending',
            'yeni' => 'confirmed',
            'hazirlaniyor' => 'processing',
            'kargoda', 'shipped' => 'shipped',
            'teslim_edildi' => 'delivered',
            'iptal' => 'cancelled',
            'iade' => 'returned',
            default => $status,
        };
    }

    private function normalizeDate(string $value): string
    {
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $item */
    private function resolveBridgeId(array $item): int
    {
        foreach (['id', 'marketplace_product_id', 'hbSku', 'sku'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (int) $item[$key];
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $result */
    private function normalizeWriteResult(array $result, int $fallbackTotal): array
    {
        if (isset($result['success']) && is_int($result['success'])) {
            return [
                'success' => (int) $result['success'],
                'failed' => (int) ($result['failed'] ?? 0),
                'errors' => $result['errors'] ?? [],
            ];
        }

        $ok = !empty($result['success']);
        return [
            'success' => $ok ? $fallbackTotal : 0,
            'failed' => $ok ? 0 : $fallbackTotal,
            'errors' => $ok ? [] : [$result['message'] ?? $result['error'] ?? 'OziyGO yazma islemi basarisiz.'],
        ];
    }

    /** @param array<string, mixed> $result */
    private function normalizeCreateResult(array $result, int $fallbackTotal): array
    {
        if (isset($result['success']) && is_int($result['success'])) {
            return [
                'success' => (int) $result['success'] > 0,
                'created' => (int) $result['success'],
                'failed' => (int) ($result['failed'] ?? 0),
                'errors' => $result['errors'] ?? [],
                'products' => $result['products'] ?? [],
            ];
        }

        $ok = !empty($result['success']);
        return [
            'success' => $ok,
            'created' => $ok ? $fallbackTotal : 0,
            'failed' => $ok ? 0 : $fallbackTotal,
            'errors' => $ok ? [] : [['error' => $result['message'] ?? $result['error'] ?? 'OziyGO urun olusturma basarisiz.']],
            'products' => $result['products'] ?? [],
        ];
    }
}
