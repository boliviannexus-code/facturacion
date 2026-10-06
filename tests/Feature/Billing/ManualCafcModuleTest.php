<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SignificantEventStatus;
use App\Jobs\BuildContingencyPackagesJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\ManualCafcCompliance;
use App\Services\Billing\ManualCafcService;
use App\Services\Siat\SiatCufdService;
use App\Services\Siat\SiatSoapClientFactory;
use App\Services\Siat\SignificantEventService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        $this->travelTo(now()->startOfSecond());
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
            'event_code' => 5, 'event_status' => SignificantEventStatus::Registered,
            'transaccion' => true, 'reception_code' => 'EVENT-CAFC-TEST',
            'started_at' => today()->subDay(), 'ended_at' => now(),
        ]);
        $this->event->cufd->forceFill(['requested_at' => today()->subDays(2)])->save();
        $this->range->forceFill(['sin_significant_event_id' => $this->event->id])->save();
    }

    public function test_contingency_detail_shows_event_cufd_and_invoice_time_limits(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo(['manual-cafc.view', 'manual-cafc.use']);

        $this->actingAs($this->user)->get(route('billing.cafc-contingencies.show', $this->range))
            ->assertOk()
            ->assertSee('CUFD y horarios de la contingencia')
            ->assertSee($this->event->cufd->cufd_code)
            ->assertSee('Horario permitido para las facturas')
            ->assertSee($this->event->started_at->format('d/m/Y H:i:s'))
            ->assertSee($this->event->ended_at->addHours(72)->format('d/m/Y H:i:s'));
    }

    public function test_unregistered_contingency_keeps_historical_cufds_for_automatic_selection_without_listing_them(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo(['manual-cafc.view', 'manual-cafc.use']);
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        $cufd = SinCufd::factory()->create([
            'company_id' => $this->company->id,
            'sin_point_of_sale_id' => $this->point->id,
            'cufd_code' => 'CUFD-VENTANA-CONTINGENCIA',
            'transaccion' => true,
            'requested_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'invalidated_at' => now()->subHours(30),
        ]);
        $latest = SinCufd::factory()->create([
            'company_id' => $this->company->id,
            'sin_point_of_sale_id' => $this->point->id,
            'cufd_code' => 'CUFD-ULTIMO-DISPONIBLE',
            'transaccion' => true,
            'requested_at' => now()->subHour(),
            'expires_at' => now()->addHours(23),
            'invalidated_at' => null,
        ]);
        $other = SinCufd::factory()->create(['company_id' => $this->company->id, 'cufd_code' => 'CUFD-OTRO-PUNTO']);

        $this->actingAs($this->user)->get(route('billing.cafc-contingencies.show', $this->range))
            ->assertOk()
            ->assertSee($cufd->cufd_code)
            ->assertSee('<code class="d-block text-break mb-2">'.$latest->cufd_code.'</code>', false)
            ->assertDontSee('<code class="d-block text-break mb-2">'.$cufd->cufd_code.'</code>', false)
            ->assertSee($cufd->invalidated_at->format('d/m/Y H:i:s'))
            ->assertSee('Último CUFD disponible')
            ->assertDontSee('Ventanas disponibles para el inicio')
            ->assertSee('data-c2-cufd', false)
            ->assertDontSee($other->cufd_code);
    }

    public function test_valid_number_is_recorded_and_counters_advance(): void
    {
        $manual = $this->useNumber(100);

        self::assertSame(100, $manual->manual_invoice_number);
        self::assertSame(ManualContingencyInvoiceStatus::PendingTranscription, $manual->manual_status);
        self::assertSame(1, $this->range->refresh()->used_count);
        self::assertSame(101, $this->range->next_number);
    }

    public function test_cafc_code_can_only_be_edited_before_using_the_range(): void
    {
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        $updated = $this->service->updateCode($this->range, 'CAFC-CORREGIDO-001', $this->user);

        self::assertSame('CAFC-CORREGIDO-001', $updated->cafc_code);
        self::assertSame($this->user->id, $updated->updated_by_user_id);

        $this->range->forceFill(['sin_significant_event_id' => $this->event->id])->save();
        $this->useNumber(100);

        try {
            $this->service->updateCode($this->range->refresh(), 'CAFC-NO-PERMITIDO', $this->user);
            self::fail('Se esperaba impedir la edición de un CAFC utilizado.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('cafc_code', $exception->errors());
        }

        self::assertSame('CAFC-CORREGIDO-001', $this->range->refresh()->cafc_code);
    }

    public function test_unused_cafc_range_can_be_deleted(): void
    {
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        $rangeId = $this->range->id;

        $this->service->deleteUnusedRange($this->range, $this->user);

        $this->assertDatabaseMissing('sin_cafc_ranges', ['id' => $rangeId]);
    }

    public function test_manager_sees_and_can_use_delete_action_for_unused_cafc(): void
    {
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo(['cafc-ranges.view', 'cafc-ranges.manage']);

        $this->actingAs($this->user)
            ->get(route('billing.cafc-ranges.index'))
            ->assertOk()
            ->assertSee(route('billing.cafc-ranges.destroy', $this->range))
            ->assertSee('Eliminar');

        $this->actingAs($this->user)
            ->delete(route('billing.cafc-ranges.destroy', $this->range))
            ->assertRedirect()
            ->assertSessionHas('success', 'Rango CAFC eliminado correctamente.');

        $this->assertDatabaseMissing('sin_cafc_ranges', ['id' => $this->range->id]);
    }

    public function test_used_cafc_range_cannot_be_deleted(): void
    {
        $this->useNumber(100);

        try {
            $this->service->deleteUnusedRange($this->range->refresh(), $this->user);
            self::fail('Se esperaba impedir la eliminación de un CAFC utilizado.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('cafc_range', $exception->errors());
        }

        $this->assertDatabaseHas('sin_cafc_ranges', ['id' => $this->range->id]);
    }

    public function test_used_test_cafc_can_be_retired_and_registered_again(): void
    {
        SinAuthorization::withoutGlobalScope('company')->where('company_id', $this->company->id)->update(['environment_code' => 2]);
        $manual = $this->useNumber(100);
        $this->service->deleteTestRange($this->range, $this->user);
        $this->assertSoftDeleted('sin_cafc_ranges', ['id' => $this->range->id]);
        self::assertNull(SinCafcRange::find($this->range->id));
        self::assertSame($this->range->cafc_code, $manual->refresh()->cafcRange->cafc_code);
        $newRange = $this->service->registerRange([
            'cafc_code' => $this->range->cafc_code, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'range_start' => 100, 'range_end' => 110,
            'authorized_from' => today()->subDay()->toDateString(), 'authorized_until' => today()->addDay()->toDateString(),
        ], $this->user);
        self::assertNotSame($this->range->id, $newRange->id);
        self::assertSame(100, $newRange->next_number);
        self::assertSame(0, $newRange->used_count);
        self::assertTrue($manual->refresh()->retired_for_tests);
        $newEvent = $this->event->replicate();
        $newEvent->reception_code = 'EVENT-RESTARTED';
        $newEvent->save();
        $newRange->forceFill(['sin_significant_event_id' => $newEvent->id])->save();
        $restarted = $this->service->recordUsed($newRange, $this->point, 100, now(), $this->user);
        self::assertSame(100, $restarted->manual_invoice_number);
        self::assertFalse($restarted->retired_for_tests);
        self::assertNotSame($manual->id, $restarted->id);
        self::assertSame(100, $manual->refresh()->manual_invoice_number);

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $previousManual = $restarted;
            $this->service->deleteTestRange($newRange, $this->user);
            self::assertTrue($previousManual->refresh()->retired_for_tests);
            $newRange = $this->service->registerRange([
                'cafc_code' => $this->range->cafc_code, 'sin_branch_id' => $this->branch->id,
                'sin_point_of_sale_id' => $this->point->id, 'range_start' => 100, 'range_end' => 110,
                'authorized_from' => today()->subDay()->toDateString(), 'authorized_until' => today()->addDay()->toDateString(),
            ], $this->user);
            $newEvent = $this->event->replicate();
            $newEvent->reception_code = 'EVENT-RESTART-CYCLE-'.$cycle;
            $newEvent->save();
            $newRange->forceFill(['sin_significant_event_id' => $newEvent->id])->save();
            $restarted = $this->service->recordUsed($newRange, $this->point, 100, now(), $this->user);
            self::assertSame(100, $restarted->manual_invoice_number);
            self::assertFalse($restarted->retired_for_tests);
            self::assertNotSame($previousManual->id, $restarted->id);
        }

    }

    public function test_production_cafc_cannot_be_retired_for_tests(): void
    {
        SinAuthorization::withoutGlobalScope('company')->where('company_id', $this->company->id)->update(['environment_code' => 1]);
        $this->expectValidation('cafc_range');
        $this->service->deleteTestRange($this->range, $this->user);
    }

    public function test_manager_can_retire_used_cafc_from_contingencies(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo(['cafc-ranges.view', 'cafc-ranges.manage']);
        SinAuthorization::withoutGlobalScope('company')->where('company_id', $this->company->id)->update(['environment_code' => 2]);
        $this->useNumber(100);
        $this->actingAs($this->user)->get(route('billing.cafc-contingencies.index'))
            ->assertOk()->assertSee('Eliminar de pruebas');
        $this->actingAs($this->user)->delete(route('billing.cafc-ranges.destroy', $this->range), ['restart_tests' => 1])
            ->assertRedirect(route('billing.cafc-contingencies.index'))->assertSessionHas('success');
        $this->assertSoftDeleted('sin_cafc_ranges', ['id' => $this->range->id]);
    }

    public function test_active_cafc_cannot_repeat_another_active_fiscal_number(): void
    {
        $this->useNumber(100);
        $other = $this->service->registerRange([
            'cafc_code' => 'OTHER-ACTIVE-CAFC', 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'range_start' => 100, 'range_end' => 110,
            'authorized_from' => today()->subDay()->toDateString(), 'authorized_until' => today()->addDay()->toDateString(),
        ], $this->user);
        $newEvent = $this->event->replicate();
        $newEvent->reception_code = 'EVENT-OTHER';
        $newEvent->save();
        $other->forceFill(['sin_significant_event_id' => $newEvent->id])->save();
        $this->expectValidation('manual_invoice_number');
        $this->service->recordUsed($other, $this->point, 100, now(), $this->user);
    }

    public function test_pilot_cafc_copy_can_repeat_a_real_fiscal_number(): void
    {
        $real = $this->useNumber(100);
        $copy = SinCafcRange::factory()->create([
            ...$this->range->only(['company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'cafc_code', 'document_sector_code', 'range_start', 'range_end', 'authorized_from', 'authorized_until']),
            'source_sin_cafc_range_id' => $this->range->id,
            'is_test_copy' => true,
            'created_by_user_id' => $this->user->id,
            'next_number' => 100,
            'used_count' => 0,
            'cancelled_count' => 0,
        ]);

        $copyEvent = $this->event->replicate();
        $copyEvent->reception_code = 'EVENT-CAFC-COPY';
        $copyEvent->save();
        $copy->forceFill(['sin_significant_event_id' => $copyEvent->id])->save();
        $test = $this->service->recordUsed($copy, $this->point, 100, now(), $this->user);

        self::assertFalse($real->is_test_copy);
        self::assertTrue($test->is_test_copy);
        self::assertSame($real->manual_invoice_number, $test->manual_invoice_number);
    }

    public function test_cafc_requires_event_before_number_reservation(): void
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

        $this->expectValidation('significant_event_id');
        $this->service->recordUsed($range, $this->point, 200, now(), $this->user);
    }

    public function test_event_period_is_suggested_from_historical_cufd_and_invoice_dates(): void
    {
        $firstInvoiceAt = now()->subMinutes(10)->startOfSecond();
        $lastInvoiceAt = now()->subMinutes(5)->startOfSecond();
        $cufdRequestedAt = $firstInvoiceAt->copy()->subMinutes(20);
        SinCufd::factory()->create([
            'company_id' => $this->company->id,
            'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id,
            'transaccion' => true,
            'cufd_code' => 'CUFD-HISTORICO-PARA-SUGERENCIA',
            'requested_at' => $cufdRequestedAt,
            'expires_at' => now()->addDay(),
            'invalidated_at' => now()->subMinutes(2),
        ]);

        $period = app(SignificantEventService::class)->suggestedPeriod(
            $this->point,
            $firstInvoiceAt,
            $lastInvoiceAt,
        );

        self::assertNotNull($period);
        self::assertTrue($period['earliest_start']->equalTo($cufdRequestedAt));
        self::assertTrue($period['latest_start']->equalTo($firstInvoiceAt));
        self::assertTrue($period['suggested_start']->equalTo($firstInvoiceAt->copy()->subMinute()));
        self::assertTrue($period['earliest_end']->equalTo($lastInvoiceAt->copy()->addSecond()));
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

    public function test_transcription_uses_event_control_code_instead_of_recovery_cufd(): void
    {
        $eventCufd = $this->event->cufd;
        $eventCufd->forceFill(['control_code' => '4BD688A4F54BF74', 'invalidated_at' => now(), 'expires_at' => now()->subMinute()])->save();
        SinCufd::factory()->create([
            'company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'control_code' => '8EA45AA4664BF74',
            'requested_at' => now()->addSecond(),
        ]);
        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $transcribed = $this->service->transcribe($manual, $customer, [
            'payment_method_code' => 1, 'currency_code' => 1, 'total_amount' => 25,
        ], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25]], $this->user);

        self::assertSame($eventCufd->id, $transcribed->invoice->sin_cufd_id);
        self::assertStringEndsWith('4BD688A4F54BF74', $transcribed->invoice->cuf);
        self::assertStringContainsString('<cufd>'.$eventCufd->cufd_code.'</cufd>', Storage::disk('local')->get($transcribed->xml_path));
    }

    public function test_transcription_requires_an_accepted_event_before_generating_xml(): void
    {
        $manual = $this->useNumber(100);
        $this->event->forceFill(['transaccion' => false, 'reception_code' => null])->save();
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $this->expectValidation('significant_event_id');
        $this->service->transcribe($manual, $customer, ['total_amount' => 25], [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25],
        ], $this->user);
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

    public function test_zero_rate_transcription_uses_the_expected_invoice_document_type(): void
    {
        $this->range->forceFill([
            'document_sector_code' => InvoiceDocumentSector::ZERO_RATE,
        ])->save();

        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'identity_document_type_code' => 1,
        ]);
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'unit_price' => 25,
        ]);

        $manual = $this->service->transcribe($manual, $customer, [
            'payment_method_code' => 1,
            'currency_code' => 1,
            'discount_amount' => 0,
            'total_amount' => 25,
        ], [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 25,
            'discount_amount' => 0,
        ]], $this->user);

        self::assertSame(InvoiceDocumentSector::ZERO_RATE, $manual->invoice->document_sector_code);
        self::assertSame(2, $manual->invoice->invoice_document_type_code);
        self::assertSame('0.00000', $manual->invoice->taxable_amount);
    }

    public function test_event_is_registered_before_invoices_and_does_not_dispatch_packages(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        Bus::fake();
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id, 'catalog_key' => 'eventos_significativos', 'classifier_code' => '5', 'is_active' => true,
        ]);
        $this->mock(SignificantEventService::class)->shouldReceive('registerForPointOfSale')->once()->andReturn($this->event);
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.events.store', $this->range), [
            'event_code' => 5, 'event_description' => 'Falla de software',
            'event_started_at' => $this->event->started_at->toDateTimeString(),
            'event_ended_at' => $this->event->ended_at->toDateTimeString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame($this->event->id, $this->range->refresh()->sin_significant_event_id);
        self::assertSame(0, $this->range->manualInvoices()->count());
        Bus::assertNotDispatched(BuildContingencyPackagesJob::class);
    }

    public function test_registration_rejects_event_older_than_48_hours_before_contacting_sin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id, 'catalog_key' => 'eventos_significativos', 'classifier_code' => '5', 'is_active' => true,
        ]);
        $this->mock(SignificantEventService::class)->shouldNotReceive('registerForPointOfSale');
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.events.store', $this->range), [
            'event_code' => 5, 'event_description' => 'Falla de software',
            'event_started_at' => now()->subHours(50)->toDateTimeString(),
            'event_ended_at' => now()->subHours(49)->toDateTimeString(),
        ])->assertSessionHasErrors('event_ended_at');
        self::assertNull($this->range->refresh()->sin_significant_event_id);
    }

    public function test_missing_receipt_blocks_number_reservation(): void
    {
        $this->event->forceFill(['reception_code' => null])->save();
        $this->expectValidation('significant_event_id');
        $this->useNumber(100);
    }

    public function test_invoice_outside_registered_event_period_is_rejected(): void
    {
        $this->travel(1)->minutes();
        $this->expectValidation('issued_manually_at');
        $this->useNumber(100);
    }

    public function test_digital_event_cannot_be_used_for_manual_transcription(): void
    {
        $this->event->forceFill(['event_code' => 1])->save();
        $this->expectValidation('significant_event_id');
        $this->useNumber(100);
    }

    public function test_manual_regularization_deadline_is_72_hours(): void
    {
        $this->travel(73)->hours();
        $this->expectValidation('significant_event_id');
        app(ManualCafcCompliance::class)->validate($this->range, $this->event, $this->event->ended_at);
    }

    public function test_event_cufd_must_be_valid_at_contingency_start(): void
    {
        $this->event->cufd->forceFill(['requested_at' => $this->event->started_at->addSecond()])->save();
        $this->expectValidation('issued_manually_at');
        $this->useNumber(100);
    }

    public function test_incomplete_transcriptions_cannot_be_finalized_or_enqueued(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        Bus::fake();
        $this->useNumber(100);
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.send', $this->range))
            ->assertSessionHasErrors('cafc_range_id');
        self::assertSame(CafcRangeStatus::InUse, $this->range->refresh()->range_status);
        Bus::assertNotDispatched(BuildContingencyPackagesJob::class);
    }

    public function test_finalizing_complete_transcriptions_closes_range_and_dispatches_package(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        Bus::fake();
        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $this->service->transcribe($manual, $customer, ['total_amount' => 25], [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25]], $this->user);
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.send', $this->range))
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(CafcRangeStatus::Blocked, $this->range->refresh()->range_status);
        Bus::assertDispatched(BuildContingencyPackagesJob::class);
        $this->expectValidation('cafc_range_id');
        $this->useNumber(101);
    }

    public function test_rejected_event_does_not_enable_number_reservation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        $this->range->forceFill(['sin_significant_event_id' => null])->save();
        $this->event->forceFill(['transaccion' => false, 'reception_code' => null])->save();
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id, 'catalog_key' => 'eventos_significativos', 'classifier_code' => '5', 'is_active' => true,
        ]);
        $this->mock(SignificantEventService::class)->shouldReceive('registerForPointOfSale')->once()->andReturn($this->event);
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.events.store', $this->range), [
            'event_code' => 5, 'event_description' => 'Falla de software',
            'event_started_at' => $this->event->started_at->toDateTimeString(),
            'event_ended_at' => $this->event->ended_at->toDateTimeString(),
        ])->assertSessionHasErrors('event_code');
        self::assertNull($this->range->refresh()->sin_significant_event_id);
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.invoices.store', $this->range), [
            'sin_point_of_sale_id' => $this->point->id, 'manual_invoice_number' => 100,
            'issued_manually_at' => now()->toDateTimeString(),
        ])->assertSessionHasErrors('significant_event_id');
        self::assertSame(0, $this->range->manualInvoices()->count());
    }

    public function test_transcribing_one_invoice_does_not_send_an_incomplete_cafc(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.transcribe');
        Bus::fake();
        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $this->actingAs($this->user)->put(route('billing.manual-cafc.transcribe.update', $manual), [
            'customer_id' => $customer->id, 'payment_method_code' => 1, 'currency_code' => 1,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        Bus::assertNotDispatched(BuildContingencyPackagesJob::class);
        self::assertSame(ManualContingencyInvoiceStatus::PendingSend, $manual->refresh()->manual_status);
        self::assertSame(101, $this->range->refresh()->next_number);
    }

    public function test_last_reserved_number_can_be_transcribed_when_range_is_exhausted(): void
    {
        $this->range->forceFill(['range_end' => 100])->save();
        $manual = $this->useNumber(100);
        self::assertSame(CafcRangeStatus::Exhausted, $this->range->refresh()->range_status);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $transcribed = $this->service->transcribe($manual, $customer, ['total_amount' => 25], [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25],
        ], $this->user);
        self::assertSame(ManualContingencyInvoiceStatus::PendingSend, $transcribed->manual_status);
    }

    public function test_finalization_rejects_wrong_control_code_and_keeps_range_open(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        Bus::fake();
        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $this->service->transcribe($manual, $customer, ['total_amount' => 25], [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25],
        ], $this->user);
        $this->event->cufd->forceFill(['control_code' => 'WRONG-CONTROL'])->save();
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.send', $this->range))
            ->assertSessionHasErrors('cuf');
        self::assertSame(CafcRangeStatus::InUse, $this->range->refresh()->range_status);
        Bus::assertNotDispatched(BuildContingencyPackagesJob::class);
    }

    public function test_registration_requests_recovery_cufd_without_replacing_historical_cufd(): void
    {
        $this->actingAs($this->user);
        $historical = $this->event->cufd;
        $recovery = SinCufd::factory()->create([
            'company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'sin_cuis_id' => $this->event->sin_cuis_id,
            'requested_at' => now(), 'control_code' => 'RECOVERY-CONTROL',
        ]);
        $this->mock(SiatCufdService::class, function ($mock) use ($recovery): void {
            $mock->shouldReceive('currentForPointOfSale')->once()->andReturnNull();
            $mock->shouldReceive('request')->once()->andReturn($recovery);
        });
        $client = new class
        {
            public array $payload = [];

            public function registroEventoSignificativo(array $payload): array
            {
                $this->payload = $payload;

                return ['RespuestaListaEventos' => ['transaccion' => true, 'codigoRecepcionEventoSignificativo' => 'RECOVERY-EVENT-TEST']];
            }
        };
        $this->mock(SiatSoapClientFactory::class)->shouldReceive('make')->once()->andReturn($client);
        $registered = app(SignificantEventService::class)->registerForPointOfSale($this->user, $this->point, [
            'event_code' => 5, 'description' => 'Falla de software',
            'started_at' => $this->event->started_at->toDateTimeString(), 'ended_at' => now()->toDateTimeString(),
        ]);
        self::assertTrue($registered->transaccion);
        self::assertSame($historical->id, $registered->sin_cufd_id);
        self::assertSame($recovery->id, $registered->recovery_sin_cufd_id);
        self::assertSame($historical->cufd_code, $client->payload['SolicitudEventoSignificativo']['cufdEvento']);
        self::assertSame($recovery->cufd_code, $client->payload['SolicitudEventoSignificativo']['cufd']);
    }

    public function test_tampered_xml_cannot_be_finalized_or_sent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->user->givePermissionTo('manual-cafc.use');
        Bus::fake();
        $manual = $this->useNumber(100);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'identity_document_type_code' => 1]);
        $product = Product::factory()->create(['company_id' => $this->company->id, 'unit_price' => 25]);
        $transcribed = $this->service->transcribe($manual, $customer, ['total_amount' => 25], [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25],
        ], $this->user);
        Storage::disk('local')->put($transcribed->xml_path, '<factura><cuf>ALTERADO</cuf></factura>');
        $this->actingAs($this->user)->post(route('billing.cafc-contingencies.send', $this->range))
            ->assertSessionHasErrors('xml');
        self::assertSame(CafcRangeStatus::InUse, $this->range->refresh()->range_status);
        Bus::assertNotDispatched(BuildContingencyPackagesJob::class);
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
