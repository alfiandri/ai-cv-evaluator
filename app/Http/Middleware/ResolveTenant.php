<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('X-Tenant-ID');
        if (!$header) {
            throw new AccessDeniedHttpException('X-Tenant-ID header is required');
        }
        $tenant = Tenant::where('id', $header)->orWhere('slug', $header)->first();
        if (!$tenant) {
            throw new AccessDeniedHttpException('Invalid tenant');
        }
        TenantContext::set($tenant->id);
        return $next($request);
    }
}
