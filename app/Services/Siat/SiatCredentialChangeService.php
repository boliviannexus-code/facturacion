<?php

namespace App\Services\Siat;

use App\Models\SinCufd;
use App\Models\SinCuis;

class SiatCredentialChangeService
{
    public function invalidateCodes(int $companyId, string $reason): void
    {
        $values = [
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
            'updated_at' => now(),
        ];

        SinCufd::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereNull('invalidated_at')
            ->update($values);

        SinCuis::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->whereNull('invalidated_at')
            ->update($values);
    }
}
