<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\User;
use App\Support\ReservedSuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_company_with_report_data_and_logo(): void
    {
        Storage::fake('public');
        $user = $this->userWithCompanyPermissions();

        $this
            ->actingAs($user)
            ->post(route('companies.store'), [
                'name' => 'Empresa Demo',
                'legal_name' => 'Empresa Demo SRL',
                'tax_id' => '1234567',
                'phone' => '70000000',
                'email' => 'demo@example.com',
                'address' => 'Av. Siempre Viva',
                'city' => 'La Paz',
                'country' => 'Bolivia',
                'report_footer' => 'Gracias por su compra',
                'logo' => UploadedFile::fake()->image('logo.png'),
                'is_active' => '1',
            ])
            ->assertRedirect(route('companies.index'));

        $company = Company::query()->firstOrFail();

        $this->assertSame('Empresa Demo', $company->name);
        $this->assertSame('Empresa Demo SRL', $company->legal_name);
        $this->assertNotNull($company->logo_path);
        $this->assertStringEndsWith('.webp', $company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);

        $logo = imagecreatefromstring(Storage::disk('public')->get($company->logo_path));
        $this->assertNotFalse($logo);
        $this->assertLessThanOrEqual(1200, imagesx($logo));
        $this->assertLessThanOrEqual(1200, imagesy($logo));
        imagedestroy($logo);
    }

    public function test_user_can_assign_company_to_user(): void
    {
        $actor = $this->userWithUserPermissions();
        $company = Company::factory()->create(['name' => 'Empresa Asignada']);

        $this
            ->actingAs($actor)
            ->post(route('users.store'), [
                'company_id' => $company->id,
                'name' => 'Cajero Empresa',
                'email' => 'cashier@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_active' => '1',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'cashier@example.com',
        ]);

        $this
            ->actingAs($actor)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Empresa Asignada');
    }

    private function userWithCompanyPermissions(): User
    {
        $permissions = ['companies.view', 'companies.create', 'companies.update', 'companies.delete'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function userWithUserPermissions(): User
    {
        $permissions = ['users.view', 'users.create'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create(['email' => ReservedSuperAdmin::EMAIL]);
        Role::findOrCreate('super_admin')->givePermissionTo($permissions);
        $user->assignRole('super_admin');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
