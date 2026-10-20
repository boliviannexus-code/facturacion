<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CompanyContext
{
    public const SESSION_KEY = 'active_company';

    public static function runInCompany(int $companyId, \Closure $callback): mixed
    {
        $previous = request()->attributes->get('background_company_id');
        request()->attributes->set('background_company_id', $companyId);
        try {
            return $callback();
        } finally {
            request()->attributes->set('background_company_id', $previous);
        }
    }

    public static function hasSelection(?User $user = null): bool
    {
        $user ??= auth()->user();

        return self::isGlobalAdmin($user) && auth()->id() === $user?->getKey()
            && request()->hasSession()
            && (request()->session()->get(self::SESSION_KEY.'.user_id') === $user->getKey()
                || ($user->company_id !== null && request()->routeIs('dashboard', 'billing.*', 'datatables.*', 'siat.*', 'sin-api-token.*', 'parameters.*', 'cash-registers.*', 'notifications.*')));
    }

    public static function operatingCompanyId(?User $user = null): ?int
    {
        $user ??= auth()->user();
        if (self::isGlobalAdmin($user) && request()->attributes->get('background_company_id') !== null) {
            return (int) request()->attributes->get('background_company_id');
        }
        if (self::hasSelection($user)) {
            $id = request()->session()->get(self::SESSION_KEY.'.user_id') === $user->getKey()
                ? (int) request()->session()->get(self::SESSION_KEY.'.company_id')
                : (int) $user->company_id;
            $cacheKey = 'selected_company_'.$id;
            if (! request()->attributes->has($cacheKey)) {
                request()->attributes->set($cacheKey, Company::query()->where('is_active', true)->find($id));
            }

            return request()->attributes->get($cacheKey)?->id ?? -1;
        }

        return $user?->company_id !== null ? (int) $user->company_id : null;
    }

    public static function id(?User $user = null): ?int
    {
        $user ??= auth()->user();

        if ($user === null) {
            return null;
        }

        if (self::isGlobalAdmin($user) && (self::isAdministration() || (! self::hasSelection($user) && request()->attributes->get('company_scoped_user_id') !== $user->getKey()))) {
            return null;
        }

        return self::operatingCompanyId($user) ?? -1;
    }

    public static function hasCompanyScope(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && (self::operatingCompanyId($user) ?? -1) > 0;
    }

    public static function canOperate(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && ($user->company_id !== null || self::isGlobalAdmin($user));
    }

    public static function isGlobalAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        return ReservedSuperAdmin::isGlobal($user);
    }

    public static function canAssignNoCompany(?User $user = null): bool
    {
        $user ??= auth()->user();

        return self::isGlobalAdmin($user);
    }

    public static function activeCompany(?User $user = null): ?Company
    {
        $user ??= auth()->user();

        if ($user === null || (self::operatingCompanyId($user) ?? -1) <= 0) {
            return null;
        }

        if (self::hasSelection($user)) {
            return Company::query()->find(self::operatingCompanyId($user));
        }

        return $user->relationLoaded('company')
            ? $user->company
            : $user->company()->first();
    }

    public static function scope(Builder $query, ?User $user = null, string $column = 'company_id'): Builder
    {
        $companyId = self::id($user);

        return $query->when($companyId, fn (Builder $query): Builder => $query->where($column, $companyId));
    }

    private static function isAdministration(): bool
    {
        return request()->routeIs('users.*', 'roles.*', 'permissions.*', 'companies.*', 'backups.*', 'api.v1.users.*', 'api.v1.roles.*', 'api.v1.permissions.*', 'api.v1.companies.*');
    }

    public static function belongsToUser(?int $companyId, User $user): bool
    {
        return (self::isGlobalAdmin($user) && (! self::hasSelection($user) || self::isAdministration()))
            || (self::hasSelection($user) && self::operatingCompanyId($user) === (int) $companyId)
            || (! self::isGlobalAdmin($user) && ($user->company_id !== null && (int) $user->company_id === (int) $companyId));
    }

    public static function applyToData(array $data, ?User $user = null): array
    {
        $companyId = self::id($user);

        if ($companyId !== null && $companyId > 0) {
            $data['company_id'] = $companyId;
        }

        return $data;
    }
}
