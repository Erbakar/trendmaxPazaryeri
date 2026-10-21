<?php
/**
 * OzyConnect Panel — SyncEnrichmentDispatcher
 * Senkron zenginleştirme dispatcher'ı (MVP).
 * Gelecekte AsyncEnrichmentDispatcher ile swap edilebilir.
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

namespace App\Services\Enrichment;

use App\Core\Logger;

final class SyncEnrichmentDispatcher implements EnrichmentDispatcher
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichmentService,
        private readonly Logger $logger,
    ) {}

    /**
     * Senkron zenginleştirme — import sonrası hemen çalışır
     * Hata durumunda import sonucunu etkilemez (try/catch ile izole)
     */
    public function dispatch(int $marketplaceId, array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        try {
            $result = $this->enrichmentService->enrichImportedProducts($marketplaceId, $productIds);

            $this->logger->info(
                "Enrichment tamamlandı: {$result->totalProcessed} ürün, "
                . "kategori: {$result->categoryMatched}/{$result->categoryUnmatched}, "
                . "marka: {$result->brandMatched}/{$result->brandUnmatched}",
                'Enrichment'
            );
        } catch (\Throwable $e) {
            // Enrichment hatası import sonucunu etkilemez
            $this->logger->error(
                "Enrichment dispatch hatası: " . $e->getMessage(),
                'Enrichment'
            );
        }
    }
}
