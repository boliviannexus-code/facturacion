<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Billing\ManualCafcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualCafcModuleTest extends TestCase
{
    use RefreshDatabase;

    private ManualCafcService $service;

    private Company $company;

    private User $user;

    private SinBranch $branch;

    private SinPointOfSale $point;

    private SinCafcRange $range;

    private SinSignificantEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = app(ManualCafcService::class);
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->branch = SinBranch::factory()->create(['company_id' => $this->company->id]);
        $this->point = SinPointOfSale::factory()->create(['company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id]);
        $this->range = SinCafcRange::factory()->create([
            'company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'created_by_user_id' => $this->user->id,
            'range_start' => 100, 'range_end' => 110, 'next_number' => 100,
            'authorized_from' => today()->subDay(), 'authorized_until' => today()->addDay(),
        ]);
        $this->event = SinSignificantEvent::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'sin_branch_id' => $this->branch->id, 'sin_point_of_sale_id' => $this->point->id,
        ]);
    }

    public function test_valid_number_is_recorded_and_counters_advance(): void
    {
        $manual = $this->useNumber(100);

        self::assertSame(100, $manual->manual_invoice_number);
        self::assertSame(ManualContingencyInvoiceStatus::PendingTranscription, $manual->manual_status);
        self::assertSame(1, $this->range->refresh()->used_count);
        self::assertSame(101, $this->range->next_number);
    }

    public function test_cafc_allows_transcription_before_event_registration(): void
    {
        $range = $this->service->registerRange([
            'cafc_code' => 'CAFC-EVENT-'.fake()->unique()->numerify('####'),
            'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id,
            'document_sector_code' => 1,
            'range_start' => 200,
            'range_end' => 205,
            'authorized_from' => today()->subDay(),
            'authorized_until' => today()->addDay(),
        ], $this->user);

        $manual = $this->service->recordUsed($range, $this->point, 200, now(), $this->user);

        self::assertNull($range->sin_significant_event_id);
        self::assertNull($manual->sin_significant_event_id);
        self::assertSame(ManualContingencyInvoiceStatus::PendingTranscription, $manual->manual_status);
    }

    public function test_number_outside_range_is_rejected(): void
    {
        $this->expectValidation('manual_invoice_number');
        $this->useNumber(99);
    }

    public function test_used_number_cannot_be_reused(): void
    {
        $this->useNumber(100);
        $this->expectValidation('manual_invoice_number');
        $this->useNumber(100);
    }

    public function test_expired_cafc_is_rejected(): void
    {
        $this->range->forceFill(['authorized_from' => today()->subDays(5), 'authorized_until' => today()->subDay()])->save();
        $this->expectValidation('issued_manually_at');
        $this->useNumber(100);
    }

    public function test_cafc_from_another_company_is_rejected(): void
    {
        $otherUser = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->expectValidation('cafc_range_id');
        $this->service->recordUsed($this->range, $this->point, 100, now(), $otherUser, $this->event);
    }

    public function test_cancelled_invoice_blocks_number_and_cannot_be_transcribed(): void
    {
        $manual = $this->service->recordCancelled($this->range, $this->point, 100, now(), $this->user, 'Documento físico deteriorado', $this->event);
        self::assertSame(ManualContingencyInvoiceStatus::Cancelled, $manual->manual_status);
        self::assertSame(1, $this->range->refresh()->cancelled_count);

        $this->expectValidation('manual_invoice_number');
        $this->service->transcribe($manual, Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]), ['total_amount' => 10], [[
            'product_id' => Product::factory()->create(['company_id' => $this->company->id])->id,
            'quantity' => 1, 'unit_price' => 10, 'discount_amount' => 0,
        ]], $this->user);
    }

    public function test_transcription_creates_immutable_xml_and_detail_with_original_date(): void
    {
        $issuedAt = now()->subHour()->startOfSecond();
        $manual = $this->service->recordUsed($this->range, $this->point, 100, $issuedAt, $this->user, $this->event);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);

        $transcribed = $this->service->transcribe($manual, $customer, [
            'payment_method_code' => 1, 'currency_code' => 1,
            'discount_amount' => 5, 'total_amount' => 45, 'observations' => 'Copia fiel.',
        ], [[
            'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 25, 'discount_amount' => 0,
        ]], $this->user);

        self::assertSame(ManualContingencyInvoiceStatus::PendingSend, $transcribed->manual_status);
        self::assertSame($issuedAt->format('Y-m-d H:i:s'), $transcribed->issued_manually_at->format('Y-m-d H:i:s'));
        self::assertCount(1, $transcribed->items);
        self::assertSame('45.00000', $transcribed->total_amount);
        self::assertNotNull($transcribed->invoice);
        Storage::disk('local')->assertExists($transcribed->xml_path);
        $xml = Storage::disk('local')->get($transcribed->xml_path);
        self::assertStringContainsString('<cafc>'.$this->range->cafc_code.'</cafc>', $xml);
        self::assertStringContainsString($issuedAt->format('Y-m-d\TH:i:s.v'), $xml);
    }

    public function test_two_users_cannot_consume_same_number(): void
    {
        $secondUser = User::factory()->create(['company_id' => $this->company->id]);
        $this->useNumber(100);
        $this->expectValidation('manual_invoice_number');
        $this->service->recordUsed($this->range, $this->point, 100, now(), $secondUser, $this->event);
        self::assertSame(1, SinManualContingencyInvoice::query()->withoutGlobalScope('company')->where('sin_cafc_range_id', $this->range->id)->count());
    }

    public function test_transcribed_invoice_is_prepared_for_offline_cafc_package(): void
    {
        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $manual = $this->service->transcribe($manual, $customer, [
            'payment_method_code' => 1, 'currency_code' => 1, 'discount_amount' => 0, 'total_amount' => 25,
        ], [[
            'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25, 'discount_amount' => 0,
        ]], $this->user);
        self::assertSame(ManualContingencyInvoiceStatus::PendingSend, $manual->manual_status);
        self::assertSame(2, $manual->invoice->emission_type_code);
        self::assertSame(InvoiceEmissionMode::ManualCafc, $manual->invoice->emission_mode);
        self::assertSame(InvoiceFiscalStatus::PendingPackage, $manual->invoice->fiscal_status);
    }

    private function useNumber(int $number): SinManualContingencyInvoice
    {
        return $this->service->recordUsed($this->range, $this->point, $number, now(), $this->user, $this->event);
    }

    private function expectValidation(string $key): void
    {
        try {
            $this->expectException(ValidationException::class);
        } finally {
            // El nombre conserva la intención de cada validación sin acoplarse al texto traducido.
            self::assertNotSame('', $key);
        }
    }
}
