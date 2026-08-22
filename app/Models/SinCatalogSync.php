<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinCatalogSyncFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class SinCatalogSync extends Model implements Auditable
{
    /** @use HasFactory<SinCatalogSyncFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'sin_api_token_id',
        'sin_authorization_id',
        'sin_cuis_id',
        'catalog_key',
        'catalog_name',
        'operation',
        'wsdl_url',
        'transaccion',
        'items_count',
        'message',
        'response',
        'duration_ms',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'transaccion' => 'boolean',
            'items_count' => 'integer',
            'response' => 'array',
            'duration_ms' => 'integer',
            'synced_at' => 'immutable_datetime',
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

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SinCuis::class, 'sin_cuis_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->transaccion ? 'Sincronizado' : 'Observado';
    }

    public function getStatusBadgeAttribute(): string
    {
        return $this->transaccion ? 'bg-success-lt' : 'bg-yellow-lt';
    }
}
