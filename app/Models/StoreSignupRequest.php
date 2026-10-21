<?php
/**
 * OzyConnect Panel — StoreSignupRequest (Faz 8: mağaza-oluştur başvuruları — GLOBAL, pre-tenant)
 *
 * Public form INSERT eder (status=pending). Admin onaylayınca StoreProvisioningService store_id'yi doldurur.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class StoreSignupRequest extends BaseModel
{
    protected string $table = 'store_signup_requests';
    protected bool $tenantScoped = false;

    /** @param array<string,mixed> $d */
    public function createRequest(array $d): int
    {
        $status = in_array($d['status'] ?? '', ['unverified', 'pending', 'approved', 'rejected'], true)
            ? (string) $d['status'] : 'pending';
        $source = in_array($d['source'] ?? '', ['public', 'admin'], true) ? (string) $d['source'] : 'public';
        $token  = isset($d['verify_token']) && $d['verify_token'] !== null && $d['verify_token'] !== ''
            ? mb_substr((string) $d['verify_token'], 0, 64) : null;

        return $this->create([
            'company'              => mb_substr((string) ($d['company'] ?? ''), 0, 200),
            'contact_name'         => mb_substr((string) ($d['contact_name'] ?? ''), 0, 150),
            'phone'                => isset($d['phone']) && $d['phone'] !== null ? mb_substr((string) $d['phone'], 0, 40) : null,
            'email'                => mb_substr((string) ($d['email'] ?? ''), 0, 200),
            'requested_package_id' => isset($d['requested_package_id']) && $d['requested_package_id'] !== null ? (int) $d['requested_package_id'] : null,
            'billing_cycle'        => in_array($d['billing_cycle'] ?? '', ['monthly', 'yearly'], true) ? $d['billing_cycle'] : 'monthly',
            'status'               => $status,
            'source'               => $source,
            'verify_token'         => $token,
            'ip'                   => isset($d['ip']) ? mb_substr((string) $d['ip'], 0, 45) : null,
            // Firma şifresini başvuru formunda belirlediyse hash'i burada
            // taşınır; provizyonda doğrudan kullanılır ve şifre belirleme
            // e-postası beklenmez.
            'password_hash'        => isset($d['password_hash']) && $d['password_hash'] !== ''
                ? (string) $d['password_hash'] : null,
        ]);
    }

    /** E-posta doğrulama token'ı ile bekleyen (unverified) başvuruyu bul. */
    public function findByVerifyToken(string $token): array|false
    {
        if ($token === '') { return false; }
        return $this->rawQueryOne(
            "SELECT * FROM store_signup_requests WHERE verify_token = :t AND status = 'unverified' LIMIT 1",
            ['t' => $token]
        );
    }

    /** E-postayı doğrula: token temizle, verified_at set, status ilerlet (pending/approved). */
    public function markVerified(int $id, string $newStatus = 'pending'): void
    {
        $st = in_array($newStatus, ['pending', 'approved'], true) ? $newStatus : 'pending';
        $this->db->query(
            "UPDATE store_signup_requests SET status = :st, verified_at = NOW(), verify_token = NULL WHERE id = :id",
            ['st' => $st, 'id' => $id]
        );
    }

    public function adminFind(int $id): array|false
    {
        return $this->rawQueryOne(
            "SELECT r.*, p.name AS package_name FROM store_signup_requests r
             LEFT JOIN packages p ON p.id = r.requested_package_id WHERE r.id = :id",
            ['id' => $id]
        );
    }

    /** Admin liste (durum filtreli). */
    public function adminList(?string $status = null, int $limit = 200): array
    {
        $lim = max(1, min(500, $limit));
        if ($status !== null && $status !== '') {
            return $this->rawQuery(
                "SELECT r.*, p.name AS package_name FROM store_signup_requests r
                 LEFT JOIN packages p ON p.id = r.requested_package_id
                 WHERE r.status = :s ORDER BY r.id DESC LIMIT {$lim}",
                ['s' => $status]
            );
        }
        return $this->rawQuery(
            "SELECT r.*, p.name AS package_name FROM store_signup_requests r
             LEFT JOIN packages p ON p.id = r.requested_package_id ORDER BY r.id DESC LIMIT {$lim}"
        );
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        $rows = $this->rawQuery("SELECT status, COUNT(*) c FROM store_signup_requests GROUP BY status");
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; $out['all'] += (int) $r['c']; }
        return $out;
    }

    public function markReviewed(int $id, string $status, ?int $adminId, ?int $storeId = null, ?string $notes = null): void
    {
        $this->db->query(
            "UPDATE store_signup_requests SET status = :st, reviewed_by = :rb, store_id = :sid, notes = :nt WHERE id = :id",
            ['st' => $status, 'rb' => $adminId, 'sid' => $storeId, 'nt' => $notes, 'id' => $id]
        );
    }
}
