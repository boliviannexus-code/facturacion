<?php

namespace App\Policies;

use App\Models\User;
use App\Support\ReservedSuperAdmin;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return ($role->name !== ReservedSuperAdmin::ROLE || ReservedSuperAdmin::isGlobal($user)) && $user->can('roles.edit');
    }

    public function delete(User $user, Role $role): bool
    {
        return $role->name !== ReservedSuperAdmin::ROLE && $user->can('roles.delete');
    }

    public function assignPermissions(User $user, Role $role): bool
    {
        return ($role->name !== ReservedSuperAdmin::ROLE || ReservedSuperAdmin::isGlobal($user)) && $user->can('roles.assign-permissions');
    }
}
