<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiatCommunicationOutcome;
use App\Enums\SiatErrorType;
use App\Enums\SiatFailureCategory;
use App\Enums\SiatOperation;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinCommunicationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class SinCommunicationLog extends Model implements Auditable
{
    /** @use HasFactory<SinCommunicationLogFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'sin_cuis_id',
        'sin_cufd_id', 'sin_api_token_id', 'user_id', 'operation', 'outcome',
        'failure_category', 'error_type', 'attempt_count', 'endpoint',
        'http_status_code', 'soap_fault_code', 'duration_ms',
        'last_request_duration_ms', 'was_retried', 'contingency_recommended',
        'message', 'technical_message', 'user_message', 'response', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'operation' => SiatOperation::class,
            'outcome' => SiatCommunicationOutcome::class,
            'error_type' => SiatErrorType::class,
            'failure_category' => SiatFailureCategory::class,
            'attempt_count' => 'integer',
            'http_status_code' => 'integer',
            'duration_ms' => 'integer',
            'last_request_duration_ms' => 'integer',
            'was_retried' => 'boolean',
            'contingency_recommended' => 'boolean',
            'response' => 'array',
            'checked_at' => 'immutable_datetime',
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

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SinCuis::class, 'sin_cuis_id');
    }

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SinCufd::class, 'sin_cufd_id');
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(SinApiToken::class, 'sin_api_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
