<?php

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\SinPointOfSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SignificantEventPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_view_permission_shows_events_but_cannot_register_or_issue_invoices(): void
    {
        $user = $this->userWith(['significant-events.view']);
        $this->actingAs($user)->get(route('billing.significant-events.index'))->assertOk()
            ->assertSee('Consultar eventos')->assertDontSee('id="event-description"', false)
            ->assertSee(route('billing.significant-events.index'))->assertDontSee(route('billing.invoices.issue.index'));
        $this->post(route('billing.significant-events.point-of-sale.store'), [])->assertForbidden();
        $this->get(route('billing.invoices.issue.index'))->assertForbidden();
    }

    public function test_registration_permission_does_not_require_or_grant_invoice_issuance(): void
    {
        $user = $this->userWith(['significant-events.register']);
        $point = SinPointOfSale::factory()->create(['company_id' => $user->company_id]);
        $this->actingAs($user)->get(route('billing.significant-events.index'))->assertOk()->assertSee('id="event-description"', false);
        $this->get(route('billing.significant-events.point-of-sale.create', $point))->assertOk();
        $this->postJson(route('billing.significant-events.point-of-sale.store'), [])->assertUnprocessable();
        $this->get(route('billing.invoices.issue.index'))->assertForbidden();
        self::assertFalse($user->can('invoices.issue'));
    }

    public function test_invoice_permission_alone_no_longer_allows_event_access(): void
    {
        $user = $this->userWith(['invoices.issue']);
        $this->actingAs($user)->get(route('billing.significant-events.index'))->assertForbidden();
        $this->post(route('billing.significant-events.point-of-sale.store'), [])->assertForbidden();
        $this->get(route('billing.invoices.issue.index'))->assertOk()->assertDontSee(route('billing.significant-events.index'));
    }

    public function test_permissions_can_be_configured_separately_from_the_role_form(): void
    {
        $actor = $this->userWith(['roles.assign-permissions']);
        $role = Role::findOrCreate('event_reader', 'web');
        $this->actingAs($actor)->get(route('roles.permissions.form', $role))->assertOk()
            ->assertSee('Eventos significativos')->assertSee('value="significant-events.view"', false)
            ->assertSee('value="significant-events.register"', false)->assertSee('Registrar');
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->patchJson(route('roles.permissions', $role),
            ['permissions' => ['significant-events.view']])->assertOk();
        self::assertSame(['significant-events.view'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_migration_preserves_existing_role_and_direct_access_without_granting_others(): void
    {
        $holder = $this->userWith(['invoices.issue']);
        $other = $this->userWith(['dashboard.view']);
        $role = Role::findOrCreate('issuer', 'web');
        $role->givePermissionTo('invoices.issue');
        $migration = require database_path('migrations/2026_10_20_100000_separate_significant_event_permissions.php');
        $migration->up();
        $migration->up();
        foreach (['significant-events.view', 'significant-events.register'] as $name) {
            self::assertTrue($holder->fresh()->hasDirectPermission($name));
            self::assertTrue($role->fresh()->hasPermissionTo($name));
            self::assertFalse($other->fresh()->hasPermissionTo($name));
        }
    }
}
