# Targeted #45/#46/#50 acceptance; only the isolated bluepos_test database.
$ErrorActionPreference = 'Stop'
$backend = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $backend
try {
    $env:APP_ENV = 'testing'
    $env:DB_DATABASE = 'bluepos_test'
    $guard = & php scripts/guard-test-database.php
    if ($LASTEXITCODE -ne 0 -or ($guard -join '').Trim() -ne 'TEST_DATABASE_OK') {
        throw "Database guard failed. No tests executed."
    }
    $filter = @(
        'test_ip_geolocation_does_not_contact_provider_without_opt_in_or_public_ip',
        'test_ip_geolocation_parses_only_provider_fields_and_keeps_failure_unknown',
        'test_ip_geolocation_provider_failure_does_not_leak_coordinates_or_break_lookup',
        'test_mandatory_browser_location_blocks_login_without_location_evidence',
        'test_optional_browser_location_allows_login_without_coordinates_and_does_not_fabricate_location',
        'test_security_retention_scheduler_registers_all_four_daily_jobs',
        'test_security_privacy_retention_redacts_only_aged_login_network_identity'
    ) -join '|'
    & php artisan test tests/Feature/AuthenticationTest.php --filter=$filter
    if ($LASTEXITCODE -ne 0) { throw "Tenant/GeoIP/retention tests failed." }
    & php artisan test tests/Feature/PlatformAccessTest.php --filter=test_platform_password_and_mfa_require_location_when_policy_enabled
    if ($LASTEXITCODE -ne 0) { throw "Platform location tests failed." }
    Write-Host "Backend #45/#46/#50 focused checks PASS. Production provider/privacy/cron and browser acceptance are separate."
}
finally { Pop-Location }
