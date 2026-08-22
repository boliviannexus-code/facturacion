<?php

namespace Tests\Feature\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SinCatalogItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_menu_and_searchable_company_options_are_rendered(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'products.view',
            'products.create',
        ]);
        $category = ProductCategory::factory()->create([
            'company_id' => $user->company_id,
            'name' => 'Alimentos',
        ]);
        ProductCategory::factory()->inactive()->create([
            'company_id' => $user->company_id,
            'name' => 'Categoria inactiva',
        ]);
        $foreignCompany = Company::factory()->create();
        ProductCategory::factory()->create([
            'company_id' => $foreignCompany->id,
            'name' => 'Categoria externa',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('parameters.products.index'));

        $this
            ->actingAs($user)
            ->get(route('parameters.products.create'))
            ->assertOk()
            ->assertSee('data-tom-select', false)
            ->assertSee('data-placeholder="Buscar categoria"', false)
            ->assertSee('data-placeholder="Buscar unidad SIAT"', false)
            ->assertSee('data-placeholder="Buscar actividad economica"', false)
            ->assertSee('data-placeholder="Buscar producto SIAT"', false)
            ->assertSee($category->name)
            ->assertSee('58 - Unidad')
            ->assertSee('471100 - Venta al por menor en comercios no especializados')
            ->assertSee('99123 - Producto SIAT de prueba')
            ->assertDontSee('Categoria inactiva')
            ->assertDontSee('Categoria externa');
    }

    public function test_company_user_can_create_audited_product_with_siat_fields(): void
    {
        config(['audit.console' => true]);

        $user = $this->companyUser([
            'products.view',
            'products.create',
        ]);
        $category = ProductCategory::factory()->create(['company_id' => $user->company_id]);
        $foreignCompany = Company::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('parameters.products.store'), [
                'company_id' => $foreignCompany->id,
                'product_category_id' => $category->id,
                'measurement_unit_code' => 58,
                'internal_code' => ' PROD-001 ',
                'description' => ' Producto de prueba ',
                'economic_activity_code' => ' 471100 ',
                'siat_product_code' => 99123,
                'unit_price' => '125.50000',
                'is_active' => '1',
            ])
            ->assertRedirect(route('parameters.products.index'));

        $product = Product::query()->firstOrFail();

        $this->assertSame($user->company_id, $product->company_id);
        $this->assertSame($category->id, $product->product_category_id);
        $this->assertSame(58, $product->measurement_unit_code);
        $this->assertSame('PROD-001', $product->internal_code);
        $this->assertSame('Producto de prueba', $product->description);
        $this->assertSame('471100', $product->economic_activity_code);
        $this->assertSame(99123, $product->siat_product_code);
        $this->assertSame('125.50000', $product->unit_price);

        $this->assertDatabaseHas('audits', [
            'company_id' => $user->company_id,
            'event' => 'created',
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_product_rejects_foreign_relations_and_inactive_siat_unit(): void
    {
        $user = $this->companyUser(['products.create']);
        $category = ProductCategory::factory()->create(['company_id' => $user->company_id]);
        $foreignCategory = ProductCategory::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('parameters.products.create'))
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($foreignCategory),
                'internal_code' => 'REL-001',
            ])
            ->assertRedirect(route('parameters.products.create'))
            ->assertSessionHasErrors('product_category_id');

        SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->where('catalog_key', 'unidades_medida')
            ->where('classifier_code', '58')
            ->update(['is_active' => false]);

        $this
            ->actingAs($user)
            ->from(route('parameters.products.create'))
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($category),
                'internal_code' => 'REL-002',
            ])
            ->assertRedirect(route('parameters.products.create'))
            ->assertSessionHasErrors('measurement_unit_code');
    }

    public function test_product_requires_active_siat_homologation_from_synced_catalog(): void
    {
        $user = $this->companyUser(['products.create']);
        $category = ProductCategory::factory()->create(['company_id' => $user->company_id]);
        $this->seedSiatHomologation($user->company_id, '471100', 44221);

        SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->where('catalog_key', 'productos_servicios')
            ->where('item_key', 'codigoActividad:471100|codigoProducto:44221')
            ->update(['is_active' => false]);

        $this
            ->actingAs($user)
            ->from(route('parameters.products.create'))
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($category),
                'economic_activity_code' => '999999',
            ])
            ->assertRedirect(route('parameters.products.create'))
            ->assertSessionHasErrors('economic_activity_code');

        $this
            ->actingAs($user)
            ->from(route('parameters.products.create'))
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($category),
                'siat_product_code' => 44221,
            ])
            ->assertRedirect(route('parameters.products.create'))
            ->assertSessionHasErrors('siat_product_code');
    }

    public function test_internal_code_is_unique_per_company_and_siat_code_can_be_shared(): void
    {
        $user = $this->companyUser(['products.create', 'products.edit']);
        $category = ProductCategory::factory()->create(['company_id' => $user->company_id]);

        $existingProduct = Product::factory()->create([
            'company_id' => $user->company_id,
            'product_category_id' => $category->id,
            'measurement_unit_code' => 58,
            'internal_code' => 'PROD-ABC',
            'siat_product_code' => 99123,
        ]);

        $this
            ->actingAs($user)
            ->from(route('parameters.products.create'))
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($category),
                'internal_code' => 'prod-abc',
                'siat_product_code' => 99123,
            ])
            ->assertRedirect(route('parameters.products.create'))
            ->assertSessionHasErrors('internal_code');

        $this
            ->actingAs($user)
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($category),
                'internal_code' => 'PROD-OTRO',
                'siat_product_code' => 99123,
            ])
            ->assertRedirect(route('parameters.products.index'));

        $otherProduct = Product::query()
            ->where('internal_code', 'PROD-OTRO')
            ->firstOrFail();

        $this
            ->actingAs($user)
            ->from(route('parameters.products.edit', $otherProduct))
            ->put(route('parameters.products.update', $otherProduct), [
                ...$this->validProductData($category),
                'internal_code' => 'prod-abc',
            ])
            ->assertRedirect(route('parameters.products.edit', $otherProduct))
            ->assertSessionHasErrors('internal_code');

        $this
            ->actingAs($user)
            ->put(route('parameters.products.update', $existingProduct), [
                ...$this->validProductData($category),
                'internal_code' => 'prod-abc',
            ])
            ->assertRedirect(route('parameters.products.index'));

        $otherUser = $this->companyUser(['products.create']);
        $otherCategory = ProductCategory::factory()->create(['company_id' => $otherUser->company_id]);

        $this
            ->actingAs($otherUser)
            ->post(route('parameters.products.store'), [
                ...$this->validProductData($otherCategory),
                'internal_code' => 'PROD-ABC',
                'siat_product_code' => 99123,
            ])
            ->assertRedirect(route('parameters.products.index'));

        $this->assertSame(3, Product::query()->withoutGlobalScope('company')->count());
    }

    public function test_product_listing_and_model_binding_are_isolated_by_company(): void
    {
        $user = $this->companyUser([
            'products.view',
            'products.edit',
            'products.delete',
        ]);
        $visible = Product::factory()->create([
            'company_id' => $user->company_id,
            'internal_code' => 'VISIBLE-001',
            'measurement_unit_code' => 58,
        ]);
        $foreign = Product::factory()->create(['internal_code' => 'OCULTO-001']);

        $this
            ->actingAs($user)
            ->get(route('parameters.products.index'))
            ->assertOk()
            ->assertSee($visible->internal_code)
            ->assertSee('58')
            ->assertSee('Unidad')
            ->assertDontSee($foreign->internal_code);

        $this
            ->actingAs($user)
            ->get(route('parameters.products.edit', $foreign))
            ->assertNotFound();

        $this
            ->actingAs($user)
            ->delete(route('parameters.products.destroy', $foreign))
            ->assertNotFound();
    }

    public function test_product_can_keep_current_catalog_values_after_they_become_inactive(): void
    {
        $user = $this->companyUser(['products.edit']);
        $category = ProductCategory::factory()->create(['company_id' => $user->company_id]);
        $product = Product::factory()->create([
            'company_id' => $user->company_id,
            'product_category_id' => $category->id,
            'measurement_unit_code' => 58,
            'economic_activity_code' => '471100',
            'siat_product_code' => 99123,
        ]);
        $category->update(['is_active' => false]);
        SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->whereIn('catalog_key', ['unidades_medida', 'actividades', 'productos_servicios'])
            ->update(['is_active' => false]);

        $this
            ->actingAs($user)
            ->get(route('parameters.products.edit', $product))
            ->assertOk()
            ->assertSee($category->name)
            ->assertSee('58 - Unidad');

        $this
            ->actingAs($user)
            ->put(route('parameters.products.update', $product), [
                ...$this->validProductData($category),
                'description' => 'Producto actualizado',
            ])
            ->assertRedirect(route('parameters.products.index'));

        $this->assertSame('Producto actualizado', $product->fresh()->description);
    }

    public function test_categories_in_use_cannot_be_deleted(): void
    {
        $user = $this->companyUser(['product-categories.delete']);
        $category = ProductCategory::factory()->create(['company_id' => $user->company_id]);
        Product::factory()->create([
            'company_id' => $user->company_id,
            'product_category_id' => $category->id,
            'measurement_unit_code' => 58,
        ]);

        $this
            ->actingAs($user)
            ->delete(route('parameters.categories.destroy', $category))
            ->assertSessionHasErrors('category');

        $this->assertNull($category->fresh()->deleted_at);
    }

    public function test_product_permissions_are_seeded_for_expected_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('products.delete'));
        $this->assertTrue($manager->hasPermissionTo('products.create'));
        $this->assertTrue($viewer->hasPermissionTo('products.view'));
        $this->assertFalse($viewer->hasPermissionTo('products.create'));
        $this->assertFalse($cashier->hasPermissionTo('products.view'));
    }

    public function test_database_protects_product_uniqueness_and_cross_company_relations(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'products')
            ->whereIn('indexname', [
                'products_company_internal_code_unique',
                'products_company_id_measurement_unit_code_index',
            ])
            ->count();

        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'products_required_text_not_blank_check',
                'products_siat_product_code_positive_check',
                'products_measurement_unit_code_positive_check',
                'products_unit_price_non_negative_check',
                'products_company_id_product_category_id_foreign',
            ])
            ->count();

        $this->assertSame(2, $indexes);
        $this->assertSame(5, $constraints);

        $company = Company::factory()->create();
        $foreignCategory = ProductCategory::factory()->create();

        try {
            DB::transaction(function () use ($company, $foreignCategory): void {
                Product::query()->withoutGlobalScope('company')->create([
                    ...$this->validProductData($foreignCategory),
                    'company_id' => $company->id,
                ]);
            });

            $this->fail('La base de datos permitio relacionar una categoria de otra empresa.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validProductData(ProductCategory $category): array
    {
        return [
            'product_category_id' => $category->id,
            'measurement_unit_code' => 58,
            'internal_code' => 'PROD-001',
            'description' => 'Producto de prueba',
            'economic_activity_code' => '471100',
            'siat_product_code' => 99123,
            'unit_price' => '10.50000',
            'is_active' => '1',
        ];
    }

    private function companyUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($permissions);
        $this->seedSiatHomologation($company->id);

        return $user;
    }

    private function seedSiatHomologation(
        int $companyId,
        string $activityCode = '471100',
        int $productCode = 99123,
        int $unitCode = 58
    ): void {
        SinCatalogItem::query()->withoutGlobalScope('company')->updateOrCreate(
            [
                'company_id' => $companyId,
                'catalog_key' => 'unidades_medida',
                'item_key' => 'codigoClasificador:'.$unitCode,
            ],
            [
                'classifier_code' => (string) $unitCode,
                'description' => 'Unidad',
                'is_active' => true,
                'raw_data' => [
                    'codigoClasificador' => $unitCode,
                    'descripcion' => 'Unidad',
                ],
                'synced_at' => now(),
            ]
        );

        SinCatalogItem::query()->withoutGlobalScope('company')->updateOrCreate(
            [
                'company_id' => $companyId,
                'catalog_key' => 'actividades',
                'item_key' => 'codigoCaeb:'.$activityCode,
            ],
            [
                'classifier_code' => null,
                'description' => 'Venta al por menor en comercios no especializados',
                'is_active' => true,
                'raw_data' => [
                    'codigoCaeb' => $activityCode,
                    'descripcion' => 'Venta al por menor en comercios no especializados',
                    'tipoActividad' => 'P',
                ],
                'synced_at' => now(),
            ]
        );

        SinCatalogItem::query()->withoutGlobalScope('company')->updateOrCreate(
            [
                'company_id' => $companyId,
                'catalog_key' => 'productos_servicios',
                'item_key' => 'codigoActividad:'.$activityCode.'|codigoProducto:'.$productCode,
            ],
            [
                'classifier_code' => $activityCode,
                'description' => 'Producto SIAT de prueba',
                'is_active' => true,
                'raw_data' => [
                    'codigoActividad' => $activityCode,
                    'codigoProducto' => $productCode,
                    'descripcionProducto' => 'Producto SIAT de prueba',
                ],
                'synced_at' => now(),
            ]
        );
    }
}
