<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\CreatePlanAction;
use App\Actions\Platform\UpdatePlanAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlanRequest;
use App\Http\Requests\Platform\SyncPlanFeaturesRequest;
use App\Http\Requests\Platform\SyncPlanLimitsRequest;
use App\Http\Requests\Platform\UpdatePlanRequest;
use App\Http\Resources\Platform\PlatformPlanResource;
use App\Models\Plan;
use App\Platform\FeatureCatalogue;
use App\Platform\PlatformCatalogSync;
use Illuminate\Http\JsonResponse;

class PlatformPlanController extends Controller
{
    public function index(PlatformCatalogSync $catalog): mixed
    {
        $catalog->ensure();

        return PlatformPlanResource::collection(
            Plan::query()->with(['features', 'limits'])->orderBy('name')->get()
        );
    }

    public function catalog(): mixed
    {
        return response()->json([
            'features' => array_map(
                static fn (array $row): array => [
                    'key' => $row[0],
                    'name' => $row[1],
                    'module' => $row[2],
                    'description' => $row[3],
                ],
                FeatureCatalogue::definitions(),
            ),
        ]);
    }

    public function store(StorePlanRequest $request, CreatePlanAction $create): JsonResponse
    {
        return (new PlatformPlanResource($create->execute($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $planUlid): PlatformPlanResource
    {
        return new PlatformPlanResource($this->findPlan($planUlid)->load(['features', 'limits']));
    }

    public function update(UpdatePlanRequest $request, string $planUlid, UpdatePlanAction $update): PlatformPlanResource
    {
        return new PlatformPlanResource($update->execute($this->findPlan($planUlid), $request->validated()));
    }

    public function syncFeatures(SyncPlanFeaturesRequest $request, string $planUlid, UpdatePlanAction $update): PlatformPlanResource
    {
        return new PlatformPlanResource($update->syncFeatures($this->findPlan($planUlid), $request->validated('features')));
    }

    public function syncLimits(SyncPlanLimitsRequest $request, string $planUlid, UpdatePlanAction $update): PlatformPlanResource
    {
        return new PlatformPlanResource($update->syncLimits($this->findPlan($planUlid), $request->validated('limits')));
    }

    private function findPlan(string $planUlid): Plan
    {
        $plan = Plan::query()->where('ulid', $planUlid)->first();
        if (! $plan) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $plan;
    }
}
