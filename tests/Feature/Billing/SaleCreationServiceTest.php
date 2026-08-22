<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinCatalogItem;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\SaleCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SaleCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_uses_server_time_and_snapshots_products(): void
    {
        [$user, $customer, $point, $product] = $this->context();
        $before = now()->subSecond();

        $sale = app(SaleCreationService::class)->create($user, $this->data($customer->id, $point->id, $product->id, [
            'issued_at' => '2020-01-01 00:00:00',
        ]));

        self::assertTrue($sale->issued_at->greaterThanOrEqualTo($before));
        self::assertSame($product->internal_code, $sale->items->first()->internal_code);
        self::assertSame($product->siat_product_code, $sale->items->first()->siat_product_code);
        self::assertSame('Producto congelado - Lote A', $sale->items->first()->description);
    }

    public function test_same_issuance_key_returns_one_sale_and_one_set_of_items(): void
    {
        [$user, $customer, $point, $product] = $this->context();
        $key = (string) Str::uuid();
        $data = $this->data($customer->id, $point->id, $product->id, ['issuance_key' => $key]);
        $service = app(SaleCreationService::class);

        $first = $service->create($user, $data);
        $second = $service->create($user, $data);

        self::assertSame($first->id, $second->id);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
    }

    public function test_sale_preserves_item_quantity_and_calculates_the_same_total(): void
    {
        [$user, $customer, $point, $product] = $this->context();
        $data = $this->data($customer->id, $point->id, $product->id);
        $data['items'][0]['quantity'] = 2;

        $sale = app(SaleCreationService::class)->create($user, $data);

        self::assertSame('2.00000', $sale->items->first()->quantity);
        self::assertSame('200.00000', $sale->items->first()->subtotal_amount);
        self::assertSame('200.00000', $sale->total_amount);
    }

    public function test_card_number_is_stored_only_in_the_sin_masked_format(): void
    {
        [$user, $customer, $point, $product] = $this->context();
        $sale = app(SaleCreationService::class)->create($user, $this->data($customer->id, $point->id, $product->id, [
            'payment_method_code' => 2,
            'card_number' => '4797123412347896',
        ]));

        self::assertSame('4797000000007896', $sale->masked_card_number);
        self::assertStringNotContainsString('12341234', (string) $sale->getRawOriginal('masked_card_number'));
    }

    public function test_zero_total_sale_is_rejected_without_gift_card(): void
    {
        [$user, $customer, $point, $product] = $this->context();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('monto total de la factura debe ser mayor a cero');

        app(SaleCreationService::class)->create($user, $this->data($customer->id, $point->id, $product->id, [
            'items' => [[
                'product_id' => $product->id,
                'description' => 'Producto sin importe',
                'quantity' => 1,
                'unit_price' => 0,
                'discount' => 0,
            ]],
        ]));
    }

    public function test_gift_card_payment_can_cover_the_full_positive_sale(): void
    {
        [$user, $customer, $point, $product] = $this->context();
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_metodo_pago',
            'classifier_code' => '27',
            'description' => 'GIFT CARD',
            'is_active' => true,
        ]);

        $sale = app(SaleCreationService::class)->create($user, $this->data($customer->id, $point->id, $product->id, [
            'payment_method_code' => 27,
            'gift_card_amount' => 100,
        ]));

        self::assertSame('100.00000', $sale->total_amount);
        self::assertSame('100.00000', $sale->gift_card_amount);
        self::assertSame('0.00000', $sale->total_amount_subject_to_vat);
    }

    public function test_gift_card_amount_is_rejected_with_another_payment_method(): void
    {
        [$user, $customer, $point, $product] = $this->context();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('método de pago Gift Card');

        app(SaleCreationService::class)->create($user, $this->data($customer->id, $point->id, $product->id, [
            'payment_method_code' => 1,
            'gift_card_amount' => 100,
        ]));
    }

    public function test_zero_rate_sale_reuses_sale_flow_and_sets_taxable_amount_to_zero(): void
    {
        [$user, $customer, $point, $product] = $this->context();
        $activityCode = '4761100';
        $product->update(['economic_activity_code' => $activityCode]);
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades_documento_sector',
            'item_key' => 'codigoActividad:'.$activityCode.'|codigoDocumentoSector:8',
            'classifier_code' => $activityCode,
            'description' => null,
            'raw_data' => ['codigoActividad' => $activityCode, 'codigoDocumentoSector' => 8, 'tipoDocumentoSector' => 'FTC'],
            'is_active' => true,
        ]);

        $sale = app(SaleCreationService::class)->create($user, $this->data($customer->id, $point->id, $product->id, [
            'document_sector_code' => InvoiceDocumentSector::ZERO_RATE,
            'economic_activity_code' => $activityCode,
        ]));

        self::assertSame(InvoiceDocumentSector::ZERO_RATE, $sale->document_sector_code);
        self::assertSame('100.00000', $sale->total_amount);
        self::assertSame('0.00000', $sale->total_amount_subject_to_vat);
    }

    /** @return array{User, Customer, SinPointOfSale, Product} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $point = SinPointOfSale::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'description' => 'Producto congelado',
        ]);

        return [$user, $customer, $point, $product];
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function data(int $customerId, int $pointId, int $productId, array $extra = []): array
    {
        return [
            'sin_point_of_sale_id' => $pointId,
            'customer_id' => $customerId,
            'economic_activity_code' => 620100,
            'payment_method_code' => 1,
            'currency_code' => 1,
            'total_discount' => 0,
            'items' => [[
                'product_id' => $productId,
                'description' => 'Producto congelado',
                'additional_description' => 'Lote A',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
            ]],
            ...$extra,
        ];
    }
}
