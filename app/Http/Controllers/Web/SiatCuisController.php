<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportSinCuisRequest;
use App\Http\Requests\RequestSinCuisRequest;
use App\Services\Parameters\SinAuthorizationService;
use App\Services\Siat\SiatCuisService;
use App\Services\Siat\SinBranchService;
use App\Services\SinApiTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SiatCuisController extends Controller
{
    public function __construct(
        private readonly SiatCuisService $cuis,
        private readonly SinApiTokenService $apiTokens,
        private readonly SinAuthorizationService $authorizations,
        private readonly SinBranchService $branches,
    ) {}

    public function index(): View
    {
        return view('siat.cuis.index', [
            'apiToken' => $this->apiTokens->current(),
            'authorization' => $this->authorizations->current(),
            'currentCuis' => $this->cuis->current(),
            'latestAttempt' => $this->cuis->latestAttempt(),
            'history' => $this->cuis->history(),
            'pointOptions' => $this->branches->activePointOptions(),
        ]);
    }

    public function request(RequestSinCuisRequest $request): RedirectResponse
    {
        $attempt = $this->cuis->request($request->user(), $request->pointOfSale());

        return redirect()
            ->route('siat.cuis.index')
            ->with(
                $attempt->transaccion ? 'success' : 'warning',
                $attempt->message,
            );
    }

    public function importExisting(ImportSinCuisRequest $request): RedirectResponse
    {
        $this->cuis->importExisting(
            $request->user(),
            $request->pointOfSale(),
            $request->validated('cuis_code'),
        );

        return redirect()
            ->route('siat.cuis.index')
            ->with('success', 'CUIS existente registrado correctamente. Ya puedes continuar con las operaciones SIAT.');
    }
}
