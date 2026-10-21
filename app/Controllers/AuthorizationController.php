<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Role;
use App\Models\User;

final class AuthorizationController extends BaseController
{
    private readonly Role $roleModel;
    private readonly User $userModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->roleModel = $container->get(Role::class);
        $this->userModel = $container->get(User::class);
    }

    public function index(Request $request): void
    {
        $roles = $this->roleModel->allWithCounts();
        $users = $this->userModel->allWithRoles();

        $this->view('authorization.index', [
            'pageTitle' => 'Yetkilendirme',
            'roles' => $roles,
            'users' => $users,
        ]);
    }
}
