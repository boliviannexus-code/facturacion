<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RestoreDatabaseBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('backups.restore') ?? false;
    }

    public function rules(): array
    {
        return ['confirmation' => ['required', 'string', Rule::in(['RESTAURAR'])]];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Escribe RESTAURAR para confirmar.',
            'confirmation.in' => 'La confirmación no coincide. Escribe RESTAURAR exactamente.',
        ];
    }
}
