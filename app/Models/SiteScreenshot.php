<?php
/** OzyConnect Panel — SiteScreenshot (Faz 9: kurumsal site ekran görüntüleri + hero slider — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class SiteScreenshot extends BaseModel
{
    protected string $table = 'site_screenshots';
    protected bool $tenantScoped = false;

    /** @return array<int,array<string,mixed>> Belirli slot için aktif + sıralı (hero/big/gallery). */
    public function forSlot(string $slot): array
    {
        return $this->rawQuery(
            "SELECT * FROM site_screenshots WHERE slot = :s AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC",
            ['s' => $slot]
        );
    }

    /** @return array<int,array<string,mixed>> Admin: silinmemiş tümü (slot+sıra). */
    public function allAdmin(): array
    {
        return $this->rawQuery(
            "SELECT * FROM site_screenshots WHERE deleted_at IS NULL ORDER BY FIELD(slot,'hero','big','gallery'), sort_order ASC, id ASC"
        );
    }

    public function findOne(int $id): array|false
    {
        return $this->rawQueryOne("SELECT * FROM site_screenshots WHERE id = :id AND deleted_at IS NULL LIMIT 1", ['id' => $id]);
    }

    /** @param array<string,mixed> $d */
    public function createOne(array $d): int
    {
        $this->db->query(
            "INSERT INTO site_screenshots (slot,title,caption,image_path,sort_order,is_active) VALUES (:sl,:t,:c,:ip,:s,:a)",
            ['sl' => $d['slot'], 't' => $d['title'] ?? null, 'c' => $d['caption'] ?? null, 'ip' => $d['image_path'],
             's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1)]
        );
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $d */
    public function updateOne(int $id, array $d): void
    {
        $sets = ['slot=:sl', 'title=:t', 'caption=:c', 'sort_order=:s', 'is_active=:a', 'updated_at=NOW()'];
        $p = ['sl' => $d['slot'], 't' => $d['title'] ?? null, 'c' => $d['caption'] ?? null,
              's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1), 'id' => $id];
        if (!empty($d['image_path'])) { $sets[] = 'image_path=:ip'; $p['ip'] = $d['image_path']; }
        $this->db->query("UPDATE site_screenshots SET " . implode(',', $sets) . " WHERE id=:id", $p);
    }

    public function softDelete(int $id): void
    {
        $this->db->query("UPDATE site_screenshots SET deleted_at = NOW() WHERE id = :id", ['id' => $id]);
    }
}
