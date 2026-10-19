<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

$statefulDomains = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),
)));

$frontendUrl = trim((string) env('FRONTEND_URL', ''));
$frontendHost = $frontendUrl !== '' ? parse_url($frontendUrl, PHP_URL_HOST) : null;
$frontendPort = $frontendUrl !== '' ? parse_url($frontendUrl, PHP_URL_PORT) : null;

if (is_string($frontendHost) && $frontendHost !== '') {
    $statefulDomains[] = $frontendHost.($frontendPort ? ':'.$frontendPort : '');
}

return [
    'stateful' => array_values(array_unique($statefulDomains)),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
