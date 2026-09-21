<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureFeatureEntitled;
use App\Http\Middleware\EnsurePlatformContext;
use App\Http\Middleware\EnsurePlatformPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\RequireRecentPlatformMfa;
use App\Http\Responses\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'platform' => EnsurePlatformContext::class,
            'platform.can' => EnsurePlatformPermission::class,
            'platform.recent-mfa' => RequireRecentPlatformMfa::class,
            'entitled' => EnsureFeatureEntitled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ApiException) {
                return ApiError::response($e->errorKey, $e->getMessage(), $e->getStatusCode(), $e->extra);
            }

            if ($e instanceof ValidationException) {
                $message = collect($e->errors())->flatten()->first() ?: 'The given data was invalid.';

                return ApiError::response('VALIDATION_ERROR', $message, 422, [
                    'fields' => $e->errors(),
                ]);
            }

            if ($e instanceof AuthenticationException) {
                return ApiError::response('UNAUTHORIZED', 'You are not authorized to perform this action.', 401);
            }

            if ($e instanceof AuthorizationException) {
                return ApiError::response('FORBIDDEN', 'You are not allowed to perform this action.', 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return ApiError::response('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            if ($e instanceof TokenMismatchException) {
                return ApiError::response('CSRF_MISMATCH', 'Your session has expired. Please refresh and try again.', 419);
            }

            if ($e instanceof TooManyRequestsHttpException) {
                return ApiError::response('TOO_MANY_ATTEMPTS', 'Too many attempts. Please wait and try again.', 429);
            }

            if ($e instanceof QueryException) {
                report($e);

                return ApiError::response('SERVER_ERROR', 'An unexpected error occurred.', 500);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if ($status >= 500) {
                    report($e);

                    return ApiError::response('SERVER_ERROR', 'An unexpected error occurred.', 500);
                }

                $key = match ($status) {
                    401 => 'UNAUTHORIZED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    419 => 'CSRF_MISMATCH',
                    429 => 'TOO_MANY_ATTEMPTS',
                    default => 'HTTP_ERROR',
                };

                $message = $status >= 400 && $status < 500
                    ? ($e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.')
                    : 'An unexpected error occurred.';

                return ApiError::response($key, $message, $status);
            }

            report($e);

            return ApiError::response('SERVER_ERROR', 'An unexpected error occurred.', 500);
        });
    })->create();
