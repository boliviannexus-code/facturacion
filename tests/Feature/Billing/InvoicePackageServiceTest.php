<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\PackageValidationOutcome;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatErrorType;
use App\Enums\SiatModality;
use App\Enums\SignificantEventStatus;
use App\Jobs\SendContingencyPackageJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinPointOfSale;
use App\Models\SinSiatAttempt;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Billing\InvoicePackageService;
use App\Services\Billing\Packages\Contracts\InvoicePackageSiatClient;
use App\Services\Billing\Packages\PackageInvoiceValidationResult;
use App\Services\Billing\Packages\PackageReceptionResult;
use App\Services\Billing\Packages\PackageTransportException;
use App\Services\Billing\Packages\PackageValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\SequenceInvoicePackageSiatClient;
use Tests\TestCase;

final class InvoicePackageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    #[DataProvider('invoiceVolumes')]
    public function test_builds_packages_in_groups_of_at_most_500_using_original_xml(
        int $invoiceCount,
        array $expectedCounts,
    ): void {
        $context = $this->context($invoiceCount);
        $packages = $this->service(new SequenceInvoicePackageSiatClient)
            ->buildForEvent($context['event'], $context['user']);

        self::assertSame($expectedCounts, $packages->pluck('invoice_count')->all());
        self::assertSame($invoiceCount, $packages->sum('invoice_count'));
        self::assertSame($invoiceCount, DB::table('sin_invoice_package_items')->count());
        self::assertSame(
            $invoiceCount,
            SinInvoiceIssue::query()->withoutGlobalScope('company')
                ->where('fiscal_status', InvoiceFiscalStatus::Packaged)
                ->count(),
        );

        foreach ($packages as $package) {
            self::assertSame(InvoicePackageStatus::PendingSend, $package->package_status);
            self::assertLessThanOrEqual(500, $package->invoice_count);
            Storage::disk('local')->assertExists((string) $package->file_path);
            $archive = Storage::disk('local')->get((string) $package->file_path);
            self::assertSame(hash('sha256', $archive), $package->file_hash);
            self::assertSame(strlen($archive), $package->file_size);
            self::assertSame($package->invoice_count, $package->items()->count());
        }

        if ($invoiceCount > 0) {
            $first = $context['invoices']->firstOrFail();
            $archive = Storage::disk('local')->get((string) $packages->firstOrFail()->file_path);
            self::assertStringContainsString($this->xml($first->cuf), (string) gzdecode($archive));
        }
    }

    /** @return array<string, array{int, array<int, int>}> */
    public static function invoiceVolumes(): array
    {
        return [
            'one invoice' => [1, [1]],
            'exactly 500 invoices' => [500, [500]],
            '501 invoices' => [501, [500, 1]],
            '1240 invoices' => [1240, [500, 500, 240]],
        ];
    }

    public function test_building_the_same_event_twice_does_not_duplicate_packages_or_items(): void
    {
        $context = $this->context(2);
        $service = $this->service(new SequenceInvoicePackageSiatClient);

        $first = $service->buildForEvent($context['event'], $context['user']);
        $second = $service->buildForEvent($context['event']->refresh(), $context['user']);

        self::assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        self::assertSame(1, SinInvoicePackage::query()->withoutGlobalScope('company')->count());
        self::assertSame(2, DB::table('sin_invoice_package_items')->count());
    }

    public function test_builds_manual_cafc_invoice_as_offline_package_with_cafc_code(): void
    {
        $context = $this->context(1);
        $invoice = $context['invoices']->firstOrFail();
        $invoice->forceFill([
            'emission_mode' => InvoiceEmissionMode::ManualCafc,
            'fiscal_status' => InvoiceFiscalStatus::PendingPackage,
        ])->save();
        $range = SinCafcRange::factory()->create([
            'company_id' => $context['company']->id,
            'sin_branch_id' => $context['branch']->id,
            'sin_point_of_sale_id' => $context['point']->id,
            'created_by_user_id' => $context['user']->id,
            'sin_significant_event_id' => $context['event']->id,
            'cafc_code' => 'CAFC-PACKAGE-TEST',
        ]);
        SinManualContingencyInvoice::factory()->create([
            'company_id' => $context['company']->id,
            'sin_cafc_range_id' => $range->id,
            'sin_significant_event_id' => $context['event']->id,
            'sin_invoice_issue_id' => $invoice->id,
            'sin_branch_id' => $context['branch']->id,
            'sin_point_of_sale_id' => $context['point']->id,
            'created_by_user_id' => $context['user']->id,
        ]);

        $package = $this->service(new SequenceInvoicePackageSiatClient)
            ->buildForEvent($context['event'], $context['user'])
            ->firstOrFail();

        self::assertSame(InvoiceEmissionMode::ManualCafc, $package->emission_mode);
        self::assertSame('CAFC-PACKAGE-TEST', $package->cafc_code);
        self::assertSame(2, $package->emission_type_code);
        self::assertSame($invoice->id, $package->items()->firstOrFail()->sin_invoice_issue_id);
    }

    public function test_excludes_manual_and_already_validated_invoices_from_digital_packages(): void
    {
        $context = $this->context(3);
        $context['invoices'][1]->forceFill([
            'emission_mode' => InvoiceEmissionMode::ManualCafc,
            'fiscal_status' => InvoiceFiscalStatus::ManualPendingSend,
        ])->save();
        $context['invoices'][2]->forceFill([
            'fiscal_status' => InvoiceFiscalStatus::ValidatedAfterContingency,
            'transaccion' => true,
        ])->save();

        $packages = $this->service(new SequenceInvoicePackageSiatClient)
            ->buildForEvent($context['event'], $context['user']);

        self::assertCount(1, $packages);
        self::assertSame(1, $packages->firstOrFail()->invoice_count);
        $this->assertDatabaseMissing('sin_invoice_package_items', [
            'sin_invoice_issue_id' => $context['invoices'][1]->id,
        ]);
        $this->assertDatabaseMissing('sin_invoice_package_items', [
            'sin_invoice_issue_id' => $context['invoices'][2]->id,
        ]);
    }

    public function test_separates_different_document_types_into_distinct_packages(): void
    {
        $context = $this->context(2);
        $context['invoices'][1]->forceFill(['invoice_document_type_code' => 2])->save();

        $packages = $this->service(new SequenceInvoicePackageSiatClient)
            ->buildForEvent($context['event'], $context['user']);

        self::assertCount(2, $packages);
        self::assertSame([1, 2], $packages->pluck('invoice_document_type_code')->sort()->values()->all());
        self::assertSame([1, 1], $packages->pluck('invoice_count')->all());
    }

    public function test_reception_code_only_marks_package_pending_until_validation_succeeds(): void
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient(
            [$this->acceptedReception('RECEPTION-VALIDATED')],
            [$this->validation(PackageValidationOutcome::Validated, 908, 'Paquete validado.')],
        );
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();

        $sent = $service->send($package, $context['user']);

        self::assertSame(InvoicePackageStatus::PendingValidation, $sent->package->package_status);
        self::assertSame('RECEPTION-VALIDATED', $sent->package->reception_code);
        self::assertSame(InvoiceFiscalStatus::PackageSent, $context['invoices'][0]->refresh()->fiscal_status);
        self::assertNull($sent->package->validated_at);

        $validated = $service->checkValidation($sent->package, $context['user']);

        self::assertSame(InvoicePackageStatus::Validated, $validated->package->package_status);
        self::assertSame(
            InvoiceFiscalStatus::ValidatedAfterContingency,
            $context['invoices'][0]->refresh()->fiscal_status,
        );
        self::assertSame(SignificantEventStatus::Completed, $context['event']->refresh()->event_status);
        self::assertSame(1, $fake->sendCalls);
        self::assertSame(1, $fake->validationCalls);
        $this->assertDatabaseHas('sin_response_messages', ['description' => 'Paquete validado.']);
    }

    public function test_marks_package_and_invoices_as_observed(): void
    {
        [$context, $service, $package] = $this->sentPackage(
            $this->validation(PackageValidationOutcome::Observed, 904, 'Paquete observado.'),
        );

        $result = $service->checkValidation($package, $context['user']);

        self::assertSame(InvoicePackageStatus::Observed, $result->package->package_status);
        self::assertSame(InvoiceFiscalStatus::Observed, $context['invoices'][0]->refresh()->fiscal_status);
        self::assertFalse($context['invoices'][0]->transaccion);
    }

    public function test_marks_package_and_invoices_as_rejected(): void
    {
        [$context, $service, $package] = $this->sentPackage(
            $this->validation(PackageValidationOutcome::Rejected, 902, 'Paquete rechazado.'),
        );

        $result = $service->checkValidation($package, $context['user']);

        self::assertSame(InvoicePackageStatus::Rejected, $result->package->package_status);
        self::assertSame(InvoiceFiscalStatus::Rejected, $context['invoices'][0]->refresh()->fiscal_status);
    }

    public function test_applies_an_individual_rejection_without_changing_other_invoice_results(): void
    {
        $context = $this->context(2);
        $first = $context['invoices'][0];
        $second = $context['invoices'][1];
        $validation = $this->validation(
            PackageValidationOutcome::Observed,
            904,
            'Paquete con observaciones.',
            [
                new PackageInvoiceValidationResult(
                    $first->cuf,
                    PackageValidationOutcome::Validated,
                    908,
                    'Factura validada.',
                ),
                new PackageInvoiceValidationResult(
                    $second->cuf,
                    PackageValidationOutcome::Rejected,
                    902,
                    'Factura rechazada.',
                ),
            ],
        );
        $fake = new SequenceInvoicePackageSiatClient(
            [$this->acceptedReception('RECEPTION-MIXED')],
            [$validation],
        );
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();
        $package = $service->send($package, $context['user'])->package;

        $service->checkValidation($package, $context['user']);

        self::assertSame(InvoiceFiscalStatus::ValidatedAfterContingency, $first->refresh()->fiscal_status);
        self::assertSame(InvoiceFiscalStatus::Rejected, $second->refresh()->fiscal_status);
        self::assertTrue($first->transaccion);
        self::assertFalse($second->transaccion);
    }

    public function test_running_send_job_twice_does_not_send_or_create_attempt_twice(): void
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient([$this->acceptedReception('RECEPTION-ONCE')]);
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();
        $job = new SendContingencyPackageJob($context['company']->id, $package->id, $context['user']->id);

        $job->handle($service);
        $job->handle($service);

        self::assertSame(1, $fake->sendCalls);
        self::assertSame(1, $package->attempts()->count());
        self::assertSame(1, $package->items()->count());
        self::assertSame(InvoicePackageStatus::PendingValidation, $package->refresh()->package_status);
    }

    public function test_network_error_before_send_keeps_package_retryable_without_receipt(): void
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient([
            new PackageTransportException(
                'No existe conexion a internet.',
                false,
                SiatErrorType::NoInternet,
            ),
        ]);
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();

        $result = $service->send($package, $context['user']);

        self::assertTrue($result->pending);
        self::assertTrue($result->retryable);
        self::assertSame(InvoicePackageStatus::PendingSend, $result->package->package_status);
        self::assertNull($result->package->reception_code);
        self::assertSame(SiatAttemptStatus::Failed, $package->attempts()->firstOrFail()->attempt_status);
    }

    public function test_lost_response_after_send_is_not_automatically_resent(): void
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient([
            new PackageTransportException(
                'Timeout esperando la respuesta del SIAT.',
                true,
                SiatErrorType::Timeout,
            ),
        ]);
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();

        $first = $service->send($package, $context['user']);
        $second = $service->send($first->package, $context['user']);

        self::assertSame(InvoicePackageStatus::Sent, $first->package->package_status);
        self::assertTrue($first->pending);
        self::assertFalse($first->retryable);
        self::assertFalse($second->retryable);
        self::assertSame(1, $fake->sendCalls);
        self::assertSame(SiatAttemptStatus::Uncertain, $package->attempts()->firstOrFail()->attempt_status);
    }

    public function test_stale_send_claim_is_marked_uncertain_without_resending(): void
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient([$this->acceptedReception('MUST-NOT-SEND')]);
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();
        $package->update([
            'send_claim' => fake()->uuid(),
            'send_claimed_at' => now()->subMinutes(10),
        ]);
        $attempt = SinSiatAttempt::factory()->create([
            'company_id' => $context['company']->id,
            'sin_invoice_issue_id' => null,
            'sin_significant_event_id' => null,
            'sin_invoice_package_id' => $package->id,
            'operation' => 'RECEIVE_PACKAGE',
            'attempt_number' => 1,
            'attempt_status' => SiatAttemptStatus::Sending,
            'finished_at' => null,
        ]);

        $result = $service->send($package->refresh(), $context['user']);

        self::assertSame(0, $fake->sendCalls);
        self::assertSame(InvoicePackageStatus::Sent, $result->package->package_status);
        self::assertSame(SiatAttemptStatus::Uncertain, $attempt->refresh()->attempt_status);
        self::assertFalse($result->retryable);
    }

    public function test_failed_package_can_be_retried_by_an_authorized_operator(): void
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient([$this->acceptedReception('ADMIN-RETRY-OK')]);
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();
        $package->update(['package_status' => InvoicePackageStatus::Failed]);

        $result = $service->send($package->refresh(), $context['user']);

        self::assertSame(1, $fake->sendCalls);
        self::assertSame(InvoicePackageStatus::PendingValidation, $result->package->package_status);
    }

    /**
     * @return array{array<string, mixed>, InvoicePackageService, SinInvoicePackage}
     */
    private function sentPackage(PackageValidationResult $validation): array
    {
        $context = $this->context(1);
        $fake = new SequenceInvoicePackageSiatClient(
            [$this->acceptedReception('RECEPTION-'.strtoupper($validation->outcome->value))],
            [$validation],
        );
        $service = $this->service($fake);
        $package = $service->buildForEvent($context['event'], $context['user'])->firstOrFail();
        $package = $service->send($package, $context['user'])->package;

        return [$context, $service, $package];
    }

    private function service(SequenceInvoicePackageSiatClient $fake): InvoicePackageService
    {
        $this->app->instance(InvoicePackageSiatClient::class, $fake);

        return app(InvoicePackageService::class);
    }

    private function acceptedReception(string $code): PackageReceptionResult
    {
        return new PackageReceptionResult(
            accepted: true,
            receptionCode: $code,
            statusCode: null,
            message: 'Paquete recibido y pendiente de validacion.',
            response: ['transaccion' => true, 'codigoRecepcion' => $code],
            messages: [['codigo' => 'SIM-RECEPTION', 'descripcion' => 'Paquete recibido.']],
            durationMs: 10,
        );
    }

    /** @param array<int, PackageInvoiceValidationResult> $invoiceResults */
    private function validation(
        PackageValidationOutcome $outcome,
        int $statusCode,
        string $message,
        array $invoiceResults = [],
    ): PackageValidationResult {
        return new PackageValidationResult(
            outcome: $outcome,
            statusCode: $statusCode,
            message: $message,
            response: ['codigoEstado' => $statusCode, 'mensaje' => $message],
            messages: [['codigo' => (string) $statusCode, 'descripcion' => $message]],
            invoiceResults: $invoiceResults,
            durationMs: 12,
        );
    }

    /** @return array<string, mixed> */
    private function context(int $invoiceCount): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $branch = SinBranch::factory()->create(['company_id' => $company->id, 'branch_code' => 1]);
        $point = SinPointOfSale::factory()->create([
            'company_id' => $company->id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 1,
        ]);
        $token = SinApiToken::factory()->create(['company_id' => $company->id]);
        $authorization = SinAuthorization::factory()->create([
            'company_id' => $company->id,
            'tax_id' => '123456789',
        ]);
        $cuis = SinCuis::factory()->create([
            'company_id' => $company->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'branch_code' => 1,
            'point_of_sale_code' => 1,
        ]);
        $oldCufd = SinCufd::factory()->create([
            'company_id' => $company->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => 1,
            'point_of_sale_code' => 1,
            'expires_at' => now()->subMinute(),
        ]);
        $recoveryCufd = SinCufd::factory()->create([
            'company_id' => $company->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => 1,
            'point_of_sale_code' => 1,
            'expires_at' => now()->addDay(),
        ]);
        $recoveredAt = now()->subMinute()->startOfSecond();
        $event = SinSignificantEvent::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'registered_by_user_id' => $user->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'sin_cuis_id' => $cuis->id,
            'sin_cufd_id' => $oldCufd->id,
            'recovery_sin_cufd_id' => $recoveryCufd->id,
            'event_status' => SignificantEventStatus::Registered,
            'ended_at' => $recoveredAt,
            'recovery_detected_at' => $recoveredAt,
            'reception_code' => 'EVENT-'.str_pad((string) $company->id, 8, '0', STR_PAD_LEFT),
            'transaccion' => true,
            'status_label' => 'Registrado',
            'registered_at' => now(),
        ]);

        $this->insertInvoices(
            $invoiceCount,
            compact('company', 'user', 'customer', 'branch', 'point', 'token', 'authorization', 'cuis', 'oldCufd', 'event'),
        );
        $invoices = SinInvoiceIssue::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('sin_significant_event_id', $event->id)
            ->orderBy('id')
            ->get();

        return compact(
            'company', 'user', 'customer', 'branch', 'point', 'token', 'authorization',
            'cuis', 'oldCufd', 'recoveryCufd', 'event', 'invoices',
        );
    }

    /** @param array<string, mixed> $context */
    private function insertInvoices(int $count, array $context): void
    {
        $rows = [];
        $now = now();

        for ($position = 1; $position <= $count; $position++) {
            $cuf = 'PKG'.str_pad((string) $position, 20, '0', STR_PAD_LEFT);
            $xmlPath = "siat/invoices/{$context['company']->id}/{$cuf}.xml";
            $xml = $this->xml($cuf);
            Storage::disk('local')->put($xmlPath, $xml);
            $rows[] = [
                'company_id' => $context['company']->id,
                'user_id' => $context['user']->id,
                'customer_id' => $context['customer']->id,
                'sin_api_token_id' => $context['token']->id,
                'sin_authorization_id' => $context['authorization']->id,
                'sin_branch_id' => $context['branch']->id,
                'sin_point_of_sale_id' => $context['point']->id,
                'sin_cuis_id' => $context['cuis']->id,
                'sin_cufd_id' => $context['oldCufd']->id,
                'sin_significant_event_id' => $context['event']->id,
                'tax_id' => '123456789',
                'environment_code' => SiatEnvironment::TestingAndPilot->value,
                'modality_code' => SiatModality::ComputerizedOnline->value,
                'emission_type_code' => 2,
                'document_sector_code' => 1,
                'invoice_document_type_code' => 1,
                'emission_mode' => InvoiceEmissionMode::OfflineDigital->value,
                'commercial_status' => InvoiceCommercialStatus::Confirmed->value,
                'fiscal_status' => InvoiceFiscalStatus::OfflineIssued->value,
                'branch_code' => 1,
                'point_of_sale_code' => 1,
                'attempted_invoice_number' => $position,
                'invoice_number' => $position,
                'cuf' => $cuf,
                'cufd_code' => (string) $context['oldCufd']->cufd_code,
                'status_label' => 'Emitida fuera de linea',
                'transaccion' => false,
                'xml_path' => $xmlPath,
                'hash_file' => hash('sha256', $xml),
                'subtotal_amount' => 100,
                'discount_amount' => 0,
                'total_amount' => 100,
                'taxable_amount' => 100,
                'payload' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
                'duration_ms' => 0,
                'issued_at' => $now->copy()->addSeconds($position),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 200) {
                DB::table('sin_invoice_issues')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('sin_invoice_issues')->insert($rows);
        }
    }

    private function xml(string $cuf): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><factura><cuf>{$cuf}</cuf></factura>";
    }
}
