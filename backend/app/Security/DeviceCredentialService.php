<?php

namespace App\Security;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DeviceCredentialService
{
    public const COOKIE = 'bluepos_device';

    public const HEADER = 'X-BluePOS-Device';

    public function issue(Device $device): string
    {
        $secret = bin2hex(random_bytes(24));
        $device->credential_hash = Hash::make($secret);
        $device->save();

        return $device->ulid.'.'.$secret;
    }

    public function cookie(string $credential): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(
            self::COOKIE,
            $credential,
            60 * 24 * 400,
            '/',
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        );
    }

    public function read(Request $request): ?string
    {
        $header = trim((string) $request->header(self::HEADER));
        if ($header !== '') {
            return $header;
        }

        $cookie = trim((string) $request->cookie(self::COOKIE));

        return $cookie !== '' ? $cookie : null;
    }

    public function locate(Request $request): ?Device
    {
        $raw = $this->read($request);
        if ($raw === null || ! str_contains($raw, '.')) {
            return null;
        }

        [$ulid, $secret] = explode('.', $raw, 2);
        if (strlen($ulid) !== 26 || $secret === '') {
            return null;
        }

        $device = Device::query()->where('ulid', $ulid)->first();
        if (! $device || ! Hash::check($secret, $device->credential_hash)) {
            return null;
        }

        return $device;
    }

    public function enrollmentCode(): string
    {
        return strtoupper(Str::random(8));
    }
}
