<?php
/**
 * OzyConnect Panel — API Stock Controller
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
use App\Models\Product;

final class ApiStockController extends ApiBaseController
{
    private readonly Product $productModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->productModel = $container->get(Product::class);
    }

    /**
     * GET /api/v1/stock/summary — Stok özeti
     */
    public function summary(Request $request): void
    {
        $this->requirePermission('stock:read');

        $summary = $this->productModel->getStockSummary();

        $this->apiSuccess([
            'total_products'    => (int) ($summary['total_products'] ?? 0),
            'in_stock'          => (int) ($summary['in_stock'] ?? 0),
            'low_stock'         => (int) ($summary['low_stock'] ?? 0),
            'out_of_stock'      => (int) ($summary['out_of_stock'] ?? 0),
        ]);
    }

    /**
     * POST /api/v1/stock/bulk-update — Toplu stok güncelleme
     *
     * Body: { "items": [ { "sku": "ABC", "stock_qty": 10 }, ... ] }
     */
    public function bulkUpdate(Request $request): void
    {
        $this->requirePermission('stock:write');

        $body = $this->jsonBody($request);

        if (empty($body['items']) || !is_array($body['items'])) {
            $this->apiValidationError(['items' => 'items dizisi zorunlu']);
        }

        if (count($body['items']) > 500) {
            $this->apiError('Tek seferde en fazla 500 ürün güncellenebilir', 'BATCH_TOO_LARGE', 400);
        }

        $results = ['updated' => 0, 'not_found' => 0, 'errors' => []];

        foreach ($body['items'] as $idx => $item) {
            $sku = $item['sku'] ?? '';
            $qty = $item['stock_qty'] ?? null;

            if ($sku === '' || $qty === null) {
                $results['errors'][] = [
                    'index' => $idx,
                    'sku'   => $sku,
                    'error' => 'sku ve stock_qty zorunlu',
                ];
                continue;
            }

            $product = $this->productModel->findBySku($sku);
            if ($product === false) {
                $results['not_found']++;
                $results['errors'][] = [
                    'index' => $idx,
                    'sku'   => $sku,
                    'error' => 'Ürün bulunamadı',
                ];
                continue;
            }

            $this->productModel->update((int) $product['id'], [
                'stock_qty' => (int) $qty,
            ]);
            $results['updated']++;
        }

        $this->apiSuccess($results, "Toplu stok güncelleme: {$results['updated']} güncellendi");
    }
}
