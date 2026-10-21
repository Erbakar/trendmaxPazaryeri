<?php
/**
 * OzyConnect Panel — AdminAuthorizationService (platform admin RBAC karar katmanı)
 *
 * Router her route'ta authorizeRoute() çağırır (merchant AuthorizationService'in admin eşi).
 * Admin oturumu yoksa (merchant route) NO-OP. admin_users.admin_role_id NULL = Süper Admin.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\AdminSession;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class AdminAuthorizationService
{
    /**
     * Admin controller → metot → gerekli yetki. '*' = controller varsayılanı.
     * Haritada OLMAYAN controller = admin RBAC'a tabi DEĞİL (örn AdminController dashboard/2fa).
     * @var array<string, array<string,string>>
     */
    private const ROUTE_PERMISSION_MAP = [
        \App\Controllers\StoreController::class => [
            '*' => 'stores.view',
            'update' => 'stores.edit', 'create' => 'stores.edit', 'store' => 'stores.edit',
            'setStatus' => 'stores.suspend', 'destroy' => 'stores.delete', 'delete' => 'stores.delete',
            'verify' => 'documents.review',
            'entitlements' => 'stores.edit', 'marketplace' => 'stores.edit', 'module' => 'stores.edit',
            'issueEarsiv' => 'invoices.issue', 'cancelEarsiv' => 'invoices.issue',
        ],
        \App\Controllers\AdminDocumentController::class => [
            '*' => 'documents.view', 'review' => 'documents.review',
        ],
        \App\Controllers\AdminDocumentTypeController::class => [
            '*' => 'documents.view',
        ],
        \App\Controllers\AdminPackageController::class => [
            '*' => 'packages.view',
            'store' => 'packages.edit', 'update' => 'packages.edit', 'create' => 'packages.edit',
            'copy' => 'packages.edit', 'toggle' => 'packages.edit', 'delete' => 'packages.edit',
        ],
        \App\Controllers\AdminModuleCatalogController::class => [
            '*' => 'packages.view', 'update' => 'packages.edit',
        ],
        \App\Controllers\AdminSubscriptionController::class => [
            '*' => 'subscriptions.view',
            'suspend' => 'subscriptions.manage', 'cancel' => 'subscriptions.manage',
            'reactivate' => 'subscriptions.manage', 'resync' => 'subscriptions.manage',
        ],
        \App\Controllers\AdminInvoiceController::class => [
            '*' => 'invoices.view', 'issueEarsiv' => 'invoices.issue', 'cancelEarsiv' => 'invoices.issue',
        ],
        \App\Controllers\AdminPaymentController::class => [
            '*' => 'payments.view', 'confirm' => 'payments.confirm', 'reject' => 'payments.confirm',
        ],
        \App\Controllers\AdminPaymentSettingsController::class => [
            '*' => 'settings.view', 'save' => 'settings.edit',
        ],
        \App\Controllers\AdminSupportController::class => [
            '*' => 'support.view',
        ],
        \App\Controllers\AdminLeadController::class => [
            '*' => 'leads.view',
        ],
        \App\Controllers\AdminSignupController::class => [
            '*' => 'stores.view', 'approve' => 'stores.edit', 'reject' => 'stores.edit', 'saveSettings' => 'stores.edit',
        ],
        \App\Controllers\AdminSiteModuleController::class    => ['*' => 'content.view', 'update' => 'content.edit', 'save' => 'content.edit', 'store' => 'content.edit', 'toggle' => 'content.edit', 'delete' => 'content.edit'],
        \App\Controllers\AdminScreenshotController::class    => ['*' => 'content.view', 'update' => 'content.edit', 'save' => 'content.edit', 'store' => 'content.edit', 'delete' => 'content.edit'],
        \App\Controllers\AdminTestimonialController::class   => ['*' => 'content.view', 'update' => 'content.edit', 'save' => 'content.edit', 'store' => 'content.edit', 'delete' => 'content.edit'],
        \App\Controllers\AdminBlogController::class          => ['*' => 'content.view', 'update' => 'content.edit', 'save' => 'content.edit', 'store' => 'content.edit', 'delete' => 'content.edit'],
        \App\Controllers\AdminFaqController::class           => ['*' => 'content.view', 'update' => 'content.edit', 'save' => 'content.edit', 'store' => 'content.edit', 'delete' => 'content.edit'],
        \App\Controllers\AdminMailTemplateController::class  => ['*' => 'content.view', 'update' => 'content.edit', 'save' => 'content.edit'],
        \App\Controllers\AdminUpdateController::class        => ['*' => 'content.view', 'store' => 'content.edit', 'update' => 'content.edit', 'publish' => 'content.edit', 'delete' => 'content.edit'],
        \App\Controllers\AdminBrandingController::class      => ['*' => 'settings.view', 'save' => 'settings.edit', 'update' => 'settings.edit'],
        \App\Controllers\AdminMailSettingsController::class  => ['*' => 'settings.view', 'save' => 'settings.edit', 'testSmtp' => 'settings.edit', 'testEarsiv' => 'settings.edit'],
        \App\Controllers\AdminSyncSettingsController::class  => ['*' => 'settings.view', 'save' => 'settings.edit', 'regenerateToken' => 'settings.edit'],
        \App\Controllers\OpsController::class                => ['*' => 'ops.view'],
        \App\Controllers\LogController::class                => ['*' => 'logs.view'],
        \App\Controllers\BackupController::class             => ['*' => 'settings.view', 'create' => 'settings.edit', 'restore' => 'settings.edit', 'delete' => 'settings.edit', 'download' => 'settings.edit'],
        \App\Controllers\AdminApiTokenController::class      => ['*' => 'api.view', 'store' => 'api.manage', 'create' => 'api.manage', 'revoke' => 'api.manage', 'delete' => 'api.manage'],
        \App\Controllers\AdminUserController::class          => ['*' => 'admins.view', 'store' => 'admins.manage', 'create' => 'admins.manage', 'update' => 'admins.manage', 'edit' => 'admins.view', 'delete' => 'admins.manage', 'resetPassword' => 'admins.manage'],
        \App\Controllers\AdminRoleController::class          => ['*' => 'admins.view', 'store' => 'admins.manage', 'create' => 'admins.manage', 'update' => 'admins.manage', 'edit' => 'admins.view', 'delete' => 'admins.manage'],
    ];

    /** @var array<int,string>|null per-request cache */
    private ?array $cache = null;

    public function __construct(
        private readonly Database $db,
        private readonly AdminSession $adminSession,
        private readonly Response $response,
    ) {}

    /** Aktif adminin yetkileri. admin_role_id NULL → ['*'] (süper). Admin yoksa []. */
    public function permissions(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $id = $this->adminSession->adminId();
        if ($id === null || $id <= 0) {
            return $this->cache = [];
        }
        $row = $this->db->fetchOne(
            "SELECT au.admin_role_id, ar.permissions_json
             FROM admin_users au
             LEFT JOIN admin_roles ar ON ar.id = au.admin_role_id
             WHERE au.id = :id",
            ['id' => $id]
        );
        if ($row === false) {
            return $this->cache = [];
        }
        if ($row['admin_role_id'] === null) {
            return $this->cache = ['*']; // rolsüz admin = süper (geri uyum, kilitlenme yok)
        }
        $perms = json_decode((string) ($row['permissions_json'] ?? '[]'), true);
        return $this->cache = is_array($perms) ? array_values(array_map('strval', $perms)) : [];
    }

    public function hasPermission(string $permission): bool
    {
        if ($permission === '') {
            return true;
        }
        $perms = $this->permissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** @param array<int,string> $permissions */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }
        foreach ($permissions as $p) {
            if ($this->hasPermission($p)) {
                return true;
            }
        }
        return false;
    }

    /** Router merkezî kancası. Admin oturumu yoksa veya controller haritada değilse NO-OP. */
    public function authorizeRoute(Request $request, string $controllerClass, string $methodName): void
    {
        if ($this->adminSession->adminId() === null) {
            return; // admin oturumu yok → merchant/public route, dokunma
        }
        $map = self::ROUTE_PERMISSION_MAP[$controllerClass] ?? null;
        if ($map === null) {
            return; // haritalanmamış admin controller (AdminController dashboard/2fa vb.) → serbest
        }
        $perm = $map[$methodName] ?? $map['*'] ?? null;
        if ($perm === null || $this->hasPermission($perm)) {
            return;
        }
        $msg = 'Bu işlem için yetkiniz bulunmuyor.';
        if ($request->isAjax()) {
            $this->response->error($msg, 403);
        }
        $this->response->forbidden($msg);
    }
}
