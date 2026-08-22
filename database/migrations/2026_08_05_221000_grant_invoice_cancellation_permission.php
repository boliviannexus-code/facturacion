<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('invoices.cancel');
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->where('name', 'invoices.cancel')->first();

        if ($permission) {
            abort_if($permission->roles()->exists() || $permission->users()->exists(), 409, 'El permiso invoices.cancel está asignado y no puede eliminarse automáticamente.');
            $permission->delete();
        }
    }
};
