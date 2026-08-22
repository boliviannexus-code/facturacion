<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatCommunicationOutcome;
use App\Enums\SiatErrorType;
use App\Enums\SiatOperation;
use App\Models\SinApiToken;
use App\Models\SinCommunicationLog;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\Contracts\SiatCommunicationClient;
use App\Services\Siat\Contracts\SiatDelay;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Throwable;

class SiatCommunicationService
{
    public function __construct(
        private readonly SiatCommunicationClient $client,
        private readonly SiatErrorClassifier $classifier,
        private readonly SiatRetryPolicy $retryPolicy,
        private readonly SiatContingencyPolicy $contingencyPolicy,
        private readonly SiatDelay $delay,
        private readonly SiatLogSanitizer $sanitizer,
    ) {}

    public function verify(
        SinApiToken $configuration,
        ?SinPointOfSale $pointOfSale = null,
        ?User $user = null,
    ): SiatHealthCheckResult|SiatCommunicationResult {
        $startedAt = microtime(true);
        $knownToken = $this->tokenValue($configuration);

        if ($configuration->status_label !== 'Vigente') {
            return $this->finish(
                configuration: $configuration,
                pointOfSale: $pointOfSale,
                user: $user,
                errorType: SiatErrorType::InvalidToken,
                technicalMessage: "Token API {$configuration->status_label}.",
                response: null,
                attempts: 1,
                requestDurationMs: 0,
                attemptErrors: [SiatErrorType::InvalidToken],
                startedAt: $startedAt,
                knownToken: $knownToken,
            );
        }

        if ($pointOfSale !== null && (int) $pointOfSale->company_id !== (int) $configuration->company_id) {
            return $this->finish(
                configuration: $configuration,
                pointOfSale: null,
                user: $user,
                errorType: SiatErrorType::LocalConfiguration,
                technicalMessage: 'El punto de venta y el token pertenecen a empresas diferentes.',
                response: null,
                attempts: 1,
                requestDurationMs: 0,
                attemptErrors: [SiatErrorType::LocalConfiguration],
                startedAt: $startedAt,
                knownToken: $knownToken,
            );
        }

        $attemptErrors = [];
        $attempts = 0;
        $requestDurationMs = 0;
        $response = null;
        $technicalMessage = '';
        $errorType = SiatErrorType::Unknown;

        foreach ($this->retryPolicy->delays() as $delaySeconds) {
            if ($delaySeconds > 0) {
                $this->delay->wait($delaySeconds);
            }

            $attempts++;
            $requestStartedAt = microtime(true);

            try {
                $response = $this->normalizeResponse(
                    $this->client->verify($configuration, $this->retryPolicy->timeoutSeconds),
                );
                $requestDurationMs = $this->durationMs($requestStartedAt);
                $transaction = $this->findTransaction($response);

                if ($transaction === true) {
                    $errorType = SiatErrorType::Available;
                    $technicalMessage = 'verificarComunicacion devolvio transaccion=true.';

                    break;
                }

                $errorType = $transaction === null
                    ? SiatErrorType::InvalidHttpResponse
                    : $this->classifier->classifyResponse($response);
                $technicalMessage = $this->responseMessage($response);
            } catch (Throwable $exception) {
                $requestDurationMs = $this->durationMs($requestStartedAt);
                $errorType = $this->classifier->classify($exception);
                $technicalMessage = $exception::class.': '.$exception->getMessage();
            }

            $attemptErrors[] = $errorType;

            if (! $this->retryPolicy->shouldRetry($errorType, $attempts)) {
                break;
            }
        }

        return $this->finish(
            configuration: $configuration,
            pointOfSale: $pointOfSale,
            user: $user,
            errorType: $errorType,
            technicalMessage: $technicalMessage,
            response: $response,
            attempts: max(1, $attempts),
            requestDurationMs: $requestDurationMs,
            attemptErrors: $attemptErrors,
            startedAt: $startedAt,
            knownToken: $knownToken,
        );
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @param  array<int, SiatErrorType>  $attemptErrors
     */
    private function finish(
        SinApiToken $configuration,
        ?SinPointOfSale $pointOfSale,
        ?User $user,
        SiatErrorType $errorType,
        string $technicalMessage,
        ?array $response,
        int $attempts,
        int $requestDurationMs,
        array $attemptErrors,
        float $startedAt,
        string $knownToken,
    ): SiatHealthCheckResult {
        $sanitizedResponse = $this->sanitizer->data($response, $knownToken);
        $sanitizedTechnicalMessage = $this->sanitizer->text($technicalMessage, $knownToken) ?? '';
        $userMessage = $this->classifier->userMessage($errorType);
        $contingencyRecommended = $errorType !== SiatErrorType::Available
            && $this->contingencyPolicy->shouldOpen($attemptErrors);
        $checkedAt = Carbon::now();
        $result = new SiatHealthCheckResult(
            available: $errorType === SiatErrorType::Available,
            errorType: $errorType,
            userMessage: $userMessage,
            technicalMessage: $sanitizedTechnicalMessage,
            operation: 'verificarComunicacion',
            wsdlUrl: $this->sanitizer->text((string) $configuration->wsdl_url, $knownToken) ?? '',
            durationMs: $this->durationMs($startedAt),
            requestDurationMs: $requestDurationMs,
            attempts: $attempts,
            shouldOpenContingency: $contingencyRecommended,
            checkedAt: $checkedAt->format('d/m/Y H:i:s'),
            response: $sanitizedResponse,
        );

        if ($configuration->exists && $configuration->company_id !== null) {
            try {
                $this->storeLog($configuration, $pointOfSale, $user, $result, $checkedAt);
            } catch (QueryException $exception) {
                return new SiatHealthCheckResult(
                    available: false,
                    errorType: SiatErrorType::Database,
                    userMessage: $this->classifier->userMessage(SiatErrorType::Database),
                    technicalMessage: $this->sanitizer->text(
                        $exception::class.': '.$exception->getMessage(),
                        $knownToken,
                    ) ?? '',
                    operation: $result->operation,
                    wsdlUrl: $result->wsdlUrl,
                    durationMs: $this->durationMs($startedAt),
                    requestDurationMs: $result->requestDurationMs,
                    attempts: $result->attempts,
                    shouldOpenContingency: false,
                    checkedAt: $result->checkedAt,
                    response: null,
                );
            }
        }

        return $result;
    }

    private function storeLog(
        SinApiToken $configuration,
        ?SinPointOfSale $pointOfSale,
        ?User $user,
        SiatHealthCheckResult $result,
        Carbon $checkedAt,
    ): void {
        $companyId = (int) $configuration->company_id;
        $safeUserId = $user !== null && (int) $user->company_id === $companyId ? $user->getKey() : null;

        SinCommunicationLog::query()->create([
            'company_id' => $companyId,
            'sin_branch_id' => $pointOfSale?->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale?->getKey(),
            'sin_api_token_id' => $configuration->getKey(),
            'user_id' => $safeUserId,
            'operation' => SiatOperation::VerifyCommunication,
            'outcome' => $this->outcome($result->errorType),
            'error_type' => $result->errorType,
            'failure_category' => $result->errorType->failureCategory(),
            'endpoint' => $this->sanitizer->text($result->wsdlUrl),
            'attempt_count' => $result->attempts,
            'duration_ms' => $result->durationMs,
            'last_request_duration_ms' => $result->requestDurationMs,
            'was_retried' => $result->attempts > 1,
            'contingency_recommended' => $result->shouldOpenContingency,
            'message' => $result->userMessage,
            'technical_message' => $result->technicalMessage,
            'user_message' => $result->userMessage,
            'response' => $result->response,
            'checked_at' => $checkedAt,
        ]);
    }

    private function outcome(SiatErrorType $errorType): SiatCommunicationOutcome
    {
        return match ($errorType) {
            SiatErrorType::Available => SiatCommunicationOutcome::Available,
            SiatErrorType::Timeout => SiatCommunicationOutcome::Timeout,
            SiatErrorType::InvalidToken,
            SiatErrorType::InvalidCuis,
            SiatErrorType::InvalidCufd,
            SiatErrorType::ExpiredCertificate,
            SiatErrorType::AuthenticationError,
            SiatErrorType::LocalConfiguration => SiatCommunicationOutcome::InvalidConfiguration,
            SiatErrorType::NoInternet,
            SiatErrorType::DnsUnavailable,
            SiatErrorType::SiatUnavailable,
            SiatErrorType::InvalidHttpResponse => SiatCommunicationOutcome::Unavailable,
            default => SiatCommunicationOutcome::Error,
        };
    }

    /** @return array<string, mixed> */
    private function normalizeResponse(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        $json = json_encode($response);
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : ['value' => $response];
    }

    /** @param array<string, mixed> $data */
    private function findTransaction(array $data): ?bool
    {
        foreach ($data as $key => $value) {
            if (mb_strtolower((string) $key) === 'transaccion') {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (is_array($value) && ($transaction = $this->findTransaction($value)) !== null) {
                return $transaction;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $response */
    private function responseMessage(array $response): string
    {
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'SIAT devolvio una respuesta no serializable.';
    }

    private function tokenValue(SinApiToken $configuration): string
    {
        try {
            return (string) $configuration->api_token;
        } catch (Throwable) {
            return '';
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
