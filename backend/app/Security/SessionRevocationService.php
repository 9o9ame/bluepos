<?php

namespace App\Security;

use App\Enums\CashierShiftStatus;
use App\Enums\DeviceStatus;
use App\Models\AuthSessionRecord;
use App\Models\CashierShift;
use App\Models\Device;
use App\Models\Membership;
use App\Models\OfflineAuthorizationLease;
use Illuminate\Support\Facades\DB;

class SessionRevocationService
{
    public function revokeMembership(Membership $membership, bool $closeShifts = true): void
    {
        DB::transaction(function () use ($membership, $closeShifts): void {
            $membership = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $membership->bumpSecurityVersion();

            AuthSessionRecord::query()
                ->where('membership_id', $membership->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            OfflineAuthorizationLease::query()
                ->where('membership_id', $membership->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if ($closeShifts) {
                CashierShift::query()
                    ->where('membership_id', $membership->id)
                    ->where('status', CashierShiftStatus::Open)
                    ->update([
                        'status' => CashierShiftStatus::Voided,
                        'closed_at' => now(),
                    ]);
            }
        });
    }

    public function revokeDevice(Device $device): void
    {
        DB::transaction(function () use ($device): void {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $device->status = DeviceStatus::Revoked;
            $device->revoked_at = now();
            $device->trusted_until = null;
            $device->save();

            AuthSessionRecord::query()
                ->where('device_id', $device->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            OfflineAuthorizationLease::query()
                ->where('device_id', $device->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        });
    }

    public function revokeRecord(AuthSessionRecord $record): void
    {
        $record->revoked_at = now();
        $record->save();
    }
}
