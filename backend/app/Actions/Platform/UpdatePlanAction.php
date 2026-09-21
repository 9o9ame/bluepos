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

class UpdatePlanAction
{
    /**
     * @param  array{name?: string, description?: ?string, status?: string, billing_interval?: ?string}  $data
     */
    public function execute(Plan $plan, array $data): Plan
    {
        $plan->fill(collect($data)->only(['name', 'description', 'billing_interval'])->all());

        if (isset($data['status'])) {
            $plan->status = PlanStatus::from($data['status']);
        }

        $plan->save();

        return $plan->fresh(['features', 'limits']) ?? $plan;
    }

    /**
     * @param  array<string, bool>  $features
     */
    public function syncFeatures(Plan $plan, array $features): Plan
    {
        foreach (array_keys($features) as $key) {
            if (! FeatureCatalogue::isValid($key)) {
                throw ValidationException::withMessages([
                    'features' => 'Unknown feature key: '.$key,
                ]);
            }
        }

        return DB::transaction(function () use ($plan, $features): Plan {
            foreach (FeatureCatalogue::keys() as $key) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $key],
                    ['enabled' => (bool) ($features[$key] ?? false)],
                );
            }

            return $plan->fresh(['features', 'limits']) ?? $plan;
        });
    }

    /**
     * @param  array<string, int|null>  $limits
     */
    public function syncLimits(Plan $plan, array $limits): Plan
    {
        foreach (array_keys($limits) as $key) {
            if (! LimitCatalogue::isValid($key)) {
                throw ValidationException::withMessages([
                    'limits' => 'Unknown limit key: '.$key,
                ]);
            }
        }

        return DB::transaction(function () use ($plan, $limits): Plan {
            foreach (LimitCatalogue::keys() as $key) {
                PlanLimit::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'limit_key' => $key],
                    ['value' => array_key_exists($key, $limits) ? $limits[$key] : null],
                );
            }

            return $plan->fresh(['features', 'limits']) ?? $plan;
        });
    }
}
