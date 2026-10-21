<?php
/**
 * OzyConnect Panel — AutoMappingController
 *
 * Akıllı Eşleştirme sihirbazı: iç kategori/markaları pazaryeri karşılıklarına
 * önce bulanık eşleştirmeyle, kalanları yapay zekâyla eşler.
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Marketplace;
use App\Services\Ai\AiMappingService;
use App\Services\Mapping\AutoMappingService;

final class AutoMappingController extends BaseController
{
    private readonly AutoMappingService $mapping;
    private readonly AiMappingService $ai;
    private readonly Marketplace $marketplaceModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->mapping          = $container->get(AutoMappingService::class);
        $this->ai               = $container->get(AiMappingService::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
    }

    /**
     * Sihirbaz sayfası
     */
    public function index(Request $request): void
    {
        $kind = $request->query('kind', 'category') === 'brand' ? 'brand' : 'category';
        $mpId = $request->integer('marketplace_id');

        $this->view('mapping.auto', [
            'pageTitle'     => 'Akıllı Eşleştirme',
            'kind'          => $kind,
            'marketplaceId' => $mpId,
            'marketplaces'  => $this->marketplaceModel->getActive(),
            'aiAvailable'   => $this->ai->available(),
            'aiCredits'     => $this->ai->creditBalance(),
            'aiReason'      => $this->ai->unavailableReason(),
            'minThreshold'  => AutoMappingService::MIN_THRESHOLD,
            'confidenceSafe' => \App\Services\Mapping\MappingCandidateFinder::CONFIDENCE_SAFE,
        ]);
    }

    public function start(Request $request): void
    {
        try {
            $this->success($this->mapping->start(
                $request->integer('marketplace_id'),
                (string) $request->post('kind', 'category')
            ), 'Eşleştirme başladı');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Bulanık aşama — 25 kayıt/istek
     */
    public function chunk(Request $request): void
    {
        @set_time_limit(0);

        try {
            $this->success($this->mapping->runChunk(
                (string) $request->post('token', ''),
                $request->integer('offset', 0),
                25
            ));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Yapay zekâ aşaması — parti parti, kredi bitince durur
     */
    public function aiChunk(Request $request): void
    {
        @set_time_limit(0);

        try {
            $token = (string) $request->post('token', '');
            $esik  = max(AutoMappingService::MIN_THRESHOLD, $request->integer('threshold', 70));
            $offset = $request->integer('offset', 0);

            $is = $this->mapping->readJob($token);
            $bekleyen = $this->mapping->pendingForAi($token, $esik, $offset, AiMappingService::BATCH_SIZE);

            if ($bekleyen === []) {
                $this->success(['offset' => $offset, 'processed' => 0, 'done' => true, 'credits' => $this->ai->creditBalance()]);
            }

            try {
                $kararlar = $this->ai->match($bekleyen, (string) $is['kind'], (int) $is['marketplace_id']);
            } catch (\App\Services\Ai\InsufficientCreditsException $e) {
                // Krediler bitti → o ana kadarki öneriler KORUNUR
                $this->success([
                    'offset'  => $offset,
                    'stopped' => true,
                    'done'    => true,
                    'credits' => $this->ai->creditBalance(),
                    'notice'  => 'Yapay zekâ kredisi tükendi — bulanık eşleştirme sonuçları korundu.',
                ]);
            }

            $sonuc = $this->mapping->applyAiDecisions($token, $kararlar);

            $this->success([
                'offset'    => $offset + count($bekleyen),
                'processed' => $sonuc['processed'],
                'done'      => count($bekleyen) < AiMappingService::BATCH_SIZE,
                'stats'     => $sonuc['stats'],
                'credits'   => $this->ai->creditBalance(),
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Önerileri kalıcı yaz
     */
    public function apply(Request $request): void
    {
        @set_time_limit(0);

        try {
            $ham = json_decode((string) $request->post('only_ids', ''), true);
            $onlyIds = is_array($ham) && $ham !== []
                ? array_values(array_filter(array_map('intval', $ham), static fn(int $v): bool => $v > 0))
                : null;

            $this->success($this->mapping->apply(
                (string) $request->post('token', ''),
                $request->integer('threshold', 70),
                $onlyIds,
                200
            ), 'Eşleştirmeler uygulandı');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Son uygulamayı geri al
     */
    public function undo(Request $request): void
    {
        try {
            $sonuc = $this->mapping->undo((string) $request->post('token', ''));
            $this->success($sonuc, "{$sonuc['removed']} eşleştirme geri alındı");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    public function cancel(Request $request): void
    {
        $this->mapping->discard((string) $request->post('token', ''));
        $this->success([], 'İptal edildi');
    }

    /**
     * Sihirbazın mevcut önerileri (tablo için)
     */
    public function proposals(Request $request): void
    {
        try {
            $is = $this->mapping->readJob((string) $request->post('token', ''));
            $oneriler = array_values($is['proposals']);

            usort($oneriler, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

            $sayfa  = max(1, $request->integer('page', 1));
            $boyut  = 100;
            $dilim  = array_slice($oneriler, ($sayfa - 1) * $boyut, $boyut);

            // Aday listesi tabloya gönderilmez (büyük); yalnız seçilen gösterilir
            $dilim = array_map(static function (array $o): array {
                unset($o['candidates']);
                return $o;
            }, $dilim);

            $this->success([
                'rows'    => $dilim,
                'page'    => $sayfa,
                'total'   => count($oneriler),
                'applied' => count($is['applied']),
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }
}
