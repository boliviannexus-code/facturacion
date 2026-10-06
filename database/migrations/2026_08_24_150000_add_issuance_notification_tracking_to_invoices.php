<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->timestampTz('issuance_notified_at')->nullable();
            $table->string('issuance_notification_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sin_invoice_issues', function (Blueprint $table): void {
            $table->dropColumn(['issuance_notified_at', 'issuance_notification_error']);
        });
    }
};
