<?php

namespace App\Services;

use App\Models\Company;
use App\Services\Images\ImageStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CompanyService
{
    public function __construct(
        private readonly ImageStorageService $images,
    ) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Company::query()
            ->withCount('users')
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $data): Company
    {
        $data = $this->normalize($data, true);

        if (($data['logo'] ?? null) instanceof UploadedFile) {
            $data['logo_path'] = $this->images->store($data['logo'], 'companies/logos', 1200, 1200);
        }

        unset($data['logo'], $data['remove_logo']);

        return Company::query()->create($data);
    }

    public function update(Company $company, array $data): Company
    {
        $data = $this->normalize($data);
        $newLogoPath = null;

        if (($data['logo'] ?? null) instanceof UploadedFile) {
            $newLogoPath = $this->images->store($data['logo'], 'companies/logos', 1200, 1200);
        }

        if ($newLogoPath !== null) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }

            $data['logo_path'] = $newLogoPath;
        } elseif (! empty($data['remove_logo']) && $company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $data['logo_path'] = null;
        }

        unset($data['logo'], $data['remove_logo']);

        $company->update($data);

        return $company->refresh();
    }

    public function delete(Company $company): bool
    {
        if ($company->cashRegisters()->exists()) {
            throw ValidationException::withMessages([
                'company' => 'No puedes eliminar una empresa que tiene historial de cajas.',
            ]);
        }

        if (
            $company->productCategories()->exists()
            || $company->customers()->exists()
            || $company->products()->exists()
        ) {
            throw ValidationException::withMessages([
                'company' => 'No puedes eliminar una empresa que tiene productos o parametros registrados.',
            ]);
        }

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }

        return (bool) $company->delete();
    }

    private function normalize(array $data, ?bool $defaultActive = null): array
    {
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        } elseif ($defaultActive !== null) {
            $data['is_active'] = $defaultActive;
        }

        return $data;
    }
}
