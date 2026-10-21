<?php
/**
 * OzyConnect Panel — BackupAuthorizationException
 *
 * Sistem-seviyesi yedek/geri-yükleme işlemi, Super Admin (veya CLI/operatör)
 * yetkisi olmadan çağrıldığında fırlatılır. Tenant kullanıcıları (mağaza admini
 * dahil) bu işlemlere ERİŞEMEZ.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class BackupAuthorizationException extends RuntimeException
{
}
