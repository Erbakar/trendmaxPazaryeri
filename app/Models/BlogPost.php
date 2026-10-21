<?php
/** OzyConnect Panel — BlogPost (Faz 8: blog — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class BlogPost extends BaseModel
{
    protected string $table = 'blog_posts';
    protected bool $tenantScoped = false;

    public function publishedList(int $limit = 20): array
    {
        $lim = max(1, min(100, $limit));
        return $this->rawQuery(
            "SELECT * FROM blog_posts
             WHERE status = 'published' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT {$lim}"
        );
    }

    public function findPublishedBySlug(string $slug): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM blog_posts WHERE slug = :s AND status = 'published' AND deleted_at IS NULL",
            ['s' => $slug]
        );
    }

    public function allAdmin(): array
    {
        return $this->rawQuery("SELECT * FROM blog_posts WHERE deleted_at IS NULL ORDER BY id DESC");
    }

    public function findOne(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM blog_posts WHERE id = :id AND deleted_at IS NULL LIMIT 1",
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $d */
    public function createOne(array $d): int
    {
        $this->db->query(
            "INSERT INTO blog_posts (slug,title,excerpt,body,cover_url,status,published_at,author)
             VALUES (:slug,:title,:excerpt,:body,:cover_url,:status,:published_at,:author)",
            [
                'slug'         => $d['slug'],
                'title'        => $d['title'],
                'excerpt'      => $d['excerpt'] ?? null,
                'body'         => $d['body'] ?? null,
                'cover_url'    => $d['cover_url'] ?? null,
                'status'       => $d['status'] ?? 'draft',
                'published_at' => $d['published_at'] ?? null,
                'author'       => $d['author'] ?? null,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Kayıt güncelle. cover_url anahtarı YOKSA mevcut kapak korunur (yalnız yeni dosya yüklendiyse gelir).
     *
     * @param array<string,mixed> $d
     */
    public function updateOne(int $id, array $d): void
    {
        $set = [
            'slug=:slug', 'title=:title', 'excerpt=:excerpt', 'body=:body',
            'status=:status', 'published_at=:published_at', 'author=:author', 'updated_at=NOW()',
        ];
        $params = [
            'slug'         => $d['slug'],
            'title'        => $d['title'],
            'excerpt'      => $d['excerpt'] ?? null,
            'body'         => $d['body'] ?? null,
            'status'       => $d['status'] ?? 'draft',
            'published_at' => $d['published_at'] ?? null,
            'author'       => $d['author'] ?? null,
            'id'           => $id,
        ];
        if (array_key_exists('cover_url', $d)) {
            $set[] = 'cover_url=:cover_url';
            $params['cover_url'] = $d['cover_url'];
        }
        $this->db->query(
            "UPDATE blog_posts SET " . implode(',', $set) . " WHERE id=:id",
            $params
        );
    }

    public function softDelete(int $id): void
    {
        $this->db->query("UPDATE blog_posts SET deleted_at = NOW() WHERE id = :id", ['id' => $id]);
    }

    /** Slug benzersiz mi? Düzenlemede kendi kaydını hariç tut ($excludeId). */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM blog_posts WHERE slug = :slug";
        $params = ['slug' => $slug];
        if ($excludeId !== null) {
            $sql .= " AND id <> :id";
            $params['id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        return $this->rawQueryOne($sql, $params) !== false;
    }
}
