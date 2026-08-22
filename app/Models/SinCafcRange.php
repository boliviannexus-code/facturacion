<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CafcRangeStatus;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinCafcRangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinCafcRange extends Model implements Auditable
{
    /** @use HasFactory<SinCafcRangeFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'sin_significant_event_id', 'created_by_user_id',
        'updated_by_user_id', 'cafc_code', 'document_sector_code', 'range_start',
        'range_end', 'next_number', 'range_status', 'authorized_from', 'authorized_until', 'notes',
        'used_count', 'cancelled_count',
    ];

    protected function casts(): array
    {
        return [
            'document_sector_code' => 'integer', 'range_start' => 'integer',
            'range_end' => 'integer', 'next_number' => 'integer',
            'used_count' => 'integer', 'cancelled_count' => 'integer',
            'range_status' => CafcRangeStatus::class,
            'authorized_from' => 'immutable_date', 'authorized_until' => 'immutable_date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(SinBranch::class, 'sin_branch_id');
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id');
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function manualInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'sin_cafc_range_id');
    }

    public function getRemainingCountAttribute(): int
    {
        return max(0, $this->range_end - $this->range_start + 1 - $this->used_count - $this->cancelled_count);
    }
}
