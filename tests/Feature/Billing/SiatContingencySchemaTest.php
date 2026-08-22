<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatCommunicationOutcome;
use App\Enums\SignificantEventStatus;
use App\Models\Company;
use App\Models\SinCafcRange;
use App\Models\SinCommunicationLog;
use App\Models\SinFiscalStatusHistory;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinInvoicePackageItem;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use App\Models\SinSignificantEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SiatContingencySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_contingency_tables_and_explicit_columns_exist(): void
    {
        foreach ([
            'sin_significant_events', 'sin_invoice_packages', 'sin_invoice_package_items',
            'sin_cafc_ranges', 'sin_manual_contingency_invoices', 'sin_siat_attempts',
            'sin_communication_logs', 'sin_fiscal_status_history', 'sin_response_messages',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        self::assertTrue(Schema::hasColumns('sin_significant_events', [
            'event_status', 'sin_branch_id', 'sin_point_of_sale_id', 'registered_by_user_id', 'closed_by_user_id',
        ]));
        self::assertTrue(Schema::hasColumns('sin_invoice_packages', [
            'package_status', 'package_number', 'file_hash', 'reception_code', 'invoice_count',
        ]));
        self::assertTrue(Schema::hasColumns('sin_cafc_ranges', [
            'cafc_code', 'range_start', 'range_end', 'next_number', 'range_status',
        ]));
    }

    public function test_factories_create_typed_records_and_relationships(): void
    {
        $invoice = SinInvoiceIssue::factory()->create([
            'emission_type_code' => 2,
            'emission_mode' => InvoiceEmissionMode::OfflineDigital,
            'fiscal_status' => InvoiceFiscalStatus::OfflineIssued,
        ]);
        $event = SinSignificantEvent::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => $invoice->id,
            'sin_branch_id' => $invoice->sin_branch_id,
            'sin_point_of_sale_id' => $invoice->sin_point_of_sale_id,
            'sin_cuis_id' => $invoice->sin_cuis_id,
            'sin_cufd_id' => $invoice->sin_cufd_id,
            'sin_api_token_id' => $invoice->sin_api_token_id,
            'sin_authorization_id' => $invoice->sin_authorization_id,
        ]);
        $package = SinInvoicePackage::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_significant_event_id' => $event->id,
            'sin_branch_id' => $invoice->sin_branch_id,
            'sin_point_of_sale_id' => $invoice->sin_point_of_sale_id,
            'sin_cuis_id' => $invoice->sin_cuis_id,
            'sin_cufd_id' => $invoice->sin_cufd_id,
            'sin_api_token_id' => $invoice->sin_api_token_id,
            'sin_authorization_id' => $invoice->sin_authorization_id,
            'invoice_count' => 1,
            'tax_id' => $invoice->tax_id,
            'environment_code' => $invoice->environment_code,
            'modality_code' => $invoice->modality_code,
            'emission_type_code' => $invoice->emission_type_code,
            'document_sector_code' => $invoice->document_sector_code,
            'invoice_document_type_code' => $invoice->invoice_document_type_code,
            'branch_code' => $invoice->branch_code,
            'point_of_sale_code' => $invoice->point_of_sale_code,
        ]);
        $item = SinInvoicePackageItem::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_package_id' => $package->id,
            'sin_invoice_issue_id' => $invoice->id,
            'cuf' => $invoice->cuf,
        ]);
        $attempt = SinSiatAttempt::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => $invoice->id,
        ]);
        $message = SinResponseMessage::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_siat_attempt_id' => $attempt->id,
        ]);
        $history = SinFiscalStatusHistory::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => $invoice->id,
            'sin_siat_attempt_id' => $attempt->id,
        ]);
        $communication = SinCommunicationLog::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_branch_id' => $invoice->sin_branch_id,
            'sin_point_of_sale_id' => $invoice->sin_point_of_sale_id,
        ]);
        $cafc = SinCafcRange::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_branch_id' => $invoice->sin_branch_id,
            'sin_point_of_sale_id' => $invoice->sin_point_of_sale_id,
        ]);
        $manual = SinManualContingencyInvoice::factory()->create([
            'company_id' => $invoice->company_id,
            'sin_invoice_issue_id' => SinInvoiceIssue::factory()->create([
                'company_id' => $invoice->company_id,
                'sin_branch_id' => $invoice->sin_branch_id,
                'sin_point_of_sale_id' => $invoice->sin_point_of_sale_id,
                'emission_mode' => InvoiceEmissionMode::ManualCafc,
            ])->id,
            'sin_cafc_range_id' => $cafc->id,
            'sin_branch_id' => $invoice->sin_branch_id,
            'sin_point_of_sale_id' => $invoice->sin_point_of_sale_id,
        ]);

        self::assertSame(SignificantEventStatus::Open, $event->event_status);
        self::assertSame(InvoicePackageStatus::Created, $package->package_status);
        self::assertSame(SiatAttemptStatus::Pending, $attempt->attempt_status);
        self::assertSame(InvoiceFiscalStatus::PendingOnlineSend, $history->to_status);
        self::assertSame(SiatCommunicationOutcome::Available, $communication->outcome);
        self::assertSame(CafcRangeStatus::Available, $cafc->range_status);
        self::assertSame(ManualContingencyInvoiceStatus::PendingTranscription, $manual->manual_status);
        self::assertTrue($item->invoice->is($invoice));
        self::assertTrue($message->attempt->is($attempt));
        self::assertTrue($package->significantEvent->is($event));
    }

    public function test_database_rejects_cross_company_point_of_sale_relationships(): void
    {
        $firstCompany = Company::factory()->create();
        $secondCompany = Company::factory()->create();
        $branchId = DB::table('sin_branches')->insertGetId([
            'company_id' => $firstCompany->id, 'branch_code' => 15, 'name' => 'Sucursal A',
            'is_main' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('sin_points_of_sale')->insert([
            'company_id' => $secondCompany->id, 'sin_branch_id' => $branchId,
            'point_of_sale_code' => 1, 'name' => 'PV cruzado', 'is_default' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_prevents_duplicate_cuf_for_every_fiscal_state(): void
    {
        $invoice = SinInvoiceIssue::factory()->create();

        $this->expectException(QueryException::class);
        SinInvoiceIssue::factory()->create(['company_id' => $invoice->company_id, 'cuf' => $invoice->cuf]);
    }

    public function test_database_prevents_reusing_a_manual_cafc_number(): void
    {
        $manual = SinManualContingencyInvoice::factory()->create();

        $this->expectException(QueryException::class);
        SinManualContingencyInvoice::factory()->create([
            'company_id' => $manual->company_id,
            'sin_cafc_range_id' => $manual->sin_cafc_range_id,
            'sin_branch_id' => $manual->sin_branch_id,
            'sin_point_of_sale_id' => $manual->sin_point_of_sale_id,
            'manual_invoice_number' => $manual->manual_invoice_number,
        ]);
    }

    public function test_database_prevents_duplicate_invoice_reception_codes(): void
    {
        $invoice = SinInvoiceIssue::factory()->create(['reception_code' => 'RECEPTION-UNIQUE-001']);

        $this->expectException(QueryException::class);
        SinInvoiceIssue::factory()->create([
            'company_id' => $invoice->company_id,
            'reception_code' => 'RECEPTION-UNIQUE-001',
        ]);
    }

    public function test_database_allows_only_one_open_event_per_company_and_point_of_sale(): void
    {
        $event = SinSignificantEvent::factory()->create([
            'event_status' => SignificantEventStatus::Open,
            'ended_at' => null,
        ]);

        $this->expectException(QueryException::class);
        SinSignificantEvent::factory()->create([
            'company_id' => $event->company_id,
            'sin_branch_id' => $event->sin_branch_id,
            'sin_point_of_sale_id' => $event->sin_point_of_sale_id,
            'sin_api_token_id' => $event->sin_api_token_id,
            'sin_authorization_id' => $event->sin_authorization_id,
            'sin_cuis_id' => $event->sin_cuis_id,
            'sin_cufd_id' => $event->sin_cufd_id,
            'event_status' => SignificantEventStatus::Open,
            'ended_at' => null,
        ]);
    }

    public function test_siat_attempt_must_reference_exactly_one_target(): void
    {
        $invoice = SinInvoiceIssue::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('sin_siat_attempts')->insert([
            'company_id' => $invoice->company_id,
            'idempotency_key' => fake()->uuid(),
            'operation' => 'RECEIVE_INVOICE',
            'attempt_number' => 1,
            'attempt_status' => 'PENDING',
            'duration_ms' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
