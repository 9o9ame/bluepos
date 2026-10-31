# BluePOS focused acceptance batch; run from any PowerShell directory.
# Refuses to execute unless the resolved Laravel pgsql database is bluepos_test.
$ErrorActionPreference = 'Stop'
$backend = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $backend
try {
    $env:APP_ENV = 'testing'
    $env:DB_DATABASE = 'bluepos_test'
    $resolved = & php scripts/guard-test-database.php
    if ($LASTEXITCODE -ne 0 -or ($resolved -join '').Trim() -ne 'TEST_DATABASE_OK') {
        throw "Unsafe test database configuration or failed connection. Test batch was not run."
    }
    $names = @(
        "test_owner_trusted_device_reuses_same_network_but_changed_network_requires_otp",
        "test_owner_trusted_device_expiry_requires_fresh_email_verification",
        "test_owner_changed_network_otp_rebinds_trust_and_reuses_new_ip",
        "test_approved_employee_access_review_cannot_be_reversed_by_rejection",
        "test_employee_access_review_rejects_ineligible_membership_or_device_without_mutation",
        "test_platform_cannot_bypass_pending_employee_device_review",
        "test_review_rechecks_live_owner_authority_and_tenant_licence_for_active_device",
        "test_tenant_owner_can_review_only_own_pending_employee_access_requests",
        "test_device_access_request_inbox_is_tenant_scoped_and_uses_public_ulids",
        "test_owner_can_filter_and_paginate_employee_device_review_history",
        "test_employee_access_review_rolls_back_if_audit_write_fails",
        "test_owner_login_history_filters_ip_device_and_exposes_only_tenant_audit_browser_hints",
        "test_login_history_reports_unknown_browser_and_os_for_missing_or_unrecognized_agent",
        "test_ip_geolocation_parses_only_provider_fields_and_keeps_failure_unknown",
        "test_ip_geolocation_provider_failure_does_not_leak_coordinates_or_break_lookup",
        "test_mandatory_browser_location_blocks_login_without_location_evidence",
        "test_tenant_mfa_otp_is_consumed_once_and_replay_denied",
        "test_tenant_mfa_atomic_claim_rejects_stale_second_claim",
        "test_tenant_mfa_wrong_code_exhausts_attempt_budget_and_blocks_correct_code",
        "test_expired_tenant_mfa_otp_cannot_be_used_or_consumed",
        "test_security_retention_scheduler_registers_all_four_daily_jobs",
        "test_security_privacy_retention_redacts_only_aged_login_network_identity"
    )
    $filter = $names -join '|'
    & php artisan test tests/Feature/AuthenticationTest.php --filter=$filter
    if ($LASTEXITCODE -ne 0) { throw "Device milestone acceptance batch FAILED." }
    & php artisan test tests/Feature/MfaConcurrencyTest.php
    if ($LASTEXITCODE -ne 0) { throw "Two-process OTP contention test FAILED." }
    & php artisan test tests/Feature/DeviceAccessConcurrencyTest.php
    if ($LASTEXITCODE -ne 0) { throw "Two-process staff access review contention test FAILED." }
    Write-Host "Focused milestone acceptance batch PASS. Browser E2E, production IPInfo and cron still require separate verification."
}
finally { Pop-Location }
