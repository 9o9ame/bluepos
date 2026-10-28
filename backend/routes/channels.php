<?php

use App\Models\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('bluepos.tenant.{tenantUlid}.member.{membershipUlid}', function ($user, string $tenantUlid, string $membershipUlid): bool {
    $context = app(TenantContext::class);

    if (!$context->membership()->hasOwnerAuthority()) {
        return false;
    }

    return $context->tenant()->ulid === $tenantUlid
        && $context->membership()->ulid === $membershipUlid
        && (int) $context->membership()->user_id === (int) $user->id;
});
