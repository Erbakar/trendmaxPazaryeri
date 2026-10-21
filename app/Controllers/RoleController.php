<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Role;

final class RoleController extends BaseController
{
    private readonly Role $roleModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->roleModel = $container->get(Role::class);
    }

    public function index(Request $request): void
    {
        $this->view('roles.index', [
            'pageTitle' => 'Yetkilendirme • Roller',
            'roles' => $this->roleModel->allWithCounts(),
            'permissionMap' => Role::PERMISSION_MAP,
            'moduleLabels' => Role::MODULE_LABELS,
        ]);
    }

    public function create(Request $request): void
    {
        $this->view('roles.form', [
            'pageTitle' => 'Yetkilendirme • Yeni Rol',
            'role' => null,
            'permissionMap' => Role::PERMISSION_MAP,
            'moduleLabels' => Role::MODULE_LABELS,
        ]);
    }

    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name', ''));
        $slug = trim((string) $request->post('slug', ''));
        $description = trim((string) $request->post('description', ''));
        $permissions = $request->post('permissions', []);

        if ($name === '') {
            $this->redirectWithError('/roles/create', 'Rol adı zorunludur.');
        }

        if ($slug === '') {
            $slug = $this->generateSlug($name);
        }

        if ($this->roleModel->findBySlug($slug) !== false) {
            $this->redirectWithError('/roles/create', 'Bu kısa ad zaten kullanılıyor.');
        }

        if (!is_array($permissions)) {
            $permissions = [];
        }

        $this->roleModel->create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'is_system' => 0,
            'permissions_json' => json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE),
        ]);

        $this->redirectWithSuccess('/roles', 'Rol oluşturuldu.');
    }

    public function edit(Request $request, string $id): void
    {
        $role = $this->roleModel->find((int) $id);
        if ($role === false) {
            $this->response->notFound('Rol bulunamadı.');
        }

        $this->view('roles.form', [
            'pageTitle' => 'Yetkilendirme • Rol Düzenle',
            'role' => $role,
            'permissionMap' => Role::PERMISSION_MAP,
            'moduleLabels' => Role::MODULE_LABELS,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $role = $this->roleModel->find((int) $id);
        if ($role === false) {
            $this->response->notFound('Rol bulunamadı.');
        }

        $name = trim((string) $request->post('name', ''));
        $slug = trim((string) $request->post('slug', ''));
        $description = trim((string) $request->post('description', ''));
        $permissions = $request->post('permissions', []);

        if ($name === '') {
            $this->redirectWithError("/roles/{$id}/edit", 'Rol adı zorunludur.');
        }

        if (!is_array($permissions)) {
            $permissions = [];
        }

        $payload = [
            'description' => $description,
            'permissions_json' => json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE),
        ];

        if ((int) ($role['is_system'] ?? 0) !== 1) {
            if ($slug === '') {
                $slug = $this->generateSlug($name);
            }

            $existing = $this->roleModel->findBySlug($slug);
            if ($existing !== false && (int) ($existing['id'] ?? 0) !== (int) $id) {
                $this->redirectWithError("/roles/{$id}/edit", 'Bu kısa ad zaten kullanılıyor.');
            }

            $payload['name'] = $name;
            $payload['slug'] = $slug;
        }

        $this->roleModel->update((int) $id, $payload);
        $this->redirectWithSuccess('/roles', 'Rol güncellendi.');
    }

    public function delete(Request $request, string $id): void
    {
        $role = $this->roleModel->find((int) $id);
        if ($role === false) {
            $this->error('Rol bulunamadı.', 404);
        }

        if ((int) ($role['is_system'] ?? 0) === 1) {
            $this->error('Sistem rolü silinemez.', 400);
        }

        $users = $this->roleModel->getUsers((int) $id);
        if ($users !== []) {
            $this->error('Bu role bağlı kullanıcılar var. Önce kullanıcıları başka role taşıyın.', 400);
        }

        $this->roleModel->delete((int) $id);

        if ($request->isAjax()) {
            $this->success([], 'Rol silindi.');
        }

        $this->redirectWithSuccess('/roles', 'Rol silindi.');
    }

    public function users(Request $request, string $id): void
    {
        $role = $this->roleModel->find((int) $id);
        if ($role === false) {
            $this->error('Rol bulunamadı.', 404);
        }

        $this->json([
            'success' => true,
            'role' => $role['name'],
            'users' => $this->roleModel->getUsers((int) $id),
        ]);
    }

    private function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = str_replace(
            ['ç', 'ğ', 'ı', 'ö', 'ş', 'ü', ' '],
            ['c', 'g', 'i', 'o', 's', 'u', '-'],
            $slug
        );
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
