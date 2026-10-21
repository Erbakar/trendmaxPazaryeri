<?php
/**
 * OzyConnect Panel — SupportTicket (Destek talepleri)
 *
 * MERCHANT metodları tenant-scoped (BaseModel store_id'yi zorlar — mağaza yalnız kendi
 * taleplerini görür/oluşturur). ADMIN metodları (admin* önekli) cross-store ham sorgudur;
 * YALNIZ admin controller'dan (AdminAuthMiddleware arkasında) çağrılır.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class SupportTicket extends BaseModel
{
    protected string $table = 'support_tickets';

    // ─── MERCHANT (tenant-scoped) ──────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function forStore(?string $status = null): array
    {
        $cond = ($status !== null && $status !== '') ? ['status' => $status] : [];
        return $this->all($cond, 'COALESCE(last_reply_at, created_at) DESC');
    }

    /** @return array{all:int,open:int,answered:int,closed:int} */
    public function statusCountsForStore(): array
    {
        $rows = $this->rawQuery(
            "SELECT status, COUNT(*) c FROM support_tickets WHERE store_id = :s GROUP BY status",
            ['s' => $this->tenantId()]
        );
        $out = ['all' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
        foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; $out['all'] += (int) $r['c']; }
        return $out;
    }

    /** Merchant yanıtı sonrası ticket meta (store-scoped update — başka store'a dokunamaz). */
    public function markReply(int $id, string $by, string $status): bool
    {
        return $this->update($id, [
            'status'        => $status,
            'last_reply_at' => date('Y-m-d H:i:s'),
            'last_reply_by' => $by,
        ]);
    }

    // ─── ADMIN (cross-store; tenant scope YOK) ─────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function adminList(?string $status = null, string $q = '', int $limit = 200): array
    {
        $where = ['1=1'];
        $p = [];
        if ($status !== null && $status !== '') { $where[] = 't.status = :st'; $p['st'] = $status; }
        if ($q !== '') { $where[] = '(t.subject LIKE :q OR s.name LIKE :q2)'; $p['q'] = "%{$q}%"; $p['q2'] = "%{$q}%"; }
        $w = implode(' AND ', $where);
        $lim = max(1, min(500, $limit));
        return $this->rawQuery(
            "SELECT t.*, s.name AS store_name,
                    (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id) AS msg_count
             FROM support_tickets t
             LEFT JOIN stores s ON s.id = t.store_id
             WHERE {$w}
             ORDER BY COALESCE(t.last_reply_at, t.created_at) DESC
             LIMIT {$lim}",
            $p
        );
    }

    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT t.*, s.name AS store_name
             FROM support_tickets t LEFT JOIN stores s ON s.id = t.store_id
             WHERE t.id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /** @return array{all:int,open:int,answered:int,closed:int} */
    public function adminStatusCounts(): array
    {
        $rows = $this->rawQuery("SELECT status, COUNT(*) c FROM support_tickets GROUP BY status");
        $out = ['all' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
        foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; $out['all'] += (int) $r['c']; }
        return $out;
    }

    /** Faz 8 — açık + kritik öncelikli ticket sayısı (admin SaaS dashboard widget). */
    public function adminOpenCriticalCount(): int
    {
        $r = $this->rawQueryOne("SELECT COUNT(*) c FROM support_tickets WHERE status = 'open' AND priority = 'critical'");
        return (int) ($r['c'] ?? 0);
    }

    public function adminSetStatus(int $id, string $status): void
    {
        $this->db->query("UPDATE support_tickets SET status = :st WHERE id = :id", ['st' => $status, 'id' => $id]);
    }

    /** Admin yanıtı sonrası: 'answered' + son yanıt meta. */
    public function adminMarkReply(int $id): void
    {
        $this->db->query(
            "UPDATE support_tickets SET status = 'answered', last_reply_at = NOW(), last_reply_by = 'admin' WHERE id = :id",
            ['id' => $id]
        );
    }
}
