<?php

namespace App\Actions\Platform;

use App\Exceptions\ApiException;
use App\Models\Platform\PlatformMfaChallenge;
use App\Security\SecurityOtp;
use Illuminate\Http\Request;

class ResendPlatformMfaAction
{
    public function __construct(private readonly IssuePlatformMfaChallengeAction $issue) {}

    public function execute(Request $request, string $challengeUlid): never
    {
        $previous = PlatformMfaChallenge::query()
            ->with(['user', 'device'])
            ->where('ulid', $challengeUlid)
            ->first();

        if (! $previous || ! $previous->user) {
            throw new ApiException('MFA_INVALID', 'The verification challenge is invalid.', 403);
        }

        SecurityOtp::assertResendCooldown($previous->created_at, ['challenge_ulid' => $previous->ulid]);

        if ($previous->consumed_at === null) {
            $previous->consumed_at = now();
            $previous->save();
        }

        $this->issue->execute(
            $previous->user,
            $previous->device,
            (string) ($previous->purpose ?: 'login'),
            true,
            (bool) $previous->remember,
        );
    }
}
