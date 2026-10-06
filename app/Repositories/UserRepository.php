<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class UserRepository
{
    public function paginate(int $perPage = 15, bool $withTrashed = false): LengthAwarePaginator
    {
        return User::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->when(CompanyContext::id(), fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->with(['company', 'roles'])
            ->latest()
            ->paginate($perPage);
    }

    public function findWithTrashed(int|string $id): User
    {
        return User::withTrashed()->with(['company', 'roles'])->findOrFail($id);
    }

    public function create(array $data): User
    {
        return User::create($data)->load('roles');
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->refresh()->load('roles');
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    public function restore(User $user): bool
    {
        return (bool) $user->restore();
    }

    public function activeAdminsCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->role(['admin', 'super_admin'])
            ->count();
    }

    public function activeAdminsExcluding(User $user): int
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->role(['admin', 'super_admin'])
            ->count();
    }

    public function rolesForSelect(): Collection
    {
        return Role::query()
            ->orderBy('name')
            ->get();
    }

    public function companiesForSelect(): Collection
    {
        return Company::query()
            ->when(CompanyContext::id(), fn ($query, $companyId) => $query->whereKey($companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
