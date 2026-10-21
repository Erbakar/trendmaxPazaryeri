<?php
/**
 * OzyConnect Panel — StockMovement Model
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

final class StockMovement extends BaseModel
{
    protected string $table = 'stock_movements';

    /**
     * Stok hareketi kaydet
     */
    public function record(
        int $productId,
        string $movementType,
        string $source,
        int $qtyChange,
        int $qtyBefore,
        int $qtyAfter,
        ?string $referenceId = null,
        ?string $note = null,
        ?int $userId = null
    ): int {
        return $this->create([
            'product_id'    => $productId,
            'movement_type' => $movementType,
            'source'        => $source,
            'reference_id'  => $referenceId,
            'qty_change'    => $qtyChange,
            'qty_before'    => $qtyBefore,
            'qty_after'     => $qtyAfter,
            'note'          => $note,
            'created_by'    => $userId,
        ]);
    }

    /**
     * Ürünün stok hareketlerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByProduct(int $productId, int $limit = 50): array
    {
        return $this->rawQuery(
            "SELECT sm.*, u.name as user_name
             FROM stock_movements sm
             LEFT JOIN users u ON sm.created_by = u.id
             WHERE sm.product_id = :pid
               AND sm.store_id = :store_id
             ORDER BY sm.created_at DESC
             LIMIT :limit",
            ['pid' => $productId, 'limit' => $limit, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Filtreli stok hareketi say
     *
     * @param array<string, mixed> $params
     */
    public function countFiltered(string $whereStr, array $params = []): int
    {
        $params['store_id'] = $this->tenantId();
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM stock_movements sm WHERE ({$whereStr}) AND sm.store_id = :store_id",
            $params
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Referans ID'ye göre stok hareketlerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByReference(string $referenceId, ?string $movementType = null): array
    {
        $sql = "SELECT * FROM stock_movements WHERE reference_id = :ref AND store_id = :store_id";
        $params = ['ref' => $referenceId, 'store_id' => $this->tenantId()];

        if ($movementType !== null) {
            $sql .= " AND movement_type = :type";
            $params['type'] = $movementType;
        }

        return $this->rawQuery($sql, $params);
    }

    /**
     * Referans ID'ye göre movement_type güncelle
     */
    public function updateTypeByReference(string $referenceId, string $oldType, string $newType): int
    {
        $stmt = $this->db->query(
            "UPDATE stock_movements SET movement_type = :newType WHERE reference_id = :ref AND movement_type = :oldType AND store_id = :store_id",
            ['newType' => $newType, 'ref' => $referenceId, 'oldType' => $oldType, 'store_id' => $this->tenantId()]
        );
        return $stmt->rowCount();
    }

    /**
     * Filtreli stok hareketleri getir
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function getFiltered(string $whereStr, array $params = [], int $limit = 50, int $offset = 0): array
    {
        $params['store_id'] = $this->tenantId();
        return $this->rawQuery(
            "SELECT sm.*, p.name as product_name, p.sku
             FROM stock_movements sm
             LEFT JOIN products p ON sm.product_id = p.id
             WHERE ({$whereStr}) AND sm.store_id = :store_id
             ORDER BY sm.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }
}
