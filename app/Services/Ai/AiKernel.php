<?php
/**
 * OzyConnect Panel — AiKernel
 *
 * Tüm AI modüllerinin tek geçiş noktası. Bir AI işlemini güvenli sarmalar:
 *   1) AI Suite açık mı + mağaza modüle yetkili mi (entitlement)
 *   2) krediyi rezerve et (atomik düş; yetersizse InsufficientCreditsException)
 *   3) sağlayıcı çağrısını çalıştır
 *   4) HATA → krediyi iade et + kullanımı 'error' logla
 *      BAŞARI → token/maliyet/kredi ile kullanımı logla
 *
 * Modül servisleri (AiContentService vb.) yalnız AiKernel + kendi model bağımlılıklarını
 * enjekte eder; kalıtım/constructor zinciri yok.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Session;
use App\Core\TenantContext;
use App\Models\AiUsageLog;
use App\Services\AiCreditService;
use App\Services\EntitlementService;
use RuntimeException;

final class AiKernel
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly AiCreditService $credits,
        private readonly AiUsageLog $usage,
        private readonly EntitlementService $entitlement,
        private readonly TenantContext $tenant,
        private readonly Session $session,
    ) {}

    public function client(): AiClient
    {
        return $this->ai;
    }

    public function creditBalance(): int
    {
        return $this->credits->balance();
    }

    /** Modül kullanılabilir mi (açık + yetkili + yeterli kredi)? UI'da buton durumu için. */
    public function available(string $moduleKey, string $action): bool
    {
        return $this->unavailableReason($moduleKey, $action) === null;
    }

    /**
     * Kullanılamıyorsa GERÇEK sebebi döndür.
     *
     * available() tek bir false döndürüyordu; ekran bunu "modül paketinizde yok"
     * diye gösteriyordu. Oysa sebep çoğu zaman AI SAĞLAYICISININ yapılandırılmamış
     * olması. Yanlış mesaj kullanıcıyı yanlış yere yönlendiriyordu.
     *
     * @return string|null Kullanılabiliyorsa null
     */
    public function unavailableReason(string $moduleKey, string $action): ?string
    {
        if (!$this->ai->isEnabled()) {
            return 'Yapay zekâ sağlayıcısı yapılandırılmamış — Ayarlar > AI Suite bölümünden '
                . 'sağlayıcı ve API anahtarını girin.';
        }

        if (!$this->entitlement->canAccessModule($moduleKey)) {
            return 'Bu yapay zekâ modülü paketinizde bulunmuyor.';
        }

        if (!$this->credits->hasCredits($this->ai->creditCost($action))) {
            return sprintf(
                'Yapay zekâ krediniz yetersiz (gereken %d, mevcut %d).',
                $this->ai->creditCost($action),
                $this->credits->balance()
            );
        }

        return null;
    }

    /**
     * Bir AI işlemini yürüt.
     *
     * @param callable(AiClient):AiResult $fn  Asıl sağlayıcı çağrısı
     * @param array{type?:string,id?:int}  $ref Kredi/log referansı (ör. ['type'=>'product','id'=>5])
     */
    public function execute(string $moduleKey, string $action, callable $fn, array $ref = []): AiResult
    {
        if (!$this->ai->isEnabled()) {
            throw new RuntimeException('AI Suite şu an kapalı. Yöneticinizle iletişime geçin.');
        }
        if (!$this->entitlement->canAccessModule($moduleKey)) {
            throw new AiNotEntitledException("Bu özellik paketinizde yok: {$moduleKey}");
        }

        $cost   = $this->ai->creditCost($action);
        $userId = $this->session->userId();
        $storeId = $this->tenant->currentStoreId();

        // 1) Krediyi rezerve et (yetersizse burada InsufficientCreditsException atılır → AI çağrısı yapılmaz)
        if ($cost > 0) {
            $this->credits->deduct($action, $cost, $ref, $storeId, $userId);
        }

        $t0 = microtime(true);
        try {
            $result = $fn($this->ai);
        } catch (\Throwable $e) {
            // 2) Hata → iade + error logla
            if ($cost > 0) {
                $this->credits->add($cost, 'refund', $ref, $storeId, $userId, 'AI hatası iadesi: ' . $action);
            }
            $this->safeLog($storeId, $userId, $moduleKey, $action, null, 0, (int) round((microtime(true) - $t0) * 1000), 'error', $e->getMessage());
            throw $e;
        }

        // 3) Başarı → kullanım logla
        $this->safeLog($storeId, $userId, $moduleKey, $action, $result, $cost, (int) round((microtime(true) - $t0) * 1000), 'success', null);
        return $result;
    }

    private function safeLog(int $storeId, ?int $userId, string $moduleKey, string $action, ?AiResult $r, int $credits, int $latencyMs, string $status, ?string $error): void
    {
        try {
            $this->usage->record(
                $storeId, $userId, $moduleKey, $action,
                $r?->provider ?? $this->ai->activeProviderName(),
                $r?->model ?? '',
                $r?->inputTokens ?? 0,
                $r?->outputTokens ?? 0,
                $r !== null ? $this->ai->costUsd($r) : 0.0,
                $credits, $latencyMs, $status, $error
            );
        } catch (\Throwable) {
            // log yazılamazsa akışı bozma
        }
    }
}
