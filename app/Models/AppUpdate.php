<?php
/**
 * OzyConnect Panel — AppUpdate (Güncelleme / changelog)
 *
 * GLOBAL tablo (tenantScoped=false): platform admini yayınlar, tüm mağazalar görür.
 * Merchant metodları yalnız yayınlanmışları (is_published=1) döndürür; admin* metodları taslaklar dahil.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class AppUpdate extends BaseModel
{
    protected string $table = 'app_updates';
    protected bool $tenantScoped = false;

    // ─── MERCHANT (yalnız yayınlanmış) ─────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function published(int $limit = 50): array
    {
        $lim = max(1, min(200, $limit));
        return $this->rawQuery(
            "SELECT * FROM app_updates WHERE is_published = 1
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT {$lim}"
        );
    }

    public function latestPublishedId(): int
    {
        $r = $this->rawQueryOne("SELECT MAX(id) m FROM app_updates WHERE is_published = 1");
        return (int) ($r['m'] ?? 0);
    }

    /** Belirtilen "son görülen" id'den sonraki yayınlanmış güncelleme sayısı. */
    public function unreadCount(int $lastSeenId): int
    {
        $r = $this->rawQueryOne(
            "SELECT COUNT(*) c FROM app_updates WHERE is_published = 1 AND id > :s",
            ['s' => $lastSeenId]
        );
        return (int) ($r['c'] ?? 0);
    }

    // ─── ADMIN (taslaklar dahil) ───────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function adminAll(int $limit = 200): array
    {
        $lim = max(1, min(500, $limit));
        return $this->rawQuery("SELECT * FROM app_updates ORDER BY id DESC LIMIT {$lim}");
    }

    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne("SELECT * FROM app_updates WHERE id = :id", ['id' => $id]);
    }

    /** Yayın durumunu çevir (taslak<->yayında); ilk yayında published_at set edilir. */
    public function togglePublish(int $id): void
    {
        $cur = $this->adminFind($id);
        if ($cur === false) {
            return;
        }
        $new = ((int) $cur['is_published'] === 1) ? 0 : 1;
        $pubAt = ($new === 1 && empty($cur['published_at'])) ? date('Y-m-d H:i:s') : ($cur['published_at'] ?? null);
        $this->db->query(
            "UPDATE app_updates SET is_published = :p, published_at = :pa WHERE id = :id",
            ['p' => $new, 'pa' => $pubAt, 'id' => $id]
        );
    }
}
