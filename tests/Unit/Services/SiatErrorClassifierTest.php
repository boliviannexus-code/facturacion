<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiatErrorType;
use App\Services\Siat\SiatErrorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class SiatErrorClassifierTest extends TestCase
{
    public function test_wsdl_load_failure_is_classified_as_siat_unavailable(): void
    {
        $classifier = new SiatErrorClassifier;

        $result = $classifier->classify(new RuntimeException(
            "SOAP-ERROR: Parsing WSDL: Couldn't load from 'https://siat.example/wsdl': failed to load external entity"
        ));

        self::assertSame(SiatErrorType::SiatUnavailable, $result);
    }

    #[DataProvider('technicalFailures')]
    public function test_it_classifies_technical_failures(string $message, SiatErrorType $expected): void
    {
        self::assertSame($expected, (new SiatErrorClassifier)->classify(new RuntimeException($message)));
    }

    /** @return array<string, array{string, SiatErrorType}> */
    public static function technicalFailures(): array
    {
        return [
            'timeout' => ['SOAP operation timed out after 30 seconds', SiatErrorType::Timeout],
            'dns' => ['Could not resolve host: pilotosiat.impuestos.gob.bo', SiatErrorType::DnsUnavailable],
            'internet' => ['Network is unreachable', SiatErrorType::NoInternet],
            'service unavailable' => ['HTTP 503 Service Unavailable', SiatErrorType::SiatUnavailable],
            'invalid http' => ['HTTP 418 malformed response', SiatErrorType::InvalidHttpResponse],
            'token' => ['Invalid token supplied', SiatErrorType::InvalidToken],
            'cuis' => ['Invalid CUIS supplied', SiatErrorType::InvalidCuis],
            'cufd' => ['CUFD vencido', SiatErrorType::InvalidCufd],
            'certificate' => ['Certificate has expired', SiatErrorType::ExpiredCertificate],
            'xml' => ['XML rechazado por schema validation', SiatErrorType::InvalidXml],
            'catalog' => ['Catalog error during synchronization', SiatErrorType::CatalogError],
            'authentication' => ['Authentication failed', SiatErrorType::AuthenticationError],
            'local configuration' => ['SOAP extension is missing', SiatErrorType::LocalConfiguration],
            'unknown' => ['Undefined internal operation', SiatErrorType::Unknown],
        ];
    }

    public function test_it_classifies_database_errors(): void
    {
        $exception = new QueryException(
            'pgsql',
            'select 1',
            [],
            new PDOException('Database unavailable'),
        );

        self::assertSame(SiatErrorType::Database, (new SiatErrorClassifier)->classify($exception));
    }

    public function test_it_classifies_known_validation_failures(): void
    {
        self::assertSame(SiatErrorType::InvalidXml, $this->validation('xml'));
        self::assertSame(SiatErrorType::InvalidToken, $this->validation('api_token'));
        self::assertSame(SiatErrorType::InvalidCufd, $this->validation('cufd'));
        self::assertSame(SiatErrorType::InvalidCuis, $this->validation('cuis'));
    }

    public function test_only_technical_connectivity_errors_are_eligible_for_contingency(): void
    {
        self::assertTrue(SiatErrorType::Timeout->canOpenContingencyAfterRetries());
        self::assertTrue(SiatErrorType::SiatUnavailable->canOpenContingencyAfterRetries());
        self::assertFalse(SiatErrorType::InvalidXml->canOpenContingencyAfterRetries());
        self::assertFalse(SiatErrorType::InvalidToken->canOpenContingencyAfterRetries());
        self::assertFalse(SiatErrorType::ExpiredCertificate->canOpenContingencyAfterRetries());
        self::assertFalse(SiatErrorType::Database->canOpenContingencyAfterRetries());
    }

    private function validation(string $field): SiatErrorType
    {
        return (new SiatErrorClassifier)->classify(
            ValidationException::withMessages([$field => 'Dato invalido.']),
        );
    }
}
