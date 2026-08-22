<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;
use App\Support\CompanyContext;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null && $user->can('product-categories.view');
    }

    public function view(User $user, ProductCategory $productCategory): bool
    {
        return $user->can('product-categories.view')
            && CompanyContext::belongsToUser($productCategory->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $user->company_id !== null && $user->can('product-categories.create');
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        return $user->can('product-categories.edit')
            && CompanyContext::belongsToUser($productCategory->company_id, $user);
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $user->can('product-categories.delete')
            && CompanyContext::belongsToUser($productCategory->company_id, $user);
    }
}
