<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\AuditsCompanyChanges;
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
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['company_id', 'name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use AuditsCompanyChanges, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

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

        if ($this->company_id === null) {
            return true;
        }

        return (bool) $this->company()->value('is_active');
    }
}
