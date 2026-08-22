<?php

namespace App\Services\Parameters;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\SinAuthorization;
use App\Models\User;
use App\Services\Siat\SiatCredentialChangeService;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SinAuthorizationService
{
    public function __construct(
        private readonly SiatCredentialChangeService $credentialChanges,
    ) {}

    public function current(): ?SinAuthorization
    {
        return SinAuthorization::query()->first();
    }

    /**
     * @return array{environments: array<int, string>, modalities: array<int, string>}
     */
    public function formOptions(): array
    {
        return [
            'environments' => SiatEnvironment::options(),
            'modalities' => SiatModality::options(),
        ];
    }

    public function save(User $user, array $data): SinAuthorization
    {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'company' => 'Selecciona una empresa antes de configurar la autorizacion SIN.',
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
    private function persist(int $companyId, array $data): SinAuthorization
    {
        return DB::transaction(function () use ($companyId, $data): SinAuthorization {
            $authorization = SinAuthorization::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if ($authorization) {
                $authorization->fill(Arr::except($data, ['company_id']));
                $siatConfigurationChanged = $authorization->isDirty([
                    'tax_id',
                    'system_code',
                    'environment_code',
                    'modality_code',
                ]);
                $authorization->save();

                if ($siatConfigurationChanged) {
                    $this->credentialChanges->invalidateCodes(
                        $companyId,
                        'Reemplazado al actualizar los parámetros de autorización SIAT.',
                    );
                }

                return $authorization->refresh();
            }

            return SinAuthorization::query()->create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['tax_id', 'legal_name', 'system_code'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        foreach (['environment_code', 'modality_code', 'branch_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (int) $data[$field];
            }
        }

        if (array_key_exists('point_of_sale_code', $data)) {
            $data['point_of_sale_code'] = $data['point_of_sale_code'] === null
                ? null
                : (int) $data['point_of_sale_code'];
        }

        if (array_key_exists('force_offline_emission', $data)) {
            $data['force_offline_emission'] = (bool) $data['force_offline_emission'];
        }

        if (array_key_exists('certificate_expires_at', $data) && blank($data['certificate_expires_at'])) {
            $data['certificate_expires_at'] = null;
        }

        if (($data['system_code'] ?? null) === null || $data['system_code'] === '') {
            unset($data['system_code']);
        }

        return $data;
    }

    private function isCompanyUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'sin_authorizations_company_id_unique');
    }
}
