<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Platform\PlatformContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformPermission
{
    public function __construct(private readonly PlatformContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->context->can($permission)) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        return $next($request);
    }
}
