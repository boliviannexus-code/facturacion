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
        Schema::table('sin_significant_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('recovery_sin_cufd_id')->nullable()->after('sin_cufd_id');
            $table->uuid('registration_claim')->nullable()->after('reception_code');
            $table->timestampTz('registration_claimed_at')->nullable()->after('registration_claim');
            $table->boolean('manual_review_required')->default(false)->after('expires_at');
            $table->text('administrative_correction_reason')->nullable()->after('manual_review_required');
            $table->unsignedBigInteger('administratively_corrected_by_user_id')->nullable()->after('administrative_correction_reason');
            $table->timestampTz('administratively_corrected_at')->nullable()->after('administratively_corrected_by_user_id');

            $table->index(
                ['company_id', 'event_status', 'recovery_detected_at'],
                'sin_events_recovery_status_index',
            );
            $table->index('registration_claim');
        });

        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_recovery_cufd_foreign FOREIGN KEY (company_id, recovery_sin_cufd_id) REFERENCES sin_cufds(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_company_admin_corrector_foreign FOREIGN KEY (company_id, administratively_corrected_by_user_id) REFERENCES users(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_significant_events ADD CONSTRAINT sin_events_recovery_time_check CHECK (recovery_detected_at IS NULL OR ended_at = recovery_detected_at)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_significant_event_time_changes()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.started_at IS DISTINCT FROM OLD.started_at THEN
                    RAISE EXCEPTION 'La fecha original de inicio del evento significativo es inmutable.';
                END IF;

                IF OLD.recovery_detected_at IS NOT NULL
                    AND NEW.recovery_detected_at IS DISTINCT FROM OLD.recovery_detected_at THEN
                    RAISE EXCEPTION 'La fecha real de recuperacion del evento significativo es inmutable.';
                END IF;

                IF OLD.ended_at IS NOT NULL AND NEW.ended_at IS DISTINCT FROM OLD.ended_at THEN
                    RAISE EXCEPTION 'La fecha de finalizacion del evento significativo es inmutable.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prevent_significant_event_time_changes
            BEFORE UPDATE ON sin_significant_events
            FOR EACH ROW EXECUTE FUNCTION prevent_significant_event_time_changes();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS prevent_significant_event_time_changes ON sin_significant_events');
        DB::statement('DROP FUNCTION IF EXISTS prevent_significant_event_time_changes()');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_recovery_time_check');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_admin_corrector_foreign');
        DB::statement('ALTER TABLE sin_significant_events DROP CONSTRAINT IF EXISTS sin_events_company_recovery_cufd_foreign');

        Schema::table('sin_significant_events', function (Blueprint $table): void {
            $table->dropIndex('sin_events_recovery_status_index');
            $table->dropIndex(['registration_claim']);
            $table->dropColumn([
                'recovery_sin_cufd_id',
                'registration_claim',
                'registration_claimed_at',
                'manual_review_required',
                'administrative_correction_reason',
                'administratively_corrected_by_user_id',
                'administratively_corrected_at',
            ]);
        });
    }
};
