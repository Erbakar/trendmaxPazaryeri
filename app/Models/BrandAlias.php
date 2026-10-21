<?php
/**
 * OzyConnect Panel — BrandAlias Model
 * Marka takma adları (alias) yönetimi
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

final class BrandAlias extends BaseModel
{
    protected string $table = 'brand_aliases';

    /**
     * Normalize edilmiş isimle bul
     *
     * @return array<string, mixed>|false
     */
    public function findByNormalizedName(string $normalizedName): array|false
    {
        return $this->findBy(['normalized_name' => $normalizedName]);
    }

    /**
     * Orijinal alias ismiyle bul
     *
     * @return array<string, mixed>|false
     */
    public function findByAliasName(string $aliasName): array|false
    {
        return $this->findBy(['alias_name' => $aliasName]);
    }

    /**
     * Bir markanın tüm takma adlarını getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByBrand(int $brandId): array
    {
        return $this->all(['brand_id' => $brandId], 'alias_name ASC');
    }

    /**
     * Takma ad ekle (normalized_name otomatik hesaplanır)
     *
     * @throws \RuntimeException Duplicate alias
     */
    public function addAlias(int $brandId, string $aliasName): int
    {
        $normalizedName = StringHelper::normalize($aliasName);

        // Duplicate kontrolü
        $existing = $this->findByAliasName($aliasName);
        if ($existing !== false) {
            throw new \RuntimeException("Bu takma ad zaten tanımlı: {$aliasName}");
        }

        return $this->create([
            'brand_id'        => $brandId,
            'alias_name'      => $aliasName,
            'normalized_name' => $normalizedName,
        ]);
    }
}
