<?php
/**
 * OzyConnect Panel — SubscriptionInvoice (Faz 8: SaaS abonelik faturası — store-bağlı, tenantScoped=false)
 *
 * DİKKAT: Bu GİB merchant→alıcı `invoices` tablosu DEĞİLDİR. Platform→mağaza abonelik faturası. Önek SUB-.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class SubscriptionInvoice extends BaseModel
{
    protected string $table = 'subscription_invoices';
    protected bool $tenantScoped = false;

    /** SUB-YYYY-nnnn sıralı numara üret (yıl bazlı). */
    public function nextNumber(): string
    {
        $year = date('Y');
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) c FROM subscription_invoices WHERE number LIKE :p",
            ['p' => 'SUB-' . $year . '-%']
        );
        $seq = ((int) ($row['c'] ?? 0)) + 1;
        return sprintf('SUB-%s-%04d', $year, $seq);
    }

    /** Ödenmemiş en güncel fatura (draft/sent/overdue). */
    public function openForStore(int $storeId): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM subscription_invoices WHERE store_id = :s AND status IN ('draft','sent','overdue') ORDER BY id DESC LIMIT 1",
            ['s' => $storeId]
        );
    }

    public function forStore(int $storeId, int $limit = 100): array
    {
        $lim = max(1, min(500, $limit));
        return $this->rawQuery(
            "SELECT * FROM subscription_invoices WHERE store_id = :s ORDER BY id DESC LIMIT {$lim}",
            ['s' => $storeId]
        );
    }

    public function findForStore(int $id, int $storeId): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM subscription_invoices WHERE id = :id AND store_id = :s",
            ['id' => $id, 's' => $storeId]
        );
    }

    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT i.*, st.name AS store_name FROM subscription_invoices i
             LEFT JOIN stores st ON st.id = i.store_id WHERE i.id = :id",
            ['id' => $id]
        );
    }

    /**
     * Tüm abonelik faturaları (global admin listesi) — mağaza adıyla, filtreli.
     * @param array{status?:string,earsiv?:string,store_id?:int,q?:string} $f
     * @return array<int,array<string,mixed>>
     */
    public function adminListAll(array $f = [], int $limit = 500): array
    {
        $where = []; $args = [];
        $status = (string) ($f['status'] ?? '');
        if ($status !== '' && in_array($status, ['draft','sent','paid','overdue','void'], true)) {
            $where[] = 'i.status = :status'; $args['status'] = $status;
        }
        $earsiv = (string) ($f['earsiv'] ?? '');
        if ($earsiv === 'none') {
            $where[] = "(i.earsiv_status IS NULL OR i.earsiv_status = '')";
        } elseif ($earsiv !== '') {
            $where[] = 'i.earsiv_status = :earsiv'; $args['earsiv'] = $earsiv;
        }
        if (!empty($f['store_id'])) {
            $where[] = 'i.store_id = :sid'; $args['sid'] = (int) $f['store_id'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(i.number LIKE :q OR st.name LIKE :q)'; $args['q'] = '%' . $q . '%';
        }
        $sql = "SELECT i.*, st.name AS store_name FROM subscription_invoices i
                LEFT JOIN stores st ON st.id = i.store_id";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $lim = max(1, min(2000, $limit));
        $sql .= " ORDER BY i.id DESC LIMIT {$lim}";
        return $this->rawQuery($sql, $args);
    }

    /** Özet sayaçlar (KPI). @return array{total:int,paid:int,issued:int,pending_earsiv:int} */
    public function adminStats(): array
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) total,
                    SUM(status = 'paid') paid,
                    SUM(earsiv_status = 'issued') issued,
                    SUM(status = 'paid' AND (earsiv_status IS NULL OR earsiv_status NOT IN ('issued','cancelled'))) pending_earsiv
             FROM subscription_invoices"
        );
        return [
            'total'          => (int) ($row['total'] ?? 0),
            'paid'           => (int) ($row['paid'] ?? 0),
            'issued'         => (int) ($row['issued'] ?? 0),
            'pending_earsiv' => (int) ($row['pending_earsiv'] ?? 0),
        ];
    }

    public function markPaid(int $id): void
    {
        $this->db->query("UPDATE subscription_invoices SET status='paid', paid_at=NOW() WHERE id=:id", ['id' => $id]);
    }
}
