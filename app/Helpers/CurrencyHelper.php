<?php
/**
 * OzyConnect Panel — Currency Helper
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Helpers;

final class CurrencyHelper
{
    /**
     * Para formatla (Türk formatı: 1.234,56 ₺)
     */
    public static function format(float|int|string|null $amount, string $currency = 'TRY'): string
    {
        if ($amount === null) {
            return '0,00 ₺';
        }

        $amount = (float) $amount;
        $formatted = number_format($amount, 2, ',', '.');

        $symbol = match ($currency) {
            'TRY' => '₺',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $currency,
        };

        return $formatted . ' ' . $symbol;
    }

    /**
     * KDV hesapla (fiyat KDV dahil ise)
     */
    public static function calculateTaxFromGross(float $grossPrice, float $taxRate): array
    {
        $netPrice  = $grossPrice / (1 + $taxRate / 100);
        $taxAmount = $grossPrice - $netPrice;

        return [
            'net'    => round($netPrice, 2),
            'tax'    => round($taxAmount, 2),
            'gross'  => round($grossPrice, 2),
            'rate'   => $taxRate,
        ];
    }

    /**
     * KDV hesapla (fiyat KDV hariç ise)
     */
    public static function calculateTaxFromNet(float $netPrice, float $taxRate): array
    {
        $taxAmount  = $netPrice * ($taxRate / 100);
        $grossPrice = $netPrice + $taxAmount;

        return [
            'net'    => round($netPrice, 2),
            'tax'    => round($taxAmount, 2),
            'gross'  => round($grossPrice, 2),
            'rate'   => $taxRate,
        ];
    }
}
