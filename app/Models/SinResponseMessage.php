<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiatMessageSeverity;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinResponseMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinResponseMessage extends Model
{
    /** @use HasFactory<SinResponseMessageFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_siat_attempt_id', 'message_key', 'service',
        'message_code', 'severity', 'description', 'raw_data', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'severity' => SiatMessageSeverity::class,
            'raw_data' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SinSiatAttempt::class, 'sin_siat_attempt_id');
    }
}
