<?php

namespace App\Notifications;

use App\Enums\SecurityNotificationType;
use App\Security\SecurityOtp;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly SecurityNotificationType $type,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ttl = $this->type === SecurityNotificationType::PasswordReset
            || $this->type === SecurityNotificationType::PlatformPasswordReset
            ? SecurityOtp::passwordResetTtlMinutes()
            : SecurityOtp::ttlMinutes();

        $payload = [
            'heading' => $this->type->heading(),
            'reason' => $this->type->reason(),
            'code' => $this->code,
            'ttl_minutes' => $ttl,
        ];

        return (new MailMessage)
            ->subject($this->type->subject())
            ->view('mail.security.otp', $payload)
            ->text('mail.security.otp-text', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
        ];
    }
}
