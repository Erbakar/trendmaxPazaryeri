<?php
/** Mağazanın bu AI modülüne erişim yetkisi (paket/add-on) yok. */
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class AiNotEntitledException extends RuntimeException {}
