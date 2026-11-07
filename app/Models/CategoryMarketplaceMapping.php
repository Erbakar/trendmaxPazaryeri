<?php
/**
 * OzyConnect Panel — CategoryMarketplaceMapping Model
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

final class CategoryMarketplaceMapping extends BaseModel
{
    protected string $table = 'category_marketplace_mapping';

    /**
     * Eşleştirme bul
     *
     * @return array<string, mixed>|false
     */
    public function findMapping(int $categoryId, int $marketplaceId): array|false
    {
        return $this->findBy([
            'category_id'    => $categoryId,
            'marketplace_id' => $marketplaceId,
        ]);
    }

    /** Resolve explicit product choice before the source-category default. */
    public function forProduct(array $product, int $marketplaceId): array|false
    {
        $own = (new ProductCategoryOverride($this->db,$this->tenant))->forProduct((int)$product['id'],$marketplaceId);
        if ($own) {
            $own['_override_id'] = (int)$own['id'];
            $own['category_id'] = (int)$product['category_id'];
            return $own;
        }
        return $this->findMapping((int)$product['category_id'],$marketplaceId);
    }

    /**
     * Pazaryeri kategori ID'si ile iç kategori bul
     *
     * @return array<string, mixed>|false
     */
    public function findByMarketplaceCategory(int $marketplaceId, string $mpCategoryId): array|false
    {
        return $this->findBy([
            'marketplace_id'          => $marketplaceId,
            'marketplace_category_id' => $mpCategoryId,
        ]);
    }

    /**
     * Cached marketplace category leaf / sale state.
     *
     * Null means cache is not available for that marketplace category.
     * is_active / is_available null: pazaryeri bu bilgiyi vermiyor ya da önbellek eski (kolonsuz şema).
     * sellable: yaprak VE (bilinen) durum aktif VE satışa açık — NULL bilinmiyor = uygun.
     *
     * @return array{is_leaf: bool, name: string, full_path: string, is_active: ?bool, is_available: ?bool, sellable: bool}|null
     */
    public function getMarketplaceCategoryLeafStatus(int $marketplaceId, string $mpCategoryId): ?array
    {
        // SELECT * — satış durumu kolonları (phase12/052) migration uygulanmamış kurulumda olmayabilir.
        // Mağaza kapsamlı platformlarda (WooCommerce …) yalnız aktif mağazanın kategori önbelleği okunur.
        $scope = MarketplaceCatalogScope::condition($this->db, 'marketplace_categories', '', ':leaf_sid');
        $params = ['mid' => $marketplaceId, 'cid' => $mpCategoryId];
        if (str_contains($scope, ':leaf_sid')) {
            $params['leaf_sid'] = MarketplaceCatalogScope::readStoreId($this->tenant->hasStore() ? $this->tenant->currentStoreId() : null);
        }
        $row = $this->rawQueryOne(
            "SELECT *
             FROM marketplace_categories
             WHERE marketplace_id = :mid AND mp_category_id = :cid AND {$scope}
             LIMIT 1",
            $params
        );

        if ($row === false) {
            return null;
        }

        $isLeaf      = (int) ($row['is_leaf'] ?? 0) === 1;
        $isActive    = isset($row['is_active']) ? (int) $row['is_active'] === 1 : null;
        $isAvailable = isset($row['is_available']) ? (int) $row['is_available'] === 1 : null;

        return [
            'is_leaf'      => $isLeaf,
            'name'         => (string) ($row['name'] ?? ''),
            'full_path'    => (string) ($row['full_path'] ?? ''),
            'is_active'    => $isActive,
            'is_available' => $isAvailable,
            'sellable'     => $isLeaf && $isActive !== false && $isAvailable !== false,
        ];
    }

    public function mappingUsesLeafCategory(array $mapping): ?bool
    {
        $status = $this->getMarketplaceCategoryLeafStatus(
            (int) ($mapping['marketplace_id'] ?? 0),
            (string) ($mapping['marketplace_category_id'] ?? '')
        );

        return $status === null ? null : $status['is_leaf'];
    }

    /**
     * Pazaryerindeki eşleştirilmemiş kategorileri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMappedCategories(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT cmm.*, c.name as internal_name, c.full_path
             FROM category_marketplace_mapping cmm
             JOIN categories c ON cmm.category_id = c.id AND c.store_id = :store_id2
             WHERE cmm.marketplace_id = :mid
               AND cmm.store_id = :store_id
             ORDER BY c.name ASC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Eşleştirme kaydet veya güncelle
     */
    public function saveMapping(
        int $categoryId,
        int $marketplaceId,
        string $mpCategoryId,
        string $mpCategoryName,
        ?string $mpCategoryPath = null
    ): int {
        $this->assertMarketplaceCategoryIsLeaf($marketplaceId, $mpCategoryId);

        $existing = $this->findMapping($categoryId, $marketplaceId);

        if ($existing !== false) {
            $changes = [
                'marketplace_category_id'   => $mpCategoryId,
                'marketplace_category_name' => $mpCategoryName,
                'marketplace_category_path' => $mpCategoryPath,
            ];
            if ((string)$existing['marketplace_category_id'] !== $mpCategoryId) $changes['attribute_mapping_json'] = null;
            $this->update((int)$existing['id'], $changes);
            return (int) $existing['id'];
        }

        return $this->create([
            'category_id'               => $categoryId,
            'marketplace_id'            => $marketplaceId,
            'marketplace_category_id'   => $mpCategoryId,
            'marketplace_category_name' => $mpCategoryName,
            'marketplace_category_path' => $mpCategoryPath,
        ]);
    }

    /**
     * Eşleştirilmemiş kategorilere sahip ürün sayısını getir
     */
    public function getUnmappedCategoryCount(int $marketplaceId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(DISTINCT p.category_id) as cnt
             FROM products p
             WHERE p.category_id IS NOT NULL
               AND p.is_active = 1
               AND p.store_id = :store_id
               AND NOT EXISTS (SELECT 1 FROM product_marketplace_category_overrides po WHERE po.store_id=p.store_id AND po.product_id=p.id AND po.marketplace_id=".(int)$marketplaceId.")
               AND p.category_id NOT IN (
                   SELECT cmm.category_id
                   FROM category_marketplace_mapping cmm
                   WHERE cmm.marketplace_id = :mid
                     AND cmm.store_id = :store_id2
               )",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Tüm iç kategorileri eşleştirme durumuyla getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoriesWithStatus(int $marketplaceId): array
    {
        $sellable = $this->marketplaceCategorySellableExpression();

        return $this->rawQuery(
            "SELECT c.id, c.name, c.full_path, c.parent_id,
                    cmm.id as mapping_id,
                    cmm.marketplace_category_id,
                    cmm.marketplace_category_name,
                    mc.is_leaf as marketplace_category_is_leaf,
                    {$sellable} as marketplace_category_sellable,
                    cmm.attribute_mapping_json,
                    (SELECT COUNT(*) FROM products op JOIN product_marketplace_category_overrides po ON po.store_id=op.store_id AND po.product_id=op.id AND po.marketplace_id=".(int)$marketplaceId." WHERE op.category_id=c.id AND op.store_id=c.store_id) AS override_count,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1 AND p.store_id = :store_id3) as product_count
             FROM categories c
             LEFT JOIN category_marketplace_mapping cmm
                 ON c.id = cmm.category_id AND cmm.marketplace_id = :mid AND cmm.store_id = :store_id2
             LEFT JOIN marketplace_categories mc
                 ON mc.marketplace_id = cmm.marketplace_id
                AND mc.mp_category_id = cmm.marketplace_category_id
                AND " . MarketplaceCatalogScope::condition($this->db, 'marketplace_categories', 'mc', 'cmm.store_id') . "
             WHERE (c.is_active = 1 OR EXISTS (SELECT 1 FROM products cp WHERE cp.category_id=c.id AND cp.store_id=c.store_id AND cp.is_active=1))
               AND c.store_id = :store_id
             ORDER BY c.name ASC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId(), 'store_id3' => $this->tenantId()]
        );
    }

    /**
     * Eşleştirilmemiş pazaryeri kategorilerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Eşleştirilmemiş pazaryeri kategorilerini getir
     */
    public function getUnmappedMarketplaceCategories(int $marketplaceId): array
    {
        return $this->rawQuery(
            "SELECT
                COALESCE(mp.marketplace_category_id, 'unknown') as marketplace_category_id,
                COUNT(DISTINCT p.id) as product_count
             FROM products p
             JOIN marketplace_products mp ON p.id = mp.product_id AND mp.marketplace_id = :mid AND mp.store_id = :store_id2
             WHERE p.needs_category_mapping = 1
               AND p.is_active = 1
               AND p.store_id = :store_id
             GROUP BY mp.marketplace_category_id
             ORDER BY product_count DESC",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()]
        );
    }

    /**
     * Belirli kategorinin kaç pazaryeriyle eşleştirildiğini say
     */
    public function countMappings(int $categoryId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM category_marketplace_mapping WHERE category_id = :cid AND store_id = :store_id",
            ['cid' => $categoryId, 'store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Belirli kategorinin tüm pazaryeri eşleştirmelerini getir
     */
    public function getMappingsForCategory(int $categoryId): array
    {
        $sellable = $this->marketplaceCategorySellableExpression();

        return $this->rawQuery(
            "SELECT cmm.*, m.slug as marketplace_slug, m.display_name as marketplace_name,
                    mc.is_leaf as marketplace_category_is_leaf,
                    {$sellable} as marketplace_category_sellable
             FROM category_marketplace_mapping cmm
             JOIN marketplaces m ON cmm.marketplace_id = m.id
             LEFT JOIN marketplace_categories mc
                 ON mc.marketplace_id = cmm.marketplace_id
                AND mc.mp_category_id = cmm.marketplace_category_id
                AND " . MarketplaceCatalogScope::condition($this->db, 'marketplace_categories', 'mc', 'cmm.store_id') . "
             WHERE cmm.category_id = :cid
               AND cmm.store_id = :store_id
             ORDER BY m.display_name ASC",
            ['cid' => $categoryId, 'store_id' => $this->tenantId()]
        );
    }

    public function countByMarketplace(int $marketplaceId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM category_marketplace_mapping WHERE marketplace_id = :mid AND store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    public function countMappedCategories(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(DISTINCT category_id) as cnt FROM category_marketplace_mapping WHERE store_id = :store_id",
            ['store_id' => $this->tenantId()]
        );
        return (int)($row['cnt'] ?? 0);
    }

    public function deleteByMarketplace(int $marketplaceId): int
    {
        $stmt = $this->db->query(
            "DELETE FROM category_marketplace_mapping WHERE marketplace_id = :mid AND store_id = :store_id",
            ['mid' => $marketplaceId, 'store_id' => $this->tenantId()]
        );
        return $stmt->rowCount();
    }

    // ═══════════════════════════════════════════════════════
    // ÖZELLİK DEĞERLERİ (attribute_mapping_json)
    //
    // HB satırlarında biçim: {"defaults": {"<özellikId>": "<değer>", "Marka": "..."},
    //   "products": {"id:<products.id>": {"<özellikId>": "<değer>"}}, "updated_at": "..."}.
    // HB-dışı satırlar eski LİSTE biçimini ([{id,name,required}]) kullanır; bu yardımcılar
    // listeyi okumaz (defaults/products anahtarı yoksa boş döner). Bir HB satırında eski liste
    // bulunursa ilk yazımda `legacy_list` anahtarına taşınır — karışık nesne üretilmez.
    // ═══════════════════════════════════════════════════════

    /**
     * attribute_mapping_json çöz (boş/bozuk JSON → boş dizi)
     *
     * @param array<string, mixed>|false|null $mapping
     * @return array<int|string, mixed>
     */
    public static function decodeAttributeMapping(array|false|null $mapping): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $decoded = json_decode((string) ($mapping['attribute_mapping_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Kategori varsayılan özellik değerleri: [özellikId => değer]
     *
     * @param array<string, mixed>|false|null $mapping
     * @return array<string, string>
     */
    public static function attributeDefaults(array|false|null $mapping): array
    {
        $config = self::decodeAttributeMapping($mapping);
        if (array_is_list($config)) {
            return [];
        }

        return self::stringMap($config['defaults'] ?? []);
    }

    /**
     * Ürün bazlı özellik değerleri: [özellikId => değer]
     *
     * @param array<string, mixed>|false|null $mapping
     * @return array<string, string>
     */
    public static function productAttributeValues(array|false|null $mapping, string $productKey): array
    {
        $config = self::decodeAttributeMapping($mapping);
        if (array_is_list($config)) {
            return [];
        }

        $products = is_array($config['products'] ?? null) ? $config['products'] : [];

        return self::stringMap($products[$productKey] ?? []);
    }

    /**
     * Ürün bazlı değer anahtarı — yalnız motor ürün kimliği: 'id:<products.id>'.
     *
     * @param array<string, mixed> $product
     */
    public static function attributeProductKey(array $product): string
    {
        return 'id:' . (int) ($product['id'] ?? 0);
    }

    /**
     * Kategori varsayılanını yaz; boş değer varsayılanı kaldırır (otomatik türetmeye döner).
     * Aktif mağazanın kaydı değilse false.
     */
    public function saveAttributeDefault(int $mappingId, string $attributeId, string $value): bool
    {
        $attributeId = trim($attributeId);
        if ($attributeId === '') {
            throw new \InvalidArgumentException('Özellik kimliği boş olamaz.');
        }

        return $this->mutateAttributeMapping($mappingId, static function (array $config) use ($attributeId, $value): array {
            $defaults = self::stringMap($config['defaults'] ?? []);
            if (trim($value) === '') {
                unset($defaults[$attributeId]);
            } else {
                $defaults[$attributeId] = trim($value);
            }
            $config['defaults'] = $defaults;

            return $config;
        });
    }

    /**
     * Ürün bazlı değeri yaz; boş değer ürün değerini kaldırır (kategori varsayılanına döner).
     * Ürünün tüm değerleri boşalınca ürün anahtarı silinir. Aktif mağazanın kaydı değilse false.
     */
    public function saveProductAttributeValue(int $mappingId, string $productKey, string $attributeId, string $value): bool
    {
        $productKey  = trim($productKey);
        $attributeId = trim($attributeId);
        if ($productKey === '' || $attributeId === '') {
            throw new \InvalidArgumentException('Ürün anahtarı ve özellik kimliği boş olamaz.');
        }

        return $this->mutateAttributeMapping($mappingId, static function (array $config) use ($productKey, $attributeId, $value): array {
            $products = is_array($config['products'] ?? null) ? $config['products'] : [];
            $values = self::stringMap($products[$productKey] ?? []);
            if (trim($value) === '') {
                unset($values[$attributeId]);
            } else {
                $values[$attributeId] = trim($value);
            }
            if ($values === []) {
                unset($products[$productKey]);
            } else {
                $products[$productKey] = $values;
            }
            $config['products'] = $products;

            return $config;
        });
    }

    /**
     * Kayıt aktif mağazadan okunur/yazılır (find/update tenant kapsamlı).
     *
     * @param callable(array<int|string, mixed>): array<int|string, mixed> $mutator
     */
    public function saveTrendyolAttributes(int $mappingId, array $values, array $sources, ?int $productId = null, ?array $valueMap = null): bool
    {
        return $this->mutateAttributeMapping($mappingId, static function(array $config) use ($values, $sources, $productId, $valueMap): array {
            if ($productId !== null) {
                $config['products']['id:'.$productId] = $values;
            } else {
                // null = caller did not send the table (older screen); keep the saved XML value choices.
                $config = self::keepLibraryMarks($config, $values, $sources, $valueMap ?? (is_array($config['value_map'] ?? null) ? $config['value_map'] : []));
                $config['defaults'] = $values;
                $config['sources'] = $sources;
                if ($valueMap !== null) $config['value_map'] = $valueMap;
            }
            return $config;
        }, true);
    }

    /**
     * Applies a shared (support panel) template to every local category of this store mapped to the
     * same Trendyol category. 'fill' never overwrites a user's existing choice; 'replace' overwrites
     * only the attributes the template carries. Returns the per-mapping changes for reporting.
     *
     * @return array<int, array{mapping_id:int, category_id:int, changed:array<string,int>}>
     */
    public function applyAttributeTemplate(int $marketplaceId, string $marketplaceCategoryId, array $defaults, array $sources, array $valueMap, string $mode, bool $dryRun): array
    {
        $rows = $this->rawQuery(
            'SELECT id, category_id FROM category_marketplace_mapping WHERE store_id = :sid AND marketplace_id = :mid AND marketplace_category_id = :target ORDER BY id',
            ['sid' => $this->tenantId(), 'mid' => $marketplaceId, 'target' => $marketplaceCategoryId]
        );
        $report = [];
        foreach ($rows as $row) {
            $changed = ['defaults' => 0, 'sources' => 0, 'value_map' => 0];
            $apply = static function (array $config) use ($defaults, $sources, $valueMap, $mode, &$changed): array {
                [$config, $changed] = self::mergeAttributeTemplate($config, $defaults, $sources, $valueMap, $mode);
                return $config;
            };
            $config = self::decodeAttributeMapping($this->find((int) $row['id']));
            if ($config !== [] && array_is_list($config)) $config = [];
            $apply($config);
            // Writing identical content would still change updated_at and so the content fingerprint,
            // which would void existing content reviews for nothing.
            if (!$dryRun && array_sum($changed) > 0) {
                $changed = ['defaults' => 0, 'sources' => 0, 'value_map' => 0];
                if (!$this->mutateAttributeMapping((int) $row['id'], $apply, true)) continue;
            }
            // A changed mapping voids the content approval of every product in the category.
            $products = (int) ($this->rawQueryOne('SELECT COUNT(*) n FROM products WHERE store_id = :sid AND category_id = :cid', ['sid' => $this->tenantId(), 'cid' => (int) $row['category_id']])['n'] ?? 0);
            $report[] = ['mapping_id' => (int) $row['id'], 'category_id' => (int) $row['category_id'], 'changed' => $changed, 'products_in_category' => $products];
        }
        return $report;
    }

    /**
     * A value written by the shared library stays library-owned only while the user keeps it unchanged;
     * an edited or removed value becomes the store's own decision (library sync never touches it again).
     */
    public static function keepLibraryMarks(array $config, array $values, array $sources, array $valueMap): array
    {
        $marks = self::libraryMarks($config);
        $keep = ['defaults' => [], 'sources' => [], 'value_map' => []];
        foreach ($marks['defaults'] as $aid => $v) if (isset($values[$aid]) && (string) $values[$aid] === (string) ($config['defaults'][$aid] ?? null)) $keep['defaults'][$aid] = true;
        foreach ($marks['sources'] as $aid => $v) if (isset($sources[$aid]) && (string) $sources[$aid] === (string) ($config['sources'][$aid] ?? null)) $keep['sources'][$aid] = true;
        foreach ($marks['value_map'] as $aid => $keys) foreach ($keys as $key => $v) {
            if (isset($valueMap[$aid][$key]) && (string) $valueMap[$aid][$key] === (string) ($config['value_map'][$aid][$key] ?? null)) $keep['value_map'][$aid][$key] = true;
        }
        $config['library'] = $keep;
        return $config;
    }

    /** @return array{defaults:array<string,bool>,sources:array<string,bool>,value_map:array<string,array<string,bool>>} */
    public static function libraryMarks(array $config): array
    {
        $m = is_array($config['library'] ?? null) ? $config['library'] : [];
        $out = ['defaults' => [], 'sources' => [], 'value_map' => []];
        foreach (['defaults', 'sources'] as $k) foreach ((array) ($m[$k] ?? []) as $aid => $v) if ($v) $out[$k][(string) $aid] = true;
        foreach ((array) ($m['value_map'] ?? []) as $aid => $keys) foreach ((array) $keys as $key => $v) if ($v) $out['value_map'][(string) $aid][(string) $key] = true;
        return $out;
    }

    /**
     * Pure (unit tested): applies the shared library's current template for this Trendyol category.
     * Only library-owned or undecided entries change: a store's own fixed value, source field or XML
     * value choice is never overwritten. Library-owned entries the library no longer carries are removed,
     * so a fix (or withdrawal) made once reaches every store that took the earlier value from the library.
     * @return array{0:array,1:array{defaults:int,sources:int,value_map:int}}
     */
    public static function mergeLibraryTemplate(array $config, array $defaults, array $sources, array $valueMap): array
    {
        $changed = ['defaults' => 0, 'sources' => 0, 'value_map' => 0];
        $marks = self::libraryMarks($config);
        $current = self::stringMap($config['defaults'] ?? []);
        $currentSources = self::stringMap($config['sources'] ?? []);
        $ownDecision = static fn(string $aid): bool => (isset($current[$aid]) && empty($marks['defaults'][$aid])) || (isset($currentSources[$aid]) && empty($marks['sources'][$aid]));
        foreach (['defaults' => $defaults, 'sources' => $sources] as $kind => $incoming) {
            $target = $kind === 'defaults' ? $current : $currentSources;
            foreach ($incoming as $aid => $value) {
                $aid = (string) $aid;
                if ($ownDecision($aid)) continue;
                if (($target[$aid] ?? null) !== (string) $value) { $target[$aid] = (string) $value; $changed[$kind]++; }
                $marks[$kind][$aid] = true;
            }
            foreach (array_keys($marks[$kind]) as $aid) {
                if (array_key_exists($aid, $incoming)) continue;
                if (isset($target[$aid])) { unset($target[$aid]); $changed[$kind]++; }
                unset($marks[$kind][$aid]);
            }
            if ($kind === 'defaults') $current = $target; else $currentSources = $target;
        }
        $map = [];
        foreach ((array) ($config['value_map'] ?? []) as $aid => $pairs) if (is_array($pairs)) foreach ($pairs as $key => $option) $map[(string) $aid][(string) $key] = (string) $option;
        foreach ($valueMap as $aid => $pairs) foreach ($pairs as $key => $option) {
            $aid = (string) $aid; $key = (string) $key;
            if (isset($map[$aid][$key]) && empty($marks['value_map'][$aid][$key])) continue; // store's own choice
            if (($map[$aid][$key] ?? null) !== (string) $option) { $map[$aid][$key] = (string) $option; $changed['value_map']++; }
            $marks['value_map'][$aid][$key] = true;
        }
        foreach ($marks['value_map'] as $aid => $keys) foreach (array_keys($keys) as $key) {
            if (isset($valueMap[$aid][$key])) continue;
            if (isset($map[$aid][$key])) { unset($map[$aid][$key]); $changed['value_map']++; }
            unset($marks['value_map'][$aid][$key]);
        }
        foreach ($map as $aid => $pairs) if ($pairs === []) unset($map[$aid]);
        foreach ($marks['value_map'] as $aid => $keys) if ($keys === []) unset($marks['value_map'][$aid]);
        $config['defaults'] = $current;
        $config['sources'] = $currentSources;
        $config['value_map'] = $map;
        $config['library'] = $marks;
        return [$config, $changed];
    }

    /** Writes a library merge into one mapping of the active store; no write when nothing changes. */
    public function applyLibraryTemplate(int $mappingId, array $defaults, array $sources, array $valueMap): array
    {
        $config = self::decodeAttributeMapping($this->find($mappingId));
        if ($config !== [] && array_is_list($config)) $config = [];
        [, $changed] = self::mergeLibraryTemplate($config, $defaults, $sources, $valueMap);
        if (array_sum($changed) === 0) return $changed;
        $this->mutateAttributeMapping($mappingId, static function (array $c) use ($defaults, $sources, $valueMap): array {
            return self::mergeLibraryTemplate($c, $defaults, $sources, $valueMap)[0];
        }, true);
        return $changed;
    }

    /**
     * Pure merge of a support-panel template into one stored mapping config (unit tested).
     * 'fill' leaves every attribute the user already decided (fixed value OR source field) untouched;
     * value-map entries are only added for XML values without a choice.
     * @return array{0:array,1:array{defaults:int,sources:int,value_map:int}}
     */
    public static function mergeAttributeTemplate(array $config, array $defaults, array $sources, array $valueMap, string $mode): array
    {
        $changed = ['defaults' => 0, 'sources' => 0, 'value_map' => 0];
        $current = self::stringMap($config['defaults'] ?? []);
        $currentSources = self::stringMap($config['sources'] ?? []);
        // A source plus fallback default, or vice versa, would change what products send.
        $decided = array_map('strval', array_keys($current + $currentSources));
        foreach ($defaults as $aid => $value) {
            if ($mode === 'fill' && in_array((string) $aid, $decided, true)) continue;
            if (($current[$aid] ?? null) !== $value) { $current[$aid] = $value; $changed['defaults']++; }
        }
        foreach ($sources as $aid => $value) {
            if ($mode === 'fill' && in_array((string) $aid, $decided, true)) continue;
            if (($currentSources[$aid] ?? null) !== $value) { $currentSources[$aid] = $value; $changed['sources']++; }
        }
        $config['defaults'] = $current;
        $config['sources'] = $currentSources;
        $currentMap = is_array($config['value_map'] ?? null) ? $config['value_map'] : [];
        foreach ($valueMap as $aid => $pairs) {
            $existing = is_array($currentMap[$aid] ?? null) ? $currentMap[$aid] : [];
            foreach ($pairs as $key => $option) {
                if ($mode === 'fill' && isset($existing[$key])) continue;
                if (($existing[$key] ?? null) !== $option) { $existing[$key] = $option; $changed['value_map']++; }
            }
            if ($existing !== []) $currentMap[$aid] = $existing;
        }
        $config['value_map'] = $currentMap;
        return [$config, $changed];
    }

    private function mutateAttributeMapping(int $mappingId, callable $mutator, bool $convertLegacy = false): bool
    {
        $mapping = $this->find($mappingId);
        if ($mapping === false) {
            return false;
        }

        $config = self::decodeAttributeMapping($mapping);

        // Eski LİSTE biçimi ([{id,name,required}]) — nesne anahtarı eklemek karışık yapı üretir.
        if ($config !== [] && array_is_list($config)) {
            if (!$convertLegacy && !$this->isHepsiburadaMarketplace((int) ($mapping['marketplace_id'] ?? 0))) {
                // HB-dışı satırın listesi o pazaryerinin doğrulamasında kullanılır; dönüştürülmez.
                throw new \RuntimeException('Bu eşleştirmenin özellik kaydı farklı biçimde; özellik değeri yazılamaz.');
            }
            $config = ['legacy_list' => $config, 'defaults' => [], 'products' => []];
        }

        $config += ['defaults' => [], 'products' => []];
        $config = $mutator($config);
        $config['updated_at'] = date('Y-m-d H:i:s');

        // Boş eşlemeler JSON'da dizi değil nesne olarak kalsın ({} — liste sanılmasın)
        foreach (['defaults', 'products'] as $key) {
            if (is_array($config[$key] ?? null) && $config[$key] === []) {
                $config[$key] = new \stdClass();
            }
        }

        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        // rowCount 0 (aynı içerik) hata değildir — kayıt bulunduysa yazım tamamdır
        $this->update($mappingId, ['attribute_mapping_json' => $json]);

        return true;
    }

    /**
     * `mc` (marketplace_categories) JOIN'i için satışa uygunluk ifadesi:
     * önbellekte kayıt yoksa NULL, satışa uygun yaprak ise 1, değilse 0.
     */
    private function marketplaceCategorySellableExpression(): string
    {
        $condition = (new MarketplaceCategory($this->db, $this->tenant))->sellableCondition('mc');

        return "(CASE WHEN mc.id IS NULL THEN NULL WHEN {$condition} THEN 1 ELSE 0 END)";
    }

    private function isHepsiburadaMarketplace(int $marketplaceId): bool
    {
        if ($marketplaceId <= 0) {
            return false;
        }

        $row = $this->rawQueryOne(
            "SELECT slug FROM marketplaces WHERE id = :id LIMIT 1",
            ['id' => $marketplaceId]
        );

        return $row !== false && (string) ($row['slug'] ?? '') === 'hepsiburada';
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '' && is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = trim((string) $value);
            }
        }

        return $out;
    }

    private function assertMarketplaceCategoryIsLeaf(int $marketplaceId, string $mpCategoryId): void
    {
        $status = $this->getMarketplaceCategoryLeafStatus($marketplaceId, $mpCategoryId);
        if ($status === null || $status['sellable']) {
            return;
        }

        $label = $status['full_path'] !== '' ? $status['full_path'] : $status['name'];
        if ($label === '') {
            $label = $mpCategoryId;
        }

        if (!$status['is_leaf']) {
            throw new \RuntimeException(
                'Ara kategori seçilemez. Lütfen en alt/final pazaryeri kategorisini seçin: ' . $label
            );
        }

        throw new \RuntimeException(
            'Bu pazaryeri kategorisi satışa kapalı, seçilemez: ' . $label
        );
    }
}
