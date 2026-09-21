<?php

namespace App\Platform;

use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformAuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $event, array $metadata = [], ?Request $request = null, ?PlatformUser $actor = null): void
    {
        $request ??= request();
        $actor ??= $request instanceof Request ? $request->user('platform') : null;
        if (! $actor instanceof PlatformUser) {
            $actor = null;
        }

        $safe = $this->redact($metadata);

        PlatformAuditLog::query()->create([
            'actor_platform_user_id' => $actor?->id,
            'event' => $event,
            'resource_type' => $safe['resource_type'] ?? null,
            'resource_ulid' => $safe['resource_ulid'] ?? null,
            'metadata' => collect($safe)->except(['resource_type', 'resource_ulid'])->all() ?: null,
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
        $blocked = ['password', 'otp', 'code', 'token', 'secret', 'credential', 'cookie', 'session'];

        foreach ($metadata as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach ($blocked as $needle) {
                if (str_contains($normalized, $needle)) {
                    unset($metadata[$key]);
                }
            }
            if (is_array($value) && isset($metadata[$key])) {
                $metadata[$key] = $this->redact($value);
            }
        }

        return $metadata;
    }
}
