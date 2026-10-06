<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SinWsdlService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSinWsdlServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('wsdlService');

        return $this->user()?->company_id !== null
            && ($this->user()?->can('sin-api-tokens.manage') ?? false)
            && (! $service instanceof SinWsdlService
                || CompanyContext::belongsToUser($service->company_id, $this->user()));
    }

    public function rules(): array
    {
        $service = $this->route('wsdlService');
        $companyId = CompanyContext::id($this->user());

        return [
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('sin_wsdl_services', 'key')
                    ->where('company_id', $companyId)
                    ->ignore($service),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['infraestructura', 'facturacion'])],
            'url' => [
                'required',
                'url',
                'starts_with:https://',
                'max:2048',
                Rule::unique('sin_wsdl_services', 'url')
                    ->where('company_id', $companyId)
                    ->ignore($service),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $key = $this->input('key');
        $url = $this->input('url');
        $description = $this->input('description');

        $this->merge([
            'key' => is_string($key) ? trim(mb_strtolower($key)) : $key,
            'name' => is_string($name) ? trim($name) : $name,
            'url' => is_string($url) ? trim($url) : $url,
            'description' => is_string($description) ? trim($description) : $description,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
