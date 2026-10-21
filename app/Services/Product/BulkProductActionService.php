<?php
/**
 * OzyConnect Panel — BulkProductActionService
 *
 * Ürünler ekranındaki toplu işlemleri (sil / aktif / pasif / arşiv) FİLTREYE UYAN
 * TÜM ürünler üzerinde, parça parça ilerleyerek çalıştırır.
 *
 * Neden: eski akış gizli bir formla YALNIZCA sayfadaki seçili kutucukları (varsayılan 25)
 * gönderiyordu; ekrandaki "filtreye uyan N ürünün tümü seçildi" bandı yalnız pazaryerine
 * gönderime bağlıydı. Kullanıcı 19.458 ürün seçtiğini sanıp 25 ürün siliyordu.
 *
 * Tek istekte on binlerce satır işlemek zaman aşımına girdiği için desen
 * BulkPublishService ile aynı: jeton + JSON iş dosyası + imleç + iptal.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-08-28
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Product;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Product;

final class BulkProductActionService
{
    /** Geçici iş dosyalarının ömrü (saniye) */
    private const JOB_TTL = 86400;

    /** Bir parçada işlenecek ürün sayısı */
    private const CHUNK = 200;

    /**
     * Bir işte kapsanabilecek azami ürün.
     *
     * Product::getIdsForFilters() 20.000'de sert kesiyor; katalog daha büyükse
     * kalan ürünler için iş TEKRAR çalıştırılmalı. Sessiz budama YOK: prepare()
     * gerçek eşleşme sayısını da döndürür, arayüz farkı kullanıcıya söyler.
     */
    private const MAX_PRODUCTS = 20000;

    /** Desteklenen işlemler ve insan okunur adları */
    public const ACTIONS = [
        'delete'      => 'silme',
        'activate'    => 'aktif etme',
        'deactivate'  => 'pasif etme',
        'archive'     => 'arşive taşıma',
        'unarchive'   => 'arşivden çıkarma',
    ];

    public function __construct(
        private readonly Product $productModel,
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
        private readonly \App\Services\ListingStatusService $listingStatus,
    ) {}

    // ─── 1. Adım: hazırlık ────────────────────────────────

    /**
     * İşi hazırla: kapsamı çöz, jetonu üret, iş dosyasını yaz.
     *
     * @param array<int, int>      $productIds mode=ids (sayfadaki seçim)
     * @param array<string, mixed> $filters    mode=filters ("tümünü seç")
     * @return array<string, mixed>
     */
    public function prepare(string $action, array $productIds, array $filters, bool $tumEslesenler): array
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \RuntimeException('Geçersiz toplu işlem.');
        }

        if ($tumEslesenler) {
            $eslesen = $this->productModel->countForFilters($filters);
            $ids     = $this->productModel->getIdsForFilters($filters, self::MAX_PRODUCTS);
        } else {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $productIds),
                static fn(int $v): bool => $v > 0
            )));
            $eslesen = count($ids);
        }

        if ($ids === []) {
            throw new \RuntimeException('İşlenecek ürün bulunamadı.');
        }

        $kirpildi = $eslesen > count($ids);

        $jeton = bin2hex(random_bytes(16));
        $this->writeJob($jeton, [
            'store_id'   => $this->tenant->currentStoreId(),
            'created_at' => time(),
            'cancelled'  => false,
            'action'     => $action,
            'ids'        => $ids,
            'cursor'     => 0,
            'totals'     => ['done' => 0, 'failed' => 0],
            'errors'     => [],
        ]);

        return [
            'token'     => $jeton,
            'action'    => $action,
            'total'     => count($ids),
            'matching'  => $eslesen,
            'truncated' => $kirpildi,
            'chunks'    => (int) ceil(count($ids) / self::CHUNK),
        ];
    }

    // ─── 2. Adım: parça parça uygulama ────────────────────

    /**
     * Bir parçayı işle.
     *
     * @return array<string, mixed>
     */
    public function runChunk(string $token, float $budgetSeconds = 15.0): array
    {
        $is = $this->readJob($token);

        if (!empty($is['cancelled'])) {
            return $this->sonuc($is, true, true);
        }

        $imlec = (int) ($is['cursor'] ?? 0);
        $ids   = (array) ($is['ids'] ?? []);

        $baslangic = microtime(true);
        $islenen   = 0;

        // Süre bütçesi GERÇEKTEN uygulanır: parça bitmeden bütçe dolarsa imleç
        // olduğu yerde bırakılır, tarayıcı sıradaki isteği atar. (BulkPublishService'te
        // bu parametre imzada var ama gövdede kullanılmıyor — aynı hatayı tekrarlamayalım.)
        while ($imlec < count($ids) && (microtime(true) - $baslangic) < $budgetSeconds) {
            $parca = array_slice($ids, $imlec, self::CHUNK);
            if ($parca === []) {
                break;
            }

            $parcaSonuc = $this->uygula((string) $is['action'], $parca);

            $is['totals']['done']   += $parcaSonuc['done'];
            $is['totals']['failed'] += $parcaSonuc['failed'];

            foreach ($parcaSonuc['errors'] as $hata) {
                if (count($is['errors']) < 200) {
                    $is['errors'][] = $hata;
                }
            }

            $imlec  += count($parca);
            $islenen += count($parca);
        }

        $is['cursor'] = $imlec;
        $this->writeJob($token, $is);

        $bitti = $imlec >= count($ids);

        if ($bitti) {
            $this->logger->info(
                sprintf(
                    'Toplu ürün işlemi (%s) tamamlandı: %d işlendi, %d hata',
                    (string) $is['action'],
                    (int) $is['totals']['done'],
                    (int) $is['totals']['failed']
                ),
                'BulkProductAction'
            );
        }

        $cikti = $this->sonuc($is, $bitti, false);
        $cikti['chunk_processed'] = $islenen;

        return $cikti;
    }

    public function cancel(string $token): void
    {
        try {
            $is = $this->readJob($token);
            $is['cancelled'] = true;
            $this->writeJob($token, $is);
        } catch (\Throwable) {
            // İş yoksa yapılacak bir şey yok
        }
    }

    // ─── İşlem uygulayıcı ─────────────────────────────────

    /**
     * @param array<int, int> $ids
     * @return array{done: int, failed: int, errors: array<int, array<string, mixed>>}
     */
    private function uygula(string $action, array $ids): array
    {
        $yapilan = 0;
        $hatali  = 0;
        $hatalar = [];

        foreach ($ids as $pid) {
            $pid = (int) $pid;

            try {
                match ($action) {
                    'delete'     => $this->sil($pid),
                    'activate'   => $this->durumUygula($pid, true),
                    'deactivate' => $this->durumUygula($pid, false),
                    'archive'    => $this->productModel->update($pid, [
                        'is_archived' => 1,
                        'archived_at' => date('Y-m-d H:i:s'),
                    ]),
                    'unarchive'  => $this->productModel->update($pid, [
                        'is_archived' => 0,
                        'archived_at' => null,
                    ]),
                    default      => throw new \RuntimeException('Geçersiz işlem'),
                };
                $yapilan++;
            } catch (\Throwable $e) {
                $hatali++;
                if (count($hatalar) < 20) {
                    $hatalar[] = ['product_id' => $pid, 'error' => $e->getMessage()];
                }
                $this->logger->error(
                    "Toplu işlem başarısız (#{$pid}, {$action}): " . $e->getMessage(),
                    'BulkProductAction'
                );
            }
        }

        return ['done' => $yapilan, 'failed' => $hatali, 'errors' => $hatalar];
    }

    /**
     * Aktiflik bayrağını yaz VE pazaryeri ilanına ilet.
     *
     * Yalnız bayrağı yazmak, panelde "pasif" görünen ürünün pazaryerinde satışta
     * kalmasına yol açardı. Pazaryeri çağrısı başarısız olursa ürün işlemi
     * BAŞARISIZ sayılmaz (yerel durum doğru yazıldı); hata kaydedilir.
     */
    private function durumUygula(int $productId, bool $aktif): void
    {
        $this->productModel->update($productId, ['is_active' => $aktif ? 1 : 0]);

        try {
            $this->listingStatus->apply($productId, $aktif);
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Pazaryeri satış durumu iletilemedi (ürün #{$productId}): " . $e->getMessage(),
                'BulkProductAction'
            );
        }
    }

    /**
     * Ürünü sil — varyantlı üründe önce çocukları sil.
     *
     * products.parent_id yabancı anahtarı ON DELETE SET NULL olduğu için ana kart
     * silindiğinde varyantlar SİLİNMEZ, sahipsiz kalırdı. Bu yüzden elle silinir.
     */
    private function sil(int $productId): void
    {
        $urun = $this->productModel->find($productId);
        if ($urun === false) {
            return;   // başka bir turda zaten silinmiş
        }

        if (!empty($urun['has_variants'])) {
            foreach ($this->productModel->getVariants($productId) as $varyant) {
                $this->productModel->delete((int) $varyant['id']);
            }
        }

        $this->productModel->delete($productId);
    }

    // ─── Yardımcılar ──────────────────────────────────────

    /**
     * @param array<string, mixed> $is
     * @return array<string, mixed>
     */
    private function sonuc(array $is, bool $bitti, bool $iptal): array
    {
        $toplam = count((array) ($is['ids'] ?? []));
        $imlec  = (int) ($is['cursor'] ?? 0);

        return [
            'finished'  => $bitti || $iptal,
            'cancelled' => $iptal,
            'action'    => (string) ($is['action'] ?? ''),
            'total'     => $toplam,
            'processed' => $imlec,
            'percent'   => $toplam > 0 ? (int) round($imlec / $toplam * 100) : 100,
            'totals'    => $is['totals'] ?? ['done' => 0, 'failed' => 0],
            'errors'    => array_slice((array) ($is['errors'] ?? []), 0, 20),
        ];
    }

    /** @param array<string, mixed> $is */
    private function writeJob(string $token, array $is): void
    {
        @file_put_contents($this->jobPath($token), json_encode($is, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return array<string, mixed> */
    private function readJob(string $token): array
    {
        $yol = $this->jobPath($token);
        if (!is_file($yol)) {
            throw new \RuntimeException('Toplu işlem bulunamadı (süresi dolmuş olabilir).');
        }

        $is = json_decode((string) @file_get_contents($yol), true);
        if (!is_array($is)) {
            throw new \RuntimeException('Toplu işlem okunamadı.');
        }

        // Kiracı koruması: başka mağazanın işi çalıştırılamaz
        if ((int) ($is['store_id'] ?? 0) !== $this->tenant->currentStoreId()) {
            throw new \RuntimeException('Bu işleme erişim yetkiniz yok.');
        }

        return $is;
    }

    private function jobDir(): string
    {
        $dir = BASE_PATH . '/storage/bulk_jobs';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir() . '/ozy_bulk_jobs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        if (!is_dir($dir)) {
            throw new \RuntimeException('Geçici iş klasörü oluşturulamadı: storage/bulk_jobs');
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
}
