<?php
/**
 * OzyConnect Panel — MarketplaceSlug Enum
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

enum MarketplaceSlug: string
{
    case OZIYGO      = 'oziygo';
    case TRENDYOL     = 'trendyol';
    case HEPSIBURADA  = 'hepsiburada';
    case N11          = 'n11';
    case AMAZON       = 'amazon';
    case PAZARAMA     = 'pazarama';
    case CICEKSEPETI  = 'ciceksepeti';
    case IDEFIX       = 'idefix';
    case SHOPIFY      = 'shopify';
    case WOOCOMMERCE  = 'woocommerce';
    case OPENCART     = 'opencart';

    public function label(): string
    {
        return match ($this) {
            self::OZIYGO     => 'OziyGO',
            self::TRENDYOL    => 'Trendyol',
            self::HEPSIBURADA => 'Hepsiburada',
            self::N11         => 'n11',
            self::AMAZON      => 'Amazon TR',
            self::PAZARAMA    => 'Pazarama',
            self::CICEKSEPETI => 'ÇiçekSepeti',
            self::IDEFIX      => 'İdefix',
            self::SHOPIFY     => 'Shopify',
            self::WOOCOMMERCE => 'WooCommerce',
            self::OPENCART    => 'OpenCart',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OZIYGO     => '#1967F2',
            self::TRENDYOL    => '#f27a1a',
            self::HEPSIBURADA => '#ff6000',
            self::N11         => '#7b2d8b',
            self::AMAZON      => '#ff9900',
            self::PAZARAMA    => '#00b0ff',
            self::CICEKSEPETI => '#e91e63',
            self::IDEFIX      => '#1a1a2e',
            self::SHOPIFY     => '#95bf47',
            self::WOOCOMMERCE => '#7f54b3',
            self::OPENCART    => '#1c8ed8',
        };
    }

    public function isCurrentlySupported(): bool
    {
        return match ($this) {
            self::OZIYGO, self::TRENDYOL, self::HEPSIBURADA, self::N11, self::AMAZON, self::SHOPIFY, self::WOOCOMMERCE, self::OPENCART => true,
            default                                                    => false,
        };
    }
}
