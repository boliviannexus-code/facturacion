<?php

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Support\ReservedSuperAdmin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    private const ADMIN_ROLES = ['admin', 'super_admin'];

    public function __construct(
        private readonly RoleRepository $roles
    ) {}

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->roles->paginate($perPage);
    }

    public function create(array $data): Role
    {
        if (mb_strtolower(trim($data['name'])) === ReservedSuperAdmin::ROLE) {
            throw ValidationException::withMessages(['name' => 'El rol super_admin está reservado por el sistema.']);
        }
        $permissions = $data['permissions'] ?? [];
        unset($data['permissions']);

        $data['guard_name'] = $data['guard_name'] ?? 'web';

        $role = $this->roles->create($data);
        $this->syncPermissions($role, $permissions);

        Log::info('Role created', ['role_id' => $role->id, 'name' => $role->name]);

        return $this->roles->withUsersCount($role->refresh()->load('permissions')->loadCount('permissions'));
    }

    public function update(Role $role, array $data): Role
    {
        $permissions = $data['permissions'] ?? null;
        unset($data['permissions']);

        if ($role->name === ReservedSuperAdmin::ROLE && (($data['name'] ?? $role->name) !== $role->name || ($data['guard_name'] ?? $role->guard_name) !== $role->guard_name)) {
            throw ValidationException::withMessages(['name' => 'No puedes renombrar ni cambiar el guard del rol super_admin.']);
        }
        if ($role->name !== ReservedSuperAdmin::ROLE && mb_strtolower(trim($data['name'] ?? $role->name)) === ReservedSuperAdmin::ROLE) {
            throw ValidationException::withMessages(['name' => 'El rol super_admin está reservado por el sistema.']);
        }
        $this->ensureCanManageProtectedRole($role);

        if ($role->name === 'admin' && ($data['name'] ?? 'admin') !== 'admin') {
            throw ValidationException::withMessages([
                'name' => 'No puedes renombrar el rol admin.',
            ]);
        }

        $data['guard_name'] = $data['guard_name'] ?? $role->guard_name;

        $role = $this->roles->update($role, $data);

        if (is_array($permissions)) {
            $this->syncPermissions($role, $permissions);
        }

        Log::info('Role updated', ['role_id' => $role->id, 'name' => $role->name]);

        return $this->roles->withUsersCount($role->refresh()->load('permissions')->loadCount('permissions'));
    }

    public function syncPermissions(Role $role, array $permissions): Role
    {
        $this->ensureCanManageProtectedRole($role);
        if (in_array($role->name, self::ADMIN_ROLES, true)) {
            $required = ['roles.view', 'roles.edit', 'roles.assign-permissions'];

            if (count(array_intersect($required, $permissions)) !== count($required)) {
                throw ValidationException::withMessages([
                    'permissions' => 'El rol administrador debe conservar los permisos para administrar roles.',
                ]);
            }
        }

        $role->syncPermissions($permissions);
        $this->forgetCache();

        Log::warning('Role permissions assigned', ['role_id' => $role->id, 'permissions' => $permissions]);

        return $this->roles->withUsersCount($role->refresh()->load('permissions')->loadCount('permissions'));
    }

    public function delete(Role $role): bool
    {
        if (in_array($role->name, self::ADMIN_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'No puedes eliminar un rol administrador del sistema.',
            ]);
        }

        $deleted = $this->roles->delete($role);
        $this->forgetCache();

        Log::warning('Role deleted', ['role_id' => $role->id, 'name' => $role->name]);

        return $deleted;
    }

    private function ensureCanManageProtectedRole(Role $role): void
    {
        if ($role->name === ReservedSuperAdmin::ROLE && ! ReservedSuperAdmin::isGlobal(auth()->user())) {
            throw ValidationException::withMessages(['role' => 'Solo el superadministrador puede modificar su rol protegido.']);
        }
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
