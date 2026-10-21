<?php
/** OzyConnect Panel — SiteModule (Faz 9: kurumsal site "Modüller" kartları — GLOBAL, admin-düzenlenebilir). */
declare(strict_types=1);

namespace App\Models;

final class SiteModule extends BaseModel
{
    protected string $table = 'site_modules';
    protected bool $tenantScoped = false;

    /** @return array<int,array<string,mixed>> Public: aktif + silinmemiş, sıralı. */
    public function activeOrdered(): array
    {
        return $this->rawQuery(
            "SELECT * FROM site_modules WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC"
        );
    }

    /** @return array<int,array<string,mixed>> Admin: silinmemiş tümü. */
    public function allAdmin(): array
    {
        return $this->rawQuery("SELECT * FROM site_modules WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC");
    }

    public function findOne(int $id): array|false
    {
        return $this->rawQueryOne("SELECT * FROM site_modules WHERE id = :id AND deleted_at IS NULL LIMIT 1", ['id' => $id]);
    }

    /** @param array<string,mixed> $d */
    public function createOne(array $d): int
    {
        $this->db->query(
            "INSERT INTO site_modules (icon,color,title,description,sort_order,is_active) VALUES (:i,:c,:t,:d,:s,:a)",
            ['i' => $d['icon'], 'c' => $d['color'], 't' => $d['title'], 'd' => $d['description'] ?? null,
             's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1)]
        );
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $d */
    public function updateOne(int $id, array $d): void
    {
        $this->db->query(
            "UPDATE site_modules SET icon=:i,color=:c,title=:t,description=:d,sort_order=:s,is_active=:a,updated_at=NOW() WHERE id=:id",
            ['i' => $d['icon'], 'c' => $d['color'], 't' => $d['title'], 'd' => $d['description'] ?? null,
             's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1), 'id' => $id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->db->query("UPDATE site_modules SET deleted_at = NOW() WHERE id = :id", ['id' => $id]);
    }
}
