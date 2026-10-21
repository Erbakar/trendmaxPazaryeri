<?php
/**
 * OzyConnect — StoreDocument (mağaza KYC belgeleri).
 * Merchant tarafı TENANT-SCOPED (yalnız kendi mağazası); admin* metodları cross-store HAM sorgu.
 */
declare(strict_types=1);

namespace App\Models;

final class StoreDocument extends BaseModel
{
    protected string $table = 'store_documents';
    // tenantScoped = true (varsayılan) — create() store_id'yi aktif bağlamdan ZORLAR (anti-spoof).

    /**
     * Merchant nav rozeti — aktif mağazanın "iş var" belgeleri (bekleyen onay + reddedilen, yeniden yüklenmeli).
     * Onaylanmış belgeler sayılmaz; rozet yalnız merchant'ın aksiyon alması gereken durumları gösterir.
     */
    public function currentStorePendingCount(): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) c FROM store_documents WHERE store_id = :sid AND status IN ('pending','rejected')",
            ['sid' => $this->tenantId()]
        );
        return (int) (($row['c'] ?? 0));
    }

    /** Merchant: aktif mağazanın belgeleri (tür bilgisiyle). */
    public function forCurrentStore(): array
    {
        return $this->rawQuery(
            "SELECT d.*, t.label AS type_label, t.`key` AS type_key
             FROM store_documents d LEFT JOIN store_document_types t ON t.id = d.doc_type_id
             WHERE d.store_id = :sid ORDER BY d.id DESC",
            ['sid' => $this->tenantId()]
        );
    }

    /** Merchant: belgeyi SAHİPLİK kontrolüyle getir (indirme — cross-tenant red). */
    public function findOwned(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM store_documents WHERE id = :id AND store_id = :sid LIMIT 1",
            ['id' => $id, 'sid' => $this->tenantId()]
        );
    }

    // ─── admin (cross-store HAM) ───────────────────────────────
    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT d.*, t.label AS type_label, s.name AS store_name
             FROM store_documents d
             LEFT JOIN store_document_types t ON t.id = d.doc_type_id
             LEFT JOIN stores s ON s.id = d.store_id
             WHERE d.id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    public function adminList(?string $status = null, int $limit = 200): array
    {
        $lim = max(1, min(500, $limit));
        $where = '';
        $params = [];
        if ($status !== null && $status !== '') { $where = 'WHERE d.status = :st'; $params['st'] = $status; }
        return $this->rawQuery(
            "SELECT d.*, t.label AS type_label, s.name AS store_name
             FROM store_documents d
             LEFT JOIN store_document_types t ON t.id = d.doc_type_id
             LEFT JOIN stores s ON s.id = d.store_id
             {$where} ORDER BY (d.status='pending') DESC, d.id DESC LIMIT {$lim}",
            $params
        );
    }

    /** @return array<string,int> */
    public function adminCounts(): array
    {
        $rows = $this->rawQuery("SELECT status, COUNT(*) c FROM store_documents GROUP BY status");
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; $out['all'] += (int) $r['c']; }
        return $out;
    }

    public function adminSetStatus(int $id, string $status, ?int $adminId, ?string $note): void
    {
        $st = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';
        $this->db->query(
            "UPDATE store_documents SET status = :st, reviewed_by_admin = :by, reviewed_at = NOW(),
                    note = :nt WHERE id = :id",
            ['st' => $st, 'by' => $adminId, 'nt' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, 'id' => $id]
        );
    }

    /** Bir mağazanın ONAYLANMIŞ benzersiz tür id'leri (doğrulama hesabı). @return int[] */
    public function approvedTypeIds(int $storeId): array
    {
        $rows = $this->rawQuery(
            "SELECT DISTINCT doc_type_id FROM store_documents WHERE store_id = :sid AND status = 'approved' AND doc_type_id IS NOT NULL",
            ['sid' => $storeId]
        );
        return array_map(static fn($r) => (int) $r['doc_type_id'], $rows);
    }
}
