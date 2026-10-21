<?php
/**
 * OzyConnect Panel — TenantContextException
 *
 * Tenant-scoped bir veri sorgusu, aktif store bağlamı kurulmadan çalıştırılmaya
 * çalışıldığında fırlatılır. Bu exception'ın amacı "sessiz scope'suz sorgu"yu
 * imkânsız kılmaktır (fail-closed): store_id yoksa sorgu çalışmaz, patlar.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class TenantContextException extends RuntimeException
{
}
