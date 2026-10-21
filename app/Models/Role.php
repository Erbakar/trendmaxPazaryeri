<?php
declare(strict_types=1);

namespace App\Models;

final class Role extends BaseModel
{
    protected string $table = 'roles';
    protected bool $tenantScoped = false; // GLOBAL sistem rolleri (store_id YOK)

    public const PERMISSION_MAP = [
        'dashboard' => [
            'view' => 'Dashboard görüntüle',
        ],
        'orders' => [
            'view' => 'Siparişleri gör',
            'ship' => 'Kargola',
            'archive' => 'Arşivle',
        ],
        'returns' => [
            'view' => 'İadeleri gör',
            'approve' => 'İade onayla',
            'reject' => 'İade reddet',
        ],
        'products' => [
            'view' => 'Ürünleri gör',
            'create' => 'Ürün oluştur',
            'edit' => 'Ürün düzenle',
            'sync' => 'Ürün senkronize et',
        ],
        'categories' => [
            'view' => 'Kategorileri gör',
            'edit' => 'Kategori düzenle',
            'sync' => 'Kategori senkronize et',
        ],
        'brands' => [
            'view' => 'Markaları gör',
            'edit' => 'Marka düzenle',
            'sync' => 'Marka senkronize et',
        ],
        'customers' => [
            'view' => 'Müşterileri gör',
            'edit' => 'Müşteri düzenle',
        ],
        'invoices' => [
            'view' => 'Faturaları gör',
            'create' => 'Fatura kes',
            'send' => 'Pazaryerine gönder',
            'cancel' => 'Fatura iptal et',
        ],
        'finance' => [
            'view' => 'Finansı gör',
            'sync' => 'Finans senkronize et',
        ],
        'marketplaces' => [
            'view' => 'Pazaryerlerini gör',
            'edit' => 'Pazaryeri ayarlarını düzenle',
        ],
        'settings' => [
            'view' => 'Ayarları gör',
            'edit' => 'Ayarları düzenle',
        ],
        'users' => [
            'view' => 'Kullanıcıları gör',
            'create' => 'Kullanıcı oluştur',
            'edit' => 'Kullanıcı düzenle',
            'delete' => 'Kullanıcı sil',
        ],
        'roles' => [
            'view' => 'Rolleri gör',
            'create' => 'Rol oluştur',
            'edit' => 'Rol düzenle',
            'delete' => 'Rol sil',
        ],
        'logs' => [
            'view' => 'Logları gör',
        ],
        'backups' => [
            'view' => 'Yedekleri gör',
            'create' => 'Yedek oluştur',
            'restore' => 'Yedekten geri yükle',
            'delete' => 'Yedek sil',
        ],
        'sync' => [
            'trigger' => 'Senkron başlat',
        ],
        'billing' => [
            'view' => 'Abonelik & faturaları gör',
        ],
    ];

    public const MODULE_LABELS = [
        'dashboard' => 'Dashboard',
        'orders' => 'Siparişler',
        'returns' => 'İadeler',
        'products' => 'Ürünler',
        'categories' => 'Kategoriler',
        'brands' => 'Markalar',
        'customers' => 'Müşteriler',
        'invoices' => 'Faturalar',
        'finance' => 'Finans',
        'marketplaces' => 'Pazaryerleri',
        'settings' => 'Ayarlar',
        'users' => 'Kullanıcılar',
        'roles' => 'Roller',
        'logs' => 'Loglar',
        'backups' => 'Yedekler',
        'sync' => 'Senkronizasyon',
        'billing' => 'Abonelik',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->rawQuery(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
             FROM roles r
             ORDER BY r.is_system DESC, r.name ASC"
        );
    }

    /**
     * @return array<int, string>
     */
    public function getPermissions(int $roleId): array
    {
        $role = $this->find($roleId);
        if ($role === false) {
            return [];
        }

        $permissions = json_decode((string) ($role['permissions_json'] ?? '[]'), true);
        return is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];
    }

    public function hasPermission(int $roleId, string $permission): bool
    {
        $permissions = $this->getPermissions($roleId);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function findBySlug(string $slug): array|false
    {
        return $this->findBy(['slug' => $slug]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(int $roleId): array
    {
        return $this->rawQuery(
            "SELECT id, name, email, is_active, last_login_at
             FROM users
             WHERE role_id = :role_id
             ORDER BY name ASC",
            ['role_id' => $roleId]
        );
    }
}
