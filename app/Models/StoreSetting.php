<?php
/**
 * OzyConnect Panel — StoreSetting (mağaza-özel ayarlar; store_settings key-value)
 *
 * Tenant-scoped: tüm okuma/yazma aktif mağaza (store_id) ile sınırlıdır. Global `settings`
 * (panel/sistem) DEĞİŞMEZ; mağaza profili (ad/adres/eposta/telefon/logo) burada tutulur.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class StoreSetting extends BaseModel
{
    protected string $table = 'store_settings';
    // tenantScoped = true (varsayılan) → store_id = TenantContext.currentStoreId()

    public function getValue(string $key, ?string $default = null): ?string
    {
        $r = $this->rawQueryOne(
            "SELECT svalue FROM store_settings WHERE store_id = :s AND skey = :k LIMIT 1",
            ['s' => $this->tenantId(), 'k' => $key]
        );
        if ($r === false || $r['svalue'] === null) {
            return $default;
        }
        return (string) $r['svalue'];
    }

    /** @return array<string,string> Aktif mağazanın tüm ayar key=>value haritası. */
    public function allForStore(): array
    {
        $rows = $this->rawQuery(
            "SELECT skey, svalue FROM store_settings WHERE store_id = :s",
            ['s' => $this->tenantId()]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['skey']] = (string) ($r['svalue'] ?? '');
        }
        return $out;
    }

    public function setValue(string $key, ?string $value): void
    {
        $this->db->query(
            "INSERT INTO store_settings (store_id, skey, svalue, created_at, updated_at)
             VALUES (:s, :k, :v, NOW(), NOW())
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = NOW()",
            ['s' => $this->tenantId(), 'k' => $key, 'v' => $value]
        );
    }

    /** @param array<string,?string> $pairs */
    public function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            $this->setValue((string) $k, $v);
        }
    }
}
