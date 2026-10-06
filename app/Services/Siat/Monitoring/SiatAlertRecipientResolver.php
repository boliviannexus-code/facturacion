<?php

declare(strict_types=1);

namespace App\Services\Siat\Monitoring;

use App\Models\SinMonitoringAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class SiatAlertRecipientResolver
{
    /** @return Collection<int, User> */
    public function recipients(SinMonitoringAlert $alert): Collection
    {
        $roles = array_values(array_filter((array) config('siat.monitoring.recipient_roles', []), 'is_string'));

        if ($roles === []) {
            return new Collection;
        }

        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($alert): void {
                $query->where('company_id', $alert->company_id)
                    ->orWhereNull('company_id');
            })
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', $roles))
            ->orderBy('id')
            ->get();
    }
}
