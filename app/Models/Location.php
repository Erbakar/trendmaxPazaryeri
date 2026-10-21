<?php
/**
 * OzyConnect Panel — Location (Türkiye il/ilçe referansı)
 *
 * provinces (id=plaka 1-81) + districts (province_id FK). Global/paylaşımlı tablo
 * (tenant-scoped DEĞİL). Adres formlarındaki il→ilçe cascade'ini besler.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class Location extends BaseModel
{
    protected string $table = 'provinces';
    protected bool $tenantScoped = false;

    /** @return array<int, array{id:int,name:string}> */
    public function provinces(): array
    {
        return $this->db->fetchAll('SELECT id, name FROM provinces ORDER BY name ASC');
    }

    /**
     * İlçeleri il bazında gruplar: [province_id => [[district_id, name], ...]].
     * View'larda cascade verisi (JSON) olarak gömülür.
     *
     * @return array<int, array<int, array{0:int,1:string}>>
     */
    public function districtsGrouped(): array
    {
        $rows = $this->db->fetchAll('SELECT id, province_id, name FROM districts ORDER BY name ASC');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['province_id']][] = [(int) $r['id'], (string) $r['name']];
        }
        return $out;
    }

    /** Tek il (ad doğrulama/çözümleme için). @return array{id:int,name:string}|false */
    public function province(int $id): array|false
    {
        return $this->db->fetchOne('SELECT id, name FROM provinces WHERE id = :id', ['id' => $id]);
    }

    /** Tek ilçe — il_id ile birlikte (ile aitlik doğrulaması için). @return array{id:int,province_id:int,name:string}|false */
    public function district(int $id): array|false
    {
        return $this->db->fetchOne('SELECT id, province_id, name FROM districts WHERE id = :id', ['id' => $id]);
    }
}
