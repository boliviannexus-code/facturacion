<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiatAlertSeverity;
use App\Enums\SiatAlertStatus;
use App\Enums\SiatAlertType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinMonitoringAlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SinMonitoringAlert extends Model
{
    /** @use HasFactory<SinMonitoringAlertFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'sin_significant_event_id',
        'sin_invoice_package_id', 'sin_invoice_issue_id', 'sin_manual_contingency_invoice_id',
        'sin_cufd_id', 'sin_cafc_range_id', 'sin_authorization_id', 'alert_type', 'severity',
        'alert_status', 'condition_key', 'active_key', 'title', 'message', 'condition_count',
        'metadata', 'first_detected_at', 'last_detected_at', 'resolved_at', 'panel_recorded_at',
        'notification_queued_at', 'internal_notified_at', 'email_notified_at',
        'technical_logged_at', 'notification_attempts', 'notification_failed_at', 'notification_error',
    ];

    protected function casts(): array
    {
        return [
            'alert_type' => SiatAlertType::class,
            'severity' => SiatAlertSeverity::class,
            'alert_status' => SiatAlertStatus::class,
            'condition_count' => 'integer',
            'metadata' => 'array',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'panel_recorded_at' => 'immutable_datetime',
            'notification_queued_at' => 'immutable_datetime',
            'internal_notified_at' => 'immutable_datetime',
            'email_notified_at' => 'immutable_datetime',
            'technical_logged_at' => 'immutable_datetime',
            'notification_attempts' => 'integer',
            'notification_failed_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('alert_status', SiatAlertStatus::Active);
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

    public function invoicePackage(): BelongsTo
    {
        return $this->belongsTo(SinInvoicePackage::class, 'sin_invoice_package_id');
    }

    public function invoiceIssue(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }

    public function manualInvoice(): BelongsTo
    {
        return $this->belongsTo(SinManualContingencyInvoice::class, 'sin_manual_contingency_invoice_id');
    }

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SinCufd::class, 'sin_cufd_id');
    }

    public function cafcRange(): BelongsTo
    {
        return $this->belongsTo(SinCafcRange::class, 'sin_cafc_range_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SinAuthorization::class, 'sin_authorization_id');
    }
}
