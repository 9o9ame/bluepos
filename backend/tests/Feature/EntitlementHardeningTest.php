<?php

namespace Tests\Feature;

use App\Actions\Platform\SuspendTenantAction;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Models\TenantSubscription;
use App\Platform\FeatureCatalogue;
use App\Platform\LimitCatalogue;
use App\Platform\PlatformCatalogSync;
use App\Security\TenantEntitlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EntitlementHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tenant_without_subscription_does_not_receive_unrestricted_features(): void
    {
        $session = $this->provisionOwnerWithoutSubscription('hard-1');
        $tenant = $session->tenant->fresh() ?? $session->tenant;
        $service = app(TenantEntitlementService::class);

        $this->assertFalse($service->isTenantOperational($tenant));
        $this->assertSame([], $service->snapshot($tenant)['features']);

        foreach (FeatureCatalogue::keys() as $feature) {
            $this->assertFalse($service->hasFeature($tenant, $feature));
        }

        foreach (LimitCatalogue::keys() as $limit) {
            $this->assertSame(0, $service->limit($tenant, $limit));
        }

        try {
            $service->assertOperational($tenant);
            $this->fail('Expected SUBSCRIPTION_REQUIRED.');
        } catch (ApiException $e) {
            $this->assertSame('SUBSCRIPTION_REQUIRED', $e->errorKey);
        }

        $this->loginAs('hard-1', 'owner')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_tenant_without_subscription_cannot_access_gated_catalog_api(): void
    {
        $this->signInOwner('hard-2')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('hard-2'))->firstOrFail();

        TenantSubscription::query()->where('tenant_id', $tenant->id)->delete();
        $tenant->unsetRelation('subscription');

        $this->getJson('/api/products')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SUBSCRIPTION_REQUIRED');
        $this->getJson('/api/categories')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_explicitly_assigned_plan_enables_allowed_feature(): void
    {
        $session = $this->provisionOwnerWithoutSubscription('hard-3');
        $this->assignExplicitPlan($session->tenant, 'STARTER');

        $service = app(TenantEntitlementService::class);
        $tenant = $session->tenant->fresh(['subscription.plan.features']) ?? $session->tenant;

        $this->assertTrue($service->isTenantOperational($tenant));
        $this->assertTrue($service->hasFeature($tenant, FeatureCatalogue::CATALOG));
        $this->assertContains(FeatureCatalogue::CATALOG, $service->snapshot($tenant)['features']);

        $this->loginAs('hard-3', 'owner')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/settings/entitlements')
            ->assertOk()
            ->assertJsonPath('plan.code', 'STARTER');
    }

    public function test_feature_not_in_plan_remains_blocked(): void
    {
        $session = $this->provisionOwnerWithoutSubscription('hard-4');
        app(PlatformCatalogSync::class)->ensure();

        $plan = Plan::query()->create([
            'code' => 'NOCAT-H4',
            'name' => 'Sales only',
            'status' => PlanStatus::Active,
        ]);
        foreach (FeatureCatalogue::keys() as $feature) {
            PlanFeature::query()->create([
                'plan_id' => $plan->id,
                'feature_key' => $feature,
                'enabled' => $feature === FeatureCatalogue::SALES,
            ]);
        }
        $this->assignExplicitPlan($session->tenant, 'NOCAT-H4');

        $service = app(TenantEntitlementService::class);
        $tenant = $session->tenant->fresh(['subscription.plan.features']) ?? $session->tenant;

        $this->assertTrue($service->hasFeature($tenant, FeatureCatalogue::SALES));
        $this->assertFalse($service->hasFeature($tenant, FeatureCatalogue::CATALOG));
        $this->assertFalse($service->hasFeature($tenant, FeatureCatalogue::ACCOUNTING));

        $this->loginAs('hard-4', 'owner')->assertOk();
        $this->getJson('/api/products')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FEATURE_DISABLED');
    }

    public function test_explicit_tenant_override_still_wins_over_plan(): void
    {
        $session = $this->provisionOwnerWithoutSubscription('hard-5');
        $this->assignExplicitPlan($session->tenant, 'STARTER');
        $tenant = $session->tenant->fresh(['subscription.plan.features']) ?? $session->tenant;
        $service = app(TenantEntitlementService::class);

        $this->assertTrue($service->hasFeature($tenant, FeatureCatalogue::CATALOG));
        $this->assertFalse($service->hasFeature($tenant, FeatureCatalogue::ACCOUNTING));

        TenantFeatureOverride::query()->create([
            'tenant_id' => $tenant->id,
            'feature_key' => FeatureCatalogue::ACCOUNTING,
            'enabled' => true,
            'reason' => 'Hardening override enable',
        ]);
        TenantFeatureOverride::query()->create([
            'tenant_id' => $tenant->id,
            'feature_key' => FeatureCatalogue::CATALOG,
            'enabled' => false,
            'reason' => 'Hardening override disable',
        ]);

        $this->assertTrue($service->hasFeature($tenant, FeatureCatalogue::ACCOUNTING));
        $this->assertFalse($service->hasFeature($tenant, FeatureCatalogue::CATALOG));
    }

    public function test_suspended_tenant_remains_blocked(): void
    {
        $this->signInOwner('hard-6')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('hard-6'))->firstOrFail();

        app(SuspendTenantAction::class)->execute($tenant, 'Hardening suspend');

        $service = app(TenantEntitlementService::class);
        $this->assertFalse($service->isTenantOperational($tenant->fresh() ?? $tenant));
        $this->assertFalse($service->hasFeature($tenant->fresh() ?? $tenant, FeatureCatalogue::CATALOG));

        $this->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'TENANT_DISABLED');
        $this->getJson('/api/products')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'TENANT_DISABLED');
    }

    public function test_inactive_subscription_blocks_modules(): void
    {
        $this->signInOwner('hard-7')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('hard-7'))->firstOrFail();
        $tenant->subscription?->forceFill(['status' => SubscriptionStatus::Suspended])->save();
        $tenant->unsetRelation('subscription');

        $service = app(TenantEntitlementService::class);
        $this->assertFalse($service->isTenantOperational($tenant->fresh() ?? $tenant));

        try {
            $service->assertOperational($tenant->fresh() ?? $tenant);
            $this->fail('Expected SUBSCRIPTION_INACTIVE.');
        } catch (ApiException $e) {
            $this->assertSame('SUBSCRIPTION_INACTIVE', $e->errorKey);
        }

        $this->getJson('/api/products')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SUBSCRIPTION_INACTIVE');
    }
}
