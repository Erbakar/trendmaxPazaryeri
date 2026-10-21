<?php
/**
 * OzyConnect Panel — BrandMappingController
 * Marka ↔ pazaryeri markası eşleştirme yönetimi
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
use App\Models\BrandMarketplaceMapping;
use App\Models\Marketplace;
use App\Services\BrandMappingService;

final class BrandMappingController extends BaseController
{
    private readonly BrandMappingService $brandMappingService;
    private readonly BrandMarketplaceMapping $mappingModel;
    private readonly Marketplace $marketplaceModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->brandMappingService = $container->get(BrandMappingService::class);
        $this->mappingModel        = $container->get(BrandMarketplaceMapping::class);
        $this->marketplaceModel    = $container->get(Marketplace::class);
    }

    /**
     * Eşleştirme listesi
     */
    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getActive();
        $marketplaceId = $request->integer('marketplace_id', 0);

        if ($marketplaceId === 0 && !empty($marketplaces)) {
            $marketplaceId = (int) $marketplaces[0]['id'];
        }

        $brands = [];
        $unmappedCount = 0;

        if ($marketplaceId > 0) {
            $brands = $this->brandMappingService->getBrandsWithMappingStatus($marketplaceId);
            $unmappedCount = $this->brandMappingService->getUnmappedProductCount($marketplaceId);
        }

        // Güven skoru DB'de tutulmaz; satır çizilirken hesaplanır.
        $finder = $this->container->get(\App\Services\Mapping\MappingCandidateFinder::class);

        foreach ($brands as $i => $b) {
            $brands[$i]['confidence'] = empty($b['mapping_id'])
                ? null
                : $finder->scoreBrand(
                    (string) ($b['name'] ?? ''),
                    (string) ($b['marketplace_brand_name'] ?? '')
                );
        }

        $catalogSize = $marketplaceId > 0
            ? $this->container->get(\App\Models\MarketplaceBrand::class)->countByMarketplace($marketplaceId)
            : 0;

        $this->view('brands.mapping', [
            'pageTitle'      => 'Marka Eşleştirme',
            'marketplaces'   => $marketplaces,
            'marketplaceId'  => $marketplaceId,
            'brands'         => $brands,
            'unmappedCount'  => $unmappedCount,
            'catalogSize'    => $catalogSize,
            'confidenceSafe' => \App\Services\Mapping\MappingCandidateFinder::CONFIDENCE_SAFE,
        ]);
    }

    /**
     * Seçilen pazaryerinde eşleşmemiş marka sayısı (AJAX).
     *
     * Toplu atamada onay penceresi "kaç marka etkilenecek" diyebilsin diye;
     * geri alınamayan toplu işlemde kapsam TIKLAMADAN ÖNCE görünmeli.
     */
    public function unmappedCount(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');

        if ($marketplaceId === 0) {
            $this->success(['count' => 0]);
        }

        $this->success([
            'count' => count($this->brandMappingService->getUnmappedBrands($marketplaceId)),
        ]);
    }

    /**
     * TOPLU marka eşleştirme.
     *
     * "Elle genel bir marka yazıp hepsine işlesin" ihtiyacı için: seçili
     * markaları (ya da eşleşmemiş tümünü) tek bir pazaryeri markasına bağlar.
     */
    public function storeBulk(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $mpBrandId     = trim((string) $request->post('marketplace_brand_id', ''));
        $mpBrandName   = trim((string) $request->post('marketplace_brand_name', ''));
        $hepsi         = (string) $request->post('tumu', '') === '1';

        if ($marketplaceId === 0 || $mpBrandId === '') {
            $this->error('Pazaryeri ve hedef marka zorunlu');
        }

        if ($hepsi) {
            // Eşleşmemiş TÜM markalar
            $brandIds = array_map(
                static fn(array $r): int => (int) $r['id'],
                $this->brandMappingService->getUnmappedBrands($marketplaceId)
            );
        } else {
            $ham = json_decode((string) $request->post('brand_ids', ''), true);
            $brandIds = is_array($ham)
                ? array_values(array_filter(array_map('intval', $ham), static fn(int $v): bool => $v > 0))
                : [];
        }

        if ($brandIds === []) {
            $this->error('Eşleştirilecek marka seçilmedi');
        }

        $yapilan = 0;
        $hatali  = 0;

        foreach ($brandIds as $brandId) {
            try {
                $this->mappingModel->saveMapping($brandId, $marketplaceId, $mpBrandId, $mpBrandName);
                $yapilan++;
            } catch (\Throwable) {
                $hatali++;
            }
        }

        $this->success(
            ['mapped' => $yapilan, 'failed' => $hatali],
            "{$yapilan} marka eşleştirildi" . ($hatali > 0 ? ", {$hatali} hata" : '')
        );
    }

    /**
     * Eşleştirme kaydet
     */
    public function store(Request $request): void
    {
        $brandId = $request->integer('brand_id');
        $marketplaceId = $request->integer('marketplace_id');
        $mpBrandId = $request->post('marketplace_brand_id', '');
        $mpBrandName = $request->post('marketplace_brand_name');

        if ($brandId === 0 || $marketplaceId === 0 || $mpBrandId === '') {
            if ($request->isAjax()) {
                $this->error('Eksik alanlar');
            }
            $this->redirectWithError('/brands/mapping', 'Eksik alanlar');
        }

        $this->mappingModel->saveMapping($brandId, $marketplaceId, $mpBrandId, $mpBrandName);

        if ($request->isAjax()) {
            $this->success([], 'Eşleştirme kaydedildi');
        }

        $this->redirectWithSuccess('/brands/mapping?marketplace_id=' . $marketplaceId, 'Marka eşleştirmesi kaydedildi');
    }

    /**
     * Eşleştirme sil
     */
    public function delete(Request $request, string $id): void
    {
        $id = (int) $id;
        $mapping = $this->mappingModel->find($id);
        if ($mapping === false) {
            $this->redirectWithError('/brands/mapping', 'Eşleştirme bulunamadı');
        }

        $marketplaceId = (int) $mapping['marketplace_id'];
        $this->mappingModel->delete($id);

        if ($request->isAjax()) {
            $this->success([], 'Eşleştirme silindi');
        }

        $this->redirectWithSuccess('/brands/mapping?marketplace_id=' . $marketplaceId, 'Eşleştirme silindi');
    }

    /**
     * AJAX: Trendyol marka arama
     */
    public function searchTrendyol(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $query = (string) $request->get('q', '');

        // Slug GEÇİLMEZSE servis Trendyol'a düşer; hangi pazaryeri seçilirse
        // seçilsin Trendyol markaları dönerdi.
        $mp   = $this->marketplaceModel->find($marketplaceId);
        $slug = (string) ($mp['slug'] ?? '');

        $brands = $slug === ''
            ? []
            : $this->brandMappingService->searchMarketplaceBrands($marketplaceId, $query, $slug);

        if ($brands === []) {
            $brands = $this->yerelKatalog($marketplaceId, $query);
        }

        $this->success(['brands' => $brands]);
    }

    /**
     * Canlı arama desteklenmiyorsa çekilmiş katalogda ara.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function yerelKatalog(int $marketplaceId, string $query): array
    {
        if ($marketplaceId === 0 || mb_strlen($query) < 2) {
            return [];
        }

        $satirlar = $this->container->get(\App\Models\MarketplaceBrand::class)
            ->searchByName($query, $marketplaceId);

        return array_map(
            static fn(array $r): array => [
                'id'   => (string) ($r['mp_brand_id'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ],
            array_slice($satirlar, 0, 50)
        );
    }
}
