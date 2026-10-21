<?php
/**
 * OzyConnect Panel — ExchangeRate Model
 *
 * TCMB satış kurları (global tablo — tüm mağazalar aynı kuru kullanır).
 * Cron (sync_exchange_rates.php) veya panel tetiklemesiyle güncellenir.
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

final class ExchangeRate extends BaseModel
{
    protected string $table = 'exchange_rates';
    protected bool $tenantScoped = false;

    /**
     * Kur değeri getir (TRY/TL → 1.0)
     */
    public function getRate(string $currencyCode): float
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === 'TRY' || $code === 'TL' || $code === '') {
            return 1.0;
        }

        $row = $this->findBy(['currency_code' => $code]);
        return $row !== false ? (float) $row['rate'] : 0.0;
    }

    /**
     * Tüm kurları [kod => rate] olarak getir
     *
     * @return array<string, float>
     */
    public function getAllRates(): array
    {
        $rates = ['TRY' => 1.0];
        foreach ($this->all([], 'currency_code ASC') as $row) {
            $rates[(string) $row['currency_code']] = (float) $row['rate'];
        }
        return $rates;
    }

    /**
     * Kur güncelle/ekle (upsert)
     */
    public function upsertRate(string $currencyCode, float $rate, string $source = 'tcmb'): void
    {
        $this->db->query(
            "INSERT INTO exchange_rates (currency_code, rate, source, fetched_at)
             VALUES (:code, :rate, :source, NOW())
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), source = VALUES(source), fetched_at = NOW()",
            ['code' => strtoupper(trim($currencyCode)), 'rate' => $rate, 'source' => $source]
        );
    }

    /**
     * En eski kur çekim zamanı (saniye cinsinden yaş; hiç çekilmemişse null)
     */
    public function oldestFetchAge(): ?int
    {
        $row = $this->rawQueryOne(
            "SELECT MIN(fetched_at) AS oldest FROM exchange_rates WHERE rate > 0"
        );
        if ($row === false || empty($row['oldest'])) {
            return null;
        }
        return max(0, time() - (int) strtotime((string) $row['oldest']));
    }
}
