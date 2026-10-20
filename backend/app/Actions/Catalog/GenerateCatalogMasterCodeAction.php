<?php

namespace App\Actions\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GenerateCatalogMasterCodeAction
{
    /**
     * @param class-string<Model> $modelClass
     */
    public function execute(
        string $modelClass,
        int $tenantId,
        string $prefix,
    ): string {
        DB::table('tenants')
            ->where('id', $tenantId)
            ->lockForUpdate()
            ->first();

        $pattern = $prefix.'-%';

        $max = $modelClass::query()
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
            $exists = $modelClass::query()
                ->forTenant($tenantId)
                ->where('code', $code)
                ->exists();
            $next++;
        } while ($exists);

        return $code;
    }
}
