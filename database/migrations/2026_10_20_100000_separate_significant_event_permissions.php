<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['significant-events.view', 'significant-events.register'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            $sourceId = Permission::where('name', 'invoices.issue')->where('guard_name', 'web')->value('id');
            if (! $sourceId) {
                continue;
            }
            foreach (DB::table('role_has_permissions')->where('permission_id', $sourceId)->get() as $grant) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $grant->role_id, 'permission_id' => $permission->id]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $sourceId)->get() as $grant) {
                DB::table('model_has_permissions')->insertOrIgnore(['model_id' => $grant->model_id, 'model_type' => $grant->model_type, 'permission_id' => $permission->id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'web')->whereIn('name', ['significant-events.view', 'significant-events.register'])->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
