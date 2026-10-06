<?php

namespace App\Policies;

use App\Models\SinAuthorization;
use App\Models\User;
use App\Support\CompanyContext;

class SinAuthorizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->can('sin-authorizations.view');
    }

    public function view(User $user, SinAuthorization $sinAuthorization): bool
    {
        return $user->can('sin-authorizations.view')
            && CompanyContext::belongsToUser($sinAuthorization->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null && $user->can('sin-authorizations.manage');
    }

    public function update(User $user, SinAuthorization $sinAuthorization): bool
    {
        return $user->can('sin-authorizations.manage')
            && CompanyContext::belongsToUser($sinAuthorization->company_id, $user);
    }
}
