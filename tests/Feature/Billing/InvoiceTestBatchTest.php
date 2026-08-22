<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Enums\InvoiceTestMode;
use App\Enums\SiatEnvironment;
use App\Jobs\CancelInvoiceTestItemJob;
use App\Jobs\IssueInvoiceTestItemJob;
use App\Jobs\ProcessOfflineContingencyTestItemJob;
use App\Jobs\ReverseInvoiceTestItemJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceTestBatch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCatalogItem;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceTestBatchService;
use App\Services\Billing\SaleCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class InvoiceTestBatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private SinPointOfSale $point;

    private SinBranch $branch;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->branch = SinBranch::factory()->create(['company_id' => $this->company->id]);
        $this->point = SinPointOfSale::factory()->create([
            'company_id' => $this->company->id,
            'sin_branch_id' => $this->branch->id,
        ]);
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'economic_activity_code' => 620100,
            'unit_price' => 10,
        ]);
        SinAuthorization::factory()->create([
            'company_id' => $this->company->id,
            'environment_code' => SiatEnvironment::TestingAndPilot,
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'motivos_anulacion',
            'classifier_code' => '1',
            'is_active' => true,
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'eventos_significativos',
            'classifier_code' => '2',
            'description' => 'Inaccesibilidad al servicio web del SIN',
            'is_active' => true,
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'actividades',
            'classifier_code' => '620100',
            'is_active' => true,
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'tipos_metodo_pago',
            'classifier_code' => '1',
            'is_active' => true,
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'tipos_moneda',
            'classifier_code' => '1',
            'is_active' => true,
        ]);
        Permission::findOrCreate('invoice-tests.run');
        $this->user->givePermissionTo('invoice-tests.run');
    }

    public function test_pilot_user_can_create_a_chain_of_twenty_five_sequential_invoices(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->get(route('billing.invoice-tests.index'))
            ->assertOk()
            ->assertSee('Cola secuencial de emisión')
            ->assertSee('Tasa Cero')
            ->assertSee('Sector 8')
            ->assertSee('25');

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), $this->payload(25))
            ->assertRedirect(route('billing.invoice-tests.index', ['batch' => 1]));

        $batch = InvoiceTestBatch::query()->with('items')->firstOrFail();
        self::assertSame(25, $batch->requested_count);
        self::assertCount(25, $batch->items);
        self::assertSame(range(1, 25), $batch->items->pluck('position')->all());
        self::assertCount(25, $batch->items->pluck('issuance_key')->unique());
        Bus::assertChained(array_fill(0, 25, IssueInvoiceTestItemJob::class));
    }

    public function test_pilot_batch_can_use_zero_rate_document_sector(): void
    {
        Bus::fake();
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'actividades_documento_sector',
            'classifier_code' => '620100-8',
            'raw_data' => ['codigoActividad' => '620100', 'codigoDocumentoSector' => 8],
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [
                ...$this->payload(1),
                'document_sector_code' => InvoiceDocumentSector::ZERO_RATE,
            ])
            ->assertRedirectContains(route('billing.invoice-tests.index'));

        self::assertSame(InvoiceDocumentSector::ZERO_RATE, InvoiceTestBatch::query()->firstOrFail()->document_sector_code);
        Bus::assertChained([IssueInvoiceTestItemJob::class]);
    }

    public function test_zero_rate_batch_rejects_activity_not_enabled_for_sector_eight(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [
                ...$this->payload(1),
                'document_sector_code' => InvoiceDocumentSector::ZERO_RATE,
            ])
            ->assertSessionHasErrors('economic_activity_code');

        self::assertSame(0, InvoiceTestBatch::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_online_batch_can_exceed_twenty_five_invoices(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), $this->payload(26))
            ->assertRedirectContains(route('billing.invoice-tests.index'));

        $batch = InvoiceTestBatch::query()->with('items')->firstOrFail();
        self::assertSame(26, $batch->requested_count);
        self::assertCount(26, $batch->items);
        Bus::assertChained(array_fill(0, 26, IssueInvoiceTestItemJob::class));
    }

    public function test_online_batch_cannot_exceed_five_hundred_invoices(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->from(route('billing.invoice-tests.index'))
            ->post(route('billing.invoice-tests.store'), $this->payload(501))
            ->assertRedirect(route('billing.invoice-tests.index'))
            ->assertSessionHasErrors('invoice_count');

        self::assertSame(0, InvoiceTestBatch::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_offline_contingency_batch_uses_requested_cycle_count_and_dispatches_only_the_first(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [
                ...$this->payload(3),
                'test_mode' => InvoiceTestMode::OfflineContingency->value,
                'event_code' => 2,
                'event_description' => 'Prueba consecutiva de contingencia.',
            ])->assertRedirectContains(route('billing.invoice-tests.index'));

        $batch = InvoiceTestBatch::query()->with('items')->firstOrFail();
        self::assertSame(InvoiceTestMode::OfflineContingency, $batch->test_mode);
        self::assertSame(3, $batch->requested_count);
        self::assertSame('Inaccesibilidad al servicio web del SIN', $batch->event_description);
        self::assertCount(3, $batch->items);
        Bus::assertDispatchedTimes(ProcessOfflineContingencyTestItemJob::class, 1);
        Bus::assertDispatched(
            ProcessOfflineContingencyTestItemJob::class,
            fn (ProcessOfflineContingencyTestItemJob $job): bool => $job->itemId === $batch->items->first()->id
                && $job->tries === 5,
        );
    }

    public function test_offline_contingency_rejects_more_than_ten_cycles(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [
                ...$this->payload(11),
                'test_mode' => InvoiceTestMode::OfflineContingency->value,
                'event_code' => 2,
                'event_description' => 'Prueba consecutiva de contingencia.',
            ])->assertSessionHasErrors('invoice_count');

        self::assertSame(0, InvoiceTestBatch::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_offline_contingency_allows_five_hundred_invoices_in_ten_cycles(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [
                ...$this->payload(10),
                'test_mode' => InvoiceTestMode::OfflineContingency->value,
                'invoices_per_cycle' => 500,
                'event_code' => 2,
                'event_description' => 'Prueba de paquete máximo.',
            ])->assertRedirectContains(route('billing.invoice-tests.index'));

        $batch = InvoiceTestBatch::query()->firstOrFail();
        self::assertSame(10, $batch->requested_count);
        self::assertSame(500, $batch->invoices_per_cycle);
        self::assertCount(10, $batch->items);
        Bus::assertDispatchedTimes(ProcessOfflineContingencyTestItemJob::class, 1);
    }

    public function test_offline_contingency_rejects_more_than_five_hundred_invoices_per_cycle(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [
                ...$this->payload(4),
                'test_mode' => InvoiceTestMode::OfflineContingency->value,
                'invoices_per_cycle' => 501,
                'event_code' => 2,
                'event_description' => 'Combinación inválida.',
            ])->assertSessionHasErrors('invoices_per_cycle');

        self::assertSame(0, InvoiceTestBatch::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_point_of_sale_must_belong_to_selected_branch(): void
    {
        Bus::fake();
        $otherBranch = SinBranch::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [...$this->payload(1), 'sin_branch_id' => $otherBranch->id])
            ->assertSessionHasErrors('sin_point_of_sale_id');

        self::assertSame(0, InvoiceTestBatch::query()->count());
    }

    public function test_product_must_belong_to_selected_activity(): void
    {
        Bus::fake();
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'actividades',
            'classifier_code' => '471100',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.store'), [...$this->payload(1), 'economic_activity_code' => 471100])
            ->assertSessionHasErrors('product_id');

        self::assertSame(0, InvoiceTestBatch::query()->count());
    }

    public function test_batch_is_blocked_outside_the_siat_pilot_environment(): void
    {
        Bus::fake();
        SinAuthorization::query()->update(['environment_code' => SiatEnvironment::Production]);

        $this->actingAs($this->user)
            ->from(route('billing.invoice-tests.index'))
            ->post(route('billing.invoice-tests.store'), $this->payload(5))
            ->assertRedirect(route('billing.invoice-tests.index'))
            ->assertSessionHasErrors('environment');

        self::assertSame(0, InvoiceTestBatch::query()->count());
    }

    public function test_user_without_permission_cannot_open_or_start_tests(): void
    {
        $other = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($other)->get(route('billing.invoice-tests.index'))->assertForbidden();
        $this->actingAs($other)->post(route('billing.invoice-tests.store'), $this->payload(5))->assertForbidden();
    }

    public function test_completed_position_is_idempotent_when_the_job_is_delivered_again(): void
    {
        $batch = app(InvoiceTestBatchService::class)->create($this->user, $this->payload(1));
        $item = $batch->items->firstOrFail();
        $item->update(['item_status' => InvoiceTestItemStatus::Succeeded, 'finished_at' => now()]);

        (new IssueInvoiceTestItemJob($this->company->id, $batch->id, $item->id))->handle(
            app(InvoiceTestBatchService::class),
            app(SaleCreationService::class),
            app(InvoiceIssuanceService::class),
        );

        self::assertSame(0, Sale::query()->count());
        self::assertSame(InvoiceTestItemStatus::Succeeded, $item->refresh()->item_status);
    }

    public function test_validated_invoices_can_be_queued_for_sequential_cancellation(): void
    {
        Bus::fake();
        $batch = app(InvoiceTestBatchService::class)->create($this->user, $this->payload(2));

        foreach ($batch->items as $position => $item) {
            $invoice = SinInvoiceIssue::factory()->create([
                'company_id' => $this->company->id,
                'user_id' => $this->user->id,
                'customer_id' => $this->customer->id,
                'sin_branch_id' => $this->branch->id,
                'sin_point_of_sale_id' => $this->point->id,
                'fiscal_status' => InvoiceFiscalStatus::Validated,
                'invoice_number' => $position + 1,
                'status_code' => 908,
                'transaccion' => true,
            ]);
            $item->update(['item_status' => InvoiceTestItemStatus::Succeeded, 'sin_invoice_issue_id' => $invoice->id]);
        }

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.cancel', $batch), ['reason_code' => 1])
            ->assertRedirect(route('billing.invoice-tests.index', ['batch' => $batch->id]));

        $batch->refresh();
        self::assertSame(2, $batch->cancellation_requested_count);
        self::assertSame(1, $batch->cancellation_reason_code);
        self::assertTrue($batch->items()->get()->every(fn ($item) => $item->cancellation_status === InvoiceTestItemStatus::Pending));
        Bus::assertChained([CancelInvoiceTestItemJob::class, CancelInvoiceTestItemJob::class]);
    }

    public function test_cancelled_invoices_can_be_queued_for_sequential_reversal(): void
    {
        Bus::fake();
        $this->customer->update(['email' => 'comprador@example.test']);
        $batch = app(InvoiceTestBatchService::class)->create($this->user, $this->payload(2));

        foreach ($batch->items as $position => $item) {
            $invoice = SinInvoiceIssue::factory()->create([
                'company_id' => $this->company->id,
                'user_id' => $this->user->id,
                'customer_id' => $this->customer->id,
                'sin_branch_id' => $this->branch->id,
                'sin_point_of_sale_id' => $this->point->id,
                'fiscal_status' => InvoiceFiscalStatus::CancelledInSiat,
                'invoice_number' => $position + 1,
                'cancellation_status_code' => 905,
                'cancelled_at' => now(),
                'issued_at' => now(),
            ]);
            $item->update([
                'item_status' => InvoiceTestItemStatus::Succeeded,
                'cancellation_status' => InvoiceTestItemStatus::Succeeded,
                'sin_invoice_issue_id' => $invoice->id,
            ]);
        }

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.reverse', $batch))
            ->assertRedirect(route('billing.invoice-tests.index', ['batch' => $batch->id]));

        $batch->refresh();
        self::assertSame(2, $batch->reversal_requested_count);
        self::assertTrue($batch->items()->get()->every(
            fn ($item) => $item->reversal_status === InvoiceTestItemStatus::Pending,
        ));
        Bus::assertChained([ReverseInvoiceTestItemJob::class, ReverseInvoiceTestItemJob::class]);
    }

    public function test_batch_without_cancelled_invoices_cannot_start_reversal(): void
    {
        Bus::fake();
        $batch = app(InvoiceTestBatchService::class)->create($this->user, $this->payload(1));

        $this->actingAs($this->user)
            ->post(route('billing.invoice-tests.reverse', $batch))
            ->assertSessionHasErrors('reversal');

        self::assertNull($batch->refresh()->reversal_status);
        Bus::assertNothingDispatched();
    }

    /** @return array<string, int|float> */
    private function payload(int $count): array
    {
        return [
            'test_mode' => InvoiceTestMode::Online->value,
            'document_sector_code' => InvoiceDocumentSector::PURCHASE_SALE,
            'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id,
            'economic_activity_code' => 620100,
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'payment_method_code' => 1,
            'currency_code' => 1,
            'quantity' => 1,
            'unit_price' => 10,
            'invoice_count' => $count,
        ];
    }
}
