<?php
/**
 * OzyConnect Panel — StoreProvisioningService (Faz 8: onaylı signup → çalışan mağaza)
 *
 * Tek transaction: store + owner user + signup işaretle. Commit SONRASI (idempotent, retry-safe):
 * trial abonelik + paket entitlement'ları (SubscriptionService→PackageEntitlementSync) + davet/welcome maili.
 * Owner = GLOBAL Admin rol (id=1, role='admin') — roles tablosu global olduğundan mağaza-başı rol YOK.
 * users.email GLOBAL UNIQUE → provizyon öncesi kontrol. Çift-onay = no-op (store_id sentineli).
 *
 * GENİŞLETİLEBİLİRLİK: provision() ileride add-on/kupon parametrelerine açık; entitlement Resolver+Sync'e
 * delege edildiğinden yeni satış tipleri bu akışı değiştirmez.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\AdminSession;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Package;
use App\Models\PasswordReset;
use App\Models\Store;
use App\Models\StoreSignupRequest;
use App\Models\User;

final class StoreProvisioningService
{
    public function __construct(
        private readonly Database $db,
        private readonly Store $stores,
        private readonly User $users,
        private readonly TenantContext $tenant,
        private readonly Package $packages,
        private readonly StoreSignupRequest $signups,
        private readonly SubscriptionService $subscriptions,
        private readonly PasswordReset $passwordReset,
        private readonly NotificationService $notifier,
        private readonly AdminSession $adminSession,
        private readonly Logger $logger,
    ) {}

    /**
     * @return array{store_id:int, user_id:int, subscription_id:int, reset_token:?string, already:bool}
     * @throws \RuntimeException kullanıcıya gösterilebilir hata (e-posta çakışması, paket yok vb.)
     */
    public function provision(int $signupId, int $packageId, string $cycle): array
    {
        $package = $this->packages->adminFind($packageId);
        if ($package === false) {
            throw new \RuntimeException('Seçilen paket bulunamadı.');
        }
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $adminId = (int) $this->adminSession->adminId() ?: null;

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            // Idempotency: signup satırını kilitle.
            $signup = $this->db->fetchOne("SELECT * FROM store_signup_requests WHERE id = :id FOR UPDATE", ['id' => $signupId]);
            if ($signup === false) {
                $pdo->rollBack();
                throw new \RuntimeException('Başvuru bulunamadı.');
            }

            // Zaten provizyonlanmış → no-op; eksik abonelik varsa commit sonrası tamamla.
            if (!empty($signup['store_id'])) {
                $pdo->commit();
                $storeId = (int) $signup['store_id'];
                $subId = $this->subscriptions->startTrial($storeId, $packageId, $cycle, (int) $package['trial_days'], $adminId);
                return ['store_id' => $storeId, 'user_id' => 0, 'subscription_id' => $subId, 'reset_token' => null, 'already' => true];
            }

            $email = trim((string) $signup['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $pdo->rollBack();
                throw new \RuntimeException('Başvurudaki e-posta geçersiz.');
            }
            // users.email GLOBAL UNIQUE — tek-param global kontrol (native prepares; tekrarlı param kullanma).
            if ($this->db->fetchOne("SELECT id FROM users WHERE email = :email LIMIT 1", ['email' => $email]) !== false) {
                $pdo->rollBack();
                throw new \RuntimeException('Bu e-posta zaten bir kullanıcıya ait. Provizyon iptal edildi.');
            }

            // 1) Store
            $company = trim((string) $signup['company']) ?: 'Mağaza';
            $slug = $this->uniqueSlug($company);
            $storeId = $this->stores->create([
                'name'   => mb_substr($company, 0, 200),
                'slug'   => $slug,
                'status' => 'active',
                'plan'   => (string) $package['slug'],
            ]);

            // 2) Owner user — GLOBAL Admin rol (id=1, role='admin'). TenantContext geçici set.
            // Firma şifresini başvuru formunda belirlediyse onu kullan.
            // Belirlememişse tek kullanımlık rastgele şifre atanır ve
            // aşağıda şifre belirleme bağlantısı gönderilir.
            $sifreHash = trim((string) ($signup['password_hash'] ?? ''));
            $sifreVar  = $sifreHash !== '' && str_starts_with($sifreHash, '$2y$');

            $this->tenant->setStoreId($storeId);
            try {
                $userId = $this->users->createUser([
                    'name'      => mb_substr(trim((string) $signup['contact_name']) ?: $company, 0, 150),
                    'email'     => $email,
                    'password'  => bin2hex(random_bytes(18)), // tek-kullanımlık
                    'role'      => 'admin',
                    'role_id'   => 1,
                    'is_active' => 1,
                ]);

                if ($sifreVar) {
                    // createUser() düz metni hash'liyor; başvurudaki hash'i
                    // olduğu gibi yazmak için doğrudan güncelliyoruz.
                    $this->db->query(
                        "UPDATE `users` SET `password_hash` = :h WHERE `id` = :id LIMIT 1",
                        ['h' => $sifreHash, 'id' => $userId]
                    );
                }
            } finally {
                $this->tenant->clear();
            }

            // 3) Signup işaretle
            $this->signups->markReviewed($signupId, 'approved', $adminId, $storeId, (string) ($signup['notes'] ?? ''));

            $this->auditProvision($storeId, $adminId, 'store.provision.create', 'store#' . $storeId);
            $pdo->commit();
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $this->logger->error('Provizyon hatası: ' . $e->getMessage(), 'Provision');
            throw new \RuntimeException('Provizyon sırasında beklenmeyen hata: ' . $e->getMessage());
        }

        // ── Commit sonrası (idempotent / retry-safe) ──
        $subId = $this->subscriptions->startTrial($storeId, $packageId, $cycle, (int) $package['trial_days'], $adminId);
        $this->auditProvision($storeId, $adminId, 'store.provision.complete', 'store#' . $storeId . '/sub#' . $subId);

        // 4) Karşılama maili (best-effort; SMTP yoksa sessizce geçilir)
        $resetToken = null;
        $girisUrl   = $this->baseUrl() . '/login';

        try {
            if ($sifreVar) {
                // Şifre başvuruda belirlendi — sıfırlama bağlantısı ÜRETİLMEZ.
                // Posta ulaşmasa bile firma hemen giriş yapabilir.
                $this->notifier->sendTemplate(
                    $email,
                    'welcome',
                    ['name' => (string) $signup['contact_name'], 'login_url' => $girisUrl],
                    '{site_name} hesabınız hazır',
                    '<p>Merhaba {name}, hesabınız oluşturuldu.</p>'
                    . '<p>Başvuruda belirlediğiniz şifreyle giriş yapabilirsiniz: '
                    . '<a href="' . htmlspecialchars($girisUrl, ENT_QUOTES) . '">'
                    . htmlspecialchars($girisUrl, ENT_QUOTES) . '</a></p>'
                    . '<p>Kullanıcı adınız: ' . htmlspecialchars($email, ENT_QUOTES) . '</p>'
                );
            } else {
                $td = $this->passwordReset->createToken($email);
                $resetToken = (string) ($td['token'] ?? '');
                $resetUrl = $this->baseUrl() . '/password/reset?token=' . urlencode($resetToken);
                $this->notifier->sendTemplate(
                    $email,
                    'welcome',
                    ['name' => (string) $signup['contact_name'], 'reset_url' => $resetUrl],
                    '{site_name} hesabınız hazır',
                    '<p>Merhaba {name}, hesabınız oluşturuldu. Şifre belirleyin: <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">' . htmlspecialchars($resetUrl, ENT_QUOTES) . '</a></p>'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Welcome mail/token üretilemedi: ' . $e->getMessage(), 'Provision');
        }

        return ['store_id' => $storeId, 'user_id' => $userId, 'subscription_id' => $subId, 'reset_token' => $resetToken, 'already' => false];
    }

    private function uniqueSlug(string $company): string
    {
        $base = $this->slugify($company) ?: 'magaza';
        $slug = $base;
        $i = 1;
        while ($this->db->fetchOne("SELECT id FROM stores WHERE slug = :s", ['s' => $slug]) !== false) {
            $i++;
            $slug = $base . '-' . $i;
            if ($i > 999) { $slug = $base . '-' . bin2hex(random_bytes(3)); break; }
        }
        return mb_substr($slug, 0, 200);
    }

    private function slugify(string $s): string
    {
        $tr = ['ç'=>'c','ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
        $s = strtr($s, $tr);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        return trim($s, '-');
    }

    private function baseUrl(): string
    {
        if (function_exists('app_base_url')) {
            $u = (string) app_base_url();
            if ($u !== '' && $u !== '/') { return rtrim($u, '/'); }
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function auditProvision(int $storeId, ?int $adminId, string $action, string $target): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_audit_log (user_id, admin_user_id, actor_type, actor_email, store_id, action, target, result, ip_address, created_at)
                 VALUES (NULL, :aid, 'admin', :email, :sid, :act, :tgt, 'success', :ip, NOW())",
                [
                    'aid' => $adminId, 'email' => $this->adminSession->adminEmail(), 'sid' => $storeId,
                    'act' => $action, 'tgt' => $target, 'ip' => $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null),
                ]
            );
        } catch (\Throwable) {
            // audit yazılamazsa akışı bozma
        }
    }
}
