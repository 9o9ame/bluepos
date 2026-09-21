<?php

namespace App\Security;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $event, array $metadata = [], ?Request $request = null): void
    {
        $request ??= request();
        $context = app(TenantContext::class);

        $safe = $this->redact($metadata);

        AuditLog::query()->create([
            'tenant_id' => $context->hasTenant() ? $context->tenantId() : ($safe['tenant_id'] ?? null),
            'actor_user_id' => $context->hasTenant() ? $context->userId() : ($safe['actor_user_id'] ?? null),
            'actor_membership_id' => $context->hasTenant() ? $context->membershipId() : ($safe['actor_membership_id'] ?? null),
            'device_id' => $context->hasDevice() ? $context->deviceId() : ($safe['device_id'] ?? null),
            'branch_id' => $context->hasTenant() ? $context->branchId() : ($safe['branch_id'] ?? null),
            'event' => $event,
            'resource_type' => $safe['resource_type'] ?? null,
            'resource_ulid' => $safe['resource_ulid'] ?? null,
            'metadata' => collect($safe)->except([
                'tenant_id',
                'actor_user_id',
                'actor_membership_id',
                'device_id',
                'branch_id',
                'resource_type',
                'resource_ulid',
            ])->all() ?: null,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? Str::limit((string) $request->userAgent(), 180, '') : null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redact(array $metadata): array
    {
        $blocked = [
            'password', 'otp', 'code', 'token', 'secret', 'credential', 'authorization',
            'cookie', 'session', 'recovery_token', 'device_secret',
        ];

        foreach ($metadata as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach ($blocked as $needle) {
                if (str_contains($normalized, $needle)) {
                    unset($metadata[$key]);
                }
            }
            if (is_array($value)) {
                $metadata[$key] = $this->redact($value);
            }
        }

        return $metadata;
    }
}
