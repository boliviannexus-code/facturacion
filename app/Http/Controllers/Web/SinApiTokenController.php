<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSinApiTokenRequest;
use App\Models\SinApiToken;
use App\Services\Siat\SiatWsdlRegistry;
use App\Services\SinApiTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SinApiTokenController extends Controller
{
    public function __construct(
        private readonly SinApiTokenService $apiTokens,
        private readonly SiatWsdlRegistry $wsdls,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', SinApiToken::class);

        $apiToken = $this->apiTokens->current();

        if ($apiToken) {
            $this->authorize('view', $apiToken);
        }

        return view('sin-api-token.index', [
            'apiToken' => $apiToken,
            'wsdlOptions' => $this->wsdls->all(),
        ]);
    }

    public function store(SaveSinApiTokenRequest $request): RedirectResponse
    {
        $this->apiTokens->save($request->user(), $request->validated());

        return redirect()
            ->route('sin-api-token.index')
            ->with('success', 'Token API registrado correctamente.');
    }

    public function update(SaveSinApiTokenRequest $request): RedirectResponse
    {
        $this->apiTokens->save($request->user(), $request->validated());

        return redirect()
            ->route('sin-api-token.index')
            ->with('success', 'Token API actualizado correctamente.');
    }
}
