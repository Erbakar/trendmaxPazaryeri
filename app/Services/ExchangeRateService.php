<?php
/**
 * OzyConnect Panel — ExchangeRateService
 *
 * TCMB döviz satış kuru servisi:
 *   - fetchTcmb(): today.xml'den USD/EUR/GBP ForexSelling çeker, exchange_rates'e yazar
 *   - ensureFresh(): kur eskiyse otomatik tazeler (import öncesi güvence)
 *   - recalcProductPrices(): döviz-cinsli ürünlerin TL fiyatlarını güncel kurla
 *     yeniden hesaplar (base_price / list_price / cost_price) — AKTİF mağaza bağlamında
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

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\ExchangeRate;

final class ExchangeRateService
{
    private const TCMB_URL = 'https://www.tcmb.gov.tr/kurlar/today.xml';
    private const CURRENCIES = ['USD', 'EUR', 'GBP'];

    public function __construct(
        private readonly ExchangeRate $rateModel,
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
    ) {}

    /**
     * TCMB'den güncel satış kurlarını çek ve kaydet.
     *
     * @return array{success: bool, rates: array<string, float>, message: string}
     */
    public function fetchTcmb(): array
    {
        $xmlStr = $this->httpGet(self::TCMB_URL);
        if ($xmlStr === null) {
            return ['success' => false, 'rates' => [], 'message' => 'TCMB kur servisine ulaşılamadı (internet erişimi gerekli).'];
        }

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlStr);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false || !isset($xml->Currency)) {
            return ['success' => false, 'rates' => [], 'message' => 'TCMB kur verisi çözümlenemedi.'];
        }

        $rates = [];
        foreach ($xml->Currency as $c) {
            $code = (string) $c['CurrencyCode'];
            if (!in_array($code, self::CURRENCIES, true)) {
                continue;
            }
            // Satış kuru: önce ForexSelling, boşsa BanknoteSelling
            $sell = (float) str_replace(',', '.', trim((string) $c->ForexSelling));
            if ($sell <= 0) {
                $sell = (float) str_replace(',', '.', trim((string) $c->BanknoteSelling));
            }
            if ($sell > 0) {
                $rates[$code] = round($sell, 6);
            }
        }

        if ($rates === []) {
            return ['success' => false, 'rates' => [], 'message' => 'TCMB kur verisi bulunamadı.'];
        }

        foreach ($rates as $code => $rate) {
            $this->rateModel->upsertRate($code, $rate, 'tcmb');
        }

        $this->logger->info('TCMB kurları güncellendi: ' . json_encode($rates), 'ExchangeRate');

        return ['success' => true, 'rates' => $rates, 'message' => 'Kurlar güncellendi (' . date('d.m.Y H:i') . ')'];
    }

    /**
     * Kurlar eskiyse (veya hiç yoksa) TCMB'den tazele. Erişim yoksa sessizce devam
     * eder — mevcut kur değerleri kullanılır.
     */
    public function ensureFresh(int $maxAgeHours = 12): void
    {
        $age = $this->rateModel->oldestFetchAge();
        if ($age !== null && $age < $maxAgeHours * 3600) {
            return;
        }
        try {
            $this->fetchTcmb();
        } catch (\Throwable $e) {
            $this->logger->warning('TCMB kur tazeleme atlandı: ' . $e->getMessage(), 'ExchangeRate');
        }
    }

    /**
     * Kur değeri (TRY → 1.0; tanımsız/sıfır kur → 0.0)
     */
    public function getRate(string $currencyCode): float
    {
        return $this->rateModel->getRate($currencyCode);
    }

    /**
     * AKTİF mağazanın döviz-cinsli ürünlerinde TL fiyatları güncel kurla yeniden
     * hesapla. XML profil ürünlerinde liste fiyatı = satış fiyatı tutulur.
     *
     * @return int Güncellenen ürün sayısı
     */
    public function recalcProductPrices(): int
    {
        $storeId = $this->tenant->currentStoreId();
        $updated = 0;

        foreach (self::CURRENCIES as $code) {
            $rate = $this->rateModel->getRate($code);
            if ($rate <= 0) {
                continue;
            }

            $stmt = $this->db->query(
                "UPDATE products SET
                    base_price = ROUND(currency_price * :rate1, 2),
                    list_price = ROUND(currency_price * :rate2, 2),
                    cost_price = IF(currency_cost > 0, ROUND(currency_cost * :rate3, 2), cost_price)
                 WHERE store_id = :store_id
                   AND currency_code = :code
                   AND currency_price > 0",
                [
                    'rate1'    => $rate,
                    'rate2'    => $rate,
                    'rate3'    => $rate,
                    'store_id' => $storeId,
                    'code'     => $code,
                ]
            );
            $updated += $stmt->rowCount();
        }

        if ($updated > 0) {
            $this->logger->info("Döviz fiyat senkronu: {$updated} ürünün TL fiyatı yenilendi (store#{$storeId})", 'ExchangeRate');
        }

        return $updated;
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 OzyConnect',
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body !== false && $err === '') {
                return (string) $body;
            }
        }

        if (ini_get('allow_url_fopen')) {
            $body = @file_get_contents($url);
            if ($body !== false) {
                return $body;
            }
        }

        return null;
    }
}
