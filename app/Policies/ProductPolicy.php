<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\CompanyContext;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->can('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view')
            && CompanyContext::belongsToUser($product->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null && $user->can('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.edit')
            && CompanyContext::belongsToUser($product->company_id, $user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete')
            && CompanyContext::belongsToUser($product->company_id, $user);
    }
}
