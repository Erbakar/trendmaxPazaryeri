<?php
/**
 * OzyConnect Panel — ApiLog Model
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

final class ApiLog extends BaseModel
{
    protected string $table = 'api_logs';

    /**
     * API isteğini logla
     */
    public function logRequest(
        ?int $tokenId,
        string $method,
        string $endpoint,
        int $statusCode,
        int $responseTimeMs,
        string $ip,
        ?string $requestBody = null,
        ?string $responseSummary = null
    ): int {
        return $this->create([
            'api_token_id'     => $tokenId,
            'method'           => $method,
            'endpoint'         => $endpoint,
            'status_code'      => $statusCode,
            'response_time_ms' => $responseTimeMs,
            'ip_address'       => $ip,
            'request_body'     => $requestBody,
            'response_summary' => $responseSummary,
        ]);
    }

    /**
     * Token'a ait son istekleri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByToken(int $tokenId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->table}` WHERE `api_token_id` = :id AND `store_id` = :store_id ORDER BY `created_at` DESC LIMIT {$limit}",
            ['id' => $tokenId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Son N dakikadaki istek sayısı (rate limiting için)
     */
    public function getRecentRequestCount(int $tokenId, int $minutes = 1): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM `{$this->table}`
             WHERE `api_token_id` = :id AND `store_id` = :store_id AND `created_at` > DATE_SUB(NOW(), INTERVAL :min MINUTE)",
            ['id' => $tokenId, 'store_id' => $this->tenantId(), 'min' => $minutes]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Eski logları temizle
     */
    public function cleanOldLogs(int $days = 30): int
    {
        $stmt = $this->db->query(
            "DELETE FROM `{$this->table}` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL :days DAY) AND `store_id` = :store_id",
            ['days' => $days, 'store_id' => $this->tenantId()]
        );
        return $stmt->rowCount();
    }
}
