<?php
/**
 * OzyConnect Panel — ReturnStatus Enum
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

enum ReturnStatus: string
{
    case REQUESTED = 'requested';
    case APPROVED  = 'approved';
    case REJECTED  = 'rejected';
    case COMPLETED = 'completed';
    case REFUNDED  = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Talep Edildi',
            self::APPROVED  => 'Onaylandı',
            self::REJECTED  => 'Reddedildi',
            self::COMPLETED => 'Tamamlandı',
            self::REFUNDED  => 'İade Edildi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::REQUESTED => '#f59e0b',
            self::APPROVED  => '#3b82f6',
            self::REJECTED  => '#ef4444',
            self::COMPLETED => '#10b981',
            self::REFUNDED  => '#8b5cf6',
        };
    }
}
