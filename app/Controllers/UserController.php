<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Role;
use App\Models\User;

final class UserController extends BaseController
{
    private readonly User $userModel;
    private readonly Role $roleModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->userModel = $container->get(User::class);
        $this->roleModel = $container->get(Role::class);
    }

    public function index(Request $request): void
    {
        $this->view('users.index', [
            'pageTitle' => 'Yetkilendirme • Kullanıcılar',
            'users' => $this->userModel->allWithRoles(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->view('users.form', [
            'pageTitle' => 'Yetkilendirme • Yeni Kullanıcı',
            'userRow' => null,
            'roles' => $this->roleModel->all([], 'name ASC'),
        ]);
    }

    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name', ''));
        $email = mb_strtolower(trim((string) $request->post('email', '')), 'UTF-8');
        $password = (string) $request->post('password', '');
        $passwordConfirm = (string) $request->post('password_confirm', '');
        $roleId = (int) $request->post('role_id', 0);
        $isActive = $request->post('is_active', '0') === '1' ? 1 : 0;

        if ($name === '' || $email === '' || $roleId <= 0) {
            $this->redirectWithError('/users/create', 'Ad, e-posta ve rol zorunludur.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWithError('/users/create', 'Geçerli bir e-posta adresi girin.');
        }

        // Güçlü şifre kontrolü: minimum 8 karakter, en az 1 büyük harf, 1 küçük harf, 1 rakam
        if ($password === '' || strlen($password) < 8) {
            $this->redirectWithError('/users/create', 'Şifre en az 8 karakter olmalıdır.');
        }
        
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->redirectWithError('/users/create', 'Şifre en az 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.');
        }

        if ($password !== $passwordConfirm) {
            $this->redirectWithError('/users/create', 'Şifre tekrar alanı eşleşmiyor.');
        }

        if ($this->userModel->emailExists($email)) {
            $this->redirectWithError('/users/create', 'Bu e-posta adresi zaten kullanılıyor.');
        }

        $role = $this->roleModel->find($roleId);
        if ($role === false) {
            $this->redirectWithError('/users/create', 'Seçilen rol bulunamadı.');
        }

        $this->userModel->createUser([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $this->legacyRoleValue($role),
            'role_id' => $roleId,
            'is_active' => $isActive,
        ]);

        $this->redirectWithSuccess('/users', 'Kullanıcı oluşturuldu.');
    }

    public function edit(Request $request, string $id): void
    {
        $user = $this->userModel->findWithRole((int) $id);
        if ($user === false) {
            $this->response->notFound('Kullanıcı bulunamadı.');
        }

        $this->view('users.form', [
            'pageTitle' => 'Yetkilendirme • Kullanıcı Düzenle',
            'userRow' => $user,
            'roles' => $this->roleModel->all([], 'name ASC'),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $userId = (int) $id;
        $user = $this->userModel->find($userId);
        if ($user === false) {
            $this->response->notFound('Kullanıcı bulunamadı.');
        }

        $name = trim((string) $request->post('name', ''));
        $email = mb_strtolower(trim((string) $request->post('email', '')), 'UTF-8');
        $password = (string) $request->post('password', '');
        $passwordConfirm = (string) $request->post('password_confirm', '');
        $roleId = (int) $request->post('role_id', 0);
        $isActive = $request->post('is_active', '0') === '1' ? 1 : 0;

        if ($name === '' || $email === '' || $roleId <= 0) {
            $this->redirectWithError("/users/{$userId}/edit", 'Ad, e-posta ve rol zorunludur.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWithError("/users/{$userId}/edit", 'Geçerli bir e-posta adresi girin.');
        }

        if ($this->userModel->emailExists($email, $userId)) {
            $this->redirectWithError("/users/{$userId}/edit", 'Bu e-posta adresi zaten kullanılıyor.');
        }

        if ($password !== '' && strlen($password) < 6) {
            $this->redirectWithError("/users/{$userId}/edit", 'Yeni şifre en az 6 karakter olmalıdır.');
        }

        if ($password !== '' && $password !== $passwordConfirm) {
            $this->redirectWithError("/users/{$userId}/edit", 'Şifre tekrar alanı eşleşmiyor.');
        }

        if ($this->session->userId() === $userId && $isActive !== 1) {
            $this->redirectWithError("/users/{$userId}/edit", 'Kendi hesabınızı pasife alamazsınız.');
        }

        $role = $this->roleModel->find($roleId);
        if ($role === false) {
            $this->redirectWithError("/users/{$userId}/edit", 'Seçilen rol bulunamadı.');
        }

        $this->userModel->update($userId, [
            'name' => $name,
            'email' => $email,
            'role' => $this->legacyRoleValue($role),
            'role_id' => $roleId,
            'is_active' => $isActive,
        ]);

        if ($password !== '') {
            $this->userModel->updatePassword($userId, $password);
        }

        $this->redirectWithSuccess('/users', 'Kullanıcı güncellendi.');
    }

    public function delete(Request $request, string $id): void
    {
        $userId = (int) $id;
        $user = $this->userModel->find($userId);
        if ($user === false) {
            $this->error('Kullanıcı bulunamadı.', 404);
        }

        if ($this->session->userId() === $userId) {
            $this->error('Kendi hesabınızı silemezsiniz.', 400);
        }

        $this->userModel->delete($userId);

        if ($request->isAjax()) {
            $this->success([], 'Kullanıcı silindi.');
        }

        $this->redirectWithSuccess('/users', 'Kullanıcı silindi.');
    }

    /**
     * @param array<string, mixed> $role
     */
    private function legacyRoleValue(array $role): string
    {
        $permissions = json_decode((string) ($role['permissions_json'] ?? '[]'), true);
        $permissions = is_array($permissions) ? $permissions : [];

        return (($role['slug'] ?? '') === 'admin' || in_array('*', $permissions, true))
            ? 'admin'
            : 'staff';
    }
}
