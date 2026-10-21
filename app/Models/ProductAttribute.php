<?php
/**
 * OzyConnect Panel — ProductAttribute Model
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

final class ProductAttribute extends BaseModel
{
    protected string $table = 'product_attributes';
    protected bool $tenantScoped = false; // GLOBAL paylasimli referans (store_id YOK)

    /**
     * Varyant özelliklerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVariantAttributes(): array
    {
        return $this->all(['is_variant_attribute' => 1], 'sort_order ASC, name ASC');
    }

    /**
     * Kategori bazlı özellikler
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByCategory(int $categoryId): array
    {
        return $this->rawQuery(
            "SELECT pa.*, ca.is_required as cat_required
             FROM product_attributes pa
             JOIN category_attributes ca ON pa.id = ca.attribute_id
             WHERE ca.category_id = :cid
             ORDER BY ca.sort_order ASC, pa.name ASC",
            ['cid' => $categoryId]
        );
    }

    /**
     * Özellik + değerlerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWithValues(int $attributeId): array
    {
        return $this->rawQuery(
            "SELECT * FROM attribute_values
             WHERE attribute_id = :aid
             ORDER BY sort_order ASC, value ASC",
            ['aid' => $attributeId]
        );
    }
}
