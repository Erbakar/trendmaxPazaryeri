<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\{Container,Database,TenantContext};
use App\Models\{CategoryMarketplaceMapping,Marketplace,ProductAttribute,StoreSetting};
use App\Services\Marketplace\TrendyolAttributeCatalog;
use App\Services\Support\AttributeTemplateService;

/**
 * "Tek kaynak": all stores live in one database, so a Trendyol category attribute fix saved in one store
 * is published here and applied by cron to every other store that maps the same Trendyol category.
 * Each store keeps its own decisions; only undecided or library-owned entries are written, and the
 * receiving store's own Trendyol list re-validates every option. Variant (renk/beden) fixed values and
 * fixed values over a field the store's products fill themselves are never carried (same rules as the
 * support-panel template import).
 */
final class AttributeLibrary
{
    public const ENABLED_KEY = 'attribute_library_enabled';
    public const CURSOR_KEY = 'attribute_library_cursor';

    public function __construct(private readonly Container $container, private readonly Database $db,
        private readonly TenantContext $tenant, private readonly StoreSetting $settings) {}

    private function store(): int { return $this->tenant->currentStoreId(); }

    public function enabled(): bool
    {
        return $this->settings->getValue(self::ENABLED_KEY, '1') !== '0';
    }

    public function setEnabled(bool $on): void
    {
        $this->settings->setValue(self::ENABLED_KEY, $on ? '1' : '0');
    }

    private function marketplace(): ?int
    {
        $mp = $this->container->get(Marketplace::class)->findBySlug('trendyol');
        return $mp && !empty($mp['is_active']) ? (int)$mp['id'] : null;
    }

    /**
     * Called after a user saves a category-level mapping in the active store. The store's entries are
     * laid over the library's current template (so a store that has not synced yet cannot wipe what
     * others published); entries the user deliberately removed after receiving them from the library
     * ($removed) are withdrawn for everyone.
     * @param array{defaults?:list<string>,sources?:list<string>,value_map?:array<string,list<string>>} $removed
     */
    public function publish(int $mappingId, array $removed = []): bool
    {
        if (!$this->enabled()) return false;
        $row = $this->db->fetchOne('SELECT m.id, m.marketplace_category_id, m.marketplace_category_name, m.attribute_mapping_json
            FROM category_marketplace_mapping m WHERE m.id=? AND m.store_id=?', [$mappingId, $this->store()]);
        if (!$row || !ctype_digit((string)$row['marketplace_category_id'])) return false;
        $names = array_column($this->container->get(ProductAttribute::class)->all(), 'name', 'id');
        $mine = AttributeTemplateService::toTemplate($row, $names) ?? ['defaults' => [], 'sources' => [], 'value_map' => []];
        $target = (string)$row['marketplace_category_id'];
        $old = $this->db->fetchOne('SELECT template_json FROM trendyol_attribute_library WHERE marketplace_category_id=?', [$target]);
        $merged = self::overlay($old ? (json_decode((string)$old['template_json'], true) ?: []) : [], json_decode(json_encode($mine), true) ?: [], $removed);
        if ($merged['defaults'] === [] && $merged['sources'] === [] && $merged['value_map'] === [] && !$old) return false;
        $template = ['trendyol_category_id' => $target, 'defaults' => (object)$merged['defaults'], 'sources' => (object)$merged['sources'],
            'value_map' => (object)array_map(static fn($p) => (object)$p, $merged['value_map'])];
        $this->db->query('INSERT INTO trendyol_attribute_library (marketplace_category_id, marketplace_category_name, template_json, source_store_id, version, updated_at)
            VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE marketplace_category_name=VALUES(marketplace_category_name), template_json=VALUES(template_json),
            source_store_id=VALUES(source_store_id), version=VALUES(version), updated_at=NOW()',
            [$target, mb_substr((string)$row['marketplace_category_name'], 0, 500), json_encode($template, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->store(), self::version()]);
        return true;
    }

    /** Pure (unit tested): library template + one store's template − that store's deliberate removals. */
    public static function overlay(array $library, array $mine, array $removed): array
    {
        $out = ['defaults' => (array)($library['defaults'] ?? []), 'sources' => (array)($library['sources'] ?? []), 'value_map' => []];
        foreach ((array)($library['value_map'] ?? []) as $aid => $pairs) $out['value_map'][(string)$aid] = (array)$pairs;
        foreach (['defaults', 'sources'] as $k) {
            foreach ((array)($mine[$k] ?? []) as $aid => $v) $out[$k][(string)$aid] = (string)$v;
            foreach ((array)($removed[$k] ?? []) as $aid) unset($out[$k][(string)$aid]);
        }
        foreach ((array)($mine['value_map'] ?? []) as $aid => $pairs) foreach ((array)$pairs as $key => $option) $out['value_map'][(string)$aid][(string)$key] = (string)$option;
        foreach ((array)($removed['value_map'] ?? []) as $aid => $keys) foreach ((array)$keys as $key) unset($out['value_map'][(string)$aid][(string)$key]);
        foreach ($out['value_map'] as $aid => $pairs) if ($pairs === []) unset($out['value_map'][$aid]);
        return $out;
    }

    /** What the user removed of values the store had received from the library (pure). */
    public static function removedLibraryEntries(array $before, array $values, array $sources, ?array $valueMap): array
    {
        $marks = CategoryMarketplaceMapping::libraryMarks($before);
        $out = ['defaults' => [], 'sources' => [], 'value_map' => []];
        foreach (array_keys($marks['defaults']) as $aid) if (!isset($values[$aid])) $out['defaults'][] = (string)$aid;
        foreach (array_keys($marks['sources']) as $aid) if (!isset($sources[$aid])) $out['sources'][] = (string)$aid;
        if ($valueMap !== null) foreach ($marks['value_map'] as $aid => $keys) foreach (array_keys($keys) as $key) if (!isset($valueMap[$aid][$key])) $out['value_map'][(string)$aid][] = (string)$key;
        return $out;
    }

    /** Applies the library entry of one Trendyol category right away (e.g. a store just mapped it). */
    public function applyNow(string $target): void
    {
        $mid = $this->marketplace();
        if ($mid === null || !$this->enabled()) return;
        $row = $this->db->fetchOne('SELECT * FROM trendyol_attribute_library WHERE marketplace_category_id=?', [$target]);
        if ($row && (int)$row['source_store_id'] !== $this->store()) $this->applyRow($row, $mid);
    }

    private static function version(): int
    {
        return (int)floor(microtime(true) * 1000000);
    }

    /**
     * Applies library entries newer than this store's cursor. Advances the cursor entry by entry,
     * so an interrupted run resumes; a failed category is retried on the next run.
     * @return array{applied:int,changed_mappings:int,errors:int}
     */
    public function sync(float $deadline): array
    {
        $out = ['applied' => 0, 'changed_mappings' => 0, 'errors' => 0];
        $mid = $this->marketplace();
        if ($mid === null || !$this->enabled()) return $out;
        $cursor = (int)($this->settings->getValue(self::CURSOR_KEY, '0') ?? '0');
        $rows = $this->db->fetchAll('SELECT l.* FROM trendyol_attribute_library l WHERE l.version > ?
            AND EXISTS (SELECT 1 FROM category_marketplace_mapping m WHERE m.store_id=? AND m.marketplace_id=? AND m.marketplace_category_id=l.marketplace_category_id)
            ORDER BY l.version LIMIT 200', [$cursor, $this->store(), $mid]);
        foreach ($rows as $row) {
            if (microtime(true) > $deadline) break;
            if ((int)$row['source_store_id'] !== $this->store()) {
                try {
                    $out['changed_mappings'] += $this->applyRow($row, $mid);
                    $out['applied']++;
                } catch (\Throwable $e) {
                    $out['errors']++;
                    error_log('[attribute_library] store '.$this->store().' category '.$row['marketplace_category_id'].': '.$e->getMessage());
                    // Keep the cursor: the Trendyol list may be temporarily unavailable. After three failed
                    // runs the entry is skipped so one broken category cannot stop the others for good.
                    $failures = json_decode((string)($this->settings->getValue('attribute_library_failures', '{}') ?? '{}'), true) ?: [];
                    $failures = [(string)$row['version'] => (int)($failures[(string)$row['version']] ?? 0) + 1];
                    $this->settings->setValue('attribute_library_failures', json_encode($failures));
                    if ($failures[(string)$row['version']] < 3) break;
                }
            }
            $this->settings->setValue(self::CURSOR_KEY, (string)$row['version']);
        }
        return $out;
    }

    /** @return int number of this store's mappings that changed */
    private function applyRow(array $row, int $mid): int
    {
        $target = (string)$row['marketplace_category_id'];
        $locals = [];
        foreach ($this->container->get(ProductAttribute::class)->all() as $a) $locals[\App\Services\Marketplace\TrendyolAttributeResolver::normalize((string)$a['name'])] ??= (string)$a['id'];
        $definitions = array_column($this->container->get(TrendyolAttributeCatalog::class)->get($target)['attributes'], null, 'id');
        $clean = AttributeTemplateService::validate(json_decode((string)$row['template_json'], true) ?: [], $definitions, $locals, $this->usedLocals($mid, $target));
        $model = $this->container->get(CategoryMarketplaceMapping::class);
        $changedMappings = 0;
        foreach ($this->db->fetchAll('SELECT id FROM category_marketplace_mapping WHERE store_id=? AND marketplace_id=? AND marketplace_category_id=?', [$this->store(), $mid, $target]) as $m) {
            if (array_sum($model->applyLibraryTemplate((int)$m['id'], $clean['defaults'], $clean['sources'], $clean['value_map'])) === 0) continue;
            $changedMappings++;
            try { $this->container->get(AttributeIssueReport::class)->refreshMapping((string)$m['id']); } catch (\Throwable) {}
        }
        return $changedMappings;
    }

    /** Local attribute ids this store's products in categories mapped to $target actually fill. */
    private function usedLocals(int $mid, string $target): array
    {
        return array_column($this->db->fetchAll("SELECT DISTINCT pav.attribute_id FROM category_marketplace_mapping m
            JOIN products p ON p.store_id=m.store_id AND p.category_id=m.category_id
            JOIN product_attribute_values pav ON pav.store_id=p.store_id AND pav.product_id=p.id
            WHERE m.store_id=? AND m.marketplace_id=? AND m.marketplace_category_id=? AND (pav.attribute_value_id IS NOT NULL OR TRIM(COALESCE(pav.custom_value,''))<>'')",
            [$this->store(), $mid, $target]), 'attribute_id');
    }
}
