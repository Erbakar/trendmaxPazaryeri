<?php
/**
 * OzyConnect Panel — AutoMappingService
 *
 * İç kategori/markaları pazaryeri karşılıklarına otomatik eşleştirir.
 *
 * Akış:
 *   1) start()    → eşleşmemişleri say, iş dosyası aç
 *   2) runChunk() → BULANIK aşama (25 kayıt/istek): birebir ad → normalize ad → skor
 *   3) aiChunk()  → skoru eşiğin altında kalanlar için YZ (20 kayıt/çağrı)
 *   4) apply()    → onaylanan önerileri kalıcı yaz (geri alınabilir)
 *
 * Öneriler DB'ye YAZILMAZ; apply() çağrılana kadar iş dosyasında durur. Bu sayede
 * şema değişikliği gerekmiyor ve yanlış eşleşme kalıcı olmuyor.
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
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Brand;
use App\Models\BrandMarketplaceMapping;
use App\Models\Category;
use App\Models\CategoryMarketplaceMapping;
use App\Models\MarketplaceBrand;
use App\Models\MarketplaceCategory;

final class AutoMappingService
{
    private const JOB_TTL = 86400;

    /**
     * Kullanıcının inebileceği en düşük otomatik uygulama eşiği.
     *
     * Skorlayıcıyla tek kaynaktan beslenir; bantlar orada ölçümle belirlendi.
     */
    public const MIN_THRESHOLD = MappingCandidateFinder::CONFIDENCE_MIN;

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly MappingCandidateFinder $finder,
        private readonly Category $categoryModel,
        private readonly Brand $brandModel,
        private readonly MarketplaceCategory $mpCategoryModel,
        private readonly MarketplaceBrand $mpBrandModel,
        private readonly CategoryMarketplaceMapping $catMapping,
        private readonly BrandMarketplaceMapping $brandMapping,
        private readonly Logger $logger,
    ) {}

    // ─── 1. Başlat ────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function start(int $marketplaceId, string $kind): array
    {
        $kind = $kind === 'brand' ? 'brand' : 'category';

        $katalog = $kind === 'category'
            ? $this->mpCategoryModel->countByMarketplace($marketplaceId)
            : $this->mpBrandModel->countByMarketplace($marketplaceId);

        // Katalog boşsa eşleştirmenin aday havuzu yok — sihirbaz başlamaz
        if ($katalog === 0) {
            throw new \RuntimeException(
                'Bu pazaryerinin ' . ($kind === 'category' ? 'kategori' : 'marka') .
                ' katalogu boş. Önce "Pazaryerinden Çek" ile katalogu indirin.'
            );
        }

        $eslesmemis = $kind === 'category'
            ? $this->unmappedCategories($marketplaceId)
            : $this->unmappedBrands($marketplaceId);

        $jeton = bin2hex(random_bytes(16));
        $this->writeJob($jeton, [
            'store_id'       => $this->tenant->currentStoreId(),
            'marketplace_id' => $marketplaceId,
            'kind'           => $kind,
            'created_at'     => time(),
            'items'          => $eslesmemis,
            'proposals'      => [],
            'applied'        => [],
        ]);

        return [
            'token'        => $jeton,
            'kind'         => $kind,
            'total'        => count($eslesmemis),
            'catalog_size' => $katalog,
        ];
    }

    // ─── 2. Bulanık aşama ─────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function runChunk(string $token, int $offset, int $limit = 25): array
    {
        $is = $this->readJob($token);
        $kind = (string) $is['kind'];
        $mpId = (int) $is['marketplace_id'];

        $dilim = array_slice($is['items'], $offset, max(1, $limit));
        $uretilen = [];

        foreach ($dilim as $kayit) {
            $oneri = $kind === 'category'
                ? $this->proposeCategory($kayit, $mpId)
                : $this->proposeBrand($kayit, $mpId);

            $is['proposals'][(string) $kayit['id']] = $oneri;
            $uretilen[] = $oneri;
        }

        $yeniOffset = $offset + count($dilim);
        $is['offset'] = $yeniOffset;
        $this->writeJob($token, $is);

        return [
            'offset'    => $yeniOffset,
            'processed' => count($dilim),
            'done'      => $yeniOffset >= count($is['items']),
            'total'     => count($is['items']),
            'proposals' => $uretilen,
            'stats'     => $this->stats($is['proposals']),
        ];
    }

    /**
     * Bir iç kategori için öneri üret.
     *
     * @param array<string, mixed> $kayit
     * @return array<string, mixed>
     */
    private function proposeCategory(array $kayit, int $marketplaceId): array
    {
        $ad = (string) $kayit['name'];

        // 1) Birebir ad — pazaryerinde aynı adlı yaprak kategori
        $tam = $this->mpCategoryModel->findExactMatch($ad, $marketplaceId, true);
        if ($tam !== false) {
            return $this->proposal($kayit, (string) $tam['mp_category_id'], (string) $tam['name'], 100, 'birebir', []);
        }

        // 2) Bulanık — puanlı adaylar
        $adaylar = $this->finder->candidatesForCategory($kayit, $marketplaceId);
        if ($adaylar === []) {
            return $this->proposal($kayit, '', '', 0, 'yok', []);
        }

        $enIyi = $adaylar[0];

        return $this->proposal(
            $kayit,
            (string) $enIyi['id'],
            (string) $enIyi['name'],
            (int) $enIyi['score'],
            $enIyi['score'] >= 95 ? 'normalize' : 'bulanik',
            $adaylar
        );
    }

    /**
     * @param array<string, mixed> $kayit
     * @return array<string, mixed>
     */
    private function proposeBrand(array $kayit, int $marketplaceId): array
    {
        $ad = (string) $kayit['name'];

        $tam = $this->mpBrandModel->findExactMatch($ad, $marketplaceId);
        if ($tam !== false) {
            return $this->proposal($kayit, (string) $tam['mp_brand_id'], (string) $tam['name'], 100, 'birebir', []);
        }

        $adaylar = $this->finder->candidatesForBrand($ad, $marketplaceId);
        if ($adaylar === []) {
            return $this->proposal($kayit, '', '', 0, 'yok', []);
        }

        $enIyi = $adaylar[0];

        return $this->proposal(
            $kayit,
            (string) $enIyi['id'],
            (string) $enIyi['name'],
            (int) $enIyi['score'],
            'bulanik',
            $adaylar
        );
    }

    /**
     * @param array<string, mixed> $kayit
     * @param array<int, array<string, mixed>> $adaylar
     * @return array<string, mixed>
     */
    private function proposal(array $kayit, string $mpId, string $mpName, int $score, string $method, array $adaylar): array
    {
        return [
            'internal_id'   => (int) $kayit['id'],
            'internal_name' => (string) $kayit['name'],
            'internal_path' => (string) ($kayit['full_path'] ?? ''),
            'mp_id'         => $mpId,
            'mp_name'       => $mpName,
            'score'         => $score,
            'method'        => $method,
            'candidates'    => array_slice($adaylar, 0, 12),
        ];
    }

    // ─── 3. YZ aşaması için bekleyenler ───────────────────

    /**
     * Skoru eşiğin altında kalan (YZ'ye gidecek) kayıtlar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForAi(string $token, int $threshold, int $offset, int $limit = 20): array
    {
        $is = $this->readJob($token);

        $bekleyen = array_values(array_filter(
            $is['proposals'],
            static fn(array $o): bool => $o['method'] !== 'yz' && $o['score'] < $threshold && $o['candidates'] !== []
        ));

        return array_slice($bekleyen, $offset, max(1, $limit));
    }

    /**
     * YZ'den dönen kararları iş dosyasına işle.
     *
     * @param array<int, array{internal_id: int, mp_id: string, score: int}> $decisions
     * @return array<string, mixed>
     */
    public function applyAiDecisions(string $token, array $decisions): array
    {
        $is = $this->readJob($token);
        $islenen = 0;

        foreach ($decisions as $karar) {
            $ic = (string) ($karar['internal_id'] ?? '');
            if ($ic === '' || !isset($is['proposals'][$ic])) {
                continue;
            }

            $secilenId = trim((string) ($karar['mp_id'] ?? ''));
            if ($secilenId === '') {
                continue;
            }

            // YZ yalnız VERİLEN adaylardan seçebilir — uydurma ID kabul edilmez
            $gecerli = null;
            foreach ($is['proposals'][$ic]['candidates'] as $aday) {
                if ((string) $aday['id'] === $secilenId) {
                    $gecerli = $aday;
                    break;
                }
            }
            if ($gecerli === null) {
                continue;
            }

            $is['proposals'][$ic]['mp_id']   = $secilenId;
            $is['proposals'][$ic]['mp_name'] = (string) $gecerli['name'];
            $is['proposals'][$ic]['score']   = min(90, max(0, (int) ($karar['score'] ?? 0)));
            $is['proposals'][$ic]['method']  = 'yz';
            $islenen++;
        }

        $this->writeJob($token, $is);

        return ['processed' => $islenen, 'stats' => $this->stats($is['proposals'])];
    }

    // ─── 4. Uygula ────────────────────────────────────────

    /**
     * Eşiği geçen önerileri kalıcı yaz.
     *
     * @param array<int, int>|null $onlyIds yalnız bu iç kayıtlar (null = eşiği geçen hepsi)
     * @return array<string, mixed>
     */
    public function apply(string $token, int $threshold, ?array $onlyIds = null, int $limit = 200): array
    {
        $is = $this->readJob($token);
        $kind = (string) $is['kind'];
        $mpId = (int) $is['marketplace_id'];
        $threshold = max(self::MIN_THRESHOLD, $threshold);

        $uygulanan = 0;
        $hatali = 0;
        $hatalar = [];
        $islenenler = [];

        foreach ($is['proposals'] as $anahtar => $oneri) {
            if (count($islenenler) >= $limit) {
                break;
            }
            if ($oneri['mp_id'] === '' || $oneri['score'] < $threshold) {
                continue;
            }
            if ($onlyIds !== null && !in_array((int) $oneri['internal_id'], $onlyIds, true)) {
                continue;
            }
            if (in_array((int) $oneri['internal_id'], $is['applied'], true)) {
                continue;
            }

            try {
                if ($kind === 'category') {
                    $this->catMapping->saveMapping(
                        (int) $oneri['internal_id'],
                        $mpId,
                        (string) $oneri['mp_id'],
                        (string) $oneri['mp_name']
                    );
                } else {
                    $this->brandMapping->saveMapping(
                        (int) $oneri['internal_id'],
                        $mpId,
                        (string) $oneri['mp_id'],
                        (string) $oneri['mp_name']
                    );
                }

                $is['applied'][] = (int) $oneri['internal_id'];
                $uygulanan++;
            } catch (\Throwable $e) {
                $hatali++;
                if (count($hatalar) < 20) {
                    $hatalar[] = [
                        'name'  => (string) $oneri['internal_name'],
                        'error' => mb_substr($e->getMessage(), 0, 180),
                    ];
                }
            }

            $islenenler[] = $anahtar;
        }

        $this->writeJob($token, $is);

        $this->logger->info(
            sprintf('Akıllı eşleştirme uygulandı (%s): %d kayıt, %d hata', $kind, $uygulanan, $hatali),
            'AutoMapping'
        );

        return [
            'applied' => $uygulanan,
            'failed'  => $hatali,
            'errors'  => $hatalar,
            'done'    => count($islenenler) < $limit,
        ];
    }

    /**
     * Son uygulamayı geri al — yanlış eşleşme kalıcı olmasın.
     *
     * @return array<string, mixed>
     */
    public function undo(string $token): array
    {
        $is = $this->readJob($token);
        $kind = (string) $is['kind'];
        $mpId = (int) $is['marketplace_id'];
        $silinen = 0;

        foreach ($is['applied'] as $icId) {
            $mevcut = $kind === 'category'
                ? $this->catMapping->findMapping((int) $icId, $mpId)
                : $this->brandMapping->findMapping((int) $icId, $mpId);

            if ($mevcut !== false) {
                $kind === 'category'
                    ? $this->catMapping->delete((int) $mevcut['id'])
                    : $this->brandMapping->delete((int) $mevcut['id']);
                $silinen++;
            }
        }

        $is['applied'] = [];
        $this->writeJob($token, $is);

        return ['removed' => $silinen];
    }

    public function discard(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            @unlink($this->jobPath($token));
        }
    }

    // ─── Eşleşmemiş kayıtlar ──────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unmappedCategories(int $marketplaceId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.name, c.full_path
               FROM categories c
              WHERE c.store_id = :sid
                AND NOT EXISTS (
                    SELECT 1 FROM category_marketplace_mapping cm
                     WHERE cm.category_id = c.id
                       AND cm.marketplace_id = :mid
                       AND cm.store_id = :sid2
                )
              ORDER BY c.name ASC
              LIMIT 2000",
            ['sid' => $this->tenant->currentStoreId(), 'mid' => $marketplaceId, 'sid2' => $this->tenant->currentStoreId()]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unmappedBrands(int $marketplaceId): array
    {
        return $this->db->fetchAll(
            "SELECT b.id, b.name, '' AS full_path
               FROM brands b
              WHERE b.store_id = :sid
                AND NOT EXISTS (
                    SELECT 1 FROM brand_marketplace_mapping bm
                     WHERE bm.brand_id = b.id
                       AND bm.marketplace_id = :mid
                       AND bm.store_id = :sid2
                )
              ORDER BY b.name ASC
              LIMIT 2000",
            ['sid' => $this->tenant->currentStoreId(), 'mid' => $marketplaceId, 'sid2' => $this->tenant->currentStoreId()]
        );
    }

    /**
     * @param array<string, array<string, mixed>> $proposals
     * @return array<string, int>
     */
    private function stats(array $proposals): array
    {
        $yuksek = 0; $orta = 0; $yok = 0;

        // Bantlar skorlayıcıyla aynı: SAFE üstü sessiz, MIN-SAFE arası uyarılı
        foreach ($proposals as $o) {
            if ($o['mp_id'] === '' || $o['score'] < self::MIN_THRESHOLD) {
                $yok++;
            } elseif ($o['score'] >= MappingCandidateFinder::CONFIDENCE_SAFE) {
                $yuksek++;
            } else {
                $orta++;
            }
        }

        return ['high' => $yuksek, 'review' => $orta, 'none' => $yok, 'total' => count($proposals)];
    }

    // ─── İş dosyası ───────────────────────────────────────

    private function jobDir(): string
    {
        $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)) . '/storage/mapping_jobs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir() . '/ozy_mapping_jobs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException('Geçici iş klasörü oluşturulamadı: storage/mapping_jobs');
        }

        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\n");
        }

        foreach ((array) @glob($dir . '/*.json') as $eski) {
            if (@filemtime($eski) < time() - self::JOB_TTL) {
                @unlink($eski);
            }
        }

        return $dir;
    }

    private function jobPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \RuntimeException('Geçersiz iş anahtarı.');
        }

        return $this->jobDir() . '/' . $token . '.json';
    }

    /**
     * @param array<string, mixed> $is
     */
    private function writeJob(string $token, array $is): void
    {
        @file_put_contents($this->jobPath($token), json_encode($is, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    public function readJob(string $token): array
    {
        $yol = $this->jobPath($token);
        if (!is_file($yol)) {
            throw new \RuntimeException('Eşleştirme işi bulunamadı (süresi dolmuş olabilir).');
        }

        $is = json_decode((string) @file_get_contents($yol), true);
        if (!is_array($is)) {
            throw new \RuntimeException('Eşleştirme işi okunamadı.');
        }

        if ((int) ($is['store_id'] ?? 0) !== $this->tenant->currentStoreId()) {
            throw new \RuntimeException('Bu eşleştirme işi başka bir mağazaya ait.');
        }

        return $is;
    }
}
