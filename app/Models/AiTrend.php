<?php
/**
 * OzyConnect Panel — AiTrend Model (Modül #7: AI Trend Merkezi)
 *
 * Günlük cron ile önceden hesaplanan trend kayıtları. Mağaza bazlı (tenant-scoped).
 * Aynı gün/kind için idempotent upsert (UNIQUE store_id+trend_date+kind).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class AiTrend extends BaseModel
{
    protected string $table = 'ai_trends';

    /** Bir gün/kind için trend kaydını yaz/güncelle (idempotent). */
    public function upsert(string $date, string $kind, array $payload): void
    {
        $sql = "INSERT INTO `ai_trends` (`store_id`,`trend_date`,`kind`,`payload_json`)
                VALUES (:sid,:d,:k,:p)
                ON DUPLICATE KEY UPDATE `payload_json` = VALUES(`payload_json`), `created_at` = CURRENT_TIMESTAMP";
        $this->db->query($sql, [
            'sid' => $this->tenantId(),
            'd'   => $date,
            'k'   => $kind,
            'p'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Mağazanın EN SON trend gününe ait tüm kayıtları kind→payload olarak getir.
     * @return array{date:?string,trends:array<string,array<string,mixed>>}
     */
    public function latestForStore(): array
    {
        $sid = $this->tenantId();
        $row = $this->db->fetchOne("SELECT MAX(`trend_date`) AS d FROM `ai_trends` WHERE `store_id` = :sid", ['sid' => $sid]);
        $date = $row['d'] ?? null;
        if ($date === null) {
            return ['date' => null, 'trends' => []];
        }
        $rows = $this->db->fetchAll(
            "SELECT `kind`,`payload_json` FROM `ai_trends` WHERE `store_id` = :sid AND `trend_date` = :d",
            ['sid' => $sid, 'd' => $date]
        );
        $trends = [];
        foreach ($rows as $r) {
            $trends[(string) $r['kind']] = json_decode((string) $r['payload_json'], true) ?: [];
        }
        return ['date' => (string) $date, 'trends' => $trends];
    }
}
