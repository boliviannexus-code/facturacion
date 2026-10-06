<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return CashRegister::query()
            ->with('user:id,name')
            ->orderByDesc('opened_at')
            ->paginate($perPage);
    }

    public function activeFor(User $user): ?CashRegister
    {
        return CashRegister::query()
            ->active()
            ->where('user_id', $user->id)
            ->first();
    }

    public function open(User $user, array $data): CashRegister
    {
        try {
            return DB::transaction(function () use ($user, $data): CashRegister {
                $lockedUser = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedUser->company_id === null) {
                    throw ValidationException::withMessages([
                        'cash_register' => 'El usuario debe pertenecer a una empresa para abrir una caja.',
                    ]);
                }

                $alreadyActive = CashRegister::query()
                    ->withoutGlobalScope('company')
                    ->active()
                    ->where('user_id', $lockedUser->id)
                    ->exists();

                if ($alreadyActive) {
                    throw ValidationException::withMessages([
                        'cash_register' => 'Ya tienes una caja activa. Debes cerrarla antes de abrir otra.',
                    ]);
                }

                return CashRegister::query()->create([
                    'company_id' => $lockedUser->company_id,
                    'user_id' => $lockedUser->id,
                    'opening_amount' => $data['opening_amount'],
                    'opening_notes' => $data['opening_notes'] ?? null,
                    'opened_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isActiveRegisterConflict($exception)) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Ya tienes una caja activa. Debes cerrarla antes de abrir otra.',
                ]);
            }

            throw $exception;
        }
    }

    public function close(CashRegister $cashRegister, User $user, array $data): CashRegister
    {
        return DB::transaction(function () use ($cashRegister, $user, $data): CashRegister {
            $lockedRegister = CashRegister::query()
                ->withoutGlobalScope('company')
                ->whereKey($cashRegister->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $lockedRegister->company_id !== (int) $user->company_id
                || (int) $lockedRegister->user_id !== (int) $user->id
            ) {
                abort(403);
            }

            if (! $lockedRegister->isActive()) {
                throw ValidationException::withMessages([
                    'cash_register' => 'La caja ya fue cerrada.',
                ]);
            }

            $lockedRegister->update([
                'closing_amount' => $data['closing_amount'],
                'closing_notes' => $data['closing_notes'] ?? null,
                'closed_at' => now(),
            ]);

            return $lockedRegister->refresh()->load('user:id,name');
        }, 3);
    }

    private function isActiveRegisterConflict(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'cash_registers_one_active_per_user_unique');
    }
}
