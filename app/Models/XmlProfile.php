<?php
/**
 * OzyConnect Panel — XmlProfile Model
 *
 * XML Profilleri: Ticimax/dbentegrasyon tarzı içe & dışa aktarım profilleri.
 * direction=import → tedarikçi XML'inden ürün senkronu (URL veya dosya)
 * direction=export → token'lı public Ticimax-format XML çıktısı
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-07-07
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Models;

final class XmlProfile extends BaseModel
{
    protected string $table = 'xml_profiles';

    /**
     * Token ile bul (public export serving — cross-tenant, store filtresi YOK;
     * token 64 karakter rastgele olduğundan tek başına yeterli anahtardır)
     *
     * @return array<string, mixed>|false
     */
    public function findByTokenGlobal(string $token): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM xml_profiles WHERE token = :token LIMIT 1",
            ['token' => $token]
        );
    }

    /**
     * Aktif profilleri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        return $this->all(['is_active' => 1], 'id ASC');
    }

    /**
     * Periyodu gelen (vadesi dolmuş) aktif profilleri getir — cron için.
     * period_hours=0 → "Hiçbir Zaman" (otomatik koşmaz).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDue(): array
    {
        return $this->rawQuery(
            "SELECT * FROM xml_profiles
             WHERE store_id = :store_id
               AND is_active = 1
               AND period_hours > 0
               AND (last_run_at IS NULL OR last_run_at <= DATE_SUB(NOW(), INTERVAL period_hours HOUR))
             ORDER BY id ASC",
            ['store_id' => $this->tenantId()]
        );
    }

    /**
     * Çalışma sonucunu profile işle
     */
    public function markRun(int $profileId, string $status, string $summary, ?int $totalProducts = null): void
    {
        $data = [
            'last_run_at'      => date('Y-m-d H:i:s'),
            'last_run_status'  => $status,
            'last_run_summary' => mb_substr($summary, 0, 490),
        ];
        if ($totalProducts !== null) {
            $data['total_products'] = $totalProducts;
        }
        $this->update($profileId, $data);
    }

    /**
     * Bu profile bağlı ürün sayısı
     */
    public function getProductCount(int $profileId): int
    {
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM products WHERE xml_profile_id = :pid AND store_id = :store_id",
            ['pid' => $profileId, 'store_id' => $this->tenantId()]
        );
        return (int) ($row['cnt'] ?? 0);
    }
}
