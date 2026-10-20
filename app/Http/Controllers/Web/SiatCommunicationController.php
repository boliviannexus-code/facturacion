<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Siat\SiatCommunicationService;
use App\Services\SinApiTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SiatCommunicationController extends Controller
{
    public function __construct(
        private readonly SinApiTokenService $apiTokens,
        private readonly SiatCommunicationService $communication,
    ) {}

    public function index(): View
    {
        return view('siat.communication.index', [
            'apiToken' => $this->apiTokens->current(),
            'result' => session('siat_communication_result'),
        ]);
    }

    public function verify(): RedirectResponse
    {
        $apiToken = $this->apiTokens->current();

        if (! $apiToken) {
            return redirect()
                ->route('siat.communication.index')
                ->withErrors([
                    'communication' => 'Registra primero el token API y la URL WSDL.',
                ]);
        }

        return redirect()
            ->route('siat.communication.index')
            ->with('siat_communication_result', $this->communication->verify($apiToken)->toArray());
    }
}
