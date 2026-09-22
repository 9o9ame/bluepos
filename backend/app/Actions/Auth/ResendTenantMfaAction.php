<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\MfaChallenge;
use App\Security\SecurityOtp;
use Illuminate\Http\Request;

class ResendTenantMfaAction
{
    public function __construct(private readonly IssueTenantMfaChallengeAction $issue) {}

    public function execute(Request $request, string $challengeUlid): never
    {
        $previous = MfaChallenge::query()
            ->with(['membership.user', 'device'])
            ->where('ulid', $challengeUlid)
            ->first();

        if (! $previous || ! $previous->membership) {
            throw new ApiException('MFA_INVALID', 'The verification challenge is invalid.', 403);
        }

        SecurityOtp::assertResendCooldown($previous->created_at, ['challenge_ulid' => $previous->ulid]);

        if ($previous->consumed_at === null) {
            $previous->consumed_at = now();
            $previous->save();
        }

        $device = $previous->device;
        if ($device === null) {
            throw new ApiException('MFA_INVALID', 'The verification challenge is invalid.', 403);
        }

        $this->issue->execute($request, $previous->membership, $device, true);
    }
}
