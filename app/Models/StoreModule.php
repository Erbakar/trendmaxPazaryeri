<?php
/**
 * OzyConnect Panel — StoreModule (mağaza modül ENTITLEMENT'i)
 * Faz 2: bir mağazanın hangi modülü (finans, kargo, sipariş…) satın aldığı.
 * Tenant-scoped (store_id) — BaseModel otomatik filtreler.
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class StoreModule extends BaseModel
{
    protected string $table = 'store_modules';

    /** Mağazanın yetkili modülleri — anahtar + durum + süre (paket + add-on dahil). */
    public function listForStore(int $storeId): array
    {
        return $this->rawQuery(
            "SELECT module_key, status, expires_at FROM store_modules
             WHERE store_id = :s ORDER BY (status = 'active') DESC, id ASC",
            ['s' => $storeId]
        );
    }
}
