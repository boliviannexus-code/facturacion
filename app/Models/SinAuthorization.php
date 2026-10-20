<?php

namespace App\Models;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinAuthorizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class SinAuthorization extends Model implements Auditable
{
    /** @use HasFactory<SinAuthorizationFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'tax_id',
        'legal_name',
        'system_code',
        'environment_code',
        'modality_code',
        'branch_code',
        'point_of_sale_code',
        'force_offline_emission',
        'certificate_expires_at',
    ];

    protected $hidden = [
        'system_code',
    ];

    protected $auditExclude = [
        'system_code',
    ];

    protected function casts(): array
    {
        return [
            'system_code' => 'encrypted',
            'environment_code' => SiatEnvironment::class,
            'modality_code' => SiatModality::class,
            'branch_code' => 'integer',
            'point_of_sale_code' => 'integer',
            'force_offline_emission' => 'boolean',
            'certificate_expires_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getMaskedSystemCodeAttribute(): string
    {
        $systemCode = (string) $this->system_code;

        if ($systemCode === '') {
            return '';
        }

        return str_repeat('*', max(4, strlen($systemCode) - 4)).substr($systemCode, -4);
    }
}
