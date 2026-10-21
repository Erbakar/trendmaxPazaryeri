<?php
/**
 * OzyConnect Panel — Category Model
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

namespace App\Models;

final class Category extends BaseModel
{
    protected string $table = 'categories';

    /**
     * Kategori ağacını getir (hiyerarşik)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTree(): array
    {
        $rows = $this->all([], 'sort_order ASC, name ASC');
        return $this->buildTree($rows);
    }

    /**
     * Düz liste (select dropdown için — girinti ile)
     *
     * @return array<int, array{id: int, name: string, depth: int}>
     */
    public function getFlatTree(): array
    {
        $rows = $this->all([], 'sort_order ASC, name ASC');
        $tree = $this->buildTree($rows);
        $flat = [];
        $this->flattenTree($tree, $flat, 0);
        return $flat;
    }

    /**
     * Full path hesapla ve güncelle
     */
    public function updateFullPath(int $categoryId): void
    {
        $path = [];
        $current = $this->find($categoryId);

        while ($current !== false) {
            array_unshift($path, $current['name']);
            $current = $current['parent_id'] !== null
                ? $this->find((int) $current['parent_id'])
                : false;
        }

        $fullPath = implode(' > ', $path);
        $this->update($categoryId, ['full_path' => $fullPath]);
    }

    /**
     * Alt kategorileri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getChildren(int $parentId): array
    {
        return $this->all(['parent_id' => $parentId], 'sort_order ASC, name ASC');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $items, ?int $parentId = null): array
    {
        $branch = [];
        foreach ($items as $item) {
            $itemParent = $item['parent_id'] !== null ? (int) $item['parent_id'] : null;
            if ($itemParent === $parentId) {
                $item['children'] = $this->buildTree($items, (int) $item['id']);
                $branch[] = $item;
            }
        }
        return $branch;
    }

    /**
     * @param array<int, array<string, mixed>> $tree
     * @param array<int, array<string, mixed>> $flat
     */
    private function flattenTree(array $tree, array &$flat, int $depth): void
    {
        foreach ($tree as $item) {
            $flat[] = [
                'id'    => (int) $item['id'],
                'name'  => str_repeat('— ', $depth) . $item['name'],
                'depth' => $depth,
            ];
            if (!empty($item['children'])) {
                $this->flattenTree($item['children'], $flat, $depth + 1);
            }
        }
    }

    /**
     * Kategorideki ürün sayısını getir
     */
    public function getProductCount(int $categoryId): int
    {
        $result = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products WHERE category_id = :cid AND store_id = :store_id",
            ['cid' => $categoryId, 'store_id' => $this->tenantId()]
        );
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * İsimle bul (case-insensitive)
     */
    public function findByNameInsensitive(string $name): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM categories WHERE LOWER(name) = LOWER(:name) AND store_id = :store_id LIMIT 1",
            ['name' => $name, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Ağaç yapısıyla birlikte ürün sayıları ve mapping durumu
     */
    public function getTreeWithCounts(): array
    {
        $rows = $this->rawQuery(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND store_id = :store_id2) as product_count,
                    (SELECT COUNT(*) FROM categories WHERE parent_id = c.id AND store_id = :store_id3) as child_count
             FROM categories c
             WHERE c.store_id = :store_id
             ORDER BY c.sort_order ASC, c.name ASC",
            [
                'store_id'  => $this->tenantId(),
                'store_id2' => $this->tenantId(),
                'store_id3' => $this->tenantId(),
            ]
        );
        return $this->buildTree($rows);
    }

    /**
     * Sadece kök kategorileri getir (ilk seviye)
     */
    public function getRootCategories(): array
    {
        return $this->rawQuery(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND store_id = :store_id2) as product_count,
                    (SELECT COUNT(*) FROM categories WHERE parent_id = c.id AND store_id = :store_id3) as child_count
             FROM categories c
             WHERE c.parent_id IS NULL AND c.store_id = :store_id
             ORDER BY c.sort_order ASC, c.name ASC",
            [
                'store_id'  => $this->tenantId(),
                'store_id2' => $this->tenantId(),
                'store_id3' => $this->tenantId(),
            ]
        );
    }

    /**
     * Belirli parent_id'ye göre alt kategorileri getir (AJAX lazy load)
     */
    public function getChildrenWithCounts(int $parentId): array
    {
        return $this->rawQuery(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND store_id = :store_id2) as product_count,
                    (SELECT COUNT(*) FROM categories WHERE parent_id = c.id AND store_id = :store_id3) as child_count
             FROM categories c
             WHERE c.parent_id = :pid AND c.store_id = :store_id
             ORDER BY c.sort_order ASC, c.name ASC",
            [
                'pid'       => $parentId,
                'store_id'  => $this->tenantId(),
                'store_id2' => $this->tenantId(),
                'store_id3' => $this->tenantId(),
            ]
        );
    }

    /**
     * Arama ile tüm kategorileri getir (filtreleme için)
     */
    public function searchCategories(string $query): array
    {
        return $this->rawQuery(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND store_id = :store_id2) as product_count,
                    (SELECT COUNT(*) FROM categories WHERE parent_id = c.id AND store_id = :store_id3) as child_count
             FROM categories c
             WHERE (c.name LIKE :q OR c.full_path LIKE :q2) AND c.store_id = :store_id
             ORDER BY c.name ASC
             LIMIT 100",
            [
                'q'         => "%{$query}%",
                'q2'        => "%{$query}%",
                'store_id'  => $this->tenantId(),
                'store_id2' => $this->tenantId(),
                'store_id3' => $this->tenantId(),
            ]
        );
    }
}

