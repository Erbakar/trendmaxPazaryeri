<?php
/**
 * OzyConnect Panel — Pazaryeri kategori/marka önbelleğinin mağaza kapsamı
 *
 * marketplace_categories ve marketplace_brands GLOBAL katalogdur (Trendyol, Hepsiburada … tüm mağazalar için
 * aynıdır; store_id = 0). Kendi mağazası olan platformlarda (WooCommerce, Shopify, OpenCart) ise kategori ve
 * marka ağacı HER MAĞAZANIN KENDİ SİTESİNE aittir: aynı kimlikler (ör. WooCommerce kategori #17) mağazadan
 * mağazaya başka kategoridir. Bu platformların satırları store_id = mağaza ile yazılır ve yalnız o mağazaya
 * okunur (database/migrations/phase12/054_marketplace_catalog_store_scope.sql).
 *
 * Kolon (store_id) yoksa — migration henüz uygulanmamış kurulum — eski global davranış sürer (koşul '1=1',
 * yazımda store_id gönderilmez). Şema yalnız migration ile değişir; çalışma anında ALTER YOK.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class MarketplaceCatalogScope
{
    /** Katalogu mağaza başına tutulan (kendi mağazası olan) platformlar. */
    public const STORE_SCOPED_SLUGS = ['woocommerce', 'shopify', 'opencart'];

    /** Kolon yokken yeniden kontrol aralığı (sn) — migration uzun ömürlü süreçte de fark edilsin. */
    private const RECHECK_SECONDS = 60;

    /** Bağlamda mağaza yokken okuma için hiçbir satıra uymayan değer (store_id UNSIGNED). */
    private const NO_STORE = -1;

    /** @var array<string, array{ready: bool, at: int}> tablo => store_id kolonu var mı */
    private static array $columnState = [];

    /** @var list<int>|null mağaza kapsamlı platformların marketplaces.id listesi */
    private static ?array $scopedIds = null;

    /** Tablo store_id kolonunu taşıyor mu (şemayı DEĞİŞTİRMEZ). */
    public static function hasColumn(Database $db, string $table): bool
    {
        $state = self::$columnState[$table] ?? null;
        if ($state !== null && ($state['ready'] || (time() - $state['at']) < self::RECHECK_SECONDS)) {
            return $state['ready'];
        }

        try {
            $row = $db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'store_id'",
                ['t' => $table]
            );
            $ready = (int) ($row['cnt'] ?? 0) === 1;
        } catch (\Throwable) {
            $ready = false;
        }

        self::$columnState[$table] = ['ready' => $ready, 'at' => time()];

        return $ready;
    }

    /**
     * Mağaza kapsamlı platformların pazaryeri kimlikleri (süreç içi önbellek; katalog satırları nadiren değişir).
     *
     * @return list<int>
     */
    public static function scopedMarketplaceIds(Database $db): array
    {
        if (self::$scopedIds === null) {
            $placeholders = [];
            $params = [];
            foreach (self::STORE_SCOPED_SLUGS as $i => $slug) {
                $placeholders[] = ':s' . $i;
                $params['s' . $i] = $slug;
            }
            try {
                $rows = $db->fetchAll(
                    'SELECT id FROM marketplaces WHERE slug IN (' . implode(', ', $placeholders) . ')',
                    $params
                );
                self::$scopedIds = array_values(array_map(static fn(array $r): int => (int) $r['id'], $rows));
            } catch (\Throwable) {
                return [];
            }
        }

        return self::$scopedIds;
    }

    public static function isScopedMarketplace(Database $db, int $marketplaceId): bool
    {
        return in_array($marketplaceId, self::scopedMarketplaceIds($db), true);
    }

    /**
     * Okuma koşulu: global platformda store_id = 0, mağaza kapsamlı platformda store_id = $storeExpr.
     * $storeExpr bir yer tutucu (':sid') ya da kolon (cmm.store_id) olabilir; yer tutucunun değerini çağıran
     * verir (bkz. readStoreId). Kolon yoksa '1=1'.
     */
    public static function condition(Database $db, string $table, string $alias, string $storeExpr): string
    {
        if (!self::hasColumn($db, $table)) {
            return '1=1';
        }

        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?? '';
        $p = $alias !== '' ? $alias . '.' : '';
        $ids = self::scopedMarketplaceIds($db);
        if ($ids === []) {
            return "{$p}store_id = 0";
        }

        return "{$p}store_id = (CASE WHEN {$p}marketplace_id IN (" . implode(', ', $ids) . ") THEN {$storeExpr} ELSE 0 END)";
    }

    /** Okuma yer tutucusunun değeri: aktif mağaza; yoksa hiçbir mağaza satırına uymayan değer. */
    public static function readStoreId(?int $currentStoreId): int
    {
        return $currentStoreId !== null && $currentStoreId > 0 ? $currentStoreId : self::NO_STORE;
    }

    /**
     * Yazma kapsamı: global platform → 0; mağaza kapsamlı platform → aktif mağaza. Mağaza kapsamlı platformda
     * aktif mağaza yoksa null (yazılmaz — satır global kataloğa sızmasın). Kolon yoksa 0 (kullanılmaz).
     */
    public static function writeStoreId(Database $db, string $table, int $marketplaceId, ?int $currentStoreId): ?int
    {
        if (!self::hasColumn($db, $table) || !self::isScopedMarketplace($db, $marketplaceId)) {
            return 0;
        }

        return $currentStoreId !== null && $currentStoreId > 0 ? $currentStoreId : null;
    }
}
