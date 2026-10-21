<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Pazaryeri Marka Cache — Her pazaryerinden çekilen markaları saklar
 *
 * Global platformların marka katalogu tüm mağazalarda ortaktır (store_id = 0). WooCommerce / Shopify /
 * OpenCart markaları mağazanın kendi sitesine aittir: store_id = mağaza ile yazılır, yalnız o mağazaya
 * okunur (MarketplaceCatalogScope; kolon yoksa eski global davranış).
 */
final class MarketplaceBrand extends BaseModel
{
    protected string $table = 'marketplace_brands';
    protected bool $tenantScoped = false; // GLOBAL referans (D2): platform marka katalogu (+ mağaza kapsamlı platformlar)

    /**
     * Okuma kapsamı: [SQL koşulu, parametreler] (parametre yalnız koşulda geçiyorsa döner).
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function scopeSql(string $alias, string $param = 'mb_scope_sid'): array
    {
        $sql = MarketplaceCatalogScope::condition($this->db, $this->table, $alias, ':' . $param);

        return [$sql, str_contains($sql, ':' . $param)
            ? [$param => MarketplaceCatalogScope::readStoreId($this->currentStoreOrNull())]
            : []];
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

    private function hasScopeColumn(): bool
    {
        return MarketplaceCatalogScope::hasColumn($this->db, $this->table);
    }

    /** Yazma kapsamı (store_id). null: mağaza kapsamlı platformda aktif mağaza yok. */
    private function writeStoreId(int $marketplaceId): ?int
    {
        return MarketplaceCatalogScope::writeStoreId($this->db, $this->table, $marketplaceId, $this->currentStoreOrNull());
    }

    private function requireWriteStoreId(int $marketplaceId): int
    {
        $storeId = $this->writeStoreId($marketplaceId);
        if ($storeId === null) {
            throw new \RuntimeException('Bu platformun markaları mağazaya özeldir; aktif mağaza bağlamı olmadan kaydedilemez.');
        }

        return $storeId;
    }

    /**
     * Pazaryeri + marka ID ile bul
     */
    public function findByMpBrandId(int $marketplaceId, string $mpBrandId): array|false
    {
        [$scope, $scopeParams] = $this->scopeSql('');

        return $this->rawQueryOne(
            "SELECT * FROM marketplace_brands WHERE marketplace_id = :mid AND mp_brand_id = :bid AND {$scope} LIMIT 1",
            ['mid' => $marketplaceId, 'bid' => $mpBrandId] + $scopeParams
        );
    }

    /**
     * Upsert
     */
    public function upsert(int $marketplaceId, string $mpBrandId, string $name): int
    {
        $scopeStoreId = $this->hasScopeColumn() ? $this->requireWriteStoreId($marketplaceId) : null;
        $existing = $this->findByMpBrandId($marketplaceId, $mpBrandId);

        if ($existing !== false) {
            $this->update((int)$existing['id'], [
                'name'      => $name,
                'synced_at' => date('Y-m-d H:i:s'),
            ]);
            return (int)$existing['id'];
        }

        $data = [
            'marketplace_id' => $marketplaceId,
            'mp_brand_id'    => $mpBrandId,
            'name'           => $name,
        ];
        if ($scopeStoreId !== null) {
            $data['store_id'] = $scopeStoreId;
        }

        return $this->create($data);
    }

    /**
     * Toplu upsert — büyük veri setleri için optimize edilmiş
     * Tek sorguda yüzlerce kayıt işler (INSERT ... ON DUPLICATE KEY UPDATE)
     *
     * @param array<int, array{mp_brand_id: string, name: string}> $brands
     * @return int İşlenen kayıt sayısı
     */
    public function bulkUpsert(int $marketplaceId, array $brands, int $chunkSize = 500): int
    {
        if ($brands === []) return 0;

        $processed = 0;
        $now = date('Y-m-d H:i:s');

        // Aynı mp_brand_id'nin chunk içinde tekrar etmemesi için tekilleştir
        $unique = [];
        foreach ($brands as $b) {
            $bid = (string)($b['mp_brand_id'] ?? '');
            $name = trim((string)($b['name'] ?? ''));
            if ($bid === '' || $name === '') continue;
            $unique[$bid] = $name;
        }

        $items = [];
        foreach ($unique as $bid => $name) {
            $items[] = ['mp_brand_id' => $bid, 'name' => $name];
        }
        if ($items === []) {
            return 0;
        }

        $withScope = $this->hasScopeColumn();
        $scopeStoreId = $withScope ? $this->requireWriteStoreId($marketplaceId) : 0;

        foreach (array_chunk($items, max(1, $chunkSize)) as $chunk) {
            $placeholders = [];
            $params = [];
            $i = 0;
            foreach ($chunk as $row) {
                $placeholders[] = $withScope
                    ? "(:mid{$i}, :sid{$i}, :bid{$i}, :name{$i}, :ts{$i})"
                    : "(:mid{$i}, :bid{$i}, :name{$i}, :ts{$i})";
                $params["mid{$i}"]  = $marketplaceId;
                if ($withScope) {
                    $params["sid{$i}"] = $scopeStoreId;
                }
                $params["bid{$i}"]  = $row['mp_brand_id'];
                $params["name{$i}"] = $row['name'];
                $params["ts{$i}"]   = $now;
                $i++;
            }

            $columns = $withScope ? '(marketplace_id, store_id, mp_brand_id, name, synced_at)' : '(marketplace_id, mp_brand_id, name, synced_at)';
            $sql = "INSERT INTO marketplace_brands {$columns} VALUES "
                . implode(',', $placeholders)
                . " ON DUPLICATE KEY UPDATE name = VALUES(name), synced_at = VALUES(synced_at)";

            $this->db->query($sql, $params);
            $processed += count($chunk);
        }

        return $processed;
    }

    /**
     * İsimle arama
     */
    public function searchByName(string $name, ?int $marketplaceId = null): array
    {
        [$scope, $scopeParams] = $this->scopeSql('mb');
        $sql = "SELECT mb.*, m.slug as marketplace_slug, m.display_name as marketplace_name
                FROM marketplace_brands mb
                JOIN marketplaces m ON mb.marketplace_id = m.id
                WHERE mb.name LIKE :q AND {$scope}";
        $params = ['q' => '%' . $name . '%'] + $scopeParams;

        if ($marketplaceId !== null) {
            $sql .= " AND mb.marketplace_id = :mid";
            $params['mid'] = $marketplaceId;
        }

        $sql .= " ORDER BY mb.name ASC LIMIT 300";

        return $this->rawQuery($sql, $params);
    }

    /**
     * Tam isim eşleşmesi
     */
    public function findExactMatch(string $name, int $marketplaceId): array|false
    {
        [$scope, $scopeParams] = $this->scopeSql('');

        return $this->rawQueryOne(
            "SELECT * FROM marketplace_brands
             WHERE LOWER(name) = LOWER(:name) AND marketplace_id = :mid AND {$scope}
             LIMIT 1",
            ['name' => $name, 'mid' => $marketplaceId] + $scopeParams
        );
    }

    /**
     * Ada göre SAYISAL pazaryeri marka kimliği (WooCommerce terim ID'si gibi). Eski senkronların marka
     * ADINI kimlik olarak yazdığı satırlar atlanır. Bulunamazsa null.
     */
    public function findNumericIdByName(string $name, int $marketplaceId): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        [$scope, $scopeParams] = $this->scopeSql('');
        $rows = $this->rawQuery(
            "SELECT mp_brand_id FROM marketplace_brands
             WHERE LOWER(name) = LOWER(:name) AND marketplace_id = :mid AND {$scope}
             ORDER BY id DESC
             LIMIT 20",
            ['name' => $name, 'mid' => $marketplaceId] + $scopeParams
        );
        foreach ($rows as $row) {
            $id = trim((string) ($row['mp_brand_id'] ?? ''));
            if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Belirli pazaryerideki marka sayısı
     */
    public function countByMarketplace(int $marketplaceId): int
    {
        [$scope, $scopeParams] = $this->scopeSql('');
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM marketplace_brands WHERE marketplace_id = :mid AND {$scope}",
            ['mid' => $marketplaceId] + $scopeParams
        );
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Pazaryerindeki tüm cache markalarını sil (mağaza kapsamlı platformda yalnız aktif mağazanınkiler)
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
            "DELETE FROM marketplace_brands WHERE marketplace_id = :mid{$scope}",
            $params
        );
        return $stmt->rowCount();
    }

    /**
     * Mağaza kapsamı (dış ham sorgular için; ör. BrandController marka listesi, eşleştirme aday havuzu):
     * [SQL koşulu, parametreler]. Kolon yoksa ['1=1', []].
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public function scopeFilter(string $alias, string $param = 'mb_scope_sid'): array
    {
        return $this->scopeSql($alias, $param);
    }
}
