<?php

namespace App\Services;

use App\Models\SinApiToken;
use App\Models\User;
use App\Services\Siat\SiatCredentialChangeService;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SinApiTokenService
{
    public function __construct(
        private readonly SiatCredentialChangeService $credentialChanges,
    ) {}

    public function current(): ?SinApiToken
    {
        return SinApiToken::query()->first();
    }

    public function save(User $user, array $data): SinApiToken
    {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'company' => 'Selecciona una empresa antes de registrar el token API.',
            ]);
        }

        $data = [
            ...$this->normalize($data),
            'company_id' => $companyId,
        ];

        try {
            return $this->persist($companyId, $data);
        } catch (QueryException $exception) {
            if ($this->isCompanyUniqueViolation($exception)) {
                return $this->persist($companyId, $data);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(int $companyId, array $data): SinApiToken
    {
        return DB::transaction(function () use ($companyId, $data): SinApiToken {
            $apiToken = SinApiToken::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if ($apiToken) {
                $apiToken->fill(Arr::except($data, ['company_id']));
                $tokenChanged = $apiToken->isDirty('api_token');
                $apiToken->save();

                if ($tokenChanged) {
                    $this->credentialChanges->invalidateCodes(
                        $companyId,
                        'Reemplazado al actualizar el token API.',
                    );
                }

                return $apiToken->refresh();
            }

            return SinApiToken::query()->create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('api_token', $data) && is_string($data['api_token'])) {
            $data['api_token'] = trim($data['api_token']);
        }

        if (array_key_exists('wsdl_url', $data) && is_string($data['wsdl_url'])) {
            $data['wsdl_url'] = trim($data['wsdl_url']);
        }

        if (($data['api_token'] ?? null) === null || $data['api_token'] === '') {
            unset($data['api_token']);
        }

        return $data;
    }

    private function isCompanyUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'sin_api_tokens_company_id_unique');
    }
}
