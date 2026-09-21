<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'general' => [
                'product' => 'BluePOS Platform',
            ],
            'security' => [
                'mfa' => [
                    'method' => 'email_otp',
                    'required' => true,
                    'totp_enabled' => false,
                    'passkey_enabled' => false,
                ],
                'session' => [
                    'recent_mfa_minutes' => 30,
                ],
            ],
        ]);
    }
}
