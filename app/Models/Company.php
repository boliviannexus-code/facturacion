<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Contracts\Auditable;

class Company extends Model implements Auditable
{
    /** @use HasFactory<CompanyFactory> */
    use AuditsCompanyChanges, HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'tax_id',
        'phone',
        'email',
        'address',
        'city',
        'country',
        'logo_path',
        'report_footer',
        'invoice_print_format',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class)->withoutGlobalScope('company');
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class)->withoutGlobalScope('company');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class)->withoutGlobalScope('company');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->withoutGlobalScope('company');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class)->withoutGlobalScope('company');
    }

    public function sinAuthorization(): HasOne
    {
        return $this->hasOne(SinAuthorization::class)->withoutGlobalScope('company');
    }

    public function sinApiToken(): HasOne
    {
        return $this->hasOne(SinApiToken::class)->withoutGlobalScope('company');
    }

    public function sinWsdlServices(): HasMany
    {
        return $this->hasMany(SinWsdlService::class)->withoutGlobalScope('company');
    }

    public function sinCuis(): HasMany
    {
        return $this->hasMany(SinCuis::class)->withoutGlobalScope('company');
    }

    public function sinCufds(): HasMany
    {
        return $this->hasMany(SinCufd::class)->withoutGlobalScope('company');
    }

    public function currentSinCuis(): HasOne
    {
        return $this->hasOne(SinCuis::class)
            ->withoutGlobalScope('company')
            ->where('transaccion', true)
            ->whereNotNull('cuis_code')
            ->latestOfMany('requested_at');
    }

    public function currentSinCufd(): HasOne
    {
        return $this->hasOne(SinCufd::class)
            ->withoutGlobalScope('company')
            ->where('transaccion', true)
            ->whereNotNull('cufd_code')
            ->where('expires_at', '>', now())
            ->latestOfMany('requested_at');
    }

    public function sinCatalogItems(): HasMany
    {
        return $this->hasMany(SinCatalogItem::class)->withoutGlobalScope('company');
    }

    public function sinCatalogSyncs(): HasMany
    {
        return $this->hasMany(SinCatalogSync::class)->withoutGlobalScope('company');
    }

    public function sinBranches(): HasMany
    {
        return $this->hasMany(SinBranch::class)->withoutGlobalScope('company');
    }

    public function sinPointsOfSale(): HasMany
    {
        return $this->hasMany(SinPointOfSale::class)->withoutGlobalScope('company');
    }

    public function sinInvoicePackages(): HasMany
    {
        return $this->hasMany(SinInvoicePackage::class)->withoutGlobalScope('company');
    }

    public function sinCafcRanges(): HasMany
    {
        return $this->hasMany(SinCafcRange::class)->withoutGlobalScope('company');
    }

    public function sinManualContingencyInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class)->withoutGlobalScope('company');
    }

    public function sinCommunicationLogs(): HasMany
    {
        return $this->hasMany(SinCommunicationLog::class)->withoutGlobalScope('company');
    }

    public function sinMonitoringAlerts(): HasMany
    {
        return $this->hasMany(SinMonitoringAlert::class)->withoutGlobalScope('company');
    }

    public function sinSignificantEvents(): HasMany
    {
        return $this->hasMany(SinSignificantEvent::class)->withoutGlobalScope('company');
    }

    public function sinSiatAttempts(): HasMany
    {
        return $this->hasMany(SinSiatAttempt::class)->withoutGlobalScope('company');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
