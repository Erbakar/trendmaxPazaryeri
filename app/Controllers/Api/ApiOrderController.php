<?php
/**
 * OzyConnect Panel — API Order Controller
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

namespace App\Controllers\Api;

use App\Core\Container;
use App\Core\Request;
use App\Models\Order;

final class ApiOrderController extends ApiBaseController
{
    private readonly Order $orderModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->orderModel = $container->get(Order::class);
    }

    /**
     * GET /api/v1/orders — Sipariş listesi
     */
    public function list(Request $request): void
    {
        $this->requirePermission('orders:read');

        $params = $this->paginationParams($request);
        $params = $this->paginationParams($request);

        // Mağazaya kapalı + sıralaması beyaz listeli model metodu (eski kod protected
        // rawQuery çağırıyordu ve sorgularda store_id yoktu).
        $sonuc = $this->orderModel->apiList(
            [
                'status'         => $request->query('status'),
                'marketplace_id' => $request->query('marketplace_id'),
                'date_from'      => $request->query('date_from'),
                'date_to'        => $request->query('date_to'),
                'search'         => trim((string) $request->query('search', '')),
            ],
            (int) $params['page'],
            (int) $params['per_page'],
            (string) $request->query('sort', 'created_desc')
        );

        $rows  = $sonuc['rows'];
        $total = $sonuc['total'];


        $this->apiPaginated(
            array_map([$this, 'formatOrder'], $rows),
            $params['page'],
            $params['per_page'],
            $total
        );
    }

    /**
     * GET /api/v1/orders/{id} — Sipariş detayı
     */
    public function show(Request $request, string $id): void
    {
        $this->requirePermission('orders:read');

        // Mağazaya kapalı (eski sorgu PK ile başka bayinin siparişini de getirebiliyordu)
        $order = $this->orderModel->apiFind((int) $id);

        if (!$order) {
            $this->apiNotFound('Sipariş bulunamadı');
        }

        // Sipariş kalemleri
        $items = $this->orderModel->apiItems((int) $id);

        $formatted = $this->formatOrder($order);
        $formatted['items'] = array_map(function (array $item): array {
            return [
                'id'            => (int) $item['id'],
                'sku'           => $item['sku'] ?? '',
                'product_name'  => $item['product_name'] ?? '',
                'quantity'      => (int) ($item['quantity'] ?? 1),
                'unit_price'    => (float) ($item['unit_price'] ?? 0),
                'total_price'   => (float) ($item['total_price'] ?? 0),
                'barcode'       => $item['barcode'] ?? null,
            ];
        }, $items);

        $this->apiSuccess($formatted);
    }

    /**
     * POST /api/v1/orders — Yeni sipariş oluştur
     */
    public function create(Request $request): void
    {
        $this->requirePermission('orders:write');

        $body = $this->jsonBody($request);
        $errors = [];

        if (empty($body['marketplace_order_id'])) {
            $errors['marketplace_order_id'] = 'Pazaryeri sipariş numarası zorunlu';
        }
        if (empty($body['marketplace_id'])) {
            $errors['marketplace_id'] = 'Pazaryeri ID zorunlu';
        }
        if (empty($body['customer_name'])) {
            $errors['customer_name'] = 'Müşteri adı zorunlu';
        }
        if (empty($body['items']) || !is_array($body['items'])) {
            $errors['items'] = 'En az bir sipariş kalemi zorunlu';
        }

        if (!empty($errors)) {
            $this->apiValidationError($errors);
        }

        // Duplicate check
        // Mağazaya kapalı duplicate kontrolü (eski sorgu tüm bayilerde arıyordu →
        // başka bir bayide aynı numara varsa bu bayinin siparişi hiç oluşmuyordu)
        $existing = $this->orderModel->findByMarketplaceOrderId(
            (int) $body['marketplace_id'],
            (string) $body['marketplace_order_id']
        );
        if ($existing) {
            $this->apiError('Bu sipariş zaten mevcut', 'DUPLICATE_ORDER', 409);
        }

        $totalAmount = 0;
        foreach ($body['items'] as $item) {
            $totalAmount += (float) ($item['total_price'] ?? ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 1));
        }

        $orderId = $this->orderModel->create([
            'marketplace_id'       => (int) $body['marketplace_id'],
            'marketplace_order_id' => $body['marketplace_order_id'],
            'customer_name'        => $body['customer_name'],
            'customer_email'       => $body['customer_email'] ?? null,
            'customer_phone'       => $body['customer_phone'] ?? null,
            // Panel şeması adresi JSON tutar; cargo_* kolonlarını kullanır.
            // (customer_address / shipping_company / tracking_number kolonu YOK.)
            'shipping_address_json' => isset($body['customer_address'])
                ? json_encode(['address' => $body['customer_address']], JSON_UNESCAPED_UNICODE)
                : null,
            'total_amount'         => $totalAmount,
            'status'               => $body['status'] ?? 'yeni',
            'cargo_company'        => $body['shipping_company'] ?? null,
            'cargo_tracking_no'    => $body['tracking_number'] ?? null,
        ]);

        // Kalemleri ekle
        foreach ($body['items'] as $item) {
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);

            // Model üzerinden: store_id otomatik dolar (ham INSERT'te eksikti) ve
            // protected rawQuery çağrısı kalkar (ölümcül hata veriyordu).
            // NOT: eski ham INSERT sütun adlarını da yanlış yazıyordu
            // (product_name/quantity/total_price/barcode → name/qty/line_total; barcode yok).
            $this->container->get(\App\Models\OrderItem::class)->create([
                'order_id'   => $orderId,
                'sku'        => (string) ($item['sku'] ?? ''),
                'name'       => (string) ($item['product_name'] ?? $item['name'] ?? ''),
                'qty'        => $qty,
                'unit_price' => $unitPrice,
                'line_total' => (float) ($item['total_price'] ?? $unitPrice * $qty),
            ]);
        }

        $order = $this->orderModel->find($orderId);
        $this->apiSuccess($this->formatOrder($order), 'Sipariş oluşturuldu');
    }

    /**
     * PUT /api/v1/orders/{id}/status — Sipariş durumu güncelle
     */
    public function updateStatus(Request $request, string $id): void
    {
        $this->requirePermission('orders:write');

        $order = $this->orderModel->find((int) $id);
        if ($order === false) {
            $this->apiNotFound('Sipariş bulunamadı');
        }

        $body = $this->jsonBody($request);
        $validStatuses = ['yeni', 'beklemede', 'hazirlaniyor', 'faturalandi', 'kargoda', 'teslim_edildi', 'iptal', 'iade'];

        if (empty($body['status'])) {
            $this->apiValidationError(['status' => 'Durum zorunlu']);
        }
        if (!in_array($body['status'], $validStatuses, true)) {
            $this->apiValidationError(['status' => 'Geçersiz durum. Geçerli: ' . implode(', ', $validStatuses)]);
        }

        $updateData = ['status' => $body['status']];

        // Panel şeması cargo_* kolonlarını kullanır (tracking_number/shipping_company kolonu yok)
        if (!empty($body['tracking_number'])) {
            $updateData['cargo_tracking_no'] = $body['tracking_number'];
        }
        if (!empty($body['shipping_company'])) {
            $updateData['cargo_company'] = $body['shipping_company'];
        }

        $this->orderModel->update((int) $id, $updateData);

        // Durum geçişi yan etkileri — OrderSyncService ile aynı desen:
        // iptal/iade'ye geçişte rezerve stok iade edilir
        $oldStatus = (string) ($order['status'] ?? '');
        $newStatus = (string) $body['status'];
        if ($oldStatus !== $newStatus && in_array($newStatus, ['iptal', 'iade'], true)) {
            try {
                $this->container->get(\App\Services\StockManager::class)->cancelOrderReservation((int) $id);
            } catch (\Throwable $e) {
                // Stok iadesi başarısız olsa da durum güncellemesi geçerli — logla ve devam et
                $this->logger->warning("API durum değişimi stok iadesi hatası (#{$id}): " . $e->getMessage(), 'ApiOrders');
            }
        }

        // NOT: Bu uç yalnızca YEREL kaydı günceller; pazaryerine iptal bildirimi göndermez.
        // Pazaryeri iptali için panel Sipariş Detay > Siparişi İptal Et kullanılmalıdır.
        $this->apiSuccess([
            'id'     => (int) $id,
            'status' => $body['status'],
        ], 'Sipariş durumu güncellendi');
    }

    /**
     * GET /api/v1/orders/statuses — Geçerli statüler
     */
    public function statuses(Request $request): void
    {
        $this->requirePermission('orders:read');

        $this->apiSuccess([
            'statuses' => [
                ['key' => 'yeni',           'label' => 'Yeni'],
                ['key' => 'beklemede',      'label' => 'Beklemede'],
                ['key' => 'hazirlaniyor',   'label' => 'Hazırlanıyor'],
                ['key' => 'faturalandi',    'label' => 'Faturalandı'],
                ['key' => 'kargoda',        'label' => 'Kargoda'],
                ['key' => 'teslim_edildi',  'label' => 'Teslim Edildi'],
                ['key' => 'iptal',          'label' => 'İptal'],
                ['key' => 'iade',           'label' => 'İade'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function formatOrder(array $o): array
    {
        return [
            'id'                   => (int) $o['id'],
            'marketplace_id'       => (int) ($o['marketplace_id'] ?? 0),
            'marketplace_name'     => $o['marketplace_name'] ?? null,
            'marketplace_slug'     => $o['marketplace_slug'] ?? null,
            'marketplace_order_id' => $o['marketplace_order_id'] ?? '',
            'customer_name'        => $o['customer_name'] ?? '',
            'customer_email'       => $o['customer_email'] ?? null,
            'customer_phone'       => $o['customer_phone'] ?? null,
            'customer_address'     => $o['customer_address'] ?? null,
            'total_amount'         => (float) ($o['total_amount'] ?? 0),
            'status'               => $o['status'] ?? 'yeni',
            'shipping_company'     => $o['shipping_company'] ?? null,
            'tracking_number'      => $o['tracking_number'] ?? null,
            'invoice_id'           => isset($o['invoice_id']) ? (int) $o['invoice_id'] : null,
            'created_at'           => $o['created_at'] ?? null,
            'updated_at'           => $o['updated_at'] ?? null,
        ];
    }
}
