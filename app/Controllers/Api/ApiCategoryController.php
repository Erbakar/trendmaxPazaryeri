<?php
/**
 * OzyConnect Panel — API Category Controller
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
use App\Models\Category;

final class ApiCategoryController extends ApiBaseController
{
    private readonly Category $categoryModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->categoryModel = $container->get(Category::class);
    }

    /**
     * GET /api/v1/categories — Kategori listesi (düz liste)
     */
    public function list(Request $request): void
    {
        $this->requirePermission('categories:read');

        $params = $this->paginationParams($request);
        $conditions = [];

        $isActive = $request->query('is_active');
        $parentId = $request->query('parent_id');

        if ($isActive !== null) {
            $conditions['is_active'] = (int) $isActive;
        }
        if ($parentId !== null) {
            $conditions['parent_id'] = $parentId === '0' ? null : (int) $parentId;
        }

        $result = $this->categoryModel->paginate(
            $params['page'],
            $params['per_page'],
            $conditions,
            'name ASC'
        );

        $this->apiPaginated(
            array_map([$this, 'formatCategory'], $result['data']),
            $result['page'],
            $result['perPage'],
            $result['total']
        );
    }

    /**
     * GET /api/v1/categories/tree — Kategori ağacı (hiyerarşik)
     */
    public function tree(Request $request): void
    {
        $this->requirePermission('categories:read');

        $tree = $this->categoryModel->getTree();

        $this->apiSuccess([
            'tree' => $tree,
        ]);
    }

    /**
     * GET /api/v1/categories/{id} — Kategori detayı
     */
    public function show(Request $request, string $id): void
    {
        $this->requirePermission('categories:read');

        $category = $this->categoryModel->find((int) $id);
        if ($category === false) {
            $this->apiNotFound('Kategori bulunamadı');
        }

        // Alt kategorileri de getir
        $children = $this->categoryModel->getChildren((int) $id);

        $formatted = $this->formatCategory($category);
        $formatted['children'] = array_map([$this, 'formatCategory'], $children);

        $this->apiSuccess($formatted);
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function formatCategory(array $c): array
    {
        return [
            'id'        => (int) $c['id'],
            'name'      => $c['name'] ?? '',
            'slug'      => $c['slug'] ?? '',
            'parent_id' => isset($c['parent_id']) ? (int) $c['parent_id'] : null,
            'full_path' => $c['full_path'] ?? null,
            'is_active' => (bool) ($c['is_active'] ?? true),
        ];
    }
}
