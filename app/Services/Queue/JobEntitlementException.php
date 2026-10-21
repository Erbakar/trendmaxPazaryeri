<?php
/**
 * OzyConnect Panel — JobEntitlementException (Faz 6)
 *
 * Bir job'ın hedef pazaryeri/modülü için mağaza ENTITLEMENT'i yoksa fırlatılır.
 * TERMİNAL hata: retry edilmez (entitlement retry ile geri gelmez) → worker job'ı
 * doğrudan 'dead' işaretler. Tenant izolasyonu/entitlement ihlali fail-closed.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Queue;

final class JobEntitlementException extends \RuntimeException
{
}
