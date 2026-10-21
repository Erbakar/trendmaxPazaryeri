<?php
/**
 * OzyConnect Panel — StoreMarketplace (mağaza pazaryeri ENTITLEMENT'i)
 * Faz 2: bir mağazanın hangi pazaryerini satın aldığı/yetkili olduğu.
 * Tenant-scoped (store_id) — BaseModel otomatik filtreler.
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class StoreMarketplace extends BaseModel
{
    protected string $table = 'store_marketplaces';

    /** Mağazanın yetkili pazaryerleri — ad + durum + süre (paket + admin-eklenen ekstralar dahil). */
    public function listForStore(int $storeId): array
    {
        return $this->rawQuery(
            "SELECT sm.marketplace_id, sm.status, sm.expires_at, m.display_name, m.slug
             FROM store_marketplaces sm
             JOIN marketplaces m ON m.id = sm.marketplace_id
             WHERE sm.store_id = :s
             ORDER BY (sm.status = 'active') DESC, m.display_name ASC",
            ['s' => $storeId]
        );
    }
}
