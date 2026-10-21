<?php
/**
 * OzyConnect Panel — InvoiceStatus Enum
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

enum InvoiceStatus: string
{
    case DRAFT     = 'draft';
    case PENDING   = 'pending';
    case SENT      = 'sent';
    case CANCELLED = 'cancelled';
    case ERROR     = 'error';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => 'Taslak',
            self::PENDING   => 'Bekliyor',
            self::SENT      => 'Gönderildi',
            self::CANCELLED => 'İptal',
            self::ERROR     => 'Hata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT     => '#94a3b8',
            self::PENDING   => '#f59e0b',
            self::SENT      => '#10b981',
            self::CANCELLED => '#ef4444',
            self::ERROR     => '#dc2626',
        };
    }
}
