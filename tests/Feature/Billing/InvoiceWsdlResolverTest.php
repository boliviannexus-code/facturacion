<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\SiatEnvironment;
use App\Models\Company;
use App\Models\SinWsdlService;
use App\Services\Billing\InvoiceWsdlResolver;
use App\Services\Siat\SiatWsdlRegistry;
use App\Services\Siat\SiatWsdlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InvoiceWsdlResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_explicit_company_and_environment_without_authentication_and_preserves_sfd(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        foreach ([$a, $b] as $company) {
            SiatWsdlRegistry::initializeCompany($company->id);
        }
        SinWsdlService::withoutGlobalScope('company')->where('company_id', $a->id)->where('environment_code', 1)
            ->where('key', 'zero_rate_invoice')->update(['is_active' => true]);
        SinWsdlService::withoutGlobalScope('company')->where('company_id', $b->id)->where('environment_code', 1)
            ->where('key', 'zero_rate_invoice')->update(['is_active' => true, 'url' => 'https://siatrest.impuestos.gob.bo/sfd/ServicioFacturacionComputarizada?wsdl']);

        $resolver = app(InvoiceWsdlResolver::class);
        self::assertSame(SiatWsdlRegistry::ZERO_RATE_INVOICE, $resolver->resolve(8, $a->id, SiatEnvironment::TestingAndPilot));
        self::assertSame('https://siatrest.impuestos.gob.bo/v2/ServicioFacturacionComputarizada?wsdl', $resolver->resolve(8, $a->id, SiatEnvironment::Production));
        self::assertSame('https://siatrest.impuestos.gob.bo/sfd/ServicioFacturacionComputarizada?wsdl', $resolver->resolve(8, $b->id, SiatEnvironment::Production));
    }

    public function test_missing_inactive_unclassified_and_mismatched_services_never_fall_back(): void
    {
        $company = Company::factory()->create();
        $resolver = app(SiatWsdlResolver::class);
        foreach ([null, 2, 1] as $environment) {
            SinWsdlService::withoutGlobalScope('company')->forceDelete();
            if ($environment !== null) {
                SinWsdlService::create([
                    'company_id' => $company->id, 'environment_code' => $environment,
                    'key' => 'codes', 'name' => 'Códigos', 'url' => SiatWsdlRegistry::CODES, 'is_active' => true,
                ]);
            }
            try {
                $resolver->resolve($company->id, SiatEnvironment::Production, 'codes');
                self::fail('No debe utilizar piloto ni un host incompatible.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('wsdl_url', $exception->errors());
            }
        }
        SinWsdlService::withoutGlobalScope('company')->update(['url' => 'https://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl', 'is_active' => false]);
        $this->expectException(ValidationException::class);
        $resolver->resolve($company->id, SiatEnvironment::Production, 'codes');
    }

    public function test_initialization_never_reactivates_deleted_or_disabled_services(): void
    {
        $company = Company::factory()->create();
        SiatWsdlRegistry::initializeCompany($company->id);
        $service = SinWsdlService::where('company_id', $company->id)->where('environment_code', 2)->where('key', 'codes')->firstOrFail();
        $service->delete();
        SiatWsdlRegistry::initializeCompany($company->id);
        self::assertFalse(SinWsdlService::where('company_id', $company->id)->where('environment_code', 2)->where('key', 'codes')->exists());
        self::assertFalse(SinWsdlService::where('company_id', $company->id)->where('environment_code', 1)->where('is_active', true)->exists());
    }
}
