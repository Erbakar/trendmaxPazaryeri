<?php
/**
 * OzyConnect Panel — ProductAttributeValue Model
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

final class ProductAttributeValue extends BaseModel
{
    protected string $table = 'product_attribute_values';

    /**
     * Özellik adına göre product_attributes tablosundan ID bul
     *
     * @return int|null Attribute ID veya null
     */
    public function findAttributeIdByName(string $name): ?int
    {
        $row = $this->rawQueryOne(
            "SELECT id FROM product_attributes WHERE LOWER(name) = LOWER(:name) LIMIT 1",
            ['name' => $name]
        );
        return $row !== false ? (int) $row['id'] : null;
    }

    /**
     * Ürünün özellik değerlerini getir (join'li)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByProduct(int $productId): array
    {
        return $this->rawQuery(
            "SELECT pav.*, pa.name as attribute_name, pa.input_type,
                    av.value as attribute_value_text, av.color_hex
             FROM product_attribute_values pav
             JOIN product_attributes pa ON pav.attribute_id = pa.id
             LEFT JOIN attribute_values av ON pav.attribute_value_id = av.id
             WHERE pav.product_id = :pid AND pav.store_id = :store_id
             ORDER BY pa.sort_order ASC, pa.name ASC",
            ['pid' => $productId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Ürün özellik değeri kaydet/güncelle (upsert)
     */
    public function setProductAttribute(
        int $productId,
        int $attributeId,
        ?int $attributeValueId = null,
        ?string $customValue = null
    ): void {
        $existing = $this->findBy([
            'product_id'   => $productId,
            'attribute_id' => $attributeId,
        ]);

        $data = [
            'attribute_value_id' => $attributeValueId,
            'custom_value'       => $customValue,
        ];

        if ($existing !== false) {
            $this->update((int) $existing['id'], $data);
        } else {
            $data['product_id']   = $productId;
            $data['attribute_id'] = $attributeId;
            $this->create($data);
        }
    }

    /**
     * Ürünün tüm özelliklerini sil (yeniden atama öncesi)
     */
    public function deleteByProduct(int $productId): void
    {
        $this->db->query(
            "DELETE FROM product_attribute_values WHERE product_id = :pid AND store_id = :store_id",
            ['pid' => $productId, 'store_id' => $this->tenantId()]
        );
    }
}
