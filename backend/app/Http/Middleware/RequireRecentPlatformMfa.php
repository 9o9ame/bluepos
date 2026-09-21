<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsurePlatformContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentPlatformMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('platform');
        $verifiedAt = $user?->last_mfa_verified_at;
        $sessionAt = $request->session()->get(EnsurePlatformContext::MFA_AT);
        $cutoff = now()->subMinutes(30);

        $recent = ($verifiedAt !== null && $verifiedAt->greaterThan($cutoff))
            || (is_numeric($sessionAt) && (int) $sessionAt >= $cutoff->timestamp);

        if (! $recent) {
            throw new ApiException('MFA_REQUIRED', 'Recent verification is required for this action.', 403);
        }

        return $next($request);
    }
}
