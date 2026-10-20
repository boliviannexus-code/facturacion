<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'users_company_id_id_unique');
        });

        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->decimal('opening_amount', 18, 2);
            $table->decimal('closing_amount', 18, 2)->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['company_id', 'user_id'], 'cash_registers_company_user_foreign')
                ->references(['company_id', 'id'])
                ->on('users')
                ->restrictOnDelete();

            $table->index(['company_id', 'opened_at'], 'cash_registers_company_opened_index');
            $table->index(['company_id', 'user_id', 'opened_at'], 'cash_registers_company_user_opened_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX cash_registers_one_active_per_user_unique
             ON cash_registers (user_id)
             WHERE closed_at IS NULL'
        );

        DB::statement(
            'ALTER TABLE cash_registers
             ADD CONSTRAINT cash_registers_amounts_nonnegative_check
             CHECK (
                opening_amount >= 0
                AND (closing_amount IS NULL OR closing_amount >= 0)
             )'
        );

        DB::statement(
            'ALTER TABLE cash_registers
             ADD CONSTRAINT cash_registers_closure_consistency_check
             CHECK (
                (closed_at IS NULL AND closing_amount IS NULL)
                OR (closed_at IS NOT NULL AND closing_amount IS NOT NULL)
             )'
        );

        DB::statement(
            'ALTER TABLE cash_registers
             ADD CONSTRAINT cash_registers_chronology_check
             CHECK (closed_at IS NULL OR closed_at >= opened_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_company_id_id_unique');
        });
    }
};
