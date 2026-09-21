<?php

namespace App\Actions\Platform;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanLimit;
use App\Platform\FeatureCatalogue;
use App\Platform\LimitCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePlanAction
{
    /**
     * @param  array{code: string, name: string, description?: ?string, status?: string, billing_interval?: ?string}  $data
     */
    public function execute(array $data): Plan
    {
        $code = strtoupper(trim($data['code']));

        return DB::transaction(function () use ($data, $code): Plan {
            if (Plan::query()->where('code', $code)->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'This plan code is already in use.',
                ]);
            }

            $plan = Plan::query()->create([
                'code' => $code,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => PlanStatus::from($data['status'] ?? PlanStatus::Active->value),
                'billing_interval' => $data['billing_interval'] ?? null,
            ]);

            foreach (FeatureCatalogue::keys() as $feature) {
                PlanFeature::query()->create([
                    'plan_id' => $plan->id,
                    'feature_key' => $feature,
                    'enabled' => false,
                ]);
            }

            foreach (LimitCatalogue::keys() as $limit) {
                PlanLimit::query()->create([
                    'plan_id' => $plan->id,
                    'limit_key' => $limit,
                    'value' => null,
                ]);
            }

            return $plan->fresh(['features', 'limits']) ?? $plan;
        });
    }
}
