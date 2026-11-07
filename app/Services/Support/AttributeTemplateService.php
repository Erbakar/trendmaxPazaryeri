<?php
declare(strict_types=1);
namespace App\Services\Support;

use App\Core\{Container,Database,TenantContext};
use App\Models\{CategoryMarketplaceMapping,Marketplace,ProductAttribute};
use App\Services\Marketplace\{TrendyolAttributeCatalog,TrendyolAttributeResolver};

/**
 * "Tek kaynak" — portable Trendyol attribute mappings keyed by Trendyol category id.
 * Local attribute ids differ between installs, so sources travel by attribute NAME and
 * are resolved again on the receiving site. Option ids are Trendyol's and are re-validated
 * against the receiving store's own Trendyol attribute list before anything is written.
 */
final class AttributeTemplateService
{
    public const FORMAT = 'trendmax.trendyol-attribute-template.v1';
    public const MAX_TEMPLATES = 300;

    public function __construct(private readonly Container $container, private readonly Database $db, private readonly TenantContext $tenant) {}

    private function marketplaceId(): int
    {
        $mp = $this->container->get(Marketplace::class)->findBySlug('trendyol');
        if (!$mp || empty($mp['is_active'])) throw new \RuntimeException('Trendyol pazaryeri aktif değil.');
        return (int)$mp['id'];
    }

    /** @return array{format:string,generated_at:string,templates:list<array>} */
    public function export(): array
    {
        $mid = $this->marketplaceId();
        $names = array_column($this->container->get(ProductAttribute::class)->all(), 'name', 'id');
        $rows = $this->db->fetchAll('SELECT m.id, m.marketplace_category_id, m.marketplace_category_name, m.attribute_mapping_json, c.name AS category_name
            FROM category_marketplace_mapping m LEFT JOIN categories c ON c.id=m.category_id WHERE m.store_id=? AND m.marketplace_id=? ORDER BY m.marketplace_category_id, m.id', [$this->tenant->currentStoreId(), $mid]);
        $templates = [];
        foreach ($rows as $row) {
            $template = self::toTemplate($row, $names);
            if ($template !== null) $templates[] = $template;
        }
        return ['format' => self::FORMAT, 'generated_at' => date(DATE_ATOM), 'templates' => $templates];
    }

    /** Pure: one stored mapping → portable template (null when it carries no attribute choices). */
    public static function toTemplate(array $row, array $localNames): ?array
    {
        $config = CategoryMarketplaceMapping::decodeAttributeMapping($row);
        if ($config === [] || array_is_list($config)) return null;
        $defaults = CategoryMarketplaceMapping::attributeDefaults($row);
        $sources = [];
        foreach ((array)($config['sources'] ?? []) as $aid => $localId) {
            if (isset($localNames[(int)$localId])) $sources[(string)$aid] = (string)$localNames[(int)$localId];
        }
        $valueMap = TrendyolAttributeResolver::valueMap($config);
        if ($defaults === [] && $sources === [] && $valueMap === []) return null;
        return ['trendyol_category_id' => (string)$row['marketplace_category_id'], 'trendyol_category_name' => (string)($row['marketplace_category_name'] ?? ''),
            'local_category_name' => (string)($row['category_name'] ?? ''), 'defaults' => (object)$defaults, 'sources' => (object)$sources,
            'value_map' => (object)$valueMap, 'updated_at' => $config['updated_at'] ?? null];
    }

    /**
     * Validates every template against this store's Trendyol lists, then applies it.
     * @return array{mode:string,dry_run:bool,applied:list<array>,skipped:list<array>}
     */
    public function import(array $templates, string $mode, bool $dryRun): array
    {
        if (!in_array($mode, ['fill', 'replace'], true)) throw new \InvalidArgumentException("mode 'fill' veya 'replace' olmalı.");
        if (count($templates) > self::MAX_TEMPLATES) throw new \InvalidArgumentException('Tek istekte en fazla '.self::MAX_TEMPLATES.' kategori şablonu gönderilebilir.');
        $mid = $this->marketplaceId();
        $locals = [];
        foreach ($this->container->get(ProductAttribute::class)->all() as $attribute) $locals[TrendyolAttributeResolver::normalize((string)$attribute['name'])] ??= (string)$attribute['id'];
        $catalog = $this->container->get(TrendyolAttributeCatalog::class);
        $model = $this->container->get(CategoryMarketplaceMapping::class);
        $applied = []; $skipped = [];
        foreach ($templates as $i => $template) {
            $target = is_array($template) ? (string)($template['trendyol_category_id'] ?? '') : '';
            try {
                if (!ctype_digit($target)) throw new \InvalidArgumentException('trendyol_category_id geçersiz.');
                // Check locally first: no Trendyol request for categories this site does not use.
                if (!$this->db->fetchOne('SELECT id FROM category_marketplace_mapping WHERE store_id=? AND marketplace_id=? AND marketplace_category_id=? LIMIT 1', [$this->tenant->currentStoreId(), $mid, $target])) {
                    $skipped[] = ['index' => $i, 'trendyol_category_id' => $target, 'reason' => 'Bu sitede bu Trendyol kategorisine eşlenmiş kategori yok.']; continue;
                }
                $definitions = array_column($catalog->get($target)['attributes'], null, 'id');
                $clean = self::validate($template, $definitions, $locals, $this->usedLocals($mid, $target));
                $changes = $model->applyAttributeTemplate($mid, $target, $clean['defaults'], $clean['sources'], $clean['value_map'], $mode, $dryRun);
                if ($changes === []) { $skipped[] = ['index' => $i, 'trendyol_category_id' => $target, 'reason' => 'Bu sitede bu Trendyol kategorisine eşlenmiş kategori yok.']; continue; }
                $applied[] = ['index' => $i, 'trendyol_category_id' => $target, 'mappings' => $changes, 'warnings' => $clean['warnings']];
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $skipped[] = ['index' => $i, 'trendyol_category_id' => $target, 'reason' => $e->getMessage()];
            }
        }
        return ['mode' => $mode, 'dry_run' => $dryRun, 'applied' => $applied, 'skipped' => $skipped];
    }

    /** Local attribute ids that this store's products in categories mapped to $target actually fill. */
    private function usedLocals(int $mid, string $target): array
    {
        return array_column($this->db->fetchAll('SELECT DISTINCT pav.attribute_id FROM category_marketplace_mapping m
            JOIN products p ON p.store_id=m.store_id AND p.category_id=m.category_id
            JOIN product_attribute_values pav ON pav.store_id=p.store_id AND pav.product_id=p.id
            WHERE m.store_id=? AND m.marketplace_id=? AND m.marketplace_category_id=? AND (pav.attribute_value_id IS NOT NULL OR TRIM(COALESCE(pav.custom_value,\'\'))<>\'\')',
            [$this->tenant->currentStoreId(), $mid, $target]), 'attribute_id');
    }

    /**
     * Pure validation (unit tested). Unknown attributes/options reject the template; a source attribute
     * name that does not exist on this site is dropped with a warning (the default value still applies).
     * @param array<string,array> $definitions Trendyol attributes of the category, keyed by id
     * @param array<string,string> $locals normalized local attribute name → local id
     */
    public static function validate(array $template, array $definitions, array $locals, ?array $usedLocals = null): array
    {
        $defaults = []; $sources = []; $valueMap = []; $warnings = [];
        // Sources first: the same-name guard below must see what this site will actually use.
        foreach ((array)($template['sources'] ?? []) as $aid => $name) {
            $aid = (string)$aid;
            if (!isset($definitions[$aid]) || !is_scalar($name)) throw new \InvalidArgumentException("Özellik $aid bu Trendyol kategorisinde yok.");
            $local = $locals[TrendyolAttributeResolver::normalize((string)$name)] ?? null;
            if ($local === null) { $warnings[] = "Kaynak alan \"$name\" bu sitede yok; atlandı."; continue; }
            $sources[$aid] = $local;
        }
        foreach ((array)($template['defaults'] ?? []) as $aid => $value) {
            $aid = (string)$aid;
            if (!isset($definitions[$aid]) || !is_scalar($value)) throw new \InvalidArgumentException("Özellik $aid bu Trendyol kategorisinde yok.");
            $value = trim((string)$value);
            if ($value === '') continue;
            TrendyolAttributeResolver::value($definitions[$aid], $value); // throws on a foreign option id
            $name = (string)($definitions[$aid]['name'] ?? $aid);
            if (!empty($definitions[$aid]['varianter']) || !empty($definitions[$aid]['slicer'])) {
                // One fixed colour/size for a whole category on another site would mislabel its variants.
                $warnings[] = "$name varyant özelliği; sabit değeri aktarılmadı."; continue;
            }
            $sameName = $locals[TrendyolAttributeResolver::normalize($name)] ?? null;
            $inUse = $sameName !== null && ($usedLocals === null || in_array((string)$sameName, array_map('strval', $usedLocals), true));
            if ($inUse && ($sources[$aid] ?? null) !== $sameName) {
                // This site's products carry their own value in a same-name field; a fixed value (or a
                // fallback behind another source) would replace it.
                $warnings[] = "$name bu sitede ürünlerin kendi alanından okunuyor; sabit değeri aktarılmadı."; continue;
            }
            $defaults[$aid] = $value;
        }
        foreach ((array)($template['value_map'] ?? []) as $aid => $pairs) {
            $aid = (string)$aid;
            if (!isset($definitions[$aid]) || !is_array($pairs)) throw new \InvalidArgumentException("Özellik $aid bu Trendyol kategorisinde yok.");
            if (!empty($definitions[$aid]['allowCustom'])) continue;
            if (count($pairs) > TrendyolAttributeResolver::VALUE_MAP_LIMIT) throw new \InvalidArgumentException("Özellik $aid için çok fazla değer eşleştirmesi var.");
            $options = array_map('strval', array_column($definitions[$aid]['values'] ?? [], 'id'));
            foreach ($pairs as $raw => $option) {
                $key = TrendyolAttributeResolver::mapKey((string)$raw);
                if ($key === '' || !is_scalar($option) || !in_array((string)$option, $options, true)) throw new \InvalidArgumentException("Özellik $aid için geçersiz Trendyol seçeneği.");
                $valueMap[$aid][$key] = (string)$option;
            }
        }
        return ['defaults' => $defaults, 'sources' => $sources, 'value_map' => $valueMap, 'warnings' => $warnings];
    }
}
