<?php
/**
 * OzyConnect Panel — PackageEntitlementSync (Faz 8: hedef küme ↔ mevcut entitlement diff motoru)
 *
 * EntitlementService'in İÇ MANTIĞINA DOKUNMAZ — yalnız grant / revoke ÇAĞIRIR. Hedef kümeyi PARAMETRE
 * alır (EntitlementResolver hesaplar); böylece ileride modül/pazaryeri satışı eklenince bu motor DEĞİŞMEZ.
 * Hedefteki tüm öğeler grant'lenir (ON DUPLICATE → expires_at tazelenir; renewal'da kritik); hedef dışı
 * aktif öğeler revoke edilir (downgrade).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class PackageEntitlementSync
{
    public function __construct(
        private readonly Database $db,
        private readonly EntitlementService $entitlements,
        private readonly EntitlementResolver $resolver,
    ) {}

    /** Paketi mağazaya uygula (provision/upgrade/downgrade/renewal hepsi). */
    public function applyPackage(int $storeId, int $packageId, ?string $expiresAt, string $reason): void
    {
        $t = $this->resolver->forPackage($packageId);
        // Paket modüllerine mağazanın aktif add-on modüllerini EKLE → sync onları revoke etmez (satın alınan modül korunur).
        $mods = array_values(array_unique(array_merge($t['moduleKeys'], $this->resolver->addonsFor($storeId))));
        $this->syncStore($storeId, $t['marketplaceIds'], $mods, $expiresAt, $reason);
    }

    /**
     * Hedef küme ile mevcut aktif entitlement'ı eşitle.
     * @param int[] $targetMpIds @param string[] $targetModuleKeys
     */
    public function syncStore(int $storeId, array $targetMpIds, array $targetModuleKeys, ?string $expiresAt, string $reason): void
    {
        $curMp  = $this->activeMarketplaceIds($storeId);
        $curMod = $this->activeModuleKeys($storeId);

        // Hedefteki TÜM öğeleri grant'le (ON DUPLICATE expires_at'i tazeler → renewal/aktivasyon doğru).
        foreach ($targetMpIds as $mp) {
            $this->entitlements->grantMarketplace($storeId, (int) $mp, $expiresAt, $reason);
        }
        foreach ($targetModuleKeys as $mk) {
            $this->entitlements->grantModule($storeId, (string) $mk, $expiresAt, $reason);
        }
        // Hedef dışı kalan aktifleri revoke et (downgrade).
        foreach (array_diff($curMp, array_map('intval', $targetMpIds)) as $mp) {
            $this->entitlements->revokeMarketplace($storeId, (int) $mp, $reason);
        }
        foreach (array_diff($curMod, $targetModuleKeys) as $mk) {
            $this->entitlements->revokeModule($storeId, (string) $mk, $reason);
        }
    }

    /** Paketin tüm öğelerini revoke et (expire/suspend/cancel). Paket tanımına göre — elle verilen ekstralar korunur. */
    public function revokeAllOfPackage(int $storeId, int $packageId, string $reason): void
    {
        $t = $this->resolver->forPackage($packageId);
        foreach ($t['marketplaceIds'] as $mp) {
            $this->entitlements->revokeMarketplace($storeId, (int) $mp, $reason);
        }
        foreach ($t['moduleKeys'] as $mk) {
            $this->entitlements->revokeModule($storeId, (string) $mk, $reason);
        }
    }

    /** @return int[] */
    private function activeMarketplaceIds(int $storeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT marketplace_id FROM store_marketplaces WHERE store_id = :s AND status = 'active'",
            ['s' => $storeId]
        );
        return array_map(static fn($r) => (int) $r['marketplace_id'], $rows);
    }

    /** @return string[] */
    private function activeModuleKeys(int $storeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT module_key FROM store_modules WHERE store_id = :s AND status = 'active'",
            ['s' => $storeId]
        );
        return array_map(static fn($r) => (string) $r['module_key'], $rows);
    }
}
