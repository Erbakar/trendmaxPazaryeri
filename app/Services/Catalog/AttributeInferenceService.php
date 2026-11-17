<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\Container;
use App\Models\CategoryMarketplaceMapping;
use App\Services\Marketplace\{TrendyolAttributeCatalog,TrendyolAttributeResolver};

/**
 * Fills the required Trendyol attributes that block a category's products from what each product
 * says about itself (see AttributeInference). One category mapping per call, so the panel can walk
 * a large catalog request by request; a dry run reports what would change without writing.
 */
final class AttributeInferenceService
{
    public function __construct(private readonly Container $container, private readonly AttributeIssueReport $report) {}

    /**
     * @return array{checked:int,blocked:int,ready:int,written:int,attributes:array<string,array{name:string,text:int,neutral:int,none:int,samples:list<string>}>}
     */
    public function run(string $key, bool $allowNeutral, bool $apply, bool $originTr = false): array
    {
        $empty = ['checked' => 0, 'blocked' => 0, 'ready' => 0, 'written' => 0, 'attributes' => []];
        $mid = $this->report->marketplace();
        if ($mid === null || !ctype_digit($key)) return $empty;
        $mapping = null;
        foreach ($this->report->mappings($mid) as $entry) if ($entry['key'] === $key) { $mapping = $entry['mapping']; break; }
        if ($mapping === null) return $empty;
        $products = $this->report->pendingProducts($mapping, $mid, false);
        if ($products === []) return $empty;
        $attributes = $this->container->get(TrendyolAttributeCatalog::class)->get((string)$mapping['marketplace_category_id'])['attributes'];
        $plan = self::plan($mapping, $attributes, $products, $this->report->localAttributes(array_column($products, 'id')), $allowNeutral, $originTr);
        if ($apply && $plan['values'] !== []) {
            $this->container->get(CategoryMarketplaceMapping::class)->saveInferredAttributeValues((int)$key, $plan['values']);
            $this->report->refreshMapping($key);
        }
        return ['checked' => count($products), 'blocked' => $plan['blocked'], 'ready' => $plan['ready'],
            'written' => $apply ? $plan['written'] : 0, 'attributes' => $plan['attributes']];
    }

    /**
     * Pure, unit tested.
     * @param list<array{id:int|string,sku:string,name:string}> $products
     * @param array<int,list<array>> $locals
     * @return array{values:array<string,array<string,string>>,blocked:int,ready:int,written:int,attributes:array}
     */
    public static function plan(array $mapping, array $attributes, array $products, array $locals, bool $allowNeutral, bool $originTr = false): array
    {
        $attributes = TrendyolAttributeResolver::withOptionIndex($attributes);
        $byId = []; $prepared = [];
        foreach ($attributes as $a) $byId[(string)$a['id']] = $a;
        $values = []; $stats = []; $blocked = 0; $ready = 0; $written = 0;
        $config = CategoryMarketplaceMapping::decodeAttributeMapping($mapping);
        if (array_is_list($config)) $config = [];
        $existing = is_array($config['products'] ?? null) ? $config['products'] : [];
        // The check below needs only the product's own entry, not every product of the category.
        $slim = array_diff_key($config, ['products' => 1, 'inferred' => 1]);
        foreach ($products as $product) {
            $pid = (int)$product['id'];
            $result = TrendyolAttributeResolver::resolve(['id' => $pid], $mapping, $attributes, $locals[$pid] ?? []);
            if (!$result['errors']) continue;
            $blocked++;
            $targets = [];
            foreach ($result['missing'] as $m) if (empty($m['invalid'])) $targets[(string)$m['id']] = '';
            foreach ($result['unmatched'] as $u) if (!empty($u['required'])) $targets[(string)$u['id']] = (string)$u['value'];
            $own = [];
            foreach ($targets as $aid => $xmlValue) {
                $attribute = $byId[$aid] ?? null;
                if ($attribute === null) continue;
                $slot = &$stats[$aid];
                $slot = ($slot ?? []) + ['name' => (string)$attribute['name'], 'text' => 0, 'neutral' => 0, 'none' => 0, 'samples' => []];
                $hit = AttributeInference::suggest($attribute, array_values(array_filter([$xmlValue, (string)($product['name'] ?? '')], static fn($t) => $t !== '')), $allowNeutral, $originTr, $prepared[$aid] ??= AttributeInference::prepare($attribute));
                if ($hit === null) { $slot['none']++; unset($slot); continue; }
                $slot[in_array($hit['basis'], ['neutral', 'default'], true) ? 'neutral' : 'text']++;
                if (count($slot['samples']) < 3) $slot['samples'][] = mb_substr((string)($product['name'] ?? ''), 0, 70).' → '.$hit['option'];
                unset($slot);
                $own[$aid] = $hit['value'];
            }
            if ($own === []) continue;
            $values['id:'.$pid] = $own; $written += count($own);
        }
        // Second pass (keeps the decode cache of the first one warm): a product counts as ready only
        // if it really resolves with the new values.
        foreach ($values as $productKey => $own) {
            $pid = (int)substr($productKey, 3);
            $one = $slim + ['products' => [$productKey => $own + (is_array($existing[$productKey] ?? null) ? $existing[$productKey] : [])]];
            $after = TrendyolAttributeResolver::resolve(['id' => $pid], ['attribute_mapping_json' => json_encode($one, JSON_UNESCAPED_UNICODE)] + $mapping, $attributes, $locals[$pid] ?? []);
            if (!$after['errors']) $ready++;
        }
        return ['values' => $values, 'blocked' => $blocked, 'ready' => $ready, 'written' => $written, 'attributes' => $stats];
    }

    /** Removes every inferred value of the store's Trendyol mappings that was not edited by hand since. */
    public function undo(): array
    {
        $mid = $this->report->marketplace();
        if ($mid === null) return ['mappings' => 0, 'removed' => 0];
        $model = $this->container->get(CategoryMarketplaceMapping::class);
        $mappings = 0; $removed = 0;
        foreach ($this->report->mappings($mid) as $entry) {
            if ($entry['mapping'] === null || !str_contains((string)($entry['mapping']['attribute_mapping_json'] ?? ''), '"inferred"')) continue;
            $n = $model->clearInferredAttributeValues((int)$entry['key']);
            if ($n > 0) { $mappings++; $removed += $n; $this->report->refreshMapping($entry['key']); }
        }
        return ['mappings' => $mappings, 'removed' => $removed];
    }
}
