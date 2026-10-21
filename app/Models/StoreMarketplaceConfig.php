<?php
/**
 * OzyConnect Panel — StoreMarketplaceConfig (Faz 4: per-store pazaryeri bağlantı/credential)
 *
 * Tenant-scoped (store_id) → bir mağaza yalnız KENDİ credential'larını görür (izolasyon).
 * Credential YALNIZCA entitlement'i olan (store_marketplaces active) pazaryeri için çözülür.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class StoreMarketplaceConfig extends BaseModel
{
    protected string $table = 'store_marketplace_configs';

    /**
     * Aktif mağazanın bir pazaryeri için credential'ı — YALNIZ entitlement'i varsa + enabled.
     * Yetkisi olmayan / başka mağazanın credential'ı DÖNMEZ (null).
     *
     * @return array<string, mixed>|null
     */
    public function getCredentialsIfEntitled(int $marketplaceId): ?array
    {
        $row = $this->rawQueryOne(
            "SELECT smc.* FROM store_marketplace_configs smc
             JOIN store_marketplaces sm
               ON sm.store_id = smc.store_id AND sm.marketplace_id = smc.marketplace_id
             WHERE smc.store_id = :sid AND smc.marketplace_id = :mp AND smc.is_enabled = 1
               AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
             LIMIT 1",
            ['sid' => $this->tenantId(), 'mp' => $marketplaceId]
        );
        return $row === false ? null : $row;
    }

    /**
     * Aktif mağazanın TÜM bağlantı yapılandırmaları (tenant-scoped; BaseModel otomatik store_id).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCurrentStore(): array
    {
        return $this->all([], 'marketplace_id ASC');
    }
}
