<?php
/**
 * OzyConnect Panel — HepsiburadaListingMatchService
 *
 * Hepsiburada İlan Eşleştirme: mağazanın ürünlerini Hepsiburada'da ZATEN açık olan ilanlarla eşleştirir.
 * Yeni ürün/ilan AÇMAZ, Hepsiburada'ya YAZMAZ — yenileme yalnız GET (MATCHED katalog + ilan listesi).
 *
 * Önbellek: hb_listing_cache (store_id'li; her mağaza yalnız kendi kayıtlarını görür).
 * Eşleşme kaydı: marketplace_products (store_id, product_id, marketplace_id = Hepsiburada)
 *   marketplace_sku        = HB merchantSku → sipariş satırı → ürün eşlemesi, listeleme yükleme anahtarı
 *   marketplace_product_id = HB hbSku       → stok/fiyat senkronunun HepsiburadaSku'su
 *   marketplace_barcode    = HB barkodu (boşsa NULL)
 *   is_listed              = HB'de ilan var mı; last_price_sent/last_stock_sent = HB'deki mevcut değer
 *                            (senkron yalnız gerçek farkı gönderir).
 *
 * Eşleştirme kuralları (OZY İlan Eşleştirme referans uygulamasıyla aynı):
 *   kesin  : ürün SKU = HB SKU · barkod = HB barkodu (barkod mağazada tekrarlanmıyorsa) ·
 *            ürün SKU = HB merchantSku · barkod = HB merchantSku
 *   öneri  : ad + miktar + paket adedi benzerlik puanı (0-100), eşik 60; toplu onay varsayılanı 90
 *   atama  : küresel en-iyi-önce — bir HB kaydı yalnız bir ürüne atanır.
 *
 * Ürün kümesi: arşivlenmemiş ürünler; varyantları olan ANA ürün hariç (Hepsiburada'da her varyant ayrı
 * ilandır — varyantlar tek tek eşleşir), varyantsız ana ürünler ve varyant satırları dahil.
 * İçeri alınmış kopya (products.source = 'hepsiburada') ilanı başka ürüne devredilebilir; diğer ürünlere
 * bağlı ilan reddedilir.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-10-09
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\MarketplaceProduct;
use InvalidArgumentException;
use RuntimeException;

final class HepsiburadaListingMatchService
{
    public const SLUG = 'hepsiburada';

    /** HB sayfa boyutu (katalog + ilan) */
    public const PAGE_SIZE = 100;
    /** Sonsuz döngü koruması (30.000 kayıt) */
    public const MAX_PAGES = 300;
    /** Bu puanın altı öneri sayılmaz */
    public const SUGGEST_THRESHOLD = 60;
    /** 'Önerileri onayla' varsayılan eşiği */
    public const BULK_THRESHOLD = 90;
    /** 'Değiştir' penceresindeki aday listesi eşiği */
    public const SEARCH_THRESHOLD = 40;
    /** Karşılıksız HB kayıtları listesinin üst sınırı */
    public const UNMATCHED_LIMIT = 300;
    /** Toplu onay hata listesinin üst sınırı */
    public const ERROR_LIMIT = 10;
    /** Tek sorguda sonucu alınacak en fazla gönderim (trackingId) */
    public const RESULT_TRACKING_LIMIT = 10;
    /** Atamada ürün başına bellekte tutulan aday sayısı (sonucu etkilemez, yalnız bellek/hız) */
    private const CANDIDATE_WINDOW = 10;

    public const STEP_CATALOG  = 'catalog';
    public const STEP_LISTINGS = 'listings';
    public const STEP_FINISH   = 'finish';

    public const STATUS_APPROVED  = 'approved';
    public const STATUS_EXACT     = 'exact';
    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_NONE      = 'none';

    public const MODE_EXACT     = 'exact';
    public const MODE_SUGGESTED = 'suggested';

    /** ProductPublishService bekleyen gönderim kalıbı (sync_error) */
    public const PENDING_BATCH_PREFIX  = 'Batch kontrol bekliyor: ';
    public const REJECTED_BATCH_PREFIX = 'Batch reddedildi';

    private const REASON_LABELS = [
        'sku_hbsku'    => 'Ürün SKU = HB SKU',
        'barcode'      => 'Barkod aynı',
        'sku_msku'     => 'Ürün SKU = HB satıcı SKU',
        'barcode_msku' => 'Barkod = HB satıcı SKU',
        'name'         => 'Ad + miktar benzerliği',
        'approved'     => 'Onaylı eşleşme',
    ];

    private const QUANTITY_PATTERN = '/(\d+(?:[.,]\d+)?)\s*(kilogram|kilo|kg|gram|gr|g|mg|mililitre|ml|litre|lt|cl|l)(?![a-z])/';

    private const QUANTITY_UNITS = [
        'kilogram' => ['g', 1000], 'kilo' => ['g', 1000], 'kg' => ['g', 1000],
        'gram' => ['g', 1], 'gr' => ['g', 1], 'g' => ['g', 1],
        'mg' => ['mg', 1],
        'mililitre' => ['ml', 1], 'ml' => ['ml', 1], 'cl' => ['ml', 10],
        'litre' => ['ml', 1000], 'lt' => ['ml', 1000], 'l' => ['ml', 1000],
    ];

    /**
     * Ürün tipi sözlüğü (karşılaştırma tanımı).
     *  classes       : ana ürün sınıfı (farklıysa eşleşme YOK)
     *  honey_anchors : 'bal' sınıfını yalnız bu kelimeler açar — 'çiçek desenli', 'krem rengi' gibi genel
     *                  ürün adları bal sınıfına düşmez (Dice eşiği devrede kalır)
     *  hard          : ayırt edici tip/çeşit kelimeleri (bir tarafta olup diğerinde yoksa eşleşme YOK)
     *  soft          : tamamlayıcı kelimeler (fark başına ceza)
     *  stop          : ortak/önemsiz kelimeler (karşılaştırmaya girmez)
     *  alias         : eş anlamlı/çekim eşlemesi
     */
    private const DICTIONARY = [
        'classes'       => ['arisutu', 'propolis', 'polen', 'perga'],
        'honey_anchors' => ['bal', 'karakovan'],
        'hard'          => ['sepet', 'karakovan', 'duz', 'kaset', 'krem', 'cicek', 'kekik', 'kestane', 'zeytinyagli', 'yayla', 'ham'],
        'soft'          => ['petek', 'suzme'],
        'stop'          => ['organik', 'dogal', 'saf', 'hakiki', 'bal', 'katkisiz', 'taze', 'yeni', 'adet', 've', 'ile',
                            'li', 'lu', 'lik', 'luk', 'cam', 'kavanoz', 'kavanozda', 'x'],
        'alias'         => ['bali' => 'bal', 'ballari' => 'bal', 'zeytinyag' => 'zeytinyagli', 'zeytinyagi' => 'zeytinyagli',
                            'sutu' => 'sut', 'suzulmus' => 'suzme', 'kaseti' => 'kaset', 'petekli' => 'petek'],
    ];

    /** Satılabilir ürün kümesi (ilk parametre store_id). Varyantı olan ana ürün ilan değildir. */
    private const SELLABLE_PRODUCT_WHERE = "p.store_id = ? AND p.is_archived = 0
        AND NOT (p.has_variants = 1 AND EXISTS (
            SELECT 1 FROM products c WHERE c.store_id = p.store_id AND c.parent_id = p.id AND c.is_archived = 0
        ))";

    private const CACHE_COLUMNS = 'hb_sku, merchant_sku, barcode, product_name, catalog_name, quantity, product_type,
        variant_group_id, image_url, in_catalog, has_listing, is_salable, price, stock, updated_at';

    /** Yenilemede yazılan kolonlar (catalogCacheRows / listingCacheRows satır sırası) */
    public const CACHE_WRITE_COLUMNS = ['store_id', 'hb_sku', 'merchant_sku', 'barcode', 'product_name', 'catalog_name',
        'quantity', 'product_type', 'variant_group_id', 'image_url', 'search_text', 'in_catalog', 'has_listing', 'is_salable',
        'price', 'stock', 'refresh_round', 'listing_round', 'updated_at'];

    /** Katalog adımı: ürün bilgisi güncellenir; ilan alanları (has_listing, fiyat, stok) korunur */
    private const CATALOG_UPSERT = 'merchant_sku = VALUES(merchant_sku), barcode = VALUES(barcode), product_name = VALUES(product_name),
        catalog_name = VALUES(catalog_name), quantity = VALUES(quantity), product_type = VALUES(product_type),
        variant_group_id = VALUES(variant_group_id), image_url = VALUES(image_url), search_text = VALUES(search_text),
        in_catalog = 1, refresh_round = VALUES(refresh_round), updated_at = VALUES(updated_at)';

    /** İlan adımı: fiyat/stok/satış durumu güncellenir; katalog bilgisi korunur (merchantSku yalnız boşsa yazılır) */
    private const LISTING_UPSERT = "merchant_sku = IF(merchant_sku = '', VALUES(merchant_sku), merchant_sku),
        search_text = IF(in_catalog = 1, search_text, VALUES(search_text)),
        has_listing = 1, is_salable = VALUES(is_salable), price = VALUES(price), stock = VALUES(stock),
        refresh_round = VALUES(refresh_round), listing_round = VALUES(listing_round), updated_at = VALUES(updated_at)";

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly HepsiburadaService $hepsiburada,
        private readonly MarketplaceProduct $marketplaceProducts,
    ) {}

    // ═══════════════════════════════════════════════════════
    // HEPSİBURADA'DAN YENİLE (salt-okuma, adım adım)
    // ═══════════════════════════════════════════════════════

    /**
     * Yenilemenin tek adımı = tek HB isteği (zaman aşımına düşmemek için parça parça).
     *   catalog  cursor = sayfa (0..)  MATCHED katalog sayfası
     *   listings cursor = offset       ilan sayfası (fiyat/stok/satış durumu)
     *   finish                         bu turda görülmeyen kayıtları temizler
     *
     * @return array{round: int, done: bool, next: int, total: int, processed: int}
     */
    public function refreshStep(string $step, int $cursor, int $round): array
    {
        $storeId = $this->storeId();
        $cursor = max(0, $cursor);
        $round = $round > 0 ? $round : time();
        $out = ['round' => $round, 'done' => true, 'next' => 0, 'total' => 0, 'processed' => 0];

        return match ($step) {
            self::STEP_CATALOG  => $this->refreshCatalogPage($storeId, $cursor, $round, $out),
            self::STEP_LISTINGS => $this->refreshListingPage($storeId, $cursor, $round, $out),
            self::STEP_FINISH   => $this->finishRefresh($storeId, $round, $out),
            default             => throw new InvalidArgumentException('Geçersiz yenileme adımı.'),
        };
    }

    /**
     * @param array{round: int, done: bool, next: int, total: int, processed: int} $out
     * @return array{round: int, done: bool, next: int, total: int, processed: int}
     */
    private function refreshCatalogPage(int $storeId, int $page, int $round, array $out): array
    {
        $response = $this->hepsiburada->fetchProductsByStatus('MATCHED', $page, self::PAGE_SIZE);
        $this->db->reconnect();

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = self::catalogCacheRows($data, $storeId, $round, date('Y-m-d H:i:s'));
        $this->writeCacheRows($rows, self::CATALOG_UPSERT);

        $totalPages = (int) ($response['totalPages'] ?? 0);
        $out['total'] = (int) ($response['totalElements'] ?? count($data));
        $out['processed'] = count($rows);
        $out['next'] = $page + 1;
        $out['done'] = $data === [] || $out['next'] >= $totalPages || $out['next'] >= self::MAX_PAGES;

        return $out;
    }

    /**
     * @param array{round: int, done: bool, next: int, total: int, processed: int} $out
     * @return array{round: int, done: bool, next: int, total: int, processed: int}
     */
    private function refreshListingPage(int $storeId, int $offset, int $round, array $out): array
    {
        $response = $this->hepsiburada->fetchListings($offset, self::PAGE_SIZE);
        $this->db->reconnect();

        $data = is_array($response['listings'] ?? null) ? $response['listings'] : [];
        $rows = self::listingCacheRows($data, $storeId, $round, date('Y-m-d H:i:s'));
        $this->writeCacheRows($rows, self::LISTING_UPSERT);

        $out['total'] = (int) ($response['totalCount'] ?? count($data));
        $out['processed'] = count($rows);
        $out['next'] = $offset + self::PAGE_SIZE;
        $out['done'] = $data === [] || $out['next'] >= $out['total'] || $out['next'] >= self::MAX_PAGES * self::PAGE_SIZE;

        return $out;
    }

    /**
     * MATCHED katalog yanıtı (data[]) → hb_listing_cache satırları (CACHE_WRITE_COLUMNS sırasıyla).
     *
     * @param array<int, mixed> $data
     * @return array<int, array<int, mixed>>
     */
    public static function catalogCacheRows(array $data, int $storeId, int $round, string $now): array
    {
        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hbSku = self::text($item['hbSku'] ?? null);
            if ($hbSku === '') {
                continue;
            }
            $info = is_array($item['matchedHbProductInfo'][0] ?? null) ? $item['matchedHbProductInfo'][0] : [];
            $quantity = '';
            $type = '';
            foreach ((array) ($info['variantTypeAttributes'] ?? []) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $attributeName = self::text($attribute['name'] ?? null);
                if ($attributeName === 'Miktar') {
                    $quantity = self::text($attribute['value'] ?? null);
                } elseif ($attributeName === 'Türü') {
                    $type = self::text($attribute['value'] ?? null);
                }
            }
            $image = self::safeImageUrl(str_replace('{size}', '200-200', self::text($info['images'][0] ?? null)));
            $name = self::text($item['productName'] ?? null);
            $catalogName = self::text($info['productName'] ?? null);
            $merchantSku = self::text($item['merchantSku'] ?? null);
            $barcode = self::text($item['barcode'] ?? null);

            $rows[] = [
                $storeId,
                self::cut($hbSku, 64), self::cut($merchantSku, 200), self::cut($barcode, 100),
                self::cut($name, 500), self::cut($catalogName, 500), self::cut($quantity, 40), self::cut($type, 120),
                self::cut(self::text($item['variantGroupId'] ?? null), 64), self::cut($image, 500),
                self::cut(self::fold($name . ' ' . $catalogName . ' ' . $barcode . ' ' . $merchantSku . ' ' . $hbSku), 900),
                1, 0, 0, null, null, $round, 0, $now,
            ];
        }

        return $rows;
    }

    /**
     * İlan listesi yanıtı (listings[]) → hb_listing_cache satırları (CACHE_WRITE_COLUMNS sırasıyla).
     * Satışta = isSalable && !isSuspended && !isFrozen.
     *
     * @param array<int, mixed> $data
     * @return array<int, array<int, mixed>>
     */
    public static function listingCacheRows(array $data, int $storeId, int $round, string $now): array
    {
        $rows = [];
        foreach ($data as $listing) {
            if (!is_array($listing)) {
                continue;
            }
            $hbSku = self::text($listing['hepsiburadaSku'] ?? null);
            if ($hbSku === '') {
                continue;
            }
            $merchantSku = self::text($listing['merchantSku'] ?? null);
            $salable = (!empty($listing['isSalable']) && empty($listing['isSuspended']) && empty($listing['isFrozen'])) ? 1 : 0;

            $rows[] = [
                $storeId,
                self::cut($hbSku, 64), self::cut($merchantSku, 200), '',
                '', '', '', '',
                '', '',
                self::cut(self::fold($merchantSku . ' ' . $hbSku), 900),
                0, 1, $salable, round((float) ($listing['price'] ?? 0), 2), (int) ($listing['availableStock'] ?? 0),
                $round, $round, $now,
            ];
        }

        return $rows;
    }

    /**
     * @param array{round: int, done: bool, next: int, total: int, processed: int} $out
     * @return array{round: int, done: bool, next: int, total: int, processed: int}
     */
    private function finishRefresh(int $storeId, int $round, array $out): array
    {
        $seen = $this->db->fetchOne(
            'SELECT SUM(refresh_round = ?) AS seen, SUM(listing_round = ?) AS listed FROM hb_listing_cache WHERE store_id = ?',
            [$round, $round, $storeId]
        );
        if ((int) ($seen['seen'] ?? 0) === 0) {
            throw new RuntimeException('Yenileme turu bulunamadı; tekrar deneyin.');
        }

        $this->db->transaction(function () use ($storeId, $round, $seen): void {
            $this->db->query('DELETE FROM hb_listing_cache WHERE store_id = ? AND refresh_round <> ?', [$storeId, $round]);
            if ((int) ($seen['listed'] ?? 0) > 0) {
                $this->db->query(
                    'UPDATE hb_listing_cache SET has_listing = 0, is_salable = 0, price = NULL, stock = NULL
                      WHERE store_id = ? AND listing_round <> ?',
                    [$storeId, $round]
                );
            }
        });

        $count = $this->db->fetchOne('SELECT COUNT(*) AS cnt FROM hb_listing_cache WHERE store_id = ?', [$storeId]);
        $out['total'] = (int) ($count['cnt'] ?? 0);

        return $out;
    }

    /**
     * Satırları tek INSERT … ON DUPLICATE KEY UPDATE ile yazar (UNIQUE store_id + hb_sku).
     *
     * @param array<int, array<int, mixed>> $rows
     */
    private function writeCacheRows(array $rows, string $updateSql): void
    {
        if ($rows === []) {
            return;
        }

        $columns = self::CACHE_WRITE_COLUMNS;
        $one = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $params = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }

        $this->db->query(
            'INSERT INTO hb_listing_cache (' . implode(', ', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($rows), $one)) . ' ON DUPLICATE KEY UPDATE ' . $updateSql,
            $params
        );
    }

    // ═══════════════════════════════════════════════════════
    // GENEL GÖRÜNÜM (sayfa verisi)
    // ═══════════════════════════════════════════════════════

    /**
     * Sayfanın tüm verisi: ürün satırları, sayaçlar, karşılıksız HB kayıtları, önbellek bilgisi.
     *
     * @return array<string, mixed>
     */
    public function overview(int $marketplaceId): array
    {
        $storeId = $this->storeId();
        $products = $this->loadProducts($storeId);
        $cache = $this->loadCache($storeId);
        $approved = $this->loadApproved($storeId, $marketplaceId);
        $result = self::computeMatches($products, $cache, $approved, self::SUGGEST_THRESHOLD);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = $this->presentRow($row, $cache, $approved);
        }

        $unmatched = [];
        foreach (array_slice($result['unmatched'], 0, self::UNMATCHED_LIMIT) as $hbSku) {
            $unmatched[] = self::presentCacheRecord($cache[$hbSku]);
        }

        $last = $this->db->fetchOne('SELECT MAX(updated_at) AS last_at FROM hb_listing_cache WHERE store_id = ?', [$storeId]);

        return [
            'counts'     => $result['counts'],
            'rows'       => $rows,
            'unmatched'  => $unmatched,
            'cache'      => [
                'count'        => count($cache),
                'refreshed_at' => (string) ($last['last_at'] ?? ''),
            ],
            'thresholds' => ['suggest' => self::SUGGEST_THRESHOLD, 'bulk' => self::BULK_THRESHOLD],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string|int, array<string, mixed>> $cache
     * @param array<int, array<string, mixed>> $approved
     * @return array<string, mixed>
     */
    private function presentRow(array $row, array $cache, array $approved): array
    {
        $product = $row['product'];
        $hb = $row['hb_sku'] !== null && isset($cache[$row['hb_sku']]) ? self::presentCacheRecord($cache[$row['hb_sku']]) : null;
        $transferFrom = null;
        if ($row['transfer_from'] !== null && isset($approved[$row['transfer_from']])) {
            $transferFrom = [
                'id'   => (int) $row['transfer_from'],
                'name' => (string) $approved[$row['transfer_from']]['product_name'],
            ];
        }

        return [
            'product'       => [
                'id'         => $product['id'],
                'name'       => $product['name'],
                'sku'        => $product['sku'],
                'barcode'    => $product['barcode'],
                'price'      => $product['price'],
                'stock'      => $product['stock'],
                'image'      => $product['image'],
                'is_active'  => $product['is_active'],
                'is_variant' => $product['is_variant'],
            ],
            'status'        => $row['status'],
            'score'         => $row['score'],
            'reason'        => self::reasonLabel($row['reason']),
            'hb'            => $hb,
            'mp'            => $row['mp'] === null ? null : [
                'marketplace_sku'        => $row['mp']['marketplace_sku'],
                'marketplace_product_id' => $row['mp']['marketplace_product_id'],
            ],
            'warnings'      => $row['warnings'],
            'transfer_from' => $transferFrom,
        ];
    }

    /**
     * @param array<string, mixed> $h
     * @return array<string, mixed>
     */
    private static function presentCacheRecord(array $h): array
    {
        return [
            'hb_sku'       => $h['hb_sku'],
            'name'         => $h['product_name'] !== '' ? $h['product_name'] : ($h['catalog_name'] !== '' ? $h['catalog_name'] : $h['hb_sku']),
            'barcode'      => $h['barcode'],
            'merchant_sku' => $h['merchant_sku'],
            'price'        => $h['price'],
            'stock'        => $h['stock'],
            'is_salable'   => $h['is_salable'],
            'has_listing'  => $h['has_listing'],
            'image'        => $h['image_url'],
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? '';
    }

    // ═══════════════════════════════════════════════════════
    // İŞLEMLER (yalnız yerel veritabanına yazar)
    // ═══════════════════════════════════════════════════════

    /**
     * Ürünü bir HB ilanına bağlar (marketplace_products upsert). HB'ye çağrı YAPMAZ.
     *
     * @return array{ok: bool, message: string, transferred?: int}
     */
    public function approve(int $marketplaceId, int $productId, string $hbSku): array
    {
        $storeId = $this->storeId();
        $hbSku = trim($hbSku);
        if ($productId <= 0 || $hbSku === '' || mb_strlen($hbSku) > 64) {
            return ['ok' => false, 'message' => 'Eksik bilgi: ürün ve Hepsiburada kaydı seçilmeli.'];
        }

        $product = $this->db->fetchOne(
            'SELECT p.id FROM products p WHERE p.id = ? AND ' . self::SELLABLE_PRODUCT_WHERE . ' LIMIT 1',
            [$productId, $storeId]
        );
        if ($product === false) {
            return ['ok' => false, 'message' => 'Ürün bulunamadı. Varyantlı ana ürünlerde her varyant ayrı eşleştirilir.'];
        }

        $record = $this->db->fetchOne(
            'SELECT ' . self::CACHE_COLUMNS . ' FROM hb_listing_cache WHERE store_id = ? AND hb_sku = ? LIMIT 1',
            [$storeId, $hbSku]
        );
        if ($record === false) {
            return ['ok' => false, 'message' => "Hepsiburada kaydı önbellekte yok — önce Hepsiburada'dan yenileyin."];
        }

        return $this->link($storeId, $marketplaceId, $productId, self::normalizeCacheRow($record, false));
    }

    /**
     * @param array<string, mixed> $h normalizeCacheRow çıktısı
     * @return array{ok: bool, message: string, transferred?: int}
     */
    private function link(int $storeId, int $marketplaceId, int $productId, array $h): array
    {
        // Bir HB ilanı birden çok ürüne bağlanamaz. İlan; pazaryerinden içeri alınmış kopyaya (source = hepsiburada)
        // ya da ürünü olmayan bir satıra bağlıysa bağlantı bu ürüne DEVREDİLİR (kopya ürünün kendisi silinmez).
        $conflicts = $this->db->fetchAll(
            "SELECT mp.id AS mp_id, p.id AS p_id, p.source, p.name
               FROM marketplace_products mp
               LEFT JOIN products p ON p.id = mp.product_id AND p.store_id = mp.store_id
              WHERE mp.store_id = ? AND mp.marketplace_id = ?
                AND (mp.marketplace_product_id = ? OR (mp.marketplace_sku <> '' AND mp.marketplace_sku = ?))
                AND (mp.product_id IS NULL OR mp.product_id <> ?)",
            [$storeId, $marketplaceId, $h['hb_sku'], $h['merchant_sku'], $productId]
        );

        $transfer = [];
        foreach ($conflicts as $conflict) {
            if ($conflict['p_id'] !== null && (string) $conflict['source'] !== self::SLUG) {
                return [
                    'ok'      => false,
                    'message' => 'Bu Hepsiburada ilanı başka bir ürüne bağlı: '
                        . trim(strip_tags((string) ($conflict['name'] ?? ''))) . ' — önce oradaki eşleşmeyi kaldırın.',
                ];
            }
            $transfer[] = (int) $conflict['mp_id'];
        }

        $hasListing = $h['has_listing'] === 1;
        $name = $h['product_name'] !== '' ? $h['product_name'] : $h['catalog_name'];

        $this->db->transaction(function () use ($storeId, $marketplaceId, $productId, $h, $transfer, $hasListing, $name): void {
            if ($transfer !== []) {
                $this->db->query(
                    'DELETE FROM marketplace_products WHERE store_id = ? AND id IN (' . implode(',', array_fill(0, count($transfer), '?')) . ')',
                    array_merge([$storeId], $transfer)
                );
            }

            $this->db->query(
                "INSERT INTO marketplace_products
                    (store_id, product_id, marketplace_id, marketplace_sku, marketplace_product_id, marketplace_barcode,
                     product_name, main_image, last_price_sent, last_stock_sent, is_active, is_listed,
                     last_synced_at, sync_error, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NULL, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    marketplace_sku = VALUES(marketplace_sku),
                    marketplace_product_id = VALUES(marketplace_product_id),
                    marketplace_barcode = VALUES(marketplace_barcode),
                    product_name = COALESCE(VALUES(product_name), product_name),
                    main_image = COALESCE(VALUES(main_image), main_image),
                    last_price_sent = VALUES(last_price_sent),
                    last_stock_sent = VALUES(last_stock_sent),
                    is_active = 1,
                    is_listed = VALUES(is_listed),
                    last_synced_at = NOW(),
                    sync_error = NULL,
                    updated_at = NOW()",
                [
                    $storeId, $productId, $marketplaceId,
                    $h['merchant_sku'] !== '' ? $h['merchant_sku'] : null,
                    $h['hb_sku'],
                    $h['barcode'] !== '' ? $h['barcode'] : null, // boşsa NULL: senkron merchantSku'ya düşer
                    $name !== '' ? $name : null,
                    $h['image_url'] !== '' ? $h['image_url'] : null,
                    $hasListing && $h['price'] !== null ? $h['price'] : null,
                    $hasListing && $h['stock'] !== null ? $h['stock'] : null,
                    $hasListing ? 1 : 0,
                ]
            );
        });

        return [
            'ok'          => true,
            'message'     => $transfer !== []
                ? 'Eşleşme kaydedildi; ilan içeri alınmış kopyadan bu ürüne devredildi.'
                : 'Eşleşme kaydedildi.',
            'transferred' => count($transfer),
        ];
    }

    /**
     * Ürünün HB eşleşmesini siler (yalnız yerel kayıt; HB ilanına dokunmaz).
     */
    public function unlink(int $marketplaceId, int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        return $this->db->query(
            'DELETE FROM marketplace_products WHERE store_id = ? AND marketplace_id = ? AND product_id = ?',
            [$this->storeId(), $marketplaceId, $productId]
        )->rowCount();
    }

    /**
     * Toplu onay — eşleşme sunucuda YENİDEN hesaplanır (istemci listesine güvenilmez).
     * exact: yalnız kesin eşleşmeler · suggested: kesinler + puanı eşik ve üstü öneriler.
     *
     * @return array{approved: int, failed: int, errors: array<int, string>}
     */
    public function bulkApprove(int $marketplaceId, string $mode, int $threshold): array
    {
        $storeId = $this->storeId();
        $mode = $mode === self::MODE_SUGGESTED ? self::MODE_SUGGESTED : self::MODE_EXACT;
        $threshold = self::clampBulkThreshold($threshold);

        $cache = $this->loadCache($storeId);
        $result = self::computeMatches(
            $this->loadProducts($storeId),
            $cache,
            $this->loadApproved($storeId, $marketplaceId),
            self::SUGGEST_THRESHOLD
        );

        $out = ['approved' => 0, 'failed' => 0, 'errors' => []];
        foreach ($result['rows'] as $productId => $row) {
            $eligible = $row['status'] === self::STATUS_EXACT
                || ($mode === self::MODE_SUGGESTED && $row['status'] === self::STATUS_SUGGESTED && $row['score'] >= $threshold);
            if (!$eligible || $row['hb_sku'] === null || !isset($cache[$row['hb_sku']])) {
                continue;
            }

            try {
                $linked = $this->link($storeId, $marketplaceId, (int) $productId, $cache[$row['hb_sku']]);
            } catch (\PDOException $e) {
                $linked = ['ok' => false, 'message' => 'Veritabanı hatası (' . $e->getCode() . ').'];
            }

            if ($linked['ok']) {
                $out['approved']++;
                continue;
            }
            $out['failed']++;
            if (count($out['errors']) < self::ERROR_LIMIT) {
                $out['errors'][] = $row['product']['name'] . ': ' . $linked['message'];
            }
        }

        return $out;
    }

    public static function clampBulkThreshold(int $threshold): int
    {
        return max(self::SUGGEST_THRESHOLD, min(100, $threshold));
    }

    /**
     * 'Değiştir' için HB kayıtları: sorgu boşsa bu ürünün en iyi adayları, doluysa ad/barkod/SKU araması.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(int $marketplaceId, int $productId, string $query, int $limit = 25): array
    {
        $storeId = $this->storeId();
        $limit = max(1, min(50, $limit));
        $query = trim(mb_substr($query, 0, 100));
        if ($query !== '' && mb_strlen($query) < 2) {
            return [];
        }

        $product = $productId > 0 ? $this->loadProduct($storeId, $productId) : null;
        $scores = [];

        if ($query === '') {
            // Aday listesi tüm önbelleğe karşı hesaplanır
            $cache = $this->loadCache($storeId);
            if ($product !== null) {
                $candidates = self::candidates($product, $cache, self::buckets($cache), self::SEARCH_THRESHOLD);
                foreach (array_slice($candidates, 0, $limit) as $candidate) {
                    $scores[] = [$candidate['hb_sku'], $candidate['score']];
                }
            }
        } else {
            // Metin araması: yalnız bulunan kayıtlar okunur (her tuş vuruşunda tüm önbellek taranmaz)
            $words = array_slice(array_values(array_filter(
                preg_split('/\s+/', self::fold($query)) ?: [],
                static fn(string $word): bool => $word !== ''
            )), 0, 5);
            if ($words === []) {
                return [];
            }

            $conditions = [];
            $params = [$storeId];
            foreach ($words as $word) {
                $conditions[] = 'search_text LIKE ?';
                $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word) . '%';
            }
            $found = $this->db->fetchAll(
                'SELECT ' . self::CACHE_COLUMNS . ' FROM hb_listing_cache WHERE store_id = ? AND ' . implode(' AND ', $conditions)
                . ' ORDER BY is_salable DESC, has_listing DESC, product_name ASC LIMIT ' . $limit,
                $params
            );

            $cache = [];
            $profile = $product !== null ? self::describe($product['name']) : null;
            foreach ($found as $foundRow) {
                $h = self::normalizeCacheRow($foundRow);
                $cache[$h['hb_sku']] = $h;
                $exact = $product !== null ? self::exactReason($product, $h) : '';
                $score = $exact !== '' ? 100 : (($profile !== null && $h['_t'] !== null) ? self::score($profile, $h['_t']) : 0);
                $scores[] = [$h['hb_sku'], $score];
            }
        }

        $links = $this->linkedProductsByHbSku($this->loadApproved($storeId, $marketplaceId), $cache);
        $out = [];
        foreach ($scores as [$hbSku, $score]) {
            $h = $cache[$hbSku];
            $link = $links[$hbSku] ?? null;
            $linkedTo = '';
            $transferFrom = '';
            if ($link !== null && $link['product_id'] !== $productId) {
                if ($link['source'] === self::SLUG) {
                    $transferFrom = $link['name'];
                } else {
                    $linkedTo = $link['name'];
                }
            }
            $out[] = self::presentCacheRecord($h) + [
                'score'         => (int) $score,
                'linked_to'     => $linkedTo,
                'transfer_from' => $transferFrom,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $approved
     * @param array<string|int, array<string, mixed>> $cache
     * @return array<string|int, array{product_id: int, name: string, source: string}>
     */
    private function linkedProductsByHbSku(array $approved, array $cache): array
    {
        $mskuIndex = self::merchantSkuIndex($cache);
        $links = [];
        foreach ($approved as $productId => $mp) {
            $hbSku = self::resolveApprovedSku($mp, $cache, $mskuIndex);
            if ($hbSku === '') {
                continue;
            }
            $links[$hbSku] = [
                'product_id' => (int) $productId,
                'name'       => (string) $mp['product_name'],
                'source'     => (string) $mp['source'],
            ];
        }

        return $links;
    }

    // ═══════════════════════════════════════════════════════
    // GÖNDERİM SONUCU (ürün gönderiminin ilan bağlantısını tamamlar)
    // ═══════════════════════════════════════════════════════

    /**
     * Hepsiburada gönderim sonucu (trackingId) — HB'ye yalnız GET.
     * Başarılı ürün: is_listed=1, sync_error=NULL, HB'nin verdiği HBC sku → marketplace_product_id.
     * Reddedilen ürün: is_listed=0, sync_error='Batch reddedildi: …'.
     * trackingId verilmezse seçili ürünlerin (ya da tümünün) bekleyen gönderimleri sync_error'dan bulunur.
     *
     * @param array<int, int> $productIds
     * @return array{results: array<int, array<string, mixed>>, processing: int, message: string}
     */
    public function publishResults(int $marketplaceId, string $trackingId, array $productIds): array
    {
        $storeId = $this->storeId();
        $trackingId = (string) preg_replace('/[^A-Za-z0-9-]/', '', $trackingId);
        $productIds = array_slice(array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0
        ))), 0, 200);

        $where = 'mp.store_id = ? AND mp.marketplace_id = ?';
        $params = [$storeId, $marketplaceId];
        $tracking = [];
        if ($trackingId !== '') {
            // Yalnız bu mağazanın bekleyen gönderimine ait takip numarası sorgulanır (başka satıcının
            // içe aktarım sonucu bu yoldan okunamasın).
            $where .= ' AND mp.sync_error = ?';
            $params[] = self::PENDING_BATCH_PREFIX . $trackingId;
        } else {
            $where .= ' AND mp.sync_error LIKE ?';
            $params[] = self::PENDING_BATCH_PREFIX . '%';
            if ($productIds !== []) {
                $where .= ' AND mp.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')';
                array_push($params, ...$productIds);
            }
        }

        $pending = $this->db->fetchAll(
            "SELECT mp.id, mp.product_id, mp.marketplace_sku, mp.marketplace_barcode, mp.sync_error, p.name AS product_name
               FROM marketplace_products mp
               LEFT JOIN products p ON p.id = mp.product_id AND p.store_id = mp.store_id
              WHERE {$where}
              ORDER BY mp.id DESC
              LIMIT 500",
            $params
        );
        foreach ($pending as $row) {
            $id = trim(substr((string) $row['sync_error'], strlen(self::PENDING_BATCH_PREFIX)));
            if ($id !== '') {
                $tracking[$id][] = $row;
            }
        }
        if ($tracking === []) {
            return ['results' => [], 'processing' => 0, 'message' => $trackingId !== ''
                ? 'Bu takip numarasına ait bekleyen gönderim yok.'
                : 'Sonucu beklenen Hepsiburada gönderimi yok.'];
        }
        if (!method_exists($this->hepsiburada, 'fetchImportResult')) {
            throw new RuntimeException('Hepsiburada servisi gönderim sonucu sorgulamayı desteklemiyor (güncelleme eksik).');
        }

        $results = [];
        foreach (array_slice($tracking, 0, self::RESULT_TRACKING_LIMIT, true) as $id => $localRows) {
            $id = (string) $id;
            try {
                $remote = $this->hepsiburada->fetchImportResult($id);
            } catch (\Throwable $e) {
                $results[] = [
                    'tracking_id' => $id, 'status' => 'hata', 'message' => 'Sorgulanamadı: ' . mb_substr($e->getMessage(), 0, 300),
                    'summary' => null, 'products' => [],
                ];
                continue;
            }
            $this->db->reconnect();

            $products = [];
            foreach ((array) ($remote['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $products[] = $this->applyImportItem($item, $localRows);
                }
            }
            $results[] = [
                'tracking_id' => $id,
                'status'      => (string) ($remote['durum'] ?? 'isleniyor'),
                'message'     => (string) ($remote['mesaj'] ?? ''),
                'summary'     => is_array($remote['ozet'] ?? null) ? $remote['ozet'] : null,
                'products'    => $products,
            ];
        }

        $processing = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'isleniyor'));
        $message = $processing > 0
            ? $processing . ' gönderim Hepsiburada tarafında hâlâ işleniyor.'
            : 'Gönderim sonuçları alındı.';
        if (count($tracking) > self::RESULT_TRACKING_LIMIT) {
            $message .= ' İlk ' . self::RESULT_TRACKING_LIMIT . ' gönderim sorgulandı; kalanlar için tekrar sorgulayın.';
        }

        return ['results' => $results, 'processing' => $processing, 'message' => $message];
    }

    /**
     * Tek ürün sonucunu yerel kayda işler (merchantSku büyük/küçük harf duyarsız ya da barkod ile eşler).
     *
     * @param array<string, mixed> $item fetchImportResult items[] öğesi
     * @param array<int, array<string, mixed>> $localRows
     * @return array<string, mixed>
     */
    private function applyImportItem(array $item, array $localRows): array
    {
        $merchantSku = self::text($item['merchantSku'] ?? null);
        $barcode = self::text($item['barcode'] ?? null);
        $hbSku = self::text($item['hbSku'] ?? null);
        $status = self::text($item['durum'] ?? null);
        $messages = array_values(array_filter(array_map(
            static fn($m): string => is_scalar($m) ? trim((string) $m) : '',
            (array) ($item['mesajlar'] ?? [])
        ), static fn(string $m): bool => $m !== ''));
        $importStatus = self::text($item['importStatus'] ?? null);

        $match = null;
        foreach ($localRows as $local) {
            $skuEqual = $merchantSku !== '' && strcasecmp((string) $local['marketplace_sku'], $merchantSku) === 0;
            $barcodeEqual = $barcode !== '' && (string) $local['marketplace_barcode'] === $barcode;
            if ($skuEqual || $barcodeEqual) {
                $match = $local;
                break;
            }
        }

        if ($match !== null && $status === 'basarili') {
            $update = ['is_listed' => 1, 'sync_error' => null, 'last_synced_at' => date('Y-m-d H:i:s')];
            // Stok/fiyat senkronu HepsiburadaSku'yu marketplace_product_id'den okur
            if (preg_match('/^HBC[A-Z0-9]{6,}$/i', $hbSku)) {
                $update['marketplace_product_id'] = $hbSku;
            }
            $this->marketplaceProducts->update((int) $match['id'], $update);
        } elseif ($match !== null && $status === 'hata') {
            $reason = $messages !== [] ? implode(', ', $messages) : $importStatus;
            $this->marketplaceProducts->update((int) $match['id'], [
                'is_listed'      => 0,
                'last_synced_at' => date('Y-m-d H:i:s'),
                'sync_error'     => mb_substr(self::REJECTED_BATCH_PREFIX . ': ' . $reason, 0, 1000),
            ]);
        }

        return [
            'product_id'    => $match !== null && $match['product_id'] !== null ? (int) $match['product_id'] : null,
            'product_name'  => $match !== null ? (string) ($match['product_name'] ?? '') : '',
            'merchant_sku'  => $merchantSku,
            'barcode'       => $barcode,
            'hb_sku'        => $hbSku,
            'import_status' => $importStatus,
            'status'        => $status,
            'messages'      => $messages,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // VERİ OKUMA
    // ═══════════════════════════════════════════════════════

    private function storeId(): int
    {
        return $this->tenant->currentStoreId();
    }

    /**
     * @return array<int, array<string, mixed>> id => normalizeProduct (+ barcode_dup)
     */
    private function loadProducts(int $storeId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.name, p.sku, p.barcode, p.base_price, p.stock_qty, p.main_image, p.is_active, p.source, p.parent_id
               FROM products p
              WHERE ' . self::SELLABLE_PRODUCT_WHERE . '
              ORDER BY p.id ASC',
            [$storeId]
        );

        $products = [];
        foreach ($rows as $row) {
            $product = self::normalizeProduct($row);
            $products[$product['id']] = $product;
        }

        return self::annotateBarcodeDuplicates($products);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadProduct(int $storeId, int $productId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT p.id, p.name, p.sku, p.barcode, p.base_price, p.stock_qty, p.main_image, p.is_active, p.source, p.parent_id
               FROM products p
              WHERE p.id = ? AND ' . self::SELLABLE_PRODUCT_WHERE . '
              LIMIT 1',
            [$productId, $storeId]
        );
        if ($row === false) {
            return null;
        }

        $product = self::normalizeProduct($row);
        $key = self::codeKey($product['barcode']);
        if ($key !== '') {
            $count = 0;
            $barcodes = $this->db->fetchAll(
                "SELECT p.barcode FROM products p WHERE " . self::SELLABLE_PRODUCT_WHERE . " AND p.barcode IS NOT NULL AND p.barcode <> ''",
                [$storeId]
            );
            foreach ($barcodes as $barcodeRow) {
                if (self::codeKey((string) $barcodeRow['barcode']) === $key) {
                    $count++;
                }
            }
            $product['barcode_dup'] = $count > 1 ? $count : 0;
        }

        return $product;
    }

    /**
     * @return array<string|int, array<string, mixed>> hb_sku => normalizeCacheRow
     */
    private function loadCache(int $storeId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::CACHE_COLUMNS . ' FROM hb_listing_cache WHERE store_id = ?
              ORDER BY is_salable DESC, has_listing DESC, hb_sku ASC',
            [$storeId]
        );

        $cache = [];
        foreach ($rows as $row) {
            $record = self::normalizeCacheRow($row);
            if ($record['hb_sku'] !== '') {
                $cache[$record['hb_sku']] = $record;
            }
        }

        return $cache;
    }

    /**
     * Kayıtlı HB bağlantıları (ilan kimliği taşıyan marketplace_products satırları): product_id => satır.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadApproved(int $storeId, int $marketplaceId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT mp.id, mp.product_id, mp.marketplace_sku, mp.marketplace_product_id, mp.marketplace_barcode,
                    mp.sync_error, p.source, p.name
               FROM marketplace_products mp
               JOIN products p ON p.id = mp.product_id AND p.store_id = mp.store_id
              WHERE mp.store_id = ? AND mp.marketplace_id = ?
                AND (COALESCE(mp.marketplace_product_id, '') <> '' OR COALESCE(mp.marketplace_sku, '') <> '')
              ORDER BY mp.updated_at ASC",
            [$storeId, $marketplaceId]
        );

        $approved = [];
        foreach ($rows as $row) {
            $approved[(int) $row['product_id']] = [
                'mp_id'                  => (int) $row['id'],
                'product_id'             => (int) $row['product_id'],
                'marketplace_sku'        => (string) ($row['marketplace_sku'] ?? ''),
                'marketplace_product_id' => (string) ($row['marketplace_product_id'] ?? ''),
                'marketplace_barcode'    => (string) ($row['marketplace_barcode'] ?? ''),
                'sync_error'             => (string) ($row['sync_error'] ?? ''),
                'source'                 => (string) ($row['source'] ?? ''),
                'product_name'           => trim(strip_tags((string) ($row['name'] ?? ''))),
            ];
        }

        return $approved;
    }

    // ═══════════════════════════════════════════════════════
    // NORMALİZASYON (saf, test edilebilir)
    // ═══════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $row products satırı
     * @return array<string, mixed>
     */
    public static function normalizeProduct(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'name'        => trim(strip_tags((string) ($row['name'] ?? ''))),
            'sku'         => (string) ($row['sku'] ?? ''),
            'barcode'     => (string) ($row['barcode'] ?? ''),
            'price'       => (float) ($row['base_price'] ?? 0),
            'stock'       => (int) ($row['stock_qty'] ?? 0),
            'image'       => (string) ($row['main_image'] ?? ''),
            'is_active'   => (int) ($row['is_active'] ?? 1),
            'is_variant'  => !empty($row['parent_id']),
            'source'      => (string) ($row['source'] ?? ''),
            'barcode_dup' => 0,
        ];
    }

    /**
     * Aynı barkod birden çok üründe kullanılıyorsa barkod kimlik değildir → barkoddan KESİN eşleşme yapılmaz.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    public static function annotateBarcodeDuplicates(array $products): array
    {
        $counts = [];
        foreach ($products as $product) {
            $key = self::codeKey($product['barcode']);
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        foreach ($products as $id => $product) {
            $key = self::codeKey($product['barcode']);
            $products[$id]['barcode_dup'] = ($key !== '' && ($counts[$key] ?? 0) > 1) ? $counts[$key] : 0;
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $row hb_listing_cache satırı
     * @return array<string, mixed>
     */
    public static function normalizeCacheRow(array $row, bool $withProfile = true): array
    {
        $record = [
            'hb_sku'           => (string) ($row['hb_sku'] ?? ''),
            'merchant_sku'     => (string) ($row['merchant_sku'] ?? ''),
            'barcode'          => (string) ($row['barcode'] ?? ''),
            'product_name'     => (string) ($row['product_name'] ?? ''),
            'catalog_name'     => (string) ($row['catalog_name'] ?? ''),
            'quantity'         => (string) ($row['quantity'] ?? ''),
            'product_type'     => (string) ($row['product_type'] ?? ''),
            'variant_group_id' => (string) ($row['variant_group_id'] ?? ''),
            'image_url'        => self::safeImageUrl((string) ($row['image_url'] ?? '')),
            'in_catalog'       => (int) ($row['in_catalog'] ?? 0),
            'has_listing'      => (int) ($row['has_listing'] ?? 0),
            'is_salable'       => (int) ($row['is_salable'] ?? 0),
            'price'            => isset($row['price']) && $row['price'] !== '' ? round((float) $row['price'], 2) : null,
            'stock'            => isset($row['stock']) && $row['stock'] !== '' ? (int) $row['stock'] : null,
            'updated_at'       => (string) ($row['updated_at'] ?? ''),
        ];
        $record['_t'] = $withProfile && $record['product_name'] !== ''
            ? self::describe($record['product_name'], $record['quantity'])
            : null;

        return $record;
    }

    /** Kod karşılaştırma anahtarı (boşluksuz büyük harf). */
    public static function codeKey(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    /** Türkçe küçük harf + ASCII katlama ('ORGANİK SÜTÜ' → 'organik sutu'). */
    public static function fold(string $value): string
    {
        $value = strtr($value, ['İ' => 'i', 'I' => 'ı']);
        $value = mb_strtolower($value, 'UTF-8');

        return strtr($value, [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'â' => 'a', 'î' => 'i', 'û' => 'u', "\xCC\x87" => '',
        ]);
    }

    /**
     * Metindeki ilk miktarı ortak birime çevirir: kg→g, l→ml; mg ayrı boyut (40 mg ≠ 40 g).
     * '1,5 kg' = '1.5KG' = '1500 g' → anahtar 'g:1500'.
     *
     * @return array{unit: string, value: float, key: string}|null
     */
    public static function parseQuantity(string $folded): ?array
    {
        if (!preg_match(self::QUANTITY_PATTERN, $folded, $m)) {
            return null;
        }
        [$unit, $factor] = self::QUANTITY_UNITS[$m[2]];
        $value = round((float) str_replace(',', '.', $m[1]) * $factor, 3);
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return ['unit' => $unit, 'value' => $value, 'key' => $unit . ':' . $text];
    }

    /**
     * Paket adedi ("2'li", "3 adet", "2 x 850 g", "850 g x 2"); bulunamazsa 1.
     * '2'li 850 g' ile tekli '850 g' farklı üründür — adet farklıysa eşleşme SAYILMAZ.
     */
    public static function packCount(string $folded): int
    {
        $unit = '(?:kilogram|kilo|kg|gram|gr|g|mg|mililitre|ml|litre|lt|cl|l)(?![a-z])';
        if (preg_match('/(?<![\d.,])(\d{1,3})\s*[\'`\x{2019}]?\s*(?:li|lu)(?![a-z])/u', $folded, $m)
            || preg_match('/(?<![\d.,])(\d{1,3})\s*adet(?![a-z])/', $folded, $m)
            || preg_match('/(?<![\d.,])(\d{1,3})\s*x\s*(?=\d)/', $folded, $m)
            || preg_match('/' . $unit . '\s*x\s*(\d{1,3})(?![\d.,])/', $folded, $m)) {
            return max(1, (int) $m[1]);
        }

        return 1;
    }

    /**
     * Ürün adını karşılaştırılabilir tanıma çevirir.
     *
     * @param string $extraQuantity addan miktar çıkmazsa yedek (HB 'Miktar' özelliği)
     * @return array{class: string, quantity: array{unit: string, value: float, key: string}|null, pack: int, hard: array<int, string>, soft: array<int, string>, other: array<int, string>}
     */
    public static function describe(string $name, string $extraQuantity = ''): array
    {
        $dictionary = self::DICTIONARY;
        $folded = self::fold(strip_tags($name));
        $quantity = self::parseQuantity($folded);
        if ($quantity === null && trim($extraQuantity) !== '') {
            $quantity = self::parseQuantity(self::fold($extraQuantity));
        }

        $plain = (string) preg_replace(self::QUANTITY_PATTERN, ' ', $folded);
        $raw = preg_split('/\s+/', trim((string) preg_replace('/[^a-z0-9]+/', ' ', $plain))) ?: [];
        $tokens = [];
        $count = count($raw);
        for ($i = 0; $i < $count; $i++) {
            $token = $dictionary['alias'][$raw[$i]] ?? $raw[$i];
            if ($token === 'ari' && isset($raw[$i + 1]) && in_array($raw[$i + 1], ['sut', 'sutu'], true)) {
                $token = 'arisutu';
                $i++;
            }
            if ($token === '' || preg_match('/^\d+$/', $token) || strlen($token) < 2) {
                continue;
            }
            $tokens[$token] = true;
        }
        $tokens = array_map('strval', array_keys($tokens));

        $class = 'diger';
        foreach ($dictionary['classes'] as $candidate) {
            if (in_array($candidate, $tokens, true)) {
                $class = $candidate;
                break;
            }
        }
        if ($class === 'diger' && array_intersect($tokens, $dictionary['honey_anchors'])) {
            $class = 'bal';
        }

        $hard = array_values(array_intersect($tokens, $dictionary['hard']));
        $soft = array_values(array_intersect($tokens, $dictionary['soft']));
        $other = array_values(array_diff($tokens, $dictionary['hard'], $dictionary['soft'], $dictionary['stop'], $dictionary['classes']));
        sort($hard);
        sort($soft);
        sort($other);

        return [
            'class'    => $class,
            'quantity' => $quantity,
            'pack'     => self::packCount($folded),
            'hard'     => $hard,
            'soft'     => $soft,
            'other'    => $other,
        ];
    }

    /**
     * İki ürün tanımının benzerlik puanı (0-100). 0 = eşleşme değil.
     * Kurallar: sınıf aynı olmalı · paket adedi aynı olmalı · iki tarafta da miktar varsa AYNI olmalı ·
     * ayırt edici tip kelimeleri iki tarafta aynı olmalı (tek istisna: 'karakovan … petek' ↔ 'karakovan kaset').
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function score(array $a, array $b): int
    {
        if ($a['class'] !== $b['class']) {
            return 0;
        }
        if (($a['pack'] ?? 1) !== ($b['pack'] ?? 1)) {
            return 0;
        }

        $penalty = 0;
        if ($a['quantity'] !== null && $b['quantity'] !== null) {
            if ($a['quantity']['key'] !== $b['quantity']['key']) {
                return 0;
            }
        } elseif ($a['quantity'] !== null || $b['quantity'] !== null) {
            $penalty += 25;
        } else {
            $penalty += 10;
        }

        $onlyA = array_values(array_diff($a['hard'], $b['hard']));
        $onlyB = array_values(array_diff($b['hard'], $a['hard']));
        if (($onlyA === ['kaset'] && $onlyB === [] && in_array('petek', $b['soft'], true) && in_array('karakovan', $b['hard'], true))
            || ($onlyB === ['kaset'] && $onlyA === [] && in_array('petek', $a['soft'], true) && in_array('karakovan', $a['hard'], true))) {
            $penalty += 20;
            $onlyA = [];
            $onlyB = [];
        }
        if ($onlyA !== [] || $onlyB !== []) {
            return 0;
        }

        $penalty += 10 * count(array_merge(array_diff($a['soft'], $b['soft']), array_diff($b['soft'], $a['soft'])));

        $countA = count($a['other']);
        $countB = count($b['other']);
        $dice = ($countA + $countB) === 0 ? 1.0 : (2 * count(array_intersect($a['other'], $b['other']))) / ($countA + $countB);
        if ($a['class'] === 'diger') {
            if ($dice < 0.5) {
                return 0;
            }
            $penalty += (int) round((1 - $dice) * 60);
        } else {
            $penalty += (int) round((1 - $dice) * 30);
        }

        return max(0, 100 - $penalty);
    }

    // ═══════════════════════════════════════════════════════
    // EŞLEŞTİRME MOTORU (saf, test edilebilir)
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün ↔ HB kaydı kod eşleşmesi. '' = kesin değil.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $h
     */
    public static function exactReason(array $product, array $h): string
    {
        $barcode = !empty($product['barcode_dup']) ? '' : self::codeKey((string) $product['barcode']);
        $sku = self::codeKey((string) $product['sku']);
        $hbBarcode = self::codeKey((string) $h['barcode']);
        $hbMerchantSku = self::codeKey((string) $h['merchant_sku']);
        $hbSku = self::codeKey((string) ($h['hb_sku'] ?? ''));

        if ($sku !== '' && $sku === $hbSku) {
            return 'sku_hbsku';
        }
        if ($barcode !== '' && $barcode === $hbBarcode) {
            return 'barcode';
        }
        if ($sku !== '' && $sku === $hbMerchantSku) {
            return 'sku_msku';
        }
        if ($barcode !== '' && $barcode === $hbMerchantSku) {
            return 'barcode_msku';
        }

        return '';
    }

    /**
     * Bilgi rozetleri (senkron AÇMAZ): barkod tekrarı, ilan yok / satışta değil, fiyat/stok farkı.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $h
     * @return array<int, array{type: string, code: string, text: string, detail?: string}>
     */
    public static function warnings(array $product, array $h): array
    {
        $out = [];
        if (!empty($product['barcode_dup'])) {
            $out[] = ['type' => 'warning', 'code' => 'barcode_dup', 'text' => 'Barkod ' . (int) $product['barcode_dup'] . ' üründe aynı — düzeltin'];
        }
        if ((int) $h['has_listing'] !== 1) {
            $out[] = ['type' => 'danger', 'code' => 'no_listing', 'text' => "HB'de ilan yok"];
            return $out;
        }
        if ((int) $h['is_salable'] !== 1) {
            $out[] = ['type' => 'muted', 'code' => 'not_salable', 'text' => 'HB ilanı satışta değil'];
        }
        if ($h['price'] !== null && abs((float) $h['price'] - (float) $product['price']) >= 0.01) {
            $out[] = [
                'type'   => 'warning',
                'code'   => 'price_diff',
                'text'   => 'Fiyat farkı',
                'detail' => 'Ürün ' . self::money((float) $product['price']) . ' · HB ' . self::money((float) $h['price']),
            ];
        }
        if ($h['stock'] !== null && (int) $h['stock'] !== (int) $product['stock']) {
            $out[] = [
                'type'   => 'warning',
                'code'   => 'stock_diff',
                'text'   => 'Stok farkı',
                'detail' => 'Ürün ' . (int) $product['stock'] . ' · HB ' . (int) $h['stock'],
            ];
        }

        return $out;
    }

    /**
     * Aday sıralaması: kesin > puan > satışta > ilanı var > HB SKU.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function compareCandidates(array $a, array $b): int
    {
        foreach (['exact', 'score', 'is_salable', 'has_listing'] as $key) {
            if ($a[$key] !== $b[$key]) {
                return $a[$key] < $b[$key] ? 1 : -1;
            }
        }

        return strcmp((string) $a['hb_sku'], (string) $b['hb_sku']);
    }

    /**
     * Önbelleği barkod / merchantSku / (sınıf, miktar) / kelime kovalarına ayırır.
     *
     * @param array<string|int, array<string, mixed>> $cache
     * @return array<string, array<string|int, mixed>>
     */
    public static function buckets(array $cache): array
    {
        $buckets = ['barcode' => [], 'msku' => [], 'name' => [], 'token' => [], 'no_token' => []];
        foreach ($cache as $h) {
            $hbSku = $h['hb_sku'];
            $barcode = self::codeKey($h['barcode']);
            $merchantSku = self::codeKey($h['merchant_sku']);
            if ($barcode !== '') {
                $buckets['barcode'][$barcode][] = $hbSku;
            }
            if ($merchantSku !== '') {
                $buckets['msku'][$merchantSku][] = $hbSku;
            }
            if ($h['_t'] === null) {
                continue;
            }
            $class = $h['_t']['class'];
            $quantityKey = $h['_t']['quantity'] !== null ? $h['_t']['quantity']['key'] : '-';
            $buckets['name'][$class][$quantityKey][] = $hbSku;
            if ($class === 'diger') {
                // 'diger' sınıfında Dice < 0.5 → puan 0: ortak kelimesi olmayan kayıt hiç puanlanmaz (hız)
                if ($h['_t']['other'] === []) {
                    $buckets['no_token'][] = $hbSku;
                }
                foreach ($h['_t']['other'] as $token) {
                    $buckets['token'][$token][] = $hbSku;
                }
            }
        }

        return $buckets;
    }

    /**
     * Bir ürün için olası HB kayıtları (kesin + puanlı), en iyiden kötüye.
     *
     * @param array<string, mixed> $product
     * @param array<string|int, array<string, mixed>> $cache
     * @param array<string, array<string|int, mixed>> $buckets
     * @return array<int, array{hb_sku: string, exact: int, reason: string, score: int, is_salable: int, has_listing: int}>
     */
    public static function candidates(array $product, array $cache, array $buckets, int $threshold = self::SUGGEST_THRESHOLD): array
    {
        $found = [];
        $sku = trim((string) $product['sku']);
        // Ürün SKU'su HB SKU'su ise (HBCV…) önbellek anahtarı doğrudan odur
        foreach ([$sku, strtoupper($sku)] as $key) {
            if ($key !== '' && isset($cache[$key])) {
                $reason = self::exactReason($product, $cache[$key]);
                if ($reason !== '') {
                    $found[$key] = self::candidate($cache[$key], 1, $reason, 100);
                }
            }
        }

        $codes = array_filter([
            !empty($product['barcode_dup']) ? '' : self::codeKey((string) $product['barcode']),
            self::codeKey($sku),
        ]);
        foreach ($codes as $code) {
            foreach (['barcode', 'msku'] as $type) {
                foreach ($buckets[$type][$code] ?? [] as $hbSku) {
                    $reason = self::exactReason($product, $cache[$hbSku]);
                    if ($reason !== '') {
                        $found[$hbSku] = self::candidate($cache[$hbSku], 1, $reason, 100);
                    }
                }
            }
        }

        $profile = self::describe((string) $product['name']);
        foreach (self::namePool($profile, $cache, $buckets) as $hbSku) {
            if (isset($found[$hbSku])) {
                continue;
            }
            $score = self::score($profile, $cache[$hbSku]['_t']);
            if ($score >= $threshold) {
                $found[$hbSku] = self::candidate($cache[$hbSku], 0, 'name', $score);
            }
        }

        $found = array_values($found);
        usort($found, [self::class, 'compareCandidates']);

        return $found;
    }

    /**
     * Ad benzerliği için puanlanacak HB kayıtları. Puanı kesin 0 olacak kayıtlar (farklı sınıf, farklı miktar,
     * 'diger' sınıfında ortak kelimesi olmayan) havuza alınmaz — sonuç tam taramayla aynıdır.
     *
     * @param array<string, mixed> $profile
     * @param array<string|int, array<string, mixed>> $cache
     * @param array<string, array<string|int, mixed>> $buckets
     * @return array<int, string|int>
     */
    private static function namePool(array $profile, array $cache, array $buckets): array
    {
        $class = $profile['class'];
        $quantityKey = $profile['quantity'] !== null ? $profile['quantity']['key'] : null;

        if ($class !== 'diger') {
            if ($quantityKey !== null) {
                return array_merge($buckets['name'][$class][$quantityKey] ?? [], $buckets['name'][$class]['-'] ?? []);
            }
            $pool = [];
            foreach ($buckets['name'][$class] ?? [] as $list) {
                $pool = array_merge($pool, $list);
            }
            return $pool;
        }

        $quantityFits = static function ($hbSku) use ($cache, $quantityKey): bool {
            if ($quantityKey === null) {
                return true;
            }
            $hbQuantity = $cache[$hbSku]['_t']['quantity'];
            return $hbQuantity === null || $hbQuantity['key'] === $quantityKey;
        };

        $own = $profile['other'];
        if ($own === []) {
            return array_values(array_filter($buckets['no_token'], $quantityFits));
        }

        $shared = [];
        foreach ($own as $token) {
            foreach ($buckets['token'][$token] ?? [] as $hbSku) {
                $shared[$hbSku] = ($shared[$hbSku] ?? 0) + 1;
            }
        }
        $pool = [];
        $ownCount = count($own);
        foreach ($shared as $hbSku => $common) {
            $dice = (2 * $common) / ($ownCount + count($cache[$hbSku]['_t']['other']));
            if ($dice >= 0.5 && $quantityFits($hbSku)) {
                $pool[] = $hbSku;
            }
        }

        return $pool;
    }

    /**
     * @param array<string, mixed> $h
     * @return array{hb_sku: string, exact: int, reason: string, score: int, is_salable: int, has_listing: int}
     */
    private static function candidate(array $h, int $exact, string $reason, int $score): array
    {
        return [
            'hb_sku'      => (string) $h['hb_sku'],
            'exact'       => $exact,
            'reason'      => $reason,
            'score'       => $score,
            'is_salable'  => (int) $h['is_salable'],
            'has_listing' => (int) $h['has_listing'],
        ];
    }

    /**
     * @param array<string|int, array<string, mixed>> $cache
     * @return array<string, string> merchantSku anahtarı => hb_sku (ilk kayıt kazanır)
     */
    private static function merchantSkuIndex(array $cache): array
    {
        $index = [];
        foreach ($cache as $h) {
            $key = self::codeKey($h['merchant_sku']);
            if ($key !== '' && !isset($index[$key])) {
                $index[$key] = $h['hb_sku'];
            }
        }

        return $index;
    }

    /**
     * Kayıtlı bağlantıyı önbellekteki HB kaydına çözer (önce HB SKU, yoksa merchantSku).
     *
     * @param array<string, mixed> $mp
     * @param array<string|int, array<string, mixed>> $cache
     * @param array<string, string> $mskuIndex
     */
    private static function resolveApprovedSku(array $mp, array $cache, array $mskuIndex): string
    {
        $hbSku = (string) $mp['marketplace_product_id'];
        if ($hbSku !== '' && isset($cache[$hbSku])) {
            return (string) $cache[$hbSku]['hb_sku'];
        }
        $key = self::codeKey((string) $mp['marketplace_sku']);

        return ($key !== '' && isset($mskuIndex[$key])) ? $mskuIndex[$key] : '';
    }

    /**
     * Tüm ürünler için eşleşme durumu.
     * Kayıtlı (onaylı) bağlantılar sabittir; kalanlar için kesin/öneri adayları puanlanır ve EN İYİDEN başlayarak
     * atanır — bir HB kaydı yalnız BİR ürüne atanır. İçeri alınmış kopyanın (source = hepsiburada) ilanı diğer
     * ürünlere aday olabilir (onayda devredilir).
     *
     * @param array<int, array<string, mixed>> $products normalizeProduct + annotateBarcodeDuplicates
     * @param array<string|int, array<string, mixed>> $cache normalizeCacheRow, hb_sku anahtarlı
     * @param array<int, array<string, mixed>> $approved product_id => kayıtlı bağlantı
     * @return array{rows: array<int, array<string, mixed>>, unmatched: array<int, string>, counts: array<string, int>}
     */
    public static function computeMatches(array $products, array $cache, array $approved, int $threshold = self::SUGGEST_THRESHOLD): array
    {
        $buckets = self::buckets($cache);
        $mskuIndex = self::merchantSkuIndex($cache);

        $rows = [];
        $used = [];         // hb_sku => product_id (başka ürüne atanamaz)
        $transferable = []; // hb_sku => içeri alınmış kopya product_id (başka ürüne devredilebilir)

        foreach ($products as $id => $product) {
            $rows[$id] = [
                'product' => $product, 'status' => self::STATUS_NONE, 'hb_sku' => null, 'score' => 0, 'reason' => '',
                'warnings' => [], 'mp' => null, 'transfer_from' => null,
            ];
            if (!isset($approved[$id])) {
                continue;
            }

            $mp = $approved[$id];
            $hbSku = self::resolveApprovedSku($mp, $cache, $mskuIndex);
            $row = &$rows[$id];
            $row['status'] = self::STATUS_APPROVED;
            $row['mp'] = $mp;
            if ($hbSku !== '') {
                $h = $cache[$hbSku];
                if ((string) $mp['source'] === self::SLUG) {
                    $transferable[$hbSku] = $id;
                } else {
                    $used[$hbSku] = $id;
                }
                $exact = self::exactReason($product, $h);
                $row['hb_sku'] = $hbSku;
                $row['score'] = $exact !== '' ? 100 : ($h['_t'] !== null ? self::score(self::describe($product['name']), $h['_t']) : 0);
                $row['reason'] = $exact !== '' ? $exact : 'approved';
                $row['warnings'] = self::warnings($product, $h);
                if ($exact === '' && $row['score'] < self::SUGGEST_THRESHOLD) {
                    array_unshift($row['warnings'], ['type' => 'danger', 'code' => 'name_mismatch', 'text' => 'Ad/miktar uyuşmuyor — kontrol edin']);
                }
            } else {
                $row['reason'] = 'approved';
                $row['warnings'][] = ['type' => 'warning', 'code' => 'not_cached', 'text' => "İlan önbellekte yok — Hepsiburada'dan yenileyin"];
            }
            foreach (self::syncWarnings((string) $mp['sync_error']) as $warning) {
                $row['warnings'][] = $warning;
            }
            unset($row);
        }

        // Listede olmayan ürünlerin (arşiv vb.) bağlantıları da HB kaydını meşgul eder
        foreach ($approved as $id => $mp) {
            if (isset($products[$id])) {
                continue;
            }
            $hbSku = self::resolveApprovedSku($mp, $cache, $mskuIndex);
            if ($hbSku === '') {
                continue;
            }
            if ((string) $mp['source'] === self::SLUG) {
                $transferable[$hbSku] = $id;
            } else {
                $used[$hbSku] = $id;
            }
        }

        // Onaysız ürünler için adaylar → küresel en-iyi-önce atama.
        // Tüm (ürün, aday) çiftlerini sıralayıp sırayla atamakla AYNI sonuç; bellek için kuyrukta her ürünün
        // yalnız sıradaki adayı, listede en iyi CANDIDATE_WINDOW adayı tutulur (tükenirse yeniden hesaplanır).
        $queue = self::assignmentQueue();
        $windows = [];
        $sequence = 0;
        foreach ($products as $id => $product) {
            $sequence++;
            if ($rows[$id]['status'] === self::STATUS_APPROVED) {
                continue;
            }
            $windows[$id] = self::candidateWindow($id, $sequence, $product, $cache, $buckets, $threshold, $used);
            if ($windows[$id]['items'] !== []) {
                $queue->insert($windows[$id]['items'][0]);
            }
        }

        while (!$queue->isEmpty()) {
            $pair = $queue->extract();
            $id = $pair['product_id'];
            if (isset($used[$pair['hb_sku']])) {
                $next = self::nextCandidate($windows[$id], $products[$id], $cache, $buckets, $threshold, $used);
                if ($next !== null) {
                    $queue->insert($next);
                }
                continue;
            }
            $used[$pair['hb_sku']] = $id;
            $h = $cache[$pair['hb_sku']];
            $rows[$id]['status'] = $pair['exact'] ? self::STATUS_EXACT : self::STATUS_SUGGESTED;
            $rows[$id]['hb_sku'] = $pair['hb_sku'];
            $rows[$id]['score'] = $pair['score'];
            $rows[$id]['reason'] = $pair['reason'];
            $rows[$id]['warnings'] = self::warnings($products[$id], $h);
            if (isset($transferable[$pair['hb_sku']])) {
                $rows[$id]['transfer_from'] = $transferable[$pair['hb_sku']];
                $rows[$id]['warnings'][] = ['type' => 'info', 'code' => 'transfer', 'text' => 'İçeri alınmış kopyadan devralınır'];
            }
        }

        $counts = [
            'products' => count($products), 'approved' => 0, 'exact' => 0, 'suggested' => 0, 'none' => 0,
            'unmatched' => 0, 'unmatched_listed' => 0, 'cache' => count($cache),
        ];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }

        $unmatched = [];
        foreach ($cache as $h) {
            if (isset($used[$h['hb_sku']]) || isset($transferable[$h['hb_sku']])) {
                continue;
            }
            $counts['unmatched']++;
            if ((int) $h['has_listing'] === 1) {
                $counts['unmatched_listed']++;
            }
            $unmatched[] = $h['hb_sku'];
        }

        return ['rows' => $rows, 'unmatched' => $unmatched, 'counts' => $counts];
    }

    /**
     * Atama kuyruğu: en önde aday sıralamasında en iyi çift; eşitlikte ürün sırası (kararlı sıralamayla aynı).
     */
    private static function assignmentQueue(): \SplHeap
    {
        return new class extends \SplHeap {
            /**
             * @param array<string, mixed> $value1
             * @param array<string, mixed> $value2
             */
            protected function compare($value1, $value2): int
            {
                $order = HepsiburadaListingMatchService::compareCandidates($value1, $value2);
                if ($order === 0) {
                    $order = $value1['sequence'] <=> $value2['sequence'];
                }

                return -$order;
            }
        };
    }

    /**
     * Ürünün henüz kullanılmamış en iyi adayları (en çok CANDIDATE_WINDOW).
     *
     * @param array<string, mixed> $product
     * @param array<string|int, array<string, mixed>> $cache
     * @param array<string, array<string|int, mixed>> $buckets
     * @param array<string|int, int> $used
     * @return array{items: array<int, array<string, mixed>>, position: int, complete: bool}
     */
    private static function candidateWindow(int $productId, int $sequence, array $product, array $cache, array $buckets, int $threshold, array $used): array
    {
        $items = [];
        $complete = true;
        foreach (self::candidates($product, $cache, $buckets, $threshold) as $candidate) {
            if (isset($used[$candidate['hb_sku']])) {
                continue;
            }
            if (count($items) === self::CANDIDATE_WINDOW) {
                $complete = false;
                break;
            }
            $candidate['product_id'] = $productId;
            $candidate['sequence'] = $sequence;
            $items[] = $candidate;
        }

        return ['items' => $items, 'position' => 0, 'complete' => $complete];
    }

    /**
     * Sıradaki aday; pencere tükendiyse kullanılmamış adaylar yeniden hesaplanır.
     *
     * @param array{items: array<int, array<string, mixed>>, position: int, complete: bool} $window
     * @param array<string, mixed> $product
     * @param array<string|int, array<string, mixed>> $cache
     * @param array<string, array<string|int, mixed>> $buckets
     * @param array<string|int, int> $used
     * @return array<string, mixed>|null
     */
    private static function nextCandidate(array &$window, array $product, array $cache, array $buckets, int $threshold, array $used): ?array
    {
        $window['position']++;
        if (isset($window['items'][$window['position']])) {
            return $window['items'][$window['position']];
        }
        if ($window['complete'] || $window['items'] === []) {
            return null;
        }

        $last = $window['items'][0];
        $window = self::candidateWindow((int) $last['product_id'], (int) $last['sequence'], $product, $cache, $buckets, $threshold, $used);

        return $window['items'][0] ?? null;
    }

    /**
     * Ürün gönderiminden kalan durum (sync_error) rozetleri.
     *
     * @return array<int, array{type: string, code: string, text: string, detail?: string}>
     */
    private static function syncWarnings(string $syncError): array
    {
        if (str_starts_with($syncError, self::PENDING_BATCH_PREFIX)) {
            return [[
                'type' => 'warning', 'code' => 'publish_pending', 'text' => 'Gönderim sonucu bekleniyor',
                'detail' => 'Takip no: ' . trim(substr($syncError, strlen(self::PENDING_BATCH_PREFIX))),
            ]];
        }
        if (str_starts_with($syncError, self::REJECTED_BATCH_PREFIX)) {
            return [[
                'type' => 'danger', 'code' => 'publish_rejected', 'text' => 'Gönderim reddedildi',
                'detail' => mb_substr($syncError, 0, 300),
            ]];
        }

        return [];
    }

    // ═══════════════════════════════════════════════════════
    // KÜÇÜK YARDIMCILAR
    // ═══════════════════════════════════════════════════════

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    /** Yalnız http(s) görsel adresleri kabul edilir. */
    private static function safeImageUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('~^https?://~i', $url) ? $url : '';
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', '.') . ' ₺';
    }
}
