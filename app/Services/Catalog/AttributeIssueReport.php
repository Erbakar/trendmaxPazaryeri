<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\{Container,Database,TenantContext};
use App\Models\{CategoryMarketplaceMapping,Marketplace,Product,StoreSetting};
use App\Services\Marketplace\{TrendyolAttributeCatalog,TrendyolAttributeResolver};

/**
 * Category-level summary of products that cannot be sent to Trendyol because a required attribute
 * has no value or an XML value has no matching Trendyol option. Each Trendyol category mapping is
 * one unit: it is recomputed and replaced as a whole, so a partial pass never mixes old and new rows.
 * Only products not already live on Trendyol are counted; approved listings are not re-validated here.
 */
final class AttributeIssueReport
{
    public const STATE_KEY = 'attribute_issues_state';
    /** Own key: a request made while a pass is running must survive that pass's state writes. */
    public const REQUEST_KEY = 'attribute_issues_requested';
    public const TICK_KEY = 'attribute_issues_tick_at';
    private const SAMPLE = 5;

    public function __construct(private readonly Container $container, private readonly Database $db,
        private readonly TenantContext $tenant, private readonly StoreSetting $settings) {}

    private function store(): int { return $this->tenant->currentStoreId(); }

    public function marketplace(): ?int
    {
        $mp = $this->container->get(Marketplace::class)->findBySlug('trendyol');
        return $mp && !empty($mp['is_active']) ? (int)$mp['id'] : null;
    }

    public static function interval(): int
    {
        // "Günde birkaç kez": default every 6 hours; a saved mapping or the refresh button queues it sooner.
        return max(900, (int)(function_exists('env') ? env('ATTRIBUTE_ISSUES_INTERVAL_SECONDS', 21600) : 21600));
    }

    public function state(): array
    {
        $state = json_decode((string)($this->settings->getValue(self::STATE_KEY, '{}') ?? '{}'), true);
        return is_array($state) ? $state : [];
    }

    private function saveState(array $state): void
    {
        $this->settings->setValue(self::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function request(): void
    {
        $this->settings->setValue(self::REQUEST_KEY, sprintf('%.6F', microtime(true)));
    }

    private function requestedAt(): float
    {
        return (float)($this->settings->getValue(self::REQUEST_KEY, '0') ?? '0');
    }

    public function due(): bool
    {
        $state = $this->state();
        if (!empty($state['cursor_active'])) return true;
        // A request counts when it arrived after the last pass started (that pass may have missed it).
        if ($this->requestedAt() > (float)($state['request_seen'] ?? 0)) return true;
        return strtotime((string)($state['refreshed_at'] ?? '1970-01-01')) < time() - self::interval();
    }

    /** @return list<array{key:string,mapping:array}> */
    private function mappings(int $mid): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT m.*, c.name AS category_name FROM category_marketplace_mapping m LEFT JOIN categories c ON c.id=m.category_id WHERE m.store_id=? AND m.marketplace_id=? ORDER BY m.id', [$this->store(), $mid]) as $row) {
            $out[] = ['key' => (string)$row['id'], 'mapping' => $row];
        }
        foreach ($this->db->fetchAll('SELECT po.product_id FROM product_marketplace_category_overrides po WHERE po.store_id=? AND po.marketplace_id=? ORDER BY po.product_id', [$this->store(), $mid]) as $row) {
            $out[] = ['key' => 'product-'.(int)$row['product_id'].'-'.$mid, 'mapping' => null, 'product_id' => (int)$row['product_id']];
        }
        return $out;
    }

    private function resolveMapping(array $entry, int $mid): ?array
    {
        if ($entry['mapping'] !== null) return $entry['mapping'];
        $product = $this->container->get(Product::class)->find((int)$entry['product_id']);
        if (!$product) return null;
        $mapping = $this->container->get(CategoryMarketplaceMapping::class)->forProduct($product, $mid);
        if (!$mapping || empty($mapping['_override_id'])) return null;
        $mapping['product_id'] = (int)$product['id'];
        $mapping['category_name'] = (string)($product['name'] ?? '');
        return $mapping;
    }

    /**
     * Advances the store's pass within the deadline; resumes from the saved cursor next time.
     * @return array<string,mixed>
     */
    public function tick(float $deadline): array
    {
        $mid = $this->marketplace();
        if ($mid === null) return ['skipped' => 'Trendyol aktif değil'];
        $state = $this->state();
        $this->settings->setValue(self::TICK_KEY, date('Y-m-d H:i:s'));
        if (empty($state['cursor_active'])) {
            // Requests up to this instant are covered by the pass starting now; later ones start another.
            $state = ['cursor_active' => true, 'started_at' => date('Y-m-d H:i:s'), 'seen' => [], 'request_seen' => microtime(true)] + $state;
            unset($state['error']);
        }
        $processed = 0;
        $entries = $this->mappings($mid);
        foreach ($entries as $entry) {
            if (in_array($entry['key'], $state['seen'] ?? [], true)) continue;
            if (microtime(true) > $deadline) { $this->saveState($state); return ['partial' => true, 'processed' => $processed]; }
            try { $this->refreshEntry($entry, $mid); }
            catch (\Throwable $e) { $state['error'] = self::publicError($e); error_log('[attribute_issues] store '.$this->store().' mapping '.$entry['key'].': '.$e->getMessage()); }
            $state['seen'][] = $entry['key']; $processed++;
            if ($processed % 5 === 0) $this->saveState($state);
        }
        // Mappings deleted since the last pass must not keep reporting old problems.
        $keys = array_column($entries, 'key');
        if ($keys === []) $this->db->query('DELETE FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=?', [$this->store(), $mid]);
        else $this->db->query('DELETE FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=? AND mapping_key NOT IN ('.implode(',', array_fill(0, count($keys), '?')).')', [$this->store(), $mid, ...$keys]);
        $summary = $this->summary();
        $state = ['refreshed_at' => date('Y-m-d H:i:s'), 'started_at' => $state['started_at'], 'request_seen' => (float)($state['request_seen'] ?? 0),
            'error' => $state['error'] ?? null, 'products_blocked' => $summary['products_blocked'], 'categories_blocked' => $summary['categories_blocked']];
        $this->saveState($state);
        return ['complete' => true, 'processed' => $processed, 'products_blocked' => $summary['products_blocked']];
    }

    /** Recount one mapping right after the user saves it. @return array<string,mixed>|null */
    public function refreshMapping(string $key): ?array
    {
        $mid = $this->marketplace();
        if ($mid === null) return null;
        foreach ($this->mappings($mid) as $entry) if ($entry['key'] === $key) { $this->refreshEntry($entry, $mid); return $this->forMapping($key); }
        return null;
    }

    private function refreshEntry(array $entry, int $mid): void
    {
        $mapping = $this->resolveMapping($entry, $mid);
        $key = $entry['key'];
        $rows = [];
        $base = ['category_id' => isset($mapping['category_id']) ? (int)$mapping['category_id'] : null,
            'category_name' => mb_substr((string)($mapping['category_name'] ?? ''), 0, 255),
            'marketplace_category_id' => (string)($mapping['marketplace_category_id'] ?? ''),
            'marketplace_category_name' => mb_substr((string)($mapping['marketplace_category_name'] ?? ''), 0, 500)];
        if ($mapping !== null) {
            $products = $this->pendingProducts($mapping, $mid, $entry['mapping'] === null);
            if ($products !== []) {
                try {
                    $catalog = $this->container->get(TrendyolAttributeCatalog::class)->get((string)$mapping['marketplace_category_id']);
                    $rows = self::aggregate($mapping, $catalog['attributes'], $products, $this->localAttributes(array_column($products, 'id')));
                } catch (\Throwable $e) {
                    $rows = [['issue_type' => 'catalog_error', 'product_count' => count($products), 'checked_count' => count($products),
                        'message' => mb_substr('Trendyol kategori özellikleri alınamadı: '.self::publicError($e), 0, 1000),
                        'sample_skus' => array_slice(array_column($products, 'sku'), 0, self::SAMPLE)]];
                }
            } else $rows = [['issue_type' => 'summary', 'product_count' => 0, 'checked_count' => 0]];
        }
        $this->db->transaction(function () use ($mid, $key, $rows, $base): void {
            $this->db->query('DELETE FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=? AND mapping_key=?', [$this->store(), $mid, $key]);
            foreach ($rows as $row) {
                $row += ['attribute_id' => '', 'attribute_name' => '', 'value_text' => '', 'is_varianter' => 0, 'product_count' => 0, 'checked_count' => 0, 'sample_skus' => [], 'message' => ''];
                $this->db->query('INSERT INTO trendyol_attribute_issues (store_id,marketplace_id,mapping_key,category_id,category_name,marketplace_category_id,marketplace_category_name,issue_type,attribute_id,attribute_name,value_text,is_varianter,product_count,checked_count,sample_skus,message,refreshed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                    [$this->store(), $mid, $key, $base['category_id'], $base['category_name'], $base['marketplace_category_id'], $base['marketplace_category_name'],
                     $row['issue_type'], mb_substr((string)$row['attribute_id'], 0, 32), mb_substr((string)$row['attribute_name'], 0, 255), mb_substr((string)$row['value_text'], 0, 300),
                     (int)$row['is_varianter'], (int)$row['product_count'], (int)$row['checked_count'], json_encode(array_values($row['sample_skus']), JSON_UNESCAPED_UNICODE), (string)$row['message']]);
            }
        });
    }

    /** Products of this mapping that are not already live on Trendyol (new, rejected or never sent). */
    private function pendingProducts(array $mapping, int $mid, bool $override): array
    {
        $params = [$this->store()];
        if ($override) { $scope = 'p.id=?'; $params[] = (int)$mapping['product_id']; }
        else {
            $scope = 'p.category_id=? AND NOT EXISTS (SELECT 1 FROM product_marketplace_category_overrides po WHERE po.store_id=p.store_id AND po.product_id=p.id AND po.marketplace_id=?)';
            array_push($params, (int)$mapping['category_id'], $mid);
        }
        // Live = the latest catalog check saw it on Trendyol (not rejected); without a check, a listed row with a remote id.
        return $this->db->fetchAll("SELECT p.id, p.sku, p.name FROM products p
            WHERE p.store_id=? AND $scope AND COALESCE(p.is_active,1)=1 AND COALESCE(p.is_archived,0)=0
              AND NOT EXISTS (SELECT 1 FROM catalog_audit_items ai WHERE ai.store_id=p.store_id AND ai.marketplace_id=? AND ai.product_id=p.id
                    AND ai.status NOT IN ('rejected','not_observed') AND ai.remote_json IS NOT NULL AND ai.remote_json<>'null')
              AND NOT (NOT EXISTS (SELECT 1 FROM catalog_audit_items ai2 WHERE ai2.store_id=p.store_id AND ai2.marketplace_id=? AND ai2.product_id=p.id AND ai2.status<>'not_observed')
                   AND EXISTS (SELECT 1 FROM marketplace_products mp WHERE mp.store_id=p.store_id AND mp.marketplace_id=? AND mp.product_id=p.id AND mp.is_listed=1 AND COALESCE(mp.marketplace_product_id,'')<>''))
            ORDER BY p.id", [...$params, $mid, $mid, $mid]);
    }

    /** @param list<int> $ids @return array<int,list<array>> */
    private function localAttributes(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            $rows = $this->db->fetchAll('SELECT pav.product_id, pav.attribute_id, pav.attribute_value_id, pav.custom_value, pa.name AS attribute_name, av.value AS attribute_value_text
                FROM product_attribute_values pav JOIN product_attributes pa ON pa.id=pav.attribute_id LEFT JOIN attribute_values av ON av.id=pav.attribute_value_id
                WHERE pav.store_id=? AND pav.product_id IN ('.implode(',', array_fill(0, count($chunk), '?')).') ORDER BY pa.sort_order, pa.name', [$this->store(), ...$chunk]);
            foreach ($rows as $row) $out[(int)$row['product_id']][] = $row;
        }
        return $out;
    }

    /**
     * Pure aggregation, unit tested: resolver output of every product grouped by attribute (and XML value).
     * @param list<array{id:int|string,sku:string}> $products
     * @param array<int,list<array>> $locals
     * @return list<array<string,mixed>>
     */
    public static function aggregate(array $mapping, array $attributes, array $products, array $locals): array
    {
        $byId = array_column($attributes, null, 'id');
        $missing = []; $unmatched = []; $other = []; $blocked = 0;
        foreach ($products as $product) {
            $result = TrendyolAttributeResolver::resolve(['id' => (int)$product['id']], $mapping, $attributes, $locals[(int)$product['id']] ?? []);
            if ($result['errors']) $blocked++;
            foreach ($result['other'] ?? [] as $o) {
                $slot = &$other[(string)$o['id']];
                $slot['count'] = ($slot['count'] ?? 0) + 1; $slot['name'] = $o['name']; $slot['error'] = $o['error'];
                if (count($slot['skus'] ?? []) < self::SAMPLE) $slot['skus'][] = (string)$product['sku'];
                unset($slot);
            }
            foreach ($result['missing'] as $m) {
                $slot = &$missing[(string)$m['id']];
                $slot['count'] = ($slot['count'] ?? 0) + 1; $slot['name'] = $m['name'];
                if (count($slot['skus'] ?? []) < self::SAMPLE) $slot['skus'][] = (string)$product['sku'];
                unset($slot);
            }
            foreach ($result['unmatched'] as $u) {
                $slot = &$unmatched[(string)$u['id']][TrendyolAttributeResolver::normalize($u['value'])];
                $slot['count'] = ($slot['count'] ?? 0) + 1; $slot['name'] = $u['name']; $slot['value'] = $u['value']; $slot['required'] = $u['required'];
                if (count($slot['skus'] ?? []) < self::SAMPLE) $slot['skus'][] = (string)$product['sku'];
                unset($slot);
            }
        }
        $rows = [['issue_type' => 'summary', 'product_count' => $blocked, 'checked_count' => count($products)]];
        foreach ($missing as $aid => $m) $rows[] = ['issue_type' => 'missing', 'attribute_id' => (string)$aid, 'attribute_name' => $m['name'],
            'is_varianter' => !empty($byId[$aid]['varianter']) || !empty($byId[$aid]['slicer']) ? 1 : 0, 'product_count' => $m['count'], 'sample_skus' => $m['skus']];
        foreach ($unmatched as $aid => $values) foreach ($values as $u) $rows[] = ['issue_type' => 'unmatched', 'attribute_id' => (string)$aid, 'attribute_name' => $u['name'],
            'value_text' => $u['value'], 'is_varianter' => !empty($byId[$aid]['varianter']) || !empty($byId[$aid]['slicer']) ? 1 : 0,
            'product_count' => $u['count'], 'sample_skus' => $u['skus'], 'message' => $u['required'] ? 'required' : 'optional'];
        foreach ($other as $aid => $o) $rows[] = ['issue_type' => 'invalid', 'attribute_id' => (string)$aid, 'attribute_name' => $o['name'],
            'is_varianter' => !empty($byId[$aid]['varianter']) || !empty($byId[$aid]['slicer']) ? 1 : 0, 'product_count' => $o['count'], 'sample_skus' => $o['skus'],
            'message' => mb_substr((string)$o['error'], 0, 1000)];
        return $rows;
    }

    /** Error text safe to show in the panel and to the support API (no SQL or file paths). */
    public static function publicError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof \PDOException || str_contains($message, 'SQLSTATE')) return 'Veritabanı hatası; ayrıntı sunucu günlüğünde.';
        return mb_substr((string)preg_replace('#(/[\w.\-]+){2,}#', '…', $message), 0, 300);
    }

    private static function decodeRow(array $row): array
    {
        $row['sample_skus'] = json_decode((string)($row['sample_skus'] ?? '[]'), true) ?: [];
        foreach (['product_count', 'checked_count', 'is_varianter'] as $k) $row[$k] = (int)$row[$k];
        return $row;
    }

    /** @return array{refreshed_at:?string,blocked:int,checked:int,missing:list<array>,unmatched:list<array>,errors:list<array>} */
    public function forMapping(string $key): array
    {
        $mid = $this->marketplace();
        $rows = $mid === null ? [] : array_map([self::class, 'decodeRow'], $this->db->fetchAll('SELECT * FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=? AND mapping_key=? ORDER BY issue_type, product_count DESC, attribute_name, value_text', [$this->store(), $mid, $key]));
        $out = ['refreshed_at' => null, 'blocked' => 0, 'checked' => 0, 'missing' => [], 'unmatched' => [], 'invalid' => [], 'errors' => []];
        foreach ($rows as $row) {
            $out['refreshed_at'] = $row['refreshed_at'];
            if ($row['issue_type'] === 'summary') { $out['blocked'] = $row['product_count']; $out['checked'] = $row['checked_count']; }
            elseif ($row['issue_type'] === 'missing') $out['missing'][] = $row;
            elseif ($row['issue_type'] === 'unmatched') $out['unmatched'][] = $row;
            elseif ($row['issue_type'] === 'invalid') $out['invalid'][] = $row;
            else { $out['errors'][] = $row; $out['blocked'] += $row['product_count']; $out['checked'] += $row['checked_count']; }
        }
        return $out;
    }

    /** Store-wide counts for the panel banner and the support API. */
    public function summary(): array
    {
        $mid = $this->marketplace();
        $empty = ['products_blocked' => 0, 'products_checked' => 0, 'categories_blocked' => 0, 'missing_attributes' => 0, 'unmatched_values' => 0, 'catalog_errors' => 0];
        if ($mid === null) return $empty + ['state' => $this->state()];
        $row = $this->db->fetchOne("SELECT
                COALESCE(SUM(CASE WHEN issue_type='summary' THEN product_count END),0) AS products_blocked,
                COALESCE(SUM(CASE WHEN issue_type IN ('summary','catalog_error') THEN checked_count END),0) AS products_checked,
                COUNT(DISTINCT CASE WHEN (issue_type='summary' AND product_count>0) OR issue_type='catalog_error' THEN mapping_key END) AS categories_blocked,
                COUNT(CASE WHEN issue_type='missing' THEN 1 END) AS missing_attributes,
                COUNT(CASE WHEN issue_type='unmatched' THEN 1 END) AS unmatched_values,
                COALESCE(SUM(CASE WHEN issue_type='catalog_error' THEN product_count END),0) AS catalog_errors
            FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=?", [$this->store(), $mid]) ?: [];
        $out = array_map('intval', array_intersect_key($row, $empty)) + $empty;
        $out['products_blocked'] += $out['catalog_errors'];
        return $out + ['state' => $this->state()];
    }

    /** Per-mapping blocked counts for the category mapping list. @return array<string,int> */
    public function blockedByMapping(): array
    {
        $mid = $this->marketplace();
        if ($mid === null) return [];
        $out = [];
        foreach ($this->db->fetchAll("SELECT mapping_key, SUM(product_count) n FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=? AND issue_type IN ('summary','catalog_error') GROUP BY mapping_key", [$this->store(), $mid]) as $row) $out[(string)$row['mapping_key']] = (int)$row['n'];
        return $out;
    }

    /** Detailed rows for the support API, largest problems first. */
    public function rows(int $limit = 500): array
    {
        $mid = $this->marketplace();
        if ($mid === null) return [];
        return array_map([self::class, 'decodeRow'], $this->db->fetchAll("SELECT mapping_key,category_id,category_name,marketplace_category_id,marketplace_category_name,issue_type,attribute_id,attribute_name,value_text,is_varianter,product_count,checked_count,sample_skus,message,refreshed_at
            FROM trendyol_attribute_issues WHERE store_id=? AND marketplace_id=? AND issue_type<>'summary' ORDER BY product_count DESC, id LIMIT ".max(1, min(2000, $limit)), [$this->store(), $mid]));
    }
}
