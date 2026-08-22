<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoicePackageStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinInvoicePackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinInvoicePackage extends Model implements Auditable
{
    /** @use HasFactory<SinInvoicePackageFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_api_token_id', 'sin_authorization_id',
        'sin_significant_event_id', 'sin_branch_id', 'sin_point_of_sale_id',
        'sin_cuis_id', 'sin_cufd_id', 'created_by_user_id', 'sent_by_user_id',
        'validated_by_user_id', 'package_key', 'package_number', 'emission_mode',
        'package_status', 'invoice_count', 'cafc_code', 'file_path', 'file_hash', 'reception_code',
        'tax_id', 'environment_code', 'modality_code', 'emission_type_code',
        'document_sector_code', 'invoice_document_type_code', 'branch_code',
        'point_of_sale_code', 'file_size', 'siat_status_code', 'send_claim',
        'send_claimed_at', 'validation_claim', 'validation_claimed_at',
        'validation_checks', 'message', 'response', 'generated_at', 'sent_at',
        'validated_at', 'last_validation_at',
    ];

    protected function casts(): array
    {
        return [
            'emission_mode' => InvoiceEmissionMode::class,
            'package_status' => InvoicePackageStatus::class,
            'package_number' => 'integer',
            'invoice_count' => 'integer',
            'environment_code' => SiatEnvironment::class,
            'modality_code' => SiatModality::class,
            'emission_type_code' => 'integer',
            'document_sector_code' => 'integer',
            'invoice_document_type_code' => 'integer',
            'branch_code' => 'integer',
            'point_of_sale_code' => 'integer',
            'file_size' => 'integer',
            'siat_status_code' => 'integer',
            'send_claimed_at' => 'immutable_datetime',
            'validation_claimed_at' => 'immutable_datetime',
            'validation_checks' => 'integer',
            'response' => 'array',
            'generated_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'last_validation_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
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
        return $this->belongsTo(SinBranch::class, 'sin_branch_id');
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id');
    }

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SinCuis::class, 'sin_cuis_id');
    }

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SinCufd::class, 'sin_cufd_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SinInvoicePackageItem::class, 'sin_invoice_package_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SinSiatAttempt::class, 'sin_invoice_package_id');
    }
}
