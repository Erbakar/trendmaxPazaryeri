<?php
/**
 * OzyConnect Panel — SyncLog Model
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Models;

final class SyncLog extends BaseModel
{
    protected string $table = 'sync_logs';

    /**
     * Sync başlat — log kaydı oluştur
     */
    public function start(int $marketplaceId, string $syncType, string $triggeredBy = 'manual'): int
    {
        return $this->create([
            'marketplace_id' => $marketplaceId,
            'sync_type'      => $syncType,
            'status'         => 'running',
            'triggered_by'   => $triggeredBy,
            'started_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Sync tamamla — sonuçları güncelle
     *
     * @param array<int, array<string, mixed>> $errors
     */
    public function finish(
        int $logId,
        string $status,
        int $fetched,
        int $processed,
        int $failed = 0,
        array $errors = []
    ): bool {
        $startRow = $this->find($logId);
        $durationMs = 0;

        if ($startRow !== false && $startRow['started_at'] !== null) {
            $startTime = strtotime($startRow['started_at']);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        }

        $data = [
            'status'            => $status,
            'records_fetched'   => $fetched,
            'records_processed' => $processed,
            'records_failed'    => $failed,
            'finished_at'       => date('Y-m-d H:i:s'),
            'duration_ms'       => $durationMs,
        ];

        if (!empty($errors)) {
            $data['error_details'] = json_encode($errors, JSON_UNESCAPED_UNICODE);
        }

        return $this->update($logId, $data);
    }

    /**
     * Son sync log'larını getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 50, ?int $marketplaceId = null): array
    {
        $where = 'sl.store_id = :store_id';
        $params = ['limit' => $limit, 'store_id' => $this->tenantId()];

        if ($marketplaceId !== null) {
            $where .= ' AND sl.marketplace_id = :mid';
            $params['mid'] = $marketplaceId;
        }

        return $this->rawQuery(
            "SELECT sl.*, m.display_name as marketplace_name, m.slug as marketplace_slug
             FROM sync_logs sl
             LEFT JOIN marketplaces m ON sl.marketplace_id = m.id
             WHERE {$where}
             ORDER BY sl.started_at DESC
             LIMIT :limit",
            $params
        );
    }

    /**
     * Her sync tipi için son başarılı log'u getir (dashboard)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLastSuccessful(?int $marketplaceId = null): array
    {
        $condition = $marketplaceId !== null
            ? "AND marketplace_id = :mid"
            : "";

        $params = ['store_id' => $this->tenantId(), 'store_id2' => $this->tenantId()];
        if ($marketplaceId !== null) {
            $params['mid'] = $marketplaceId;
        }

        return $this->rawQuery(
            "SELECT sl.*, m.display_name as marketplace_name
             FROM sync_logs sl
             JOIN marketplaces m ON sl.marketplace_id = m.id
             WHERE sl.store_id = :store_id
               AND sl.id IN (
                 SELECT MAX(id) FROM sync_logs
                 WHERE status = 'success' AND store_id = :store_id2 {$condition}
                 GROUP BY marketplace_id, sync_type
             )
             ORDER BY sl.sync_type, m.display_name",
            $params
        );
    }
}
