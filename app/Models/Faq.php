<?php
/** OzyConnect Panel — Faq (Faz 8: SSS — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class Faq extends BaseModel
{
    protected string $table = 'faqs';
    protected bool $tenantScoped = false;

    public function activeOrdered(): array
    {
        return $this->rawQuery(
            "SELECT * FROM faqs WHERE is_active = 1 AND deleted_at IS NULL ORDER BY category ASC, sort_order ASC, id ASC"
        );
    }

    public function allAdmin(): array
    {
        return $this->rawQuery("SELECT * FROM faqs WHERE deleted_at IS NULL ORDER BY category ASC, sort_order ASC, id ASC");
    }

    public function findOne(int $id): array|false
    {
        return $this->rawQueryOne("SELECT * FROM faqs WHERE id = :id AND deleted_at IS NULL LIMIT 1", ['id' => $id]);
    }

    /** @param array<string,mixed> $d */
    public function createOne(array $d): int
    {
        $this->db->query(
            "INSERT INTO faqs (category,question,answer,sort_order,is_active) VALUES (:c,:q,:an,:s,:a)",
            ['c' => $d['category'] ?? 'genel', 'q' => $d['question'], 'an' => $d['answer'],
             's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1)]
        );
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $d */
    public function updateOne(int $id, array $d): void
    {
        $this->db->query(
            "UPDATE faqs SET category=:c,question=:q,answer=:an,sort_order=:s,is_active=:a,updated_at=NOW() WHERE id=:id",
            ['c' => $d['category'] ?? 'genel', 'q' => $d['question'], 'an' => $d['answer'],
             's' => (int) ($d['sort_order'] ?? 0), 'a' => (int) ($d['is_active'] ?? 1), 'id' => $id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->db->query("UPDATE faqs SET deleted_at = NOW() WHERE id = :id", ['id' => $id]);
    }
}
