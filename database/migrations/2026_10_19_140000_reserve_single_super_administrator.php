<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE users, roles, model_has_roles IN SHARE ROW EXCLUSIVE MODE');
            $email = 'boliviannexus@gmail.com';
            $morph = (new User)->getMorphClass();
            $userType = DB::connection()->getPdo()->quote($morph);
            $reserved = DB::table('users')->whereRaw('lower(btrim(email)) = ?', [$email])->lockForUpdate()->get();
            if ($reserved->count() > 1) {
                throw new RuntimeException('Existen varias cuentas con el correo reservado. Resuelve el duplicado antes de continuar.');
            }
            $roles = DB::table('roles')->whereRaw("lower(btrim(name)) = 'super_admin'")->get();
            if ($roles->contains(fn ($role) => $role->name !== 'super_admin' || $role->guard_name !== 'web') || $roles->count() > 1) {
                throw new RuntimeException('El rol super_admin debe existir una sola vez, con guard web.');
            }
            $role = $roles->first();
            $owner = $reserved->first();
            if (! $owner && DB::table('users')->exists()) {
                throw new RuntimeException('Registra la cuenta boliviannexus@gmail.com antes de regularizar usuarios existentes.');
            }
            if ($owner && (! $owner->is_active || $owner->deleted_at !== null)) {
                throw new RuntimeException('Restaura y activa la cuenta reservada antes de aplicar esta migración.');
            }
            if ($owner && ! $role) {
                $roleId = DB::table('roles')->insertGetId(['name' => 'super_admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
                $role = DB::table('roles')->where('id', $roleId)->first();
                foreach (DB::table('permissions')->where('guard_name', 'web')->pluck('id') as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            if ($role) {
                $memberships = DB::table('model_has_roles')->where('role_id', $role->id)->lockForUpdate()->get();
                foreach ($memberships as $membership) {
                    if ($owner && $membership->model_type === $morph && (int) $membership->model_id === (int) $owner->id) {
                        continue;
                    }
                    DB::table('model_has_roles')->where('role_id', $role->id)->where('model_type', $membership->model_type)->where('model_id', $membership->model_id)->delete();
                    if ($membership->model_type !== $morph) {
                        continue;
                    }
                    $user = DB::table('users')->where('id', $membership->model_id)->lockForUpdate()->first();
                    if (! $user) {
                        continue;
                    }
                    $beforeRoles = DB::table('model_has_roles')->where('model_type', $morph)->where('model_id', $user->id)->pluck('role_id')->all();
                    $beforeRoles[] = $role->id;
                    if ($user->company_id !== null) {
                        $adminId = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
                        if (! $adminId) {
                            $adminId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
                        }
                        DB::table('model_has_roles')->insertOrIgnore(['role_id' => $adminId, 'model_type' => $morph, 'model_id' => $user->id]);
                    } else {
                        DB::table('users')->where('id', $user->id)->update(['is_active' => false, 'updated_at' => now()]);
                    }
                    DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);
                    $sessionTable = config('session.table', 'sessions');
                    if (Schema::hasTable($sessionTable)) {
                        DB::table($sessionTable)->where('user_id', $user->id)->delete();
                    }
                    if (Schema::hasTable('personal_access_tokens')) {
                        DB::table('personal_access_tokens')->where('tokenable_type', $morph)->where('tokenable_id', $user->id)->delete();
                    }
                    $afterRoles = DB::table('model_has_roles')->where('model_type', $morph)->where('model_id', $user->id)->pluck('role_id')->all();
                    DB::table('audits')->insert([
                        'company_id' => $user->company_id, 'event' => 'updated', 'auditable_type' => $morph, 'auditable_id' => $user->id,
                        'old_values' => json_encode(['role_ids' => $beforeRoles, 'is_active' => (bool) $user->is_active]),
                        'new_values' => json_encode(['role_ids' => $afterRoles, 'is_active' => $user->company_id !== null ? (bool) $user->is_active : false]),
                        'tags' => 'reserved-super-admin-migration', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($owner) {
                    DB::table('model_has_roles')->insertOrIgnore(['role_id' => $role->id, 'model_type' => $morph, 'model_id' => $owner->id]);
                }
            }
            DB::statement("CREATE UNIQUE INDEX users_reserved_super_admin_email_unique ON users (lower(btrim(email))) WHERE lower(btrim(email)) = 'boliviannexus@gmail.com'");
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION enforce_reserved_super_admin_membership() RETURNS trigger LANGUAGE plpgsql AS \$\$
                DECLARE role_name text;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        SELECT name INTO role_name FROM roles WHERE id = OLD.role_id;
                        IF role_name = 'super_admin' THEN
                            RAISE EXCEPTION 'La cuenta protegida debe conservar el rol super_admin' USING ERRCODE = '23514';
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        SELECT name INTO role_name FROM roles WHERE id = OLD.role_id;
                        IF role_name = 'super_admin' AND (NEW.role_id <> OLD.role_id OR NEW.model_id <> OLD.model_id OR NEW.model_type <> OLD.model_type) THEN
                            RAISE EXCEPTION 'No se puede transferir el rol super_admin' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    SELECT name INTO role_name FROM roles WHERE id = NEW.role_id;
                    IF role_name = 'super_admin' AND (
                        NEW.model_type <> {$userType} OR NOT EXISTS (
                            SELECT 1 FROM users WHERE id = NEW.model_id AND lower(btrim(email)) = 'boliviannexus@gmail.com' AND is_active AND deleted_at IS NULL
                        )
                    ) THEN
                        RAISE EXCEPTION 'super_admin está reservado para boliviannexus@gmail.com' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$;
                CREATE TRIGGER reserved_super_admin_membership BEFORE INSERT OR UPDATE OR DELETE ON model_has_roles
                FOR EACH ROW EXECUTE FUNCTION enforce_reserved_super_admin_membership();

                CREATE OR REPLACE FUNCTION protect_reserved_super_admin_user() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF lower(btrim(OLD.email)) = 'boliviannexus@gmail.com' THEN
                            RAISE EXCEPTION 'No se puede eliminar la cuenta protegida' USING ERRCODE = '23514';
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'UPDATE' AND lower(btrim(OLD.email)) = 'boliviannexus@gmail.com' AND (NEW.id <> OLD.id OR lower(btrim(NEW.email)) <> 'boliviannexus@gmail.com') THEN
                        RAISE EXCEPTION 'El correo del superadministrador está protegido' USING ERRCODE = '23514';
                    END IF;
                    IF lower(btrim(NEW.email)) = 'boliviannexus@gmail.com' AND (NOT NEW.is_active OR NEW.deleted_at IS NOT NULL) THEN
                        RAISE EXCEPTION 'La cuenta protegida debe permanecer activa' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$;
                CREATE TRIGGER reserved_super_admin_user BEFORE INSERT OR UPDATE OR DELETE ON users
                FOR EACH ROW EXECUTE FUNCTION protect_reserved_super_admin_user();

                CREATE OR REPLACE FUNCTION protect_reserved_super_admin_role() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.name = 'super_admin' THEN
                            RAISE EXCEPTION 'No se puede eliminar el rol super_admin' USING ERRCODE = '23514';
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF TG_OP = 'UPDATE' AND OLD.name = 'super_admin' AND (NEW.name <> OLD.name OR NEW.guard_name <> OLD.guard_name) THEN
                        RAISE EXCEPTION 'El rol super_admin está protegido' USING ERRCODE = '23514';
                    END IF;
                    IF lower(btrim(NEW.name)) = 'super_admin' AND (
                        NEW.name <> 'super_admin' OR NEW.guard_name <> 'web' OR EXISTS (
                            SELECT 1 FROM roles WHERE lower(btrim(name)) = 'super_admin' AND id <> NEW.id
                        )
                    ) THEN
                        RAISE EXCEPTION 'El rol super_admin solo puede existir con guard web' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$;
                CREATE TRIGGER reserved_super_admin_role BEFORE INSERT OR UPDATE OR DELETE ON roles
                FOR EACH ROW EXECUTE FUNCTION protect_reserved_super_admin_role();
                SQL);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS reserved_super_admin_membership ON model_has_roles;
            DROP TRIGGER IF EXISTS reserved_super_admin_user ON users;
            DROP TRIGGER IF EXISTS reserved_super_admin_role ON roles;
            DROP FUNCTION IF EXISTS enforce_reserved_super_admin_membership();
            DROP FUNCTION IF EXISTS protect_reserved_super_admin_user();
            DROP FUNCTION IF EXISTS protect_reserved_super_admin_role();
            DROP INDEX IF EXISTS users_reserved_super_admin_email_unique;');
        // Demoted accounts and revoked sessions are intentionally not restored.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
