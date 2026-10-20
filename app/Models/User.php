<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Support\ReservedSuperAdmin;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['company_id', 'name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use AuditsCompanyChanges, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    use HasRoles { syncRoles as private syncRolesFromPackage; }

    public function syncRoles(...$roles): static
    {
        return DB::transaction(function () use ($roles): static {
            if (! ReservedSuperAdmin::is($this) || ! $this->hasRole(ReservedSuperAdmin::ROLE)) {
                return $this->syncRolesFromPackage(...$roles);
            }
            $names = collect($roles)->flatten()->filter()->map(fn ($role) => $this->getStoredRole($role)->name)->all();
            if (! in_array(ReservedSuperAdmin::ROLE, $names, true)) {
                throw ValidationException::withMessages(['roles' => 'La cuenta protegida debe conservar el rol super_admin.']);
            }
            foreach ($this->roles->pluck('name')->diff($names) as $name) {
                $this->removeRole($name);
            }

            return $this->assignRole(...$names);
        });
    }

    protected $guard_name = 'web';

    protected $auditExclude = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function createdManualContingencyInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'created_by_user_id');
    }

    public function transcribedManualContingencyInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'transcribed_by_user_id');
    }

    public function voidedManualContingencyInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'voided_by_user_id');
    }

    public function activeCashRegister(): HasOne
    {
        return $this->hasOne(CashRegister::class)->whereNull('closed_at');
    }

    public function hasActiveAccess(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->company_id === null || ReservedSuperAdmin::isGlobal($this)) {
            return true;
        }

        return (bool) $this->company()->value('is_active');
    }
}
