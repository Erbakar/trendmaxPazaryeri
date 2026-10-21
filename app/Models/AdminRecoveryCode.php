<?php
/** OzyConnect — AdminRecoveryCode (2FA kurtarma kodu; tek kullanımlık). @package OzyConnect */
declare(strict_types=1);

namespace App\Models;

final class AdminRecoveryCode extends BaseModel
{
    protected string $table = 'admin_recovery_codes';
    protected bool $tenantScoped = false;
}
