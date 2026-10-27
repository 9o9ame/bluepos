<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeDeviceApprovalNotification extends Notification
{
    public function __construct(
        public readonly string $employeeUsername,
        public readonly string $deviceName,
        public readonly ?string $networkIp = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('BluePOS Employee Device Approval Required')
            ->greeting('New employee access request')
            ->line('An employee has requested access from a device or network that requires approval.')
            ->line('Employee: '.$this->employeeUsername)
            ->line('Device: '.$this->deviceName)
            ->line('Request IP: '.($this->networkIp ?? 'Unavailable'))
            ->line('Sign in to your tenant BluePOS workspace and open Devices to review the pending request.')
            ->line('Only approve requests that you recognize. BluePOS will never ask you to reply with an OTP or password.');
    }
}
