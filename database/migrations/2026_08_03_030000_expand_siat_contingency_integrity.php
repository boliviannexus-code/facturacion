<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_branches', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_points_of_sale', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('customers', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_api_tokens', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_authorizations', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_cuis', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_cufds', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_invoice_issues', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::table('sin_significant_events', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
            $table->unsignedBigInteger('sin_branch_id')->nullable()->after('sin_authorization_id');
            $table->unsignedBigInteger('sin_point_of_sale_id')->nullable()->after('sin_branch_id');
            $table->string('event_status', 40)->default('OPEN')->after('event_description');
            $table->foreignId('updated_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('registered_by_user_id')->nullable()->after('updated_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->after('registered_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestampTz('detected_at')->nullable()->after('ended_at');
            $table->timestampTz('recovery_detected_at')->nullable()->after('detected_at');
            $table->timestampTz('closed_at')->nullable()->after('registered_at');
            $table->timestampTz('expires_at')->nullable()->after('closed_at');

            $table->index(['company_id', 'event_status', 'started_at'], 'sin_events_company_status_started_index');
            $table->index(['company_id', 'sin_point_of_sale_id', 'event_status'], 'sin_events_company_pos_status_index');
        });

        DB::statement('ALTER TABLE sin_significant_events ALTER COLUMN ended_at DROP NOT NULL');
        DB::statement(<<<'SQL'
            UPDATE sin_significant_events AS event
            SET sin_branch_id = COALESCE(
                    (SELECT invoice.sin_branch_id FROM sin_invoice_issues AS invoice WHERE invoice.id = event.sin_invoice_issue_id),
                    (SELECT cufd.sin_branch_id FROM sin_cufds AS cufd WHERE cufd.id = event.sin_cufd_id)
                ),
                sin_point_of_sale_id = COALESCE(
                    (SELECT invoice.sin_point_of_sale_id FROM sin_invoice_issues AS invoice WHERE invoice.id = event.sin_invoice_issue_id),
                    (SELECT cufd.sin_point_of_sale_id FROM sin_cufds AS cufd WHERE cufd.id = event.sin_cufd_id)
                ),
                detected_at = COALESCE(event.detected_at, event.started_at)
        SQL);
        DB::statement(<<<'SQL'
            UPDATE sin_significant_events
            SET event_status = CASE
                WHEN transaccion = true THEN 'REGISTERED'
                WHEN status_label IN ('Error', 'Rechazado') THEN 'FAILED'
                ELSE 'OPEN'
            END
        SQL);

        DB::statement('ALTER TABLE sin_points_of_sale ADD CONSTRAINT sin_points_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE sin_cuis ADD CONSTRAINT sin_cuis_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cuis ADD CONSTRAINT sin_cuis_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cuis ADD CONSTRAINT sin_cuis_company_token_foreign FOREIGN KEY (company_id, sin_api_token_id) REFERENCES sin_api_tokens(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cuis ADD CONSTRAINT sin_cuis_company_authorization_foreign FOREIGN KEY (company_id, sin_authorization_id) REFERENCES sin_authorizations(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cufds ADD CONSTRAINT sin_cufds_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cufds ADD CONSTRAINT sin_cufds_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cufds ADD CONSTRAINT sin_cufds_company_cuis_foreign FOREIGN KEY (company_id, sin_cuis_id) REFERENCES sin_cuis(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cufds ADD CONSTRAINT sin_cufds_company_token_foreign FOREIGN KEY (company_id, sin_api_token_id) REFERENCES sin_api_tokens(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_cufds ADD CONSTRAINT sin_cufds_company_authorization_foreign FOREIGN KEY (company_id, sin_authorization_id) REFERENCES sin_authorizations(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_cuis_foreign FOREIGN KEY (company_id, sin_cuis_id) REFERENCES sin_cuis(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_cufd_foreign FOREIGN KEY (company_id, sin_cufd_id) REFERENCES sin_cufds(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_token_foreign FOREIGN KEY (company_id, sin_api_token_id) REFERENCES sin_api_tokens(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_authorization_foreign FOREIGN KEY (company_id, sin_authorization_id) REFERENCES sin_authorizations(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_user_foreign FOREIGN KEY (company_id, user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_invoice_issues ADD CONSTRAINT sin_invoices_company_customer_foreign FOREIGN KEY (company_id, customer_id) REFERENCES customers(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_invoice_foreign FOREIGN KEY (company_id, sin_invoice_issue_id) REFERENCES sin_invoice_issues(company_id, id) ON DELETE SET NULL (sin_invoice_issue_id)');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_cuis_foreign FOREIGN KEY (company_id, sin_cuis_id) REFERENCES sin_cuis(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_cufd_foreign FOREIGN KEY (company_id, sin_cufd_id) REFERENCES sin_cufds(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_creator_foreign FOREIGN KEY (company_id, user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_updater_foreign FOREIGN KEY (company_id, updated_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_registrar_foreign FOREIGN KEY (company_id, registered_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_closer_foreign FOREIGN KEY (company_id, closed_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_status_check CHECK (event_status IN ('OPEN','RECOVERY_DETECTED','PENDING_REGISTRATION','REGISTERED','PACKAGING','SENDING','VALIDATING','COMPLETED','FAILED','EXPIRED'))");
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_chronology_check CHECK (ended_at IS NULL OR ended_at >= started_at)');
        DB::statement('CREATE UNIQUE INDEX sin_events_company_reception_unique ON sin_significant_events(company_id, reception_code) WHERE reception_code IS NOT NULL');

        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_id_cuf_unique');
        DB::statement('CREATE UNIQUE INDEX sin_invoice_issues_company_cuf_unique ON sin_invoice_issues(company_id, cuf)');
        DB::statement('CREATE UNIQUE INDEX sin_invoice_issues_company_reception_unique ON sin_invoice_issues(company_id, reception_code) WHERE reception_code IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_reception_unique');
        DB::statement('DROP INDEX IF EXISTS sin_invoice_issues_company_cuf_unique');
        DB::statement('CREATE UNIQUE INDEX sin_invoice_issues_company_id_cuf_unique ON sin_invoice_issues(company_id, cuf) WHERE invoice_number IS NOT NULL');
        DB::statement('DROP INDEX IF EXISTS sin_events_company_reception_unique');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_chronology_check');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_status_check');
        DB::statement('UPDATE sin_significant_events SET ended_at = started_at WHERE ended_at IS NULL');
        DB::statement('ALTER TABLE sin_significant_events ALTER COLUMN ended_at SET NOT NULL');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_cufd_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_closer_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_registrar_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_updater_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_creator_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_cuis_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_invoice_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_pos_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_branch_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_cufd_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_customer_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_user_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_authorization_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_token_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_cuis_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_pos_foreign');
        DB::statement('ALTER TABLE sin_invoice_issues DROP CONSTRAINT IF EXISTS sin_invoices_company_branch_foreign');
        DB::statement('ALTER TABLE sin_cufds DROP CONSTRAINT IF EXISTS sin_cufds_company_cuis_foreign');
        DB::statement('ALTER TABLE sin_cufds DROP CONSTRAINT IF EXISTS sin_cufds_company_authorization_foreign');
        DB::statement('ALTER TABLE sin_cufds DROP CONSTRAINT IF EXISTS sin_cufds_company_token_foreign');
        DB::statement('ALTER TABLE sin_cufds DROP CONSTRAINT IF EXISTS sin_cufds_company_pos_foreign');
        DB::statement('ALTER TABLE sin_cufds DROP CONSTRAINT IF EXISTS sin_cufds_company_branch_foreign');
        DB::statement('ALTER TABLE sin_cuis DROP CONSTRAINT IF EXISTS sin_cuis_company_pos_foreign');
        DB::statement('ALTER TABLE sin_cuis DROP CONSTRAINT IF EXISTS sin_cuis_company_branch_foreign');
        DB::statement('ALTER TABLE sin_cuis DROP CONSTRAINT IF EXISTS sin_cuis_company_authorization_foreign');
        DB::statement('ALTER TABLE sin_cuis DROP CONSTRAINT IF EXISTS sin_cuis_company_token_foreign');
        DB::statement('ALTER TABLE sin_points_of_sale DROP CONSTRAINT IF EXISTS sin_points_company_branch_foreign');

        Schema::table('sin_significant_events', function (Blueprint $table): void {
            $table->dropIndex('sin_events_company_status_started_index');
            $table->dropIndex('sin_events_company_pos_status_index');
            $table->dropUnique(['company_id', 'id']);
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropConstrainedForeignId('registered_by_user_id');
            $table->dropConstrainedForeignId('updated_by_user_id');
            $table->dropColumn([
                'sin_branch_id', 'sin_point_of_sale_id', 'event_status', 'detected_at',
                'recovery_detected_at', 'closed_at', 'expires_at',
            ]);
        });

        Schema::table('sin_invoice_issues', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('sin_cufds', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('sin_cuis', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('sin_points_of_sale', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('sin_authorizations', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('sin_api_tokens', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
        Schema::table('sin_branches', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
    }
};
