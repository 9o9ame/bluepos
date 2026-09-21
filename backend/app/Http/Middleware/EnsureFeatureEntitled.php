<?php

namespace App\Http\Middleware;

use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEntitled
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $this->entitlements->assertFeature($this->tenantContext->tenant(), $feature);

        return $next($request);
    }
}
