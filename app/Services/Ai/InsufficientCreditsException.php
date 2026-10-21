<?php
/** Kredi bakiyesi yetersiz. Üst katman "kredi satın al" yönlendirmesi yapar. */
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class InsufficientCreditsException extends RuntimeException {}
