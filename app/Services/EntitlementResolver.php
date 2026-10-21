<?php
/**
 * OzyConnect Panel — EntitlementResolver (Faz 8: bir mağazanın HEDEF entitlement kümesini hesaplar)
 *
 * GENİŞLETİLEBİLİRLİK İLKESİ: "Bu mağaza/abonelik neyi açar?" sorusunun TEK cevap noktası.
 * MVP: hedef = paketin pazaryeri + modülleri. Gelecekte buraya à-la-carte add-on'lar
 * (satın alınmış ekstra modül/pazaryeri, kupon kaynaklı) EKLENİR; PackageEntitlementSync DEĞİŞMEZ.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Models\Package;
use App\Models\StoreAddon;
use App\Models\Subscription;

final class EntitlementResolver
{
    public function __construct(
        private readonly Package $packages,
        private readonly Subscription $subscriptions,
        private readonly StoreAddon $addons,
    ) {}

    /**
     * Mağazanın satın aldığı (aktif) add-on modül anahtarları.
     * @return string[]
     */
    public function addonsFor(int $storeId): array
    {
        return $this->addons->activeModuleKeys($storeId);
    }

    /**
     * Paketin hedef entitlement kümesi.
     * @return array{marketplaceIds:int[], moduleKeys:string[]}
     */
    public function forPackage(int $packageId): array
    {
        return [
            'marketplaceIds' => $this->packages->marketplaceIds($packageId),
            'moduleKeys'     => $this->packages->moduleKeys($packageId),
            // GELECEK: + $this->addonsFor($storeId) (store_addons), + kupon kaynaklı eklemeler
        ];
    }

    /**
     * Mağazanın aktif aboneliğine göre hedef küme (re-sync için).
     * @return array{marketplaceIds:int[], moduleKeys:string[]}
     */
    public function forStore(int $storeId): array
    {
        $sub = $this->subscriptions->forStore($storeId);
        $base = ($sub === false)
            ? ['marketplaceIds' => [], 'moduleKeys' => []]
            : $this->forPackage((int) $sub['package_id']);
        // Paket ∪ satın alınmış add-on modülleri (sahip olmadığı modülü satın alma).
        $base['moduleKeys'] = array_values(array_unique(array_merge($base['moduleKeys'], $this->addonsFor($storeId))));
        return $base;
    }
}
