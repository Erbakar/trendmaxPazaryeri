<?php
/** OzyConnect Panel — Lead (Faz 8: demo/iletişim talepleri — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class Lead extends BaseModel
{
    protected string $table = 'leads';
    protected bool $tenantScoped = false;

    /** @param array<string,mixed> $d */
    public function createLead(array $d): int
    {
        return $this->create([
            'type'       => in_array($d['type'] ?? '', ['demo', 'contact'], true) ? $d['type'] : 'contact',
            'name'       => mb_substr((string) ($d['name'] ?? ''), 0, 150),
            'company'    => isset($d['company']) ? mb_substr((string) $d['company'], 0, 200) : null,
            'email'      => mb_substr((string) ($d['email'] ?? ''), 0, 200),
            'phone'      => isset($d['phone']) ? mb_substr((string) $d['phone'], 0, 40) : null,
            'message'    => isset($d['message']) ? mb_substr((string) $d['message'], 0, 4000) : null,
            'status'     => 'new',
            'source'     => isset($d['source']) ? mb_substr((string) $d['source'], 0, 80) : null,
            'ip'         => isset($d['ip']) ? mb_substr((string) $d['ip'], 0, 45) : null,
            'user_agent' => isset($d['user_agent']) ? mb_substr((string) $d['user_agent'], 0, 255) : null,
        ]);
    }

    public function recent(?string $type = null, int $limit = 100): array
    {
        $lim = max(1, min(500, $limit));
        if ($type !== null) {
            return $this->rawQuery("SELECT * FROM leads WHERE type = :t ORDER BY id DESC LIMIT {$lim}", ['t' => $type]);
        }
        return $this->rawQuery("SELECT * FROM leads ORDER BY id DESC LIMIT {$lim}");
    }

    /**
     * Admin gelen kutusu listesi — type ve status filtreli, id DESC.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminList(?string $type = null, ?string $status = null, int $limit = 300): array
    {
        $lim = max(1, min(1000, $limit));
        $where = [];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = :t';
            $params['t'] = $type;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = :st';
            $params['st'] = $status;
        }
        $sql = 'SELECT * FROM leads';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY id DESC LIMIT {$lim}";
        return $this->rawQuery($sql, $params);
    }

    /**
     * Durum bazlı sayılar — new/contacted/closed + all.
     *
     * @return array{new:int,contacted:int,closed:int,all:int}
     */
    public function statusCounts(): array
    {
        $rows = $this->rawQuery("SELECT status, COUNT(*) AS cnt FROM leads GROUP BY status");
        $out = ['new' => 0, 'contacted' => 0, 'closed' => 0, 'all' => 0];
        foreach ($rows as $r) {
            $s = (string) ($r['status'] ?? '');
            $c = (int) ($r['cnt'] ?? 0);
            if (isset($out[$s])) {
                $out[$s] = $c;
            }
            $out['all'] += $c;
        }
        return $out;
    }

    /** Durum güncelle — status ∈ new|contacted|closed. */
    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            return;
        }
        $this->db->query("UPDATE leads SET status = :st WHERE id = :id", ['st' => $status, 'id' => $id]);
    }

    /** Gerçek DELETE — leads geçici kayıtlardır (soft-delete yok). */
    public function deleteOne(int $id): void
    {
        $this->db->query("DELETE FROM leads WHERE id = :id", ['id' => $id]);
    }
}
