<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Pazaryeri Kategori Cache — Her pazaryerinden çekilen kategorileri saklar
 *
 * Global platformların (Trendyol, Hepsiburada …) katalogu tüm mağazalarda ortaktır (store_id = 0).
 * WooCommerce / Shopify / OpenCart kategorileri mağazanın kendi sitesine aittir: store_id = mağaza ile
 * yazılır, yalnız o mağazaya okunur (MarketplaceCatalogScope; kolon yoksa eski global davranış).
 */
final class MarketplaceCategory extends BaseModel
{
    protected string $table = 'marketplace_categories';
    protected bool $tenantScoped = false; // GLOBAL referans (D2): platform kategori katalogu (+ mağaza kapsamlı platformlar)

    /**
     * Okuma kapsamı: [SQL koşulu, parametreler]. Parametre yalnız koşulda geçiyorsa döner
     * (PDO gerçek prepare: fazla parametre HY093).
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function scopeSql(string $alias, string $param = 'mc_scope_sid'): array
    {
        $sql = MarketplaceCatalogScope::condition($this->db, $this->table, $alias, ':' . $param);

        return [$sql, str_contains($sql, ':' . $param)
            ? [$param => MarketplaceCatalogScope::readStoreId($this->currentStoreOrNull())]
            : []];
    }

    /**
     * Mağaza kapsamı (dış ham sorgular için; ör. kategori araması, eşleştirme aday havuzu):
     * [SQL koşulu, parametreler]. Kolon yoksa ['1=1', []].
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public function scopeFilter(string $alias, string $param = 'mc_scope_sid'): array
    {
        return $this->scopeSql($alias, $param);
    }

    /**
     * Yazma kapsamı (store_id değeri). null: mağaza kapsamlı platformda aktif mağaza yok → yazılamaz.
     */
    private function writeStoreId(int $marketplaceId): ?int
    {
        return MarketplaceCatalogScope::writeStoreId($this->db, $this->table, $marketplaceId, $this->currentStoreOrNull());
    }

    private function hasScopeColumn(): bool
    {
        return MarketplaceCatalogScope::hasColumn($this->db, $this->table);
    }

    private function currentStoreOrNull(): ?int
    {
        try {
            $storeId = $this->tenant->currentStoreId();
        } catch (\Throwable) {
            return null;
        }

        return $storeId > 0 ? $storeId : null;
    }

    private function requireWriteStoreId(int $marketplaceId): int
    {
        $storeId = $this->writeStoreId($marketplaceId);
        if ($storeId === null) {
            throw new \RuntimeException('Bu platformun kategorileri mağazaya özeldir; aktif mağaza bağlamı olmadan kaydedilemez.');
        }

        return $storeId;
    }

    /**
     * Satış durumu kolonları (database/migrations/phase12/052). NULL: pazaryeri bu bilgiyi
     * vermiyor ya da önbellek eski. Şema yalnız migration ile değişir — çalışma anında ALTER YOK.
     */
    private const AVAILABILITY_COLUMNS = ['is_active', 'is_available'];

    /** Kolonlar yokken yeniden kontrol aralığı (sn) — migration uzun ömürlü süreçte de fark edilsin */
    private const AVAILABILITY_RECHECK_SECONDS = 60;

    /** Şema kontrolü sonucu (aynı DB'yi paylaşan tüm örnekler için; kolon varlığı mağazaya bağlı değildir) */
    private static ?bool $availabilityColumnsReady = null;
    private static int $availabilityCheckedAt = 0;

    /**
     * Satış durumu kolonları şemada var mı (şemayı DEĞİŞTİRMEZ).
     */
    public function hasAvailabilityColumns(): bool
    {
        if (self::$availabilityColumnsReady === true) {
            return true;
        }
        if (self::$availabilityColumnsReady === false
            && (time() - self::$availabilityCheckedAt) < self::AVAILABILITY_RECHECK_SECONDS
        ) {
            return false;
        }

        try {
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'marketplace_categories'
                    AND COLUMN_NAME IN ('is_active', 'is_available')"
            );
            $ready = (int) ($row['cnt'] ?? 0) === count(self::AVAILABILITY_COLUMNS);
        } catch (\Throwable) {
            $ready = false;
        }

        self::$availabilityColumnsReady = $ready;
        self::$availabilityCheckedAt = time();

        return $ready;
    }

    /**
     * Bu pazaryerinin önbelleği satış durumu bilgisi taşıyor mu (yeni senkronla dolmuş mu).
     */
    public function hasAvailabilityData(int $marketplaceId): bool
    {
        if (!$this->hasAvailabilityColumns()) {
            return false;
        }

        [$scope, $scopeParams] = $this->scopeSql('');
        $row = $this->db->fetchOne(
            "SELECT 1 FROM marketplace_categories
              WHERE marketplace_id = :mid AND {$scope} AND (is_available IS NOT NULL OR is_active IS NOT NULL)
              LIMIT 1",
            ['mid' => $marketplaceId] + $scopeParams
        );

        return $row !== false;
    }

    /**
     * "Satışa uygun yaprak kategori" SQL koşulu. Durum bilgisi NULL ise uygun sayılır;
     * kolonlar yoksa yalnız yaprak koşulu döner.
     *
     * @param string $alias Tablo takma adı (ör. 'mc'); yalnız harf/rakam/alt çizgi kabul edilir
     */
    public function sellableCondition(string $alias = ''): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?? '';
        $p = $alias !== '' ? $alias . '.' : '';

        if (!$this->hasAvailabilityColumns()) {
            return "{$p}is_leaf = 1";
        }

        return "{$p}is_leaf = 1 AND COALESCE({$p}is_active, 1) = 1 AND COALESCE({$p}is_available, 1) = 1";
    }

    /**
     * Toplu upsert — on binlerce kategori için tek tek sorgu yerine INSERT ... ON DUPLICATE KEY UPDATE.
     * Satış durumu kolonları yoksa is_active / is_available yazılmaz (eski şema uyumu).
     *
     * @param array<int, array{mp_category_id: string, name: string, parent_mp_id?: ?string, full_path?: ?string, is_leaf?: bool, is_active?: ?bool, is_available?: ?bool}> $rows
     * @return int İşlenen (tekilleştirilmiş) kayıt sayısı
     */
    public function bulkUpsert(int $marketplaceId, array $rows, int $chunkSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        $unique = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id   = trim((string) ($row['mp_category_id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $row['name'] = $name;
            $unique[$id] = $row;
        }

        if ($unique === []) {
            return 0;
        }

        $withScope = $this->hasScopeColumn();
        $scopeStoreId = $withScope ? $this->requireWriteStoreId($marketplaceId) : 0;

        $withAvailability = $this->hasAvailabilityColumns();
        $columns = ['marketplace_id'];
        if ($withScope) {
            $columns[] = 'store_id';
        }
        array_push($columns, 'mp_category_id', 'name', 'parent_mp_id', 'full_path', 'is_leaf');
        if ($withAvailability) {
            $columns[] = 'is_active';
            $columns[] = 'is_available';
        }
        $columns[] = 'synced_at';

        $updates = [];
        foreach ($columns as $column) {
            if (!in_array($column, ['marketplace_id', 'store_id', 'mp_category_id'], true)) {
                $updates[] = "{$column} = VALUES({$column})";
            }
        }

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $now = date('Y-m-d H:i:s');
        $processed = 0;

        foreach (array_chunk($unique, max(1, $chunkSize), true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $id => $row) {
                $placeholders[] = $rowPlaceholder;
                $parent = isset($row['parent_mp_id']) ? trim((string) $row['parent_mp_id']) : '';

                $params[] = $marketplaceId;
                if ($withScope) {
                    $params[] = $scopeStoreId;
                }
                $params[] = (string) $id;
                $params[] = mb_substr((string) $row['name'], 0, 500);
                $params[] = $parent !== '' ? $parent : null;
                $params[] = isset($row['full_path']) ? (string) $row['full_path'] : null;
                $params[] = !empty($row['is_leaf']) ? 1 : 0;
                if ($withAvailability) {
                    $params[] = self::nullableFlag($row['is_active'] ?? null);
                    $params[] = self::nullableFlag($row['is_available'] ?? null);
                }
                $params[] = $now;
            }

            $this->db->query(
                "INSERT INTO marketplace_categories (" . implode(', ', $columns) . ")
                 VALUES " . implode(', ', $placeholders) . "
                 ON DUPLICATE KEY UPDATE " . implode(', ', $updates),
                $params
            );
            $processed += count($chunk);
        }

        return $processed;
    }

    /**
     * Son TAM senkronda pazaryerinden GELMEYEN kategorileri satışa kapalı işaretle
     * (silinmiş/taşınmış kategoriye eşleşme gönderimde yakalansın). Kolonlar yoksa 0.
     * Çağıran, kısmi yanıtta bunu çağırmamalıdır (bkz. InitialImportService::saveFlatCategories %90 koruması).
     */
    public function markUnavailableSyncedBefore(int $marketplaceId, string $syncStartedAt): int
    {
        if (!$this->hasAvailabilityColumns()) {
            return 0;
        }

        $params = ['mid' => $marketplaceId, 'ts' => $syncStartedAt];
        $scope = '';
        if ($this->hasScopeColumn()) {
            $storeId = $this->writeStoreId($marketplaceId);
            if ($storeId === null) {
                return 0;
            }
            $scope = ' AND store_id = :w_sid';
            $params['w_sid'] = $storeId;
        }

        return $this->db->query(
            "UPDATE marketplace_categories SET is_active = 0, is_available = 0
              WHERE marketplace_id = :mid AND synced_at < :ts{$scope}",
            $params
        )->rowCount();
    }

    /**
     * Mağazanın satışa uygun OLMAYAN kategoriye bağlı eşleşmeleri (önbellekte durum bilgisi
     * yoksa boş döner). Yalnız verilen mağazanın eşleşmeleri okunur.
     *
     * @return array<int, array{reason: string, mp_category_id: string, path: string}> category_id => ayrıntı
     *         reason: 'bulunamadi' | 'ara' | 'pasif' | 'kapali'
     */
    public function findUnsellableMappings(int $marketplaceId, int $storeId): array
    {
        if ($storeId <= 0 || !$this->hasAvailabilityData($marketplaceId)) {
            return [];
        }

        $mcScope = MarketplaceCatalogScope::condition($this->db, $this->table, 'mc', 'cmm.store_id');
        $rows = $this->db->fetchAll(
            "SELECT cmm.category_id, cmm.marketplace_category_id, cmm.marketplace_category_name,
                    cmm.marketplace_category_path, mc.id AS mc_id, mc.full_path, mc.is_leaf, mc.is_active, mc.is_available
               FROM category_marketplace_mapping cmm
               LEFT JOIN marketplace_categories mc
                      ON mc.marketplace_id = cmm.marketplace_id AND mc.mp_category_id = cmm.marketplace_category_id
                     AND {$mcScope}
              WHERE cmm.marketplace_id = :mid AND cmm.store_id = :sid",
            ['mid' => $marketplaceId, 'sid' => $storeId]
        );

        $invalid = [];
        foreach ($rows as $row) {
            $reason = match (true) {
                $row['mc_id'] === null                                              => 'bulunamadi',
                (int) $row['is_leaf'] !== 1                                         => 'ara',
                $row['is_active'] !== null && (int) $row['is_active'] === 0         => 'pasif',
                $row['is_available'] !== null && (int) $row['is_available'] === 0   => 'kapali',
                default                                                             => '',
            };
            if ($reason === '') {
                continue;
            }

            $path = (string) ($row['full_path'] ?? '');
            if ($path === '') {
                $path = (string) (($row['marketplace_category_path'] ?? '') ?: ($row['marketplace_category_name'] ?? ''));
            }

            $invalid[(int) $row['category_id']] = [
                'reason'         => $reason,
                'mp_category_id' => (string) $row['marketplace_category_id'],
                'path'           => $path,
            ];
        }

        return $invalid;
    }

    private static function nullableFlag(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $value ? 1 : 0;
    }

    /**
     * Pazaryeri + kategori ID ile bul
     */
    public function findByMpCatId(int $marketplaceId, string $mpCategoryId): array|false
    {
        [$scope, $scopeParams] = $this->scopeSql('');

        return $this->rawQueryOne(
            "SELECT * FROM marketplace_categories WHERE marketplace_id = :mid AND mp_category_id = :cid AND {$scope} LIMIT 1",
            ['mid' => $marketplaceId, 'cid' => $mpCategoryId] + $scopeParams
        );
    }

    /**
     * Pazaryeri kategorisini kaydet veya güncelle (upsert)
     */
    public function upsert(int $marketplaceId, string $mpCategoryId, string $name, ?string $parentMpId = null, ?string $fullPath = null, bool $isLeaf = false): int
    {
        $scopeStoreId = $this->hasScopeColumn() ? $this->requireWriteStoreId($marketplaceId) : null;
        $existing = $this->findByMpCatId($marketplaceId, $mpCategoryId);

        if ($existing !== false) {
            $this->update((int)$existing['id'], [
                'name'         => $name,
                'parent_mp_id' => $parentMpId,
                'full_path'    => $fullPath,
                'is_leaf'      => $isLeaf ? 1 : 0,
                'synced_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int)$existing['id'];
        }

        $data = [
            'marketplace_id' => $marketplaceId,
            'mp_category_id' => $mpCategoryId,
            'name'           => $name,
            'parent_mp_id'   => $parentMpId,
            'full_path'      => $fullPath,
            'is_leaf'        => $isLeaf ? 1 : 0,
        ];
        if ($scopeStoreId !== null) {
            $data['store_id'] = $scopeStoreId;
        }

        return $this->create($data);
    }

    /**
     * Pazaryerindeki tüm kategorileri getir
     */
    public function getAllByMarketplace(int $marketplaceId): array
    {
        [$scope, $scopeParams] = $this->scopeSql('');

        return $this->rawQuery(
            "SELECT * FROM marketplace_categories WHERE marketplace_id = :mid AND {$scope} ORDER BY name ASC",
            ['mid' => $marketplaceId] + $scopeParams
        );
    }

    /**
     * İsimle arama (fuzzy — LIKE ile, birden çok pazaryeri destekli)
     */
    /**
     * Arama cümlesini anlamlı kelimelere ayır.
     *
     * Kelime bulunamazsa cümlenin tamamı tek terim olarak kullanılır.
     *
     * @return array<int, string>
     */
    private function searchPool(string $name): array
    {
        $parcalar = preg_split('/[^\p{L}\p{N}]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kelimeler = array_values(array_unique(array_filter(
            $parcalar,
            static fn(string $t): bool => mb_strlen($t) > 2
        )));

        // En fazla 6 kelime — sorgu şişmesin
        return $kelimeler === [] ? [trim($name)] : array_slice($kelimeler, 0, 6);
    }

    public function searchByName(string $name, ?int $marketplaceId = null, bool $leafOnly = true): array
    {
        // Cümlenin tamamını aramak yerine KELİMELERİ ara: "Kalem ve Yazı
        // Gereçleri" sorgusu bugün hiçbir sonuç vermiyordu çünkü hiçbir
        // pazaryeri kategorisi bu cümlenin tamamını içermez.
        $kelimeler = $this->searchPool($name);

        $kosullar = [];
        $params = [];
        foreach ($kelimeler as $i => $kelime) {
            $kosullar[] = "mc.name LIKE :k{$i}";
            $params["k{$i}"] = '%' . $kelime . '%';
        }

        [$scope, $scopeParams] = $this->scopeSql('mc');
        $params += $scopeParams;

        $sql = "SELECT mc.*, m.slug as marketplace_slug, m.display_name as marketplace_name
                FROM marketplace_categories mc
                JOIN marketplaces m ON mc.marketplace_id = m.id
                WHERE (" . implode(' OR ', $kosullar) . ")
                  AND {$scope}";

        if ($marketplaceId !== null) {
            $sql .= " AND mc.marketplace_id = :mid";
            $params['mid'] = $marketplaceId;
        }

        if ($leafOnly) {
            // Seçilebilir = satışa uygun yaprak (durum bilgisi yoksa yalnız yaprak)
            $sql .= ' AND ' . $this->sellableCondition('mc');
        }

        $sql .= " ORDER BY mc.name ASC LIMIT 300";

        return $this->rawQuery($sql, $params);
    }

    /**
     * Seçilebilir kategori araması (tek pazaryeri): her kelime ADDA ya da TAM YOLDA geçmeli (kelimeler VE ile
     * bağlanır — "kahvaltılık bal" yolu "Gıda > Kahvaltılık > Bal" olan yaprağı da bulur). Yalnız satışa
     * uygun yapraklar; sıralama: ad = sorgu, ad sorguyla başlar, ad sorguyu içerir, kısa ad, kısa yol.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchSellable(string $query, int $marketplaceId, int $limit = 50): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if ($query === '' || $marketplaceId <= 0) {
            return [];
        }

        $conditions = [];
        $params = ['mid' => $marketplaceId];
        foreach ($this->searchPool($query) as $i => $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $conditions[] = "(mc.name LIKE :n{$i} OR mc.full_path LIKE :p{$i})";
            $params["n{$i}"] = $like;
            $params["p{$i}"] = $like;
        }
        $params['q_exact']  = $query;
        $params['q_prefix'] = addcslashes($query, '%_\\') . '%';
        $params['q_any']    = '%' . addcslashes($query, '%_\\') . '%';
        [$scope, $scopeParams] = $this->scopeSql('mc');
        $params += $scopeParams;

        $sql = "SELECT mc.*, m.slug AS marketplace_slug, m.display_name AS marketplace_name
                  FROM marketplace_categories mc
                  JOIN marketplaces m ON mc.marketplace_id = m.id
                 WHERE mc.marketplace_id = :mid
                   AND {$scope}
                   AND " . implode(' AND ', $conditions) . "
                   AND " . $this->sellableCondition('mc') . "
                 ORDER BY (mc.name = :q_exact) DESC, (mc.name LIKE :q_prefix) DESC, (mc.name LIKE :q_any) DESC,
                          CHAR_LENGTH(mc.name) ASC, CHAR_LENGTH(mc.full_path) ASC
                 LIMIT " . max(1, min(100, $limit));

        return $this->rawQuery($sql, $params);
    }

    /**
     * Tam isim eşleşmesi (case-insensitive, belirli pazaryeri)
     */
    public function findExactMatch(string $name, int $marketplaceId, bool $leafOnly = true): array|false
    {
        [$scope, $scopeParams] = $this->scopeSql('');
        $sql = "SELECT * FROM marketplace_categories
                WHERE LOWER(name) = LOWER(:name) AND marketplace_id = :mid AND {$scope}";
        if ($leafOnly) {
            // Seçilebilir = satışa uygun yaprak (durum bilgisi yoksa yalnız yaprak)
            $sql .= ' AND ' . $this->sellableCondition();
        }
        $sql .= " LIMIT 1";

        return $this->rawQueryOne($sql, ['name' => $name, 'mid' => $marketplaceId] + $scopeParams);
    }

    /**
     * Belirli pazaryerideki toplam kategori sayısı
     */
    public function countByMarketplace(int $marketplaceId): int
    {
        [$scope, $scopeParams] = $this->scopeSql('');
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM marketplace_categories WHERE marketplace_id = :mid AND {$scope}",
            ['mid' => $marketplaceId] + $scopeParams
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Satış durumu AÇIK (kapalı işaretlenmemiş) önbellek satırı sayısı. "Liste tam mı" oranı buna
     * göre hesaplanır: daha önce kapatılmış eski satırlar sayılırsa sonraki TAM listeler de
     * "eksik" görünür ve yeni kaldırılan kategoriler hiç kapatılmaz. Kolonlar yoksa toplam sayı.
     */
    public function countOpenByMarketplace(int $marketplaceId): int
    {
        if (!$this->hasAvailabilityColumns()) {
            return $this->countByMarketplace($marketplaceId);
        }

        [$scope, $scopeParams] = $this->scopeSql('');
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) AS cnt FROM marketplace_categories
              WHERE marketplace_id = :mid AND {$scope} AND COALESCE(is_active, 1) = 1 AND COALESCE(is_available, 1) = 1",
            ['mid' => $marketplaceId] + $scopeParams
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Önbelleği sil. Mağaza kapsamlı platformda yalnız aktif mağazanın satırları silinir.
     */
    public function deleteByMarketplace(int $marketplaceId): int
    {
        $params = ['mid' => $marketplaceId];
        $scope = '';
        if ($this->hasScopeColumn()) {
            $storeId = $this->writeStoreId($marketplaceId);
            if ($storeId === null) {
                return 0;
            }
            $scope = ' AND store_id = :w_sid';
            $params['w_sid'] = $storeId;
        }

        $stmt = $this->db->query(
            "DELETE FROM marketplace_categories WHERE marketplace_id = :mid{$scope}",
            $params
        );
        return $stmt->rowCount();
    }

    /**
     * Paginated search with mapping status — for marketplace tab listing
     * @return array{items: array, total: int}
     */
    public function paginatedWithMappingStatus(
        int $marketplaceId,
        string $search = '',
        bool $leafOnly = false,
        int $page = 1,
        int $perPage = 50
    ): array {
        $offset = ($page - 1) * $perPage;

        [$scope, $scopeParams] = $this->scopeSql('mc');
        $where = 'mc.marketplace_id = :mid AND ' . $scope;
        $params = ['mid' => $marketplaceId] + $scopeParams;

        if ($search !== '') {
            $where .= ' AND mc.name LIKE :q';
            $params['q'] = '%' . $search . '%';
        }
        if ($leafOnly) {
            $where .= ' AND mc.is_leaf = 1';
        }

        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM marketplace_categories mc WHERE {$where}", $params
        );
        $total = (int)($countRow['cnt'] ?? 0);

        // Eşleşme durumu yalnız AKTİF MAĞAZANIN kayıtlarından okunur (kategori kataloğu global,
        // eşleşmeler mağazaya özel — filtresiz JOIN başka mağazanın kategori adını gösteriyordu).
        $sellable = $this->sellableCondition('mc');
        $items = $this->db->fetchAll(
            "SELECT mc.*,
                    (CASE WHEN {$sellable} THEN 1 ELSE 0 END) as is_sellable,
                    cmm.id as mapping_id,
                    cmm.category_id as system_category_id,
                    c.name as system_category_name
             FROM marketplace_categories mc
             LEFT JOIN category_marketplace_mapping cmm
                 ON mc.mp_category_id = cmm.marketplace_category_id AND cmm.marketplace_id = mc.marketplace_id
                AND cmm.store_id = :sid_cmm
             LEFT JOIN categories c ON cmm.category_id = c.id AND c.store_id = :sid_cat
             WHERE {$where}
             ORDER BY mc.name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            array_merge($params, $this->mappingStoreParams())
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Eşleşme JOIN'leri için aktif mağaza parametreleri. Mağaza bağlamı yoksa 0 (hiçbir
     * eşleşme görünmez — başka mağazanın verisi sızmaz).
     *
     * @return array{sid_cmm: int, sid_cat: int}
     */
    private function mappingStoreParams(): array
    {
        try {
            $storeId = $this->tenant->currentStoreId();
        } catch (\Throwable) {
            $storeId = 0;
        }

        return ['sid_cmm' => $storeId, 'sid_cat' => $storeId];
    }

    /**
     * Tree listing: root veya belirli parent'ın children'ları (mapping status ile)
     * Paginated for performance — correlated subquery replaced with LEFT JOIN aggregation
     */
    public function treeList(int $marketplaceId, ?string $parentMpId = null, string $search = '', int $page = 1, int $perPage = 100): array
    {
        [$scope, $scopeParams] = $this->scopeSql('mc');
        [$ccScope, $ccScopeParams] = $this->scopeSql('', 'cc_scope_sid');
        $where = 'mc.marketplace_id = :mid AND ' . $scope;
        $params = ['mid' => $marketplaceId] + $scopeParams;

        if ($search !== '') {
            $where .= ' AND mc.name LIKE :q';
            $params['q'] = '%' . $search . '%';
        } else {
            if ($parentMpId === null) {
                $where .= ' AND (mc.parent_mp_id IS NULL OR mc.parent_mp_id = "")';
            } else {
                $where .= ' AND mc.parent_mp_id = :pid';
                $params['pid'] = $parentMpId;
            }
        }

        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM marketplace_categories mc WHERE {$where}", $params
        );
        $total = (int)($countRow['cnt'] ?? 0);

        $offset = ($page - 1) * $perPage;

        $sellable = $this->sellableCondition('mc');
        $items = $this->db->fetchAll(
            "SELECT mc.*,
                    (CASE WHEN {$sellable} THEN 1 ELSE 0 END) as is_sellable,
                    cmm.id as mapping_id,
                    cmm.category_id as system_category_id,
                    c.name as system_category_name,
                    IFNULL(cc.child_count, 0) as child_count
             FROM marketplace_categories mc
             LEFT JOIN category_marketplace_mapping cmm
                 ON mc.mp_category_id = cmm.marketplace_category_id AND cmm.marketplace_id = mc.marketplace_id
                AND cmm.store_id = :sid_cmm
             LEFT JOIN categories c ON cmm.category_id = c.id AND c.store_id = :sid_cat
             LEFT JOIN (
                 SELECT marketplace_id, parent_mp_id, COUNT(*) as child_count
                 FROM marketplace_categories
                 WHERE marketplace_id = :mid_cc AND {$ccScope}
                 GROUP BY marketplace_id, parent_mp_id
             ) cc ON cc.marketplace_id = mc.marketplace_id AND cc.parent_mp_id = mc.mp_category_id
             WHERE {$where}
             ORDER BY mc.name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            array_merge($params, ['mid_cc' => $marketplaceId], $ccScopeParams, $this->mappingStoreParams())
        );

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }
}
