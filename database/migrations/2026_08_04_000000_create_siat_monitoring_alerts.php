<?php

use App\Enums\SiatAlertStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_authorizations', function (Blueprint $table): void {
            $table->timestampTz('certificate_expires_at')->nullable()->after('point_of_sale_code');
            $table->index(['company_id', 'certificate_expires_at'], 'sin_auth_certificate_expiry_index');
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sin_monitoring_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sin_branch_id')->nullable()->constrained('sin_branches')->restrictOnDelete();
            $table->foreignId('sin_point_of_sale_id')->nullable()->constrained('sin_points_of_sale')->restrictOnDelete();
            $table->foreignId('sin_significant_event_id')->nullable()->constrained('sin_significant_events')->restrictOnDelete();
            $table->foreignId('sin_invoice_package_id')->nullable()->constrained('sin_invoice_packages')->restrictOnDelete();
            $table->foreignId('sin_invoice_issue_id')->nullable()->constrained('sin_invoice_issues')->restrictOnDelete();
            $table->foreignId('sin_manual_contingency_invoice_id')->nullable()->constrained('sin_manual_contingency_invoices')->restrictOnDelete();
            $table->foreignId('sin_cufd_id')->nullable()->constrained('sin_cufds')->restrictOnDelete();
            $table->foreignId('sin_cafc_range_id')->nullable()->constrained('sin_cafc_ranges')->restrictOnDelete();
            $table->foreignId('sin_authorization_id')->nullable()->constrained('sin_authorizations')->restrictOnDelete();
            $table->string('alert_type', 80);
            $table->string('severity', 20);
            $table->string('alert_status', 20)->default(SiatAlertStatus::Active->value);
            $table->char('condition_key', 64);
            $table->char('active_key', 64)->nullable()->unique();
            $table->string('title');
            $table->text('message');
            $table->unsignedInteger('condition_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestampTz('first_detected_at');
            $table->timestampTz('last_detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('panel_recorded_at')->nullable();
            $table->timestampTz('notification_queued_at')->nullable();
            $table->timestampTz('internal_notified_at')->nullable();
            $table->timestampTz('email_notified_at')->nullable();
            $table->timestampTz('technical_logged_at')->nullable();
            $table->unsignedSmallInteger('notification_attempts')->default(0);
            $table->timestampTz('notification_failed_at')->nullable();
            $table->text('notification_error')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'alert_status', 'severity'], 'sin_alerts_company_status_index');
            $table->index(['company_id', 'alert_type', 'resolved_at'], 'sin_alerts_company_type_index');
            $table->index(['sin_branch_id', 'sin_point_of_sale_id', 'alert_status'], 'sin_alerts_location_index');
            $table->index(['notification_failed_at', 'notification_attempts'], 'sin_alerts_retry_index');
        });

        DB::statement("ALTER TABLE sin_monitoring_alerts ADD CONSTRAINT sin_alerts_severity_check CHECK (severity IN ('INFO','WARNING','CRITICAL'))");
        DB::statement("ALTER TABLE sin_monitoring_alerts ADD CONSTRAINT sin_alerts_status_check CHECK (alert_status IN ('ACTIVE','RESOLVED'))");
        DB::statement('ALTER TABLE sin_monitoring_alerts ADD CONSTRAINT sin_alerts_resolution_check CHECK ((alert_status = \'ACTIVE\' AND resolved_at IS NULL AND active_key IS NOT NULL) OR (alert_status = \'RESOLVED\' AND resolved_at IS NOT NULL AND active_key IS NULL))');
        DB::statement('ALTER TABLE sin_monitoring_alerts ADD CONSTRAINT sin_alerts_count_positive_check CHECK (condition_count > 0)');
        DB::statement('ALTER TABLE sin_monitoring_alerts ADD CONSTRAINT sin_alerts_company_branch_foreign FOREIGN KEY (company_id, sin_branch_id) REFERENCES sin_branches(company_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sin_monitoring_alerts ADD CONSTRAINT sin_alerts_company_pos_foreign FOREIGN KEY (company_id, sin_point_of_sale_id) REFERENCES sin_points_of_sale(company_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::dropIfExists('sin_monitoring_alerts');
        Schema::dropIfExists('notifications');

        Schema::table('sin_authorizations', function (Blueprint $table): void {
            $table->dropIndex('sin_auth_certificate_expiry_index');
            $table->dropColumn('certificate_expires_at');
        });
    }
};
