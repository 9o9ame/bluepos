<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformUser;
use App\Platform\FinalPlatformAdminGuard;
use App\Platform\PlatformAuditLogger;
use App\Platform\RecentPlatformMfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UpdatePlatformUserAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly RecentPlatformMfa $recentMfa,
        private readonly FinalPlatformAdminGuard $integrity,
        private readonly DeactivatePlatformAdminAction $deactivate,
    ) {}

    /**
     * @param  array{name?: string, email?: string, status?: string, current_password?: string}  $data
     */
    public function execute(Request $request, PlatformUser $target, array $data, bool $selfService = false): PlatformUser
    {
        return DB::transaction(function () use ($request, $target, $data, $selfService): PlatformUser {
            $target = PlatformUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $changed = [];

            if (isset($data['name']) && $data['name'] !== $target->name) {
                $target->name = $data['name'];
                $changed[] = 'name';
            }

            if (isset($data['email'])) {
                $email = strtolower(trim($data['email']));
                if ($email !== $target->email) {
                    if ($selfService) {
                        $this->recentMfa->assert($request);
                        if (! Hash::check((string) ($data['current_password'] ?? ''), $target->password)) {
                            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
                        }
                    }
                    if (PlatformUser::query()->where('email', $email)->whereKeyNot($target->id)->exists()) {
                        throw ValidationException::withMessages([
                            'email' => 'This platform email is already in use.',
                        ]);
                    }
                    $target->email = $email;
                    $changed[] = 'email';
                }
            }

            if (isset($data['status']) && ! $selfService) {
                $status = PlatformUserStatus::from($data['status']);
                if ($status === PlatformUserStatus::Inactive) {
                    if ($changed !== []) {
                        $target->save();
                    }

                    return $this->deactivate->execute($target);
                }
                if ($status !== PlatformUserStatus::Active) {
                    $this->integrity->assertCanDeactivate($target);
                }
                $target->status = $status;
                $changed[] = 'status';
            }

            $target->save();

            if ($changed !== []) {
                $this->audit->record('PLATFORM_USER_UPDATED', [
                    'resource_type' => 'platform_user',
                    'resource_ulid' => $target->ulid,
                    'fields' => $changed,
                ]);
            }

            return $target->fresh(['roles']) ?? $target;
        });
    }
}
