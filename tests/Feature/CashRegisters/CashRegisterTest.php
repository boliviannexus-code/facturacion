<?php

namespace Tests\Feature\CashRegisters;

use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_can_open_a_cash_register_for_themselves(): void
    {
        config(['audit.console' => true]);

        $user = $this->companyUser([
            'cash-registers.view',
            'cash-registers.open',
        ]);

        $this
            ->actingAs($user)
            ->post(route('cash-registers.store'), [
                'opening_amount' => '250.50',
                'opening_notes' => 'Inicio de turno',
                'company_id' => Company::factory()->create()->id,
                'user_id' => User::factory()->create()->id,
            ])
            ->assertRedirect(route('cash-registers.index'));

        $cashRegister = CashRegister::query()->firstOrFail();

        $this->assertSame($user->company_id, $cashRegister->company_id);
        $this->assertSame($user->id, $cashRegister->user_id);
        $this->assertSame('250.50', $cashRegister->opening_amount);
        $this->assertTrue($cashRegister->isActive());

        $this->assertDatabaseHas('audits', [
            'company_id' => $user->company_id,
            'event' => 'created',
            'auditable_type' => CashRegister::class,
            'auditable_id' => $cashRegister->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_cannot_open_two_active_cash_registers(): void
    {
        $user = $this->companyUser([
            'cash-registers.view',
            'cash-registers.open',
        ]);

        $this->actingAs($user)->post(route('cash-registers.store'), [
            'opening_amount' => '100.00',
        ]);

        $this
            ->actingAs($user)
            ->from(route('cash-registers.index'))
            ->post(route('cash-registers.store'), [
                'opening_amount' => '200.00',
            ])
            ->assertRedirect(route('cash-registers.index'))
            ->assertSessionHasErrors('cash_register');

        $this->assertSame(1, CashRegister::query()->active()->count());
    }

    public function test_opening_amount_must_be_nonnegative_with_at_most_two_decimals(): void
    {
        $user = $this->companyUser([
            'cash-registers.view',
            'cash-registers.open',
        ]);

        $this
            ->actingAs($user)
            ->post(route('cash-registers.store'), [
                'opening_amount' => '-1.00',
            ])
            ->assertSessionHasErrors('opening_amount');

        $this
            ->actingAs($user)
            ->post(route('cash-registers.store'), [
                'opening_amount' => '10.999',
            ])
            ->assertSessionHasErrors('opening_amount');

        $this->assertDatabaseCount('cash_registers', 0);
    }

    public function test_user_can_close_their_cash_register_and_open_another(): void
    {
        $user = $this->companyUser([
            'cash-registers.view',
            'cash-registers.open',
            'cash-registers.close',
        ]);

        $this->actingAs($user)->post(route('cash-registers.store'), [
            'opening_amount' => '100.00',
        ]);

        $cashRegister = CashRegister::query()->firstOrFail();

        $this
            ->actingAs($user)
            ->patch(route('cash-registers.close', $cashRegister), [
                'closing_amount' => '480.75',
                'closing_notes' => 'Fin de turno',
            ])
            ->assertRedirect(route('cash-registers.index'));

        $cashRegister->refresh();

        $this->assertFalse($cashRegister->isActive());
        $this->assertSame('480.75', $cashRegister->closing_amount);
        $this->assertNotNull($cashRegister->closed_at);

        $this
            ->actingAs($user)
            ->post(route('cash-registers.store'), [
                'opening_amount' => '50.00',
            ])
            ->assertRedirect(route('cash-registers.index'));

        $this->assertSame(2, CashRegister::query()->count());
        $this->assertSame(1, CashRegister::query()->active()->count());
    }

    public function test_cash_register_listing_is_isolated_by_company(): void
    {
        $user = $this->companyUser(['cash-registers.view'], name: 'Cajero visible');
        $otherUser = $this->companyUser(['cash-registers.view'], name: 'Cajero ajeno');

        CashRegister::factory()->closed()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);
        CashRegister::factory()->closed()->create([
            'company_id' => $otherUser->company_id,
            'user_id' => $otherUser->id,
        ]);

        $this
            ->actingAs($user)
            ->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('Cajero visible')
            ->assertDontSee('Cajero ajeno');
    }

    public function test_user_cannot_close_a_cash_register_from_another_company(): void
    {
        $user = $this->companyUser([
            'cash-registers.view',
            'cash-registers.close',
        ]);
        $otherUser = $this->companyUser([
            'cash-registers.view',
            'cash-registers.close',
        ]);
        $foreignCashRegister = CashRegister::factory()->create([
            'company_id' => $otherUser->company_id,
            'user_id' => $otherUser->id,
        ]);

        $this
            ->actingAs($user)
            ->patch(route('cash-registers.close', $foreignCashRegister), [
                'closing_amount' => '100.00',
            ])
            ->assertNotFound();

        $this->assertNull(
            CashRegister::query()
                ->withoutGlobalScope('company')
                ->findOrFail($foreignCashRegister->id)
                ->closed_at
        );
    }

    public function test_global_administrator_without_company_cannot_operate_cash_registers(): void
    {
        Permission::findOrCreate('cash-registers.view');
        Role::findOrCreate('super_admin');

        $user = User::factory()->create(['company_id' => null]);
        $user->assignRole('super_admin');
        $user->givePermissionTo('cash-registers.view');

        $this
            ->actingAs($user)
            ->get(route('cash-registers.index'))
            ->assertForbidden();
    }

    public function test_user_cannot_close_another_users_cash_register_in_the_same_company(): void
    {
        $company = Company::factory()->create();
        $user = $this->companyUser([
            'cash-registers.view',
            'cash-registers.close',
        ], $company);
        $otherUser = $this->companyUser([
            'cash-registers.view',
            'cash-registers.close',
        ], $company);
        $cashRegister = CashRegister::factory()->create([
            'company_id' => $company->id,
            'user_id' => $otherUser->id,
        ]);

        $this
            ->actingAs($user)
            ->patch(route('cash-registers.close', $cashRegister), [
                'closing_amount' => '100.00',
            ])
            ->assertForbidden();
    }

    public function test_user_with_active_cash_register_cannot_be_deactivated(): void
    {
        $company = Company::factory()->create();
        $actor = $this->companyUser(['users.edit'], $company);
        $cashier = $this->companyUser([], $company);

        CashRegister::factory()->create([
            'company_id' => $company->id,
            'user_id' => $cashier->id,
        ]);

        $this
            ->actingAs($actor)
            ->from(route('users.index'))
            ->patch(route('users.toggle-status', $cashier))
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('user');

        $this->assertTrue($cashier->refresh()->is_active);
    }

    public function test_user_with_cash_register_history_cannot_be_moved_to_another_company(): void
    {
        Permission::findOrCreate('users.edit');
        $actor = User::factory()->create(['company_id' => null]);
        Role::findOrCreate('super_admin');
        $actor->assignRole('super_admin');
        $actor->givePermissionTo('users.edit');

        $cashier = $this->companyUser([]);
        $newCompany = Company::factory()->create();

        CashRegister::factory()->closed()->create([
            'company_id' => $cashier->company_id,
            'user_id' => $cashier->id,
        ]);

        $this
            ->actingAs($actor)
            ->from(route('users.edit', $cashier))
            ->put(route('users.update', $cashier), [
                'company_id' => $newCompany->id,
                'name' => $cashier->name,
                'email' => $cashier->email,
                'is_active' => '1',
            ])
            ->assertRedirect(route('users.edit', $cashier))
            ->assertSessionHasErrors('company_id');

        $this->assertNotSame($newCompany->id, $cashier->refresh()->company_id);
    }

    public function test_user_with_sales_cannot_be_changed_to_no_company(): void
    {
        Permission::findOrCreate('users.edit');
        $actor = User::factory()->create(['company_id' => null]);
        Role::findOrCreate('super_admin');
        $actor->assignRole('super_admin');
        $actor->givePermissionTo('users.edit');

        $seller = $this->companyUser([]);
        Sale::factory()->create([
            'company_id' => $seller->company_id,
            'user_id' => $seller->id,
        ]);

        $this
            ->actingAs($actor)
            ->from(route('users.edit', $seller))
            ->put(route('users.update', $seller), [
                'company_id' => '',
                'name' => $seller->name,
                'email' => $seller->email,
                'is_active' => '1',
            ])
            ->assertRedirect(route('users.edit', $seller))
            ->assertSessionHasErrors([
                'company_id' => 'No puedes cambiar ni quitar la empresa de un usuario que tiene ventas registradas.',
            ]);

        $this->assertNotNull($seller->refresh()->company_id);
    }

    public function test_company_with_cash_register_history_cannot_be_deleted(): void
    {
        Permission::findOrCreate('companies.delete');
        $actor = User::factory()->create();
        $actor->givePermissionTo('companies.delete');
        $cashier = $this->companyUser([]);
        $company = $cashier->company;

        CashRegister::factory()->closed()->create([
            'company_id' => $company->id,
            'user_id' => $cashier->id,
        ]);

        $this
            ->actingAs($actor)
            ->from(route('companies.index'))
            ->delete(route('companies.destroy', $company))
            ->assertRedirect(route('companies.index'))
            ->assertSessionHasErrors('company');

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_database_enforces_active_uniqueness_and_company_user_consistency(): void
    {
        $indexDefinition = DB::table('pg_indexes')
            ->where('tablename', 'cash_registers')
            ->where('indexname', 'cash_registers_one_active_per_user_unique')
            ->value('indexdef');

        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'cash_registers_company_user_foreign',
                'cash_registers_amounts_nonnegative_check',
                'cash_registers_closure_consistency_check',
                'cash_registers_chronology_check',
            ])
            ->pluck('conname');

        $this->assertNotNull($indexDefinition);
        $this->assertStringContainsString('WHERE (closed_at IS NULL)', $indexDefinition);
        $this->assertCount(4, $constraints);
    }

    public function test_cashier_role_receives_only_the_required_operational_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $cashier = Role::findByName('cashier');

        $this->assertEqualsCanonicalizing([
            'dashboard.view',
            'cash-registers.view',
            'cash-registers.open',
            'cash-registers.close',
            'invoices.view',
            'invoices.issue',
            'manual-cafc.view',
            'manual-cafc.use',
            'contingencies.view',
            'contingencies.events.view',
            'customers.create',
        ], $cashier->permissions->pluck('name')->all());

        $this->assertFalse($cashier->hasPermissionTo('contingencies.events.retry'));
        $this->assertFalse($cashier->hasPermissionTo('contingencies.packages.build'));
        $this->assertFalse($cashier->hasPermissionTo('contingencies.packages.send'));
        $this->assertFalse($cashier->hasPermissionTo('contingencies.technical.view'));
    }

    private function companyUser(
        array $permissions,
        ?Company $company = null,
        string $name = 'Cajero'
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

        return $user;
    }
}
