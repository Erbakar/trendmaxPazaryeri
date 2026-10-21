<?php
/**
 * OzyConnect Panel — Store (platform mağaza kaydı)
 * Faz 1/2: stores tablosu tenant KÖKÜDÜR; kendisi tenant-scoped DEĞİLDİR (platform/super-admin
 * tüm mağazaları görür). Entitlement yönetimi bu model + EntitlementService ile yapılır.
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class Store extends BaseModel
{
    protected string $table = 'stores';
    protected bool $tenantScoped = false; // platform kaydı — store_id ile filtrelenmez

    /** @return array<int, array<string,mixed>> */
    public function allStores(): array
    {
        return $this->db->fetchAll("SELECT * FROM stores ORDER BY id ASC");
    }

    /**
     * Liste + hızlı-bak için zenginleştirilmiş: sahip e-posta, paket, abonelik durumu, kullanıcı sayısı.
     * subscriptions.store_id UNIQUE → 1:1 join.
     * @return array<int, array<string,mixed>>
     */
    public function allStoresEnriched(): array
    {
        return $this->db->fetchAll(
            "SELECT s.*,
                    p.name AS package_name, sub.status AS sub_status, sub.billing_cycle,
                    (SELECT u.email FROM users u WHERE u.store_id = s.id AND u.role = 'admin' ORDER BY u.id LIMIT 1) AS owner_email,
                    (SELECT COUNT(*) FROM users u2 WHERE u2.store_id = s.id) AS user_count
             FROM stores s
             LEFT JOIN subscriptions sub ON sub.store_id = s.id
             LEFT JOIN packages p ON p.id = sub.package_id
             ORDER BY s.id ASC"
        );
    }
}
