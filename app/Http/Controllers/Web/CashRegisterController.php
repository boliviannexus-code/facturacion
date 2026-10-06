<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegisters\CloseCashRegisterRequest;
use App\Http\Requests\CashRegisters\OpenCashRegisterRequest;
use App\Models\CashRegister;
use App\Services\CashRegisterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function __construct(
        private readonly CashRegisterService $cashRegisters,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CashRegister::class);

        return view('cash-registers.index', [
            'cashRegisters' => $this->cashRegisters->paginate(),
            'activeCashRegister' => $this->cashRegisters->activeFor($request->user()),
        ]);
    }

    public function store(OpenCashRegisterRequest $request): RedirectResponse
    {
        $this->cashRegisters->open($request->user(), $request->validated());

        return redirect()
            ->route('cash-registers.index')
            ->with('success', 'Caja abierta correctamente.');
    }

    public function close(
        CloseCashRegisterRequest $request,
        CashRegister $cashRegister
    ): RedirectResponse {
        $this->cashRegisters->close($cashRegister, $request->user(), $request->validated());

        return redirect()
            ->route('cash-registers.index')
            ->with('success', 'Caja cerrada correctamente.');
    }
}
