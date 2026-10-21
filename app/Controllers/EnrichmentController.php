<?php
/**
 * OzyConnect Panel — EnrichmentController
 * Zenginleştirme dashboard, toplu işlemler ve eşleştirme uygulama
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
use App\Models\BrandAlias;
use App\Models\Marketplace;
use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;

final class EnrichmentController extends BaseController
{
    private readonly ProductEnrichmentService $enrichmentService;
    private readonly Marketplace $marketplaceModel;
    private readonly Product $productModel;
    private readonly BrandAlias $brandAliasModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->enrichmentService = $container->get(ProductEnrichmentService::class);
        $this->marketplaceModel  = $container->get(Marketplace::class);
        $this->productModel      = $container->get(Product::class);
        $this->brandAliasModel   = $container->get(BrandAlias::class);
    }

    /**
     * T7.1 — Enrichment dashboard sayfası
     * GET /enrichment
     */
    public function dashboard(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getActive();
        $marketplaceId = $request->integer('marketplace_id', 0);

        if ($marketplaceId === 0 && !empty($marketplaces)) {
            $marketplaceId = (int) $marketplaces[0]['id'];
        }

        $stats = ['total' => 0, 'complete' => 0, 'partial' => 0, 'pending' => 0,
                  'missing_category' => 0, 'missing_brand' => 0, 'missing_price' => 0,
                  'missing_images' => 0, 'missing_stock' => 0];
        $products = [];
        $filters = [
            'missing_category' => $request->query('missing_category'),
            'missing_brand'    => $request->query('missing_brand'),
            'missing_price'    => $request->query('missing_price'),
            'missing_images'   => $request->query('missing_images'),
            'missing_stock'    => $request->query('missing_stock'),
            'status'           => $request->query('status'),
        ];

        if ($marketplaceId > 0) {
            $stats = $this->getDashboardStats($marketplaceId);
            $products = $this->enrichmentService->getIncompleteProducts($marketplaceId, $filters);
        }

        $this->view('enrichment.dashboard', [
            'pageTitle'     => 'Zenginleştirme Dashboard',
            'marketplaces'  => $marketplaces,
            'marketplaceId' => $marketplaceId,
            'stats'         => $stats,
            'products'      => $products,
            'filters'       => $filters,
        ]);
    }

    /**
     * T7.2 — Toplu zenginleştirme
     * POST /enrichment/bulk
     */
    public function bulkEnrich(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $productIdsRaw = $request->post('product_ids', '');

        $productIds = [];
        if ($productIdsRaw !== '') {
            $productIds = array_filter(array_map('intval', explode(',', $productIdsRaw)));
        }

        if ($marketplaceId === 0) {
            if ($request->isAjax()) {
                $this->error('Pazaryeri seçimi zorunludur');
            }
            $this->redirectWithError('/enrichment', 'Pazaryeri seçimi zorunludur');
        }

        try {
            $result = $this->enrichmentService->enrichImportedProducts($marketplaceId, $productIds);

            if ($request->isAjax()) {
                $this->success($result->toArray(), "Zenginleştirme: {$result->totalProcessed} ürün işlendi");
            }

            $msg = "Zenginleştirme tamamlandı: {$result->totalProcessed} ürün, "
                 . "kategori: {$result->categoryMatched} eşleşti, "
                 . "marka: {$result->brandMatched} eşleşti";
            $this->redirectWithSuccess('/enrichment?marketplace_id=' . $marketplaceId, $msg);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Zenginleştirme hatası: ' . $e->getMessage());
            }
            $this->redirectWithError('/enrichment?marketplace_id=' . $marketplaceId, 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * T7.3 — Tek ürün zenginleştirme
     * POST /enrichment/product/{id}
     */
    public function enrichProduct(Request $request, string $id): void
    {
        $marketplaceId = $request->integer('marketplace_id');

        if ($marketplaceId === 0) {
            if ($request->isAjax()) {
                $this->error('Pazaryeri seçimi zorunludur');
            }
            $this->redirectWithError("/products/{$id}", 'Pazaryeri seçimi zorunludur');
        }

        try {
            $result = $this->enrichmentService->enrichSingleProduct((int) $id, $marketplaceId);

            if ($request->isAjax()) {
                $this->success($result->toArray(), 'Ürün zenginleştirildi');
            }

            $status = $result->categoryMatched > 0 || $result->brandMatched > 0
                ? 'Zenginleştirme tamamlandı'
                : 'Zenginleştirme çalıştı, bazı alanlar eşleştirilemedi';
            $this->redirectWithSuccess("/products/{$id}", $status);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Hata: ' . $e->getMessage());
            }
            $this->redirectWithError("/products/{$id}", 'Zenginleştirme hatası: ' . $e->getMessage());
        }
    }

    /**
     * T7.4 — Kategori eşleştirmesini ürünlere uygula
     * POST /enrichment/apply-category-mapping
     */
    public function applyCategoryMapping(Request $request): void
    {
        $categoryId = $request->integer('category_id');
        $marketplaceId = $request->integer('marketplace_id');
        $marketplaceCategoryId = $request->post('marketplace_category_id', '');

        if ($categoryId === 0 || $marketplaceId === 0 || $marketplaceCategoryId === '') {
            if ($request->isAjax()) {
                $this->error('Eksik alanlar');
            }
            $this->redirectWithError('/enrichment', 'Eksik alanlar');
        }

        try {
            $count = $this->enrichmentService->applyCategoryMapping($categoryId, $marketplaceId, $marketplaceCategoryId);

            if ($request->isAjax()) {
                $this->success(['updated' => $count], "{$count} ürün güncellendi");
            }

            $this->redirectWithSuccess('/enrichment?marketplace_id=' . $marketplaceId, "Kategori eşleştirmesi {$count} ürüne uygulandı");
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Hata: ' . $e->getMessage());
            }
            $this->redirectWithError('/enrichment', 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * T7.5 — Marka eşleştirmesini ürünlere uygula
     * POST /enrichment/apply-brand-mapping
     */
    public function applyBrandMapping(Request $request): void
    {
        $brandId = $request->integer('brand_id');
        $marketplaceId = $request->integer('marketplace_id');
        $marketplaceBrandName = $request->post('marketplace_brand_name', '');

        if ($brandId === 0 || $marketplaceId === 0 || $marketplaceBrandName === '') {
            if ($request->isAjax()) {
                $this->error('Eksik alanlar');
            }
            $this->redirectWithError('/enrichment', 'Eksik alanlar');
        }

        try {
            $count = $this->enrichmentService->applyBrandMapping($brandId, $marketplaceId, $marketplaceBrandName);

            if ($request->isAjax()) {
                $this->success(['updated' => $count], "{$count} ürün güncellendi");
            }

            $this->redirectWithSuccess('/enrichment?marketplace_id=' . $marketplaceId, "Marka eşleştirmesi {$count} ürüne uygulandı");
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Hata: ' . $e->getMessage());
            }
            $this->redirectWithError('/enrichment', 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * T7.6 — Marka takma adı ekle
     * POST /enrichment/brand-alias
     */
    public function addBrandAlias(Request $request): void
    {
        $brandId = $request->integer('brand_id');
        $aliasName = trim($request->post('alias_name', ''));

        if ($brandId === 0 || $aliasName === '') {
            if ($request->isAjax()) {
                $this->error('Marka ve takma ad zorunludur');
            }
            $this->redirectWithError('/enrichment', 'Marka ve takma ad zorunludur');
        }

        try {
            $this->brandAliasModel->addAlias($brandId, $aliasName);

            if ($request->isAjax()) {
                $this->success([], "Takma ad eklendi: {$aliasName}");
            }

            $this->redirectWithSuccess('/brands/mapping', "Takma ad eklendi: {$aliasName}");
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error($e->getMessage());
            }
            $this->redirectWithError('/brands/mapping', 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * T7.6 — Marka takma adı sil
     * POST /enrichment/brand-alias/{id}/delete
     */
    public function deleteBrandAlias(Request $request, string $id): void
    {
        try {
            $this->brandAliasModel->delete((int) $id);

            if ($request->isAjax()) {
                $this->success([], 'Takma ad silindi');
            }

            $this->redirectWithSuccess('/brands/mapping', 'Takma ad silindi');
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error($e->getMessage());
            }
            $this->redirectWithError('/brands/mapping', 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * T7.7 — Başarısız/partial zenginleştirmeleri tekrarla
     * POST /enrichment/retry-failed
     */
    public function retryFailed(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');

        if ($marketplaceId === 0) {
            if ($request->isAjax()) {
                $this->error('Pazaryeri seçimi zorunludur');
            }
            $this->redirectWithError('/enrichment', 'Pazaryeri seçimi zorunludur');
        }

        try {
            // partial ve pending ürünleri yeniden işle
            $incompleteProducts = $this->enrichmentService->getIncompleteProducts($marketplaceId, ['status' => 'partial']);
            $pendingProducts = $this->enrichmentService->getIncompleteProducts($marketplaceId, ['status' => 'pending']);

            $productIds = array_merge(
                array_column($incompleteProducts, 'id'),
                array_column($pendingProducts, 'id')
            );
            $productIds = array_unique(array_map('intval', $productIds));

            if (empty($productIds)) {
                if ($request->isAjax()) {
                    $this->success(['totalProcessed' => 0], 'Tekrarlanacak ürün bulunamadı');
                }
                $this->redirectWithSuccess('/enrichment?marketplace_id=' . $marketplaceId, 'Tekrarlanacak ürün bulunamadı');
            }

            $result = $this->enrichmentService->enrichImportedProducts($marketplaceId, $productIds);

            if ($request->isAjax()) {
                $this->success($result->toArray(), "{$result->totalProcessed} ürün yeniden işlendi");
            }

            $this->redirectWithSuccess('/enrichment?marketplace_id=' . $marketplaceId, "Tekrar zenginleştirme: {$result->totalProcessed} ürün işlendi");
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                $this->error('Hata: ' . $e->getMessage());
            }
            $this->redirectWithError('/enrichment?marketplace_id=' . $marketplaceId, 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * GET /enrichment/incomplete — Eksik verili ürünler (AJAX)
     */
    public function incomplete(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $filters = [
            'missing_category' => $request->query('missing_category'),
            'missing_brand'    => $request->query('missing_brand'),
            'missing_price'    => $request->query('missing_price'),
            'missing_images'   => $request->query('missing_images'),
            'missing_stock'    => $request->query('missing_stock'),
            'status'           => $request->query('status'),
        ];

        $products = $this->enrichmentService->getIncompleteProducts($marketplaceId, $filters);
        $this->success(['products' => $products, 'count' => count($products)]);
    }

    // ═══════════════════════════════════════════════════════
    // YARDIMCI
    // ═══════════════════════════════════════════════════════

    /**
     * Dashboard istatistikleri
     *
     * @return array<string, int>
     */
    private function getDashboardStats(int $marketplaceId): array
    {
        return $this->productModel->getEnrichmentStats($marketplaceId);
    }
}
