<?php
/** OzyConnect — StoreDocumentType (KYC belge TÜRÜ kataloğu; GLOBAL/admin-yönetilebilir, tenantScoped=false). */
declare(strict_types=1);

namespace App\Models;

final class StoreDocumentType extends BaseModel
{
    protected string $table = 'store_document_types';
    protected bool $tenantScoped = false;

    /** Mağaza panelinde gösterilecek aktif türler (sıralı). */
    public function active(): array
    {
        return $this->rawQuery(
            "SELECT * FROM store_document_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        );
    }

    /** Admin yönetim listesi (hepsi). */
    public function allTypes(): array
    {
        return $this->rawQuery("SELECT * FROM store_document_types ORDER BY sort_order ASC, id ASC");
    }

    /** Aktif + zorunlu tür id'leri (doğrulama hesabı). @return int[] */
    public function requiredActiveIds(): array
    {
        $rows = $this->rawQuery("SELECT id FROM store_document_types WHERE is_active = 1 AND is_required = 1");
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /** Slug'dan benzersiz key üret (admin yeni tür eklerken). */
    public function makeKey(string $label): string
    {
        $tr = ['ç'=>'c','ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(strtr($label, $tr), 'UTF-8')) ?? '', '_') ?: 'belge';
        $base = mb_substr($base, 0, 40);
        $key = $base; $i = 1;
        while ($this->rawQueryOne("SELECT id FROM store_document_types WHERE `key` = :k", ['k' => $key]) !== false) {
            $key = $base . '_' . (++$i);
            if ($i > 99) { $key = $base . '_' . bin2hex(random_bytes(2)); break; }
        }
        return $key;
    }
}
