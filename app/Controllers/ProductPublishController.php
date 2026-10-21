<?php
/**
 * OzyConnect Panel — ProductPublishController
 * Ürünleri pazaryerlerine gönderme (listeleme) controller'ı
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Marketplace;
use App\Services\EntitlementService;
use App\Services\Marketplace\HepsiburadaListingMatchService;
use App\Services\ProductPublishService;
use App\Services\Publish\BulkPublishService;
use App\Services\Publish\MarginCalculator;

final class ProductPublishController extends BaseController
{
    private const NOT_ENTITLED = 'Bu pazaryeri paketinizde bulunmuyor; önce eklenti olarak eklenmeli.';

    /** ProductPublishService HB mükerrer koruması hata metninin ayırt edici parçası. */
    private const ALREADY_LISTED_MARK = 'zaten satışta';

    private readonly ProductPublishService $publishService;
    private readonly BulkPublishService $bulkService;
    private readonly MarginCalculator $margin;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->publishService = $container->get(ProductPublishService::class);
        $this->bulkService    = $container->get(BulkPublishService::class);
        $this->margin         = $container->get(MarginCalculator::class);
    }

    // ═══════════════════════════════════════════════════════
    // TOPLU GÖNDERİM (parça parça)
    // ═══════════════════════════════════════════════════════

    /**
     * 1. adım — işi hazırla, engelleri raporla
     */
    public function bulkPrepare(Request $request): void
    {
        try {
            $sonuc = $this->bulkService->prepare(
                $this->idListesi($request->post('product_ids', '')),
                $this->idListesi($request->post('marketplace_ids', '')),
                $this->filtreler($request)
            );

            $this->success($sonuc, 'Gönderim hazırlandı');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 2. adım — bir parçayı gönder (istemci döngüde çağırır)
     */
    public function bulkChunk(Request $request): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $this->success($this->bulkService->runChunk((string) $request->post('token', '')));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    public function bulkCancel(Request $request): void
    {
        $this->bulkService->cancel((string) $request->post('token', ''));
        $this->success(['cancelled' => true], 'Gönderim durduruldu');
    }

    /**
     * Fiyat / kâr marjı önizlemesi (gönderim öncesi)
     */
    public function pricePreview(Request $request): void
    {
        try {
            $ids = $this->idListesi($request->post('product_ids', ''));
            if ($ids === []) {
                $this->error('Önizleme için ürün seçilmedi.');
            }

            $sayfa   = max(1, $request->integer('page', 1));
            $sayfaBoy = max(1, min(200, $request->integer('per_page', 50)));

            $varsayimlar = [
                'commission_pct' => (float) $request->post('commission_pct', '0'),
                'shipping_cost'  => (float) $request->post('shipping_cost', '0'),
                'packaging_cost' => (float) $request->post('packaging_cost', '0'),
            ];
            $override = [
                'price_rule_type'  => (string) $request->post('price_rule_type', ''),
                'price_rule_value' => (float) $request->post('price_rule_value', '0'),
            ];

            $mpId = $request->integer('marketplace_id');

            // Özet TÜM seçim üzerinden, tablo yalnız o sayfa
            $tumSatirlar = $this->margin->rows($ids, $mpId, $varsayimlar, $override);
            $ozet        = $this->margin->summary($tumSatirlar);
            $dilim       = array_slice($tumSatirlar, ($sayfa - 1) * $sayfaBoy, $sayfaBoy);

            $this->success([
                'rows'     => $dilim,
                'summary'  => $ozet,
                'page'     => $sayfa,
                'per_page' => $sayfaBoy,
                'total'    => count($tumSatirlar),
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Önizlenen fiyat kuralını ürünlere kalıcı yaz
     */
    public function priceApply(Request $request): void
    {
        try {
            $ids  = $this->idListesi($request->post('product_ids', ''));
            $mpId = $request->integer('marketplace_id');
            $tip  = (string) $request->post('price_rule_type', '');
            $deger = (float) $request->post('price_rule_value', '0');

            if ($ids === [] || $mpId <= 0 || $tip === '') {
                $this->error('Ürün, pazaryeri ve fiyat kuralı gereklidir.');
            }

            /** @var \App\Models\MarketplaceProduct $mpModel */
            $mpModel = $this->container->get(\App\Models\MarketplaceProduct::class);
            $guncellenen = 0;

            foreach (array_slice($ids, 0, 200) as $pid) {
                $mpModel->upsertByProductAndMarketplace((int) $pid, $mpId, [
                    'price_rule_type'  => $tip,
                    'price_rule_value' => $deger,
                ]);
                $guncellenen++;
            }

            $this->success(['updated' => $guncellenen], "{$guncellenen} üründe fiyat kuralı güncellendi");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * "12,44,91" ya da JSON dizi → int listesi
     *
     * @return array<int, int>
     */
    private function idListesi(mixed $ham): array
    {
        if (is_array($ham)) {
            $parcalar = $ham;
        } else {
            $ham = trim((string) $ham);
            if ($ham === '') {
                return [];
            }
            $decoded = json_decode($ham, true);
            $parcalar = is_array($decoded) ? $decoded : explode(',', $ham);
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $parcalar),
            static fn(int $v): bool => $v > 0
        )));
    }

    /**
     * Ürün listesi filtreleri (mode=filters)
     *
     * @return array<string, mixed>
     */
    private function filtreler(Request $request): array
    {
        $ham = json_decode((string) $request->post('filters', '{}'), true);
        if (!is_array($ham)) {
            return [];
        }

        $izinli = ['search', 'category_id', 'brand_id', 'is_active', 'stock_status', 'marketplace_id', 'show_archived', 'source', 'xml_profile_id'];

        return array_filter(
            array_intersect_key($ham, array_flip($izinli)),
            static fn($v): bool => $v !== '' && $v !== null
        );
    }

    /**
     * Tekli veya toplu ürün gönderimi
     */
    public function publish(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $productIdsRaw = $request->post('product_ids', '');

        // JSON array veya comma-separated string
        if (is_string($productIdsRaw)) {
            $decoded = json_decode($productIdsRaw, true);
            $productIds = is_array($decoded) ? $decoded : array_filter(array_map('intval', explode(',', $productIdsRaw)));
        } else {
            $productIds = array_map('intval', (array) $productIdsRaw);
        }

        $productIds = array_filter($productIds, fn($id) => $id > 0);

        if (empty($productIds) || $marketplaceId === 0) {
            if ($request->isAjax()) {
                $this->error('Ürün ve pazaryeri seçimi zorunludur');
            }
            $this->redirectWithError('/products', 'Ürün ve pazaryeri seçimi zorunludur');
        }

        if (!$this->isEntitled($marketplaceId)) {
            if ($request->isAjax()) {
                $this->error(self::NOT_ENTITLED, 403);
            }
            $this->redirectWithError('/products', self::NOT_ENTITLED);
        }

        try {
            $result = $this->publishService->publishProducts($productIds, $marketplaceId);

            // Hiçbir ürün gönderilemediyse bu bir başarı değil — gerçek sebebi göster.
            // 'processing' (pazaryeri onayı bekleyen, ör. Hepsiburada içe aktarımı) hata sayılmaz.
            $processing = (int) ($result['processing'] ?? 0);
            $allFailed = $result['success'] === 0 && $processing === 0 && ($result['failed'] > 0 || $result['skipped'] > 0);
            $firstError = !empty($result['errors']) ? (string) ($result['errors'][0]['error'] ?? '') : '';
            $processingInfo = $processing > 0 ? ", {$processing} onay bekliyor" : '';
            // HB mükerrer koruması: zaten satışta olan ürünler ayrıca sayılır ve İlan Eşleştirme'ye yönlendirilir.
            $duplicates = self::countAlreadyListed($result['errors'] ?? []);
            $duplicateInfo = $duplicates > 0
                ? " — {$duplicates} ürün Hepsiburada'da zaten satışta; yeni ürün oluşturulmaz, İlan Eşleştirme'yi kullanın"
                : '';
            if ($duplicates > 0 && str_contains($firstError, self::ALREADY_LISTED_MARK)) {
                $firstError = '';
            }

            if ($request->isAjax()) {
                $batchInfo = !empty($result['batch_ids']) ? ' Batch: ' . implode(', ', $result['batch_ids']) : '';
                $summary = "Gönderim: {$result['success']} başarılı{$processingInfo}, {$result['failed']} hatalı, {$result['skipped']} atlandı{$batchInfo}{$duplicateInfo}";

                if ($allFailed) {
                    $this->json([
                        'success' => false,
                        'message' => $summary . ($firstError !== '' ? ' — ' . $firstError : ''),
                        'data'    => $result,
                    ]);
                    return;
                }

                $this->success($result, $summary);
            }

            $msg = "Ürün gönderimi: {$result['success']} başarılı{$processingInfo}";
            if ($result['failed'] > 0) {
                $msg .= ", {$result['failed']} hatalı";
            }
            if ($result['skipped'] > 0) {
                $msg .= ", {$result['skipped']} atlandı";
            }
            $msg .= $duplicateInfo;

            if ($allFailed) {
                $this->redirectWithError('/products', $msg . ($firstError !== '' ? ' — ' . $firstError : ''));
            }
            $this->redirectWithSuccess('/products', $msg);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Gönderim hatası: ' . $e->getMessage());
            }
            $this->redirectWithError('/products', 'Gönderim hatası: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Gönderim öncesi validasyon
     */
    public function validate(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $productId = $request->integer('product_id');

        if ($productId === 0 || $marketplaceId === 0) {
            $this->error('Ürün ve pazaryeri seçimi zorunludur');
        }

        if (!$this->isEntitled($marketplaceId)) {
            $this->error(self::NOT_ENTITLED, 403);
        }

        $result = $this->publishService->validateForPublish($productId, $marketplaceId);
        $this->success($result);
    }

    /**
     * AJAX: Hepsiburada gönderim sonucu (trackingId) — HB'ye yalnız GET.
     * Girdi: marketplace_id + tracking_id YA DA product_ids (JSON/virgüllü/dizi; ikisi de boşsa
     * mağazanın bekleyen tüm gönderimleri). Sonuç yerel kayda işlenir (is_listed, sync_error, HB SKU).
     */
    public function publishResult(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        if ($marketplaceId <= 0) {
            $this->error('Pazaryeri seçimi zorunludur.');
        }

        $marketplace = $this->container->get(Marketplace::class)->find($marketplaceId);
        if ($marketplace === false || ($marketplace['slug'] ?? '') !== HepsiburadaListingMatchService::SLUG) {
            $this->error('Gönderim sonucu sorgulama yalnız Hepsiburada için kullanılabilir.', 422);
        }
        if (!$this->isEntitled($marketplaceId)) {
            $this->error(self::NOT_ENTITLED, 403);
        }

        $productIds = $this->idListesi($request->post('product_ids', ''));
        if (count($productIds) > 200) {
            $this->error('Tek seferde en fazla 200 ürün sorgulanabilir.');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // HB sorgusu uzun sürebilir; panel oturum kilidi diğer sekmeleri bekletmesin
        }
        @set_time_limit(120);

        try {
            $result = $this->container->get(HepsiburadaListingMatchService::class)->publishResults(
                $marketplaceId,
                (string) $request->post('tracking_id', ''),
                $productIds
            );
        } catch (\Throwable $e) {
            $this->logger->error('Gönderim sonucu sorgulanamadı: ' . $e->getMessage(), 'ProductPublish');
            $this->error('Gönderim sonucu sorgulanamadı: ' . mb_substr($e->getMessage(), 0, 300), 502);
        }

        $this->success(
            ['results' => $result['results'], 'processing' => $result['processing']],
            $result['message']
        );
    }

    /** Aktif mağaza bu pazaryerine yetkili mi? (store_marketplaces entitlement) */
    private function isEntitled(int $marketplaceId): bool
    {
        return $this->container->get(EntitlementService::class)->canAccessMarketplace($marketplaceId);
    }

    /**
     * Mükerrer korumasına takılan (HB'de zaten satışta) ürün sayısı.
     *
     * @param array<int, mixed> $errors publishProducts errors[] ({error: string, ...})
     */
    private static function countAlreadyListed(array $errors): int
    {
        $count = 0;
        foreach ($errors as $error) {
            $text = is_array($error) ? (string) ($error['error'] ?? '') : (is_string($error) ? $error : '');
            if (str_contains($text, self::ALREADY_LISTED_MARK)) {
                $count++;
            }
        }
        return $count;
    }
}
