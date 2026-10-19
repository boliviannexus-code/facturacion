<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SiatWsdlMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_classifies_existing_urls_without_replacing_them_or_activating_production(): void
    {
        $schema = 'wsdl_migration_'.bin2hex(random_bytes(4));
        $original = DB::selectOne('SHOW search_path')->search_path;
        DB::statement("CREATE SCHEMA {$schema}");
        try {
            DB::select("SELECT set_config('search_path', ?, true)", [$schema]);
            DB::statement('CREATE TABLE companies (id BIGINT PRIMARY KEY)');
            DB::table('companies')->insert(['id' => 1]);
            (require database_path('migrations/2026_08_10_130000_create_sin_wsdl_services_table.php'))->up();
            $urls = [
                'codes' => 'https://pilotosiatservicios.impuestos.gob.bo/sfd/FacturacionCodigos?wsdl',
                'operations' => 'https://siatrest.impuestos.gob.bo/sfd/FacturacionOperaciones?wsdl',
                'synchronization' => 'https://legacy.example.test/catalog?wsdl',
            ];
            foreach ($urls as $key => $url) {
                DB::table('sin_wsdl_services')->insert(['company_id' => 1, 'key' => $key, 'name' => $key, 'url' => $url, 'is_active' => true]);
            }
            (require database_path('migrations/2026_10_19_120000_scope_siat_wsdl_services_by_environment.php'))->up();
            foreach ($urls as $key => $url) {
                $row = DB::table('sin_wsdl_services')->where('key', $key)->where('url', $url)->first();
                self::assertSame($url, $row->url);
                self::assertTrue($row->is_active);
            }
            self::assertSame(2, DB::table('sin_wsdl_services')->where('url', $urls['codes'])->value('environment_code'));
            self::assertSame(1, DB::table('sin_wsdl_services')->where('url', $urls['operations'])->value('environment_code'));
            self::assertNull(DB::table('sin_wsdl_services')->where('url', $urls['synchronization'])->value('environment_code'));
            $template = DB::table('sin_wsdl_services')->where('environment_code', 1)->where('key', 'zero_rate_invoice')->first();
            self::assertFalse($template->is_active);
            self::assertSame('https://siatrest.impuestos.gob.bo/v2/ServicioFacturacionComputarizada?wsdl', $template->url);
        } finally {
            DB::select("SELECT set_config('search_path', ?, true)", [$original]);
            DB::statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
}
