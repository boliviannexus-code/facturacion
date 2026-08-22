<?php

namespace App\Policies;

use App\Models\SinApiToken;
use App\Models\User;
use App\Support\CompanyContext;

class SinApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->can('sin-api-tokens.view');
    }

    public function view(User $user, SinApiToken $sinApiToken): bool
    {
        return $user->can('sin-api-tokens.view')
            && CompanyContext::belongsToUser($sinApiToken->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null && $user->can('sin-api-tokens.manage');
    }

    public function update(User $user, SinApiToken $sinApiToken): bool
    {
        return $user->can('sin-api-tokens.manage')
            && CompanyContext::belongsToUser($sinApiToken->company_id, $user);
    }
}
