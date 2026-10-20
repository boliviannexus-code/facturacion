<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CompanyContext::hasCompanyScope($request->user()), 403);

        $request->attributes->set('company_scoped_user_id', $request->user()->getKey());

        return $next($request);
    }
}
