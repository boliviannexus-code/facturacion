<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSinWsdlServiceRequest;
use App\Models\SinWsdlService;
use App\Services\Siat\SiatWsdlRegistry;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class SiatWsdlServiceController extends Controller
{
    public function __construct(private readonly SiatWsdlRegistry $registry) {}

    public function index(): View
    {
        $this->registry->ensureDefaults();

        return view('siat.wsdl-services.index', [
            'services' => SinWsdlService::query()->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    public function store(SaveSinWsdlServiceRequest $request): RedirectResponse
    {
        SinWsdlService::query()->create([
            ...$request->validated(),
            'company_id' => CompanyContext::id($request->user()),
        ]);

        return to_route('siat.wsdl-services.index')->with('success', 'Servicio WSDL agregado correctamente.');
    }

    public function update(SaveSinWsdlServiceRequest $request, SinWsdlService $wsdlService): RedirectResponse
    {
        $wsdlService->update($request->validated());

        return to_route('siat.wsdl-services.index')->with('success', 'Servicio WSDL actualizado correctamente.');
    }

    public function destroy(SinWsdlService $wsdlService): RedirectResponse
    {
        $wsdlService->delete();

        return to_route('siat.wsdl-services.index')->with('success', 'Servicio WSDL eliminado correctamente.');
    }
}
