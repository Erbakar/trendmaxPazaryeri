<?php
/**
 * OzyConnect Panel — ApiToken Model
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Models;

final class ApiToken extends BaseModel
{
    protected string $table = 'api_tokens';

    /**
     * API Key ile token bul
     *
     * @return array<string, mixed>|false
     */
    public function findByApiKey(string $apiKey): array|false
    {
        // KASITLI GLOBAL: API auth tenant-öncesi yoldur; api_key GLOBAL benzersizdir.
        // Tenant bağlamı bu token'ın store_id'sinden SONRA kurulur (Faz 4).
        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table}` WHERE `api_key` = :key AND `is_active` = 1 LIMIT 1",
            ['key' => $apiKey]
        );
    }

    /**
     * API Key + Secret doğrula, token verisini döndür
     *
     * @return array<string, mixed>|false
     */
    public function verifyCredentials(string $apiKey, string $apiSecret): array|false
    {
        $token = $this->findByApiKey($apiKey);
        if ($token === false) {
            return false;
        }

        if (!password_verify($apiSecret, $token['api_secret_hash'])) {
            return false;
        }

        // Süre kontrolü
        if (!empty($token['expires_at']) && strtotime($token['expires_at']) < time()) {
            return false;
        }

        return $token;
    }

    /**
     * Token'ın belirli bir izne sahip olup olmadığını kontrol et
     */
    public function hasPermission(array $token, string $permission): bool
    {
        $permissions = json_decode($token['permissions'] ?? '[]', true);
        if (!is_array($permissions)) {
            return false;
        }

        // Wildcard desteği: "products:*" → "products:read", "products:write" vs.
        foreach ($permissions as $perm) {
            if ($perm === '*') {
                return true;
            }
            if ($perm === $permission) {
                return true;
            }
            // Wildcard: "products:*" matches "products:read"
            if (str_ends_with($perm, ':*')) {
                $prefix = substr($perm, 0, -1); // "products:"
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * IP whitelist kontrolü
     */
    public function isIpAllowed(array $token, string $ip): bool
    {
        $whitelist = json_decode($token['ip_whitelist'] ?? 'null', true);

        // null veya boş array = tüm IP'lere izin ver
        if ($whitelist === null || empty($whitelist)) {
            return true;
        }

        return in_array($ip, $whitelist, true);
    }

    /**
     * Son kullanım zamanını güncelle
     */
    public function updateLastUsed(int $tokenId): void
    {
        // KASITLI GLOBAL: API auth sonrası, henüz-doğrulanmış token'ın kendi id'siyle çalışır
        // (tenant bağlamı API'de bu noktadan sonra kurulur — Faz 4). id otoritatiftir.
        $this->db->query(
            "UPDATE `{$this->table}` SET `last_used_at` = NOW() WHERE `id` = :id",
            ['id' => $tokenId]
        );
    }

    /**
     * Yeni API token oluştur
     *
     * @param array<string, mixed> $data
     * @return array{id: int, api_key: string, api_secret: string}
     */
    public function generateToken(array $data): array
    {
        $apiKey = bin2hex(random_bytes(16));    // 32 char hex
        $apiSecret = bin2hex(random_bytes(32)); // 64 char hex

        $id = $this->create([
            'name'            => $data['name'],
            'api_key'         => $apiKey,
            'api_secret_hash' => password_hash($apiSecret, PASSWORD_BCRYPT),
            'permissions'     => json_encode($data['permissions'] ?? []),
            'rate_limit'      => (int) ($data['rate_limit'] ?? 60),
            'is_active'       => (int) ($data['is_active'] ?? 1),
            'ip_whitelist'    => !empty($data['ip_whitelist']) ? json_encode($data['ip_whitelist']) : null,
            'expires_at'      => $data['expires_at'] ?? null,
            'created_by'      => $data['created_by'] ?? null,
        ]);

        return [
            'id'         => $id,
            'api_key'    => $apiKey,
            'api_secret' => $apiSecret, // Sadece oluşturma anında gösterilir
        ];
    }

    /**
     * Tüm tokenları kullanıcı bilgileriyle getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function allWithCreator(): array
    {
        // TENANT-SCOPED (web admin token listesi): yalnız aktif mağazanın token'ları.
        return $this->db->fetchAll(
            "SELECT t.*, u.name as creator_name
             FROM `{$this->table}` t
             LEFT JOIN `users` u ON u.id = t.created_by
             WHERE t.store_id = :store_id
             ORDER BY t.created_at DESC",
            ['store_id' => $this->tenantId()]
        );
    }

    /**
     * Token kullanım istatistikleri
     *
     * @return array{total_requests: int, today_requests: int, avg_response_ms: float}
     */
    public function getUsageStats(int $tokenId): array
    {
        // TENANT-SCOPED: api_logs store_id taşır; web admin bağlamında çağrılır.
        $sid = $this->tenantId();
        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM `api_logs` WHERE `api_token_id` = :id AND `store_id` = :sid",
            ['id' => $tokenId, 'sid' => $sid]
        );

        $today = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM `api_logs` WHERE `api_token_id` = :id AND `store_id` = :sid AND DATE(`created_at`) = CURDATE()",
            ['id' => $tokenId, 'sid' => $sid]
        );

        $avg = $this->db->fetchOne(
            "SELECT AVG(`response_time_ms`) as avg_ms FROM `api_logs` WHERE `api_token_id` = :id AND `store_id` = :sid",
            ['id' => $tokenId, 'sid' => $sid]
        );

        return [
            'total_requests' => (int) ($total['cnt'] ?? 0),
            'today_requests' => (int) ($today['cnt'] ?? 0),
            'avg_response_ms' => round((float) ($avg['avg_ms'] ?? 0), 1),
        ];
    }

    /**
     * Tüm izin listesi
     *
     * @return array<string, string>
     */
    public static function availablePermissions(): array
    {
        return [
            'products:read'   => 'Ürünleri görüntüleme',
            'products:write'  => 'Ürün ekleme/güncelleme',
            'orders:read'     => 'Siparişleri görüntüleme',
            'orders:write'    => 'Sipariş oluşturma/güncelleme',
            'categories:read' => 'Kategorileri görüntüleme',
            'brands:read'     => 'Markaları görüntüleme',
            'stock:read'      => 'Stok bilgisi görüntüleme',
            'stock:write'     => 'Stok güncelleme',
            'customers:read'  => 'Müşteri bilgisi görüntüleme',
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       ADMIN — cross-store ham metotlar (YALNIZ AdminApiTokenController'dan çağrılır).
       Tenant filtresi YOK; store_id açıkça verilir. AdminAuthMiddleware arkasındadır.
       ═══════════════════════════════════════════════════════════ */

    /** @return array<int, array<string, mixed>> */
    public function adminAll(?int $storeId = null, string $q = ''): array
    {
        $where = '1=1';
        $params = [];
        if ($storeId !== null && $storeId > 0) {
            $where .= ' AND t.store_id = :sid';
            $params['sid'] = $storeId;
        }
        if ($q !== '') {
            $where .= ' AND (t.name LIKE :q OR t.api_key LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        return $this->db->fetchAll(
            "SELECT t.*, s.name AS store_name, u.name AS creator_name
             FROM `{$this->table}` t
             LEFT JOIN `stores` s ON s.id = t.store_id
             LEFT JOIN `users` u ON u.id = t.created_by
             WHERE {$where}
             ORDER BY t.created_at DESC",
            $params
        );
    }

    /** @return array<string, mixed>|false */
    public function adminFind(int $id): array|false
    {
        return $this->db->fetchOne(
            "SELECT t.*, s.name AS store_name FROM `{$this->table}` t
             LEFT JOIN `stores` s ON s.id = t.store_id WHERE t.id = :id LIMIT 1",
            ['id' => $id]
        );
    }

    /**
     * Belirli bir mağaza için token oluştur (BaseModel::create store_id'yi ezdiği için ham INSERT).
     * @param array<string, mixed> $data
     * @return array{id:int, api_key:string, api_secret:string}
     */
    public function adminCreateForStore(int $storeId, array $data): array
    {
        $apiKey = bin2hex(random_bytes(16));
        $apiSecret = bin2hex(random_bytes(32));
        $this->db->query(
            "INSERT INTO `{$this->table}`
                (`store_id`,`name`,`api_key`,`api_secret_hash`,`permissions`,`rate_limit`,`is_active`,`ip_whitelist`,`expires_at`,`created_by`,`created_at`,`updated_at`)
             VALUES (:store_id,:name,:api_key,:secret,:permissions,:rate_limit,:is_active,:ip_whitelist,:expires_at,NULL,NOW(),NOW())",
            [
                'store_id'     => $storeId,
                'name'         => $data['name'],
                'api_key'      => $apiKey,
                'secret'       => password_hash($apiSecret, PASSWORD_BCRYPT),
                'permissions'  => json_encode($data['permissions'] ?? []),
                'rate_limit'   => (int) ($data['rate_limit'] ?? 60),
                'is_active'    => (int) ($data['is_active'] ?? 1),
                'ip_whitelist' => !empty($data['ip_whitelist']) ? json_encode($data['ip_whitelist']) : null,
                'expires_at'   => $data['expires_at'] ?? null,
            ]
        );
        return ['id' => (int) $this->db->lastInsertId(), 'api_key' => $apiKey, 'api_secret' => $apiSecret];
    }

    public function adminSetActive(int $id, int $isActive): void
    {
        $this->db->query("UPDATE `{$this->table}` SET `is_active` = :a, `updated_at` = NOW() WHERE `id` = :id",
            ['a' => $isActive ? 1 : 0, 'id' => $id]);
    }

    /** @return array{api_key:string, api_secret:string}|false */
    public function adminRegenerate(int $id): array|false
    {
        $row = $this->adminFind($id);
        if ($row === false) {
            return false;
        }
        $apiSecret = bin2hex(random_bytes(32));
        $this->db->query("UPDATE `{$this->table}` SET `api_secret_hash` = :h, `updated_at` = NOW() WHERE `id` = :id",
            ['h' => password_hash($apiSecret, PASSWORD_BCRYPT), 'id' => $id]);
        return ['api_key' => (string) $row['api_key'], 'api_secret' => $apiSecret];
    }

    public function adminDelete(int $id): void
    {
        $this->db->query("DELETE FROM `{$this->table}` WHERE `id` = :id", ['id' => $id]);
    }

    /** @return array{total_requests:int, today_requests:int} */
    public function adminUsageStats(int $tokenId): array
    {
        $total = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM `api_logs` WHERE `api_token_id` = :id", ['id' => $tokenId]);
        $today = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM `api_logs` WHERE `api_token_id` = :id AND DATE(`created_at`) = CURDATE()", ['id' => $tokenId]);
        return ['total_requests' => (int) ($total['cnt'] ?? 0), 'today_requests' => (int) ($today['cnt'] ?? 0)];
    }
}
