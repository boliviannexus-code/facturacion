<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SinWsdlService;
use App\Services\Siat\SiatWsdlRegistry;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSinWsdlServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('wsdlService');

        return CompanyContext::operatingCompanyId($this->user()) !== null
            && ($this->user()?->can('sin-api-tokens.manage') ?? false)
            && (! $service instanceof SinWsdlService
                || CompanyContext::belongsToUser($service->company_id, $this->user()));
    }

    public function rules(): array
    {
        $service = $this->route('wsdlService');
        $companyId = CompanyContext::id($this->user());

        return [
            'environment_code' => ['required', Rule::in([1, 2])],
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('sin_wsdl_services', 'key')
                    ->where('company_id', $companyId)
                    ->where('environment_code', $this->integer('environment_code'))->whereNull('deleted_at')
                    ->ignore($service),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['infraestructura', 'facturacion'])],
            'url' => [
                'required',
                'url',
                'starts_with:https://',
                'max:1000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! SiatWsdlRegistry::validUrl((string) $value, $this->integer('environment_code'))) {
                        $fail('Selecciona una URL WSDL oficial del SIN que corresponda al ambiente indicado.');
                    }
                },
                Rule::unique('sin_wsdl_services', 'url')
                    ->where('company_id', $companyId)
                    ->where('environment_code', $this->integer('environment_code'))->whereNull('deleted_at')
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
