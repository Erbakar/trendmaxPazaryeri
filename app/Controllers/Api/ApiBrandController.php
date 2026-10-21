<?php
/**
 * OzyConnect Panel — API Brand Controller
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

namespace App\Controllers\Api;

use App\Core\Container;
use App\Core\Request;
use App\Models\Brand;

final class ApiBrandController extends ApiBaseController
{
    private readonly Brand $brandModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->brandModel = $container->get(Brand::class);
    }

    /**
     * GET /api/v1/brands — Marka listesi
     */
    public function list(Request $request): void
    {
        $this->requirePermission('brands:read');

        $params = $this->paginationParams($request);
        $conditions = [];

        $isActive = $request->query('is_active');
        $search = trim($request->query('search', ''));

        if ($isActive !== null) {
            $conditions['is_active'] = (int) $isActive;
        }

        if ($search !== '') {
            $where = "name LIKE :search";
            $binds = ['search' => "%{$search}%"];

            if ($isActive !== null) {
                $where .= " AND is_active = :active";
                $binds['active'] = (int) $isActive;
            }

            // Mağazaya kapalı model metodu (eski kod protected rawQuery çağırıyordu
            // ve sorguda store_id yoktu → tüm bayilerin markaları görünüyordu)
            $sonuc = $this->brandModel->apiList(
                $search,
                $isActive !== null ? (int) $isActive : null,
                (int) $params['page'],
                (int) $params['per_page']
            );

            $this->apiPaginated(
                array_map([$this, 'formatBrand'], $sonuc['rows']),
                $params['page'],
                $params['per_page'],
                $sonuc['total']
            );
            return;
        }

        $result = $this->brandModel->paginate(
            $params['page'],
            $params['per_page'],
            $conditions,
            'name ASC'
        );

        $this->apiPaginated(
            array_map([$this, 'formatBrand'], $result['data']),
            $result['page'],
            $result['perPage'],
            $result['total']
        );
    }

    /**
     * GET /api/v1/brands/{id} — Marka detayı
     */
    public function show(Request $request, string $id): void
    {
        $this->requirePermission('brands:read');

        $brand = $this->brandModel->find((int) $id);
        if ($brand === false) {
            $this->apiNotFound('Marka bulunamadı');
        }

        $this->apiSuccess($this->formatBrand($brand));
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function formatBrand(array $b): array
    {
        return [
            'id'        => (int) $b['id'],
            'name'      => $b['name'] ?? '',
            'slug'      => $b['slug'] ?? '',
            'is_active' => (bool) ($b['is_active'] ?? true),
        ];
    }
}
