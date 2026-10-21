<?php
/**
 * OzyConnect Panel — MappingCandidateFinder
 *
 * Mağazanın İÇ kategorisi/markası için PAZARYERİ karşılıklarını bulur ve puanlar.
 *
 * NOT: Mevcut CategoryMappingService::fuzzyMatchCategory() TERS yönde çalışır
 * (pazaryeri adı → iç kategori; içe aktarımda ürün yaratmak için). Burada gereken
 * yön iç → pazaryeri olduğu için ayrı bir skorlayıcı var; mevcut metotlara
 * dokunulmaz.
 *
 * Aday havuzu ÖNCE SQL ile daraltılır; benzerlik hesabı yalnız daraltılmış küme
 * üzerinde çalışır (15.000 satırlık katalogda tam tarama saniyeler sürerdi).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-08-26
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Mapping;

use App\Core\Database;
use App\Helpers\StringHelper;
use App\Models\MarketplaceBrand;
use App\Models\MarketplaceCategory;

final class MappingCandidateFinder
{
    /**
     * Güven bantları.
     *
     * SAFE ve üstü sessizce uygulanır. MIN ile SAFE arası da uygulanır ama
     * eşleştirme ekranında uyarı ikonuyla işaretlenir. MIN altı hiç uygulanmaz.
     *
     * Bu değerler gerçek veriyle ölçülerek seçildi (150 iç kategori × Trendyol
     * katalogu): eski formülde eşik 70 ile kapsama %12 idi, yeni formül ve bu
     * bantlarla %82 seviyesine çıkıyor.
     */
    public const CONFIDENCE_SAFE = 75;
    public const CONFIDENCE_MIN  = 45;

    /** Skorlamaya alınacak azami aday */
    private const MAX_CANDIDATES = 12;

    /** Aday havuzunun SQL ile daraltılmış üst sınırı */
    private const POOL_LIMIT = 80;

    /**
     * Soyulacak Türkçe ekler — UZUNDAN KISAYA sıralı olmalı.
     * Normalize edilmiş (Türkçe karakterleri sadeleştirilmiş) metne uygulanır.
     */
    private const SUFFIXES = [
        'larindan', 'lerinden', 'lariyla', 'leriyle', 'lardan', 'lerden',
        'lari', 'leri', 'lara', 'lere', 'sinin', 'sinin',
        'lar', 'ler', 'nin', 'nun', 'sini', 'sina',
        'si', 'su', 'ni', 'nu', 'yi', 'yu', 'in', 'un',
        'i', 'u',
    ];

    /** Eşleştirmede anlam taşımayan kelimeler */
    private const STOPWORDS = [
        've', 'ile', 'diger', 'diğer', 'urun', 'ürün', 'urunleri', 'ürünleri',
        'urunler', 'ürünler', 'cesitleri', 'çeşitleri', 'genel', 'other',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly MarketplaceCategory $mpCategoryModel,
        private readonly MarketplaceBrand $mpBrandModel,
    ) {}

    /**
     * İç kategori için pazaryeri kategori adayları (puanlı, en iyiden kötüye).
     *
     * @param array<string, mixed> $category products/categories satırı (name, full_path)
     * @return array<int, array{id: string, name: string, path: string, score: int}>
     */
    public function candidatesForCategory(array $category, int $marketplaceId, int $limit = self::MAX_CANDIDATES): array
    {
        $ad = trim((string) ($category['name'] ?? ''));
        if ($ad === '') {
            return [];
        }

        $havuz = $this->categoryPool($ad, (string) ($category['full_path'] ?? ''), $marketplaceId);
        if ($havuz === []) {
            return [];
        }

        $ic = [
            'name' => $ad,
            'path' => (string) ($category['full_path'] ?? ''),
        ];

        $puanli = [];
        foreach ($havuz as $aday) {
            $puanli[] = [
                'id'    => (string) $aday['mp_category_id'],
                'name'  => (string) $aday['name'],
                'path'  => (string) ($aday['full_path'] ?? $aday['name']),
                'score' => $this->scoreCategory($ic, $aday),
            ];
        }

        usort($puanli, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($puanli, 0, max(1, $limit));
    }

    /**
     * İç marka için pazaryeri marka adayları.
     *
     * @return array<int, array{id: string, name: string, score: int}>
     */
    public function candidatesForBrand(string $brandName, int $marketplaceId, int $limit = self::MAX_CANDIDATES): array
    {
        $ad = trim($brandName);
        if ($ad === '') {
            return [];
        }

        $havuz = $this->brandPool($ad, $marketplaceId);
        if ($havuz === []) {
            return [];
        }

        $puanli = [];
        foreach ($havuz as $aday) {
            $adayAdi = (string) $aday['name'];
            $puanli[] = [
                'id'    => (string) $aday['mp_brand_id'],
                'name'  => $adayAdi,
                'score' => $this->scoreBrand($ad, $adayAdi),
            ];
        }

        usort($puanli, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($puanli, 0, max(1, $limit));
    }

    // ─── Puanlama ─────────────────────────────────────────

    /**
     * Kategori skoru: ad benzerliği + kelime örtüşmesi + yol benzerliği.
     *
     * Salt similar_text yetmez: "Kadın Ayakkabı" ↔ "Ayakkabı Kadın" %55 alır ama
     * kelime kümesi bire bir aynıdır. Jaccard bunu yakalar.
     *
     * @param array{name: string, path: string} $ic
     * @param array<string, mixed> $aday
     */
    /**
     * Marka skoru — kategoriyle aynı ölçütler, yol bileşeni yok.
     *
     * Markalar genelde tek kelimedir; bu yüzden kapsama ağırlığı daha yüksek
     * ("Samsung" ile "Samsung Electronics" aynı markadır) ve Türkçe ek
     * toleransı burada da geçerlidir.
     */
    public function scoreBrand(string $internalName, string $marketplaceName): int
    {
        $ta = $this->tokens($internalName);
        $tb = $this->tokens($marketplaceName);

        $puan = 0.50 * $this->containment($ta, $tb)
            + 0.30 * $this->tokenJaccard($internalName, $marketplaceName)
            + 0.20 * $this->nameSimilarity($internalName, $marketplaceName);

        return (int) round(100 * $puan);
    }

    public function scoreCategory(array $ic, array $aday): int
    {
        $adayAdi  = (string) ($aday['name'] ?? '');
        $adayYol  = (string) ($aday['full_path'] ?? $adayAdi);

        $ta = $this->tokens($ic['name']);
        $tb = $this->tokens($adayAdi);

        // KAPSAMA en belirleyici ölçüt: kısa adın kelimeleri uzun adın içinde
        // geçiyorsa bu genellikle DOĞRU eşleşmedir. Salt similar_text kullanmak
        // uzunluk farkını cezalandırıyor ve "Oyuncak ↔ Eğitici, Geliştirici
        // Oyuncak" gibi tam isabetli eşleşmeleri 38 puana düşürüyordu.
        $sKapsama = $this->containment($ta, $tb);
        $sToken   = $this->tokenJaccard($ic['name'], $adayAdi);
        $sAd      = $this->nameSimilarity($ic['name'], $adayAdi);

        $puan = 0.45 * $sKapsama + 0.25 * $sToken + 0.20 * $sAd;

        // Yol bilgisi iki tarafta da varsa ağırlığa katılır; yoksa ada devrolur
        $puan += 0.10 * (($ic['path'] !== '' && $adayYol !== '')
            ? $this->nameSimilarity($ic['path'], $adayYol)
            : $sAd);

        return (int) round(100 * $puan);
    }

    private function nameSimilarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $yuzde = 0.0;
        similar_text($a, $b, $yuzde);

        return $yuzde / 100;
    }

    private function tokenJaccard(string $a, string $b): float
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        // Kesişim GÖVDE üzerinden: "kitap" ile "kitaplari" aynı kelimedir
        $kesisim = 0;
        foreach ($ta as $x) {
            foreach ($tb as $y) {
                if ($this->tokensMatch($x, $y)) {
                    $kesisim++;
                    break;
                }
            }
        }

        $birlesim = count($ta) + count($tb) - $kesisim;

        return $birlesim > 0 ? $kesisim / $birlesim : 0.0;
    }

    /**
     * Kapsama: kısa taraftaki kelimelerin kaçı uzun tarafta geçiyor.
     *
     * @param array<int, string> $ta
     * @param array<int, string> $tb
     */
    private function containment(array $ta, array $tb): float
    {
        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $kucuk = count($ta) <= count($tb) ? $ta : $tb;
        $buyuk = count($ta) <= count($tb) ? $tb : $ta;

        $bulunan = 0;
        foreach ($kucuk as $x) {
            foreach ($buyuk as $y) {
                if ($this->tokensMatch($x, $y)) {
                    $bulunan++;
                    break;
                }
            }
        }

        return $bulunan / count($kucuk);
    }

    /**
     * İki kelime aynı sayılır mı: birebir ya da gövdeleri eşit.
     */
    private function tokensMatch(string $a, string $b): bool
    {
        return $a === $b || $this->stem($a) === $this->stem($b);
    }

    /**
     * Türkçe çoğul/iyelik/hâl eklerini soyup gövdeyi döndür.
     *
     * DİKKAT: yalnız GERÇEK ekler soyulur ve gövde en az 4 harf kalmalıdır.
     * Daha gevşek bir "ön ek eşleşmesi" kuralı denendiğinde
     * "Antre ↔ Antrenman Malzemesi" 29 puandan 70 puana fırlamıştı; ek
     * sözlüğüyle 13'e indi. Bu sınırı gevşetmeyin.
     */
    private function stem(string $token): string
    {
        foreach (self::SUFFIXES as $ek) {
            if (mb_strlen($token) - mb_strlen($ek) >= 4 && str_ends_with($token, $ek)) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($ek));
            }
        }

        return $token;
    }

    /**
     * Eşleştirme ekranının güven rozeti için tek giriş noktası.
     *
     * Skor veritabanında TUTULMAZ; satır çizilirken yeniden hesaplanır. Böylece
     * şema değişmez ve uyarı elle yapılmış eşleştirmelerde de çalışır.
     */
    public function confidenceFor(string $internalName, string $internalPath, string $marketplaceName): int
    {
        if (trim($internalName) === '' || trim($marketplaceName) === '') {
            return 0;
        }

        return $this->scoreCategory(
            ['name' => $internalName, 'path' => $internalPath],
            ['name' => $marketplaceName, 'full_path' => $marketplaceName]
        );
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $parcalar = preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $parcalar,
            static fn(string $t): bool => mb_strlen($t) > 1 && !in_array($t, self::STOPWORDS, true)
        )));
    }

    private function normalize(string $text): string
    {
        // StringHelper::normalize Türkçe karakterleri sadeleştirip küçültür
        return mb_strtolower(trim(StringHelper::normalize($text)));
    }

    // ─── Aday havuzu (SQL ile daraltma) ───────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoryPool(string $name, string $path, int $marketplaceId): array
    {
        $kelimeler = $this->tokens($name . ' ' . $path);
        if ($kelimeler === []) {
            $kelimeler = [$this->normalize($name)];
        }

        $kosullar = [];
        $params = ['mid' => $marketplaceId];

        foreach (array_slice($kelimeler, 0, 6) as $i => $kelime) {
            $anahtar = 'k' . $i;
            $kosullar[] = "mc.name LIKE :{$anahtar}";
            $params[$anahtar] = '%' . $kelime . '%';
        }

        // Yalnız satışa uygun yaprak kategoriler aday olur (durum bilgisi yoksa yalnız yaprak)
        $satilabilir = $this->mpCategoryModel->sellableCondition('mc');
        // Mağaza kapsamlı platformlarda (WooCommerce …) yalnız aktif mağazanın kategorileri
        [$kapsam, $kapsamParams] = $this->mpCategoryModel->scopeFilter('mc');

        $limit = self::POOL_LIMIT;
        $havuz = $this->db->fetchAll(
            "SELECT mc.mp_category_id, mc.name, mc.full_path
               FROM marketplace_categories mc
              WHERE mc.marketplace_id = :mid
                AND {$kapsam}
                AND {$satilabilir}
                AND (" . implode(' OR ', $kosullar) . ")
              LIMIT {$limit}",
            $params + $kapsamParams
        );

        // Hiç kelime tutmadıysa: satışa uygun yapraklardan bir örnek küme (YZ için şık olsun)
        if ($havuz === []) {
            $havuz = $this->db->fetchAll(
                "SELECT mc.mp_category_id, mc.name, mc.full_path
                   FROM marketplace_categories mc
                  WHERE mc.marketplace_id = :mid AND {$kapsam} AND {$satilabilir}
                  ORDER BY mc.name ASC
                  LIMIT 20",
                ['mid' => $marketplaceId] + $kapsamParams
            );
        }

        return $havuz;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function brandPool(string $name, int $marketplaceId): array
    {
        $kelimeler = $this->tokens($name);
        if ($kelimeler === []) {
            $kelimeler = [$this->normalize($name)];
        }

        $kosullar = [];
        $params = ['mid' => $marketplaceId];

        foreach (array_slice($kelimeler, 0, 4) as $i => $kelime) {
            $anahtar = 'k' . $i;
            $kosullar[] = "mb.name LIKE :{$anahtar}";
            $params[$anahtar] = '%' . $kelime . '%';
        }

        $limit = self::POOL_LIMIT;
        [$kapsam, $kapsamParams] = $this->mpBrandModel->scopeFilter('mb');

        return $this->db->fetchAll(
            "SELECT mb.mp_brand_id, mb.name
               FROM marketplace_brands mb
              WHERE mb.marketplace_id = :mid
                AND {$kapsam}
                AND (" . implode(' OR ', $kosullar) . ")
              LIMIT {$limit}",
            $params + $kapsamParams
        );
    }
}
