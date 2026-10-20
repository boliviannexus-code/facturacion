<?php

namespace Tests\Feature\Users;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinApiToken;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Billing\SaleCreationService;
use App\Support\CompanyContext;
use App\Support\ReservedSuperAdmin;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create(['email' => ReservedSuperAdmin::EMAIL, 'company_id' => Company::factory()->create()->id]);
        $owner->assignRole('super_admin');

        return $owner;
    }

    public function test_switching_scopes_credentials_invoices_and_writes_without_changing_registered_company(): void
    {
        $owner = $this->owner();
        $original = $owner->company_id;
        $other = Company::factory()->create();
        $firstInvoice = SinInvoiceIssue::factory()->create(['company_id' => $original]);
        $secondInvoice = SinInvoiceIssue::factory()->create(['company_id' => $other->id]);
        $otherToken = SinApiToken::withoutGlobalScope('company')->where('company_id', $other->id)->firstOrFail();
        $this->actingAs($owner)->getJson(route('datatables.invoices'))->assertOk()->assertJsonPath('recordsTotal', 1);
        $this->post(route('active-company.update'), ['company_id' => $other->id])
            ->assertRedirect(route('dashboard'))->assertSessionHas('active_company.company_id', $other->id);
        $this->get(route('sin-api-token.index'))->assertOk()
            ->assertViewHas('apiToken', fn ($token) => $token->is($otherToken));
        $response = $this->getJson(route('datatables.invoices', ['draw' => 1, 'start' => 0, 'length' => 10]));
        $response->assertOk()->assertJsonPath('recordsTotal', 1);
        self::assertSame([$secondInvoice->id], array_column($response->json('data'), 'id'));
        $this->get(route('billing.invoices.print', $firstInvoice))->assertNotFound();
        $this->post(route('cash-registers.store'), ['opening_amount' => 10])->assertRedirect();
        $this->assertDatabaseHas('cash_registers', ['company_id' => $other->id, 'user_id' => $owner->id]);
        self::assertSame($original, $owner->fresh()->company_id);
        $this->post(route('active-company.update'), ['company_id' => $original])->assertRedirect();
        $this->getJson(route('datatables.invoices'))->assertOk()->assertJsonPath('recordsTotal', 1);
        $this->get(route('companies.index'))->assertOk()->assertSee($other->name);
    }

    public function test_sales_and_background_work_keep_company_and_original_author(): void
    {
        $owner = $this->owner();
        $registered = $owner->company_id;
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $point = SinPointOfSale::factory()->create(['company_id' => $company->id]);
        $data = ['sin_point_of_sale_id' => $point->id, 'customer_id' => $customer->id,
            'economic_activity_code' => $product->economic_activity_code, 'payment_method_code' => 1,
            'currency_code' => 1, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]];
        $this->actingAs($owner)->post(route('active-company.update'), ['company_id' => $company->id])->assertRedirect();
        $sale = app(SaleCreationService::class)->create($owner, $data);
        self::assertSame($company->id, $sale->company_id);
        self::assertSame($owner->id, $sale->user_id);
        $this->app['auth']->forgetGuards();
        request()->session()->forget(CompanyContext::SESSION_KEY);
        $queuedSale = CompanyContext::runInCompany($company->id,
            fn () => app(SaleCreationService::class)->create($owner, $data));
        self::assertSame($company->id, $queuedSale->company_id);
        self::assertSame($registered, $owner->fresh()->company_id);
        self::assertSame($registered, CompanyContext::operatingCompanyId($owner));
    }

    public function test_database_grants_cross_company_access_only_to_reserved_super_admin(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create();
        $this->assertDatabaseHas('company_user_access', ['company_id' => $company->id, 'user_id' => $owner->id]);
        $ordinary = User::factory()->create(['company_id' => $owner->company_id]);
        $this->expectException(QueryException::class);
        DB::table('company_user_access')->insert(['company_id' => $company->id, 'user_id' => $ordinary->id]);
    }

    public function test_only_reserved_super_administrator_can_use_or_see_company_selector(): void
    {
        $owner = $this->owner();
        $other = Company::factory()->create();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('id="active-company"', false);
        $admin = User::factory()->create(['company_id' => $owner->company_id]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->withSession(['active_company' => ['user_id' => $admin->id, 'company_id' => $other->id]])
            ->post(route('active-company.update'), ['company_id' => $other->id])->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('id="active-company"', false);
        self::assertSame($admin->company_id, CompanyContext::operatingCompanyId($admin));
    }

    public function test_inactive_missing_and_later_disabled_company_cannot_be_used(): void
    {
        $owner = $this->owner();
        $inactive = Company::factory()->create(['is_active' => false]);
        $this->actingAs($owner);
        foreach ([$inactive->id, 99999999] as $id) {
            $this->postJson(route('active-company.update'), ['company_id' => $id])->assertUnprocessable();
        }
        $other = Company::factory()->create();
        $this->post(route('active-company.update'), ['company_id' => $other->id])->assertRedirect();
        $other->update(['is_active' => false]);
        $this->get(route('sin-api-token.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertSee('Selecciona una empresa');
    }

    public function test_alerts_and_mark_all_are_limited_to_selected_company(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create();
        $homeAlert = $owner->notifications()->create(['id' => (string) Str::uuid(),
            'type' => 'test', 'data' => ['company_id' => $owner->company_id, 'title' => 'Solo empresa original']]);
        $selectedAlert = $owner->notifications()->create(['id' => (string) Str::uuid(),
            'type' => 'test', 'data' => ['company_id' => $company->id, 'title' => 'Solo empresa seleccionada']]);
        $this->actingAs($owner)->post(route('active-company.update'), ['company_id' => $company->id])->assertRedirect();
        $this->get(route('dashboard'))->assertOk()->assertSee('Solo empresa seleccionada')->assertDontSee('Solo empresa original');
        $this->post(route('notifications.read-all'))->assertRedirect();
        self::assertNull($homeAlert->fresh()->read_at);
        self::assertNotNull($selectedAlert->fresh()->read_at);
        $this->post(route('notifications.read', $homeAlert->id))->assertNotFound();
    }

    public function test_background_company_context_is_explicit_and_restored_after_failure(): void
    {
        $owner = $this->owner();
        $other = Company::factory()->create();
        try {
            CompanyContext::runInCompany($other->id, function () use ($owner, $other): void {
                self::assertSame($other->id, CompanyContext::operatingCompanyId($owner));
                throw new \RuntimeException('test');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('test', $e->getMessage());
        }
        self::assertSame($owner->company_id, CompanyContext::operatingCompanyId($owner));
    }
}
