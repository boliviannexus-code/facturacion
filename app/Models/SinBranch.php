<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinBranch extends Model implements Auditable
{
    /** @use HasFactory<SinBranchFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'branch_code',
        'name',
        'is_main',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'branch_code' => 'integer',
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function pointsOfSale(): HasMany
    {
        return $this->hasMany(SinPointOfSale::class)->withoutGlobalScope('company');
    }

    public function activePointsOfSale(): HasMany
    {
        return $this->pointsOfSale()->where('is_active', true);
    }

    public function getDisplayNameAttribute(): string
    {
        return ($this->is_main ? 'Casa matriz' : 'Sucursal '.$this->branch_code).' - '.$this->name;
    }

    public function invoicePackages(): HasMany
    {
        return $this->hasMany(SinInvoicePackage::class, 'sin_branch_id');
    }

    public function cafcRanges(): HasMany
    {
        return $this->hasMany(SinCafcRange::class, 'sin_branch_id');
    }

    public function manualContingencyInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'sin_branch_id');
    }
}
