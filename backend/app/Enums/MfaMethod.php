<?php

namespace App\Enums;

enum MfaMethod: string
{
    case Passkey = 'passkey';
    case Totp = 'totp';
    case EmailOtp = 'email_otp';
}
