<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user_access', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['company_id', 'user_id']);
        });
        DB::statement('INSERT INTO company_user_access SELECT company_id, id FROM users WHERE company_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION grant_reserved_company_access() RETURNS void LANGUAGE sql AS $$
                INSERT INTO company_user_access (company_id, user_id)
                SELECT c.id, u.id FROM companies c CROSS JOIN users u
                WHERE lower(btrim(u.email)) = 'boliviannexus@gmail.com'
                AND EXISTS (SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id
                    WHERE m.model_id = u.id AND m.model_type = 'App\Models\User' AND r.name = 'super_admin' AND r.guard_name = 'web')
                ON CONFLICT DO NOTHING;
            $$;
            SELECT grant_reserved_company_access();

            CREATE OR REPLACE FUNCTION validate_company_user_access() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.user_id AND (u.company_id = NEW.company_id OR (
                    lower(btrim(u.email)) = 'boliviannexus@gmail.com' AND EXISTS (
                        SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id
                        WHERE m.model_id = u.id AND m.model_type = 'App\Models\User' AND r.name = 'super_admin' AND r.guard_name = 'web'
                    )
                ))) THEN
                    RAISE EXCEPTION 'El usuario no puede operar en esta empresa' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER validate_company_user_access BEFORE INSERT OR UPDATE ON company_user_access
                FOR EACH ROW EXECUTE FUNCTION validate_company_user_access();

            CREATE OR REPLACE FUNCTION sync_company_user_access() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'users' THEN
                    IF TG_OP = 'UPDATE' AND NEW.company_id IS NOT DISTINCT FROM OLD.company_id THEN RETURN NEW; END IF;
                    DELETE FROM company_user_access WHERE user_id = NEW.id AND company_id IS DISTINCT FROM NEW.company_id;
                    IF NEW.company_id IS NOT NULL THEN
                        INSERT INTO company_user_access VALUES (NEW.company_id, NEW.id) ON CONFLICT DO NOTHING;
                    END IF;
                END IF;
                PERFORM grant_reserved_company_access();
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER sync_company_user_access AFTER INSERT OR UPDATE OF company_id ON users
                FOR EACH ROW EXECUTE FUNCTION sync_company_user_access();
            CREATE TRIGGER grant_company_owner_access AFTER INSERT ON companies
                FOR EACH ROW EXECUTE FUNCTION sync_company_user_access();
            CREATE TRIGGER grant_super_admin_company_access AFTER INSERT ON model_has_roles
                FOR EACH ROW EXECUTE FUNCTION sync_company_user_access();
            SQL);
        $this->retarget('users', 'company_user_access', 'user_id');
    }

    public function down(): void
    {
        // Restoring the original constraints fails atomically if cross-company history exists.
        $this->retarget('company_user_access', 'users', 'id');
        DB::unprepared('DROP TRIGGER IF EXISTS sync_company_user_access ON users;
            DROP TRIGGER IF EXISTS grant_company_owner_access ON companies;
            DROP TRIGGER IF EXISTS grant_super_admin_company_access ON model_has_roles;
            DROP FUNCTION IF EXISTS sync_company_user_access();
            DROP TABLE company_user_access;
            DROP FUNCTION IF EXISTS validate_company_user_access();
            DROP FUNCTION IF EXISTS grant_reserved_company_access();');
    }

    private function retarget(string $source, string $target, string $targetUserColumn): void
    {
        $constraints = DB::select(<<<'SQL'
            SELECT c.conname, c.conrelid::regclass::text AS table_name, a.attname AS user_column
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[2]
            JOIN pg_attribute first_col ON first_col.attrelid = c.conrelid AND first_col.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND c.confrelid = to_regclass(?) AND array_length(c.conkey, 1) = 2
                AND first_col.attname = 'company_id'
            SQL, [$source]);
        foreach ($constraints as $constraint) {
            // Identifiers come exclusively from PostgreSQL's system catalog.
            $table = implode('.', array_map(fn ($part) => '"'.str_replace('"', '""', $part).'"', explode('.', $constraint->table_name)));
            $name = '"'.str_replace('"', '""', $constraint->conname).'"';
            $column = '"'.str_replace('"', '""', $constraint->user_column).'"';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY (company_id, {$column}) REFERENCES {$target}(company_id, {$targetUserColumn}) ON DELETE RESTRICT");
        }
    }
};
