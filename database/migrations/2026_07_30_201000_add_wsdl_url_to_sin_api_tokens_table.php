<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_WSDL_URL = 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionCodigos?wsdl';

    public function up(): void
    {
        Schema::table('sin_api_tokens', function (Blueprint $table): void {
            $table->string('wsdl_url', 2048)
                ->default(self::DEFAULT_WSDL_URL);
        });

        DB::statement(
            'ALTER TABLE sin_api_tokens
            ADD CONSTRAINT sin_api_tokens_wsdl_url_not_blank_check
            CHECK (length(trim(wsdl_url)) > 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sin_api_tokens DROP CONSTRAINT IF EXISTS sin_api_tokens_wsdl_url_not_blank_check');

        Schema::table('sin_api_tokens', function (Blueprint $table): void {
            $table->dropColumn('wsdl_url');
        });
    }
};
