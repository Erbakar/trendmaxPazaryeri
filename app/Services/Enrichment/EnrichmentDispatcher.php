<?php
/**
 * OzyConnect Panel — EnrichmentDispatcher Interface
 * Zenginleştirme tetikleme soyutlaması.
 * MVP: SyncEnrichmentDispatcher (senkron)
 * Gelecek: AsyncEnrichmentDispatcher (kuyruk tabanlı)
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

interface EnrichmentDispatcher
{
    /**
     * Zenginleştirme işlemini tetikle
     *
     * @param int $marketplaceId Pazaryeri ID
     * @param array<int> $productIds İşlenecek ürün ID'leri
     */
    public function dispatch(int $marketplaceId, array $productIds): void;
}
