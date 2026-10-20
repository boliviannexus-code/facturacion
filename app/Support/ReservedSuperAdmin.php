<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ReservedSuperAdmin
{
    public const EMAIL = 'boliviannexus@gmail.com';

    public const ROLE = 'super_admin';

    public static function is(User|string $user): bool
    {
        return mb_strtolower(trim($user instanceof User ? $user->email : $user)) === self::EMAIL;
    }

    public static function isGlobal(?User $user): bool
    {
        return $user !== null && self::is($user) && $user->hasRole(self::ROLE);
    }

    public static function assertCanManage(User $target): void
    {
        $actor = auth()->user();
        if (self::is($target) && (! self::isGlobal($actor) || ! $actor->is($target))) {
            throw ValidationException::withMessages(['user' => 'Solo el superadministrador puede modificar su cuenta protegida.']);
        }
    }

    public static function validate(string $email, ?array $roles = null, ?User $existing = null, array $data = []): void
    {
        if (self::is($email) && (! $existing || ! self::is($existing))) {
            throw ValidationException::withMessages(['email' => 'El correo está reservado para la cuenta de superadministración existente.']);
        }
        if ($existing && self::is($existing)) {
            self::assertCanManage($existing);
            if (! self::is($email)) {
                throw ValidationException::withMessages(['email' => 'El correo del superadministrador está protegido.']);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'El superadministrador debe permanecer activo.']);
            }
        }
        if ($roles !== null && in_array(self::ROLE, $roles, true) && ! self::is($email)) {
            throw ValidationException::withMessages(['roles' => 'El rol super_admin está reservado para '.self::EMAIL.'.']);
        }
        if (self::is($email) && $roles !== null && ! in_array(self::ROLE, $roles, true)) {
            throw ValidationException::withMessages(['roles' => 'La cuenta protegida debe conservar el rol super_admin.']);
        }
    }

    public static function preventRemoval(User $user): void
    {
        if (self::is($user)) {
            throw ValidationException::withMessages(['user' => 'No puedes eliminar ni desactivar la cuenta del superadministrador.']);
        }
    }
}
