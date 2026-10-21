<?php
/**
 * OzyConnect Panel — CategoryAttribute Model
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

final class CategoryAttribute extends BaseModel
{
    protected string $table = 'category_attributes';

    /**
     * Kategorinin zorunlu özelliklerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRequired(int $categoryId): array
    {
        return $this->rawQuery(
            "SELECT ca.*, pa.name, pa.input_type, pa.unit
             FROM category_attributes ca
             JOIN product_attributes pa ON ca.attribute_id = pa.id
             WHERE ca.category_id = :cid AND ca.is_required = 1 AND ca.store_id = :store_id
             ORDER BY ca.sort_order ASC",
            ['cid' => $categoryId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Kategoriye özellik ata
     */
    public function assign(int $categoryId, int $attributeId, bool $required = false): int
    {
        $existing = $this->findBy([
            'category_id'  => $categoryId,
            'attribute_id' => $attributeId,
        ]);

        if ($existing !== false) {
            return (int) $existing['id'];
        }

        return $this->create([
            'category_id'  => $categoryId,
            'attribute_id' => $attributeId,
            'is_required'  => $required ? 1 : 0,
        ]);
    }
}
