<?php

return [
    // Mandatory browser-geolocation consent for tenant login. Test environment opts out by default.
    'tenant_login_location_required' => filter_var(env('TENANT_LOGIN_LOCATION_REQUIRED', true), FILTER_VALIDATE_BOOL),


    // Optional IPInfo lookup. Empty token: zero third-party IP disclosure.
    'geoip' => ['token' => env('IPINFO_TOKEN', '')],
    'privacy' => ['security_ip_retention_days' => max(30, (int) env('SECURITY_IP_RETENTION_DAYS', 90))],

    // Temporarily frozen for the initial platform implementation.
    'platform_mfa_enabled' => filter_var(env('PLATFORM_MFA_ENABLED', false), FILTER_VALIDATE_BOOL),

    'platform_dev_bypass_mfa' => filter_var(
        env('PLATFORM_DEV_BYPASS_MFA', false),
        FILTER_VALIDATE_BOOL,
    ),

    /*
    |--------------------------------------------------------------------------
    | Security OTP / email policy
    |--------------------------------------------------------------------------
    |
    | Centralized challenge policy. Controllers must not hardcode these values.
    | Override with environment variables when needed.
    |
    */

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 6),
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
        'max_verify_attempts' => (int) env('OTP_MAX_VERIFY_ATTEMPTS', 5),
        'rate_limit_per_minute' => (int) env('OTP_RATE_LIMIT_PER_MINUTE', 5),
    ],

    'password_reset' => [
        'ttl_minutes' => (int) env('PASSWORD_RESET_TTL_MINUTES', env('OTP_TTL_MINUTES', 10)),
        'generic_message' => 'If the account exists, password reset instructions have been sent.',
    ],

];
