<?php

namespace App\Actions\Parties;

use App\Models\PartyProfile;
use Illuminate\Support\Facades\DB;

class GeneratePartyProfileCodeAction
{
    /**
     * @param list<string> $types
     */
    public function execute(int $tenantId, array $types): string
    {
        DB::table('tenants')
            ->where('id', $tenantId)
            ->lockForUpdate()
            ->first();

        $normalized = array_values(array_unique(array_map(
            static fn (string $type): string => strtolower(trim($type)),
            $types
        )));
        sort($normalized);

        $prefix = count($normalized) > 1
            ? 'PTY'
            : match ($normalized[0] ?? '') {
                'vendor' => 'VEN',
                'customer' => 'CUS',
                'account' => 'ACC',
                'salesman' => 'SAL',
                default => 'PTY',
            };

        $pattern = $prefix.'-%';

        $max = PartyProfile::query()
            ->forTenant($tenantId)
            ->where('code', 'like', $pattern)
            ->pluck('code')
            ->reduce(function (int $highest, string $code) use ($prefix): int {
                if (! preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $code, $matches)) {
                    return $highest;
                }

                return max($highest, (int) $matches[1]);
            }, 0);

        $next = $max + 1;

        do {
            $code = sprintf('%s-%06d', $prefix, $next);
            $exists = PartyProfile::query()
                ->forTenant($tenantId)
                ->where('code', $code)
                ->exists();
            $next++;
        } while ($exists);

        return $code;
    }
}
