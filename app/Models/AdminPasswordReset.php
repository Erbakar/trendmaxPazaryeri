<?php
/** OzyConnect — AdminPasswordReset (admin şifre sıfırlama; tek kullanımlık+süreli). @package OzyConnect */
declare(strict_types=1);

namespace App\Models;

final class AdminPasswordReset extends BaseModel
{
    protected string $table = 'admin_password_resets';
    protected bool $tenantScoped = false;
}
