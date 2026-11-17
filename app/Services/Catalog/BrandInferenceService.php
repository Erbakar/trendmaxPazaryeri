<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\{Database,TenantContext};
use App\Models\StoreSetting;

/**
 * Gives products that have no brand the store brand named in their title (see BrandInference).
 * Products that already have a brand are never touched. What was assigned is remembered so it can
 * be undone.
 */
final class BrandInferenceService
{
    public const ASSIGNED_KEY = 'brand_inference_assigned';
    private const SAMPLE = 8;

    public function __construct(private readonly Database $db, private readonly TenantContext $tenant, private readonly StoreSetting $settings) {}

    private function store(): int { return $this->tenant->currentStoreId(); }

    /** @return array<string,int> product id => brand id */
    private function assigned(): array
    {
        $rows = json_decode((string)($this->settings->getValue(self::ASSIGNED_KEY, '{}') ?? '{}'), true);
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    public function hasAssigned(): bool { return $this->assigned() !== []; }

    /**
     * @return array{without_brand:int,found:int,mapped:int,assigned:int,brands:list<array{name:string,count:int,mapped:bool}>,samples:list<string>}
     */
    public function run(int $marketplaceId, bool $apply): array
    {
        $brands = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM brands WHERE store_id=? AND COALESCE(is_active,1)=1', [$this->store()]) as $row) $brands[(int)$row['id']] = (string)$row['name'];
        $mapped = array_flip(array_map('intval', array_column($this->db->fetchAll('SELECT DISTINCT brand_id FROM brand_marketplace_mapping WHERE store_id=? AND marketplace_id=?', [$this->store(), $marketplaceId]), 'brand_id')));
        $index = BrandInference::index($brands);
        $products = $this->db->fetchAll('SELECT id, name FROM products WHERE store_id=? AND brand_id IS NULL AND COALESCE(is_active,1)=1 AND COALESCE(is_archived,0)=0 ORDER BY id', [$this->store()]);
        $byBrand = []; $samples = [];
        foreach ($products as $product) {
            $brandId = BrandInference::fromName((string)$product['name'], $index);
            if ($brandId === null) continue;
            $byBrand[$brandId][] = (int)$product['id'];
            if (count($samples) < self::SAMPLE) $samples[] = mb_substr((string)$product['name'], 0, 70).' → '.$brands[$brandId];
        }
        $found = 0; $foundMapped = 0; $list = [];
        foreach ($byBrand as $brandId => $ids) {
            $found += count($ids);
            if (isset($mapped[$brandId])) $foundMapped += count($ids);
            $list[] = ['name' => $brands[$brandId], 'count' => count($ids), 'mapped' => isset($mapped[$brandId])];
        }
        usort($list, static fn($a, $b) => $b['count'] <=> $a['count']);
        $assigned = 0;
        if ($apply && $byBrand !== []) {
            $record = $this->assigned();
            $this->db->transaction(function () use ($byBrand, &$record, &$assigned): void {
                foreach ($byBrand as $brandId => $ids) foreach (array_chunk($ids, 500) as $chunk) {
                    // brand_id IS NULL again: a product given a brand since the read is left alone.
                    $this->db->query('UPDATE products SET brand_id=? WHERE store_id=? AND brand_id IS NULL AND id IN ('.implode(',', array_fill(0, count($chunk), '?')).')', [$brandId, $this->store(), ...$chunk]);
                    foreach ($chunk as $id) $record[(string)$id] = $brandId;
                    $assigned += count($chunk);
                }
            });
            $this->settings->setValue(self::ASSIGNED_KEY, json_encode($record, JSON_THROW_ON_ERROR));
        }
        return ['without_brand' => count($products), 'found' => $found, 'mapped' => $foundMapped, 'assigned' => $assigned,
            'brands' => array_slice($list, 0, 40), 'samples' => $samples];
    }

    /** Clears the brands this tool assigned, unless the product's brand was changed by hand since. */
    public function undo(): int
    {
        $byBrand = [];
        foreach ($this->assigned() as $productId => $brandId) $byBrand[$brandId][] = (int)$productId;
        $removed = 0;
        foreach ($byBrand as $brandId => $ids) foreach (array_chunk($ids, 500) as $chunk) {
            $removed += $this->db->query('UPDATE products SET brand_id=NULL WHERE store_id=? AND brand_id=? AND id IN ('.implode(',', array_fill(0, count($chunk), '?')).')', [$this->store(), $brandId, ...$chunk])->rowCount();
        }
        $this->settings->setValue(self::ASSIGNED_KEY, '{}');
        return $removed;
    }
}
