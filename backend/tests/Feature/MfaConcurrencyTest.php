<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\MembershipStatus;
use App\Enums\MfaMethod;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Device;
use App\Models\Membership;
use App\Models\MfaChallenge;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Support\TestDatabaseGuard;

class MfaConcurrencyTest extends TestCase
{
    public function test_two_independent_postgresql_processes_cannot_claim_same_otp(): void
    {
        TestDatabaseGuard::assertApplication();
        $database = config('database.connections.pgsql');
        $this->assertSame('bluepos_test', $database['database']);
        $tag = strtolower((string) Str::ulid());
        $tenant = null;
        $user = null;
        $membership = null;
        $device = null;
        $challenge = null;
        $workers = [];

        try {
            $tenant = Tenant::query()->create([
                'name' => 'MFA race '.$tag,
                'slug' => 'mfa-race-'.$tag,
                'code' => 'race'.substr($tag, 0, 20),
                'status' => TenantStatus::Active,
                'timezone' => 'UTC',
                'currency_code' => 'PKR',
                'security_version' => 1,
            ]);
            $user = User::query()->create([
                'name' => 'MFA race fixture',
                'email' => 'mfa-race-'.$tag.'@example.invalid',
                'password' => 'unused-test-password',
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'security_version' => 1,
            ]);
            $membership = Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'username' => 'raceowner',
                'status' => MembershipStatus::Active,
                'is_owner' => false,
                'security_version' => 1,
            ]);
            $device = Device::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'MFA race device',
                'device_type' => 'browser',
                'status' => DeviceStatus::Active,
                'credential_hash' => 'test-fixture',
                'registered_at' => now(),
            ]);
            $challenge = MfaChallenge::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'membership_id' => $membership->id,
                'device_id' => $device->id,
                'method' => MfaMethod::EmailOtp,
                'purpose' => 'login',
                'code_hash' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
            ]);

            // Use the same PostgreSQL clock as the worker predicate, not PHP's local timezone.
            DB::table('mfa_challenges')->where('id', $challenge->id)
                ->update(['expires_at' => DB::raw("CURRENT_TIMESTAMP + INTERVAL '10 minutes'")]);
            $this->assertSame(1, DB::table('mfa_challenges')
                ->where('id', $challenge->id)
                ->where('consumed_at', null)
                ->where('expires_at', '>', DB::raw('CURRENT_TIMESTAMP'))
                ->count());

            // Each child uses its own PDO connection/process. No shared Laravel connection.
            // The winning transaction holds its row lock for 1 second so the loser
            // contends against a still-open transaction rather than a completed update.
            $worker = <<<'PHP'
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = $input['db'];
$pdo = new PDO(
    'pgsql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['database'],
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
);
$pdo->exec("SET lock_timeout = '8s'");
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE mfa_challenges SET consumed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '.
        'WHERE id = :id AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP '.
        'AND attempts < :max_attempts RETURNING id'
    );
    $stmt->execute(['id' => $input['id'], 'max_attempts' => $input['max']]);
    $won = $stmt->fetchColumn() !== false;
    if ($won) {
        $pdo->query('SELECT pg_sleep(1)')->fetchColumn();
    }
    $pdo->commit();
    echo $won ? '1' : '0';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'OTP race worker failed');
    exit(1);
}
PHP;
            $input = json_encode([
                'db' => [
                    'host' => $database['host'] ?? '127.0.0.1',
                    'port' => $database['port'] ?? 5432,
                    'database' => $database['database'],
                    'username' => $database['username'],
                    'password' => $database['password'],
                ],
                'id' => $challenge->id,
                'max' => \App\Security\SecurityOtp::maxVerifyAttempts(),
            ], JSON_THROW_ON_ERROR);

            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, '-r', $worker]);
                $process->setTimeout(20);
                $process->setInput($input);
                $process->start();
                $workers[] = $process;
            }
            $outcomes = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'One PostgreSQL worker failed; no credentials logged.');
                $outcomes[] = trim($process->getOutput());
            }
            sort($outcomes);
            $this->assertSame(['0', '1'], $outcomes);
            $this->assertSame(1, DB::table('mfa_challenges')
                ->where('id', $challenge->id)->whereNotNull('consumed_at')->count());
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
            // Scoped fixture cleanup only; never truncate or alter schema.
            if ($challenge) $challenge->delete();
            if ($device) $device->delete();
            if ($membership) $membership->delete();
            if ($tenant) $tenant->delete();
            if ($user) $user->delete();
        }
    }
}
