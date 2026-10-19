<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSinWsdlServiceRequest;
use App\Models\SinAuthorization;
use App\Models\SinWsdlService;
use App\Services\Siat\SiatWsdlRegistry;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SiatWsdlServiceController extends Controller
{
    public function __construct(private readonly SiatWsdlRegistry $registry) {}

    public function index(Request $request): View
    {
        $this->registry->ensureDefaults();

        $environment = $request->input('environment_code', SinAuthorization::query()->first()?->environment_code?->value ?? 2);
        abort_unless(in_array((string) $environment, ['1', '2', 'unclassified'], true), 422);

        return view('siat.wsdl-services.index', [
            'selectedEnvironment' => $environment,
            'services' => SinWsdlService::query()->when($environment === 'unclassified', fn ($query) => $query->whereNull('environment_code'), fn ($query) => $query->where('environment_code', $environment))->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    public function store(SaveSinWsdlServiceRequest $request): RedirectResponse
    {
        SinWsdlService::query()->create([
            ...$request->validated(),
            'company_id' => CompanyContext::id($request->user()),
        ]);

        return to_route('siat.wsdl-services.index', ['environment_code' => $request->integer('environment_code')])->with('success', 'Servicio WSDL agregado correctamente.');
    }

    public function update(SaveSinWsdlServiceRequest $request, SinWsdlService $wsdlService): RedirectResponse
    {
        $wsdlService->update($request->validated());

        return to_route('siat.wsdl-services.index', ['environment_code' => $request->integer('environment_code')])->with('success', 'Servicio WSDL actualizado correctamente.');
    }

    public function destroy(SinWsdlService $wsdlService): RedirectResponse
    {
        $wsdlService->delete();

        return to_route('siat.wsdl-services.index', ['environment_code' => $wsdlService->environment_code?->value ?? 'unclassified'])->with('success', 'Servicio WSDL eliminado correctamente.');
    }
}
