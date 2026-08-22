<?php

namespace App\Services\Siat;

class SiatSoapClientFactory
{
    public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
    {
        $context = stream_context_create([
            'http' => [
                'header' => "apikey: TokenApi {$apiToken}",
                'timeout' => $timeoutSeconds,
            ],
        ]);

        return new \SoapClient($wsdlUrl, [
            'stream_context' => $context,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
        ]);
    }
}
