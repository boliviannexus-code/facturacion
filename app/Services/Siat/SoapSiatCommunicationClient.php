<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatEnvironment;
use App\Models\SinApiToken;
use App\Services\Siat\Contracts\SiatCommunicationClient;
use RuntimeException;

final readonly class SoapSiatCommunicationClient implements SiatCommunicationClient
{
    public function __construct(private SiatSoapClientFactory $clients) {}

    public function verify(SinApiToken $configuration, int $timeoutSeconds, ?SiatEnvironment $environment = null): mixed
    {
        if (! extension_loaded('soap')) {
            throw new RuntimeException('SOAP extension no disponible en la configuracion local.');
        }

        $client = $this->clients->make(
            app(SiatWsdlResolver::class)->resolve((int) $configuration->company_id, $environment ?? app(SiatWsdlResolver::class)->currentEnvironment((int) $configuration->company_id), 'codes'),
            (string) $configuration->api_token,
            $timeoutSeconds,
        );

        return $client->verificarComunicacion();
    }
}
