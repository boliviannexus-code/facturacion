<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeleteDatabaseBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('backups.delete') ?? false;
    }

    public function rules(): array
    {
        return ['confirmation' => ['required', 'string', Rule::in(['ELIMINAR'])]];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Escribe ELIMINAR para confirmar.',
            'confirmation.in' => 'La confirmación no coincide. Escribe ELIMINAR exactamente.',
        ];
    }
}
