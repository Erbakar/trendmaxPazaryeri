<?php
/** OzyConnect Panel — ModuleCatalog (Faz 9: satılabilir modül kataloğu + fiyatlar — GLOBAL). */
declare(strict_types=1);

namespace App\Models;

final class ModuleCatalog extends BaseModel
{
    protected string $table = 'module_catalog';
    protected bool $tenantScoped = false;

    /** @return array<int,array<string,mixed>> Satışa açık modüller (merchant "satın al" listesi). */
    public function sellable(): array
    {
        return $this->rawQuery("SELECT * FROM module_catalog WHERE is_sellable = 1 ORDER BY sort_order ASC, id ASC");
    }

    /** @return array<int,array<string,mixed>> Admin: tüm modüller. */
    public function allAdmin(): array
    {
        return $this->rawQuery("SELECT * FROM module_catalog ORDER BY sort_order ASC, id ASC");
    }

    /**
     * @return array<string,string> module_key => label (sıralı). TÜM modüller.
     * Paket formu / abonelik / yetki ekranı için TEK etiket+liste kaynağı (sabit MODULE_LABELS yerine).
     */
    public function labelMap(): array
    {
        $out = [];
        foreach ($this->rawQuery("SELECT module_key, label FROM module_catalog ORDER BY sort_order ASC, id ASC") as $r) {
            $out[(string) $r['module_key']] = (string) $r['label'];
        }
        return $out;
    }

    public function byKey(string $key): array|false
    {
        return $this->rawQueryOne("SELECT * FROM module_catalog WHERE module_key = :k LIMIT 1", ['k' => $key]);
    }

    /** Satışa açık + geçerli fiyatlı modül anahtarı mı? */
    public function isSellable(string $key): bool
    {
        $r = $this->rawQueryOne("SELECT id FROM module_catalog WHERE module_key = :k AND is_sellable = 1 LIMIT 1", ['k' => $key]);
        return $r !== false;
    }

    public function priceOf(string $key): float
    {
        $r = $this->byKey($key);
        return $r !== false ? (float) $r['price_monthly'] : 0.0;
    }

    /** Admin: fiyat + satılabilirlik güncelle. */
    public function updateOne(string $key, float $price, bool $sellable, ?string $label = null): void
    {
        $sets = ['price_monthly = :p', 'is_sellable = :s', 'updated_at = NOW()'];
        $params = ['p' => $price, 's' => $sellable ? 1 : 0, 'k' => $key];
        if ($label !== null && $label !== '') { $sets[] = 'label = :l'; $params['l'] = $label; }
        $this->db->query("UPDATE module_catalog SET " . implode(',', $sets) . " WHERE module_key = :k", $params);
    }
}
