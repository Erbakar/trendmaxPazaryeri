<?php
/**
 * OzyConnect Panel — AdminAuthService (Faz 3)
 *
 * Admin kimlik akışı: parola → 2FA (TOTP veya recovery code) → tam oturum. Merchant'tan
 * tamamen ayrı (admin_users + AdminSession). Şifre sıfırlama (tek kullanımlık+süreli),
 * recovery code (tek kullanımlık), giriş/IP/cihaz/başarısız-giriş + admin audit logları.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\AdminSession;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TotpService;
use App\Models\AdminUser;

final class AdminAuthService
{
    public function __construct(
        private readonly Database $db,
        private readonly AdminSession $adminSession,
        private readonly TotpService $totp,
        private readonly AdminUser $adminUserModel,
        private readonly Logger $logger,
    ) {}

    // ─── Giriş (parola) ────────────────────────────────────────────────────

    /**
     * Parola adımı. Başarılıysa oturum PENDING (2FA bekler). Her deneme loglanır.
     * @return array<string,mixed>|false admin satırı | false
     */
    public function attemptLogin(string $email, string $password, string $ip = '', string $userAgent = ''): array|false
    {
        $admin = $this->adminUserModel->findByEmail($email);
        if ($admin === false || !$this->adminUserModel->verifyPassword($password, (string) $admin['password_hash'])) {
            $this->logAttempt($admin['id'] ?? null, $email, $ip, $userAgent, false, 'bad_password');
            return false;
        }
        $this->adminSession->startPending($admin);
        // 2FA gerekmiyorsa (totp_enabled=0) doğrudan tamamla; aksi halde PENDING kalır.
        if ((int) ($admin['totp_enabled'] ?? 0) !== 1) {
            $this->adminSession->complete2fa();
            $this->finishLogin((int) $admin['id'], $email, $ip, $userAgent, 'ok_no2fa');
        }
        return $admin;
    }

    // ─── 2FA ────────────────────────────────────────────────────────────────

    /** PENDING admin için TOTP doğrula → tamamla. */
    public function verifyTotp(string $code, string $ip = '', string $userAgent = ''): bool
    {
        $adminId = $this->adminSession->adminId();
        if ($adminId === null || !$this->adminSession->isPending2fa()) {
            return false;
        }
        $admin = $this->adminUserModel->find($adminId);
        if ($admin === false || empty($admin['totp_secret']) || !$this->totp->verify((string) $admin['totp_secret'], $code)) {
            $this->logAttempt($adminId, (string) ($admin['email'] ?? ''), $ip, $userAgent, false, 'bad_2fa');
            return false;
        }
        $this->adminSession->complete2fa();
        $this->finishLogin($adminId, (string) $admin['email'], $ip, $userAgent, 'ok_totp');
        return true;
    }

    /** PENDING admin için TEK KULLANIMLIK recovery code → tamamla. */
    public function useRecoveryCode(string $code, string $ip = '', string $userAgent = ''): bool
    {
        $adminId = $this->adminSession->adminId();
        if ($adminId === null || !$this->adminSession->isPending2fa()) {
            return false;
        }
        $hash = $this->hashToken($this->normalizeCode($code));
        $row = $this->db->fetchOne(
            "SELECT id FROM admin_recovery_codes WHERE admin_user_id = :a AND code_hash = :h AND used_at IS NULL LIMIT 1",
            ['a' => $adminId, 'h' => $hash]
        );
        if ($row === false) {
            $this->logAttempt($adminId, '', $ip, $userAgent, false, 'bad_recovery');
            return false;
        }
        // TEK KULLANIM: kullanıldı işaretle
        $this->db->query("UPDATE admin_recovery_codes SET used_at = NOW() WHERE id = :id", ['id' => (int) $row['id']]);
        $this->adminSession->complete2fa();
        $this->finishLogin($adminId, '', $ip, $userAgent, 'ok_recovery');
        $this->audit('admin.recovery_used', "admin#{$adminId}", []);
        return true;
    }

    // ─── TOTP / recovery kurulum (onboarding) ────────────────────────────────

    public function enableTotp(int $adminId, string $secretBase32): void
    {
        $this->adminUserModel->update($adminId, ['totp_secret' => $secretBase32, 'totp_enabled' => 1]);
        $this->audit('admin.totp_enabled', "admin#{$adminId}", []);
    }

    /** N adet tek-kullanımlık recovery code üret (plaintext döner; hash saklanır). @return string[] */
    public function generateRecoveryCodes(int $adminId, int $count = 10): array
    {
        $this->db->query("DELETE FROM admin_recovery_codes WHERE admin_user_id = :a", ['a' => $adminId]);
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4))); // 8-8 hex
            $codes[] = $code;
            $this->db->query(
                "INSERT INTO admin_recovery_codes (admin_user_id, code_hash, created_at) VALUES (:a, :h, NOW())",
                ['a' => $adminId, 'h' => $this->hashToken($this->normalizeCode($code))]
            );
        }
        $this->audit('admin.recovery_generated', "admin#{$adminId}", ['count' => $count]);
        return $codes;
    }

    // ─── Şifre sıfırlama (tek kullanımlık + süreli) ──────────────────────────

    /** Token üret + hash sakla; düz token döner (e-posta ile gönderilir). null = admin yok. */
    public function createPasswordReset(string $email, int $ttlMinutes = 30): ?string
    {
        $admin = $this->adminUserModel->findByEmail($email);
        if ($admin === false) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->db->query(
            "INSERT INTO admin_password_resets (admin_user_id, token_hash, expires_at, created_at)
             VALUES (:a, :h, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), NOW())",
            ['a' => (int) $admin['id'], 'h' => $this->hashToken($token), 'ttl' => $ttlMinutes]
        );
        $this->audit('admin.password_reset_requested', "admin#{$admin['id']}", []);
        return $token;
    }

    /** Token doğrula (hash + süre + TEK KULLANIM) → parolayı değiştir. */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $row = $this->db->fetchOne(
            "SELECT id, admin_user_id FROM admin_password_resets
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() LIMIT 1",
            ['h' => $this->hashToken($token)]
        );
        if ($row === false) {
            return false;
        }
        $this->adminUserModel->updatePasswordHash((int) $row['admin_user_id'], $newPassword);
        // TEK KULLANIM
        $this->db->query("UPDATE admin_password_resets SET used_at = NOW() WHERE id = :id", ['id' => (int) $row['id']]);
        $this->audit('admin.password_reset', "admin#{$row['admin_user_id']}", []);
        return true;
    }

    // ─── Çıkış ────────────────────────────────────────────────────────────────

    public function logout(): void
    {
        $id = $this->adminSession->adminId();
        $this->adminSession->logout();
        if ($id !== null) {
            $this->audit('admin.logout', "admin#{$id}", []);
        }
    }

    // ─── iç yardımcılar ───────────────────────────────────────────────────────

    private function finishLogin(int $adminId, string $email, string $ip, string $ua, string $reason): void
    {
        $this->adminUserModel->touchLastLogin($adminId);
        $this->logAttempt($adminId, $email, $ip, $ua, true, $reason);
        $this->audit('admin.login', "admin#{$adminId}", ['reason' => $reason]);
    }

    private function logAttempt(?int $adminId, string $email, string $ip, string $ua, bool $success, string $reason): void
    {
        try {
            $this->db->query(
                "INSERT INTO admin_login_attempts (admin_user_id, email, ip_address, user_agent, success, reason, created_at)
                 VALUES (:a, :e, :ip, :ua, :s, :r, NOW())",
                ['a' => $adminId, 'e' => $email ?: null, 'ip' => $ip ?: null, 'ua' => $ua ?: null, 's' => $success ? 1 : 0, 'r' => $reason]
            );
        } catch (\Throwable $e) {
            // Denetim izinin yazılamaması sessiz kalmamalı (login_attempts düştü/kilitlendi/şema değişti vb).
            $this->logger->critical('admin_login_attempts kaydı yazılamadı: ' . $e->getMessage(), 'AdminAuth', [
                'admin_id' => $adminId, 'email' => $email, 'success' => $success, 'reason' => $reason,
            ]);
        }
    }

    private function audit(string $action, string $target, array $detail): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_audit_log (user_id, admin_user_id, actor_type, actor_email, action, target, result, detail_json, ip_address, created_at)
                 VALUES (NULL, :aid, 'admin', :em, :ac, :tg, 'success', :dj, :ip, NOW())",
                ['aid' => $this->adminSession->adminId(), 'em' => $this->adminSession->adminEmail(), 'ac' => $action, 'tg' => $target,
                 'dj' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'ip' => $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null)]
            );
        } catch (\Throwable $e) {
            // system_audit_log yazılamazsa görünür ol — admin işlemlerinin izi kayboluyor demektir.
            $this->logger->critical('system_audit_log kaydı yazılamadı: ' . $e->getMessage(), 'AdminAuth', [
                'action' => $action, 'target' => $target,
            ]);
        }
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
