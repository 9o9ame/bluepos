<?php

namespace App\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional server-observed public-IP location; never derive identity or authorization from it.
 * No network requests without an explicitly configured provider token.
 */
final class IpGeolocation
{
    /** @return array{country: string|null, region: string|null, city: string|null} */
    public function lookup(?string $ip): array
    {
        $empty = ['country' => null, 'region' => null, 'city' => null];
        $token = (string) config('security.geoip.token', '');
        if ($token === '' || ! is_string($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $empty;
        }
        try {
            return Cache::remember('geoip:'.hash('sha256', $ip), now()->addHours(12), function () use ($ip, $token, $empty): array {
                // Fixed HTTPS endpoint: no user-supplied URL, DNS or redirect targets.
                $response = Http::withToken($token)->timeout(2)->withOptions(['allow_redirects' => false])
                    ->acceptJson()->get('https://ipinfo.io/'.$ip.'/json');
                if (! $response->ok() || ! is_array($response->json())) {
                    return $empty;
                }
                $data = $response->json();
                return [
                    'country' => $this->safe($data['country'] ?? null),
                    'region' => $this->safe($data['region'] ?? null),
                    'city' => $this->safe($data['city'] ?? null),
                ];
            });
        } catch (Throwable) {
            // Provider outages must not change login permissions.
            return $empty;
        }
    }

    private function safe(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? \Illuminate\Support\Str::limit(trim($value), 90, '') : null;
    }
}
