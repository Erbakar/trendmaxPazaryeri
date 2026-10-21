<?php
/**
 * OzyConnect Panel — OrderStatus Enum
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

enum OrderStatus: string
{
    case YENI              = 'yeni';
    case HAZIRLANIYOR      = 'hazirlaniyor';
    case FATURALI          = 'faturali';
    case KARGODA           = 'kargoda';
    case TESLIM_EDILDI     = 'teslim_edildi';
    case IPTAL             = 'iptal';
    case TESLIM_EDILEMEDI  = 'teslim_edilemedi';
    case IADE              = 'iade';

    public function label(): string
    {
        return match ($this) {
            self::YENI              => 'Yeni',
            self::HAZIRLANIYOR      => 'Hazırlanıyor',
            self::FATURALI          => 'Faturalı',
            self::KARGODA           => 'Kargoda',
            self::TESLIM_EDILDI     => 'Teslim Edildi',
            self::IPTAL             => 'İptal',
            self::TESLIM_EDILEMEDI  => 'Teslim Edilemedi',
            self::IADE              => 'İade',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::YENI              => '#3b82f6',
            self::HAZIRLANIYOR      => '#f59e0b',
            self::FATURALI          => '#8b5cf6',
            self::KARGODA           => '#06b6d4',
            self::TESLIM_EDILDI     => '#10b981',
            self::IPTAL             => '#ef4444',
            self::TESLIM_EDILEMEDI  => '#f97316',
            self::IADE              => '#ec4899',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::YENI              => 'badge-info',
            self::HAZIRLANIYOR      => 'badge-warning',
            self::FATURALI          => 'badge-purple',
            self::KARGODA           => 'badge-cyan',
            self::TESLIM_EDILDI     => 'badge-success',
            self::IPTAL             => 'badge-danger',
            self::TESLIM_EDILEMEDI  => 'badge-orange',
            self::IADE              => 'badge-pink',
        };
    }
}
