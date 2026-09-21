<?php

namespace App\Actions\Auth;

use App\Logging\AuthEventLogger;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutUserAction
{
    public function __construct(private readonly AuthEventLogger $logger) {}

    public function execute(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->logger->logout($user, $request);
        }

        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
