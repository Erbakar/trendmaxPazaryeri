<?php
/**
 * OzyConnect Panel — BulkPublishService
 *
 * Seçilen ürünleri seçilen pazaryerlerine TOPLU gönderir.
 *
 * Neden parça parça: ProductPublishService::publishProducts() kendi içinde 50'lik
 * gruplara bölüyor ama TÜMÜNÜ tek HTTP isteğinde işliyor. Pazaryerleri toplu
 * yüklemeyi kuyrukta işlediği için istek başına 20 sn bekleme bütçesi var; 2.000
 * ürün = 40 grup × (API + bekleme) → kesin zaman aşımı. Burada bir HTTP isteği =
 * bir parça; tarayıcı parçaları sırayla çağırır, ilerleme çubuğu gösterilir.
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

namespace App\Services\Publish;

use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Models\Product;
use App\Services\EntitlementService;
use App\Services\ProductPublishService;
use App\Services\Import\XmlProfileSettings;

final class BulkPublishService
{
    /** Geçici iş dosyalarının ömrü (saniye) */
    private const JOB_TTL = 86400;

    /** Bir istekte gönderilecek ürün sayısı — pazaryerine göre */
    private const CHUNK_SIZES = [
        // Amazon'da toplu uç nokta yok: her SKU ayrı PUT → parça küçük tutulur
        'amazon'      => 10,
        // ÇiçekSepeti kota penceresi dar
        'ciceksepeti' => 20,
        // WooCommerce yanıtı senkron: mağaza görselleri gönderim sırasında indirir,
        // varyantlı ürün = değişken ürün + varyasyonlar → istek kısa tutulur
        'woocommerce' => 10,
    ];

    /** Barkod, kategori ve marka eşleştirmesi isteğe bağlı pazaryerleri: yalnız fiyat engeldir */
    private const PRICE_ONLY_READINESS = ['woocommerce'];
    private const DEFAULT_CHUNK = 50;

    /**
     * Filtreyle seçimde alınacak azami ürün.
     *
     * 5.000'di: 10.000 ürünlük kataloglarda seçimin yarısı SESSİZCE düşüyordu.
     * Kırpma uyarısı da çalışmıyordu (bkz. prepare()), kullanıcı eksik gönderimi
     * hiç fark etmiyordu. Kardeş servis BulkProductActionService ile aynı tavan.
     */
    private const MAX_PRODUCTS = 20000;

    public function __construct(
        private readonly ProductPublishService $publishService,
        private readonly Product $productModel,
        private readonly Marketplace $marketplaceModel,
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
        private readonly XmlProfileSettings $profileSettings,
    ) {}

    // ─── 1. Adım: hazırlık ────────────────────────────────

    /**
     * İşi hazırla: ürün listesini çöz, pazaryerlerini süz, engelleri raporla.
     *
     * @param array<int, int>      $productIds mode=ids için
     * @param array<int, int>      $marketplaceIds
     * @param array<string, mixed> $filters    mode=filters için
     * @return array<string, mixed>
     */
    public function prepare(array $productIds, array $marketplaceIds, array $filters = []): array
    {
        if ($productIds !== []) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $productIds),
                static fn(int $v): bool => $v > 0
            )));
            $eslesen = count($ids);
        } else {
            // Filtreye uyan GERÇEK sayı önce alınır. getIdsForFilters zaten SQL'de
            // LIMIT uyguladığı için "count($ids) > MAX_PRODUCTS" karşılaştırması
            // asla doğru olamazdı; kırpma bu yüzden sessiz kalıyordu.
            $eslesen = $this->productModel->countForFilters($filters);
            $ids     = $this->productModel->getIdsForFilters($filters, self::MAX_PRODUCTS);
        }

        if ($ids === []) {
            throw new \RuntimeException('Gönderilecek ürün seçilmedi.');
        }

        if (count($ids) > self::MAX_PRODUCTS) {
            $ids = array_slice($ids, 0, self::MAX_PRODUCTS);
        }

        $kirpildi = $eslesen > count($ids);

        $kuyruk = [];
        $pazaryerleri = [];
        $engellenen = [];

        foreach ($marketplaceIds as $mpId) {
            $mpId = (int) $mpId;
            $mp = $this->marketplaceModel->find($mpId);

            if ($mp === false) {
                $engellenen[] = ['id' => $mpId, 'name' => '#' . $mpId, 'reason' => 'Pazaryeri bulunamadı'];
                continue;
            }
            if (!$this->entitlements->canAccessMarketplace($mpId)) {
                $engellenen[] = ['id' => $mpId, 'name' => (string) $mp['display_name'], 'reason' => 'Paketinizde bu pazaryeri yok'];
                continue;
            }
            if (empty($mp['api_key_encrypted']) && empty($mp['api_secret_encrypted'])) {
                $engellenen[] = ['id' => $mpId, 'name' => (string) $mp['display_name'], 'reason' => 'API bilgileri girilmemiş'];
                continue;
            }

            $slug  = (string) $mp['slug'];
            $parca = self::CHUNK_SIZES[$slug] ?? self::DEFAULT_CHUNK;
            $rapor = $this->productModel->getPublishReadiness(
                $productIds !== [] ? ['__ids' => $ids] + $filters : $filters,
                $mpId
            );

            $kuyruk[] = [
                'mp_id'  => $mpId,
                'slug'   => $slug,
                'name'   => (string) $mp['display_name'],
                'chunk'  => $parca,
                'offset' => 0,
            ];

            $pazaryerleri[] = in_array($slug, self::PRICE_ONLY_READINESS, true)
                ? [
                    'id'         => $mpId,
                    'slug'       => $slug,
                    'name'       => (string) $mp['display_name'],
                    'chunk_size' => $parca,
                    'ready'      => max(0, $rapor['total'] - $rapor['no_price']),
                    'blocked'    => $rapor['no_price'],
                    'blockers'   => ['no_price' => $rapor['no_price']],
                    'optional_mapping' => true,
                ]
                : [
                    'id'         => $mpId,
                    'slug'       => $slug,
                    'name'       => (string) $mp['display_name'],
                    'chunk_size' => $parca,
                    'ready'      => $rapor['ready'],
                    'blocked'    => max(0, $rapor['total'] - $rapor['ready']),
                    'blockers'   => [
                        'no_category_mapping' => $rapor['no_category_mapping'],
                        'no_brand_mapping'    => $rapor['no_brand_mapping'],
                        'no_barcode'          => $rapor['no_barcode'],
                        'no_image'            => $rapor['no_image'],
                        'no_price'            => $rapor['no_price'],
                    ],
                ];
        }

        if ($kuyruk === []) {
            throw new \RuntimeException('Gönderilebilecek pazaryeri yok. ' . ($engellenen[0]['reason'] ?? ''));
        }

        $jeton = bin2hex(random_bytes(16));
        $this->writeJob($jeton, [
            'store_id'   => $this->tenant->currentStoreId(),
            'created_at' => time(),
            'cancelled'  => false,
            'ids'        => $ids,
            'queue'      => $kuyruk,
            'cursor'     => ['mp' => 0, 'offset' => 0],
            'totals'     => ['success' => 0, 'failed' => 0, 'skipped' => 0, 'processing' => 0],
            'errors'     => [],
            'batch_ids'  => [],
        ]);

        $toplamParca = 0;
        foreach ($kuyruk as $sira) {
            $toplamParca += (int) ceil(count($ids) / $sira['chunk']);
        }

        return [
            'token'                => $jeton,
            'total'                => count($ids),
            'matching'             => $eslesen,
            'truncated'            => $kirpildi,
            'estimated_chunks'     => $toplamParca,
            'marketplaces'         => $pazaryerleri,
            'blocked_marketplaces' => $engellenen,
            'xml_profit_rule'      => $this->xmlKarKurali($ids),
        ];
    }

    /**
     * Seçimin TAMAMI tek bir XML profilinden geliyorsa o profilin kâr kuralı.
     *
     * Karışık seçimde (birden çok tedarikçi ya da elle eklenmiş ürün) kural
     * dönmez — yanlış tedarikçinin marjını uygulamak fiyatı bozar.
     *
     * @param array<int, int> $ids
     * @return array{type: string, value: float, profile_id: int}|null
     */
    private function xmlKarKurali(array $ids): ?array
    {
        $profiller = $this->productModel->xmlProfilleriOfIds($ids);

        if (count($profiller) !== 1 || (int) $profiller[0] <= 0) {
            return null;
        }

        $profilId = (int) $profiller[0];
        $ayar     = $this->profileSettings->forProfile($profilId);
        $deger    = (float) ($ayar['kar_degeri'] ?? 0);

        if ($deger <= 0) {
            return null;
        }

        return [
            'type'       => ($ayar['kar_tipi'] ?? 'yuzde') === 'tutar' ? 'fixed_increase' : 'percent_increase',
            'value'      => $deger,
            'profile_id' => $profilId,
        ];
    }

    // ─── 2. Adım: parça parça gönderim ────────────────────

    /**
     * Bir parçayı gönder.
     *
     * @return array<string, mixed>
     */
    public function runChunk(string $token, float $budgetSeconds = 20.0): array
    {
        $is = $this->readJob($token);

        if (!empty($is['cancelled'])) {
            return $this->chunkResult($is, true, ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []], true);
        }

        $mpIndex = (int) ($is['cursor']['mp'] ?? 0);
        $offset  = (int) ($is['cursor']['offset'] ?? 0);

        if (!isset($is['queue'][$mpIndex])) {
            return $this->chunkResult($is, true, ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []]);
        }

        $sira    = $is['queue'][$mpIndex];
        $ids     = $is['ids'];
        $parcaId = array_slice($ids, $offset, (int) $sira['chunk']);

        // Bu pazaryeri bitti → sıradakine geç
        if ($parcaId === []) {
            $is['cursor'] = ['mp' => $mpIndex + 1, 'offset' => 0];
            $this->writeJob($token, $is);

            return $this->chunkResult($is, !isset($is['queue'][$mpIndex + 1]), ['success' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []]);
        }

        $baslangic = microtime(true);
        $parcaSonuc = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'processing' => 0, 'errors' => []];

        try {
            $sonuc = $this->publishService->publishProducts($parcaId, (int) $sira['mp_id']);

            $parcaSonuc['success'] = (int) ($sonuc['success'] ?? 0);
            $parcaSonuc['failed']  = (int) ($sonuc['failed'] ?? 0);
            $parcaSonuc['skipped'] = (int) ($sonuc['skipped'] ?? 0);
            // HB içe aktarımı ilk kontrolde çoğunlukla 'işleniyor' döner (onay bekliyor)
            $parcaSonuc['processing'] = (int) ($sonuc['processing'] ?? 0);
            $parcaSonuc['errors']  = array_slice((array) ($sonuc['errors'] ?? []), 0, 10);

            foreach ((array) ($sonuc['batch_ids'] ?? []) as $batch) {
                if ($batch !== '' && count($is['batch_ids']) < 200) {
                    $is['batch_ids'][] = (string) $batch;
                }
            }
        } catch (\Throwable $e) {
            $parcaSonuc['failed'] = count($parcaId);
            $parcaSonuc['errors'] = [['error' => mb_substr($e->getMessage(), 0, 200)]];
            $this->logger->error(
                sprintf('Toplu gönderim parçası hatası (%s): %s', $sira['slug'], $e->getMessage()),
                'BulkPublish'
            );
        }

        $is['totals']['success'] += $parcaSonuc['success'];
        $is['totals']['failed']  += $parcaSonuc['failed'];
        $is['totals']['skipped'] += $parcaSonuc['skipped'];
        // Pazaryeri onayı bekleyenler: ne başarılı ne hatalı — ayrı sayılır,
        // yoksa toplam tutmaz ve ürünler özetten sessizce düşer.
        $is['totals']['processing'] = ($is['totals']['processing'] ?? 0) + ($parcaSonuc['processing'] ?? 0);

        foreach ($parcaSonuc['errors'] as $hata) {
            if (count($is['errors']) < 500) {
                $is['errors'][] = ['marketplace' => $sira['name']] + (array) $hata;
            }
        }

        // İmleci ilerlet
        $yeniOffset = $offset + count($parcaId);
        if ($yeniOffset >= count($ids)) {
            $is['cursor'] = ['mp' => $mpIndex + 1, 'offset' => 0];
        } else {
            $is['cursor'] = ['mp' => $mpIndex, 'offset' => $yeniOffset];
        }

        $bitti = !isset($is['queue'][(int) $is['cursor']['mp']]);
        $this->writeJob($token, $is);

        if ($bitti) {
            $this->logger->info(
                sprintf(
                    'Toplu gönderim tamamlandı: %d başarılı, %d hatalı, %d atlandı, %d işleniyor',
                    $is['totals']['success'],
                    $is['totals']['failed'],
                    $is['totals']['skipped'],
                    $is['totals']['processing'] ?? 0
                ),
                'BulkPublish'
            );
        }

        $sonucDizi = $this->chunkResult($is, $bitti, $parcaSonuc);
        $sonucDizi['elapsed'] = round(microtime(true) - $baslangic, 2);

        return $sonucDizi;
    }

    public function cancel(string $token): void
    {
        try {
            $is = $this->readJob($token);
            $is['cancelled'] = true;
            $this->writeJob($token, $is);
        } catch (\Throwable) {
            // İş zaten yoksa yapılacak bir şey yok
        }
    }

    /**
     * Bitmiş işin raporu.
     *
     * @return array<string, mixed>
     */
    public function report(string $token): array
    {
        $is = $this->readJob($token);

        return [
            'totals'    => $is['totals'],
            'errors'    => array_slice($is['errors'], 0, 200),
            'batch_ids' => $is['batch_ids'],
            'total'     => count($is['ids']),
        ];
    }

    // ─── Yardımcılar ──────────────────────────────────────

    /**
     * @param array<string, mixed> $is
     * @param array<string, mixed> $parca
     * @return array<string, mixed>
     */
    private function chunkResult(array $is, bool $finished, array $parca, bool $cancelled = false): array
    {
        $mpIndex = (int) ($is['cursor']['mp'] ?? 0);
        $offset  = (int) ($is['cursor']['offset'] ?? 0);
        $toplam  = count($is['ids']);
        $sira    = $is['queue'][$mpIndex] ?? null;

        $tamamlanan = $mpIndex * $toplam + $offset;
        $genelToplam = max(1, count($is['queue']) * $toplam);

        return [
            'cursor'    => $is['cursor'],
            'finished'  => $finished,
            'cancelled' => $cancelled,
            'current'   => [
                'marketplace' => $sira !== null ? $sira['name'] : '',
                'done'        => $offset,
                'total'       => $toplam,
            ],
            'chunk'       => $parca,
            'totals'      => $is['totals'],
            'overall_pct' => min(100, (int) round(($tamamlanan / $genelToplam) * 100)),
        ];
    }

    private function jobDir(): string
    {
        $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)) . '/storage/publish_jobs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir() . '/ozy_publish_jobs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException('Geçici iş klasörü oluşturulamadı: storage/publish_jobs');
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
    private function readJob(string $token): array
    {
        $yol = $this->jobPath($token);
        if (!is_file($yol)) {
            throw new \RuntimeException('Gönderim işi bulunamadı (süresi dolmuş olabilir).');
        }

        $is = json_decode((string) @file_get_contents($yol), true);
        if (!is_array($is)) {
            throw new \RuntimeException('Gönderim işi okunamadı.');
        }

        // Kiracı koruması: başka mağazanın işi çalıştırılamaz
        if ((int) ($is['store_id'] ?? 0) !== $this->tenant->currentStoreId()) {
            throw new \RuntimeException('Bu gönderim işi başka bir mağazaya ait.');
        }

        return $is;
    }
}
