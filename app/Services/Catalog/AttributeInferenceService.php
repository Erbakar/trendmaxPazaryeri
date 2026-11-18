<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\Container;
use App\Core\Database;
use App\Models\{CategoryMarketplaceMapping,Product,ProductCategoryOverride};
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
    /** @param array<string,array<string,string>> $chosen Trendyol category id => [attribute id => value] */
    public function run(string $key, bool $allowNeutral, bool $apply, bool $originTr = false, array $chosen = []): array
    {
        $empty = ['checked' => 0, 'blocked' => 0, 'ready' => 0, 'written' => 0, 'attributes' => [], 'unresolved' => []];
        $mid = $this->report->marketplace();
        if ($mid === null || !ctype_digit($key)) return $empty;
        $mapping = null;
        foreach ($this->report->mappings($mid) as $entry) if ($entry['key'] === $key) { $mapping = $entry['mapping']; break; }
        if ($mapping === null) return $empty;
        $products = $this->report->pendingProducts($mapping, $mid, false);
        if ($products === []) return $empty;
        $attributes = $this->container->get(TrendyolAttributeCatalog::class)->get((string)$mapping['marketplace_category_id'])['attributes'];
        $target = (string)$mapping['marketplace_category_id'];
        $plan = self::plan($mapping, $attributes, $products, $this->report->localAttributes(array_column($products, 'id')), $allowNeutral, $originTr, (array)($chosen[$target] ?? []));
        if ($apply && $plan['values'] !== []) {
            $this->container->get(CategoryMarketplaceMapping::class)->saveInferredAttributeValues((int)$key, $plan['values']);
            $this->report->refreshMapping($key);
        }
        return ['checked' => count($products), 'blocked' => $plan['blocked'], 'ready' => $plan['ready'],
            'written' => $apply ? $plan['written'] : 0, 'attributes' => $plan['attributes'],
            'unresolved' => self::withTarget($plan['unresolved'], $target, (string)($mapping['marketplace_category_name'] ?? ''))];
    }

    /**
     * Pure, unit tested.
     * @param list<array{id:int|string,sku:string,name:string}> $products
     * @param array<int,list<array>> $locals
     * @return array{values:array<string,array<string,string>>,blocked:int,ready:int,written:int,attributes:array}
     */
    /**
     * @param array<string,string> $chosen attribute id => value the user chose for this Trendyol category, used
     *        only when the product itself says nothing (see unresolved in the result for what is still open)
     */
    public static function plan(array $mapping, array $attributes, array $products, array $locals, bool $allowNeutral, bool $originTr = false, array $chosen = []): array
    {
        $attributes = TrendyolAttributeResolver::withOptionIndex($attributes);
        $byId = []; $prepared = [];
        foreach ($attributes as $a) $byId[(string)$a['id']] = $a;
        $values = []; $stats = []; $unresolved = []; $blocked = 0; $ready = 0; $written = 0;
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
                if ($hit === null && isset($chosen[$aid]) && self::validChoice($attribute, (string)$chosen[$aid])) {
                    $hit = ['value' => (string)$chosen[$aid], 'option' => self::optionName($attribute, (string)$chosen[$aid]), 'basis' => 'default'];
                }
                if ($hit === null) {
                    $slot['none']++; unset($slot);
                    $open = &$unresolved[$aid];
                    $open = ($open ?? []) + ['aid' => (string)$aid, 'name' => (string)$attribute['name'], 'n' => 0, 'custom' => !empty($attribute['allowCustom']),
                        'multi' => !empty($attribute['allowMultipleAttributeValues']),
                        'options' => array_map(static fn($o) => ['id' => (string)$o['id'], 'name' => (string)$o['name']], array_slice($attribute['values'] ?? [], 0, 80)),
                        'option_count' => count($attribute['values'] ?? []), 'samples' => []];
                    $open['n']++;
                    if (count($open['samples']) < 4) $open['samples'][] = mb_substr((string)($product['name'] ?? ''), 0, 80);
                    unset($open);
                    continue;
                }
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
        return ['values' => $values, 'blocked' => $blocked, 'ready' => $ready, 'written' => $written, 'attributes' => $stats, 'unresolved' => array_values($unresolved)];
    }

    /** A chosen value must be one of the category's options (or any text for a free-text attribute). */
    public static function validChoice(array $attribute, string $value): bool
    {
        if (trim($value) === '') return false;
        if (!empty($attribute['allowCustom'])) return mb_strlen($value) <= 1000;
        foreach ($attribute['values'] ?? [] as $option) if ((string)$option['id'] === $value) return true;
        return false;
    }

    private static function optionName(array $attribute, string $value): string
    {
        foreach ($attribute['values'] ?? [] as $option) if ((string)$option['id'] === $value) return (string)$option['name'];
        return $value;
    }

    /**
     * Same for products that carry their own Trendyol category ("ürüne özel kategori seçimi", which the
     * source enrichment assigns): each is resolved against its own category's attributes and the values
     * are written to its own override. Works through one local category in chunks; pass the returned
     * last_id as $after until done.
     * @return array{processed:int,last_id:int,done:bool,checked:int,blocked:int,ready:int,written:int,attributes:array}
     */
    public function runOverrides(int $categoryId, int $after, int $limit, bool $allowNeutral, bool $apply, bool $originTr = false, array $chosen = []): array
    {
        $out = ['processed' => 0, 'last_id' => $after, 'done' => true, 'checked' => 0, 'blocked' => 0, 'ready' => 0, 'written' => 0, 'attributes' => [], 'unresolved' => []];
        $mid = $this->report->marketplace();
        if ($mid === null) return $out;
        $db = $this->container->get(Database::class);
        $store = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();
        $limit = max(1, min(500, $limit));
        $rows = $db->fetchAll('SELECT p.id FROM products p JOIN product_marketplace_category_overrides po ON po.store_id=p.store_id AND po.product_id=p.id AND po.marketplace_id=?
            WHERE p.store_id=? AND p.category_id=? AND p.id>? ORDER BY p.id LIMIT '.($limit + 1), [$mid, $store, $categoryId, $after]);
        $out['done'] = count($rows) <= $limit;
        $rows = array_slice($rows, 0, $limit);
        if ($rows === []) return $out;
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $out['processed'] = count($ids); $out['last_id'] = end($ids);
        $locals = $this->report->localAttributes($ids);
        $pendingById = $this->report->pendingAmong($ids, $mid);
        $products = $this->container->get(Product::class); $maps = $this->container->get(CategoryMarketplaceMapping::class);
        $catalog = $this->container->get(TrendyolAttributeCatalog::class); $overrides = $this->container->get(ProductCategoryOverride::class);
        foreach ($ids as $pid) {
            // Listings already live on Trendyol are left alone, exactly as the issue report does.
            if (!isset($pendingById[$pid])) continue;
            $pending = [$pendingById[$pid]];
            $product = $products->find($pid);
            $mapping = $product ? $maps->forProduct($product, $mid) : false;
            if (!$mapping || empty($mapping['_override_id'])) continue;
            $mapping['product_id'] = $pid;
            $out['checked']++;
            try { $attributes = $catalog->get((string)$mapping['marketplace_category_id'])['attributes']; }
            catch (\Throwable) { continue; } // counted by the issue report as a catalog error
            $target = (string)$mapping['marketplace_category_id'];
            $plan = self::plan($mapping, $attributes, $pending, [$pid => $locals[$pid] ?? []], $allowNeutral, $originTr, (array)($chosen[$target] ?? []));
            foreach (self::withTarget($plan['unresolved'], $target, (string)($mapping['marketplace_category_name'] ?? '')) as $open) {
                $k = $target.'|'.$open['aid'];
                if (!isset($out['unresolved'][$k])) { $out['unresolved'][$k] = $open; continue; }
                $out['unresolved'][$k]['n'] += $open['n'];
                foreach ($open['samples'] as $x) if (count($out['unresolved'][$k]['samples']) < 4) $out['unresolved'][$k]['samples'][] = $x;
            }
            $out['blocked'] += $plan['blocked']; $out['ready'] += $plan['ready'];
            foreach ($plan['attributes'] as $stat) {
                // Attribute ids repeat across Trendyol categories; the name is what the user reads.
                $slot = &$out['attributes'][$stat['name']];
                $slot = ($slot ?? []) + ['name' => $stat['name'], 'text' => 0, 'neutral' => 0, 'none' => 0, 'samples' => []];
                foreach (['text', 'neutral', 'none'] as $k) $slot[$k] += $stat[$k];
                foreach ($stat['samples'] as $sample) if (count($slot['samples']) < 3) $slot['samples'][] = $sample;
                unset($slot);
            }
            if ($apply && isset($plan['values']['id:'.$pid])) {
                $overrides->mergeInferredAttributes((int)$mapping['_override_id'], $pid, $plan['values']['id:'.$pid]);
                $out['written'] += $plan['written'];
            }
        }
        // The per-product report rows are recounted by the report's own pass instead of one by one here.
        if ($apply && $out['written'] > 0) $this->report->request();
        $out['unresolved'] = array_values($out['unresolved']);
        return $out;
    }

    private static function withTarget(array $unresolved, string $target, string $targetName): array
    {
        return array_map(static fn(array $o) => ['target' => $target, 'target_name' => $targetName] + $o, $unresolved);
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
        $own = $this->container->get(ProductCategoryOverride::class)->clearInferredAttributes($mid);
        if ($own > 0) { $removed += $own; $this->report->request(); }
        return ['mappings' => $mappings, 'removed' => $removed];
    }
}
