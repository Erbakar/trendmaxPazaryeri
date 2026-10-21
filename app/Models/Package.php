<?php
/**
 * OzyConnect Panel — Package (Faz 8: paketler — GLOBAL ticari tablo)
 *
 * Public fiyat tablosu + admin CRUD. Paket içeriği junction'larda (package_marketplaces/modules) +
 * package_limits. Entitlement uygulaması PackageEntitlementSync üzerinden (bu model yalnız veri).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class Package extends BaseModel
{
    protected string $table = 'packages';
    protected bool $tenantScoped = false;

    /** Public fiyat tablosu: dahili olmayan, aktif, silinmemiş. */
    public function activePublic(): array
    {
        return $this->rawQuery(
            "SELECT p.*, l.max_products, l.max_orders_month, l.max_users, l.max_api_tokens
             FROM packages p LEFT JOIN package_limits l ON l.package_id = p.id
             WHERE p.is_active = 1 AND p.status = 'active' AND p.is_internal = 0 AND p.deleted_at IS NULL
             ORDER BY p.sort_order ASC, p.price_monthly ASC"
        );
    }

    /** Admin liste: silinmemiş tüm paketler (dahili dahil). */
    public function allAdmin(): array
    {
        return $this->rawQuery(
            "SELECT p.*, l.max_products, l.max_orders_month, l.max_users, l.max_api_tokens
             FROM packages p LEFT JOIN package_limits l ON l.package_id = p.id
             WHERE p.deleted_at IS NULL ORDER BY p.sort_order ASC, p.id ASC"
        );
    }

    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne("SELECT * FROM packages WHERE id = :id", ['id' => $id]);
    }

    public function findBySlug(string $slug): array|false
    {
        return $this->rawQueryOne("SELECT * FROM packages WHERE slug = :s AND deleted_at IS NULL", ['s' => $slug]);
    }

    /** @return int[] */
    public function marketplaceIds(int $pkgId): array
    {
        return array_map(
            static fn($r) => (int) $r['marketplace_id'],
            $this->rawQuery("SELECT marketplace_id FROM package_marketplaces WHERE package_id = :p", ['p' => $pkgId])
        );
    }

    /** @return string[] */
    public function moduleKeys(int $pkgId): array
    {
        return array_map(
            static fn($r) => (string) $r['module_key'],
            $this->rawQuery("SELECT module_key FROM package_modules WHERE package_id = :p", ['p' => $pkgId])
        );
    }

    public function limits(int $pkgId): array|false
    {
        return $this->rawQueryOne("SELECT * FROM package_limits WHERE package_id = :p", ['p' => $pkgId]);
    }

    // ─── YAZMA (admin) ──────────────────────────────────────────────────────

    /** @param array<string,mixed> $d */
    public function createPackage(array $d): int
    {
        return $this->create([
            'name'          => mb_substr((string) $d['name'], 0, 150),
            'slug'          => mb_substr((string) $d['slug'], 0, 150),
            'description'   => isset($d['description']) ? mb_substr((string) $d['description'], 0, 2000) : null,
            'price_monthly' => (float) ($d['price_monthly'] ?? 0),
            'price_yearly'  => (float) ($d['price_yearly'] ?? 0),
            'currency'      => 'TRY',
            'trial_days'    => max(0, (int) ($d['trial_days'] ?? 0)),
            'is_internal'   => 0,
            'is_active'     => !empty($d['is_active']) ? 1 : 0,
            'status'        => in_array($d['status'] ?? '', ['draft', 'active', 'archived'], true) ? $d['status'] : 'active',
            'sort_order'    => max(0, (int) ($d['sort_order'] ?? 0)),
        ]);
    }

    /** @param array<string,mixed> $d */
    public function updatePackage(int $id, array $d): void
    {
        $this->update($id, [
            'name'          => mb_substr((string) $d['name'], 0, 150),
            'slug'          => mb_substr((string) $d['slug'], 0, 150),
            'description'   => isset($d['description']) ? mb_substr((string) $d['description'], 0, 2000) : null,
            'price_monthly' => (float) ($d['price_monthly'] ?? 0),
            'price_yearly'  => (float) ($d['price_yearly'] ?? 0),
            'trial_days'    => max(0, (int) ($d['trial_days'] ?? 0)),
            'is_active'     => !empty($d['is_active']) ? 1 : 0,
            'status'        => in_array($d['status'] ?? '', ['draft', 'active', 'archived'], true) ? $d['status'] : 'active',
            'sort_order'    => max(0, (int) ($d['sort_order'] ?? 0)),
        ]);
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $row = $ignoreId !== null
            ? $this->rawQueryOne("SELECT id FROM packages WHERE slug = :s AND id <> :i", ['s' => $slug, 'i' => $ignoreId])
            : $this->rawQueryOne("SELECT id FROM packages WHERE slug = :s", ['s' => $slug]);
        return $row !== false;
    }

    public function softDelete(int $id): void
    {
        // Internal paket (id=1) silinemez.
        $this->db->query("UPDATE packages SET deleted_at = NOW(), is_active = 0 WHERE id = :id AND is_internal = 0", ['id' => $id]);
    }

    /** @param int[] $mpIds */
    public function setMarketplaces(int $id, array $mpIds): void
    {
        $this->db->query("DELETE FROM package_marketplaces WHERE package_id = :p", ['p' => $id]);
        foreach (array_unique(array_map('intval', $mpIds)) as $mp) {
            if ($mp > 0) {
                $this->db->query("INSERT INTO package_marketplaces (package_id, marketplace_id) VALUES (:p, :m)", ['p' => $id, 'm' => $mp]);
            }
        }
    }

    /** @param string[] $keys */
    public function setModules(int $id, array $keys): void
    {
        $valid = \App\Services\EntitlementService::ENTITLEMENT_MODULES;
        $this->db->query("DELETE FROM package_modules WHERE package_id = :p", ['p' => $id]);
        foreach (array_unique($keys) as $k) {
            if (in_array($k, $valid, true)) {
                $this->db->query("INSERT INTO package_modules (package_id, module_key) VALUES (:p, :k)", ['p' => $id, 'k' => $k]);
            }
        }
    }

    /** @param array<string,?int> $lim NULL = sınırsız */
    public function upsertLimits(int $id, array $lim): void
    {
        $this->db->query(
            "INSERT INTO package_limits (package_id, max_products, max_orders_month, max_users, max_api_tokens, api_rate_per_min)
             VALUES (:p, :mp, :mo, :mu, :mt, :mr)
             ON DUPLICATE KEY UPDATE max_products=VALUES(max_products), max_orders_month=VALUES(max_orders_month),
                 max_users=VALUES(max_users), max_api_tokens=VALUES(max_api_tokens), api_rate_per_min=VALUES(api_rate_per_min)",
            ['p' => $id, 'mp' => $lim['max_products'] ?? null, 'mo' => $lim['max_orders_month'] ?? null,
             'mu' => $lim['max_users'] ?? null, 'mt' => $lim['max_api_tokens'] ?? null, 'mr' => $lim['api_rate_per_min'] ?? null]
        );
    }

    /** Paketi kopyala (+ limit + junction). Yeni slug üret. */
    public function duplicate(int $id): ?int
    {
        $src = $this->adminFind($id);
        if ($src === false) {
            return null;
        }
        $slug = $src['slug'] . '-kopya';
        $i = 1;
        while ($this->slugExists($slug)) { $i++; $slug = $src['slug'] . '-kopya-' . $i; }
        $newId = $this->create([
            'name' => mb_substr($src['name'] . ' (Kopya)', 0, 150), 'slug' => mb_substr($slug, 0, 150),
            'description' => $src['description'], 'price_monthly' => $src['price_monthly'], 'price_yearly' => $src['price_yearly'],
            'currency' => $src['currency'], 'trial_days' => $src['trial_days'], 'is_internal' => 0,
            'is_active' => 0, 'status' => 'draft', 'sort_order' => (int) $src['sort_order'] + 1,
        ]);
        $this->setMarketplaces($newId, $this->marketplaceIds($id));
        $this->setModules($newId, $this->moduleKeys($id));
        $lim = $this->limits($id);
        if ($lim !== false) {
            $this->upsertLimits($newId, [
                'max_products' => $lim['max_products'], 'max_orders_month' => $lim['max_orders_month'],
                'max_users' => $lim['max_users'], 'max_api_tokens' => $lim['max_api_tokens'], 'api_rate_per_min' => $lim['api_rate_per_min'],
            ]);
        }
        return $newId;
    }
}
