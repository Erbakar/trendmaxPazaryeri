<?php
/**
 * OzyConnect Panel — AttributeValue Model
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

use App\Helpers\StringHelper;

final class AttributeValue extends BaseModel
{
    protected string $table = 'attribute_values';
    protected bool $tenantScoped = false; // GLOBAL paylasimli referans (store_id YOK)

    /**
     * Özelliğin değerlerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByAttribute(int $attributeId): array
    {
        return $this->all(['attribute_id' => $attributeId], 'sort_order ASC, value ASC');
    }

    /**
     * Değer bul veya oluştur
     */
    public function findOrCreate(int $attributeId, string $value): int
    {
        $existing = $this->rawQueryOne(
            "SELECT id FROM attribute_values
             WHERE attribute_id = :aid AND LOWER(value) = LOWER(:val) LIMIT 1",
            ['aid' => $attributeId, 'val' => $value]
        );

        if ($existing !== false) {
            return (int) $existing['id'];
        }

        return $this->create([
            'attribute_id' => $attributeId,
            'value'        => $value,
            'slug'         => StringHelper::slugify($value),
        ]);
    }
}
