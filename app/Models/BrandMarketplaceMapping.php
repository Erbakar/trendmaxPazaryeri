<?php
/**
 * OzyConnect Panel — BrandMarketplaceMapping Model
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

final class BrandMarketplaceMapping extends BaseModel
{
    protected string $table = 'brand_marketplace_mapping';

    /**
     * Eşleştirme bul
     *
     * @return array<string, mixed>|false
     */
    public function findMapping(int $brandId, int $marketplaceId): array|false
    {
        return $this->findBy([
            'brand_id'       => $brandId,
            'marketplace_id' => $marketplaceId,
        ]);
    }

    /**
     * Pazaryeri marka ID'si ile iç marka bul
     *
     * @return array<string, mixed>|false
     */
    public function findByMarketplaceBrand(int $marketplaceId, string $mpBrandId): array|false
    {
        return $this->findBy([
            'marketplace_id'       => $marketplaceId,
            'marketplace_brand_id' => $mpBrandId,
        ]);
    }

    /**
     * Eşleştirme kaydet veya güncelle
     */
    public function saveMapping(int $brandId, int $marketplaceId, string $mpBrandId, ?string $mpBrandName = null): int
    {
        $existing = $this->findMapping($brandId, $marketplaceId);

        if ($existing !== false) {
            $this->update((int) $existing['id'], [
                'marketplace_brand_id'   => $mpBrandId,
                'marketplace_brand_name' => $mpBrandName,
            ]);
            return (int) $existing['id'];
        }

        return $this->create([
            'brand_id'               => $brandId,
            'marketplace_id'         => $marketplaceId,
            'marketplace_brand_id'   => $mpBrandId,
            'marketplace_brand_name' => $mpBrandName,
        ]);
    }

    /**
     * Eşleştirilmemiş markalara sahip ürün sayısını getir
     */
    public function getUnmappedBrandCount(int $marketplaceId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(DISTINCT p.brand_id) as cnt
             FROM products p
             WHERE p.brand_id IS NOT NULL
               AND p.is_active = 1
               AND p.store_id = :store_id
               AND p.brand_id NOT IN (
                   SELECT bmm.brand_id
                   FROM brand_marketplace_mapping bmm
                   WHERE bmm.marketplace_id = :mid
                     AND bmm.store_id = :store_id2
               )",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Tüm iç markaları eşleştirme durumuyla getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBrandsWithStatus(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT b.id, b.name,
                    bmm.id as mapping_id,
                    bmm.marketplace_brand_id,
                    bmm.marketplace_brand_name,
                    (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.is_active = 1 AND p.store_id = :store_id) as product_count
             FROM brands b
             LEFT JOIN brand_marketplace_mapping bmm
                 ON b.id = bmm.brand_id AND bmm.marketplace_id = :mid AND bmm.store_id = :store_id2
             WHERE b.is_active = 1
               AND b.store_id = :store_id3
             ORDER BY b.name ASC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId(), 'store_id3' => $this->tenantId()]
        );
    }

    /**
     * Pazaryerine ait tüm mapping'leri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllByMarketplace(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT bmm.brand_id, bmm.marketplace_brand_name
             FROM brand_marketplace_mapping bmm
             WHERE bmm.marketplace_id = :mid
               AND bmm.store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Eşleştirilmemiş pazaryeri markalarını getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnmappedMarketplaceBrands(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT
                COALESCE(mp.marketplace_brand_id, 'Bilinmeyen') as raw_brand_name,
                COUNT(DISTINCT p.id) as product_count
             FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id AND mp.marketplace_id = :mid AND mp.store_id = :store_id2
             WHERE p.needs_brand_mapping = 1
               AND p.is_active = 1
               AND p.store_id = :store_id
             GROUP BY raw_brand_name
             ORDER BY product_count DESC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Belirli markanın tüm pazaryeri eşleştirmelerini getir
     */
    public function getMappingsForBrand(int $brandId): array
    {
        return $this->rawQuery(
            "SELECT bmm.*, m.slug as marketplace_slug, m.display_name as marketplace_name
             FROM brand_marketplace_mapping bmm
             JOIN marketplaces m ON bmm.marketplace_id = m.id
             WHERE bmm.brand_id = :bid
               AND bmm.store_id = :store_id
             ORDER BY m.display_name ASC",
            ['bid' => $brandId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Batch: birden fazla markanın tüm eşleştirmelerini tek sorguda getir
     * Returns: [brand_id => [mapping1, mapping2, ...], ...]
     */
    public function getMappingsForBrands(array $brandIds): array
    {
        if (empty($brandIds)) return [];
        $placeholders = implode(',', array_fill(0, count($brandIds), '?'));
        $params = array_values($brandIds);
        $params[] = $this->tenantId();
        $rows = $this->rawQuery(
            "SELECT bmm.*, m.slug as marketplace_slug, m.display_name as marketplace_name
             FROM brand_marketplace_mapping bmm
             JOIN marketplaces m ON bmm.marketplace_id = m.id
             WHERE bmm.brand_id IN ({$placeholders})
               AND bmm.store_id = ?
             ORDER BY m.display_name ASC",
            $params
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['brand_id']][] = $row;
        }
        return $result;
    }

    /**
     * Pazaryerine göre mapping sayısı
     */
    public function countByMarketplace(int $marketplaceId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM brand_marketplace_mapping WHERE marketplace_id = :mid AND store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * En az 1 pazaryeriyle eşleşmiş toplam marka sayısı
     */
    public function countMappedBrands(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(DISTINCT brand_id) as cnt FROM brand_marketplace_mapping WHERE store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Pazaryerine ait tüm mapping'leri sil
     */
    public function deleteByMarketplace(int $marketplaceId): int
    {
        $stmt = $this->db->query(
            "DELETE FROM brand_marketplace_mapping WHERE marketplace_id = :mid AND store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
        return $stmt->rowCount();
    }
}
