<?php

namespace App\Policies;

use App\Models\User;
use App\Support\CompanyContext;
use App\Support\ReservedSuperAdmin;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view') && CompanyContext::canOperate($user);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view') && CompanyContext::belongsToUser($model->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->can('users.create') && CompanyContext::canOperate($user);
    }

    public function update(User $user, User $model): bool
    {
        return (! ReservedSuperAdmin::is($model) || (ReservedSuperAdmin::isGlobal($user) && $user->is($model)))
            && $user->can('users.edit') && CompanyContext::belongsToUser($model->company_id, $user);
    }

    public function delete(User $user, User $model): bool
    {
        return ! ReservedSuperAdmin::is($model) && $user->can('users.delete') && CompanyContext::belongsToUser($model->company_id, $user);
    }

    public function restore(User $user, User $model): bool
    {
        return ! ReservedSuperAdmin::is($model) && $user->can('users.restore') && CompanyContext::belongsToUser($model->company_id, $user);
    }

    public function changePassword(User $user, User $model): bool
    {
        return (! ReservedSuperAdmin::is($model) || (ReservedSuperAdmin::isGlobal($user) && $user->is($model)))
            && $user->can('users.change-password') && CompanyContext::belongsToUser($model->company_id, $user);
    }

    public function assignRoles(User $user, User $model): bool
    {
        return (! ReservedSuperAdmin::is($model) || (ReservedSuperAdmin::isGlobal($user) && $user->is($model)))
            && $user->can('users.assign-roles') && CompanyContext::belongsToUser($model->company_id, $user);
    }
}
