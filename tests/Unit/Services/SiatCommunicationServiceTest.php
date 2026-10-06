<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiatErrorType;
use App\Models\SinApiToken;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatContingencyPolicy;
use App\Services\Siat\SiatErrorClassifier;
use App\Services\Siat\SiatLogSanitizer;
use App\Services\Siat\SiatRetryPolicy;
use App\Services\Siat\SiatWsdlRegistry;
use RuntimeException;
use Tests\Fakes\RecordingSiatDelay;
use Tests\Fakes\SequenceSiatCommunicationClient;
use Tests\TestCase;

final class SiatCommunicationServiceTest extends TestCase
{
    public function test_successful_communication_returns_on_first_attempt(): void
    {
        [$service, $client, $delay] = $this->service([
            ['RespuestaComunicacion' => ['transaccion' => true]],
        ]);

        $result = $service->verify($this->token());

        self::assertTrue($result->available);
        self::assertSame(SiatErrorType::Available, $result->errorType);
        self::assertSame(1, $result->attempts);
        self::assertFalse($result->shouldOpenContingency);
        self::assertSame(1, $client->calls);
        self::assertSame([], $delay->waits);
    }

    public function test_isolated_timeout_does_not_open_contingency(): void
    {
        [$service] = $this->service(
            [new RuntimeException('SOAP operation timed out')],
            delays: [0],
        );

        $result = $service->verify($this->token());

        self::assertSame(SiatErrorType::Timeout, $result->errorType);
        self::assertSame(1, $result->attempts);
        self::assertFalse($result->shouldOpenContingency);
    }

    public function test_service_recovers_after_an_isolated_timeout_without_opening_contingency(): void
    {
        [$service, $client, $delay] = $this->service([
            new RuntimeException('SOAP operation timed out'),
            ['transaccion' => true],
        ]);

        $result = $service->verify($this->token());

        self::assertTrue($result->available);
        self::assertSame(2, $result->attempts);
        self::assertFalse($result->shouldOpenContingency);
        self::assertSame(2, $client->calls);
        self::assertSame([2], $delay->waits);
    }

    public function test_repeated_internet_outage_retries_and_recommends_contingency(): void
    {
        [$service, $client, $delay] = $this->service([
            new RuntimeException('Network is unreachable'),
            new RuntimeException('No route to host'),
            new RuntimeException('No internet connection'),
        ]);

        $result = $service->verify($this->token());

        self::assertSame(SiatErrorType::NoInternet, $result->errorType);
        self::assertSame(3, $result->attempts);
        self::assertTrue($result->shouldOpenContingency);
        self::assertSame(3, $client->calls);
        self::assertSame([2, 5], $delay->waits);
    }

    public function test_xml_rejection_does_not_retry_or_open_contingency(): void
    {
        [$service, $client] = $this->service([
            ['transaccion' => false, 'mensajesList' => [['descripcion' => 'XML invalido: schema validation']]],
        ]);

        $result = $service->verify($this->token());

        self::assertSame(SiatErrorType::InvalidXml, $result->errorType);
        self::assertSame(1, $client->calls);
        self::assertFalse($result->shouldOpenContingency);
    }

    public function test_invalid_token_does_not_call_client_or_open_contingency(): void
    {
        [$service, $client] = $this->service([
            ['transaccion' => true],
        ]);
        $token = $this->token();
        $token->ends_at = now()->subDay()->toDateString();

        $result = $service->verify($token);

        self::assertSame(SiatErrorType::InvalidToken, $result->errorType);
        self::assertSame(0, $client->calls);
        self::assertFalse($result->shouldOpenContingency);
    }

    public function test_expired_certificate_does_not_retry_or_open_contingency(): void
    {
        [$service, $client] = $this->service([
            new RuntimeException('Certificate has expired'),
        ]);

        $result = $service->verify($this->token());

        self::assertSame(SiatErrorType::ExpiredCertificate, $result->errorType);
        self::assertSame(1, $client->calls);
        self::assertFalse($result->shouldOpenContingency);
    }

    public function test_unknown_error_is_classified_without_retrying(): void
    {
        [$service, $client] = $this->service([
            new RuntimeException('Unexpected internal state'),
        ]);

        $result = $service->verify($this->token());

        self::assertSame(SiatErrorType::Unknown, $result->errorType);
        self::assertSame(1, $client->calls);
        self::assertFalse($result->shouldOpenContingency);
    }

    /**
     * @param  array<int, mixed>  $responses
     * @param  array<int, int>  $delays
     * @return array{SiatCommunicationService, SequenceSiatCommunicationClient, RecordingSiatDelay}
     */
    private function service(array $responses, array $delays = [0, 2, 5]): array
    {
        $client = new SequenceSiatCommunicationClient($responses);
        $delay = new RecordingSiatDelay;

        return [
            new SiatCommunicationService(
                client: $client,
                classifier: new SiatErrorClassifier,
                retryPolicy: new SiatRetryPolicy($delays, 5),
                contingencyPolicy: new SiatContingencyPolicy(3),
                delay: $delay,
                sanitizer: new SiatLogSanitizer,
            ),
            $client,
            $delay,
        ];
    }

    private function token(): SinApiToken
    {
        return new SinApiToken([
            'api_token' => 'SECRET-TOKEN-123',
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);
    }
}
