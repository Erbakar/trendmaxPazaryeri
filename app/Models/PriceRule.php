<?php
/**
 * OzyConnect Panel — PriceRule Model
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

final class PriceRule extends BaseModel
{
    protected string $table = 'price_rules';

    /**
     * Pazaryeri bazlı kuralları getir (öncelik sıralı)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByMarketplace(int $marketplaceId): array
    {
        return $this->all(
            ['marketplace_id' => $marketplaceId, 'is_active' => 1],
            'priority ASC, id ASC'
        );
    }

    /**
     * Ürün için en uygun kuralı bul (hiyerarşik)
     *
     * @return array<string, mixed>|false
     */
    public function findBestRule(int $marketplaceId, int $productId, ?int $categoryId): array|false
    {
        // 1. Ürün bazlı kural
        $rule = $this->rawQueryOne(
            "SELECT * FROM price_rules
             WHERE marketplace_id = :mid AND apply_to = 'product' AND reference_id = :pid AND is_active = 1 AND store_id = :store_id
             ORDER BY priority ASC LIMIT 1",
            ['mid' => $marketplaceId, 'pid' => $productId, 'store_id' => $this->tenantId()]
        );
        if ($rule !== false) {
            return $rule;
        }

        // 2. Kategori bazlı kural
        if ($categoryId !== null) {
            $rule = $this->rawQueryOne(
                "SELECT * FROM price_rules
                 WHERE marketplace_id = :mid AND apply_to = 'category' AND reference_id = :cid AND is_active = 1 AND store_id = :store_id
                 ORDER BY priority ASC LIMIT 1",
                ['mid' => $marketplaceId, 'cid' => $categoryId, 'store_id' => $this->tenantId()]
            );
            if ($rule !== false) {
                return $rule;
            }
        }

        // 3. Genel kural
        return $this->rawQueryOne(
            "SELECT * FROM price_rules
             WHERE marketplace_id = :mid AND apply_to = 'all' AND is_active = 1 AND store_id = :store_id
             ORDER BY priority ASC LIMIT 1",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
    }
}
