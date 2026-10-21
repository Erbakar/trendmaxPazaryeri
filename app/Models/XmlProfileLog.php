<?php
/**
 * OzyConnect Panel — XmlProfileLog Model
 *
 * XML profil çalışma kayıtları ("Kayıtlar" ekranı).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-07-07
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Models;

final class XmlProfileLog extends BaseModel
{
    protected string $table = 'xml_profile_logs';

    /**
     * Kayıt başlat
     */
    public function start(int $profileId, string $action = 'sync', string $triggeredBy = 'manual'): int
    {
        return $this->create([
            'profile_id'   => $profileId,
            'action'       => $action,
            'status'       => 'running',
            'triggered_by' => $triggeredBy,
            'started_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Kaydı tamamla
     *
     * @param array<int, array<string, mixed>> $errors
     */
    public function finish(
        int $logId,
        string $status,
        string $message,
        int $total = 0,
        int $created = 0,
        int $updated = 0,
        int $skipped = 0,
        int $failed = 0,
        array $errors = []
    ): bool {
        $row = $this->find($logId);
        $durationMs = 0;
        if ($row !== false && !empty($row['started_at'])) {
            $durationMs = (int) ((microtime(true) - strtotime((string) $row['started_at'])) * 1000);
        }

        $data = [
            'status'       => $status,
            'message'      => mb_substr($message, 0, 990),
            'total_rows'   => $total,
            'created_rows' => $created,
            'updated_rows' => $updated,
            'skipped_rows' => $skipped,
            'failed_rows'  => $failed,
            'finished_at'  => date('Y-m-d H:i:s'),
            'duration_ms'  => max(0, $durationMs),
        ];
        if (!empty($errors)) {
            // İlk 100 hata yeterli — longtext'i şişirme
            $data['error_details'] = json_encode(array_slice($errors, 0, 100), JSON_UNESCAPED_UNICODE);
        }

        return $this->update($logId, $data);
    }

    /**
     * Profilin son kayıtları
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForProfile(int $profileId, int $limit = 50): array
    {
        return $this->all(['profile_id' => $profileId], 'id DESC', $limit);
    }
}
