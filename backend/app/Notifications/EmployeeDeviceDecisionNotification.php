<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeDeviceDecisionNotification extends Notification
{
    public function __construct(
        public readonly string $deviceName,
        public readonly string $decision,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->decision === 'approved';

        return (new MailMessage)
            ->subject('BluePOS Device Access Request '.($approved ? 'Approved' : 'Rejected'))
            ->greeting('Device access request '.($approved ? 'approved' : 'rejected'))
            ->line('Device: '.$this->deviceName)
            ->line($approved
                ? 'Your tenant owner approved access for this device/network. Return to BluePOS and sign in again.'
                : 'Your tenant owner rejected access for this device/network. Contact the tenant owner if you need help.')
            ->line('Never share your password, OTP, or device credentials by email.');
    }
}
