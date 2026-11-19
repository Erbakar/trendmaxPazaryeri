<?php
declare(strict_types=1);

namespace App\Services\Mapping;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\CategoryMarketplaceMapping;

/**
 * Bir pazaryerindeki kategori kararlarını başka bir pazaryerine taşır (ör. Trendyol → Hepsiburada).
 *
 * Kaynak, ürünün KAYNAK pazaryerinde gerçekten kullandığı kategoridir: ürüne özel seçim
 * (product_marketplace_category_overrides) varsa o, yoksa panel kategorisinin eşleştirmesi.
 * XML'den gelen panel kategorileri çoğu zaman yanlıştır (bıçak → oyuncak); Trendyol tarafında
 * düzeltilmiş kararı kullanmak bu hataları hedef pazaryerine taşımaz.
 *
 * Karşılıklar (kaynak kategori → hedef kategori) GLOBAL tabloda tutulur; uygulama:
 *  - ürüne özel kaynak kategorisi olan ürün → hedef pazaryerinde ürüne özel kategori,
 *  - panel eşleştirmesinden gelen ürün → panel kategorisinin hedef pazaryeri eşleştirmesi.
 * Kullanıcının hedef pazaryerinde zaten yaptığı eşleştirmelere dokunulmaz.
 */
final class CategoryCrosswalkService
{
    /** Bir uygula isteğinde işlenen en fazla ürün */
    public const APPLY_LIMIT = 500;

    /** Bir öneri isteğinde işlenen en fazla grup */
    public const SUGGEST_LIMIT = 25;

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly MappingCandidateFinder $finder,
        private readonly CategoryMarketplaceMapping $catMapping,
        private readonly Logger $logger,
    ) {}

    public function ensureTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS marketplace_category_crosswalk (
             source_marketplace_id INT UNSIGNED NOT NULL,
             source_category_id VARCHAR(100) NOT NULL,
             target_marketplace_id INT UNSIGNED NOT NULL,
             target_category_id VARCHAR(100) NOT NULL DEFAULT '',
             target_category_name VARCHAR(500) NOT NULL DEFAULT '',
             target_category_path TEXT NULL,
             method VARCHAR(20) NOT NULL DEFAULT 'oneri',
             confirmed TINYINT(1) NOT NULL DEFAULT 0,
             score SMALLINT NOT NULL DEFAULT 0,
             updated_by_store_id INT UNSIGNED NULL,
             updated_at DATETIME NOT NULL,
             PRIMARY KEY (source_marketplace_id, source_category_id, target_marketplace_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function marketplaceIdBySlug(string $slug): int
    {
        $row = $this->db->fetchOne('SELECT id FROM marketplaces WHERE slug = ? LIMIT 1', [$slug]);

        return $row !== false ? (int) $row['id'] : 0;
    }

    private function store(): int
    {
        return (int) $this->tenant->currentStoreId();
    }

    /**
     * Ürün başına kaynak kategori (derived tablo). Yer tutucular benzersizdir
     * (EMULATE_PREPARES=false → aynı adı iki kez kullanmak HY093 verir).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function sourceRows(int $src, int $tgt): array
    {
        $sql = "SELECT p.id, p.name, p.category_id,
                    CASE WHEN po.id IS NOT NULL THEN po.marketplace_category_id ELSE cm.marketplace_category_id END AS src_id,
                    CASE WHEN po.id IS NOT NULL THEN po.marketplace_category_name ELSE cm.marketplace_category_name END AS src_name,
                    CASE WHEN po.id IS NOT NULL THEN po.marketplace_category_path ELSE cm.marketplace_category_path END AS src_path,
                    CASE WHEN po.id IS NOT NULL THEN 1 ELSE 0 END AS own,
                    CASE WHEN EXISTS (SELECT 1 FROM marketplace_products m
                                       WHERE m.store_id = p.store_id AND m.product_id = p.id
                                         AND m.marketplace_id = :cw_src_listed AND m.is_listed = 1) THEN 1 ELSE 0 END AS listed,
                    CASE WHEN EXISTS (SELECT 1 FROM product_marketplace_category_overrides ho
                                       WHERE ho.store_id = p.store_id AND ho.product_id = p.id AND ho.marketplace_id = :cw_tgt_own)
                           OR (po.id IS NULL AND EXISTS (SELECT 1 FROM category_marketplace_mapping hm
                                       WHERE hm.store_id = p.store_id AND hm.category_id = p.category_id
                                         AND hm.marketplace_id = :cw_tgt_panel))
                         THEN 1 ELSE 0 END AS has_target
                  FROM products p
                  LEFT JOIN product_marketplace_category_overrides po
                         ON po.store_id = p.store_id AND po.product_id = p.id AND po.marketplace_id = :cw_src_own
                  LEFT JOIN category_marketplace_mapping cm
                         ON cm.store_id = p.store_id AND cm.category_id = p.category_id AND cm.marketplace_id = :cw_src_panel
                 WHERE p.store_id = :cw_store AND COALESCE(p.is_archived, 0) = 0
                   AND (po.id IS NOT NULL OR cm.id IS NOT NULL)";

        return [$sql, [
            'cw_src_listed' => $src, 'cw_tgt_own' => $tgt, 'cw_tgt_panel' => $tgt,
            'cw_src_own' => $src, 'cw_src_panel' => $src, 'cw_store' => $this->store(),
        ]];
    }

    /**
     * Kaynak kategoriye göre gruplar: ürün sayıları, örnek adlar ve kayıtlı karşılık.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(int $src, int $tgt): array
    {
        $this->ensureTable();
        [$inner, $params] = $this->sourceRows($src, $tgt);

        $this->db->query('SET SESSION group_concat_max_len = 65535');
        $rows = $this->db->fetchAll(
            "SELECT x.src_id, MAX(x.src_name) AS src_name, MAX(x.src_path) AS src_path,
                    COUNT(*) AS total, SUM(x.own) AS own, SUM(x.listed) AS listed, SUM(x.has_target) AS mapped,
                    SUBSTRING_INDEX(GROUP_CONCAT(SUBSTRING(x.name, 1, 90) ORDER BY x.listed DESC, x.id SEPARATOR '\\n'), '\\n', 5) AS samples
               FROM ({$inner}) x
              GROUP BY x.src_id
              ORDER BY total DESC",
            $params
        );

        $karsiliklar = [];
        foreach ($this->db->fetchAll(
            'SELECT * FROM marketplace_category_crosswalk WHERE source_marketplace_id = ? AND target_marketplace_id = ?',
            [$src, $tgt]
        ) as $k) {
            $karsiliklar[(string) $k['source_category_id']] = $k;
        }

        return array_map(static function (array $r) use ($karsiliklar): array {
            $k = $karsiliklar[(string) $r['src_id']] ?? null;

            return [
                'source_id'   => (string) $r['src_id'],
                'source_name' => (string) $r['src_name'],
                'source_path' => (string) $r['src_path'],
                'total'       => (int) $r['total'],
                'own'         => (int) $r['own'],
                'listed'      => (int) $r['listed'],
                'mapped'      => (int) $r['mapped'],
                'samples'     => array_values(array_filter(explode("\n", (string) $r['samples']), 'strlen')),
                'target'      => $k === null ? null : [
                    'id'        => (string) $k['target_category_id'],
                    'name'      => (string) $k['target_category_name'],
                    'path'      => (string) ($k['target_category_path'] ?? ''),
                    'method'    => (string) $k['method'],
                    'confirmed' => (int) $k['confirmed'] === 1,
                    'score'     => (int) $k['score'],
                ],
            ];
        }, $rows ?: []);
    }

    /**
     * Kaynak kategori için hedef pazaryeri aday kategorileri (bulanık skor).
     *
     * @return array<int, array{id: string, name: string, path: string, score: int}>
     */
    public function candidates(int $src, int $tgt, string $sourceCategoryId, int $limit = 12): array
    {
        $kaynak = $this->sourceCategory($src, $sourceCategoryId);
        if ($kaynak === null) {
            return [];
        }

        return $this->finder->candidatesForCategory(
            ['name' => $kaynak['name'], 'full_path' => self::pathWithoutLeaf($kaynak['path'], $kaynak['name'])],
            $tgt,
            $limit
        );
    }

    /**
     * Kaynak kategorinin adı ve yolu: önce kategori önbelleği, yoksa mağazanın kendi kayıtları.
     *
     * @return array{name: string, path: string}|null
     */
    private function sourceCategory(int $src, string $sourceCategoryId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT name, full_path FROM marketplace_categories WHERE marketplace_id = ? AND mp_category_id = ? LIMIT 1',
            [$src, $sourceCategoryId]
        );
        if ($row !== false && trim((string) $row['name']) !== '') {
            return ['name' => (string) $row['name'], 'path' => (string) ($row['full_path'] ?? '')];
        }

        $row = $this->db->fetchOne(
            'SELECT marketplace_category_name AS name, marketplace_category_path AS path FROM product_marketplace_category_overrides
              WHERE store_id = ? AND marketplace_id = ? AND marketplace_category_id = ? LIMIT 1',
            [$this->store(), $src, $sourceCategoryId]
        ) ?: $this->db->fetchOne(
            'SELECT marketplace_category_name AS name, marketplace_category_path AS path FROM category_marketplace_mapping
              WHERE store_id = ? AND marketplace_id = ? AND marketplace_category_id = ? LIMIT 1',
            [$this->store(), $src, $sourceCategoryId]
        );

        return $row !== false && trim((string) $row['name']) !== ''
            ? ['name' => (string) $row['name'], 'path' => (string) ($row['path'] ?? '')]
            : null;
    }

    /** "A > B > Leaf" → "A > B" (aday skorunda yol bileşeni yaprağı iki kez saymasın) */
    public static function pathWithoutLeaf(string $path, string $leaf): string
    {
        $parcalar = array_map('trim', explode('>', $path));
        if ($parcalar !== [] && mb_strtolower(end($parcalar)) === mb_strtolower(trim($leaf))) {
            array_pop($parcalar);
        }

        return implode(' > ', array_filter($parcalar, 'strlen'));
    }

    /**
     * Karşılığı olmayan grupların ilk SUGGEST_LIMIT kadarına bulanık öneri yaz (onaysız).
     *
     * @return array{suggested: int, remaining: int}
     */
    public function suggest(int $src, int $tgt): array
    {
        $bekleyen = array_values(array_filter($this->groups($src, $tgt), static fn(array $g): bool => $g['target'] === null));
        $islenen = 0;

        foreach (array_slice($bekleyen, 0, self::SUGGEST_LIMIT) as $g) {
            $aday = $this->candidates($src, $tgt, $g['source_id'], 1)[0] ?? null;
            $this->save($src, $tgt, $g['source_id'], $aday['id'] ?? '', $aday['name'] ?? '', $aday['path'] ?? '', 'bulanik', false, (int) ($aday['score'] ?? 0));
            $islenen++;
        }

        return ['suggested' => $islenen, 'remaining' => max(0, count($bekleyen) - $islenen)];
    }

    /**
     * Karşılığı kaydet. $targetId '' = bu kaynak kategoriyi hedefe gönderme.
     */
    public function setTarget(int $src, int $tgt, string $sourceCategoryId, string $targetId, bool $confirmed, string $method = 'elle'): array
    {
        $sourceCategoryId = trim($sourceCategoryId);
        $targetId = trim($targetId);
        if ($sourceCategoryId === '') {
            throw new \InvalidArgumentException('Kaynak kategori boş.');
        }

        $ad = '';
        $yol = '';
        if ($targetId !== '') {
            $yaprak = $this->catMapping->getMarketplaceCategoryLeafStatus($tgt, $targetId);
            if ($yaprak === null) {
                throw new \InvalidArgumentException("Hedef kategori #{$targetId} kategori listesinde yok. Önce kategorileri güncelleyin.");
            }
            if (!$yaprak['sellable']) {
                throw new \InvalidArgumentException("#{$targetId} ({$yaprak['name']}) satışa açık bir alt kategori değil.");
            }
            $ad = $yaprak['name'];
            $yol = $yaprak['full_path'];
        }

        $this->save($src, $tgt, $sourceCategoryId, $targetId, $ad, $yol, $method, $confirmed, $confirmed ? 100 : 0);

        return ['id' => $targetId, 'name' => $ad, 'path' => $yol, 'confirmed' => $confirmed];
    }

    private function save(int $src, int $tgt, string $sourceId, string $targetId, string $name, string $path, string $method, bool $confirmed, int $score): void
    {
        $this->ensureTable();
        $this->db->query(
            'INSERT INTO marketplace_category_crosswalk
                (source_marketplace_id, source_category_id, target_marketplace_id, target_category_id, target_category_name,
                 target_category_path, method, confirmed, score, updated_by_store_id, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE target_category_id = VALUES(target_category_id), target_category_name = VALUES(target_category_name),
                target_category_path = VALUES(target_category_path), method = VALUES(method), confirmed = VALUES(confirmed),
                score = VALUES(score), updated_by_store_id = VALUES(updated_by_store_id), updated_at = NOW()',
            [$src, $sourceId, $tgt, $targetId, mb_substr($name, 0, 500), $path, mb_substr($method, 0, 20), $confirmed ? 1 : 0, max(-1, min(100, $score)), $this->store()]
        );
    }

    /**
     * Dışarıda hazırlanmış karşılıkları içeri al. Onaylı karşılıklara dokunulmaz; gelenler
     * ÖNERİ olarak yazılır, kullanıcı ekranda inceleyip onaylar.
     *
     * @param array<int, mixed> $rows [{source: "766", target: "60001234"|"" }]
     * @return array{imported: int, kept: int, errors: array<int, string>}
     */
    public function import(int $src, int $tgt, array $rows): array
    {
        $this->ensureTable();
        $onayli = [];
        foreach ($this->db->fetchAll(
            'SELECT source_category_id FROM marketplace_category_crosswalk WHERE source_marketplace_id = ? AND target_marketplace_id = ? AND confirmed = 1',
            [$src, $tgt]
        ) as $r) {
            $onayli[(string) $r['source_category_id']] = true;
        }

        $sonuc = ['imported' => 0, 'kept' => 0, 'errors' => []];
        foreach (array_slice($rows, 0, 5000) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kaynak = trim((string) ($row['source'] ?? ''));
            if ($kaynak === '') {
                continue;
            }
            if (isset($onayli[$kaynak])) {
                $sonuc['kept']++;
                continue;
            }
            try {
                $this->setTarget($src, $tgt, $kaynak, (string) ($row['target'] ?? ''), false, 'iceri');
                $sonuc['imported']++;
            } catch (\Throwable $e) {
                if (count($sonuc['errors']) < 50) {
                    $sonuc['errors'][] = "#{$kaynak}: " . $e->getMessage();
                }
            }
        }

        return $sonuc;
    }

    /**
     * Onaylı karşılığı ürünlere uygula (ürün id'sine göre sayfalı).
     *
     * @return array{source: string, created_overrides: int, created_mappings: int, skipped: int, last_id: int, limited: bool}
     */
    public function apply(int $src, int $tgt, string $sourceCategoryId, int $afterId = 0): array
    {
        $this->ensureTable();
        $k = $this->db->fetchOne(
            'SELECT * FROM marketplace_category_crosswalk WHERE source_marketplace_id = ? AND source_category_id = ? AND target_marketplace_id = ?',
            [$src, $sourceCategoryId, $tgt]
        );
        if ($k === false || (int) $k['confirmed'] !== 1) {
            throw new \InvalidArgumentException('Bu kategorinin karşılığı onaylanmamış.');
        }

        $sonuc = ['source' => $sourceCategoryId, 'created_overrides' => 0, 'created_mappings' => 0, 'skipped' => 0, 'last_id' => $afterId, 'limited' => false];
        $hedef = (string) $k['target_category_id'];
        if ($hedef === '') {
            return $sonuc; // "gönderme" kararı: hedefte eşleştirme oluşturulmaz
        }

        $yaprak = $this->catMapping->getMarketplaceCategoryLeafStatus($tgt, $hedef);
        if ($yaprak === null || !$yaprak['sellable']) {
            throw new \InvalidArgumentException("Hedef kategori #{$hedef} artık satışa açık değil; karşılığı değiştirin.");
        }

        [$inner, $params] = $this->sourceRows($src, $tgt);
        $params['cw_source'] = $sourceCategoryId;
        $params['cw_after'] = $afterId;
        $limit = self::APPLY_LIMIT;
        $rows = $this->db->fetchAll(
            "SELECT x.* FROM ({$inner}) x WHERE x.src_id = :cw_source AND x.id > :cw_after ORDER BY x.id LIMIT {$limit}",
            $params
        );

        $panelKategoriler = [];
        foreach ($rows as $row) {
            $sonuc['last_id'] = (int) $row['id'];
            if ((int) $row['has_target'] === 1) {
                $sonuc['skipped']++;
                continue;
            }
            if ((int) $row['own'] === 1) {
                $this->db->query(
                    'INSERT IGNORE INTO product_marketplace_category_overrides
                        (store_id, product_id, marketplace_id, marketplace_category_id, marketplace_category_name, marketplace_category_path)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$this->store(), (int) $row['id'], $tgt, $hedef, $yaprak['name'], $yaprak['full_path']]
                );
                $sonuc['created_overrides']++;
            } elseif ((int) $row['category_id'] > 0) {
                $panelKategoriler[(int) $row['category_id']] = true;
            }
        }

        foreach (array_keys($panelKategoriler) as $kategoriId) {
            if ($this->catMapping->findMapping($kategoriId, $tgt) !== false) {
                continue;
            }
            $this->catMapping->saveMapping($kategoriId, $tgt, $hedef, $yaprak['name'], $yaprak['full_path']);
            $sonuc['created_mappings']++;
        }

        $sonuc['limited'] = count($rows) >= $limit;
        if ($sonuc['created_overrides'] + $sonuc['created_mappings'] > 0) {
            $this->logger->info(sprintf(
                'Kategori karşılığı uygulandı (%d→%d) #%s → #%s: %d ürüne özel, %d panel eşleştirmesi',
                $src, $tgt, $sourceCategoryId, $hedef, $sonuc['created_overrides'], $sonuc['created_mappings']
            ), 'CategoryCrosswalk');
        }

        return $sonuc;
    }
}
