<?php
/**
 * OzyConnect Panel — TenantCronRunner (Faz 4)
 *
 * Cron/sync işlerini PER-STORE çalıştırır: her aktif mağaza için TenantContext kurar,
 * işi çalıştırır, sonra TenantContext'i TEMİZLER. Kurallar:
 *  - Her işlem store bazında (setStoreId → iş → clear).
 *  - Bir mağazanın hatası diğerlerini DURDURMAZ (per-store try/catch).
 *  - TenantContext olmadan iş çalışmaz (job içindeki tenant-scoped sorgular fail-closed).
 *  - Singleton PDO + uzun ömürlü süreçte tenant SIZINTISI yok (her iş sonrası clear).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;

final class TenantCronRunner
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Aktif tüm mağazalar için $job'ı per-store çalıştır.
     * $job: fn(int $storeId): mixed. Dönüş: [store_id => ['ok'=>bool, 'result'|'error']].
     *
     * @return array<int, array<string, mixed>>
     */
    public function runForActiveStores(callable $job): array
    {
        $stores = $this->db->fetchAll("SELECT id FROM stores WHERE status = 'active' ORDER BY id");
        $results = [];
        foreach ($stores as $s) {
            $storeId = (int) $s['id'];
            $this->tenant->setStoreId($storeId);          // bu işin tenant bağlamı
            try {
                $results[$storeId] = ['ok' => true, 'result' => $job($storeId)];
            } catch (\Throwable $e) {
                // Bir mağaza hatası diğer mağazaları DURDURMAZ — izole edilir.
                $results[$storeId] = ['ok' => false, 'error' => $e->getMessage()];
            } finally {
                $this->tenant->clear();                   // sızıntı önleme (zorunlu)
            }
        }
        return $results;
    }
}
