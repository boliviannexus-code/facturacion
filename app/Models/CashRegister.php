<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CashRegisterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class CashRegister extends Model implements Auditable
{
    /** @use HasFactory<CashRegisterFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'opening_amount',
        'closing_amount',
        'opening_notes',
        'closing_notes',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at');
    }

    public function isActive(): bool
    {
        return $this->closed_at === null;
    }
}
