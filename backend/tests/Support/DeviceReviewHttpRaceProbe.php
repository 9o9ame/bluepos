<?php

/**
 * Standalone, opt-in authenticated HTTP race probe.
 *
 * Usage: configure BLUEPOS_RACE_BASE_URL, BLUEPOS_RACE_TENANT_CODE,
 * BLUEPOS_RACE_OWNER_PASSWORD and BLUEPOS_RACE_DEVICE_CREDENTIAL in
 * the environment; start a multi-worker Laravel HTTP server against
 * bluepos_test, then run this file with PHP.
 *
 * Creates one isolated employee, pending device and access request and cleans
 * those exact rows after the race. Existing tenant/owner must be test-only.
 * The server MUST also use bluepos_test and must support concurrent requests.
 */

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Tests\Support\TestDatabaseGuard;

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
TestDatabaseGuard::assertApplication();

if (config('session.driver') === 'array') {
    throw new RuntimeException('Use persistent sessions (file/database) in the test HTTP server, not SESSION_DRIVER=array.');
}

if (! extension_loaded('curl')) {
    throw new RuntimeException('PHP curl extension is required for the HTTP race probe.');
}

$required = [
    'BLUEPOS_RACE_BASE_URL',
    'BLUEPOS_RACE_TENANT_CODE',
    'BLUEPOS_RACE_OWNER_PASSWORD',
    'BLUEPOS_RACE_DEVICE_CREDENTIAL',
];
$values = [];
foreach ($required as $name) {
    $value = getenv($name);
    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException('Missing environment configuration: '.$name);
    }
    $values[$name] = $value;
}
$base = rtrim($values['BLUEPOS_RACE_BASE_URL'], '/');
$parts = parse_url($base);
if (! is_array($parts)
    || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
    || ! in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('The race probe can only target an HTTP service on localhost.');
}
$tenant = \App\Models\Tenant::query()->where('code', $values['BLUEPOS_RACE_TENANT_CODE'])->firstOrFail();
$owner = \App\Models\Membership::query()->where('tenant_id', $tenant->id)
    ->where('username', 'owner')->firstOrFail();
if (! $owner->hasOwnerAuthority()) {
    throw new RuntimeException('The selected test tenant has no active owner authority.');
}
$credentialRequest = \Illuminate\Http\Request::create('/', 'GET');
$credentialRequest->headers->set(
    \App\Security\DeviceCredentialService::HEADER,
    $values['BLUEPOS_RACE_DEVICE_CREDENTIAL']
);
$activeDevice = app(\App\Security\DeviceCredentialService::class)->locate($credentialRequest);
if (! $activeDevice || (int) $activeDevice->tenant_id !== (int) $tenant->id || ! $activeDevice->isActive()) {
    throw new RuntimeException('Provided owner device credential must resolve to a trusted active device in this test tenant.');
}

// Only create a disposable fixture in the strictly guarded test database.
// Never provision a tenant or mutate a pre-existing employee/device.
$stamp = strtolower((string) \Illuminate\Support\Str::ulid());
$employee = null;
$device = null;
$ulid = (string) \Illuminate\Support\Str::ulid();
try {
    $employee = \Illuminate\Support\Facades\DB::transaction(function () use ($stamp, $tenant) {
        return \App\Models\User::query()->create([
            'name' => 'Disposable HTTP race staff',
            'email' => 'race-'.$stamp.'@example.invalid',
            'password' => \Illuminate\Support\Str::random(40),
            'status' => \App\Enums\UserStatus::Active,
            'security_version' => 1,
            'must_change_password' => false,
        ]);
    });
    $member = \App\Models\Membership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $employee->id,
        'username' => 'race-'.$stamp,
        'status' => \App\Enums\MembershipStatus::Active,
        'security_version' => 1,
        'is_owner' => false,
    ]);
    $device = \App\Models\Device::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Disposable HTTP race device',
        'device_type' => 'browser',
        'status' => \App\Enums\DeviceStatus::Pending,
        'credential_hash' => 'disposable-race-probe',
        'registered_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
        'ulid' => $ulid,
        'tenant_id' => $tenant->id,
        'membership_id' => $member->id,
        'device_id' => $device->id,
        'network_hash' => hash('sha256', 'race-'.$stamp),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
} catch (\Throwable $exception) {
    if ($device) {
        $device->delete();
    }
    if (isset($member)) {
        $member->delete();
    }
    if ($employee) {
        $employee->delete();
    }
    throw $exception;
}

register_shutdown_function(static function () use ($tenant, $ulid, $device, $member, $employee): void {
    // Explicit test-only cleanup; no tenant-wide delete or table truncation.
    \Illuminate\Support\Facades\DB::table('audit_logs')
        ->where('tenant_id', $tenant->id)
        ->where('resource_ulid', $ulid)
        ->whereIn('event', ['DEVICE_ACCESS_APPROVED', 'DEVICE_ACCESS_REJECTED'])
        ->delete();
    \Illuminate\Support\Facades\DB::table('device_access_requests')
        ->where('tenant_id', $tenant->id)->where('ulid', $ulid)->delete();
    $device->delete();
    $member->delete();
    $employee->delete();
});

function requestHandle(string $url, string $body, string $deviceCredential, ?string $cookie = null): CurlHandle
{
    $handle = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Origin: http://localhost:5173',
        'Referer: http://localhost:5173/',
        \App\Security\DeviceCredentialService::HEADER.': '.$deviceCredential,
    ];
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($cookie !== null) {
        curl_setopt($handle, CURLOPT_COOKIE, $cookie);
    }

    return $handle;
}

// Each owner login receives its own session cookie. Do not share PHP sessions:
// shared session locks could otherwise hide the concurrency being tested.
$cookies = [];
foreach ([0, 1] as $worker) {
    $login = requestHandle($base.'/api/auth/login', json_encode([
        'tenant_code' => $tenant->code,
        'username' => 'owner',
        'password' => $values['BLUEPOS_RACE_OWNER_PASSWORD'],
    ], JSON_THROW_ON_ERROR), $values['BLUEPOS_RACE_DEVICE_CREDENTIAL']);
    $raw = curl_exec($login);
    $code = curl_getinfo($login, CURLINFO_HTTP_CODE);
    if (! is_string($raw) || $code !== 200) {
        throw new RuntimeException('Owner login failed for worker '.($worker + 1).' (HTTP '.$code.').');
    }
    preg_match_all('/^Set-Cookie:\s*([^=;\s]+=[^;\r\n]*)/mi', $raw, $matches);
    $cookie = implode('; ', $matches[1] ?? []);
    if ($cookie === '') {
        throw new RuntimeException('No owner session cookie was issued to worker '.($worker + 1).'.');
    }
    $cookies[] = $cookie;
    curl_close($login);
}

$multi = curl_multi_init();
$handles = [];
foreach (['approved', 'rejected'] as $index => $decision) {
    $handles[] = requestHandle(
        $base.'/api/devices/access-requests/'.$ulid.'/review',
        json_encode(['decision' => $decision], JSON_THROW_ON_ERROR),
        $values['BLUEPOS_RACE_DEVICE_CREDENTIAL'],
        $cookies[$index]
    );
    curl_multi_add_handle($multi, $handles[$index]);
}
try {
    do {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) {
            throw new RuntimeException('curl_multi_exec failed: '.$status);
        }
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $index => $handle) {
        $response = curl_multi_getcontent($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $body = is_string($response) ? substr($response, (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE)) : '';
        $json = json_decode($body, true);
        $results[['approved', 'rejected'][$index]] = [
            'http' => $httpCode,
            'status' => $json['status'] ?? null,
            'error' => $json['error']['key'] ?? null,
        ];
    }
} finally {
    foreach ($handles as $handle) {
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
}

$winner = null;
foreach ($results as $decision => $result) {
    if ($result['http'] === 200 && $result['status'] === $decision) {
        $winner = $decision;
    }
}
$loser = $winner === 'approved' ? 'rejected' : 'approved';
$after = \Illuminate\Support\Facades\DB::table('device_access_requests')->where('ulid', $ulid)->first();
$device->refresh();
$audit = \App\Models\AuditLog::query()->where('tenant_id', $tenant->id)
    ->where('resource_ulid', $ulid)
    ->whereIn('event', ['DEVICE_ACCESS_APPROVED', 'DEVICE_ACCESS_REJECTED'])
    ->pluck('event')->all();

$valid = $winner !== null
    && count(array_filter($results, static fn (array $result): bool => $result['http'] === 200)) === 1
    && count(array_filter($results, static fn (array $result): bool => $result['http'] === 409)) === 1
    && $results[$loser]['http'] === 409
    && $results[$loser]['error'] === 'ACCESS_REQUEST_ALREADY_REVIEWED'
    && $after->status === $winner
    && count($audit) === 1
    && $audit[0] === ($winner === 'approved' ? 'DEVICE_ACCESS_APPROVED' : 'DEVICE_ACCESS_REJECTED')
    && ($winner === 'approved'
        ? $device->status === \App\Enums\DeviceStatus::Active
        : $device->status === \App\Enums\DeviceStatus::Pending);

echo json_encode(['passed' => $valid, 'results' => $results, 'persisted_status' => $after->status,
    'audit_count' => count($audit)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($valid ? 0 : 1);
