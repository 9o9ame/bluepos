<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

class GenerateCategoryCodeAction
{
    public function execute(int $tenantId): string
    {
        DB::table('tenants')
            ->where('id', $tenantId)
            ->lockForUpdate()
            ->first();

        $max = Category::query()
            ->forTenant($tenantId)
            ->where('code', 'like', 'CAT-%')
            ->pluck('code')
            ->reduce(function (int $highest, string $code): int {
                if (! preg_match('/^CAT-(\d+)$/', $code, $matches)) {
                    return $highest;
                }

                return max($highest, (int) $matches[1]);
            }, 0);

        $next = $max + 1;

        do {
            $code = sprintf('CAT-%06d', $next);
            $exists = Category::query()
                ->forTenant($tenantId)
                ->where('code', $code)
                ->exists();
            $next++;
        } while ($exists);

        return $code;
    }
}
