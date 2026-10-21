<?php
/**
 * OzyConnect Panel — ProductImportJob Model
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

final class ProductImportJob extends BaseModel
{
    protected string $table = 'product_import_jobs';

    /**
     * İş başlat
     */
    public function startJob(int $marketplaceId, string $mode, ?int $userId = null): int
    {
        return $this->create([
            'marketplace_id' => $marketplaceId,
            'import_mode'    => $mode,
            'status'         => 'processing',
            'started_at'     => date('Y-m-d H:i:s'),
            'created_by'     => $userId,
        ]);
    }

    /**
     * İlerlemeyi güncelle
     */
    public function updateProgress(int $jobId, int $processed, int $success, int $failed): bool
    {
        return $this->update($jobId, [
            'processed_rows' => $processed,
            'success_rows'   => $success,
            'failed_rows'    => $failed,
        ]);
    }

    /**
     * İşi tamamla
     *
     * @param array<int, array<string, mixed>> $errors
     */
    public function finishJob(int $jobId, string $status, int $total, int $success, int $failed, array $errors = []): bool
    {
        $data = [
            'status'       => $status,
            'total_rows'   => $total,
            'success_rows' => $success,
            'failed_rows'  => $failed,
            'finished_at'  => date('Y-m-d H:i:s'),
        ];

        if (!empty($errors)) {
            $data['error_log'] = json_encode($errors, JSON_UNESCAPED_UNICODE);
        }

        return $this->update($jobId, $data);
    }
}
