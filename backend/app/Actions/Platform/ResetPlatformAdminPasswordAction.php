<?php

namespace App\Actions\Platform;

use App\Models\Platform\PlatformUser;

class ResetPlatformAdminPasswordAction
{
    public function __construct(private readonly ResetPlatformUserPasswordAction $reset) {}

    /**
     * @return array{user: PlatformUser, temporary_password: string}
     */
    public function execute(string $email, ?string $password = null): array
    {
        $user = PlatformUser::query()->where('email', strtolower(trim($email)))->first();
        if (! $user) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'No platform administrator exists for that email.',
            ]);
        }

        return $this->reset->execute($user, $password);
    }
}
