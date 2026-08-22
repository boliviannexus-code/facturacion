<?php

namespace Tests\Feature\Parameters;

use App\Models\Company;
use App\Models\Customer;
use App\Models\ProductCategory;
use App\Models\SinCatalogItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ParameterCatalogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parameter_menu_links_are_visible_to_company_users_with_view_permissions(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'product-categories.view',
            'customers.view',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Parametros')
            ->assertSee(route('parameters.categories.index'))
            ->assertSee(route('parameters.customers.index'))
            ->assertDontSee('Medidas');
    }

    public function test_company_user_can_create_category_only_for_their_company(): void
    {
        config(['audit.console' => true]);

        $user = $this->companyUser([
            'product-categories.view',
            'product-categories.create',
        ]);
        $foreignCompany = Company::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('parameters.categories.store'), [
                'company_id' => $foreignCompany->id,
                'name' => 'Servicios',
                'description' => 'Categoria interna',
                'is_active' => '1',
            ])
            ->assertRedirect(route('parameters.categories.index'));

        $category = ProductCategory::query()->firstOrFail();

        $this->assertSame($user->company_id, $category->company_id);
        $this->assertSame('Servicios', $category->name);

        $this->assertDatabaseHas('audits', [
            'company_id' => $user->company_id,
            'event' => 'created',
            'auditable_type' => ProductCategory::class,
            'auditable_id' => $category->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_category_names_are_unique_inside_each_company(): void
    {
        $user = $this->companyUser([
            'product-categories.view',
            'product-categories.create',
        ]);
        ProductCategory::factory()->create([
            'company_id' => $user->company_id,
            'name' => 'Alimentos',
        ]);

        $this
            ->actingAs($user)
            ->from(route('parameters.categories.create'))
            ->post(route('parameters.categories.store'), [
                'name' => 'Alimentos',
            ])
            ->assertRedirect(route('parameters.categories.create'))
            ->assertSessionHasErrors('name');

        $otherUser = $this->companyUser([
            'product-categories.view',
            'product-categories.create',
        ]);

        $this
            ->actingAs($otherUser)
            ->post(route('parameters.categories.store'), [
                'name' => 'Alimentos',
            ])
            ->assertRedirect(route('parameters.categories.index'));

        $this->assertSame(2, ProductCategory::query()->withoutGlobalScope('company')->count());
    }

    public function test_company_user_can_create_customer_with_siat_identity_fields(): void
    {
        $user = $this->companyUser([
            'customers.view',
            'customers.create',
        ]);
        $foreignCompany = Company::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('parameters.customers.store'), [
                'company_id' => $foreignCompany->id,
                'identity_document_type_code' => 1,
                'document_number' => '1234567',
                'document_complement' => '',
                'name' => 'Cliente de prueba',
                'email' => 'cliente@example.com',
                'is_active' => '1',
            ])
            ->assertRedirect(route('parameters.customers.index'));

        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id,
            'identity_document_type_code' => 1,
            'document_number' => '1234567',
            'document_complement' => null,
            'customer_code' => 'CLI-1234567',
            'name' => 'Cliente de prueba',
        ]);
    }

    public function test_customer_form_uses_active_siat_identity_document_type_catalog(): void
    {
        $user = $this->companyUser([
            'customers.create',
        ]);
        $otherCompany = Company::factory()->create();

        $this->seedSiatIdentityDocumentType($user->company_id, '2', 'NIT', isActive: false);
        $this->seedSiatIdentityDocumentType($otherCompany->id, '3', 'PASAPORTE EXTERNO');

        $this
            ->actingAs($user)
            ->get(route('parameters.customers.create'))
            ->assertOk()
            ->assertSee('data-placeholder="Buscar tipo documento"', false)
            ->assertSee('data-allow-empty-option="false"', false)
            ->assertSee('1 - CEDULA DE IDENTIDAD')
            ->assertDontSee('2 - NIT')
            ->assertDontSee('PASAPORTE EXTERNO');
    }

    public function test_customer_create_modal_and_ajax_store_returns_new_customer_payload(): void
    {
        $user = $this->companyUser([
            'customers.create',
        ]);

        $this
            ->actingAs($user)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('parameters.customers.create'))
            ->assertOk()
            ->assertSee('data-invoice-customer-create="1"', false)
            ->assertSee('1 - CEDULA DE IDENTIDAD');

        $this
            ->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 1,
                'document_number' => '1234567',
                'document_complement' => '',
                'name' => 'Cliente modal',
                'email' => 'modal@example.com',
                'is_active' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer.name', 'Cliente modal')
            ->assertJsonPath('data.customer.document_number', '1234567')
            ->assertJsonPath('data.customer.customer_code', 'CLI-1234567')
            ->assertJsonPath('data.customer.identity_document_type_code', 1);

        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id,
            'document_number' => '1234567',
            'customer_code' => 'CLI-1234567',
            'name' => 'Cliente modal',
        ]);
    }

    public function test_customer_rejects_inactive_siat_identity_document_type(): void
    {
        $user = $this->companyUser([
            'customers.create',
        ]);

        SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->where('catalog_key', 'tipos_documento_identidad')
            ->where('classifier_code', '1')
            ->update(['is_active' => false]);

        $this
            ->actingAs($user)
            ->from(route('parameters.customers.create'))
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 1,
                'document_number' => '1234567',
                'name' => 'Cliente inactivo',
            ])
            ->assertRedirect(route('parameters.customers.create'))
            ->assertSessionHasErrors('identity_document_type_code');
    }

    public function test_customer_validates_identity_card_and_nit_digit_lengths(): void
    {
        $user = $this->companyUser([
            'customers.create',
        ]);
        $this->seedSiatIdentityDocumentType($user->company_id, '5', 'NIT');

        $this
            ->actingAs($user)
            ->from(route('parameters.customers.create'))
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 1,
                'document_number' => '1234',
                'name' => 'Carnet muy corto',
            ])
            ->assertRedirect(route('parameters.customers.create'))
            ->assertSessionHasErrors('document_number');

        $this
            ->actingAs($user)
            ->from(route('parameters.customers.create'))
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 1,
                'document_number' => '12345A',
                'name' => 'Carnet con letras',
            ])
            ->assertRedirect(route('parameters.customers.create'))
            ->assertSessionHasErrors('document_number');

        $this
            ->actingAs($user)
            ->from(route('parameters.customers.create'))
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 5,
                'document_number' => '123456',
                'name' => 'NIT muy corto',
            ])
            ->assertRedirect(route('parameters.customers.create'))
            ->assertSessionHasErrors('document_number');

        $this
            ->actingAs($user)
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 5,
                'document_number' => '1234567890',
                'name' => 'NIT valido',
            ])
            ->assertRedirect(route('parameters.customers.index'));

        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id,
            'identity_document_type_code' => 5,
            'document_number' => '1234567890',
        ]);
    }

    public function test_customer_document_number_for_other_identity_types_keeps_generic_validation(): void
    {
        $user = $this->companyUser([
            'customers.create',
        ]);
        $this->seedSiatIdentityDocumentType($user->company_id, '3', 'PASAPORTE');

        $this
            ->actingAs($user)
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 3,
                'document_number' => 'AB-123',
                'name' => 'Pasaporte alfanumerico',
            ])
            ->assertRedirect(route('parameters.customers.index'));

        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id,
            'identity_document_type_code' => 3,
            'document_number' => 'AB-123',
        ]);
    }

    public function test_customer_can_keep_current_identity_document_type_after_it_becomes_inactive(): void
    {
        $user = $this->companyUser([
            'customers.edit',
        ]);
        $customer = Customer::factory()->create([
            'company_id' => $user->company_id,
            'identity_document_type_code' => 1,
            'document_number' => '7654321',
            'customer_code' => 'CLI-7654321',
        ]);

        SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->where('catalog_key', 'tipos_documento_identidad')
            ->where('classifier_code', '1')
            ->update(['is_active' => false]);

        $this
            ->actingAs($user)
            ->get(route('parameters.customers.edit', $customer))
            ->assertOk()
            ->assertSee('1 - CEDULA DE IDENTIDAD (inactivo)');

        $this
            ->actingAs($user)
            ->put(route('parameters.customers.update', $customer), [
                'identity_document_type_code' => 1,
                'document_number' => '7654321',
                'name' => 'Cliente actualizado',
                'is_active' => '1',
            ])
            ->assertRedirect(route('parameters.customers.index'));

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'identity_document_type_code' => 1,
            'name' => 'Cliente actualizado',
        ]);
    }

    public function test_customer_code_is_generated_with_friendly_unique_format(): void
    {
        $user = $this->companyUser([
            'customers.view',
            'customers.create',
        ]);

        Customer::factory()->create([
            'company_id' => $user->company_id,
            'identity_document_type_code' => 2,
            'document_number' => 'OTRO',
            'customer_code' => 'CLI-1111111',
        ]);

        $this
            ->actingAs($user)
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 1,
                'document_number' => '1111111',
                'customer_code' => 'MANUAL-IGNORADO',
                'name' => 'Cliente codigo automatico',
            ])
            ->assertRedirect(route('parameters.customers.index'));

        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id,
            'document_number' => '1111111',
            'customer_code' => 'CLI-1111111-2',
            'name' => 'Cliente codigo automatico',
        ]);
    }

    public function test_customers_cannot_duplicate_document_inside_company(): void
    {
        $user = $this->companyUser([
            'customers.view',
            'customers.create',
        ]);

        Customer::factory()->create([
            'company_id' => $user->company_id,
            'identity_document_type_code' => 1,
            'document_number' => '7654321',
            'document_complement' => null,
            'customer_code' => 'CLI-001',
        ]);

        $this
            ->actingAs($user)
            ->from(route('parameters.customers.create'))
            ->post(route('parameters.customers.store'), [
                'identity_document_type_code' => 1,
                'document_number' => '7654321',
                'name' => 'Duplicado documento',
            ])
            ->assertRedirect(route('parameters.customers.create'))
            ->assertSessionHasErrors('document_number');
    }

    public function test_parameter_lists_are_isolated_by_company(): void
    {
        $user = $this->companyUser([
            'product-categories.view',
            'customers.view',
        ]);
        $otherCompany = Company::factory()->create();

        ProductCategory::factory()->create([
            'company_id' => $user->company_id,
            'name' => 'Visible',
        ]);
        ProductCategory::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Oculta',
        ]);
        Customer::factory()->create([
            'company_id' => $user->company_id,
            'name' => 'Cliente visible',
            'customer_code' => 'VISIBLE',
        ]);
        Customer::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Cliente oculto',
            'customer_code' => 'OCULTO',
        ]);

        $this
            ->actingAs($user)
            ->get(route('parameters.categories.index'))
            ->assertOk()
            ->assertSee('Visible')
            ->assertDontSee('Oculta');

        $this
            ->actingAs($user)
            ->get(route('parameters.customers.index'))
            ->assertOk()
            ->assertSee('Cliente visible')
            ->assertDontSee('Cliente oculto');
    }

    public function test_company_user_cannot_edit_or_delete_foreign_parameters(): void
    {
        $user = $this->companyUser([
            'product-categories.edit',
            'product-categories.delete',
            'customers.edit',
            'customers.delete',
        ]);
        $otherCompany = Company::factory()->create();
        $foreignCategory = ProductCategory::factory()->create(['company_id' => $otherCompany->id]);
        $foreignCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);

        $this
            ->actingAs($user)
            ->get(route('parameters.categories.edit', $foreignCategory))
            ->assertNotFound();
        $this
            ->actingAs($user)
            ->delete(route('parameters.customers.destroy', $foreignCustomer))
            ->assertNotFound();
    }

    public function test_global_administrator_without_company_cannot_access_parameter_routes(): void
    {
        Permission::findOrCreate('customers.view');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create(['company_id' => null]);
        $user->assignRole('super_admin');
        $user->givePermissionTo('customers.view');

        $this
            ->actingAs($user)
            ->get(route('parameters.customers.index'))
            ->assertForbidden();
    }

    public function test_role_seeder_registers_parameter_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('customers.delete'));
        $this->assertTrue($viewer->hasPermissionTo('product-categories.view'));
        $this->assertFalse($viewer->hasPermissionTo('customers.create'));
        $this->assertFalse($cashier->hasPermissionTo('customers.view'));
        $this->assertDatabaseMissing('permissions', ['name' => 'measurement-units.view']);
    }

    public function test_database_indexes_and_constraints_protect_parameter_integrity(): void
    {
        $indexes = DB::table('pg_indexes')
            ->whereIn('tablename', ['product_categories', 'customers'])
            ->whereIn('indexname', [
                'product_categories_company_name_unique',
                'customers_company_customer_code_unique',
                'customers_company_document_unique',
            ])
            ->pluck('indexname');

        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'product_categories_name_not_blank_check',
                'customers_identity_document_type_code_positive_check',
                'customers_required_text_not_blank_check',
            ])
            ->pluck('conname');

        $this->assertCount(3, $indexes);
        $this->assertCount(3, $constraints);
    }

    private function companyUser(
        array $permissions,
        ?Company $company = null,
        string $name = 'Usuario parametros'
    ): User {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $company ??= Company::factory()->create();

        $user = User::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
        ]);
        $user->givePermissionTo($permissions);
        $this->seedSiatIdentityDocumentType($company->id, '1', 'CEDULA DE IDENTIDAD');

        return $user;
    }

    private function seedSiatIdentityDocumentType(
        int $companyId,
        string $code,
        string $description,
        bool $isActive = true
    ): void {
        SinCatalogItem::query()->withoutGlobalScope('company')->updateOrCreate(
            [
                'company_id' => $companyId,
                'catalog_key' => 'tipos_documento_identidad',
                'item_key' => 'codigoClasificador:'.$code,
            ],
            [
                'classifier_code' => $code,
                'description' => $description,
                'is_active' => $isActive,
                'raw_data' => [
                    'codigoClasificador' => $code,
                    'descripcion' => $description,
                ],
                'synced_at' => now(),
            ]
        );
    }
}
