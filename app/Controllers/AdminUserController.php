<?php
/**
 * OzyConnect Panel — AdminUserController (platform admin kullanıcı yönetimi)
 *
 * Admin kullanıcı ekle/düzenle/sil + rol ata. RBAC: admins.view / admins.manage
 * (AdminAuthorizationService route haritası zorlar). AdminAuthMiddleware (Pattern A).
 * Parola = admin tarafından belirlenir (bcrypt cost 12, AdminUser::updatePasswordHash ile aynı).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AdminRole;
use App\Models\AdminUser;

final class AdminUserController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Database $db;
    private readonly AdminUser $admins;
    private readonly AdminRole $roles;
    private readonly Logger $logger;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->db           = $container->get(Database::class);
        $this->admins       = $container->get(AdminUser::class);
        $this->roles        = $container->get(AdminRole::class);
        $this->logger       = $container->get(Logger::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    public function index(Request $request): void
    {
        $this->render('admin.admin_users_index', [
            'pageTitle' => 'Admin Kullanıcılar',
            'admins'    => $this->admins->allWithRole(),
            'selfId'    => (int) $this->adminSession->adminId(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.admin_user_form', [
            'pageTitle' => 'Yeni Admin',
            'admin'     => null,
            'roles'     => $this->roles->allWithCounts(),
        ]);
    }

    public function store(Request $request): void
    {
        $name     = trim((string) $request->post('name', ''));
        $email    = trim((string) $request->post('email', ''));
        $password = (string) $request->post('password', '');
        $roleId   = (int) $request->post('admin_role_id', 0);
        $isActive = (string) $request->post('is_active', '1') === '1' ? 1 : 0;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('/admin/admin-users/create', 'Ad ve geçerli e-posta zorunludur.');
        }
        if (strlen($password) < 8) {
            $this->fail('/admin/admin-users/create', 'Parola en az 8 karakter olmalıdır.');
        }
        if ($this->admins->emailExists($email)) {
            $this->fail('/admin/admin-users/create', 'Bu e-posta zaten kayıtlı.');
        }

        $id = $this->admins->create([
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'is_active'     => $isActive,
            'admin_role_id' => $roleId > 0 ? $roleId : null,
        ]);
        $this->audit('admin_user.create', "admin#{$id}", ['email' => $email, 'role_id' => $roleId]);
        $this->session->flash('success', 'Admin kullanıcı oluşturuldu. (2FA ilk girişte kurulur.)');
        $this->response->redirectTo('/admin/admin-users');
    }

    public function edit(Request $request, string $id): void
    {
        $admin = $this->admins->find((int) $id);
        if ($admin === false) {
            $this->response->notFound('Admin bulunamadı');
            return;
        }
        $this->render('admin.admin_user_form', [
            'pageTitle' => 'Admin Düzenle',
            'admin'     => $admin,
            'roles'     => $this->roles->allWithCounts(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $adminId = (int) $id;
        $admin = $this->admins->find($adminId);
        if ($admin === false) {
            $this->response->notFound('Admin bulunamadı');
            return;
        }
        $name     = trim((string) $request->post('name', ''));
        $email    = trim((string) $request->post('email', ''));
        $password = (string) $request->post('password', '');
        $roleId   = (int) $request->post('admin_role_id', 0);
        $isActive = (string) $request->post('is_active', '0') === '1' ? 1 : 0;
        $isSelf   = $adminId === (int) $this->adminSession->adminId();

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail("/admin/admin-users/{$adminId}/edit", 'Ad ve geçerli e-posta zorunludur.');
        }
        if ($this->admins->emailExists($email, $adminId)) {
            $this->fail("/admin/admin-users/{$adminId}/edit", 'Bu e-posta başka bir admine ait.');
        }
        // Kendini pasifleştirme/yetkisizleştirme koruması (kilitlenmeyi önle).
        if ($isSelf && $isActive === 0) {
            $this->fail("/admin/admin-users/{$adminId}/edit", 'Kendi hesabınızı pasifleştiremezsiniz.');
        }

        $payload = [
            'name'          => $name,
            'email'         => $email,
            'is_active'     => $isActive,
            'admin_role_id' => $roleId > 0 ? $roleId : null,
        ];
        if ($password !== '') {
            if (strlen($password) < 8) {
                $this->fail("/admin/admin-users/{$adminId}/edit", 'Parola en az 8 karakter olmalıdır.');
            }
            $payload['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        $this->admins->update($adminId, $payload);
        $this->audit('admin_user.update', "admin#{$adminId}", ['email' => $email, 'role_id' => $roleId, 'pw' => $password !== '']);
        $this->session->flash('success', 'Admin kullanıcı güncellendi.');
        $this->response->redirectTo('/admin/admin-users');
    }

    public function delete(Request $request, string $id): void
    {
        $adminId = (int) $id;
        $admin = $this->admins->find($adminId);
        if ($admin === false) {
            $this->fail('/admin/admin-users', 'Admin bulunamadı.');
        }
        if ($adminId === (int) $this->adminSession->adminId()) {
            $this->fail('/admin/admin-users', 'Kendi hesabınızı silemezsiniz.');
        }
        // Son aktif admin korunur (kilitlenme önleme).
        $activeCount = (int) ($this->db->fetchOne("SELECT COUNT(*) c FROM admin_users WHERE is_active = 1")['c'] ?? 0);
        if ($activeCount <= 1) {
            $this->fail('/admin/admin-users', 'Son aktif admin silinemez.');
        }
        $this->admins->delete($adminId);
        $this->audit('admin_user.delete', "admin#{$adminId}", ['email' => $admin['email'] ?? '']);
        $this->session->flash('success', 'Admin kullanıcı silindi.');
        $this->response->redirectTo('/admin/admin-users');
    }

    private function fail(string $path, string $message): never
    {
        $this->session->flash('error', $message);
        $this->response->redirectTo($path);
        exit;
    }

    private function audit(string $action, string $target, array $detail): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_audit_log (user_id, admin_user_id, actor_type, actor_email, action, target, result, detail_json, ip_address, created_at)
                 VALUES (NULL, :aid, 'admin', :em, :ac, :tg, 'success', :dj, :ip, NOW())",
                ['aid' => $this->adminSession->adminId(), 'em' => $this->adminSession->adminEmail(), 'ac' => $action, 'tg' => $target,
                 'dj' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]
            );
        } catch (\Throwable $e) {
            $this->logger->critical('system_audit_log kaydı yazılamadı: ' . $e->getMessage(), 'AdminUser', [
                'action' => $action, 'target' => $target,
            ]);
        }
    }
}
