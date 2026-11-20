<?php
declare(strict_types=1);

namespace App\Services\Mapping;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\CategoryMarketplaceMapping;
use App\Services\Marketplace\HepsiburadaService;

/**
 * Pazaryeri kategorisi başına mağaza varsayılan özellik değerleri (ör. Hepsiburada "Peluş Oyuncak"
 * kategorisinde "Malzeme = Peluş").
 *
 * Panel eşleştirmesinin varsayılanları yalnız o panel kategorisinin ürünlerine uygulanır; Trendyol'dan
 * aktarılan ürünlerin çoğu ürüne özel kategoriyle gider ve onlara varsayılan girilecek yer yoktu.
 * Bu tablo pazaryeri kategorisine bağlıdır: o kategoriye giden her ürün, nereden eşleşmiş olursa olsun,
 * değeri ürünün kendisinden çıkarılamadığında buradan alır.
 *
 * Eksikler ekranı (gaps) son gönderimin doğrulama hatalarını okur: hangi kategoride hangi zorunlu
 * özellik kaç ürünü durdurdu, hangi ürünlerin hiç pazaryeri kategorisi yok.
 */
final class MarketplaceAttributeDefaultService
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    private bool $tableReady = false;

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly CategoryMarketplaceMapping $catMapping,
        private readonly HepsiburadaService $hepsiburada,
    ) {}

    public function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS marketplace_category_attribute_defaults (
             store_id INT UNSIGNED NOT NULL,
             marketplace_id INT UNSIGNED NOT NULL,
             marketplace_category_id VARCHAR(100) NOT NULL,
             attribute_id VARCHAR(191) NOT NULL,
             attribute_name VARCHAR(255) NOT NULL DEFAULT '',
             value VARCHAR(500) NOT NULL,
             updated_at DATETIME NOT NULL,
             PRIMARY KEY (store_id, marketplace_id, marketplace_category_id, attribute_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->tableReady = true;
    }

    private function store(): int
    {
        return $this->tenant->hasStore() ? (int) $this->tenant->currentStoreId() : 0;
    }

    /**
     * Kategorinin kayıtlı varsayılanları: [özellikId => değer]. Tablo yoksa boş.
     *
     * @return array<string, string>
     */
    public function forCategory(int $marketplaceId, string $categoryId): array
    {
        $key = $marketplaceId . '|' . $categoryId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (count($this->cache) > 500) {
            $this->cache = [];
        }
        $out = [];
        try {
            foreach ($this->db->fetchAll(
                'SELECT attribute_id, value FROM marketplace_category_attribute_defaults
                  WHERE store_id = ? AND marketplace_id = ? AND marketplace_category_id = ?',
                [$this->store(), $marketplaceId, $categoryId]
            ) as $row) {
                $out[(string) $row['attribute_id']] = (string) $row['value'];
            }
        } catch (\Throwable) {
            $out = []; // tablo henüz yok
        }

        return $this->cache[$key] = $out;
    }

    /**
     * Doğrulama hatasındaki çözülemeyen zorunlu özellik adları.
     *
     * @return list<string>
     */
    public static function missingAttributes(string $syncError): array
    {
        preg_match_all('/zorunlu özelliği çözülemedi: (.+?) — /u', $syncError, $m);

        return array_values(array_unique($m[1]));
    }

    /** Ürünün hiç kullanılabilir pazaryeri kategorisi yok mu (panel eşleştirmesiyle çözülür)? */
    public static function lacksCategory(string $syncError): bool
    {
        return str_contains($syncError, 'Kategori pazaryeri eşleştirmesi yapılmamış')
            || str_contains($syncError, 'kategorisi satışa kapalı')
            || str_contains($syncError, 'kategori eşleştirmesi yok');
    }

    /**
     * Son gönderimde zorunlu özellik ya da kategori yüzünden atlanan ürünler, gruplanmış.
     *
     * @return array{attributes: list<array<string, mixed>>, categories: list<array<string, mixed>>, products: int}
     */
    public function gaps(int $marketplaceId): array
    {
        $this->ensureTable();
        $rows = $this->db->fetchAll(
            "SELECT m.sync_error, p.name, p.category_id, c.name AS panel_category,
                    CASE WHEN ho.id IS NOT NULL THEN ho.marketplace_category_id ELSE hm.marketplace_category_id END AS hb_id,
                    CASE WHEN ho.id IS NOT NULL THEN ho.marketplace_category_path ELSE hm.marketplace_category_path END AS hb_path,
                    CASE WHEN ho.id IS NOT NULL THEN 1 ELSE 0 END AS own
               FROM marketplace_products m
               JOIN products p ON p.id = m.product_id AND p.store_id = m.store_id
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN product_marketplace_category_overrides ho
                      ON ho.store_id = p.store_id AND ho.product_id = p.id AND ho.marketplace_id = :ad_own
               LEFT JOIN category_marketplace_mapping hm
                      ON hm.store_id = p.store_id AND hm.category_id = p.category_id AND hm.marketplace_id = :ad_panel
              WHERE m.store_id = :ad_store AND m.marketplace_id = :ad_mp
                AND COALESCE(m.is_listed, 0) = 0 AND COALESCE(p.is_archived, 0) = 0
                AND m.sync_error LIKE 'Validasyon hatası:%'",
            ['ad_own' => $marketplaceId, 'ad_panel' => $marketplaceId, 'ad_store' => $this->store(), 'ad_mp' => $marketplaceId]
        );

        $attrs = [];
        $cats = [];
        $products = 0;
        foreach ($rows as $r) {
            $error = (string) $r['sync_error'];
            $missing = self::missingAttributes($error);
            $hbId = trim((string) ($r['hb_id'] ?? ''));
            $name = mb_substr((string) $r['name'], 0, 90);
            if ($missing !== [] || self::lacksCategory($error)) {
                $products++;
            }
            if (self::lacksCategory($error) && (int) $r['own'] === 0) {
                $k = (string) (int) $r['category_id'];
                $cats[$k] ??= [
                    'category_id' => (int) $r['category_id'], 'category' => (string) ($r['panel_category'] ?? ''),
                    'current' => (string) ($r['hb_path'] ?? ''), 'count' => 0, 'samples' => [],
                ];
                $cats[$k]['count']++;
                if (count($cats[$k]['samples']) < 5) {
                    $cats[$k]['samples'][] = $name;
                }
            }
            if ($hbId === '') {
                continue;
            }
            foreach ($missing as $attrName) {
                $k = $hbId . '|' . $attrName;
                $attrs[$k] ??= [
                    'category_id' => $hbId, 'category' => (string) ($r['hb_path'] ?? ''), 'attribute' => $attrName,
                    'count' => 0, 'samples' => [],
                ];
                $attrs[$k]['count']++;
                if (count($attrs[$k]['samples']) < 5) {
                    $attrs[$k]['samples'][] = $name;
                }
            }
        }

        // Özellik no'su, türü ve kayıtlı varsayılan (HB şeması önbellekten)
        $schemas = [];
        foreach ($attrs as &$a) {
            $cid = $a['category_id'];
            if (!array_key_exists($cid, $schemas)) {
                try {
                    $schema = $this->hepsiburada->getCategoryAttributeSchema($cid);
                    $schemas[$cid] = array_merge($schema['attributes'], $schema['variant']);
                } catch (\Throwable) {
                    $schemas[$cid] = [];
                }
            }
            $a['attribute_id'] = '';
            $a['type'] = '';
            foreach ($schemas[$cid] as $s) {
                if ((string) $s['name'] === $a['attribute']) {
                    $a['attribute_id'] = (string) $s['id'];
                    $a['type'] = (string) $s['type'];
                    break;
                }
            }
            $a['measure'] = !\App\Services\Marketplace\HepsiburadaAttributeInference::allowsNeutral($a['attribute']);
            $a['default'] = $a['attribute_id'] !== '' ? ($this->forCategory($marketplaceId, $cid)[$a['attribute_id']] ?? '') : '';
        }
        unset($a);

        $attrs = array_values($attrs);
        usort($attrs, static fn(array $x, array $y): int => $y['count'] <=> $x['count']);
        $cats = array_values($cats);
        usort($cats, static fn(array $x, array $y): int => $y['count'] <=> $x['count']);

        return ['attributes' => $attrs, 'categories' => $cats, 'products' => $products];
    }

    /** @return array{type: string, values: list<string>} */
    public function values(string $categoryId, string $attributeId): array
    {
        foreach ($this->hepsiburada->getRequiredCategoryAttributes($categoryId) as $attr) {
            if ((string) $attr['id'] === $attributeId) {
                return ['type' => (string) $attr['type'], 'values' => array_values(array_map('strval', (array) ($attr['values'] ?? [])))];
            }
        }
        throw new \InvalidArgumentException('Bu özellik kategorinin zorunlu özellikleri arasında yok.');
    }

    /**
     * Varsayılanı kaydeder ('' siler). Listeli özellikte değer HB listesindeki yazımına çevrilir.
     */
    public function save(int $marketplaceId, string $categoryId, string $attributeId, string $value): string
    {
        $this->ensureTable();
        $value = trim($value);
        $categoryId = trim($categoryId);
        if ($categoryId === '' || $attributeId === '') {
            throw new \InvalidArgumentException('Kategori ve özellik gerekli.');
        }
        if ($value === '') {
            $this->db->query(
                'DELETE FROM marketplace_category_attribute_defaults
                  WHERE store_id = ? AND marketplace_id = ? AND marketplace_category_id = ? AND attribute_id = ?',
                [$this->store(), $marketplaceId, $categoryId, $attributeId]
            );
            unset($this->cache[$marketplaceId . '|' . $categoryId]);

            return '';
        }

        $name = $attributeId;
        foreach ($this->hepsiburada->getRequiredCategoryAttributes($categoryId) as $attr) {
            if ((string) $attr['id'] !== $attributeId) {
                continue;
            }
            $name = (string) $attr['name'];
            if (($attr['type'] ?? '') === 'enum' && ($attr['values'] ?? []) !== []) {
                $canonical = HepsiburadaService::canonicalAttributeValue($value, (array) $attr['values']);
                if ($canonical === null) {
                    throw new \InvalidArgumentException("'{$value}' Hepsiburada'nın {$name} listesinde yok.");
                }
                $value = $canonical;
            }
            break;
        }

        $this->db->query(
            'INSERT INTO marketplace_category_attribute_defaults
                (store_id, marketplace_id, marketplace_category_id, attribute_id, attribute_name, value, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE attribute_name = VALUES(attribute_name), value = VALUES(value), updated_at = NOW()',
            [$this->store(), $marketplaceId, $categoryId, $attributeId, mb_substr($name, 0, 255), mb_substr($value, 0, 500)]
        );
        unset($this->cache[$marketplaceId . '|' . $categoryId]);

        return $value;
    }

    /** Panel kategorisini satışa açık bir pazaryeri kategorisine bağlar (kapalı eşleştirmenin yerine de). */
    public function mapPanelCategory(int $marketplaceId, int $categoryId, string $targetId): array
    {
        $leaf = $this->catMapping->getMarketplaceCategoryLeafStatus($marketplaceId, $targetId);
        if ($leaf === null || !$leaf['sellable']) {
            throw new \InvalidArgumentException('Satışa açık bir Hepsiburada alt kategorisi seçin.');
        }
        $this->catMapping->saveMapping($categoryId, $marketplaceId, $targetId, $leaf['name'], $leaf['full_path']);

        return ['id' => $targetId, 'path' => $leaf['full_path']];
    }
}
