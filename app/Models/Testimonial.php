<?php
/** OzyConnect Panel — Testimonial (Faz 8: referanslar — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class Testimonial extends BaseModel
{
    protected string $table = 'testimonials';
    protected bool $tenantScoped = false;

    public function activeOrdered(): array
    {
        return $this->rawQuery(
            "SELECT * FROM testimonials WHERE is_active = 1 AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC"
        );
    }

    public function allAdmin(): array
    {
        return $this->rawQuery("SELECT * FROM testimonials WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC");
    }

    public function findOne(int $id): array|false
    {
        return $this->rawQueryOne("SELECT * FROM testimonials WHERE id = :id AND deleted_at IS NULL LIMIT 1", ['id' => $id]);
    }

    /** @param array<string,mixed> $d */
    public function createOne(array $d): int
    {
        $this->db->query(
            "INSERT INTO testimonials (company,person,role,quote,logo_url,sort_order,is_active)
             VALUES (:co,:pe,:ro,:qu,:lo,:s,:a)",
            ['co' => $d['company'], 'pe' => $d['person'] ?? null, 'ro' => $d['role'] ?? null,
             'qu' => $d['quote'], 'lo' => $d['logo_url'] ?? null,
             's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1)]
        );
        return (int) $this->db->lastInsertId();
    }

    /** logo_url opsiyonel — anahtar yoksa mevcut değer korunur. @param array<string,mixed> $d */
    public function updateOne(int $id, array $d): void
    {
        $params = [
            'co' => $d['company'], 'pe' => $d['person'] ?? null, 'ro' => $d['role'] ?? null,
            'qu' => $d['quote'], 's' => (int) ($d['sort_order'] ?? 0),
            'a' => (int) ($d['is_active'] ?? 1), 'id' => $id,
        ];
        $logoSql = '';
        if (array_key_exists('logo_url', $d)) {
            $logoSql = 'logo_url=:lo,';
            $params['lo'] = $d['logo_url'];
        }
        $this->db->query(
            "UPDATE testimonials SET company=:co,person=:pe,role=:ro,quote=:qu,{$logoSql}sort_order=:s,is_active=:a,updated_at=NOW() WHERE id=:id",
            $params
        );
    }

    public function softDelete(int $id): void
    {
        $this->db->query("UPDATE testimonials SET deleted_at = NOW() WHERE id = :id", ['id' => $id]);
    }
}
