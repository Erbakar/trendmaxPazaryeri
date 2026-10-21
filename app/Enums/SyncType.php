<?php
/**
 * OzyConnect Panel — SyncType Enum
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

enum SyncType: string
{
    case ORDERS        = 'orders';
    case STOCK         = 'stock';
    case PRICE         = 'price';
    case RETURNS       = 'returns';
    case PRODUCTS_PUSH = 'products_push';
    case PRODUCTS_PULL = 'products_pull';
    case FEED_GENERATE = 'feed_generate';

    public function label(): string
    {
        return match ($this) {
            self::ORDERS        => 'Sipariş Senkronizasyonu',
            self::STOCK         => 'Stok Güncelleme',
            self::PRICE         => 'Fiyat Güncelleme',
            self::RETURNS       => 'İade Senkronizasyonu',
            self::PRODUCTS_PUSH => 'Ürün Gönderme (Push)',
            self::PRODUCTS_PULL => 'Ürün Çekme (Pull)',
            self::FEED_GENERATE => 'Feed Üretimi',
        };
    }
}
