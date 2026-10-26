<?php

namespace App\Security;

use App\Authz\PermissionCatalogue;
use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Models\Membership;
use App\Notifications\EmployeeDeviceApprovalNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class EmployeeDeviceApprovalNotifier
{
    public function notifyOwners(Membership $employee, string $deviceName): void
    {
        $owners = Membership::query()
            ->with('user')
            ->where('tenant_id', $employee->tenant_id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('roles', function ($query) use ($employee): void {
                $query->where('roles.tenant_id', $employee->tenant_id)
                    ->where('roles.code', PermissionCatalogue::OWNER)
                    ->where('roles.is_active', true);
            })
            ->get();

        $recipients = [];
        foreach ($owners as $owner) {
            if ($owner->user?->status !== UserStatus::Active) {
                continue;
            }

            $address = EmailNormalizer::normalize($owner->user->recoveryAddress());
            if ($address !== null) {
                $recipients[$address] = true;
            }
        }

        foreach (array_keys($recipients) as $address) {
            try {
                Notification::route('mail', $address)->notify(
                    new EmployeeDeviceApprovalNotification($employee->username, $deviceName)
                );
            } catch (Throwable $error) {
                // Notification failures must not grant access or prevent a pending request being recorded.
                Log::warning('security.device_approval_email_failed', [
                    'reason' => $error::class,
                ]);
            }
        }
    }
}
