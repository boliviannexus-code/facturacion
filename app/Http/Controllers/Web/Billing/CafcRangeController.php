<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCafcRangeRequest;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Services\Billing\ManualCafcService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CafcRangeController extends Controller
{
    public function __construct(private readonly ManualCafcService $cafc) {}

    public function index(): View
    {
        return view('billing.cafc-ranges.index', [
            'ranges' => SinCafcRange::query()->with(['branch', 'pointOfSale', 'creator'])->latest()->paginate(15),
            'branches' => SinBranch::query()->with('activePointsOfSale')->where('is_active', true)->orderBy('branch_code')->get(),
        ]);
    }

    public function store(StoreCafcRangeRequest $request): RedirectResponse
    {
        $this->cafc->registerRange($request->validated(), $request->user());

        return back()->with('success', 'Rango CAFC registrado correctamente.');
    }
}
