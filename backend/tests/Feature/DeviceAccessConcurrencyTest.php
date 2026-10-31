<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class DeviceAccessConcurrencyTest extends TestCase
{
    public function test_two_postgresql_workers_cannot_make_conflicting_pending_access_decisions(): void
    {
        TestDatabaseGuard::assertApplication();
        $db = config('database.connections.pgsql');
        $this->assertSame('bluepos_test', $db['database']);
        $tag = strtolower((string) Str::ulid());
        $tenant = $user = $member = $device = null;
        $requestId = null;
        $workers = [];

        try {
            $tenant = Tenant::query()->create([
                'name' => 'Review race '.$tag,
                'slug' => 'review-race-'.$tag,
                'code' => 'review'.substr($tag, 0, 20),
                'status' => TenantStatus::Active,
                'timezone' => 'UTC',
                'currency_code' => 'PKR',
                'security_version' => 1,
            ]);
            $user = User::query()->create([
                'name' => 'Review race fixture',
                'email' => 'review-race-'.$tag.'@example.invalid',
                'password' => 'unused-test-password',
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'security_version' => 1,
            ]);
            $member = Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'username' => 'reviewstaff',
                'status' => MembershipStatus::Active,
                'is_owner' => false,
                'security_version' => 1,
            ]);
            $device = Device::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Review race browser',
                'device_type' => 'browser',
                'status' => DeviceStatus::Pending,
                'credential_hash' => 'test-fixture',
                'registered_at' => now(),
            ]);
            $requestId = DB::table('device_access_requests')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'membership_id' => $member->id,
                'device_id' => $device->id,
                'network_hash' => hash('sha256', $tag),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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
        'UPDATE device_access_requests SET status = :decision, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '.
        "WHERE id = :id AND tenant_id = :tenant AND status = 'pending' RETURNING id"
    );
    $stmt->execute([
        'decision' => $input['decision'],
        'id' => $input['id'],
        'tenant' => $input['tenant'],
    ]);
    $won = $stmt->fetchColumn() !== false;
    if ($won) $pdo->query('SELECT pg_sleep(1)')->fetchColumn();
    $pdo->commit();
    echo $won ? '1' : '0';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Access decision worker failed');
    exit(1);
}
PHP;
            foreach (['approved', 'rejected'] as $decision) {
                $input = json_encode([
                    'db' => [
                        'host' => $db['host'] ?? '127.0.0.1',
                        'port' => $db['port'] ?? 5432,
                        'database' => $db['database'],
                        'username' => $db['username'],
                        'password' => $db['password'],
                    ],
                    'id' => $requestId,
                    'tenant' => $tenant->id,
                    'decision' => $decision,
                ], JSON_THROW_ON_ERROR);
                $process = new Process([PHP_BINARY, '-r', $worker]);
                $process->setInput($input); // No DB credentials in process argv.
                $process->setTimeout(20);
                $process->start();
                $workers[] = $process;
            }

            $outcomes = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'PostgreSQL access worker failed; no credentials logged.');
                $outcomes[] = trim($process->getOutput());
            }
            sort($outcomes);
            $this->assertSame(['0', '1'], $outcomes);
            $this->assertContains(DB::table('device_access_requests')->where('id', $requestId)->value('status'), ['approved', 'rejected']);
            $this->assertSame(1, DB::table('device_access_requests')->where('id', $requestId)->whereNotNull('reviewed_at')->count());
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
            if ($requestId !== null) DB::table('device_access_requests')->where('id', $requestId)->delete();
            if ($device) $device->delete();
            if ($member) $member->delete();
            if ($tenant) $tenant->delete();
            if ($user) $user->delete();
        }
    }
}
