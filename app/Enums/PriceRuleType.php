<?php
/**
 * OzyConnect Panel — PriceRuleType Enum
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

namespace App\Enums;

enum PriceRuleType: string
{
    case NONE              = 'none';
    case PERCENT_INCREASE  = 'percent_increase';
    case PERCENT_DECREASE  = 'percent_decrease';
    case FIXED_INCREASE    = 'fixed_increase';
    case FIXED_DECREASE    = 'fixed_decrease';

    public function label(): string
    {
        return match ($this) {
            self::NONE              => 'Kural Yok',
            self::PERCENT_INCREASE  => 'Yüzde Artış',
            self::PERCENT_DECREASE  => 'Yüzde Azalış',
            self::FIXED_INCREASE    => 'Sabit Artış',
            self::FIXED_DECREASE    => 'Sabit Azalış',
        };
    }

    /**
     * Baz fiyata kuralı uygula
     */
    public function apply(float $basePrice, float $value): float
    {
        return match ($this) {
            self::NONE              => $basePrice,
            self::PERCENT_INCREASE  => (float) ceil($basePrice * (1 + $value / 100)),
            self::PERCENT_DECREASE  => (float) ceil($basePrice * (1 - $value / 100)),
            self::FIXED_INCREASE    => $basePrice + $value,
            self::FIXED_DECREASE    => $basePrice - $value,
        };
    }
}
