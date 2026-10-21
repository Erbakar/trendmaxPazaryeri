<?php
/**
 * OzyConnect Panel — OrderItem Model
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

namespace App\Models;

final class OrderItem extends BaseModel
{
    protected string $table = 'order_items';

    /**
     * Siparişin kalemlerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByOrderId(int $orderId): array
    {
        return $this->rawQuery(
            "SELECT oi.*, p.sku as product_sku, p.barcode as product_barcode,
                    p.main_image as product_main_image,
                    (
                        SELECT mp.main_image
                        FROM marketplace_products mp
                                                JOIN orders o2 ON o2.marketplace_id = mp.marketplace_id
                        WHERE mp.marketplace_id = o2.marketplace_id
                                                    AND o2.id = oi.order_id
                          AND mp.marketplace_sku = oi.sku
                          AND mp.main_image IS NOT NULL
                          AND mp.main_image != ''
                          AND mp.store_id = :store_id2
                          AND o2.store_id = :store_id3
                        LIMIT 1
                    ) as marketplace_main_image,
                    COALESCE(
                        NULLIF(p.main_image, ''),
                        (
                            SELECT mp.main_image
                            FROM marketplace_products mp
                                                        JOIN orders o2 ON o2.marketplace_id = mp.marketplace_id
                            WHERE mp.marketplace_id = o2.marketplace_id
                                                            AND o2.id = oi.order_id
                              AND mp.marketplace_sku = oi.sku
                              AND mp.main_image IS NOT NULL
                              AND mp.main_image != ''
                              AND mp.store_id = :store_id4
                              AND o2.store_id = :store_id5
                            LIMIT 1
                        )
                    ) as item_image
             FROM order_items oi
             LEFT JOIN products p ON oi.product_id = p.id AND p.store_id = :store_id6
             WHERE oi.order_id = :oid
               AND oi.store_id = :store_id
             ORDER BY oi.id ASC",
            [
                'oid' => $orderId,
                'store_id' => $this->tenantId(),
                'store_id2' => $this->tenantId(),
                'store_id3' => $this->tenantId(),
                'store_id4' => $this->tenantId(),
                'store_id5' => $this->tenantId(),
                'store_id6' => $this->tenantId(),
            ]
        );
    }

    /**
     * Toplu kalem ekle
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function createBulk(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            $item['order_id'] = $orderId;
            $this->create($item);
        }
    }
}
