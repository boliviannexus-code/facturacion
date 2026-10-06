<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_significant_event_time_changes()
            RETURNS trigger AS $$
            DECLARE
                audited_zero_duration_correction boolean;
            BEGIN
                IF NEW.started_at IS DISTINCT FROM OLD.started_at THEN
                    RAISE EXCEPTION 'La fecha original de inicio del evento significativo es inmutable.';
                END IF;

                audited_zero_duration_correction :=
                    OLD.ended_at IS NOT NULL
                    AND OLD.recovery_detected_at IS NOT NULL
                    AND OLD.ended_at <= OLD.started_at
                    AND NEW.ended_at = OLD.started_at + INTERVAL '1 second'
                    AND NEW.recovery_detected_at = NEW.ended_at
                    AND NEW.administratively_corrected_by_user_id IS NOT NULL
                    AND NEW.administratively_corrected_at IS NOT NULL
                    AND NULLIF(BTRIM(NEW.administrative_correction_reason), '') IS NOT NULL;

                IF OLD.recovery_detected_at IS NOT NULL
                    AND NEW.recovery_detected_at IS DISTINCT FROM OLD.recovery_detected_at
                    AND NOT audited_zero_duration_correction THEN
                    RAISE EXCEPTION 'La fecha real de recuperacion del evento significativo es inmutable.';
                END IF;

                IF OLD.ended_at IS NOT NULL
                    AND NEW.ended_at IS DISTINCT FROM OLD.ended_at
                    AND NOT audited_zero_duration_correction THEN
                    RAISE EXCEPTION 'La fecha de finalizacion del evento significativo es inmutable.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
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
        SQL);
    }
};
