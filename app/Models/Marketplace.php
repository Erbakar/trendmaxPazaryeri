<?php
/**
 * OzyConnect Panel — Marketplace Model
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

final class Marketplace extends BaseModel
{
    protected string $table = 'marketplaces';

    // `marketplaces` tablosu GLOBAL katalogtur (hangi pazaryerleri var, adı, fiyatı).
    // ANCAK bağlantı bilgileri (API anahtarı/secret/extra config/webhook) mağazaya
    // ÖZELDİR ve store_marketplace_configs tablosunda tutulur. Aşağıdaki katman,
    // okunan her katalog satırının üzerine AKTİF MAĞAZANIN bilgilerini bindirir.
    // (Öncesinde anahtarlar global satırdaydı → bütün bayiler aynı pazaryeri
    //  hesabını paylaşıyordu.)
    protected bool $tenantScoped = false;

    /**
     * Mağaza yapılandırmaları (süreç içi önbellek) — store_id ANAHTARLI:
     * [store_id => [marketplace_id => store_marketplace_configs satırı]].
     * Model tekil (singleton) olduğundan çok kiracılı cron/kuyruk döngüsünde bir önceki
     * mağazanın kimlik bilgisi sonraki mağazaya sızmasın diye mağaza başına ayrı tutulur.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $storeConfigCache = [];

    /** @var array<int, int> store_id => önbelleğe alınma zamanı (uzun ömürlü süreçte bayat kalmasın) */
    private array $storeConfigCachedAt = [];

    /** Mağaza yapılandırma önbelleğinin ömrü (sn) */
    private const STORE_CONFIG_TTL = 60;

    /**
     * Slug ile bul
     *
     * @return array<string, mixed>|false
     */
    public function findBySlug(string $slug): array|false
    {
        return $this->applyStoreConfig($this->findBy(['slug' => $slug]));
    }

    /**
     * ID ile bul — mağazaya özel bağlantı bilgileriyle
     *
     * @return array<string, mixed>|false
     */
    public function find(int $id): array|false
    {
        return $this->applyStoreConfig(parent::find($id));
    }

    /**
     * Aktif pazaryerlerini getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        $rows = $this->applyStoreConfigAll($this->all(['is_active' => 1], 'display_name ASC'));

        // Mağazanın kapattığı pazaryeri "aktif" sayılmaz
        return array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
    }

    // ─── Mağazaya özel bağlantı katmanı ───────────────────

    /**
     * Aktif mağazanın bir pazaryeri için bağlantı bilgilerini kaydet (upsert).
     *
     * @param array<string, mixed> $config
     *        is_enabled, api_key_encrypted, api_secret_encrypted,
     *        extra_config_json, webhook_token, display_name
     */
    public function saveStoreConfig(int $marketplaceId, array $config): void
    {
        $storeId = $this->currentStore();
        if ($storeId === null) {
            throw new \RuntimeException('Aktif mağaza bağlamı olmadan pazaryeri bağlantısı kaydedilemez.');
        }

        $existing = $this->db->fetchOne(
            "SELECT * FROM store_marketplace_configs WHERE store_id = :sid AND marketplace_id = :mp LIMIT 1",
            ['sid' => $storeId, 'mp' => $marketplaceId]
        );

        $credentials = $existing !== false
            ? (json_decode((string) ($existing['credentials_encrypted'] ?? ''), true) ?: [])
            : [];
        $settings = $existing !== false
            ? (json_decode((string) ($existing['settings_json'] ?? ''), true) ?: [])
            : [];

        foreach (['api_key_encrypted', 'api_secret_encrypted', 'webhook_token'] as $key) {
            if (array_key_exists($key, $config)) {
                $credentials[$key] = $config[$key];
            }
        }
        foreach (['extra_config_json', 'display_name'] as $key) {
            if (array_key_exists($key, $config)) {
                $settings[$key] = $config[$key];
            }
        }

        $isEnabled = array_key_exists('is_enabled', $config)
            ? (int) (bool) $config['is_enabled']
            : (int) ($existing['is_enabled'] ?? 0);

        $params = [
            'sid'   => $storeId,
            'mp'    => $marketplaceId,
            'en'    => $isEnabled,
            'cred'  => json_encode($credentials, JSON_UNESCAPED_UNICODE),
            'stg'   => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ];

        if ($existing === false) {
            $this->db->query(
                "INSERT INTO store_marketplace_configs
                    (store_id, marketplace_id, is_enabled, credentials_encrypted, settings_json, created_at, updated_at)
                 VALUES (:sid, :mp, :en, :cred, :stg, NOW(), NOW())",
                $params
            );
        } else {
            $this->db->query(
                "UPDATE store_marketplace_configs
                    SET is_enabled = :en, credentials_encrypted = :cred, settings_json = :stg, updated_at = NOW()
                  WHERE store_id = :sid AND marketplace_id = :mp",
                $params
            );
        }

        unset($this->storeConfigCache[$storeId]);
    }

    /**
     * Aktif mağazanın bu pazaryerine bağlantısı var mı? (anahtar girilmiş mi)
     */
    public function isConnectedForStore(int $marketplaceId): bool
    {
        $config = $this->storeConfig($marketplaceId);
        if ($config === null) {
            return false;
        }

        $credentials = json_decode((string) ($config['credentials_encrypted'] ?? ''), true) ?: [];

        return !empty($credentials['api_key_encrypted']) || !empty($credentials['api_secret_encrypted']);
    }

    // ─── Mağazaya özel webhook anahtarı ───────────────────
    // Webhook adresi /webhooks/{slug}/{anahtar}. Anahtar mağaza yapılandırmasında
    // (credentials → webhook_token) tutulur; böylece gelen bildirim hem mağazayı hem
    // pazaryerini tek başına belirler. (Eski global marketplaces.webhook_token çok
    // mağazada hangi mağazaya ait olduğunu söyleyemiyordu.)

    /** Webhook anahtarı biçimi: yalnız harf/rakam, 16-128 karakter (üretilenler 40 hex). */
    public static function isWellFormedWebhookToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{16,128}$/', $token) === 1;
    }

    /**
     * Hiçbir mağaza yapılandırmasında ve eski global satırlarda kullanılmayan, tahmin
     * edilemez yeni bir webhook anahtarı (160 bit → 40 hex).
     */
    public function generateStoreWebhookToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(20));
            $usedGlobally = $this->db->fetchOne(
                "SELECT id FROM marketplaces WHERE webhook_token = :t LIMIT 1",
                ['t' => $token]
            ) !== false;

            if (!$usedGlobally && $this->findStoreConfigsByWebhookToken($token) === []) {
                return $token;
            }
        }

        throw new \RuntimeException('Benzersiz webhook anahtarı üretilemedi; lütfen tekrar deneyin.');
    }

    /**
     * Aktif mağazanın bu pazaryeri için webhook anahtarı. Yoksa (ya da biçimi tanınmıyorsa)
     * yenisini üretip mağaza yapılandırmasına kaydeder. Aktif mağaza yoksa istisna fırlatır.
     */
    public function ensureStoreWebhookToken(int $marketplaceId): string
    {
        $config      = $this->storeConfig($marketplaceId);
        $credentials = $config !== null
            ? (json_decode((string) ($config['credentials_encrypted'] ?? ''), true) ?: [])
            : [];
        $token = trim((string) ($credentials['webhook_token'] ?? ''));

        if (self::isWellFormedWebhookToken($token)) {
            return $token;
        }

        $token = $this->generateStoreWebhookToken();
        $this->saveStoreConfig($marketplaceId, ['webhook_token' => $token]);

        return $token;
    }

    /**
     * Webhook anahtarı bu olan mağaza yapılandırmaları. Oturumsuz (webhook) çağrılır;
     * kiracı bağlamı gerektirmez. Eşleşme PHP tarafında birebir (hash_equals) doğrulanır.
     *
     * @return list<array{store_id: int, marketplace_id: int, is_enabled: int}>
     */
    public function findStoreConfigsByWebhookToken(string $token, ?int $marketplaceId = null): array
    {
        if (!self::isWellFormedWebhookToken($token)) {
            return [];
        }

        // Anahtar yalnız harf/rakam içerdiğinden LIKE joker karakteri taşımaz.
        $sql    = "SELECT store_id, marketplace_id, is_enabled, credentials_encrypted
                     FROM store_marketplace_configs
                    WHERE credentials_encrypted LIKE :needle";
        $params = ['needle' => '%' . $token . '%'];
        if ($marketplaceId !== null) {
            $sql .= ' AND marketplace_id = :mp';
            $params['mp'] = $marketplaceId;
        }

        $matches = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $credentials = json_decode((string) ($row['credentials_encrypted'] ?? ''), true);
            $stored = is_array($credentials) ? (string) ($credentials['webhook_token'] ?? '') : '';
            if ($stored !== '' && hash_equals($stored, $token)) {
                $matches[] = [
                    'store_id'       => (int) $row['store_id'],
                    'marketplace_id' => (int) $row['marketplace_id'],
                    'is_enabled'     => (int) $row['is_enabled'],
                ];
            }
        }

        return $matches;
    }

    /**
     * Katalog satırına aktif mağazanın bağlantı bilgilerini bindir.
     *
     * @param array<string, mixed>|false $row
     * @return array<string, mixed>|false
     */
    private function applyStoreConfig(array|false $row): array|false
    {
        if ($row === false || $this->currentStore() === null) {
            return $row;
        }

        $config = $this->storeConfig((int) ($row['id'] ?? 0));

        // Mağazanın kendi kaydı yoksa GLOBAL anahtarlar sızdırılmaz — boş döner.
        if ($config === null) {
            $row['api_key_encrypted']    = null;
            $row['api_secret_encrypted'] = null;
            $row['extra_config_json']    = '{}';
            $row['webhook_token']        = null;
            $row['last_connected_at']    = null;
            $row['store_connected']      = false;
            return $row;
        }

        $credentials = json_decode((string) ($config['credentials_encrypted'] ?? ''), true) ?: [];
        $settings    = json_decode((string) ($config['settings_json'] ?? ''), true) ?: [];

        $row['api_key_encrypted']    = $credentials['api_key_encrypted'] ?? null;
        $row['api_secret_encrypted'] = $credentials['api_secret_encrypted'] ?? null;
        $row['webhook_token']        = $credentials['webhook_token'] ?? null;
        $row['extra_config_json']    = $settings['extra_config_json'] ?? '{}';
        $row['last_connected_at']    = $config['last_sync_at'] ?? null;
        $row['store_connected']      = !empty($credentials['api_key_encrypted']) || !empty($credentials['api_secret_encrypted']);

        if (!empty($settings['display_name'])) {
            $row['display_name'] = $settings['display_name'];
        }

        // Etkin durum = platform açık VE mağaza açmış
        $row['is_active'] = ((int) ($row['is_active'] ?? 0) === 1 && (int) ($config['is_enabled'] ?? 0) === 1) ? 1 : 0;

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyStoreConfigAll(array $rows): array
    {
        if ($this->currentStore() === null) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            $merged = $this->applyStoreConfig($row);
            if ($merged !== false) {
                $rows[$index] = $merged;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storeConfig(int $marketplaceId): ?array
    {
        if ($marketplaceId <= 0) {
            return null;
        }

        $storeId = $this->currentStore();
        if ($storeId === null) {
            return null;
        }

        $cachedAt = $this->storeConfigCachedAt[$storeId] ?? 0;
        if (!isset($this->storeConfigCache[$storeId]) || (time() - $cachedAt) >= self::STORE_CONFIG_TTL) {
            $configs = [];
            foreach ($this->db->fetchAll(
                "SELECT * FROM store_marketplace_configs WHERE store_id = :sid",
                ['sid' => $storeId]
            ) as $row) {
                $configs[(int) $row['marketplace_id']] = $row;
            }
            $this->storeConfigCache[$storeId] = $configs;
            $this->storeConfigCachedAt[$storeId] = time();
        }

        return $this->storeConfigCache[$storeId][$marketplaceId] ?? null;
    }

    /**
     * Aktif mağaza (yoksa null — admin/CLI/login öncesi bağlam).
     */
    private function currentStore(): ?int
    {
        try {
            $storeId = $this->tenant->currentStoreId();
        } catch (\Throwable) {
            return null;
        }

        return $storeId > 0 ? $storeId : null;
    }

    /**
     * Faz 4 — Bir mağazanın ENTITLEMENT'ine göre aktif pazaryerleri.
     * (is_active=1 ∩ store_marketplaces active+geçerli). Cron/sync per-store bunu kullanır;
     * entitlement olmayan pazaryeri DÖNMEZ.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForStore(int $storeId): array
    {
        return $this->applyStoreConfigAll($this->rawQuery(
            "SELECT m.* FROM marketplaces m
             JOIN store_marketplaces sm ON sm.marketplace_id = m.id
             WHERE m.is_active = 1
               AND sm.store_id = :sid AND sm.status = 'active'
               AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
             ORDER BY m.display_name ASC",
            ['sid' => $storeId]
        ));
    }

    /**
     * Tüm pazaryerlerini getir (aktifler önce, sonra pasifler)
     */
    public function getAll(): array
    {
        return $this->applyStoreConfigAll($this->all([], 'is_active DESC, id ASC'));
    }

    /**
     * Tüm pazaryerlerini getir (aktif+pasif)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllWithStatus(): array
    {
        // Alt sorgular TENANT tablolarına gidiyor — aktif mağazayla sınırlanmazsa
        // her bayi diğerlerinin ürün/sipariş sayısını görür.
        $storeId = $this->currentStore();
        $mpStore = $storeId !== null ? ' AND mp.store_id = :sid1' : '';
        $oStore  = $storeId !== null ? ' AND o.store_id = :sid2' : '';
        $slStore = $storeId !== null ? ' AND sl.store_id = :sid3' : '';
        $params  = $storeId !== null ? ['sid1' => $storeId, 'sid2' => $storeId, 'sid3' => $storeId] : [];

        return $this->applyStoreConfigAll($this->rawQuery(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM marketplace_products mp WHERE mp.marketplace_id = m.id AND mp.is_active = 1{$mpStore}) as product_count,
                    (SELECT COUNT(*) FROM orders o WHERE o.marketplace_id = m.id{$oStore}) as order_count,
                    (SELECT MAX(sl.started_at) FROM sync_logs sl WHERE sl.marketplace_id = m.id AND sl.status = 'success'{$slStore}) as last_sync_at
             FROM marketplaces m
             ORDER BY m.is_active DESC, m.id ASC",
            $params
        ));
    }

    /**
     * Admin add-on kataloğu: pazaryeri fiyat + satılabilirlik listesi (modül kataloğunun pazaryeri karşılığı).
     *
     * @return array<int, array<string, mixed>>
     */
    public function allCatalog(): array
    {
        return $this->rawQuery(
            "SELECT id, slug, display_name, is_active, price_monthly, is_sellable
             FROM marketplaces ORDER BY display_name ASC, id ASC"
        );
    }

    /** Admin: pazaryeri add-on aylık fiyatı + satılabilirlik güncelle. */
    public function updateCatalog(int $id, float $priceMonthly, bool $sellable): void
    {
        $this->db->query(
            "UPDATE marketplaces SET price_monthly = :p, is_sellable = :s WHERE id = :id",
            ['p' => max(0.0, $priceMonthly), 's' => $sellable ? 1 : 0, 'id' => $id]
        );
    }

    /**
     * Merchant'ın add-on olarak satın alabileceği pazaryerleri (aktif + satılabilir).
     * Fiyat filtresi controller'da (price_monthly>0 + sahip olunmayan) uygulanır.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sellable(): array
    {
        return $this->rawQuery(
            "SELECT id, slug, display_name, price_monthly
             FROM marketplaces
             WHERE is_active = 1 AND is_sellable = 1
             ORDER BY display_name ASC"
        );
    }

    /** Add-on olarak pazaryeri aylık fiyatı (kayıt yoksa 0). */
    public function priceOf(int $id): float
    {
        $r = $this->rawQueryOne("SELECT price_monthly FROM marketplaces WHERE id = :id LIMIT 1", ['id' => $id]);
        return $r !== false ? (float) $r['price_monthly'] : 0.0;
    }

    /**
     * API bilgilerini güncelle
     *
     * @param array<string, mixed> $config
     */
    public function updateApiConfig(int $marketplaceId, string $apiKeyEnc, string $apiSecretEnc, array $config = []): bool
    {
        // Anahtarlar MAĞAZAYA yazılır (global katalog satırına değil)
        $data = [
            'api_key_encrypted'    => $apiKeyEnc,
            'api_secret_encrypted' => $apiSecretEnc,
            'is_enabled'           => true,
        ];

        if (!empty($config)) {
            $data['extra_config_json'] = json_encode($config, JSON_UNESCAPED_UNICODE);
        }

        $this->saveStoreConfig($marketplaceId, $data);

        return true;
    }

    /**
     * Son bağlantı zamanını güncelle (mağaza bazında)
     */
    public function updateLastConnected(int $marketplaceId): bool
    {
        $storeId = $this->currentStore();
        if ($storeId === null) {
            return false;
        }

        $this->db->query(
            "UPDATE store_marketplace_configs SET last_sync_at = NOW(), updated_at = NOW()
              WHERE store_id = :sid AND marketplace_id = :mp",
            ['sid' => $storeId, 'mp' => $marketplaceId]
        );

        unset($this->storeConfigCache[$storeId]);

        return true;
    }

    /**
     * Pazaryeri istatistiklerini getir
     *
     * @return array{active_products: int, total_orders: int, monthly_orders: int}
     */
    public function getStats(int $marketplaceId): array
    {
        // Bu sınıf katalog tablosu olduğu için tenant-scoped DEĞİL; ancak aşağıdaki
        // sayımlar TENANT tablolarına gidiyor. store_id filtresi olmadığında bir bayinin
        // sayacı tüm bayilerin siparişlerini gösteriyordu (149 → 218 şikâyeti).
        $storeId = $this->currentStore();
        $storeSql = $storeId !== null ? ' AND store_id = :store_id' : '';
        $storeParam = $storeId !== null ? ['store_id' => $storeId] : [];

        // Aktif ürün sayısı
        $productResult = $this->rawQueryOne(
            "SELECT COUNT(*) as count FROM marketplace_products
             WHERE marketplace_id = :mp_id AND is_active = 1" . $storeSql,
            ['mp_id' => $marketplaceId] + $storeParam
        );

        // Toplam sipariş sayısı
        $orderResult = $this->rawQueryOne(
            "SELECT COUNT(*) as count FROM orders WHERE marketplace_id = :mp_id" . $storeSql,
            ['mp_id' => $marketplaceId] + $storeParam
        );

        // Bu ayki sipariş sayısı
        $monthlyResult = $this->rawQueryOne(
            "SELECT COUNT(*) as count FROM orders
             WHERE marketplace_id = :mp_id
             AND MONTH(created_at) = MONTH(CURRENT_DATE())
             AND YEAR(created_at) = YEAR(CURRENT_DATE())" . $storeSql,
            ['mp_id' => $marketplaceId] + $storeParam
        );

        return [
            'active_products' => (int) ($productResult['count'] ?? 0),
            'total_orders'    => (int) ($orderResult['count'] ?? 0),
            'monthly_orders'  => (int) ($monthlyResult['count'] ?? 0),
        ];
    }
}
