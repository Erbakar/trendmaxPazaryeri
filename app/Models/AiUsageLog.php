<?php
/** OzyConnect Panel — AiUsageLog (AI kullanım/maliyet kaydı; operatör marjı analizi). GLOBAL. */
declare(strict_types=1);

namespace App\Models;

final class AiUsageLog extends BaseModel
{
    protected string $table = 'ai_usage_logs';
    protected bool $tenantScoped = false; // store_id açıkça verilir

    public function record(
        int $storeId, ?int $userId, string $moduleKey, string $action,
        string $provider, string $model, int $inputTokens, int $outputTokens,
        float $costUsd, int $credits, int $latencyMs, string $status, ?string $error
    ): int {
        $this->db->query(
            "INSERT INTO ai_usage_logs
               (store_id,user_id,module_key,action,provider,model,input_tokens,output_tokens,cost_usd,credits,latency_ms,status,error,created_at)
             VALUES (:store_id,:user_id,:module_key,:action,:provider,:model,:in_t,:out_t,:cost,:credits,:lat,:status,:err,NOW())",
            [
                'store_id' => $storeId, 'user_id' => $userId, 'module_key' => $moduleKey, 'action' => $action,
                'provider' => $provider, 'model' => $model, 'in_t' => $inputTokens, 'out_t' => $outputTokens,
                'cost' => $costUsd, 'credits' => $credits, 'lat' => $latencyMs, 'status' => $status,
                'err' => $error !== null ? mb_substr($error, 0, 250) : null,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForStore(int $storeId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM ai_usage_logs WHERE store_id = :s ORDER BY id DESC LIMIT " . (int) $limit,
            ['s' => $storeId]
        );
    }
}
