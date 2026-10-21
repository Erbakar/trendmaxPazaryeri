<?php
/**
 * OzyConnect Panel — MarketplacePurgeService
 *
 * Bir mağazanın TEK bir pazaryerinden gelen verisini siler ve o pazaryerini
 * mağaza için pasife alır. (Örn. satıcı Amazon mağazasını kapattı.)
 *
 * TASARIM KARARLARI — bunlar güvenlik gereğidir, değiştirmeden önce okuyun:
 *
 *   1. ORTAK KATALOĞA DOKUNULMAZ. `marketplace_categories` ve `marketplace_brands`
 *      tablolarının global satırları (store_id = 0) tüm kiracılarda ortaktır. Buradan
 *      silmek, bir bayinin diğer TÜM bayilerin katalogunu yok etmesi demektir.
 *      Yalnız mağazanın kendi eşleştirmeleri ve — kendi mağazası olan platformlarda
 *      (WooCommerce/Shopify/OpenCart, phase12/054) — store_id = bu mağaza olan kendi
 *      kategori/marka önbelleği silinir.
 *
 *   2. ÜRÜN yalnızca (a) kaynağı bu pazaryeri ise VE (b) bağlantılar koptuktan
 *      sonra başka hiçbir pazaryerinde listeli değilse silinir. Aksi halde ürün
 *      korunur, yalnız bu pazaryerine ait listelemesi kaldırılır.
 *
 *   3. SİLME SIRASI zorunludur: `invoices.order_id` → `orders` bağı RESTRICT'tir,
 *      faturası olan sipariş önce faturası silinmeden kaldırılamaz.
 *
 *   4. API ANAHTARLARI KORUNUR. Yalnız `is_enabled = 0` yapılır; satıcı geri
 *      dönerse anahtarları tekrar girmek zorunda kalmaz.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-08-27
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\BrandMarketplaceMapping;
use App\Models\CategoryMarketplaceMapping;
use App\Models\Marketplace;

final class MarketplacePurgeService
{
    /** Tek turda silinecek en fazla ürün (bellek ve kilit süresi dengesi) */
    private const PRODUCT_CHUNK = 500;

    /** Tek turda silinecek en fazla sipariş */
    private const ORDER_CHUNK = 500;

    /**
     * Pazaryerine özel, MAĞAZA bazlı önbellek tabloları (marketplace_id kolonu yoktur; tablo
     * yalnız o pazaryerine aittir). Ortak katalog değildir — store_id ile silinir.
     */
    private const LISTING_CACHE_TABLES = [
        'hepsiburada' => 'hb_listing_cache',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Marketplace $marketplaceModel,
        private readonly CategoryMarketplaceMapping $catMapping,
        private readonly BrandMarketplaceMapping $brandMapping,
        private readonly Logger $logger,
    ) {}

    // ─── Önizleme ─────────────────────────────────────────

    /**
     * Silinecek kayıt sayıları. HİÇBİR ŞEY SİLMEZ.
     *
     * @return array<string, mixed>
     */
    public function preview(int $marketplaceId): array
    {
        $storeId = $this->requireStore();
        $mp      = $this->requireMarketplace($marketplaceId);
        $slug    = (string) $mp['slug'];

        $p = ['sid' => $storeId, 'mid' => $marketplaceId];

        $say = fn(string $sql, array $params = []): int
            => (int) ($this->db->fetchOne($sql, $params ?: $p)['c'] ?? 0);

        // Ürünler iki gruba ayrılır: silinecekler ve yalnız bağlantısı kopacaklar
        $silinecekUrun = $say(
            "SELECT COUNT(*) c FROM products p
              WHERE p.store_id = :sid
                AND p.source = :slug
                AND EXISTS (SELECT 1 FROM marketplace_products mp
                             WHERE mp.product_id = p.id AND mp.marketplace_id = :mid AND mp.store_id = :sid2)
                AND NOT EXISTS (SELECT 1 FROM marketplace_products mp2
                                 WHERE mp2.product_id = p.id AND mp2.marketplace_id <> :mid2 AND mp2.store_id = :sid3)",
            $this->urunParam($storeId, $marketplaceId, $slug)
        );

        $baglantiSayisi = $say(
            "SELECT COUNT(*) c FROM marketplace_products
              WHERE store_id = :sid AND marketplace_id = :mid"
        );

        return [
            'marketplace_id'   => $marketplaceId,
            'slug'             => $slug,
            'display_name'     => (string) ($mp['display_name'] ?? $slug),
            'products_deleted' => $silinecekUrun,
            'products_kept'    => max(0, $baglantiSayisi - $silinecekUrun),
            'listings'         => $baglantiSayisi,
            'orders'           => $say("SELECT COUNT(*) c FROM orders WHERE store_id = :sid AND marketplace_id = :mid"),
            'invoices'         => $say(
                "SELECT COUNT(*) c FROM invoices i
                   JOIN orders o ON o.id = i.order_id
                  WHERE i.store_id = :sid AND o.marketplace_id = :mid"
            ),
            'returns'          => $say("SELECT COUNT(*) c FROM returns WHERE store_id = :sid AND marketplace_id = :mid"),
            'questions'        => $say("SELECT COUNT(*) c FROM questions WHERE store_id = :sid AND marketplace_id = :mid"),
            'finance'          => $say("SELECT COUNT(*) c FROM financial_transactions WHERE store_id = :sid AND marketplace_id = :mid"),
            'category_mapping' => $say("SELECT COUNT(*) c FROM category_marketplace_mapping WHERE store_id = :sid AND marketplace_id = :mid"),
            'brand_mapping'    => $say("SELECT COUNT(*) c FROM brand_marketplace_mapping WHERE store_id = :sid AND marketplace_id = :mid"),
            'listing_cache'    => $this->countListingCache($slug, $storeId),
        ];
    }

    // ─── Silme ────────────────────────────────────────────

    /**
     * Parçalı silme. Süre bütçesi dolunca done=false döner; istemci tekrar çağırır.
     *
     * @return array<string, mixed>
     */
    public function run(int $marketplaceId, float $budgetSeconds = 20.0): array
    {
        $storeId = $this->requireStore();
        $mp      = $this->requireMarketplace($marketplaceId);
        $slug    = (string) $mp['slug'];

        $bas     = microtime(true);
        $silinen = [];

        $kalanSure = static fn(): float => $budgetSeconds - (microtime(true) - $bas);

        // ── 1. Faturalar (invoices.order_id RESTRICT — siparişten ÖNCE) ──
        $silinen['invoices'] = $this->db->transaction(fn(): int => $this->db->query(
            "DELETE i FROM invoices i
               JOIN orders o ON o.id = i.order_id
              WHERE i.store_id = :sid AND o.marketplace_id = :mid",
            ['sid' => $storeId, 'mid' => $marketplaceId]
        )->rowCount());

        // ── 2. İadeler (returns.order_id SET NULL — siparişten ÖNCE) ──
        $silinen['returns'] = $this->db->transaction(fn(): int => $this->db->query(
            "DELETE FROM returns WHERE store_id = :sid AND marketplace_id = :mid",
            ['sid' => $storeId, 'mid' => $marketplaceId]
        )->rowCount());

        // ── 3. Siparişler (order_items CASCADE ile gider) ──
        $silinen['orders'] = 0;
        while ($kalanSure() > 1.0) {
            $adet = $this->db->transaction(fn(): int => $this->db->query(
                "DELETE FROM orders WHERE store_id = :sid AND marketplace_id = :mid LIMIT " . self::ORDER_CHUNK,
                ['sid' => $storeId, 'mid' => $marketplaceId]
            )->rowCount());

            $silinen['orders'] += $adet;
            if ($adet < self::ORDER_CHUNK) {
                break;
            }
        }

        if ($this->db->fetchOne(
            "SELECT 1 c FROM orders WHERE store_id = :sid AND marketplace_id = :mid LIMIT 1",
            ['sid' => $storeId, 'mid' => $marketplaceId]
        ) !== false) {
            return $this->araSonuc($silinen, 'orders');
        }

        // ── 4. Pazaryerine bağlı yan tablolar ──
        foreach ([
            'questions',
            'financial_transactions',
            'enrichment_logs',
            'price_rules',
            'sync_logs',
            'sync_jobs',
            'product_import_jobs',
            'xml_feeds',
        ] as $tablo) {
            $silinen[$tablo] = $this->db->transaction(fn(): int => $this->db->query(
                "DELETE FROM `{$tablo}` WHERE store_id = :sid AND marketplace_id = :mid",
                ['sid' => $storeId, 'mid' => $marketplaceId]
            )->rowCount());
        }

        // ── 5. Silinecek ürünleri BAĞLANTI KOPMADAN ÖNCE belirle ──
        // Bağlantıyı önce silersek "başka pazaryerinde listeli mi" ölçütü bozulur.
        $silinen['products'] = 0;
        while ($kalanSure() > 1.0) {
            $idler = array_column($this->db->fetchAll(
                "SELECT p.id FROM products p
              WHERE p.store_id = :sid
                AND p.source = :slug
                AND EXISTS (SELECT 1 FROM marketplace_products mp
                             WHERE mp.product_id = p.id AND mp.marketplace_id = :mid AND mp.store_id = :sid2)
                AND NOT EXISTS (SELECT 1 FROM marketplace_products mp2
                                 WHERE mp2.product_id = p.id AND mp2.marketplace_id <> :mid2 AND mp2.store_id = :sid3)
                  LIMIT " . self::PRODUCT_CHUNK,
                $this->urunParam($storeId, $marketplaceId, $slug)
            ), 'id');

            if ($idler === []) {
                break;
            }

            $yer = implode(',', array_fill(0, count($idler), '?'));
            $silinen['products'] += $this->db->transaction(fn(): int => $this->db->query(
                "DELETE FROM products WHERE store_id = ? AND id IN ({$yer})",
                array_merge([$storeId], $idler)
            )->rowCount());

            if (count($idler) < self::PRODUCT_CHUNK) {
                break;
            }
        }

        // Silinecek ürün kaldıysa bir sonraki turda devam
        if ($this->db->fetchOne(
            "SELECT 1 c FROM products p
              WHERE p.store_id = :sid
                AND p.source = :slug
                AND EXISTS (SELECT 1 FROM marketplace_products mp
                             WHERE mp.product_id = p.id AND mp.marketplace_id = :mid AND mp.store_id = :sid2)
                AND NOT EXISTS (SELECT 1 FROM marketplace_products mp2
                                 WHERE mp2.product_id = p.id AND mp2.marketplace_id <> :mid2 AND mp2.store_id = :sid3)
              LIMIT 1",
            $this->urunParam($storeId, $marketplaceId, $slug)
        ) !== false) {
            return $this->araSonuc($silinen, 'products');
        }

        // ── 6. Kalan listelemeler (başka pazaryerinde de satılan ürünlerin bağı) ──
        $silinen['listings'] = $this->db->transaction(fn(): int => $this->db->query(
            "DELETE FROM marketplace_products WHERE store_id = :sid AND marketplace_id = :mid",
            ['sid' => $storeId, 'mid' => $marketplaceId]
        )->rowCount());

        // ── 6b. Pazaryerine özel mağaza önbellekleri (HB ilan önbelleği: hb_listing_cache) ──
        $silinen['listing_cache'] = $this->purgeListingCache($slug, $storeId);

        // ── 7. Eşleştirmeler — ORTAK KATALOĞA DOKUNULMAZ ──
        // Bu iki yardımcı mağazaya kapalıdır. MarketplaceCategory/MarketplaceBrand
        // sınıflarındaki aynı adlı metotlar DEĞİLDİR; burada kullanılmamalıdır.
        $silinen['category_mapping'] = $this->catMapping->deleteByMarketplace($marketplaceId);
        $silinen['brand_mapping']    = $this->brandMapping->deleteByMarketplace($marketplaceId);

        // ── 7b. Mağazanın KENDİ kategori/marka önbelleği (yalnız store_id = bu mağaza satırları) ──
        $silinen['store_catalog'] = $this->purgeStoreCatalog($storeId, $marketplaceId);

        // ── 8. Öksüz müşteriler (orders.customer_id SET NULL) ──
        $silinen['customers'] = $this->db->transaction(fn(): int => $this->db->query(
            "DELETE FROM customers
              WHERE store_id = :sid
                AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customers.id)",
            ['sid' => $storeId]
        )->rowCount());

        // ── 9. Pazaryerini pasife al — ANAHTARLAR KORUNUR ──
        $this->db->query(
            "UPDATE store_marketplace_configs
                SET is_enabled = 0, last_sync_at = NULL, updated_at = NOW()
              WHERE store_id = :sid AND marketplace_id = :mid",
            ['sid' => $storeId, 'mid' => $marketplaceId]
        );

        $toplam = array_sum(array_map('intval', $silinen));

        $this->logger->warning(
            sprintf(
                'Pazaryeri verisi silindi (%s): %d kayıt — mağaza #%d',
                $slug,
                $toplam,
                $storeId
            ),
            'MarketplacePurge',
            $silinen
        );

        return [
            'done'    => true,
            'stage'   => 'finished',
            'deleted' => $silinen,
            'total'   => $toplam,
            'slug'    => $slug,
        ];
    }

    // ─── Yardımcılar ──────────────────────────────────────

    /**
     * @param array<string, int> $silinen
     * @return array<string, mixed>
     */
    private function araSonuc(array $silinen, string $stage): array
    {
        return [
            'done'    => false,
            'stage'   => $stage,
            'deleted' => $silinen,
            'total'   => array_sum(array_map('intval', $silinen)),
        ];
    }

    /**
     * Ürün seçim sorgusunun parametreleri.
     *
     * PDO EMULATE_PREPARES=false altında aynı isimli yer tutucu TEKRARLANAMAZ;
     * alt sorgularda geçen her store_id/marketplace_id ayrı isim alır.
     *
     * @return array<string, mixed>
     */
    private function urunParam(int $storeId, int $marketplaceId, string $slug): array
    {
        return [
            'sid'  => $storeId,
            'sid2' => $storeId,
            'sid3' => $storeId,
            'mid'  => $marketplaceId,
            'mid2' => $marketplaceId,
            'slug' => $slug,
        ];
    }

    /**
     * Mağaza kapsamlı platformun (WooCommerce/Shopify/OpenCart) bu mağazaya ait kategori ve marka önbelleği.
     * Global satırlara (store_id = 0) asla dokunmaz; store_id kolonu yoksa (054 uygulanmamış) 0.
     */
    private function purgeStoreCatalog(int $storeId, int $marketplaceId): int
    {
        if ($storeId <= 0) {
            return 0;
        }

        $deleted = 0;
        foreach (['marketplace_categories', 'marketplace_brands'] as $table) {
            if (!\App\Models\MarketplaceCatalogScope::hasColumn($this->db, $table)) {
                continue;
            }
            $deleted += $this->db->transaction(fn(): int => $this->db->query(
                "DELETE FROM `{$table}` WHERE store_id = :sid AND marketplace_id = :mid",
                ['sid' => $storeId, 'mid' => $marketplaceId]
            )->rowCount());
        }

        return $deleted;
    }

    private function countListingCache(string $slug, int $storeId): int
    {
        $table = self::LISTING_CACHE_TABLES[$slug] ?? null;
        if ($table === null) {
            return 0;
        }

        try {
            return (int) ($this->db->fetchOne(
                "SELECT COUNT(*) c FROM `{$table}` WHERE store_id = :sid",
                ['sid' => $storeId]
            )['c'] ?? 0);
        } catch (\PDOException $e) {
            return $this->ignoreMissingTable($e, $table);
        }
    }

    private function purgeListingCache(string $slug, int $storeId): int
    {
        $table = self::LISTING_CACHE_TABLES[$slug] ?? null;
        if ($table === null) {
            return 0;
        }

        try {
            return $this->db->transaction(fn(): int => $this->db->query(
                "DELETE FROM `{$table}` WHERE store_id = :sid",
                ['sid' => $storeId]
            )->rowCount());
        } catch (\PDOException $e) {
            return $this->ignoreMissingTable($e, $table);
        }
    }

    /**
     * Önbellek tablosu bu kurulumda yoksa (migration uygulanmamış) silinecek bir şey yoktur;
     * başka bir veritabanı hatası temizliği durdurmaz ama loglanır.
     */
    private function ignoreMissingTable(\PDOException $e, string $table): int
    {
        $missing = (string) $e->getCode() === '42S02' || (int) ($e->errorInfo[1] ?? 0) === 1146;
        if (!$missing) {
            $this->logger->warning("Önbellek tablosu temizlenemedi ({$table}): " . $e->getMessage(), 'MarketplacePurge');
        }

        return 0;
    }

    private function requireStore(): int
    {
        if (!$this->tenant->hasStore()) {
            throw new \RuntimeException('Aktif mağaza bağlamı olmadan pazaryeri verisi silinemez.');
        }

        return $this->tenant->currentStoreId();
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMarketplace(int $marketplaceId): array
    {
        $mp = $this->db->fetchOne(
            'SELECT id, slug, display_name FROM marketplaces WHERE id = :id LIMIT 1',
            ['id' => $marketplaceId]
        );

        if ($mp === false) {
            throw new \RuntimeException('Pazaryeri bulunamadı.');
        }

        // Mağazanın bu pazaryerine erişim yetkisi var mı?
        $yetki = $this->db->fetchOne(
            "SELECT 1 c FROM store_marketplaces
              WHERE store_id = :sid AND marketplace_id = :mid AND status = 'active' LIMIT 1",
            ['sid' => $this->tenant->currentStoreId(), 'mid' => $marketplaceId]
        );

        if ($yetki === false) {
            throw new \RuntimeException('Bu pazaryeri mağazanıza tanımlı değil.');
        }

        return $mp;
    }
}
