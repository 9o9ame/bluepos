<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;

final class SecurityPrivacyRetention
{
    /**
     * Preserve security events and reviewer evidence, redact only aged login IP/UA.
     * All location records have shorter, separate 30-day retention rules.
     */
    public function redactExpiredNetworkIdentifiers(): int
    {
        return DB::table('audit_logs')
            ->whereIn('event', ['LOGIN_SUCCESS', 'LOGIN_FAILURE', 'LOGIN_BLOCKED', 'MFA_FAILURE', 'MFA_SUCCESS'])
            ->where('occurred_at', '<', now()->subDays((int) config('security.privacy.security_ip_retention_days', 90)))
            ->where(function ($query): void {
                $query->whereNotNull('ip_address')->orWhereNotNull('user_agent');
            })
            ->update(['ip_address' => null, 'user_agent' => null]);
    }
}
