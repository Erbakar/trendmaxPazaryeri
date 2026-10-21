<?php
/**
 * OzyConnect Panel — InvoiceType Enum
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

enum InvoiceType: string
{
    case E_FATURA = 'e-fatura';
    case E_ARSIV  = 'e-arsiv';

    public function label(): string
    {
        return match ($this) {
            self::E_FATURA => 'e-Fatura',
            self::E_ARSIV  => 'e-Arşiv',
        };
    }

    public function profileId(): string
    {
        return match ($this) {
            self::E_FATURA => 'TICARIFATURA',
            self::E_ARSIV  => 'EARSIVFATURA',
        };
    }
}
