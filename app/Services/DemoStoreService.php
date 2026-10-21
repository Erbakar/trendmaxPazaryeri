<?php
/**
 * OzyConnect Panel — Demo / Vitrin Mağaza Servisi
 *
 * Belirli mağazaları satış öncesi vitrin olarak işaretler; yazma işlemleri middleware ile engellenir.
 * Kaynak: stores.is_demo kolonu veya .env DEMO_STORE_IDS (virgülle ayrılmış ID listesi).
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DemoStoreService
{
    /** @var array<int, bool> */
    private array $cache = [];

    /** @var list<int> */
    private readonly array $envIds;

    public function __construct(
        private readonly Database $db,
    ) {
        $raw = function_exists('env') ? (string) env('DEMO_STORE_IDS', '') : '';
        $this->envIds = [];
        if ($raw !== '') {
            foreach (explode(',', $raw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) {
                    $this->envIds[] = $id;
                }
            }
        }
    }

    public function isDemoStore(int $storeId): bool
    {
        if ($storeId <= 0) {
            return false;
        }

        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }

        if (in_array($storeId, $this->envIds, true)) {
            return $this->cache[$storeId] = true;
        }

        try {
            $row = $this->db->fetchOne('SELECT is_demo FROM stores WHERE id = :id', ['id' => $storeId]);
            if ($row === false) {
                return $this->cache[$storeId] = false;
            }
            return $this->cache[$storeId] = (int) ($row['is_demo'] ?? 0) === 1;
        } catch (\Throwable) {
            // Kolon henüz migrate edilmediyse .env listesine düş.
            return $this->cache[$storeId] = in_array($storeId, $this->envIds, true);
        }
    }
}
