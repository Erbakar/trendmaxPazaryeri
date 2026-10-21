<?php
/**
 * OzyConnect Panel — EnrichmentLog Model
 * Zenginleştirme adım logları
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

final class EnrichmentLog extends BaseModel
{
    protected string $table = 'enrichment_logs';

    /**
     * Zenginleştirme adımı logla
     */
    public function logStep(
        int $productId,
        int $marketplaceId,
        string $step,
        string $field,
        string $status,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $message = null
    ): int {
        return $this->create([
            'product_id'     => $productId,
            'marketplace_id' => $marketplaceId,
            'step'           => $step,
            'field'          => $field,
            'status'         => $status,
            'old_value'      => $oldValue,
            'new_value'      => $newValue,
            'message'        => $message,
        ]);
    }

    /**
     * Ürüne ait logları getir (son N kayıt)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByProduct(int $productId, int $limit = 50): array
    {
        return $this->rawQuery(
            "SELECT * FROM enrichment_logs
             WHERE product_id = :pid
               AND store_id = :store_id
             ORDER BY created_at DESC
             LIMIT :limit",
            ['pid' => $productId, 'limit' => $limit, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Adım ve duruma göre logları getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByStep(string $step, string $status = '', int $limit = 100): array
    {
        $where = 'step = :step AND store_id = :store_id';
        $params = ['step' => $step, 'limit' => $limit, 'store_id' => $this->tenantId()];

        if ($status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        return $this->rawQuery(
            "SELECT * FROM enrichment_logs
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT :limit",
            $params
        );
    }

    /**
     * Pazaryerine ait son logları getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentByMarketplace(int $marketplaceId, int $limit = 100): array
    {
        return $this->rawQuery(
            "SELECT el.*, p.name as product_name, p.sku
             FROM enrichment_logs el
             LEFT JOIN products p ON el.product_id = p.id AND p.store_id = :store_id2
             WHERE el.marketplace_id = :mid
               AND el.store_id = :store_id
             ORDER BY el.created_at DESC
             LIMIT :limit",
            ['mid' => $marketplaceId, 'limit' => $limit, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }
}
