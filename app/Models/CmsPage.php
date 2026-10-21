<?php
/** OzyConnect Panel — CmsPage (Faz 8: kurumsal sayfa içerikleri — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class CmsPage extends BaseModel
{
    protected string $table = 'cms_pages';
    protected bool $tenantScoped = false;

    public function findActiveBySlug(string $slug): array|false
    {
        return $this->rawQueryOne("SELECT * FROM cms_pages WHERE slug = :s AND is_active = 1", ['s' => $slug]);
    }

    /** sections_json çözülmüş halde (yoksa boş dizi). */
    public function sections(string $slug): array
    {
        $row = $this->findActiveBySlug($slug);
        if ($row === false || empty($row['sections_json'])) {
            return [];
        }
        $data = json_decode((string) $row['sections_json'], true);
        return is_array($data) ? $data : [];
    }

    public function allAdmin(): array
    {
        return $this->rawQuery("SELECT * FROM cms_pages ORDER BY sort_order ASC, id ASC");
    }
}
