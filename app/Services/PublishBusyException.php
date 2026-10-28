<?php
declare(strict_types=1);
namespace App\Services;

/** Raised before any marketplace request when another publish/receipt worker owns the lock. */
final class PublishBusyException extends \RuntimeException {}
