<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiatErrorType;
use App\Jobs\VerifySiatCommunicationJob;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinCommunicationLog;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\Contracts\SiatCommunicationClient;
use App\Services\Siat\Contracts\SiatDelay;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatRetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fakes\RecordingSiatDelay;
use Tests\Fakes\SequenceSiatCommunicationClient;
use Tests\TestCase;

final class SiatCommunicationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_persists_duration_and_multi_company_context(): void
    {
        [$token, $pointOfSale, $user] = $this->context();
        $this->useSimulation([
            ['RespuestaComunicacion' => ['transaccion' => true]],
        ]);

        $result = app(SiatCommunicationService::class)->verify($token, $pointOfSale, $user);

        self::assertTrue($result->available);
        $this->assertDatabaseHas('sin_communication_logs', [
            'company_id' => $token->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_api_token_id' => $token->id,
            'user_id' => $user->id,
            'error_type' => SiatErrorType::Available->value,
            'attempt_count' => 1,
            'contingency_recommended' => false,
        ]);

        $log = SinCommunicationLog::query()->withoutGlobalScope('company')->firstOrFail();
        self::assertGreaterThanOrEqual(0, $log->duration_ms);
        self::assertGreaterThanOrEqual(0, $log->last_request_duration_ms);
    }

    public function test_retries_are_finite_and_sensitive_values_are_redacted_in_log(): void
    {
        [$token, $pointOfSale, $user] = $this->context();
        $secret = (string) $token->api_token;
        $client = $this->useSimulation([
            new RuntimeException("Network is unreachable token={$secret}"),
            new RuntimeException("Network is unreachable token={$secret}"),
            new RuntimeException("Network is unreachable token={$secret}"),
        ]);

        $result = app(SiatCommunicationService::class)->verify($token, $pointOfSale, $user);

        self::assertSame(3, $client->calls);
        self::assertTrue($result->shouldOpenContingency);

        $log = SinCommunicationLog::query()->withoutGlobalScope('company')->firstOrFail();
        self::assertSame(3, $log->attempt_count);
        self::assertTrue($log->was_retried);
        self::assertTrue($log->contingency_recommended);
        self::assertStringNotContainsString($secret, (string) $log->technical_message);
        self::assertStringContainsString('[REDACTADO]', (string) $log->technical_message);
    }

    public function test_job_uses_the_injected_client_and_never_requires_real_siat_in_tests(): void
    {
        [$token, $pointOfSale, $user] = $this->context();
        $client = $this->useSimulation([
            ['transaccion' => true],
        ]);
        $job = new VerifySiatCommunicationJob(
            companyId: (int) $token->company_id,
            apiTokenId: (int) $token->id,
            pointOfSaleId: (int) $pointOfSale->id,
            userId: (int) $user->id,
        );

        $job->handle(app(SiatCommunicationService::class));

        self::assertSame(1, $client->calls);
        $this->assertDatabaseCount('sin_communication_logs', 1);
    }

    /** @return array{SinApiToken, SinPointOfSale, User} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $pointOfSale = SinPointOfSale::factory()->create(['company_id' => $company->id]);
        $token = SinApiToken::factory()->create(['company_id' => $company->id]);

        return [$token, $pointOfSale, $user];
    }

    /** @param array<int, mixed> $responses */
    private function useSimulation(array $responses): SequenceSiatCommunicationClient
    {
        $client = new SequenceSiatCommunicationClient($responses);
        $this->app->instance(SiatCommunicationClient::class, $client);
        $this->app->instance(SiatDelay::class, new RecordingSiatDelay);
        $this->app->instance(SiatRetryPolicy::class, new SiatRetryPolicy([0, 2, 5], 1));

        return $client;
    }
}
