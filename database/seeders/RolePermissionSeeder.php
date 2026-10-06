<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.restore',
            'users.change-password',
            'users.assign-roles',
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'roles.assign-permissions',
            'permissions.view',
            'permissions.create',
            'permissions.edit',
            'permissions.delete',
            'companies.view',
            'companies.create',
            'companies.update',
            'companies.delete',
            'audits.view',
            'backups.view',
            'backups.create',
            'backups.download',
            'backups.restore',
            'backups.delete',
            'cash-registers.view',
            'cash-registers.open',
            'cash-registers.close',
            'invoices.view',
            'invoices.issue',
            'invoice-tests.run',
            'invoices.cancel',
            'cafc-ranges.view',
            'cafc-ranges.manage',
            'manual-cafc.view',
            'manual-cafc.use',
            'manual-cafc.transcribe',
            'contingencies.view',
            'contingencies.communication.check',
            'contingencies.events.view',
            'contingencies.events.retry',
            'contingencies.packages.build',
            'contingencies.packages.send',
            'contingencies.packages.validate',
            'contingencies.artifacts.download',
            'contingencies.technical.view',
            'contingencies.audit.view',
            'product-categories.view',
            'product-categories.create',
            'product-categories.edit',
            'product-categories.delete',
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            'sin-authorizations.view',
            'sin-authorizations.manage',
            'sin-api-tokens.view',
            'sin-api-tokens.manage',
            'siat-communication.view',
            'siat-communication.verify',
            'siat-cuis.view',
            'siat-cuis.request',
            'siat-catalogs.view',
            'siat-catalogs.sync',
            'siat-branches.view',
            'siat-branches.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        Permission::query()
            ->whereNotIn('name', $permissions)
            ->delete();

        Role::findOrCreate('super_admin')->syncPermissions($permissions);
        Role::findOrCreate('admin')->syncPermissions($permissions);

        Role::findOrCreate('manager')->syncPermissions([
            'dashboard.view',
            'users.view',
            'roles.view',
            'permissions.view',
            'companies.view',
            'companies.create',
            'companies.update',
            'audits.view',
            'cash-registers.view',
            'cash-registers.open',
            'cash-registers.close',
            'invoices.view',
            'invoices.issue',
            'invoice-tests.run',
            'invoices.cancel',
            'cafc-ranges.view',
            'cafc-ranges.manage',
            'manual-cafc.view',
            'manual-cafc.use',
            'manual-cafc.transcribe',
            'contingencies.view',
            'contingencies.communication.check',
            'contingencies.events.view',
            'contingencies.events.retry',
            'contingencies.packages.build',
            'contingencies.packages.send',
            'contingencies.packages.validate',
            'contingencies.artifacts.download',
            'contingencies.technical.view',
            'contingencies.audit.view',
            'product-categories.view',
            'product-categories.create',
            'product-categories.edit',
            'product-categories.delete',
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            'sin-authorizations.view',
            'sin-authorizations.manage',
            'sin-api-tokens.view',
            'sin-api-tokens.manage',
            'siat-communication.view',
            'siat-communication.verify',
            'siat-cuis.view',
            'siat-cuis.request',
            'siat-catalogs.view',
            'siat-catalogs.sync',
            'siat-branches.view',
            'siat-branches.manage',
        ]);

        Role::findOrCreate('viewer')->syncPermissions([
            'dashboard.view',
            'users.view',
            'roles.view',
            'permissions.view',
            'companies.view',
            'audits.view',
            'cash-registers.view',
            'invoices.view',
            'cafc-ranges.view',
            'manual-cafc.view',
            'contingencies.view',
            'contingencies.events.view',
            'contingencies.artifacts.download',
            'product-categories.view',
            'customers.view',
            'products.view',
            'sin-authorizations.view',
            'sin-api-tokens.view',
            'siat-communication.view',
            'siat-cuis.view',
            'siat-catalogs.view',
            'siat-branches.view',
        ]);

        Role::findOrCreate('cashier')->syncPermissions([
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
        ]);

        Role::findOrCreate('branch_admin')->syncPermissions([
            'dashboard.view', 'cash-registers.view', 'invoices.view', 'invoices.issue',
            'cafc-ranges.view', 'cafc-ranges.manage', 'manual-cafc.view', 'manual-cafc.use',
            'manual-cafc.transcribe', 'contingencies.view', 'contingencies.communication.check',
            'contingencies.events.view', 'contingencies.events.retry', 'contingencies.packages.build',
            'contingencies.packages.send', 'contingencies.packages.validate',
            'contingencies.artifacts.download', 'customers.view', 'products.view',
        ]);

        Role::findOrCreate('accounting')->syncPermissions([
            'dashboard.view', 'invoices.view', 'cafc-ranges.view', 'manual-cafc.view',
            'manual-cafc.transcribe', 'contingencies.view', 'contingencies.events.view',
            'contingencies.packages.validate', 'contingencies.artifacts.download',
        ]);

        Role::findOrCreate('tax_responsible')->syncPermissions([
            'dashboard.view', 'audits.view', 'invoices.view', 'cafc-ranges.view',
            'cafc-ranges.manage', 'manual-cafc.view', 'manual-cafc.use', 'manual-cafc.transcribe',
            'contingencies.view', 'contingencies.communication.check', 'contingencies.events.view',
            'contingencies.events.retry', 'contingencies.packages.build', 'contingencies.packages.send',
            'contingencies.packages.validate', 'contingencies.artifacts.download',
            'contingencies.technical.view', 'contingencies.audit.view',
        ]);

        Role::findOrCreate('technical_support')->syncPermissions([
            'dashboard.view', 'audits.view', 'invoices.view', 'contingencies.view',
            'contingencies.communication.check', 'contingencies.events.view',
            'contingencies.technical.view', 'contingencies.audit.view',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
