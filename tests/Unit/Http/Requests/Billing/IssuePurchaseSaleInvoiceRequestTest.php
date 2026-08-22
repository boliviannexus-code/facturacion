<?php

namespace Tests\Unit\Http\Requests\Billing;

use App\Http\Requests\Billing\IssuePurchaseSaleInvoiceRequest;
use Carbon\Carbon;
use Tests\TestCase;

class IssuePurchaseSaleInvoiceRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prepare_for_validation_refreshes_issued_at_to_current_server_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 15:42:11'));

        $request = IssuePurchaseSaleInvoiceRequest::create('/facturacion/emitir', 'POST', [
            'issued_at' => '2026-07-31T09:00',
            'items' => '[{"product_id":1}]',
        ]);

        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);

        $this->assertSame('2026-07-31 15:42:11', $request->input('issued_at'));
        $this->assertSame([['product_id' => 1]], $request->input('items'));
    }
}
