<?php

namespace App\Http\Middleware;

use App\Platform\RecentPlatformMfa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentPlatformMfa
{
    public function __construct(private readonly RecentPlatformMfa $recentMfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->recentMfa->assert($request);

        return $next($request);
    }
}
