<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\CompanyContext;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view')
            && CompanyContext::belongsToUser($customer->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null && $user->can('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.edit')
            && CompanyContext::belongsToUser($customer->company_id, $user);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete')
            && CompanyContext::belongsToUser($customer->company_id, $user);
    }
}
