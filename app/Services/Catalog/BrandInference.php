<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Services\Marketplace\TrendyolAttributeResolver;

/**
 * Finds the store brand a product without a brand names in its own title. Pure and unit tested.
 * A brand is taken only when its full name stands in the title as whole words; when several brands
 * are named, only the one the title starts with counts (titles lead with the brand).
 */
final class BrandInference
{
    /** Placeholder "brands" feeds create; they say nothing about the product. */
    private const STOP = ['diger', 'markasiz', 'marka', 'yok', 'genel', 'muhtelif', 'karisik', 'oyuncak', 'set', 'yeni', 'ithal', 'yerli'];

    /**
     * @param array<int|string,string> $brands id => name
     * @return array<string,list<array{id:int,key:string}>> first word => brands starting with it
     */
    public static function index(array $brands): array
    {
        $index = [];
        foreach ($brands as $id => $name) {
            $key = TrendyolAttributeResolver::mapKey((string)$name);
            if ($key === '' || mb_strlen($key) < 3 || ctype_digit(str_replace(' ', '', $key)) || in_array($key, self::STOP, true)) continue;
            $index[explode(' ', $key)[0]][] = ['id' => (int)$id, 'key' => $key];
        }
        return $index;
    }

    /** @return int|null brand id */
    public static function fromName(string $productName, array $index): ?int
    {
        $name = TrendyolAttributeResolver::mapKey($productName);
        if ($name === '') return null;
        $hay = ' '.$name.' ';
        $hits = [];
        foreach (array_unique(explode(' ', $name)) as $word) foreach ($index[$word] ?? [] as $brand) {
            if (str_contains($hay, ' '.$brand['key'].' ')) $hits[$brand['key']] = $brand['id'];
        }
        foreach (array_keys($hits) as $short) foreach (array_keys($hits) as $long) {
            if ($short !== $long && str_contains(' '.$long.' ', ' '.$short.' ')) { unset($hits[$short]); break; }
        }
        if (count(array_unique($hits)) === 1) return reset($hits);
        foreach ($hits as $key => $id) if (str_starts_with($hay, ' '.$key.' ')) return $id;
        return null;
    }
}
