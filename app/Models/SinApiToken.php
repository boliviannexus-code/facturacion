<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinApiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class SinApiToken extends Model implements Auditable
{
    /** @use HasFactory<SinApiTokenFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'api_token',
        'wsdl_url',
        'starts_at',
        'ends_at',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected $auditExclude = [
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'starts_at' => 'immutable_date',
            'ends_at' => 'immutable_date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getMaskedApiTokenAttribute(): string
    {
        $token = (string) $this->api_token;

        if ($token === '') {
            return '';
        }

        return str_repeat('*', max(8, strlen($token) - 6)).substr($token, -6);
    }

    public function getStatusLabelAttribute(): string
    {
        $today = today();

        if ($this->starts_at->isFuture()) {
            return 'Pendiente';
        }

        if ($this->ends_at->lt($today)) {
            return 'Vencido';
        }

        return 'Vigente';
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status_label) {
            'Vigente' => 'bg-success-lt',
            'Pendiente' => 'bg-yellow-lt',
            default => 'bg-danger-lt',
        };
    }
}
