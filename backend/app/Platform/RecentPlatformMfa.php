<?php

namespace App\Platform;

use App\Actions\Platform\IssuePlatformMfaChallengeAction;
use App\Exceptions\ApiException;
use App\Http\Middleware\EnsurePlatformContext;
use Illuminate\Http\Request;

class RecentPlatformMfa
{
    public function __construct(
        private readonly PlatformContext $context,
        private readonly IssuePlatformMfaChallengeAction $issue,
        private readonly PlatformMfaPolicy $policy,
    ) {}

    public function assert(Request $request): void
    {
        if (! $this->policy->enabled()) {
            return;
        }

        $user = $request->user('platform');
        $verifiedAt = $user?->last_mfa_verified_at;
        $sessionAt = $request->session()->get(EnsurePlatformContext::MFA_AT);
        $cutoff = now()->subMinutes(30);

        $recent = ($verifiedAt !== null && $verifiedAt->greaterThan($cutoff))
            || (is_numeric($sessionAt) && (int) $sessionAt >= $cutoff->timestamp);

        if ($recent) {
            return;
        }

        if ($this->context->hasUser()) {
            $this->issue->execute($this->context->user(), $this->context->device(), 'step_up');
        }

        throw new ApiException('MFA_REQUIRED', 'Recent verification is required for this action.', 403);
    }
}
