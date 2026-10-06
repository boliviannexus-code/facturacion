<?php

namespace App\Models;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinCufdFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinCufd extends Model implements Auditable
{
    /** @use HasFactory<SinCufdFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $table = 'sin_cufds';

    protected $fillable = [
        'company_id',
        'sin_api_token_id',
        'sin_authorization_id',
        'sin_branch_id',
        'sin_point_of_sale_id',
        'sin_cuis_id',
        'tax_id',
        'wsdl_url',
        'environment_code',
        'modality_code',
        'branch_code',
        'point_of_sale_code',
        'transaccion',
        'cufd_code',
        'control_code',
        'address',
        'expires_at',
        'message',
        'response',
        'duration_ms',
        'requested_at',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected function casts(): array
    {
        return [
            'environment_code' => SiatEnvironment::class,
            'modality_code' => SiatModality::class,
            'branch_code' => 'integer',
            'point_of_sale_code' => 'integer',
            'transaccion' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'response' => 'array',
            'duration_ms' => 'integer',
            'requested_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(SinApiToken::class, 'sin_api_token_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SinAuthorization::class, 'sin_authorization_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(SinBranch::class, 'sin_branch_id')->withoutGlobalScope('company');
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id')->withoutGlobalScope('company');
    }

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SinCuis::class, 'sin_cuis_id')->withoutGlobalScope('company');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('transaccion', true)->whereNotNull('cufd_code');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->usable()->where('expires_at', '>', now());
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->successful()->whereNull('invalidated_at');
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->invalidated_at !== null) {
            return 'Reemplazado';
        }

        if (! $this->transaccion) {
            return 'Observado';
        }

        return $this->expires_at?->isFuture() ? 'Vigente' : 'Vencido';
    }

    public function getStatusBadgeAttribute(): string
    {
        if ($this->invalidated_at !== null) {
            return 'bg-secondary-lt';
        }

        return match ($this->status_label) {
            'Vigente' => 'bg-success-lt',
            'Observado' => 'bg-yellow-lt',
            default => 'bg-danger-lt',
        };
    }

    public function invoicePackages(): HasMany
    {
        return $this->hasMany(SinInvoicePackage::class, 'sin_cufd_id');
    }

    public function significantEvents(): HasMany
    {
        return $this->hasMany(SinSignificantEvent::class, 'sin_cufd_id');
    }

    public function recoveredSignificantEvents(): HasMany
    {
        return $this->hasMany(SinSignificantEvent::class, 'recovery_sin_cufd_id');
    }

    public function communicationLogs(): HasMany
    {
        return $this->hasMany(SinCommunicationLog::class, 'sin_cufd_id');
    }
}
