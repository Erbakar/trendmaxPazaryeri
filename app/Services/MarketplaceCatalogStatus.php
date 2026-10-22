<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\StoreSetting;
use App\Models\Marketplace;
use App\Services\Marketplace\TrendyolService;

/** Store-scoped API snapshot; local mappings never imply an approved listing. */
final class MarketplaceCatalogStatus
{
    private const KEY = 'trendyol_catalog_status_v1';

    public function __construct(private readonly StoreSetting $settings, private readonly TrendyolService $trendyol, private readonly Marketplace $marketplace) {}

    public function read(): array
    {
        $snapshot = json_decode($this->settings->getValue(self::KEY, '{}') ?? '{}', true);
        if (!is_array($snapshot) || ($snapshot['account'] ?? '') !== $this->account()) return [];
        return $snapshot;
    }

    private function account(): string
    {
        $mp = $this->marketplace->findBySlug('trendyol') ?: [];
        return hash('sha256', ($mp['extra_config_json'] ?? '') . '|' . ($mp['api_key_encrypted'] ?? ''));
    }

    public function refresh(): array
    {
        $snapshot = $this->read();
        // Also throttle failed calls. Keep the last successful counts and timestamp on failure.
        if (time() - (int)($snapshot['attempted_at'] ?? 0) < 600) return $snapshot;
        $snapshot['attempted_at'] = time();
        $snapshot['account'] = $this->account();
        $this->settings->setValue(self::KEY, json_encode($snapshot, JSON_THROW_ON_ERROR));
        try {
            $counts = $this->trendyol->fetchCatalogStatusCounts();
            $snapshot = ['counts' => $counts, 'checked_at' => time(), 'attempted_at' => time(), 'error' => false, 'account' => $this->account()];
        } catch (\Throwable) {
            $snapshot['error'] = true;
        }
        $this->settings->setValue(self::KEY, json_encode($snapshot, JSON_THROW_ON_ERROR));
        return $snapshot;
    }
}
