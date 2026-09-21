<?php

namespace App\Logging;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthEventLogger
{
    public function loginSucceeded(User $user, Request $request): void
    {
        Log::info('auth.login.success', [
            'event' => 'login_success',
            'user_ulid' => $user->ulid,
            'ip' => $request->ip(),
            'user_agent' => $this->safeUserAgent($request),
        ]);
    }

    public function loginFailed(string $email, Request $request): void
    {
        Log::notice('auth.login.failure', [
            'event' => 'login_failure',
            'email_hash' => hash('sha256', Str::lower(trim($email))),
            'ip' => $request->ip(),
            'user_agent' => $this->safeUserAgent($request),
        ]);
    }

    public function logout(User $user, Request $request): void
    {
        Log::info('auth.logout', [
            'event' => 'logout',
            'user_ulid' => $user->ulid,
            'ip' => $request->ip(),
            'user_agent' => $this->safeUserAgent($request),
        ]);
    }

    private function safeUserAgent(Request $request): string
    {
        return Str::limit((string) $request->userAgent(), 180, '');
    }
}
