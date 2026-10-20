<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActiveCompanyController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        abort_unless(CompanyContext::isGlobalAdmin($request->user()), 403);
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('is_active', true)],
        ]);
        $request->session()->put(CompanyContext::SESSION_KEY, [
            'user_id' => $request->user()->getKey(),
            'company_id' => (int) $data['company_id'],
        ]);

        return redirect()->route('dashboard')->with('success', 'Empresa activa actualizada.');
    }
}
