# Focused monitoring acceptance: #24 #28 #29 #37 #39 #43 #44
# Requires isolated bluepos_test; never executes migrations or cleanup commands.
$ErrorActionPreference = 'Stop'
$backend = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $backend
try {
    $env:APP_ENV = 'testing'
    $env:DB_DATABASE = 'bluepos_test'
    $guard = & php scripts/guard-test-database.php
    if ($LASTEXITCODE -ne 0 -or ($guard -join '').Trim() -ne 'TEST_DATABASE_OK') {
        throw 'Refused: test database guard did not pass.'
    }
    $names = @(
        'test_device_access_request_inbox_is_tenant_scoped_and_uses_public_ulids',
        'test_non_owner_cannot_read_tenant_employee_network_approval_inbox',
        'test_tenant_owner_can_review_only_own_pending_employee_access_requests',
        'test_owner_can_filter_and_paginate_employee_device_review_history',
        'test_owner_login_history_filters_ip_device_and_exposes_only_tenant_audit_browser_hints',
        'test_login_history_reports_unknown_browser_and_os_for_missing_or_unrecognized_agent',
        'test_login_history_denies_authenticated_member_without_owner_authority',
        'test_platform_device_activity_denies_staff_without_audit_permission',
        'test_platform_device_activity_requires_platform_audit_authorization_and_exposes_ulids',
        'test_platform_tenant_device_review_history_is_read_only_and_tenant_scoped'
    )
    $filter = $names -join '|'
    & php artisan test tests/Feature/AuthenticationTest.php --filter=$filter
    if ($LASTEXITCODE -ne 0) { throw 'Monitoring milestone test batch FAILED.' }
    Write-Host 'Monitoring backend acceptance PASS; confirm Owner/Platform browser screens for #24 #28 #29 #37 #39 #43 #44.'
}
finally { Pop-Location }
