<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCafcRangeRequest;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Services\Billing\ManualCafcService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CafcRangeController extends Controller
{
    public function __construct(private readonly ManualCafcService $cafc) {}

    public function index(): View
    {
        return view('billing.cafc-ranges.index', [
            'ranges' => SinCafcRange::query()
                ->where('is_test_copy', false)
                ->with(['branch', 'pointOfSale', 'creator'])
                ->withExists([
                    'manualInvoices',
                    'derivedCopies',
                    'invoiceTestBatches',
                    'invoiceTestBatchItems',
                    'monitoringAlerts',
                ])
                ->latest()
                ->paginate(15),
            'branches' => SinBranch::query()->with('activePointsOfSale')->where('is_active', true)->orderBy('branch_code')->get(),
        ]);
    }

    public function store(StoreCafcRangeRequest $request): RedirectResponse
    {
        $this->cafc->registerRange($request->validated(), $request->user());

        return back()->with('success', 'Rango CAFC registrado correctamente.');
    }

    public function destroy(Request $request, SinCafcRange $cafcRange): RedirectResponse
    {
        if ($request->boolean('restart_tests')) {
            $this->cafc->deleteTestRange($cafcRange, $request->user());

            return redirect()->route('billing.cafc-contingencies.index')->with('success', 'CAFC retirado de pruebas. Puede registrar nuevamente el mismo código y numeración; el historial anterior se conserva.');
        }

        $this->cafc->deleteUnusedRange($cafcRange, $request->user());

        return back()->with('success', 'Rango CAFC eliminado correctamente.');
    }
}
