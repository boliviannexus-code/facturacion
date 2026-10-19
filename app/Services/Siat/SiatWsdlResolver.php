<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatEnvironment;
use App\Models\SinAuthorization;
use App\Models\SinWsdlService;
use Illuminate\Validation\ValidationException;

class SiatWsdlResolver
{
    public function resolve(int $companyId, SiatEnvironment $environment, string $key): string
    {
        $url = SinWsdlService::withoutGlobalScope('company')
            ->where('company_id', $companyId)->where('environment_code', $environment->value)
            ->where('key', $key)->where('is_active', true)->value('url');

        if (! is_string($url) || ! SiatWsdlRegistry::validUrl(trim($url), $environment->value)) {
            throw ValidationException::withMessages([
                'wsdl_url' => "Configura y activa el servicio WSDL {$key} para la empresa {$companyId} en {$environment->label()}.",
            ]);
        }

        return trim($url);
    }

    public function currentEnvironment(int $companyId): SiatEnvironment
    {
        $authorization = SinAuthorization::withoutGlobalScope('company')->where('company_id', $companyId)->first();
        if (! $authorization) {
            throw ValidationException::withMessages(['authorization' => 'Configura la autorización SIN de la empresa.']);
        }

        return $authorization->environment_code;
    }

    public function qrBaseUrl(SiatEnvironment $environment): string
    {
        return $environment === SiatEnvironment::Production
            ? 'https://siat.impuestos.gob.bo/consulta/QR'
            : 'https://pilotosiat.impuestos.gob.bo/consulta/QR';
    }
}
