<?php

namespace App\Services\Siat;

use App\Models\SinBranch;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SinBranchService
{
    public function branches(): mixed
    {
        return SinBranch::query()
            ->with(['pointsOfSale' => fn ($query) => $query->orderBy('point_of_sale_code')])
            ->orderBy('branch_code')
            ->get();
    }

    public function activePointOptions(): mixed
    {
        return SinPointOfSale::query()
            ->with('branch')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->get()
            ->sortBy([
                fn (SinPointOfSale $left, SinPointOfSale $right): int => $left->branch->branch_code <=> $right->branch->branch_code,
                fn (SinPointOfSale $left, SinPointOfSale $right): int => $left->point_of_sale_code <=> $right->point_of_sale_code,
            ]);
    }

    public function createBranch(User $user, array $data): SinBranch
    {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'company' => 'Selecciona una empresa antes de registrar sucursales.',
            ]);
        }

        return DB::transaction(function () use ($companyId, $data): SinBranch {
            $branch = SinBranch::query()->create([
                'company_id' => $companyId,
                'branch_code' => (int) $data['branch_code'],
                'name' => trim((string) $data['name']),
                'is_main' => (bool) ($data['is_main'] ?? false),
                'is_active' => true,
            ]);

            $this->createDefaultPoint($branch);

            return $branch->refresh();
        });
    }

    private function createDefaultPoint(SinBranch $branch): SinPointOfSale
    {
        return SinPointOfSale::query()->create([
            'company_id' => $branch->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 0,
            'name' => 'Punto de venta 0',
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
