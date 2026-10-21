<?php
/**
 * OzyConnect Panel — AdminRole (platform admin RBAC; merchant Role'dan AYRI)
 *
 * admin_roles tablosu + admin_users.admin_role_id. Yetki anahtarları PERMISSION_MAP'te.
 * '*' = tüm yetkiler. admin_users.admin_role_id NULL = Süper Admin (geri uyum).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class AdminRole extends BaseModel
{
    protected string $table = 'admin_roles';
    protected bool $tenantScoped = false; // platform geneli (store_id YOK)

    /** Admin paneli yetki haritası: modül => [aksiyon => etiket]. */
    public const PERMISSION_MAP = [
        'stores' => [
            'view'    => 'Mağazaları gör',
            'edit'    => 'Mağaza oluştur/düzenle',
            'suspend' => 'Askıya al / aktifleştir',
            'delete'  => 'Mağaza sil',
        ],
        'documents' => [
            'view'   => 'Belgeleri gör',
            'review' => 'Belge onayla / reddet',
        ],
        'packages' => [
            'view' => 'Paket & modülleri gör',
            'edit' => 'Paket & modül düzenle',
        ],
        'subscriptions' => [
            'view'   => 'Abonelikleri gör',
            'manage' => 'Abonelik yönet (askıya/iptal/yenile)',
        ],
        'invoices' => [
            'view'  => 'Faturaları gör',
            'issue' => 'e-Arşiv kes / iptal',
        ],
        'payments' => [
            'view'    => 'Ödemeleri gör',
            'confirm' => 'Ödeme onayla / reddet',
        ],
        'support' => [
            'view'  => 'Destek taleplerini gör',
            'reply' => 'Destek talebi yanıtla',
        ],
        'leads' => [
            'view' => 'Gelen kutusu / başvuruları gör',
        ],
        'content' => [
            'view' => 'Site içeriğini gör',
            'edit' => 'Site içeriği düzenle (modül/blog/SSS/referans)',
        ],
        'ops' => [
            'view' => 'Operasyon panelini gör (kuyruk/worker/sağlık/denetim)',
        ],
        'logs' => [
            'view' => 'Sistem loglarını gör',
        ],
        'settings' => [
            'view' => 'Ayarları gör',
            'edit' => 'Ayarları düzenle (marka/e-posta/ödeme/yedek)',
        ],
        'admins' => [
            'view'   => 'Admin & rolleri gör',
            'manage' => 'Admin kullanıcı & rol yönet',
        ],
        'api' => [
            'view'   => 'API anahtarlarını gör',
            'manage' => 'API anahtarı yönet',
        ],
    ];

    public const MODULE_LABELS = [
        'stores'        => 'Mağazalar',
        'documents'     => 'Belgeler',
        'packages'      => 'Paket & Modül',
        'subscriptions' => 'Abonelikler',
        'invoices'      => 'Faturalar',
        'payments'      => 'Ödemeler',
        'support'       => 'Destek',
        'leads'         => 'Gelen Kutusu',
        'content'       => 'Site İçeriği',
        'ops'           => 'Operasyon',
        'logs'          => 'Loglar',
        'settings'      => 'Ayarlar',
        'admins'        => 'Admin & Roller',
        'api'           => 'API',
    ];

    /** @return array<int, array<string, mixed>> */
    public function allWithCounts(): array
    {
        return $this->rawQuery(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM admin_users u WHERE u.admin_role_id = r.id) AS admin_count
             FROM admin_roles r
             ORDER BY r.is_system DESC, r.name ASC"
        );
    }

    /** @return array<int, string> */
    public function getPermissions(int $roleId): array
    {
        $role = $this->find($roleId);
        if ($role === false) {
            return [];
        }
        $perms = json_decode((string) ($role['permissions_json'] ?? '[]'), true);
        return is_array($perms) ? array_values(array_map('strval', $perms)) : [];
    }

    public function findBySlug(string $slug): array|false
    {
        return $this->findBy(['slug' => $slug]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getAdmins(int $roleId): array
    {
        return $this->rawQuery(
            "SELECT id, name, email, is_active, last_login_at
             FROM admin_users WHERE admin_role_id = :rid ORDER BY name ASC",
            ['rid' => $roleId]
        );
    }
}
