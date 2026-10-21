<?php
/**
 * OzyConnect Panel — UpdateRead (Güncelleme okuma takibi)
 *
 * Kişi-başı (user_id) "son görülen güncelleme id". Nav'daki okunmamış rozeti bundan hesaplanır.
 * GLOBAL (tenantScoped=false) — kullanıcıya bağlı, mağazaya değil.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class UpdateRead extends BaseModel
{
    protected string $table = 'update_reads';
    protected bool $tenantScoped = false;
    protected string $primaryKey = 'user_id';

    public function lastSeen(int $userId): int
    {
        $r = $this->rawQueryOne("SELECT last_seen_id FROM update_reads WHERE user_id = :u", ['u' => $userId]);
        return (int) ($r['last_seen_id'] ?? 0);
    }

    public function markSeen(int $userId, int $lastId): void
    {
        $this->db->query(
            "INSERT INTO update_reads (user_id, last_seen_id, seen_at) VALUES (:u, :l, NOW())
             ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, :l2), seen_at = NOW()",
            ['u' => $userId, 'l' => $lastId, 'l2' => $lastId]
        );
    }
}
