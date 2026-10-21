<?php
declare(strict_types=1);

namespace App\Models;

final class Question extends BaseModel
{
    protected string $table = 'questions';

    /**
     * Pazaryeri soru ID'si ile bul
     */
    public function findByMarketplaceQuestionId(int $marketplaceId, string $questionId): array|false
    {
        return $this->findBy([
            'marketplace_id' => $marketplaceId,
            'marketplace_question_id' => $questionId,
        ]);
    }

    /**
     * Eski biçimde (soru numarası olmadan, rakam dışı kimlikle) kaydedilmiş aynı metinli Hepsiburada sorusu.
     * Senkron, bu satırı yeni soru numarasına taşır (mükerrer satır oluşmaz, eski soru cevaplanabilir olur).
     */
    public function findLegacyHepsiburadaMatch(int $marketplaceId, string $questionText): array|false
    {
        $questionText = trim($questionText);
        if ($questionText === '') {
            return false;
        }

        return $this->rawQueryOne(
            "SELECT * FROM `{$this->table}`
              WHERE store_id = :store_id AND marketplace_id = :mp_id
                AND marketplace_question_id NOT REGEXP '^[0-9]+$'
                AND TRIM(question_text) = :question_text
              ORDER BY id ASC
              LIMIT 1",
            ['store_id' => $this->tenantId(), 'mp_id' => $marketplaceId, 'question_text' => $questionText]
        );
    }

    /**
     * Belirli bir tarihten sonra oluşan yeni soru sayısı
     */
    public function getNewCountSince(string $since): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM `{$this->table}` WHERE created_at > :since AND store_id = :store_id",
            ['since' => $since, 'store_id' => $this->tenantId()]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Filtrelenmiş soru listesi (DataTables uyumlu)
     */
    public function getFiltered(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1', 'q.store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        // Faz 2 — Store Entitlement: yalnız mağazanın aktif satın aldığı pazaryeri soruları.
        $where[] = "q.marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = 'q.marketplace_id = :mp_id';
            $params['mp_id'] = (int) $filters['marketplace_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'q.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(q.question_text LIKE :search OR q.product_name LIKE :search2 OR q.customer_name LIKE :search3)';
            $params['search'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'q.question_date >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'q.question_date <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        // Total count
        $countSql = "SELECT COUNT(*) as cnt FROM `{$this->table}` q WHERE {$whereClause}";
        $total = (int) ($this->db->fetchOne($countSql, $params)['cnt'] ?? 0);

        // Data
        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT q.*, m.display_name as marketplace_name, m.slug as marketplace_slug
                    FROM `{$this->table}` q
                    LEFT JOIN `marketplaces` m ON m.id = q.marketplace_id
                    WHERE {$whereClause}
                    ORDER BY q.question_date DESC
                    LIMIT {$perPage} OFFSET {$offset}";

        $data = $this->db->fetchAll($dataSql, $params);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Pazaryeri bazlı soru sayıları
     */
    public function getMarketplaceQuestionCounts(array $filters = []): array
    {
        $where = ['1=1', 'store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        // Faz 2 — Store Entitlement: getFiltered ile tutarlı; yalnız mağazanın aktif satın aldığı pazaryeri soruları sayılır.
        $where[] = "marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent'] = $this->tenantId();

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT marketplace_id, COUNT(*) as cnt 
                FROM `{$this->table}` 
                WHERE {$whereClause}
                GROUP BY marketplace_id";

        $rows = $this->db->fetchAll($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['marketplace_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * Durum bazlı sayılar
     */
    public function getStatusCounts(array $filters = []): array
    {
        $where = ['1=1', 'store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        // Entitlement: getFiltered + getMarketplaceQuestionCounts ile AYNI kapsam (rozet/başlık tutarlılığı).
        $where[] = "marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $params['store_id_ent'] = $this->tenantId();

        if (!empty($filters['marketplace_id'])) {
            $where[] = 'marketplace_id = :mp_id';
            $params['mp_id'] = (int) $filters['marketplace_id'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT status, COUNT(*) as cnt 
                FROM `{$this->table}` 
                WHERE {$whereClause}
                GROUP BY status";

        $rows = $this->db->fetchAll($sql, $params);
        $counts = ['all' => 0, 'waiting' => 0, 'answered' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
            $counts['all'] += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Tek pazaryeri için durum bazlı sayılar (overlay stats)
     */
    public function getStatusCountsByMarketplace(int $marketplaceId): array
    {
        $sql = "SELECT status, COUNT(*) as cnt
                FROM `{$this->table}`
                WHERE marketplace_id = :mp_id AND store_id = :store_id
                GROUP BY status";

        $rows = $this->db->fetchAll($sql, ['mp_id' => $marketplaceId, 'store_id' => $this->tenantId()]);
        $counts = ['all' => 0, 'waiting' => 0, 'answered' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
            $counts['all'] += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Cevap güncelle
     */
    public function answer(int $id, string $answerText): bool
    {
        return $this->update($id, [
            'answer_text' => $answerText,
            'answer_date' => date('Y-m-d H:i:s'),
            'status' => 'answered',
        ]);
    }
}
