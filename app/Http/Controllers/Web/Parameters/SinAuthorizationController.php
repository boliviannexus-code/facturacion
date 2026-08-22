<?php

namespace App\Http\Controllers\Web\Parameters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parameters\SaveSinAuthorizationRequest;
use App\Models\SinAuthorization;
use App\Services\Parameters\SinAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SinAuthorizationController extends Controller
{
    public function __construct(
        private readonly SinAuthorizationService $authorizations,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', SinAuthorization::class);

        $authorization = $this->authorizations->current();

        if ($authorization) {
            $this->authorize('view', $authorization);
        }

        return view('parameters.authorization.index', [
            'authorization' => $authorization,
            ...$this->authorizations->formOptions(),
        ]);
    }

    public function store(SaveSinAuthorizationRequest $request): RedirectResponse
    {
        $this->authorizations->save($request->user(), $request->validated());

        return redirect()
            ->route('parameters.authorization.index')
            ->with('success', 'Autorizacion SIN registrada correctamente.');
    }

    public function update(SaveSinAuthorizationRequest $request): RedirectResponse
    {
        $this->authorizations->save($request->user(), $request->validated());

        return redirect()
            ->route('parameters.authorization.index')
            ->with('success', 'Autorizacion SIN actualizada correctamente.');
    }
}
