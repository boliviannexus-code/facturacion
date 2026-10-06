<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinInvoicePackageItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinInvoicePackageItem extends Model
{
    /** @use HasFactory<SinInvoicePackageItemFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'sin_invoice_package_id', 'sin_invoice_issue_id', 'position', 'cuf', 'file_hash'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SinInvoicePackage::class, 'sin_invoice_package_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }
}
