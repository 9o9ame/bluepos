<?php

namespace App\Security;

use App\Enums\SecurityNotificationType;
use App\Exceptions\ApiException;
use App\Notifications\SecurityCodeNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SecurityMailService
{
    public function sendOtp(string $recipient, SecurityNotificationType $type, string $otp): void
    {
        $email = EmailNormalizer::normalize($recipient);
        if ($email === null) {
            $this->logFailure($type, 'invalid_recipient');
            throw $this->failed();
        }

        try {
            Notification::route('mail', $email)->notify(new SecurityCodeNotification($otp, $type));
        } catch (Throwable $e) {
            $this->logFailure($type, $e::class);
            throw $this->failed();
        }
    }

    public function trySendOtp(string $recipient, SecurityNotificationType $type, string $otp): bool
    {
        try {
            $this->sendOtp($recipient, $type, $otp);

            return true;
        } catch (ApiException $e) {
            if ($e->errorKey === 'EMAIL_DELIVERY_FAILED') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function failed(array $extra = []): ApiException
    {
        return new ApiException(
            'EMAIL_DELIVERY_FAILED',
            'Unable to send the verification email. Please try again shortly.',
            503,
            $extra,
        );
    }

    private function logFailure(SecurityNotificationType $type, string $reason): void
    {
        Log::warning('security.email.delivery_failed', [
            'type' => $type->value,
            'mailer' => config('mail.default'),
            'reason' => $reason,
        ]);
    }
}
