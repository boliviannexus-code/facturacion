<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SignificantEventStatus;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinCommunicationLog;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinMonitoringAlert;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ContingencyDashboardService
{
    /** @param array<string, mixed> $requestedFilters @return array<string, mixed> */
    public function dashboard(User $user, array $requestedFilters): array
    {
        [$company, $companies] = $this->companyContext($user, $requestedFilters);
        $filters = $requestedFilters;
        $filters['company_id'] = $company->id;

        $branches = SinBranch::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)->where('is_active', true)
            ->orderByDesc('is_main')->orderBy('branch_code')->get();
        $branch = $this->selectedBranch($branches, $filters['branch_id'] ?? null);
        $filters['branch_id'] = $branch?->id;

        $points = SinPointOfSale::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->when($branch, fn (Builder $query) => $query->where('sin_branch_id', $branch->id))
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('point_of_sale_code')->get();
        $point = $this->selectedPoint($points, $filters['point_of_sale_id'] ?? null);
        $filters['point_of_sale_id'] = $point?->id;

        $location = fn (Builder $query): Builder => $this->location($query, $company->id, $branch?->id, $point?->id);
        $latestCommunication = $location(SinCommunicationLog::query()->withoutGlobalScope('company'))
            ->with(['pointOfSale', 'branch'])->latest('checked_at')->first();
        $activeCuis = $location(SinCuis::query()->withoutGlobalScope('company')->usable())
            ->latest('requested_at')->first();
        $currentCufd = $location(SinCufd::query()->withoutGlobalScope('company')->usable())
            ->latest('requested_at')->first();
        $openEvent = $location(SinSignificantEvent::query()->withoutGlobalScope('company'))
            ->with(['creator', 'cufd', 'recoveryCufd'])
            ->withExists(['invoiceIssues as has_eligible_offline_invoices' => fn (Builder $query) => $query
                ->where('emission_mode', InvoiceEmissionMode::OfflineDigital)
                ->whereIn('fiscal_status', [InvoiceFiscalStatus::OfflineIssued, InvoiceFiscalStatus::PendingPackage])
                ->whereNotNull('xml_path')
                ->whereNotNull('cuf')
                ->whereDoesntHave('packageItem')])
            ->whereNull('closed_at')
            ->whereNotIn('event_status', [SignificantEventStatus::Completed, SignificantEventStatus::Expired])
            ->orderByRaw(
                'CASE WHEN EXISTS (
                    SELECT 1 FROM sin_invoice_issues
                    WHERE sin_invoice_issues.sin_significant_event_id = sin_significant_events.id
                      AND sin_invoice_issues.emission_mode = ?
                      AND sin_invoice_issues.fiscal_status IN (?, ?)
                      AND sin_invoice_issues.xml_path IS NOT NULL
                      AND sin_invoice_issues.cuf IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM sin_invoice_package_items
                          WHERE sin_invoice_package_items.sin_invoice_issue_id = sin_invoice_issues.id
                      )
                ) THEN 0 ELSE 1 END',
                [
                    InvoiceEmissionMode::OfflineDigital->value,
                    InvoiceFiscalStatus::OfflineIssued->value,
                    InvoiceFiscalStatus::PendingPackage->value,
                ],
            )
            ->latest('started_at')->first();

        $invoiceBase = $location(SinInvoiceIssue::query()->withoutGlobalScope('company'));
        $packageBase = $location(SinInvoicePackage::query()->withoutGlobalScope('company'));
        $manualBase = $location(SinManualContingencyInvoice::query()->withoutGlobalScope('company'));
        $cafcBase = SinCafcRange::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->when($branch, fn (Builder $query) => $query->where('sin_branch_id', $branch->id))
            ->when($point, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('sin_point_of_sale_id')->orWhere('sin_point_of_sale_id', $point->id)));
        $alertBase = SinMonitoringAlert::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->when($branch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('sin_branch_id')->orWhere('sin_branch_id', $branch->id)))
            ->when($point, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('sin_point_of_sale_id')->orWhere('sin_point_of_sale_id', $point->id)))
            ->active()->whereNotNull('panel_recorded_at');

        $metrics = [
            'offline_invoices' => (clone $invoiceBase)->where('emission_mode', InvoiceEmissionMode::OfflineDigital)->count(),
            'pending_invoices' => (clone $invoiceBase)->whereIn('fiscal_status', [
                InvoiceFiscalStatus::PendingOnlineSend, InvoiceFiscalStatus::OfflineIssued,
                InvoiceFiscalStatus::PendingPackage, InvoiceFiscalStatus::Packaged,
                InvoiceFiscalStatus::PackageSent, InvoiceFiscalStatus::ManualPendingSend,
            ])->count(),
            'pending_packages' => (clone $packageBase)->whereIn('package_status', [
                InvoicePackageStatus::Created, InvoicePackageStatus::PendingSend,
                InvoicePackageStatus::Sent, InvoicePackageStatus::PendingValidation,
            ])->count(),
            'observed_packages' => (clone $packageBase)->where('package_status', InvoicePackageStatus::Observed)->count(),
            'rejected_invoices' => (clone $invoiceBase)->where('fiscal_status', InvoiceFiscalStatus::Rejected)->count(),
            'manual_pending' => (clone $manualBase)->where('manual_status', ManualContingencyInvoiceStatus::PendingTranscription)->count(),
            'available_cafc' => (clone $cafcBase)->whereIn('range_status', [CafcRangeStatus::Available, CafcRangeStatus::InUse])
                ->whereDate('authorized_from', '<=', today())->whereDate('authorized_until', '>=', today())
                ->whereRaw('used_count + cancelled_count < range_end - range_start + 1')->count(),
            'active_alerts' => (clone $alertBase)->count(),
        ];

        $invoices = $this->filteredInvoices(clone $invoiceBase, $filters)
            ->with(['company', 'branch', 'pointOfSale', 'customer', 'significantEvent', 'manualContingency'])
            ->latest('issued_at')->paginate(15, ['*'], 'invoices_page')->withQueryString();
        $events = $this->filteredEvents(
            $location(SinSignificantEvent::query()->withoutGlobalScope('company')),
            $filters,
        )->with(['branch', 'pointOfSale', 'creator'])->latest('started_at')
            ->paginate(10, ['*'], 'events_page')->withQueryString();
        $packages = (clone $packageBase)->with(['significantEvent', 'branch', 'pointOfSale'])
            ->latest('generated_at')->limit(12)->get();
        $manualInvoices = (clone $manualBase)->with(['cafcRange', 'customer', 'significantEvent'])
            ->where('manual_status', ManualContingencyInvoiceStatus::PendingTranscription)
            ->oldest('issued_manually_at')->limit(12)->get();
        $cafcRanges = (clone $cafcBase)->with(['branch', 'pointOfSale'])
            ->whereIn('range_status', [CafcRangeStatus::Available, CafcRangeStatus::InUse])
            ->whereDate('authorized_until', '>=', today())->orderBy('authorized_until')->limit(12)->get();
        $activeAlerts = (clone $alertBase)->with(['branch', 'pointOfSale'])
            ->latest('last_detected_at')->limit(12)->get();
        $significantEventTypes = SinCatalogItem::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('catalog_key', 'eventos_significativos')
            ->where('is_active', true)
            ->whereIn('classifier_code', ['1', '2', '3', '4'])
            ->orderByRaw("nullif(classifier_code, '')::integer nulls last")
            ->get();

        return compact(
            'company', 'companies', 'branches', 'branch', 'points', 'point', 'filters',
            'latestCommunication', 'activeCuis', 'currentCufd', 'openEvent', 'metrics',
            'invoices', 'events', 'packages', 'manualInvoices', 'cafcRanges', 'activeAlerts',
            'significantEventTypes',
        ) + [
            'apiToken' => SinApiToken::query()->withoutGlobalScope('company')->where('company_id', $company->id)->first(),
            'fiscalStatuses' => InvoiceFiscalStatus::cases(),
            'emissionModes' => InvoiceEmissionMode::cases(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filteredInvoices(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('fiscal_status', $status))
            ->when($filters['modality'] ?? null, fn (Builder $query, string $mode) => $query->where('emission_mode', $mode))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issued_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issued_at', '<=', $date))
            ->when($filters['event_id'] ?? null, fn (Builder $query, int|string $event) => $query->where('sin_significant_event_id', (int) $event))
            ->when($filters['cuf'] ?? null, fn (Builder $query, string $cuf) => $query->where('cuf', 'ilike', '%'.$this->escapeLike($cuf).'%'))
            ->when($filters['number'] ?? null, fn (Builder $query, int|string $number) => $query->where(fn (Builder $nested) => $nested
                ->where('invoice_number', (int) $number)->orWhere('attempted_invoice_number', (int) $number)))
            ->when($filters['client'] ?? null, fn (Builder $query, string $client) => $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery
                ->where(fn (Builder $match) => $match->where('name', 'ilike', '%'.$this->escapeLike($client).'%')
                    ->orWhere('document_number', 'ilike', '%'.$this->escapeLike($client).'%'))));
    }

    /** @param array<string, mixed> $filters */
    private function filteredEvents(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['event_id'] ?? null, fn (Builder $query, int|string $id) => $query->whereKey((int) $id))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('started_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('started_at', '<=', $date));
    }

    private function location(Builder $query, int $companyId, ?int $branchId, ?int $pointId): Builder
    {
        return $query->where('company_id', $companyId)
            ->when($branchId, fn (Builder $query) => $query->where('sin_branch_id', $branchId))
            ->when($pointId, fn (Builder $query) => $query->where('sin_point_of_sale_id', $pointId));
    }

    /** @param array<string, mixed> $filters @return array{Company, Collection<int, Company>} */
    private function companyContext(User $user, array $filters): array
    {
        $companies = Company::query()->where('is_active', true)->orderBy('name')->get();
        $companyId = CompanyContext::isGlobalAdmin($user)
            ? (int) ($filters['company_id'] ?? $companies->first()?->id)
            : (int) $user->company_id;
        $company = $companies->firstWhere('id', $companyId) ?? Company::query()->find($companyId);

        if (! $company || ! CompanyContext::belongsToUser((int) $company->id, $user)) {
            throw ValidationException::withMessages(['company_id' => 'La empresa seleccionada no está disponible para este usuario.']);
        }

        return [$company, CompanyContext::isGlobalAdmin($user) ? $companies : $companies->where('id', $company->id)->values()];
    }

    /** @param Collection<int, SinBranch> $branches */
    private function selectedBranch(Collection $branches, mixed $requested): ?SinBranch
    {
        return $branches->firstWhere('id', (int) $requested) ?? $branches->first();
    }

    /** @param Collection<int, SinPointOfSale> $points */
    private function selectedPoint(Collection $points, mixed $requested): ?SinPointOfSale
    {
        return $points->firstWhere('id', (int) $requested) ?? $points->first();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
