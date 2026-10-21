<?php
/** OzyConnect Panel — StoreAddon (Faz 9: mağaza modül add-on'ları — aylık ek satın alım). GLOBAL. */
declare(strict_types=1);

namespace App\Models;

final class StoreAddon extends BaseModel
{
    protected string $table = 'store_addons';
    protected bool $tenantScoped = false;

    /** @return string[] Mağazanın aktif + süresi geçmemiş add-on modül anahtarları (EntitlementResolver kullanır). */
    public function activeModuleKeys(int $storeId): array
    {
        $rows = $this->rawQuery(
            "SELECT module_key FROM store_addons
             WHERE store_id = :s AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
            ['s' => $storeId]
        );
        return array_map(static fn($r) => (string) $r['module_key'], $rows);
    }

    /** @return array<int,array<string,mixed>> Mağazanın tüm add-on'ları (merchant/admin görünüm). */
    public function forStore(int $storeId): array
    {
        return $this->rawQuery(
            "SELECT * FROM store_addons WHERE store_id = :s ORDER BY status ASC, module_key ASC",
            ['s' => $storeId]
        );
    }

    public function findActive(int $storeId, string $moduleKey): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM store_addons WHERE store_id = :s AND module_key = :m AND status = 'active' LIMIT 1",
            ['s' => $storeId, 'm' => $moduleKey]
        );
    }

    /** Add-on'u oluştur ya da yeniden aktive et (UNIQUE store+module; ON DUPLICATE). */
    public function activate(int $storeId, string $moduleKey, float $price, string $cycle, ?string $expiresAt): void
    {
        $this->db->query(
            "INSERT INTO store_addons (store_id, module_key, price_monthly, billing_cycle, status, purchased_at, expires_at)
             VALUES (:s, :m, :p, :c, 'active', NOW(), :e)
             ON DUPLICATE KEY UPDATE
                price_monthly = VALUES(price_monthly), billing_cycle = VALUES(billing_cycle),
                status = 'active', expires_at = VALUES(expires_at), cancelled_at = NULL, updated_at = NOW()",
            ['s' => $storeId, 'm' => $moduleKey, 'p' => $price, 'c' => $cycle, 'e' => $expiresAt]
        );
    }

    public function cancel(int $storeId, string $moduleKey): void
    {
        $this->db->query(
            "UPDATE store_addons SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
             WHERE store_id = :s AND module_key = :m",
            ['s' => $storeId, 'm' => $moduleKey]
        );
    }

    // ─── Faz 11: Pazaryeri add-on'ları ──────────────────────────

    /** @return int[] Aktif + süresi geçmemiş pazaryeri add-on id'leri. */
    public function activeMarketplaceIds(int $storeId): array
    {
        $rows = $this->rawQuery(
            "SELECT marketplace_id FROM store_addons
             WHERE store_id = :s AND addon_type = 'marketplace' AND status = 'active'
               AND marketplace_id IS NOT NULL AND (expires_at IS NULL OR expires_at > NOW())",
            ['s' => $storeId]
        );
        return array_map(static fn($r) => (int) $r['marketplace_id'], $rows);
    }

    /** Pazaryeri add-on'u oluştur ya da yeniden aktive et (UNIQUE store+marketplace; ON DUPLICATE). */
    public function activateMarketplace(int $storeId, int $marketplaceId, float $price, string $cycle, ?string $expiresAt): void
    {
        $this->db->query(
            "INSERT INTO store_addons (store_id, addon_type, marketplace_id, price_monthly, billing_cycle, status, purchased_at, expires_at)
             VALUES (:s, 'marketplace', :mp, :p, :c, 'active', NOW(), :e)
             ON DUPLICATE KEY UPDATE
                price_monthly = VALUES(price_monthly), billing_cycle = VALUES(billing_cycle),
                status = 'active', expires_at = VALUES(expires_at), cancelled_at = NULL, updated_at = NOW()",
            ['s' => $storeId, 'mp' => $marketplaceId, 'p' => $price, 'c' => $cycle, 'e' => $expiresAt]
        );
    }

    public function cancelMarketplace(int $storeId, int $marketplaceId): void
    {
        $this->db->query(
            "UPDATE store_addons SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
             WHERE store_id = :s AND addon_type = 'marketplace' AND marketplace_id = :mp",
            ['s' => $storeId, 'mp' => $marketplaceId]
        );
    }

    /** @return array<int,array<string,mixed>> Mağazanın aktif (modül+pazaryeri) add-on'ları. */
    public function activeAddons(int $storeId): array
    {
        return $this->rawQuery(
            "SELECT * FROM store_addons
             WHERE store_id = :s AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
            ['s' => $storeId]
        );
    }

    /** Aktif add-on'ların AYLIK toplam tutarı (yenileme faturasına eklenir). */
    public function monthlyTotal(int $storeId): float
    {
        $r = $this->rawQueryOne(
            "SELECT COALESCE(SUM(price_monthly), 0) AS t FROM store_addons
             WHERE store_id = :s AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())",
            ['s' => $storeId]
        );
        return (float) ($r['t'] ?? 0);
    }
}
