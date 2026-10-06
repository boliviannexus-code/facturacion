<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;
use App\Support\CompanyContext;

class CashRegisterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->can('cash-registers.view');
    }

    public function view(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('cash-registers.view')
            && CompanyContext::belongsToUser($cashRegister->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null && $user->can('cash-registers.open');
    }

    public function close(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('cash-registers.close')
            && CompanyContext::belongsToUser($cashRegister->company_id, $user)
            && (int) $cashRegister->user_id === (int) $user->id
            && $cashRegister->isActive();
    }
}
