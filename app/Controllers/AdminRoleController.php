<?php
/**
 * OzyConnect Panel — AdminRoleController (platform admin RBAC rolleri)
 *
 * admin_roles CRUD + yetki (permissions_json) atama. PERMISSION_MAP modül/aksiyon haritası.
 * Sistem rolü (is_system=1, Süper Admin) düzenlenemez/silinemez. Atanmış admini olan rol silinemez.
 * RBAC: admins.view / admins.manage (Router ROUTE_PERMISSION_MAP zorlar). AdminAuthMiddleware (Pattern A).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AdminRole;

final class AdminRoleController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly AdminRole $roles;
    private readonly Database $db;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->roles        = $container->get(AdminRole::class);
        $this->db           = $container->get(Database::class);
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
        $this->render('admin.admin_roles_index', [
            'pageTitle' => 'Roller',
            'roles'     => $this->roles->allWithCounts(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.admin_role_form', [
            'pageTitle' => 'Yeni Rol',
            'role'      => null,
            'current'   => [],
        ]);
    }

    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->fail('/admin/admin-roles/create', 'Rol adı zorunludur.');
        }
        $slug = $this->uniqueSlug($name, null);
        $this->roles->create([
            'name'             => mb_substr($name, 0, 100),
            'slug'             => $slug,
            'description'      => mb_substr(trim((string) $request->post('description', '')), 0, 500) ?: null,
            'is_system'        => 0,
            'permissions_json' => $this->buildPermissions($request),
        ]);
        $this->session->flash('success', 'Rol oluşturuldu.');
        $this->response->redirectTo('/admin/admin-roles');
    }

    public function edit(Request $request, string $id): void
    {
        $role = $this->roles->find((int) $id);
        if ($role === false) { $this->response->notFound('Rol bulunamadı'); return; }
        if ((int) ($role['is_system'] ?? 0) === 1) {
            $this->fail('/admin/admin-roles', 'Sistem rolü (Süper Admin) düzenlenemez — tüm yetkilere sahiptir.');
        }
        $this->render('admin.admin_role_form', [
            'pageTitle' => 'Rol Düzenle',
            'role'      => $role,
            'current'   => $this->roles->getPermissions((int) $id),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $roleId = (int) $id;
        $role = $this->roles->find($roleId);
        if ($role === false) { $this->response->notFound('Rol bulunamadı'); return; }
        if ((int) ($role['is_system'] ?? 0) === 1) {
            $this->fail('/admin/admin-roles', 'Sistem rolü düzenlenemez.');
        }
        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->fail("/admin/admin-roles/{$roleId}/edit", 'Rol adı zorunludur.');
        }
        $this->roles->update($roleId, [
            'name'             => mb_substr($name, 0, 100),
            'slug'             => $this->uniqueSlug($name, $roleId),
            'description'      => mb_substr(trim((string) $request->post('description', '')), 0, 500) ?: null,
            'permissions_json' => $this->buildPermissions($request),
        ]);
        $this->session->flash('success', 'Rol güncellendi.');
        $this->response->redirectTo('/admin/admin-roles');
    }

    public function delete(Request $request, string $id): void
    {
        $roleId = (int) $id;
        $role = $this->roles->find($roleId);
        if ($role === false) { $this->fail('/admin/admin-roles', 'Rol bulunamadı.'); }
        if ((int) ($role['is_system'] ?? 0) === 1) {
            $this->fail('/admin/admin-roles', 'Sistem rolü silinemez.');
        }
        if ($this->roles->getAdmins($roleId) !== []) {
            $this->fail('/admin/admin-roles', 'Bu role atanmış admin var — önce onların rolünü değiştirin.');
        }
        $this->roles->delete($roleId);
        $this->session->flash('success', 'Rol silindi.');
        $this->response->redirectTo('/admin/admin-roles');
    }

    /** Form post'undan geçerli yetki anahtarlarını JSON'a çevir. "all" → ['*']. */
    private function buildPermissions(Request $request): string
    {
        if ((string) $request->post('all_perms', '') === '1') {
            return json_encode(['*']);
        }
        $valid = [];
        foreach (AdminRole::PERMISSION_MAP as $mod => $acts) {
            foreach ($acts as $a => $lbl) { $valid[$mod . '.' . $a] = true; }
        }
        $posted = (array) $request->post('perms', []);
        $perms = [];
        foreach ($posted as $p) {
            $p = (string) $p;
            if (isset($valid[$p])) { $perms[] = $p; }
        }
        return json_encode(array_values(array_unique($perms)));
    }

    private function uniqueSlug(string $name, ?int $exceptId): string
    {
        $tr = ['ç'=>'c','ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(strtr($name, $tr), 'UTF-8')) ?? '', '-') ?: 'rol';
        $slug = $base; $i = 1;
        while (true) {
            $row = $this->db->fetchOne("SELECT id FROM admin_roles WHERE slug = :s LIMIT 1", ['s' => $slug]);
            if ($row === false || ($exceptId !== null && (int) $row['id'] === $exceptId)) { return $slug; }
            $slug = $base . '-' . (++$i);
            if ($i > 99) { return $base . '-' . bin2hex(random_bytes(3)); }
        }
    }

    private function fail(string $path, string $message): never
    {
        $this->session->flash('error', $message);
        $this->response->redirectTo($path);
        exit;
    }
}
