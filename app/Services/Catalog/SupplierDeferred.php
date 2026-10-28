<?php
declare(strict_types=1);
namespace App\Services\Catalog;

/** The worker yielded its time budget; this is not a supplier or product failure. */
final class SupplierDeferred extends \RuntimeException {}
