<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinInvoiceSequence extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'document_sector_code', 'next_number'];

    protected function casts(): array
    {
        return [
            'document_sector_code' => 'integer',
            'next_number' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
