<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinPointOfSaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinPointOfSale extends Model implements Auditable
{
    /** @use HasFactory<SinPointOfSaleFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $table = 'sin_points_of_sale';

    protected $fillable = [
        'company_id',
        'sin_branch_id',
        'point_of_sale_code',
        'point_of_sale_type_code',
        'point_of_sale_type',
        'name',
        'description',
        'is_default',
        'is_active',
        'registered_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'point_of_sale_code' => 'integer',
            'point_of_sale_type_code' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(SinBranch::class, 'sin_branch_id')->withoutGlobalScope('company');
    }

    public function getDisplayNameAttribute(): string
    {
        return 'PV '.$this->point_of_sale_code.' - '.$this->name;
    }

    public function invoicePackages(): HasMany
    {
        return $this->hasMany(SinInvoicePackage::class, 'sin_point_of_sale_id');
    }

    public function cafcRanges(): HasMany
    {
        return $this->hasMany(SinCafcRange::class, 'sin_point_of_sale_id');
    }

    public function manualContingencyInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'sin_point_of_sale_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'sin_point_of_sale_id');
    }
}
